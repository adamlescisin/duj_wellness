/**
 * duj-wellness admin JS — ES module, no build step.
 * Loaded only on duj-wellness admin pages.
 */

const cfg = window.dujAdmin ?? {};
const REST = cfg.restUrl ?? '';
const NONCE = cfg.nonce ?? '';

const MONTHS_CS = ['Leden','Únor','Březen','Duben','Květen','Červen','Červenec','Srpen','Září','Říjen','Listopad','Prosinec'];
const DAYS_CS   = ['Po','Út','St','Čt','Pá','So','Ne'];

const STATUS_LABELS = {
    pending_payment:        'Čekání na platbu',
    awaiting_confirmation:  'Čeká na potvrzení',
    confirmed:              'Potvrzeno',
    cancelled:              'Zrušeno',
    expired:                'Vypršelo',
    completed:              'Dokončeno',
    rejected:               'Zamítnuto',
};

function formatPrice(minor) {
    return (minor / 100).toLocaleString('cs-CZ') + ' Kč';
}

async function apiFetch(path, opts = {}) {
    const res = await fetch(REST + path, {
        headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json', ...opts.headers },
        ...opts,
    });
    const json = await res.json();
    if (!res.ok) throw new Error(json.message ?? 'Chyba serveru');
    return json;
}

function showNotice(msg, type = 'success', container) {
    const el = document.createElement('p');
    el.className = `duj-notice duj-notice--${type}`;
    el.textContent = msg;
    const target = container ?? document.querySelector('.duj-notice-area') ?? document.querySelector('.wrap');
    target.prepend(el);
    setTimeout(() => el.remove(), 5000);
}

// ── Tabs ─────────────────────────────────────────────────────────────────────

function initTabs(root) {
    const tabs = root.querySelectorAll('.duj-admin-tabs a');
    if (!tabs.length) return;

    const panels = root.querySelectorAll('.duj-tab-panel');

    function activate(hash) {
        const target = hash || tabs[0].getAttribute('href');
        tabs.forEach(a => a.classList.toggle('active', a.getAttribute('href') === target));
        panels.forEach(p => p.classList.toggle('active', '#' + p.id === target));
    }

    tabs.forEach(a => a.addEventListener('click', e => {
        e.preventDefault();
        history.replaceState(null, '', a.getAttribute('href'));
        activate(a.getAttribute('href'));
    }));

    activate(location.hash || undefined);
}

// ── Modal ─────────────────────────────────────────────────────────────────────

function createModal(title, bodyHtml, footerHtml = '') {
    const overlay = document.createElement('div');
    overlay.className = 'duj-modal-overlay';
    overlay.innerHTML = `
        <div class="duj-modal" role="dialog" aria-modal="true">
            <div class="duj-modal-header">
                <h2>${title}</h2>
                <button class="duj-modal-close" aria-label="Zavřít">&times;</button>
            </div>
            <div class="duj-modal-body">${bodyHtml}</div>
            ${footerHtml ? `<div class="duj-modal-footer">${footerHtml}</div>` : ''}
        </div>`;

    const close = () => overlay.remove();
    overlay.querySelector('.duj-modal-close').addEventListener('click', close);
    overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
    document.addEventListener('keydown', function esc(e) {
        if (e.key === 'Escape') { close(); document.removeEventListener('keydown', esc); }
    });

    document.body.appendChild(overlay);
    return { overlay, close };
}

// ── Bookings page ─────────────────────────────────────────────────────────────

function initBookingsPage() {
    // Bulk action handler
    const bulkForm = document.getElementById('duj-bookings-form');
    if (bulkForm) {
        bulkForm.addEventListener('submit', async e => {
            if (e.submitter?.name === 'export_csv') return; // native form submit for CSV
            e.preventDefault();
            const action = bulkForm.querySelector('[name="bulk_action"]')?.value;
            if (!action || action === '-1') return;

            const checked = [...bulkForm.querySelectorAll('input[name="booking_ids[]"]:checked')];
            if (!checked.length) { alert('Vyberte alespoň jednu rezervaci.'); return; }
            const ids = checked.map(c => parseInt(c.value));

            if (!confirm(`Opravdu chcete provést akci "${action}" pro ${ids.length} rezervaci?`)) return;

            try {
                await apiFetch('admin/bookings/bulk', {
                    method: 'POST',
                    body: JSON.stringify({ action, ids }),
                });
                location.reload();
            } catch (err) {
                showNotice(err.message, 'error');
            }
        });

        // Select all checkbox
        const selectAll = bulkForm.querySelector('#cb-select-all');
        selectAll?.addEventListener('change', () => {
            bulkForm.querySelectorAll('input[name="booking_ids[]"]').forEach(cb => { cb.checked = selectAll.checked; });
        });
    }

    // Row action buttons (confirm, reject, cancel)
    document.querySelectorAll('[data-booking-action]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.bookingId;
            const action = btn.dataset.bookingAction;
            if (!confirm(`Provést akci "${action}" pro rezervaci #${id}?`)) return;
            try {
                await apiFetch(`admin/bookings/${id}/action`, {
                    method: 'POST',
                    body: JSON.stringify({ action }),
                });
                location.reload();
            } catch (err) {
                showNotice(err.message, 'error');
            }
        });
    });

    // Detail modal
    document.querySelectorAll('[data-booking-detail]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.bookingDetail;
            await openBookingModal(id);
        });
    });
}

async function openBookingModal(id) {
    const { overlay } = createModal('Detail rezervace', '<div class="duj-spinner"></div>');

    try {
        const b = await apiFetch(`admin/bookings/${id}`);
        const body = overlay.querySelector('.duj-modal-body');

        const statusBadge = `<span class="duj-badge duj-badge--${b.status}">${STATUS_LABELS[b.status] ?? b.status}</span>`;
        const comboLabels = { sud: 'Koupací sud', sauna: 'Sauna', 'sauna+sud': 'Sauna + koupací sud' };

        body.innerHTML = `
            <div class="duj-booking-detail">
                <dl>
                    <dt>Reference</dt><dd>${escHtml(b.reference)}</dd>
                    <dt>Stav</dt><dd>${statusBadge}</dd>
                    <dt>Datum</dt><dd>${escHtml(b.booking_date)}</dd>
                    <dt>Čas</dt><dd>${escHtml(b.slot_from)}–${escHtml(b.slot_to)}</dd>
                    <dt>Služba</dt><dd>${escHtml(comboLabels[b.combo_key] ?? b.combo_key)}</dd>
                    <dt>Hosté</dt><dd>${b.guests ?? '—'}</dd>
                    <dt>Zákazník</dt><dd>${escHtml(b.customer_name ?? '—')}</dd>
                    <dt>E-mail</dt><dd>${escHtml(b.customer_email)}</dd>
                    <dt>Telefon</dt><dd>${escHtml(b.customer_phone)}</dd>
                    <dt>Celkem</dt><dd>${formatPrice(b.amount_minor)}</dd>
                    <dt>Platba</dt><dd>${escHtml(b.payment_method)}</dd>
                    <dt>Zdroj</dt><dd>${escHtml(b.source)}</dd>
                    <dt>Poznámka zákazníka</dt><dd>${escHtml(b.customer_note ?? '—')}</dd>
                </dl>
                <div style="margin-top:1rem">
                    <label style="font-weight:600;display:block;margin-bottom:.3rem">Poznámka správce</label>
                    <textarea id="duj-admin-note" rows="3" style="width:100%;max-width:600px">${escHtml(b.admin_note ?? '')}</textarea>
                </div>
            </div>`;

        // Footer buttons
        const footer = document.createElement('div');
        footer.className = 'duj-modal-footer';

        const makeBtn = (label, cls, action) => {
            const btn = document.createElement('button');
            btn.className = `button ${cls}`;
            btn.textContent = label;
            btn.addEventListener('click', async () => {
                const note = body.querySelector('#duj-admin-note')?.value ?? '';
                try {
                    await apiFetch(`admin/bookings/${id}/action`, {
                        method: 'POST',
                        body: JSON.stringify({ action, admin_note: note }),
                    });
                    location.reload();
                } catch (err) {
                    showNotice(err.message, 'error');
                }
            });
            return btn;
        };

        const saveBtn = document.createElement('button');
        saveBtn.className = 'button';
        saveBtn.textContent = 'Uložit poznámku';
        saveBtn.addEventListener('click', async () => {
            const note = body.querySelector('#duj-admin-note').value;
            try {
                await apiFetch(`admin/bookings/${id}`, {
                    method: 'PATCH',
                    body: JSON.stringify({ admin_note: note }),
                });
                showNotice('Uloženo.');
            } catch (err) {
                showNotice(err.message, 'error');
            }
        });
        footer.appendChild(saveBtn);

        if (b.status === 'awaiting_confirmation') {
            footer.appendChild(makeBtn('Potvrdit', 'button-primary', 'confirm'));
            footer.appendChild(makeBtn('Zamítnout', 'button-secondary', 'reject'));
        }
        if (['pending_payment','awaiting_confirmation','confirmed'].includes(b.status)) {
            footer.appendChild(makeBtn('Zrušit', '', 'cancel'));
        }
        if (b.status === 'pending_payment' && (b.payment_method === 'bank_transfer' || b.payment_method === 'qr_bank')) {
            footer.appendChild(makeBtn('Označit zaplaceno', 'button-secondary', 'mark_paid'));
        }

        overlay.querySelector('.duj-modal').appendChild(footer);

    } catch (err) {
        overlay.querySelector('.duj-modal-body').innerHTML = `<p class="duj-notice duj-notice--error">${err.message}</p>`;
    }
}

// ── Calendar page ─────────────────────────────────────────────────────────────

async function initCalendarPage() {
    const root = document.getElementById('duj-admin-calendar');
    if (!root) return;

    let year  = parseInt(root.dataset.year);
    let month = parseInt(root.dataset.month);

    async function render() {
        root.innerHTML = '<div class="duj-spinner" style="margin:2rem auto;display:block"></div>';
        const from = `${year}-${String(month).padStart(2,'0')}-01`;
        const last = new Date(year, month, 0).getDate();
        const to   = `${year}-${String(month).padStart(2,'0')}-${String(last).padStart(2,'0')}`;

        let avail = {};
        try { avail = await apiFetch(`admin/calendar?from=${from}&to=${to}`); } catch {}

        const nav = document.createElement('div');
        nav.className = 'duj-cal-nav';
        nav.innerHTML = `
            <button class="button" id="cal-prev">&larr; Předchozí</button>
            <h2>${MONTHS_CS[month-1]} ${year}</h2>
            <button class="button" id="cal-next">Další &rarr;</button>`;
        nav.querySelector('#cal-prev').addEventListener('click', () => { month--; if(month<1){month=12;year--;} render(); });
        nav.querySelector('#cal-next').addEventListener('click', () => { month++; if(month>12){month=1;year++;} render(); });

        const grid = document.createElement('div');
        grid.className = 'duj-cal-grid';
        DAYS_CS.forEach(d => { const h = document.createElement('div'); h.className='duj-cal-head'; h.textContent=d; grid.appendChild(h); });

        const firstDay = new Date(year, month-1, 1).getDay();
        const startEmpty = firstDay === 0 ? 6 : firstDay - 1;
        for (let i = 0; i < startEmpty; i++) { const e=document.createElement('div'); e.className='duj-cal-cell empty'; grid.appendChild(e); }

        const today = new Date();
        const todayStr = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;
        const STATE_LABELS_CS = { available: 'Volno', booked: 'Obsazeno', partial: 'Čeká na platbu', closed: 'Zavřeno' };
        const RESOURCES = [
            { key: 'sud',   icon: '🛁', label: 'Sud' },
            { key: 'sauna', icon: '🔥', label: 'Sauna' },
        ];

        for (let d = 1; d <= last; d++) {
            const dateStr = `${year}-${String(month).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
            const cell = document.createElement('div');
            cell.className = 'duj-cal-cell';
            const cellDate = new Date(year, month-1, d);
            const todayDate = new Date(today.getFullYear(), today.getMonth(), today.getDate());
            const isPast = cellDate < todayDate;
            if (isPast) cell.classList.add('past');
            if (dateStr === todayStr) cell.classList.add('today');

            const dayInfo  = avail[dateStr] ?? {};
            const slots    = dayInfo.slots ?? {};
            const slotTimes = Object.keys(slots).sort();

            const dayNum = document.createElement('div');
            dayNum.className = 'duj-cal-day';
            dayNum.textContent = String(d);
            cell.appendChild(dayNum);

            if (slotTimes.length === 0) {
                // Closed day — show greyed-out resource chips
                const resContainer = document.createElement('div');
                resContainer.className = 'duj-cal-resources';
                RESOURCES.forEach(({ icon, label }) => {
                    const chip = document.createElement('div');
                    chip.className = 'duj-cal-res duj-cal-res--closed';
                    chip.innerHTML = `<span class="duj-cal-res-icon">${icon}</span><span class="duj-cal-res-label">${label}</span>`;
                    resContainer.appendChild(chip);
                });
                cell.appendChild(resContainer);
            } else {
                // Build time × service matrix
                const matrix = document.createElement('table');
                matrix.className = 'duj-cal-matrix';

                // Header: service icons
                const thead = matrix.createTHead();
                const hRow  = thead.insertRow();
                const corner = document.createElement('th');
                corner.className = 'duj-cal-matrix__corner';
                hRow.appendChild(corner);
                RESOURCES.forEach(({ icon, label }) => {
                    const th = document.createElement('th');
                    th.className = 'duj-cal-matrix__res-header';
                    th.textContent = icon;
                    th.title = label;
                    hRow.appendChild(th);
                });

                // Rows: one per slot time
                const tbody = matrix.createTBody();
                slotTimes.forEach(time => {
                    const slotData = slots[time] ?? {};
                    const row = tbody.insertRow();
                    const tc = row.insertCell();
                    tc.className = 'duj-cal-matrix__time';
                    tc.textContent = time;
                    RESOURCES.forEach(({ key, label }) => {
                        const state = slotData[key] ?? 'closed';
                        const td = row.insertCell();
                        td.className = `duj-cal-matrix__cell duj-cal-matrix__cell--${state}`;
                        td.title = `${time} · ${label}: ${STATE_LABELS_CS[state] ?? state}`;
                    });
                });

                cell.appendChild(matrix);
            }

            if (!isPast) {
                cell.addEventListener('click', () => openCalendarDayModal(dateStr, dayInfo));
            }
            grid.appendChild(cell);
        }

        root.innerHTML = '';
        root.appendChild(nav);
        root.appendChild(grid);
    }

    render();
}

function openCalendarDayModal(date, dayInfo) {
    const { overlay, close } = createModal(
        `Termíny: ${date}`,
        `<p>Klik pro vytvoření ruční rezervace nebo blokace.</p>
         <div id="duj-day-bookings"><div class="duj-spinner"></div></div>`,
        `<button class="button button-primary" id="duj-create-booking-btn">Vytvořit rezervaci</button>
         <button class="button" id="duj-block-day">Blokovat den</button>`
    );

    overlay.querySelector('#duj-create-booking-btn')?.addEventListener('click', () => {
        close();
        openManualBookingModal(date);
    });

    apiFetch(`admin/calendar/day?date=${date}`).then(data => {
        const el = overlay.querySelector('#duj-day-bookings');
        if (!data.bookings?.length) { el.textContent = 'Žádné rezervace.'; return; }
        el.innerHTML = `<table class="widefat fixed"><thead><tr><th>Ref</th><th>Čas</th><th>Zákazník</th><th>Stav</th></tr></thead><tbody>
            ${data.bookings.map(b => `<tr>
                <td><a href="#" data-booking-detail="${b.id}">${escHtml(b.reference)}</a></td>
                <td>${escHtml(b.slot_from)}–${escHtml(b.slot_to)}</td>
                <td>${escHtml(b.customer_email)}</td>
                <td><span class="duj-badge duj-badge--${b.status}">${STATUS_LABELS[b.status]??b.status}</span></td>
            </tr>`).join('')}</tbody></table>`;
        el.querySelectorAll('[data-booking-detail]').forEach(a => {
            a.addEventListener('click', async e => { e.preventDefault(); await openBookingModal(a.dataset.bookingDetail); });
        });
    }).catch(() => { overlay.querySelector('#duj-day-bookings').textContent = 'Chyba načítání.'; });

    overlay.querySelector('#duj-block-day')?.addEventListener('click', async () => {
        const reason = prompt('Důvod blokace (nepovinné):') ?? '';
        try {
            await apiFetch('admin/schedule/overrides', {
                method: 'POST',
                body: JSON.stringify({ override_date: date, mode: 'closed', note: reason }),
            });
            showNotice('Den zablokován.');
            overlay.querySelector('.duj-modal-close').click();
        } catch (err) { showNotice(err.message, 'error'); }
    });
}

function openManualBookingModal(date) {
    const { overlay, close } = createModal('Nová ruční rezervace',
        `<form id="duj-manual-booking-form">
            <table class="form-table">
                <tr><th>Datum</th><td><input type="date" name="booking_date" value="${escHtml(date)}" required style="width:160px"></td></tr>
                <tr><th>Čas od</th><td><input type="time" name="slot_from" value="16:00" required style="width:120px"></td></tr>
                <tr><th>Čas do</th><td><input type="time" name="slot_to" value="18:00" required style="width:120px"></td></tr>
                <tr><th>Služba</th><td>
                    <select name="combo_key">
                        <option value="sud">Koupací sud</option>
                        <option value="sauna">Sauna</option>
                        <option value="sauna+sud">Sauna + koupací sud</option>
                    </select>
                </td></tr>
                <tr><th>Jméno</th><td><input type="text" name="customer_name" style="width:100%;max-width:340px"></td></tr>
                <tr><th>E-mail *</th><td><input type="email" name="customer_email" required style="width:100%;max-width:340px"></td></tr>
                <tr><th>Telefon *</th><td><input type="tel" name="customer_phone" required style="width:100%;max-width:340px"></td></tr>
                <tr><th>Počet hostů</th><td><input type="number" name="guests" value="1" min="1" max="20" style="width:80px"></td></tr>
                <tr><th>Poznámka zákazníka</th><td><textarea name="customer_note" rows="2" style="width:100%;max-width:340px"></textarea></td></tr>
                <tr><th>Poznámka správce</th><td><textarea name="admin_note" rows="2" style="width:100%;max-width:340px"></textarea></td></tr>
            </table>
        </form>`,
        `<button class="button button-primary" id="duj-manual-submit">Vytvořit rezervaci</button>
         <button class="button duj-modal-close">Zrušit</button>`
    );

    overlay.querySelector('#duj-manual-submit')?.addEventListener('click', async () => {
        const form = overlay.querySelector('#duj-manual-booking-form');
        if (!form.checkValidity()) { form.reportValidity(); return; }
        const fd = new FormData(form);
        const payload = {
            booking_date:   fd.get('booking_date'),
            slot_from:      fd.get('slot_from'),
            slot_to:        fd.get('slot_to'),
            combo_key:      fd.get('combo_key'),
            customer_name:  fd.get('customer_name') || '',
            customer_email: fd.get('customer_email'),
            customer_phone: fd.get('customer_phone'),
            guests:         parseInt(fd.get('guests')) || 1,
            customer_note:  fd.get('customer_note') || '',
            admin_note:     fd.get('admin_note') || '',
        };
        try {
            const res = await apiFetch('admin/bookings/manual', { method: 'POST', body: JSON.stringify(payload) });
            close();
            showNotice(`Rezervace ${res.reference} vytvořena.`);
        } catch (err) { showNotice(err.message, 'error'); }
    });
}

// ── Schedule page ─────────────────────────────────────────────────────────────

function initSchedulePage() {
    initTabs(document.getElementById('duj-schedule-page') ?? document);

    // Slot generator
    const genForm = document.getElementById('duj-slot-gen-form');
    if (genForm) {
        genForm.addEventListener('submit', async e => {
            e.preventDefault();
            const fd = new FormData(genForm);
            const payload = {
                time_from:  fd.get('time_from'),
                time_to:    fd.get('time_to'),
                slot_minutes: parseInt(fd.get('slot_minutes')),
                buffer_minutes: parseInt(fd.get('buffer_minutes')),
                weekdays: [...genForm.querySelectorAll('input[name="weekdays[]"]:checked')].map(c=>parseInt(c.value)),
                valid_from: fd.get('valid_from') || null,
                valid_to:   fd.get('valid_to')   || null,
                dry_run: e.submitter?.value === 'preview',
            };

            try {
                const res = await apiFetch('admin/schedule/generate-slots', {
                    method: 'POST', body: JSON.stringify(payload),
                });
                const preview = document.getElementById('duj-slot-preview');
                if (payload.dry_run) {
                    const validityNote = (res.valid_from && res.valid_to)
                        ? ` <em>(platnost ${escHtml(res.valid_from)} – ${escHtml(res.valid_to)})</em>`
                        : '';
                    preview.style.display = 'block';
                    preview.innerHTML = `<strong>Náhled ${res.slots.length} slotů${validityNote}:</strong>
                        <ul>${res.slots.map(s=>`<li>${escHtml(s.weekday_label)}: ${escHtml(s.time_from)}–${escHtml(s.time_to)}</li>`).join('')}</ul>`;
                } else {
                    showNotice(`Vygenerováno ${res.count} pravidel.`);
                    preview.style.display = 'none';
                    setTimeout(() => location.reload(), 1500);
                }
            } catch (err) { showNotice(err.message, 'error'); }
        });
    }

    // Bulk schedule edit
    const bulkForm = document.getElementById('duj-schedule-bulk-form');
    if (bulkForm) {
        bulkForm.addEventListener('submit', async e => {
            e.preventDefault();
            const fd = new FormData(bulkForm);
            const payload = {
                date_from: fd.get('date_from'),
                date_to:   fd.get('date_to'),
                weekdays:  [...bulkForm.querySelectorAll('input[name="weekdays[]"]:checked')].map(c=>parseInt(c.value)),
                action:    fd.get('bulk_action'),
                time_from: fd.get('time_from') || undefined,
                time_to:   fd.get('time_to') || undefined,
                slot_minutes: fd.get('slot_minutes') ? parseInt(fd.get('slot_minutes')) : undefined,
                dry_run: e.submitter?.value === 'preview',
            };

            try {
                const res = await apiFetch('admin/schedule/bulk', {
                    method: 'POST', body: JSON.stringify(payload),
                });
                const preview = document.getElementById('duj-bulk-preview');
                if (payload.dry_run) {
                    preview.style.display = 'block';
                    preview.innerHTML = `<strong>Dopad:</strong> změní se ${res.affected_days} dnů, koliduje s ${res.conflicting_bookings} potvrzenými rezervacemi.`;
                    if (res.conflicting_bookings > 0) preview.innerHTML += ' <strong style="color:red">Varování: koliduje s potvrzenou rezervací!</strong>';
                } else {
                    showNotice(`Hromadná úprava provedena pro ${res.affected_days} dnů.`);
                    preview.style.display = 'none';
                    setTimeout(() => location.reload(), 1500);
                }
            } catch (err) { showNotice(err.message, 'error'); }
        });
    }

    // Add rule form (inline in Pravidla tab)
    const addRuleForm = document.getElementById('duj-add-rule-form');
    addRuleForm?.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(addRuleForm);
        const payload = {
            weekday:   parseInt(fd.get('weekday')),
            time_from: fd.get('time_from'),
            time_to:   fd.get('time_to'),
            label:     fd.get('label') || '',
        };
        try {
            const res = await apiFetch('admin/schedule/rules', {
                method: 'POST', body: JSON.stringify(payload),
            });
            showNotice('Pravidlo přidáno.');
            // Remove the "no rules" empty row if present
            document.getElementById('duj-rules-empty')?.remove();
            // Append new row to table
            const weekdayLabels = {1:'Po',2:'Út',3:'St',4:'Čt',5:'Pá',6:'So',7:'Ne'};
            const tbody = document.getElementById('duj-rules-tbody');
            if (tbody) {
                const tr = document.createElement('tr');
                tr.dataset.ruleId = res.id;
                tr.innerHTML = `
                    <td>${escHtml(weekdayLabels[payload.weekday] ?? payload.weekday)}</td>
                    <td>${escHtml(payload.time_from)}–${escHtml(payload.time_to)}</td>
                    <td>${escHtml(payload.label)}</td>
                    <td>—</td><td>—</td><td>✓</td>
                    <td><button type="button" class="button button-small" data-delete-rule="${res.id}">Smazat</button></td>`;
                tbody.appendChild(tr);
                // Wire the new delete button
                tr.querySelector('[data-delete-rule]').addEventListener('click', handleDeleteRule);
            }
            addRuleForm.reset();
        } catch (err) { showNotice(err.message, 'error'); }
    });

    function handleDeleteRule() {
        const btn = this;
        if (!confirm('Smazat pravidlo?')) return;
        apiFetch(`admin/schedule/rules/${btn.dataset.deleteRule}`, { method: 'DELETE' })
            .then(() => {
                const tr = btn.closest('tr');
                tr?.remove();
                const tbody = document.getElementById('duj-rules-tbody');
                if (tbody && !tbody.querySelector('tr:not(#duj-rules-empty)')) {
                    const empty = document.createElement('tr');
                    empty.id = 'duj-rules-empty';
                    empty.innerHTML = '<td colspan="7">Žádná pravidla. Přidejte první pravidlo výše.</td>';
                    tbody.appendChild(empty);
                }
            })
            .catch(err => showNotice(err.message, 'error'));
    }

    // Delete rule buttons
    document.querySelectorAll('[data-delete-rule]').forEach(btn => {
        btn.addEventListener('click', handleDeleteRule);
    });

    // Delete override buttons
    document.querySelectorAll('[data-delete-override]').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Smazat výjimku?')) return;
            try {
                await apiFetch(`admin/schedule/overrides/${btn.dataset.deleteOverride}`, { method: 'DELETE' });
                btn.closest('tr')?.remove();
            } catch (err) { showNotice(err.message, 'error'); }
        });
    });

    // Add override form — custom slots UI
    const ovMode = document.getElementById('ov-mode');
    const ovSlotsRow = document.getElementById('ov-slots-row');
    const ovSlotsList = document.getElementById('ov-slots-list');

    function addSlotRow(from = '16:00', to = '18:00') {
        const idx = ovSlotsList?.querySelectorAll('.ov-slot-entry').length ?? 0;
        const div = document.createElement('div');
        div.className = 'ov-slot-entry';
        div.style.cssText = 'display:flex;gap:.5rem;align-items:center;margin-bottom:.3rem';
        div.innerHTML = `<input type="time" name="slot_from[]" value="${from}" required style="width:120px">
            <span>–</span>
            <input type="time" name="slot_to[]" value="${to}" required style="width:120px">
            <button type="button" class="button button-small ov-remove-slot">✕</button>`;
        div.querySelector('.ov-remove-slot').addEventListener('click', () => div.remove());
        ovSlotsList?.appendChild(div);
    }

    ovMode?.addEventListener('change', () => {
        const isCustom = ovMode.value === 'custom';
        if (ovSlotsRow) ovSlotsRow.style.display = isCustom ? '' : 'none';
        if (isCustom && ovSlotsList && !ovSlotsList.querySelector('.ov-slot-entry')) {
            addSlotRow();
        }
    });

    document.getElementById('ov-add-slot')?.addEventListener('click', () => addSlotRow());

    const overrideForm = document.getElementById('duj-override-form');
    overrideForm?.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(overrideForm);
        const mode = fd.get('mode');

        const payload = {
            override_date: fd.get('override_date'),
            mode,
            note: fd.get('note') || '',
        };

        if (mode === 'custom') {
            const froms = [...overrideForm.querySelectorAll('input[name="slot_from[]"]')].map(i => i.value);
            const tos   = [...overrideForm.querySelectorAll('input[name="slot_to[]"]')].map(i => i.value);
            payload.slots = froms.map((f, i) => ({ from: f, to: tos[i] })).filter(s => s.from && s.to);
            if (!payload.slots.length) { showNotice('Přidejte alespoň jeden slot.', 'error'); return; }
        }

        try {
            await apiFetch('admin/schedule/overrides', { method: 'POST', body: JSON.stringify(payload) });
            location.reload();
        } catch (err) { showNotice(err.message, 'error'); }
    });
}

// ── Stats page ────────────────────────────────────────────────────────────────

async function initStatsPage() {
    const periodSelect = document.getElementById('duj-stats-period');
    const loadingEl    = document.getElementById('duj-stats-loading');

    function fmtMoney(minor) {
        return Math.round(minor / 100).toLocaleString('cs-CZ') + ' Kč';
    }

    const STATUS_LABELS_CS = {
        pending_payment: 'Čeká na platbu', awaiting_confirmation: 'Čeká na potvrzení',
        confirmed: 'Potvrzeno', completed: 'Dokončeno', cancelled: 'Zrušeno',
        expired: 'Expirováno', rejected: 'Zamítnuto', no_show: 'Nedostavení',
    };

    function renderStats(data) {
        const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
        set('kpi-revenue',   fmtMoney(data.total_revenue ?? 0));
        set('kpi-avg',       fmtMoney(data.avg_booking   ?? 0));
        set('kpi-customers', data.unique_customers ?? 0);
        set('kpi-confirmed', data.by_status?.confirmed ?? 0);

        const tbodyMonthly = document.getElementById('tbody-monthly');
        if (tbodyMonthly) {
            tbodyMonthly.innerHTML = (data.monthly ?? []).length
                ? data.monthly.map(r => `<tr><td>${escHtml(r.month)}</td><td>${r.bookings}</td><td>${fmtMoney(r.revenue)}</td></tr>`).join('')
                : '<tr><td colspan="3">—</td></tr>';
        }

        const tbodyService = document.getElementById('tbody-service');
        if (tbodyService) {
            tbodyService.innerHTML = (data.by_service ?? []).length
                ? data.by_service.map(r => `<tr><td>${escHtml(r.combo_key)}</td><td>${r.bookings}</td><td>${fmtMoney(r.revenue)}</td></tr>`).join('')
                : '<tr><td colspan="3">—</td></tr>';
        }

        const tbodyStatus = document.getElementById('tbody-status');
        if (tbodyStatus) {
            const entries = Object.entries(data.by_status ?? {});
            tbodyStatus.innerHTML = entries.length
                ? entries.map(([st, cnt]) => `<tr><td>${escHtml(STATUS_LABELS_CS[st] ?? st)}</td><td>${cnt}</td></tr>`).join('')
                : '<tr><td colspan="2">—</td></tr>';
        }
    }

    async function loadStats(period) {
        if (loadingEl) loadingEl.hidden = false;
        try {
            const data = await apiFetch(`admin/stats?period=${encodeURIComponent(period)}`);
            renderStats(data);
        } catch (err) {
            showNotice(err.message, 'error');
        } finally {
            if (loadingEl) loadingEl.hidden = true;
        }
    }

    periodSelect?.addEventListener('change', () => loadStats(periodSelect.value));
    await loadStats(periodSelect?.value ?? 'year');
}

// ── Pricing page ──────────────────────────────────────────────────────────────

function initPricingPage() {
    initTabs(document.getElementById('duj-pricing-page') ?? document);

    // Tier bulk save
    const tiersForm = document.getElementById('duj-tiers-form');
    tiersForm?.addEventListener('submit', async e => {
        e.preventDefault();
        const tiers = [...tiersForm.querySelectorAll('tr[data-tier-id]')].map(row => ({
            id:            parseInt(row.dataset.tierId),
            label:         row.querySelector('[name="label"]')?.value.trim() ?? '',
            requires_code: row.querySelector('[name="requires_code"]')?.checked ? 1 : 0,
            show_in_form:  row.querySelector('[name="show_in_form"]')?.checked  ? 1 : 0,
            is_active:     row.querySelector('[name="is_active"]')?.checked     ? 1 : 0,
            cutoff_mode:   row.querySelector('[name="cutoff_mode"]')?.value ?? 'inherit',
            sort_order:    parseInt(row.querySelector('[name="sort_order"]')?.value ?? '0'),
        }));
        try {
            await apiFetch('admin/price-tiers/bulk', { method: 'POST', body: JSON.stringify({ tiers }) });
            showNotice('Hladiny uloženy.');
        } catch (err) { showNotice(err.message, 'error'); }
    });

    // Add new tier
    const addTierForm = document.getElementById('duj-add-tier-form');
    addTierForm?.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(addTierForm);
        try {
            await apiFetch('admin/price-tiers', {
                method: 'POST',
                body: JSON.stringify({
                    slug:          fd.get('slug'),
                    label:         fd.get('label'),
                    requires_code: fd.get('requires_code') ? 1 : 0,
                    show_in_form:  fd.get('show_in_form')  ? 1 : 0,
                    cutoff_mode:   fd.get('cutoff_mode'),
                    sort_order:    parseInt(fd.get('sort_order') || '0'),
                }),
            });
            showNotice('Hladina přidána.');
            setTimeout(() => location.reload(), 1200);
        } catch (err) { showNotice(err.message, 'error'); }
    });

    // Delete tier
    document.querySelectorAll('[data-delete-tier]').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Smazat hladinu? Tato akce je nevratná.')) return;
            try {
                await apiFetch(`admin/price-tiers/${btn.dataset.deleteTier}`, { method: 'DELETE' });
                btn.closest('tr')?.remove();
                showNotice('Hladina smazána.');
            } catch (err) { showNotice(err.message, 'error'); }
        });
    });

    // Price matrix save
    const matrixForm = document.getElementById('duj-price-matrix-form');
    matrixForm?.addEventListener('submit', async e => {
        e.preventDefault();
        const prices = [...matrixForm.querySelectorAll('[data-price-id]')].map(inp => ({
            id: parseInt(inp.dataset.priceId),
            amount_minor: Math.round(parseFloat(inp.value) * 100),
        }));
        try {
            await apiFetch('admin/prices/bulk', { method: 'POST', body: JSON.stringify({ prices }) });
            showNotice('Ceník uložen.');
        } catch (err) { showNotice(err.message, 'error'); }
    });

    // Generate access code
    const codeForm = document.getElementById('duj-gen-code-form');
    codeForm?.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(codeForm);
        try {
            const res = await apiFetch('admin/access-codes', {
                method: 'POST',
                body: JSON.stringify({
                    tier_slug:  fd.get('tier_slug'),
                    label:      fd.get('label'),
                    valid_from: fd.get('valid_from') || null,
                    valid_to:   fd.get('valid_to') || null,
                    max_uses:   fd.get('max_uses') ? parseInt(fd.get('max_uses')) : null,
                }),
            });
            showNotice(`Kód vytvořen: ${res.code}`);
            setTimeout(() => location.reload(), 1500);
        } catch (err) { showNotice(err.message, 'error'); }
    });

    // Deactivate access code
    document.querySelectorAll('[data-deactivate-code]').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Deaktivovat kód?')) return;
            try {
                await apiFetch(`admin/access-codes/${btn.dataset.deactivateCode}`, { method: 'DELETE' });
                btn.closest('tr')?.remove();
            } catch (err) { showNotice(err.message, 'error'); }
        });
    });
}

// ── Accommodation page ────────────────────────────────────────────────────────

function initAccommodationPage() {
    // Sync button
    document.getElementById('duj-sync-now')?.addEventListener('click', async btn => {
        btn = document.getElementById('duj-sync-now');
        btn.disabled = true;
        btn.textContent = 'Synchronizuji…';
        try {
            const res = await apiFetch('admin/accommodation/sync', { method: 'POST' });
            showNotice(`Synchronizace dokončena: ${res.imported} záznamů.`);
        } catch (err) {
            showNotice(err.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Synchronizovat teď';
        }
    });

    // Manual policy override
    document.querySelectorAll('[data-accom-policy]').forEach(sel => {
        sel.addEventListener('change', async () => {
            const date   = sel.dataset.accomPolicy;
            const policy = sel.value;
            try {
                await apiFetch('admin/accommodation', {
                    method: 'POST',
                    body: JSON.stringify({ date, policy, is_manual: true }),
                });
                showNotice('Politika uložena.');
            } catch (err) { showNotice(err.message, 'error'); sel.value = sel.dataset.original; }
        });
    });

    // CSV import
    const csvForm = document.getElementById('duj-csv-import-form');
    csvForm?.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(csvForm);
        const isDryRun = e.submitter?.value === 'preview';
        if (isDryRun) fd.set('dry_run', '1'); else fd.set('dry_run', '0');

        try {
            const res = await apiFetch('admin/accommodation/import-csv', {
                method: 'POST',
                headers: {},
                body: fd,
            });
            const preview = document.getElementById('duj-csv-preview');
            if (isDryRun) {
                preview.style.display = 'block';
                preview.innerHTML = `<strong>Náhled:</strong> ${res.to_import} záznamů, ${res.conflicts} kolizí s existujícími rezervacemi.`;
            } else {
                showNotice(`Importováno ${res.imported} záznamů.`);
                preview.style.display = 'none';
            }
        } catch (err) { showNotice(err.message, 'error'); }
    });
}

// ── Email templates page ──────────────────────────────────────────────────────

function initEmailsPage() {
    let currentTemplate = null;

    document.querySelectorAll('.duj-template-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            document.querySelectorAll('.duj-template-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentTemplate = btn.dataset.template;
            await loadTemplate(currentTemplate);
        });
    });

    async function loadTemplate(name) {
        const area = document.getElementById('duj-template-editor');
        if (!area) return;
        area.classList.add('duj-loading');
        try {
            const res = await apiFetch(`admin/templates/${name}`);
            document.getElementById('duj-tpl-subject').value = res.subject ?? '';
            document.getElementById('duj-tpl-body').value    = res.body ?? '';
        } catch (err) {
            showNotice('Nepodařilo se načíst šablonu: ' + err.message, 'error');
        } finally { area.classList.remove('duj-loading'); }
    }

    // Save template
    document.getElementById('duj-tpl-save')?.addEventListener('click', async () => {
        if (!currentTemplate) return;
        try {
            await apiFetch(`admin/templates/${currentTemplate}`, {
                method: 'PATCH',
                body: JSON.stringify({
                    subject: document.getElementById('duj-tpl-subject').value,
                    body: document.getElementById('duj-tpl-body').value,
                }),
            });
            showNotice('Šablona uložena.');
        } catch (err) { showNotice(err.message, 'error'); }
    });

    // Reset to default
    document.getElementById('duj-tpl-reset')?.addEventListener('click', async () => {
        if (!currentTemplate || !confirm('Obnovit výchozí šablonu?')) return;
        try {
            await apiFetch(`admin/templates/${currentTemplate}`, { method: 'DELETE' });
            await loadTemplate(currentTemplate);
            showNotice('Šablona obnovena.');
        } catch (err) { showNotice(err.message, 'error'); }
    });

    // Send test email
    document.getElementById('duj-tpl-test')?.addEventListener('click', async () => {
        if (!currentTemplate) return;
        const to = prompt('Testovací e-mail:');
        if (!to) return;
        try {
            const res = await apiFetch(`admin/templates/${currentTemplate}/test`, {
                method: 'POST',
                body: JSON.stringify({ email: to }),
            });
            showNotice(`Testovací e-mail odeslán na ${res.sent_to ?? to}.`);
        } catch (err) { showNotice(err.message, 'error'); }
    });

    // Placeholder click → insert to textarea
    document.querySelectorAll('.duj-placeholder-list code').forEach(code => {
        code.addEventListener('click', () => {
            const ta = document.getElementById('duj-tpl-body');
            if (!ta) return;
            const placeholder = code.textContent;
            const start = ta.selectionStart, end = ta.selectionEnd;
            ta.setRangeText(placeholder, start, end, 'end');
            ta.focus();
        });
    });

    // Activate first template
    document.querySelector('.duj-template-btn')?.click();
}

// ── Notifications page ────────────────────────────────────────────────────────

function initNotificationsPage() {
    document.getElementById('duj-test-telegram')?.addEventListener('click', async () => {
        try {
            await apiFetch('admin/notifications/test', { method: 'POST', body: JSON.stringify({}) });
            showNotice('Telegram test odeslán.');
        } catch (err) { showNotice(err.message, 'error'); }
    });

    document.getElementById('duj-notif-settings-form')?.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const data = { telegram_chat_id: fd.get('telegram_chat_id') };
        if (fd.get('telegram_bot_token')) data.telegram_bot_token = fd.get('telegram_bot_token');
        try {
            await apiFetch('admin/settings', { method: 'PATCH', body: JSON.stringify(data) });
            showNotice('Nastavení uloženo.');
        } catch (err) { showNotice(err.message, 'error'); }
    });
}

// ── Settings page ─────────────────────────────────────────────────────────────

function initSettingsPage() {
    document.getElementById('duj-settings-form')?.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const data = {};
        fd.forEach((v, k) => { data[k] = v; });
        // Checkboxes that may be missing
        ['cutoff_enabled','debug_mode'].forEach(k => { if (!fd.has(k)) data[k] = '0'; });

        try {
            await apiFetch('admin/settings', { method: 'PATCH', body: JSON.stringify(data) });
            showNotice('Nastavení uloženo.');
        } catch (err) { showNotice(err.message, 'error'); }
    });
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function escHtml(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Boot ──────────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    const page = document.body.dataset.dujPage;

    switch (page) {
        case 'bookings':      initBookingsPage();      break;
        case 'calendar':      initCalendarPage();       break;
        case 'schedule':      initSchedulePage();       break;
        case 'pricing':       initPricingPage();        break;
        case 'stats':         initStatsPage();          break;
        case 'accommodation': initAccommodationPage();  break;
        case 'emails':        initEmailsPage();         break;
        case 'notifications': initNotificationsPage();  break;
        case 'settings':      initSettingsPage();       break;
    }

    // Global tab init for any page with tabs
    initTabs(document.body);
});

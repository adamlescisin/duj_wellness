/**
 * duj-wellness booking widget — ES module, no build step.
 *
 * State machine: calendar → slots → service → details → payment → result
 * history.pushState pro step navigaci, tlačítko Zpět funguje.
 */

const cfg = window.dujWellness ?? {};
const REST = cfg.restUrl ?? '/wp-json/duj/v1/';
const NONCE = cfg.nonce ?? '';
const i18n = cfg.i18n ?? {};
const STRIPE_KEY = cfg.stripeKey ?? '';

/* ─── Czech calendar helpers ─── */
const MONTHS_CS = ['Leden','Únor','Březen','Duben','Květen','Červen','Červenec','Srpen','Září','Říjen','Listopad','Prosinec'];
const DAYS_CS   = ['Po','Út','St','Čt','Pá','So','Ne'];

function formatPrice(halers) {
  const czk = Math.round(halers / 100);
  return czk.toLocaleString('cs-CZ') + ' ' + (i18n.czk ?? 'Kč');
}

function isoDate(y, m, d) {
  return `${y}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
}

function getISOWeek(date) {
  const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
  d.setUTCDate(d.getUTCDate() + 4 - (d.getUTCDay() || 7));
  const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
  return Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
}

/* ─── REST helpers ─── */
async function apiFetch(path, opts = {}) {
  const res = await fetch(REST + path, {
    headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json', ...opts.headers },
    ...opts,
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw Object.assign(new Error(json.message ?? i18n.errorGeneric), { code: json.code, status: res.status });
  return json;
}

/* ─── State ─── */
const state = {
  step: 0,            // 0=calendar 1=slots 2=service 3=details 4=payment 5=result
  accessCode: '',
  codeValid: null,    // null|true|false
  tier: 'public',
  months: 3,
  service: 'all',

  // availability cache: { [yyyymm]: DayInfo[] }
  cache: {},
  currentYear: null,
  currentMonth: null,

  selectedDate: null, // 'YYYY-MM-DD'
  slots: [],          // AvailabilitySlot[]
  selectedSlot: null, // { from, to }

  prices: null,       // { sud: N, sauna: N, 'sauna+sud': N }
  selectedCombo: null, // 'sud'|'sauna'|'sauna+sud'

  // form
  customerName: '',
  customerEmail: '',
  customerPhone: '',
  guests: 1,
  note: '',
  consent: false,

  // payment
  bookingId: null,
  bookingRef: null,
  holdExpiresAt: null,
  clientSecret: null,
  timerHandle: null,

  // stripe
  stripe: null,
  elements: null,
  paymentEl: null,
};

/* ─── Root element ─── */
let root = null;
let app  = null;

/* ─── Init ─── */
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.duj-wellness').forEach(el => initWidget(el));
});

function initWidget(el) {
  root = el;
  app  = el.querySelector('.duj-wellness__app');

  const months  = parseInt(el.dataset.months ?? '3', 10);
  const service = el.dataset.service ?? 'all';
  const mode    = el.dataset.mode ?? 'booking';

  state.months  = months;
  state.service = service;

  const now = new Date();
  state.currentYear  = now.getFullYear();
  state.currentMonth = now.getMonth() + 1;

  if (mode === 'availability') {
    renderAvailabilityWidget(parseInt(el.dataset.count ?? '5', 10));
    return;
  }

  // Restore from history state
  const hs = history.state;
  if (hs?.dujStep != null) {
    Object.assign(state, hs.dujState ?? {});
    renderStep(hs.dujStep);
  } else {
    renderStep(0);
  }

  window.addEventListener('popstate', e => {
    if (e.state?.dujStep != null) {
      Object.assign(state, e.state.dujState ?? {});
      renderStep(e.state.dujStep);
    }
  });
}

function pushStep(step) {
  const snap = { ...state, stripe: null, elements: null, paymentEl: null, cache: {} };
  history.pushState({ dujStep: step, dujState: snap }, '');
  renderStep(step);
}

/* ─── Render dispatcher ─── */
function renderStep(step) {
  state.step = step;
  app.innerHTML = '';

  switch (step) {
    case 0: return renderCalendar();
    case 1: return renderSlots();
    case 2: return renderService();
    case 3: return renderDetails();
    case 4: return renderPayment();
    case 5: return renderResult();
  }
}

/* ─── Step indicator ─── */
function buildStepBar(active) {
  const labels = [i18n.selectDay, i18n.selectSlot, i18n.selectService, i18n.fillDetails, i18n.payment];
  const bar = el('div', { className: 'duj-steps', role: 'list' });
  labels.forEach((label, i) => {
    const step = el('div', { className: `duj-step${i < active ? ' duj-step--done' : i === active ? ' duj-step--active' : ''}`, role: 'listitem' });
    const num  = el('span', { className: 'duj-step__num', textContent: i < active ? '✓' : String(i + 1) });
    const txt  = el('span', { className: 'duj-step__label', textContent: label });
    step.append(num, txt);
    if (i < labels.length - 1) {
      step.append(el('span', { className: 'duj-step__sep', 'aria-hidden': 'true' }));
    }
    bar.append(step);
  });
  return bar;
}

/* ─── Pricing header ─── */
function buildPricingHeader() {
  const prices = state.prices ?? {};
  const wrap = el('div', { className: 'duj-pricing-header' });
  const tiers = el('div', { className: 'duj-pricing-tiers' });

  const PUBLIC_PRICES = { sud: 150000, sauna: 150000, 'sauna+sud': 200000 };
  const GUEST_PRICES  = { sud: 100000, sauna: 100000, 'sauna+sud': 150000 };

  const pubLine = el('span');
  pubLine.innerHTML = `<strong>${i18n.pricePublic}:</strong> ${formatPrice(PUBLIC_PRICES.sud)} / ${formatPrice(PUBLIC_PRICES.sauna)} / ${formatPrice(PUBLIC_PRICES['sauna+sud'])}`;

  const guestLine = el('span');
  guestLine.innerHTML = `<strong>${i18n.priceGuest}:</strong> ${formatPrice(GUEST_PRICES.sud)} / ${formatPrice(GUEST_PRICES.sauna)} / ${formatPrice(GUEST_PRICES['sauna+sud'])}`;

  tiers.append(pubLine, guestLine);
  wrap.append(tiers);

  // Access code toggle
  const codeWrap = el('div', { style: 'flex-basis:100%' });
  const codeToggle = el('button', { className: 'duj-code-toggle', type: 'button', textContent: i18n.guestCode });

  const codeForm = el('div', { className: 'duj-code-form', hidden: true });
  const codeInput = el('input', { type: 'text', placeholder: '••••••', 'aria-label': i18n.guestCode, autocomplete: 'off' });
  const codeBtn   = el('button', { className: 'duj-btn', type: 'button', textContent: i18n.continue });
  const feedback  = el('div', { className: 'duj-code-feedback', role: 'status' });

  codeForm.append(codeInput, codeBtn);

  codeToggle.addEventListener('click', () => {
    codeForm.hidden = !codeForm.hidden;
    if (!codeForm.hidden) codeInput.focus();
  });

  codeBtn.addEventListener('click', async () => {
    const code = codeInput.value.trim();
    if (!code) return;
    codeBtn.disabled = true;
    try {
      const res = await apiFetch(`access-codes/validate?code=${encodeURIComponent(code)}`);
      if (!res.valid) throw new Error('invalid');
      state.accessCode = res.valid_code ?? code;
      state.tier = res.tier ?? 'public';
      state.codeValid = true;
      feedback.textContent = i18n.validCode;
      feedback.className = 'duj-code-feedback duj-code-feedback--ok';
    } catch {
      state.codeValid = false;
      feedback.textContent = i18n.invalidCode;
      feedback.className = 'duj-code-feedback duj-code-feedback--err';
    } finally {
      codeBtn.disabled = false;
    }
  });

  if (state.codeValid === true) {
    feedback.textContent = i18n.validCode;
    feedback.className = 'duj-code-feedback duj-code-feedback--ok';
    codeForm.hidden = false;
    codeInput.value = state.accessCode;
  }

  codeWrap.append(codeToggle, codeForm, feedback);
  wrap.append(codeWrap);
  return wrap;
}

/* ─── STEP 0: Calendar ─── */
async function renderCalendar() {
  app.append(buildPricingHeader(), buildStepBar(0));

  const calWrap = el('div', { className: 'duj-calendar', role: 'grid', 'aria-label': 'Kalendář rezervací' });
  app.append(calWrap);

  await renderMonth(calWrap, state.currentYear, state.currentMonth);
}

async function renderMonth(calWrap, year, month) {
  calWrap.innerHTML = '';

  const key = `${year}-${String(month).padStart(2,'0')}`;
  if (!state.cache[key]) {
    const from = isoDate(year, month, 1);
    const lastDay = new Date(year, month, 0).getDate();
    const to   = isoDate(year, month, lastDay);
    const code = state.accessCode || '';
    try {
      const data = await apiFetch(`availability?from=${from}&to=${to}${code ? '&code='+encodeURIComponent(code) : ''}`);
      state.cache[key] = {};
      (data.days ?? []).forEach(d => { state.cache[key][d.date] = d; });
      if (data.tier) state.tier = data.tier;
    } catch {
      state.cache[key] = {};
    }
  }

  const dayMap = state.cache[key];
  const today  = new Date();
  const todayStr = isoDate(today.getFullYear(), today.getMonth()+1, today.getDate());
  const maxDate  = new Date(today);
  maxDate.setMonth(maxDate.getMonth() + state.months);

  // Nav
  const nav = el('div', { className: 'duj-calendar__nav' });
  const prevBtn = el('button', { type: 'button', 'aria-label': 'Předchozí měsíc', textContent: '‹' });
  const nextBtn = el('button', { type: 'button', 'aria-label': 'Následující měsíc', textContent: '›' });
  const title   = el('span', { className: 'duj-calendar__title', textContent: `${MONTHS_CS[month-1]} ${year}` });

  const prevY = month === 1 ? year - 1 : year;
  const prevM = month === 1 ? 12 : month - 1;
  const nextY = month === 12 ? year + 1 : year;
  const nextM = month === 12 ? 1 : month + 1;

  const isBeforeToday = new Date(year, month-1, 1) <= new Date(today.getFullYear(), today.getMonth(), 1);
  const isBeyondMax   = new Date(nextY, nextM-1, 1) > maxDate;

  prevBtn.disabled = isBeforeToday;
  nextBtn.disabled = isBeyondMax;

  prevBtn.addEventListener('click', () => {
    state.currentYear = prevY; state.currentMonth = prevM;
    renderMonth(calWrap, prevY, prevM);
  });
  nextBtn.addEventListener('click', () => {
    state.currentYear = nextY; state.currentMonth = nextM;
    renderMonth(calWrap, nextY, nextM);
  });

  nav.append(prevBtn, title, nextBtn);
  calWrap.append(nav);

  // Weekday headers
  const grid = el('div', { className: 'duj-calendar__grid' });
  DAYS_CS.forEach(d => {
    grid.append(el('div', { className: 'duj-calendar__weekday', textContent: d, role: 'columnheader' }));
  });

  // Days
  const firstDow = new Date(year, month-1, 1).getDay(); // 0=Sun
  const startOffset = firstDow === 0 ? 6 : firstDow - 1; // Convert to Mon=0
  const daysInMonth = new Date(year, month, 0).getDate();

  for (let i = 0; i < startOffset; i++) {
    grid.append(el('div', { className: 'duj-calendar__day duj-calendar__day--empty', 'aria-hidden': 'true' }));
  }

  for (let d = 1; d <= daysInMonth; d++) {
    const dateStr = isoDate(year, month, d);
    const dayInfo = dayMap[dateStr];
    const isPast  = dateStr < todayStr;
    const isBeyond = dateStr > isoDate(maxDate.getFullYear(), maxDate.getMonth()+1, maxDate.getDate());

    let cls = 'duj-calendar__day';
    let ariaLabel = `${d}. ${MONTHS_CS[month-1]} ${year}`;
    let disabled = false;
    let clickable = false;

    // A day is bookable only if at least one slot has at least one available combo.
    // This is checked directly from slot data rather than trusting the `status` string,
    // so stale API caches or unexpected status values don't produce phantom green days.
    const hasBookable = !!(dayInfo?.slots?.some(s => (s.available_combos?.length ?? 0) > 0));

    if (isPast || isBeyond) {
      cls += ' duj-calendar__day--past';
      disabled = true;
    } else if (!hasBookable) {
      cls += ' duj-calendar__day--closed';
      disabled = true;
      ariaLabel += ` — ${i18n.closed}`;
    } else {
      cls += ' duj-calendar__day--available';
      clickable = true;
      ariaLabel += ` — ${i18n.available}`;
    }

    if (dateStr === state.selectedDate) {
      cls += ' duj-calendar__day--selected';
    }

    const cell = el('div', {
      className: cls,
      role: 'gridcell',
      'aria-label': ariaLabel,
      'aria-selected': dateStr === state.selectedDate ? 'true' : 'false',
      'aria-disabled': disabled ? 'true' : 'false',
      tabIndex: disabled ? -1 : 0,
    });

    cell.append(el('span', { className: 'duj-calendar__day-num', textContent: String(d) }));
    if (clickable) cell.append(el('span', { className: 'duj-calendar__day-dot', 'aria-hidden': 'true' }));

    if (clickable) {
      const onSelect = () => {
        state.selectedDate = dateStr;
        pushStep(1);
      };
      cell.addEventListener('click', onSelect);
      cell.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSelect(); }
        if (e.key === 'ArrowRight') (cell.nextElementSibling)?.focus();
        if (e.key === 'ArrowLeft')  (cell.previousElementSibling)?.focus();
        if (e.key === 'ArrowDown') {
          const cells = [...grid.querySelectorAll('[role="gridcell"]:not([aria-disabled="true"])')];
          const idx   = cells.indexOf(cell);
          cells[idx + 7]?.focus();
        }
        if (e.key === 'ArrowUp') {
          const cells = [...grid.querySelectorAll('[role="gridcell"]:not([aria-disabled="true"])')];
          const idx   = cells.indexOf(cell);
          cells[idx - 7]?.focus();
        }
      });
    }

    grid.append(cell);
  }

  calWrap.append(grid);
}

/* ─── STEP 1: Slots ─── */
async function renderSlots() {
  app.append(buildPricingHeader(), buildStepBar(1));
  app.append(el('div', { className: 'duj-notice duj-notice--info', textContent: `${state.selectedDate}` }));

  const loading = el('div', { className: 'duj-wellness__skeleton', 'aria-label': i18n.loading });
  app.append(loading);

  try {
    const code = state.accessCode || '';
    const data = await apiFetch(
      `availability?from=${state.selectedDate}&to=${state.selectedDate}${code ? '&code='+encodeURIComponent(code) : ''}`
    );
    const dayInfo = (data.days ?? []).find(d => d.date === state.selectedDate);
    state.slots = dayInfo?.slots ?? [];
    if (data.tier) state.tier = data.tier;
  } catch {
    state.slots = [];
  }

  loading.remove();

  const slotsWrap = el('div', { className: 'duj-slots' });
  slotsWrap.append(el('h3', { className: 'duj-slots__title', textContent: i18n.selectSlot }));

  if (state.slots.length === 0) {
    slotsWrap.append(el('div', { className: 'duj-notice duj-notice--info', textContent: i18n.noSlotsAvailable }));
  } else {
    const grid = el('div', { className: 'duj-slots__grid' });
    state.slots.forEach(slot => {
      const isAvail = slot.available_combos?.length > 0;
      const cls = `duj-slot-card${!isAvail ? ' duj-slot-card--unavailable' : ''}${
        state.selectedSlot?.from === slot.from ? ' duj-slot-card--selected' : ''
      }`;
      const card = el('button', {
        type: 'button',
        className: cls,
        disabled: !isAvail,
        'aria-pressed': state.selectedSlot?.from === slot.from ? 'true' : 'false',
      });
      card.append(
        el('div', { className: 'duj-slot-card__time', textContent: `${slot.from.slice(0,5)}–${slot.to.slice(0,5)}` }),
        el('div', { className: 'duj-slot-card__label', textContent: isAvail ? i18n.available : i18n.fullyBooked }),
      );
      if (isAvail) {
        card.addEventListener('click', () => {
          state.selectedSlot = { from: slot.from, to: slot.to };
          state.prices = slot.prices ?? null;
          pushStep(2);
        });
      }
      grid.append(card);
    });
    slotsWrap.append(grid);
  }

  app.append(slotsWrap);
  app.append(backBtn(0));
}

/* ─── STEP 2: Service ─── */
function renderService() {
  app.append(buildPricingHeader(), buildStepBar(2));

  const servicesWrap = el('div', { className: 'duj-services' });
  servicesWrap.append(el('h3', { className: 'duj-slots__title', textContent: i18n.selectService }));

  const grid = el('div', { className: 'duj-services__grid' });

  const COMBO_DEFS = [
    { key: 'sud',      label: i18n.sud,      emoji: '🛁' },
    { key: 'sauna',    label: i18n.sauna,    emoji: '🧖' },
    { key: 'sauna+sud', label: i18n.saunaSud, emoji: '🔥🛁' },
  ];

  const slot = state.slots.find(s => s.from === state.selectedSlot?.from);
  const availCombos = slot?.available_combos ?? [];
  const prices = state.prices ?? {};

  COMBO_DEFS.forEach(({ key, label, emoji }) => {
    // Filter by service attr
    if (state.service !== 'all') {
      if (state.service === 'sud'   && key !== 'sud') return;
      if (state.service === 'sauna' && key !== 'sauna') return;
    }

    const isAvail = availCombos.includes(key);
    const price   = prices[key];
    const isGuest = state.tier === 'guest';

    const cls = `duj-service-card${!isAvail ? ' duj-service-card--unavailable' : ''}${
      state.selectedCombo === key ? ' duj-service-card--selected' : ''
    }`;

    const card = el('button', {
      type: 'button',
      className: cls,
      disabled: !isAvail,
      'aria-pressed': state.selectedCombo === key ? 'true' : 'false',
    });

    card.append(el('div', { className: 'duj-service-card__name', textContent: `${emoji} ${label}` }));
    if (price != null) {
      card.append(el('div', { className: 'duj-service-card__price', textContent: formatPrice(price) }));
      if (isGuest) {
        card.append(el('div', { className: 'duj-service-card__tier-label', textContent: `(${i18n.priceGuest})` }));
      }
    }
    if (!isAvail) {
      card.append(el('div', { className: 'duj-service-card__reason', textContent: i18n.fullyBooked }));
    }

    if (isAvail) {
      card.addEventListener('click', () => {
        state.selectedCombo = key;
        pushStep(3);
      });
    }

    grid.append(card);
  });

  servicesWrap.append(grid);
  app.append(servicesWrap, backBtn(1));
}

/* ─── STEP 3: Details ─── */
function renderDetails() {
  app.append(buildPricingHeader(), buildStepBar(3));

  const form = el('form', { className: 'duj-form', noValidate: true });

  const fields = [
    { id: 'duj-name',  label: 'Jméno a příjmení *', type: 'text',  key: 'customerName',  required: true, autocomplete: 'name' },
    { id: 'duj-email', label: 'E-mail *',            type: 'email', key: 'customerEmail', required: true, autocomplete: 'email' },
    { id: 'duj-phone', label: 'Telefon *',           type: 'tel',   key: 'customerPhone', required: true, autocomplete: 'tel', inputmode: 'tel' },
    { id: 'duj-guests',label: 'Počet osob *',        type: 'number',key: 'guests',        required: true, min: 1, max: 10 },
    { id: 'duj-note',  label: 'Poznámka',            type: 'textarea', key: 'note', required: false },
  ];

  const fieldEls = {};

  fields.forEach(f => {
    const fieldWrap = el('div', { className: 'duj-field' });
    const label = el('label', { htmlFor: f.id, textContent: f.label });
    let input;
    if (f.type === 'textarea') {
      input = el('textarea', { id: f.id, name: f.key, rows: 3 });
      input.value = state[f.key] ?? '';
    } else {
      input = el('input', { id: f.id, type: f.type, name: f.key, required: f.required });
      if (f.autocomplete) input.autocomplete = f.autocomplete;
      if (f.inputmode) input.inputMode = f.inputmode;
      if (f.min != null) input.min = f.min;
      if (f.max != null) input.max = f.max;
      input.value = state[f.key] ?? '';
    }
    const errEl = el('div', { className: 'duj-field__error', role: 'alert' });
    fieldWrap.append(label, input, errEl);
    form.append(fieldWrap);
    fieldEls[f.key] = { input, errEl, required: f.required };
  });

  // Consent
  const consentWrap = el('label', { className: 'duj-consent' });
  const consentCheck = el('input', { type: 'checkbox', id: 'duj-consent' });
  consentCheck.checked = state.consent ?? false;
  const vopUrl = cfg.homeUrl ? cfg.homeUrl.replace(/\/?$/, '/vop/') : '/vop/';
  const consentText = document.createElement('span');
  consentText.innerHTML = `${i18n.consentPrefix} <a href="${vopUrl}" target="_blank" rel="noopener">${i18n.consentLink}</a>`;
  const consentErr = el('div', { className: 'duj-field__error', role: 'alert' });
  consentWrap.append(consentCheck, consentText);
  form.append(consentWrap, consentErr);

  const errNotice = el('div', { className: 'duj-notice duj-notice--error', hidden: true, role: 'alert' });
  form.append(errNotice);

  const btnRow = el('div', { className: 'duj-btn-row' });
  const backB  = el('button', { type: 'button', className: 'duj-btn duj-btn--secondary', textContent: i18n.back });
  const nextB  = el('button', { type: 'submit', className: 'duj-btn', textContent: i18n.continue });
  backB.addEventListener('click', () => pushStep(2));
  btnRow.append(backB, nextB);
  form.append(btnRow);

  form.addEventListener('submit', e => {
    e.preventDefault();
    let valid = true;

    fields.forEach(f => {
      const { input, errEl, required } = fieldEls[f.key];
      const val = input.value.trim();
      errEl.textContent = '';
      input.removeAttribute('aria-invalid');

      if (required && val === '') {
        errEl.textContent = 'Toto pole je povinné.';
        input.setAttribute('aria-invalid', 'true');
        valid = false;
      } else if (f.type === 'email' && val && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
        errEl.textContent = 'Zadejte platnou e-mailovou adresu.';
        input.setAttribute('aria-invalid', 'true');
        valid = false;
      } else if (f.type === 'tel' && val && !/^[\d\s+\-()]{7,15}$/.test(val)) {
        errEl.textContent = 'Zadejte platné telefonní číslo.';
        input.setAttribute('aria-invalid', 'true');
        valid = false;
      }
      state[f.key] = f.type === 'number' ? parseInt(val, 10) || 1 : val;
    });

    if (!consentCheck.checked) {
      consentErr.textContent = i18n.consentRequired;
      valid = false;
    } else {
      consentErr.textContent = '';
      state.consent = true;
    }

    if (!valid) return;

    pushStep(4);
  });

  app.append(form);
}

/* ─── STEP 4: Payment ─── */
async function renderPayment() {
  app.append(buildStepBar(4));

  // Recap
  const slot = state.selectedSlot;
  const recap = el('div', { className: 'duj-recap' });
  const recapRows = [
    ['Datum', state.selectedDate],
    ['Čas',   slot ? `${slot.from.slice(0,5)}–${slot.to.slice(0,5)}` : ''],
    ['Služba', comboLabel(state.selectedCombo)],
    ['Zákazník', state.customerName],
    ['E-mail', state.customerEmail],
  ];
  recapRows.forEach(([k, v]) => {
    const row = el('div', { className: 'duj-recap__row' });
    row.append(el('span', { textContent: k }), el('strong', { textContent: v ?? '' }));
    recap.append(row);
  });
  const price = state.prices?.[state.selectedCombo ?? ''];
  if (price != null) {
    const totalRow = el('div', { className: 'duj-recap__row duj-recap__row--total' });
    totalRow.append(el('span', { textContent: 'Celkem' }), el('strong', { textContent: formatPrice(price) }));
    recap.append(totalRow);
  }
  app.append(recap);

  // Hold timer — shown only after booking is created
  const timerWrap = el('div', { className: 'duj-hold-timer', hidden: true });
  const timerVal  = el('span', { className: 'duj-hold-timer__time', textContent: '—' });
  timerWrap.append(el('span', { textContent: i18n.holdTimer + ' ' }), timerVal);
  app.append(timerWrap);

  const errNotice = el('div', { className: 'duj-notice duj-notice--error', hidden: true, role: 'alert' });
  app.append(errNotice);

  // If booking already created (user navigated back and forward)
  if (state.bookingId) {
    if (state.holdExpiresAt) { timerWrap.hidden = false; startTimer(timerVal, new Date(state.holdExpiresAt)); }
    if (state.paymentMethod === 'bank_transfer') { showBankDetails(state.bankPayment); return; }
    if (state.clientSecret) { mountStripeElement(); return; }
  }

  // Payment method selector
  const hasCreditCard = !!STRIPE_KEY;
  const methods = hasCreditCard
    ? [{ value: 'stripe_card', label: i18n.payStripeCard ?? 'Platba kartou' },
       { value: 'bank_transfer', label: i18n.payBankTransfer ?? 'Bankovní převod / QR platba' }]
    : [{ value: 'bank_transfer', label: i18n.payBankTransfer ?? 'Bankovní převod / QR platba' }];

  let selectedMethod = methods[0].value;

  const methodsWrap = el('fieldset', { className: 'duj-payment-methods' });
  const legend = el('legend', { className: 'duj-payment-methods__legend', textContent: 'Způsob platby' });
  methodsWrap.append(legend);
  methods.forEach(m => {
    const id  = `duj-pm-${m.value}`;
    const wrap = el('div', { className: 'duj-payment-method' });
    const radio = el('input', { type: 'radio', name: 'duj_payment_method', id });
    radio.value   = m.value;
    radio.checked = m.value === selectedMethod;
    radio.addEventListener('change', () => { selectedMethod = m.value; });
    const label = el('label', { htmlFor: id, textContent: m.label });
    wrap.append(radio, label);
    methodsWrap.append(wrap);
  });
  app.append(methodsWrap);

  const payBtn = el('button', { type: 'button', className: 'duj-btn duj-btn--full', textContent: i18n.pay ?? 'Pokračovat k platbě' });
  app.append(payBtn);
  app.append(backBtn(3));

  payBtn.addEventListener('click', async () => {
    payBtn.disabled = true;
    errNotice.hidden = true;

    try {
      const res = await apiFetch('bookings', {
        method: 'POST',
        body: JSON.stringify({
          booking_date:   state.selectedDate,
          slot_from:      state.selectedSlot.from,
          combo_key:      state.selectedCombo,
          customer_name:  state.customerName,
          customer_email: state.customerEmail,
          customer_phone: state.customerPhone,
          guests:         state.guests,
          customer_note:  state.note,
          payment_method: selectedMethod,
          code:           state.accessCode || undefined,
          consent_at:     new Date().toISOString(),
        }),
      });

      state.bookingId     = res.booking_id;
      state.bookingRef    = res.reference;
      state.paymentMethod = selectedMethod;
      state.holdExpiresAt = res.payment?.hold_expires_at ?? res.hold_expires_at ?? null;

      methodsWrap.remove();
      payBtn.remove();

      if (state.holdExpiresAt) { timerWrap.hidden = false; startTimer(timerVal, new Date(state.holdExpiresAt)); }

      if (selectedMethod === 'bank_transfer') {
        state.bankPayment = res.payment;
        showBankDetails(res.payment);
      } else {
        state.clientSecret = res.payment?.client_secret ?? null;
        mountStripeElement();
      }
    } catch (err) {
      payBtn.disabled = false;
      errNotice.textContent = err.code === 'slot_taken'
        ? i18n.slotTaken
        : (err.message || i18n.errorGeneric);
      errNotice.hidden = false;
    }
  });

  function showBankDetails(payment) {
    const div = el('div', { className: 'duj-bank-transfer' });
    div.append(el('h3', { className: 'duj-bank-transfer__title', textContent: 'Platební údaje' }));
    const rows = [
      payment?.iban           ? ['IBAN', payment.iban] : null,
      payment?.account_number ? ['Číslo účtu', payment.account_number] : null,
      payment?.variable_symbol ? ['Variabilní symbol', payment.variable_symbol] : null,
      price != null           ? ['Částka', formatPrice(price)] : null,
      payment?.hold_expires_at
        ? ['Uhraďte do', new Date(payment.hold_expires_at).toLocaleString('cs-CZ', { dateStyle: 'short', timeStyle: 'short' })]
        : payment?.hold_hours ? ['Splatnost', `${payment.hold_hours} hodin od rezervace`] : null,
    ].filter(Boolean);
    rows.forEach(([k, v]) => {
      const row = el('div', { className: 'duj-recap__row' });
      row.append(el('span', { textContent: k }), el('strong', { textContent: v }));
      div.append(row);
    });

    if (payment?.qr_uri) {
      const qrWrap = el('div', { className: 'duj-bank-transfer__qr' });
      const qrImg  = el('img', { src: payment.qr_uri, alt: 'QR platba', width: 220, height: 220 });
      const qrNote = el('p', { className: 'duj-bank-transfer__qr-note', textContent: 'Naskenujte kód svou bankovní aplikací (QR Platba)' });
      qrWrap.append(qrImg, qrNote);
      div.append(qrWrap);
    }

    const note = el('p', { className: 'duj-bank-transfer__note' });
    note.textContent = 'Po přijetí platby vám zašleme potvrzení e-mailem.';
    div.append(note);
    app.append(div);
  }

  function mountStripeElement() {
    const stripeWrap = el('div', { id: 'duj-stripe-element' });
    app.append(stripeWrap);

    const submitBtn = el('button', { type: 'button', className: 'duj-btn duj-btn--full', textContent: i18n.pay ?? 'Zaplatit' });
    submitBtn.disabled = true;
    app.append(submitBtn);

    loadStripe().then(stripe => {
      if (!stripe) throw new Error('Stripe.js nelze načíst');
      state.stripe = stripe;
      const elements = stripe.elements({ clientSecret: state.clientSecret, locale: 'cs' });
      state.elements = elements;
      const paymentEl = elements.create('payment', { layout: 'tabs' });
      state.paymentEl = paymentEl;
      paymentEl.mount(stripeWrap);
      paymentEl.on('ready', () => { submitBtn.disabled = false; });
      paymentEl.on('loaderror', () => { errNotice.textContent = i18n.errorGeneric; errNotice.hidden = false; });

      submitBtn.addEventListener('click', async () => {
        if (!state.stripe || !state.elements) return;
        submitBtn.disabled = true;
        errNotice.hidden = true;
        const { error } = await state.stripe.confirmPayment({
          elements: state.elements,
          confirmParams: { return_url: window.location.href },
          redirect: 'if_required',
        });
        if (error) {
          errNotice.textContent = error.message ?? i18n.errorGeneric;
          errNotice.hidden = false;
          submitBtn.disabled = false;
        } else {
          clearInterval(state.timerHandle);
          pushStep(5);
        }
      });
    }).catch(() => {
      errNotice.textContent = i18n.errorGeneric;
      errNotice.hidden = false;
    });
  }
}

function startTimer(el, expiresAt) {
  clearInterval(state.timerHandle);
  const update = () => {
    const diff = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
    const m = Math.floor(diff / 60);
    const s = diff % 60;
    el.textContent = `${m}:${String(s).padStart(2,'0')}`;
    if (diff === 0) clearInterval(state.timerHandle);
  };
  update();
  state.timerHandle = setInterval(update, 1000);
}

async function loadStripe() {
  if (!STRIPE_KEY) return null;
  if (!document.querySelector('script[src*="js.stripe.com"]')) {
    await new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://js.stripe.com/v3/';
      s.onload = resolve;
      s.onerror = reject;
      document.head.append(s);
    });
  }
  return window.Stripe?.(STRIPE_KEY) ?? null;
}

/* ─── STEP 5: Result ─── */
function renderResult() {
  clearInterval(state.timerHandle);

  const div = el('div', { className: 'duj-result', role: 'status' });
  div.append(
    el('div', { className: 'duj-result__icon', textContent: '✅', 'aria-hidden': 'true' }),
    el('h3', { className: 'duj-result__title', textContent: i18n.bookingConfirmed }),
    el('p',  { className: 'duj-result__text', textContent: i18n.thankYou }),
  );
  if (state.bookingRef) {
    div.append(el('p', { className: 'duj-result__text', textContent: `Číslo rezervace: ${state.bookingRef}` }));
  }
  app.append(div);
}

/* ─── Availability widget ─── */
async function renderAvailabilityWidget(count) {
  const ul = el('ul', { className: 'duj-avail-list', 'aria-label': 'Nejbližší volné termíny' });
  app.innerHTML = '';
  const loading = el('div', { className: 'duj-wellness__skeleton' });
  app.append(loading);

  try {
    const today = new Date();
    const to = new Date(today); to.setMonth(to.getMonth() + 3);
    const from = isoDate(today.getFullYear(), today.getMonth()+1, today.getDate());
    const toStr = isoDate(to.getFullYear(), to.getMonth()+1, to.getDate());
    const data = await apiFetch(`availability?from=${from}&to=${toStr}`);
    const avail = (data.days ?? []).filter(d => d.status !== 'closed' && d.status !== 'fully_booked' && (d.slots?.length ?? 0) > 0).slice(0, count);
    loading.remove();

    if (avail.length === 0) {
      app.append(el('div', { className: 'duj-notice duj-notice--info', textContent: i18n.noSlotsAvailable }));
      return;
    }

    avail.forEach(d => {
      const li = el('li', { className: 'duj-avail-item' });
      li.append(
        el('span', { className: 'duj-avail-item__date', textContent: d.date }),
        el('span', { className: 'duj-avail-item__slots', textContent: `${d.slots.length} termín${d.slots.length > 1 ? 'y' : ''}` }),
      );
      ul.append(li);
    });
    app.append(ul);
  } catch {
    loading.remove();
    app.append(el('div', { className: 'duj-notice duj-notice--error', textContent: i18n.errorGeneric }));
  }
}

/* ─── Helpers ─── */
function el(tag, props = {}) {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(props)) {
    if (k === 'textContent') e.textContent = v;
    else if (k === 'className') e.className = v;
    else if (k === 'htmlFor') e.htmlFor = v;
    else if (k === 'hidden') e.hidden = v;
    else if (k === 'disabled') e.disabled = v;
    else if (k.startsWith('aria-') || k === 'role' || k === 'type' || k === 'id' || k === 'name'
          || k === 'min' || k === 'max' || k === 'rows' || k === 'placeholder'
          || k === 'autocomplete' || k === 'inputmode' || k === 'noValidate') {
      if (k === 'noValidate') e.noValidate = v;
      else e.setAttribute(k, v);
    }
    else e[k] = v;
  }
  return e;
}

function backBtn(toStep) {
  const btn = el('button', { type: 'button', className: 'duj-btn duj-btn--secondary', textContent: i18n.back });
  btn.style.marginTop = '1rem';
  btn.addEventListener('click', () => pushStep(toStep));
  return btn;
}

function comboLabel(key) {
  const map = { 'sud': i18n.sud, 'sauna': i18n.sauna, 'sauna+sud': i18n.saunaSud };
  return map[key] ?? key ?? '';
}

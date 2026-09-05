# Rezervační systém wellness — Domeček u Josefa

**Zadání pro implementaci (Claude Code)**
Verze 1.1 · Cílový web: https://domecekujosefa.cz (WordPress) · Jazyk UI: čeština · Měna: CZK

> **Změny ve v1.1** oproti v1.0: doplněny **cenové hladiny** (ubytovaní 1 000 Kč vs. veřejnost 1 500 Kč), **konfigurovatelná délka slotu a technická pauza mezi sloty**, **vazba na potvrzené rezervace ubytování**. Dotčené kapitoly: ADR-5, ADR-6, ADR-9, ADR-10, 4.4, 4.5, 4.6, 4.11, 5.2, 5.5, 9, 10, 13.

---

## 0. Shrnutí pro netrpělivé

Postavíme **samostatný WordPress plugin** `duj-wellness` (vlastní tabulky, vlastní REST API, vlastní admin), který:

- zobrazí rezervační formulář kdekoliv na webu přes shortcode `[duj_wellness_booking]`,
- řídí dostupnost dvou zdrojů (**koupací sud**, **sauna**) ve slotech s konfigurovatelnou délkou (výchozí 2 h) a povinnou technickou pauzou mezi nimi (výchozí 1 h na zatopení a úklid),
- rozlišuje **cenové hladiny**: veřejnost 1 500 / 1 500 / 2 000 Kč, ubytovaní hosté 1 000 / 1 000 / 1 500 Kč (přes kód),
- umí omezit dostupnost wellness ve dnech s **potvrzenou rezervací ubytování** (výchozí režim: vyhrazeno ubytovaným),
- vybere platbu přes **Stripe** (karta + Apple Pay + Google Pay + QR do mobilu), volitelně české **QR platbě** (SPAYD),
- používá **manuální capture** (peníze se blokují, strhnou se až po potvrzení správcem, při zamítnutí se blokace zruší → žádné refundace),
- pošle e-maily zákazníkovi i správci, správce potvrdí/zamítne **jedním klikem z e-mailu** (podepsaný jednorázový token),
- volitelně notifikuje správce přes **Telegram / SMS / WhatsApp**.

**Klíčová architektonická rozhodnutí jsou v kapitole 2, otevřené otázky v kapitole 3 — ty vyřeš dřív, než začneš kódovat.**

---

## 1. Kontext

### 1.1 Stávající stav webu

| Věc | Stav |
|---|---|
| CMS | WordPress, one-page téma (design AKpro) |
| Rezervace ubytování | plugin WP Booking System (`wpbs-booking-form`), sekce `#rezervace` |
| Platby | už dnes Stripe + bankovní převod |
| Kontakt | domecekujosefa@gmail.com, +420 773 454 854 |
| Provozovatel | Miroslav Minařík, Hostín 7, 277 32 Hostín |
| Wellness dnes na webu | „koupací sud a sauna pro až 6 osob", uvedená cena **1000 Kč/den** za sud i saunu |
| Hosting | Hostinger (Cloud Startup / Premium) |

> ⚠️ **Nesoulad:** web dnes inzeruje 1000 Kč, zadání říká 1500 Kč (sud/sauna) a 2000 Kč (kombo). Ceny musí být plně konfigurovatelné v adminu a texty na webu je potřeba sjednotit. Viz otevřená otázka O1.

### 1.2 Rozsah (scope)

**V rozsahu:** rezervace wellness (sud / sauna / kombo), rozvrh slotů, ceník, online platba, potvrzovací workflow, e-mailové šablony, admin, notifikace správci.

**Mimo rozsah:** rezervace ubytování (zůstává na WP Booking System), snídaně, cykloservis, uživatelské účty zákazníků, věrnostní program.

---

## 2. Architektonická rozhodnutí (ADR)

### ADR-1 — Vlastní plugin, ne WooCommerce ani hotové řešení

**Rozhodnutí:** samostatný plugin s vlastními tabulkami.

**Proč:** WooCommerce + Bookings znamená ~600 tabulkových sloupců navíc, placené rozšíření, těžkou údržbu a boj s cizí doménovou logikou (kombo blokující dva zdroje současně, admin potvrzení, cutoff 12:00). Doména je malá a ostře vymezená — vlastní kód je tu levnější na napsání i na provoz. Zároveň nekoliduje se stávajícím WP Booking System.

**Důsledek:** neseme si vlastní migrace, vlastní admin UI, vlastní bezpečnost. Kompenzuje se to použitím nativních WP API (REST, `WP_List_Table`, `wp_mail`, capabilities, i18n).

### ADR-2 — Plugin v WP, ne samostatná aplikace na subdoméně

**Rozhodnutí:** vše uvnitř WordPressu, žádný iframe.

**Proč:** požadavek zní „vložit shortcodem do WP stránky". Iframe (jako u `rezervace.vintagelover.cz`) přináší problémy s výškou, sladěním stylu, cookies a Apple Pay domain verification. Nativní plugin dědí styl tématu zdarma a Apple Pay běží na hlavní doméně.

**Důsledek:** PHP 8.1+, žádný build step (vanilla JS + CSS), aby se plugin dal nasadit prostým nahráním.

### ADR-3 — Platba: Stripe s manuálním capture

**Rozhodnutí:** `PaymentIntent` s `capture_method: 'manual'`. Při rezervaci se částka **autorizuje** (blokuje na kartě), při potvrzení správcem se **strhne** (capture), při zamítnutí se **uvolní** (cancel).

**Proč:** požadavky „musí být hrazeny" + „musí být potvrzeny správcem" jsou v konfliktu. Manuální capture je řeší elegantně — zákazník nikdy neplatí za rezervaci, kterou provozovatel odmítne, a provozovatel nikdy nemusí refundovat.

**Omezení, se kterými musíš počítat:**
- Autorizace u karet platí typicky **7 dní**. Správce tedy musí potvrdit do 7 dní od vytvoření rezervace, ne až v den služby.
- Nutný cron, který 24 h před expirací autorizace upozorní správce a po expiraci rezervaci zruší + zákazníka informuje.
- Fallback: nastavení `payment_capture_mode = manual | automatic`. V `automatic` režimu se strhne hned a při zamítnutí se volá refund. Default = `manual`.

### ADR-4 — „QR kód" = dvě různé věci, implementuj obě

Zadání říká „kartou, QR kódem nebo Apple/Google Pay". To jsou technicky tři různé věci a QR je nejednoznačné:

| Metoda | Klíč | Jak funguje | Automatické? |
|---|---|---|---|
| Karta, Apple Pay, Google Pay | `stripe_card` | Stripe Payment Element přímo na stránce | ✅ ano |
| **QR do mobilu** (doporučeno jako výchozí význam „QR") | `qr_checkout` | Na desktopu se zobrazí QR s odkazem na Stripe Checkout Session; zákazník ho naskenuje telefonem a zaplatí Apple/Google Pay | ✅ ano |
| **QR platba (SPAYD)** — bankovní převod | `qr_bank` | Vygeneruje se český QR kód pro platbu; peníze dorazí na účet, správce ji musí spárovat ručně | ❌ ne, ruční |

**Doporučení:** zapni `stripe_card` + `qr_checkout` jako default. `qr_bank` naimplementuj, ale ve výchozím nastavení vypnutý — vede k ruční agendě a nedá se autorizovat (musí se hradit předem celá a při zamítnutí vracet).

### ADR-5 — Zamezení dvojité rezervace na úrovni databáze

**Rozhodnutí:** tabulka `duj_booking_items` (jeden řádek = jeden zdroj v jedné rezervaci) s nullovatelným sloupcem `blocking_key VARCHAR(191)` a **UNIQUE indexem**.

```
blocking_key = '{booking_date}|{slot_from}|{resource_id}'   -- když rezervace blokuje
blocking_key = NULL                                          -- zrušená/zamítnutá/expirovaná
```

MySQL v UNIQUE indexu povoluje více `NULL` hodnot, takže zrušené rezervace se nikdy nepobijou, ale dvě aktivní na stejný slot+zdroj databáze **fyzicky nedovolí**.

**Ale sám o sobě to nestačí.** Unique index hlídá jen *shodu* slotu, ne *překryv*. Jakmile má systém konfigurovatelnou délku slotu, technickou pauzu a možnost výjimek s vlastními časy, mohou vzniknout dva různé sloty, které se překrývají (např. běžný 16:00–18:00 a ručně vytvořená rezervace správcem na 17:00–19:00). Proto je obrana **dvouvrstvá**:

1. **UNIQUE `blocking_key`** — okamžitá, bezplatná ochrana běžného případu (stejný slot, stejný zdroj). Databáze ji vynutí vždy.
2. **Kontrola překryvu intervalů v transakci pod zámkem** — `duj_booking_items` navíc nese `blocked_from` a `blocked_to` (UTC, včetně technické pauzy). Před insertem se v jedné transakci zamkne řádek dne a zdroje a ověří, že se nový interval s ničím aktivním nepřekrývá.

```sql
START TRANSACTION;
  INSERT IGNORE INTO duj_day_locks (lock_date, resource_id) VALUES (:date, :rid);
  SELECT id FROM duj_day_locks
    WHERE lock_date = :date AND resource_id = :rid FOR UPDATE;   -- serializace
  -- overlap check: existuje aktivní item, kde blocked_from < :to AND blocked_to > :from ?
  INSERT INTO duj_booking_items (…, blocking_key, blocked_from, blocked_to) VALUES (…);
COMMIT;
```

(Alternativa k `duj_day_locks` je `GET_LOCK()`, ale ta neparticipuje na transakci — zámková tabulka je čistší.)

**Důsledek:** insert booking_items obal do try/catch; při duplicate key i při nalezeném překryvu vrať HTTP 409 s hláškou „Termín byl právě obsazen, vyberte prosím jiný."

**Kombo** = dvě řádky v `duj_booking_items` (sud + sauna) → automaticky blokuje oba zdroje. Přesně jak zadání vyžaduje, bez speciální logiky.

### ADR-6 — Rozvrh se počítá, neukládá; sloty a pauza jsou konfigurovatelné

**Rozhodnutí:** neukládat předgenerované sloty na roky dopředu. Dostupné sloty pro datum D se počítají za běhu z:

1. **Pravidel** (`duj_schedule_rules`) — „každé pondělí 16:00–18:00 a 19:00–21:00, platné od …do…" → plošná změna.
2. **Výjimek** (`duj_schedule_overrides`) — konkrétní datum: zavřeno / jiné sloty → změna pro den nebo týden.
3. **Vazby na ubytování** (viz ADR-10) — dny s potvrzenou rezervací apartmánu.

Priorita: **výjimka pro datum > politika ubytování > pravidla.**

**Délka slotu a technická pauza.** Sloty se v pravidlech nadále ukládají explicitně (`time_from`, `time_to`), protože to je nejsrozumitelnější a nejflexibilnější. Konfigurovatelnost se řeší dvěma věcmi:

- **Nastavení:** `default_slot_minutes` (výchozí 120) a `buffer_minutes` (výchozí 60 — zatopení, úklid, výměna ručníků).
- **Generátor slotů v adminu:** správce zadá okno (16:00–21:00), délku slotu a pauzu, systém vygeneruje sloty (16:00–18:00, 19:00–21:00) a vloží je jako pravidla. Nemusí je klikat ručně, ale může je pak jednotlivě upravit.

**Pauza není slot, je to prodloužení blokace.** Rezervace 16:00–18:00 obsadí zdroj do 19:00 (`blocked_to = slot_to + buffer_minutes`). Intervaly jsou polouzavřené `[from, to)`, takže navazující slot v 19:00 je volný. Díky tomu platí:

- pauza se **nikdy nezobrazí zákazníkovi** jako termín,
- pauza se vynutí i u ručně vytvořené rezervace správcem mimo rozvrh,
- změna `buffer_minutes` ovlivní jen nové rezervace (u existujících je interval uložený).

**Validace při ukládání rozvrhu:** dva sloty pro stejný zdroj ve stejný den se po prodloužení o pauzu nesmí překrývat. Admin to musí odmítnout s konkrétní hláškou („Slot 18:00–20:00 koliduje s 16:00–18:00 — mezi sloty musí být alespoň 60 minut.").

**Ale:** rezervace si čas **denormalizuje** (`booking_date`, `slot_from`, `slot_to`, `blocked_from`, `blocked_to`). Když správce později změní rozvrh nebo délku pauzy, existující rezervace se nerozpadnou.

### ADR-7 — Peníze jako celá čísla

Vše v haléřích (`INT`, 1500 Kč = `150000`). Žádné floaty.

> ⚠️ **Ověř při implementaci** v aktuální dokumentaci Stripe, jak Stripe očekává částku v CZK (minor units vs. celé koruny) — u některých měn platila v minulosti výjimka. Napiš na to unit test.

### ADR-8 — Časové pásmo

Veškeré časové značky v DB v **UTC** (`DATETIME`, UTC). Veškeré rozhodování o rozvrhu a cutoffu v `Europe/Prague` přes explicitní `new DateTimeZone('Europe/Prague')` — **nespoléhej na `current_time()` ani na nastavení WP**, může se změnit.

`booking_date` je `DATE` (lokální kalendářní den), `slot_from`/`slot_to` jsou `TIME` (lokální nástěnný čas). Tím je rozvrh imunní vůči letnímu času.

### ADR-9 — Cenové hladiny místo slev

**Rozhodnutí:** cena není jedno číslo na kombinaci služeb, ale matice **hladina × kombinace**. Zavádíme dvě hladiny (rozšiřitelné):

| Hladina | Kdo | Sud | Sauna | Kombo |
|---|---|---|---|---|
| `public` (výchozí) | veřejnost | 1 500 Kč | 1 500 Kč | 2 000 Kč |
| `guest` | ubytovaní v apartmánu | 1 000 Kč | 1 000 Kč | 1 500 Kč* |

\* návrh, viz O11.

**Proč hladiny a ne slevové kupony:** kupon je procento nebo částka odečtená od základní ceny — tady jde o dva různé ceníky, které se mohou vyvíjet nezávisle (např. sezónní příplatek jen pro veřejnost). Hladina je čitelnější v adminu i v účetnictví a v rezervaci se ukládá, takže je vždy zpětně dohledatelné, proč zákazník zaplatil kolik zaplatil.

**Jak se zákazník do hladiny `guest` dostane:** hladina má příznak `requires_code`. Ubytovaný zadá **přístupový kód** (tabulka `duj_access_codes`), který dostane v potvrzení rezervace ubytování nebo od hostitele. Kód může být trvalý (jeden pro celou sezónu) nebo jednorázový vázaný na konkrétní pobyt. Ceny hladiny `guest` se ve formuláři zobrazují informativně („Ubytovaní hosté: 1 000 Kč — zadejte kód"), aby to fungovalo i marketingově.

**Důsledek:** cena se **vždy počítá na serveru** při vytváření rezervace, nikdy se nepřebírá z requestu. Klient posílá jen `combo_key` a `access_code`.

### ADR-10 — Vazba na potvrzené rezervace ubytování přes adaptér

**Rozhodnutí:** wellness zná dny, kdy je obsazený apartmán, ale **nečte cizí databázi přímo z doménové logiky**. Mezi tím stojí rozhraní `AccommodationSourceInterface` a tabulka `duj_accommodation_blocks`, kterou plní synchronizační job.

**Politika pro obsazený den** (nastavitelná globálně, přebitelná pro konkrétní datum):

| Politika | Chování |
|---|---|
| `ignore` | Wellness se chová, jako by apartmán nebyl obsazený |
| `guests_only` (**výchozí**) | Sloty existují, ale rezervovat je lze jen s kódem hladiny `guest`. Veřejnost vidí „Termín je vyhrazen ubytovaným hostům." |
| `closed` | Wellness ten den zavřeno úplně |

`guests_only` odpovídá tvému provozu: apartmán o víkendech → wellness pro hosty za 1 000 Kč; přes týden volno → veřejnost za 1 500 Kč. A hlavně se to nastavuje samo, bez ručního zavírání dnů.

**Zdroje dat.** Feed je ověřený a funkční — `IcsFeedSource` je tedy **primární cesta**, CSV klesá na zálohu.

| # | Zdroj | Role | Automatické? |
|---|---|---|---|
| 1 | `IcsFeedSource` | **primární** | ✅ hodinově |
| 2 | `ManualSource` | fallback a ruční přepis jednotlivých dnů, vždy implementuj | ❌ |
| 3 | `CsvImportSource` | záloha, když feed vypadne nebo se změní token | ⚠️ půlautomatické |
| 4 | `WpBookingSystemSource` | nepoužívej, pokud feed funguje | ✅ |

#### Ověřená podoba feedu

Feed byl stažen a analyzován. Parser piš proti těmto konkrétním faktům, ne proti obecné představě o iCalu:

| Vlastnost | Zjištěný stav |
|---|---|
| `PRODID` | `WP Booking System - Calendar ID: 1` |
| `X-WR-TIMEZONE` | `Europe/Prague` |
| Události | výhradně celodenní: `DTSTART;VALUE=DATE:YYYYMMDD` |
| **`DTEND`** | **exkluzivní** — obsazené dny jsou `DTSTART … DTEND − 1 den` |
| `STATUS` | u všech událostí `CONFIRMED` → **k filtrování nepoužitelné** |
| Skládání řádků | dlouhé `UID` jsou zalomené podle RFC 5545 (pokračovací řádek začíná mezerou) |
| Escapování | čárky v textu jsou `\,` |
| Jeden pobyt | rozpadá se do **více `VEVENT`** — první nese popis, navazující dny mají prázdný `SUMMARY` |
| Rozsah | zhruba dva roky dopředu, řádově stovky událostí |

Z toho plynou tři konkrétní implementační pravidla:

1. **Nejdřív rozlož zalomené řádky** (`\r\n` + mezera → nic), teprve pak parsuj. Tohle je nejčastější zdroj tichých chyb.
2. **Neřeš párování událostí do pobytů.** Udělej sjednocení dnů: pro každou událost přidej do množiny dny `DTSTART` až `DTEND − 1`. Že je pobyt rozsekaný na několik `VEVENT`, je pak jedno.
3. **Nefiltruj podle `STATUS`** — všechno je `CONFIRMED`. Filtrovat lze jen podle toho, které legend items se v WPBS exportují.

#### ⚠️ Feed obsahuje osobní údaje

Toto je nejdůležitější zjištění. Pole `SUMMARY` a `DESCRIPTION` obsahují **příjmení hostů, počty osob, poznámky o platbách** („záloha placena mně", „neplaceno", „doplatek hotově") a další interní poznámky. Feed přitom běží na **veřejné URL bez autentizace** — jediné, co ho chrání, je neuhodnutelnost tokenu v query parametru.

Důsledky pro tento projekt:

- **Wellness plugin nesmí `SUMMARY` ani `DESCRIPTION` nikdy uložit.** Použije je maximálně v paměti pro klasifikaci (viz níže) a okamžitě zahodí. Do `duj_accommodation_blocks` jde jen datum a politika.
- **Do logů nepatří tělo feedu.** Loguj jen HTTP status, počet událostí a čas.
- **URL feedu je tajemství.** Ulož ji do `wp-config.php` jako `DUJ_ACCOMMODATION_ICS_URL`, ne do `wp_options`, a v adminu ji zobrazuj zamaskovanou.

Nezávisle na pluginu: token, který se dostal ven, je potřeba v WPBS přegenerovat a s odkazem zacházet jako s heslem. Za zvážení stojí i přestat psát do popisků rezervací příjmení a platební poznámky — feed sdílíš s Airbnb, Booking.com i Google Kalendářem a ty údaje tam nikdo nepotřebuje.

#### Klasifikace: pobyt hostů vs. vlastní blokace

Feed **míchá dvě různé věci**: rezervace hostů (airbnb, booking, e-chalupy, jmenovité rezervace) a vlastní blokace majitelů („naše volno", „dovolena", výjezdy). Rozdíl je pro wellness zásadní:

| Typ dne | Správná politika | Proč |
|---|---|---|
| Pobyt hostů | `guests_only` | hosté jsou na místě, wellness jim má být dostupné za 1 000 Kč |
| Vlastní blokace majitelů | `closed` | nikdo tu není, sud nemá kdo zatopit — a kód by teoreticky pustil cizího člověka |

Feed sám o sobě rozdíl nenese. Řeš to v tomto pořadí:

1. **Nejlepší řešení je mimo kód:** v WPBS oddělit vlastní blokace do samostatné legend item a exportovat do feedu pro wellness jen pobyty hostů. Konfigurace na deset minut, která odstraní veškeré hádání. Pokud to jde, jdi touhle cestou a klasifikaci vůbec neimplementuj.
2. **Klasifikace klíčovými slovy** jako fallback: v nastavení seznam vzorů pro `closed` (výchozí: „naše volno", „nase volno", „dovolena", „cizina") vyhodnocovaný nad `SUMMARY` v paměti. Neshoda → výchozí politika.
3. **Výchozí politika při nejistotě je `closed`, ne `guests_only`.** Chybné `closed` znamená ušlou rezervaci; chybné `guests_only` znamená, že někdo přijede k zavřenému a prázdnému domu. Admin může den kdykoliv přepnout ručně.

**`ManualSource`.** Správce označí den v adminu. Vždy funguje, `is_manual = 1` přebíjí synchronizaci.

**`CsvImportSource`.** Záložní cesta. Admin nahraje CSV z Booking Manageru → mapování sloupců (uloží se pro příště) → náhled dopadu → potvrzení. Import musí být idempotentní a stejně jako feed nesmí uložit osobní údaje.

**`WpBookingSystemSource`.** Čtení tabulek `{prefix}wpbs_*`. Není to veřejné API, schéma se může s aktualizací změnit. Pokud po tom sáhneš, piš defenzivně: ověř existenci tabulek a sloupců, při odchylce **selži tiše do manuálního režimu** a upozorni správce. Nikdy nesmí pád téhle integrace shodit rezervační formulář.

#### Stahování feedu

- Cron 1× za hodinu, plus tlačítko „Synchronizovat teď".
- `wp_remote_get` s timeoutem 10 s, limitem velikosti odpovědi a `User-Agent` identifikujícím plugin.
- Respektuj `ETag` / `Last-Modified`, ať se zbytečně nestahuje nezměněný obsah.
- **Nikdy nezapisuj výsledek, když stažení selhalo nebo vrátilo prázdný kalendář** — ponech předchozí data a zvyš čítač chyb. Prázdný feed po chybě na straně WPBS by jinak rázem „otevřel" celý rok veřejnosti.
- Po třech neúspěších za sebou upozorni správce e-mailem a na Telegram.

#### Ochrana proti zastaralým datům

- Ukládej `synced_at` a v adminu zobrazuj stáří dat („Naposledy synchronizováno před 9 dny").
- Když jsou data starší než `accommodation_stale_after_days` (výchozí 2 dny při feedu, 7 při CSV), zobraz výrazné varování a pošli připomínku.
- Přepínač `stale_policy`: `warn_only` nebo **`fail_safe`** (doporučeno při feedu) — u dnů bez čerstvých dat se uplatní `closed`.

**Poznámka k riziku:** výchozí rozvrh (pondělí a středa) sám o sobě víkendy s ubytováním z velké části míjí, takže tahle integrace je pojistka, ne nosný prvek. To ale neznamená, že na ní nezáleží — z dat je vidět, že pobyty běžně padnou i na všední dny.

Ruční zásah správce (`is_manual = 1`) má vždy přednost a sync ho nepřepíše.

---

## 3. Otevřené otázky

### 3.1 Vyřešeno (v1.1)

| # | Otázka | Rozhodnutí |
|---|---|---|
| ~~O1~~ | Ceny | Dvě hladiny: veřejnost 1500/1500/2000, ubytovaní 1000/1000/1500. Ceny za **slot**, ne za den. Viz ADR-9 |
| ~~O2~~ | Cutoff a časové pásmo | **Nástěnných 12:00 v Europe/Prague** celoročně. Přepínač `cutoff_tz_mode` zůstává pro jistotu |
| ~~O5~~ | Vazba na ubytování | Ano, řeší ADR-10. Výchozí politika `guests_only` |
| ~~O14~~ | Odkud brát obsazenost apartmánu | **iCal feed** z WP Booking System (`?wpbs-ical=…`), stahovaný hodinově. CSV import zůstává jako záloha. Viz ADR-10 |
| ~~O16~~ | Je k dispozici iCal feed? | **Ano**, ověřeno na živém feedu. Formát a jeho úskalí popsané v ADR-10. Feed je primární zdroj, CSV klesá na zálohu |
| — | Délka slotu | Konfigurovatelná, výchozí **120 min**, povinná pauza **60 min** mezi sloty. Viz ADR-6 |

### 3.2 Stále otevřené — vyřeš PŘED implementací

| # | Otázka | Návrh defaultu |
|---|---|---|
| **O3** | Platí cutoff jen na rezervaci **na dnešek**, nebo je to obecná lhůta X hodin předem? | Jen na dnešní den; navíc `min_lead_time_minutes` (návrh 180 — zatopení sudu chvíli trvá) |
| **O6** | Potřebuješ evidovat počet osob (kapacita „až 6")? | Ano, pole „počet osob" (1–6), informativní, bez vlivu na cenu |
| **O7** | Storno zákazníkem — je povolené a s jakou lhůtou / vratkou? | MVP: odkaz „zrušit rezervaci" v e-mailu funguje do 48 h před termínem, vrací plnou částku |
| **O8** | Vystavuješ doklad / fakturu? Jsi plátce DPH? | MVP: PDF doklad neřešíme, Stripe posílá potvrzení o platbě. Pole pro DPH sazbu (default 0 %, neplátce) |
| **O9** | Odesílací e-mail — dnes gmail.com. | Odesílat jako `rezervace@domecekujosefa.cz` přes SMTP relay (Brevo / Resend / SMTP2GO) |
| **O10** | Kanál pro okamžitou notifikaci správce | **Telegram** (zdarma, okamžitý). SMS/WhatsApp jako fáze 3 |
| **O11** | **Kolik stojí kombo pro ubytované?** Veřejnost má 2000 (tj. 1500+1500 − 1000). Analogicky by ubytovaní měli 1500 (1000+1000 − 500). | **1 500 Kč**, konfigurovatelné |
| **O12** | **Jak se ubytovaný prokáže?** Kód v potvrzení pobytu / automaticky podle e-mailu / rezervaci mu založí správce? | **Kód** jako primární cesta (funguje vždy, i pro hosty z Airbnb) + ruční založení správcem. Automatika podle e-mailu jako fáze 8 |
| **O13** | **Platí uzávěrka 12:00 i pro ubytované?** Host je na místě, ale sud se stejně musí zatopit. | Uzávěrka 12:00 jen pro `public`; pro `guest` platí jen `min_lead_time_minutes` (nastavitelné per hladinu) |
| **O17** | **Půjde v WPBS oddělit vlastní blokace („naše volno", dovolená) do samostatné legend item** a exportovat do feedu jen pobyty hostů? | Ano = ideální, klasifikaci klíčovými slovy pak vůbec neimplementuj |
| **O18** | **Jak počítat den odjezdu?** Feed obsahuje události typu „booking odjezd" — hosté ráno odjedou a apartmán je večer prázdný. Má být wellness ten večer volné pro veřejnost? | Konzervativně `closed`; přepínač `checkout_day_policy` pro případ, že to chceš uvolnit |
| **O19** | **Přegeneruješ token iCal feedu?** Ten současný se dostal ven a odkazem lze bez přihlášení číst jména hostů a platební poznámky. | Ano, a v WPBS zvaž přestat psát do popisků příjmení a platební poznámky |
| **O15** | Mají ubytovaní hosté vlastní sloty (např. i o víkendu ráno), nebo stejný rozvrh jako veřejnost? | Stejný rozvrh; rozdíl dělá jen politika `guests_only` a cena |

> Poznámka: EET (elektronická evidence tržeb) je v ČR od 1. 1. 2024 zrušena, takže s ní nepočítáme. Daňové povinnosti si ověř u účetní.

---

## 4. Datový model

Prefix tabulek: `{$wpdb->prefix}duj_`. Engine InnoDB, charset `utf8mb4_unicode_ci`.

### 4.1 `duj_resources` — zdroje

```sql
CREATE TABLE {prefix}duj_resources (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug          VARCHAR(50)  NOT NULL,            -- 'sud', 'sauna'
  name          VARCHAR(120) NOT NULL,            -- 'Koupací sud'
  description   TEXT NULL,
  capacity      SMALLINT UNSIGNED NOT NULL DEFAULT 6,
  sort_order    SMALLINT NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL,
  UNIQUE KEY uq_slug (slug)
) ;
```
Seed při aktivaci: `sud` („Koupací sud"), `sauna` („Sauna").

### 4.2 `duj_schedule_rules` — opakující se rozvrh

```sql
CREATE TABLE {prefix}duj_schedule_rules (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label         VARCHAR(120) NULL,
  weekday       TINYINT UNSIGNED NOT NULL,        -- 1=Po … 7=Ne (ISO-8601)
  time_from     TIME NOT NULL,
  time_to       TIME NOT NULL,
  valid_from    DATE NULL,                        -- NULL = bez omezení
  valid_to      DATE NULL,
  resource_scope JSON NULL,                       -- NULL = všechny zdroje, jinak ["sud"]
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL,
  KEY idx_weekday (weekday, is_active)
);
```
Seed: Po 16:00–18:00, Po 19:00–21:00, St 16:00–18:00, St 19:00–21:00 (weekday 1 a 3).

### 4.3 `duj_schedule_overrides` — výjimky pro konkrétní data

```sql
CREATE TABLE {prefix}duj_schedule_overrides (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  override_date DATE NOT NULL,
  mode          ENUM('closed','replace') NOT NULL,
  slots         JSON NULL,   -- [{"from":"15:00","to":"17:00","resources":["sud"]}]
  note          VARCHAR(255) NULL,
  created_by    BIGINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL,
  UNIQUE KEY uq_date (override_date)
);
```

### 4.4 Ceník — hladiny, ceny, přístupové kódy

```sql
CREATE TABLE {prefix}duj_price_tiers (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug               VARCHAR(40) NOT NULL,       -- 'public' | 'guest'
  label              VARCHAR(120) NOT NULL,      -- 'Veřejnost' | 'Ubytovaní hosté'
  is_default         TINYINT(1) NOT NULL DEFAULT 0,
  requires_code      TINYINT(1) NOT NULL DEFAULT 0,
  show_in_form       TINYINT(1) NOT NULL DEFAULT 1,  -- zobrazit ceny informativně
  cutoff_mode        ENUM('inherit','lead_time_only','none') NOT NULL DEFAULT 'inherit',
  min_lead_minutes   INT UNSIGNED NULL,          -- NULL = převzít globální nastavení
  sort_order         SMALLINT NOT NULL DEFAULT 0,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_slug (slug)
);

CREATE TABLE {prefix}duj_prices (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tier_slug     VARCHAR(40) NOT NULL,     -- FK na duj_price_tiers.slug
  combo_key     VARCHAR(60) NOT NULL,     -- 'sauna' | 'sud' | 'sauna+sud' (slugy abecedně, spojené '+')
  label         VARCHAR(120) NOT NULL,    -- 'Sauna i sud (kombo)'
  amount_minor  INT UNSIGNED NOT NULL,    -- v haléřích
  currency      CHAR(3) NOT NULL DEFAULT 'CZK',
  weekday       TINYINT UNSIGNED NULL,    -- NULL = všechny dny
  time_from     TIME NULL,                -- NULL = všechny sloty
  valid_from    DATE NULL,
  valid_to      DATE NULL,
  priority      SMALLINT NOT NULL DEFAULT 0,  -- vyšší vyhrává
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_lookup (tier_slug, combo_key, is_active)
);

CREATE TABLE {prefix}duj_access_codes (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(40) NOT NULL,       -- ukládej UPPER, porovnávej case-insensitive
  tier_slug      VARCHAR(40) NOT NULL,
  label          VARCHAR(160) NULL,          -- 'Pobyt Novákovi 12.–14. 9.'
  valid_from     DATE NULL,
  valid_to       DATE NULL,
  max_uses       INT UNSIGNED NULL,          -- NULL = neomezeně
  used_count     INT UNSIGNED NOT NULL DEFAULT 0,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at     DATETIME NOT NULL,
  UNIQUE KEY uq_code (code)
);
```

**Seed:**
- `duj_price_tiers`: `public` („Veřejnost", `is_default=1`, `requires_code=0`), `guest` („Ubytovaní hosté", `requires_code=1`, `cutoff_mode='lead_time_only'`)
- `duj_prices`: public → sud 150000, sauna 150000, sauna+sud 200000 · guest → sud 100000, sauna 100000, sauna+sud 150000
- `duj_access_codes`: jeden trvalý kód `HOSTE2026` pro hladinu `guest` (správce si ho pak změní)

**Resolver ceny:** pro (tier, combo_key, date, slot_from) vyber aktivní záznam s nejvyšší `priority`, který matchuje `tier_slug` + `combo_key` + (`weekday` = weekday(date) nebo NULL) + (`time_from` = slot_from nebo NULL) + datum v rozsahu platnosti. Když pro hladinu cena chybí, **spadni na výchozí hladinu** a zaloguj varování — nikdy nevracej nulu.

**Validace kódu:** aktivní, v platnosti, `max_uses` nevyčerpáno. `used_count` inkrementuj až při přechodu do `awaiting_confirmation` (ne při vytvoření pending rezervace, jinak by expirované holdy kód vyčerpaly). Rate-limit ověřování kódu: 10 pokusů / IP / hodinu, ať se nedá uhodnout.

### 4.5 `duj_bookings` — rezervace

```sql
CREATE TABLE {prefix}duj_bookings (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid              CHAR(36) NOT NULL,
  reference         VARCHAR(20) NOT NULL,     -- 'W-2026-0041' pro lidi a variabilní symbol
  booking_date      DATE NOT NULL,
  slot_from         TIME NOT NULL,
  slot_to           TIME NOT NULL,
  combo_key         VARCHAR(60) NOT NULL,
  guests            SMALLINT UNSIGNED NULL,
  status            VARCHAR(30) NOT NULL,     -- viz stavový automat
  tier_slug         VARCHAR(40) NOT NULL DEFAULT 'public',  -- za jakou hladinu bylo účtováno
  access_code       VARCHAR(40) NULL,         -- kód, kterým se hladina odemkla
  amount_minor      INT UNSIGNED NOT NULL,    -- výsledná cena, spočtená na serveru
  currency          CHAR(3) NOT NULL DEFAULT 'CZK',
  customer_name     VARCHAR(160) NULL,
  customer_email    VARCHAR(190) NOT NULL,
  customer_phone    VARCHAR(40)  NOT NULL,
  customer_note     TEXT NULL,
  admin_note        TEXT NULL,
  payment_method    VARCHAR(30) NOT NULL,     -- stripe_card | qr_checkout | qr_bank | onsite
  payment_status    VARCHAR(30) NOT NULL,     -- none|requires_payment|authorized|paid|failed|refunded|released
  payment_provider  VARCHAR(30) NULL,         -- 'stripe'
  payment_intent_id VARCHAR(190) NULL,
  payment_meta      JSON NULL,
  hold_expires_at   DATETIME NULL,            -- UTC; dokud drží slot bez zaplacení
  auth_expires_at   DATETIME NULL,            -- UTC; expirace autorizace karty
  confirmed_at      DATETIME NULL,
  confirmed_by      BIGINT UNSIGNED NULL,     -- 0 = z e-mailu tokenem
  cancelled_at      DATETIME NULL,
  cancel_reason     VARCHAR(255) NULL,
  consent_at        DATETIME NULL,
  consent_ip        VARBINARY(16) NULL,
  source            VARCHAR(30) NOT NULL DEFAULT 'web',  -- web | admin
  locale            VARCHAR(10) NOT NULL DEFAULT 'cs_CZ',
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NOT NULL,
  UNIQUE KEY uq_uuid (uuid),
  UNIQUE KEY uq_reference (reference),
  KEY idx_date (booking_date, slot_from),
  KEY idx_status (status),
  KEY idx_pi (payment_intent_id)
);
```

### 4.6 `duj_booking_items` — obsazenost (srdce systému)

```sql
CREATE TABLE {prefix}duj_booking_items (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id    BIGINT UNSIGNED NOT NULL,
  resource_id   BIGINT UNSIGNED NOT NULL,
  blocking_key  VARCHAR(191) NULL,          -- '2026-09-14|16:00:00|1' nebo NULL
  blocked_from  DATETIME NULL,              -- UTC, = booking_date + slot_from
  blocked_to    DATETIME NULL,              -- UTC, = booking_date + slot_to + buffer_minutes
  buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- kolik pauzy je v blocked_to zahrnuto
  created_at    DATETIME NOT NULL,
  UNIQUE KEY uq_blocking (blocking_key),
  KEY idx_booking (booking_id),
  KEY idx_overlap (resource_id, blocked_from, blocked_to),
  CONSTRAINT fk_bi_booking FOREIGN KEY (booking_id)
    REFERENCES {prefix}duj_bookings(id) ON DELETE CASCADE
);

-- serializace zápisů, viz ADR-5
CREATE TABLE {prefix}duj_day_locks (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lock_date   DATE NOT NULL,
  resource_id BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_day_resource (lock_date, resource_id)
);
```

Při uvolnění slotu (`cancelled`/`rejected`/`expired`) se nulují **všechny tři** sloupce: `blocking_key`, `blocked_from`, `blocked_to`. Kontrola překryvu i unique index tak pracují se stejnou pravdou.

### 4.7 `duj_action_tokens` — jednorázové odkazy z e-mailů

```sql
CREATE TABLE {prefix}duj_action_tokens (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id  BIGINT UNSIGNED NOT NULL,
  action      VARCHAR(30) NOT NULL,      -- confirm | reject | cancel | view
  token_hash  CHAR(64) NOT NULL,         -- sha256 tokenu, plaintext se nikdy neukládá
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  used_ip     VARBINARY(16) NULL,
  created_at  DATETIME NOT NULL,
  UNIQUE KEY uq_token (token_hash),
  KEY idx_booking (booking_id, action)
);
```

### 4.8 `duj_email_templates`

```sql
CREATE TABLE {prefix}duj_email_templates (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_key VARCHAR(60) NOT NULL,
  subject      VARCHAR(255) NOT NULL,
  body_html    LONGTEXT NOT NULL,
  is_enabled   TINYINT(1) NOT NULL DEFAULT 1,
  updated_at   DATETIME NOT NULL,
  UNIQUE KEY uq_key (template_key)
);
```

### 4.9 `duj_notifications` (log odeslaného) a `duj_audit_log` (kdo co udělal)

```sql
CREATE TABLE {prefix}duj_notifications (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id   BIGINT UNSIGNED NULL,
  channel      VARCHAR(30) NOT NULL,      -- email | telegram | sms | whatsapp
  template_key VARCHAR(60) NULL,
  recipient    VARCHAR(190) NULL,
  status       VARCHAR(20) NOT NULL,      -- sent | failed
  error        TEXT NULL,
  created_at   DATETIME NOT NULL,
  KEY idx_booking (booking_id)
);

CREATE TABLE {prefix}duj_audit_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id  BIGINT UNSIGNED NULL,
  user_id     BIGINT UNSIGNED NULL,
  action      VARCHAR(60) NOT NULL,
  data        JSON NULL,
  ip          VARBINARY(16) NULL,
  created_at  DATETIME NOT NULL,
  KEY idx_booking (booking_id),
  KEY idx_created (created_at)
);
```

### 4.10 `duj_accommodation_blocks` — obsazenost apartmánu

```sql
CREATE TABLE {prefix}duj_accommodation_blocks (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  block_date    DATE NOT NULL,
  policy        ENUM('ignore','guests_only','closed') NOT NULL DEFAULT 'guests_only',
  source        VARCHAR(30) NOT NULL,     -- manual | csv | ics | wpbs
  external_ref  VARCHAR(190) NULL,        -- id rezervace ubytování, ať jde spárovat
  is_manual     TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = sync ani import nesmí přepsat
  note          VARCHAR(255) NULL,
  synced_at     DATETIME NULL,
  created_at    DATETIME NOT NULL,
  UNIQUE KEY uq_date (block_date),
  KEY idx_source (source)
);
```

Sync job pro každý den v okně (dnes až dnes + `calendar_months`) zjistí, zda je apartmán obsazený, a zapíše/smaže záznam. Řádky s `is_manual = 1` **nikdy** nepřepisuje ani nemaže.

### 4.11 Migrace

Verzované migrace v `includes/Migrations/`, verze v option `duj_db_version`. Při každém `plugins_loaded` porovnej a doběhni chybějící. Nepoužívej jen `dbDelta` na měnící se schéma — `dbDelta` neumí spolehlivě UNIQUE indexy na nullable sloupcích, ty přidej explicitním `ALTER TABLE`.

---

## 5. Doménová logika

### 5.1 Stavový automat rezervace

```
                    ┌──────────────────┐
      vytvoření ───►│ pending_payment  │  (drží slot, hold_expires_at = +15 min)
                    └────────┬─────────┘
                             │ platba autorizována / přijata
                             ▼
                    ┌────────────────────────┐
                    │ awaiting_confirmation  │  (drží slot, čeká na správce)
                    └───┬────────────────┬───┘
             potvrzení  │                │  zamítnutí
             (capture)  ▼                ▼  (cancel/refund)
                 ┌───────────┐     ┌───────────┐
                 │ confirmed │     │ rejected  │  slot uvolněn
                 └─────┬─────┘     └───────────┘
                       │ po termínu (cron)
                       ▼
                 ┌───────────┐
                 │ completed │
                 └───────────┘

Z pending_payment  → expired    (hold vypršel / platba neproběhla) — slot uvolněn
Z awaiting/confirmed → cancelled (zrušil zákazník nebo správce)    — slot uvolněn
Z confirmed        → no_show    (ručně správcem)                   — slot zůstává historicky
```

**Blokující stavy** (`blocking_key` je vyplněný): `pending_payment` (dokud platí hold), `awaiting_confirmation`, `confirmed`, `no_show`.
**Neblokující** (`blocking_key = NULL`): `expired`, `rejected`, `cancelled`.

Přechod stavu **musí** proběhnout v jedné transakci se změnou `blocking_key` a zápisem do `duj_audit_log`. Implementuj jako jedinou metodu `BookingService::transition(Booking $b, string $to, array $ctx): void` s explicitní maticí povolených přechodů — nikde jinde `status` neměň.

### 5.2 Výpočet dostupnosti

```
resolveDayPolicy(date D):                        // ADR-10
    block = accommodation_blocks[D]
    return block ? block.policy : 'ignore'

resolveSlots(date D):
    if exists override(D):
        if override.mode == 'closed': return []
        return override.slots                     // výjimka přebíjí vše
    if resolveDayPolicy(D) == 'closed': return []
    return rules where weekday(D) matches AND D within valid_from..valid_to AND is_active

getAvailability(from, to, tier):                  // tier = 'public' dokud nezadá kód
    for each D in from..to:
        policy = resolveDayPolicy(D)
        slots  = resolveSlots(D)

        if D < today(Prague):                     mark all 'past';          continue
        if policy == 'guests_only' && tier == 'public':
                                                  mark all 'guests_only';   continue

        for each slot S in slots:
            if not CutoffPolicy(tier).allows(D, S.from):
                                                  mark S 'cutoff';          continue

            // interval, který by rezervace obsadila, včetně technické pauzy
            win_from = utc(D, S.from)
            win_to   = utc(D, S.to) + buffer_minutes

            busy = SELECT DISTINCT resource_id FROM duj_booking_items bi
                   JOIN duj_bookings b ON b.id = bi.booking_id
                   WHERE bi.blocked_from < win_to
                     AND bi.blocked_to   > win_from
                     AND bi.blocking_key IS NOT NULL
                     AND (b.status <> 'pending_payment' OR b.hold_expires_at > now)

            free = slot.resources − busy
            offers = []
            if 'sud'   in free: offers += { key:'sud',       price: resolvePrice(tier,'sud',D,S) }
            if 'sauna' in free: offers += { key:'sauna',     price: resolvePrice(tier,'sauna',D,S) }
            if both    in free: offers += { key:'sauna+sud', price: resolvePrice(tier,'sauna+sud',D,S) }

            emit { date: D, from: S.from, to: S.to, policy, offers }
```

**Kombo** se nabízí **jen když jsou volné oba zdroje**. Rezervace komba vytvoří dvě položky → oba zdroje se stanou nedostupnými. (Požadavek splněn strukturálně, ne speciálním ifem.)

**Pozor na dvě věci:**

- Dotaz na obsazenost je **překryv intervalů**, ne shoda slotů — jinak by technická pauza nefungovala a ruční rezervace správce mimo rozvrh by se ignorovala.
- Když je `policy = 'guests_only'`, den se veřejnosti **nezobrazí jako obsazený, ale jako vyhrazený**, s vysvětlující hláškou a odkazem „Jste u nás ubytovaní? Zadejte kód." Po zadání kódu se dotaz zopakuje s `tier = 'guest'` a den se odemkne. Nikdy neposílej do veřejné odpovědi údaje o hostech apartmánu — jen příznak politiky.

### 5.3 Cutoff (uzávěrka)

```php
final class CutoffPolicy {
    // globální nastavení: cutoff_enabled(bool), cutoff_time('12:00'),
    //   cutoff_tz_mode('wall_clock'|'fixed_cet'), min_lead_time_minutes(int)
    // per hladinu (duj_price_tiers): cutoff_mode('inherit'|'lead_time_only'|'none'),
    //   min_lead_minutes(int|null)

    public function allows(string $bookingDate, string $slotFrom, PriceTier $tier): bool {
        $tz  = new DateTimeZone('Europe/Prague');
        $now = new DateTimeImmutable('now', $tz);

        $slotStart = new DateTimeImmutable("$bookingDate $slotFrom", $tz);
        if ($slotStart <= $now) return false;                       // slot už začal

        // minimální doba předem (zatopení sudu) — platí pro všechny hladiny
        $lead = $tier->minLeadMinutes ?? $this->minLeadMinutes;
        if ($lead > 0 && $slotStart < $now->modify("+{$lead} minutes")) return false;

        // uzávěrka 12:00 se na hladinu 'guest' standardně nevztahuje (viz O13)
        if ($tier->cutoffMode !== 'inherit') return true;
        if (!$this->enabled) return true;
        if ($bookingDate !== $now->format('Y-m-d')) return true;    // ne dnešek → bez omezení

        if ($this->tzMode === 'fixed_cet') {
            // literální SEČ = UTC+1 celoročně
            $deadline = new DateTimeImmutable("$bookingDate {$this->cutoffTime}", new DateTimeZone('+01:00'));
        } else {
            // nástěnných 12:00 v Praze (doporučeno)
            $deadline = new DateTimeImmutable("$bookingDate {$this->cutoffTime}", $tz);
        }
        return $now < $deadline;
    }
}
```

**Testy, které musí projít:** 30. 3. (přechod na letní čas), 26. 10. (na zimní), 11:59 a 12:01 v obou režimech, rezervace na zítřek ve 23:00, a navíc: `guest` ve 14:00 na dnešní slot v 19:00 **projde**, `public` ve stejné situaci **neprojde**; `guest` ve 17:30 na slot v 19:00 neprojde kvůli `min_lead_minutes = 180`.

### 5.4 Souběh a hold

1. `POST /bookings` → transakce → zamkni `duj_day_locks` pro (datum, každý dotčený zdroj) přes `SELECT … FOR UPDATE` → ověř překryv intervalů → insert `duj_bookings` (`pending_payment`, `hold_expires_at = UTC now + 15 min`) → insert `duj_booking_items` s `blocking_key`, `blocked_from`, `blocked_to`.
2. Duplicate key **nebo** nalezený překryv → rollback → HTTP 409.
3. Zámky ber vždy **v deterministickém pořadí** (podle `resource_id` vzestupně), jinak si při komboch dvě souběžné rezervace zablokují navzájem (deadlock).
4. Cron `duj_release_expired_holds` (á 5 min): `pending_payment` s `hold_expires_at < now` → `expired`, `blocking_key`/`blocked_from`/`blocked_to` na `NULL`, zruš PaymentIntent.
5. Navíc **lazy expirace** přímo v dotazu na dostupnost (viz 5.2) — kdyby cron neběžel, slot se stejně tváří jako volný a hold se uklidí při dalším insertu.

### 5.5 Určení hladiny a ceny

Cena se počítá **výhradně na serveru**. Klient posílá `combo_key` a volitelně `access_code`, nikdy částku.

```
resolveTier(access_code, date):
    if access_code is empty:            return default_tier            // 'public'
    code = findActiveCode(access_code, date)                            // platnost, max_uses
    if code is null:                    return default_tier + warning('invalid_code')
    return tier(code.tier_slug)

createBooking(...):
    tier   = resolveTier(input.access_code, input.date)
    policy = resolveDayPolicy(input.date)
    if policy == 'guests_only' && tier.slug == default_tier.slug:
        reject 422 'guests_only'                                        // kód chybí nebo je neplatný
    if not CutoffPolicy.allows(date, slot_from, tier): reject 422 'cutoff_passed'
    amount = resolvePrice(tier, combo_key, date, slot_from)
    ...
```

Do rezervace se uloží `tier_slug` i `access_code`, takže je později dohledatelné, proč byla cena 1 000 a ne 1 500. `used_count` kódu se inkrementuje až při přechodu do `awaiting_confirmation`.

**Bezpečnostní poznámka:** neplatný kód **nesmí** vrátit „takový kód neexistuje" vedle „kód vypršel" — obojí hlas jednotně jako „Kód neplatí. Zkontrolujte ho prosím nebo nás kontaktujte." Jinak z toho jde vyčíst, které kódy existují.

---

## 6. Platby

### 6.1 Konfigurace

| Nastavení | Default |
|---|---|
| `stripe_mode` | `test` \| `live` |
| `stripe_publishable_key`, `stripe_secret_key`, `stripe_webhook_secret` | uloženo v `wp_options`, **nikdy neloguj** |
| `payment_capture_mode` | `manual` |
| `enabled_methods` | `["stripe_card","qr_checkout"]` |
| `hold_minutes` | 15 |
| `bank_account_iban`, `bank_account_number` | pro `qr_bank` |

Klíče doporučuji držet v `wp-config.php` konstantami (`DUJ_STRIPE_SECRET_KEY`) a v adminu jen zobrazit „nastaveno v konfiguraci". Option použij jako fallback.

### 6.2 Tok `stripe_card` (karta / Apple Pay / Google Pay)

```
Frontend                     Plugin (PHP)                       Stripe
   │ POST /bookings ─────────►│
   │                          │ validace, cutoff, hold, insert
   │                          │ createPaymentIntent(
   │                          │   amount, currency:'czk',
   │                          │   capture_method:'manual',
   │                          │   automatic_payment_methods:{enabled:true},
   │                          │   metadata:{booking_uuid, reference},
   │                          │   idempotency_key: booking_uuid ) ──►│
   │ ◄── {client_secret} ─────│◄────────────────────────────────────│
   │ Payment Element .confirmPayment() ─────────────────────────────►│
   │                          │◄── webhook payment_intent.amount_capturable_updated
   │                          │ status → awaiting_confirmation
   │                          │ auth_expires_at = now + 7 dní
   │                          │ e-maily + Telegram
   │ ◄── polling GET /bookings/{uuid}?token=…
```

- **Apple Pay / Google Pay** jsou součástí Payment Elementu, netřeba zvláštní kód. Nutné ale:
  - HTTPS (už je),
  - v Stripe Dashboardu **ověřit doménu** `domecekujosefa.cz` pro Apple Pay (nahrání souboru do `/.well-known/apple-developer-merchantid-domain-association`),
  - povolit Apple Pay + Google Pay v Payment method settings.
- **Idempotence:** `idempotency_key` = `booking_uuid`, ať dvojité odeslání formuláře nevytvoří dva PaymentIntenty.

### 6.3 Webhooky

Endpoint: `POST /wp-json/duj/v1/webhooks/stripe` (veřejný, ověřovaný podpisem).

| Event | Akce |
|---|---|
| `payment_intent.amount_capturable_updated` | autorizováno → `awaiting_confirmation`, notifikace |
| `payment_intent.succeeded` | capture proběhl → `payment_status = paid` (a v režimu `automatic` → `awaiting_confirmation`) |
| `payment_intent.payment_failed` | `payment_status = failed`, ponech hold do expirace |
| `payment_intent.canceled` | uvolni slot, `expired`/`rejected` |
| `charge.refunded` | `payment_status = refunded` |
| `checkout.session.completed` | pro `qr_checkout` |

**Povinné:** ověř `Stripe-Signature` proti `webhook_secret`, ulož `event.id` do transientu (24 h) a duplicitní eventy ignoruj. Webhook nikdy nesmí vracet 500 kvůli chybě v e-mailu — notifikace posílej asynchronně (Action Scheduler), 200 vrať hned.

### 6.4 `qr_checkout`

Vytvoř Stripe Checkout Session (`mode: payment`, `payment_intent_data.capture_method: manual`), vygeneruj QR s URL session (`endroid/qr-code`) a zobraz vedle Payment Elementu se štítkem „Zaplatit telefonem". Session `expires_at` sladi s `hold_expires_at`.

### 6.5 `qr_bank` (SPAYD, volitelné)

```
SPD*1.0*ACC:{IBAN}*AM:{1500.00}*CC:CZK*X-VS:{numerická část reference}*MSG:WELLNESS {reference}
```
Rezervace zůstává `pending_payment` s prodlouženým holdem (`qr_bank_hold_hours`, default 48, ale nikdy za cutoff termínu). Správce v adminu tlačítkem „Označit jako zaplaceno" posune do `awaiting_confirmation`. V e-mailu zákazníkovi pošli QR jako inline obrázek (CID attachment) i jako text.

---

## 7. Notifikace

### 7.1 Šablony e-mailů (editovatelné v adminu)

| `template_key` | Komu | Kdy | Povinné dle zadání |
|---|---|---|---|
| `customer_booking_received` | zákazník | po zaplacení/autorizaci | ✅ |
| `admin_booking_new` | správce | po zaplacení/autorizaci | ✅ |
| `customer_booking_confirmed` | zákazník | po potvrzení správcem | ✅ |
| `customer_booking_rejected` | zákazník | po zamítnutí správcem | ✅ |
| `customer_payment_instructions` | zákazník | při `qr_bank` | doplňkové |
| `customer_booking_cancelled` | zákazník | zrušení (zákazník/správce) | doplňkové |
| `customer_reminder` | zákazník | 24 h předem | doplňkové |
| `admin_auth_expiring` | správce | 24 h před expirací autorizace | doplňkové |

### 7.2 Placeholdery

`{{reference}}` `{{customer_name}}` `{{customer_email}}` `{{customer_phone}}` `{{date}}` (14. 9. 2026) `{{weekday}}` `{{time_from}}` `{{time_to}}` `{{service_label}}` `{{guests}}` `{{price}}` (1 500 Kč) `{{tier_label}}` (Ubytovaní hosté) `{{access_code}}` `{{payment_method_label}}` `{{status_label}}` `{{customer_note}}` `{{admin_note}}` `{{confirm_url}}` `{{reject_url}}` `{{cancel_url}}` `{{detail_url}}` `{{admin_url}}` `{{site_name}}` `{{site_url}}` `{{contact_email}}` `{{contact_phone}}` `{{address}}` `{{qr_payment_image}}`

Renderer: prosté nahrazení + `esc_html` na hodnotách, obal do jednoho HTML layoutu (`templates/emails/layout.php`) s barvami z nastavení. Vždy generuj i plaintext část (`AltBody`). K `customer_booking_confirmed` přilož **.ics soubor** (`Ics::forBooking()`), ať si zákazník termín přidá do kalendáře.

Admin UI: `wp_editor` pro tělo, seznam placeholderů s tlačítkem „vložit", tlačítko **„Odeslat testovací e-mail"**, tlačítko „Obnovit výchozí".

### 7.3 Potvrzení/zamítnutí z e-mailu

```
GET  /wp-json/duj/v1/action/{token}   → HTML stránka s rekapitulací + tlačítkem
POST /wp-json/duj/v1/action/{token}   → provede akci
```

**Kritické:** akci **nikdy neprováděj na GET**. E-mailoví klienti a antispamové skenery odkazy předběžně načítají — na GET by ti rezervace potvrzovaly samy od sebe. GET jen vykreslí stránku „Opravdu potvrdit rezervaci W-2026-0041?" s formulářem, akce se stane až na POST.

Token: `bin2hex(random_bytes(32))`, do DB jen `hash('sha256', $token)`, TTL 14 dní, jednorázový (`used_at`). Po použití zneplatni **oba** tokeny (confirm i reject) dané rezervace. Rate-limit 10 pokusů / IP / hodinu.

### 7.4 SMS / WhatsApp / Telegram

Rozhraní `NotificationChannelInterface { supports(): bool; send(string $to, string $message, array $ctx): void; }`

| Driver | Poznámka |
|---|---|
| `TelegramChannel` | **Doporučeno.** Zdarma, okamžité. Bot přes @BotFather, `POST https://api.telegram.org/bot{TOKEN}/sendMessage` s `chat_id`, `parse_mode: HTML`. Můžeš přidat inline tlačítka „Potvrdit/Zamítnout" s odkazy na action URL. |
| `SmsChannel` (SMSbrana.cz / GoSMS.cz) | Jednoduché HTTP API, ~1 Kč/SMS, české. Twilio jako alternativa. |
| `WhatsAppChannel` (Twilio / Meta Cloud API) | **Pozor:** zprávu iniciovanou firmou lze poslat jen jako **schválenou šablonu**; volný text jen 24 h po zprávě od uživatele. Znamená to registraci WhatsApp Business účtu a schvalování šablony. Proto fáze 3. |

Nastavení: `notify_channels[]`, `admin_phone`, `telegram_bot_token`, `telegram_chat_id`, tlačítko „Poslat test". Selhání kanálu **nesmí** shodit vytvoření rezervace — logni do `duj_notifications` a pokračuj.

---

## 8. REST API

Namespace `duj/v1`. Veřejné endpointy chraň nonce `wp_rest` + rate limitem.

| Metoda | Cesta | Auth | Popis |
|---|---|---|---|
| GET | `/availability?from=&to=&access_code=` | public | Dny, sloty, dostupné varianty + ceny pro rozpoznanou hladinu; dny s `policy` |
| GET | `/config` | public | Ceník všech veřejně zobrazovaných hladin, texty, povolené metody, min/max datum, délka slotu |
| POST | `/access-code/validate` | public + nonce + rate limit | Ověří kód, vrátí `{valid, tier_slug, tier_label}` — nikdy detaily kódu |
| POST | `/bookings` | public + nonce | Vytvoří `pending_payment`, vrátí `client_secret` / `checkout_url` / SPAYD |
| GET | `/bookings/{uuid}?token=` | token | Stav rezervace (polling po platbě) |
| POST | `/bookings/{uuid}/cancel` | token | Storno zákazníkem (dle O7) |
| GET | `/bookings/{uuid}/ics?token=` | token | Kalendářový soubor |
| GET/POST | `/action/{token}` | token | Potvrzení / zamítnutí správcem z e-mailu |
| POST | `/webhooks/stripe` | podpis | Stripe webhook |
| GET/POST/PATCH/DELETE | `/admin/bookings[/{id}]` | cap `duj_manage_bookings` | CRUD rezervací |
| GET/POST/PATCH/DELETE | `/admin/schedule/rules`, `/admin/schedule/overrides` | cap | Rozvrh |
| POST | `/admin/schedule/bulk` | cap | Hromadná aplikace na období |
| GET/POST/PATCH/DELETE | `/admin/prices`, `/admin/tiers` | cap | Ceník a cenové hladiny |
| GET/POST/PATCH/DELETE | `/admin/access-codes` | cap | Přístupové kódy pro ubytované |
| GET/POST/PATCH/DELETE | `/admin/accommodation` | cap | Ruční blokace dnů podle ubytování |
| POST | `/admin/accommodation/sync` | cap | Vynucená synchronizace ze zdroje |
| POST | `/admin/accommodation/import-csv` | cap | Nahrání CSV → náhled (`dry_run=1`) nebo zápis |
| POST | `/admin/schedule/generate-slots` | cap | Generátor slotů (okno, délka, pauza) → náhled i uložení |
| GET/PATCH | `/admin/templates` | cap | E-mailové šablony |
| POST | `/admin/test-notification` | cap | Test e-mailu/Telegramu/SMS |

**Kontrakt `POST /bookings`:**
```jsonc
// request
{
  "date": "2026-09-14",
  "slot_from": "16:00",
  "combo_key": "sauna+sud",
  "guests": 4,
  "access_code": "HOSTE2026",   // volitelné; server z něj odvodí hladinu a cenu
  "customer": { "name": "Jan Novák", "email": "jan@example.cz", "phone": "+420777123456" },
  "note": "",
  "payment_method": "stripe_card",
  "consent": true,
  "hp_field": "",          // honeypot, musí být prázdný
  "form_ts": 1757000000    // čas vykreslení formuláře, < 3 s = bot
}
// POZOR: request NIKDY neobsahuje cenu. Amount se počítá na serveru z (tier, combo_key, date, slot).
// response 201
{
  "uuid": "…", "reference": "W-2026-0041",
  "tier": { "slug": "guest", "label": "Ubytovaní hosté" },
  "amount_minor": 150000, "currency": "CZK",
  "expires_at": "2026-09-05T12:15:00Z",
  "access_token": "…",
  "payment": { "provider": "stripe", "client_secret": "pi_…_secret_…", "publishable_key": "pk_…" }
}
// chyby
409 slot_taken · 422 cutoff_passed · 422 validation_failed · 422 guests_only ·
422 invalid_access_code · 422 price_unavailable · 429 rate_limited
```

---

## 9. Frontend

### 9.1 Shortcode

```
[duj_wellness_booking months="2" service="all" heading="Rezervace wellness"]
```
Atributy: `months` (kolik měsíců dopředu v kalendáři, default 3), `service` (`all|sud|sauna`), `heading`, `theme` (`auto|light|dark`).

Doplňkově `[duj_wellness_availability]` — jen čtecí přehled nejbližších volných termínů (hodí se na homepage).

Assety enqueue **jen když je shortcode na stránce** (`has_shortcode()` v `wp` hooku nebo `wp_enqueue_scripts` s detekcí).

### 9.2 Uživatelský tok (mobile-first)

```
0. HLAVIČKA      ceník obou hladin: „Veřejnost 1 500 / 1 500 / 2 000 Kč ·
                 Ubytovaní u nás 1 000 / 1 000 / 1 500 Kč"
                 rozbalovací pole „Jste u nás ubytovaní? Zadejte kód" (nenápadné,
                 ale dostupné z kteréhokoliv kroku — po zadání se přepočte celý kalendář)
        ↓
1. KALENDÁŘ      měsíční mřížka Po–Ne, české názvy měsíců a dnů, čísla týdnů
                 stavy dne: volno (zvýrazněno) / částečně / obsazeno /
                 vyhrazeno ubytovaným / zavřeno / minulost
                 šipky ← →, přepínač měsíce
        ↓ klik na den
2. SLOTY         karty „16:00–18:00" / „19:00–21:00" s dostupností
                 (technická pauza se nikde nezobrazuje — není to termín)
        ↓
3. SLUŽBA        3 karty: Koupací sud · Sauna · Sud + sauna
                 ceny podle rozpoznané hladiny, u hladiny 'guest' viditelně
                 „cena pro ubytované"
                 (nedostupné varianty zašedlé s vysvětlením)
        ↓
4. ÚDAJE         jméno, e-mail*, telefon*, počet osob, poznámka
                 checkbox souhlasu s odkazem na /vop/
        ↓
5. PLATBA        rekapitulace + Stripe Payment Element (karta / Apple Pay / Google Pay)
                 vedle: QR „zaplatit telefonem"
                 odpočet „Termín držíme ještě 14:32"
        ↓
6. VÝSLEDEK      „Děkujeme, rezervaci potvrdíme do 24 hodin. Poslali jsme vám e-mail."
```

Stav drž v jednom JS objektu, kroky vykresluj jako accordion (na mobilu) / dva sloupce (na desktopu). Bez page reloadu, ale s `history.pushState`, ať funguje tlačítko zpět.

### 9.3 Styl

Web má vlastní téma (design AKpro). Postupuj takto:

1. Při implementaci si stáhni homepage a **vytáhni z CSS reálné hodnoty**: primární/akcentní barvu, barvu textu, font-family nadpisů a textu, radius tlačítek, styl tlačítka `.button` / CTA „Rezervovat".
2. Všechny barvy a fonty ve stylu pluginu definuj jako CSS custom properties na `.duj-wellness`:
   ```css
   .duj-wellness{
     --duj-accent:#…; --duj-accent-contrast:#fff;
     --duj-text:#…; --duj-muted:#…; --duj-border:#…;
     --duj-surface:#fff; --duj-radius:…; --duj-font:inherit;
   }
   ```
3. `font-family: inherit` všude — dědí se z tématu. Nikdy nevkládej vlastní webfont.
4. V nastavení pluginu udělej sekci **Vzhled** s color pickery pro tyto proměnné, aby se dal styl doladit bez zásahu do kódu.
5. CSS scopuj pod `.duj-wellness`, používej `:where()` pro nízkou specificitu, aby téma mohlo přebít. Žádné `!important`.
6. Nadpisy uvnitř widgetu nech na `h3`/`h4`, ať nekolidují s SEO strukturou stránky.

### 9.4 Přístupnost a UX

- Kalendář ovladatelný klávesnicí (šipky, Enter), `role="grid"`, `aria-selected`, `aria-disabled`.
- `aria-live="polite"` pro změny dostupnosti a chyby.
- Kontrast min. 4.5:1 (nespoléhej na barvu jako jediný nositel informace u „obsazeno").
- Telefon: `inputmode="tel"`, validace českého i mezinárodního formátu (`+420 xxx xxx xxx`), normalizace do E.164.
- Loading skeleton, ne spinner na celou plochu.
- Chybové hlášky česky a konkrétně („Tento termín právě někdo zarezervoval, vyberte prosím jiný.").

---

## 10. Administrace

Menu `Wellness` (ikona `dashicons-heart`), capability `duj_manage_bookings`. Vytvoř roli **Správce wellness** a přidej cap i administrátorovi.

| Podstránka | Obsah |
|---|---|
| **Rezervace** | `WP_List_Table`: reference, datum+slot, služba, zákazník, stav, platba, částka. Filtry: stav, rozsah dat, služba, fulltext. Hromadné akce: potvrdit, zamítnout, zrušit, export CSV. Detail v modálu: úprava termínu/služby/údajů, poznámka správce, historie z audit logu, tlačítka Potvrdit / Zamítnout / Zrušit / Označit zaplaceno / Refundovat / Smazat. |
| **Kalendář** | Měsíční přehled obsazenosti (sud/sauna barevně), klik na slot → vytvoření rezervace ručně (`source=admin`, `payment_method=onsite`) nebo blokace termínu. |
| **Rozvrh** | Sekce A: opakující se pravidla (tabulka + týdenní mřížka pro vizuální kontrolu). Sekce B: **Generátor slotů** — okno (16:00–21:00), délka slotu, technická pauza, dny v týdnu → náhled vygenerovaných slotů → uložit jako pravidla. Sekce C: výjimky pro data (kalendář, klik → zavřít den / nastavit vlastní sloty). Sekce D: **Hromadná úprava** — od–do, které dny v týdnu, akce (nastavit sloty / zavřít / smazat výjimky), náhled „změní se X dnů, koliduje s Y potvrzenými rezervacemi" **před** provedením. Ukládání validuje, že mezi sloty je alespoň `buffer_minutes`. |
| **Ceník** | Záložka A: cenové hladiny (`duj_price_tiers`) — název, vyžaduje kód, zobrazovat ve formuláři, vlastní pravidla uzávěrky. Záložka B: matice hladina × kombinace s cenami, plus sezónní přepisy (weekday / slot / období). Kontrola úplnosti: pro každou aktivní hladinu musí existovat cena pro všechny tři kombinace, jinak varování. Záložka C: **přístupové kódy** — vygenerovat kód, nastavit platnost a počet použití, přehled využití, tlačítko „zkopírovat text pro hosta". |
| **Ubytování** | Přehled dnů s obsazeným apartmánem a použitou politikou (`ignore` / `guests_only` / `closed`). **Import CSV** z WP Booking System: nahrání souboru → mapování sloupců (uloží se pro příště) → náhled dopadu včetně kolizí s existujícími rezervacemi wellness → potvrzení. Ruční přepnutí politiky pro konkrétní den (zamkne se proti přepsání importem). Volba zdroje dat, případně URL iCal feedu, tlačítko „Synchronizovat teď", **stáří dat s varováním** a stav poslední synchronizace včetně chyby adaptéru. |
| **E-maily** | Editor 8 šablon, náhled, test. |
| **Notifikace** | Telegram/SMS/WhatsApp konfigurace + testy + log posledních 100 odeslání. |
| **Nastavení** | Stripe klíče a režim, povolené metody plateb, `hold_minutes`, **`default_slot_minutes` (120) a `buffer_minutes` (60)**, cutoff (čas, režim TZ, min lead time), **výchozí politika pro dny s ubytováním**, délka kalendáře, texty (nadpisy, souhlas + URL VOP, hláška pro vyhrazené dny), vzhled (barvy), bankovní údaje, GDPR retence, ladicí režim. |

**Ochrany v adminu:**
- Úprava termínu potvrzené rezervace musí projít stejnou kontrolou obsazenosti (přes `blocking_key`) — nesmí vzniknout dvojitá rezervace ručně.
- Smazání rezervace se zaplacenou platbou vyžaduje potvrzení a nabídne refundaci.
- Změna rozvrhu, která zneplatní existující potvrzené rezervace, se **nesmí provést tiše** — zobraz varování se seznamem kolizí.

---

## 11. Bezpečnost, GDPR, provoz

### 11.1 Bezpečnost

- Všechny DB dotazy přes `$wpdb->prepare()`. Žádná interpolace do SQL.
- Výstup `esc_html` / `esc_attr` / `esc_url` / `wp_kses_post`.
- Veřejné POST: `wp_verify_nonce` (`wp_rest`), honeypot pole, minimální čas vyplnění 3 s.
- Rate limit přes transienty: `/availability` 60/min/IP, `/bookings` 5/h/IP, `/action` 10/h/IP.
- Admin endpointy: `current_user_can('duj_manage_bookings')` v `permission_callback` — nikdy `__return_true`.
- Tokeny: `random_bytes(32)`, v DB jen SHA-256, porovnání `hash_equals`.
- Stripe: podpis webhooku, klíče nikdy do logu ani do JS (kromě publishable).
- Žádná data karet se nikdy nedotknou tvého serveru (Payment Element = Stripe iframe).
- `uninstall.php` maže tabulky jen když je zapnuto „smazat data při odinstalaci" (default vypnuto).

### 11.2 GDPR

- Souhlas: povinný checkbox s odkazem na `/vop/`, ukládá se `consent_at` + `consent_ip`.
- Účel a doba uchování: rezervační a účetní agenda. Osobní údaje **anonymizuj po 24 měsících** od termínu (cron), účetně relevantní částky ponech.
- Registruj `wp_privacy_personal_data_exporters` a `wp_privacy_personal_data_erasers` (export/výmaz podle e-mailu).
- Do nastavení dej pole „Text zpracování osobních údajů" a odkaz do zápatí formuláře.
- IP ukládej jako `VARBINARY(16)` (`inet_pton`), nikdy do logů v plaintextu déle než 30 dní.

### 11.3 Cron

Použij **Action Scheduler** (knihovna z WooCommerce, funguje i samostatně) — spolehlivější než WP-Cron.

| Job | Frekvence | Co dělá |
|---|---|---|
| `duj_release_expired_holds` | 5 min | `pending_payment` po `hold_expires_at` → `expired`, cancel PI, uvolni slot |
| `duj_warn_auth_expiring` | 1× denně | 24 h před `auth_expires_at` → e-mail + Telegram správci |
| `duj_expire_authorizations` | 1× denně | po `auth_expires_at` bez potvrzení → `cancelled`, e-mail zákazníkovi |
| `duj_send_reminders` | 1× za hodinu | 24 h před termínem → připomínka zákazníkovi |
| `duj_mark_completed` | 1× denně | `confirmed` po termínu → `completed` |
| `duj_sync_accommodation` | 1× za hodinu | načte obsazenost apartmánu a aktualizuje `duj_accommodation_blocks` (kromě `is_manual`) |
| `duj_anonymize_old` | týdně | retence dle nastavení |

Na Hostingeru **zakaž WP-Cron** (`define('DISABLE_WP_CRON', true);`) a nastav systémový cron á 5 min na `wp-cron.php`.

### 11.4 Doručitelnost e-mailů

Kritické — potvrzovací e-maily nesmí končit ve spamu.
1. Odesílatel `rezervace@domecekujosefa.cz` (ne gmail.com).
2. SMTP relay: Brevo (zdarma do 300 mailů/den), Resend nebo SMTP2GO. Plugin použij `wp_mail` + WP Mail SMTP, nebo si nastav `phpmailer_init`.
3. SPF, DKIM, DMARC na doméně.
4. Reply-To na `domecekujosefa@gmail.com`, ať odpovědi chodí kam mají.
5. Loguj každý pokus do `duj_notifications` a v adminu ukaž selhání.

---

## 12. Struktura repozitáře

```
duj-wellness/
├── duj-wellness.php              # hlavička pluginu, bootstrap, autoload
├── uninstall.php
├── composer.json                 # stripe/stripe-php, endroid/qr-code, woocommerce/action-scheduler
├── includes/
│   ├── Plugin.php                # DI kontejner, hooky
│   ├── Activator.php  Deactivator.php
│   ├── Migrations/               # 001_initial.php, 002_….php
│   ├── Domain/
│   │   ├── BookingStatus.php     # enum + matice přechodů
│   │   ├── Money.php  Slot.php  ComboKey.php
│   │   ├── ScheduleResolver.php  # pravidla + výjimky + politika ubytování → sloty
│   │   ├── SlotGenerator.php     # okno + délka + pauza → sloty (pro admin generátor)
│   │   ├── AvailabilityService.php
│   │   ├── PricingService.php  PriceTier.php  TierResolver.php  AccessCodeService.php
│   │   ├── CutoffPolicy.php
│   │   └── BookingService.php    # jediné místo, kde se mění stav
│   ├── Accommodation/
│   │   ├── AccommodationSourceInterface.php
│   │   ├── ManualSource.php  CsvImportSource.php  IcsFeedSource.php  WpBookingSystemSource.php
│   │   ├── CsvColumnMapper.php  IcsParser.php  DayClassifier.php
│   │   └── AccommodationSyncService.php
│   ├── Repository/               # BookingRepository, ScheduleRepository, PriceRepository, TokenRepository
│   ├── Payments/
│   │   ├── PaymentGatewayInterface.php
│   │   ├── StripeGateway.php
│   │   ├── QrBankGateway.php
│   │   └── StripeWebhookHandler.php
│   ├── Notifications/
│   │   ├── NotificationManager.php  TemplateRenderer.php
│   │   └── Channels/ EmailChannel.php TelegramChannel.php SmsChannel.php WhatsAppChannel.php
│   ├── Rest/                     # AvailabilityController, BookingController, ActionController,
│   │                             # WebhookController, Admin*Controller
│   ├── Admin/                    # Menu, BookingsListTable, stránky
│   ├── Frontend/                 # Shortcode.php, Assets.php
│   └── Support/                  # Logger, RateLimiter, Ics, Spayd, Tokens, Dates, Settings
├── assets/
│   ├── css/booking.css  admin.css
│   └── js/booking.js  admin.js
├── templates/
│   ├── booking-form.php
│   ├── action-page.php
│   └── emails/layout.php + 8 šablon
├── languages/duj-wellness-cs_CZ.po|mo
└── tests/                        # PHPUnit (wp-phpunit) + Playwright e2e
```

Pokud composer na hostingu nechceš, přibal `vendor/` do releasu (buildni lokálně `composer install --no-dev -o`).

---

## 13. Implementační plán pro Claude Code

Postupuj po fázích, každou uzavři funkčním a otestovaným celkem. Po každé fázi commit.

### Fáze 0 — Skeleton (0,5 dne)
Struktura pluginu, autoloading (PSR-4 přes composer), aktivace/deaktivace, migrace, seed zdrojů + pravidel + cen + šablon, capability a role, nastavení jako typovaná třída `Settings`.
**Hotovo když:** plugin se aktivuje bez chyb, tabulky existují, seed data jsou v DB.

### Fáze 1 — Rozvrh, hladiny a dostupnost (1,5 dne)
`ScheduleResolver`, `SlotGenerator`, `AvailabilityService`, `CutoffPolicy` (per hladinu), `PricingService`, `TierResolver`, `AccessCodeService`, endpointy `GET /availability`, `GET /config`, `POST /access-code/validate`.
**Hotovo když:** unit testy pokrývají výjimky, DST přechody, cutoff hranice 11:59/12:01 pro obě hladiny, technickou pauzu, prázdné dny; generátor z okna 16:00–21:00 / 120 min / 60 min vyrobí přesně 16:00–18:00 a 19:00–21:00; API vrací pro `public` a `guest` různé ceny.

### Fáze 2 — Rezervace bez platby (1,5 dne)
`BookingService` se stavovým automatem, `POST /bookings`, `blocking_key` + intervalová kontrola pod zámkem, hold, `duj_release_expired_holds`, audit log.
**Hotovo když:** integrační test paralelně vytvoří dvě rezervace na stejný slot a přesně jedna projde s 201, druhá s 409; rezervace 16:00–18:00 znemožní rezervaci 17:00–19:00 kvůli pauze; kombo blokuje oba zdroje; expirace holdu slot uvolní; cena přijatá v requestu je ignorována.

### Fáze 2b — Vazba na ubytování (1 den)
`AccommodationSourceInterface`, `IcsFeedSource` (stažení, rozložení zalomených řádků, sjednocení dnů, klasifikace v paměti), `ManualSource`, `AccommodationSyncService`, tabulka `duj_accommodation_blocks`, hlídání stáří dat, promítnutí politiky do dostupnosti. `CsvImportSource` jako záloha, `WpBookingSystemSource` jen v nouzi.
**Napiš test proti uloženému vzorku feedu** (fixture, ze kterého předem odstraníš osobní údaje) — ne proti živé URL.
**Hotovo když:** vícedenní pobyt s `DTEND` o den dál obsadí správný počet dnů (žádná off-by-one); zalomený `UID` parser nerozbije; pobyt rozsekaný do několika `VEVENT` dá souvislý blok dnů; `SUMMARY`/`DESCRIPTION` se nikde neuloží ani nezaloguje; den označený `guests_only` je pro `public` nedostupný s vlastní hláškou a pro `guest` rezervovatelný; „naše volno" skončí jako `closed`; ruční nastavení přežije synchronizaci; selhání stahování nepřepíše existující data; při stáří dat nad limit se zobrazí varování a v režimu `fail_safe` se uplatní `closed`; výpadek adaptéru neshodí formulář.

### Fáze 3 — Platby Stripe (1,5 dne)
`StripeGateway` (PaymentIntent manual capture, capture, cancel, refund), `qr_checkout`, webhook handler s ověřením podpisu a idempotencí.
**Hotovo když:** v test módu projde celý tok karta → autorizace → capture → refund; webhook je idempotentní (dvojí doručení nezpůsobí dvojí akci); Apple Pay se zobrazí na Safari po ověření domény.

### Fáze 4 — Notifikace (1 den)
Šablony, renderer, `EmailChannel`, action tokeny, GET/POST action stránka, ICS příloha.
**Hotovo když:** potvrzení z e-mailu funguje, druhý klik na stejný odkaz vrátí „odkaz už byl použit", GET nikdy nic nemění.

### Fáze 5 — Frontend (1,5 dne)
Shortcode, kalendář, výběr slotu a služby, formulář, Payment Element, výsledková stránka, CSS s proměnnými.
**Hotovo když:** tok funguje na iPhone Safari i Chrome desktop, je ovladatelný klávesnicí, styl sedí k webu.

### Fáze 6 — Administrace (1,5 dne)
Všech 7 podstránek dle kapitoly 10 včetně hromadné úpravy rozvrhu s náhledem kolizí.
**Hotovo když:** správce zvládne bez editace kódu změnit rozvrh, ceny, texty e-mailů a spravovat rezervace.

### Fáze 7 — Zpevnění (1 den)
Rate limiting, GDPR exportery/erasery, retence, cron joby, logování, i18n (`.pot` + český překlad), README s postupem nasazení.
**Hotovo když:** projde bezpečnostní checklist z kapitoly 11.1.

### Fáze 8 — Volitelné (0,5 dne)
Telegram kanál, SMS, `qr_bank`, widget nejbližších volných termínů, export CSV, statistiky (obsazenost, tržby po měsících).

**Odhad celkem: 8–10 člověkodnů.**

---

## 14. Testy

**Unit (PHPUnit):**
`CutoffPolicy` (DST, hranice, režimy TZ, rozdíl `public` vs. `guest`) · `ScheduleResolver` (priorita výjimka > politika ubytování > pravidlo, rozsahy platnosti) · `SlotGenerator` (okno nedělitelné beze zbytku, pauza 0, slot delší než okno) · `PricingService` (priorita, fallback na výchozí hladinu, chybějící cena) · `TierResolver` (neplatný / vypršelý / vyčerpaný kód) · `Money` (formátování 150000 → „1 500 Kč") · matice přechodů stavů · `Spayd` (formát řetězce).

**Integrační (wp-phpunit + testovací DB):**
Souběh na stejný slot · souběh dvou komb (pořadí zámků, žádný deadlock) · **překryv kvůli technické pauze** · kombo blokuje oba zdroje · den `guests_only` pro obě hladiny · `used_count` kódu se zvýší až při potvrzení platby, ne při pending · expirace holdu · webhook idempotence · jednorázovost tokenů · pokus poslat vlastní `amount_minor` v requestu.

**E2E (Playwright, Stripe test karty):**
`4242 4242 4242 4242` úspěch · `4000 0000 0000 0002` zamítnutí · `4000 0025 0000 3155` 3DS · celý tok rezervace → e-mail (Mailhog) → potvrzení z odkazu → capture.

**Manuální checklist před ostrým provozem:**
Apple Pay na reálném iPhonu · doručitelnost e-mailů (mail-tester.com, cíl ≥ 9/10) · přepnutí Stripe do live módu a ověření webhook endpointu · zobrazení na mobilu 360 px.

---

## 15. Nasazení (Hostinger)

1. **Staging first.** Vytvoř kopii webu, nasaď a otestuj tam. Nikdy nedebuguj na produkci s live Stripe klíči.
2. PHP 8.1+, `memory_limit` ≥ 256M.
3. `wp-config.php`:
   ```php
   define('DISABLE_WP_CRON', true);
   define('DUJ_STRIPE_SECRET_KEY', '…');
   define('DUJ_STRIPE_WEBHOOK_SECRET', '…');
   define('DUJ_ACCOMMODATION_ICS_URL', 'https://…/?wpbs-ical=….ics'); // tajné, ne do wp_options
   ```
4. Systémový cron (hPanel → Cron Jobs), á 5 min:
   `cd /home/uXXXX/domains/domecekujosefa.cz/public_html && php wp-cron.php >/dev/null 2>&1`
5. Stripe Dashboard: webhook na `https://domecekujosefa.cz/wp-json/duj/v1/webhooks/stripe`, události dle 6.3; ověření domény pro Apple Pay.
6. SMTP relay + SPF/DKIM/DMARC.
7. Vlož shortcode na stránku (návrh: nová stránka `/wellness/` + odkaz z homepage sekce Wellness).
8. Zálohy: denní záloha DB (Hostinger) + před každou aktualizací pluginu.
9. Aktualizuj ceny wellness v textech webu (dnes 1 000 Kč) tak, aby odpovídaly oběma hladinám: „1 500 Kč pro veřejnost, 1 000 Kč pro ubytované hosty".
10. Doplň **kód pro ubytované** do potvrzovacího e-mailu rezervace ubytování (WP Booking System) a do uvítacích informací v apartmánu.

---

## 16. Startovní prompt pro Claude Code

```
Přečti si soubor duj-wellness-spec.md v kořeni repozitáře — je to kompletní
zadání rezervačního systému wellness jako WordPress pluginu.

Než začneš psát kód:
1. Shrň mi v 10 bodech, jak pochopíš zadání, a upozorni na cokoliv, co je
   nejednoznačné nebo si odporuje.
2. Navrhni pořadí souborů, které vytvoříš ve Fázi 0, a počkej na moje OK.

Pak implementuj po fázích z kapitoly 13. Po každé fázi:
- napiš testy uvedené u dané fáze a spusť je,
- shrň, co je hotové, a co z toho mám ručně ověřit,
- udělej commit se smysluplnou zprávou,
- zastav se a počkej na moje potvrzení, než začneš další fázi.

Pravidla:
- PHP 8.1+, striktní typy (declare(strict_types=1)), PSR-12, PSR-4 autoloading.
- Žádný build step na frontendu — vanilla JS (ES modules) a CSS.
- Všechny SQL dotazy přes $wpdb->prepare(). Žádná interpolace.
- Stav rezervace se mění výhradně přes BookingService::transition().
- Peníze jsou vždy celá čísla v haléřích.
- Veškerá práce s časem přes DateTimeImmutable s explicitní Europe/Prague.
- Texty pro uživatele česky, přes __() s textdomain 'duj-wellness'.
- Nikdy nelogovat Stripe secret klíče ani osobní údaje.
```

---

## Příloha A — Výchozí obsah e-mailů (návrh k úpravě)

**`customer_booking_received`** — Předmět: `Přijali jsme vaši rezervaci wellness ({{reference}})`
> Dobrý den, {{customer_name}},
> děkujeme za rezervaci wellness v Domečku u Josefa.
> **{{weekday}} {{date}}, {{time_from}}–{{time_to}}** · {{service_label}} · {{price}}
> Rezervaci ještě potvrdíme — dáme vám vědět e-mailem, obvykle do 24 hodin. Částka je zatím na vaší kartě pouze blokovaná, stržena bude až po potvrzení.
> Rezervaci můžete zrušit zde: {{cancel_url}}
> Leona a Míra, {{contact_phone}}

**`admin_booking_new`** — Předmět: `NOVÁ REZERVACE {{reference}} — {{date}} {{time_from}}`
> {{service_label}} · {{weekday}} {{date}} {{time_from}}–{{time_to}} · {{guests}} osob · {{price}}
> {{customer_name}} · {{customer_email}} · {{customer_phone}}
> Poznámka: {{customer_note}}
> **[ POTVRDIT ]({{confirm_url}})  [ ZAMÍTNOUT ]({{reject_url}})**
> Detail v administraci: {{admin_url}}

**`customer_booking_confirmed`** — Předmět: `Rezervace wellness potvrzena — {{date}} {{time_from}}`
> Vaše rezervace je potvrzená. Těšíme se na vás!
> **{{weekday}} {{date}}, {{time_from}}–{{time_to}}** · {{service_label}} · {{price}}
> Adresa: {{address}}. Zatopení, úklid a ručníky jsou v ceně.
> (přiložen soubor pro kalendář)

**`customer_booking_rejected`** — Předmět: `Rezervace wellness {{reference}} — bohužel nemůžeme potvrdit`
> Mrzí nás to, ale termín {{date}} {{time_from}}–{{time_to}} nemůžeme potvrdit. Blokace částky na vaší kartě byla zrušena, nic vám nebylo strženo.
> Rádi vám nabídneme jiný termín — ozvěte se na {{contact_phone}} nebo {{contact_email}}.

---

## Příloha B — Bezpečnostní checklist před spuštěním

- [ ] `permission_callback` u všech admin endpointů kontroluje capability
- [ ] Žádný veřejný endpoint nevrací osobní údaje bez platného tokenu
- [ ] Stripe webhook ověřuje podpis a je idempotentní
- [ ] Action tokeny: jednorázové, hashované, s TTL, GET nic nemění
- [ ] Rate limity aktivní na `/bookings`, `/availability`, `/action`
- [ ] Honeypot + časová kontrola formuláře
- [ ] Všechny výstupy escapované, všechny dotazy prepared
- [ ] Secret klíče nejsou v DB dumpu ani v JS bundlu
- [ ] URL iCal feedu je v `wp-config.php`, v adminu zamaskovaná a nikdy v logu
- [ ] Ze zdroje ubytování se neukládají ani nelogují `SUMMARY` / `DESCRIPTION`
- [ ] `WP_DEBUG_DISPLAY = false` na produkci
- [ ] Souhlas se zpracováním údajů se ukládá s časem a IP
- [ ] Retence a exportery/erasery pro GDPR fungují
- [ ] Zálohy DB nastavené a otestovaně obnovitelné

/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

// Kalender-Abo (FI-8): den echten Feed mit ical.js parsen -- dem Parser, den
// Thunderbird verwendet. Alles lokal, es verlaesst nichts den Rechner.
//
// Ablauf: Anmeldung als user (Token ueber resource=auth), Schalter
// calendar_feed_enabled als admin einschalten, Abo erzeugen, Feed abrufen,
// pruefen; am Ende Abo loeschen und den Schalter auf den vorherigen Wert
// zurueckstellen (finally).
//
// Geprueft: VERSION 2.0 und PRODID; mindestens ein VEVENT; je VEVENT UID
// (eindeutig), DTSTART mit TZID Europe/Berlin, DTEND nach DTSTART, SUMMARY;
// die Umrechnung nach UTC ergibt +1 h im Winter und +2 h im Sommer (zeigt,
// dass der VTIMEZONE-Block richtig gelesen wird); keine Dauer ab 24 h;
// Titel, Ort, Datum und Uhrzeit stimmen mit GET appointments desselben
// Kontos ueberein; Zeilen hoechstens 75 Oktette, CRLF als Zeilenende
// (RFC 5545, 3.1); ein Rueckmelde-Link (URL) zeigt auf checkin/#rueckmeldung=<id>,
// steht nur an kommenden Terminen und als letzte Zeile der Beschreibung. Damit sicher Umlaute dabei sind, legt das Skript als admin
// einen Probetermin mit langem Titel und Ort voller Umlaute an (ueber
// Mitternacht, Terminart eines Termins des Mitglieds) und loescht ihn am Ende.
//
// Aufruf:  node tests/browser/ics-check.mjs
// Konfiguration aus tests/config.php (base_url, admin, user); ES_BASE_URL
// ueberschreibt base_url.

import ICAL from 'ical.js';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PHP  = process.env.PHP_BIN || 'C:/xampp/php/php.exe';
const TZID = 'Europe/Berlin';
const RESPONSE_PREFIXES = ['✓ ', '✗ ', '? '];
const PROBE_TITLE    = 'ics-check Probe: Übungsabend für Blechbläser – Größe, Maß und Öl (äöüÄÖÜß)';
const PROBE_LOCATION = 'Bürgerhaus Großenlüder, Saal „Grün“';
const ICAL_VERSION   = JSON.parse(readFileSync(
    join(dirname(fileURLToPath(import.meta.resolve('ical.js'))), '..', 'package.json'), 'utf8')).version;

function config() {
    const php = `echo json_encode(require '${ROOT.replace(/\\/g, '/')}/tests/config.php');`;
    return JSON.parse(execFileSync(PHP, ['-r', php], { encoding: 'utf8' }));
}

const cfg  = config();
const BASE = (process.env.ES_BASE_URL || cfg.base_url).replace(/\/$/, '');

const failures = [];
let checks = 0;

function check(name, ok, detail = '') {
    checks++;
    if (!ok) {
        failures.push(`${name}${detail ? ` -- ${detail}` : ''}`);
    }
    return ok;
}

async function api(method, resource, { token, query = {}, body } = {}) {
    const url = new URL(`${BASE}/api/api.php`);
    url.searchParams.set('resource', resource);
    for (const [k, v] of Object.entries(query)) {
        url.searchParams.set(k, String(v));
    }
    const headers = { Accept: 'application/json' };
    if (token) headers.Authorization = `Bearer ${token}`;
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    const res = await fetch(url, { method, headers, body: body !== undefined ? JSON.stringify(body) : undefined });
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch { /* kein JSON */ }
    return { status: res.status, json, text };
}

async function login(role) {
    if (!cfg[role]) throw new Error(`Kein Konto '${role}' in tests/config.php`);
    const res = await api('POST', 'auth', { body: { email: cfg[role].email, password: cfg[role].password } });
    if (res.status !== 200 || !res.json?.token) {
        throw new Error(`Anmeldung als ${role} fehlgeschlagen: HTTP ${res.status}`);
    }
    return res.json.token;
}

async function getSetting(adminToken, key) {
    const res = await api('GET', 'settings', { token: adminToken });
    const row = (res.json?.settings ?? []).find(r => r.setting_key === key);
    if (!row) throw new Error(`Einstellung ${key} nicht gefunden (HTTP ${res.status})`);
    return String(row.setting_value);
}

async function setSetting(adminToken, key, value) {
    const res = await api('PUT', 'settings', { token: adminToken, body: { setting_key: key, setting_value: value } });
    if (res.status !== 200) throw new Error(`Einstellung ${key} nicht gesetzt: HTTP ${res.status} ${res.text.slice(0, 200)}`);
}

const pad = n => String(n).padStart(2, '0');
const ymd = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

/** Tag (1-31) des letzten Sonntags im Monat (month 1-12). */
function lastSunday(year, month) {
    const last = new Date(Date.UTC(year, month, 0));
    return last.getUTCDate() - last.getUTCDay();
}

/**
 * Erwarteter Abstand zu UTC in Stunden fuer eine Ortszeit in Berlin, nach der
 * EU-Regel (letzter Sonntag im Maerz 02:00 bis letzter Sonntag im Oktober 03:00).
 * null fuer die doppelte Stunde im Oktober -- dort ist beides richtig.
 */
function expectedOffset(t) {
    const { year, month, day, hour } = t;
    const mar = lastSunday(year, 3);
    const oct = lastSunday(year, 10);
    if (month > 3 && month < 10) return 2;
    if (month < 3 || month > 10) return 1;
    if (month === 3) {
        if (day > mar) return 2;
        if (day < mar) return 1;
        return hour >= 2 ? 2 : 1;
    }
    if (day < oct) return 2;
    if (day > oct) return 1;
    if (hour < 2) return 2;
    if (hour >= 3) return 1;
    return null;
}

const localText = t => `${t.year}-${pad(t.month)}-${pad(t.day)} ${pad(t.hour)}:${pad(t.minute)}`;

function stripPrefix(summary) {
    for (const p of RESPONSE_PREFIXES) {
        if (summary.startsWith(p)) return summary.slice(p.length);
    }
    return summary;
}

/**
 * Legt den Probetermin an: Terminart eines kuenftigen Termins, den das Mitglied
 * sieht, 23:15 bis 00:45. Ein Tag mit einem Termin derselben Art liefert 409 --
 * dann der naechste Tag.
 */
async function createProbe(adminToken, userToken) {
    const list = await api('GET', 'appointments', { token: userToken, query: { from_date: ymd(new Date()) } });
    const typeId = (list.json ?? []).find(a => a.type_id)?.type_id;
    if (!typeId) throw new Error('Keine kuenftige Terminart fuer das Testkonto user gefunden');
    for (let i = 10; i < 40; i++) {
        const d = new Date();
        d.setDate(d.getDate() + i);
        const res = await api('POST', 'appointments', { token: adminToken, body: {
            title: PROBE_TITLE, location: PROBE_LOCATION, type_id: typeId,
            date: ymd(d), start_time: '23:15:00', end_time: '00:45:00',
        } });
        if ((res.status === 201 || res.status === 200) && res.json?.id) return Number(res.json.id);
        if (res.status !== 409) throw new Error(`Probetermin anlegen: HTTP ${res.status} ${res.text.slice(0, 200)}`);
    }
    throw new Error('Probetermin: kein freier Tag gefunden');
}

async function main() {
    const adminToken = await login('admin');
    const userToken  = await login('user');

    const before = await getSetting(adminToken, 'calendar_feed_enabled');
    let created = false;
    let probeId = null;
    try {
        if (before !== '1') await setSetting(adminToken, 'calendar_feed_enabled', '1');

        const status = await api('GET', 'calendar_feed', { token: userToken });
        if (status.status !== 200) throw new Error(`Status calendar_feed: HTTP ${status.status}`);
        if (!status.json.member_linked) throw new Error('Das Testkonto user hat kein verknuepftes Mitglied');
        if (status.json.active) {
            throw new Error('Das Testkonto user hat schon ein Abo -- abgebrochen, um es nicht zu ersetzen');
        }

        probeId = await createProbe(adminToken, userToken);

        const post = await api('POST', 'calendar_feed', { token: userToken, body: {} });
        if (post.status !== 201 || !post.json?.url) throw new Error(`Abo erzeugen: HTTP ${post.status}`);
        created = true;

        // Abgerufen wird der Link, den der Server ausgibt -- wie eine Kalender-App.
        const feedRes = await fetch(post.json.url);
        check('Feed antwortet 200', feedRes.status === 200, `HTTP ${feedRes.status} fuer ${post.json.url.replace(/[0-9a-f]{64}/, '<token>')}`);
        check('Content-Type text/calendar', (feedRes.headers.get('content-type') ?? '').startsWith('text/calendar'),
            feedRes.headers.get('content-type') ?? '-');
        const raw = await feedRes.text();
        if (feedRes.status !== 200) return;

        // RFC 5545, 3.1: CRLF und hoechstens 75 Oktette je Zeile (ohne CRLF)
        check('Zeilenende CRLF', !/(^|[^\r])\n/.test(raw));
        const longLines = raw.split('\r\n').filter(l => Buffer.byteLength(l, 'utf8') > 75);
        check('Zeilen hoechstens 75 Oktette', longLines.length === 0, `${longLines.length} zu lang, z. B. ${longLines[0]?.slice(0, 40)}`);

        const cal = new ICAL.Component(ICAL.parse(raw));
        check('Wurzel VCALENDAR', cal.name === 'vcalendar', cal.name);
        check('VERSION 2.0', cal.getFirstPropertyValue('version') === '2.0', String(cal.getFirstPropertyValue('version')));
        check('PRODID vorhanden', !!cal.getFirstPropertyValue('prodid'));

        const vtz = cal.getFirstSubcomponent('vtimezone');
        check('VTIMEZONE vorhanden', !!vtz);
        if (vtz) {
            const tz = new ICAL.Timezone(vtz);
            check('VTIMEZONE heisst Europe/Berlin', tz.tzid === TZID, tz.tzid);
            ICAL.TimezoneService.register(tz);
        }

        const events = cal.getAllSubcomponents('vevent');
        check('Mindestens ein VEVENT', events.length > 0, `${events.length}`);

        // Vergleichsdaten: dieselbe Terminliste, die das Mitglied in der App sieht
        const today = new Date();
        const from  = new Date(today); from.setMonth(from.getMonth() - 3); from.setDate(from.getDate() - 1);
        const to    = new Date(today); to.setMonth(to.getMonth() + 12); to.setDate(to.getDate() + 1);
        const list  = await api('GET', 'appointments', { token: userToken, query: { from_date: ymd(from), to_date: ymd(to) } });
        if (list.status !== 200 || !Array.isArray(list.json)) throw new Error(`GET appointments: HTTP ${list.status}`);
        const byId = new Map(list.json.map(a => [String(a.appointment_id), a]));

        const uids = new Set();
        let first = null;
        let last = null;
        let nonAscii = 0;
        let compared = 0;
        let links = 0;
        const checkinBase = post.json.url.replace(/\/api\/calendar\/[0-9a-f]{64}\.ics$/, '') + '/checkin/#rueckmeldung=';
        const todayYmd = ymd(new Date());
        const offsets = { 1: 0, 2: 0 };

        for (const comp of events) {
            const ev  = new ICAL.Event(comp);
            const uid = ev.uid ?? '';
            const tag = uid || '(ohne UID)';

            check(`${tag}: UID vorhanden`, uid !== '');
            check(`${tag}: UID eindeutig`, !uids.has(uid));
            uids.add(uid);

            const summary = ev.summary ?? '';
            check(`${tag}: SUMMARY vorhanden`, summary.trim() !== '');

            const dtstart = comp.getFirstProperty('dtstart');
            const dtend   = comp.getFirstProperty('dtend');
            if (!check(`${tag}: DTSTART vorhanden`, !!dtstart) || !check(`${tag}: DTEND vorhanden`, !!dtend)) continue;
            check(`${tag}: DTSTART mit TZID ${TZID}`, dtstart.getParameter('tzid') === TZID, String(dtstart.getParameter('tzid')));
            check(`${tag}: DTEND mit TZID ${TZID}`, dtend.getParameter('tzid') === TZID, String(dtend.getParameter('tzid')));

            const start = ev.startDate;
            const end   = ev.endDate;
            check(`${tag}: DTSTART in der Zone ${TZID} gelesen`, start.zone?.tzid === TZID, String(start.zone?.tzid));

            const startUtc = start.toUnixTime();
            const endUtc   = end.toUnixTime();
            const dauerH   = (endUtc - startUtc) / 3600;
            check(`${tag}: DTEND nach DTSTART`, endUtc > startUtc, `${localText(start)} -> ${localText(end)}`);
            check(`${tag}: Dauer unter 24 h`, dauerH < 24, `${dauerH} h`);

            // Ortszeit als UTC gelesen minus echte UTC-Zeit = Abstand der Zone
            const wall   = Date.UTC(start.year, start.month - 1, start.day, start.hour, start.minute, start.second) / 1000;
            const actual = (wall - startUtc) / 3600;
            const expect = expectedOffset(start);
            if (expect !== null) {
                check(`${tag}: UTC-Abstand ${localText(start)}`, actual === expect, `erwartet +${expect} h, erhalten ${actual} h`);
                offsets[expect]++;
            }

            if (first === null || startUtc < first.t) first = { t: startUtc, s: localText(start) };
            if (last === null || startUtc > last.t) last = { t: startUtc, s: localText(start) };

            // Abgleich mit der Terminliste
            const id = (uid.match(/^appointment-(\d+)@/) ?? [])[1];
            const appt = id ? byId.get(id) : undefined;
            if (!check(`${tag}: Termin in GET appointments`, !!appt)) continue;
            compared++;
            const title = stripPrefix(summary);
            check(`${tag}: SUMMARY = Titel`, title === appt.title, `${JSON.stringify(title)} vs. ${JSON.stringify(appt.title)}`);
            const loc = (appt.location ?? '').trim();
            const feedLoc = comp.getFirstPropertyValue('location') ?? '';
            check(`${tag}: LOCATION = Ort`, feedLoc === loc, `${JSON.stringify(feedLoc)} vs. ${JSON.stringify(loc)}`);
            const wantStart = `${appt.date} ${String(appt.start_time).slice(0, 5)}`;
            check(`${tag}: Beginn = Datum/Uhrzeit`, localText(start) === wantStart, `${localText(start)} vs. ${wantStart}`);
            if (/[^\x00-\x7f]/.test(title + feedLoc)) nonAscii++;

            // Rueckmelde-Link (Entscheidung 2026-10-07): URL als URI-Wert und als
            // letzte Zeile der Beschreibung, nur fuer heute und spaeter
            const url = comp.getFirstPropertyValue('url');
            if (url) {
                links++;
                check(`${tag}: URL = Rueckmelde-Link`, url === checkinBase + id, `${url}`);
                check(`${tag}: Rueckmelde-Link nur fuer kommende Termine`, appt.date >= todayYmd, appt.date);
                const desc = comp.getFirstPropertyValue('description') ?? '';
                check(`${tag}: Beschreibung endet mit dem Link`,
                    desc.endsWith(`Rückmeldung geben: ${url}`) || desc.endsWith(`Rückmeldung ändern: ${url}`), JSON.stringify(desc.slice(-120)));
            }
        }

        const probe = events.map(c => new ICAL.Event(c)).find(e => (e.uid ?? '').startsWith(`appointment-${probeId}@`));
        if (check('Probetermin im Feed', !!probe)) {
            check('Probetermin: Titel mit Umlauten unversehrt', stripPrefix(probe.summary) === PROBE_TITLE, probe.summary);
            check('Probetermin: Ort mit Umlauten unversehrt', probe.location === PROBE_LOCATION, probe.location);
            check('Probetermin: endet am Folgetag 00:45',
                localText(probe.endDate).endsWith(' 00:45') && probe.endDate.day !== probe.startDate.day,
                localText(probe.endDate));
        }

        console.log('ical.js-Prüfung des Kalender-Feeds (FI-8)');
        console.log(`  ical.js:        ${ICAL_VERSION}`);
        console.log(`  Termine:        ${events.length} (davon ${compared} mit der Terminliste abgeglichen, ${nonAscii} mit Umlauten/Sonderzeichen)`);
        console.log(`  Rückmelde-Link: ${links} Termine`);
        console.log(`  Zeitraum:       ${first?.s ?? '-'} bis ${last?.s ?? '-'} (Ortszeit ${TZID})`);
        console.log(`  UTC-Abstand:    ${offsets[1]} × +1 h (Winter), ${offsets[2]} × +2 h (Sommer)`);
    } finally {
        if (probeId !== null) {
            const del = await api('DELETE', 'appointments', { token: adminToken, query: { id: probeId } });
            check('Probetermin am Ende geloescht', del.status === 200, `HTTP ${del.status}`);
        }
        if (created) {
            const del = await api('DELETE', 'calendar_feed', { token: userToken });
            check('Abo am Ende geloescht', del.status === 200, `HTTP ${del.status}`);
        }
        if (before !== '1') await setSetting(adminToken, 'calendar_feed_enabled', before);
        const after = await getSetting(adminToken, 'calendar_feed_enabled');
        check('Schalter zurueckgestellt', after === before, `vorher ${before}, nachher ${after}`);
    }
}

try {
    await main();
} catch (e) {
    failures.push(`Abbruch: ${e.message}`);
}

console.log(`  Prüfungen:      ${checks - failures.length} ok, ${failures.length} Fehler`);
for (const f of failures.slice(0, 30)) console.log(`  FEHLER ${f}`);
if (failures.length > 30) console.log(`  ... und ${failures.length - 30} weitere`);
process.exit(failures.length > 0 ? 1 : 0);

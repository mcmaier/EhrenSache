# Member Aktiv-Status Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mitglieder-Aktivstatus aus `membership_dates` korrekt in Statistik, Dropdowns, Anwesenheitsansicht und Mitgliederübersicht (Toggle + Zeilenfärbung) abbilden.

**Architecture:** Hybrid-Ansatz — Backend (`statistics.php`) nutzt jahresbasierte SQL-Filterung via `getMemberActivityWhereYear()`; Frontend filtert Dropdowns und Ansichten aus dem bestehenden Cache via `is_active_in_period`. Toggle in der Mitgliederübersicht ist reiner Frontend-State, kein Extra-API-Call.

**Tech Stack:** PHP 8, MySQL, Vanilla JS (ES6 Modules), CSS Custom Properties

---

## Betroffene Dateien

| Datei | Art | Zweck |
|-------|-----|-------|
| `private/helpers/member_activity.php` | Modify | Neue Funktion `getMemberActivityWhereYear()` hinzufügen |
| `private/handlers/statistics.php` | Modify | `m.active = 1` durch jahresbasierte Filterung ersetzen |
| `public/css/components/tables.css` | Modify | `.row-inactive` CSS-Klasse hinzufügen |
| `public/js/modules/members.js` | Modify | Toggle-Logik + Zeilenfärbung + Modal-Badge |
| `public/index.html` | Modify | `member_active` Checkbox → read-only Badge |
| `public/js/modules/records.js` | Modify | `m.active` → `m.is_active_in_period` in Dropdowns + Ansicht |
| `public/js/modules/exceptions.js` | Modify | `m.active` → `m.is_active_in_period` im Dropdown |
| `public/js/modules/statistics.js` | Modify | `m.active` → `m.is_active_in_period` im Mitgliederfilter |

---

## Task 1: getMemberActivityWhereYear in member_activity.php

**Files:**
- Modify: `private/helpers/member_activity.php` (nach Zeile 54, neue Funktion anhängen)

### Hintergrund
`getMemberActivityWhere()` prüft einen konkreten Datumswert. Für Statistiken brauchen wir eine jahresbasierte Überschneidungsprüfung: Mitglied war irgendwann im Jahr aktiv = Periode überlappt mit [YYYY-01-01, YYYY-12-31].

- [ ] **Schritt 1: Neue Funktion in member_activity.php einfügen**

Nach der schließenden `}` von `getMemberActivityWhere()` (aktuell Zeile 54) einfügen:

```php
/**
 * Generiert WHERE-Clause für Mitglieder-Aktivität für ein ganzes Jahr.
 * Prüft ob Mitglied irgendwann im angegebenen Jahr aktiv war (Periodenüberschneidung).
 *
 * @param int    $year        Jahr für den Aktivitätscheck (z.B. 2026)
 * @param string $memberAlias Tabellen-Alias für members (Standard: 'm')
 * @return string SQL WHERE-Clause Fragment
 */
function getMemberActivityWhereYear($year, $memberAlias = 'm') {
    global $database;
    $prefix = $database->table('');
    $year      = (int)$year;
    $yearStart = $year . '-01-01';
    $yearEnd   = $year . '-12-31';

    return "
        {$memberAlias}.active = 1
        AND (
            -- Keine membership_dates → immer aktiv
            NOT EXISTS (
                SELECT 1 FROM {$prefix}membership_dates md
                WHERE md.member_id = {$memberAlias}.member_id
            )
            OR
            -- Hat membership_dates → muss im Jahr irgendwann aktiv gewesen sein
            EXISTS (
                SELECT 1 FROM {$prefix}membership_dates md
                WHERE md.member_id = {$memberAlias}.member_id
                AND md.start_date <= '{$yearEnd}'
                AND (md.end_date IS NULL OR md.end_date >= '{$yearStart}')
            )
        )
    ";
}
```

- [ ] **Schritt 2: Syntaxcheck**

```bash
php -l private/helpers/member_activity.php
```
Erwartete Ausgabe: `No syntax errors detected`

- [ ] **Schritt 3: Commit**

```bash
git add private/helpers/member_activity.php
git commit -m "feat: add getMemberActivityWhereYear helper for year-based member filtering"
```

---

## Task 2: statistics.php — Jahr-basierte Mitgliederfilterung

**Files:**
- Modify: `private/handlers/statistics.php` (Zeilen 275, 297, 308–314, 319–320, 381–388)

### Hintergrund
Drei SQL-Stellen in `statistics.php` nutzen `m.active = 1` ohne Berücksichtigung der `membership_dates`. Diese werden durch `getMemberActivityWhereYear($year, 'm')` ersetzt. `member_activity.php` ist bereits via `members.php` (require_once Zeile 17) geladen, wenn `statistics.php` läuft — kein zusätzliches require_once nötig.

- [ ] **Schritt 1: $year-Parameter zu getActiveMemberCount-Aufruf hinzufügen**

In `handleStatistics()`, Zeile 275, den Aufruf ändern:

```php
// Vorher:
$totalMembers = getActiveMemberCount($db, $database, $groups, $memberId);

// Nachher:
$totalMembers = getActiveMemberCount($db, $database, $groups, $year, $memberId);
```

- [ ] **Schritt 2: Funktionssignatur von getActiveMemberCount aktualisieren**

Zeile 297:

```php
// Vorher:
function getActiveMemberCount($db, $database, $groupIds, $specificMemberId = null) {

// Nachher:
function getActiveMemberCount($db, $database, $groupIds, $year, $specificMemberId = null) {
```

- [ ] **Schritt 3: Gruppen-Query in getActiveMemberCount ersetzen**

Zeilen 308–314 (Query mit `AND m.active = 1`):

```php
// Vorher:
$stmt = $db->prepare("
    SELECT COUNT(DISTINCT mga.member_id)
    FROM {$prefix}member_group_assignments mga
    JOIN {$prefix}members m ON mga.member_id = m.member_id
    WHERE mga.group_id IN ($placeholders)
    AND m.active = 1
");

// Nachher:
$stmt = $db->prepare("
    SELECT COUNT(DISTINCT mga.member_id)
    FROM {$prefix}member_group_assignments mga
    JOIN {$prefix}members m ON mga.member_id = m.member_id
    WHERE mga.group_id IN ($placeholders)
    AND " . getMemberActivityWhereYear($year, 'm') . "
");
```

- [ ] **Schritt 4: Gesamt-Mitglieder-Query in getActiveMemberCount ersetzen**

Zeile 319–320 (Fallback ohne Gruppen):

```php
// Vorher:
$stmt = $db->query("SELECT COUNT(*) FROM {$prefix}members WHERE active = 1");

// Nachher:
$activityWhere = getMemberActivityWhereYear($year, 'm');
$stmt = $db->query("SELECT COUNT(*) FROM {$prefix}members m WHERE {$activityWhere}");
```

- [ ] **Schritt 5: Query in calculateGroupStatistics ersetzen**

Zeilen 381–388 (`AND m.active = 1`):

```php
// Vorher:
$stmt = $db->prepare("
    SELECT DISTINCT mga.member_id, mg.group_name
    FROM {$prefix}member_group_assignments mga
    JOIN {$prefix}members m ON mga.member_id = m.member_id
    LEFT JOIN {$prefix}member_groups mg ON mga.group_id = mg.group_id
    WHERE mga.group_id = ? AND m.active = 1
    ORDER BY m.surname, m.name
");

// Nachher:
$stmt = $db->prepare("
    SELECT DISTINCT mga.member_id, mg.group_name
    FROM {$prefix}member_group_assignments mga
    JOIN {$prefix}members m ON mga.member_id = m.member_id
    LEFT JOIN {$prefix}member_groups mg ON mga.group_id = mg.group_id
    WHERE mga.group_id = ? AND " . getMemberActivityWhereYear($year, 'm') . "
    ORDER BY m.surname, m.name
");
```

- [ ] **Schritt 6: Syntaxcheck**

```bash
php -l private/handlers/statistics.php
```
Erwartete Ausgabe: `No syntax errors detected`

- [ ] **Schritt 7: Manuell testen**

Statistikseite im Browser öffnen, Jahr wählen in dem ein Mitglied per `membership_dates` inaktiv ist → Mitglied darf nicht mehr in Gesamtanzahl und Gruppenstatistik auftauchen.

- [ ] **Schritt 8: Commit**

```bash
git add private/handlers/statistics.php
git commit -m "fix: filter statistics by membership_dates year overlap instead of active flag"
```

---

## Task 3: CSS — .row-inactive Klasse

**Files:**
- Modify: `public/css/components/tables.css` (Ende der Datei)

- [ ] **Schritt 1: CSS-Klasse ans Ende von tables.css anhängen**

```css
/* Inaktive Mitglieder-Zeilen (basierend auf membership_dates) */
tr.row-inactive td {
    opacity: 0.45;
    background-color: var(--bg-gray);
}

tr.row-inactive:hover td {
    opacity: 0.65;
    background-color: var(--bg-light);
}
```

- [ ] **Schritt 2: Commit**

```bash
git add public/css/components/tables.css
git commit -m "feat: add row-inactive CSS class for inactive member rows"
```

---

## Task 4: members.js — Toggle-Filter + Zeilenfärbung

**Files:**
- Modify: `public/index.html` (Zeile 744 — `checked`-Attribut entfernen)
- Modify: `public/js/modules/members.js` (Modul-Variable, `showMemberSection`, `renderMembers`, `resetMemberFilter`)

### Hintergrund
Das `show_inactive_members`-Checkbox existiert bereits in index.html (Zeile 744), ist aber noch nicht mit JS verbunden. Default muss `unchecked` sein (= nur Aktive anzeigen). Der Toggle gilt nur für Admin/Manager.

- [ ] **Schritt 1: checked-Attribut aus dem Checkbox entfernen (index.html, Zeile 744)**

```html
<!-- Vorher: -->
<input type="checkbox" id="show_inactive_members" class="checkbox-label" checked>

<!-- Nachher: -->
<input type="checkbox" id="show_inactive_members" class="checkbox-label">
```

- [ ] **Schritt 2: Initialisierungs-Flag als Modul-Variable in members.js hinzufügen**

Nach den bestehenden Modul-Variablen (nach Zeile 34 `let currentMemberGroups = [];`) einfügen:

```javascript
let memberFilterInitialized = false;
```

- [ ] **Schritt 3: showMemberSection mit Filter-Logik und einmaliger Event-Registrierung erweitern**

Funktion `showMemberSection` (Zeile 288–298) ersetzen:

```javascript
export async function showMemberSection(forceReload = false, page = 1) {
    debug.log("Show Member Section ()");

    const allMembers = await loadMembers(forceReload);

    // Event-Listener einmalig registrieren (Admin/Manager)
    if (!memberFilterInitialized && isAdminOrManager) {
        const checkbox = document.getElementById('show_inactive_members');
        if (checkbox) {
            checkbox.addEventListener('change', () => showMemberSection(false));
        }
        const groupFilter = document.getElementById('filterMemberGroup');
        if (groupFilter) {
            groupFilter.addEventListener('change', () => showMemberSection(false));
        }
        memberFilterInitialized = true;
    }

    // Gruppen-Dropdown befüllen (einmalig)
    if (isAdminOrManager) {
        const groupFilter = document.getElementById('filterMemberGroup');
        if (groupFilter && groupFilter.options.length <= 1) {
            const { dataCache } = await import('./ui.js');
            groupFilter.innerHTML = '<option value="">Alle Gruppen</option>';
            dataCache.groups.data.forEach(g => {
                groupFilter.innerHTML += `<option value="${g.group_id}">${g.group_name}</option>`;
            });
        }
    }

    const currentSection = sessionStorage.getItem('currentSection');
    if (currentSection === 'mitglieder') {
        const showInactive = isAdminOrManager &&
            document.getElementById('show_inactive_members')?.checked;
        const selectedGroupId = document.getElementById('filterMemberGroup')?.value;

        let displayMembers = showInactive
            ? allMembers
            : allMembers.filter(m => m.is_active_in_period);

        if (selectedGroupId) {
            displayMembers = displayMembers.filter(m =>
                m.group_ids_array && m.group_ids_array.includes(parseInt(selectedGroupId))
            );
        }

        renderMembers(displayMembers, page);
    }
}
```

- [ ] **Schritt 4: renderMembers — row-inactive Klasse für inaktive Zeilen setzen**

In `renderMembers()`, direkt nach `const tr = document.createElement('tr');` (Zeile 129) einfügen:

```javascript
if (!member.is_active_in_period) {
    tr.classList.add('row-inactive');
}
```

- [ ] **Schritt 5: resetMemberFilter implementieren**

Funktion `resetMemberFilter` (Zeile 301–304) ersetzen:

```javascript
export function resetMemberFilter() {
    const showInactiveEl = document.getElementById('show_inactive_members');
    if (showInactiveEl) showInactiveEl.checked = false;
    const groupFilterEl = document.getElementById('filterMemberGroup');
    if (groupFilterEl) groupFilterEl.value = '';
    showMemberSection(false);
}
```

- [ ] **Schritt 6: Manuell testen**

1. Als Admin: Mitgliederverwaltung öffnen → nur aktive Mitglieder sichtbar
2. "Inaktive anzeigen" anhaken → inaktive Mitglieder erscheinen gedimmt
3. Gruppe filtern → kombinierte Filterung funktioniert
4. "Filter zurücksetzen" → Checkbox unchecked, Gruppe leer, nur Aktive sichtbar

- [ ] **Schritt 7: Commit**

```bash
git add public/index.html public/js/modules/members.js
git commit -m "feat: implement show-inactive toggle and row highlighting in member list"
```

---

## Task 5: members.js + index.html — Modal is_active als read-only Badge

**Files:**
- Modify: `public/index.html` (Zeile 1029–1033)
- Modify: `public/js/modules/members.js` (Zeilen 339, 368, 422)

### Hintergrund
Das `member_active`-Checkbox im Modal wird durch einen read-only Badge ersetzt. Der angezeigte Status kommt aus `is_active_in_period` (berechnet für das gewählte Jahr). Im Save-Payload wird `active: 1` fest gesetzt, da der Aktivstatus ausschließlich über `membership_dates` gesteuert wird.

- [ ] **Schritt 1: Checkbox in index.html durch Badge-Anzeige ersetzen**

Zeilen 1029–1033:

```html
<!-- Vorher: -->
<div class="form-group">
    <label>       
        <input type="checkbox" id="member_active" checked readonly>
        <span>Aktiv</span>
    </label>
</div>

<!-- Nachher: -->
<div class="form-group">
    <label>Status im gewählten Jahr</label>
    <span id="member_active_status" class="status-badge status-approved">Aktiv</span>
</div>
```

- [ ] **Schritt 2: Neues Mitglied — Badge-Initialisierung in openMemberModal**

Zeile 339 in `openMemberModal()`:

```javascript
// Vorher:
document.getElementById('member_active').checked = true;

// Nachher:
const statusEl = document.getElementById('member_active_status');
if (statusEl) {
    statusEl.textContent = 'Aktiv';
    statusEl.className = 'status-badge status-approved';
}
```

- [ ] **Schritt 3: Bestehendes Mitglied — Badge aus is_active_in_period setzen**

Zeile 368 in `loadMemberData()`:

```javascript
// Vorher:
document.getElementById('member_active').checked = member.active == 1;

// Nachher:
const statusEl = document.getElementById('member_active_status');
if (statusEl) {
    const isActive = member.is_active_in_period;
    statusEl.textContent = isActive ? 'Aktiv' : 'Inaktiv';
    statusEl.className = isActive
        ? 'status-badge status-approved'
        : 'status-badge status-rejected';
}
```

- [ ] **Schritt 4: saveMember — active fest auf 1 setzen**

Zeile 422 in `saveMember()`:

```javascript
// Vorher:
active: document.getElementById('member_active').checked,

// Nachher:
active: 1,  // Aktivstatus wird über membership_dates gesteuert, nicht direkt
```

- [ ] **Schritt 5: Manuell testen**

1. Mitglied öffnen das aktiv ist → Badge zeigt "Aktiv" (grün)
2. Mitglied öffnen das inaktiv ist → Badge zeigt "Inaktiv" (rot)
3. Neues Mitglied anlegen → Badge zeigt "Aktiv"
4. Speichern → kein Fehler, Mitglied gespeichert

- [ ] **Schritt 6: Commit**

```bash
git add public/index.html public/js/modules/members.js
git commit -m "feat: replace is_active checkbox with read-only status badge in member modal"
```

---

## Task 6: records.js — Dropdowns und Ansicht auf is_active_in_period filtern

**Files:**
- Modify: `public/js/modules/records.js` (Zeilen 486, 828–832)

### Hintergrund
`loadMembers()` lädt für Admin/Manager bereits alle Mitglieder (`include_inactive: true`) mit dem korrekten `is_active_in_period`-Wert für das aktuelle Jahr. Die bisherige Filterung `m.active` zeigt auch Mitglieder die per `membership_dates` inaktiv sind. Beide Dropdown-Stellen müssen auf `m.is_active_in_period` umgestellt werden.

- [ ] **Schritt 1: Filter-Dropdown (Anwesenheitsansicht) auf is_active_in_period umstellen**

Zeile 486:

```javascript
// Vorher:
.filter(m => m.active)

// Nachher:
.filter(m => m.is_active_in_period)
```

- [ ] **Schritt 2: Modal-Dropdown (neuen Record anlegen) auf is_active_in_period umstellen**

Zeilen 828–832:

```javascript
// Vorher:
members
    .filter(m => m.active)
    .forEach(member => {
        memberSelect.innerHTML += `<option value="${member.member_id}">${member.surname}, ${member.name}</option>`;
    });

// Nachher:
members
    .filter(m => m.is_active_in_period)
    .forEach(member => {
        memberSelect.innerHTML += `<option value="${member.member_id}">${member.surname}, ${member.name}</option>`;
    });
```

- [ ] **Schritt 3: Records-Tabelle — Einträge inaktiver Mitglieder ausblenden**

In `applyRecordFilters()` (Zeile ~511), nach der `filterRecords`-Zeile (Zeile 525) einfügen:

```javascript
// Vorher:
const filteredRecords = filterRecords(allRecords, filters);

// Nachher:
const filteredRecords = filterRecords(allRecords, filters);

// Einträge inaktiver Mitglieder für das gewählte Jahr ausblenden
const members = await loadMembers();
const activeFilteredRecords = filteredRecords.filter(r => {
    const member = members.find(m => m.member_id === r.member_id);
    // Nicht gefundenes Mitglied (z.B. gelöscht) → trotzdem anzeigen
    return !member || member.is_active_in_period;
});
```

Und die darauf folgende `renderRecords`-Zeile anpassen:

```javascript
// Vorher:
renderRecords(filteredRecords, currentPage);

// Nachher:
renderRecords(activeFilteredRecords, currentPage);
```

- [ ] **Schritt 4: Manuell testen**

1. Anwesenheitserfassung öffnen → Mitglieder-Filter zeigt nur aktive Mitglieder für gewähltes Jahr
2. "Neuer Record" → Mitglieder-Dropdown zeigt nur aktive Mitglieder

- [ ] **Schritt 5: Commit**

```bash
git add public/js/modules/records.js
git commit -m "fix: filter record member dropdowns and attendance view by is_active_in_period"
```

---

## Task 7: exceptions.js — Dropdown auf is_active_in_period filtern

**Files:**
- Modify: `public/js/modules/exceptions.js` (Zeile 449)

- [ ] **Schritt 1: Member-Dropdown auf is_active_in_period umstellen**

Zeile 449:

```javascript
// Vorher:
members.filter(m => m.active).forEach(member => {

// Nachher:
members.filter(m => m.is_active_in_period).forEach(member => {
```

- [ ] **Schritt 2: Manuell testen**

"Neue Ausnahme" öffnen → Mitglieder-Dropdown zeigt nur aktive Mitglieder für das gewählte Jahr.

- [ ] **Schritt 3: Commit**

```bash
git add public/js/modules/exceptions.js
git commit -m "fix: filter exception member dropdown by is_active_in_period"
```

---

## Task 8: statistics.js — Frontend-Mitgliederfilter auf is_active_in_period umstellen

**Files:**
- Modify: `public/js/modules/statistics.js` (Zeile 116)

- [ ] **Schritt 1: Mitglieder-Filter in updateStatisticsFilters umstellen**

Zeile 116 in `updateStatisticsFilters()`:

```javascript
// Vorher:
let filteredMembers = allMembers.filter(m => m.active);

// Nachher:
let filteredMembers = allMembers.filter(m => m.is_active_in_period);
```

- [ ] **Schritt 2: Manuell testen**

Statistikseite öffnen → Mitglieder-Dropdown zeigt nur Mitglieder die im gewählten Jahr aktiv waren.

- [ ] **Schritt 3: Commit**

```bash
git add public/js/modules/statistics.js
git commit -m "fix: filter statistics member dropdown by is_active_in_period"
```

---

## Abschluss-Verifikation

Nach Abschluss aller Tasks folgende Szenarien manuell testen:

1. **Statistik:** Mitglied das 2025 inaktiv war, 2026 aktiv ist → erscheint nur in 2026er Statistik
2. **Mitgliederliste:** Toggle "Inaktive anzeigen" → inaktive Zeilen erscheinen gedimmt; Filter zurücksetzen → wieder nur aktive
3. **Record anlegen:** Dropdown zeigt kein inaktives Mitglied
4. **Ausnahme anlegen:** Dropdown zeigt kein inaktives Mitglied
5. **Mitglied-Modal öffnen:** Status-Badge zeigt korrekt "Aktiv" / "Inaktiv" für das gewählte Jahr

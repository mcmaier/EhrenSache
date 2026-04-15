# Dropdown Cross-Filtering (Records & Exceptions) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mitglied- und Termin-Dropdowns in Record- und Exception-Modal gegenseitig filtern, sodass nur gruppenkompatible Kombinationen wählbar sind.

**Architecture:** Rein clientseitige Filterung auf Basis bereits gecachter Daten (`members`, `appointments`, `types`). Zwei neue Hilfsfunktionen in `utils.js`, refaktorierte Dropdown-Builder in `records.js` und `exceptions.js`, je ein `change`-Event-Listener pro Dropdown.

**Tech Stack:** Vanilla JS ES6 Modules, kein Build-Step. Testsystem: `http://localhost:8123/ehrensache/public` (Admin: `admin@example.com` / `test1234`).

---

## Dateien

| Datei | Änderung |
|-------|----------|
| `public/js/modules/utils.js` | 2 neue Exportfunktionen am Ende |
| `public/js/modules/records.js` | Import erweitern, 3 neue Hilfsfunktionen, `loadRecordDropdowns()` refaktorieren, `openRecordModal()` Event-Listener anpassen |
| `public/js/modules/exceptions.js` | Import erweitern, 3 neue Hilfsfunktionen, `loadExceptionModalFilters()` refaktorieren |

---

## Task 1: Kompatibilitäts-Hilfsfunktionen in `utils.js`

**Files:**
- Modify: `public/js/modules/utils.js` (Ende der Datei)

- [ ] **Schritt 1: Funktionen am Ende von `utils.js` hinzufügen**

Direkt vor der letzten Leerzeile der Datei einfügen:

```js
/**
 * Gibt alle Termine zurück, die mit dem Mitglied gruppenkompatibel sind.
 * Terminart ohne Gruppen gilt als universell kompatibel.
 */
export function getCompatibleAppointments(member, appointments, types) {
    return appointments.filter(apt => {
        const type = types.find(t => t.type_id == apt.type_id);
        if (!type || !type.groups || type.groups.length === 0) return true;
        const typeGroupIds = type.groups.map(g => g.group_id);
        return member.group_ids_array.some(gid => typeGroupIds.includes(gid));
    });
}

/**
 * Gibt alle Mitglieder zurück, die mit dem Termin gruppenkompatibel sind.
 * Terminart ohne Gruppen → alle Mitglieder kompatibel.
 */
export function getCompatibleMembers(appointment, members, types) {
    const type = types.find(t => t.type_id == appointment.type_id);
    if (!type || !type.groups || type.groups.length === 0) return members;
    const typeGroupIds = type.groups.map(g => g.group_id);
    return members.filter(m =>
        m.group_ids_array.some(gid => typeGroupIds.includes(gid))
    );
}
```

- [ ] **Schritt 2: Commit**

```bash
git add public/js/modules/utils.js
git commit -m "feat: add getCompatibleAppointments/Members helpers to utils.js"
```

---

## Task 2: Cross-Filtering im Record-Modal (`records.js`)

**Files:**
- Modify: `public/js/modules/records.js`

### 2a – Import erweitern

- [ ] **Schritt 1: Import-Zeile anpassen**

Aktuelle Zeile (ca. Zeile 16):
```js
import { datetimeLocalToMysql, mysqlToDatetimeLocal, updateModalId, escapeHtml } from './utils.js';
```

Ersetzen durch:
```js
import { datetimeLocalToMysql, mysqlToDatetimeLocal, updateModalId, escapeHtml, getCompatibleAppointments, getCompatibleMembers } from './utils.js';
```

### 2b – Modul-State und Hilfsfunktionen

- [ ] **Schritt 2: Modul-Level-Variablen nach den bestehenden `let`-Deklarationen (ca. Zeile 39) einfügen**

Nach `let isLoadingFilters = false;` hinzufügen:

```js
// State für Cross-Filtering im Record-Modal
let _recordAllMembers = [];
let _recordAllAppointments = [];
let _recordTypes = [];
```

- [ ] **Schritt 3: Hilfsfunktionen zum Aufbau der Dropdowns vor `loadRecordDropdowns()` einfügen**

Direkt vor `export async function loadRecordDropdowns()` einfügen:

```js
function buildRecordMemberOptions(members) {
    const select = document.getElementById('record_member');
    const currentVal = select.value;
    select.innerHTML = '<option value="">Bitte wählen...</option>';
    members.forEach(member => {
        const opt = document.createElement('option');
        opt.value = member.member_id;
        opt.textContent = `${member.surname}, ${member.name}`;
        select.appendChild(opt);
    });
    if (members.some(m => m.member_id == currentVal)) select.value = currentVal;
}

function buildRecordAppointmentOptions(appointments) {
    const select = document.getElementById('record_appointment');
    const currentVal = select.value;
    select.innerHTML = '<option value="">Bitte wählen...</option>';
    appointments.forEach(appointment => {
        const date = new Date(appointment.date + 'T00:00:00');
        const formattedDate = date.toLocaleDateString('de-DE');
        const startTime = appointment.start_time ? appointment.start_time.substring(0, 5) : '';
        let displayText = `${appointment.title} (${formattedDate} - ${startTime})`;
        if (appointment.type_name) displayText += ` [${appointment.type_name}]`;
        const option = document.createElement('option');
        option.value = appointment.appointment_id;
        option.textContent = displayText;
        if (appointment.color) {
            option.style.color = appointment.color;
            option.style.fontWeight = '500';
        }
        option.dataset.typeId = appointment.type_id || '';
        option.dataset.typeName = appointment.type_name || '';
        select.appendChild(option);
    });
    if (appointments.some(a => a.appointment_id == currentVal)) select.value = currentVal;
}

function onRecordMemberChange() {
    const memberSelect = document.getElementById('record_member');
    const selectedMember = _recordAllMembers.find(m => m.member_id == memberSelect.value);
    if (!selectedMember) {
        buildRecordAppointmentOptions(_recordAllAppointments);
        return;
    }
    const compatible = getCompatibleAppointments(selectedMember, _recordAllAppointments, _recordTypes);
    buildRecordAppointmentOptions(compatible);
}

function onRecordAppointmentChange() {
    updateArrivalTimeFromAppointment();
    const appointmentSelect = document.getElementById('record_appointment');
    const selectedAppointment = _recordAllAppointments.find(a => a.appointment_id == appointmentSelect.value);
    if (!selectedAppointment) {
        buildRecordMemberOptions(_recordAllMembers);
        return;
    }
    const compatible = getCompatibleMembers(selectedAppointment, _recordAllMembers, _recordTypes);
    buildRecordMemberOptions(compatible);
}
```

### 2c – `loadRecordDropdowns()` refaktorieren

- [ ] **Schritt 4: `loadRecordDropdowns()` ersetzen**

Aktuelle Funktion (ca. Zeile 829–875) ersetzen durch:

```js
export async function loadRecordDropdowns() {
    _recordTypes = await loadTypes();
    const members = await loadMembers();
    _recordAllMembers = members.filter(m => m.is_active_in_period);
    _recordAllAppointments = await loadAppointments(false, currentYear);

    buildRecordMemberOptions(_recordAllMembers);
    buildRecordAppointmentOptions(_recordAllAppointments);
}
```

### 2d – Event-Listener in `openRecordModal()` anpassen

- [ ] **Schritt 5: Event-Listener-Block in `openRecordModal()` anpassen**

Aktuellen Block (ca. Zeile 791–795):
```js
    appointmentSelect.removeEventListener('change',updateArrivalTimeFromAppointment);
    appointmentSelect.addEventListener('change',updateArrivalTimeFromAppointment);
```

Ersetzen durch:
```js
    memberSelect.removeEventListener('change', onRecordMemberChange);
    appointmentSelect.removeEventListener('change', onRecordAppointmentChange);
    memberSelect.addEventListener('change', onRecordMemberChange);
    appointmentSelect.addEventListener('change', onRecordAppointmentChange);
```

- [ ] **Schritt 6: Browser-Verifikation Record-Modal**

Unter `http://localhost:8123/ehrensache/public` als Admin einloggen:
1. Anwesenheiten → „Anwesenheit erfassen" öffnen
2. Ein Mitglied aus Gruppe A wählen → Termin-Dropdown darf nur Termine aus Gruppe A zeigen
3. Termin wählen → Mitglieds-Dropdown darf nur Mitglieder aus derselben Gruppe zeigen
4. Mitglied wechseln zu Gruppe B → Terminauswahl muss zurückgesetzt werden, wenn der gewählte Termin inkompatibel ist
5. Bestehenden Record bearbeiten → beide Felder disabled, keine Filterung

- [ ] **Schritt 7: Commit**

```bash
git add public/js/modules/records.js
git commit -m "feat: add cross-filtering between member/appointment dropdowns in record modal"
```

---

## Task 3: Cross-Filtering im Exception-Modal (`exceptions.js`)

**Files:**
- Modify: `public/js/modules/exceptions.js`

### 3a – Import erweitern

- [ ] **Schritt 1: Import-Zeile anpassen**

Aktuelle Zeile (ca. Zeile 13):
```js
import {translateExceptionStatus, translateExceptionType, datetimeLocalToMysql, mysqlToDatetimeLocal, formatDateTime , updateModalId} from './utils.js';
```

Ersetzen durch:
```js
import {translateExceptionStatus, translateExceptionType, datetimeLocalToMysql, mysqlToDatetimeLocal, formatDateTime, updateModalId, getCompatibleAppointments, getCompatibleMembers} from './utils.js';
```

- [ ] **Schritt 2: `loadTypes` zum Management-Import hinzufügen**

Aktuelle Zeile (ca. Zeile 14):
```js
import { loadAppointments } from './appointments.js';
import { loadMembers } from './members.js';
```

`loadTypes` aus management.js importieren – neue Zeile nach den bestehenden Imports einfügen:
```js
import { loadTypes } from './management.js';
```

### 3b – Modul-State und Hilfsfunktionen

- [ ] **Schritt 3: Modul-Level-Variablen nach den bestehenden `let`-Deklarationen (ca. Zeile 27) einfügen**

Nach `let allFilteredExceptions = [];` hinzufügen:

```js
// State für Cross-Filtering im Exception-Modal
let _exceptionAllMembers = [];
let _exceptionAllAppointments = [];
let _exceptionTypes = [];
```

- [ ] **Schritt 4: Hilfsfunktionen direkt vor `loadExceptionModalFilters()` einfügen**

```js
function buildExceptionMemberOptions(members) {
    const select = document.getElementById('exception_member');
    const currentVal = select.value;
    select.innerHTML = '<option value="">Bitte wählen...</option>';
    members.forEach(member => {
        const opt = document.createElement('option');
        opt.value = member.member_id;
        opt.textContent = `${member.surname}, ${member.name}`;
        select.appendChild(opt);
    });
    if (members.some(m => m.member_id == currentVal)) select.value = currentVal;
}

function buildExceptionAppointmentOptions(appointments) {
    const select = document.getElementById('exception_appointment');
    const currentVal = select.value;
    select.innerHTML = '<option value="">Bitte wählen...</option>';
    appointments.forEach(appointment => {
        const date = new Date(appointment.date + 'T00:00:00');
        const formattedDate = date.toLocaleDateString('de-DE');
        const startTime = appointment.start_time ? appointment.start_time.substring(0, 5) : '';
        let displayText = `${appointment.title} (${formattedDate} ${startTime})`;
        if (appointment.type_name) displayText += ` - [${appointment.type_name}]`;
        const option = document.createElement('option');
        option.value = appointment.appointment_id;
        option.textContent = displayText;
        option.dataset.typeId = appointment.type_id || '';
        option.dataset.typeName = appointment.type_name || '';
        select.appendChild(option);
    });
    if (appointments.some(a => a.appointment_id == currentVal)) select.value = currentVal;
}

function onExceptionMemberChange() {
    const memberSelect = document.getElementById('exception_member');
    const selectedMember = _exceptionAllMembers.find(m => m.member_id == memberSelect.value);
    if (!selectedMember) {
        buildExceptionAppointmentOptions(_exceptionAllAppointments);
        return;
    }
    const compatible = getCompatibleAppointments(selectedMember, _exceptionAllAppointments, _exceptionTypes);
    buildExceptionAppointmentOptions(compatible);
}

function onExceptionAppointmentChange() {
    const appointmentSelect = document.getElementById('exception_appointment');
    const selectedAppointment = _exceptionAllAppointments.find(a => a.appointment_id == appointmentSelect.value);
    if (!selectedAppointment) {
        buildExceptionMemberOptions(_exceptionAllMembers);
        return;
    }
    const compatible = getCompatibleMembers(selectedAppointment, _exceptionAllMembers, _exceptionTypes);
    buildExceptionMemberOptions(compatible);
}
```

### 3c – `loadExceptionModalFilters()` refaktorieren

- [ ] **Schritt 5: `loadExceptionModalFilters()` ersetzen**

Aktuelle Funktion (ca. Zeile 406–453) ersetzen durch:

```js
export async function loadExceptionModalFilters(forceReload = false) {
    _exceptionTypes = await loadTypes();
    _exceptionAllAppointments = await loadAppointments(forceReload);

    buildExceptionAppointmentOptions(_exceptionAllAppointments);

    if (isAdminOrManager) {
        const members = await loadMembers(forceReload);
        _exceptionAllMembers = members.filter(m => m.is_active_in_period);
        buildExceptionMemberOptions(_exceptionAllMembers);

        const memberSelect = document.getElementById('exception_member');
        const appointmentSelect = document.getElementById('exception_appointment');
        memberSelect.removeEventListener('change', onExceptionMemberChange);
        appointmentSelect.removeEventListener('change', onExceptionAppointmentChange);
        memberSelect.addEventListener('change', onExceptionMemberChange);
        appointmentSelect.addEventListener('change', onExceptionAppointmentChange);
    }
}
```

- [ ] **Schritt 6: Browser-Verifikation Exception-Modal**

Unter `http://localhost:8123/ehrensache/public` als Admin:
1. Anträge → „Neuer Antrag" öffnen
2. Mitglied aus Gruppe A wählen → Termin-Dropdown darf nur gruppenkompatible Termine zeigen
3. Termin wählen → Mitglieds-Dropdown filtert entsprechend
4. Mitglied wechseln zu inkompatiblem → Terminauswahl wird zurückgesetzt
5. Bestehenden Antrag öffnen → keine Filterung (Felder disabled)

- [ ] **Schritt 7: Commit**

```bash
git add public/js/modules/exceptions.js
git commit -m "feat: add cross-filtering between member/appointment dropdowns in exception modal"
```

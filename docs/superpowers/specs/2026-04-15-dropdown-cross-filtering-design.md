# Design: Gegenseitige Dropdown-Filterung (Records & Exceptions)

**Datum:** 2026-04-15  
**Status:** Approved

## Problem

Im Record-Modal und Exception-Modal sind die Dropdowns für Mitglied und Termin nicht gegenseitig gefiltert. Dadurch können ungültige Einträge entstehen: ein Mitglied aus Gruppe A wird mit einem Termin verknüpft, der Gruppe B gehört.

## Datenmodell

Die Kompatibilität zwischen Mitglied und Termin ergibt sich über:

```
member.group_ids_array ∩ appointment_type.groups[].group_id ≠ ∅
```

Alle benötigten Daten liegen bereits im Frontend-Cache:

| Cache-Schlüssel | Relevante Felder |
|-----------------|-----------------|
| `dataCache.members[year].data` | `member_id`, `group_ids_array` |
| `dataCache.appointments[year].data` | `appointment_id`, `type_id` |
| `dataCache.types.data` | `type_id`, `groups: [{group_id}]` |

**Fallback:** Hat eine Terminart keine Gruppen (per Constraints nicht vorgesehen), gilt der Termin als universell kompatibel und wird für alle Mitglieder angezeigt.

## Ansatz: JS-Array-basierter Neuaufbau (Frontend-only)

Keine Backend-Änderungen. Filterung ausschließlich auf Basis gecachter Daten. Bei jeder Dropdown-Auswahl wird das jeweils andere Dropdown aus einem lokalen Array neu aufgebaut.

## Interaktionsfluss

### Neu-Anlage (Modal ohne Vorauswahl)
1. Beide Dropdowns vollständig befüllen (alle aktiven Mitglieder, alle Termine)
2. Event-Listener auf beide Dropdowns registrieren

**Mitglied wird gewählt:**
1. Kompatible Termine berechnen
2. Appointment-Dropdown neu aufbauen (nur kompatible Einträge)
3. Falls gewählter Termin nicht mehr kompatibel → Auswahl zurücksetzen (`value = ''`)

**Termin wird gewählt:**
1. Kompatible Mitglieder berechnen
2. Member-Dropdown neu aufbauen (nur kompatible Einträge)
3. Falls gewähltes Mitglied nicht mehr kompatibel → Auswahl zurücksetzen

### Bearbeiten (bestehender Eintrag)
Member und Appointment sind `disabled` – keine Filterung, bestehende Logik bleibt unverändert.

## Änderungen

### `public/js/modules/utils.js` — 2 neue Hilfsfunktionen

```js
/**
 * Gibt alle Termine zurück, die mit dem Mitglied kompatibel sind.
 * Kompatibel = mindestens eine gemeinsame Gruppe.
 * Terminart ohne Gruppen gilt als universell.
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
 * Gibt alle Mitglieder zurück, die mit dem Termin kompatibel sind.
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

### `public/js/modules/records.js` — `loadRecordDropdowns()`

- Lokale Arrays `allMembers` und `allAppointments` speichern
- Hilfsfunktionen `buildMemberOptions(members)` und `buildAppointmentOptions(appointments)` extrahieren (bereits fast vorhanden)
- Nach initialem Befüllen: Event-Listener auf `record_member` und `record_appointment`
- `loadTypes()` ist bereits aufgerufen — `dataCache.types.data` steht bereit

### `public/js/modules/exceptions.js` — `loadExceptionModalFilters()`

- `loadTypes()` hinzufügen (bisher nicht aufgerufen)
- Gleiche Logik wie in records.js: lokale Arrays, Event-Listener, Neuaufbau bei Auswahl
- Gilt nur im Admin/Manager-Pfad (User setzt eigene Member-ID automatisch)

## Nicht geändert

- Backend / PHP-Handler
- API-Struktur
- Bestehende Filter- und Render-Funktionen der Tabellen
- Verhalten beim Bearbeiten bestehender Einträge

# Design: Rollenbasierte Filtereinschränkungen für User-Rolle

**Datum:** 2026-04-15  
**Status:** Approved  
**Betrifft:** `members.php`, `members.js`, `statistics.js`, `records.js`

---

## Problem

Normale Benutzer (Rolle `user`) sehen im Frontend Filter, die nicht auf ihre erlaubten Daten eingeschränkt sind:

1. **Statistik – Gruppenauswahl** zeigt alle Gruppen, nicht nur die eigenen
2. **Anwesenheit – Terminart-Filter** zeigt alle Terminarten, nicht nur die für die eigenen Gruppen erlaubten
3. **Anwesenheit – Termin-Filter** ist für normale Benutzer unzulässig und soll ausgeblendet werden

---

## Ursache

Das PHP-Backend gibt beim Abruf des eigenen Members (non-Admin-Pfad) keine `group_ids` zurück. Ohne diese Information kann das Frontend keine gruppenbasierte Einschränkung vornehmen.

---

## Lösung: Ansatz A — Zentraler `getUserGroupIds()`-Helper

### 1. PHP API — `private/handlers/members.php`

**Non-Admin-Pfad für `GET members?id=...`** (Einzelabruf des eigenen Members):

Die SQL-Query wird um einen `LEFT JOIN` auf `member_group_assignments` und `GROUP_CONCAT` erweitert, sodass `group_ids` (kommaseparierter String der Gruppen-IDs) im Response enthalten ist — analog zur Admin-Abfrage.

**Vorher:** Response enthält nur `name`, `surname`, `member_number`  
**Nachher:** Response enthält zusätzlich `group_ids` (z.B. `"1, 3"`)

### 2. Frontend Helper — `public/js/modules/members.js`

**Änderung 1 — `loadMembers()`:**  
Im non-Admin-Zweig nach dem API-Call: `group_ids_array` aus dem `group_ids`-String berechnen (analog zum Admin-Zweig).

```js
// Non-Admin-Zweig, nach API-Call:
if (member.group_ids && typeof member.group_ids === 'string') {
    member.group_ids_array = member.group_ids
        .split(',')
        .map(id => parseInt(id.trim()));
} else {
    member.group_ids_array = [];
}
```

**Änderung 2 — neue exportierte Funktion `getUserGroupIds()`:**

```js
export async function getUserGroupIds() {
    if (isAdminOrManager) return null; // null = keine Einschränkung
    const members = await loadMembers();
    if (!members || members.length === 0) return [];
    return members[0].group_ids_array || [];
}
```

- Gibt `null` zurück für Admin/Manager → keine Filtereinschränkung
- Gibt `[]` zurück wenn kein Member verknüpft → alle Filter leer
- Gibt `[1, 3, ...]` zurück für normale User

### 3. Statistik-Gruppenfilter — `public/js/modules/statistics.js`

In `loadStatisticsFilters()`, nach dem Befüllen des `statGroup`-Dropdowns:

- `getUserGroupIds()` aufrufen
- Für non-Admin-User: alle `<option>`-Elemente entfernen, deren `value` nicht in `userGroupIds` enthalten ist
- Hat der User genau eine Gruppe: diese automatisch vorauswählen
- Option „Alle Gruppen" (`value=""`) bleibt immer erhalten

### 4. Anwesenheits-Filter — `public/js/modules/records.js`

In `loadRecordFilters()`:

**A) Terminart-Filter (`filterAptType`):**  
Für non-Admin-User: beim Befüllen des Dropdowns nur Typen hinzufügen, bei denen gilt:
- `type.groups.length > 0` (Typen ohne Gruppenverknüpfung werden ausgeblendet)
- `type.groups.some(g => userGroupIds.includes(g.group_id))` (mindestens eine Gruppe stimmt überein)

Die `type.groups[]`-Daten sind bereits im Cache von `loadTypes()` enthalten — kein extra API-Call nötig.

**B) Termin-Filter (`filterAppointment`):**  
Das übergeordnete `form-group`-Element des `filterAppointment`-Dropdowns wird für non-Admin-User mit `style.display = 'none'` ausgeblendet. Element und Event-Listener bleiben im DOM.

**C) Member-Filter (`filterMember`):**  
Keine Änderung nötig. `loadMembers()` gibt für non-Admin-User ohnehin nur den eigenen Member zurück.

---

## Betroffene Dateien

| Datei | Änderung |
|-------|----------|
| `private/handlers/members.php` | `group_ids` in non-Admin-Einzelabruf ergänzen |
| `public/js/modules/members.js` | `group_ids_array` für non-Admin + `getUserGroupIds()` |
| `public/js/modules/statistics.js` | Gruppenfilter auf User-Gruppen einschränken |
| `public/js/modules/records.js` | Terminart-Filter filtern, Termin-Filter ausblenden |

---

## Nicht im Scope

- Backend-seitige Absicherung der Statistik-API (der Backend-Filter nach `group_id` bleibt unverändert)
- Ausnahmen-Modul (`exceptions.js`) — nicht betroffen
- Änderungen an der Datenbankstruktur

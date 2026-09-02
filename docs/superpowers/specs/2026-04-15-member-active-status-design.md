# Member Aktiv-Status — Design Spec

**Datum:** 2026-04-15  
**Status:** Approved  
**Ansatz:** Hybrid (Frontend-Cache-Filter + Backend-Statistik-Fix)

---

## Kontext

Mitglieder haben einen jahresabhängigen Aktiv-Status, der sich aus `membership_dates` ergibt. Bisher wird dieser Status in der Statistik ignoriert (nur `m.active = 1` geprüft) und im Frontend nur als Status-Text angezeigt. Inaktive Mitglieder tauchen in Dropdowns und Ansichten auf, wo sie nicht erscheinen sollen.

---

## Datenstatus-Logik

### Kanonische Regel für `is_active_in_period`

| Bedingung | Ergebnis |
|-----------|----------|
| `members.active = 0` | Immer inaktiv |
| `members.active = 1`, keine `membership_dates` | Immer aktiv (für jedes Jahr) |
| `members.active = 1`, `membership_dates` vorhanden | Aktiv nur in Perioden mit `status = 'active'`, die das gewählte Jahr überschneiden |

Die Berechnung erfolgt bereits im Backend via `getMemberActivityWhere($year)` in `private/helpers/member_activity.php`. Das Ergebnis `is_active_in_period` wird pro Mitglied + Jahr im API-Response zurückgegeben.

### `is_active`-Feld im Member-Modal

- **Read-only** — kein editierbares Input-Feld
- Darstellung als Badge/Label: "Aktiv in [Jahr]" oder "Inaktiv in [Jahr]"
- Verwaltung ausschließlich über Mitgliedszeiträume (membership_dates)

---

## Backend-Änderungen

### `private/handlers/statistics.php`

Alle Stellen, die Mitglieder zählen oder aggregieren, ersetzen `m.active = 1` durch `getMemberActivityWhere($year)`:

- `getActiveMemberCount()` — Gesamtzahl aktiver Mitglieder
- Gruppenstatistik — Mitglieder pro Gruppe
- Anwesenheitsquoten-Berechnung — Basis für Prozentrechnung

`member_activity.php` wird via `require_once` eingebunden (analog zur bestehenden Nutzung in `members.php`).

**Keine Änderungen** an `records.php`, `exceptions.php`, `members.php` auf Handler-Ebene — Filterung dort läuft über den Frontend-Cache.

---

## Frontend-Änderungen

### `public/js/modules/members.js`

**Toggle-Button (nur Admin/Manager):**
- Label: "Aktive Mitglieder" / "Alle Mitglieder"
- Lokaler Modul-State — kein API-Call, kein Persist
- Default: nur aktive Mitglieder (`is_active_in_period === true`)
- Cache wird weiterhin mit `include_inactive=true` für Admins/Manager geladen

**Hervorhebung inaktiver Zeilen:**
- CSS-Klasse `row-inactive` auf `<tr>` von Mitgliedern mit `is_active_in_period === false`
- Stil: reduzierte Opacity + leicht grauer Hintergrund (Theme-konform, via `main.css`)

**Member-Modal:**
- `is_active`-Feld: read-only Badge statt `<input>` (siehe Datenstatus-Logik)

### `public/js/modules/records.js`

- Member-Dropdown beim Anlegen eines Records: `members.filter(m => m.is_active_in_period)`
- Anwesenheits-Ansicht (Tabellendarstellung): Zeilen inaktiver Mitglieder werden ausgeblendet

### `public/js/modules/exceptions.js`

- Member-Dropdown beim Anlegen einer Ausnahme: `members.filter(m => m.is_active_in_period)`

### `public/js/modules/statistics.js`

- Zeile ~116: `allMembers.filter(m => m.active)` → `allMembers.filter(m => m.is_active_in_period)`
- Member-Auswahl-Dropdowns in der Statistik: nur aktive Mitglieder anzeigen

---

## Abgrenzungen

| Bereich | Verhalten |
|---------|-----------|
| Member-Liste (Admin/Manager) | Toggle: aktiv only ↔ alle (inkl. inaktiv, farblich markiert) |
| Records-Dropdown | Immer nur aktive Mitglieder (für gewähltes Jahr) |
| Exceptions-Dropdown | Immer nur aktive Mitglieder (für gewähltes Jahr) |
| Anwesenheits-Ansicht | Nur aktive Mitglieder |
| Statistik (Frontend) | Nur aktive Mitglieder (`is_active_in_period`) |
| Statistik (Backend) | Nur Mitglieder aktiv im gewählten Jahr (`getMemberActivityWhere`) |
| User-Accounts | Unabhängig — nicht betroffen |

---

## CSS

Neue Klasse in `public/css/main.css`:

```css
tr.row-inactive {
    opacity: 0.5;
    background-color: var(--color-bg-subtle, #f5f5f5);
}
```

---

## Nicht im Scope

- Bearbeitung von `membership_dates` im Frontend (separates Feature)
- Automatische Berechnung von `is_active` ohne explizite `membership_dates`-Eingabe
- User-Account-Aktivierung (separates Konzept)

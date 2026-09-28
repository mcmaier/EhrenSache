# „Keine Antwort“ ohne Zugang kenntlich machen — Design

**Vorgang:** OI-109 · entworfen am 2026-09-28 · Grundlage ist `dev` nach dem Merge von OI-101 bis
OI-103 (`9f5a525`)

## Ziel

Im Rückmeldungs-Dialog zählt „Keine Antwort“ heute zwei verschiedene Fälle zusammen: Mitglieder,
die antworten könnten, und Mitglieder, die mangels Benutzerkonto gar nicht selbst antworten können.
Für die zweite Gruppe hilft kein Nachhaken, nur ein Eintrag durch die Verwaltung.

Künftig sieht der Verwalter im Dialog und im Druckbericht, welche offenen Rückmeldungen von
Mitgliedern ohne Zugang stammen, und wie viele es sind.

## Entscheidungen des Nutzers (2026-09-28)

| Frage | Entscheidung |
|---|---|
| Umfang | **Dialog und Druckbericht.** Nicht die Ampel-Chips der Terminliste, nicht die Check-in-App |
| Kennzeichen | Nur an **offenen** Zeilen, keine eigene Filterstufe |
| Zählung | Filterknopf „Keine Antwort (12, davon 8 ohne Zugang)“, Zusatz nur bei mehr als 0 |

## Begriff „Zugang“

Ein Mitglied hat Zugang, wenn es einen Benutzer gibt mit

- `users.member_id` = dieses Mitglied,
- `is_active = 1`,
- `account_status = 'active'`,
- `role <> 'device'`.

Die Regel deckt ohne Sonderfall ab:

- **Eingeladen, nicht aktiviert:** Ein Konto im Zustand `pending` trägt das Mitglied nur in
  `pending_member_id`. `member_id` wird erst bei der Aktivierung gesetzt (`users.php`, Aktivierung).
  Solche Mitglieder gelten als ohne Zugang, das ist richtig, denn anmelden können sie sich noch nicht.
- **Gesperrt oder deaktiviert:** `suspended` oder `is_active = 0` heißt ohne Zugang, denn `login()`
  in `auth.php` lehnt beide ab.
- **Mehrere Benutzer je Mitglied:** Das verhindert `usersCheckMemberLink()`. Die Abfrage prüft
  trotzdem per `EXISTS` bzw. `DISTINCT`, sie verlässt sich nicht darauf.

Der Feldname ist `has_access`, nicht `has_account`: Ein eingeladenes oder gesperrtes Konto ist ein
Konto, aber kein Zugang.

## Backend

**`private/helpers/responses.php`:** neue Funktion

```php
/**
 * Mitglieder aus $memberIds, die sich selbst anmelden und antworten koennen (OI-109).
 * @param array<int, int> $memberIds
 * @return array<int, true> member_id => true
 */
function responsesFetchMemberIdsWithAccess($db, $database, array $memberIds): array
```

Eine Abfrage mit `IN (…)` über die übergebenen IDs, leere Liste ergibt ein leeres Ergebnis ohne
Abfrage. Rückgabe als Nachschlagetabelle, weil der Aufrufer je Mitglied fragt.

**`private/handlers/appointment_responses.php`, `responsesPayload()`:** nur im Verwalterzweig
(`$isManager`):

- jede Zeile in `members[]` bekommt `has_access: bool`;
- `summary` bekommt `open_without_access: int`: erwartete Mitglieder ohne Rückmeldung und ohne
  Zugang. Zählt dieselben Mitglieder wie `summary.open`, also nach `responsesDedupeExpected()`.

`responseSummary()` bleibt unverändert. Sie dient auch der Terminliste, die das Feld nicht bekommt.

**Mitgliedssicht:** unverändert. Weder `has_access` noch `open_without_access` erscheinen dort,
auch nicht bei `names_visible`. Sonst sähe jedes Mitglied, wer ein Konto hat. Die Check-in-App und
`upcoming=1` holen immer die Mitgliedssicht (`responsesGetUpcoming()` ruft `responsesPayload()` mit
`$isManager = false`) und bleiben deshalb ebenfalls unberührt.

**Druckbericht (`responsesRenderPrint()`):**

- Hinweiszeile: `… · keine Antwort 12 (davon 8 ohne Zugang)`, der Zusatz nur bei mehr als 0;
- Spalte „Rückmeldung“ bei offenen Zeilen ohne Zugang: `keine Antwort (kein Zugang)`.

## Dashboard (`public/js/modules/responses.js`)

- **`managerMemberRowHtml()`:** Bei `m.status === null && m.has_access === false` folgt auf den
  Badge „keine Antwort“ ein `<span class="response-no-access" title="…">kein Zugang</span>`.
  Tooltip: „Kein aktives Benutzerkonto – Rückmeldung nur durch die Verwaltung“. Geprüft wird auf
  `=== false`, nicht auf Falschheit: Fehlt das Feld (älterer Server), erscheint nichts.
- **`managerTableHtml()`:** Der Filterknopf lautet `Keine Antwort (12, davon 8 ohne Zugang)`, der
  Zusatz nur, wenn die Zahl größer als 0 ist. Gezählt wird clientseitig aus `data.members` wie
  schon `openCount`, damit Knopf und Tabelle aus derselben Quelle stammen.
- **CSS:** `.response-no-access` in `css/components/badges.css` neben `.response-late`, als
  gedämpfter Text in einer Farbe aus `variables.css`, ohne eigenen Badge-Rahmen.

Die Namensliste für Mitglieder (`namesListHtml()`) und die Vergleichskacheln nach Beginn
(`comparisonHtml()`) bleiben unverändert.

## Tests

**`tests/suites/responses_api.php`**, eine Welt mit Termin in der Zukunft:

1. Das Weltmitglied ohne Benutzer ergibt beim Verwalter `has_access === false` und
   `summary.open_without_access === 1`.
2. Ein per `POST users` mit `member_id` angelegter Benutzer ergibt `has_access === true` und
   `open_without_access === 0`.
3. Derselbe Benutzer per `PUT` auf `account_status = 'suspended'` ergibt `false`. Mit `is_active = 0`
   ebenfalls `false`.
4. Mitgliedssicht (Konto `user`, dessen Mitglied für die Dauer des Tests in der Gruppe der Welt
   steht, Terminart mit `responses_names_visible = 1`): Weder `members[*].has_access` noch
   `summary.open_without_access` sind vorhanden.
5. Hat das Mitglied eine Rückmeldung, zählt es nicht mehr in `open_without_access`, auch ohne
   Zugang.

Der angelegte Benutzer wird im `finally` gelöscht, vor dem Abbau der Welt.

**`tests/suites/responses_frontend.php`**, statisch wie die übrigen Fälle der Suite:
`managerMemberRowHtml()` prüft `has_access === false`, und der Filterknopf trägt den Zusatz „ohne
Zugang“.

## Dokumentation

- `API.md`, Abschnitt Terminrückmeldungen: `has_access` in `members[]` und
  `summary.open_without_access`, beide nur für Admin und Manager.
- `CHANGELOG.md` unter `[Unreleased]`.
- `docs/OPEN-ITEMS.md`: OI-109 auf erledigt setzen. Dabei die Aussage berichtigen, die
  Check-in-App habe eine Rückmeldeübersicht für Verwalter: Sie holt nur die Mitgliedssicht.
- `docs/testplan.md`: ein Fall im Abschnitt Terminrückmeldung.

Keine Schemaänderung, keine Migration, kein Versionssprung (Regel für parallele Sitzungen in
`CLAUDE.md`).

## Nicht Teil dieses Vorhabens

- Ampel-Chips und Tooltip der Terminliste (`responsesAttachSummaries()`)
- Kennzeichnung gesetzter Rückmeldungen, die die Verwaltung eingetragen hat: das ist die Spur aus
  OI-63
- Einladung oder Kontoanlage aus dem Dialog heraus

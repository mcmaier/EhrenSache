<?php
/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

/**
 * "Schlüssel-Wächter" (OI-66, entschieden: ja). Hält API.md gegen die echten
 * Antworten der Testinstanz. Für jeden gelisteten Lesepfad wird das
 * dokumentierte JSON-Beispiel aus API.md geparst und mit der echten Antwort
 * verglichen -- jeder Schlüssel, den die Doku zeigt, muss in der echten
 * Antwort stehen. Die Richtung ist wichtig: Die Doku darf nichts versprechen,
 * was der Server nicht liefert. Zusätzliche Felder in der echten Antwort sind
 * erlaubt (die Doku darf kürzen) und werden nur gezählt, nie als Fehler
 * gewertet -- sichtbar als "INFO"-Zeile im Testlauf.
 *
 * Entwurf:
 * - adEndpoints() ist eine von Hand gepflegte Tabelle, kein Parser, der
 *   API.md selbst nach Endpunkten durchsucht. Ein solcher Parser würde bei
 *   jeder Abweichung von seinen Annahmen leise nichts prüfen statt laut zu
 *   scheitern -- 20 explizite Zeilen sind ehrlicher als ein cleverer Parser
 *   mit blinden Flecken.
 * - adSection()/adJsonBlock() finden den $block-ten ```json-Block unter einer
 *   Überschrift. Mehrere Beispiele stehen manchmal unter derselben
 *   Überschrift (z. B. "Alle Mitglieder abrufen": Admin/Manager-, Geräte-,
 *   User- und Einzelantwort nacheinander) -- der Block-Index wählt aus.
 * - adCheckKeys() vergleicht rekursiv: Objekte über ihre Schlüssel, Arrays
 *   über die Schlüssel ihres ersten Elements (die Tabelle beschreibt die
 *   Form, nicht jeden Datensatz).
 *
 * Bewusst NICHT geprüft (kein vollständiges Antwortbeispiel, oder die
 * Antwort hängt von Zustand ab, den ein GET-only-Test nicht herstellen
 * kann -- siehe Auftrag: nur lesen, nichts anlegen/ändern/löschen):
 * - ping: nur der Erfolgsfall, nicht der Fehler-Status "not_installed" --
 *   der lässt sich ohne Deinstallation nicht erzeugen.
 * - appointments?locations=1: eigenes, kleineres Antwortformat (Array aus
 *   Orten), nicht die Terminliste selbst -- der erste json-Block unter
 *   "Alle Termine abrufen" gehört dazu, deshalb steht die Terminliste in
 *   dieser Tabelle bewusst auf Block 2.
 * - exceptions PUT (Selbstgenehmigungssperre, 403): Fehlerbeispiel.
 * - my_open_items "ohne verknüpftes Mitglied" (zweiter Block der
 *   Überschrift): Randfall, nicht die Hauptform. Wird hier ohnehin nicht
 *   getroffen, weil das Testkonto "user" ein verknüpftes Mitglied hat.
 * - export (CSV) und Logo-Upload: keine JSON-Antworten.
 * - Fehlerbehandlung, Best Practices, Beispiel-Implementierungen: keine
 *   Endpunkt-Antworten, sondern Referenzmaterial.
 * - appointment_responses, session_info, update_check, Terminserien,
 *   Auto-/TOTP-/Stations-Check-In: nicht in der Vorgabenliste dieses
 *   Vorhabens (OI-66) -- eigene Suite wäre ein Folgeschritt.
 *
 * Bewusst nur eingeschränkt geprüft:
 * - members (Liste): nur die Admin/Manager-Form. Die Antwort hat laut Doku
 *   drei Formen je nach Rolle (admin/manager, device, user); die beiden
 *   anderen sind echte Teilmengen derselben Zeile und nicht Teil der
 *   Kernliste dieses Vorhabens.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function adApiMdPath(): string
{
    return dirname(__DIR__, 2) . '/API.md';
}

/**
 * Liefert den Abschnitt unter der Überschrift $heading (## bis ####, Text
 * ohne Raute, exakte Zeile). Der Abschnitt endet vor der nächsten
 * Überschrift gleicher Ebenen (## bis ####).
 */
function adSection(string $markdown, string $heading): string
{
    $lines = explode("\n", str_replace("\r\n", "\n", $markdown));
    $start = null;
    foreach ($lines as $i => $line) {
        if (preg_match('/^#{2,4}\s+' . preg_quote($heading, '/') . '\s*$/', rtrim($line))) {
            $start = $i + 1;
            break;
        }
    }
    assertTrue($start !== null,
        "Ueberschrift '{$heading}' nicht in API.md gefunden -- Abschnitt umbenannt oder entfernt?");

    $end = count($lines);
    for ($i = $start; $i < count($lines); $i++) {
        if (preg_match('/^#{2,4}\s+\S/', $lines[$i])) {
            $end = $i;
            break;
        }
    }

    return implode("\n", array_slice($lines, $start, $end - $start));
}

/** Liefert den $blockIndex-ten (1-basiert) ```json-Block aus $section als Array. */
function adJsonBlock(string $section, int $blockIndex, string $heading): array
{
    preg_match_all('/```json\s*\n(.*?)```/s', $section, $matches);
    $blocks = $matches[1];
    assertTrue(count($blocks) >= $blockIndex,
        "Ueberschrift '{$heading}': nur " . count($blocks) . " json-Block(e) gefunden, Block {$blockIndex} erwartet");

    $raw = trim($blocks[$blockIndex - 1]);
    $decoded = json_decode($raw, true);
    if ($decoded === null && $raw !== 'null') {
        // Zeilenkommentare sind kein gueltiges JSON -- ein Versuch, bevor
        // endgueltig aufgegeben wird.
        $stripped = (string) preg_replace('#^\s*//.*$#m', '', $raw);
        $decoded  = json_decode($stripped, true);
    }
    assertTrue($decoded !== null || $raw === 'null',
        "Ueberschrift '{$heading}', Block {$blockIndex}: kein gueltiges JSON in API.md -- " . substr($raw, 0, 200));

    return (array) $decoded;
}

/** Holt Block $blockIndex unter der Ueberschrift $heading aus API.md (gecacht je Prozess). */
function adDoc(string $heading, int $blockIndex): array
{
    static $markdown = null;
    if ($markdown === null) {
        $markdown = (string) file_get_contents(adApiMdPath());
        assertTrue($markdown !== '', 'API.md konnte nicht gelesen werden: ' . adApiMdPath());
    }

    return adJsonBlock(adSection($markdown, $heading), $blockIndex, $heading);
}

/**
 * Vergleicht rekursiv die Schlüssel des dokumentierten Beispiels ($doc)
 * gegen die echte Antwort ($live). Jeder Schlüssel aus $doc muss in $live
 * vorkommen; fehlende Felder lassen den Test scheitern. Zusätzliche Felder
 * in $live sind erlaubt und werden nur als INFO-Zeile ausgegeben.
 *
 * Arrays: nur das erste Element wird verglichen (Objekte: alle Schlüssel).
 * $skipDive listet Pfade (Punktnotation, wie sie beim Abstieg entstehen,
 * z. B. "my_open_items.items" oder "holidays.holidays"), an denen
 * absichtlich NICHT weiter hinabgestiegen wird -- entweder weil die Elemente
 * je nach `kind` unterschiedliche Felder tragen (my_open_items.items), oder
 * weil die Schlüssel selbst Daten sind statt eines Feldschemas
 * (holidays.holidays, nach Kalenderdatum indiziert). Nur die Existenz des
 * Schlüssels wird dann geprüft, nicht sein Inhalt. Ist ein Array in der
 * echten Antwort leer (keine passenden Testdaten in dieser Instanz), wird
 * die Tiefenprüfung ebenfalls übersprungen und das sichtbar gemeldet -- kein
 * stiller Erfolg.
 *
 * @param string[] $skipDive
 */
function adCheckKeys($doc, $live, string $path, array $skipDive): void
{
    if (in_array($path, $skipDive, true)) {
        echo "  INFO  {$path}: Tiefenpruefung bewusst uebersprungen (siehe skipDive-Kommentar am Eintrag)\n";

        return;
    }

    if (is_array($doc) && array_is_list($doc)) {
        if ($doc === []) {
            return;
        }
        assertTrue(is_array($live) && array_is_list($live),
            "{$path}: API.md zeigt ein Array, die echte Antwort liefert keins");

        if (!is_array($doc[0])) {
            return; // Liste von Skalaren (z. B. available_years) -- keine Schluessel zu pruefen
        }
        if ($live === []) {
            echo "  INFO  {$path}: in dieser Instanz leer -- Tiefenpruefung uebersprungen\n";

            return;
        }
        adCheckKeys($doc[0], $live[0], "{$path}[0]", $skipDive);

        return;
    }

    // Ein Feld, das laut Doku ein Objekt traegt, darf im Bestand null sein --
    // etwa `responses` an einem Termin, dessen Terminart keine Rueckmeldung
    // vorsieht. Das ist ein zulaessiger Wert, keine Falschaussage; geprueft
    // wurde schon, dass der Schluessel ueberhaupt existiert.
    if ($live === null) {
        echo "  INFO  {$path}: in dieser Instanz null -- Tiefenpruefung uebersprungen\n";

        return;
    }

    assertTrue(is_array($live), "{$path}: API.md zeigt ein Objekt, die echte Antwort liefert keins");

    $missing = [];
    foreach ($doc as $key => $docValue) {
        if (!array_key_exists($key, $live)) {
            $missing[] = (string) $key;
            continue;
        }
        if (is_array($docValue)) {
            adCheckKeys($docValue, $live[$key], "{$path}.{$key}", $skipDive);
        }
    }
    assertTrue($missing === [],
        "{$path}: in API.md dokumentiert, in der echten Antwort nicht vorhanden: " . implode(', ', $missing));

    $extra = array_diff(array_keys($live), array_keys($doc));
    if ($extra !== []) {
        echo "  INFO  {$path}: " . count($extra) . ' zusaetzliche(s) Feld(er) in der echten Antwort ('
            . implode(', ', $extra) . ")\n";
    }
}

/** Echte IDs aus der Instanz, für Pfade, die eine ID brauchen (gecacht je Prozess). */
function adContext(): array
{
    static $ctx = null;
    if ($ctx !== null) {
        return $ctx;
    }

    $admin = apiToken('admin');
    $year  = (int) date('Y');

    $members = apiRequest('GET', 'members', ['token' => $admin]);
    assertStatus(200, $members, 'members fuer den Kontext');
    assertTrue($members['body'] !== [], 'Instanz hat keine Mitglieder -- Kontext kann nicht gebaut werden');

    $appointments = apiRequest('GET', 'appointments', ['token' => $admin, 'query' => ['year' => $year]]);
    assertStatus(200, $appointments, 'appointments fuer den Kontext');
    assertTrue($appointments['body'] !== [],
        "Instanz hat keine Termine im Jahr {$year} -- Kontext kann nicht gebaut werden");

    return $ctx = [
        'memberId'      => (int) $members['body'][0]['member_id'],
        'appointmentId' => (int) $appointments['body'][0]['appointment_id'],
        'year'          => $year,
    ];
}

/**
 * Die geprüften Lesepfade. Felder je Zeile:
 * - name:      Testname (erscheint im Testlauf)
 * - heading:   Überschrift in API.md (## bis ####, ohne Raute)
 * - block:     wievielter ```json-Block unter dieser Überschrift (1-basiert)
 * - resource:  API-Ressource
 * - role:      Testrolle für apiToken(), oder null für unauthentifiziert
 * - query:     Query-Parameter, entweder Array oder Closure(array $ctx): array
 * - skipDive:  Pfade (Punktnotation, ausgehend von $resource), an denen die
 *              Tiefenprüfung in einem Array bewusst ausgelassen wird
 */
function adEndpoints(): array
{
    return [
        ['name' => 'ping', 'heading' => 'System Status', 'block' => 1,
            'resource' => 'ping', 'role' => null, 'query' => []],

        ['name' => 'appearance', 'heading' => 'Appearance', 'block' => 1,
            'resource' => 'appearance', 'role' => null, 'query' => []],

        ['name' => 'me', 'heading' => 'Benutzer-Info', 'block' => 1,
            'resource' => 'me', 'role' => 'admin', 'query' => []],

        ['name' => 'version', 'heading' => 'Version', 'block' => 1,
            'resource' => 'version', 'role' => 'admin', 'query' => []],

        ['name' => 'members (Liste, Admin/Manager)', 'heading' => 'Alle Mitglieder abrufen', 'block' => 1,
            'resource' => 'members', 'role' => 'admin', 'query' => []],

        ['name' => 'members (einzeln)', 'heading' => 'Alle Mitglieder abrufen', 'block' => 4,
            'resource' => 'members', 'role' => 'admin',
            'query' => static fn (array $ctx) => ['id' => $ctx['memberId']]],

        ['name' => 'appointments (Liste)', 'heading' => 'Alle Termine abrufen', 'block' => 2,
            'resource' => 'appointments', 'role' => 'admin',
            'query' => static fn (array $ctx) => ['year' => $ctx['year']]],

        // holidays.holidays ist ein nach Kalenderdatum indiziertes Objekt --
        // die Schluessel im Beispiel ("2026-11-01" usw.) sind Daten, kein
        // stabiles Feldschema. Nur "region" und die Existenz von "holidays"
        // als Objekt werden geprueft, nicht seine Schluessel.
        ['name' => 'holidays', 'heading' => 'Feiertage abrufen', 'block' => 1,
            'resource' => 'holidays', 'role' => 'admin',
            'query' => ['from' => date('Y-m-d'), 'to' => date('Y-m-d', strtotime('+90 days'))],
            'skipDive' => ['holidays.holidays']],

        ['name' => 'records (Liste)', 'heading' => 'Anwesenheitseinträge abrufen', 'block' => 1,
            'resource' => 'records', 'role' => 'admin', 'query' => []],

        ['name' => 'exceptions', 'heading' => 'Ausnahmen abrufen', 'block' => 1,
            'resource' => 'exceptions', 'role' => 'admin', 'query' => []],

        ['name' => 'member_groups', 'heading' => 'Gruppen abrufen', 'block' => 1,
            'resource' => 'member_groups', 'role' => 'admin', 'query' => []],

        ['name' => 'appointment_types', 'heading' => 'Terminarten abrufen', 'block' => 1,
            'resource' => 'appointment_types', 'role' => 'admin', 'query' => []],

        ['name' => 'activity_types', 'heading' => 'Tätigkeitsarten abrufen', 'block' => 1,
            'resource' => 'activity_types', 'role' => 'admin', 'query' => []],

        ['name' => 'work_sessions', 'heading' => 'Sitzungen abrufen', 'block' => 1,
            'resource' => 'work_sessions', 'role' => 'admin', 'query' => []],

        ['name' => 'statistics', 'heading' => 'Statistik abrufen', 'block' => 1,
            'resource' => 'statistics', 'role' => 'admin',
            'query' => static fn (array $ctx) => ['year' => $ctx['year']]],

        ['name' => 'users', 'heading' => 'Benutzer abrufen', 'block' => 1,
            'resource' => 'users', 'role' => 'admin', 'query' => []],

        ['name' => 'my_open_items', 'heading' => 'Eigene offene Punkte abrufen', 'block' => 1,
            'resource' => 'my_open_items', 'role' => 'user', 'query' => [],
            'skipDive' => ['my_open_items.items']],

        ['name' => 'settings (scope=client)', 'heading' => 'Client-Einstellungen (seit 1.2.4)', 'block' => 1,
            'resource' => 'settings', 'role' => 'user', 'query' => ['scope' => 'client']],

        ['name' => 'attendance_list (Termin)', 'heading' => 'Anwesenheitsliste für Termin', 'block' => 1,
            'resource' => 'attendance_list', 'role' => 'admin',
            'query' => static fn (array $ctx) => ['appointment_id' => $ctx['appointmentId']]],

        ['name' => 'available_years', 'heading' => 'Jahre mit Daten abrufen', 'block' => 1,
            'resource' => 'available_years', 'role' => 'admin', 'query' => []],

        ['name' => 'membership_dates', 'heading' => 'Zeiträume abrufen', 'block' => 1,
            'resource' => 'membership_dates', 'role' => 'admin', 'query' => []],
    ];
}

foreach (adEndpoints() as $entry) {
    test("API.md-Schluessel: {$entry['name']}", function () use ($entry) {
        $ctx   = adContext();
        $query = is_callable($entry['query']) ? $entry['query']($ctx) : $entry['query'];
        $token = $entry['role'] !== null ? apiToken($entry['role']) : null;

        $res = apiRequest('GET', $entry['resource'], ['token' => $token, 'query' => $query]);
        assertStatus(200, $res, "GET {$entry['resource']} fuer den Schluessel-Abgleich");

        $doc = adDoc($entry['heading'], $entry['block']);
        adCheckKeys($doc, $res['body'], $entry['resource'], $entry['skipDive'] ?? []);
    });
}

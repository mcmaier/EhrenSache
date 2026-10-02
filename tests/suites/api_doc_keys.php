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
 * - Auto-/TOTP-/Stations-Check-In: brauchen ein Geräte-Token, das die
 *   Benutzerliste nicht herausgibt, oder sind Schreibpfade (OI-100, offen).
 *   appointment_responses, session_info, update_check und Terminserien sind
 *   seit OI-100 dabei.
 *
 * Bewusst nur eingeschränkt geprüft:
 * - members (Liste): nur die Admin/Manager-Form. Die Antwort hat laut Doku
 *   drei Formen je nach Rolle (admin/manager, device, user); die beiden
 *   anderen sind echte Teilmengen derselben Zeile und nicht Teil der
 *   Kernliste dieses Vorhabens.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';
require_once __DIR__ . '/../lib/api_doc.php';

if (!extension_loaded('curl')) {
    return;
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

    // Ein Serientermin und ein Termin mit Rueckmeldung (OI-100); fehlt einer,
    // bleibt der Wert null und der Eintrag meldet das, statt still zu bestehen.
    $seriesId = null;
    $responsesAppointmentId = null;
    foreach ($appointments['body'] as $apt) {
        if ($seriesId === null && !empty($apt['series_id'])) {
            $seriesId = (int) $apt['series_id'];
        }
        // Nur ein begonnener Termin: bei einem kuenftigen fehlen 'comparison' und
        // 'present', die API.md beschreibt -- sonst wird der Test rot, sobald der
        // erste Termin mit Rueckmeldung in der Zukunft liegt.
        $begun = isset($apt['date'], $apt['start_time'])
            && $apt['date'] . ' ' . $apt['start_time'] <= date('Y-m-d H:i:s');
        if ($responsesAppointmentId === null && $begun && !empty($apt['responses'])) {
            $responsesAppointmentId = (int) $apt['appointment_id'];
        }
    }

    return $ctx = [
        'memberId'               => (int) $members['body'][0]['member_id'],
        'appointmentId'          => (int) $appointments['body'][0]['appointment_id'],
        'seriesId'               => $seriesId,
        'responsesAppointmentId' => $responsesAppointmentId,
        'year'                   => $year,
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
 * - session:   true = mit Sitzungs-Cookie statt Token der Rolle (session_info)
 * - needs:     Kontextschluessel, der nicht null sein darf (sonst Testfehler
 *              mit Hinweis auf fehlende Testdaten)
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

        // Seit OI-100
        ['name' => 'session_info', 'heading' => 'Session-Status', 'block' => 1,
            'resource' => 'session_info', 'role' => 'admin', 'query' => [], 'session' => true],

        ['name' => 'update_check (GET)', 'heading' => 'Update-Prüfung', 'block' => 1,
            'resource' => 'update_check', 'role' => 'admin', 'query' => []],

        ['name' => 'appointment_series (einzeln)', 'heading' => 'Serie abrufen', 'block' => 1,
            'resource' => 'appointment_series', 'role' => 'admin', 'needs' => 'seriesId',
            'query' => static fn (array $ctx) => ['id' => $ctx['seriesId']]],

        ['name' => 'appointment_responses (kommende)', 'heading' => 'Kommende Termine', 'block' => 1,
            'resource' => 'appointment_responses', 'role' => 'user', 'query' => ['upcoming' => 1]],

        ['name' => 'appointment_responses (ein Termin)', 'heading' => 'Ein Termin', 'block' => 1,
            'resource' => 'appointment_responses', 'role' => 'admin', 'needs' => 'responsesAppointmentId',
            'query' => static fn (array $ctx) => ['appointment_id' => $ctx['responsesAppointmentId']]],
    ];
}

foreach (adEndpoints() as $entry) {
    test("API.md-Schluessel: {$entry['name']}", function () use ($entry) {
        $ctx   = adContext();
        if (isset($entry['needs'])) {
            assertTrue($ctx[$entry['needs']] !== null,
                "Testinstanz hat keine Daten fuer {$entry['needs']} im Jahr {$ctx['year']} -- Eintrag nicht pruefbar");
        }
        $query = is_callable($entry['query']) ? $entry['query']($ctx) : $entry['query'];

        $opts = ['query' => $query];
        if (!empty($entry['session'])) {
            $opts['cookie'] = adSessionCookie($entry['role']);
        } elseif ($entry['role'] !== null) {
            $opts['token'] = apiToken($entry['role']);
        }

        $res = apiRequest('GET', $entry['resource'], $opts);
        assertStatus(200, $res, "GET {$entry['resource']} fuer den Schluessel-Abgleich");

        $doc = adDoc($entry['heading'], $entry['block']);
        adCheckKeys($doc, $res['body'], $entry['resource'], $entry['skipDive'] ?? []);
    });
}

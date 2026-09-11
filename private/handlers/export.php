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

// ============================================
// EXPORT Handler
// ============================================

function handleExport($db, $database, $request_method, $authMemberId) {
    if ($request_method !== 'GET') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        exit();
    }

    $type = $_GET['type'] ?? 'members';

    // Rechte je Exporttyp statt pauschal: Der Stundennachweis ist der einzige
    // Typ, der sich sinnvoll auf eine Person einschraenken laesst. Alle
    // uebrigen aggregieren ueber Personen hinweg und bleiben admin/manager.
    //
    // $forceMemberId wird weiter unten an exportWorktimeMember() gereicht.
    // Bewusst als Parameter statt ueber $_GET: Eine Rechtepruefung, die
    // darauf baut, dass eine andere Funktion zufaellig aus $_GET liest, faellt
    // lautlos aus, sobald jene Funktion umgebaut wird.
    $forceMemberId = null;

    if (!isAdminOrManager()) {
        if ($type !== 'worktime_member') {
            http_response_code(403);
            echo json_encode(["message" => "Access denied"]);
            exit();
        }

        if (exportFormat() !== 'html') {
            // Kein stilles Umbiegen auf HTML: Der Aufrufer soll wissen, dass er
            // nicht bekommt, was er angefordert hat.
            //
            // Der Selbstexport der eigenen Daten laeuft ueber my_data -- aber
            // nur dessen JSON-Form enthaelt die Arbeitszeiten. exportAsCSV()
            // in my_data.php gibt Stammdaten, Gruppen, Anwesenheiten und
            // Antraege aus, keine Sitzungen (OI-50). Wer hier also auf my_data
            // verweist, verweist auf format=json, nicht auf format=csv.
            http_response_code(403);
            echo json_encode(["message" => "Nur die Druckansicht ist verfügbar (format=html)"],
                             JSON_UNESCAPED_UNICODE);
            exit();
        }

        if ($authMemberId === null) {
            // Trifft auch Geraetekonten: Sie tragen kein Mitglied.
            http_response_code(403);
            echo json_encode(["message" => "Kein Mitglied mit diesem Benutzer verknüpft"],
                             JSON_UNESCAPED_UNICODE);
            exit();
        }

        $forceMemberId = (int) $authMemberId;
    }

    switch($type) {
        case 'members':
            exportMembers($db, $database);
            break;
        case 'appointments':
            exportAppointments($db, $database);
            break;
        case 'records':
            exportRecords($db, $database);
            break;
        case 'worktime_member':
            exportWorktimeMember($db, $database, $forceMemberId);
            break;
        case 'worktime_activity':
            exportWorktimeActivity($db, $database);
            break;
        case 'worktime_appointment':
            exportWorktimeAppointment($db, $database);
            break;
        default:
            http_response_code(400);
            echo json_encode(["message" => "Invalid export type"]);
    }
}

function exportMembers($db, $database) {
    $prefix = $database->table('');

    // Hole alle Mitglieder mit Gruppenzuordnungen
    $stmt = $db->query("
        SELECT m.member_id, m.name, m.surname, m.member_number, m.active,
               GROUP_CONCAT(g.group_name SEPARATOR '|') as group_names
        FROM {$prefix}members m
        LEFT JOIN {$prefix}member_group_assignments mga ON m.member_id = mga.member_id
        LEFT JOIN {$prefix}member_groups g ON mga.group_id = g.group_id
        GROUP BY m.member_id
        ORDER BY m.surname, m.name
    ");
    
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // CSV Header setzen
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="members_export_' . date('Y-m-d') . '.csv"');
    
    // UTF-8 BOM für Excel
    echo "\xEF\xBB\xBF";
    
    // CSV Output
    $output = fopen('php://output', 'w');
    
    // Header-Zeile
    fputcsv($output, ['name', 'surname', 'member_number', 'active', 'groups'], ';');
    
    // Daten
    foreach ($members as $member) {
        fputcsv($output, [
            $member['name'],
            $member['surname'],
            $member['member_number'],
            $member['active'],
            $member['group_names'] ?? ''
        ], ';');
    }
    
    fclose($output);
    exit();
}

function exportAppointments($db, $database) {
    $year = $_GET['year'] ?? date('Y');

    $prefix = $database->table('');
    
    $stmt = $db->prepare("
        SELECT a.appointment_id, a.date, a.start_time, a.title, a.description,
               at.type_name,
               GROUP_CONCAT(DISTINCT g.group_name SEPARATOR '|') as group_names
        FROM {$prefix}appointments a
        LEFT JOIN {$prefix}appointment_types at ON a.type_id = at.type_id
        LEFT JOIN {$prefix}appointment_type_groups atg ON at.type_id = atg.type_id
        LEFT JOIN {$prefix}member_groups g ON atg.group_id = g.group_id
        WHERE YEAR(a.date) = ?
        GROUP BY a.appointment_id
        ORDER BY a.date, a.start_time
    ");
    $stmt->execute([$year]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="appointments_export_' . $year . '.csv"');
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    // Spaltennamen sind die Schnittstelle zum Import: importAppointments()
    // verlangt 'type_name'. Hier stand 'type', wodurch ein exportiertes CSV
    // beim Reimport mit "missing required columns" abgewiesen wurde, bevor
    // eine Zeile gelesen war. Reihenfolge und Zusatzspalten sind egal — der
    // Import liest ueber array_combine() nach Namen. Siehe OI-24.
    fputcsv($output, ['date', 'start_time', 'title', 'type_name', 'groups', 'description'], ';');
    
    foreach ($appointments as $apt) {
        fputcsv($output, [
            $apt['date'],
            $apt['start_time'],
            $apt['title'],
            $apt['type_name'],
            $apt['group_names'] ?? '',
            $apt['description']
        ], ';');
    }
    
    fclose($output);
    exit();
}

function exportRecords($db, $database) {
    $year = $_GET['year'] ?? date('Y');

    $prefix = $database->table('');
    
    // Startzeit und Terminart gehen mit, damit der Reimport den Termin exakt
    // trifft statt ihn ueber zeitliche Naehe zu raten. Zusammen mit dem Datum
    // sind sie der Schluessel, den die Anwendung ohnehin verwendet: Beim
    // Anlegen weist appointments.php einen Termin *dieser Art* im Toleranz-
    // fenster als Konflikt ab. Zwei Arten am selben Abend sind dagegen
    // erlaubt — und genau die konnte der Import bisher nicht unterscheiden.
    // Der Titel identifiziert nicht (zwei Proben heissen beide "Probe"), er
    // dient nur zum Anlegen. Siehe OI-24.
    $stmt = $db->prepare("
        SELECT r.record_id, r.arrival_time, r.status, r.checkin_source,
               m.name, m.surname, m.member_number,
               a.date as appointment_date, a.start_time as appointment_start_time,
               a.title as appointment_title, at.type_name as appointment_type
        FROM {$prefix}records r
        JOIN {$prefix}members m ON r.member_id = m.member_id
        JOIN {$prefix}appointments a ON r.appointment_id = a.appointment_id
        LEFT JOIN {$prefix}appointment_types at ON a.type_id = at.type_id
        WHERE YEAR(a.date) = ?
        ORDER BY a.date, r.arrival_time IS NULL, r.arrival_time
    ");
    $stmt->execute([$year]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="records_export_' . $year . '.csv"');
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    // 'arrival_date_time' statt 'arrival_time': So heisst die Pflichtspalte in
    // importRecords(), und der Name trifft es besser — die Spalte fuehrt Datum
    // UND Uhrzeit. Siehe OI-24.
    fputcsv($output, ['member_name', 'member_surname', 'member_number', 'appointment_date',
                      'appointment_start_time', 'appointment_type', 'appointment_title',
                      'arrival_date_time', 'status', 'checkin_source'], ';');

    foreach ($records as $record) {
        fputcsv($output, [
            $record['name'],
            $record['surname'],
            $record['member_number'],
            $record['appointment_date'],
            $record['appointment_start_time'],
            $record['appointment_type'],
            $record['appointment_title'],
            $record['arrival_time'],
            $record['status'],
            $record['checkin_source']
        ], ';');
    }
    
    fclose($output);
    exit();
}

/** Bezeichnung des Nachweisgrads fuer die CSV. */
function worktimeProofLabel(string $proof): string
{
    switch ($proof) {
        case 'hours': return 'stundenbelegt';
        case 'start': return 'teilbelegt';
        default:      return 'unbelegt';
    }
}

// ============================================
// ZEITRAUM UND AUSGABEFORMAT
// ============================================

/**
 * Zeitraum einer Arbeitszeitauswertung aus der Anfrage.
 *
 * Beantwortet einen unbrauchbaren Zeitraum mit 400 statt mit einem leeren
 * Bericht: Ein Nachweis ueber null Stunden, weil das Datum verdreht war,
 * faellt niemandem auf.
 *
 * @return array{from: string, to: string, label: string, slug: string}
 */
function exportPeriodOrFail(): array
{
    try {
        return worktimeResolvePeriod(
            isset($_GET['from']) ? (string) $_GET['from'] : null,
            isset($_GET['to'])   ? (string) $_GET['to']   : null,
            isset($_GET['year']) ? (int) $_GET['year']    : null
        );
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

/** Ausgabeformat: 'html' fuer die Druckansicht, sonst CSV. */
function exportFormat(): string
{
    return (($_GET['format'] ?? 'csv') === 'html') ? 'html' : 'csv';
}

/** Minuten als Stundenwert mit zwei Nachkommastellen, deutsches Komma. */
function worktimeHours(int $minutes): string
{
    return number_format($minutes / 60, 2, ',', '');
}

/**
 * Zeitspanne einer Sitzung fuer den Bericht.
 *
 * Das Ende zeigt nur die Uhrzeit, solange es auf denselben Tag faellt. Das
 * spart Breite und macht zugleich sichtbar, welche Sitzung ueber Mitternacht
 * lief -- genau die, fuer die die Zuordnungsregel in der Fussnote gilt.
 * Sekunden entfallen; sie sind fuer einen Nachweis ohne Aussage.
 *
 * Die CSV behaelt das ISO-Format: Sie wird eingelesen, nicht gelesen.
 *
 * @return array{0: string, 1: string} Beginn und Ende
 */
function worktimeReportTimes(?string $start, ?string $end): array
{
    $startTs = $start !== null ? strtotime($start) : false;
    $endTs   = $end   !== null ? strtotime($end)   : false;

    if ($startTs === false) {
        return ['', ''];
    }

    $startOut = date('d.m.Y H:i', $startTs);

    if ($endTs === false) {
        return [$startOut, ''];
    }

    $sameDay = date('Y-m-d', $startTs) === date('Y-m-d', $endTs);

    return [$startOut, $sameDay ? date('H:i', $endTs) : date('d.m.Y H:i', $endTs)];
}

/**
 * Fussnoten, die in jeden Arbeitszeitbericht gehoeren.
 *
 * Ohne die Zuordnungsregel behauptet der Bericht eine Genauigkeit, die er
 * nicht hat. Ohne die Erklaerung der Nachweisgrade ist die Spalte fuer einen
 * Foerdergeber Rauschen -- die Begriffe sind projekteigen.
 *
 * @return array<int, string>
 */
function worktimeReportNotes(): array
{
    return [
        'Sitzungen sind dem Zeitraum ihres Beginns zugeordnet. Eine Sitzung über '
            . 'Mitternacht zählt vollständig zum Tag ihres Beginns.',
        'Gezählt wird ausschließlich, was bestätigt und beendet ist.',
        'stundenbelegt: Beginn und Ende wurden an einer Station belegt, die Dauer ist '
            . 'damit nachgewiesen.',
        'teilbelegt: Nur der Beginn wurde an einer Station belegt.',
        'unbelegt: Ohne Ortsnachweis erfasst oder nachgetragen.',
    ];
}

/**
 * Stundennachweis je Person: eine Zeile pro Sitzung, mit Nachweisgrad.
 * Grundlage fuer Ehrenamtskarte und Bescheinigung.
 */
function exportWorktimeMember($db, $database, ?int $forceMemberId = null) {
    requireWorktimeEnabled($db, $database);

    $period = exportPeriodOrFail();
    // Fuer Nicht-Manager kommt die ID aus der Rechtepruefung in handleExport()
    // und ueberschreibt alles, was die Anfrage mitschickt. Der Parameter aus
    // der Anfrage wird dann ignoriert, nicht abgewiesen: Eine Fehlermeldung
    // waere ein Orakel darueber, welche IDs existieren.
    $memberId = $forceMemberId ?? ($_GET['member_id'] ?? null);
    $prefix   = $database->table('');
    $duration = worktimeDurationExpression();
    $proof    = worktimeProofExpression();

    $where  = "ws.status = 'confirmed' AND ws.end_time IS NOT NULL
               AND " . worktimePeriodCondition();
    $params = [$period['from'], $period['to']];

    // !== null statt truthy: Diese Zeile traegt seit der Oeffnung fuer die
    // Rolle user eine Rechteentscheidung mit. Waere $memberId je 0 oder "0",
    // entfiele der Filter stillschweigend und der Nachweis zeigte alle
    // Personen. member_id ist AUTO_INCREMENT ab 1, das ist also heute nicht
    // ausloesbar -- die Bedingung kostet aber nichts und schliesst es aus.
    if ($memberId !== null && $memberId !== '') {
        $where   .= " AND ws.member_id = ?";
        $params[] = $memberId;
    }

    $stmt = $db->prepare("
        SELECT m.name, m.surname, m.member_number,
               at.activity_name,
               ws.start_time, ws.end_time, ws.break_minutes,
               {$duration} AS minutes,
               {$proof}    AS proof,
               ws.start_location_name, ws.end_location_name,
               ws.note, a.title AS appointment_title
        FROM {$prefix}work_sessions ws
        LEFT JOIN {$prefix}members m         ON ws.member_id     = m.member_id
        LEFT JOIN {$prefix}activity_types at ON ws.activity_id   = at.activity_id
        LEFT JOIN {$prefix}appointments a    ON ws.appointment_id = a.appointment_id
        WHERE {$where}
        ORDER BY m.surname, m.name, ws.start_time
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Summen je Person -- das ist die Zahl, die in eine Bescheinigung geht.
    $sums = [];
    foreach ($rows as $r) {
        $key = $r['member_number'] . '|' . $r['surname'] . '|' . $r['name'];
        $sums[$key] = ($sums[$key] ?? 0) + (int) $r['minutes'];
    }

    if (exportFormat() === 'html') {
        $reportRows = [];
        foreach ($rows as $r) {
            [$startOut, $endOut] = worktimeReportTimes($r['start_time'], $r['end_time']);
            $reportRows[] = [
                trim($r['surname'] . ', ' . $r['name']),
                $r['member_number'],
                $r['activity_name'],
                $startOut,
                $endOut,
                $r['break_minutes'],
                worktimeHours((int) $r['minutes']),
                worktimeProofLabel($r['proof']),
                $r['appointment_title'] ?? '',
                $r['note'] ?? '',
            ];
        }

        $summaryRows = [];
        foreach ($sums as $key => $minutes) {
            [$number, $surname, $name] = explode('|', $key);
            $summaryRows[] = [trim($surname . ', ' . $name), $number, worktimeHours($minutes)];
        }

        renderReport($db, $database, [
            'title'    => 'Stundennachweis',
            'period'   => $period['label'],
            'sections' => [
                [
                    'heading' => null,
                    'columns' => ['Mitglied', 'Mitgliedsnr.', 'Tätigkeit', 'Beginn', 'Ende',
                                  'Pause (min)', 'Stunden', 'Nachweis', 'Termin', 'Notiz'],
                    'rows'    => $reportRows,
                    'empty'   => 'Für diesen Zeitraum sind keine bestätigten Sitzungen erfasst.',
                ],
                [
                    'heading' => 'Summen je Person',
                    'class'   => 'report-summary',
                    'columns' => ['Mitglied', 'Mitgliedsnr.', 'Stunden'],
                    'rows'    => $summaryRows,
                    'empty'   => 'Keine Summen für diesen Zeitraum.',
                ],
            ],
            'notes'    => worktimeReportNotes(),
        ]);
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stundennachweis_' . $period['slug'] . '.csv"');
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');
    fputcsv($output, ['member_name', 'member_surname', 'member_number', 'activity',
                      'start_time', 'end_time', 'break_minutes', 'minutes', 'hours',
                      'proof', 'start_location', 'end_location', 'appointment', 'note'], ';');

    foreach ($rows as $r) {
        fputcsv($output, [
            $r['name'], $r['surname'], $r['member_number'],
            $r['activity_name'],
            $r['start_time'], $r['end_time'], $r['break_minutes'],
            $r['minutes'], worktimeHours((int) $r['minutes']),
            worktimeProofLabel($r['proof']),
            $r['start_location_name'], $r['end_location_name'],
            $r['appointment_title'], $r['note'],
        ], ';');
    }

    fputcsv($output, [], ';');
    fputcsv($output, ['SUMMEN', $period['label']], ';');
    fputcsv($output, ['member_number', 'member_surname', 'member_name', 'minutes', 'hours'], ';');

    foreach ($sums as $key => $minutes) {
        [$number, $surname, $name] = explode('|', $key);
        fputcsv($output, [$number, $surname, $name, $minutes, worktimeHours($minutes)], ';');
    }

    fclose($output);
    exit();
}

/**
 * Summen je Taetigkeitsart, getrennt nach Nachweisgrad.
 * Grundlage fuer den Verwendungsnachweis gegenueber Foerdergebern: Sichtbar
 * ist, welcher Teil der Summe belegt ist, statt einer Zahl ohne Qualitaet.
 */
function exportWorktimeActivity($db, $database) {
    requireWorktimeEnabled($db, $database);

    $period = exportPeriodOrFail();
    $rows   = worktimeByActivity($db, $database, $period);

    $total = 0;
    foreach ($rows as $r) {
        $total += (int) $r['minutes'];
    }

    if (exportFormat() === 'html') {
        $reportRows = [];
        foreach ($rows as $r) {
            $reportRows[] = [
                $r['activity_name'],
                worktimeProofLabel($r['proof']),
                $r['sessions'],
                $r['members'],
                worktimeHours((int) $r['minutes']),
            ];
        }

        renderReport($db, $database, [
            'title'    => 'Arbeitszeit nach Tätigkeit',
            'period'   => $period['label'],
            'sections' => [
                [
                    'heading' => null,
                    'columns' => ['Tätigkeit', 'Nachweis', 'Sitzungen', 'Personen', 'Stunden'],
                    'rows'    => $reportRows,
                    'empty'   => 'Für diesen Zeitraum sind keine bestätigten Sitzungen erfasst.',
                ],
                [
                    'heading' => 'Gesamt',
                    'class'   => 'report-summary',
                    'columns' => ['Zeitraum', 'Stunden'],
                    'rows'    => [[$period['label'], worktimeHours($total)]],
                    'empty'   => 'Keine Summe für diesen Zeitraum.',
                ],
            ],
            'notes'    => worktimeReportNotes(),
        ]);
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="taetigkeiten_' . $period['slug'] . '.csv"');
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');
    fputcsv($output, ['activity', 'verification', 'proof', 'sessions', 'members',
                      'minutes', 'hours'], ';');

    foreach ($rows as $r) {
        fputcsv($output, [
            $r['activity_name'], $r['verification'],
            worktimeProofLabel($r['proof']),
            $r['sessions'], $r['members'],
            $r['minutes'], worktimeHours((int) $r['minutes']),
        ], ';');
    }

    fputcsv($output, [], ';');
    fputcsv($output, ['GESAMT', $period['label'], '', '', '', $total,
                      worktimeHours($total)], ';');

    fclose($output);
    exit();
}

/**
 * Summen je Termin, getrennt nach Nachweisgrad.
 * Beantwortet, was eine einzelne Veranstaltung an ehrenamtlicher Arbeit
 * gekostet hat — die Zahl, die F\u00f6rderantr\u00e4ge oft verlangen.
 */
function exportWorktimeAppointment($db, $database) {
    requireWorktimeEnabled($db, $database);

    $period = exportPeriodOrFail();
    $rows   = worktimeByAppointment($db, $database, $period);

    $total = 0;
    foreach ($rows as $r) {
        $total += (int) $r['minutes'];
    }

    if (exportFormat() === 'html') {
        $reportRows = [];
        foreach ($rows as $r) {
            $reportRows[] = [
                $r['date'] !== null ? date('d.m.Y', strtotime((string) $r['date'])) : '',
                $r['title'] ?? '(ohne Termin)',
                $r['appointment_type'] ?? '',
                worktimeProofLabel($r['proof']),
                $r['sessions'],
                $r['members'],
                worktimeHours((int) $r['minutes']),
            ];
        }

        renderReport($db, $database, [
            'title'    => 'Arbeitszeit nach Termin',
            'period'   => $period['label'],
            'sections' => [
                [
                    'heading' => null,
                    'columns' => ['Datum', 'Termin', 'Terminart', 'Nachweis', 'Sitzungen',
                                  'Personen', 'Stunden'],
                    'rows'    => $reportRows,
                    'empty'   => 'Für diesen Zeitraum sind keine bestätigten Sitzungen erfasst.',
                ],
                [
                    'heading' => 'Gesamt',
                    'class'   => 'report-summary',
                    'columns' => ['Zeitraum', 'Stunden'],
                    'rows'    => [[$period['label'], worktimeHours($total)]],
                    'empty'   => 'Keine Summe für diesen Zeitraum.',
                ],
            ],
            'notes'    => array_merge(worktimeReportNotes(), [
                'Sitzungen ohne Terminbezug erscheinen gesammelt in einer Zeile "(ohne Termin)". '
                    . 'Sie wegzulassen würde die Gesamtsumme still verfälschen.',
            ]),
        ]);
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="termine_' . $period['slug'] . '.csv"');
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');
    fputcsv($output, ['appointment_date', 'appointment', 'appointment_type', 'proof',
                      'sessions', 'members', 'minutes', 'hours'], ';');

    foreach ($rows as $r) {
        fputcsv($output, [
            $r['date'],
            $r['title'] ?? '(ohne Termin)',
            $r['appointment_type'],
            worktimeProofLabel($r['proof']),
            $r['sessions'], $r['members'],
            $r['minutes'], worktimeHours((int) $r['minutes']),
        ], ';');
    }

    fputcsv($output, [], ';');
    fputcsv($output, ['GESAMT', $period['label'], '', '', '', '', $total,
                      worktimeHours($total)], ';');

    fclose($output);
    exit();
}

?>

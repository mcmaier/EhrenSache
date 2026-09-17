<?php

// ============================================
// MY_DATA - DSGVO Datenexport für User
// ============================================

function handleMyData($db, $database, $request_method, $authUserId)
{
    if($request_method !== 'GET') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        exit();
    }    
    
    // User authentifiziert? (Session oder Token)
    if(!$authUserId) {
        http_response_code(401);
        echo json_encode(["message" => "Unauthorized"]);
        exit();
    }

    $prefix = $database->table('');
    
    // Hole member_id des Users
    $stmt = $db->prepare("SELECT member_id FROM {$prefix}users WHERE user_id = ?");
    $stmt->execute([$authUserId]);
    $member_id = $stmt->fetchColumn();
    
    if(!$member_id) {
        http_response_code(404);
        echo json_encode(["message" => "Kein Mitglied mit diesem Benutzer verknüpft"]);
        exit();
    }
    
    // Sammle alle Daten
    $data = [];
    
    // 1. User-Daten
    $stmt = $db->prepare("
        SELECT user_id, email, role, is_active, created_at, member_id
        FROM {$prefix}users 
        WHERE user_id = ?
    ");
    $stmt->execute([$authUserId]);
    $data['user'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 2. Member-Stammdaten
    $stmt = $db->prepare("
        SELECT member_id, name, surname, member_number, active, created_at,
               pin_updated_at, (pin_hash IS NOT NULL) AS has_pin
        FROM {$prefix}members
        WHERE member_id = ?
    ");
    $stmt->execute([$member_id]);
    $data['member'] = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($data['member']) {
        // memberPublicRow() liefert has_pin bereits als bool — hier ebenso,
        // damit die Selbstauskunft nicht vom PDO-Rueckgabetyp (string) abhaengt.
        $data['member']['has_pin'] = (bool) $data['member']['has_pin'];
    }

    // 3. Gruppenzugehörigkeiten
    $stmt = $db->prepare("
        SELECT mg.group_name, mg.description
        FROM {$prefix}member_group_assignments mga
        JOIN {$prefix}member_groups mg ON mga.group_id = mg.group_id
        WHERE mga.member_id = ?
    ");
    $stmt->execute([$member_id]);
    $data['groups'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Anwesenheitsdaten
    $stmt = $db->prepare("
        SELECT 
            r.record_id,
            r.arrival_time,
            r.created_at,
            r.status,
            a.title as appointment_title,
            a.date as appointment_date
        FROM {$prefix}records r
        LEFT JOIN {$prefix}appointments a ON r.appointment_id = a.appointment_id
        WHERE r.member_id = ?
        ORDER BY a.date DESC, r.arrival_time IS NULL, r.arrival_time DESC
    ");
    $stmt->execute([$member_id]);
    $data['records'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 5. Ausnahmen/Anträge
    $stmt = $db->prepare("
        SELECT 
            e.exception_id,
            e.exception_type,
            e.reason,
            e.requested_arrival_time,
            e.status,
            e.created_at,
            e.approved_at,
            a.title as appointment_title,
            a.date as appointment_date
        FROM {$prefix}exceptions e
        LEFT JOIN {$prefix}appointments a ON e.appointment_id = a.appointment_id
        WHERE e.member_id = ?
        ORDER BY e.created_at DESC
    ");
    $stmt->execute([$member_id]);
    $data['exceptions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 6. Mitgliedschaftszeiträume
    $stmt = $db->prepare("
        SELECT start_date, end_date, status
        FROM {$prefix}membership_dates
        WHERE member_id = ?
        ORDER BY start_date DESC
    ");
    $stmt->execute([$member_id]);
    $data['membership_dates'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 7. Zeiterfassung
    // Gehoert zur Auskunft nach Art. 15 DSGVO wie jede andere Verarbeitung.
    // Auch die Aenderungshistorie: sie enthaelt personenbezogene Daten und
    // ueberlebt bewusst die Loeschung einer Sitzung.
    $stmt = $db->prepare("
        SELECT
            ws.session_id,
            ws.start_time,
            ws.end_time,
            ws.break_minutes,
            ws.note,
            ws.status,
            ws.source,
            ws.start_location_name,
            ws.end_location_name,
            ws.created_at,
            ws.approved_at,
            " . worktimeProofExpression('ws') . " AS proof,
            at.activity_name,
            a.title as appointment_title,
            a.date as appointment_date
        FROM {$prefix}work_sessions ws
        LEFT JOIN {$prefix}activity_types at ON ws.activity_id = at.activity_id
        LEFT JOIN {$prefix}appointments a    ON ws.appointment_id = a.appointment_id
        WHERE ws.member_id = ?
        ORDER BY ws.start_time DESC
    ");
    $stmt->execute([$member_id]);
    $data['work_sessions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("
        SELECT l.log_id, l.session_id, l.changed_at, l.action, l.changes
        FROM {$prefix}work_session_log l
        JOIN {$prefix}work_sessions ws ON l.session_id = ws.session_id
        WHERE ws.member_id = ?
        ORDER BY l.changed_at DESC
    ");
    $stmt->execute([$member_id]);
    $data['work_session_log'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Terminrueckmeldungen (FI-1). Gespeicherte Angaben des Mitglieds wie
    // Anwesenheiten, deshalb Teil der Auskunft nach Art. 15 DSGVO.
    $stmt = $db->prepare("
        SELECT r.status, r.comment, r.status_changed_at, r.updated_at,
               a.title AS appointment_title, a.date AS appointment_date
        FROM {$prefix}appointment_responses r
        JOIN {$prefix}appointments a ON a.appointment_id = r.appointment_id
        WHERE r.member_id = ?
        ORDER BY a.date DESC
    ");
    $stmt->execute([$member_id]);
    $data['appointment_responses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Puenktlichkeit und Zuverlaessigkeit je Jahr -- abgeleitete Kennzahlen,
    // keine gespeicherten Daten. Sie gehoeren trotzdem in die Auskunft: Die
    // DSGVO nennt Zuverlaessigkeit und Verhalten bei der Begriffsbestimmung
    // des Profilings (DATENSCHUTZ.md, Abschnitt 11). Ausgeschaltet: leer.
    require_once __DIR__ . '/../helpers/punctuality.php';

    $groupStmt = $db->prepare("SELECT group_id FROM {$prefix}member_group_assignments WHERE member_id = ?");
    $groupStmt->execute([$member_id]);
    $ownGroupIds = array_map('intval', $groupStmt->fetchAll(PDO::FETCH_COLUMN));

    $data['behavior'] = punctualityByYear($db, $database, (int) $member_id, $ownGroupIds);

    // 8. Metadaten
    $data['export_info'] = [
        'export_date' => date('Y-m-d H:i:s'),
        'format' => 'JSON',
        'gdpr_compliant' => true,
        'data_subject_rights' => 'Art. 15, 20 DSGVO'
    ];
    
    // Format als Parameter (JSON oder CSV)
    $format = $_GET['format'] ?? 'json';
    
    if($format === 'csv') {
        exportAsCSV($data);
    } else {
        // JSON (Standard)
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="meine_daten_' . date('Y-m-d') . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}

function exportAsCSV($data) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="meine_daten_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // UTF-8 BOM für Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Jede Zelle laeuft ueber csvCell(): Ein Freitext, der mit '=' beginnt,
    // waere in einer Tabellenkalkulation sonst eine Formel (OI-59). Das
    // Trennzeichen bleibt das Komma -- die Selbstauskunft hat es seit jeher,
    // anders als die Exporte unter resource=export.
    $csvZeile = function (array $felder) use ($output) {
        csvRow($output, $felder, ',');
    };
    
    // Stammdaten
    $csvZeile(['[ STAMMDATEN ]']);
    $csvZeile(['Feld', 'Wert']);
    $csvZeile(['Name', $data['member']['name'] . ' ' . $data['member']['surname']]);
    $csvZeile(['Mitgliedsnummer', $data['member']['member_number'] ?? '-']);
    $csvZeile(['E-Mail', $data['user']['email']]);
    $csvZeile(['Rolle', $data['user']['role']]);
    $csvZeile(['Aktiv', $data['member']['active'] ? 'Ja' : 'Nein']);
    $csvZeile(['Stations-PIN gesetzt', $data['member']['has_pin'] ? 'Ja' : 'Nein']);
    $csvZeile(['PIN zuletzt geändert', $data['member']['pin_updated_at'] ?? '-']);
    $csvZeile([]);
    
    // Gruppen
    $csvZeile(['[ GRUPPEN ]']);
    $csvZeile(['Gruppe']);
    foreach($data['groups'] as $group) {
        $csvZeile([$group['group_name']]);
    }
    $csvZeile([]);

    // Mitgliedschaftszeitraeume. Standen frueher nur in der JSON-Form —
    // beide Formate beantworten dasselbe Auskunftsersuchen (OI-50).
    $csvZeile(['[ MITGLIEDSCHAFTSZEITRÄUME ]']);
    $csvZeile(['Beginn', 'Ende', 'Status']);
    foreach ($data['membership_dates'] as $zeitraum) {
        $csvZeile([
            $zeitraum['start_date'] ? date('d.m.Y', strtotime($zeitraum['start_date'])) : '',
            $zeitraum['end_date']   ? date('d.m.Y', strtotime($zeitraum['end_date']))   : '',
            $zeitraum['status'] ?? '',
        ]);
    }
    $csvZeile([]);
    
    // Anwesenheiten
    $csvZeile(['[ ANWESENHEITEN ]']);
    $csvZeile(['Datum', 'Ankunft', 'Termin', 'Status']);
    foreach($data['records'] as $record) {
        // Das Datum steht am Termin, nicht an der Ankunft: Seit 1.5.0 darf
        // arrival_time NULL sein, und strtotime(null) ergaebe den 01.01.1970 —
        // ein Datum, das in einer Auskunft nach Art. 15 DSGVO nichts zu suchen
        // hat. Eine fehlende Uhrzeit bleibt eine leere Zelle.
        $csvZeile([
            $record['appointment_date'] ? date('d.m.Y', strtotime($record['appointment_date'])) : '',
            $record['arrival_time'] ? date('H:i', strtotime($record['arrival_time'])) : '',
            $record['appointment_title'] ?? '-',
            $record['status'] ?? ''
        ]);
    }
    $csvZeile([]);
    
    // Ausnahmen
    $csvZeile(['[ AUSNAHMEN/ANTRÄGE ]']);
    $csvZeile(['Datum', 'Typ', 'Grund', 'Status', 'Termin']);
    foreach($data['exceptions'] as $exception) {
        $csvZeile([
            date('d.m.Y', strtotime($exception['created_at'])),
            $exception['exception_type'],
            $exception['reason'],
            $exception['status'],
            $exception['appointment_title'] ?? '-'
        ]);
    }

    $csvZeile([]);
    $csvZeile(['[ TERMINRÜCKMELDUNGEN ]']);
    $csvZeile(['Termindatum', 'Termin', 'Rückmeldung', 'Bemerkung', 'Status geändert', 'Zuletzt geändert']);
    $responseLabels = ['yes' => 'Zusage', 'no' => 'Absage', 'maybe' => 'Unsicher'];
    foreach ($data['appointment_responses'] as $response) {
        $csvZeile([
            date('d.m.Y', strtotime($response['appointment_date'])),
            $response['appointment_title'] ?? '-',
            $responseLabels[$response['status']] ?? $response['status'],
            $response['comment'] ?? '',
            date('d.m.Y H:i', strtotime($response['status_changed_at'])),
            date('d.m.Y H:i', strtotime($response['updated_at'])),
        ]);
    }

    // Arbeitszeiten. Die Aufbereitung ist dieselbe wie im Arbeitszeitbericht
    // (private/handlers/export.php) — eine Auskunft soll nicht anders rechnen
    // als der Nachweis, den der Verein in der Hand haelt.
    $csvZeile([]);
    $csvZeile(['[ ARBEITSZEITEN ]']);
    $csvZeile(['Beginn', 'Ende', 'Pause (Min)', 'Dauer (h)', 'Tätigkeit',
                      'Termin', 'Status', 'Nachweis', 'Quelle', 'Notiz']);
    foreach ($data['work_sessions'] as $session) {
        [$beginn, $ende] = worktimeReportTimes($session['start_time'], $session['end_time']);
        $minuten = sessionDurationMinutes($session);

        $csvZeile([
            $beginn,
            $ende,
            (int) ($session['break_minutes'] ?? 0),
            $minuten === null ? 'läuft' : worktimeHours($minuten),
            $session['activity_name'] ?? '-',
            $session['appointment_title'] ?? '-',
            $session['status'] ?? '',
            worktimeProofLabel((string) ($session['proof'] ?? 'none')),
            $session['source'] ?? '',
            $session['note'] ?? '',
        ]);
    }

    // Aenderungshistorie: Sie enthaelt personenbezogene Daten und ueberlebt
    // die Loeschung einer Sitzung bewusst, gehoert also in die Auskunft. Die
    // Vorher/Nachher-Werte bleiben als JSON in einer Spalte — eine Aufloesung
    // in Spalten haette fuer jede Aenderungsart ein anderes Format.
    $csvZeile([]);
    $csvZeile(['[ ÄNDERUNGSHISTORIE ARBEITSZEIT ]']);
    $csvZeile(['Zeitpunkt', 'Sitzung', 'Vorgang', 'Änderungen']);
    foreach ($data['work_session_log'] as $eintrag) {
        $csvZeile([
            $eintrag['changed_at'] ? date('d.m.Y H:i', strtotime($eintrag['changed_at'])) : '',
            $eintrag['session_id'] ?? '',
            $eintrag['action'] ?? '',
            $eintrag['changes'] ?? '',
        ]);
    }

    if ($data['behavior'] !== []) {
        $csvZeile([]);
        $csvZeile(['[ PÜNKTLICHKEIT UND ZUVERLÄSSIGKEIT ]']);
        $csvZeile(['Jahr', 'Kennzahl', 'Wert']);

        foreach ($data['behavior'] as $jahr => $blocks) {
            $p = $blocks['punctuality'];
            if (!empty($p['enabled'])) {
                $csvZeile([$jahr, 'Pünktlichkeit', $p['sufficient']
                    ? sprintf('Pünktlich bei %d von %d gemessenen Ankünften (%s %%)',
                              $p['on_time_count'], $p['measured_count'],
                              number_format((float) $p['rate'], 1, ',', ''))
                    : sprintf('Zu wenige Messungen (%d von mindestens %d)',
                              $p['measured_count'], $p['min_measurements'])]);
                $csvZeile([$jahr, 'Messabdeckung',
                    sprintf('Gemessen bei %d von %d Terminen', $p['measured_count'], $p['total_count'])]);
            }

            $r = $blocks['reliability'];
            if (!empty($r['enabled'])) {
                $csvZeile([$jahr, 'Zuverlässigkeit', $r['total'] > 0
                    ? sprintf('Erschienen oder rechtzeitig abgemeldet: %d von %d (%s %%)',
                              $r['appeared'] + $r['excused_in_time'], $r['total'],
                              number_format((float) $r['rate'], 1, ',', ''))
                    : 'Keine Termine']);
            }
        }
    }

    fclose($output);
}

?>
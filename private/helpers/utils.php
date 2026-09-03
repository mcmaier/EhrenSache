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
// HILFSFUNKTION: Genehmigte Zeitkorrektur verarbeiten
// ============================================
function handleApprovedTimeCorrection($db, $database, $exceptionId, $exceptionData) {
    // Hole Exception Details
    $prefix = $database->table('');

    $stmt = $db->prepare("SELECT member_id, appointment_id, requested_arrival_time 
                          FROM {$prefix}exceptions WHERE exception_id = ?");
    $stmt->execute([$exceptionId]);
    $exception = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$exception || !$exception['requested_arrival_time']) {
        return;
    }
    
    // Prüfe ob bereits ein Record existiert
    $checkStmt = $db->prepare("SELECT record_id FROM {$prefix}records 
                               WHERE member_id = ? AND appointment_id = ?");
    $checkStmt->execute([$exception['member_id'], $exception['appointment_id']]);
    $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if($existingRecord) {
        // Update bestehenden Record
        $updateStmt = $db->prepare("UPDATE {$prefix}records 
                                    SET arrival_time = ?, status = 'present' 
                                    WHERE record_id = ?");
        $updateStmt->execute([
            $exception['requested_arrival_time'], 
            $existingRecord['record_id']
        ]);
    } else {
        // Erstelle neuen Record
        $insertStmt = $db->prepare("INSERT INTO {$prefix}records 
                                    (member_id, appointment_id, arrival_time, status) 
                                    VALUES (?, ?, ?, 'present')");
        $insertStmt->execute([
            $exception['member_id'], 
            $exception['appointment_id'], 
            $exception['requested_arrival_time']
        ]);
    }
}

// ============================================
// HILFSFUNKTION: Genehmigte Entschuldigung verarbeiten
// ============================================

function handleApprovedAbsence($db, $database, $exceptionId, $data) {
    // Exception-Details holen
    $prefix = $database->table('');
    $stmt = $db->prepare(
        "SELECT member_id, appointment_id 
         FROM {$prefix}exceptions 
         WHERE exception_id = ?"
    );
    $stmt->execute([$exceptionId]);
    $exception = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$exception) return;
    
    // Appointment-Datum holen für arrival_time
    $stmt = $db->prepare(
        "SELECT date, start_time 
         FROM {$prefix}appointments 
         WHERE appointment_id = ?"
    );
    $stmt->execute([$exception['appointment_id']]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$appointment) return;
    
    $arrivalTime = $appointment['date'] . ' ' . $appointment['start_time'];
    
    // Record erstellen (INSERT IGNORE falls bereits vorhanden)
    $stmt = $db->prepare(
        "INSERT IGNORE INTO {$prefix}records 
         (member_id, appointment_id, arrival_time, status, checkin_source) 
         VALUES (?, ?, ?, 'excused', 'admin')"
    );
    $stmt->execute([
        $exception['member_id'],
        $exception['appointment_id'],
        $arrivalTime
    ]);
}

// ============================================
// HILFSFUNKTION: Member-ID aus Nummer ermitteln
// ============================================
function resolveMemberIdByNumber($db, $database, $memberNumber) {
    $prefix = $database->table('');
    $stmt = $db->prepare("SELECT member_id FROM {$prefix}members WHERE member_number = ?");
    $stmt->execute([$memberNumber]);
    $memberId = $stmt->fetchColumn();
    
    return $memberId ? intval($memberId) : null;
}

// ============================================
// SYSTEMEINSTELLUNGEN
// ============================================

/**
 * Liest eine Einstellung aus system_settings.
 *
 * Der Rumpf stammt aus worktimeSetting() in worktime.php — die Funktion war
 * trotz ihres Namens nie an die Zeiterfassung gebunden. Sie steht jetzt hier,
 * damit nicht zwei Lesefunktionen nebeneinander wachsen.
 */
function systemSetting($db, $database, string $key, string $default): string
{
    $prefix = $database->table('');
    $stmt   = $db->prepare("SELECT setting_value FROM {$prefix}system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return ($value === false || $value === null) ? $default : (string) $value;
}

/**
 * Zeitfenster in Stunden, in dem ein Check-in einem Termin zugeordnet wird.
 *
 * Kette: Einstellung → Konstante aus config.php → 2. Die Konstante bleibt der
 * Rückfall für Installationen, deren Migration noch nicht gelaufen ist.
 */
function checkinToleranceHours($db, $database): int
{
    $fallback = defined('AUTO_CHECKIN_TOLERANCE_HOURS')
        ? (int) AUTO_CHECKIN_TOLERANCE_HOURS
        : 2;

    $hours = (int) systemSetting($db, $database, 'checkin_tolerance_hours', (string) $fallback);

    return ($hours < 0 || $hours > 8) ? $fallback : $hours;
}

/**
 * Darf ein Check-in einen Termin anlegen, wenn keiner passt?
 *
 * Vorgabe '1', nicht '0': Fehlt die Zeile, ist die Migration nicht gelaufen —
 * dann gilt das bisherige Verhalten, statt still Check-ins abzuweisen.
 */
function checkinCreatesAppointments($db, $database): bool
{
    return systemSetting($db, $database, 'checkin_auto_create_appointment', '1') === '1';
}

?>
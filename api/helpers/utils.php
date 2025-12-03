<?php


// ============================================
// HILFSFUNKTION: Genehmigte Zeitkorrektur verarbeiten
// ============================================
function handleApprovedTimeCorrection($db, $exceptionId, $exceptionData) {
    // Hole Exception Details
    $stmt = $db->prepare("SELECT member_id, appointment_id, requested_arrival_time 
                          FROM exceptions WHERE exception_id = ?");
    $stmt->execute([$exceptionId]);
    $exception = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$exception || !$exception['requested_arrival_time']) {
        return;
    }
    
    // Prüfe ob bereits ein Record existiert
    $checkStmt = $db->prepare("SELECT record_id FROM records 
                               WHERE member_id = ? AND appointment_id = ?");
    $checkStmt->execute([$exception['member_id'], $exception['appointment_id']]);
    $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if($existingRecord) {
        // Update bestehenden Record
        $updateStmt = $db->prepare("UPDATE records 
                                    SET arrival_time = ?, status = 'present' 
                                    WHERE record_id = ?");
        $updateStmt->execute([
            $exception['requested_arrival_time'], 
            $existingRecord['record_id']
        ]);
    } else {
        // Erstelle neuen Record
        $insertStmt = $db->prepare("INSERT INTO records 
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
// HILFSFUNKTION: Member-ID aus Nummer ermitteln
// ============================================
function resolveMemberIdByNumber($db, $memberNumber) {
    $stmt = $db->prepare("SELECT member_id FROM members WHERE member_number = ?");
    $stmt->execute([$memberNumber]);
    $memberId = $stmt->fetchColumn();
    
    return $memberId ? intval($memberId) : null;
}


?>
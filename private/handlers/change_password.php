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
// CHANGE_PASSWORD Controller
// ============================================

function handlePasswordChange($db, $request_method, $authUserId)
{
    if($request_method !== 'POST') {
            http_response_code(405);
            echo json_encode(["message" => "Method not allowed"]);
            exit();
    }
    
    $data = json_decode(file_get_contents("php://input"));
    
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = ?");
    $stmt->execute([$authUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!password_verify($data->current_password, $user['password_hash'])) {
        http_response_code(403);
        echo json_encode(["message" => "Current password incorrect"]);
        exit();
    }
    
    if(strlen($data->new_password) < 6) {
        http_response_code(400);
        echo json_encode(["message" => "Password must be at least 6 characters"]);
        exit();
    }
    
    $newHash = password_hash($data->new_password, PASSWORD_DEFAULT);
    $updateStmt = $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
    
    if($updateStmt->execute([$newHash, $authUserId])) {
        echo json_encode(["message" => "Password changed successfully"]);
    } else {
        http_response_code(500);
        echo json_encode(["message" => "Failed to change password"]);
    }
}    
     
?>
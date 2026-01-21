<?php
// ============================================
// REGENERATE TOKEN Controller
// ============================================

function handleTokenRegeneration($db, $request_method, $authUserId, $authUserRole)
{
    if($request_method !== 'POST') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        exit();
    }
    
    $data = json_decode(file_get_contents("php://input"));
    $targetUserId = $data->user_id ?? $authUserId; // Standard: eigener User
    
    // Device darf keinen Token regenerieren
    if(isDevice()) {
        http_response_code(403);
        echo json_encode([
            "message" => "Devices cannot regenerate tokens"
        ]);
        exit();
    }
    
    // User darf nur eigenen Token regenerieren
    if(!isAdmin() && ($targetUserId != $authUserId)) {
        http_response_code(403);
        echo json_encode([
            "message" => "You can only regenerate your own token"
        ]);
        exit();
    }
    
    // Prüfe ob Ziel-User existiert
    $checkStmt = $db->prepare("SELECT user_id, role FROM users WHERE user_id = ?");
    $checkStmt->execute([$targetUserId]);
    $targetUser = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$targetUser) {
        http_response_code(404);
        echo json_encode(["message" => "User not found"]);
        exit();
    }

    $isDevice = ($targetUser['role'] === 'device');

    // Token generieren
    $newToken = bin2hex(random_bytes(24));
    if($isDevice)
    {
        $expiresAt = date('Y-m-d H:i:s', strtotime('+10 years'));
    }
    else
    {
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));
    }
    
    try{    
        $stmt = $db->prepare("UPDATE users SET api_token = ?, api_token_expires_at = ? WHERE user_id = ?");
        
        if($stmt->execute([$newToken, $expiresAt, $targetUserId])) {
            echo json_encode([
                "message" => "Token regenerated",
                "api_token" => $newToken,
                "expires_at" => $expiresAt,
                "user_id" => $targetUserId
            ]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Fehler beim Erneuern des Token"]);
        }
    }
    catch (Exception $e) {        
        http_response_code(500);
        echo json_encode(['message' => 'Fehler beim Erneuern des Token']);
    }
}

?>
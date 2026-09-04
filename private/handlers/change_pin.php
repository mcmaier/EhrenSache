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
// CHANGE_PIN Controller — Stations-PIN im Profil
// ============================================
// Vorbild: change_password.php. Das aktuelle Passwort bestaetigt, dass der
// Kontoinhaber selbst handelt — eine offen liegende Session soll nicht genuegen,
// um die Stempel-PIN eines anderen zu setzen.

function handlePinChange($db, $database, $request_method, $authUserId, $authMemberId)
{
    if ($request_method !== 'POST') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        return;
    }

    if (!isStationPinEnabled($db, $database)) {
        http_response_code(404);
        echo json_encode(["message" => "Endpoint not found"]);
        return;
    }

    if (isDevice()) {
        http_response_code(403);
        echo json_encode(["message" => "Devices have no PIN"]);
        return;
    }

    if (!$authMemberId) {
        http_response_code(403);
        echo json_encode(["message" => "No member linked to your account",
                          "hint"    => "Contact administrator"]);
        return;
    }

    $prefix = $database->table('');
    $data   = json_decode(file_get_contents("php://input")) ?: new stdClass();

    $stmt = $db->prepare("SELECT password_hash FROM {$prefix}users WHERE user_id = ?");
    $stmt->execute([$authUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $currentPassword = $data->current_password ?? '';
    if (!$user || !is_string($currentPassword)
        || !password_verify($currentPassword, (string) $user['password_hash'])) {
        http_response_code(403);
        echo json_encode(["message" => "Current password incorrect"]);
        return;
    }

    $pin = $data->new_pin ?? '';
    if (!is_string($pin)) {
        http_response_code(400);
        echo json_encode(["message" => "Die PIN darf nur Ziffern enthalten", "field" => "new_pin"]);
        return;
    }
    $error = validateStationPin($pin, stationPinMinLength($db, $database));
    if ($error !== null) {
        http_response_code(400);
        echo json_encode(["message" => $error, "field" => "new_pin"]);
        return;
    }

    $db->prepare("UPDATE {$prefix}members SET pin_hash = ?, pin_updated_at = NOW() WHERE member_id = ?")
       ->execute([password_hash($pin, PASSWORD_DEFAULT), $authMemberId]);

    // P2: neue PIN hebt eine Sperre auf
    (new RateLimiter($db, $database))->reset('station_member_' . (int) $authMemberId, 'station_pin');

    echo json_encode(["message" => "PIN changed successfully"]);
}

?>

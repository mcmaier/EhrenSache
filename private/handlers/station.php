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
// STATION Controller — virtuelle Station (Kiosk)
// ============================================
//
// Zwei Vertrauensmodelle, bewusst getrennt (Spec 2.1):
//   auto_checkin mit device_auth: das GERAET buergt fuer die Identitaet.
//   station:                     der SERVER prueft Mitgliedsnummer + PIN,
//                                das Geraet ist nur Tastatur und Bildschirm.
// Ein gestohlenes Kiosk-Token darf deshalb ohne PIN eines Mitglieds nichts
// bewirken; api.php laesst Kiosk-Token nur an diese Ressource.

function handleStation($db, $database, $method, $authUserId, $authUserRole, $authDeviceType)
{
    if ($authUserRole !== 'device' || $authDeviceType !== 'kiosk') {
        http_response_code(403);
        echo json_encode(["message" => "Kiosk device token required"]);
        return;
    }

    $prefix = $database->table('');

    $stmt = $db->prepare("SELECT user_id, device_name, totp_secret, is_active
                          FROM {$prefix}users WHERE user_id = ?");
    $stmt->execute([$authUserId]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device || (int) $device['is_active'] !== 1) {
        http_response_code(403);
        echo json_encode(["message" => "Device is inactive"]);
        return;
    }

    $action = $_GET['action'] ?? '';

    if ($method === 'GET') {
        switch ($action) {
            case 'status':
                stationStatus($db, $database, $device);
                return;
            case 'totp':
                stationTotp($device);
                return;
        }
    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents("php://input")) ?: new stdClass();
        // POST-Aktionen folgen in Phase 2 (identify, checkin, work_*)
    }

    http_response_code(400);
    echo json_encode([
        "message" => "Unknown action",
        "allowed" => ["GET status", "GET totp"],
    ]);
}

/** Konfiguration und Uhrzeit — beim Start des Kiosks und alle fuenf Minuten. */
function stationStatus($db, $database, array $device)
{
    echo json_encode([
        'device_name'      => $device['device_name'],
        'totp_enabled'     => !empty($device['totp_secret']),
        'pin_enabled'      => isStationPinEnabled($db, $database),
        'pin_min_length'   => stationPinMinLength($db, $database),
        'worktime_enabled' => isWorktimeEnabled($db, $database),
        'server_time'      => date('Y-m-d H:i:s'),
        'server_unix'      => time(),
    ]);
}

/**
 * Aktueller und naechster Stations-Code. Das Secret bleibt hier (E5).
 * `now` reist mit, damit der Kiosk die Restlaufzeit gegen die Serveruhr
 * rechnet und nicht gegen die eigene.
 */
function stationTotp(array $device)
{
    if (empty($device['totp_secret'])) {
        http_response_code(404);
        echo json_encode(["message" => "This station shows no TOTP code"]);
        return;
    }

    $codes        = totpCodesForSecret($device['totp_secret']);
    $codes['now'] = time();
    echo json_encode($codes);
}

?>

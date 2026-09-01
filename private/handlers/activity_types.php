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
// ACTIVITY_TYPES Controller
// ============================================
function handleActivityTypes($db, $database, $method, $id) {

    requireWorktimeEnabled($db, $database);

    $prefix = $database->table('');
    $allowedVerification = ['none', 'start', 'start_end'];

    switch($method) {
        case 'GET':
            if($id) {
                $stmt = $db->prepare("SELECT * FROM {$prefix}activity_types WHERE activity_id = ?");
                $stmt->execute([$id]);
                $type = $stmt->fetch(PDO::FETCH_ASSOC);

                if($type) {
                    echo json_encode($type);
                } else {
                    http_response_code(404);
                    echo json_encode(["message" => "Activity type not found"]);
                }
            } else {
                // Nicht-Admins sehen nur aktive Arten
                $sql = "SELECT * FROM {$prefix}activity_types";
                if(!isAdmin()) {
                    $sql .= " WHERE is_active = 1";
                }
                $sql .= " ORDER BY activity_name";

                $stmt = $db->query($sql);
                echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            break;

        case 'POST':
            requireAdmin();

            $data = json_decode(file_get_contents("php://input"));

            if(empty($data->activity_name)) {
                http_response_code(400);
                echo json_encode(["message" => "activity_name is required"]);
                return;
            }

            $verification = $data->verification ?? 'none';
            if(!in_array($verification, $allowedVerification, true)) {
                http_response_code(400);
                echo json_encode(["message" => "Invalid verification value",
                                  "allowed" => $allowedVerification]);
                return;
            }

            if(isset($data->is_default) && $data->is_default) {
                $db->exec("UPDATE {$prefix}activity_types SET is_default = 0");
            }

            $stmt = $db->prepare("INSERT INTO {$prefix}activity_types
                                  (activity_name, description, color, is_default, is_active, verification)
                                  VALUES (?, ?, ?, ?, ?, ?)");
            if($stmt->execute([
                $data->activity_name,
                $data->description ?? null,
                $data->color ?? '#1F5FBF',
                !empty($data->is_default) ? 1 : 0,
                isset($data->is_active) ? (int)(bool)$data->is_active : 1,
                $verification
            ])) {
                http_response_code(201);
                echo json_encode(["message" => "Activity type created", "id" => (int)$db->lastInsertId()]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to create activity type"]);
            }
            break;

        case 'PUT':
            requireAdmin();

            $data = json_decode(file_get_contents("php://input"));

            if(empty($data->activity_name)) {
                http_response_code(400);
                echo json_encode(["message" => "activity_name is required"]);
                return;
            }

            $verification = $data->verification ?? 'none';
            if(!in_array($verification, $allowedVerification, true)) {
                http_response_code(400);
                echo json_encode(["message" => "Invalid verification value",
                                  "allowed" => $allowedVerification]);
                return;
            }

            if(isset($data->is_default) && $data->is_default) {
                $db->prepare("UPDATE {$prefix}activity_types SET is_default = 0 WHERE activity_id != ?")
                   ->execute([$id]);
            }

            $stmt = $db->prepare("UPDATE {$prefix}activity_types
                                  SET activity_name = ?, description = ?, color = ?,
                                      is_default = ?, is_active = ?, verification = ?
                                  WHERE activity_id = ?");
            if($stmt->execute([
                $data->activity_name,
                $data->description ?? null,
                $data->color ?? '#1F5FBF',
                !empty($data->is_default) ? 1 : 0,
                isset($data->is_active) ? (int)(bool)$data->is_active : 1,
                $verification,
                $id
            ])) {
                echo json_encode(["message" => "Activity type updated"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to update activity type"]);
            }
            break;

        case 'DELETE':
            requireAdmin();

            try {
                $stmt = $db->prepare("DELETE FROM {$prefix}activity_types WHERE activity_id = ?");
                $stmt->execute([$id]);
                echo json_encode(["message" => "Activity type deleted"]);
            } catch (PDOException $e) {
                // ON DELETE RESTRICT: an der Art haengen Sitzungen.
                // Loeschen wuerde bestaetigten Nachweisstunden die Zuordnung nehmen.
                http_response_code(409);
                echo json_encode([
                    "message" => "Activity type is in use and cannot be deleted",
                    "hint"    => "Set is_active = 0 to retire it instead"
                ]);
            }
            break;
    }
}

?>

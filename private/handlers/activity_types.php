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
 * Ermittelt das Mitglied, nach dessen Gruppen gefiltert wird.
 *
 * Rolle user: immer das eigene Mitglied — der Parameter aendert daran nichts.
 * Admin/Manager: nur wenn member_id ausdruecklich mitgegeben wird; sonst
 * sehen sie alles, weil sie Nachtraege zugunsten anderer erfassen.
 *
 * @return int|null null bedeutet: nicht filtern
 */
function activityFilterMemberId($db, $database): ?int
{
    $prefix = $database->table('');

    if(isAdminOrManager()) {
        return isset($_GET['member_id']) ? (int)$_GET['member_id'] : null;
    }

    $stmt = $db->prepare("SELECT member_id FROM {$prefix}users WHERE user_id = ?");
    $stmt->execute([getCurrentUserId()]);
    $memberId = $stmt->fetchColumn();

    // Kein verknuepftes Mitglied: es gibt keine Gruppen, also nichts zu sehen.
    return $memberId ? (int)$memberId : 0;
}

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
                    // Lade zugehörige Gruppen
                    $groupStmt = $db->prepare("SELECT g.* FROM {$prefix}member_groups g
                                               INNER JOIN {$prefix}activity_type_groups atg
                                                       ON g.group_id = atg.group_id
                                               WHERE atg.activity_id = ?");
                    $groupStmt->execute([$id]);
                    $type['groups'] = $groupStmt->fetchAll(PDO::FETCH_ASSOC);

                    echo json_encode($type);
                } else {
                    http_response_code(404);
                    echo json_encode(["message" => "Activity type not found"]);
                }
            } else {
                $filterMemberId = activityFilterMemberId($db, $database);
                $params         = [];

                $sql = "SELECT a.* FROM {$prefix}activity_types a";

                if($filterMemberId !== null) {
                    // 0 = Nutzer ohne verknuepftes Mitglied → leere Liste
                    $sql .= " WHERE EXISTS (
                                SELECT 1 FROM {$prefix}activity_type_groups atg
                                INNER JOIN {$prefix}member_group_assignments mga
                                        ON mga.group_id = atg.group_id
                                WHERE atg.activity_id = a.activity_id
                                  AND mga.member_id = ?
                              )";
                    $params[] = $filterMemberId;
                } else {
                    $sql .= " WHERE 1=1";
                }

                if(!isAdmin()) {
                    $sql .= " AND a.is_active = 1";
                }

                $sql .= " ORDER BY a.activity_name";

                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $types = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Für jeden Type die Gruppen laden
                $groupStmt = $db->prepare("SELECT g.* FROM {$prefix}member_groups g
                                           INNER JOIN {$prefix}activity_type_groups atg
                                                   ON g.group_id = atg.group_id
                                           WHERE atg.activity_id = ?");

                foreach ($types as &$type) {
                    $groupStmt->execute([$type['activity_id']]);
                    $type['groups'] = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
                }
                unset($type);

                echo json_encode($types);
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
                $newId = (int)$db->lastInsertId();

                // Verknüpfe mit Gruppen
                if(!empty($data->group_ids) && is_array($data->group_ids)) {
                    $linkStmt = $db->prepare("INSERT INTO {$prefix}activity_type_groups
                                              (activity_id, group_id) VALUES (?, ?)");
                    foreach($data->group_ids as $gid) {
                        $linkStmt->execute([$newId, (int)$gid]);
                    }
                }

                http_response_code(201);
                echo json_encode(["message" => "Activity type created", "id" => $newId]);
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
                // Aktualisiere Gruppen-Verknüpfungen: fehlendes Feld lässt sie unangetastet,
                // ein leeres Array löscht sie bewusst — daher isset() statt !empty().
                if(isset($data->group_ids) && is_array($data->group_ids)) {
                    $db->prepare("DELETE FROM {$prefix}activity_type_groups WHERE activity_id = ?")
                       ->execute([$id]);

                    $linkStmt = $db->prepare("INSERT INTO {$prefix}activity_type_groups
                                              (activity_id, group_id) VALUES (?, ?)");
                    foreach($data->group_ids as $gid) {
                        $linkStmt->execute([$id, (int)$gid]);
                    }
                }

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

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
// MEMBER GROUPS Controller
// ============================================

function handleMemberGroups($db, $database, $method, $id) {

    $prefix = $database->table('');

    switch($method) {
        case 'GET':
            if($id) {
                // Einzelne Gruppe mit Members
                $stmt = $db->prepare("SELECT * FROM {$prefix}member_groups WHERE group_id = ?");
                $stmt->execute([$id]);
                $group = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if($group) {
                    // Lade zugehörige Members - nie pin_hash oder pin_updated_at
                    // selektieren (kein Konsument braucht sie hier; das
                    // Mitglieder-Modul liefert has_pin bereits über die
                    // members-Ressource selbst).
                    if(isAdminOrManager()) {
                        $memberStmt = $db->prepare("SELECT m.member_id, m.name, m.surname, m.member_number, m.active, m.created_at
                                                    FROM {$prefix}members m
                                                    JOIN {$prefix}member_group_assignments mga ON m.member_id = mga.member_id
                                                    WHERE mga.group_id = ?");
                    } else {
                        $memberStmt = $db->prepare("SELECT m.member_id, m.name, m.surname
                                                    FROM {$prefix}members m
                                                    JOIN {$prefix}member_group_assignments mga ON m.member_id = mga.member_id
                                                    WHERE mga.group_id = ?");
                    }
                    $memberStmt->execute([$id]);
                    $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
                    $group['members'] = $members;

                    echo json_encode($group);
                } else {
                    http_response_code(404);
                    echo json_encode(["message" => "Group not found"]);
                }
            } else {
                    // Liste aller Gruppen MIT Mitgliederanzahl
                    $stmt = $db->query("SELECT g.*,
                                    COUNT(mga.member_id) as member_count
                                    FROM {$prefix}member_groups g
                                    LEFT JOIN {$prefix}member_group_assignments mga ON g.group_id = mga.group_id
                                    GROUP BY g.group_id
                                    ORDER BY g.sort_order, g.group_name");
                    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            break;

        case 'POST':
            requireAdmin();

            $data = json_decode(file_get_contents("php://input"));

            $isSubgroupPost = !empty($data->is_subgroup) ? 1 : 0;
            $isDefaultPost  = !empty($data->is_default) ? 1 : 0;

            // Eine Untergruppe (Register) darf nie Standardgruppe sein --
            // sonst landet jedes neue Mitglied ungefragt in diesem Register.
            if($isSubgroupPost && $isDefaultPost) {
                http_response_code(400);
                echo json_encode(["message" => "Eine Untergruppe kann nicht gleichzeitig Standardgruppe sein"]);
                break;
            }

            // Wenn is_default=true, setze alle anderen auf false
            if($isDefaultPost) {
                $db->exec("UPDATE {$prefix}member_groups SET is_default = 0");
            }

            $stmt = $db->prepare("INSERT INTO {$prefix}member_groups
                                  (group_name, description, is_default, is_subgroup, sort_order)
                                  VALUES (?, ?, ?, ?, ?)");
            if($stmt->execute([
                $data->group_name,
                $data->description ?? null,
                $isDefaultPost,
                $isSubgroupPost,
                (int) ($data->sort_order ?? 0)
            ])) {
                http_response_code(201);
                echo json_encode(["message" => "Group created", "id" => $db->lastInsertId()]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to create group"]);
            }
            break;

        case 'PUT':
            requireAdmin();

            $data = json_decode(file_get_contents("php://input"));
            $data = (object) ($data ?? []);

            // is_subgroup/sort_order: Dieses PUT ist ein Voll-Update wie das der
            // Terminarten (OI-54, vgl. responseTypeSettings() in responses.php) --
            // fehlen die Felder im Koerper, bleibt der gespeicherte Wert stehen,
            // statt still auf 0 zurueckzufallen und die gepflegte Reihenfolge zu
            // loeschen. description/is_default bleiben Altbestand (?? wie bisher).
            $currentStmt = $db->prepare("SELECT is_subgroup, sort_order, is_default FROM {$prefix}member_groups WHERE group_id = ?");
            $currentStmt->execute([$id]);
            $currentRow = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: ['is_subgroup' => 0, 'sort_order' => 0, 'is_default' => 0];

            $isSubgroup = property_exists($data, 'is_subgroup')
                ? (!empty($data->is_subgroup) ? 1 : 0)
                : (int) $currentRow['is_subgroup'];
            $sortOrder = property_exists($data, 'sort_order')
                ? (int) $data->sort_order
                : (int) $currentRow['sort_order'];

            // Fuer die Ausschluss-Pruefung zaehlt der Wert, der nach diesem PUT
            // gelten wird -- nicht nur das gesendete Feld. is_default faellt bei
            // Fehlen im Koerper zwar auf false zurueck (Voll-Update, s. o.), aber
            // wer nur is_subgroup schickt, waehrend die Gruppe bereits
            // Standardgruppe IST, darf sie nicht unbemerkt gleichzeitig zur
            // Untergruppe machen -- deshalb hier gegen den gespeicherten Wert
            // geprueft, nicht gegen das (fehlende) gesendete Feld.
            $effectiveIsDefault = property_exists($data, 'is_default')
                ? (!empty($data->is_default) ? 1 : 0)
                : (int) $currentRow['is_default'];

            if($isSubgroup && $effectiveIsDefault) {
                http_response_code(400);
                echo json_encode(["message" => "Eine Untergruppe kann nicht gleichzeitig Standardgruppe sein"]);
                break;
            }

            // Wenn is_default=true, setze alle anderen auf false (außer dieser)
            if(isset($data->is_default) && $data->is_default) {
                $db->prepare("UPDATE {$prefix}member_groups SET is_default = 0 WHERE group_id != ?")->execute([$id]);
            }

            $stmt = $db->prepare("UPDATE {$prefix}member_groups
                                  SET group_name = ?, description = ?, is_default = ?,
                                      is_subgroup = ?, sort_order = ?
                                  WHERE group_id = ?");
            if($stmt->execute([
                $data->group_name,
                $data->description ?? null,
                $data->is_default ?? false,
                $isSubgroup,
                $sortOrder,
                $id
            ])) {
                echo json_encode(["message" => "Group updated"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to update group"]);
            }
            break;
            
        case 'DELETE':
            requireAdmin();

            $stmt = $db->prepare("DELETE FROM {$prefix}member_groups WHERE group_id = ?");
            if($stmt->execute([$id])) {
                echo json_encode(["message" => "Group deleted"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to delete group"]);
            }
            break;
    }
}

?>
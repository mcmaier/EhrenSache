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

/**
 * Prueft parent_group_ids eines Registers (Spec 2026-10-02, 3): Liste ganzer
 * Zahlen, jede Gruppe muss existieren und eine gewoehnliche Gruppe sein (keine
 * Untergruppe, nicht die Gruppe selbst). Nur Untergruppen duerfen Gruppen haben.
 *
 * @return array{ids: array<int, int>, error: ?string} bereinigte, sortierte Liste
 */
function memberGroupsCheckParents($db, string $prefix, $raw, bool $isSubgroup, int $selfId): array {
    if(!is_array($raw)) {
        return ['ids' => [], 'error' => 'parent_group_ids muss eine Liste sein'];
    }

    $ids = [];
    foreach($raw as $value) {
        if(is_int($value) || (is_string($value) && ctype_digit($value))) {
            $ids[(int) $value] = (int) $value;
        } else {
            return ['ids' => [], 'error' => 'parent_group_ids darf nur Gruppen-IDs enthalten'];
        }
    }
    $ids = array_values($ids);
    sort($ids);

    if($ids === []) {
        return ['ids' => [], 'error' => null];
    }
    if(!$isSubgroup) {
        return ['ids' => [], 'error' => 'Nur eine Untergruppe kann Gruppen haben'];
    }
    if($selfId > 0 && in_array($selfId, $ids, true)) {
        return ['ids' => [], 'error' => 'Eine Untergruppe kann nicht zu sich selbst gehören'];
    }

    $in   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT group_id FROM {$prefix}member_groups WHERE group_id IN ({$in}) AND is_subgroup = 0");
    $stmt->execute($ids);
    if(count($stmt->fetchAll(PDO::FETCH_COLUMN)) !== count($ids)) {
        return ['ids' => [], 'error' => 'Jede Gruppe muss existieren und eine gewöhnliche Gruppe sein'];
    }

    return ['ids' => $ids, 'error' => null];
}

/** Ersetzt die Gruppen eines Registers: loeschen und neu einfuegen. */
function memberGroupsWriteParents($db, string $prefix, int $subgroupId, array $parentIds): void {
    $db->prepare("DELETE FROM {$prefix}subgroup_parents WHERE subgroup_id = ?")->execute([$subgroupId]);
    $insert = $db->prepare("INSERT INTO {$prefix}subgroup_parents (subgroup_id, group_id) VALUES (?, ?)");
    foreach($parentIds as $parentId) {
        $insert->execute([$subgroupId, $parentId]);
    }
}

/** Gruppen je Register als Karte subgroup_id => sortierte Liste von ints (mit ID: nur dieses Register). */
function memberGroupsParentMap($db, string $prefix, ?int $subgroupId = null): array {
    if($subgroupId !== null) {
        $stmt = $db->prepare("SELECT subgroup_id, group_id FROM {$prefix}subgroup_parents WHERE subgroup_id = ? ORDER BY group_id");
        $stmt->execute([$subgroupId]);
    } else {
        $stmt = $db->query("SELECT subgroup_id, group_id FROM {$prefix}subgroup_parents ORDER BY group_id");
    }
    $map = [];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int) $row['subgroup_id']][] = (int) $row['group_id'];
    }

    return $map;
}

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
                    $group['parent_group_ids'] = memberGroupsParentMap($db, $prefix, (int) $id)[(int) $id] ?? [];

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
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $parentMap = memberGroupsParentMap($db, $prefix);
                    foreach($rows as &$row) {
                        $row['parent_group_ids'] = $parentMap[(int) $row['group_id']] ?? [];
                    }
                    unset($row);
                    echo json_encode($rows);
            }
            break;

        case 'POST':
            requireAdmin();

            $data = json_decode(file_get_contents("php://input"));
            $data = (object) ($data ?? []);

            $isSubgroupPost = !empty($data->is_subgroup) ? 1 : 0;
            $isDefaultPost  = !empty($data->is_default) ? 1 : 0;

            // Eine Untergruppe (Register) darf nie Standardgruppe sein --
            // sonst landet jedes neue Mitglied ungefragt in diesem Register.
            if($isSubgroupPost && $isDefaultPost) {
                http_response_code(400);
                echo json_encode(["message" => "Eine Untergruppe kann nicht gleichzeitig Standardgruppe sein"]);
                break;
            }

            // Gruppen des Registers pruefen, bevor etwas geschrieben wird
            $parentIdsPost = null;
            if(property_exists($data, 'parent_group_ids')) {
                $check = memberGroupsCheckParents($db, $prefix, $data->parent_group_ids, (bool) $isSubgroupPost, 0);
                if($check['error'] !== null) {
                    http_response_code(400);
                    echo json_encode(["message" => $check['error']]);
                    break;
                }
                $parentIdsPost = $check['ids'];
            }

            // Alles in einer Transaktion: Gruppe, Zuordnungen und Mitgliedschaftsregel
            // gelten ganz oder gar nicht. Nichts darin gibt etwas aus.
            try {
                $db->beginTransaction();

                // Wenn is_default=true, setze alle anderen auf false
                if($isDefaultPost) {
                    $db->exec("UPDATE {$prefix}member_groups SET is_default = 0");
                }

                $stmt = $db->prepare("INSERT INTO {$prefix}member_groups
                                      (group_name, description, is_default, is_subgroup, sort_order)
                                      VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $data->group_name ?? null,
                    $data->description ?? null,
                    $isDefaultPost,
                    $isSubgroupPost,
                    (int) ($data->sort_order ?? 0)
                ]);
                $newId = (int) $db->lastInsertId();
                $added = [];
                $warnings = [];
                if($parentIdsPost) {
                    memberGroupsWriteParents($db, $prefix, $newId, $parentIdsPost);
                    $rule = groupsApplySubgroupRule($db, $database, $newId);
                    $added = $rule['added'];
                    $warnings = $rule['warnings'];
                }

                $db->commit();

                http_response_code(201);
                echo json_encode(["message" => "Group created", "id" => $newId,
                                  "added_groups" => $added, "group_warnings" => $warnings]);
            } catch (Throwable $e) {
                if($db->inTransaction()) {
                    $db->rollBack();
                }
                // Deadlock (1213) oder Lock-Timeout (1205): Client kann sofort wiederholen
                $isDeadlock = $e instanceof PDOException && in_array($e->errorInfo[1] ?? 0, [1205, 1213]);
                http_response_code($isDeadlock ? 503 : 500);
                echo json_encode([
                    "message" => $isDeadlock
                        ? "Temporärer Datenbankkonflikt. Bitte erneut versuchen."
                        : "Failed to create group",
                ]);
                error_log("POST member_groups failed: " . $e->getMessage());
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
            $currentRow = $currentStmt->fetch(PDO::FETCH_ASSOC);
            if(!$currentRow) {
                http_response_code(404);
                echo json_encode(["message" => "Group not found"]);
                break;
            }

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

            // Eine Gruppe, der Register zugeordnet sind, darf keine Untergruppe
            // werden -- sonst entstuenden drei Ebenen.
            if($isSubgroup) {
                $childStmt = $db->prepare("SELECT 1 FROM {$prefix}subgroup_parents WHERE group_id = ? LIMIT 1");
                $childStmt->execute([$id]);
                if($childStmt->fetchColumn()) {
                    http_response_code(400);
                    echo json_encode(["message" => "Eine Gruppe mit Untergruppen kann nicht selbst Untergruppe werden"]);
                    break;
                }
            }

            // Gruppen des Registers: fehlt das Feld, bleiben die Zuordnungen stehen
            $parentIdsPut = null;
            if(property_exists($data, 'parent_group_ids')) {
                $check = memberGroupsCheckParents($db, $prefix, $data->parent_group_ids, (bool) $isSubgroup, (int) $id);
                if($check['error'] !== null) {
                    http_response_code(400);
                    echo json_encode(["message" => $check['error']]);
                    break;
                }
                $parentIdsPut = $check['ids'];
            }

            // Alles in einer Transaktion: Gruppe, Zuordnungen und Mitgliedschaftsregel
            // gelten ganz oder gar nicht. Nichts darin gibt etwas aus.
            try {
                $db->beginTransaction();

                // Wenn is_default=true, setze alle anderen auf false (außer dieser)
                if(isset($data->is_default) && $data->is_default) {
                    $db->prepare("UPDATE {$prefix}member_groups SET is_default = 0 WHERE group_id != ?")->execute([$id]);
                }

                $stmt = $db->prepare("UPDATE {$prefix}member_groups
                                      SET group_name = ?, description = ?, is_default = ?,
                                          is_subgroup = ?, sort_order = ?
                                      WHERE group_id = ?");
                $stmt->execute([
                    $data->group_name ?? null,
                    $data->description ?? null,
                    $data->is_default ?? false,
                    $isSubgroup,
                    $sortOrder,
                    $id
                ]);

                $added = [];
                $warnings = [];
                if(!$isSubgroup) {
                    // Wer keine Untergruppe mehr ist, verliert seine Zuordnungen
                    memberGroupsWriteParents($db, $prefix, (int) $id, []);
                } elseif($parentIdsPut !== null) {
                    memberGroupsWriteParents($db, $prefix, (int) $id, $parentIdsPut);
                    $rule = groupsApplySubgroupRule($db, $database, (int) $id);
                    $added = $rule['added'];
                    $warnings = $rule['warnings'];
                }

                $db->commit();

                echo json_encode(["message" => "Group updated",
                                  "added_groups" => $added, "group_warnings" => $warnings]);
            } catch (Throwable $e) {
                if($db->inTransaction()) {
                    $db->rollBack();
                }
                $isDeadlock = $e instanceof PDOException && in_array($e->errorInfo[1] ?? 0, [1205, 1213]);
                http_response_code($isDeadlock ? 503 : 500);
                echo json_encode([
                    "message" => $isDeadlock
                        ? "Temporärer Datenbankkonflikt. Bitte erneut versuchen."
                        : "Failed to update group",
                ]);
                error_log("PUT member_groups $id failed: " . $e->getMessage());
            }
            break;

        case 'DELETE':
            requireAdmin();

            if (!$id) {
                http_response_code(400);
                echo json_encode(["message" => "id ist erforderlich"]);
                break;
            }

            $stmt = $db->prepare("DELETE FROM {$prefix}member_groups WHERE group_id = ?");
            if($stmt->execute([$id])) {
                if($stmt->rowCount() === 0) {
                    http_response_code(404);
                    echo json_encode(["message" => "Group not found"]);
                    break;
                }
                echo json_encode(["message" => "Group deleted"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to delete group"]);
            }
            break;
    }
}

?>
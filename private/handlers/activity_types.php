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
 * Terminarten, auf die eine Taetigkeitsart eingegrenzt ist.
 *
 * Ein leeres Array bedeutet KEINE Einschraenkung — die Taetigkeitsart bietet
 * dann alle Termine an. Das ist bewusst die andere Semantik als bei den
 * Gruppen, wo eine leere Zuordnung "niemand" heisst: Bei Gruppen waere
 * "leer = alle" eine stille Rechteausweitung, bei Terminarten waere
 * "leer = keine" eine Selbstblockade nach dem Update.
 *
 * @return array<int, int>
 */
function activityAppointmentTypeIds($db, $database, int $activityId): array
{
    $prefix = $database->table('');

    $stmt = $db->prepare("SELECT type_id FROM {$prefix}activity_type_appointment_types
                          WHERE activity_id = ? ORDER BY type_id");
    $stmt->execute([$activityId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Setzt die Terminart-Zuordnung neu. Ein leeres Array loest sie.
 *
 * @param array<int, mixed> $typeIds
 * @return bool false, wenn eine der IDs unbekannt ist
 */
function setActivityAppointmentTypes($db, $database, int $activityId, array $typeIds): bool
{
    $prefix = $database->table('');

    $ids = array_values(array_unique(array_map('intval', $typeIds)));

    if($ids !== []) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $check = $db->prepare("SELECT COUNT(*) FROM {$prefix}appointment_types
                               WHERE type_id IN ({$placeholders})");
        $check->execute($ids);

        if((int)$check->fetchColumn() !== count($ids)) {
            return false;
        }
    }

    $db->prepare("DELETE FROM {$prefix}activity_type_appointment_types WHERE activity_id = ?")
       ->execute([$activityId]);

    if($ids !== []) {
        $insert = $db->prepare("INSERT INTO {$prefix}activity_type_appointment_types
                                (activity_id, type_id) VALUES (?, ?)");
        foreach($ids as $typeId) {
            $insert->execute([$activityId, $typeId]);
        }
    }

    return true;
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
                // Dieselbe Sichtbarkeitsregel wie im Listenzweig unten:
                // activityTypeVisibility() in helpers/worktime.php liefert
                // Gruppengrenze und is_active-Filter als einen SQL-Zusatz.
                // Bis dahin las dieser Zweig ohne beides — jedes angemeldete
                // Konto konnte Name, Beschreibung, Nachweisart, Gruppenliste
                // und Terminarten einer fremden oder ausgemusterten Art lesen.
                // Eine nicht sichtbare Art verhaelt sich wie eine nicht
                // vorhandene (404, gleiche Meldung), sonst verriete die
                // Antwort, dass es sie gibt.
                [$visibilitySql, $visibilityParams] = activityTypeVisibility($db, $database, 'a');

                $stmt = $db->prepare("SELECT a.* FROM {$prefix}activity_types a
                                      WHERE a.activity_id = ?" . $visibilitySql);
                $stmt->execute(array_merge([$id], $visibilityParams));
                $type = $stmt->fetch(PDO::FETCH_ASSOC);

                if($type) {
                    // Lade zugehörige Gruppen
                    $groupStmt = $db->prepare("SELECT g.* FROM {$prefix}member_groups g
                                               INNER JOIN {$prefix}activity_type_groups atg
                                                       ON g.group_id = atg.group_id
                                               WHERE atg.activity_id = ?");
                    $groupStmt->execute([$id]);
                    $type['groups'] = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
                    $type['appointment_type_ids'] =
                        activityAppointmentTypeIds($db, $database, (int)$id);

                    echo json_encode($type);
                } else {
                    http_response_code(404);
                    echo json_encode(["message" => "Activity type not found"]);
                }
            } else {
                // Gruppengrenze und is_active-Filter stehen an einer Stelle
                // (helpers/worktime.php) und gelten damit auch fuer den
                // Einzelabruf oben.
                [$visibilitySql, $params] = activityTypeVisibility($db, $database, 'a');

                $sql = "SELECT a.* FROM {$prefix}activity_types a WHERE 1=1"
                     . $visibilitySql
                     . " ORDER BY a.activity_name";

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
                    $type['appointment_type_ids'] =
                        activityAppointmentTypeIds($db, $database, (int)$type['activity_id']);
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

            // Ohne Gruppe waere die Taetigkeitsart fuer NIEMANDEN erfassbar —
            // ein toter Datensatz, den erst ein Administrator wieder findet.
            // Die Pruefung steht hinter Name und verification, damit deren
            // Meldungen den genaueren Grund nennen.
            if(empty($data->group_ids) || !is_array($data->group_ids)) {
                http_response_code(400);
                echo json_encode(["message" => "At least one group is required",
                                  "hint"    => "Without a group the activity type is usable by nobody"]);
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

                // Terminarten sind optional: Fehlt das Feld, bleibt die
                // Taetigkeitsart unverknuepft und bietet alle Termine an.
                if(isset($data->appointment_type_ids) && is_array($data->appointment_type_ids)) {
                    if(!setActivityAppointmentTypes($db, $database, $newId,
                                                    $data->appointment_type_ids)) {
                        http_response_code(400);
                        echo json_encode(["message" => "Unknown appointment type id"]);
                        return;
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

            // Ein PUT aendert seit OI-54 nur, was es mitschickt. Der Name ist
            // deshalb nicht mehr Pflicht -- aber wenn er kommt, darf er nicht
            // leer sein: Eine Taetigkeitsart ohne Namen ist in keiner Liste
            // wiederzufinden.
            if(property_exists($data, 'activity_name') && trim((string) $data->activity_name) === '') {
                http_response_code(400);
                echo json_encode(["message" => "activity_name must not be empty"]);
                return;
            }

            if(isset($data->verification)
               && !in_array($data->verification, $allowedVerification, true)) {
                http_response_code(400);
                echo json_encode(["message" => "Invalid verification value",
                                  "allowed" => $allowedVerification]);
                return;
            }

            // Ein FEHLENDES group_ids laesst die Zuordnung unangetastet — das
            // ist dokumentiertes Verhalten. Ein LEERES Array wuerde sie
            // loeschen und die Art fuer niemanden mehr erfassbar machen.
            if(isset($data->group_ids) && is_array($data->group_ids) && count($data->group_ids) === 0) {
                http_response_code(400);
                echo json_encode(["message" => "At least one group is required",
                                  "hint"    => "Without a group the activity type is usable by nobody"]);
                return;
            }

            if(isset($data->is_default) && $data->is_default) {
                $db->prepare("UPDATE {$prefix}activity_types SET is_default = 0 WHERE activity_id != ?")
                   ->execute([$id]);
            }

            // Nur schreiben, was mitgeschickt wurde (OI-54). Vorher schrieb das
            // UPDATE alle Grundfelder bedingungslos -- am folgenreichsten bei
            // is_active: Ein fehlendes Feld fiel auf 1 zurueck und aktivierte
            // eine ausgemusterte Taetigkeitsart still wieder.
            $updateFields = [];
            $updateParams = [];

            foreach (['activity_name', 'description', 'color', 'verification'] as $feld) {
                if (isset($data->$feld)) {
                    $updateFields[] = "{$feld} = ?";
                    $updateParams[] = $data->$feld;
                }
            }

            foreach (['is_default', 'is_active'] as $feld) {
                if (isset($data->$feld)) {
                    $updateFields[] = "{$feld} = ?";
                    $updateParams[] = $data->$feld ? 1 : 0;
                }
            }

            if ($updateFields === []) {
                // Nichts an der Art selbst: Gruppen und Terminarten unten
                // koennen trotzdem gemeint sein, deshalb kein Fehler.
                $updateFields[] = "activity_id = activity_id";
            }

            $updateParams[] = $id;

            $stmt = $db->prepare("UPDATE {$prefix}activity_types
                                  SET " . implode(', ', $updateFields) . "
                                  WHERE activity_id = ?");
            if($stmt->execute($updateParams)) {
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

                // Terminarten: fehlendes Feld laesst die Zuordnung unangetastet,
                // ein leeres Array loest sie. Anders als bei den Gruppen ist das
                // leere Array hier ZULAESSIG — es bedeutet "keine Einschraenkung",
                // nicht "fuer niemanden nutzbar".
                if(isset($data->appointment_type_ids) && is_array($data->appointment_type_ids)) {
                    if(!setActivityAppointmentTypes($db, $database, (int)$id,
                                                    $data->appointment_type_ids)) {
                        http_response_code(400);
                        echo json_encode(["message" => "Unknown appointment type id"]);
                        return;
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

            if (!$id) {
                http_response_code(400);
                echo json_encode(["message" => "id ist erforderlich"]);
                break;
            }

            try {
                $stmt = $db->prepare("DELETE FROM {$prefix}activity_types WHERE activity_id = ?");
                $stmt->execute([$id]);

                if ($stmt->rowCount() === 0) {
                    http_response_code(404);
                    echo json_encode(["message" => "Activity type not found"]);
                    break;
                }

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

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
// APPOINTMENTS Controller
// ============================================
function handleAppointments($db, $database, $method, $id) {

    $prefix = $database->table('');
    require_once __DIR__ . '/../helpers/appointment_attendance.php';

    switch($method) {
        case 'GET':
            // Vorschlagsliste fuer das Ortsfeld (FI-23). Nur fuer die Rollen,
            // die Termine anlegen -- Orte sind Planungsdaten.
            if (isset($_GET['locations'])) {
                if (!isAdminOrManager()) {
                    http_response_code(403);
                    echo json_encode(["message" => "Nur für Admin und Manager"], JSON_UNESCAPED_UNICODE);
                    break;
                }
                echo json_encode(appointmentLocationSuggestions($db, $prefix), JSON_UNESCAPED_UNICODE);
                break;
            }

            if($id) {
                // Zaehlungen fuer die Rueckfrage vor dem Loeschen (OI-125):
                // nur fuer Verwalter -- sie verraten, wie viele Mitglieder
                // geantwortet oder Antraege gestellt haben.
                $withDependents = isset($_GET['dependents']);
                if ($withDependents && !isAdminOrManager()) {
                    http_response_code(403);
                    echo json_encode(["message" => "Nur für Admin und Manager"], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $sql = "SELECT a.*,
                                        at.type_name,
                                        at.color,
                                        at.description as type_description
                                        FROM {$prefix}appointments a
                                        LEFT JOIN {$prefix}appointment_types at ON a.type_id = at.type_id
                                        WHERE a.appointment_id = ?";
                $params = [$id];

                // Dieselbe Gruppengrenze wie im Listenzweig unten
                // (appointmentGroupVisibility() in helpers/appointment_rules.php).
                // Bis dahin las der Einzelabruf ungefiltert: jedes angemeldete
                // Mitglied konnte damit Titel, Beschreibung, Ort und Zeiten eines
                // Termins einer fremden Gruppe lesen. Ein nicht sichtbarer Termin
                // verhaelt sich wie ein nicht vorhandener -- 404 mit derselben
                // Meldung, sonst verraet die Antwort, dass es ihn gibt.
                if(!isAdminOrManager()) {
                    $userStmt = $db->prepare("SELECT member_id FROM {$prefix}users WHERE user_id = ?");
                    $userStmt->execute([getCurrentUserId()]);
                    $userMemberId = $userStmt->fetchColumn();

                    $visibility = appointmentGroupVisibility($db, $prefix, $userMemberId);
                    if($visibility === null) {
                        http_response_code(404);
                        echo json_encode(["message" => "Appointment not found"]);
                        break;
                    }

                    $sql .= $visibility[0];
                    $params = array_merge($params, $visibility[1]);
                }

                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
                if($appointment) {
                    if ($withDependents) {
                        $appointment['dependents'] = appointmentDependentCounts($db, $prefix, (int) $id);
                    }
                    echo json_encode($appointment);
                } else {
                    http_response_code(404);
                    echo json_encode(["message" => "Appointment not found"]);
                }
            } 
            else {
                // Liste mit optionalen Filtern
                $year = $_GET['year'] ?? null;
                $month = $_GET['month'] ?? null;
                $from_date = $_GET['from_date'] ?? null;
                $to_date = $_GET['to_date'] ?? null;
                $type_id = $_GET['type_id'] ?? null;
                $member_id = $_GET['member_id'] ?? null;
                
                // Baue Query dynamisch - MIT Alias für type_description
                $sql = "SELECT a.*,
                        at.type_name,
                        at.color,
                        at.type_id,
                        at.description as type_description,
                        COALESCE(at.responses_enabled, 0) as responses_enabled
                        FROM {$prefix}appointments a
                        LEFT JOIN {$prefix}appointment_types at ON a.type_id = at.type_id
                        WHERE 1=1";
                $params = [];
                
                // Gruppen-Filterung für User
                if(!isAdminOrManager() || isset($member_id)) {
                    // Hole member_id des Users
                    $userStmt = $db->prepare("SELECT member_id FROM {$prefix}users WHERE user_id = ?");
                    $userStmt->execute([getCurrentUserId()]);
                    $userMemberId = $userStmt->fetchColumn();

                    // Eine fremde member_id duerfen nur Verwalter setzen. Fuer
                    // alle anderen bleibt es beim eigenen Mitglied -- sonst
                    // liest ein Mitglied mit einer fremden ID die Termine
                    // fremder Gruppen. Die PWA schickt die eigene ID mit;
                    // fuer sie aendert sich dadurch nichts.
                    if($member_id !== null && isAdminOrManager())
                    {
                        $userMemberId = $member_id;
                    }
                    
                    // Filterung: Nur Termine, deren Terminart zu den Gruppen des
                    // Mitglieds passt. Kein verknuepftes Mitglied oder keine
                    // Gruppe → keine Termine. Dieselbe Regel wendet der
                    // Einzelabruf oben an (helpers/appointment_rules.php).
                    $visibility = appointmentGroupVisibility($db, $prefix, $userMemberId);
                    if($visibility === null) {
                        echo json_encode([]);
                        return;
                    }

                    $sql .= $visibility[0];
                    $params = array_merge($params, $visibility[1]);
                }
                
                if($year) {
                    $sql .= " AND YEAR(a.date) = ?";
                    $params[] = $year;
                }
                
                if($month && $year) {
                    $sql .= " AND MONTH(a.date) = ?";
                    $params[] = $month;
                }
                
                if($from_date) {
                    $sql .= " AND a.date >= ?";
                    $params[] = $from_date;
                }
                
                if($to_date) {
                    $sql .= " AND a.date <= ?";
                    $params[] = $to_date;
                }

                if($type_id) {
                    $sql .= " AND at.type_id = ?";
                    $params[] = $type_id;
                }
                
                $sql .= " ORDER BY a.date DESC, a.start_time";

                if(count($params) > 0) {
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                } else {
                    $stmt = $db->query($sql);
                }

                //$stmt = $db->query("SELECT * FROM appointments ORDER BY date DESC, start_time");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Summen nur bei eingegrenztem Zeitraum anhaengen: Ohne Jahres- oder
                // Datumsfilter laeuft die Liste ueber die ganze Historie (so ruft die
                // Check-in-PWA sie mit member_id allein ab) -- dort waeren die je Termin
                // korrelierten Aktivitaets-Unterabfragen zu teuer. Die PWA holt sich
                // Rueckmeldungen stattdessen ueber resource=appointment_responses&upcoming=1.
                if ($year || $from_date || $to_date) {
                    // Eigene Antwort je Termin: das Mitglied des angemeldeten Kontos,
                    // auch bei Admin und Manager, wenn eines verknuepft ist.
                    $viewerStmt = $db->prepare("SELECT member_id FROM {$prefix}users WHERE user_id = ?");
                    $viewerStmt->execute([getCurrentUserId()]);
                    $viewerMemberId = $viewerStmt->fetchColumn();

                    $rows = responsesAttachSummaries(
                        $db, $database, $rows,
                        $viewerMemberId ? (int) $viewerMemberId : null
                    );

                    // Anwesenheitszahlen nur auf Anforderung (Kalender, Spec
                    // 2026-09-22-kalender-anwesenheit). Ohne include bleibt die
                    // Antwort unveraendert; ohne Zeitraum waere die Zaehlung
                    // ueber die ganze Historie zu teuer -- dieselbe Grenze wie
                    // bei den Rueckmeldungen oben.
                    // Ohne Anwesenheit keine Zahlen (OI-62, Etappe 2).
                    if (($_GET['include'] ?? '') === 'attendance'
                        && isFeatureEnabled($db, $database, 'attendance')) {
                        $rows = attendanceAttachSummaries(
                            $db, $database, $rows,
                            $viewerMemberId ? (int) $viewerMemberId : null,
                            isAdminOrManager()
                        );
                    }
                }

                echo json_encode($rows);
            }
            break;
            
        case 'POST':
            requireAdminOrManager();

            $rawData = json_decode(file_get_contents("php://input"));
            
            // Nur erlaubte Felder extrahieren
            $allowedFields = ['title', 'description','type_id', 'date', 'start_time','created_by', 'location', 'end_time'];
            $data = new stdClass();
            foreach($allowedFields as $field) {
                if(isset($rawData->$field)) {
                    $data->$field = $rawData->$field;
                }
            }

            // Titel, Datum, Beginn, Beschreibung und Terminart pruefen, bevor
            // irgendetwas geschrieben wird. Fehlende Felder kommen als null an.
            [$core, $fehler] = appointmentNormalizeCore([
                'title'       => $data->title ?? null,
                'date'        => $data->date ?? null,
                'start_time'  => $data->start_time ?? null,
                'description' => $data->description ?? null,
                'type_id'     => $data->type_id ?? null,
            ]);

            // Ohne Angabe gilt die Standard-Terminart.
            $typeId = null;
            if ($fehler === null) {
                $typeId = $core['type_id'] ?? seriesDefaultTypeId($db, $prefix);
                if ($core['type_id'] !== null && !seriesTypeExists($db, $prefix, $core['type_id'])) {
                    $fehler = 'Die Terminart existiert nicht';
                }
            }

            // Ort und Ende (FI-23).
            $location = null;
            $endTime = null;
            if ($fehler === null) {
                [$location, $fehler] = appointmentNormalizeLocation($data->location ?? null);
            }
            if ($fehler === null) {
                [$endTime, $fehler] = appointmentNormalizeEndTime($data->end_time ?? null, $core['start_time']);
            }
            if ($fehler !== null) {
                http_response_code(400);
                echo json_encode(["message" => $fehler], JSON_UNESCAPED_UNICODE);
                break;
            }

            // Dublettenpruefung: gleiche Terminart im Toleranzfenster (appointment_rules.php).
            $tolerance = checkinToleranceHours($db, $database);
            $conflict = findAppointmentConflict($db, $prefix, $core['date'], $core['start_time'],
                                                $typeId, $tolerance);
            if ($conflict) {
                http_response_code(409);
                echo json_encode(appointmentConflictBody($conflict, $tolerance));
                break;
            }

            $stmt = $db->prepare("INSERT INTO {$prefix}appointments (title, type_id, description, location, date,
                                  start_time, end_time, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $createdBy = getCurrentUserId();
            if($stmt->execute([$core['title'], $typeId, $core['description'], $location, $core['date'],
                               $core['start_time'], $endTime, $createdBy])) {
                http_response_code(201);
                echo json_encode(["message" => "Appointment created", "id" => $db->lastInsertId()]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to create appointment"]);
            }
            break;
            
        case 'PUT':
            requireAdminOrManager();

            $rawData = (object) (json_decode(file_get_contents("php://input")) ?? []);

            // Nur erlaubte Felder extrahieren. property_exists statt isset:
            // Ein ausdrueckliches null ist eine Angabe ("Beschreibung loeschen"),
            // ein fehlendes Feld ist keine. isset() warf beides in denselben
            // Topf und machte das Loeschen einer Angabe unmoeglich.
            $allowedFields = ['title', 'type_id', 'description', 'date', 'start_time', 'location', 'end_time'];
            $data = new stdClass();
            foreach($allowedFields as $field) {
                if(property_exists($rawData, $field)) {
                    $data->$field = $rawData->$field;
                }
            }

            // Bestand lesen: Was der Request nicht mitschickt, bleibt stehen.
            // Bis 1.9.0 war das eine Vollersetzung -- ein PUT, das nur das
            // Datum aendern wollte, nullte Titel und Terminart (OI-69). Die
            // schwerere Folge war die Terminart: Ein Termin ohne type_id hat
            // keine Gruppenzuordnung mehr, verschwindet aus den Listen der
            // Mitglieder und zaehlt in keiner Auswertung mehr mit.
            $bestandStmt = $db->prepare("SELECT title, type_id, description, date, start_time, end_time, location, series_id
                                         FROM {$prefix}appointments WHERE appointment_id = ?");
            $bestandStmt->execute([$id]);
            $bestand = $bestandStmt->fetch(PDO::FETCH_ASSOC);

            if(!$bestand) {
                http_response_code(404);
                echo json_encode(["message" => "Appointment not found"]);
                break;
            }

            // OI-124: Was mit Zusagen geschieht, wenn sich der Zeitpunkt aendert,
            // entscheidet der Client. Ohne Angabe fragt der Server unten per 409.
            $resetFlag = responsesResetFlag(get_object_vars($rawData));
            if ($resetFlag['error']) {
                http_response_code(400);
                echo json_encode(["message" => "reset_responses muss true oder false sein"], JSON_UNESCAPED_UNICODE);
                break;
            }

            // Mitgeschickte Kernfelder pruefen; was fehlt, bleibt wie gespeichert.
            // title, date und start_time sind Pflicht und lassen sich nicht per
            // null loeschen. type_id darf null werden (keine Terminart),
            // description ebenso (leer wird null).
            $kern = array_intersect_key(get_object_vars($data), array_flip(['title', 'date', 'start_time', 'description', 'type_id']));
            [$kern, $fehler] = appointmentNormalizeCore($kern);
            if ($fehler === null && ($kern['type_id'] ?? null) !== null
                && !seriesTypeExists($db, $prefix, $kern['type_id'])) {
                $fehler = 'Die Terminart existiert nicht';
            }
            if ($fehler !== null) {
                http_response_code(400);
                echo json_encode(["message" => $fehler], JSON_UNESCAPED_UNICODE);
                break;
            }
            foreach ($kern as $feld => $wert) {
                $data->$feld = $wert;
            }

            // Prüfe ob bereits ein anderer Termin der gleichen Art in der Toleranzzeit existiert

            // Geprüft wird gegen den Zustand NACH dem Update, nicht gegen den
            // Anfragekörper: Fehlt ein Feld, gilt der gespeicherte Wert. Vorher
            // verglich die Prüfung bei einem Teil-Update gegen " " und
            // type_id = NULL -- und NULL trifft in SQL nie, die Dublettenprüfung
            // fiel also still aus.
            $wirkDate  = $data->date       ?? $bestand['date'];
            $wirkTime  = $data->start_time ?? $bestand['start_time'];
            $wirkType  = array_key_exists('type_id', get_object_vars($data))
                ? $data->type_id : $bestand['type_id'];

            // Ort und Ende (FI-23). Geprueft wird gegen den wirksamen Beginn;
            // aendert der Request nur den Beginn, zaehlt das gespeicherte Ende.
            $gesendet = get_object_vars($data);
            if (array_key_exists('location', $gesendet)) {
                [$data->location, $fehler] = appointmentNormalizeLocation($data->location);
                if ($fehler !== null) {
                    http_response_code(400);
                    echo json_encode(["message" => $fehler], JSON_UNESCAPED_UNICODE);
                    break;
                }
            }
            if (array_key_exists('end_time', $gesendet)) {
                [$data->end_time, $fehler] = appointmentNormalizeEndTime($data->end_time, (string) $wirkTime);
                if ($fehler !== null) {
                    http_response_code(400);
                    echo json_encode(["message" => $fehler], JSON_UNESCAPED_UNICODE);
                    break;
                }
            }
            $wirkEnd = array_key_exists('end_time', $gesendet) ? $data->end_time : $bestand['end_time'];
            if ($wirkEnd !== null && appointmentTimeKey((string) $wirkEnd) === appointmentTimeKey((string) $wirkTime)) {
                http_response_code(400);
                echo json_encode(["message" => "Das Ende darf nicht gleich dem Beginn sein"], JSON_UNESCAPED_UNICODE);
                break;
            }

            $tolerance = checkinToleranceHours($db, $database);
            $conflict = findAppointmentConflict($db, $prefix, (string) $wirkDate, (string) $wirkTime,
                                                $wirkType, $tolerance, [(int) $id]);
            if ($conflict) {
                http_response_code(409);
                echo json_encode(appointmentConflictBody($conflict, $tolerance));
                break;
            }

            // Nur schreiben, was mitgeschickt wurde -- dasselbe Muster wie bei
            // members, appointment_types und activity_types (OI-54).
            // description und type_id duerfen ausdruecklich auf NULL gesetzt
            // werden, deshalb array_key_exists statt isset.
            $vorhanden = get_object_vars($data);
            $updateFields = [];
            $updateParams = [];
            $geaendert = false;
            $datumGeaendert = false;
            $zeitpunktGeaendert = false;

            foreach (['title', 'type_id', 'description', 'date', 'start_time', 'location', 'end_time'] as $feld) {
                if (array_key_exists($feld, $vorhanden)) {
                    $updateFields[] = "{$feld} = ?";
                    $updateParams[] = $data->$feld;
                    if (appointmentFieldChanged($feld, $data->$feld, $bestand[$feld])) {
                        $geaendert = true;
                        if ($feld === 'date') {
                            $datumGeaendert = true;
                        }
                        if ($feld === 'date' || $feld === 'start_time') {
                            $zeitpunktGeaendert = true;
                        }
                    }
                }
            }

            if (empty($updateFields)) {
                echo json_encode(["message" => "Appointment updated", "responses_reset" => 0]);
                break;
            }

            // Ein einzeln geaenderter Serientermin folgt der Serie nicht mehr (FI-7) --
            // aber nur bei einer echten Aenderung. Der Dialog schickt alle Felder
            // mit, auch unveraendert; sonst loeste sich jeder Serientermin schon
            // beim blossen Speichern ab.
            if ($bestand['series_id'] !== null && $geaendert) {
                $updateFields[] = 'is_detached = 1';
            }

            $updateParams[] = $id;

            // Aenderung, Seriendatum und Zuruecksetzen gelingen gemeinsam oder
            // gar nicht (OI-124). Die Zeilensperre haelt die Zahl der Rueckfrage
            // bis zum Schreiben stabil.
            $db->beginTransaction();
            try {
                $db->prepare("SELECT appointment_id FROM {$prefix}appointments WHERE appointment_id = ? FOR UPDATE")
                   ->execute([$id]);

                $zurueckgesetzt = 0;
                if ($zeitpunktGeaendert && $resetFlag['value'] !== false) {
                    $betroffen = responsesCountResettable($db, $prefix, [(int) $id]);
                    if ($betroffen['responses'] > 0 && $resetFlag['value'] === null) {
                        $db->rollBack();
                        http_response_code(409);
                        echo json_encode(responsesAffectedBody($betroffen), JSON_UNESCAPED_UNICODE);
                        break;
                    }
                    if ($resetFlag['value'] === true) {
                        $zurueckgesetzt = responsesReset($db, $prefix, [(int) $id]);
                    }
                }

                $db->prepare("UPDATE {$prefix}appointments SET " . implode(', ', $updateFields)
                             . " WHERE appointment_id = ?")
                   ->execute($updateParams);

                // Verschiebt sich ein Serientermin auf ein anderes Datum, gilt das
                // ALTE Datum als Ausfall der Serie -- wie beim Einzel-DELETE (FI-7).
                // Ohne diesen Eintrag legt eine spaetere Serienaktion (z. B. ein
                // Split, dessen Regel denselben Wochentag trifft) am alten Datum
                // erneut einen Termin an: eine doppelte Probe.
                if ($bestand['series_id'] !== null && $datumGeaendert) {
                    seriesAddExdates($db, $prefix, (int) $bestand['series_id'], [$bestand['date']]);
                }

                $db->commit();
                echo json_encode(["message" => "Appointment updated", "responses_reset" => $zurueckgesetzt]);
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('appointments PUT: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(["message" => "Failed to update appointment"]);
            }
            break;

        case 'DELETE':
            requireAdminOrManager();

            if (!$id) {
                http_response_code(400);
                echo json_encode(["message" => "id ist erforderlich"]);
                break;
            }
            
            // Ein geloeschter Serientermin ist ein Ausfall: Das Datum wandert in
            // exdates, damit "Serie fortsetzen" es nicht wieder erzeugt (FI-7).
            $serienStmt = $db->prepare("SELECT series_id, date FROM {$prefix}appointments WHERE appointment_id = ?");
            $serienStmt->execute([$id]);
            $serienRow = $serienStmt->fetch(PDO::FETCH_ASSOC);
            if ($serienRow && $serienRow['series_id'] !== null) {
                seriesAddExdates($db, $prefix, (int) $serienRow['series_id'], [$serienRow['date']]);
            }

            // Lösche zuerst abhängige Datensätze
            $db->prepare("DELETE FROM {$prefix}records WHERE appointment_id = ?")->execute([$id]);
            // Explizit vor exceptions (W2): appointment_responses.exception_id zeigt
            // sonst kurzzeitig auf einen bereits geloeschten Antrag.
            $db->prepare("DELETE FROM {$prefix}appointment_responses WHERE appointment_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM {$prefix}exceptions WHERE appointment_id = ?")->execute([$id]);
            
            // Dann den Termin selbst
            $stmt = $db->prepare("DELETE FROM {$prefix}appointments WHERE appointment_id = ?");
            if($stmt->execute([$id])) {
                if($stmt->rowCount() === 0) {
                    // execute() liefert true, auch wenn keine Zeile traf --
                    // eine erfundene oder bereits geloeschte ID sah bisher
                    // wie ein Erfolg aus.
                    http_response_code(404);
                    echo json_encode(["message" => "Appointment not found"]);
                } else {
                    echo json_encode(["message" => "Appointment deleted"]);
                }
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to delete appointment"]);
            }
            break;
    }
}

?>
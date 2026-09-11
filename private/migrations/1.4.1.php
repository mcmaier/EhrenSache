<?php

/**
 * EhrenSache - Migration v1.4.1 → v1.5.0
 *
 * Die Ankunftszeit darf fehlen.
 *
 * `records.arrival_time` war DATETIME NOT NULL und trug zwei Rollen: die
 * gemessene Ankunft und das Datum, an dem der Datensatz hängt. Weil das Datum
 * gebraucht wurde — für die Jahresauswahl und für die Löschfrist —, musste eine
 * fehlende Uhrzeit erfunden werden: `records.php` setzte die Startzeit des
 * Termins ein. Der Datensatz war damit konstruiert pünktlich, und keine
 * Auswertung konnte das von einer echten Messung unterscheiden.
 *
 * Die Datumsrolle liegt ab 1.5.0 auf `appointments.date`, wo die Statistik
 * ohnehin rechnet. Die Uhrzeit darf NULL sein und heißt dann: keine Aussage
 * über die Ankunft.
 *
 * **Verlustbehaftet und nicht umkehrbar.** Schritt 3 und 4 leeren Uhrzeiten,
 * die nie eine Aussage waren. Ein Admin, der eine Ankunft bewusst auf die
 * Startminute gesetzt hat, verliert sie — sie ist von einem abgehakten
 * Listeneintrag nicht unterscheidbar. Der Fehler geht zulasten der
 * Messabdeckung, nicht zulasten einer Quote, und das ist die richtige
 * Richtung: lieber eine Messung zu wenig als eine erfundene.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */

function migrate_1_4_1(PDO $pdo, string $prefix, string $configPath): array
{
    $log      = [];
    $warnings = [];

    // 1. Herkunft einer nachträglich genehmigten Zeit wird darstellbar.
    //    Ohne diesen Wert trägt ein Record nach handleApprovedTimeCorrection()
    //    weiter das Etikett der Messung, die er gerade überschrieben hat.
    $pdo->exec("ALTER TABLE `{$prefix}records`
                MODIFY `checkin_source`
                ENUM('admin','user_totp','device_auth','auto_checkin','import',
                     'timer','station_pin','exception_request')
                DEFAULT 'admin'");
    $log[] = 'checkin_source um exception_request erweitert';

    // 2. Die Uhrzeit darf fehlen.
    $pdo->exec("ALTER TABLE `{$prefix}records`
                MODIFY `arrival_time` DATETIME NULL");
    $log[] = 'arrival_time darf jetzt NULL sein';

    // 3. Konstruierte Zeiten leeren.
    //    Die Einschränkung auf admin und timer schützt den Kiosk-Stempel, der
    //    zufällig auf die Startminute fällt — er trägt station_pin und wird
    //    nicht angefasst.
    $stmt = $pdo->prepare("UPDATE `{$prefix}records` r
                           JOIN `{$prefix}appointments` a
                             ON a.appointment_id = r.appointment_id
                           SET r.arrival_time = NULL
                           WHERE r.checkin_source IN ('admin','timer')
                             AND r.arrival_time = CONCAT(a.date, ' ', a.start_time)");
    $stmt->execute();
    $log[] = $stmt->rowCount() . ' konstruierte Ankunftszeiten geleert';

    // 4. Entschuldigte trugen durchweg die Startzeit (handleApprovedAbsence()).
    //    Wer gestempelt hat und erst danach auf 'excused' gesetzt wurde, behält
    //    seine echte Zeit — deshalb dieselbe Bedingung wie in Schritt 3.
    $stmt = $pdo->prepare("UPDATE `{$prefix}records` r
                           JOIN `{$prefix}appointments` a
                             ON a.appointment_id = r.appointment_id
                           SET r.arrival_time = NULL
                           WHERE r.status = 'excused'
                             AND r.arrival_time = CONCAT(a.date, ' ', a.start_time)");
    $stmt->execute();
    $log[] = $stmt->rowCount() . ' Ankunftszeiten entschuldigter Eintraege geleert';

    // 5. idx_year stützte allein YEAR(arrival_time) in handleAvailableYears().
    //    Diese Abfrage entfällt mit 1.5.0. idx_member_year bleibt: das
    //    member_id-Präfix ist weiter nützlich. idx_arrival ebenso, es trägt
    //    die Sortierung.
    $idx = $pdo->query("SHOW INDEX FROM `{$prefix}records` WHERE Key_name = '{$prefix}idx_year'");
    if ($idx && $idx->rowCount() > 0) {
        $pdo->exec("ALTER TABLE `{$prefix}records` DROP INDEX `{$prefix}idx_year`");
        $log[] = 'Index idx_year entfernt (stuetzte nur YEAR(arrival_time))';
    }

    return ['log' => $log, 'warnings' => $warnings];
}

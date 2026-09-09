<?php

/**
 * EhrenSache - Migration v1.3.1 → v1.4.0
 *
 * Änderung:
 * - work_sessions.active_member wird von VIRTUAL auf STORED umgestellt
 *
 * Hintergrund: OI-1 in docs/OPEN-ITEMS.md. `ez_work_sessions` verlor nach einer
 * InnoDB-Crash-Recovery zweimal seinen AUTO_INCREMENT-Zähler (2026-09-02 und
 * 2026-09-09). Er las sich danach als 0, und jeder INSERT scheiterte mit
 * "1467 Failed to read auto-increment value from storage engine" — die
 * Zeiterfassung stand still. Im MariaDB-Protokoll steht dazu ausdrücklich
 * "InnoDB: AUTOINC next value generation is disabled": InnoDB findet die
 * AUTOINC-Spalte im eigenen Datenwörterbuch nicht mehr.
 *
 * Betroffen war beide Male ausschließlich diese Tabelle — die einzige des
 * Schemas mit einer indizierten virtuellen Spalte. Virtuelle Spalten werden
 * nicht gespeichert und verschieben damit die Zuordnung zwischen der
 * Tabellendefinition und den tatsächlich abgelegten Spalten, die InnoDB nach
 * einer Recovery neu aufbaut. Eine gespeicherte Spalte liegt im Zeilenformat
 * und verschiebt nichts.
 *
 * Belegt ist der Zusammenhang nicht — der Zustand lässt sich nicht auf
 * Kommando herbeiführen. Die Selbstheilung im Handler (worktimeWithAutoincRepair
 * in private/helpers/worktime.php) bleibt deshalb bestehen; sie behandelt die
 * Folge, diese Migration nimmt dem Fehler die vermutete Ursache.
 *
 * Warum Spalte und Index neu angelegt statt geändert werden: MariaDB 10.4 lehnt
 * die Umstellung einer generierten Spalte von VIRTUAL auf STORED ab —
 * "ERROR 1907 (HY000): This is not yet supported for generated columns". Das
 * Löschen ist unbedenklich, weil der Wert vollständig aus end_time und
 * member_id folgt und beim Neuanlegen aus den vorhandenen Zeilen entsteht.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */

function migrate_1_3_1(PDO $pdo, string $prefix, string $configPath): array
{
    $log  = [];
    $warn = [];

    $tabelle = $prefix . 'work_sessions';
    $index   = $prefix . 'uq_running_session';

    // ---- Ist die Umstellung überhaupt nötig? ----
    $stmt = $pdo->prepare("
        SELECT EXTRA FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'active_member'
    ");
    $stmt->execute([$tabelle]);
    $extra = (string) $stmt->fetchColumn();

    if ($extra === '') {
        $warn[] = "Spalte <code>active_member</code> in <code>{$tabelle}</code> nicht gefunden – "
                . 'die Umstellung wurde übersprungen';
        return ['log' => $log, 'warnings' => $warn];
    }

    if (stripos($extra, 'STORED') !== false) {
        $log[] = 'Spalte <code>active_member</code> ist bereits <code>STORED</code> – unverändert';
        return ['log' => $log, 'warnings' => $warn];
    }

    // ---- Lässt sich der Unique-Index danach wieder anlegen? ----
    // Zwei laufende Sitzungen desselben Mitglieds dürfte es nicht geben, der
    // Index verhindert sie ja. Fehlte er in einem Altbestand, scheiterte das
    // Neuanlegen aber mitten im Umbau und ließe die Tabelle ohne Spalte zurück.
    $doppelte = $pdo->query("
        SELECT member_id, COUNT(*) AS anzahl
        FROM `{$tabelle}`
        WHERE end_time IS NULL
        GROUP BY member_id
        HAVING anzahl > 1
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($doppelte !== []) {
        $nummern = implode(', ', array_column($doppelte, 'member_id'));
        $warn[]  = 'Es gibt Mitglieder mit mehr als einer laufenden Arbeitszeitsitzung '
                 . "(Mitglieds-IDs: {$nummern}). Die Umstellung von <code>active_member</code> "
                 . 'wurde übersprungen, weil der Unique-Index danach nicht wieder angelegt werden '
                 . 'könnte. Bitte die überzähligen Sitzungen beenden und das Update erneut ausführen.';
        return ['log' => $log, 'warnings' => $warn];
    }

    // ---- Umbau ----
    // Die Tabelle wird dabei neu geschrieben. Bei sehr vielen Zeilen dauert das
    // und sperrt so lange; der Hinweis erklärt eine ungewöhnlich lange Pause.
    $anzahl = (int) $pdo->query("SELECT COUNT(*) FROM `{$tabelle}`")->fetchColumn();
    if ($anzahl > 50000) {
        $warn[] = "Die Tabelle <code>{$tabelle}</code> enthält {$anzahl} Zeilen. Der Umbau "
                . 'schreibt sie vollständig neu und kann einige Minuten dauern.';
    }

    $hatIndex = $pdo->query("SHOW INDEX FROM `{$tabelle}` WHERE Key_name = '{$index}'")->fetch();
    if ($hatIndex !== false) {
        $pdo->exec("ALTER TABLE `{$tabelle}` DROP INDEX `{$index}`");
    }

    $pdo->exec("ALTER TABLE `{$tabelle}` DROP COLUMN `active_member`");
    $pdo->exec("
        ALTER TABLE `{$tabelle}`
        ADD COLUMN `active_member` INT AS (IF(end_time IS NULL, member_id, NULL)) STORED,
        ADD UNIQUE KEY `{$index}` (`active_member`)
    ");

    // ---- Gegenprobe ----
    $stmt->execute([$tabelle]);
    $extraDanach = (string) $stmt->fetchColumn();

    if (stripos($extraDanach, 'STORED') === false) {
        $warn[] = "Die Spalte <code>active_member</code> meldet nach dem Umbau "
                . "<code>{$extraDanach}</code> statt <code>STORED GENERATED</code>. "
                . 'Bitte den Zustand der Tabelle prüfen.';
    } else {
        $log[] = 'Spalte <code>active_member</code> von <code>VIRTUAL</code> auf '
               . '<code>STORED</code> umgestellt, Unique-Index neu angelegt '
               . "({$anzahl} Zeilen)";
    }

    return ['log' => $log, 'warnings' => $warn];
}

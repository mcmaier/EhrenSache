<?php

/**
 * EhrenSache - Migration v1.6.1 → v1.7.0
 *
 * Terminrückmeldung: Zusage, Absage, Unsicher (FI-1).
 * Spec: docs/superpowers/specs/2026-09-14-terminrueckmeldung-design.md
 *
 * 1. appointment_types bekommt vier Einstellungen, alle ab Werk aus. Eine
 *    Probe soll nach dem Update genau so funktionieren wie vorher.
 * 2. Neue Tabelle appointment_responses: der aktuelle Stand je Mitglied und
 *    Termin, kein Verlauf (Spec 3.8).
 * 3. Die Fremdschluessel werden einzeln angehaengt. Scheitert einer -- etwa
 *    weil eine alte Installation eine Tabelle noch als MyISAM fuehrt --, bleibt
 *    die Tabelle ohne ihn nutzbar, und der Assistent zeigt eine Warnung.
 * 4. Globale Frist response_deadline_hours = 24. INSERT IGNORE: ein schon
 *    gesetzter Wert bleibt. Der Schluessel muss existieren, sonst blockiert
 *    settings.js das Speichern aller Einstellungen (leeres Zahlenfeld).
 *
 * Idempotent: Jeder Schritt prueft, ob er schon gelaufen ist.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

function migrate_1_6_1(PDO $pdo, string $prefix, string $configPath): array
{
    $log      = [];
    $warnings = [];

    // 1. Einstellungen an der Terminart
    $columns = [
        'responses_enabled'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'responses_names_visible'  => 'TINYINT(1) NOT NULL DEFAULT 0',
        'responses_require_excuse' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'response_deadline_hours'  => 'SMALLINT UNSIGNED NULL DEFAULT NULL',
    ];

    $columnExists = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");

    foreach ($columns as $name => $definition) {
        $columnExists->execute(["{$prefix}appointment_types", $name]);
        if ((int) $columnExists->fetchColumn() > 0) {
            $log[] = "Spalte appointment_types.{$name} bestand bereits — unverändert";
            continue;
        }
        $pdo->exec("ALTER TABLE `{$prefix}appointment_types` ADD COLUMN `{$name}` {$definition}");
        $log[] = "Spalte appointment_types.{$name} angelegt";
    }

    // 2. Tabelle, zunaechst ohne Fremdschluessel
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}appointment_responses` (
          response_id       INT PRIMARY KEY AUTO_INCREMENT,
          appointment_id    INT NOT NULL,
          member_id         INT NOT NULL,
          status            ENUM('yes','no','maybe') NOT NULL,
          comment           VARCHAR(255) DEFAULT NULL,
          exception_id      INT DEFAULT NULL,
          status_changed_at DATETIME NOT NULL,
          updated_at        DATETIME NOT NULL,
          UNIQUE KEY uq_response (appointment_id, member_id),
          KEY idx_member (member_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $log[] = 'Tabelle appointment_responses vorhanden';

    // 3. Fremdschluessel einzeln
    $foreignKeys = [
        "{$prefix}resp_appointment_fk" => [
            'appointment_id', 'appointments', 'appointment_id', 'CASCADE',
            'beim Löschen von Terminen bzw. Mitgliedern bleiben ihre Rückmeldungen stehen',
        ],
        "{$prefix}resp_member_fk" => [
            'member_id', 'members', 'member_id', 'CASCADE',
            'beim Löschen von Terminen bzw. Mitgliedern bleiben ihre Rückmeldungen stehen',
        ],
        "{$prefix}resp_exception_fk" => [
            'exception_id', 'exceptions', 'exception_id', 'SET NULL',
            'nach dem Löschen eines Antrags zeigt exception_id auf einen nicht mehr vorhandenen Antrag',
        ],
    ];

    $fkExists = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?
          AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ");

    foreach ($foreignKeys as $name => [$column, $refTable, $refColumn, $onDelete, $consequence]) {
        $fkExists->execute(["{$prefix}appointment_responses", $name]);
        if ((int) $fkExists->fetchColumn() > 0) {
            $log[] = "Fremdschlüssel {$name} bestand bereits";
            continue;
        }
        $alterSql = "ALTER TABLE `{$prefix}appointment_responses` "
            . "ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`) "
            . "REFERENCES `{$prefix}{$refTable}` (`{$refColumn}`) ON DELETE {$onDelete}";
        try {
            $pdo->exec($alterSql);
            $log[] = "Fremdschlüssel {$name} angelegt";
        } catch (PDOException $e) {
            $warnings[] = "Fremdschlüssel {$name} konnte nicht angelegt werden ("
                . htmlspecialchars($e->getMessage()) . '). Rückmeldungen funktionieren trotzdem; '
                . htmlspecialchars($consequence) . '. Nach Behebung der Ursache manuell nachtragen: <code>'
                . htmlspecialchars($alterSql) . '</code>';
        }
    }

    // 4. Globale Frist
    $insert = $pdo->prepare("
        INSERT IGNORE INTO `{$prefix}system_settings`
            (setting_key, setting_value, setting_type, category, description)
        VALUES ('response_deadline_hours', '24', 'number', 'general',
                'Frist für Terminrückmeldungen in Stunden vor Beginn (0 bis 720)')
    ");
    $insert->execute();
    $log[] = $insert->rowCount() > 0
        ? 'Einstellung response_deadline_hours angelegt (24)'
        : 'Einstellung response_deadline_hours bestand bereits — unverändert';

    return ['log' => $log, 'warnings' => $warnings];
}

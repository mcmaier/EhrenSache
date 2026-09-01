<?php

/**
 * EhrenSache - Migration v1.0.0 → v1.1.x
 *
 * Änderungen:
 * - Tabellen-Prefix auf alle Tabellen/Constraints/View
 * - Neue Tabelle: import_logs
 * - Neue Spalte: appointments.type_id
 * - Neue Indizes auf records, appointments, member_group_assignments
 * - system_settings: ENUM-Wert 'appearance' → 'public', neuer Eintrag privacy_policy_url
 * - View v_users_extended → {prefix}v_users_extended
 * - config.php: $prefix-Feld ergänzen
 */

function migrate_1_0_0(PDO $pdo, string $prefix, string $configPath): array
{
    $log  = [];
    $warn = [];

    // ----------------------------------------------------------------
    // Schritt 1: Tabellen umbenennen (kein Prefix → Prefix)
    // ----------------------------------------------------------------
    $tables = [
        'users', 'members', 'appointments', 'records', 'exceptions',
        'membership_dates', 'member_groups', 'member_group_assignments',
        'appointment_types', 'appointment_type_groups',
        'rate_limits', 'email_verification_tokens', 'password_reset_tokens',
        'system_settings',
    ];

    if (!empty($prefix)) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $dst = $prefix . $table;
            $srcExists = (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->rowCount();
            $dstExists = (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($dst))->rowCount();

            if ($srcExists && !$dstExists) {
                $pdo->exec("RENAME TABLE `{$table}` TO `{$dst}`");
                $log[] = "Tabelle <code>{$table}</code> → <code>{$dst}</code>";
            } elseif ($dstExists) {
                $log[] = "Tabelle <code>{$dst}</code> existiert bereits – übersprungen";
            } else {
                $warn[] = "Tabelle <code>{$table}</code> nicht gefunden – übersprungen";
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    } else {
        $log[] = "Kein Prefix angegeben – Tabellen-Umbenennung übersprungen";
    }

    // ----------------------------------------------------------------
    // Schritt 2: Neue Tabelle import_logs
    // ----------------------------------------------------------------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}import_logs` (
            `log_id`          INT AUTO_INCREMENT PRIMARY KEY,
            `user_id`         INT NOT NULL,
            `import_type`     ENUM('members','records','appointments') NOT NULL,
            `filename`        VARCHAR(255),
            `total_rows`      INT DEFAULT 0,
            `successful_rows` INT DEFAULT 0,
            `failed_rows`     INT DEFAULT 0,
            `errors`          TEXT,
            `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `{$prefix}users`(`user_id`) ON DELETE CASCADE,
            INDEX idx_user_date (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = "Tabelle <code>{$prefix}import_logs</code> erstellt/bestätigt";

    // ----------------------------------------------------------------
    // Schritt 3: Spalte type_id in appointments
    // ----------------------------------------------------------------
    $colExists = (int) $pdo->query("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = " . $pdo->quote("{$prefix}appointments") . "
          AND COLUMN_NAME  = 'type_id'
    ")->fetchColumn();

    if (!$colExists) {
        // appointment_types muss existieren bevor der FK gesetzt wird
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$prefix}appointment_types` (
                `type_id`    INT PRIMARY KEY AUTO_INCREMENT,
                `type_name`  VARCHAR(100) NOT NULL,
                `description` TEXT,
                `is_default`  TINYINT(1) DEFAULT 0,
                `color`       VARCHAR(7) DEFAULT '#3498db',
                `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("ALTER TABLE `{$prefix}appointments`
            ADD COLUMN `type_id` INT DEFAULT NULL AFTER `title`");

        // FK nur anlegen wenn noch nicht vorhanden
        $fkExists = (int) $pdo->query("
            SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME        = " . $pdo->quote("{$prefix}appointments") . "
              AND CONSTRAINT_NAME   = " . $pdo->quote("{$prefix}appointments_type_fk") . "
        ")->fetchColumn();

        if (!$fkExists) {
            $pdo->exec("ALTER TABLE `{$prefix}appointments`
                ADD CONSTRAINT `{$prefix}appointments_type_fk`
                FOREIGN KEY (`type_id`) REFERENCES `{$prefix}appointment_types`(`type_id`)
                ON DELETE SET NULL");
        }
        $log[] = "Spalte <code>type_id</code> zu <code>{$prefix}appointments</code> hinzugefügt";
    } else {
        $log[] = "Spalte <code>type_id</code> existiert bereits – übersprungen";
    }

    // ----------------------------------------------------------------
    // Schritt 4: Neue Indizes
    // ----------------------------------------------------------------
    $newIndexes = [
        "{$prefix}records" => [
            ["{$prefix}idx_member_year", "member_id, arrival_time"],
            ["{$prefix}idx_year",        "arrival_time"],
        ],
        "{$prefix}appointments" => [
            ["{$prefix}idx_year", "date"],
        ],
        "{$prefix}member_group_assignments" => [
            ["{$prefix}idx_member", "member_id"],
            ["{$prefix}idx_group",  "group_id"],
        ],
    ];

    foreach ($newIndexes as $table => $idxList) {
        foreach ($idxList as [$idxName, $cols]) {
            $exists = (int) $pdo->query("
                SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = " . $pdo->quote($table) . "
                  AND INDEX_NAME   = " . $pdo->quote($idxName) . "
            ")->fetchColumn();

            if (!$exists) {
                $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$idxName}` ({$cols})");
                $log[] = "Index <code>{$idxName}</code> auf <code>{$table}</code> hinzugefügt";
            }
        }
    }

    // ----------------------------------------------------------------
    // Schritt 5: system_settings aktualisieren
    // ----------------------------------------------------------------

    // 5a: ENUM 'appearance' → 'public'
    $colInfo = $pdo->query("
        SHOW COLUMNS FROM `{$prefix}system_settings` LIKE 'category'
    ")->fetch(PDO::FETCH_ASSOC);

    if ($colInfo && strpos($colInfo['Type'], "'public'") === false) {
        // Zwischenschritt: 'public' + 'appearance' gleichzeitig erlauben, dann migrieren
        $pdo->exec("ALTER TABLE `{$prefix}system_settings`
            MODIFY `category` ENUM('general','public','appearance','pagination','security') DEFAULT 'general'");
        $pdo->exec("UPDATE `{$prefix}system_settings` SET `category` = 'public' WHERE `category` = 'appearance'");
        $pdo->exec("ALTER TABLE `{$prefix}system_settings`
            MODIFY `category` ENUM('general','public','pagination','security') DEFAULT 'general'");
        $log[] = "system_settings: ENUM-Wert <code>appearance</code> → <code>public</code> migriert";
    }

    // 5b: Bestehende Einstellungen auf 'public' setzen
    $pdo->exec("
        UPDATE `{$prefix}system_settings`
        SET `category` = 'public'
        WHERE `setting_key` IN ('organization_name','primary_color','secondary_color','background_color','organization_logo')
          AND `category` != 'public'
    ");

    // 5c: privacy_policy_url einfügen falls fehlend
    $privExists = (int) $pdo->query("
        SELECT COUNT(*) FROM `{$prefix}system_settings`
        WHERE `setting_key` = 'privacy_policy_url'
    ")->fetchColumn();
    if (!$privExists) {
        $pdo->exec("
            INSERT INTO `{$prefix}system_settings`
                (`setting_key`, `setting_value`, `setting_type`, `category`, `description`)
            VALUES ('privacy_policy_url', '', 'text', 'public', 'Link zur Datenschutzerklärung')
        ");
        $log[] = "Einstellung <code>privacy_policy_url</code> hinzugefügt";
    }

    // ----------------------------------------------------------------
    // Schritt 6: View neu erstellen mit Prefix
    // View-Definition direkt aus ehrensache_db.sql lesen, damit er
    // immer aktuell ist und keine Felder fehlen.
    // ----------------------------------------------------------------
    $pdo->exec("DROP VIEW IF EXISTS `v_users_extended`");
    $pdo->exec("DROP VIEW IF EXISTS `{$prefix}v_users_extended`");

    $sqlFile = __DIR__ . '/../setup/ehrensache_db.sql';
    if (!file_exists($sqlFile)) {
        $warn[] = "ehrensache_db.sql nicht gefunden – View konnte nicht aus Schema gelesen werden";
    } else {
        $schema = file_get_contents($sqlFile);
        // View-Definition extrahieren
        if (preg_match('/(CREATE OR REPLACE VIEW\s+`\{PREFIX\}v_users_extended`.*?;)\s*$/ms', $schema, $m)) {
            $viewSql = str_replace('{PREFIX}', $prefix, $m[1]);
            $pdo->exec($viewSql);
            $log[] = "View <code>{$prefix}v_users_extended</code> aus Schema erstellt";
        } else {
            $warn[] = "View-Definition nicht in ehrensache_db.sql gefunden – bitte manuell prüfen";
        }
    }

    // ----------------------------------------------------------------
    // Schritt 7: config.php aktualisieren
    //   a) $prefix-Feld ergänzen (falls fehlend)
    //   b) table()-Methode ergänzen (v1.0.0 hat sie nicht)
    // ----------------------------------------------------------------
    $configContent = file_get_contents($configPath);
    $configChanged = false;

    if ($configContent !== false) {
        // a) $prefix-Feld
        if (strpos($configContent, '$prefix') === false) {
            $configContent = preg_replace(
                '/(private\s+\$password\s*=\s*"[^"]*";)/m',
                '$1' . "\n    private \$prefix = " . json_encode($prefix) . ";",
                $configContent
            );
            $configChanged = true;
            $log[] = "config.php: Feld <code>\$prefix</code> ergänzt";
        } else {
            $log[] = "config.php: <code>\$prefix</code> bereits vorhanden – übersprungen";
        }

        // b) table()-Methode
        if (strpos($configContent, 'function table(') === false) {
            // Suche das Ende der Database-Klasse: schließendes } nach getConnection()
            $tableMethod = "\n\n    // Helper-Methode für Tabellennamen\n    public function table(\$tableName) {\n        return \$this->prefix . \$tableName;\n    }";
            // Einfügen direkt vor dem schließenden } der getConnection()-Methode
            // Muster: letzte } in der Klasse vor "// Helper" oder EOF
            $configContent = preg_replace(
                '/(function getConnection\(\).*?\})\s*\n(\})/s',
                '$1' . $tableMethod . "\n" . '$2',
                $configContent
            );
            $configChanged = true;
            $log[] = "config.php: Methode <code>table()</code> ergänzt";
        } else {
            $log[] = "config.php: <code>table()</code> bereits vorhanden – übersprungen";
        }

        if ($configChanged) {
            file_put_contents($configPath, $configContent);
        }
    } else {
        $warn[] = "config.php konnte nicht gelesen werden – bitte manuell aktualisieren";
    }

    // Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php),
    // damit ihn nicht jede Migration einzeln setzen muss.

    return ['log' => $log, 'warnings' => $warn];
}

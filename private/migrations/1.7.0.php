<?php

/**
 * EhrenSache - Migration v1.7.0 → v1.8.0
 *
 * Untergruppen zur Gliederung von Listen (FI-14 Variante B).
 * Spec: docs/superpowers/specs/2026-09-16-untergruppen-gliederung-design.md
 *
 * 1. member_groups bekommt is_subgroup und sort_order. Beide ab Werk so
 *    gesetzt, dass sich nichts ändert: keine Gruppe gliedert, alle sortieren
 *    alphabetisch.
 * 2. Einstellung subgroup_label mit der Vorgabe "Untergruppe". INSERT IGNORE,
 *    ein vorhandener Wert bleibt.
 *
 * Idempotent: Jeder Schritt prüft, ob er schon gelaufen ist.
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

function migrate_1_7_0(PDO $pdo, string $prefix, string $configPath): array
{
    $log      = [];
    $warnings = [];

    $columns = [
        'is_subgroup' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'sort_order'  => 'INT NOT NULL DEFAULT 0',
    ];

    $columnExists = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");

    foreach ($columns as $name => $definition) {
        $columnExists->execute(["{$prefix}member_groups", $name]);
        if ((int) $columnExists->fetchColumn() > 0) {
            $log[] = "Spalte member_groups.{$name} bestand bereits — unverändert";
            continue;
        }
        $pdo->exec("ALTER TABLE `{$prefix}member_groups` ADD COLUMN `{$name}` {$definition}");
        $log[] = "Spalte member_groups.{$name} angelegt";
    }

    // Der Schlüssel muss existieren, sonst zeigt die Oberfläche ein leeres Feld
    // und speichert beim nächsten Sichern einen leeren Wert.
    $insert = $pdo->prepare("INSERT IGNORE INTO `{$prefix}system_settings` (setting_key, setting_value)
                             VALUES ('subgroup_label', 'Untergruppe')");
    $insert->execute();
    $log[] = $insert->rowCount() > 0
        ? 'Einstellung subgroup_label angelegt (Vorgabe: Untergruppe)'
        : 'Einstellung subgroup_label bestand bereits — unverändert';

    return ['log' => $log, 'warnings' => $warnings];
}

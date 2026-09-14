<?php

/**
 * EhrenSache - Migration v1.5.0 → v1.5.1
 *
 * Einstellungen für Pünktlichkeit und Zuverlässigkeit (OI-51, Teil 2).
 *
 * Beide Kennzahlen starten ausgeschaltet. Eine personenbezogene
 * Verhaltenskennzahl darf nicht dadurch entstehen, dass jemand ein Update
 * einspielt — das Einschalten ist die Entscheidung des Verantwortlichen
 * (DATENSCHUTZ.md, Abschnitt 11).
 *
 * Die Schlüssel werden trotzdem angelegt und nicht erst beim ersten Speichern:
 * settings.js wertet ein leeres Zahlenfeld als ungültig und blockiert dann das
 * Speichern sämtlicher Einstellungen.
 *
 * INSERT IGNORE: Wer die Schlüssel schon gesetzt hat, behält seine Werte.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */

function migrate_1_5_0(PDO $pdo, string $prefix, string $configPath): array
{
    $insert = $pdo->prepare("
        INSERT IGNORE INTO `{$prefix}system_settings`
            (setting_key, setting_value, setting_type, category, description)
        VALUES (?, ?, ?, 'general', ?)
    ");

    $settings = [
        ['punctuality_enabled',       '0', 'boolean', 'Pünktlichkeitskennzahl berechnen und anzeigen'],
        ['reliability_enabled',       '0', 'boolean', 'Zuverlässigkeitskennzahl berechnen und anzeigen'],
        ['punctuality_grace_minutes', '0', 'number',  'Karenz in Minuten relativ zum Terminbeginn (-60 bis 60)'],
    ];

    $log = [];
    foreach ($settings as [$key, $value, $type, $description]) {
        $insert->execute([$key, $value, $type, $description]);
        $log[] = $insert->rowCount() > 0
            ? "Einstellung {$key} angelegt ({$value})"
            : "Einstellung {$key} bestand bereits — unverändert";
    }

    return ['log' => $log, 'warnings' => []];
}

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
 * EhrenSache - Migration v1.8.0 → v1.9.0
 *
 * Farbschwellen der Anwesenheitsquote als Systemeinstellung (OI-55).
 * Spec: docs/superpowers/specs/2026-09-16-einstellungen-untertabs-design.md
 *
 * Drei Zahlen mit den bisher fest verdrahteten Werten als Vorgabe: ab 40
 * Prozent Orange, ab 60 Gelb, ab 80 Grün. Wer nichts umstellt, sieht nach dem
 * Update dieselben Farben wie vorher.
 *
 * Kategorie 'general': Die Schwellen erreichen das Frontend über den
 * Statistik-Payload (rate_bands), nicht über den öffentlichen
 * appearance-Endpunkt — sie gehören nicht zum Branding.
 *
 * Keine Schemaänderung. Idempotent: INSERT IGNORE lässt einen bereits
 * gesetzten Wert unverändert.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

function migrate_1_8_0(PDO $pdo, string $prefix, string $configPath): array
{
    $log      = [];
    $warnings = [];

    $vorgaben = [
        'rate_threshold_mid'  => ['40', 'Ab dieser Quote Orange statt Rot'],
        'rate_threshold_fair' => ['60', 'Ab dieser Quote Gelb'],
        'rate_threshold_good' => ['80', 'Ab dieser Quote Grün'],
    ];

    $insert = $pdo->prepare("INSERT IGNORE INTO `{$prefix}system_settings`
                             (setting_key, setting_value, setting_type, category, description)
                             VALUES (?, ?, 'number', 'general', ?)");

    foreach ($vorgaben as $key => [$wert, $beschreibung]) {
        $insert->execute([$key, $wert, $beschreibung]);

        $log[] = $insert->rowCount() > 0
            ? "Einstellung {$key} angelegt (Vorgabe: {$wert})"
            : "Einstellung {$key} bestand bereits — unverändert";
    }

    // Eine verdrehte Reihenfolge wäre kein Fehler der Migration, sondern ein
    // Bestand aus früherer Handarbeit an der Datenbank. Der lesende Helfer
    // sortiert defensiv; hier genügt ein Hinweis im Protokoll.
    $stmt = $pdo->prepare("SELECT setting_key, setting_value
                           FROM `{$prefix}system_settings`
                           WHERE setting_key IN ('rate_threshold_mid', 'rate_threshold_fair', 'rate_threshold_good')");
    $stmt->execute();
    $werte = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $werte[$row['setting_key']] = (int) $row['setting_value'];
    }

    if (count($werte) === 3
        && !($werte['rate_threshold_mid'] < $werte['rate_threshold_fair']
             && $werte['rate_threshold_fair'] < $werte['rate_threshold_good'])) {
        $warnings[] = 'Die Farbschwellen stehen nicht aufsteigend ('
            . implode(', ', [$werte['rate_threshold_mid'], $werte['rate_threshold_fair'], $werte['rate_threshold_good']])
            . '). Die Statistik sortiert sie beim Lesen; in den Einstellungen lassen sie sich richtigstellen.';
    }

    return ['log' => $log, 'warnings' => $warnings];
}

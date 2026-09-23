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
 * EhrenSache - Migration v1.5.1 → v1.6.0
 *
 * Schreibt private/config/config.php von der alten Klassenform auf reine Daten
 * um. Keine Schemaänderung.
 *
 * Der Programmcode, der bisher in dieser Datei stand, liegt ab 1.6.0 in
 * private/helpers/database.php und private/helpers/bootstrap.php und wird bei
 * jedem Update mit ausgetauscht.
 *
 * Kann die Datei nicht geschrieben werden, läuft die Installation trotzdem
 * weiter: Der Bootstrap liest auch die alte Form, und das Dashboard weist den
 * Admin darauf hin.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/../helpers/config_reader.php';

function migrate_1_5_1(PDO $pdo, string $prefix, string $configPath): array
{
    $log  = [];
    $warn = [];

    $gelesen = readConfigFile($configPath);

    if ($gelesen['format'] === CONFIG_FORMAT_ARRAY) {
        $log[] = 'config.php liegt bereits in der Datenform vor – übersprungen';
        return ['log' => $log, 'warnings' => $warn];
    }

    if ($gelesen['format'] !== CONFIG_FORMAT_LEGACY) {
        $warn[] = 'config.php konnte nicht gelesen werden (Zustand: '
            . htmlspecialchars($gelesen['format']) . ') – bitte von Hand auf die neue Form '
            . 'bringen, Vorlage: <code>private/config/config_example.php</code>';
        return ['log' => $log, 'warnings' => $warn];
    }

    $neuerInhalt = renderConfigFile($gelesen);

    if (!is_writable($configPath)) {
        $warn[] = 'config.php ist nicht beschreibbar – die Anwendung läuft mit der alten Form '
            . 'weiter. Bitte Schreibrecht setzen und das Update erneut ausführen, oder den '
            . 'folgenden Inhalt von Hand eintragen:<pre>' . htmlspecialchars($neuerInhalt) . '</pre>';
        return ['log' => $log, 'warnings' => $warn];
    }

    // Sicherung, bevor irgendetwas geschrieben wird.
    $sicherung = $configPath . '.bak-1.5.1';
    if (!copy($configPath, $sicherung)) {
        $warn[] = 'Sicherung von config.php fehlgeschlagen – Datei unverändert gelassen';
        return ['log' => $log, 'warnings' => $warn];
    }
    $log[] = 'Sicherung angelegt: <code>' . htmlspecialchars(basename($sicherung)) . '</code>';

    file_put_contents($configPath, $neuerInhalt);

    // Gegenlesen: Kommt die Datei mit denselben Werten zurück? Sonst Sicherung zurück.
    $kontrolle = readConfigFile($configPath);
    $gleich = $kontrolle['format'] === CONFIG_FORMAT_ARRAY
        && $kontrolle['db'] === $gelesen['db']
        && $kontrolle['base_url'] === $gelesen['base_url']
        && $kontrolle['demo_mode'] === $gelesen['demo_mode'];

    if (!$gleich) {
        copy($sicherung, $configPath);
        $warn[] = 'Die neu geschriebene config.php ergab andere Werte als die alte – Sicherung '
            . 'zurückgespielt, die Anwendung läuft mit der alten Form weiter';
        return ['log' => $log, 'warnings' => $warn];
    }

    $log[] = 'config.php auf reine Daten umgestellt';
    if ($gelesen['base_url'] !== null) {
        $log[] = 'BASE_URL übernommen: <code>' . htmlspecialchars($gelesen['base_url']) . '</code>';
    }
    if ($gelesen['demo_mode'] !== null) {
        $log[] = 'DEMO_MODE übernommen: <code>'
            . htmlspecialchars(var_export($gelesen['demo_mode'], true)) . '</code>';
    }
    $log[] = 'Die Sicherung kann nach einer erfolgreichen Anmeldung gelöscht werden';

    return ['log' => $log, 'warnings' => $warn];
}

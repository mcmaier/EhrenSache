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
 * Anforderungen einer Version aus version.json ("requires"): PHP-Version und
 * PHP-Erweiterungen der Anwendung. Die einzige Quelle für Installer,
 * Update-Assistent und Updater.
 *
 * Bewusst ohne weitere Abhängigkeiten, damit jeder dieser drei die Datei laden
 * kann. Teil des Update-Pfads: nur Syntax bis PHP 8.0, siehe
 * tests/suites/update_path_syntax.php.
 */
declare(strict_types=1);

/**
 * @return array{php:string, extensions:list<string>}
 *
 * Fehlt die Angabe oder ist sie unbrauchbar, gelten keine zusätzlichen
 * Anforderungen (php 0.0.0). Pakete vor 1.6.1 kennen requires nicht.
 */
function requirementsRead(string $root): array
{
    $daten = json_decode((string) @file_get_contents($root . '/version.json'), true);
    $req   = is_array($daten) && is_array($daten['requires'] ?? null) ? $daten['requires'] : [];

    $php = $req['php'] ?? null;
    $php = is_string($php) && preg_match('/^\d+\.\d+(\.\d+)?$/', $php) ? $php : '0.0.0';

    $erweiterungen = [];
    foreach ((array) ($req['extensions'] ?? []) as $name) {
        if (is_string($name) && $name !== '') {
            $erweiterungen[] = $name;
        }
    }

    return ['php' => $php, 'extensions' => $erweiterungen];
}

/**
 * Prüfpunkte als Bezeichnung => erfüllt, in der Form, die Installer und
 * Update-Assistent anzeigen. PHP-Version und Erweiterungsprüfung sind
 * austauschbar, damit Tests beliebige Server nachstellen können.
 *
 * @return array<string, bool>
 */
function requirementsChecks(array $requires, ?string $phpVersion = null, ?callable $extensionLoaded = null): array
{
    $phpVersion      = $phpVersion ?? PHP_VERSION;
    $extensionLoaded = $extensionLoaded ?? 'extension_loaded';

    $checks = [];
    if ($requires['php'] !== '0.0.0') {
        $checks["PHP >= {$requires['php']} (läuft: {$phpVersion})"] = version_compare($phpVersion, $requires['php'], '>=');
    }
    foreach ($requires['extensions'] as $name) {
        $checks["PHP-Erweiterung {$name}"] = (bool) $extensionLoaded($name);
    }
    return $checks;
}

/** Die nicht erfüllten Anforderungen als Meldungen; leer = alles erfüllt. */
function requirementsErrors(array $requires, ?string $phpVersion = null, ?callable $extensionLoaded = null): array
{
    $fehler = [];
    foreach (requirementsChecks($requires, $phpVersion, $extensionLoaded) as $bezeichnung => $erfuellt) {
        if (!$erfuellt) {
            $fehler[] = "Nicht erfüllt: {$bezeichnung}";
        }
    }
    return $fehler;
}

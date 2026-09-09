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
 * Schreibt den Demo-Bestand in die Datenbank.
 *
 * Aufruf:
 *   php private/demo/seed.php [--yes] [--seed=<int>] [--reference-date=Y-m-d]
 *                             [--password=<klartext>] [--quiet]
 *
 * ACHTUNG: Das Skript LEERT die Fachtabellen. Ohne --yes fragt es vorher nach.
 *
 * Was der Plan liefert, wird hier nur geschrieben. Alles Gewürfelte steht in
 * plan.php; hier entstehen ausschließlich die Geheimnisse: Hashes für PIN und
 * Passwort, Token und TOTP-Secret für die Geräte.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/plan.php';

const DEMO_MIN_SCHEMA = '1.3.0';

/**
 * Reihenfolge beim Leeren: Kinder vor Eltern.
 * system_settings fehlt bewusst — dort wird aktualisiert, nicht gelöscht.
 */
const DEMO_TABLES = [
    'work_session_log',
    'work_sessions',
    'exceptions',
    'records',
    'appointment_type_groups',
    'appointments',
    'appointment_types',
    'activity_type_appointment_types',
    'activity_type_groups',
    'activity_types',
    'member_group_assignments',
    'membership_dates',
    'members',
    'member_groups',
    'users',
    'rate_limits',
    'email_verification_tokens',
    'password_reset_tokens',
    'import_logs',
];

/**
 * Kommandozeile auswerten.
 *
 * Wirft bei fehlerhafter Eingabe, statt den Prozess zu beenden: So lässt sich
 * die Auswertung prüfen, ohne einen Unterprozess zu starten. Der Ablauf am Ende
 * der Datei fängt die Ausnahme und beendet mit Code 1.
 *
 * @param array<int, string> $argv
 * @return array{yes: bool, seed: int, reference_date: string, password: string, quiet: bool}
 */
function parseOptions(array $argv): array
{
    $options = [
        'yes'            => false,
        'seed'           => 20260908,
        'reference_date' => date('Y-m-d'),
        'password'       => 'demo2025',
        'quiet'          => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--yes') {
            $options['yes'] = true;
        } elseif ($arg === '--quiet') {
            $options['quiet'] = true;
        } elseif (str_starts_with($arg, '--seed=')) {
            $options['seed'] = (int) substr($arg, 7);
        } elseif (str_starts_with($arg, '--reference-date=')) {
            $date = substr($arg, 17);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
                throw new InvalidArgumentException("Ungültiger Stichtag: {$date} (erwartet YYYY-MM-DD)");
            }
            $options['reference_date'] = $date;
        } elseif (str_starts_with($arg, '--password=')) {
            $options['password'] = substr($arg, 11);
        } else {
            throw new InvalidArgumentException("Unbekannte Option: {$arg}");
        }
    }

    return $options;
}

/** Ein Aufruf über den Webserver würde die Datenbank eines Besuchers leeren. */
function requireCli(): void
{
    if (php_sapi_name() !== 'cli') {
        http_response_code(403);
        exit('Nur über die Kommandozeile aufrufbar.');
    }
}

/**
 * Prüft den Schemastand.
 *
 * Verlangt wird 1.3.0, nicht 1.3.1: Die Migration 1.3.0.php ändert das Schema
 * nicht, sie schließt nur die Kette bis zur Version aus version.json. Eine
 * korrekt aktualisierte 1.3.1-Installation trägt daher 1.3.0 als letzten
 * Stempel — eine Prüfung auf 1.3.1 würde sie fälschlich abweisen.
 */
function assertSchema(PDO $db, string $prefix): void
{
    $stmt = $db->query("SELECT version FROM {$prefix}schema_version");
    $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

    if ($rows === []) {
        throw new RuntimeException('Kein Eintrag in schema_version — ist die Installation abgeschlossen?');
    }

    usort($rows, 'version_compare');
    $current = (string) end($rows);

    if (version_compare($current, DEMO_MIN_SCHEMA, '<')) {
        throw new RuntimeException(
            "Schemastand {$current} ist zu alt (erwartet mindestens " . DEMO_MIN_SCHEMA . "). "
            . 'Bitte zuerst den Update-Assistenten unter /update ausführen.'
        );
    }
}

/**
 * Nennt das Ziel und verlangt eine Bestätigung.
 *
 * Ohne diese Anzeige wäre nicht erkennbar, welche Datenbank getroffen wird —
 * und das Skript löscht.
 */
function confirmTarget(PDO $db, string $prefix, string $dbName, bool $yes): void
{
    echo "Ziel:      Datenbank '{$dbName}', Präfix '{$prefix}'\n";
    echo "Folgende Tabellen werden GELEERT:\n";

    foreach (DEMO_TABLES as $table) {
        $stmt  = $db->query("SELECT COUNT(*) FROM {$prefix}{$table}");
        $count = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
        printf("  %-32s %6d Zeile(n)\n", $table, $count);
    }

    if ($yes) {
        echo "\n--yes gesetzt, keine Rückfrage.\n";

        return;
    }

    echo "\nZum Fortfahren LOESCHEN eingeben: ";
    $answer = trim((string) fgets(STDIN));

    if ($answer !== 'LOESCHEN') {
        echo "Abgebrochen.\n";
        exit(0);
    }
}

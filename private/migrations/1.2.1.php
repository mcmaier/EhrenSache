<?php

/**
 * EhrenSache - Migration v1.2.1 → v1.2.2
 *
 * Änderungen am Schema: keine.
 *
 * 1.2.2 bringt den Zeitraumfilter der Arbeitszeitberichte und die Druckansicht.
 * Beides kommt ohne neue Spalten, Tabellen oder Umformung aus — der
 * Zeitraumvergleich ersetzt lediglich YEAR(start_time) durch eine Bereichs-
 * bedingung auf derselben Spalte.
 *
 * Dieser Schritt existiert trotzdem, und er ist nicht überflüssig:
 * resolveMigrationChain() in private/helpers/migrations.php läuft die Kette
 * über from/to ab und wirft "Keine Migration ab Version 1.2.1 vorhanden",
 * sobald ein Glied fehlt. Ohne diese Datei bräche der Update-Wizard in jeder
 * bestehenden Installation — bei einem Release, das an der Datenbank gar
 * nichts ändert.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */

function migrate_1_2_1(PDO $pdo, string $prefix, string $configPath): array
{
    return [
        'log' => [
            'Keine Schemaänderung erforderlich – 1.2.2 betrifft nur Auswertung und Ausgabe',
        ],
        'warnings' => [],
    ];
}

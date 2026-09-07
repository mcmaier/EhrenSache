<?php

/**
 * EhrenSache - Migration v1.3.0 → v1.3.1
 *
 * Keine Schemaänderung. 1.3.1 behebt ausschließlich Fehler im Frontend:
 * - Mitgliedsfilter der Zeiterfassung war beim ersten Öffnen leer
 * - Check-in-PWA zeigte Verwaltern fremde Arbeitszeiten im Verlauf
 * - Check-in-PWA behielt nach der Abmeldung die Ansichten des vorigen Mitglieds
 *
 * Der Schritt existiert trotzdem: Die Kette in manifest.php muss lückenlos bis
 * zur Version aus version.json führen, sonst findet der Update-Wizard für eine
 * 1.3.0-Installation keinen Weg auf den aktuellen Stand.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */

function migrate_1_3_0(PDO $pdo, string $prefix, string $configPath): array
{
    return [
        'log' => [
            'Keine Datenbankänderung nötig – 1.3.1 betrifft nur das Frontend',
        ],
        'warnings' => [],
    ];
}

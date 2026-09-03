<?php

/**
 * EhrenSache - Migration v1.2.2 → v1.2.3
 *
 * Änderungen:
 * - Neue Tabelle: activity_type_appointment_types (Tätigkeitsart ↔ Terminart)
 *
 * Die Tabelle bleibt bewusst LEER. Anders als bei activity_type_groups, wo
 * eine fehlende Zuordnung „niemand" bedeutet und die Migration deshalb jede
 * Tätigkeitsart jeder Gruppe zuordnen musste, heißt hier keine Zuordnung
 * „keine Einschränkung": Eine Tätigkeitsart ohne verknüpfte Terminart bietet
 * weiterhin alle Termine an.
 *
 * Der Unterschied liegt im Schaden der jeweils falschen Vorbelegung. Bei
 * Gruppen wäre „leer = alle" eine stille Rechteausweitung. Bei Terminarten
 * wäre „leer = keine" eine Selbstblockade: Nach dem Update könnte niemand mehr
 * einen Termin zuordnen, bis ein Administrator jede Tätigkeitsart durchpflegt.
 *
 * Deshalb schreibt diese Migration keine einzige Datenzeile. Der Filter wirkt
 * genau dort, wo ihn jemand bewusst setzt; wer ihn nie anfasst, merkt nicht,
 * dass es ihn gibt, und verliert nichts.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */

function migrate_1_2_2(PDO $pdo, string $prefix, string $configPath): array
{
    $log  = [];
    $warn = [];

    $exists = static function (string $table) use ($pdo): bool {
        return (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->rowCount();
    };

    $had = $exists($prefix . 'activity_type_appointment_types');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}activity_type_appointment_types` (
            activity_id INT NOT NULL,
            type_id     INT NOT NULL,
            PRIMARY KEY (activity_id, type_id),
            CONSTRAINT `{$prefix}atat_activity_fk` FOREIGN KEY (activity_id)
                REFERENCES `{$prefix}activity_types`(activity_id) ON DELETE CASCADE,
            CONSTRAINT `{$prefix}atat_type_fk` FOREIGN KEY (type_id)
                REFERENCES `{$prefix}appointment_types`(type_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $log[] = $had
        ? "Tabelle <code>{$prefix}activity_type_appointment_types</code> existiert bereits – übersprungen"
        : "Tabelle <code>{$prefix}activity_type_appointment_types</code> angelegt";

    $log[] = 'Keine Vorbelegung: Eine Tätigkeitsart ohne verknüpfte Terminart '
           . 'bietet weiterhin alle Termine an';

    // Anwesenheitseinträge, die der Timer-Start bis 1.2.2 miterzeugt hat,
    // bleiben unangetastet. Ein Teil davon ist korrekt — das Mitglied war
    // tatsächlich beim Termin —, und welcher, lässt sich nachträglich nicht
    // entscheiden. Der Enum-Wert 'timer' bleibt deshalb ebenfalls bestehen.
    $stmt = $pdo->query("SELECT COUNT(*) FROM `{$prefix}records`
                         WHERE checkin_source = 'timer'");
    $legacy = (int) $stmt->fetchColumn();

    if ($legacy > 0) {
        $warn[] = "{$legacy} Anwesenheitseintrag/-einträge stammen aus der bis 1.2.2 "
                . 'gekoppelten Zeiterfassung (<code>checkin_source = timer</code>). Sie '
                . 'bleiben erhalten und zählen weiterhin in Anwesenheit und Pünktlichkeit. '
                . 'Eine Bereinigung wäre ein nicht umkehrbarer Eingriff in erfasste Daten '
                . 'und unterbleibt deshalb.';
    }

    return ['log' => $log, 'warnings' => $warn];
}

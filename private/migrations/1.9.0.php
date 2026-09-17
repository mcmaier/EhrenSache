<?php

/**
 * EhrenSache - Migration v1.9.0 → v1.9.1
 *
 * Entfernt die Spalte `expires_at` aus rate_limits (OI-40).
 *
 * Die Spalte stand seit jeher als `DATETIME NOT NULL` ohne Vorgabewert im
 * Schema, wurde aber nie geschrieben: Der Limiter rechnet seine Fenster
 * ausschließlich über `created_at` (private/helpers/rate_limiter.php). Jede
 * Zeile trug deshalb das ungültige Nulldatum 0000-00-00 00:00:00.
 *
 * Folgenlos bleibt das nur bei nachsichtigem SQL-Modus. Läuft die Datenbank
 * unter STRICT_TRANS_TABLES — auf manchem Hosting die Voreinstellung —,
 * scheitert jeder INSERT des Limiters. Aufgefangen wird das vom fail-open in
 * checkDatabase(), das heißt: Der Schutz gegen Brute Force und die Sperre der
 * Stations-PIN sind dann dauerhaft und lautlos aus, ohne dass im Betrieb
 * etwas darauf hinweist.
 *
 * Die Spalte wird entfernt statt gefüllt, weil sie nirgends gelesen wird. Der
 * Index idx_expires hängt allein an ihr und fällt mit ihr weg; MariaDB räumt
 * ihn beim DROP COLUMN selbst ab — hier wird er trotzdem zuerst gelöst, damit
 * das Protokoll beide Schritte ausweist und eine von Hand veränderte
 * Datenbank (Index über mehrere Spalten) nicht stillschweigend anders endet.
 *
 * Idempotent: Fehlt die Spalte bereits, passiert nichts.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

function migrate_1_9_0(PDO $pdo, string $prefix, string $configPath): array
{
    $log      = [];
    $warnings = [];

    $table = $prefix . 'rate_limits';

    $tableExists = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $tableExists->execute([$table]);

    if ((int) $tableExists->fetchColumn() === 0) {
        $warnings[] = "Tabelle {$table} nicht gefunden — Schritt übersprungen. "
            . 'Das Rate Limiting arbeitet dann ohne Datenbank; die Tabelle legt '
            . 'ein erneutes Einspielen von private/setup/ehrensache_db.sql an.';
        return ['log' => $log, 'warnings' => $warnings];
    }

    $columnExists = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'expires_at'
    ");
    $columnExists->execute([$table]);

    if ((int) $columnExists->fetchColumn() === 0) {
        $log[] = 'Spalte rate_limits.expires_at bestand nicht mehr — unverändert';
        return ['log' => $log, 'warnings' => $warnings];
    }

    // Index zuerst: Trägt er ausschließlich expires_at, ist er nach dem DROP
    // COLUMN ohnehin weg. Trägt er mehr — Handarbeit an der Datenbank —, wäre
    // das Ergebnis sonst ein anderes, als dieses Protokoll behauptet.
    $indexColumns = $pdo->prepare("
        SELECT COLUMN_NAME FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_expires'
    ");
    $indexColumns->execute([$table]);
    $spalten = $indexColumns->fetchAll(PDO::FETCH_COLUMN);

    if (count($spalten) === 1 && $spalten[0] === 'expires_at') {
        $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `idx_expires`");
        $log[] = 'Index rate_limits.idx_expires entfernt';
    } elseif (count($spalten) > 1) {
        $warnings[] = 'Der Index idx_expires trägt neben expires_at weitere Spalten ('
            . implode(', ', $spalten) . '). Er wurde nicht angefasst; MariaDB entfernt '
            . 'beim Löschen der Spalte nur deren Anteil.';
    }

    $pdo->exec("ALTER TABLE `{$table}` DROP COLUMN `expires_at`");
    $log[] = 'Spalte rate_limits.expires_at entfernt — der Limiter rechnet über created_at';

    // Ersatz für den entfallenen Index, damit das Aufräumen alter Zeilen
    // (DELETE … WHERE created_at < ? AND action = ?) nicht über die ganze
    // Tabelle laufen muss. idx_expires half dabei nie: Er lag auf einer
    // Spalte, die nur Nulldaten enthielt.
    $indexExists = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_created'
    ");
    $indexExists->execute([$table]);

    if ((int) $indexExists->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `idx_created` (created_at)");
        $log[] = 'Index rate_limits.idx_created angelegt';
    } else {
        $log[] = 'Index rate_limits.idx_created bestand bereits — unverändert';
    }

    return ['log' => $log, 'warnings' => $warnings];
}

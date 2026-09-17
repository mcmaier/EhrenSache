<?php
/**
 * EhrenSache - Verifikation des Rate Limiting unter strengem SQL-Modus (OI-40)
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 *
 * Nicht Teil von tests/run.php, weil die Datei den SQL-Modus der Verbindung
 * umstellt und dafür eine eigene Verbindung mit DDL-Rechten braucht.
 *
 * Der Fehler, um den es geht, ist lokal unsichtbar: `rate_limits.expires_at`
 * stand als DATETIME NOT NULL ohne Vorgabewert im Schema, wurde aber nie
 * geschrieben. Unter dem nachsichtigen Voreinstellungsmodus von XAMPP trägt
 * MariaDB stillschweigend das Nulldatum ein und alles läuft. Unter
 * STRICT_TRANS_TABLES -- bei manchem Hosting die Voreinstellung -- scheitert
 * derselbe INSERT.
 *
 * Und dann fällt es niemandem auf: checkDatabase() fängt jede PDOException
 * und gibt true zurück (fail-open, OI-28). Der Limiter zählt also nicht mehr,
 * sperrt nicht mehr und meldet trotzdem "in Ordnung" -- Brute-Force-Schutz und
 * die Sperre der Stations-PIN sind aus, ohne einen anderen Hinweis als eine
 * Zeile im Fehlerprotokoll von PHP.
 *
 * Geprüft wird deshalb nicht, ob der INSERT gelingt, sondern ob der Limiter
 * unter strengem Modus überhaupt noch sperrt. Gegen den Stand vor der
 * Migration schlägt der letzte Fall fehl: Er lässt beliebig viele Versuche zu.
 *
 * Aufruf:
 *   php tests/db/verify_rate_limiter_strict.php "mysql:host=127.0.0.1;port=3306;dbname=ehrensache" tester test123 ez_
 */
declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Aufruf: php tests/db/verify_rate_limiter_strict.php <dsn-mit-dbname> <user> <password> <prefix>\n");
    fwrite(STDERR, "Beispiel: php tests/db/verify_rate_limiter_strict.php \"mysql:host=127.0.0.1;port=3306;dbname=ehrensache\" tester test123 ez_\n");
    exit(2);
}

[$_, $dsn, $dbUser, $dbPass, $prefix] = $argv;

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../../private/helpers/database.php';
require_once __DIR__ . '/../../private/helpers/rate_limiter.php';

$pdo = new PDO($dsn . ';charset=utf8mb4', $dbUser, $dbPass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Der eigentliche Prüfstand: die Verbindung verhält sich wie striktes Hosting.
$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE'");

$modus = $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
echo "SQL-Modus der Verbindung: {$modus}\n\n";

$table = $prefix . 'rate_limits';

// Der Limiter liest sein Praefix ueber Database::table(). Die Verbindung
// bleibt die eigene von oben -- Database::getConnection() wuerde eine neue
// ohne den strengen Modus aufbauen und den Prueffall damit entwerten.
$database = new Database(['prefix' => $prefix]);

test('rate_limits trägt keine Spalte expires_at mehr', function () use ($pdo, $table) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'expires_at'
    ");
    $stmt->execute([$table]);

    assertSame(
        0,
        (int) $stmt->fetchColumn(),
        'Die Spalte steht noch in der Tabelle — Migration 1.9.0 gelaufen?'
    );
});

test('Keine Spalte verlangt einen Wert, den der INSERT nicht liefert', function () use ($pdo, $table) {
    // Die Klasse des Fehlers, nicht nur sein Einzelfall: Jede Spalte, die
    // NOT NULL ohne Vorgabewert ist, muss vom INSERT des Limiters bedient
    // werden. Bedient werden identifier, action und created_at.
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
          AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL
          AND EXTRA NOT LIKE '%auto_increment%'
    ");
    $stmt->execute([$table]);
    $pflicht = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $geliefert = ['identifier', 'action', 'created_at'];
    $fehlend   = array_diff($pflicht, $geliefert);

    assertSame(
        [],
        array_values($fehlend),
        'Pflichtspalten ohne Wert im INSERT: ' . implode(', ', $fehlend)
    );
});

test('Der Limiter sperrt unter strengem SQL-Modus nach der dritten Anfrage', function () use ($pdo, $prefix, $database) {
    $limiter = new RateLimiter($pdo, $database);

    // Eigener Bezeichner je Lauf: Die Suite darf sich nicht selbst sperren und
    // keine Rückstände hinterlassen, die den nächsten Lauf treffen
    // (dieselbe Falle wie in station_api, dort am 2026-09-09 behoben).
    $kennung = 'strict-test-' . uniqid();
    $aktion  = 'strict_probe_' . uniqid();

    $ergebnisse = [];
    for ($i = 0; $i < 4; $i++) {
        $ergebnisse[] = $limiter->check($kennung, $aktion, 3, 60);
    }

    assertSame(
        [true, true, true, false],
        $ergebnisse,
        'Erwartet: drei Versuche zugelassen, der vierte gesperrt. Kommt stattdessen '
        . 'viermal true, schlägt der INSERT fehl und das fail-open in checkDatabase() '
        . 'verdeckt es — genau der Zustand aus OI-40.'
    );

    $pdo->prepare("DELETE FROM `{$prefix}rate_limits` WHERE action = ?")->execute([$aktion]);
});

test('Ein Eintrag trägt eine brauchbare Zeit', function () use ($pdo, $prefix, $database) {
    $limiter = new RateLimiter($pdo, $database);
    $kennung = 'strict-time-' . uniqid();
    $aktion  = 'strict_time_' . uniqid();

    $limiter->check($kennung, $aktion, 3, 60);

    $stmt = $pdo->prepare("SELECT created_at FROM `{$prefix}rate_limits` WHERE action = ?");
    $stmt->execute([$aktion]);
    $zeit = $stmt->fetchColumn();

    assertTrue($zeit !== false, 'Kein Eintrag geschrieben — der INSERT ist gescheitert');
    assertTrue(
        abs(time() - strtotime((string) $zeit)) < 120,
        "created_at liegt nicht in der Gegenwart: {$zeit}"
    );

    $pdo->prepare("DELETE FROM `{$prefix}rate_limits` WHERE action = ?")->execute([$aktion]);
});

test('Eine Stoerung wird fuer die Einstellungsseite vermerkt', function () use ($pdo, $prefix, $database) {
    // Der echte Fehlerpfad, nicht nachgestellt: Ohne die Tabelle scheitert
    // jedes Statement des Limiters. Umbenennen statt Loeschen -- der Bestand
    // (laufende Sperren) bleibt dabei erhalten.
    $limiter = new RateLimiter($pdo, $database);
    $weg     = $prefix . 'rate_limits_verify_tmp';

    $pdo->prepare("DELETE FROM `{$prefix}system_settings` WHERE setting_key IN (?, ?)")
        ->execute(['rate_limiter_last_error_at', 'rate_limiter_last_error_code']);

    $pdo->exec("RENAME TABLE `{$prefix}rate_limits` TO `{$weg}`");

    try {
        $durchgelassen = $limiter->check('notice-' . uniqid(), 'notice_probe_' . uniqid(), 1, 60);
    } finally {
        // Auch wenn oben etwas wirft: Die Instanz darf nicht ohne ihre
        // Tabelle zurueckbleiben.
        $pdo->exec("RENAME TABLE `{$weg}` TO `{$prefix}rate_limits`");
    }

    assertTrue($durchgelassen, 'Der Limiter soll bei einer Stoerung durchlassen (fail-open, bewusst)');

    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM `{$prefix}system_settings`
                           WHERE setting_key IN (?, ?)");
    $stmt->execute(['rate_limiter_last_error_at', 'rate_limiter_last_error_code']);
    $vermerk = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $vermerk[$row['setting_key']] = $row['setting_value'];
    }

    assertTrue(
        isset($vermerk['rate_limiter_last_error_at']),
        'Die Stoerung wurde nirgends vermerkt -- die Einstellungsseite kann sie nicht zeigen'
    );
    assertTrue(
        abs(time() - strtotime((string) $vermerk['rate_limiter_last_error_at'])) < 120,
        'Der Zeitstempel der Stoerung liegt nicht in der Gegenwart'
    );
    assertTrue(
        isset($vermerk['rate_limiter_last_error_code']) && $vermerk['rate_limiter_last_error_code'] !== '',
        'Die Fehlernummer fehlt'
    );
    assertTrue(
        stripos((string) $vermerk['rate_limiter_last_error_code'], 'rate_limits') === false,
        'Der Vermerk traegt Tabellennamen aus dem Meldungstext -- nur die Fehlernummer gehoert hinein'
    );

    // Kein Rueckstand: Sonst zeigt die Einstellungsseite nach jedem Testlauf
    // 24 Stunden lang eine Warnung.
    $pdo->prepare("DELETE FROM `{$prefix}system_settings` WHERE setting_key IN (?, ?)")
        ->execute(['rate_limiter_last_error_at', 'rate_limiter_last_error_code']);
});

exit(harnessSummary());

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
        'password'       => 'probelauf',
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
 * Entscheidet, ob die Zielanzeige erscheint.
 *
 * Unterdrückt wird sie nur, wenn beides gesetzt ist: `--quiet` **und** `--yes`.
 * Das ist der Cron-Fall — dort liest die Anzeige niemand, und rund zwanzig
 * Zeilen je Lauf bedeuten bei einem stündlichen Job je nach Konfiguration eine
 * Mail pro Stunde.
 *
 * Ohne `--yes` erscheint sie **immer**, auch mit `--quiet`: Wer aufgefordert
 * wird, LOESCHEN zu tippen, muss sehen, was er löscht. Eine stille Rückfrage
 * wäre gefährlicher als eine laute Ausgabe lästig ist.
 */
function showTargetListing(bool $yes, bool $quiet): bool
{
    return !$quiet || !$yes;
}

/**
 * Nennt das Ziel und verlangt eine Bestätigung.
 *
 * Ohne diese Anzeige wäre nicht erkennbar, welche Datenbank getroffen wird —
 * und das Skript löscht. Zur Ausnahme siehe showTargetListing().
 */
function confirmTarget(PDO $db, string $prefix, string $dbName, bool $yes, bool $quiet = false): void
{
    if (showTargetListing($yes, $quiet)) {
        echo "Ziel:      Datenbank '{$dbName}', Präfix '{$prefix}'\n";
        echo "Folgende Tabellen werden GELEERT:\n";

        foreach (DEMO_TABLES as $table) {
            $stmt  = $db->query("SELECT COUNT(*) FROM {$prefix}{$table}");
            $count = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
            printf("  %-32s %6d Zeile(n)\n", $table, $count);
        }
    }

    if ($yes) {
        if (!$quiet) {
            echo "\n--yes gesetzt, keine Rückfrage.\n";
        }

        return;
    }

    echo "\nZum Fortfahren LOESCHEN eingeben: ";
    $answer = trim((string) fgets(STDIN));

    if ($answer !== 'LOESCHEN') {
        echo "Abgebrochen.\n";
        exit(0);
    }
}

/**
 * Leert die Fachtabellen.
 *
 * Bewusst `DELETE` und nicht `TRUNCATE`: TRUNCATE löst in MySQL ein implizites
 * COMMIT aus. Der ganze Lauf steht aber in einer Transaktion, damit ein Fehler
 * beim Schreiben keine halb geleerte Datenbank hinterlässt — mit TRUNCATE wäre
 * das rollBack() wirkungslos und die Daten trotzdem weg.
 *
 * Auto-Increment-Werte bleiben dabei stehen. Das ist folgenlos, weil der
 * Generator alle IDs ausdrücklich setzt.
 */
function clearAll(PDO $db, string $prefix): void
{
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (DEMO_TABLES as $table) {
        $db->exec("DELETE FROM {$prefix}{$table}");
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
}

/**
 * Fügt Zeilen in eine Tabelle ein.
 *
 * Die Spaltenliste wird aus den Schlüsseln der ersten Zeile abgeleitet und
 * das Prepared Statement einmal vorbereitet. Jede weitere Zeile muss exakt
 * dieselben Schlüssel in derselben Reihenfolge tragen — gebunden wird nach
 * Position, nicht nach Name. Eine abweichende Zeile würde sonst
 * stillschweigend in die falschen Spalten geschrieben, statt laut zu
 * scheitern.
 *
 * @param array<int, array<string, mixed>> $rows
 */
function insertRows(PDO $db, string $prefix, string $table, array $rows): int
{
    if ($rows === []) {
        return 0;
    }

    $columns = array_keys($rows[0]);
    $columnList  = implode(', ', $columns);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));

    $stmt = $db->prepare("INSERT INTO {$prefix}{$table} ({$columnList}) VALUES ({$placeholders})");

    foreach ($rows as $index => $row) {
        if (array_keys($row) !== $columns) {
            throw new RuntimeException(
                "insertRows: Tabelle '{$table}', Zeile {$index}: Schlüssel weichen von der ersten Zeile ab "
                . '(erwartet ' . implode(', ', $columns) . ', erhalten ' . implode(', ', array_keys($row)) . ').'
            );
        }
        $stmt->execute(array_values($row));
    }

    return count($rows);
}

/**
 * Zufälliges 32-stelliges Base32-Secret für TOTP-Geräte.
 *
 * Alphabet und Länge wie an anderer Stelle im Produktivcode (siehe
 * private/helpers/totp.php), damit ein erzeugtes Gerät dieselben Regeln
 * erfüllt wie ein über die Oberfläche angelegtes.
 */
function demoBase32Secret(): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret   = '';
    for ($i = 0; $i < 32; $i++) {
        $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $secret;
}

/**
 * Löst die Platzhalter 'member' und 'manager' aus dem Plan in tatsächliche
 * Benutzer-IDs auf (3 = Mitgliedskonto, 2 = Schriftführung/Manager). `null`
 * bleibt `null`. Der Plan kennt keine Konten, siehe Kommentar über
 * buildExceptions() und buildWorkSessions() in plan.php.
 */
function demoResolveActor(string|int|null $value): int|null
{
    if ($value === 'member') {
        return 3;
    }
    if ($value === 'manager') {
        return 2;
    }

    return $value;
}

/**
 * Schreibt den vollständigen Bestand und gibt Tabellenname => geschriebene
 * Zeilen zurück. Hier — und nur hier — entstehen die Geheimnisse: Passwort-
 * und PIN-Hashes, Geräte-Token und TOTP-Secret. plan.php kennt sie bewusst
 * nicht, sonst wäre der Plan nicht reproduzierbar (password_hash() salzt bei
 * jedem Aufruf neu).
 *
 * Reihenfolge: Eltern vor Kindern. Eine Abweichung vom Vorgabetext ist
 * bewusst: `members` steht vor `users`, nicht danach — `users.member_id` ist
 * ein Fremdschlüssel auf `members.member_id` (`users_ibfk_member_id1`), und
 * das Benutzerkonto 'user@musterhausen.example' hängt an Mitglied 1. Mit
 * users vor members würde dieser INSERT unter MySQL mit aktiven
 * Fremdschlüsselprüfungen scheitern (clearAll() schaltet sie am Ende wieder
 * ein). member_groups vor members ist unkritisch, da keine Abhängigkeit in
 * beide Richtungen besteht — hier trotzdem vorgezogen, um alle Stammdaten
 * ohne Fremdschlüssel zuerst zu schreiben.
 *
 * @return array<string, int>
 */
function writePlan(PDO $db, string $prefix, array $plan, string $password): array
{
    $written = [];

    // 1. Einstellungen. rowCount() taugt hier nicht als Existenzprüfung: Es
    // liefert 0, wenn der Wert schon stimmte, nicht nur wenn der Schlüssel
    // fehlt. Deshalb vorher per SELECT prüfen. Ein fehlender Schlüssel wird
    // nicht stillschweigend übersprungen, sondern als Warnung gemeldet —
    // sonst bliebe z. B. der Vereinsname falsch, ohne dass es auffiele.
    $settingsWritten = 0;
    $existsStmt = $db->prepare("SELECT COUNT(*) FROM {$prefix}system_settings WHERE setting_key = ?");
    $updateStmt = $db->prepare("UPDATE {$prefix}system_settings SET setting_value = ?, updated_by = NULL WHERE setting_key = ?");
    foreach ($plan['settings'] as $key => $value) {
        $existsStmt->execute([$key]);
        if ((int) $existsStmt->fetchColumn() === 0) {
            fwrite(STDERR, "Warnung: setting_key '{$key}' existiert nicht — übersprungen.\n");
            continue;
        }
        $updateStmt->execute([$value, $key]);
        $settingsWritten++;
    }
    $written['system_settings'] = $settingsWritten;

    // 2. member_groups (vorgezogen, siehe Hinweis oben — keine Abhängigkeit
    // zu members/users in beide Richtungen).
    $written['member_groups'] = insertRows($db, $prefix, 'member_groups', $plan['groups']);

    // 3. members. `pin` ist im Plan Klartext und darf nicht in die
    // Datenbank — ersetzt durch pin_hash, mit pin_updated_at nur, wenn eine
    // PIN vorliegt.
    $now = date('Y-m-d H:i:s');
    $memberRows = [];
    foreach ($plan['members'] as $member) {
        $pin = $member['pin'];
        unset($member['pin']);
        $member['pin_hash']       = $pin !== null ? password_hash($pin, PASSWORD_DEFAULT) : null;
        $member['pin_updated_at'] = $pin !== null ? $now : null;
        $memberRows[] = $member;
    }
    $written['members'] = insertRows($db, $prefix, 'members', $memberRows);

    // 4. users. Konten (role !== 'device') bekommen ein Passwort-Hash, Geräte
    // bekommen Token und TOTP-Secret.
    $userRows = [];
    foreach ($plan['users'] as $user) {
        $isDevice = $user['role'] === 'device';
        $user['password_hash'] = $isDevice ? null : password_hash($password, PASSWORD_DEFAULT);
        $user['api_token']     = $isDevice ? bin2hex(random_bytes(24)) : null;
        $user['totp_secret']   = $isDevice ? demoBase32Secret() : null;
        $userRows[] = $user;
    }
    $written['users'] = insertRows($db, $prefix, 'users', $userRows);

    // 5. Restliche Stammdaten, unverändert aus dem Plan.
    $written['member_group_assignments'] = insertRows($db, $prefix, 'member_group_assignments', $plan['member_group_assignments']);
    $written['membership_dates']         = insertRows($db, $prefix, 'membership_dates', $plan['membership_dates']);
    $written['appointment_types']        = insertRows($db, $prefix, 'appointment_types', $plan['appointment_types']);
    $written['appointment_type_groups']  = insertRows($db, $prefix, 'appointment_type_groups', $plan['appointment_type_groups']);
    $written['activity_types']           = insertRows($db, $prefix, 'activity_types', $plan['activity_types']);
    $written['activity_type_groups']     = insertRows($db, $prefix, 'activity_type_groups', $plan['activity_type_groups']);

    // 6. appointments. created_by = 1 (Admin), is_auto_created = 0.
    $appointmentRows = [];
    foreach ($plan['appointments'] as $appointment) {
        $appointment['created_by']      = 1;
        $appointment['is_auto_created'] = 0;
        $appointmentRows[] = $appointment;
    }
    $written['appointments'] = insertRows($db, $prefix, 'appointments', $appointmentRows);

    // 7. records, unverändert.
    $written['records'] = insertRows($db, $prefix, 'records', $plan['records']);

    // 8. exceptions, work_sessions, work_session_log: Platzhalter 'member'
    // und 'manager' durch die tatsächlichen Benutzer-IDs ersetzen.
    $exceptionRows = [];
    foreach ($plan['exceptions'] as $exception) {
        $exception['created_by']  = demoResolveActor($exception['created_by']);
        $exception['approved_by'] = demoResolveActor($exception['approved_by']);
        $exceptionRows[] = $exception;
    }
    $written['exceptions'] = insertRows($db, $prefix, 'exceptions', $exceptionRows);

    // work_sessions: kein Feld active_member — es ist eine generierte
    // virtuelle Spalte (siehe Kommentar über buildWorkSessions() in
    // plan.php) und darf im INSERT nicht auftauchen, sonst weist MySQL ihn
    // ab.
    $sessionRows = [];
    foreach ($plan['work_sessions'] as $session) {
        $session['created_by']  = demoResolveActor($session['created_by']);
        $session['approved_by'] = demoResolveActor($session['approved_by']);
        $sessionRows[] = $session;
    }
    $written['work_sessions'] = insertRows($db, $prefix, 'work_sessions', $sessionRows);

    $logRows = [];
    foreach ($plan['work_session_log'] as $log) {
        $log['changed_by'] = demoResolveActor($log['changed_by']);
        $logRows[] = $log;
    }
    $written['work_session_log'] = insertRows($db, $prefix, 'work_session_log', $logRows);

    return $written;
}

// ---------------------------------------------------------------------
// Ablauf
// ---------------------------------------------------------------------
//
// Läuft nur, wenn diese Datei direkt aufgerufen wurde — nicht bei einem
// require_once aus der Testsuite. Ohne diese Schranke würde schon das Laden
// der Testsuite parseOptions() mit den Argumenten des Testrunners aufrufen,
// eine echte Datenbankverbindung öffnen und mit exit() den gesamten
// Testlauf abwürgen.
$isMainScript = isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__;

if ($isMainScript) {
    requireCli();

    try {
        $options = parseOptions($argv);
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }

    $database = new Database();
    $db       = $database->getConnection();
    $prefix   = $database->table('');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    try {
        assertSchema($db, $prefix);
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }

    $dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn();

    confirmTarget($db, $prefix, $dbName, $options['yes'], $options['quiet']);

    // Uhrzeit des tatsächlichen Laufs, nicht nur das Datum: buildWorkSessions()
    // braucht sie, damit die eine laufende Sitzung im Bestand vor diesem
    // Zeitpunkt beginnt statt auf einem gewürfelten Slot zwischen 08:00 und
    // 18:45 — sonst könnte sie in der Zukunft liegen (siehe Docblock dort).
    $plan = buildDemoPlan($options['seed'], $options['reference_date'], date('H:i:s'));

    try {
        $db->beginTransaction();
        clearAll($db, $prefix);
        $written = writePlan($db, $prefix, $plan, $options['password']);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        fwrite(STDERR, 'Fehler beim Schreiben, Transaktion zurückgerollt: ' . $e->getMessage() . "\n");
        exit(1);
    }

    if (!$options['quiet']) {
        echo "\nGeschrieben:\n";
        foreach ($written as $table => $count) {
            printf("  %-32s %6d Zeile(n)\n", $table, $count);
        }

        echo "\nVerein:    " . DEMO_ORG_NAME . "\n";
        echo "Stichtag:  {$options['reference_date']}\n";
        echo "Saat:      {$options['seed']}\n";
        echo "\nKonten (Passwort: {$options['password']}):\n";
        echo "  admin@musterhausen.example    (admin)\n";
        echo "  manager@musterhausen.example  (manager)\n";
        echo "  user@musterhausen.example     (Mitglied)\n";
        echo "\nDer Stations-Token steht im Dashboard unter Geräte.\n";
    }

    exit(0);
}

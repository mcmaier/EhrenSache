<?php
/**
 * Fachlogik rund um die Ankunftszeit, ohne HTTP.
 *
 * Gegen SQLite im Speicher: Die Funktionen in helpers/utils.php und
 * handlers/auto_checkin.php benutzen einfaches SQL und laufen dort
 * unveraendert. Tabellenpraefix 'ut_' wie in station_unit.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/utils.php';

/** Stub fuer das Database-Objekt: gebraucht wird nur table(). */
class ArrivalTestDatabase
{
    public function table(string $name): string
    {
        return 'ut_' . $name;
    }
}

/** Frische Datenbank mit einem Termin am 04.03.2031, 20:00 Uhr (appointment_id 7). */
function arrivalTestDb(): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE ut_records (
                    record_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    member_id INTEGER, appointment_id INTEGER,
                    arrival_time TEXT, status TEXT DEFAULT 'present',
                    checkin_source TEXT DEFAULT 'admin',
                    source_device TEXT, location_name TEXT)");
    $pdo->exec("CREATE TABLE ut_appointments (
                    appointment_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    date TEXT, start_time TEXT)");
    $pdo->exec("CREATE TABLE ut_exceptions (
                    exception_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    member_id INTEGER, appointment_id INTEGER,
                    exception_type TEXT, reason TEXT,
                    requested_arrival_time TEXT,
                    status TEXT, created_by INTEGER)");

    $pdo->exec("INSERT INTO ut_appointments (appointment_id, date, start_time)
                VALUES (7, '2031-03-04', '20:00:00')");

    return [$pdo, new ArrivalTestDatabase()];
}

test('Ein genehmigter Zeitkorrektur-Antrag kennzeichnet seine Herkunft', function () {
    [$pdo, $database] = arrivalTestDb();

    // Ein Record, der wie eine Kiosk-Messung aussieht
    $pdo->exec("INSERT INTO ut_records (member_id, appointment_id, arrival_time, status, checkin_source)
                VALUES (3, 7, '2031-03-04 20:02:00', 'present', 'station_pin')");
    $pdo->exec("INSERT INTO ut_exceptions (member_id, appointment_id, exception_type,
                                           reason, requested_arrival_time, status, created_by)
                VALUES (3, 7, 'time_correction', 'Stempeln ging nicht',
                        '2031-03-04 19:55:00', 'approved', 1)");

    handleApprovedTimeCorrection($pdo, $database, 1, []);

    $row = $pdo->query("SELECT arrival_time, checkin_source FROM ut_records WHERE record_id = 1")
               ->fetch(PDO::FETCH_ASSOC);

    assertSame('2031-03-04 19:55:00', $row['arrival_time']);
    assertSame('exception_request', $row['checkin_source'],
        'Eine Selbstauskunft darf nicht als station_pin weiterlaufen');
});

/*
 * handleApprovedAbsence() wird nicht hier geprueft, sondern in arrival_api:
 * Die Funktion benutzt INSERT IGNORE, und das kennt SQLite nicht (dort hiesse
 * es INSERT OR IGNORE). Die Syntax steht in der Produktionsfunktion und wird
 * nicht fuer einen Test umgeschrieben.
 */

test('Ein Antrag ohne vorhandenen Record legt ihn mit der richtigen Quelle an', function () {
    [$pdo, $database] = arrivalTestDb();

    $pdo->exec("INSERT INTO ut_exceptions (member_id, appointment_id, exception_type,
                                           reason, requested_arrival_time, status, created_by)
                VALUES (4, 7, 'time_correction', 'war da', '2031-03-04 19:50:00', 'approved', 1)");

    handleApprovedTimeCorrection($pdo, $database, 1, []);

    $row = $pdo->query("SELECT arrival_time, checkin_source, status FROM ut_records WHERE member_id = 4")
               ->fetch(PDO::FETCH_ASSOC);

    assertTrue($row !== false, 'Es wurde kein Record angelegt');
    assertSame('2031-03-04 19:50:00', $row['arrival_time']);
    assertSame('exception_request', $row['checkin_source']);
    assertSame('present', $row['status']);
});

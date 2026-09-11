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
require_once __DIR__ . '/../../private/handlers/auto_checkin.php';

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

test('Ein Stempel ersetzt einen Eintrag ohne Ankunftszeit', function () {
    [$pdo, $database] = arrivalTestDb();

    // Liste abgehakt: Record ohne Uhrzeit
    $pdo->exec("INSERT INTO ut_records (member_id, appointment_id, arrival_time, status, checkin_source)
                VALUES (6, 7, NULL, 'present', 'admin')");

    $res = writeCheckinRecord($pdo, 'ut_', 6, 7, '2031-03-04 19:58:00',
                              'station_pin', null, null);

    assertSame('updated', $res['body']['record_action'],
        'Eine Messung muss eine fehlende Zeit immer ersetzen');

    $row = $pdo->query("SELECT arrival_time, checkin_source FROM ut_records WHERE member_id = 6")
               ->fetch(PDO::FETCH_ASSOC);
    assertSame('2031-03-04 19:58:00', $row['arrival_time']);
    assertSame('station_pin', $row['checkin_source']);
});

test('Ein spaeterer Stempel laesst eine fruehere Messung stehen', function () {
    [$pdo, $database] = arrivalTestDb();

    $pdo->exec("INSERT INTO ut_records (member_id, appointment_id, arrival_time, status, checkin_source)
                VALUES (8, 7, '2031-03-04 19:50:00', 'present', 'station_pin')");

    $res = writeCheckinRecord($pdo, 'ut_', 8, 7, '2031-03-04 20:05:00',
                              'station_pin', null, null);

    assertSame('unchanged', $res['body']['record_action'],
        'Die frueheste Ankunft gilt — daran aendert dieser Umbau nichts');

    $row = $pdo->query("SELECT arrival_time FROM ut_records WHERE member_id = 8")
               ->fetch(PDO::FETCH_ASSOC);
    assertSame('2031-03-04 19:50:00', $row['arrival_time']);
});

// ---- Fenster fuer eine beantragte Ankunftszeit ------------------------------

test('Eine beantragte Zeit innerhalb des Fensters wird angenommen', function () {
    [$pdo, $database] = arrivalTestDb();

    // Termin 7: 2031-03-04, 20:00 Uhr. Fenster bei 2 Stunden: 18:00 bis 22:00.
    assertTrue(arrivalWithinAppointmentWindow($pdo, $database, 7, '2031-03-04 19:55:00', 2));
    assertTrue(arrivalWithinAppointmentWindow($pdo, $database, 7, '2031-03-04 18:00:00', 2));
    assertTrue(arrivalWithinAppointmentWindow($pdo, $database, 7, '2031-03-04 22:00:00', 2));
});

test('Eine beantragte Zeit ausserhalb des Fensters wird abgelehnt', function () {
    [$pdo, $database] = arrivalTestDb();

    // Ohne Schranke liesse sich fuer einen 20-Uhr-Termin 17:00 beantragen und
    // damit eine Puenktlichkeit behaupten, die niemand pruefen kann.
    assertTrue(!arrivalWithinAppointmentWindow($pdo, $database, 7, '2031-03-04 17:59:00', 2));
    assertTrue(!arrivalWithinAppointmentWindow($pdo, $database, 7, '2031-03-04 22:01:00', 2));
    assertTrue(!arrivalWithinAppointmentWindow($pdo, $database, 7, '2031-03-05 19:55:00', 2));
});

test('Ein unbekannter Termin oder eine unlesbare Zeit gilt nicht als gueltig', function () {
    [$pdo, $database] = arrivalTestDb();

    assertTrue(!arrivalWithinAppointmentWindow($pdo, $database, 999, '2031-03-04 19:55:00', 2));
    assertTrue(!arrivalWithinAppointmentWindow($pdo, $database, 7, 'kein Datum', 2));
    assertTrue(!arrivalWithinAppointmentWindow($pdo, $database, 7, '', 2));
});

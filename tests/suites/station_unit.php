<?php
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/totp.php';
require_once __DIR__ . '/../../private/helpers/rate_limiter.php';
require_once __DIR__ . '/../../private/helpers/station.php';

// ---- totpCodesForSecret --------------------------------------------------
// RFC 6238, Anhang B: Secret "12345678901234567890" (Base32
// GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ), SHA1, T = 59 s → 94287082, sechsstellig 287082.
const STATION_RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

test('totpCodesForSecret liefert den RFC-6238-Code fuer T=59', function () {
    $codes = totpCodesForSecret(STATION_RFC_SECRET, 59);
    assertSame('287082', $codes['code']);
});

test('totpCodesForSecret nennt das Fensterende und die Periode', function () {
    $codes = totpCodesForSecret(STATION_RFC_SECRET, 59);
    assertSame(60, $codes['valid_until']);
    assertSame(30, $codes['period']);
});

test('totpCodesForSecret liefert als next_code den Code des Folgefensters', function () {
    $codes = totpCodesForSecret(STATION_RFC_SECRET, 59);
    // Verifiziert mit: (new TOTP('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'))->getCode(60)
    assertSame('359152', $codes['next_code']);
    assertSame('359152', totpCodesForSecret(STATION_RFC_SECRET, 89)['code'], 'Folgefenster reicht bis 89 s');
});

test('totpCodesForSecret an der Fenstergrenze T=60', function () {
    $codes = totpCodesForSecret(STATION_RFC_SECRET, 60);
    assertSame('359152', $codes['code']);
    assertSame(90, $codes['valid_until']);
});

test('totpCodesForSecret ohne Zeitstempel nimmt die Serverzeit', function () {
    $before = time();
    $codes  = totpCodesForSecret(STATION_RFC_SECRET);
    assertTrue(preg_match('/^\d{6}$/', $codes['code']) === 1, 'sechs Ziffern erwartet');
    assertTrue($codes['valid_until'] > $before && $codes['valid_until'] <= $before + 30, 'valid_until liegt im naechsten Fenster');
});

// ---- RateLimiter::reset ---------------------------------------------------
// Session-Modus: ohne PDO zaehlt der Limiter in $_SESSION. Im CLI ist das ein
// gewoehnliches Array — genau richtig fuer einen Test ohne Datenbank.

test('RateLimiter::reset hebt eine erreichte Sperre auf', function () {
    $_SESSION = [];
    $limiter  = new RateLimiter();

    assertTrue($limiter->check('m1', 'station_pin', 2, 900));
    assertTrue($limiter->check('m1', 'station_pin', 2, 900));
    assertSame(false, $limiter->check('m1', 'station_pin', 2, 900), 'dritter Versuch muss scheitern');

    $limiter->reset('m1', 'station_pin');
    assertTrue($limiter->check('m1', 'station_pin', 2, 900), 'nach reset wieder erlaubt');
});

test('RateLimiter::reset trifft nur das genannte Kennzeichen', function () {
    $_SESSION = [];
    $limiter  = new RateLimiter();

    $limiter->check('a', 'station_pin', 1, 900);
    $limiter->check('b', 'station_pin', 1, 900);
    $limiter->reset('a', 'station_pin');

    assertTrue($limiter->check('a', 'station_pin', 1, 900));
    assertSame(false, $limiter->check('b', 'station_pin', 1, 900));
});

test('RateLimiter::reset trifft nur die genannte Action, nicht andere Actions desselben Kennzeichens', function () {
    $_SESSION = [];
    $limiter  = new RateLimiter();

    assertTrue($limiter->check('x', 'a1', 1, 900));
    assertSame(false, $limiter->check('x', 'a1', 1, 900), 'a1 bereits ausgeschoepft');
    assertTrue($limiter->check('x', 'a2', 1, 900));
    assertSame(false, $limiter->check('x', 'a2', 1, 900), 'a2 bereits ausgeschoepft');

    $limiter->reset('x', 'a1');

    assertTrue($limiter->check('x', 'a1', 1, 900), 'a1 nach reset wieder erlaubt');
    assertSame(false, $limiter->check('x', 'a2', 1, 900), 'a2 bleibt gesperrt');
});

// ---- validateStationPin ---------------------------------------------------
// Liefert null bei gueltiger PIN, sonst den Fehlertext fuer die Oberflaeche.

test('validateStationPin nimmt vier Ziffern an', function () {
    assertSame(null, validateStationPin('2580', 4));
});

test('validateStationPin nimmt acht Ziffern an', function () {
    assertSame(null, validateStationPin('20481357', 4));
});

test('validateStationPin lehnt Buchstaben ab', function () {
    assertTrue(validateStationPin('12a4', 4) !== null);
});

test('validateStationPin lehnt Leerstring ab', function () {
    assertTrue(validateStationPin('', 4) !== null);
});

test('validateStationPin haelt die Mindestlaenge ein', function () {
    assertTrue(validateStationPin('2580', 6) !== null, 'vier Stellen bei Minimum sechs');
    assertSame(null, validateStationPin('258013', 6));
});

test('validateStationPin lehnt mehr als acht Ziffern ab', function () {
    assertTrue(validateStationPin('204813579', 4) !== null);
});

test('validateStationPin lehnt lauter gleiche Ziffern ab', function () {
    assertTrue(validateStationPin('0000', 4) !== null);
    assertTrue(validateStationPin('777777', 4) !== null);
});

test('validateStationPin lehnt aufsteigende Folgen ab', function () {
    assertTrue(validateStationPin('1234', 4) !== null);
    assertTrue(validateStationPin('456789', 4) !== null);
});

test('validateStationPin lehnt absteigende Folgen ab', function () {
    assertTrue(validateStationPin('4321', 4) !== null);
    assertTrue(validateStationPin('9876', 4) !== null);
});

test('validateStationPin laesst unterbrochene Folgen zu', function () {
    assertSame(null, validateStationPin('1235', 4));
    assertSame(null, validateStationPin('1224', 4));
});

test('validateStationPin klemmt die Mindestlaenge auf 4..8', function () {
    assertSame(null, validateStationPin('2580', 2), 'Minimum 2 wirkt wie 4');
    assertTrue(validateStationPin('258', 2) !== null, 'drei Stellen bleiben zu kurz');
    assertSame(null, validateStationPin('20481357', 12), 'Minimum 12 wirkt wie 8');
});

test('validateStationPin lehnt eine PIN mit angehaengtem Zeilenumbruch ab', function () {
    assertTrue(validateStationPin("1234\n", 4) !== null, 'trailing newline ist keine Ziffer');
    assertTrue(validateStationPin("123\n", 4) !== null, 'trailing newline zaehlt nicht als vierte Ziffer');
});

test('validateStationPin lehnt eine PIN mit fuehrendem Leerzeichen ab', function () {
    assertTrue(validateStationPin(' 2580', 4) !== null);
});

// ---- stationPinIsSequence --------------------------------------------------

test('stationPinIsSequence lehnt Strings kuerzer als 2 Zeichen ab', function () {
    assertSame(false, stationPinIsSequence('5'));
});

test('stationPinIsSequence erkennt eine zweistellige Folge', function () {
    assertSame(true, stationPinIsSequence('12'));
});

test('stationPinIsSequence erkennt eine zweistellige Nicht-Folge', function () {
    assertSame(false, stationPinIsSequence('13'));
});

// ---- stationDummyHash -------------------------------------------------------

test('stationDummyHash verifiziert nie gegen einen echten Wert', function () {
    assertSame(false, password_verify('anything', stationDummyHash()));
});

test('stationDummyHash liefert einen bcrypt-Hash', function () {
    $info = password_get_info(stationDummyHash());
    assertSame('bcrypt', $info['algoName']);
});

// ---- stationAuthenticate --------------------------------------------------
// Laeuft gegen eine SQLite-Datenbank im Speicher und den Session-Limiter:
// die Sperrlogik wird rot/gruen gefahren, ohne die Entwicklungsdatenbank
// anzufassen. Braucht pdo_sqlite (in XAMPP standardmaessig aktiv).

if (!extension_loaded('pdo_sqlite')) {
    test('stationAuthenticate-Tests uebersprungen: pdo_sqlite fehlt', function () {
        throw new RuntimeException('php_pdo_sqlite aktivieren');
    });
} else {
    /** Minimaler Ersatz fuer die Database-Klasse aus config.php. */
    final class StationTestDatabase
    {
        public function table(string $name): string
        {
            return 'ut_' . $name;
        }
    }

    /**
     * Frische Datenbank mit Mitgliedern: 100 (PIN 2580, aktiv),
     * 200 (PIN 1357, inaktiv), 300 ohne PIN und 400 doppelt vergeben.
     */
    function stationTestDb(): array
    {
        // Einmal berechnete Hashes statt pro Aufruf — password_hash() ist mit
        // Absicht langsam, und stationTestDb() laeuft in etlichen Tests.
        static $pinHash2580 = null;
        static $pinHash1357 = null;
        if ($pinHash2580 === null) {
            $pinHash2580 = password_hash('2580', PASSWORD_DEFAULT);
            $pinHash1357 = password_hash('1357', PASSWORD_DEFAULT);
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE ut_members (
                        member_id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT, surname TEXT, member_number TEXT,
                        pin_hash TEXT, active INTEGER DEFAULT 1)");
        $ins = $pdo->prepare("INSERT INTO ut_members (name, surname, member_number, pin_hash, active)
                              VALUES (?, ?, ?, ?, ?)");
        $ins->execute(['Anna', 'Aktiv',   '100', $pinHash2580, 1]);
        $ins->execute(['Ingo', 'Inaktiv', '200', $pinHash1357, 0]);
        $ins->execute(['Olga', 'Ohne',    '300', null, 1]);
        $ins->execute(['Dora', 'Doppelt', '400', $pinHash2580, 1]);
        $ins->execute(['Dirk', 'Doppelt', '400', $pinHash2580, 1]);

        $_SESSION = [];

        return [$pdo, new StationTestDatabase(), new RateLimiter()];
    }

    test('stationAuthenticate akzeptiert Nummer + richtige PIN', function () {
        [$db, $database, $limiter] = stationTestDb();
        $member = stationAuthenticate($db, $database, $limiter, 1, '100', '2580', $failure);
        assertTrue($member !== null, 'Mitglied erwartet');
        assertSame('Anna', $member['name']);
        assertSame(null, $failure);
    });

    test('stationAuthenticate lehnt falsche PIN ab', function () {
        [$db, $database, $limiter] = stationTestDb();
        assertSame(null, stationAuthenticate($db, $database, $limiter, 1, '100', '9999', $failure));
        assertSame('invalid', $failure);
    });

    test('stationAuthenticate lehnt unbekannte Nummer ab — gleiche Meldung', function () {
        [$db, $database, $limiter] = stationTestDb();
        assertSame(null, stationAuthenticate($db, $database, $limiter, 1, '999', '2580', $failure));
        assertSame('invalid', $failure);
    });

    test('stationAuthenticate lehnt inaktives Mitglied ab', function () {
        [$db, $database, $limiter] = stationTestDb();
        assertSame(null, stationAuthenticate($db, $database, $limiter, 1, '200', '1357', $failure));
        assertSame('invalid', $failure);
    });

    test('stationAuthenticate lehnt Mitglied ohne PIN ab', function () {
        [$db, $database, $limiter] = stationTestDb();
        assertSame(null, stationAuthenticate($db, $database, $limiter, 1, '300', '2580', $failure));
        assertSame('invalid', $failure);
    });

    test('stationAuthenticate lehnt mehrdeutige Nummer ab', function () {
        [$db, $database, $limiter] = stationTestDb();
        assertSame(null, stationAuthenticate($db, $database, $limiter, 1, '400', '2580', $failure));
        assertSame('ambiguous', $failure);
    });

    test('stationAuthenticate sperrt das Mitglied nach fuenf Fehlversuchen', function () {
        [$db, $database, $limiter] = stationTestDb();
        for ($i = 0; $i < 5; $i++) {
            stationAuthenticate($db, $database, $limiter, 1, '100', '0001', $failure);
            assertSame('invalid', $failure, "Versuch " . ($i + 1));
        }
        // Sechster Versuch: sogar die RICHTIGE PIN wird abgewiesen
        assertSame(null, stationAuthenticate($db, $database, $limiter, 1, '100', '2580', $failure));
        assertSame('locked', $failure);
    });

    test('stationAuthenticate: Erfolg setzt den Zaehler zurueck', function () {
        [$db, $database, $limiter] = stationTestDb();
        for ($i = 0; $i < 4; $i++) {
            stationAuthenticate($db, $database, $limiter, 1, '100', '0001', $failure);
        }
        assertTrue(stationAuthenticate($db, $database, $limiter, 1, '100', '2580', $failure) !== null);
        for ($i = 0; $i < 4; $i++) {
            stationAuthenticate($db, $database, $limiter, 1, '100', '0001', $failure);
        }
        assertTrue(stationAuthenticate($db, $database, $limiter, 1, '100', '2580', $failure) !== null,
                   'nach Erfolg zaehlt es wieder von vorn');
    });

    test('stationAuthenticate sperrt den Kiosk nach dreissig Fehlversuchen', function () {
        [$db, $database, $limiter] = stationTestDb();
        // 30 Versuche auf lauter unbekannte Nummern: kein Mitgliedszaehler greift
        for ($i = 0; $i < 30; $i++) {
            stationAuthenticate($db, $database, $limiter, 7, 'nr' . $i, '0001', $failure);
            assertSame('invalid', $failure, "Versuch " . ($i + 1));
        }
        assertSame(null, stationAuthenticate($db, $database, $limiter, 7, '100', '2580', $failure));
        assertSame('device_locked', $failure, 'Kiosk-Sperre ist von der Mitglieds-Sperre unterscheidbar');
        // Ein anderer Kiosk ist nicht betroffen
        assertTrue(stationAuthenticate($db, $database, $limiter, 8, '100', '2580', $failure) !== null);
    });

    test('stationAuthenticate: Erfolg setzt auch den Kiosk-Zaehler zurueck', function () {
        [$db, $database, $limiter] = stationTestDb();
        for ($i = 0; $i < 4; $i++) {
            stationAuthenticate($db, $database, $limiter, 1, '100', '0001', $failure);
        }
        for ($i = 0; $i < 4; $i++) {
            stationAuthenticate($db, $database, $limiter, 1, 'nr' . $i, '0001', $failure);
        }
        assertTrue(stationAuthenticate($db, $database, $limiter, 1, '100', '2580', $failure) !== null,
                   'Erfolg trotz vier Mitglieds- und vier Kiosk-Fehlversuchen');

        // Waeren die Zaehler nicht zurueckgesetzt, wuerde der Kiosk hier schon
        // nach 26 weiteren Versuchen sperren (30 minus vier vorherige).
        for ($i = 0; $i < 29; $i++) {
            stationAuthenticate($db, $database, $limiter, 1, 'unbekannt' . $i, '0001', $failure);
            assertSame('invalid', $failure, "Versuch " . ($i + 1) . " nach Erfolg");
        }
    });

    test('stationAuthenticate ueberschreibt ein vorbelegtes $failure bei Erfolg mit null', function () {
        [$db, $database, $limiter] = stationTestDb();
        $failure = 'x';
        $member  = stationAuthenticate($db, $database, $limiter, 1, '100', '2580', $failure);
        assertTrue($member !== null);
        assertSame(null, $failure);
    });

    test('stationAuthenticate lehnt leere Mitgliedsnummer und leere PIN ohne Exception ab', function () {
        [$db, $database, $limiter] = stationTestDb();
        assertSame(null, stationAuthenticate($db, $database, $limiter, 1, '', '', $failure));
        assertSame('invalid', $failure);
    });
}

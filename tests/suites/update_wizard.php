<?php
declare(strict_types=1);

/**
 * Statische Prüfung des Update-Wizards.
 *
 * Der Wizard lässt sich nicht sinnvoll aufrufen: er startet eine Session,
 * setzt Header, verbindet die Datenbank und sperrt sich am Ende selbst per
 * .htaccess aus. `tests/db/verify_migration_chain.php` bildet seinen dritten
 * Schritt deshalb nach, statt ihn auszuführen — und hat damit genau den
 * Fehler nicht gesehen, der am 2026-09-04 auftrat:
 *
 * `$step` hält die Nummer des Wizard-Schritts. Die Schleife über die
 * Migrationskette lief mit `foreach ($chain as $step)` und überschrieb sie
 * mit einem Array. Danach war keine der Bedingungen `$step == 1|2|3` mehr
 * wahr, und die Ergebnisseite blieb leer — ohne Fehlermeldung, während die
 * Migration selbst sauber durchgelaufen war. Da sich der Wizard im selben
 * Durchgang aussperrt, war das Ergebnis auch nicht mehr aufrufbar.
 *
 * Diese Suite prüft die Quelle, nicht das Verhalten. Das ist die schwächere
 * Form, aber sie fängt die Wiederkehr dieser Klasse von Fehler.
 */

$wizardSource = (string) file_get_contents(dirname(__DIR__, 2) . '/public/update/index.php');

test('Update-Wizard: keine Schleife bindet an $step', function () use ($wizardSource) {
    // Deckt beide Formen ab: `as $step` und `as $key => $step`.
    assertSame(0, preg_match_all('/\bas\s*(?:\$\w+\s*=>\s*)?\$step\b/', $wizardSource),
        'Die Schrittnummer des Wizards wird von einer Schleife ueberschrieben — '
        . 'danach rendert keine Ansicht mehr');
});

test('Update-Wizard: $step bleibt eine Schrittnummer', function () use ($wizardSource) {
    // Jede Zuweisung muss eine ganze Zahl liefern. Die Zeile 63 castet aus
    // $_GET, Zeile 104 setzt eine Konstante — alles andere waere der naechste
    // Weg in denselben Fehler.
    preg_match_all('/\$step\s*=(?!=)\s*([^;]+);/', $wizardSource, $matches);

    assertTrue($matches[1] !== [], 'Keine Zuweisung an $step gefunden — Suche anpassen');

    foreach ($matches[1] as $rhs) {
        $rhs = trim($rhs);
        assertTrue(
            preg_match('/^\d+$/', $rhs) === 1 || str_starts_with($rhs, '(int)'),
            "Zuweisung an \$step liefert keine ganze Zahl: {$rhs}"
        );
    }
});

test('Update-Wizard: die drei Ansichten haengen an $step', function () use ($wizardSource) {
    foreach ([1, 2, 3] as $view) {
        assertTrue(
            str_contains($wizardSource, "\$step == {$view}"),
            "Ansicht fuer Schritt {$view} nicht gefunden — Suche oben anpassen"
        );
    }
});

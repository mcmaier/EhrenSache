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

test('Update-Wizard: die vier Ansichten haengen an $step', function () use ($wizardSource) {
    // Schritt 0 (Dateien von GitHub holen) kam mit 1.6.0 dazu.
    foreach ([0, 1, 2, 3] as $view) {
        assertTrue(
            str_contains($wizardSource, "\$step == {$view}"),
            "Ansicht fuer Schritt {$view} nicht gefunden — Suche oben anpassen"
        );
    }
});

test('Update-Wizard: die Warnung zur Ausgangsversion 1.0.0 ist entfernt', function () use ($wizardSource) {
    assertSame(false, strpos($wizardSource, 'erwarteten Ausgangsversion'),
        'Die Warnung erschien bei jedem regulaeren Update');
});

test('Update-Wizard: geplante Aenderungen kommen aus der Migrationskette', function () use ($wizardSource) {
    assertSame(false, strpos($wizardSource, 'import_logs'), 'Feste Liste aus der 1.0.0-Zeit steht noch drin');
    assertTrue(strpos($wizardSource, '$plannedChain') !== false, 'Schritt 2 liest die Kette nicht');
});

test('Update-Wizard: POST in Schritt 0 prueft ein Sitzungstoken', function () use ($wizardSource) {
    assertSame(1, preg_match('/hash_equals\(\s*\$_SESSION\[\'update_csrf\'\]/', $wizardSource));
});

test('Update-Wizard: Werte aus der GitHub-Antwort werden maskiert ausgegeben', function () use ($wizardSource) {
    // Jede Ausgabe von $updateInfo, $updateErrors, $updateLog und der
    // Schleifenvariable $zeile steht in htmlspecialchars().
    preg_match_all('/<\?=\s*(.*?)\s*\?>/s', $wizardSource, $treffer);
    foreach ($treffer[1] as $ausdruck) {
        if (strpos($ausdruck, '$updateInfo') !== false || strpos($ausdruck, '$updateErrors') !== false
            || strpos($ausdruck, '$updateLog') !== false || strpos($ausdruck, '$zeile') !== false) {
            assertSame(0, strpos($ausdruck, 'htmlspecialchars('), "Unmaskiert: {$ausdruck}");
        }
    }
});

test('Installer und Update-Assistent lesen die Anforderungen aus version.json', function () use ($wizardSource) {
    $installer = (string) file_get_contents(dirname(__DIR__, 2) . '/public/install/index.php');
    foreach (['public/update/index.php' => $wizardSource, 'public/install/index.php' => $installer] as $datei => $quelle) {
        assertSame(false, strpos($quelle, 'version_compare(PHP_VERSION'), "{$datei} prueft die PHP-Version noch selbst");
        assertTrue(strpos($quelle, 'requirementsChecks(') !== false, "{$datei} nutzt requirementsChecks() nicht");
    }
});

test('Update-Wizard: sperrt sich erst nach der Ergebnisseite (OI-23)', function () use ($wizardSource) {
    // Bis 1.12.0 schrieb Schritt 3 die Sperre vor der Ausgabe. Scheiterte das
    // Rendern, war der Assistent zu und das Ergebnis nirgends mehr zu sehen --
    // wieder oeffnen liess er sich nur durch Loeschen der .htaccess.
    $html  = strpos($wizardSource, '<!DOCTYPE html>');
    $ende  = strpos($wizardSource, '</html>');
    $sperre = strpos($wizardSource, 'file_put_contents(HTACCESS_PATH');

    assertTrue($html !== false && $ende !== false, 'Seitengeruest nicht gefunden');
    assertTrue($sperre !== false, 'Der Wizard schreibt seine Sperre nicht mehr');
    assertSame(1, substr_count($wizardSource, 'file_put_contents(HTACCESS_PATH'),
        'Die Sperre wird an mehr als einer Stelle geschrieben');
    assertTrue($sperre > $ende, 'Die Sperre wird vor dem Ende der Ergebnisseite geschrieben');

    // Nur nach erfolgreicher Migration sperren, nie auf der Fehlerseite.
    $nachEnde = substr($wizardSource, $ende);
    assertTrue(preg_match('/if\s*\(\s*\$migrationOk\s*\)/', $nachEnde) === 1,
        'Die Sperre haengt nicht an $migrationOk');
});

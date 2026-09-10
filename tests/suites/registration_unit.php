<?php
declare(strict_types=1);

/**
 * Statische Gegenproben am Registrierungspfad (private/handlers/user_mailer.php).
 *
 * Das Muster, das hier zweimal zugeschlagen hat: Nach dem commit() folgt der
 * Mailversand — und dessen Fehlschlag kippte das Ergebnis eines Vorgangs, der
 * bereits gespeichert war. Der Nutzer sah einen Fehler, das Konto existierte
 * trotzdem. Beim zweiten Anlauf war dann die E-Mail-Adresse „schon vergeben".
 */

$repoRoot = dirname(__DIR__, 2);

test('Kein rollBack ohne Pruefung auf eine offene Transaktion', function () use ($repoRoot) {
    // PDO::rollBack() wirft "There is no active transaction", wenn der commit
    // schon durch ist — im catch-Block verdeckt das die eigentliche Ursache und
    // endet im Fatal error.
    $dateien = [
        '/private/handlers/user_mailer.php',
        '/private/handlers/users.php',
        '/public/verify_email.php',
    ];

    $verstoesse = [];
    foreach ($dateien as $rel) {
        $zeilen = file($repoRoot . $rel, FILE_IGNORE_NEW_LINES);
        foreach ($zeilen as $i => $zeile) {
            if (strpos($zeile, '->rollBack()') === false) {
                continue;
            }
            // Die Pruefung steht ueblicherweise in der Zeile davor
            $davor = $zeilen[$i - 1] ?? '';
            if (strpos($davor, 'inTransaction()') === false
                && strpos($zeile, 'inTransaction()') === false) {
                $verstoesse[] = $rel . ':' . ($i + 1) . ' — ' . trim($zeile);
            }
        }
    }

    assertTrue(
        $verstoesse === [],
        "rollBack() ohne inTransaction()-Pruefung:\n  " . implode("\n  ", $verstoesse)
    );
});

test('Der Mailversand der Registrierung haengt in einem eigenen try', function () use ($repoRoot) {
    // Zwischen commit() und der Antwort darf nichts mehr liegen, das den
    // Vorgang scheitern laesst. Geprueft wird die Klammer: nach dem commit
    // folgt ein try, und es faengt Throwable — Error ist keine Exception.
    $code = (string) file_get_contents($repoRoot . '/private/handlers/user_mailer.php');

    assertTrue(
        preg_match('/\$db->commit\(\);\s*(?:\/\/[^\n]*\n\s*)*.*?try\s*\{/s', $code) === 1,
        'Nach dem commit() der Registrierung beginnt kein try-Block'
    );
    assertTrue(
        substr_count($code, 'catch (Throwable') >= 2,
        'Der Registrierungspfad faengt keine Throwable — ein Error bleibt ungefangen'
    );
});
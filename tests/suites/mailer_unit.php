<?php
declare(strict_types=1);

/**
 * Statische Gegenprobe an den Aufrufstellen des Mailers.
 *
 * Der Konstruktor nimmt drei Parameter, deklariert die letzten beiden aber als
 * optional: `__construct($config, $pdo = null, $database = null)`. Sobald ein
 * PDO uebergeben wird, ruft er `$database->table('')` — wer das dritte Argument
 * weglaesst, bekommt keinen Standardwert, sondern einen Fatal error.
 *
 * Genau das stand in public/verify_email.php: als einzige von sechs Stellen ohne
 * das dritte Argument. Der Fehler schlug nach dem COMMIT zu, weshalb das Konto
 * bestaetigt und der Token verbraucht war, waehrend der Nutzer eine Fehlerseite
 * sah. Diese Suite haelt die Aufrufform fest.
 */

$repoRoot = dirname(__DIR__, 2);

/**
 * Zaehlt die Argumente eines Aufrufs ab der oeffnenden Klammer — Kommas zaehlen
 * nur auf oberster Ebene, damit `getMailConfig(), $db` nicht falsch zerfaellt.
 */
function mailerArgCount(string $code, int $start): int
{
    $tiefe = 0;
    $args  = 1;

    for ($i = $start; $i < strlen($code); $i++) {
        $z = $code[$i];
        if ($z === '(') {
            $tiefe++;
        } elseif ($z === ')') {
            $tiefe--;
            if ($tiefe === 0) {
                return $args;
            }
        } elseif ($z === ',' && $tiefe === 1) {
            $args++;
        }
    }

    return -1;
}

test('Jede Mailer-Instanziierung uebergibt Konfiguration, PDO und Database', function () use ($repoRoot) {
    $dir = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($repoRoot, FilesystemIterator::SKIP_DOTS)
    );

    $stellen    = 0;
    $verstoesse = [];

    foreach ($dir as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($repoRoot)));
        if (strpos($rel, '/tests/') === 0 || strpos($rel, '/.git/') === 0) {
            continue;
        }

        $code   = (string) file_get_contents($file->getPathname());
        $offset = 0;

        while (($pos = strpos($code, 'new Mailer(', $offset)) !== false) {
            $stellen++;
            $klammer = $pos + strlen('new Mailer') ;
            $anzahl  = mailerArgCount($code, $klammer);
            $zeile   = substr_count(substr($code, 0, $pos), "\n") + 1;

            if ($anzahl !== 3) {
                $verstoesse[] = "{$rel}:{$zeile} — {$anzahl} statt 3 Argumente";
            }
            $offset = $pos + 1;
        }
    }

    assertTrue($stellen > 0, 'Keine einzige Mailer-Instanziierung gefunden — sucht die Suite am falschen Ort?');
    assertTrue(
        $verstoesse === [],
        "Mailer ohne Database-Objekt (Fatal error, sobald ein PDO dabei ist):\n  "
        . implode("\n  ", $verstoesse)
    );
});

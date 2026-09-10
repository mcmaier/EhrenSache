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

test('Keine Mailer-Aufrufstelle laedt die Mailkonfiguration ungeprueft', function () use ($repoRoot) {
    // mail_config.php entsteht erst, wenn ein Admin die SMTP-Einstellungen
    // speichert (settings.php, saveSmtpConfig). Auf einer frischen Installation
    // gibt es sie also nicht — und getMailConfig() in config.php laedt sie
    // ungeprueft per `require`. Jede Stelle, die den Mailer davor baut, stirbt
    // dann mit einem Fatal error, noch bevor checkMailStatus() gefragt werden
    // kann. Zwei dieser Stellen stehen direkt hinter einem commit().
    $dir = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($repoRoot, FilesystemIterator::SKIP_DOTS)
    );

    $verstoesse = [];
    foreach ($dir as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($repoRoot)));
        // config.php und ihr Muster duerfen die Funktion definieren
        if (strpos($rel, '/tests/') === 0 || strpos($rel, '/.git/') === 0
            || strpos($rel, '/private/config/') === 0) {
            continue;
        }

        foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) as $i => $line) {
            $trimmed = ltrim($line);
            // Kommentare erwaehnen die Funktion, sie rufen sie nicht auf
            if ($trimmed === '' || $trimmed[0] === '*' || strpos($trimmed, '//') === 0
                || strpos($trimmed, '/*') === 0) {
                continue;
            }
            if (strpos($line, 'getMailConfig()') !== false) {
                $verstoesse[] = $rel . ':' . ($i + 1) . ' — ' . trim($line);
            }
        }
    }

    assertTrue(
        $verstoesse === [],
        "Direkter getMailConfig()-Aufruf statt loadMailConfig():\n  " . implode("\n  ", $verstoesse)
    );
});

test('loadMailConfig liefert ohne Datei eine vollstaendige Rueckfallkonfiguration', function () use ($repoRoot) {
    require_once $repoRoot . '/private/helpers/mailer.php';

    $config = loadMailConfig($repoRoot . '/private/config/gibt-es-nicht.php');

    foreach (['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'from_email', 'from_name'] as $key) {
        assertTrue(array_key_exists($key, $config), "Schluessel {$key} fehlt in der Rueckfallkonfiguration");
    }
});

test('Der Mailer laesst sich mit der Rueckfallkonfiguration bauen', function () use ($repoRoot) {
    require_once $repoRoot . '/private/helpers/mailer.php';

    // Ohne PDO, wie auf einer Installation ohne Mailkonfiguration: der
    // Konstruktor darf weder eine Warning werfen noch abbrechen.
    $fehler = null;
    set_error_handler(function ($no, $str) use (&$fehler) { $fehler = $str; return true; });
    $mailer = new Mailer(loadMailConfig($repoRoot . '/private/config/gibt-es-nicht.php'));
    restore_error_handler();

    assertTrue($fehler === null, "Konstruktor meldete: {$fehler}");
    assertTrue($mailer instanceof Mailer, 'Kein Mailer-Objekt');
});

test('config_example.php laedt die Mailkonfiguration nicht ungeprueft', function () use ($repoRoot) {
    // Neuinstallationen erben ihre config.php aus dieser Vorlage.
    $php = (string) file_get_contents($repoRoot . '/private/config/config_example.php');

    assertTrue(
        preg_match('/function getMailConfig\(\).*?\}/s', $php, $m) === 1,
        'getMailConfig() nicht gefunden'
    );
    assertTrue(
        strpos($m[0], 'is_file') !== false || strpos($m[0], 'file_exists') !== false,
        'getMailConfig() prueft nicht, ob mail_config.php ueberhaupt existiert'
    );
});
test('Versandte Mails tragen einen To-Header', function () use ($repoRoot) {
    // Der Empfaenger stand bisher nur im SMTP-Envelope (RCPT TO). Ohne
    // Destination-Header zeigen Clients "undisclosed recipients", und ein
    // fehlender To-Header ist ein klassisches Spam-Merkmal — ausgerechnet bei
    // Registrierungs- und Reset-Mails, die ankommen muessen. RFC 5322 verlangt
    // ihn ebenfalls.
    $code = (string) file_get_contents($repoRoot . '/private/helpers/mailer.php');

    assertTrue(
        preg_match('/public function send\(.*?fputs\(\$socket, \$body/s', $code, $m) === 1,
        'send() nicht gefunden'
    );
    assertTrue(
        preg_match('/\$headers\s*\.?=\s*"To:/', $m[0]) === 1,
        'send() baut keinen To-Header — der Empfaenger steht nur im SMTP-Envelope'
    );
});
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
 * Die beiden serverseitig gebauten Seiten ausserhalb der Anmeldung.
 *
 * public/reset_password.php und public/verify_email.php sind ohne Sitzung
 * erreichbar, setzen ihr HTML selbst zusammen und haben keine CSP. Was sie
 * ausgeben, muss an der Quelle stimmen -- eine zweite Schranke gibt es dort
 * nicht. Drei Regeln haelt diese Suite fest:
 *
 * 1. Jeder htmlspecialchars()-Aufruf nennt ENT_QUOTES. Bis PHP 8.0, der von
 *    version.json verlangten Fassung, ist ENT_COMPAT die Vorgabe: ' bleibt
 *    stehen, und die Attribute dieser Seiten stehen in einfachen Anfuehrungen.
 *    Erst ab 8.1 ist ENT_QUOTES die Vorgabe -- eine Vorgabe, die zwischen
 *    Versionen wechselt, taugt nicht als Verlass.
 *
 * 2. Die Formpruefung des Tokens steht vor jedem Zweig, auch vor POST. Kein
 *    Zweig darf mit einem ungeprueften Token arbeiten.
 *
 * 3. Die Branding-Farben laufen durch eine Formpruefung, bevor sie in den
 *    <style>-Block kommen. Dort traegt Maskierung nicht: Der Inhalt eines
 *    style-Elements ist roher Text.
 *
 * Regel 3 wird an der Wirkung geprueft, nicht am Wortlaut: Die Funktionen
 * laufen mit feindlichen Werten. Eine Pruefung, die nur noch im Kommentar
 * steht, wird damit rot (OI-107).
 */

require_once __DIR__ . '/../../private/helpers/branding.php';

$ppRoot = dirname(__DIR__, 2);

/** Die beiden Seiten, relativ zur Wurzel. */
const PP_PAGES = [
    'public/reset_password.php',
    'public/verify_email.php',
];

/**
 * Argumentlisten aller htmlspecialchars()-Aufrufe im Quelltext, als
 * "Zeile n => Argumente". Klammert korrekt aus, damit ein Aufruf mit
 * verschachtelten Klammern vollstaendig erfasst wird.
 */
function ppEscapeCalls(string $code): array
{
    $calls  = [];
    $offset = 0;

    while (($pos = strpos($code, 'htmlspecialchars(', $offset)) !== false) {
        $open  = $pos + strlen('htmlspecialchars(') - 1;
        $depth = 0;
        $end   = $open;
        for ($i = $open, $n = strlen($code); $i < $n; $i++) {
            if ($code[$i] === '(') {
                $depth++;
            } elseif ($code[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        $line    = substr_count(substr($code, 0, $pos), "\n") + 1;
        $calls[] = ['line' => $line, 'args' => substr($code, $open + 1, $end - $open - 1)];
        $offset  = $end + 1;
    }

    return $calls;
}

test('Kein htmlspecialchars() ohne ENT_QUOTES in den oeffentlichen Seiten', function () use ($ppRoot) {
    $dateien = array_merge(PP_PAGES, ['private/helpers/branding.php']);

    foreach ($dateien as $rel) {
        $pfad = "{$ppRoot}/{$rel}";
        assertTrue(is_file($pfad), "Datei fehlt: {$rel}");

        $calls = ppEscapeCalls(sourceCode($pfad));
        assertTrue($calls !== [], "Keine Maskierung gefunden in {$rel} -- Aufrufe umbenannt?");

        foreach ($calls as $call) {
            assertTrue(
                strpos($call['args'], 'ENT_QUOTES') !== false,
                "{$rel} Zeile {$call['line']}: htmlspecialchars() ohne ENT_QUOTES"
            );
            assertTrue(
                strpos($call['args'], 'UTF-8') !== false,
                "{$rel} Zeile {$call['line']}: htmlspecialchars() ohne Encoding"
            );
        }
    }
});

test('Die Tokenpruefung steht vor dem POST-Zweig', function () use ($ppRoot) {
    $code = sourceCode("{$ppRoot}/public/reset_password.php");

    $pruefung = strpos($code, 'ctype_xdigit');
    $post     = strpos($code, "REQUEST_METHOD'] === 'POST'");

    assertTrue($pruefung !== false, 'Die Formpruefung des Tokens fehlt ganz');
    assertTrue($post !== false, 'Der POST-Zweig ist nicht mehr zu finden -- Suite pflegen');
    assertTrue($pruefung < $post, 'Der POST-Zweig laeuft vor der Formpruefung des Tokens');

    // Zwischen Pruefung und POST-Zweig muss der Abbruch liegen, sonst prueft
    // die Seite und macht trotzdem weiter.
    $kopf = substr($code, $pruefung, $post - $pruefung);
    assertTrue(strpos($kopf, 'showError') !== false, 'Die Pruefung fuehrt zu keiner Fehlerseite');
    assertTrue(strpos($kopf, 'exit') !== false, 'Die Pruefung bricht die Anfrage nicht ab');
});

test('Der Token erreicht die Seite nicht mehr als Formularfeld', function () use ($ppRoot) {
    // Das versteckte Feld wurde serverseitig nie gelesen (beide Zweige nehmen
    // $_GET) und war die Stelle, an der der ungepruefte Wert ausgegeben wurde.
    $code = sourceCode("{$ppRoot}/public/reset_password.php");

    assertTrue(
        preg_match("/name\s*=\s*'token'/", $code) !== 1,
        'Das versteckte Token-Feld ist zurueck -- es wird serverseitig nicht gelesen'
    );
});

test('brandingColor() laesst nur Hexfarben durch', function () {
    foreach (['#abc', '#abcd', '#1F5FBF', '#1f5fbfcc', '  #1F5FBF  '] as $gut) {
        assertSame(trim($gut), brandingColor($gut, 'ERSATZ'), "Gueltige Farbe abgewiesen: {$gut}");
    }

    $schlecht = [
        'red',
        '#12345',
        '#1234567',
        '#',
        '',
        null,
        123,
        ['#abc'],
        "red</style><script>alert(1)</script>",
        '#abc; background: url(http://boese.test/x)',
        "#abc\n}",
    ];
    foreach ($schlecht as $wert) {
        assertSame('ERSATZ', brandingColor($wert, 'ERSATZ'), 'Ungueltiger Wert kam durch: ' . var_export($wert, true));
    }
});

test('getBrandingCSS() prueft beide Farben', function () {
    $css = getBrandingCSS([
        'primary_color'   => "red</style><script>alert(1)</script>",
        'secondary_color' => '#abc; position: fixed; top: 0',
    ]);

    assertTrue(strpos($css, '</style>') === false, 'Der Block kann verlassen werden');
    assertTrue(strpos($css, '<script') === false, 'Markup steht im Stilblock');
    assertTrue(strpos($css, 'position: fixed') === false, 'Fremde Deklaration steht im Stilblock');
    assertTrue(strpos($css, BRANDING_PRIMARY_DEFAULT) !== false, 'Die Vorgabefarbe greift nicht');
    assertTrue(strpos($css, BRANDING_SECONDARY_DEFAULT) !== false, 'Die zweite Vorgabefarbe greift nicht');

    // Gueltige Farben muessen weiterhin ankommen, sonst waere die Pruefung
    // gruen und das Branding kaputt.
    $echt = getBrandingCSS(['primary_color' => '#123456', 'secondary_color' => '#654321']);
    assertTrue(strpos($echt, '#123456') !== false, 'Gueltige Farbe kommt nicht an');
    assertTrue(strpos($echt, '#654321') !== false, 'Gueltige zweite Farbe kommt nicht an');
});

test('getBrandingLogo() maskiert auch einfache Anfuehrungszeichen', function () {
    $html = getBrandingLogo([
        'organization_logo' => "x.png' onerror='alert(1)",
        'organization_name' => "Verein' onmouseover='alert(2)",
    ]);

    assertTrue(strpos($html, "onerror='") === false, 'Der Pfad bricht aus dem Attribut aus');
    assertTrue(strpos($html, "onmouseover='") === false, 'Der Name bricht aus dem Attribut aus');
    assertTrue(strpos($html, '&#039;') !== false, 'Das einfache Anfuehrungszeichen wurde nicht maskiert');
});

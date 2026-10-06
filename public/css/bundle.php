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
 * Liefert css/v<Version>/main.css bzw. login.css als ein Bündel aus (OI-120).
 * public/.htaccess schreibt diese Pfade intern hierher um; REQUEST_URI trägt
 * weiter den ursprünglichen Pfad. Ein Jahr Cache nur, wenn dessen Version die
 * aktuelle ist und das Bündel ohne Fehlerkommentar aufging (cssBundleCacheControl).
 * Keine Datenbank, keine config.php.
 */

require_once __DIR__ . '/../../private/helpers/css_bundle.php';

// ?entry[]=x ist ein Array: ohne Absicherung ein Warning im Rumpf (Vorbild api.php).
$entry = is_string($_GET['entry'] ?? null) ? $_GET['entry'] : '';
if (!array_key_exists($entry, CSS_BUNDLE_ENTRIES)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    echo "Not found\n";
    exit;
}

// Zuerst bauen, dann Kopfzeilen: scheitert das Bündel, darf der Browser den
// Fehler nicht ein Jahr behalten. Der Rumpf nennt weder Pfad noch Meldung.
try {
    $css = cssBundle($entry);
} catch (Throwable $e) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "CSS bundle unavailable\n";
    exit;
}

$path    = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$pattern = '#/css/v([0-9][0-9.]*)/' . preg_quote(CSS_BUNDLE_ENTRIES[$entry], '#') . '$#';
$current = cssBundleCurrentVersion();
$aktuell = $current !== '' && preg_match($pattern, $path, $m) === 1 && $m[1] === $current;

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: ' . cssBundleCacheControl($css, $aktuell));
header('Vary: Accept-Encoding');

// Kompression (OI-120): mod_deflate lässt .php aus (no-gzip), das Bündel
// komprimiert selbst. zlib.output_compression kann "On", "1" oder eine
// Puffergröße sein -- alles außer "aus" heißt: PHP komprimiert schon. Das
// spätere Content-Length würde diese Kompression ohnehin abschalten (Bündel
// unkomprimiert), also wird sie hier gezielt ausgeschaltet. Gelingt das, packt
// das Bündel selbst; gelingt es nicht (ini_set gesperrt), bleibt PHPs eigene
// Kompression zuständig und das Bündel komprimiert nicht doppelt.
$oc = strtolower(trim((string) ini_get('zlib.output_compression')));
$outputCompression = !in_array($oc, ['', '0', 'off', 'false', 'no'], true);
if ($outputCompression && @ini_set('zlib.output_compression', '0') !== false) {
    $outputCompression = false;
}
$gzip = cssBundleUseGzip(
    (string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''),
    function_exists('gzencode'),
    $outputCompression
);
if ($gzip) {
    $css = (string) gzencode($css, 6);
    header('Content-Encoding: gzip');
}
header('Content-Length: ' . strlen($css));

echo $css;

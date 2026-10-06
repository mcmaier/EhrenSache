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
 * aktuelle ist -- sonst landete neuer Inhalt unter einer alten Adresse.
 * Keine Datenbank, keine config.php.
 */

require_once __DIR__ . '/../../private/helpers/css_bundle.php';

$entry = (string) ($_GET['entry'] ?? '');
if (!array_key_exists($entry, CSS_BUNDLE_ENTRIES)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    echo "Not found\n";
    exit;
}

$path    = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$pattern = '#/css/v([0-9][0-9.]*)/' . preg_quote(CSS_BUNDLE_ENTRIES[$entry], '#') . '$#';
$current = cssBundleCurrentVersion();
$aktuell = $current !== '' && preg_match($pattern, $path, $m) === 1 && $m[1] === $current;

header('Content-Type: text/css; charset=utf-8');
header($aktuell
    ? 'Cache-Control: public, max-age=31536000, immutable'
    : 'Cache-Control: no-cache, must-revalidate');

echo cssBundle($entry);

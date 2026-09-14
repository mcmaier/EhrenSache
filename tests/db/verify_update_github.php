<?php
/**
 * EhrenSache - Echter Abruf bei GitHub, ohne etwas zu tauschen.
 *
 * Prueft, was sich ohne Netz nicht pruefen laesst: TLS, Weiterleitung von
 * api.github.com nach codeload.github.com, Aufbau des echten zipball.
 *
 * Aufruf: php tests/db/verify_update_github.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/updater.php';

$fehler = 0;
$melde  = static function (bool $ok, string $text) use (&$fehler): void {
    echo ($ok ? '  OK   ' : '  FEHL ') . $text . "\n";
    if (!$ok) {
        $fehler++;
    }
};

$melde(updateEnvironmentErrors() === [], 'curl und zip vorhanden');

$zip  = sys_get_temp_dir() . '/es_gh_' . uniqid() . '.zip';
$ziel = sys_get_temp_dir() . '/es_gh_' . uniqid();

try {
    $release = updateFetchLatest();
    $melde(true, "Neuestes Release: {$release['tag']} vom {$release['published_at']}");

    updateDownloadPackage($release['zipball_url'], $zip);
    $melde(filesize($zip) > 100000, 'Paket geladen: ' . filesize($zip) . ' Bytes');

    updateExtractPackage($zip, $ziel);
    $melde(updateReadVersionFile($ziel) === $release['version'], 'version.json im Paket entspricht dem Tag');
    $melde(updateValidatePackage($ziel, '0.0.0') === [], 'Pflichtdateien vorhanden');
    $melde(!is_dir("{$ziel}/tests") && !is_dir("{$ziel}/docs"), 'export-ignore greift: kein tests/, kein docs/');
} catch (Throwable $e) {
    $melde(false, get_class($e) . ': ' . $e->getMessage());
} finally {
    @unlink($zip);
    updateRemoveTree($ziel);
}

echo $fehler === 0 ? "\nAlles in Ordnung.\n" : "\n{$fehler} Pruefung(en) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);

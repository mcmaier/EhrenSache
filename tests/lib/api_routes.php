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

/**
 * Liest die gerouteten Ressourcen aus public/api/api.php.
 *
 * Stand bis OI-62 als demoTestResourcesFromApi() in tests/suites/demo_mode.php;
 * seit den Feature-Schaltern brauchen ihn zwei Suiten.
 *
 * 'cases' = case-Zweige nach "switch($resource) {", 'early' = $resource === '…'
 * davor (fruehe Ausstiege), 'src' = der Dateitext ohne Kommentare.
 */
declare(strict_types=1);

require_once __DIR__ . '/source.php';

/** @return array{cases: string[], early: string[], src: string} */
function apiRoutesFromSource(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $src = (string) sourceCode(dirname(__DIR__, 2) . '/public/api/api.php');

    $marker = 'switch($resource) {';
    $cut = strpos($src, $marker);

    if ($cut === false) {
        throw new RuntimeException(
            "Trennzeile '{$marker}' nicht in api.php gefunden - "
            . 'apiRoutesFromSource() kann den Ressourcen-Router nicht mehr '
            . 'von den fruehen Ausstiegen trennen. api.php wurde vermutlich '
            . 'umgebaut; die Suche in dieser Funktion muss nachziehen.'
        );
    }

    preg_match_all('/\$resource\s*===\s*[\'"]([a-z0-9_\-]+)[\'"]/i', substr($src, 0, $cut), $early);
    preg_match_all('/case\s+[\'"]([a-z0-9_\-]+)[\'"]\s*:/i', substr($src, $cut), $cases);

    return $cache = [
        'cases' => array_values(array_unique($cases[1])),
        'early' => array_values(array_unique($early[1])),
        'src'   => $src,
    ];
}

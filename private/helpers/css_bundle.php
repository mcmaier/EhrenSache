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
 * CSS-Bündel für Dashboard und Anmeldeseite (OI-120 Weg 1).
 *
 * Beide Seiten laden css/v<Version>/main.css bzw. login.css; public/.htaccess
 * schickt diese Pfade an public/css/bundle.php. Hier wird jede Zeile
 * @import url('…'); durch den Inhalt der Datei ersetzt, rekursiv und in
 * derselben Reihenfolge -- die Kaskade bleibt gleich, aus 22 Anfragen wird
 * eine. Kein Build-Schritt, keine erzeugte Datei: die Quellen bleiben einzeln.
 * Spec docs/superpowers/specs/2026-10-06-css-buendel-design.md.
 */

/** Erlaubte Einstiege → Datei unter public/css/. */
const CSS_BUNDLE_ENTRIES = ['main' => 'main.css', 'login' => 'login.css'];

/**
 * Bündel eines Einstiegs. $root nur für Tests (Standard: public/css).
 *
 * @throws InvalidArgumentException bei unbekanntem Einstieg
 */
function cssBundle(string $entry, ?string $root = null): string
{
    if (!array_key_exists($entry, CSS_BUNDLE_ENTRIES)) {
        throw new InvalidArgumentException("Unbekannter CSS-Einstieg: {$entry}");
    }
    $root = realpath($root ?? __DIR__ . '/../../public/css');
    if ($root === false) {
        throw new RuntimeException('CSS-Verzeichnis nicht gefunden');
    }
    $file = realpath($root . '/' . CSS_BUNDLE_ENTRIES[$entry]);
    if ($file === false) {
        throw new RuntimeException('CSS-Einstieg fehlt: ' . CSS_BUNDLE_ENTRIES[$entry]);
    }
    $seen = [];
    return cssBundleInline($root, $file, $seen);
}

/** Inhalt von $file mit aufgelösten Importen. $seen: bereits eingefügte Dateien. */
function cssBundleInline(string $root, string $file, array &$seen): string
{
    $seen[$file] = true;
    $dir = dirname($file);

    return (string) preg_replace_callback(
        "/^[ \\t]*@import\\s+url\\(\\s*(['\"])([^'\"]*)\\1\\s*\\)\\s*;[ \\t\\r]*$/m",
        static function (array $m) use ($root, $dir, &$seen): string {
            $ref = $m[2];
            if ($ref === '' || $ref[0] === '/' || $ref[0] === '\\' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $ref)) {
                return cssBundleError($ref, 'nur relative Pfade');
            }
            $target = realpath($dir . '/' . $ref);
            if ($target === false || !is_file($target)) {
                return cssBundleError($ref, 'Datei fehlt');
            }
            if (!str_starts_with($target, $root . DIRECTORY_SEPARATOR) || substr($target, -4) !== '.css') {
                return cssBundleError($ref, 'außerhalb von css/');
            }
            if (isset($seen[$target])) {
                return cssBundleError($ref, 'doppelt importiert');
            }
            $rel = str_replace('\\', '/', substr($target, strlen($root) + 1));
            return "/* ---- {$rel} ---- */\n" . rtrim(cssBundleInline($root, $target, $seen)) . "\n";
        },
        (string) file_get_contents($file)
    );
}

/** Ersatz für einen nicht einbindbaren Import; kann den Kommentar nicht beenden. */
function cssBundleError(string $ref, string $grund): string
{
    $ref = str_replace(['*/', '/*'], '', $ref);
    return "/* css-bundle: {$ref} nicht eingebunden ({$grund}) */";
}

/** Aktuelle Version aus version.json. */
function cssBundleCurrentVersion(): string
{
    $data = json_decode((string) @file_get_contents(__DIR__ . '/../../version.json'), true);
    return is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : '';
}

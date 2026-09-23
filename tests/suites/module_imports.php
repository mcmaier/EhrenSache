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
 * Jeder benannte Import muss im Zielmodul auch exportiert sein.
 *
 * Ein fehlender benannter Import ist im Browser kein stilles undefined,
 * sondern ein Linking-Fehler: das ES-Modul wird gar nicht erst ausgewertet,
 * und die gesamte Anwendung startet nicht mehr. Genau das war im Vorhaben
 * "Kalender zur Anwesenheit" zwischenzeitlich der Fall (records.js importierte
 * setCalendarMonth aus appointments.js, bevor es dort stand) -- kein Test hat
 * es bemerkt, weil die Suiten nur einzelne Funktionsrumpfe lesen.
 *
 * Bewusst ausgeklammert:
 *   - dynamische Importe (await import('./x.js')): der Zielpfad kann berechnet
 *     sein, und ein Fehler trifft nur den Aufrufweg, nicht den Modulstart
 *   - Standard-Importe (import x from) und Namensraum-Importe (import * as x):
 *     sie haengen nicht an einem Namen im Ziel
 *   - Pfade ausserhalb von public/js/ (Fremdcode, vendor/)
 */
declare(strict_types=1);

$miRoot = dirname(__DIR__, 2);
$miJsDir = $miRoot . '/public/js';

/** Alle .js-Dateien unter public/js/ (Module und Einstiegspunkte). */
function miJsFiles(string $jsDir): array
{
    $found = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($jsDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        $pfad = str_replace('\\', '/', $file->getPathname());
        if ($file->isFile() && substr($pfad, -3) === '.js' && strpos($pfad, '/vendor/') === false) {
            $found[] = $pfad;
        }
    }
    sort($found);

    return $found;
}

/**
 * Benannte Importe einer Datei: [['ziel' => absoluter Pfad, 'name' => Export], ...]
 *
 * Erfasst nur statische Importe mit geschweiften Klammern. Umbenennungen
 * (import { a as b }) zaehlen mit ihrem Namen im Ziel, also a.
 */
function miNamedImports(string $src, string $datei, string $jsDir): array
{
    $treffer = [];
    // import { ... } from '...';  -- auch ueber mehrere Zeilen, auch gemischt
    // mit einem Standard-Import (import x, { y } from '...').
    //
    // Der Anker ^[ \t]* haelt auskommentierte Importe heraus (utils.js traegt
    // so eine Zeile). Statische Importe stehen ohnehin nur auf oberster Ebene,
    // also immer am Zeilenanfang. Grenze: ein in /* ... */ eingepackter Import
    // wuerde noch mitgezaehlt -- im Projekt gibt es keinen.
    preg_match_all('/^[ \t]*import\s+(?:[A-Za-z_$][\w$]*\s*,\s*)?\{([^}]*)\}\s*from\s*[\'"]([^\'"]+)[\'"]/sm', $src, $m, PREG_SET_ORDER);

    foreach ($m as $treffer_) {
        $pfad = $treffer_[2];
        if ($pfad === '' || $pfad[0] !== '.') {
            continue; // Paketnamen o. ae. -- gibt es hier nicht, waere aber Fremdcode
        }

        $ziel = realpath(dirname($datei) . '/' . $pfad);
        if ($ziel === false) {
            $treffer[] = ['ziel' => null, 'name' => null, 'pfad' => $pfad];
            continue;
        }
        $ziel = str_replace('\\', '/', $ziel);
        if (strpos($ziel, str_replace('\\', '/', $jsDir)) !== 0) {
            continue; // ausserhalb von public/js/
        }

        foreach (explode(',', $treffer_[1]) as $teil) {
            $teil = trim($teil);
            if ($teil === '') {
                continue;
            }
            // import { a as b } -> im Ziel heisst der Export a
            if (preg_match('/^([A-Za-z_$][\w$]*)\s+as\s+[A-Za-z_$][\w$]*$/', $teil, $um)) {
                $teil = $um[1];
            }
            if (!preg_match('/^[A-Za-z_$][\w$]*$/', $teil)) {
                continue; // z. B. "default as x" -- kein benannter Export
            }
            $treffer[] = ['ziel' => $ziel, 'name' => $teil, 'pfad' => $pfad];
        }
    }

    return $treffer;
}

/** Alle benannten Exporte einer Moduldatei. */
function miExports(string $src): array
{
    $namen = [];

    // export function/async function/const/let/var/class NAME
    preg_match_all('/^\s*export\s+(?:async\s+)?(?:function\*?|const|let|var|class)\s+([A-Za-z_$][\w$]*)/m', $src, $m);
    foreach ($m[1] as $name) {
        $namen[$name] = true;
    }

    // export const { a, b } = ... / export const [a, b] = ...
    preg_match_all('/^\s*export\s+(?:const|let|var)\s*[\{\[]([^}\]]*)[\}\]]/m', $src, $m);
    foreach ($m[1] as $liste) {
        foreach (explode(',', $liste) as $teil) {
            if (preg_match('/([A-Za-z_$][\w$]*)\s*$/', trim($teil), $t)) {
                $namen[$t[1]] = true;
            }
        }
    }

    // export { a, b as c }  -- auch als Re-Export mit from '...'
    preg_match_all('/^\s*export\s*\{([^}]*)\}/m', $src, $m);
    foreach ($m[1] as $liste) {
        foreach (explode(',', $liste) as $teil) {
            $teil = trim($teil);
            if ($teil === '') {
                continue;
            }
            // "a as b" wird nach aussen als b sichtbar
            if (preg_match('/\s+as\s+([A-Za-z_$][\w$]*)$/', $teil, $um)) {
                $namen[$um[1]] = true;
            } elseif (preg_match('/^[A-Za-z_$][\w$]*$/', $teil)) {
                $namen[$teil] = true;
            }
        }
    }

    return $namen;
}

test('Unter public/js/ liegen Module zum Pruefen', function () use ($miJsDir) {
    assertTrue(is_dir($miJsDir), 'public/js/ fehlt');
    assertTrue(count(miJsFiles($miJsDir)) > 10, 'Zu wenige Moduldateien gefunden -- der Test liefe ins Leere');
});

test('Jeder benannte Import existiert im Zielmodul', function () use ($miRoot, $miJsDir) {
    $exportCache = [];
    $fehler = [];

    foreach (miJsFiles($miJsDir) as $datei) {
        $src = (string) file_get_contents($datei);
        $kurz = ltrim(str_replace(str_replace('\\', '/', $miRoot), '', $datei), '/');

        foreach (miNamedImports($src, $datei, $miJsDir) as $imp) {
            if ($imp['ziel'] === null) {
                $fehler[] = "{$kurz}: Zielmodul {$imp['pfad']} existiert nicht";
                continue;
            }
            if (!isset($exportCache[$imp['ziel']])) {
                $exportCache[$imp['ziel']] = miExports((string) file_get_contents($imp['ziel']));
            }
            if (!isset($exportCache[$imp['ziel']][$imp['name']])) {
                $zielKurz = ltrim(str_replace(str_replace('\\', '/', $miRoot), '', $imp['ziel']), '/');
                $fehler[] = "{$kurz}: {$imp['name']} wird aus {$zielKurz} importiert, dort aber nicht exportiert";
            }
        }
    }

    assertTrue($fehler === [], "Fehlende Exporte -- die Anwendung startet im Browser nicht:\n  " . implode("\n  ", $fehler));
});

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
 * Mitgeprueft werden auch dynamische Importe der Form
 * const { a } = await import('./x.js'). Sie brechen zwar nur den Aufrufweg und
 * nicht den Modulstart, aber genau daran haengt der Sprung vom Kalender in die
 * Anwesenheit (appointments.js -> records.js) -- er bliebe sonst ungeprueft.
 *
 * Bewusst ausgeklammert:
 *   - dynamische Importe mit berechnetem Pfad: sie fallen automatisch heraus,
 *     weil das Muster ein mit '.' beginnendes String-Literal verlangt
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
 * Erfasst statische Importe mit geschweiften Klammern und dynamische Importe
 * mit Literalpfad. Umbenennungen (import { a as b }) zaehlen mit ihrem Namen
 * im Ziel, also a.
 */
function miNamedImports(string $src, string $datei, string $jsDir): array
{
    // Rohtreffer: je Eintrag [Namensliste aus den Klammern, Pfad-Literal].
    $rohe = [];

    // import { ... } from '...';  -- auch ueber mehrere Zeilen, auch gemischt
    // mit einem Standard-Import (import x, { y } from '...').
    //
    // Der Anker ^[ \t]* haelt auskommentierte Importe heraus (utils.js traegt
    // so eine Zeile). Statische Importe stehen ohnehin nur auf oberster Ebene,
    // also immer am Zeilenanfang. Grenze: ein in /* ... */ eingepackter Import
    // wuerde noch mitgezaehlt -- im Projekt gibt es keinen.
    preg_match_all('/^[ \t]*import\s+(?:[A-Za-z_$][\w$]*\s*,\s*)?\{([^}]*)\}\s*from\s*[\'"]([^\'"]+)[\'"]/sm', $src, $statisch, PREG_SET_ORDER);
    foreach ($statisch as $treffer) {
        $rohe[] = [$treffer[1], $treffer[2]];
    }

    // const { ... } = await import('...') bzw. ({ ... } = await import('...')).
    // Das verlangte String-Literal mit fuehrendem Punkt laesst berechnete Pfade
    // automatisch draussen.
    //
    // [^{}] statt [^}]: ohne das Verbot der oeffnenden Klammer begaenne der
    // Treffer beim naechstgelegenen vorangehenden Block ("try {" eine Zeile
    // darueber), und die Namensliste truege dessen Rumpf mit sich.
    preg_match_all('/\{([^{}]*)\}\s*=\s*await\s+import\(\s*[\'"](\.[^\'"]+)[\'"]\s*\)/s', $src, $dynamisch, PREG_SET_ORDER);
    foreach ($dynamisch as $treffer) {
        $rohe[] = [$treffer[1], $treffer[2]];
    }

    // Der Praefixvergleich braucht den Schraegstrich: ohne ihn gaelte ein
    // Nachbarverzeichnis public/jsx als "innerhalb von public/js".
    $jsPraefix = rtrim(str_replace('\\', '/', $jsDir), '/') . '/';

    $importe = [];
    foreach ($rohe as [$namensliste, $pfad]) {
        if ($pfad === '' || $pfad[0] !== '.') {
            continue; // Paketnamen o. ae. -- gibt es hier nicht, waere aber Fremdcode
        }

        $ziel = realpath(dirname($datei) . '/' . $pfad);
        if ($ziel === false) {
            $importe[] = ['ziel' => null, 'name' => null, 'pfad' => $pfad];
            continue;
        }
        $ziel = str_replace('\\', '/', $ziel);
        if (strpos($ziel, $jsPraefix) !== 0) {
            continue; // ausserhalb von public/js/
        }

        foreach (explode(',', $namensliste) as $teil) {
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
            $importe[] = ['ziel' => $ziel, 'name' => $teil, 'pfad' => $pfad];
        }
    }

    return $importe;
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

test('Dynamische Importe mit Literalpfad werden erfasst', function () use ($miJsDir) {
    // Ohne diese Gegenprobe koennte das Muster still ins Leere laufen und der
    // Hauptttest waere weiterhin gruen, ohne einen einzigen dynamischen Import
    // gesehen zu haben.
    $datei = str_replace('\\', '/', $miJsDir) . '/modules/appointments.js';
    assertTrue(is_file($datei), 'appointments.js fehlt');

    $namen = array_column(miNamedImports((string) file_get_contents($datei), $datei, $miJsDir), 'name');
    assertTrue(in_array('openAttendanceForAppointment', $namen, true),
        'Der dynamische Import aus records.js wird nicht erfasst -- die Kopplung Kalender/Anwesenheit bliebe ungeprueft');
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

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
 * Jede ausgelieferte JavaScript-Datei muss sich parsen lassen.
 *
 * Anlass: Auf feat/feature-schalter (OI-62) stand in ui.js in showDashboard()
 * zweimal `const navItem` im selben Block. ui.js lud dadurch nicht, das
 * Dashboard blieb leer — und alle PHP-Suiten waren gruen, weil sie nur
 * einzelne Stellen im Quelltext lesen. Gefunden hat es erst der
 * Klickdurchgang im Browser.
 *
 * Geprueft wird mit Node, aber NICHT als `node --check <datei>`: Fuer eine
 * .js-Datei mit import/export meldet Node 24 dabei Exit-Code 0, ganz gleich,
 * was in der Datei steht (am 2026-10-05 nachgeprueft, sogar `const = ;` kam
 * durch). Der Quelltext geht deshalb ueber stdin an
 * `node --input-type=<art> --check`, das parst ihn wirklich. Der Selbsttest
 * unten haelt fest, dass ein Fehler auch erkannt wird — aendert Node sein
 * Verhalten erneut, wird die Suite rot statt still gruen.
 *
 * Geprueft wird als ES-Modul, der strengsten Form (strikter Modus,
 * Modulgrammatik). Nur die klassischen Skripte aus JSX_CLASSIC_SCRIPTS laufen
 * als Skript, damit eine Fremdbibliothek im nicht strikten Modus nicht
 * faelschlich rot wird. Mitgeprueft werden die Service Worker der beiden PWAs
 * und public/js/vendor/ — eine kaputte Fremddatei bricht die Seite genauso.
 *
 * Node ist damit Voraussetzung dieser Suite. Fehlt es, scheitert ein Test mit
 * klarer Meldung; stilles Ueberspringen waere gruen ohne Pruefung.
 */
declare(strict_types=1);

$jsxRoot = str_replace("\\", "/", dirname(__DIR__, 2));

/** Verzeichnisse mit ausgeliefertem JavaScript, rekursiv durchsucht. */
const JSX_DIRS = ['public/js', 'public/checkin', 'public/station'];

/**
 * Untergrenze gefundener Dateien. Am 2026-10-05 sind es 35; ein falscher
 * Pfad oder ein geaenderter Dateifilter soll nicht still null Dateien pruefen.
 */
const JSX_MIN_FILES = 25;

/**
 * Dateien, die der Browser als klassisches Skript laedt (ohne type="module")
 * oder als Service Worker; ein Eintrag mit / am Ende gilt fuer das ganze
 * Verzeichnis. Alles andere wird als Modul geprueft.
 */
const JSX_CLASSIC_SCRIPTS = [
    'public/js/vendor/',
    'public/js/install-check.js',
    'public/checkin/js/app.js',
    'public/checkin/service-worker.js',
    'public/station/js/app.js',
    'public/station/service-worker.js',
];

/**
 * Fuehrt einen Befehl ohne Umweg ueber cmd.exe aus (Array-Form von proc_open)
 * und reicht $stdin hinein.
 *
 * @param string[] $command
 * @return array{code: int, stdout: string, stderr: string}|null null, wenn der
 *         Prozess gar nicht startet (Programm nicht gefunden)
 */
function jsxRun(array $command, string $stdin = ''): ?array
{
    $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return null;
    }
    // Node liest stdin vollstaendig, bevor es etwas ausgibt; Schreiben vor
    // dem Lesen blockiert deshalb nicht.
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/**
 * Syntaxpruefung eines Quelltexts als Modul ('module') oder klassisches
 * Skript ('commonjs').
 *
 * @return array{code: int, stdout: string, stderr: string}|null
 */
function jsxCheck(string $source, string $inputType): ?array
{
    return jsxRun(['node', "--input-type={$inputType}", '--check'], $source);
}

/** Wird $relative als klassisches Skript geladen? */
function jsxIsClassic(string $relative): bool
{
    foreach (JSX_CLASSIC_SCRIPTS as $entry) {
        if ($relative === $entry || (str_ends_with($entry, '/') && str_starts_with($relative, $entry))) {
            return true;
        }
    }

    return false;
}

/**
 * Kurzfassung einer Fehlerausgabe von node --check: die Fundstelle (erste
 * Zeile, "[stdin]:<zeile>", hier mit dem Dateinamen) und die Meldung
 * ("SyntaxError: …").
 */
function jsxFirstError(string $stderr, string $relative): string
{
    $lines    = array_values(array_filter(array_map('trim', preg_split('/\R/', $stderr) ?: []), 'strlen'));
    $location = str_replace('[stdin]', $relative, $lines[0] ?? '(keine Ausgabe)');
    foreach ($lines as $line) {
        if (preg_match('/^[A-Za-z]*Error\b/', $line) === 1) {
            return "{$location} — {$line}";
        }
    }

    return $location;
}

$jsxFiles = [];
foreach (JSX_DIRS as $dir) {
    if (is_dir("{$jsxRoot}/{$dir}")) {
        $jsxFiles = array_merge($jsxFiles, projectFiles("{$jsxRoot}/{$dir}", 'js'));
    }
}
$jsxRelative = array_map(static fn (string $file): string => substr($file, strlen($jsxRoot) + 1), $jsxFiles);

test('js_syntax: mindestens ' . JSX_MIN_FILES . ' JavaScript-Dateien gefunden', function () use ($jsxFiles): void {
    assertTrue(
        count($jsxFiles) >= JSX_MIN_FILES,
        'nur ' . count($jsxFiles) . ' Dateien unter ' . implode(', ', JSX_DIRS) . ' — Pfad oder Filter kaputt?'
    );
});

test('js_syntax: jeder Eintrag in JSX_CLASSIC_SCRIPTS trifft eine Datei', function () use ($jsxRelative): void {
    foreach (JSX_CLASSIC_SCRIPTS as $entry) {
        $hits = array_filter($jsxRelative, static fn (string $r): bool => $r === $entry
            || (str_ends_with($entry, '/') && str_starts_with($r, $entry)));
        assertTrue($hits !== [], "{$entry} trifft keine Datei mehr — Eintrag veraltet");
    }
});

$jsxNode   = jsxRun(['node', '--version']);
$jsxNodeOk = $jsxNode !== null && $jsxNode['code'] === 0;

test('js_syntax: node ist im PATH aufrufbar', function () use ($jsxNode, $jsxNodeOk): void {
    assertTrue(
        $jsxNodeOk,
        'node nicht gefunden oder nicht lauffaehig'
        . ($jsxNode !== null ? ' (Exit-Code ' . $jsxNode['code'] . ')' : '')
        . ' — diese Suite braucht Node.js im PATH, sonst bleibt die JavaScript-Syntax ungeprueft'
    );
});

// Ohne Node gibt es nichts zu pruefen; der Test oben ist dann bereits rot.
if ($jsxNodeOk) {
    test('js_syntax: Selbsttest — die Pruefung erkennt Fehler und laesst Gueltiges durch', function (): void {
        $duplicate = "export function f() {\n    const a = 1;\n    const a = 2;\n}\n";
        foreach (['module', 'commonjs'] as $type) {
            $result = jsxCheck($duplicate, $type);
            assertTrue($result !== null && $result['code'] !== 0, "doppelte const-Deklaration als {$type} nicht erkannt");
            assertTrue(
                str_contains($result['stderr'], "Identifier 'a' has already been declared")
                    || str_contains($result['stderr'], 'SyntaxError'),
                "keine SyntaxError-Meldung als {$type}: " . $result['stderr']
            );
        }
        $valid = jsxCheck("export const a = 1;\n", 'module');
        assertSame(0, $valid['code'] ?? null, 'gueltiges Modul abgelehnt: ' . ($valid['stderr'] ?? ''));
        $valid = jsxCheck("var a = 1;\n", 'commonjs');
        assertSame(0, $valid['code'] ?? null, 'gueltiges Skript abgelehnt: ' . ($valid['stderr'] ?? ''));
    });

    foreach ($jsxFiles as $index => $file) {
        $relative  = $jsxRelative[$index];
        $inputType = jsxIsClassic($relative) ? 'commonjs' : 'module';
        test("js_syntax: {$relative}", function () use ($file, $relative, $inputType): void {
            $result = jsxCheck(rawSource($file), $inputType);
            assertTrue($result !== null, "node --check liess sich fuer {$relative} nicht starten");
            assertTrue(
                $result['code'] === 0,
                "{$relative} als {$inputType} nicht parsebar (Exit-Code {$result['code']}): "
                . jsxFirstError($result['stderr'], $relative)
            );
        });
    }
}

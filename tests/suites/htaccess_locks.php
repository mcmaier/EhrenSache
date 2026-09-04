<?php
declare(strict_types=1);

/**
 * Die Zugriffssperren von Installer und Update-Assistent.
 *
 * Beide Verzeichnisse tragen eine `.htaccess`, die sie sperrt — und beide
 * Skripte schreiben dieselbe Datei am Ende ihres Laufs neu. Damit gibt es je
 * zwei Fassungen desselben Inhalts, die auseinanderlaufen können. Am
 * 2026-09-04 war genau das der Fall: Der Assistent überschrieb die Anleitung
 * zum Wiederöffnen mit einer zweizeiligen Kurzfassung.
 *
 * Geprüft wird zweierlei:
 *
 * 1. Die Sperre ist auf Apache 2.2 UND 2.4 wirksam. `Order Deny,Allow` allein
 *    ist 2.2-Syntax; auf einem 2.4 ohne mod_access_compat quittiert Apache das
 *    mit HTTP 500 statt 403. Es sperrt dann zwar immer noch — sieht aber nach
 *    Defekt aus. Der Rest des Projekts nutzt `Require all denied`.
 * 2. Datei und Generator schreiben dieselben Direktiven.
 */

$repoRoot = dirname(__DIR__, 2);

/**
 * Die wirksamen Zeilen einer .htaccess: ohne Kommentare, ohne Leerzeilen,
 * ohne Einrückung. Kommentartexte dürfen sich unterscheiden, Direktiven nicht.
 *
 * @return array<int, string>
 */
function hlDirectives(string $content): array
{
    $out = [];
    foreach (preg_split('/\R/', $content) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $out[] = $line;
    }

    return $out;
}

/** Holt das Heredoc HTACCESS aus einer PHP-Quelle. */
function hlHeredoc(string $source, string $file): string
{
    if (!preg_match("/<<<'HTACCESS'\R(.*?)\R\s*HTACCESS;/s", $source, $m)) {
        throw new RuntimeException("Kein HTACCESS-Heredoc in {$file} gefunden");
    }

    // Wie PHP 7.3+ es tut: die Einrückung der Schlusszeile von jeder Zeile abziehen.
    $lines  = preg_split('/\R/', $m[1]) ?: [];
    $indent = '';
    if (preg_match('/\R([ \t]*)HTACCESS;/', $source, $i)) {
        $indent = $i[1];
    }

    return implode("\n", array_map(
        static fn (string $l): string => $indent !== '' && str_starts_with($l, $indent)
            ? substr($l, strlen($indent))
            : $l,
        $lines
    ));
}

$targets = [
    'Update-Assistent' => [
        'file'      => '/public/update/.htaccess',
        'generator' => '/public/update/index.php',
    ],
    'Installer' => [
        'file'      => '/public/install/.htaccess',
        'generator' => '/public/install/index.php',
    ],
];

foreach ($targets as $name => $paths) {
    test("{$name}: Sperre wirkt auf Apache 2.2 und 2.4", function () use ($repoRoot, $paths) {
        $content = (string) file_get_contents($repoRoot . $paths['file']);

        assertTrue(str_contains($content, 'Require all denied'),
            '2.4-Syntax fehlt — auf einem Apache ohne mod_access_compat gibt es einen 500er');
        assertTrue(str_contains($content, 'Deny from all'),
            '2.2-Syntax fehlt — alte Hoster sperren dann nicht');
        assertTrue(str_contains($content, '<IfModule mod_authz_core.c>'),
            'Ohne Wächter laufen beide Syntaxen nebeneinander und eine davon scheitert');
        assertTrue(str_contains($content, '<IfModule !mod_authz_core.c>'),
            'Der Rückfall auf 2.2 braucht seinen eigenen Wächter');
    });

    test("{$name}: die ausgelieferte Datei beschreibt den Auslieferungszustand",
        function () use ($repoRoot, $paths, $name) {
            // Die Datei im Repository ist der Zustand VOR dem Lauf. Bis
            // 2026-09-04 behauptete die des Installers „Installation
            // abgeschlossen" — der Text des Generators, versehentlich
            // eingecheckt. Wer klonte, stand vor einem 403 und einer Datei,
            // die ihm sagte, er sei fertig.
            $content = (string) file_get_contents($repoRoot . $paths['file']);

            assertTrue(!str_contains($content, 'abgeschlossen'),
                "Die ausgelieferte Sperre des {$name} meldet einen Abschluss, der nicht stattgefunden hat");
            assertTrue(str_contains($content, 'standardmäßig gesperrt'),
                'Der Auslieferungszustand muss als solcher benannt sein');
            assertTrue(str_contains($content, 'leeren oder umbenennen'),
                'Ohne Anleitung zum Freischalten bleibt nur ein nacktes Forbidden');
        });

    test("{$name}: Generator schreibt dieselben Direktiven wie die Datei",
        function () use ($repoRoot, $paths) {
            $file      = (string) file_get_contents($repoRoot . $paths['file']);
            $generator = hlHeredoc(
                (string) file_get_contents($repoRoot . $paths['generator']),
                $paths['generator']
            );

            assertSame(hlDirectives($file), hlDirectives($generator),
                'Datei und Generator sind auseinandergelaufen');
        });
}

test('README nennt den Freischaltschritt fuer beide Assistenten', function () use ($repoRoot) {
    // Beide Verzeichnisse werden gesperrt ausgeliefert. Steht der Schritt
    // nicht in der Anleitung, endet die dokumentierte Installation an einem
    // Forbidden — genau so war es bis 2026-09-04 fuer den Installer.
    $readme = (string) file_get_contents($repoRoot . '/README.md');

    foreach (['public/install/.htaccess', 'public/update/.htaccess'] as $path) {
        assertTrue(str_contains($readme, $path),
            "README erwaehnt {$path} nicht — der Freischaltschritt fehlt");
    }
});

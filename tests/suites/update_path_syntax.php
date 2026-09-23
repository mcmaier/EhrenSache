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
 * Der Update-Pfad bleibt auf PHP 8.0 lauffaehig.
 *
 * Eine Installation auf 1.6.0 kennt requires nicht und tauscht blind; nur der
 * Folgeaufruf der NEUEN Version kann dann Anforderungen pruefen und
 * zurueckrollen. Das geht nur, wenn dieser Code auf dem alten PHP ueberhaupt
 * startet (Spec 2026-09-14-direktsprung-requires-design.md, 3.3).
 *
 * Der Test erkennt die typischen Konstrukte nach PHP 8.0, nicht jede Kleinigkeit.
 */

const UPDATE_PATH_FILES = [
    'public/update/index.php',
    'private/helpers/migrations.php',
    'private/helpers/config_reader.php',
    'private/helpers/requirements.php',
    'private/helpers/updater.php',
    'private/helpers/maintenance.php',
    'private/helpers/update_source.php',
    'private/helpers/update_package.php',
    'private/helpers/update_swap.php',
];

/** Liste der gefundenen Konstrukte nach PHP 8.0 als "Konstrukt (Zeile n)". */
function syntaxAfterPhp80(string $code): array
{
    $sig = [];
    foreach (token_get_all($code) as $t) {
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $sig[] = $t;
    }

    $text  = static fn($t): string => is_array($t) ? $t[1] : $t;
    $art   = static fn($t) => is_array($t) ? $t[0] : null;
    $zeile = static function (array $sig, int $i): int {
        for ($j = $i; $j >= 0; $j--) {
            if (is_array($sig[$j])) {
                return $sig[$j][2];
            }
        }
        return 0;
    };

    $funde = [];
    $n     = count($sig);

    for ($i = 0; $i < $n; $i++) {
        $t = $sig[$i];

        if (defined('T_ENUM') && $art($t) === T_ENUM) {
            $funde[] = 'enum (Zeile ' . $zeile($sig, $i) . ')';
        }
        if (defined('T_READONLY') && $art($t) === T_READONLY) {
            $funde[] = 'readonly (Zeile ' . $zeile($sig, $i) . ')';
        }
        if ($art($t) === T_ELLIPSIS && $text($sig[$i - 1] ?? '') === '(' && $text($sig[$i + 1] ?? '') === ')') {
            $funde[] = 'foo(...) (Zeile ' . $zeile($sig, $i) . ')';
        }

        // Typisierte Klassenkonstante: const Typ NAME = ...
        if ($art($t) === T_CONST) {
            $namen = 0;
            for ($j = $i + 1; $j < $n && $text($sig[$j]) !== '='; $j++) {
                if ($art($sig[$j]) === T_STRING) {
                    $namen++;
                }
            }
            if ($namen >= 2) {
                $funde[] = 'typisierte Konstante (Zeile ' . $zeile($sig, $i) . ')';
            }
        }

        // Funktionssignaturen: Parameterliste und Rueckgabetyp
        if ($art($t) !== T_FUNCTION && $art($t) !== T_FN) {
            continue;
        }
        $auf = $i + 1;
        while ($auf < $n && $text($sig[$auf]) !== '(') {
            $auf++;
        }
        $tiefe = 0;
        $zu    = $auf;
        for (; $zu < $n; $zu++) {
            if ($text($sig[$zu]) === '(') {
                $tiefe++;
            } elseif ($text($sig[$zu]) === ')') {
                $tiefe--;
                if ($tiefe === 0) {
                    break;
                }
            }
        }

        // Parameter
        for ($j = $auf + 1; $j < $zu; $j++) {
            if ($art($sig[$j]) === T_NEW) {
                $funde[] = 'new in Parameter-Vorgabe (Zeile ' . $zeile($sig, $j) . ')';
            }
            if (defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG') && $art($sig[$j]) === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) {
                $funde[] = 'Schnittmengentyp (Zeile ' . $zeile($sig, $j) . ')';
            }
            if ($art($sig[$j]) === T_VARIABLE) {
                // Typ direkt vor der Variablen, rueckwaerts bis , oder (
                $typ = [];
                for ($k = $j - 1; $k > $auf && !in_array($text($sig[$k]), [',', '('], true); $k--) {
                    if ($art($sig[$k]) === T_STRING) {
                        $typ[] = strtolower($text($sig[$k]));
                    }
                }
                if (in_array('true', $typ, true) || $typ === ['false'] || $typ === ['null']) {
                    $funde[] = 'Typ true/false/null (Zeile ' . $zeile($sig, $j) . ')';
                }
            }
        }

        // Rueckgabetyp: : Typ bis { ; oder =>
        if ($text($sig[$zu + 1] ?? '') === ':') {
            $typ = [];
            for ($k = $zu + 2; $k < $n && !in_array($text($sig[$k]), ['{', ';', '=>'], true); $k++) {
                if ($art($sig[$k]) === T_STRING) {
                    $typ[] = strtolower($text($sig[$k]));
                }
                if ($text($sig[$k]) === '(') {
                    $funde[] = 'DNF-Typ (Zeile ' . $zeile($sig, $k) . ')';
                }
            }
            if (in_array('never', $typ, true)) {
                $funde[] = 'Rueckgabetyp never (Zeile ' . $zeile($sig, $zu) . ')';
            }
            if (in_array('true', $typ, true) || $typ === ['false'] || $typ === ['null']) {
                $funde[] = 'Rueckgabetyp true/false/null (Zeile ' . $zeile($sig, $zu) . ')';
            }
        }
    }

    return $funde;
}

test('Die Syntaxpruefung erkennt Konstrukte nach PHP 8.0', function () {
    $faelle = [
        'enum'                  => "<?php enum Farbe { case Rot; }",
        'readonly'              => "<?php class A { public readonly int \$x; }",
        'foo(...)'              => "<?php \$f = strlen(...);",
        'typisierte Konstante'  => "<?php class A { const string NAME = 'x'; }",
        'new in Parameter'      => "<?php function f(\$x = new DateTime()) {}",
        'Schnittmengentyp'      => "<?php function f(Countable&Iterator \$x) {}",
        'Rueckgabetyp never'    => "<?php function f(): never { exit; }",
        'Rueckgabetyp null'     => "<?php function f(): null { return null; }",
        'Rueckgabetyp true'     => "<?php function f(): true { return true; }",
        'Parametertyp false'    => "<?php function f(false \$x) {}",
    ];
    foreach ($faelle as $name => $code) {
        assertTrue(syntaxAfterPhp80($code) !== [], "Nicht erkannt: {$name}");
    }
});

test('Die Syntaxpruefung laesst Syntax bis PHP 8.0 durch', function () {
    $code = "<?php\n"
        . "const LISTE = ['a', 'b'];\n"
        . "function f(?string \$a, string|false \$b, int|null \$c = null, &\$ref = null, ...\$rest): string|false { return \$a ?? false; }\n"
        . "\$x = \$a ? foo() : null;\n"
        . "\$y = \$b & \$c;\n"
        . "\$z = match (\$a) { 1 => 'eins', default => null };\n"
        . "\$w = \$obj?->wert;\n"
        . "\$g = static fn(string \$s): int => strlen(\$s);\n"
        . "f(a: 1, b: false);\n";

    assertSame([], syntaxAfterPhp80($code));
});

test('Der Update-Pfad enthaelt keine Syntax nach PHP 8.0', function () {
    $wurzel = dirname(__DIR__, 2);
    foreach (UPDATE_PATH_FILES as $rel) {
        $pfad = "{$wurzel}/{$rel}";
        assertTrue(is_file($pfad), "Datei des Update-Pfads fehlt: {$rel} -- Liste in dieser Suite pflegen");
        assertSame([], syntaxAfterPhp80((string) file_get_contents($pfad)), $rel);
    }
});

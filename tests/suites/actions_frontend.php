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
 * Aktionen im Markup und in der Aktionstabelle stimmen ueberein (OI-17 Etappe 2).
 *
 * Ein Knopf, dessen Aktion niemand registriert, tut nichts -- und keine andere
 * Suite merkt es. Eine registrierte Aktion, die kein Markup benutzt, ist toter
 * Code, der beim naechsten Umbau fuer lebend gehalten wird. Deshalb in beide
 * Richtungen.
 *
 * Gelesen wird ueber sourceCode(): Ein auskommentierter Knopf zaehlt nicht.
 * Die Registrierung wird nur in der festen Form erkannt, die der Plan
 * vorschreibt (ein registerActions({ ... }); je Datei, Spalte 0, ein Schluessel
 * je Zeile) -- jede andere Form meldet der letzte Test.
 */

$acRoot = dirname(__DIR__, 2);

/** Dateien des Dashboards, in denen Markup mit Aktionen stehen kann. */
function acFiles(string $root): array
{
    $files = glob($root . '/public/js/modules/*.js') ?: [];
    sort($files);

    return array_merge([$root . '/public/index.html', $root . '/public/js/app.js'], $files);
}

/** @return array<string, string[]> Aktionsname => Fundstellen "datei:zeile" */
function acUsedActions(string $root): array
{
    $used = [];
    foreach (acFiles($root) as $file) {
        $src = sourceCode($file);
        if (preg_match_all('/\bdata-action(?:-change|-submit)?="([^"]*)"/', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$name, $off]) {
                $used[$name][] = basename($file) . ':' . (substr_count($src, "\n", 0, $off) + 1);
            }
        }
    }

    return $used;
}

/** @return array<string, string[]> Aktionsname => Dateien, die ihn registrieren */
function acRegisteredActions(string $root): array
{
    $registered = [];
    foreach (acFiles($root) as $file) {
        if (str_ends_with($file, '.html')) {
            continue;
        }
        $src = sourceCode($file);
        if (!preg_match_all('/^registerActions\(\{\R(.*?)^\}\);/ms', $src, $blocks)) {
            continue;
        }
        foreach ($blocks[1] as $block) {
            preg_match_all("/^\s+'([^']+)':/m", $block, $keys);
            foreach ($keys[1] as $name) {
                $registered[$name][] = basename($file);
            }
        }
    }

    return $registered;
}

test('Aktionsnamen im Markup sind Literale in Kebab-Form', function () use ($acRoot) {
    // data-action="${x}" machte diesen Abgleich blind: Welche Aktion ein
    // Knopf ausloest, stuende erst zur Laufzeit fest.
    $bad = [];
    foreach (acUsedActions($acRoot) as $name => $where) {
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $name) !== 1) {
            $bad[] = "\"{$name}\" in " . implode(', ', $where);
        }
    }
    assertTrue($bad === [], "Aktionsname kein Literal in Kebab-Form:\n  " . implode("\n  ", $bad));
});

test('Jede Aktion im Markup ist registriert', function () use ($acRoot) {
    $registered = acRegisteredActions($acRoot);
    $missing = [];
    foreach (acUsedActions($acRoot) as $name => $where) {
        if (!isset($registered[$name])) {
            $missing[] = "{$name} (" . implode(', ', $where) . ')';
        }
    }
    assertTrue($missing === [], "Knopf ohne registrierte Aktion -- er taete nichts:\n  " . implode("\n  ", $missing));
});

test('Jede registrierte Aktion wird im Markup benutzt', function () use ($acRoot) {
    $used = acUsedActions($acRoot);
    $dead = [];
    foreach (acRegisteredActions($acRoot) as $name => $files) {
        if (!isset($used[$name])) {
            $dead[] = "{$name} (" . implode(', ', $files) . ')';
        }
    }
    assertTrue($dead === [], "Registriert, aber von keinem Knopf benutzt:\n  " . implode("\n  ", $dead));
});

test('Jede Aktion ist genau einmal registriert', function () use ($acRoot) {
    $twice = [];
    foreach (acRegisteredActions($acRoot) as $name => $files) {
        if (count($files) > 1) {
            $twice[] = "{$name} in " . implode(', ', $files);
        }
    }
    assertTrue($twice === [], "Doppelt registriert -- registerActions() wirft beim Laden:\n  " . implode("\n  ", $twice));
});

test('registerActions steht ueberall in der auswertbaren Form', function () use ($acRoot) {
    $bad = [];
    foreach (acFiles($acRoot) as $file) {
        if (str_ends_with($file, '.html') || str_ends_with($file, '/actions.js')) {
            continue;
        }
        $src = sourceCode($file);
        $calls = preg_match_all('/\bregisterActions\s*\(/', $src);
        $imports = preg_match_all('/^import\s*\{[^}]*\bregisterActions\b/m', $src);
        $blocks = preg_match_all('/^registerActions\(\{\R.*?^\}\);/ms', $src);
        if ($calls - $imports !== $blocks || $blocks > 1) {
            $bad[] = basename($file) . ": {$calls} Aufrufe, {$blocks} auswertbare Bloecke";
        }
    }
    assertTrue($bad === [], "registerActions() nicht in der festen Form (ein Block, Spalte 0):\n  " . implode("\n  ", $bad));
});

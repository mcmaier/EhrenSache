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
        // Der Import enthaelt keine Klammer und zaehlt daher nicht als Aufruf.
        $blocks = preg_match_all('/^registerActions\(\{\R.*?^\}\);/ms', $src);
        if ($calls !== $blocks || $blocks > 1) {
            $bad[] = basename($file) . ": {$calls} Aufrufe, {$blocks} auswertbare Bloecke";
        }
    }
    assertTrue($bad === [], "registerActions() nicht in der festen Form (ein Block, Spalte 0):\n  " . implode("\n  ", $bad));
});

/**
 * Browser-Schnittstellen, die das Dashboard ueber window anspricht. Alles
 * andere hinter window. waere eine Anwendungsfunktion auf dem Umweg ueber den
 * globalen Namensraum -- genau der Weg, den Etappe 2 abgeraeumt hat (Task 19).
 * Ein Aufruf wie window.openResponsesModal?.(id) liefe nach dem Abraeumen
 * still ins Leere; deshalb Import statt window. Erweitern nur um echte
 * Browser-APIs, nie um eigene Funktionen.
 */
const AC_WINDOW_ALLOWED = ['addEventListener', 'localStorage', 'location', 'matchMedia', 'open', 'URL'];

test('Dashboard-Module legen nichts auf window ab und rufen nichts ueber window', function () use ($acRoot) {
    $bad = [];
    foreach (acFiles($acRoot) as $file) {
        if (str_ends_with($file, '.html')) {
            continue;
        }
        $src = sourceCode($file);
        $name = basename($file);
        $line = static fn (int $off): int => substr_count($src, "\n", 0, $off) + 1;

        // Zuweisung an ein window-Mitglied, auch an ein erlaubtes (window.open = ...).
        if (preg_match_all('/\b(?:window|globalThis)\s*(?:\.\s*[A-Za-z_$][\w$]*|\[[^\]]*\])\s*=(?!=)/', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$hit, $off]) {
                $bad[] = "{$name}:{$line($off)} Zuweisung {$hit}";
            }
        }
        // Zugriff per Index: window['x'], window[name] -- nicht pruefbar, also verboten.
        if (preg_match_all('/\b(?:window|globalThis)\s*\??\.?\s*\[/', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$hit, $off]) {
                $bad[] = "{$name}:{$line($off)} Indexzugriff {$hit}";
            }
        }
        // Jedes andere Mitglied als die erlaubten Browser-APIs, ob Aufruf
        // (window.x(), window.x?.()) oder Lesen (window.x.y).
        if (preg_match_all('/\b(?:window|globalThis)\s*\??\.\s*([A-Za-z_$][\w$]*)/', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$member, $off]) {
                if (!in_array($member, AC_WINDOW_ALLOWED, true)) {
                    $bad[] = "{$name}:{$line($off)} window.{$member}";
                }
            }
        }
    }
    assertTrue($bad === [], "Anwendungsfunktion ueber window statt Import/Aktion:\n  " . implode("\n  ", $bad));
});

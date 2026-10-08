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
 * App-Rahmen der Check-in-PWA im Service Worker (OI-43, Stufe 1).
 *
 * Der Service Worker haelt eine feste Liste von Rahmendateien vor. Zwei Dinge
 * gehen dabei erfahrungsgemaess schief und sollen hier auffallen statt beim
 * Mitglied:
 *
 * - Die Liste passt nicht zu dem, was index.html laedt. 2025 zeigten absolute
 *   Pfade ('/index.html') nach dem Umbau auf die Web-Root public/ ins
 *   Dashboard, cache.addAll() scheiterte an einer 404, und die
 *   Zwischenspeicherung wurde abgeschaltet statt repariert.
 * - VERSION wird beim Release vergessen. Dann bleibt der Speichername gleich,
 *   kein neuer Service Worker wird installiert, und jedes Telefon behaelt den
 *   alten Rahmen — genau wie ein vergessenes ?v=, nur ohne Ausweg per Neuladen.
 */

$repoRoot = dirname(__DIR__, 2);

/** Version aus version.json. */
function pwaVersion(string $root): string
{
    return (string) json_decode(sourceCode($root . '/version.json'), true)['version'];
}

/**
 * Vorladeliste des Service Workers, ${VERSION} eingesetzt.
 *
 * @return string[]
 */
function pwaShellList(string $sw, string $version): array
{
    if (!preg_match('/const\s+SHELL\s*=\s*\[(.*?)\];/s', $sw, $m)) {
        return [];
    }
    preg_match_all('/[\'"`]([^\'"`]+)[\'"`]/', $m[1], $entries);

    return array_map(fn(string $e) => str_replace('${VERSION}', $version, $e), $entries[1]);
}

/**
 * Lokale Dateien, die checkin/index.html per <link>, <script> oder <img> laedt.
 * Verweise per <a> sind Navigation, kein Rahmen.
 *
 * @return string[]
 */
function pwaIndexAssets(string $html): array
{
    preg_match_all('/<(?:link|script|img)\b[^>]*\b(?:href|src)="([^"]+)"/i', $html, $m);

    return array_values(array_unique(array_filter($m[1],
        fn(string $u) => !preg_match('#^(?:[a-z]+:|//|\#)#i', $u))));
}

/** @return string[] */
function pwaManifestIcons(string $root): array
{
    $manifest = json_decode(sourceCode($root . '/public/checkin/manifest.json'), true);

    return array_map(fn(array $i) => (string) $i['src'], $manifest['icons'] ?? []);
}

test('Service Worker: VERSION entspricht version.json', function () use ($repoRoot) {
    $sw = sourceCode($repoRoot . '/public/checkin/service-worker.js');
    assertTrue((bool) preg_match("/const\s+VERSION\s*=\s*'([^']+)'/", $sw, $m),
        'const VERSION fehlt im Service Worker');
    assertSame(pwaVersion($repoRoot), $m[1],
        'VERSION im Service Worker muss beim Versionssprung mitgezogen werden');
});

test('Vorladeliste: jede Rahmendatei aus checkin/index.html steht darin', function () use ($repoRoot) {
    $version = pwaVersion($repoRoot);
    $shell   = pwaShellList(sourceCode($repoRoot . '/public/checkin/service-worker.js'), $version);
    assertTrue($shell !== [], 'const SHELL = [...] nicht gefunden');

    foreach (pwaIndexAssets(sourceCode($repoRoot . '/public/checkin/index.html')) as $asset) {
        assertTrue(in_array($asset, $shell, true),
            "index.html laedt '{$asset}', die Vorladeliste kennt es nicht — offline fehlt es");
    }
});

test('Vorladeliste: jeder Eintrag wird wirklich gebraucht', function () use ($repoRoot) {
    $version = pwaVersion($repoRoot);
    $shell   = pwaShellList(sourceCode($repoRoot . '/public/checkin/service-worker.js'), $version);
    $needed  = array_merge(
        ['index.html'],
        pwaIndexAssets(sourceCode($repoRoot . '/public/checkin/index.html')),
        pwaManifestIcons($repoRoot)
    );

    foreach ($shell as $entry) {
        assertTrue(in_array($entry, $needed, true),
            "Vorladeliste enthaelt '{$entry}', das weder index.html noch das Manifest laedt");
    }
});

test('Vorladeliste: nur relative Pfade, keine API', function () use ($repoRoot) {
    $shell = pwaShellList(sourceCode($repoRoot . '/public/checkin/service-worker.js'), pwaVersion($repoRoot));

    foreach ($shell as $entry) {
        assertTrue($entry[0] !== '/', "'{$entry}' ist absolut — zeigte 2025 ins Dashboard");
        assertTrue(!str_contains($entry, 'api/'), "'{$entry}': API-Antworten gehoeren nicht in den Rahmen");
    }
});

test('Vorladeliste: jeder Eintrag existiert als Datei', function () use ($repoRoot) {
    $shell = pwaShellList(sourceCode($repoRoot . '/public/checkin/service-worker.js'), pwaVersion($repoRoot));

    foreach ($shell as $entry) {
        $path = $repoRoot . '/public/checkin/' . preg_replace('/\?.*$/', '', $entry);
        assertTrue(is_file($path), "'{$entry}' gibt es nicht — cache.addAll() scheiterte an der 404");
    }
});

test('install: skipWaiting nur ohne vorhandenen Speicher', function () use ($repoRoot) {
    $sw = sourceCode($repoRoot . '/public/checkin/service-worker.js');

    assertTrue((bool) preg_match('/if\s*\(\s*!hadCache\s*\)\s*\{\s*await\s+self\.skipWaiting\(\);/', $sw),
        'Erstinstallation/Umstieg muss sofort uebernehmen (if (!hadCache) { await self.skipWaiting(); })');

    $rest = preg_replace('/if\s*\(\s*!hadCache\s*\)\s*\{\s*await\s+self\.skipWaiting\(\);/', '', $sw);
    $rest = preg_replace("/if\s*\([^)]*'SKIP_WAITING'\s*\)\s*\{?\s*self\.skipWaiting\(\);/", '', $rest);
    assertTrue(!str_contains($rest, 'skipWaiting('),
        'Ein unbedingtes skipWaiting() uebergeht die Hinweisleiste — die neue Version muss warten');
});

test('fetch: Anfragen ausserhalb des Rahmens gehen unberuehrt ans Netz', function () use ($repoRoot) {
    $sw = sourceCode($repoRoot . '/public/checkin/service-worker.js');

    assertTrue(!str_contains($sw, 'respondWith(fetch('),
        'respondWith(fetch(...)) schleust jede Anfrage durch den Worker — nur der Rahmen darf beantwortet werden');
    assertTrue(str_contains($sw, 'SHELL_URLS.has('),
        'Der fetch-Handler muss gegen die Vorladeliste pruefen');
});

test('Hinweisleiste: Markup in index.html', function () use ($repoRoot) {
    $html = sourceCode($repoRoot . '/public/checkin/index.html');

    assertTrue((bool) preg_match('/<div[^>]*\bid="updateBanner"[^>]*\bhidden\b/', $html),
        'id="updateBanner" muss versteckt im Markup stehen');
    assertTrue(str_contains($html, 'id="updateReloadBtn"'), 'Knopf id="updateReloadBtn" fehlt');
});

test('Hinweisleiste: in app.js verdrahtet', function () use ($repoRoot) {
    $js = sourceCode($repoRoot . '/public/checkin/js/app.js');

    assertTrue(str_contains($js, "getElementById('updateReloadBtn')?.addEventListener('click', applyUpdate)"),
        'Knopf muss applyUpdate ausloesen');
    assertTrue(str_contains($js, "postMessage({ type: 'SKIP_WAITING' })"),
        'applyUpdate muss den wartenden Worker aktivieren');
    assertTrue(str_contains($js, "addEventListener('controllerchange'"),
        'Nach dem Wechsel muss die Seite neu laden');
    assertTrue((bool) preg_match('/swRegistration\?\.update\(\)/', $js),
        'Beim Zurueckkehren muss nach einer neuen Version gesucht werden (tagelang offene App)');
});

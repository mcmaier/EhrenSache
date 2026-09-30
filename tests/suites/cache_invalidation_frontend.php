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
 * invalidateCache() in ui.js: globale gegen jahresabhaengige Eintraege.
 *
 * dataCache kennt zwei Formen, beide Objekte: global { data, timestamp }
 * (groups, types, userData ...) und jahresabhaengig { <jahr>: { data, timestamp } }
 * (members, appointments ...). Die Zweigwahl fragte nur „Objekt und kein Array?“
 * und schickte damit auch globale Eintraege in die Schleife „alle Jahre“:
 * invalidateCache('groups') machte aus data und timestamp je ein Objekt
 * { data: [], timestamp: null }. Dass der Cache danach trotzdem als ungueltig
 * galt, lag nur an Date.now() - {} = NaN in isCacheValid().
 */

$ciRoot = dirname(__DIR__, 2);

/** Rumpf einer Funktion bis zur naechsten Funktionsdefinition auf oberster Ebene. */
function ciFunktion(string $js, string $name): string
{
    $start = strpos($js, 'function ' . $name . '(');
    assertTrue($start !== false, $name . '() nicht gefunden');

    if (preg_match('/\n(?:export\s+)?(?:async\s+)?function\s/', $js, $m, PREG_OFFSET_CAPTURE, $start + 1)) {
        return substr($js, $start, $m[0][1] - $start);
    }

    return substr($js, $start);
}

test('Cache: globaler Eintrag wird am Schluessel data/timestamp erkannt', function () use ($ciRoot) {
    $js = (string) sourceCode($ciRoot . '/public/js/modules/ui.js');
    $rumpf = ciFunktion($js, 'isGlobalCacheEntry');

    // Ein leerer jahresabhaengiger Eintrag ist {}, ein geleerter globaler traegt
    // data = [] — nur das Vorhandensein des Schluessels trennt beide sicher,
    // nicht der Typ und nicht die Truthiness von data.
    assertTrue(preg_match('/return\s+\'data\'\s+in\s+entry\s*\|\|\s*\'timestamp\'\s+in\s+entry\s*;/', $rumpf) === 1,
        'isGlobalCacheEntry() prueft nicht auf den Schluessel data bzw. timestamp');
});

test('Cache: invalidateCache(key) leert globale Eintraege direkt, nur jahresabhaengige je Jahr', function () use ($ciRoot) {
    $js = (string) sourceCode($ciRoot . '/public/js/modules/ui.js');
    $rumpf = ciFunktion($js, 'invalidateCache');

    assertTrue(preg_match(
        '/else\s+if\s*\(\s*isGlobalCacheEntry\(\s*entry\s*\)\s*\)\s*\{\s*'
        . 'entry\.data\s*=\s*\[\]\s*;\s*entry\.timestamp\s*=\s*null\s*;\s*\}\s*'
        . 'else\s*\{\s*Object\.keys\(\s*entry\s*\)\.forEach/',
        $rumpf) === 1,
        'Ohne Jahr muss ein globaler Eintrag direkt geleert werden und nur ein jahresabhaengiger in die Jahresschleife gehen');

    // Die alte Zweigwahl („Objekt und kein Array“) trifft auch globale Eintraege.
    assertTrue(!str_contains($rumpf, 'Array.isArray'),
        'invalidateCache() unterscheidet wieder ueber Array.isArray — das trifft globale Eintraege mit');
});

test('Cache: invalidateCache() ohne Schluessel waehlt den Zweig ueber dieselbe Pruefung', function () use ($ciRoot) {
    $js = (string) sourceCode($ciRoot . '/public/js/modules/ui.js');
    $rumpf = ciFunktion($js, 'invalidateCache');

    assertTrue(preg_match(
        '/Object\.keys\(\s*dataCache\s*\)\.forEach\(\s*key\s*=>\s*\{\s*'
        . 'if\s*\(\s*isGlobalCacheEntry\(\s*dataCache\[key\]\s*\)\s*\)\s*\{\s*'
        . 'dataCache\[key\]\.data\s*=\s*\[\]\s*;\s*dataCache\[key\]\.timestamp\s*=\s*null\s*;\s*\}\s*'
        . 'else\s*\{\s*dataCache\[key\]\s*=\s*\{\s*\}\s*;/',
        $rumpf) === 1,
        'Ohne Schluessel muss jeder Eintrag ueber isGlobalCacheEntry() zugeordnet werden');

    // Truthiness von data unterscheidet nicht nach Form (settings traegt data = {}).
    assertTrue(preg_match('/!\s*dataCache\[key\]\.data\b/', $rumpf) !== 1,
        'invalidateCache() prueft data wieder auf Truthiness statt auf den Schluessel');
});

// ============================================
// Bereichswechsel und gleichzeitige Abrufe
// ============================================
//
// loadAllData() rief jeden Bereich mit forceReload = true auf: Mitglieder,
// Termine, Antraege und Terminarten kamen bei jedem Klick von der API, der
// Cache wirkte nur fuer Bereiche, die das Flag zufaellig nicht weiterreichten.
// Beim Jahreswechsel luden Filter-Reset und Bereichsaufbau dasselbe Jahr doppelt.

test('Bereichswechsel: loadAllData() erzwingt kein Neuladen', function () use ($ciRoot) {
    $js = (string) sourceCode($ciRoot . '/public/js/modules/ui.js');
    $rumpf = ciFunktion($js, 'loadAllData');

    assertTrue(preg_match_all('/\bawait\s+(?:show\w+Section|loadProfile)\(\s*\)/', $rumpf) >= 10,
        'loadAllData() ruft die Bereiche nicht mehr wie erwartet auf');
    assertTrue(preg_match('/\b(?:show\w+Section|loadProfile)\(\s*true\b/', $rumpf) !== 1,
        'loadAllData() erzwingt wieder ein Neuladen — der Cache wirkt beim Bereichswechsel nicht');
});

test('Bereichswechsel: CACHE_TTL ist hoechstens zwei Minuten', function () use ($ciRoot) {
    $js = (string) sourceCode($ciRoot . '/public/js/modules/ui.js');

    // Ohne erzwungenes Neuladen ist die TTL das Einzige, was fremde Aenderungen
    // ins Dashboard bringt (OI-67).
    assertTrue(preg_match('/const\s+CACHE_TTL\s*=\s*(\d+)\s*\*\s*60\s*\*\s*1000\s*;/', $js, $m) === 1,
        'CACHE_TTL nicht in der Form <Minuten> * 60 * 1000 gefunden');
    assertTrue((int) $m[1] >= 1 && (int) $m[1] <= 2,
        'CACHE_TTL ist ' . $m[1] . ' Minuten — fremde Aenderungen kaemen zu spaet an');
});

test('Gleichzeitige Abrufe: invalidateCache() vergisst laufende Abrufe', function () use ($ciRoot) {
    $js = (string) sourceCode($ciRoot . '/public/js/modules/ui.js');
    $rumpf = ciFunktion($js, 'invalidateCache');

    // Sonst haengt sich ein Aufruf nach einer eigenen Aenderung an eine Anfrage
    // von davor und bekommt den alten Stand.
    assertTrue(preg_match('/\{\s*forgetPendingLoads\(\s*cacheKey\s*,\s*year\s*\)\s*;/', $rumpf) === 1,
        'invalidateCache() ruft forgetPendingLoads(cacheKey, year) nicht als Erstes');
});

test('Gleichzeitige Abrufe: die Loader gehen ueber sharedLoad()', function () use ($ciRoot) {
    $loader = [
        ['members.js',      'loadMembers',      '`members:${year}`'],
        ['appointments.js', 'loadAppointments', '`appointments:${year}`'],
        ['records.js',      'loadRecords',      '`records:${year}`'],
        ['exceptions.js',   'loadExceptions',   '`exceptions:${year}`'],
        ['management.js',   'loadGroups',       "'groups'"],
        ['management.js',   'loadTypes',        "'types'"],
        ['users.js',        'loadUsers',        "'users'"],
        ['users.js',        'loadUserData',     "'userData'"],
        ['devices.js',      'loadDevices',      "'devices'"],
    ];

    foreach ($loader as [$datei, $funktion, $schluessel]) {
        $js = (string) sourceCode($ciRoot . '/public/js/modules/' . $datei);
        $rumpf = ciFunktion($js, $funktion);

        // Der Schluessel muss dem Cache-Schluessel entsprechen, sonst trifft
        // forgetPendingLoads() aus invalidateCache() ihn nicht.
        assertTrue(str_contains($rumpf, 'return sharedLoad(' . $schluessel . ', forceReload, async () => {'),
            $funktion . '() laedt nicht ueber sharedLoad(' . $schluessel . ', forceReload, ...)');
        assertTrue(substr_count($rumpf, 'apiCall(') === substr_count(
                substr($rumpf, (int) strpos($rumpf, 'return sharedLoad(')), 'apiCall('),
            $funktion . '() ruft die API ausserhalb von sharedLoad()');
    }
});

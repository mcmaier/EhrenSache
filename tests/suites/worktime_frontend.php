<?php
declare(strict_types=1);

/**
 * Statische Gegenproben am Frontend der Zeiterfassung.
 *
 * Dashboard und Check-in-PWA sind Vanilla-JS ohne eigenen Testlauf. Pruefbar
 * bleibt die Herkunft der Daten: WOHER eine Ansicht ihre Mitgliederliste nimmt,
 * MIT WELCHER Eingrenzung sie Sitzungen abruft und WAS die Abmeldung wieder
 * abraeumt. Genau daran hingen die drei Fehler, die diese Suite festhaelt.
 */

$repoRoot = dirname(__DIR__, 2);

/**
 * Liefert den Rumpf einer JS-Funktion — von der Deklaration bis zur
 * schliessenden Klammer am Zeilenanfang. Reicht fuer die flache
 * Funktionsebene, in der das PWA-Skript geschrieben ist.
 */
function frontendFunctionBody(string $js, string $name): string
{
    $start = strpos($js, "function {$name}(");
    assertTrue($start !== false, "Funktion {$name}() nicht gefunden");

    $ende = strpos($js, "\n}", $start);

    return substr($js, $start, ($ende === false ? 2000 : $ende - $start));
}

test('Zeiterfassung: die Mitgliederauswahl laedt ihre Liste selbst', function () use ($repoRoot) {
    $js = (string) file_get_contents($repoRoot . '/public/js/modules/worktime.js');

    // showSection() fuellt den Mitglieder-Cache erst 500 ms nach dem Wechsel im
    // Hintergrund. Wer ihn direkt liest, baut die Auswahl beim ERSTEN Oeffnen
    // des Bereichs aus einem leeren Cache — der Filter bleibt dann wirkungslos,
    // bis irgendwo sonst die Mitgliederliste geladen wurde.
    assertTrue(
        strpos($js, 'dataCache.members') === false,
        'worktime.js liest dataCache.members direkt — beim ersten Oeffnen ist der Cache leer'
    );

    assertTrue(
        preg_match('/import\s*\{[^}]*\bloadMembers\b[^}]*\}\s*from\s*[\'"]\.\/members\.js[\'"]/', $js) === 1,
        'worktime.js importiert loadMembers nicht aus members.js'
    );
});

test('Check-in-PWA: der Verlauf holt nur die eigenen Arbeitszeiten', function () use ($repoRoot) {
    $js = (string) file_get_contents($repoRoot . '/public/checkin/js/app.js');

    // Ohne member_id liefert die Ressource einem Admin oder Manager die
    // Sitzungen ALLER Mitglieder (so gewollt fuers Dashboard, siehe
    // work_sessions.php). Die PWA ist die persoenliche Sicht eines Mitglieds —
    // hier muss die Eingrenzung immer mit.
    preg_match_all(
        "/apiCall\(\s*'work_sessions'\s*,\s*'GET'/",
        $js,
        $m,
        PREG_OFFSET_CAPTURE
    );

    assertTrue(count($m[0]) > 0, 'Kein lesender work_sessions-Aufruf in der PWA gefunden');

    foreach ($m[0] as [$treffer, $offset]) {
        // Der Aufruf endet am naechsten Semikolon — die Argumente stehen bei
        // mehrzeiligen Aufrufen sonst ausserhalb des Treffers.
        $ende = strpos($js, ';', $offset);
        $call = substr($js, $offset, ($ende === false ? 200 : $ende - $offset));

        assertTrue(
            strpos($call, 'member_id') !== false || strpos($call, 'running') !== false,
            'work_sessions-Abruf ohne Eingrenzung auf das eigene Mitglied: '
            . preg_replace('/\s+/', ' ', trim($call))
        );
    }
});

test('Check-in-PWA: die Abmeldung raeumt die Ansichten des Mitglieds ab', function () use ($repoRoot) {
    $js = (string) file_get_contents($repoRoot . '/public/checkin/js/app.js');

    // Auf einem geteilten Geraet meldet sich nach dem Admin ein Mitglied an.
    // Bleiben Verlauf, Statistik und Anwesenheitsliste gerendert stehen, sieht
    // das Mitglied fremde Daten — bis irgendwann ein Reload dazwischenkommt.
    $abmelden = frontendFunctionBody($js, 'handleLogout');
    assertTrue(
        strpos($abmelden, 'resetSessionState()') !== false,
        'handleLogout raeumt den Sitzungszustand nicht ab (resetSessionState fehlt)'
    );

    $reset = frontendFunctionBody($js, 'resetSessionState');

    foreach (['userData = null', 'historyList', 'worktimeActivities'] as $spur) {
        assertTrue(
            strpos($reset, $spur) !== false,
            "resetSessionState setzt '{$spur}' nicht zurueck"
        );
    }
});

test('Check-in-PWA: die Anmeldung haengt keine Ereignisse doppelt an', function () use ($repoRoot) {
    $js = (string) file_get_contents($repoRoot . '/public/checkin/js/app.js');

    // Diese fuenf laufen bei JEDER Anmeldung erneut (handleLogin und
    // checkAutoLogin, initWorktime und initAttendanceList ueber loadUserData),
    // die Elemente im Dokument bleiben dieselben. Ein direktes addEventListener
    // mit einer Arrow-Function haengt dort nach jedem Zyklus Abmelden →
    // Anmelden einen weiteren Handler an: ein Klick auf „Start" schickte zwei
    // Anfragen, einer auf „naechstes Jahr" spraenge zwei Jahre weit.
    // bindOnce() sperrt das.
    $einstiege = [
        'initTabs', 'initCaptureTab', 'initYearNavigation',
        'initWorktime', 'initAttendanceList',
    ];

    foreach ($einstiege as $name) {
        $body = frontendFunctionBody($js, $name);

        assertTrue(
            strpos($body, 'addEventListener') === false,
            "{$name}() bindet direkt per addEventListener — die Funktion laeuft bei "
            . 'jeder Anmeldung erneut, das Ereignis haengt danach mehrfach'
        );
    }
});

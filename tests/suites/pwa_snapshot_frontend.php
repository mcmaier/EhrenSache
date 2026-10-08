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
 * "Letzter Stand" der Check-in-App (OI-43, Stufe 2): Verdrahtung.
 *
 * Die Logik pruefen die Node-Tests (pwa_snapshot_unit). Hier steht, was dort
 * nicht zu sehen ist: dass der Schnappschuss mit dem Token verschwindet, dass
 * er nur nach erfolgreichem Laden geschrieben wird, dass Knoepfe und
 * Bildschirm verdrahtet sind -- und dass snapshot.js kein Markup aus Daten baut.
 */

$snapRepo = dirname(__DIR__, 2);

/** Rumpf einer Funktion (von "function $name(" bis zur ersten "\n}" in Spalte 0). */
function snapFunctionBody(string $src, string $name): string
{
    $start = strpos($src, "function {$name}(");
    if ($start === false) {
        return '';
    }
    $end = strpos($src, "\n}", $start);

    return $end === false ? '' : substr($src, $start, $end - $start);
}

test('Token wird nur ueber forgetSavedLogin() geloescht, und mit ihm der Schnappschuss', function () use ($snapRepo) {
    $js = str_replace("\r\n", "\n", sourceCode($snapRepo . '/public/checkin/js/app.js'));

    $body = snapFunctionBody($js, 'forgetSavedLogin');
    assertTrue(str_contains($body, "localStorage.removeItem('api_token')"), 'forgetSavedLogin() muss den Token loeschen');
    assertTrue(str_contains($body, 'clearSnapshot()'), 'forgetSavedLogin() muss den Schnappschuss loeschen');
    assertSame(1, substr_count($js, "removeItem('api_token')"),
        "Token wird ausserhalb von forgetSavedLogin() geloescht — der Schnappschuss bliebe liegen");
});

test('Schnappschuss nur mit gespeichertem Token', function () use ($snapRepo) {
    $js = str_replace("\r\n", "\n", sourceCode($snapRepo . '/public/checkin/js/snapshot.js'));
    $body = snapFunctionBody($js, 'saveSnapshotPart');

    $check = strpos($body, "getItem('api_token')");
    $write = strpos($body, 'setItem(');
    assertTrue($check !== false && $write !== false && $check < $write,
        'saveSnapshotPart() muss vor dem Schreiben den gespeicherten Token pruefen');
});

test('snapshot.js baut kein Markup aus Daten', function () use ($snapRepo) {
    $js = sourceCode($snapRepo . '/public/checkin/js/snapshot.js');
    foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write'] as $sink) {
        assertTrue(!str_contains($js, $sink), "snapshot.js nutzt {$sink} — Inhalte nur per textContent");
    }
});

test('Termine und Verlauf schreiben ihren Teil nach erfolgreichem Laden', function () use ($snapRepo) {
    $js = str_replace("\r\n", "\n", sourceCode($snapRepo . '/public/checkin/js/app.js'));

    $resp = snapFunctionBody($js, 'loadResponses');
    $pos  = strpos($resp, 'saveAppointmentsSnapshot()');
    assertTrue($pos !== false, 'loadResponses() schreibt den Teil appointments nicht');
    assertTrue($pos > strpos($resp, 'if (!result.success)'), 'Erst nach der Erfolgspruefung schreiben');

    assertTrue(str_contains(snapFunctionBody($js, 'saveAppointmentsSnapshot'), "saveSnapshotPart('appointments'"),
        "saveAppointmentsSnapshot() muss den Teil 'appointments' schreiben");

    $submit = snapFunctionBody($js, 'submitResponse');
    $assign = strpos($submit, 'upcomingResponses[index] = result.data');
    $save   = strpos($submit, 'saveAppointmentsSnapshot()');
    assertTrue($save !== false && $assign !== false && $save > $assign,
        'submitResponse() muss nach der geaenderten Rueckmeldung den Schnappschuss neu schreiben');

    $hist = snapFunctionBody($js, 'loadHistory');
    $pos  = strpos($hist, "saveSnapshotPart('history'");
    assertTrue($pos !== false, "loadHistory() schreibt den Teil 'history' nicht");
    assertTrue($pos > strpos($hist, 'renderHistory(toRender)'), 'Erst nach dem Anzeigen schreiben — gelesen wird die Liste');
});

test('loadHistory prueft nach jedem await die Sitzung (kein Verlauf unter fremdem Mitglied)', function () use ($snapRepo) {
    $js = str_replace("\r\n", "\n", sourceCode($snapRepo . '/public/checkin/js/app.js'));
    $body = snapFunctionBody($js, 'loadHistory');

    assertTrue(str_contains($body, 'const generation = sessionGeneration'), 'loadHistory() merkt sich die Sitzung nicht');
    assertTrue(!str_contains($body, 'userData.member_id'), 'loadHistory() liest userData.member_id nach einem await — memberId vorab merken');
    assertTrue(substr_count($body, 'generation !== sessionGeneration') >= substr_count($body, 'await '),
        'Nach jedem await in loadHistory() fehlt die Pruefung generation !== sessionGeneration');
});

test('Abgeschaltete Terminplanung entfernt den Teil appointments', function () use ($snapRepo) {
    $js = str_replace("\r\n", "\n", sourceCode($snapRepo . '/public/checkin/js/app.js'));
    $body = snapFunctionBody($js, 'startSession');
    assertTrue(str_contains($body, "dropSnapshotPart('appointments')"),
        "startSession() muss bei abgeschalteter Terminplanung dropSnapshotPart('appointments') aufrufen");
});

test('Abgeschaltete Funktionen hinterlassen keinen Teil', function () use ($snapRepo) {
    $js = str_replace("\r\n", "\n", sourceCode($snapRepo . '/public/checkin/js/app.js'));
    assertTrue(str_contains(snapFunctionBody($js, 'applyPwaFeatureTabs'), "dropSnapshotPart('history'"),
        "applyPwaFeatureTabs() muss beim Ausblenden des Verlauf-Tabs dropSnapshotPart('history', …) aufrufen");
    assertTrue(str_contains(snapFunctionBody($js, 'startSession'), "dropSnapshotPart('appointments', "),
        'dropSnapshotPart() braucht die member_id');
    assertTrue(str_contains(snapFunctionBody($js, 'offerSnapshot'), 'snapshotHasContent('),
        'offerSnapshot() darf nur einen Schnappschuss mit Inhalt anbieten');
});

test('Abmelden leert auch die gezeichnete Ansicht', function () use ($snapRepo) {
    $js = str_replace("\r\n", "\n", sourceCode($snapRepo . '/public/checkin/js/app.js'));
    assertTrue(str_contains(snapFunctionBody($js, 'forgetSavedLogin'), "getElementById('snapshotContent')?.replaceChildren()"),
        'forgetSavedLogin() muss #snapshotContent leeren (geteilte Geraete)');
});

test('Nach "me" wird ein fremder Schnappschuss verworfen', function () use ($snapRepo) {
    $js = str_replace("\r\n", "\n", sourceCode($snapRepo . '/public/checkin/js/app.js'));
    assertTrue(str_contains(snapFunctionBody($js, 'startSession'), 'discardForeignSnapshot(meData.member_id)'),
        'startSession() muss discardForeignSnapshot(meData.member_id) aufrufen');
});

test('Startbildschirm bietet den letzten Stand nur bei fehlendem Server an', function () use ($snapRepo) {
    $js = str_replace("\r\n", "\n", sourceCode($snapRepo . '/public/checkin/js/app.js'));

    assertTrue(str_contains(snapFunctionBody($js, 'showStartStatus'), 'startSnapshotBtn'),
        'showStartStatus() muss den Knopf bei jedem Statuswechsel zuruecksetzen');
    $auto = snapFunctionBody($js, 'checkAutoLogin');
    $offer = strpos($auto, 'offerSnapshot()');
    assertTrue($offer !== false, 'checkAutoLogin() bietet den letzten Stand nicht an');
    $branch = strpos($auto, 'if (!result.success)');
    assertTrue($offer > $branch, 'Angebot nur im Zweig ohne Server');
    $status = strpos($auto, 'showStartStatus(', $branch);
    assertTrue($status !== false && $offer > $status,
        'offerSnapshot() muss nach showStartStatus() stehen — sonst blendet es den Knopf gleich wieder aus');
});

test('Markup: Knopf, Bildschirm, Skript vor app.js', function () use ($snapRepo) {
    $html = sourceCode($snapRepo . '/public/checkin/index.html');

    assertTrue((bool) preg_match('/<button[^>]*id="startSnapshotBtn"[^>]*data-action="start-snapshot"[^>]*hidden/', $html),
        'Knopf id="startSnapshotBtn" data-action="start-snapshot" hidden fehlt');
    assertTrue(str_contains($html, 'id="snapshotScreen"'), 'id="snapshotScreen" fehlt');
    assertTrue(str_contains($html, 'id="snapshotStamp"'), 'id="snapshotStamp" fehlt');
    assertTrue(str_contains($html, 'id="snapshotContent"'), 'id="snapshotContent" fehlt');
    assertTrue(str_contains($html, 'data-action="snapshot-reconnect"'), 'Knopf „Erneut verbinden“ fehlt');

    $snap = strpos($html, 'js/snapshot.js?v=');
    $app  = strpos($html, 'js/app.js?v=');
    assertTrue($snap !== false && $app !== false && $snap < $app, 'snapshot.js muss vor app.js geladen werden');
});

test('Aktionen und Bildschirm in app.js verdrahtet', function () use ($snapRepo) {
    $js = str_replace("\r\n", "\n", sourceCode($snapRepo . '/public/checkin/js/app.js'));

    assertTrue(str_contains($js, "'start-snapshot':"), "Aktion 'start-snapshot' fehlt in dataActions");
    assertTrue(str_contains($js, "'snapshot-reconnect':"), "Aktion 'snapshot-reconnect' fehlt in dataActions");
    assertTrue(str_contains(snapFunctionBody($js, 'showScreen'), "getElementById('snapshotScreen')"),
        'showScreen() kennt den Bildschirm snapshot nicht');
    $open = snapFunctionBody($js, 'openSnapshotView');
    assertTrue(str_contains($open, 'renderSnapshotView('), 'openSnapshotView() muss renderSnapshotView() aufrufen');
    assertTrue(str_contains($open, 'try {') && str_contains($open, 'clearSnapshot()'),
        'openSnapshotView() muss einen kaputten Schnappschuss abfangen und verwerfen');
});

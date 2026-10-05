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
 * Mitgliederdialog: Gruppenwechsel mit Datum (Spec 2026-10-05, 6.1).
 * Gelesen wird ueber sourceCode() -- Kommentare zaehlen nicht als Treffer.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/source.php';

$ghfRoot = dirname(__DIR__, 2);

function ghfBody(string $js, string $name): string
{
    $start = strpos($js, "function {$name}(");
    assertTrue($start !== false, "{$name}() fehlt");
    $next = preg_match('/\n(?:export\s+)?(?:async\s+)?function\s/', $js, $m, PREG_OFFSET_CAPTURE, $start + 10)
        ? $m[0][1] : strlen($js);

    return substr($js, $start, $next - $start);
}

test('Markup: Datumsfeld, Vorschau und Verlauf im Mitgliederdialog', function () use ($ghfRoot) {
    $html = (string) sourceCode($ghfRoot . '/public/index.html');
    assertTrue(str_contains($html, 'id="memberGroupsChange"') && preg_match('/id="memberGroupsChange"[^>]*\shidden/', $html) === 1,
        'Block memberGroupsChange fehlt oder ist nicht versteckt');
    assertTrue(str_contains($html, 'id="member_groups_valid_from"') && str_contains($html, 'type="date"'), 'Datumsfeld fehlt');
    assertTrue(str_contains($html, 'data-action-change="preview-member-group-change"'), 'Aktion am Datumsfeld fehlt');
    assertTrue(str_contains($html, 'id="memberGroupsPreview"'), 'Vorschau fehlt');
    assertTrue(str_contains($html, 'id="memberGroupsHistory"'), 'Verlaufszeile fehlt');
});

test('JS: Datumsfeld nur beim Bearbeiten und nur bei geaenderten Haekchen, max heute', function () use ($ghfRoot) {
    $js  = (string) sourceCode($ghfRoot . '/public/js/modules/members.js');
    $upd = ghfBody($js, 'updateMemberGroupChange');
    assertTrue(str_contains($upd, "document.getElementById('member_id').value"), 'prueft nicht, ob bearbeitet wird');
    assertTrue(str_contains($upd, 'loadedMemberGroups'), 'vergleicht nicht mit dem geladenen Stand');
    assertTrue(str_contains($upd, '.hidden'), 'blendet den Block nicht ein/aus');
    assertTrue(str_contains($upd, '.max = '), 'setzt max nicht');
    assertTrue(str_contains($upd, 'textContent'), 'Vorschau nicht per textContent');
    assertTrue(!str_contains($upd, 'innerHTML'), 'Vorschau darf nicht per innerHTML gesetzt werden');
    assertTrue(str_contains(ghfBody($js, 'syncMemberGroupCheckboxes'), 'updateMemberGroupChange()'),
        'Haekchen-Aenderung aktualisiert das Datumsfeld nicht');
    assertTrue(str_contains($js, "'preview-member-group-change': () => updateMemberGroupChange()"), 'Aktion nicht registriert');
});

test('JS: saveMember schickt groups_valid_from nur bei sichtbarem Feld', function () use ($ghfRoot) {
    $js   = (string) sourceCode($ghfRoot . '/public/js/modules/members.js');
    $save = ghfBody($js, 'saveMember');
    assertTrue(str_contains($save, 'groups_valid_from'), 'groups_valid_from wird nicht gesendet');
    assertTrue(str_contains($save, "getElementById('memberGroupsChange').hidden"), 'Senden haengt nicht an der Sichtbarkeit');
    assertTrue(str_contains($save, 'memberId &&'), 'Anlegen darf das Datum nie senden');
});

test('JS: Verlauf und seit-Angabe werden maskiert', function () use ($ghfRoot) {
    $js   = (string) sourceCode($ghfRoot . '/public/js/modules/members.js');
    $hist = ghfBody($js, 'renderMemberGroupHistory');
    assertTrue(str_contains($hist, 'escapeHtml('), 'Verlauf wird nicht maskiert');
    $groups = ghfBody($js, 'renderMemberGroups');
    assertTrue(str_contains($groups, 'group-choice-since'), 'seit-Angabe an den Haekchen fehlt');
    assertTrue(str_contains($groups, 'escapeHtml(formatIsoDateDe('), 'seit-Angabe wird nicht maskiert');
});

test('Cache: saveMember verwirft weiter alle Jahre (rueckwirkende Aenderung)', function () use ($ghfRoot) {
    $js   = (string) sourceCode($ghfRoot . '/public/js/modules/members.js');
    $save = ghfBody($js, 'saveMember');
    assertTrue(str_contains($save, "invalidateCache('appointments')"), 'appointments wird nicht verworfen');
    assertTrue(str_contains($save, 'invalidateMemberDependents()'), 'invalidateMemberDependents fehlt');
    $dep = ghfBody($js, 'invalidateMemberDependents');
    foreach (["'members'", "'records'", "'exceptions'"] as $key) {
        assertTrue(str_contains($dep, $key), "invalidateMemberDependents verwirft {$key} nicht");
    }
    assertTrue(!str_contains($dep, 'invalidateCache(key, '), 'invalidateMemberDependents darf nicht auf ein Jahr eingrenzen');
});

test('JS: Daten im Verlauf zweistellig (31.05.2026)', function () use ($ghfRoot) {
    $js = (string) sourceCode($ghfRoot . '/public/js/modules/members.js');
    assertTrue(str_contains(ghfBody($js, 'formatIsoDateDe'), "month: '2-digit'"), 'Datum nicht zweistellig formatiert');
});

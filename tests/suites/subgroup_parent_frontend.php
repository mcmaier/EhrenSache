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
 * Statische Gegenproben der Oberflaeche fuer die Gruppen eines Registers
 * (Spec 2026-10-02, Abschnitt 7 und 4.4): Gruppendialog, Gruppenliste,
 * Terminart-Auswahl und Mitgliederdialog. Prefix spf.
 */

$spfRoot = dirname(__DIR__, 2);

function spfBody(string $code, string $marker, string $stop): string
{
    $start = strpos($code, $marker);
    assertTrue($start !== false, "{$marker} fehlt");
    $ende = strpos($code, $stop, $start + strlen($marker));
    assertTrue($ende !== false, "Ende nach {$marker} nicht gefunden");
    return substr($code, $start, $ende - $start);
}

test('Gruppendialog: Checkbox-Liste statt Auswahl, nur bei Register sichtbar', function () use ($spfRoot) {
    $html = (string) sourceCode($spfRoot . '/public/index.html');
    assertTrue(str_contains($html, 'id="group_parent_list"'), 'index.html: #group_parent_list fehlt');
    assertTrue(str_contains($html, 'id="group_parent_row"'), 'index.html: #group_parent_row fehlt');
    assertTrue(!str_contains($html, 'id="group_parent_id"'), 'index.html: das alte <select> ist noch da');

    $js = (string) sourceCode($spfRoot . '/public/js/modules/management.js');
    assertTrue(str_contains($js, 'group-parent-checkbox'), 'management.js: Klasse group-parent-checkbox fehlt');
    $toggle = spfBody($js, 'export function toggleGroupExclusivity', "\n}");
    assertTrue(str_contains($toggle, 'group_parent_row'), 'toggleGroupExclusivity() steuert group_parent_row nicht');
});

test('saveGroup schickt parent_group_ids und zeigt Ergaenzungen und Warnungen', function () use ($spfRoot) {
    $js = (string) sourceCode($spfRoot . '/public/js/modules/management.js');
    $save = spfBody($js, 'export async function saveGroup', "\n}");
    assertTrue(preg_match('/parent_group_ids\s*:\s*isSubgroup\s*\?/', $save) === 1,
        'saveGroup() schickt parent_group_ids nicht abhaengig von isSubgroup');
    assertTrue(str_contains($save, '.group-parent-checkbox:checked'), 'saveGroup() liest die angehakten Gruppen nicht');
    assertTrue(str_contains($save, 'added_groups') && str_contains($save, 'group_warnings'),
        'saveGroup() wertet added_groups/group_warnings nicht aus');
    assertTrue(str_contains($save, 'showToast'), 'saveGroup() zeigt keinen Hinweis');
    assertTrue(str_contains($save, "invalidateCache('appointments')"), 'saveGroup() verwirft appointments nicht');
    assertTrue(str_contains($save, "invalidateCache('records')"), 'saveGroup() verwirft records nicht');
    assertTrue(!str_contains($js, 'Kein invalidateCache'), 'der veraltete Kommentar "Kein invalidateCache" steht noch da');
});

test('Gruppenliste rueckt Register unter jeder ihrer Gruppen ein', function () use ($spfRoot) {
    $js = (string) sourceCode($spfRoot . '/public/js/modules/management.js');
    $render = spfBody($js, 'function renderGroups', "\nfunction ");
    assertTrue(str_contains($render, 'group-row--sub'), 'renderGroups() setzt group-row--sub nicht');
    assertTrue(str_contains($render, 'parent_group_ids'), 'renderGroups() liest parent_group_ids nicht');
    assertTrue(str_contains($render, 'group-row--unassigned'), 'renderGroups() kennzeichnet Register ohne Gruppe nicht');
    $css = (string) sourceCode($spfRoot . '/public/css/components/tables.css');
    assertTrue(str_contains($css, '.group-row--sub'), 'tables.css: .group-row--sub fehlt');
});

test('Terminart-Auswahl ordnet Register unter ihrer ersten Gruppe ein', function () use ($spfRoot) {
    $js = (string) sourceCode($spfRoot . '/public/js/modules/management.js');
    $render = spfBody($js, 'function renderTypeGroups', "\n}");
    assertTrue(str_contains($render, 'group-choice--sub'), 'renderTypeGroups() setzt group-choice--sub nicht');
    assertTrue(str_contains($render, 'parent_group_ids'), 'renderTypeGroups() liest parent_group_ids nicht');
    assertTrue(str_contains($render, 'nur für eigene Termine, z. B. Registerprobe'),
        'renderTypeGroups() zeigt die Unterzeile fuer Register nicht');
    assertTrue(str_contains($js, "invalidateCache('types')") && str_contains($js, 'loadGroups(true)'),
        'deleteGroup()/openGroupModal(): types verwerfen bzw. Gruppen neu laden fehlt');
    $css = (string) sourceCode($spfRoot . '/public/css/components/forms.css');
    assertTrue(str_contains($css, '.group-choice--sub'), 'forms.css: .group-choice--sub fehlt');
});

test('Mitgliederdialog: data-parent-ids, Abgleich der Checkboxen, Hinweise', function () use ($spfRoot) {
    $js = (string) sourceCode($spfRoot . '/public/js/modules/members.js');
    assertTrue(str_contains($js, 'data-parent-ids'), 'members.js: data-parent-ids fehlt');
    assertTrue(str_contains($js, 'data-action-change="sync-member-groups"'), 'members.js: data-action-change fehlt');
    assertTrue(str_contains($js, "'sync-member-groups': (el) => syncMemberGroupCheckboxes(el)"),
        'members.js: Aktion sync-member-groups nicht registriert');
    assertTrue(str_contains($js, 'function syncMemberGroupCheckboxes('), 'syncMemberGroupCheckboxes fehlt');
    assertTrue(str_contains($js, 'function updateMemberGroupHints('), 'updateMemberGroupHints fehlt');
    $hints = spfBody($js, 'function updateMemberGroupHints', "\n}");
    assertTrue(str_contains($hints, 'textContent'), 'updateMemberGroupHints() setzt den Text nicht per textContent');
    assertTrue(!str_contains($hints, 'innerHTML'), 'updateMemberGroupHints() nutzt innerHTML');

    $save = spfBody($js, 'export async function saveMember', "\n}");
    $conseq = spfBody($js, 'function showGroupConsequences', "
}");
    assertTrue(str_contains($conseq, 'added_groups') && str_contains($conseq, 'group_warnings'),
        'showGroupConsequences() wertet added_groups/group_warnings nicht aus');
    assertTrue(str_contains($conseq, 'showToast'), 'showGroupConsequences() zeigt keinen Hinweis');

    // invalidateMemberDependents() leert den Gruppen-Cache; Namen muessen vorher gesichert sein
    $snap = strpos($save, 'groupNameById');
    $inval = strpos($save, 'invalidateMemberDependents(');
    assertTrue($snap !== false && $inval !== false && $snap < $inval,
        'saveMember() sichert die Gruppennamen nicht vor invalidateMemberDependents()');
    assertTrue(substr_count($save, 'showGroupConsequences(') >= 2,
        'saveMember() zeigt die Hinweise nicht in beiden Zweigen (pinFailed und Erfolg)');
});

test('Mitgliederimport: Ergebnis zeigt added_groups und group_warnings, Namen vor der Invalidierung gesichert', function () use ($spfRoot) {
    $js = (string) sourceCode($spfRoot . '/public/js/modules/import_export.js');
    $summary = spfBody($js, 'function importGroupSummaryHtml', "\n}");
    assertTrue(str_contains($summary, 'added_groups') && str_contains($summary, 'group_warnings'),
        'importGroupSummaryHtml() wertet added_groups/group_warnings nicht aus');
    assertTrue(str_contains($summary, 'member_name'), 'importGroupSummaryHtml() nutzt member_name nicht');
    assertTrue(str_contains($summary, 'escapeHtml('), 'importGroupSummaryHtml() maskiert nicht');
    assertTrue(!str_contains($summary, 'style='), 'importGroupSummaryHtml() setzt Inline-Styles');

    $display = spfBody($js, 'function displayImportResult', "\n}");
    assertTrue(str_contains($display, 'importGroupSummaryHtml('), 'displayImportResult() zeigt die Gruppenhinweise nicht an');

    $run = spfBody($js, 'async function executeImport', "\n}");
    $snap = strpos($run, 'importNameLookup(');
    $inval = strpos($run, "invalidateCache('members')");
    assertTrue($snap !== false && $inval !== false && $snap < $inval,
        'Namen werden nicht vor invalidateCache() gesichert');
});

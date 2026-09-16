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

/** Vorgabe, wenn die Einstellung fehlt oder unbrauchbar ist. */
const GROUP_SUBGROUP_LABEL_DEFAULT = 'Untergruppe';

/** Längenbegrenzung: das Wort steht in Überschriften und im Umschalter. */
const GROUP_SUBGROUP_LABEL_MAX = 30;

/**
 * Bezeichnung der Untergruppen aus der Einstellung: getrimmt, ohne
 * Steuerzeichen, höchstens 30 Zeichen. Leer ergibt die Vorgabe.
 *
 * Escaped kein HTML: Escaping geschieht beim Anzeigen in der Oberfläche,
 * das Projekt hat bewusst keine CSP (siehe OI-17 in docs/OPEN-ITEMS.md).
 */
function groupSubgroupLabel(?string $raw): string
{
    if ($raw === null) {
        return GROUP_SUBGROUP_LABEL_DEFAULT;
    }

    // Steuerzeichen (auch Zeilenumbruch und Tabulator) fallen ersatzlos weg --
    // sie würden die Überschrift zerreißen, nicht nur verunstalten.
    $clean = preg_replace('/[\x00-\x1F\x7F]+/u', '', $raw) ?? '';
    $clean = trim($clean);

    if ($clean === '') {
        return GROUP_SUBGROUP_LABEL_DEFAULT;
    }

    return mb_substr($clean, 0, GROUP_SUBGROUP_LABEL_MAX);
}

/** Sortierregel für Gruppen: sort_order, bei Gleichstand der Name. */
function groupSortCompare(array $a, array $b): int
{
    $orderA = (int) ($a['sort_order'] ?? 0);
    $orderB = (int) ($b['sort_order'] ?? 0);

    if ($orderA !== $orderB) {
        return $orderA <=> $orderB;
    }

    return strcasecmp((string) ($a['group_name'] ?? ''), (string) ($b['group_name'] ?? ''));
}

/**
 * @param array<int, array<string, mixed>> $groups
 * @return array<int, array<string, mixed>>
 */
function groupsSortForDisplay(array $groups): array
{
    usort($groups, 'groupSortCompare');

    return $groups;
}

/**
 * Gruppen und Untergruppen je Mitglied, für Anwesenheitslisten und die
 * Namensliste der Terminrückmeldung.
 *
 * `groups` sind die Mitgliedschaften aus $termGroupIds (z. B. die Gruppen der
 * Terminart) -- die Beschaffung dieser IDs bleibt beim Aufrufer, denn beide
 * bisherigen Aufrufer haben sie schon zur Hand (attendance_list.php aus der
 * Termin-Abfrage, responses.php aus den noch nicht entdoppelten erwarteten
 * Mitgliedern). `subgroups` sind alle als Untergruppe markierten Gruppen des
 * Mitglieds, unabhängig von $termGroupIds. Beide Listen sortiert nach
 * groupSortCompare().
 *
 * @param PDO $db Datenbankverbindung
 * @param Database $database Liefert den Tabellenpräfix über table()
 * @param array<int, array<string, mixed>> $members Zeilen mit member_id, werden um groups/subgroups ergänzt
 * @param array<int, int|string> $termGroupIds Gruppen-IDs, die als `groups` gelten
 * @return array<int, array<string, mixed>>
 */
function groupsAttachToMembers($db, $database, array $members, array $termGroupIds): array
{
    if (empty($members)) {
        return $members;
    }

    $prefix    = $database->table('');
    $memberIds = array_map(static fn ($m) => (int) $m['member_id'], $members);
    $inMembers = str_repeat('?,', count($memberIds) - 1) . '?';

    $stmt = $db->prepare("
        SELECT mga.member_id, g.group_id, g.group_name, g.sort_order, g.is_subgroup
        FROM {$prefix}member_group_assignments mga
        JOIN {$prefix}member_groups g ON g.group_id = mga.group_id
        WHERE mga.member_id IN ($inMembers)
    ");
    $stmt->execute($memberIds);

    $termGroups = array_map('intval', $termGroupIds);
    $byMember   = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $eintrag = [
            'group_id'   => (int) $row['group_id'],
            'group_name' => $row['group_name'],
            'sort_order' => (int) $row['sort_order'],
        ];
        $mid = (int) $row['member_id'];

        if (in_array($eintrag['group_id'], $termGroups, true)) {
            $byMember[$mid]['groups'][] = $eintrag;
        }
        if ((int) $row['is_subgroup'] === 1) {
            $byMember[$mid]['subgroups'][] = $eintrag;
        }
    }

    foreach ($members as &$member) {
        $mid = (int) $member['member_id'];
        $member['groups']    = groupsSortForDisplay($byMember[$mid]['groups'] ?? []);
        $member['subgroups'] = groupsSortForDisplay($byMember[$mid]['subgroups'] ?? []);
    }
    unset($member);

    return $members;
}

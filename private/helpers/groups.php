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
 * Terminart) OHNE die als Untergruppe markierten -- die Beschaffung der IDs
 * bleibt beim Aufrufer, denn beide bisherigen Aufrufer haben sie schon zur
 * Hand (attendance_list.php aus der Termin-Abfrage, responses.php aus den
 * noch nicht entdoppelten erwarteten Mitgliedern). `subgroups` sind alle als
 * Untergruppe markierten Gruppen des Mitglieds, aber nur solche, die selbst in
 * $termGroupIds liegen oder von denen mindestens eine ihrer Gruppen
 * (subgroup_parents) darin liegt (Spec 2026-10-02, 5.2). Die beiden Stufen sind gegenseitig ausschliessend: eine als
 * Untergruppe markierte Gruppe landet nie in `groups`, auch wenn sie zu
 * $termGroupIds gehoert -- sonst waere ein Mitglied doppelt erwartet, wenn
 * eine Terminart ein Register direkt zugeordnet hat. Beide Listen sortiert
 * nach groupSortCompare().
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
    $rows       = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Gruppen der gelesenen Register einmal laden
    $parentsOf = [];
    $subIds    = array_values(array_unique(array_map(
        static fn ($r) => (int) $r['group_id'],
        array_filter($rows, static fn ($r) => (int) $r['is_subgroup'] === 1)
    )));
    if ($subIds !== []) {
        $inSubs = str_repeat('?,', count($subIds) - 1) . '?';
        $pStmt  = $db->prepare("SELECT subgroup_id, group_id FROM {$prefix}subgroup_parents WHERE subgroup_id IN ($inSubs)");
        $pStmt->execute($subIds);
        while ($p = $pStmt->fetch(PDO::FETCH_ASSOC)) {
            $parentsOf[(int) $p['subgroup_id']][] = (int) $p['group_id'];
        }
    }

    foreach ($rows as $row) {
        $eintrag = [
            'group_id'   => (int) $row['group_id'],
            'group_name' => $row['group_name'],
            'sort_order' => (int) $row['sort_order'],
        ];
        $mid = (int) $row['member_id'];

        // Gegenseitig ausschliessend: eine Terminart kann ein Register direkt
        // zugeordnet haben (z. B. eine Terminart nur fuer ein Register). Ohne
        // diese Weiche stuende so eine Gruppe in beiden Stufen und jedes
        // Mitglied waere doppelt erwartet -- die Markierung is_subgroup
        // entscheidet, in welche Stufe eine Gruppe gehoert, unabhaengig
        // davon, ob sie auch zu $termGroupIds zaehlt.
        //
        // Register nur, wenn sie selbst dem Termin zugeordnet sind oder zu einer
        // Gruppe des Termins gehoeren -- ein Vorstandsmitglied, das Trompete
        // spielt, erzeugt auf der Liste einer Vorstandssitzung keinen Abschnitt
        // "Trompete".
        if ((int) $row['is_subgroup'] === 1) {
            $gid = $eintrag['group_id'];
            if (in_array($gid, $termGroups, true)
                || array_intersect($parentsOf[$gid] ?? [], $termGroups) !== []) {
                $byMember[$mid]['subgroups'][] = $eintrag;
            }
        } elseif (in_array($eintrag['group_id'], $termGroups, true)) {
            $byMember[$mid]['groups'][] = $eintrag;
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

/**
 * Regel nach dem Aendern der Gruppen eines Registers (Spec 2026-10-02, 4.2):
 * Mitglieder von S, die in keiner Gruppe aus P(S) stehen -- bei genau einer
 * Gruppe ergaenzen, bei mehreren warnen. Niemand wird entfernt.
 *
 * @return array{added: array<int, array{member_id: int, group_id: int}>,
 *               warnings: array<int, array{member_id: int, subgroup_id: int}>}
 */
function groupsApplySubgroupRule($db, $database, int $subgroupId): array
{
    $prefix = $database->table('');
    $result = ['added' => [], 'warnings' => []];

    $stmt = $db->prepare("SELECT group_id FROM {$prefix}subgroup_parents WHERE subgroup_id = ? ORDER BY group_id");
    $stmt->execute([$subgroupId]);
    $parents = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($parents === []) {
        return $result;
    }

    $in   = implode(',', array_fill(0, count($parents), '?'));
    $stmt = $db->prepare("SELECT a.member_id FROM {$prefix}member_group_assignments a
                           WHERE a.group_id = ?
                             AND NOT EXISTS (SELECT 1 FROM {$prefix}member_group_assignments p
                                              WHERE p.member_id = a.member_id AND p.group_id IN ({$in}))
                           ORDER BY a.member_id");
    $stmt->execute(array_merge([$subgroupId], $parents));
    $memberIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $insert = $db->prepare("INSERT INTO {$prefix}member_group_assignments (member_id, group_id) VALUES (?, ?)");
    foreach ($memberIds as $memberId) {
        if (count($parents) === 1) {
            $insert->execute([$memberId, $parents[0]]);
            $result['added'][] = ['member_id' => $memberId, 'group_id' => $parents[0]];
        } else {
            $result['warnings'][] = ['member_id' => $memberId, 'subgroup_id' => $subgroupId];
        }
    }

    return $result;
}

/**
 * Mitgliedschaftsregel fuer die Gruppenliste eines Mitglieds (Spec 2026-10-02, 4.1):
 * Fuer jedes Register S in der Liste, dessen Gruppen P(S) alle fehlen -- bei genau
 * einer Gruppe ergaenzen, bei mehreren in warnings melden, ohne Gruppe nichts.
 *
 * @param array<int, int|string> $groupIds
 * @return array{group_ids: array<int, int>, added: array<int, int>, warnings: array<int, int>}
 *         added = ergaenzte group_ids, warnings = subgroup_ids ohne passende Gruppe
 */
function groupsWithParents($db, $database, array $groupIds): array
{
    $prefix = $database->table('');

    $ids = [];
    foreach ($groupIds as $id) {
        $ids[(int) $id] = (int) $id;
    }
    $ids    = array_values($ids);
    $result = ['group_ids' => $ids, 'added' => [], 'warnings' => []];
    if ($ids === []) {
        return $result;
    }

    $in   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT sp.subgroup_id, sp.group_id FROM {$prefix}subgroup_parents sp
                           WHERE sp.subgroup_id IN ({$in}) ORDER BY sp.subgroup_id, sp.group_id");
    $stmt->execute($ids);
    $parentsOf = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $parentsOf[(int) $row['subgroup_id']][] = (int) $row['group_id'];
    }

    // Zwei Phasen, damit das Ergebnis nicht von der Reihenfolge abhaengt: erst die
    // Register mit genau einer Gruppe, dann die mit mehreren gegen die ergaenzte Liste.
    foreach ($parentsOf as $parents) {
        if (count($parents) === 1 && !in_array($parents[0], $ids, true)) {
            $result['group_ids'][] = $parents[0];
            $result['added'][]     = $parents[0];
            $ids[]                 = $parents[0];
        }
    }
    foreach ($parentsOf as $subgroupId => $parents) {
        if (count($parents) > 1 && array_intersect($parents, $ids) === []) {
            $result['warnings'][] = $subgroupId;
        }
    }

    return $result;
}

/**
 * Antwortform der Mitgliedschaftsregel fuer ein Mitglied.
 *
 * @param array{group_ids: array<int, int>, added: array<int, int>, warnings: array<int, int>} $normalized
 * @return array{0: array<int, array{member_id: int, group_id: int}>, 1: array<int, array{member_id: int, subgroup_id: int}>}
 */
function groupsRuleReport(int $memberId, array $normalized): array
{
    $added = array_map(static fn (int $g) => ['member_id' => $memberId, 'group_id' => $g], $normalized['added']);
    $warnings = array_map(static fn (int $s) => ['member_id' => $memberId, 'subgroup_id' => $s], $normalized['warnings']);

    return [$added, $warnings];
}

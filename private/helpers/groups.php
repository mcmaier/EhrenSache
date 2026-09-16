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

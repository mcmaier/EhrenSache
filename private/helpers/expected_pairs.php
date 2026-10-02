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
 * Die Soll-Menge: welches Mitglied zu welchem Termin erwartet wird
 * (Spec 2026-10-01-register-statistik-besetzung, Abschnitt 3).
 *
 * Regel: Terminart -> zugeordnete Gruppe -> Mitglied der Gruppe, aktiv AM
 * TERMINDATUM (getMemberActivityWhere mit a.date). Dieselbe Regel galt schon
 * fuer Anwesenheitsliste, Kalender und Rueckmeldung; die Statistik rechnete
 * bis hierher mit "irgendwann im Jahr aktiv" und zaehlte deshalb Termine vor
 * einem Eintritt als unentschuldigt.
 *
 * Eine Zeile je Weg: Erreicht ein Termin ein Mitglied ueber zwei Gruppen,
 * entstehen zwei Zeilen mit verschiedenem via_group_id. Wer Paare braucht,
 * nimmt DISTINCT member_id, appointment_id.
 */
declare(strict_types=1);

require_once __DIR__ . '/member_activity.php';
require_once __DIR__ . '/appointment_attendance.php';   // attendanceStartedSql()

/**
 * SELECT der Soll-Menge mit den Spalten
 * member_id, appointment_id, type_id, via_group_id -- zum Einbetten als
 * abgeleitete Tabelle: "FROM ({$sql}) ep".
 *
 * Filter (alle optional):
 *   year            int       YEAR(a.date) = ?
 *   appointment_ids int[]     a.appointment_id IN (...) -- leer heisst: kein Treffer
 *   type_id         int       a.type_id = ?
 *   member_id       int       mga.member_id = ?
 *   started_lead    int       nur begonnene Termine, Vorlauf in Stunden
 *
 * @param array{year?: ?int, appointment_ids?: ?array<int,int>, type_id?: ?int,
 *              member_id?: ?int, started_lead?: ?int} $filter
 * @return array{0: string, 1: array<int, mixed>}
 */
function expectedPairsSql($database, array $filter = []): array
{
    $prefix   = $database->table('');
    $activity = getMemberActivityWhere('m', 'a.date', false, $database);

    $sql = "
        SELECT mga.member_id, a.appointment_id, a.type_id, mga.group_id AS via_group_id
        FROM {$prefix}appointments a
        JOIN {$prefix}appointment_type_groups atg ON atg.type_id = a.type_id
        JOIN {$prefix}member_group_assignments mga ON mga.group_id = atg.group_id
        JOIN {$prefix}members m ON m.member_id = mga.member_id AND ({$activity})
        WHERE 1 = 1
    ";
    $params = [];

    if (($filter['year'] ?? null) !== null) {
        $sql .= " AND YEAR(a.date) = ?";
        $params[] = (int) $filter['year'];
    }
    if (array_key_exists('appointment_ids', $filter) && $filter['appointment_ids'] !== null) {
        $ids = array_values(array_map('intval', $filter['appointment_ids']));
        if ($ids === []) {
            $sql .= " AND 1 = 0";
        } else {
            $sql .= " AND a.appointment_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
            array_push($params, ...$ids);
        }
    }
    if (($filter['type_id'] ?? null) !== null) {
        $sql .= " AND a.type_id = ?";
        $params[] = (int) $filter['type_id'];
    }
    if (($filter['member_id'] ?? null) !== null) {
        $sql .= " AND mga.member_id = ?";
        $params[] = (int) $filter['member_id'];
    }
    if (($filter['started_lead'] ?? null) !== null) {
        $sql .= " AND " . attendanceStartedSql((int) $filter['started_lead']);
    }

    return [$sql, $params];
}

/**
 * Bereichsfilter ueber eine Gruppenliste, fuer "WHERE ..." auf der
 * abgeleiteten Tabelle ep (Spec 2026-10-02, 5.1):
 *
 *   gewoehnliche Gruppe G:     Paare, die ueber G kommen (ep.via_group_id = G)
 *   Register S mit Gruppen P:  Paare, deren Mitglied in S steht und deren
 *                              Termin ueber S selbst oder eine Gruppe aus
 *                              P(S) (subgroup_parents) kommt -- Termine
 *                              anderer Gruppen des Mitglieds zaehlen nicht
 *   Register ohne Gruppe:      nur Paare ueber S selbst (erster Teil); der
 *                              Join auf subgroup_parents schliesst es aus
 *                              dem zweiten Teil aus
 *
 * Beides in einer Bedingung. Die Gruppenart steht in member_groups.is_subgroup,
 * der Aufrufer muss sie nicht kennen.
 *
 * @param array<int, int> $groupIds nicht leer
 * @return array{0: string, 1: array<int, int>}
 */
function expectedPairsScopeSql($database, array $groupIds, string $alias = 'ep'): array
{
    if ($groupIds === []) {
        throw new InvalidArgumentException('expectedPairsScopeSql: leere Gruppenliste');
    }
    if (preg_match('/^[a-z_][a-z0-9_]*$/i', $alias) !== 1) {
        throw new InvalidArgumentException('Ungueltiger Tabellenalias: ' . $alias);
    }

    $prefix = $database->table('');
    $ids    = array_values(array_map('intval', $groupIds));
    $in     = implode(',', array_fill(0, count($ids), '?'));

    $sql = "(
        {$alias}.via_group_id IN ({$in})
        OR EXISTS (
            SELECT 1
            FROM {$prefix}member_groups sg
            JOIN {$prefix}member_group_assignments sga
                 ON sga.group_id = sg.group_id AND sga.member_id = {$alias}.member_id
            JOIN {$prefix}subgroup_parents sp ON sp.subgroup_id = sg.group_id
            WHERE sg.group_id IN ({$in})
              AND sg.is_subgroup = 1
              AND ({$alias}.via_group_id = sg.group_id OR {$alias}.via_group_id = sp.group_id)
        )
    )";

    return [$sql, array_merge($ids, $ids)];
}

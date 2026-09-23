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
 * Offene Punkte eines Mitglieds (FI-17): was das System gerade von ihm will.
 *
 * Einzige Regelstelle fuer die Uebersicht in Dashboard und PWA. FI-6
 * (Benachrichtigungen) soll spaeter dieselbe Funktion aufrufen, um zu
 * entscheiden, was verschickt wird.
 *
 * Spec: docs/superpowers/specs/2026-09-22-offene-punkte-design.md
 */
declare(strict_types=1);

require_once __DIR__ . '/responses.php';

/** Wie lange eine Ablehnung in der Uebersicht bleibt, ab Entscheidung. */
const OPEN_ITEMS_REJECTED_DAYS = 14;

/**
 * Wie weit eine Rueckmeldung in die Zukunft reichen darf, um als offener
 * Punkt zu erscheinen. Wegen Terminserien waeren sonst ohne Weiteres 50
 * kommende woechentliche Proben ohne Antwort gleichzeitig "offen" -- eine
 * Uebersicht, die niemand mehr liest. Die Grenze bei 14 Tagen haelt die Liste
 * auf das, was tatsaechlich ansteht.
 */
const OPEN_ITEMS_RESPONSE_DAYS = 14;

/**
 * Ist eine Rueckmeldung offen? Dieselbe Regel wie updateResponsesBadge() in
 * der PWA: keine eigene Antwort, Termin nicht begonnen, Frist nicht vorbei.
 * Nach Fristablauf nimmt der Server eine Antwort noch als „kurzfristig" an,
 * die Uebersicht fordert dann aber nicht mehr dazu auf.
 *
 * Alle Zeitwerte als 'Y-m-d H:i:s' bzw. 'Y-m-d' / 'H:i:s' in lokaler
 * Wanduhrzeit -- der Textvergleich ist dann chronologisch.
 */
function openItemsResponseIsOpen(string $date, string $startTime, string $deadline,
                                 bool $answered, string $now): bool
{
    return !$answered
        && !responseHasStarted($date, $startTime, $now)
        && $now <= $deadline;
}

/** Fruehester Entscheidungszeitpunkt, den eine Ablehnung haben darf, um zu erscheinen. */
function openItemsRejectedSince(string $now): string
{
    return (new DateTimeImmutable($now))
        ->modify('-' . OPEN_ITEMS_REJECTED_DAYS . ' days')
        ->format('Y-m-d H:i:s');
}

/** Spaetestes Termindatum ('Y-m-d'), zu dem eine Rueckmeldung noch als offener Punkt zaehlt. */
function openItemsResponseHorizon(string $now): string
{
    return (new DateTimeImmutable($now))
        ->modify('+' . OPEN_ITEMS_RESPONSE_DAYS . ' days')
        ->format('Y-m-d');
}

/**
 * Rueckmeldungen nach Frist aufsteigend, dann Wartendes nach Datum
 * aufsteigend, dann Ablehnungen nach Entscheidung absteigend.
 *
 * @param array<int, array<string, mixed>> $items
 * @return array<int, array<string, mixed>>
 */
function openItemsSort(array $items): array
{
    $rankOf = ['open' => 0, 'pending' => 1, 'rejected' => 2];
    $rank   = static fn (array $i): int => $rankOf[$i['state']];

    $key = static function (array $i): string {
        if ($i['kind'] === 'response') {
            return (string) $i['deadline'];
        }
        if ($i['state'] === 'pending') {
            return $i['kind'] === 'work_session'
                ? (string) $i['start_time']
                : $i['date'] . ' ' . $i['start_time'];
        }

        return (string) $i['decided_at'];
    };

    usort($items, static function (array $a, array $b) use ($rank, $key): int {
        $byRank = $rank($a) <=> $rank($b);
        if ($byRank !== 0) {
            return $byRank;
        }
        $byKey = strcmp($key($a), $key($b));

        return $a['state'] === 'rejected' ? -$byKey : $byKey;
    });

    return $items;
}

/**
 * @param array<int, array<string, mixed>> $items
 * @return array{open: int, pending: int, rejected: int}
 */
function openItemsCounts(array $items): array
{
    $counts = ['open' => 0, 'pending' => 0, 'rejected' => 0];
    foreach ($items as $item) {
        $counts[$item['state']]++;
    }

    return $counts;
}

/**
 * Alle offenen Punkte eines Mitglieds, sortiert, samt Zaehlung.
 *
 * Braucht die Helfer aus responses.php (Frist), worktime.php (Schalter,
 * Dauer) und utils.php (systemSetting) -- api.php laedt sie alle.
 *
 * @return array{items: array<int, array<string, mixed>>, counts: array{open: int, pending: int, rejected: int}}
 */
function openItemsForMember($db, $database, int $memberId, string $now): array
{
    $prefix   = $database->table('');
    $since    = openItemsRejectedSince($now);
    $items    = [];

    // --- Rueckmeldungen -------------------------------------------------
    // responsesFetchUpcomingIds() begrenzt auf 50 Termine -- bei einer
    // woechentlichen Serie koennte das schon nach knapp einem Jahr zuschlagen.
    // Innerhalb des 14-Tage-Horizonts unten bleibt das folgenlos: eine Serie
    // liefert in zwei Wochen nie annaehernd 50 Termine.
    $globalHours = responseDeadlineHours(null, systemSetting(
        $db, $database, 'response_deadline_hours', (string) RESPONSE_DEADLINE_DEFAULT_HOURS
    ));
    $horizon = openItemsResponseHorizon($now);
    $ids = responsesFetchUpcomingIds($db, $database, $memberId, $now);
    if ($ids !== []) {
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("
            SELECT a.appointment_id, a.title, a.date, a.start_time, t.response_deadline_hours,
                   EXISTS(SELECT 1 FROM {$prefix}appointment_responses r
                          WHERE r.appointment_id = a.appointment_id AND r.member_id = ?) AS answered
            FROM {$prefix}appointments a
            JOIN {$prefix}appointment_types t ON t.type_id = a.type_id
            WHERE a.appointment_id IN ({$in})
        ");
        $stmt->execute(array_merge([$memberId], $ids));

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            if ($a['date'] > $horizon) {
                continue;
            }
            $deadline = responseDeadline($a['date'], $a['start_time'],
                responseDeadlineHours($a['response_deadline_hours'], (string) $globalHours));
            if (!openItemsResponseIsOpen($a['date'], $a['start_time'], $deadline, (bool) $a['answered'], $now)) {
                continue;
            }
            $items[] = [
                'kind'           => 'response',
                'state'          => 'open',
                'appointment_id' => (int) $a['appointment_id'],
                'title'          => $a['title'],
                'date'           => $a['date'],
                'start_time'     => $a['start_time'],
                'deadline'       => $deadline,
            ];
        }
    }

    // --- Antraege --------------------------------------------------------
    $stmt = $db->prepare("
        SELECT e.exception_id, e.exception_type, e.status, e.approved_at,
               a.appointment_id, a.title, a.date, a.start_time
        FROM {$prefix}exceptions e
        JOIN {$prefix}appointments a ON a.appointment_id = e.appointment_id
        WHERE e.member_id = ?
          AND (e.status = 'pending' OR (e.status = 'rejected' AND e.approved_at >= ?))
    ");
    $stmt->execute([$memberId, $since]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $item = [
            'kind'           => 'exception',
            'state'          => $e['status'],
            'id'             => (int) $e['exception_id'],
            'exception_type' => $e['exception_type'],
            'appointment_id' => (int) $e['appointment_id'],
            'title'          => $e['title'],
            'date'           => $e['date'],
            'start_time'     => $e['start_time'],
        ];
        if ($e['status'] === 'rejected') {
            $item['decided_at'] = $e['approved_at'];
        }
        $items[] = $item;
    }

    // --- Arbeitszeiten, nur wenn eingeschaltet ---------------------------
    if (isWorktimeEnabled($db, $database)) {
        $duration = worktimeDurationExpression('ws');
        $stmt = $db->prepare("
            SELECT ws.session_id, ws.status, ws.start_time, ws.approved_at, at.activity_name,
                   {$duration} AS duration_minutes
            FROM {$prefix}work_sessions ws
            JOIN {$prefix}activity_types at ON at.activity_id = ws.activity_id
            WHERE ws.member_id = ?
              AND ws.end_time IS NOT NULL
              AND (ws.status = 'submitted' OR (ws.status = 'rejected' AND ws.approved_at >= ?))
        ");
        $stmt->execute([$memberId, $since]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $item = [
                'kind'             => 'work_session',
                'state'            => $s['status'] === 'submitted' ? 'pending' : 'rejected',
                'id'               => (int) $s['session_id'],
                'activity_name'    => $s['activity_name'],
                'start_time'       => $s['start_time'],
                'duration_minutes' => (int) $s['duration_minutes'],
            ];
            if ($s['status'] === 'rejected') {
                $item['decided_at'] = $s['approved_at'];
            }
            $items[] = $item;
        }
    }

    $items = openItemsSort($items);

    return ['items' => $items, 'counts' => openItemsCounts($items)];
}

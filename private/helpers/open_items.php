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

/** Wie lange eine Ablehnung in der Uebersicht bleibt, ab Entscheidung. */
const OPEN_ITEMS_REJECTED_DAYS = 14;

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

/**
 * Rueckmeldungen nach Frist aufsteigend, dann Wartendes nach Datum
 * aufsteigend, dann Ablehnungen nach Entscheidung absteigend.
 *
 * @param array<int, array<string, mixed>> $items
 * @return array<int, array<string, mixed>>
 */
function openItemsSort(array $items): array
{
    $rank = static fn (array $i): int =>
        $i['kind'] === 'response' ? 0 : ($i['state'] === 'pending' ? 1 : 2);

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

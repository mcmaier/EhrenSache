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
 * Prüfung der Bestandsdaten zu OI-130 für den Migrationsschritt 1.23.0 → 1.23.1.
 *
 * Bis 1.23.0 wertete EhrenSache den Status eines Zeitraums in membership_dates
 * nirgends aus: Ein Zeitraum „Inaktiv“ wirkte wie ein aktiver. Seit 1.23.1 zählt
 * der Status. Wer einen beendeten Zeitraum als „Inaktiv“ markiert hat, um damit
 * „war aktiv von … bis …“ auszudrücken, bekommt dadurch die umgekehrte
 * Bedeutung: Hat das Mitglied keinen Zeitraum „Aktiv“ und steht es in den
 * Stammdaten auf aktiv, gilt es außerhalb dieses Zeitraums wieder als aktiv.
 *
 * Entscheidung des Nutzers (2026-10-08, Variante b): nur melden, nichts ändern.
 * Der Verein prüft die genannten Mitglieder selbst — ein „Inaktiv“ kann auch
 * genau so gemeint sein.
 *
 * Steht im Update-Pfad: nur Syntax bis PHP 8.0.
 */
declare(strict_types=1);

/** Höchstzahl der Mitglieder, die eine Warnung einzeln nennt. */
const MEMBERSHIP_STATUS_CHECK_LIST_LIMIT = 50;

/**
 * Meldet Mitglieder, deren Aktivität sich durch die Auswertung des Status
 * umkehren kann. Verändert keine Daten, wiederholbar.
 *
 * Betroffen ist ein Mitglied, wenn
 * - members.active = 1,
 * - es mindestens einen Zeitraum mit status 'inactive' und gesetztem Ende hat und
 * - es keinen Zeitraum mit status 'active' hat.
 *
 * @return array{log: string[], warnings: string[]}
 */
function membershipStatusCheck(PDO $pdo, string $prefix): array
{
    $members = $prefix . 'members';
    $dates   = $prefix . 'membership_dates';

    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?)');
    $exists->execute([$members, $dates]);
    if ((int) $exists->fetchColumn() < 2) {
        return [
            'log'      => [],
            'warnings' => ["Tabelle {$members} oder {$dates} nicht gefunden — Prüfung der Aktivzeiträume übersprungen."],
        ];
    }

    $rows = $pdo->query("
        SELECT m.member_id, m.member_number, m.name, m.surname
        FROM `{$members}` m
        WHERE m.active = 1
          AND EXISTS (
              SELECT 1 FROM `{$dates}` d
              WHERE d.member_id = m.member_id AND d.status = 'inactive' AND d.end_date IS NOT NULL
          )
          AND NOT EXISTS (
              SELECT 1 FROM `{$dates}` d
              WHERE d.member_id = m.member_id AND d.status = 'active'
          )
        ORDER BY m.surname, m.name, m.member_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) === 0) {
        return [
            'log'      => ['Aktivzeiträume geprüft: kein Mitglied mit beendetem Zeitraum „Inaktiv“ ohne Zeitraum „Aktiv“'],
            'warnings' => [],
        ];
    }

    $names = [];
    foreach (array_slice($rows, 0, MEMBERSHIP_STATUS_CHECK_LIST_LIMIT) as $row) {
        $label   = trim($row['name'] . ' ' . $row['surname']);
        $number  = trim((string) ($row['member_number'] ?? ''));
        $names[] = $number !== '' ? "{$label} ({$number})" : "{$label} (ID {$row['member_id']})";
    }
    $rest = count($rows) - count($names);

    $warning = 'Aktivzeiträume bitte prüfen: Seit 1.23.1 zählt der Status eines Zeitraums. '
        . count($rows) . ' Mitglied(er) haben einen beendeten Zeitraum „Inaktiv“ und keinen Zeitraum „Aktiv“, '
        . 'stehen in den Stammdaten aber auf aktiv. Sie gelten außerhalb dieses Zeitraums jetzt als aktiv. '
        . 'War „aktiv von … bis …“ gemeint, den Zeitraum im Mitgliederdialog unter „Mitgliedschaftszeiträume“ auf „Aktiv“ stellen. '
        . 'Betroffen: ' . implode(', ', $names)
        . ($rest > 0 ? " und {$rest} weitere" : '') . '.';

    return [
        'log'      => ['Aktivzeiträume geprüft: ' . count($rows) . ' Mitglied(er) zur Prüfung gemeldet, keine Daten geändert'],
        'warnings' => [$warning],
    ];
}

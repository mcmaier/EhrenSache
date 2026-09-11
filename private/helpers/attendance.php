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

// ============================================
// ANWESENHEITSAUSWERTUNG
//
// Die Datei trennt zwei Sorten Funktionen:
//
//   holend  -- fuehrt SQL aus, gibt rohe Zeilen zurueck
//   formend -- nimmt Zeilen entgegen, gibt die Ergebnisstruktur zurueck
//
// Die formenden tragen die Logik, an der OI-48 hing: Welche Terminarten
// eine Gruppe hat und wie sie zusammengefasst werden. Sie kommen ohne
// Datenbank aus und sind deshalb in tests/suites/statistics_unit.php mit
// handgeschriebenen Zeilen pruefbar -- auch fuer Faelle, die in keinem
// Datenbestand vorkommen.
// ============================================

/** Quote in Prozent, eine Nachkommastelle, ohne Division durch null. */
function attendanceRate(int $attended, int $total): float
{
    return $total > 0 ? round(($attended / $total) * 100, 1) : 0.0;
}

/**
 * Formt die Zeilen einer Gruppe zum Ergebnisblock.
 *
 * $types ist die vollstaendige, bereits sortierte Terminartliste der Gruppe.
 * Sie bestimmt Zahl und Reihenfolge der Eintraege in by_type -- auch fuer
 * Terminarten ohne Termine im Jahr. Ohne das wechselten die Spalten der
 * Oberflaeche mit dem Jahresfilter.
 *
 * $rows kommt aus attendanceFetchGroupRows(): je Mitglied und Terminart eine
 * Zeile mit total, attended und unexcused. 'excused' wird hier gerechnet, aus
 * denselben drei Zahlen -- eine Quelle, wie seit 1.5.0.
 *
 * @param array<int, array{type_id: int, type_name: ?string}> $types
 * @param array<int, array<string, mixed>> $rows
 */
function attendanceBuildGroup(int $groupId, string $groupName, array $types, array $rows): array
{
    // Bekannte Terminarten als Nachschlagetabelle. Die beiden Pruefungen
    // darunter sind Vertragspruefungen, keine Fehlerbehandlung: Das SQL
    // liefert nur Zeilen zu Terminarten dieser Gruppe, und es gruppiert nach
    // member_id und type_id. Verletzt eine Zeile das, ist die Voraussetzung
    // kaputt -- und genau dann soll es auffallen statt zu verschwinden.
    // Stiller Datenverlust ist der Fehler, den dieses Vorhaben behebt (OI-48).
    $knownTypes = [];
    foreach ($types as $type) {
        $knownTypes[(int) $type['type_id']] = true;
    }

    $byMember = [];

    foreach ($rows as $row) {
        $id     = (int) $row['member_id'];
        $typeId = (int) $row['type_id'];

        if (!isset($knownTypes[$typeId])) {
            throw new InvalidArgumentException(
                "Zeile mit Terminart {$typeId}, die nicht zur Gruppe {$groupId} gehoert"
            );
        }

        if (!isset($byMember[$id])) {
            $byMember[$id] = [
                'member_id'   => $id,
                'member_name' => $row['surname'] . ', ' . $row['name'],
                'per_type'    => [],
            ];
        }

        if (isset($byMember[$id]['per_type'][$typeId])) {
            throw new InvalidArgumentException(
                "Doppelte Zeile fuer Mitglied {$id} und Terminart {$typeId}"
            );
        }

        $total     = (int) $row['total'];
        $attended  = (int) $row['attended'];
        $unexcused = (int) $row['unexcused'];

        $byMember[$id]['per_type'][$typeId] = [
            'total_appointments' => $total,
            'attended'           => $attended,
            'unexcused_absences' => $unexcused,
            'excused'            => max(0, $total - $attended - $unexcused),
        ];
    }

    $members = [];

    foreach ($byMember as $entry) {
        $sum = ['total_appointments' => 0, 'attended' => 0,
                'unexcused_absences' => 0, 'excused' => 0];
        $byType = [];

        foreach ($types as $type) {
            $typeId = (int) $type['type_id'];
            $values = $entry['per_type'][$typeId]
                ?? ['total_appointments' => 0, 'attended' => 0,
                    'unexcused_absences' => 0, 'excused' => 0];

            foreach ($sum as $key => $_) {
                $sum[$key] += $values[$key];
            }

            $byType[] = [
                'type_id'            => $typeId,
                'type_name'          => $type['type_name'],
                'total_appointments' => $values['total_appointments'],
                'attended'           => $values['attended'],
                'excused'            => $values['excused'],
                'unexcused_absences' => $values['unexcused_absences'],
                'attendance_rate'    => attendanceRate($values['attended'],
                                                       $values['total_appointments']),
            ];
        }

        $members[] = [
            'member_id'          => $entry['member_id'],
            'member_name'        => $entry['member_name'],
            'total_appointments' => $sum['total_appointments'],
            'attended'           => $sum['attended'],
            'excused'            => $sum['excused'],
            'unexcused_absences' => $sum['unexcused_absences'],
            'attendance_rate'    => attendanceRate($sum['attended'], $sum['total_appointments']),
            'by_type'            => $byType,
        ];
    }

    return [
        'group_id'          => $groupId,
        'group_name'        => $groupName,
        'appointment_types' => $types,
        'members'           => $members,
    ];
}

/**
 * Formt die Kopfzahlen aus den bereits entdoppelten Mitgliedszeilen.
 *
 * $memberTotals kommt aus attendanceFetchMemberTotals(). Die Entdopplung
 * geschieht dort im SQL ueber COUNT(DISTINCT ...) -- diese Funktion summiert
 * nur. Deshalb laesst sich die Entdopplung hier nicht pruefen; dafuer gibt es
 * einen HTTP-Test.
 *
 * 'unexcused' wird als total - attended - excused gerechnet. Die Richtung ist
 * umgekehrt zu attendanceBuildGroup(): Dort ist 'kein Eintrag vorhanden'
 * direkt zaehlbar, hier nicht, weil ein fehlender Eintrag keine Termin-ID
 * beisteuert, die COUNT(DISTINCT ...) zaehlen koennte.
 *
 * @param array<int, array{member_id: int, total: int, attended: int, excused: int}> $memberTotals
 */
function attendanceBuildSummary(array $memberTotals, int $appointmentCount, int $memberCount): array
{
    $possible  = 0;
    $present   = 0;
    $excused   = 0;
    $unexcused = 0;

    foreach ($memberTotals as $row) {
        $total    = (int) $row['total'];
        $attended = (int) $row['attended'];
        $exc      = (int) $row['excused'];

        $possible  += $total;
        $present   += $attended;
        $excused   += $exc;
        $unexcused += max(0, $total - $attended - $exc);
    }

    return [
        'total_appointments' => $appointmentCount,
        'total_members'      => $memberCount,
        'total_present'      => $present,
        'total_excused'      => $excused,
        'total_unexcused'    => $unexcused,
        'overall_average'    => attendanceRate($present, $possible),
    ];
}

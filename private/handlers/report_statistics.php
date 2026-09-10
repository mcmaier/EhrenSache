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
// ANWESENHEITSBERICHT (Druckansicht)
// ============================================

/**
 * Anwesenheitsbericht als druckbare HTML-Seite.
 *
 * Jahresbasiert wie die Statistik-Sektion, deren Filter er uebernimmt. Ein
 * freier Zeitraum wuerde die Statistik-SQL umbauen; Bericht und Bildschirm
 * koennten dann verschiedene Zahlen zeigen.
 *
 * Kein CSV: Anwesenheitsdaten liefert export&type=records bereits.
 */
function handleStatisticsReport($db, $database, $request_method, $authUserRole, $authMemberId): void
{
    if ($request_method !== 'GET') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        exit();
    }

    $year     = isset($_GET['year'])      ? intval($_GET['year'])      : (int) date('Y');
    $groupId  = isset($_GET['group_id'])  ? intval($_GET['group_id'])  : null;
    $memberId = isset($_GET['member_id']) ? intval($_GET['member_id']) : null;

    if (!isAdminOrManager()) {
        // Fremde member_id wird ignoriert, nicht abgewiesen -- dieselbe Linie
        // wie handleStatistics(). Eine Fehlermeldung waere ein Orakel darueber,
        // welche IDs existieren.
        $memberId = $authMemberId;

        if ($memberId === null) {
            http_response_code(403);
            echo json_encode(["message" => "Kein Mitglied mit diesem Benutzer verknüpft"],
                             JSON_UNESCAPED_UNICODE);
            exit();
        }

        if ($groupId !== null
            && !hasStatisticsGroupAccess($db, $database, $authMemberId, $authUserRole, $groupId)) {
            http_response_code(403);
            echo json_encode(["message" => "No access to this group"]);
            exit();
        }
    }

    // Benannte Argumente an der neuen Aufrufstelle: Drei benachbarte ?int-
    // Parameter sind bei Vertauschung fuer PHP nicht erkennbar, und der Fehler
    // waere ein Bericht ueber die falsche Person.
    $result = buildStatisticsResult(
        $db, $database,
        year:              $year,
        groupId:           $groupId,
        memberId:          $memberId,
        appointmentTypeId: null,
        role:              $authUserRole,
        authMemberId:      $authMemberId
    );

    $sections = [statisticsReportSummarySection($result['summary'])];

    foreach ($result['statistics'] as $group) {
        $sections[] = statisticsReportGroupSection($group);
    }

    renderReport($db, $database, [
        'title'    => 'Anwesenheitsbericht',
        'period'   => 'Jahr ' . $year,
        'sections' => $sections,
        'notes'    => statisticsReportNotes(),
    ]);
}

/** Kennzahlen des Gesamtergebnisses, zweispaltig. */
function statisticsReportSummarySection(array $summary): array
{
    return [
        'heading' => null,
        'class'   => 'report-summary',
        'columns' => ['Kennzahl', 'Wert'],
        'rows'    => [
            ['Termine gesamt',   (string) $summary['total_appointments']],
            ['Anwesend',         (string) $summary['total_present']],
            ['Entschuldigt',     (string) $summary['total_excused']],
            ['Unentschuldigt',   (string) $summary['total_unexcused']],
            ['Durchschnittliche Anwesenheitsquote', statisticsReportRate($summary['overall_average'])],
        ],
        'empty'   => 'Keine Kennzahlen für dieses Jahr.',
    ];
}

/** Ein Abschnitt je Gruppe: eine Zeile je Mitglied. */
function statisticsReportGroupSection(array $group): array
{
    $rows = [];

    foreach ($group['members'] as $member) {
        // 'Entschuldigt' steht nicht in der Antwort -- dieselbe Rechnung wie
        // in der Gesamtsumme, damit beide nicht auseinanderlaufen koennen.
        $excused = $member['total_appointments']
                 - $member['attended']
                 - $member['unexcused_absences'];

        $rows[] = [
            $member['member_name'],
            (string) $member['total_appointments'],
            (string) $member['attended'],
            (string) $excused,
            (string) $member['unexcused_absences'],
            statisticsReportRate($member['attendance_rate']),
        ];
    }

    return [
        'heading' => $group['group_name'],
        'columns' => ['Mitglied', 'Termine', 'Anwesend', 'Entschuldigt', 'Unentschuldigt', 'Quote'],
        'rows'    => $rows,
        'empty'   => 'Für dieses Jahr sind in dieser Gruppe keine Termine erfasst.',
    ];
}

/** Quote mit deutschem Komma und Prozentzeichen. */
function statisticsReportRate($rate): string
{
    return number_format((float) $rate, 1, ',', '') . ' %';
}

/**
 * Fussnoten des Anwesenheitsberichts.
 *
 * Ohne die erste Zeile behauptet der Bericht Vollstaendigkeit fuer ein Jahr,
 * das noch laeuft. Ohne die dritte verschweigt er, dass automatisch erzeugte
 * Eintraege mitzaehlen (OI-20).
 *
 * @return array<int, string>
 */
function statisticsReportNotes(): array
{
    return [
        'Gezählt werden nur Termine, die zum Zeitpunkt der Erstellung bereits begonnen haben.',
        'Termine ohne Eintrag gelten als unentschuldigt.',
        'Automatisch erzeugte Check-ins zählen wie erfasste Anwesenheiten.',
        'Der Bericht berücksichtigt die Zeiträume der Mitgliedschaft.',
    ];
}

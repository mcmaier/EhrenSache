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
 * Bestand des Demo-Vereins — reine Berechnung.
 *
 * Diese Datei kennt weder Datenbank noch Uhr. Sie bekommt Saat und Stichtag und
 * gibt Zeilen zurück, benannt wie die Tabellenspalten. Alles, was gewürfelt oder
 * gerechnet wird, steht hier und ist damit ohne Datenbank prüfbar; das Schreiben
 * erledigt seed.php.
 *
 * Geheimnisse entstehen bewusst NICHT hier: Der Plan führt die PIN im Klartext,
 * seed.php hasht sie. Sonst wäre der Plan nicht mehr reproduzierbar, weil
 * password_hash() bei jedem Aufruf ein neues Salz zieht.
 */
declare(strict_types=1);

// validateStationPin() prüft die PIN-Regeln. Der Generator nutzt dieselbe
// Funktion wie die Anwendung, damit erzeugte PINs an der Station funktionieren.
require_once __DIR__ . '/../helpers/station.php';

/**
 * Linearer Kongruenzgenerator.
 *
 * Bewusst nicht mt_rand: Dessen Zustand ist global. Zieht irgendwann anderer Code
 * dazwischen eine Zahl, verschiebt sich die gesamte Folge und zwei Läufe erzeugen
 * verschiedene Bestände. Hier hängt die Folge ausschließlich am Saat.
 */
final class DemoRandom
{
    private int $state;

    public function __construct(int $seed)
    {
        // 0 wäre ein Fixpunkt der Rekursion — auf 1 ausweichen.
        $this->state = ($seed & 0x7FFFFFFF) ?: 1;
    }

    private function next(): int
    {
        $this->state = (1103515245 * $this->state + 12345) & 0x7FFFFFFF;

        return $this->state;
    }

    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        // Bewusst über float() und damit über die oberen Bits: Bei einem linearen
        // Kongruenzgenerator mit Modulus 2^31 hat Bit k nur die Periode 2^(k+1).
        // Ein "% n" liest genau diese schwachen unteren Bits und liefert bei
        // Zweierpotenzen eine starre Wiederholung — int(0,3) ergäbe 1230123012...
        return $min + (int) ($this->float() * ($max - $min + 1));
    }

    /** Gleichverteilt in [0, 1). */
    public function float(): float
    {
        return $this->next() / 2147483648.0;
    }

    /** @param array<int, mixed> $list */
    public function pick(array $list)
    {
        // Leere Liste ist in den Folgeaufgaben ein echter Fall (gefilterte Listen).
        // Lautloses null wäre dort schwer zu finden — lieber laut scheitern.
        if ($list === []) {
            throw new InvalidArgumentException('DemoRandom::pick() erhielt eine leere Liste.');
        }

        return $list[$this->int(0, count($list) - 1)];
    }

    public function chance(float $probability): bool
    {
        return $this->float() < $probability;
    }
}

const DEMO_ORG_NAME     = 'Musikverein Musterhausen';
const DEMO_STATION_NAME = 'Probenraum-Station';

/**
 * Auftrittstitel nach Monat.
 *
 * Nach Datum gewählt, nicht nach Reihenfolge: Ein „Adventsständchen" im Juli
 * fällt auf dem Screenshot der Terminübersicht sofort auf.
 */
const DEMO_PERFORMANCE_TITLES = [
    1  => 'Neujahrskonzert',
    2  => 'Faschingsumzug',
    3  => 'Frühjahrskonzert',
    4  => 'Osterkonzert',
    5  => 'Maibaumstellen',
    6  => 'Sommerserenade',
    7  => 'Dorffest',
    8  => 'Kirchweih',
    9  => 'Herbstkonzert',
    10 => 'Erntedankumzug',
    11 => 'Volkstrauertag',
    12 => 'Adventsständchen',
];

/** Gruppen. Die IDs sind fest, weil alles Weitere sie referenziert. */
function buildGroups(): array
{
    return [
        ['group_id' => 1, 'group_name' => 'Aktive',          'description' => 'Aktive Musikerinnen und Musiker', 'is_default' => 1],
        ['group_id' => 2, 'group_name' => 'Jugend',          'description' => 'Jugendorchester und Ausbildung',  'is_default' => 0],
        ['group_id' => 3, 'group_name' => 'Vorstandschaft',  'description' => 'Gewählte Vorstandschaft',         'is_default' => 0],
        ['group_id' => 4, 'group_name' => 'Ehrenmitglieder', 'description' => 'Ehrenmitglieder ohne Dienstpflicht', 'is_default' => 0],
    ];
}

/** Terminarten. */
function buildAppointmentTypes(): array
{
    return [
        ['type_id' => 1, 'type_name' => 'Gesamtprobe',      'description' => 'Wöchentliche Probe des Gesamtorchesters', 'is_default' => 1, 'color' => '#1F5FBF'],
        ['type_id' => 2, 'type_name' => 'Registerprobe',    'description' => 'Probe einzelner Register',                'is_default' => 0, 'color' => '#4CAF50'],
        ['type_id' => 3, 'type_name' => 'Auftritt',         'description' => 'Konzert, Umzug, Ständchen',               'is_default' => 0, 'color' => '#F5A623'],
        ['type_id' => 4, 'type_name' => 'Vorstandssitzung', 'description' => 'Sitzung der Vorstandschaft',              'is_default' => 0, 'color' => '#6B7280'],
    ];
}

/**
 * Gruppenbindung der Terminarten.
 *
 * Sie entscheidet später, wer zu einem Termin überhaupt erwartet wird — ohne sie
 * bekämen Ehrenmitglieder Anwesenheitspflicht bei der Vorstandssitzung.
 */
function buildAppointmentTypeGroups(): array
{
    return [
        ['type_id' => 1, 'group_id' => 1],
        ['type_id' => 1, 'group_id' => 2],
        ['type_id' => 2, 'group_id' => 1],
        ['type_id' => 2, 'group_id' => 2],
        ['type_id' => 3, 'group_id' => 1],
        ['type_id' => 3, 'group_id' => 2],
        ['type_id' => 4, 'group_id' => 3],
    ];
}

/** Tätigkeitsarten der Zeiterfassung, mit gestaffeltem Nachweisgrad. */
function buildActivityTypes(): array
{
    return [
        ['activity_id' => 1, 'activity_name' => 'Bühnenaufbau',            'description' => 'Auf- und Abbau bei Veranstaltungen', 'color' => '#1F5FBF', 'is_default' => 1, 'is_active' => 1, 'verification' => 'start_end'],
        ['activity_id' => 2, 'activity_name' => 'Festvorbereitung',        'description' => 'Vorbereitung von Vereinsfesten',     'color' => '#F5A623', 'is_default' => 0, 'is_active' => 1, 'verification' => 'start_end'],
        ['activity_id' => 3, 'activity_name' => 'Vereinsheim-Renovierung', 'description' => 'Instandhaltung des Vereinsheims',    'color' => '#4CAF50', 'is_default' => 0, 'is_active' => 1, 'verification' => 'start'],
        ['activity_id' => 4, 'activity_name' => 'Notenarchiv',             'description' => 'Pflege des Notenbestands',           'color' => '#6B7280', 'is_default' => 0, 'is_active' => 1, 'verification' => 'none'],
        ['activity_id' => 5, 'activity_name' => 'Instrumentenpflege',      'description' => 'Wartung der Vereinsinstrumente',     'color' => '#0F4F9F', 'is_default' => 0, 'is_active' => 1, 'verification' => 'none'],
        ['activity_id' => 6, 'activity_name' => 'Jugendbetreuung',         'description' => 'Betreuung des Jugendorchesters',     'color' => '#FFC83D', 'is_default' => 0, 'is_active' => 1, 'verification' => 'none'],
    ];
}

/**
 * Gruppenbindung der Tätigkeitsarten.
 *
 * Ohne Eintrag ist eine Tätigkeitsart für niemanden sichtbar: activity_types
 * filtert über EXISTS auf diese Tabelle, und memberMayUseActivity() weist
 * Timer-Start und Selbst-Nachtrag ab. Eine ungebundene Tätigkeit wäre in der
 * Demo also vorhanden, aber unbedienbar.
 */
function buildActivityTypeGroups(): array
{
    return [
        ['activity_id' => 1, 'group_id' => 1],   // Bühnenaufbau: Aktive
        ['activity_id' => 1, 'group_id' => 2],   // und Jugend
        ['activity_id' => 2, 'group_id' => 1],   // Festvorbereitung: Aktive
        ['activity_id' => 2, 'group_id' => 2],   // und Jugend
        ['activity_id' => 2, 'group_id' => 4],   // und Ehrenmitglieder
        ['activity_id' => 3, 'group_id' => 1],   // Vereinsheim-Renovierung: Aktive
        ['activity_id' => 4, 'group_id' => 1],   // Notenarchiv: Aktive
        ['activity_id' => 4, 'group_id' => 4],   // und Ehrenmitglieder
        ['activity_id' => 5, 'group_id' => 1],   // Instrumentenpflege: Aktive
        ['activity_id' => 5, 'group_id' => 2],   // und Jugend
        ['activity_id' => 6, 'group_id' => 1],   // Jugendbetreuung: Aktive
        ['activity_id' => 6, 'group_id' => 3],   // und Vorstandschaft
    ];
}

const DEMO_FIRST_NAMES = [
    'Andreas', 'Anna', 'Bernd', 'Birgit', 'Christian', 'Claudia', 'Daniel', 'Doris',
    'Elias', 'Eva', 'Florian', 'Franziska', 'Georg', 'Greta', 'Hannes', 'Heike',
    'Ingo', 'Irene', 'Jonas', 'Julia', 'Katrin', 'Klaus', 'Lena', 'Lukas',
    'Marion', 'Martin', 'Nadine', 'Nils', 'Olaf', 'Petra', 'Rainer', 'Sabine',
    'Simon', 'Sonja', 'Thomas', 'Tanja', 'Ulrich', 'Ursula', 'Volker', 'Wiebke',
];

const DEMO_LAST_NAMES = [
    'Albrecht', 'Bauer', 'Becker', 'Brandt', 'Dietrich', 'Ehlers', 'Fischer', 'Frank',
    'Graf', 'Hartmann', 'Hoffmann', 'Jung', 'Kaiser', 'Keller', 'Koch', 'Kramer',
    'Lang', 'Lehmann', 'Maier', 'Neumann', 'Ott', 'Peters', 'Reuter', 'Richter',
    'Sauer', 'Schmidt', 'Schneider', 'Schulz', 'Seidel', 'Sommer', 'Stein', 'Thiel',
    'Vogel', 'Wagner', 'Weber', 'Werner', 'Wolf', 'Zimmermann', 'Ziegler', 'Zorn',
];

/**
 * Gruppenstärken.
 *
 * Aktive, Jugend und Ehrenmitglieder decken zusammen genau die 40 Mitglieder ab
 * (28 + 8 + 4). Die Vorstandschaft kommt obendrauf: Sie besteht aus Aktiven, die
 * zusätzlich ein Amt tragen. Deshalb liegt die Summe aller Stärken über 40.
 */
const DEMO_GROUP_SIZES = [1 => 28, 2 => 8, 3 => 6, 4 => 4];

/**
 * Mitglieder samt Gruppenzuordnung, Mitgliedschaftszeiträumen und PINs.
 *
 * Rückgabe: ['members' => [...], 'assignments' => [...], 'membership_dates' => [...]]
 * Die PIN steht im Klartext unter 'pin'; seed.php hasht sie. Ohne diese Trennung
 * wäre der Plan nicht reproduzierbar, weil password_hash() je Aufruf salzt.
 */
// Die Reihenfolge der $random-Aufrufe innerhalb (und vor) dieser Funktion ist
// Teil der Schnittstelle, nicht Implementierungsdetail: Jede zusätzliche oder
// verschobene Ziehung verändert alle nachfolgenden Werte und damit den
// gesamten Demo-Bestand — bereits erstellte Screenshots stimmten dann nicht
// mehr. Neue Ziehungen gehören ans Ende, nie dazwischen.
function buildMembers(DemoRandom $random, string $referenceDate = '2026-09-08'): array
{
    $members     = [];
    $assignments = [];
    $dates       = [];

    // Inaktive Mitglieder stehen fest, damit die Menge nicht vom Zufall abhängt.
    $inactiveIds = [7, 19, 33];

    for ($i = 0; $i < 40; $i++) {
        $id     = $i + 1;
        $active = in_array($id, $inactiveIds, true) ? 0 : 1;

        $members[] = [
            'member_id'     => $id,
            'name'          => DEMO_FIRST_NAMES[$i],
            'surname'       => DEMO_LAST_NAMES[$i],
            'member_number' => sprintf('M%03d', $id),
            'active'        => $active,
            'pin'           => null, // wird unten für 15 Mitglieder gesetzt
        ];

        // Eintritt gestreut über die letzten zwölf Jahre.
        $joinYear  = (int) substr($referenceDate, 0, 4) - $random->int(1, 12);
        $startDate = sprintf('%04d-%02d-01', $joinYear, $random->int(1, 12));

        // Austritt aus dem Beginn ableiten statt unabhängig zu ziehen — sonst
        // könnte das Ende vor dem Beginn liegen (bei manchen Saaten tritt das
        // tatsächlich auf, und die Folgeaufgaben filtern über diesen Zeitraum).
        // $random->int() wird nur im Inaktiv-Fall aufgerufen: Bei aktiven
        // Mitgliedern bleibt end_date null, ohne dass eine Ziehung stattfindet,
        // damit sich die Reihenfolge für aktive Mitglieder nicht verschiebt.
        $endDate = null;
        if ($active === 0) {
            // Austritt zwischen einem Monat nach Eintritt und 60 Tagen vor dem
            // Stichtag.
            $latestEnd = demoShiftDate($referenceDate, -60);
            $spanDays  = (int) ((strtotime($latestEnd) - strtotime($startDate)) / 86400);
            $endDate   = $spanDays > 30
                ? demoShiftDate($startDate, $random->int(30, $spanDays))
                : $latestEnd;
        }

        $dates[] = [
            'member_id'  => $id,
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'status'     => $active === 1 ? 'active' : 'inactive',
        ];
    }

    // Gruppenzuordnung der Reihe nach, damit die Stärken exakt stimmen statt
    // annähernd und jedes Mitglied genau einmal vorkommt:
    //   Mitglieder  1– 8  Jugend
    //   Mitglieder  9–12  Ehrenmitglieder
    //   Mitglieder 13–40  Aktive
    $cursor = 0;
    foreach ([2 => DEMO_GROUP_SIZES[2], 4 => DEMO_GROUP_SIZES[4], 1 => DEMO_GROUP_SIZES[1]] as $groupId => $size) {
        for ($n = 0; $n < $size; $n++) {
            $assignments[] = ['member_id' => $cursor + 1, 'group_id' => $groupId];
            $cursor++;
        }
    }
    // Vorstandschaft: sechs Aktive tragen zusätzlich dieses Amt. Alle sechs IDs
    // liegen im Bereich 13–40, sind also tatsächlich Aktive.
    foreach ([13, 16, 21, 27, 30, 35] as $memberId) {
        $assignments[] = ['member_id' => $memberId, 'group_id' => 3];
    }

    // PIN für 15 Mitglieder. Vierstellig, keine Einheitsziffern, keine Folge —
    // die Regeln stehen in validateStationPin().
    $pinFor = [];
    while (count($pinFor) < 15) {
        $candidate = $random->int(1, 40);
        if (!isset($pinFor[$candidate])) {
            $pinFor[$candidate] = demoPin($random);
        }
    }
    foreach ($members as $idx => $member) {
        if (isset($pinFor[$member['member_id']])) {
            $members[$idx]['pin'] = $pinFor[$member['member_id']];
        }
    }

    return ['members' => $members, 'assignments' => $assignments, 'membership_dates' => $dates];
}

/** Vierstellige PIN, die validateStationPin() besteht. */
function demoPin(DemoRandom $random): string
{
    do {
        $pin = sprintf('%04d', $random->int(1000, 9999));
    } while (validateStationPin($pin, 4) !== null);

    return $pin;
}

/** Datum um $days Tage verschieben. Negativ = in die Vergangenheit. */
function demoShiftDate(string $date, int $days): string
{
    $ts = strtotime($date . ' ' . ($days >= 0 ? '+' : '-') . abs($days) . ' days');

    // Diese Datei kennt keine Uhr außer dem übergebenen Stichtag. Ein stiller
    // Rückfall auf time() würde das brechen, ohne dass es auffiele — analog zu
    // DemoRandom::pick(), das bei leerer Liste ebenfalls wirft statt null zu liefern.
    if ($ts === false) {
        throw new InvalidArgumentException("demoShiftDate() erhielt ein ungültiges Datum: \"{$date}\".");
    }

    return date('Y-m-d', $ts);
}

/**
 * Terminserie über zwölf Monate rückwärts und vier Wochen vorwärts.
 *
 * Vier Termine liegen bewusst in der Zukunft — sonst endet die Terminliste im
 * Screenshot mit der Vergangenheit und wirkt wie ein aufgegebener Verein.
 */
function buildAppointments(DemoRandom $random, string $referenceDate): array
{
    $from = demoShiftDate($referenceDate, -365);
    $to   = demoShiftDate($referenceDate, 28);

    $appointments = [];
    $id           = 1;

    // Gesamtprobe: jeden Freitag, 20:00.
    foreach (demoWeekdaySeries($from, $to, 5, 1) as $date) {
        $appointments[] = [
            'appointment_id' => $id++,
            'title'          => 'Gesamtprobe',
            'type_id'        => 1,
            'description'    => null,
            'date'           => $date,
            'start_time'     => '20:00:00',
        ];
    }

    // Registerprobe: jeden zweiten Dienstag, 19:30.
    foreach (demoWeekdaySeries($from, $to, 2, 2) as $date) {
        $appointments[] = [
            'appointment_id' => $id++,
            'title'          => 'Registerprobe',
            'type_id'        => 2,
            'description'    => null,
            'date'           => $date,
            'start_time'     => '19:30:00',
        ];
    }

    // Vorstandssitzung: erster Montag im Monat, 19:00.
    foreach (demoFirstMondays($from, $to) as $date) {
        $appointments[] = [
            'appointment_id' => $id++,
            'title'          => 'Vorstandssitzung',
            'type_id'        => 4,
            'description'    => null,
            'date'           => $date,
            'start_time'     => '19:00:00',
        ];
    }

    // Auftritte: zehn Samstage, gleichmäßig über die verfügbaren Samstage verteilt.
    $saturdays = demoWeekdaySeries($from, demoShiftDate($referenceDate, -1), 6, 1);
    $step      = max(1, intdiv(count($saturdays), 10));
    for ($n = 0; $n < 10 && $n * $step < count($saturdays); $n++) {
        $date           = $saturdays[$n * $step];
        $appointments[] = [
            'appointment_id' => $id++,
            'title'          => DEMO_PERFORMANCE_TITLES[(int) date('n', strtotime($date))],
            'type_id'        => 3,
            'description'    => null,
            'date'           => $date,
            'start_time'     => sprintf('%02d:00:00', $random->int(10, 19)),
        ];
    }

    // Genau vier Termine in der Zukunft: die nächsten Gesamt- und Registerproben
    // stehen bereits in den Serien oben. Alles danach wird gekappt.
    $past   = array_values(array_filter($appointments, fn ($a) => $a['date'] <= $referenceDate));
    $future = array_values(array_filter($appointments, fn ($a) => $a['date'] > $referenceDate));
    usort($future, fn ($x, $y) => strcmp($x['date'], $y['date']));
    $future = array_slice($future, 0, 4);

    $result = array_merge($past, $future);
    usort($result, fn ($x, $y) => strcmp($x['date'], $y['date']) ?: strcmp($x['start_time'], $y['start_time']));

    // IDs nach der Sortierung neu vergeben, damit sie der Chronologie folgen.
    foreach ($result as $idx => $row) {
        $result[$idx]['appointment_id'] = $idx + 1;
    }

    return $result;
}

/**
 * Alle Daten eines Wochentags zwischen zwei Grenzen.
 *
 * @param int $weekday 1 = Montag … 7 = Sonntag (wie date('N'))
 * @param int $every   1 = jede Woche, 2 = jede zweite …
 * @return array<int, string>
 */
function demoWeekdaySeries(string $from, string $to, int $weekday, int $every): array
{
    $dates  = [];
    $cursor = strtotime($from);
    $end    = strtotime($to);

    // Auf den ersten passenden Wochentag vorrücken.
    while ((int) date('N', $cursor) !== $weekday) {
        $cursor = strtotime('+1 day', $cursor);
    }

    $n = 0;
    while ($cursor <= $end) {
        if ($n % $every === 0) {
            $dates[] = date('Y-m-d', $cursor);
        }
        $cursor = strtotime('+1 week', $cursor);
        $n++;
    }

    return $dates;
}

/** @return array<int, string> Erster Montag jedes Monats im Zeitraum. */
function demoFirstMondays(string $from, string $to): array
{
    $dates  = [];
    $cursor = strtotime(date('Y-m-01', strtotime($from)));
    $end    = strtotime($to);

    while ($cursor <= $end) {
        $first = strtotime('first monday of ' . date('F Y', $cursor));
        if ($first >= strtotime($from) && $first <= $end) {
            $dates[] = date('Y-m-d', $first);
        }
        $cursor = strtotime('+1 month', $cursor);
    }

    return $dates;
}

/**
 * Alle (Mitglied, Termin)-Paare, zu denen ein Mitglied erwartet wird.
 *
 * Erwartet wird, wessen Mitgliedschaft am Termintag lief und wessen Gruppe an
 * der Terminart hängt. Beide Bedingungen brauchen Anwesenheiten wie Anträge —
 * eine Entschuldigung für einen Termin, zu dem jemand gar nicht erwartet wurde,
 * ergibt so wenig Sinn wie eine Anwesenheit dort.
 *
 * Die Reihenfolge ist Termin für Termin, darin Mitglied für Mitglied. Sie ist
 * Teil der Schnittstelle: buildRecords() zieht in genau dieser Reihenfolge aus
 * dem Zufallsgenerator.
 *
 * @return array<int, array{member_id: int, appointment_id: int}>
 */
function demoExpectedPairs(
    array $members,
    array $assignments,
    array $membershipDates,
    array $appointments,
    array $typeGroups,
    string $referenceDate
): array {
    $groupsOf = [];
    foreach ($assignments as $a) {
        $groupsOf[$a['member_id']][] = $a['group_id'];
    }
    $groupsForType = [];
    foreach ($typeGroups as $l) {
        $groupsForType[$l['type_id']][] = $l['group_id'];
    }
    $periodOf = [];
    foreach ($membershipDates as $d) {
        $periodOf[$d['member_id']] = ['start' => $d['start_date'], 'end' => $d['end_date']];
    }

    $pairs = [];
    foreach ($appointments as $appt) {
        if ($appt['date'] > $referenceDate) {
            continue;
        }
        $allowed = $groupsForType[$appt['type_id']] ?? [];

        foreach ($members as $m) {
            // Der Mitgliedschaftszeitraum entscheidet, nicht das active-Flag:
            // Das Flag kennt nur "heute", der Bestand reicht zwölf Monate
            // zurück. Ein im Februar ausgetretenes Mitglied war im Januar
            // anwesend, und ein im Dezember eingetretenes war es im
            // September nicht.
            $period = $periodOf[$m['member_id']];
            if ($appt['date'] < $period['start']) {
                continue;
            }
            if ($period['end'] !== null && $appt['date'] > $period['end']) {
                continue;
            }
            if (count(array_intersect($allowed, $groupsOf[$m['member_id']] ?? [])) === 0) {
                continue;
            }

            $pairs[] = ['member_id' => $m['member_id'], 'appointment_id' => $appt['appointment_id']];
        }
    }

    return $pairs;
}

/**
 * Anwesenheiten zu allen vergangenen Terminen.
 *
 * Jedes Mitglied bekommt eine eigene Grundquote. Ohne diese Streuung sähe die
 * Statistik aus wie ein Balken auf gleicher Höhe — genau das Bild, das niemanden
 * überzeugt. Die Ankunftszeiten streuen um den Terminbeginn, damit die
 * Pünktlichkeitsauswertung eine Verteilung zeigt statt einer Linie.
 *
 * Die Reihenfolge der $random-Aufrufe ist auch hier Teil der Schnittstelle
 * (siehe Hinweis über buildMembers()): Pro erzeugtem Datensatz stehen vier
 * Ziehungen (Quote-Vergleich, Verspätungswahrscheinlichkeit, Ankunftsoffset,
 * Check-in-Quelle) — jede zusätzliche oder verschobene Ziehung verändert die
 * gesamte Folge und damit bereits erstellte Screenshots.
 */
function buildRecords(
    DemoRandom $random,
    array $members,
    array $assignments,
    array $membershipDates,
    array $appointments,
    array $typeGroups,
    string $referenceDate
): array {
    // Grundquote je Mitglied, einmal gezogen und dann fest.
    $quota = [];
    foreach ($members as $m) {
        $quota[$m['member_id']] = $random->int(60, 95) / 100;
    }

    $startOf = [];
    foreach ($appointments as $appt) {
        $startOf[$appt['appointment_id']] = strtotime($appt['date'] . ' ' . $appt['start_time']);
    }

    $pairs   = demoExpectedPairs($members, $assignments, $membershipDates, $appointments, $typeGroups, $referenceDate);
    $records = [];
    foreach ($pairs as $pair) {
        if (!$random->chance($quota[$pair['member_id']])) {
            continue;
        }

        // Ankunft: meist knapp vor bis knapp nach Beginn, selten deutlich später.
        $offset = $random->chance(0.08)
            ? $random->int(16, 40)
            : $random->int(-10, 15);

        $source = $random->pick(['user_totp', 'user_totp', 'station_pin', 'auto_checkin', 'admin']);

        $records[] = [
            'member_id'      => $pair['member_id'],
            'appointment_id' => $pair['appointment_id'],
            'arrival_time'   => date('Y-m-d H:i:s', $startOf[$pair['appointment_id']] + $offset * 60),
            'status'         => 'present',
            'checkin_source' => $source,
            'source_device'  => $source === 'station_pin' ? DEMO_STATION_NAME : null,
            'location_name'  => $source === 'station_pin' ? DEMO_STATION_NAME : null,
        ];
    }

    return $records;
}

/**
 * Anträge auf Entschuldigung und Zeitkorrektur.
 *
 * Beide Antragsarten setzen voraus, dass das Mitglied zum Termin überhaupt
 * erwartet wurde (siehe demoExpectedPairs()) — eine `absence` entsteht nur zu
 * einem erwarteten Termin ohne Anwesenheitseintrag, eine `time_correction` nur
 * zu einem mit Eintrag. Gezogen wird je Art ohne Zurücklegen: Jedes Paar taucht
 * höchstens einmal auf.
 *
 * Ist eine der beiden Kandidatenlisten leer oder erschöpft, weicht die
 * Ziehung auf die andere aus. Läuft auch die leer, liefert die Funktion
 * weniger als 25 Anträge zurück, statt einen ungültigen Antrag zu erzeugen.
 *
 * Mindestens vier bleiben offen — sonst ist der Antrags-Tab im Screenshot leer,
 * und genau er belegt, dass es einen Freigabeweg gibt.
 *
 * created_by und approved_by tragen Platzhalter-IDs; seed.php ersetzt sie durch
 * die tatsächlichen Benutzer-IDs (2 = Manager). Der Plan kennt keine Konten.
 */
const DEMO_EXCEPTION_REASONS = [
    'Krankheit', 'Beruflich verhindert', 'Urlaub', 'Familienfeier',
    'Prüfungsvorbereitung', 'Kinderbetreuung', 'Arzttermin', 'Auswärtstermin',
];

function buildExceptions(
    DemoRandom $random,
    array $members,
    array $assignments,
    array $membershipDates,
    array $appointments,
    array $typeGroups,
    array $records,
    string $referenceDate
): array {
    $pairs = demoExpectedPairs($members, $assignments, $membershipDates, $appointments, $typeGroups, $referenceDate);
    if ($pairs === []) {
        return [];
    }

    $present = [];
    foreach ($records as $rec) {
        $present[$rec['member_id'] . '-' . $rec['appointment_id']] = true;
    }

    $absenceCandidates        = [];
    $timeCorrectionCandidates = [];
    foreach ($pairs as $pair) {
        if (isset($present[$pair['member_id'] . '-' . $pair['appointment_id']])) {
            $timeCorrectionCandidates[] = $pair;
        } else {
            $absenceCandidates[] = $pair;
        }
    }

    $appointmentById = [];
    foreach ($appointments as $a) {
        $appointmentById[$a['appointment_id']] = $a;
    }

    $exceptions = [];
    for ($n = 0; $n < 25; $n++) {
        $kind = $random->chance(0.65) ? 'absence' : 'time_correction';

        // Bevorzugte Liste erschöpft oder von Anfang an leer: auf die andere
        // ausweichen. Ist auch die leer, bricht die Erzeugung ab — lieber
        // weniger als 25 Anträge als ein ungültiges Paar.
        if ($kind === 'absence' && $absenceCandidates === []) {
            $kind = 'time_correction';
        } elseif ($kind === 'time_correction' && $timeCorrectionCandidates === []) {
            $kind = 'absence';
        }
        if ($absenceCandidates === [] && $timeCorrectionCandidates === []) {
            break;
        }

        $candidates = $kind === 'absence' ? $absenceCandidates : $timeCorrectionCandidates;
        $index      = $random->int(0, count($candidates) - 1);
        $pair       = $candidates[$index];
        // Gezogen ohne Zurücklegen, damit kein Paar zweimal einen Antrag erhält.
        if ($kind === 'absence') {
            array_splice($absenceCandidates, $index, 1);
        } else {
            array_splice($timeCorrectionCandidates, $index, 1);
        }

        $appt  = $appointmentById[$pair['appointment_id']];
        $start = strtotime($appt['date'] . ' ' . $appt['start_time']);

        // Die ersten fünf bleiben offen, der Rest ist entschieden.
        $status = $n < 5
            ? 'pending'
            : ($random->chance(0.8) ? 'approved' : 'rejected');

        $exceptions[] = [
            'member_id'              => $pair['member_id'],
            'appointment_id'         => $pair['appointment_id'],
            'exception_type'         => $kind,
            'reason'                 => $kind === 'absence'
                ? $random->pick(DEMO_EXCEPTION_REASONS)
                : 'Ankunft wurde nicht erfasst',
            'requested_arrival_time' => $kind === 'time_correction'
                ? date('Y-m-d H:i:s', $start + $random->int(-15, 20) * 60)
                : null,
            'status'                 => $status,
            'created_by'             => 'member',  // seed.php löst auf
            'approved_by'            => $status === 'pending' ? null : 'manager',
            'approved_at'            => $status === 'pending'
                ? null
                : date('Y-m-d H:i:s', $start + $random->int(1, 5) * 86400),
            'created_at'             => date('Y-m-d H:i:s', $start - $random->int(1, 6) * 86400),
        ];
    }

    return $exceptions;
}

/** Kurze Freitextnotizen, wie sie in einem Nachtrag realistisch stehen könnten. */
const DEMO_WORK_SESSION_NOTES = [
    'Vor dem Auftritt aufgebaut',
    'Gemeinsam mit weiteren Helfern',
    'Kurzfristig eingesprungen',
    'Nacharbeiten am Wochenende',
    'Vorbereitung für den nächsten Termin',
];

/**
 * Arbeitszeitsitzungen samt Auditspur.
 *
 * Rückgabe: ['sessions' => [...], 'log' => [...]]
 *
 * Sitzung 120 läuft noch (end_time null) und gehört fest Mitglied 1 — das
 * Mitglied, mit dem später die PWA fotografiert wird. Alle anderen 119
 * Sitzungen sind abgeschlossen.
 *
 * Kein Feld active_member: work_sessions.active_member ist in der Datenbank
 * eine generierte virtuelle Spalte (if(end_time is null, member_id, NULL))
 * mit UNIQUE-Index. Die Datenbank berechnet den Wert beim Schreiben selbst;
 * ein INSERT, der ihn mitliefert, würde von MySQL abgewiesen. Der UNIQUE-Index
 * stellt zugleich sicher, dass je Mitglied höchstens eine Sitzung ohne
 * end_time im Bestand stehen darf — im Plan also genau die von Sitzung 120.
 *
 * Mitglied und Tätigkeit hängen zusammen: Eine Tätigkeit ohne Gruppenbindung
 * zum gewählten Mitglied wäre in der Oberfläche nicht buchbar (siehe
 * buildActivityTypeGroups()). Ein gesetzter appointment_id-Wert stammt
 * ausschließlich aus demoExpectedPairs() — sonst hinge eine Arbeitszeit an
 * einem Termin, zu dem das Mitglied gar nicht erwartet wurde.
 *
 * Der Status entsteht über den Zähler $n, nicht über eine Ziehung: n % 24 = 0
 * → rejected (24, 48, 72, 96 — vier Sitzungen), sonst n % 8 = 0 → submitted
 * (zehn Sitzungen), sonst confirmed. Die Reihenfolge der beiden Zweige ist
 * wesentlich: Jedes Vielfache von 24 ist auch eines von 8 — stünde der
 * 8er-Zweig zuerst, gäbe es nie eine Ablehnung. Sitzung 120 fällt zwar
 * ebenfalls auf ein Vielfaches von 24, wird aber danach fest auf confirmed
 * gesetzt: Eine laufende Sitzung kann nicht zugleich abgelehnt sein, und die
 * Zählung "106 confirmed inkl. der laufenden" schließt sie ausdrücklich ein.
 *
 * Die Reihenfolge der $random-Aufrufe ist wie bei den übrigen Build-Funktionen
 * Teil der Schnittstelle (siehe Hinweis über buildMembers()).
 *
 * Sitzung 120 (die laufende) beginnt 95 Minuten vor
 * "$referenceDate $referenceTime", nicht auf dem Raster der übrigen Sitzungen:
 * Ein Datum allein reicht für eine laufende Sitzung nicht, sie braucht eine
 * Uhrzeit relativ zum tatsächlichen Lauf des Generators — sonst kann sie in
 * der Zukunft liegen (bei einem Vormittagslauf mit gewürfelter Uhrzeit
 * zwischen 08:00 und 18:45) und lässt sich weder in der PWA noch über die API
 * beenden. Ihr create-Eintrag in der Auditspur trägt denselben Zeitpunkt.
 */
function buildWorkSessions(
    DemoRandom $random,
    array $members,
    array $assignments,
    array $membershipDates,
    array $appointments,
    array $typeGroups,
    array $activityGroups,
    string $referenceDate,
    string $referenceTime
): array {
    $windowStart = demoShiftDate($referenceDate, -360);
    $windowEnd   = demoShiftDate($referenceDate, -1);

    $groupsOf = [];
    foreach ($assignments as $a) {
        $groupsOf[$a['member_id']][] = $a['group_id'];
    }

    $activitiesForGroup = [];
    foreach ($activityGroups as $l) {
        $activitiesForGroup[$l['group_id']][] = $l['activity_id'];
    }

    // Nachweisgrad je Taetigkeit, fuer die Auswahl der laufenden Sitzung unten.
    $verificationOfActivity = [];
    foreach (buildActivityTypes() as $activityType) {
        $verificationOfActivity[$activityType['activity_id']] = $activityType['verification'];
    }

    $periodOf = [];
    foreach ($membershipDates as $d) {
        $periodOf[$d['member_id']] = ['start' => $d['start_date'], 'end' => $d['end_date']];
    }

    // Auswahlbereich je Mitglied: Schnittmenge aus Mitgliedschaftszeitraum und
    // dem 360-Tage-Fenster vor dem Stichtag. Mitglieder ohne Schnittmenge (z. B.
    // vor über einem Jahr ausgetreten) fehlen bewusst im Kandidatenpool — für
    // sie gäbe es sonst keinen gültigen Sitzungstag.
    $memberWindow = [];
    foreach ($members as $m) {
        $period = $periodOf[$m['member_id']] ?? null;
        if ($period === null) {
            continue;
        }
        $effEnd = $period['end'] ?? $windowEnd;
        $effStart = $period['start'] > $windowStart ? $period['start'] : $windowStart;
        $effEnd   = $effEnd < $windowEnd ? $effEnd : $windowEnd;
        if ($effStart <= $effEnd) {
            $memberWindow[$m['member_id']] = ['start' => $effStart, 'end' => $effEnd];
        }
    }
    $eligibleMemberIds = array_keys($memberWindow);

    $expectedByMember = [];
    foreach (demoExpectedPairs($members, $assignments, $membershipDates, $appointments, $typeGroups, $referenceDate) as $pair) {
        $expectedByMember[$pair['member_id']][] = $pair['appointment_id'];
    }

    $sessions = [];
    $log      = [];

    for ($n = 1; $n <= 120; $n++) {
        $isRunning = $n === 120;

        if ($isRunning) {
            // Fest auf Mitglied 1 — kein Zug aus dem Zufallsgenerator.
            $memberId = 1;
            $date     = $referenceDate;
        } else {
            $memberId = $random->pick($eligibleMemberIds);
            $window   = $memberWindow[$memberId];
            $spanDays = (int) ((strtotime($window['end']) - strtotime($window['start'])) / 86400);
            $date     = demoShiftDate($window['start'], $random->int(0, $spanDays));
        }

        // Uhrzeit: 08:00 bis 18:45 in Viertelstundenschritten (44 Raster-Werte).
        // Für die laufende Sitzung wird trotzdem gezogen — die Reihenfolge der
        // Ziehungen bleibt so für Sitzung 120 selbst unverändert (siehe
        // appointmentId und $note unten) —, das Ergebnis aber verworfen: Ihr
        // Start ergibt sich aus dem Bezugszeitpunkt, nicht aus dem Raster.
        $slot      = $random->int(0, 43);
        $minutes   = $slot * 15;
        $startTime = $isRunning
            ? date('Y-m-d H:i:s', strtotime($referenceDate . ' ' . $referenceTime) - 95 * 60)
            : sprintf('%s %02d:%02d:00', $date, 8 + intdiv($minutes, 60), $minutes % 60);

        $eligibleActivities = [];
        foreach ($groupsOf[$memberId] ?? [] as $groupId) {
            foreach ($activitiesForGroup[$groupId] ?? [] as $activityId) {
                $eligibleActivities[$activityId] = true;
            }
        }

        $activityPool = array_keys($eligibleActivities);
        if ($isRunning) {
            // Eine Taetigkeit mit Nachweispflicht (verification start/start_end)
            // laesst sich ohne TOTP-Code nicht beenden (workSessionStop() weist
            // das mit 409 ab). Fuer eine Sitzung, die im Demo-Bestand dauerhaft
            // offen steht, hiesse das: Weder die Testsuite noch ein Besucher der
            // Demo bekommt sie zu. Der laufende Timer soll etwas zeigen, das man
            // auch wieder ausschalten kann — deshalb bevorzugt die laufende
            // Sitzung eine Taetigkeit ohne Nachweispflicht.
            $noVerificationPool = array_values(array_filter(
                $activityPool,
                static fn (int $id): bool => ($verificationOfActivity[$id] ?? null) === 'none'
            ));
            if ($noVerificationPool !== []) {
                // Gibt es fuer die Gruppen des Mitglieds keine Taetigkeit ohne
                // Nachweispflicht, bleibt es beim bisherigen Verhalten: eine
                // beliebige erlaubte Taetigkeit.
                $activityPool = $noVerificationPool;
            }
        }
        $activityId = $random->pick($activityPool);

        if ($isRunning) {
            $breakMinutes = 0;
            $endTime      = null;
        } else {
            $durationMinutes = $random->int(45, 300);
            $breakMinutes    = $random->chance(0.3) ? $random->pick([15, 30, 45]) : 0;
            $endTime         = date('Y-m-d H:i:s', strtotime($startTime) + $durationMinutes * 60);
        }

        // Quelle: Ein manueller Nachtrag hat per Definition ein Ende, eine
        // laufende Sitzung kann also nicht 'manual' sein — bei ihr fest
        // 'timer'. Gezogen wird trotzdem, das Ergebnis dann verworfen, damit
        // sich weder die Folge der übrigen Sitzungen noch die von Sitzung 120
        // selbst verschobenen Ziehungen (appointment_id, note) gegenüber dem
        // bisherigen Bestand ändern.
        $drawnSource = $random->pick(['timer', 'timer', 'manual', 'station']);
        $source      = $isRunning ? 'timer' : $drawnSource;

        $appointmentId = null;
        if ($random->chance(1 / 3)) {
            // Nur ein Termin, zu dem dieses Mitglied tatsächlich erwartet wurde
            // (siehe demoExpectedPairs()). Findet sich keiner, bleibt es beim
            // null — kein zusätzlicher Zug, sonst würde ein leerer Kandidatenkreis
            // die Folge für alle nachfolgenden Sitzungen verschieben.
            $candidates = $expectedByMember[$memberId] ?? [];
            if ($candidates !== []) {
                $appointmentId = $random->pick($candidates);
            }
        }

        $note = $random->chance(0.4) ? $random->pick(DEMO_WORK_SESSION_NOTES) : null;

        if ($isRunning) {
            $status = 'confirmed';
        } elseif ($n % 24 === 0) {
            $status = 'rejected';
        } elseif ($n % 8 === 0) {
            $status = 'submitted';
        } else {
            $status = 'confirmed';
        }

        if ($isRunning) {
            // Eine laufende Sitzung wurde noch nicht freigegeben — dazu passt,
            // dass ihre Auditspur unten nur den create-Eintrag erhält.
            $approvedBy = null;
            $approvedAt = null;
        } elseif ($status === 'submitted') {
            $approvedBy = null;
            $approvedAt = null;
        } else {
            $approvedBy = 'manager';
            $approvedAt = date('Y-m-d H:i:s', strtotime($startTime) + $random->int(1, 4) * 86400);
        }

        $sessions[] = [
            'session_id'          => $n,
            'member_id'           => $memberId,
            'activity_id'         => $activityId,
            'appointment_id'      => $appointmentId,
            'start_time'          => $startTime,
            'end_time'            => $endTime,
            'break_minutes'       => $breakMinutes,
            'break_started_at'    => null,
            'note'                => $note,
            'start_location_name' => $source === 'station' ? DEMO_STATION_NAME : null,
            'end_location_name'   => ($source === 'station' && $endTime !== null) ? DEMO_STATION_NAME : null,
            'status'              => $status,
            'source'              => $source,
            'created_by'          => 'member',   // seed.php löst auf
            'approved_by'         => $approvedBy, // seed.php löst auf
            'approved_at'         => $approvedAt,
        ];

        // Auditspur: jede Sitzung bekommt ihren create-Eintrag zum Beginn.
        $log[] = [
            'session_id' => $n,
            'changed_by' => 'member',   // seed.php löst auf
            'changed_at' => $startTime,
            'action'     => 'create',
            'changes'    => null,
        ];

        if (!$isRunning) {
            // Freigabe oder Ablehnung: einen Tag nach Beginn, fest — anders als
            // approved_at oben, das über einen eigenen Zug zwischen ein und
            // vier Tagen streut.
            $decisionAt = date('Y-m-d H:i:s', strtotime($startTime) + 86400);
            if ($status === 'confirmed') {
                $log[] = [
                    'session_id' => $n,
                    'changed_by' => 'manager', // seed.php löst auf
                    'changed_at' => $decisionAt,
                    'action'     => 'approve',
                    'changes'    => null,
                ];
            } elseif ($status === 'rejected') {
                $log[] = [
                    'session_id' => $n,
                    'changed_by' => 'manager', // seed.php löst auf
                    'changed_at' => $decisionAt,
                    'action'     => 'reject',
                    'changes'    => 'Doppelte Erfassung',
                ];
            }
        }
    }

    return ['sessions' => $sessions, 'log' => $log];
}

/** Einstellungen, die der Generator setzt. Werte als String wie in system_settings. */
function buildSettings(): array
{
    return [
        'organization_name'      => DEMO_ORG_NAME,
        'organization_logo'      => '',
        'primary_color'          => '#1F5FBF',
        'secondary_color'        => '#4CAF50',
        'worktime_enabled'       => '1',
        'station_pin_enabled'    => '1',
        'station_pin_min_length' => '4',
        'pagination_limit'       => '25',
    ];
}

/**
 * Konten und Geräte.
 *
 * Passwörter und Token stehen hier NICHT: seed.php hasht bzw. würfelt sie. Der
 * Plan nennt nur, welche Konten es gibt und woran sie hängen.
 */
function buildUsers(): array
{
    return [
        ['user_id' => 1, 'email' => 'admin@musterhausen.example',   'name' => 'Vereinsverwaltung', 'device_name' => null, 'role' => 'admin',   'device_type' => null, 'member_id' => null, 'is_active' => 1, 'account_status' => 'active', 'email_verified' => 1],
        ['user_id' => 2, 'email' => 'manager@musterhausen.example', 'name' => 'Schriftführung',    'device_name' => null, 'role' => 'manager', 'device_type' => null, 'member_id' => null, 'is_active' => 1, 'account_status' => 'active', 'email_verified' => 1],
        ['user_id' => 3, 'email' => 'user@musterhausen.example',    'name' => 'Mitglied',          'device_name' => null, 'role' => 'user',    'device_type' => null, 'member_id' => 1,    'is_active' => 1, 'account_status' => 'active', 'email_verified' => 1],
        ['user_id' => 4, 'email' => null, 'name' => null, 'device_name' => DEMO_STATION_NAME, 'role' => 'device', 'device_type' => 'kiosk',          'member_id' => null, 'is_active' => 1, 'account_status' => 'active', 'email_verified' => 0],
        ['user_id' => 5, 'email' => null, 'name' => null, 'device_name' => 'Proberaum',        'role' => 'device', 'device_type' => 'totp_location', 'member_id' => null, 'is_active' => 1, 'account_status' => 'active', 'email_verified' => 0],
    ];
}

/**
 * Der vollständige Bestand.
 *
 * Ein Aufruf, ein Zufallsgenerator, eine Reihenfolge — damit derselbe Saat
 * denselben Bestand ergibt. Wird hier eine Zeile eingefügt, verschiebt sich alles
 * Nachfolgende; das ist gewollt und der Grund, warum die Reihenfolge feststeht.
 *
 * Reproduzierbarkeit hängt an Saat und Stichtag ($referenceDate) — mit einer
 * Ausnahme: Der Start der einen laufenden Arbeitszeitsitzung (siehe
 * buildWorkSessions()) hängt zusätzlich an $referenceTime, dem Zeitpunkt des
 * Laufs. Alles andere im Bestand ist von $referenceTime unabhängig.
 */
function buildDemoPlan(int $seed, string $referenceDate, string $referenceTime = '12:00:00'): array
{
    $random = new DemoRandom($seed);

    $members        = buildMembers($random, $referenceDate);
    $appointments   = buildAppointments($random, $referenceDate);
    $activities     = buildActivityTypes();
    $activityGroups = buildActivityTypeGroups();
    $typeGroups     = buildAppointmentTypeGroups();

    $records    = buildRecords($random, $members['members'], $members['assignments'], $members['membership_dates'], $appointments, $typeGroups, $referenceDate);
    $exceptions = buildExceptions($random, $members['members'], $members['assignments'], $members['membership_dates'], $appointments, $typeGroups, $records, $referenceDate);
    $work       = buildWorkSessions($random, $members['members'], $members['assignments'], $members['membership_dates'], $appointments, $typeGroups, $activityGroups, $referenceDate, $referenceTime);

    return [
        'settings'                 => buildSettings(),
        'groups'                   => buildGroups(),
        'members'                  => $members['members'],
        'member_group_assignments' => $members['assignments'],
        'membership_dates'         => $members['membership_dates'],
        'users'                    => buildUsers(),
        'appointment_types'        => buildAppointmentTypes(),
        'appointment_type_groups'  => $typeGroups,
        'activity_types'           => $activities,
        'activity_type_groups'     => $activityGroups,
        'appointments'             => $appointments,
        'records'                  => $records,
        'exceptions'               => $exceptions,
        'work_sessions'            => $work['sessions'],
        'work_session_log'         => $work['log'],
    ];
}

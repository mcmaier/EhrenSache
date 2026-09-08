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

        $dates[] = [
            'member_id'  => $id,
            'start_date' => $startDate,
            'end_date'   => $active === 1 ? null : demoShiftDate($referenceDate, -$random->int(60, 400)),
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

    return date('Y-m-d', $ts === false ? time() : $ts);
}

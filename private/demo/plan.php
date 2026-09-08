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

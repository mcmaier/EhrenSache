<?php
/**
 * Regeln von Puenktlichkeit und Zuverlaessigkeit, ohne Datenbank.
 *
 * Die holenden Funktionen in helpers/punctuality.php liefern Rohfakten; alles,
 * was entschieden wird -- Karenz, Kappung, Mindestzahl, Reihenfolge der
 * Ausgaenge --, steht in den Funktionen, die hier geprueft werden.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/punctuality.php';

/** Eine Messung: Sekunden nach Terminbeginn, negativ = frueher. */
function puM(int $seconds, string $source = 'station_pin'): array
{
    return ['delta_seconds' => $seconds, 'checkin_source' => $source];
}

// ---- Karenz aus der Einstellung ---------------------------------------------

test('punctualityGraceFromSetting uebernimmt ganze Zahlen im Bereich', function () {
    assertSame(0,   punctualityGraceFromSetting('0'));
    assertSame(-5,  punctualityGraceFromSetting('-5'));
    assertSame(60,  punctualityGraceFromSetting('60'));
    assertSame(-60, punctualityGraceFromSetting('-60'));
});

test('punctualityGraceFromSetting faellt bei Unbrauchbarem auf 0 zurueck', function () {
    // Die Einstellungsseite prueft den Bereich, der Server beim Speichern
    // nicht. Ein Wert von Hand in der Datenbank darf die Kennzahl nicht
    // unbemerkt verbiegen.
    assertSame(0, punctualityGraceFromSetting('61'));
    assertSame(0, punctualityGraceFromSetting('-61'));
    assertSame(0, punctualityGraceFromSetting(''));
    assertSame(0, punctualityGraceFromSetting('5 Minuten'));
    assertSame(0, punctualityGraceFromSetting('2.5'));
});

// ---- Puenktlichkeit ---------------------------------------------------------

test('punctualityBuild zaehlt puenktlich, spaet und die gekappte Verspaetung', function () {
    $messungen = [
        puM(-300),              // 5 Minuten frueher
        puM(30),                // 20:00:30 gilt als 20:00 -- abgerundet
        puM(180),               // 3 Minuten spaet
        puM(2700),              // 45 Minuten spaet, gekappt auf 20
        puM(600, 'exception_request'),   // 10 Minuten spaet, Selbstauskunft
    ];

    $p = punctualityBuild($messungen, 6, 0);

    assertSame(true, $p['enabled']);
    assertSame(true, $p['sufficient']);
    assertSame(5,    $p['min_measurements']);
    assertSame(5,    $p['measured_count']);
    assertSame(6,    $p['total_count']);
    assertSame(2,    $p['on_time_count']);
    assertSame(40.0, $p['rate']);
    assertSame(3,    $p['late_count']);
    assertSame(11.0, $p['avg_late_minutes'], '(3 + 20 + 10) / 3');
    assertSame(1,    $p['self_reported_count']);
});

test('punctualityBuild rundet negative Sekunden ab, nicht zur Null', function () {
    // floor(-30 / 60) = -1: 19:59:30 ist eine Minute vor Beginn, nicht null.
    $p = punctualityBuild([puM(-30), puM(-30), puM(-30), puM(-30), puM(-30)], 5, -1);
    assertSame(5, $p['on_time_count'], 'Bei Karenz -1 muessen 19:59:30-Ankuenfte puenktlich sein');
});

test('punctualityBuild wendet die Karenz relativ zum Beginn an', function () {
    $messungen = [puM(-300), puM(30), puM(180), puM(2700), puM(600)];

    assertSame(1, punctualityBuild($messungen, 5, -5)['on_time_count'], 'nur -5 liegt bei Karenz -5');
    assertSame(3, punctualityBuild($messungen, 5, 5)['on_time_count'],  '-5, 0 und 3 liegen bei Karenz +5');
});

test('punctualityBuild misst die Verspaetung ab Beginn, nicht ab der Karenz', function () {
    // Spec 3.4: Bei Karenz -5 ist eine Ankunft 3 Minuten VOR Beginn
    // unpuenktlich, gehoert aber nicht ins Verspaetungsmass.
    $p = punctualityBuild([puM(-180), puM(-180), puM(-180), puM(-180), puM(-180)], 5, -5);

    assertSame(0,    $p['on_time_count']);
    assertSame(0,    $p['late_count']);
    assertSame(null, $p['avg_late_minutes']);
});

test('punctualityBuild gibt unter fuenf Messungen keine Quote aus', function () {
    $p = punctualityBuild([puM(0), puM(0), puM(0), puM(0)], 10, 0);

    assertSame(false, $p['sufficient']);
    assertSame(null,  $p['rate'], 'Nicht 0 %, nicht 100 % -- keine Quote');
    assertSame(4,     $p['measured_count']);
});

test('punctualityBuild nennt ohne Verspaetung keinen Durchschnitt', function () {
    // "im Schnitt 0 Minuten zu spaet" waere eine Aussage ueber eine leere Menge.
    $p = punctualityBuild([puM(0), puM(0), puM(0), puM(0), puM(0)], 5, 0);
    assertSame(null, $p['avg_late_minutes']);
});

test('punctualityBuild vertraegt einen leeren Bereich', function () {
    $p = punctualityBuild([], 0, 0);

    assertSame(false, $p['sufficient']);
    assertSame(0,     $p['measured_count']);
    assertSame(null,  $p['rate']);
});

// ---- Zuverlaessigkeit -------------------------------------------------------

/** Rohfakten eines Soll-Paars, wie reliabilityFetchPairs() sie liefert. */
function puPair(int $present, int $excusedRecord, int $absences, int $absencesInTime): array
{
    return [
        'has_present'           => $present,
        'has_excused_record'    => $excusedRecord,
        'absence_count'         => $absences,
        'absence_in_time_count' => $absencesInTime,
    ];
}

test('reliabilityOutcome: wer kam, ist erschienen -- auch trotz Abmeldung', function () {
    assertSame('appeared', reliabilityOutcome(puPair(1, 0, 0, 0)));
    assertSame('appeared', reliabilityOutcome(puPair(1, 0, 1, 1)));
});

test('reliabilityOutcome: die Abmeldung entscheidet nach ihrem Zeitpunkt', function () {
    assertSame('excused', reliabilityOutcome(puPair(0, 0, 1, 1)), 'vor Beginn');
    assertSame('missed',  reliabilityOutcome(puPair(0, 0, 1, 0)), 'nach Beginn');
});

test('reliabilityOutcome: eine nachtraeglich genehmigte Abmeldung bleibt verspaetet', function () {
    // Die Genehmigung legt einen excused-Record an. Er darf die Verspaetung
    // nicht heilen: Es zaehlt der Zeitpunkt der Meldung, nicht der Freigabe.
    assertSame('missed', reliabilityOutcome(puPair(0, 1, 1, 0)));
});

test('reliabilityOutcome: eine Entschuldigung des Verwalters ohne Abmeldung zaehlt', function () {
    // Ueber den Zeitpunkt eines Anrufs weiss das System nichts.
    assertSame('excused', reliabilityOutcome(puPair(0, 1, 0, 0)));
});

test('reliabilityOutcome: ohne alles ausgefallen', function () {
    assertSame('missed', reliabilityOutcome(puPair(0, 0, 0, 0)));
});

test('reliabilityOutcome vertraegt Zahlen als Zeichenketten', function () {
    // PDO liefert je nach Treiber '1' statt 1.
    assertSame('appeared', reliabilityOutcome([
        'has_present' => '1', 'has_excused_record' => '0',
        'absence_count' => '0', 'absence_in_time_count' => '0',
    ]));
});

// ---- Zuverlaessigkeit bei Terminarten mit Rueckmeldung ------------------------

/** Soll-Paar einer Terminart mit Rueckmeldung (Spec Terminrueckmeldung 5.5). */
function puResponsePair(int $present, int $excusedRecord, int $absences, int $absencesInDeadline,
                        int $noResponse, int $noInTime): array
{
    return [
        'has_present'               => $present,
        'has_excused_record'        => $excusedRecord,
        'absence_count'             => $absences,
        'absence_in_time_count'     => $absences,   // vor Beginn -- darf hier nicht zaehlen
        'responses_enabled'         => 1,
        'absence_in_deadline_count' => $absencesInDeadline,
        'response_no_count'         => $noResponse,
        'response_no_in_time'       => $noInTime,
    ];
}

test('reliabilityOutcome: rechtzeitige Absage ohne Antrag zaehlt als abgemeldet', function () {
    assertSame('excused', reliabilityOutcome(puResponsePair(0, 0, 0, 0, 1, 1)));
});

test('reliabilityOutcome: kurzfristige Absage ist ausgefallen', function () {
    assertSame('missed', reliabilityOutcome(puResponsePair(0, 0, 0, 0, 1, 0)));
});

test('reliabilityOutcome: bei Rueckmeldung entscheidet fuer Antraege die Frist, nicht der Beginn', function () {
    assertSame('missed',  reliabilityOutcome(puResponsePair(0, 0, 1, 0, 0, 0)), 'vor Beginn, nach der Frist');
    assertSame('excused', reliabilityOutcome(puResponsePair(0, 0, 1, 1, 0, 0)), 'vor der Frist');
});

test('reliabilityOutcome: kurzfristig abgesagt und spaeter genehmigt bleibt ausgefallen', function () {
    assertSame('missed', reliabilityOutcome(puResponsePair(0, 1, 1, 0, 1, 0)));
});

test('reliabilityOutcome: bei Rueckmeldung ohne Absage zaehlt die Entschuldigung des Verwalters', function () {
    assertSame('excused', reliabilityOutcome(puResponsePair(0, 1, 0, 0, 0, 0)));
    assertSame('missed',  reliabilityOutcome(puResponsePair(0, 0, 0, 0, 0, 0)));
});

test('reliabilityOutcome: wer trotz Absage kam, ist erschienen', function () {
    assertSame('appeared', reliabilityOutcome(puResponsePair(1, 0, 0, 0, 1, 1)));
});

test('reliabilityBuild zaehlt jeden Ausgang und die Quote', function () {
    $r = reliabilityBuild([
        puPair(1, 0, 0, 0),   // erschienen
        puPair(0, 0, 1, 1),   // rechtzeitig abgemeldet
        puPair(0, 1, 0, 0),   // vom Verwalter entschuldigt
        puPair(0, 1, 1, 0),   // spaet abgemeldet, genehmigt
        puPair(0, 0, 0, 0),   // ausgefallen
    ]);

    assertSame(true, $r['enabled']);
    assertSame(5,    $r['total']);
    assertSame(1,    $r['appeared']);
    assertSame(2,    $r['excused_in_time']);
    assertSame(2,    $r['missed']);
    assertSame(60.0, $r['rate']);
});

test('reliabilityBuild nennt ohne Termine keine Quote', function () {
    $r = reliabilityBuild([]);

    assertSame(0,    $r['total']);
    assertSame(null, $r['rate']);
});

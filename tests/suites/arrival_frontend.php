<?php
declare(strict_types=1);

/**
 * Statische Gegenproben zur fehlenden Ankunftszeit im Frontend.
 *
 * Dashboard und Check-in-PWA sind Vanilla-JS ohne eigenen Testlauf. Pruefbar
 * bleibt die Verdrahtung: dass das Feld kein Pflichtfeld mehr ist, dass die
 * Terminstartzeit nur noch auf Knopfdruck hineingeschrieben wird und dass
 * fehlende Zeiten nirgends durch new Date() laufen.
 *
 * Der Grund fuer diese Suite: Der Umbau am Backend (1.5.0) waere ohne das
 * Frontend wirkungslos geblieben. Ein Pflichtfeld im Dialog haette jede
 * fehlende Ankunft weiterhin unmoeglich gemacht, und die automatische
 * Befuellung aus dem Termin haette genau die konstruierte Zeit erzeugt, die
 * der Umbau beseitigt.
 */

$arrivalRoot = dirname(__DIR__, 2);

test('Ankunftszeit im Record-Dialog ist kein Pflichtfeld mehr', function () use ($arrivalRoot) {
    $html = (string) file_get_contents($arrivalRoot . '/public/index.html');

    $start = strpos($html, 'id="record_arrival_time"');
    assertTrue($start !== false, 'Eingabefeld nicht gefunden');

    // Das Attribut steht im selben Tag -- bis zum naechsten '>'.
    $tagEnde = strpos($html, '>', $start);
    $tag     = substr($html, $start, $tagEnde - $start);

    assertTrue(
        strpos($tag, 'required') === false,
        'Die Ankunftszeit ist noch Pflichtfeld; eine unbekannte Ankunft laesst sich nicht erfassen'
    );
});

test('Der Status steht im Dialog vor der Ankunftszeit', function () use ($arrivalRoot) {
    // Die Reihenfolge traegt Bedeutung: Der Status entscheidet, ob das
    // Ankunftsfeld ueberhaupt sichtbar ist.
    $html = (string) file_get_contents($arrivalRoot . '/public/index.html');

    $status  = strpos($html, 'id="record_status"');
    $arrival = strpos($html, 'id="recordArrivalTimeGroup"');

    assertTrue($status !== false && $arrival !== false, 'Felder nicht gefunden');
    assertTrue($status < $arrival, 'Die Ankunftszeit steht vor dem Status');
});

test('Der Terminwechsel schreibt keine Ankunftszeit mehr', function () use ($arrivalRoot) {
    // Bis 1.5.0 setzte onRecordAppointmentChange() die Startzeit des Termins
    // ins Feld. Wer danach speicherte, erzeugte einen konstruiert puenktlichen
    // Datensatz, ohne es zu merken.
    $js = (string) file_get_contents($arrivalRoot . '/public/js/modules/records.js');

    $start = strpos($js, 'function onRecordAppointmentChange(');
    assertTrue($start !== false, 'onRecordAppointmentChange() nicht gefunden');
    $ende = strpos($js, "\n}", $start);
    $body = substr($js, $start, $ende - $start);

    assertTrue(
        strpos($body, 'record_arrival_time') === false,
        'Der Terminwechsel fasst die Ankunftszeit wieder an'
    );
});

test('Die Terminstartzeit gibt es nur auf Knopfdruck', function () use ($arrivalRoot) {
    $html = (string) file_get_contents($arrivalRoot . '/public/index.html');
    $js   = (string) file_get_contents($arrivalRoot . '/public/js/modules/records.js');

    assertTrue(
        strpos($html, 'onclick="setArrivalTimeFromAppointment()"') !== false,
        'Kein Knopf fuer die Terminstartzeit im Dialog'
    );
    assertTrue(
        strpos($js, 'window.setArrivalTimeFromAppointment = setArrivalTimeFromAppointment;') !== false,
        'setArrivalTimeFromAppointment() ist nicht global verfuegbar -- der onclick liefe ins Leere'
    );
    assertTrue(
        strpos($js, 'window.toggleArrivalTimeField = toggleArrivalTimeField;') !== false,
        'toggleArrivalTimeField() ist nicht global verfuegbar -- der onchange liefe ins Leere'
    );
});

test('Bei Status entschuldigt wird die Ankunftszeit ausgeblendet und geleert', function () use ($arrivalRoot) {
    $js = (string) file_get_contents($arrivalRoot . '/public/js/modules/records.js');

    $start = strpos($js, 'function toggleArrivalTimeField(');
    assertTrue($start !== false, 'toggleArrivalTimeField() nicht gefunden');
    $ende = strpos($js, "\n}", $start);
    $body = substr($js, $start, $ende - $start);

    assertTrue(strpos($body, "'excused'") !== false, 'Der Status excused wird nicht geprueft');
    assertTrue(strpos($body, "display = 'none'") !== false, 'Das Feld wird nicht ausgeblendet');
    assertTrue(strpos($body, "input.value = ''") !== false,
        'Eine zuvor eingetippte Zeit bliebe unsichtbar stehen und wuerde mitgespeichert');
});

test('Die Zeitkonvertierung vertraegt leere und fehlende Werte', function () use ($arrivalRoot) {
    // datetimeLocalToMysql('') lieferte ':00' -- einen Wert, den niemand
    // eingegeben hat. mysqlToDatetimeLocal(null) warf, und der Dialog blieb
    // beim Bearbeiten eines Eintrags ohne Ankunftszeit unbefuellt stehen.
    $js = (string) file_get_contents($arrivalRoot . '/public/js/modules/utils.js');

    assertTrue(
        strpos($js, 'if (!datetimeLocalValue) return null;') !== false,
        'datetimeLocalToMysql() macht aus einem leeren Feld weiterhin ":00"'
    );
    assertTrue(
        strpos($js, "if (!mysqlDateTime) return '';") !== false,
        'mysqlToDatetimeLocal() wirft weiterhin bei einem fehlenden Zeitstempel'
    );
});

test('Keine ungeschuetzte Datumskonvertierung der Ankunftszeit', function () use ($arrivalRoot) {
    // new Date(null) ergibt den 01.01.1970, new Date(undefined) "Invalid Date".
    // Jede Stelle, die arrival_time in ein Datum wandelt, muss vorher pruefen.
    $dateien = [
        '/public/js/modules/records.js',
        '/public/checkin/js/app.js',
    ];

    // Abgesichert gilt eine Stelle, wenn die Pruefung im Ausdruck selbst steht
    // (??) ODER kurz davor: als if auf dieselbe Eigenschaft (die aeltere Form
    // im Bestand) oder als Ternaer davor. Beide sind in Ordnung, nur das
    // ungeschuetzte new Date(x.arrival_time) ist es nicht.
    foreach ($dateien as $datei) {
        $js = (string) file_get_contents($arrivalRoot . $datei);

        preg_match_all('/new Date\(([^)]*arrival_time[^)]*)\)/', $js, $treffer, PREG_OFFSET_CAPTURE);

        foreach ($treffer[1] as [$ausdruck, $position]) {
            if (strpos($ausdruck, '??') !== false) {
                continue;
            }

            $davor = substr($js, max(0, $position - 200), min(200, $position));

            $abgesichert = preg_match('/if\s*\([^)]*arrival_time/', $davor) === 1
                        || preg_match('/arrival_time\s*\?\s*new Date\($/', $davor) === 1;

            assertTrue(
                $abgesichert,
                "{$datei}: new Date({$ausdruck}) ohne Absicherung gegen eine fehlende Ankunftszeit"
            );
        }
    }
});

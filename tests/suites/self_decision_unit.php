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
 * FI-24: Selbstgenehmigungsregel (OI-87) als reine Funktion, ohne Datenbank.
 *
 * Die API-Suite kann den Fall "kein weiterer Verwalter" nicht herstellen —
 * im Testbestand gibt es immer einen zweiten. Deshalb hier alle Kombinationen.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/utils.php';

test('Konto ohne Mitglied: nie gesperrt', function () {
    assertTrue(selfDecisionBlocked(null, true, 7) === false, 'Konto ohne Mitglied gesperrt (mit weiterem Verwalter)');
    assertTrue(selfDecisionBlocked(null, false, 7) === false, 'Konto ohne Mitglied gesperrt (ohne weiteren Verwalter)');
});

test('Fremder Antrag: nie gesperrt', function () {
    assertTrue(selfDecisionBlocked(13, true, 2) === false, 'Fremder Antrag gesperrt (mit weiterem Verwalter)');
    assertTrue(selfDecisionBlocked(13, false, 2) === false, 'Fremder Antrag gesperrt (ohne weiteren Verwalter)');
});

test('Eigener Antrag mit weiterem Verwalter: gesperrt', function () {
    assertTrue(selfDecisionBlocked(13, true, 13) === true, 'Eigener Antrag trotz zweitem Verwalter frei');
});

test('Eigener Antrag ohne weiteren Verwalter: frei', function () {
    assertTrue(selfDecisionBlocked(13, false, 13) === false,
        'Einziger Verwalter kann seinen eigenen Antrag nicht bescheiden');
});

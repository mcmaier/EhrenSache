<?php
declare(strict_types=1);

require_once __DIR__ . '/../../private/demo/seed.php';

// ---- parseOptions ---------------------------------------------------------

test('parseOptions liefert die Vorgaben ohne weitere Argumente', function () {
    $options = parseOptions(['seed.php']);
    assertSame(false, $options['yes']);
    assertSame(20260908, $options['seed']);
    assertSame('demo2025', $options['password']);
    assertSame(false, $options['quiet']);
    assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}$/', $options['reference_date']), 'reference_date im Format YYYY-MM-DD');
});

test('parseOptions liest alle Optionen zusammen richtig', function () {
    $options = parseOptions([
        'seed.php',
        '--yes',
        '--quiet',
        '--seed=7',
        '--reference-date=2025-01-31',
        '--password=geheim',
    ]);
    assertSame(true, $options['yes']);
    assertSame(true, $options['quiet']);
    assertSame(7, $options['seed']);
    assertSame('2025-01-31', $options['reference_date']);
    assertSame('geheim', $options['password']);
});

test('parseOptions wirft bei unbekannter Option', function () {
    assertThrows(fn () => parseOptions(['seed.php', '--unsinn']));
});

test('parseOptions wirft bei ungueltigem Stichtag (falsches Format)', function () {
    assertThrows(fn () => parseOptions(['seed.php', '--reference-date=08.09.2026']));
});

test('parseOptions wirft bei ungueltigem Stichtag (unmoegliches Datum)', function () {
    assertThrows(fn () => parseOptions(['seed.php', '--reference-date=2026-13-45']));
});

// Bekanntes, nicht korrigiertes Verhalten: (int) auf einen nicht-numerischen
// String ergibt 0. --seed=abc wird also stillschweigend zu seed 0, statt
// abzulehnen. Wird hier festgehalten, nicht als Bug behandelt — sollte sich
// das als falsch herausstellen, gehört die Entscheidung nach OPEN-ITEMS.md.
test('parseOptions macht aus einem nicht-numerischen --seed= den Wert 0', function () {
    $options = parseOptions(['seed.php', '--seed=abc']);
    assertSame(0, $options['seed']);
});

// ---- assertSchema -----------------------------------------------------

function demoSeedCliMakeSchemaDb(array $versions): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE test_schema_version (version TEXT NOT NULL)');
    $stmt = $db->prepare('INSERT INTO test_schema_version (version) VALUES (:version)');
    foreach ($versions as $version) {
        $stmt->execute(['version' => $version]);
    }

    return $db;
}

test('assertSchema wirft, wenn schema_version leer ist', function () {
    $db = demoSeedCliMakeSchemaDb([]);
    assertThrows(fn () => assertSchema($db, 'test_'));
});

test('assertSchema wirft bei zu altem Schemastand', function () {
    $db = demoSeedCliMakeSchemaDb(['1.2.5']);
    assertThrows(fn () => assertSchema($db, 'test_'));
});

test('assertSchema laesst 1.3.0 durch', function () {
    $db = demoSeedCliMakeSchemaDb(['1.3.0']);
    assertSchema($db, 'test_');
});

test('assertSchema laesst 1.3.1 durch', function () {
    $db = demoSeedCliMakeSchemaDb(['1.3.1']);
    assertSchema($db, 'test_');
});

// Versionen absichtlich in falscher Reihenfolge eingetragen (1.3.0 vor
// 1.2.5): assertSchema sortiert selbst ueber version_compare und darf sich
// nicht auf die Einfuegereihenfolge oder eine string-alphabetische Sortierung
// verlassen (die wuerde bei "1.10.0" vor "1.3.0" zu falschen Ergebnissen
// fuehren).
test('assertSchema ermittelt den hoechsten Stand unabhaengig von der Reihenfolge', function () {
    $db = demoSeedCliMakeSchemaDb(['1.3.0', '1.2.5']);
    assertSchema($db, 'test_');
});

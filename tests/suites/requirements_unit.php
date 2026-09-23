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

require_once __DIR__ . '/../../private/helpers/requirements.php';

/** Verzeichnis mit version.json aus $daten; gibt den Pfad zurueck. */
function requirementsFixture(array $daten): string
{
    $dir = sys_get_temp_dir() . '/es_req_' . uniqid();
    mkdir($dir);
    file_put_contents($dir . '/version.json', json_encode($daten));
    return $dir;
}

function requirementsCleanup(string $dir): void
{
    @unlink($dir . '/version.json');
    @rmdir($dir);
}

test('requirementsRead liest requires aus version.json', function () {
    $dir = requirementsFixture(['version' => '1.6.1', 'requires' => ['php' => '8.1.0', 'extensions' => ['pdo', 'zip']]]);

    assertSame(['php' => '8.1.0', 'extensions' => ['pdo', 'zip']], requirementsRead($dir));

    requirementsCleanup($dir);
});

test('requirementsRead ohne requires stellt keine Anforderungen', function () {
    // Pakete vor 1.6.1 kennen die Angabe nicht.
    $dir = requirementsFixture(['version' => '1.5.1']);

    assertSame(['php' => '0.0.0', 'extensions' => []], requirementsRead($dir));

    requirementsCleanup($dir);
});

test('requirementsRead verwirft unbrauchbare Angaben', function () {
    $dir = requirementsFixture(['requires' => ['php' => 'acht', 'extensions' => [1, '', 'zip']]]);

    assertSame(['php' => '0.0.0', 'extensions' => ['zip']], requirementsRead($dir));

    requirementsCleanup($dir);
});

test('requirementsChecks liefert Bezeichnung und Ergebnis je Anforderung', function () {
    $checks = requirementsChecks(
        ['php' => '8.0.0', 'extensions' => ['pdo', 'zip']],
        '8.2.12',
        static fn(string $e): bool => $e === 'pdo'
    );

    assertSame([
        'PHP >= 8.0.0 (läuft: 8.2.12)' => true,
        'PHP-Erweiterung pdo'          => true,
        'PHP-Erweiterung zip'          => false,
    ], $checks);
});

test('requirementsErrors meldet zu altes PHP und fehlende Erweiterungen', function () {
    $fehler = requirementsErrors(
        ['php' => '99.0.0', 'extensions' => ['pdo', 'gibtesnicht']],
        '8.2.12',
        static fn(string $e): bool => $e === 'pdo'
    );

    assertSame([
        'Nicht erfüllt: PHP >= 99.0.0 (läuft: 8.2.12)',
        'Nicht erfüllt: PHP-Erweiterung gibtesnicht',
    ], $fehler);
});

test('requirementsErrors ohne Verstoesse ist leer, ohne PHP-Angabe keine PHP-Pruefung', function () {
    assertSame([], requirementsErrors(['php' => '8.0.0', 'extensions' => []], '8.0.0', static fn(string $e): bool => true));
    assertSame([], requirementsChecks(['php' => '0.0.0', 'extensions' => []], '5.6.0', static fn(string $e): bool => false));
});

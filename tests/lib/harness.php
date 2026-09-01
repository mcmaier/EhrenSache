<?php
/**
 * EhrenSache - Minimaler Testharness
 *
 * Bewusst ohne Abhängigkeiten: kein composer, kein vendor/, kein Build-Step.
 * Eine Suite ist eine PHP-Datei, die test(...) aufruft.
 */
declare(strict_types=1);

final class TestHarness
{
    /** @var array<int, array{name: string, ok: bool, message: string}> */
    public static array $results = [];
}

function test(string $name, callable $fn): void
{
    try {
        $fn();
        TestHarness::$results[] = ['name' => $name, 'ok' => true, 'message' => ''];
    } catch (Throwable $e) {
        TestHarness::$results[] = ['name' => $name, 'ok' => false, 'message' => $e->getMessage()];
    }
}

function assertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($msg !== '' ? $msg . ' — ' : '')
            . 'erwartet ' . var_export($expected, true)
            . ', erhalten ' . var_export($actual, true)
        );
    }
}

function assertTrue($cond, string $msg = ''): void
{
    if ($cond !== true) {
        throw new RuntimeException($msg !== '' ? $msg : 'Bedingung war nicht true');
    }
}

function assertThrows(callable $fn, string $msg = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        return;
    }
    throw new RuntimeException($msg !== '' ? $msg : 'Es wurde keine Exception geworfen');
}

function harnessSummary(): int
{
    $failed = 0;
    foreach (TestHarness::$results as $r) {
        if ($r['ok']) {
            echo "  PASS  {$r['name']}\n";
        } else {
            $failed++;
            echo "  FAIL  {$r['name']}\n";
            echo "        {$r['message']}\n";
        }
    }
    $total = count(TestHarness::$results);
    echo "\n{$total} Test(s), " . ($total - $failed) . " bestanden, {$failed} fehlgeschlagen\n";

    return $failed === 0 ? 0 : 1;
}

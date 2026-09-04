<?php
/**
 * Prüfung der Löschfristen der DSGVO-Bereinigung.
 *
 * Bewusst als Unit-Test und nicht über resource=cleanup: Um zu zeigen, dass
 * eine Frist von 0 abgewiesen wird, müsste ein API-Test sie abschicken — und
 * genau das löscht ohne die Prüfung den gesamten Bestand bis heute. Diese
 * Suite fasst die Datenbank nicht an.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/utils.php';

test('retentionYears nimmt eine ganze Zahl an', function () {
    assertSame(3, retentionYears(3));
});

test('retentionYears nimmt eine Zahl als Zeichenkette an', function () {
    assertSame(5, retentionYears('5'));
});

test('retentionYears weist 0 ab', function () {
    assertSame(null, retentionYears(0));
});

test('retentionYears weist negative Fristen ab', function () {
    assertSame(null, retentionYears(-1));
});

test('retentionYears weist Text ab', function () {
    assertSame(null, retentionYears('drei'));
});

test('retentionYears weist Bruchteile ab', function () {
    assertSame(null, retentionYears('2.5'));
});

test('retentionYears weist null ab', function () {
    assertSame(null, retentionYears(null));
});

test('retentionYears weist true ab', function () {
    assertSame(null, retentionYears(true));
});

test('retentionYears weist ein Array ab', function () {
    assertSame(null, retentionYears([3]));
});

test('retentionYears toleriert umgebende Leerzeichen', function () {
    assertSame(3, retentionYears(' 3 '));
});

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

require_once __DIR__ . '/../../private/helpers/update_package.php';

/** ZIP aus [Name => Inhalt]; ein Name mit / am Ende wird Verzeichniseintrag. */
function packageZip(array $eintraege): string
{
    $pfad = sys_get_temp_dir() . '/es_zip_' . uniqid() . '.zip';
    $zip  = new ZipArchive();
    $zip->open($pfad, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($eintraege as $name => $inhalt) {
        if (substr($name, -1) === '/') {
            $zip->addEmptyDir(rtrim($name, '/'));
        } else {
            $zip->addFromString($name, $inhalt);
        }
    }
    $zip->close();
    return $pfad;
}

function packageTmp(): string
{
    return sys_get_temp_dir() . '/es_pkg_' . uniqid();
}

function packageRemove(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $e) {
        $e->isDir() ? rmdir($e->getPathname()) : unlink($e->getPathname());
    }
    rmdir($dir);
}

/** Paketverzeichnis mit den Pflichtdateien und gegebener Version. */
function packageDir(string $version, array $ohne = []): string
{
    $dir = packageTmp();
    $dateien = [
        'version.json'                    => json_encode(['version' => $version]),
        'public/api/api.php'              => '<?php',
        'private/migrations/manifest.php' => '<?php return [];',
        'private/helpers/migrations.php'  => '<?php',
    ];
    foreach ($dateien as $rel => $inhalt) {
        if (in_array($rel, $ohne, true)) {
            continue;
        }
        @mkdir(dirname("{$dir}/{$rel}"), 0777, true);
        file_put_contents("{$dir}/{$rel}", $inhalt);
    }
    return $dir;
}

test('updateEntryNameSafe weist gefaehrliche Pfade ab', function () {
    foreach (['a/b.php', 'a/', 'version.json', 'x/y/z.js'] as $gut) {
        assertSame(true, updateEntryNameSafe($gut), $gut);
    }
    foreach (['', '../x', 'a/../../x', '/etc/passwd', 'C:/x', 'a\\b', "a\0b", 'a/./b'] as $schlecht) {
        assertSame(false, updateEntryNameSafe($schlecht), var_export($schlecht, true));
    }
});

test('updateCommonWrapper erkennt nur einen gemeinsamen obersten Ordner', function () {
    assertSame('w/', updateCommonWrapper(['w/', 'w/a', 'w/b/c']));
    assertSame('', updateCommonWrapper(['w/a', 'v/b']));
    assertSame('', updateCommonWrapper(['a.txt', 'w/b']));
    assertSame('', updateCommonWrapper([]));
});

test('updateExtractPackage streift den Wrapper-Ordner von GitHub ab', function () {
    $zip = packageZip([
        'mcmaier-EhrenSache-abc1234/'                   => '',
        'mcmaier-EhrenSache-abc1234/version.json'       => '{"version":"9.9.9"}',
        'mcmaier-EhrenSache-abc1234/public/api/api.php' => '<?php',
    ]);
    $ziel = packageTmp();

    updateExtractPackage($zip, $ziel);

    assertSame(true, is_file("{$ziel}/version.json"));
    assertSame(true, is_file("{$ziel}/public/api/api.php"));
    assertSame(false, is_dir("{$ziel}/mcmaier-EhrenSache-abc1234"));

    unlink($zip);
    packageRemove($ziel);
});

test('updateExtractPackage entpackt ein Archiv ohne Wrapper unveraendert', function () {
    $zip  = packageZip(['version.json' => '{}', 'public/x.php' => '<?php']);
    $ziel = packageTmp();

    updateExtractPackage($zip, $ziel);

    assertSame(true, is_file("{$ziel}/version.json"));
    assertSame(true, is_file("{$ziel}/public/x.php"));

    unlink($zip);
    packageRemove($ziel);
});

test('updateExtractPackage weist einen Pfad mit .. ab, bevor es schreibt', function () {
    $zip = packageZip(['w/ok.txt' => 'x', 'w/../../boese.php' => '<?php']);
    $zipPruefung = new ZipArchive();
    $zipPruefung->open($zip);
    $vorhanden = $zipPruefung->locateName('w/../../boese.php') !== false;
    $zipPruefung->close();
    assertSame(true, $vorhanden, 'Vorbedingung: ZipArchive hat den boesen Namen nicht gespeichert');

    $ziel = packageTmp();
    assertThrows(fn() => updateExtractPackage($zip, $ziel));
    assertSame(false, is_file("{$ziel}/ok.txt"), 'Vor der Ablehnung wurde schon geschrieben');

    unlink($zip);
    packageRemove($ziel);
});

test('updateValidatePackage verlangt Pflichtdateien und eine hoehere Version', function () {
    $gut = packageDir('1.7.0');
    assertSame([], updateValidatePackage($gut, '1.6.0'));

    $fehler = updateValidatePackage($gut, '1.7.0');
    assertTrue($fehler !== [] && strpos(implode(' ', $fehler), 'nicht neuer') !== false, implode(' ', $fehler));
    packageRemove($gut);

    $ohneManifest = packageDir('1.7.0', ['private/migrations/manifest.php']);
    $fehler = updateValidatePackage($ohneManifest, '1.6.0');
    assertTrue(strpos(implode(' ', $fehler), 'private/migrations/manifest.php') !== false, implode(' ', $fehler));
    packageRemove($ohneManifest);
});

test('updateCheckChain meldet eine Luecke in der Migrationskette', function () {
    $manifest = sys_get_temp_dir() . '/es_manifest_' . uniqid() . '.php';
    file_put_contents($manifest, "<?php return [['from' => '1.6.0', 'to' => '1.7.0', 'file' => 'x.php', 'function' => 'f']];");

    assertSame(null, updateCheckChain('1.6.0', '1.7.0', $manifest));

    $luecke = updateCheckChain('1.6.0', '1.8.0', $manifest);
    assertTrue(is_string($luecke) && strpos($luecke, '1.7.0') !== false, (string) $luecke);

    assertTrue(is_string(updateCheckChain('unbekannt', '1.7.0', $manifest)));

    unlink($manifest);
});

<?php
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/update_source.php';

/** Transport, der der Reihe nach die gegebenen Antworten liefert. */
function sourceFakeHttp(array $antworten): callable
{
    return static function (string $url) use (&$antworten): array {
        $antwort = array_shift($antworten);
        if ($antwort === null) {
            throw new RuntimeException("Unerwarteter Abruf: {$url}");
        }
        return $antwort;
    };
}

function sourceReleaseJson(array $ueber = []): string
{
    return (string) json_encode($ueber + [
        'tag_name'     => 'v1.7.0',
        'draft'        => false,
        'prerelease'   => false,
        'published_at' => '2026-10-01T08:00:00Z',
        'html_url'     => 'https://github.com/mcmaier/EhrenSache/releases/tag/v1.7.0',
        'zipball_url'  => 'https://api.github.com/repos/mcmaier/EhrenSache/zipball/v1.7.0',
        'body'         => 'Neu: Updater',
    ]);
}

test('updateUrlAllowed laesst nur HTTPS zu den GitHub-Hosts durch', function () {
    foreach ([
        'https://api.github.com/repos/x/y/releases/latest',
        'https://codeload.github.com/x/y/legacy.zip/refs/tags/v1.7.0',
        'https://github.com/x/y/releases/tag/v1.7.0',
    ] as $gut) {
        assertSame(true, updateUrlAllowed($gut), $gut);
    }
    foreach ([
        'http://api.github.com/repos/x/y',
        'https://evil.example/paket.zip',
        'https://github.com.evil.example/x',
        'https://user@api.github.com/x',
        'https://api.github.com:8443/x',
        '/relativer/pfad',
        'javascript:alert(1)',
    ] as $schlecht) {
        assertSame(false, updateUrlAllowed($schlecht), $schlecht);
    }
});

test('updateNextLocation folgt nur Weiterleitungen', function () {
    assertSame(null, updateNextLocation(200, "HTTP/1.1 200 OK\r\n\r\n"));
    assertSame(
        'https://codeload.github.com/x.zip',
        updateNextLocation(302, "HTTP/1.1 302 Found\r\nLocation: https://codeload.github.com/x.zip\r\n\r\n")
    );
    assertThrows(fn() => updateNextLocation(302, "HTTP/1.1 302 Found\r\n\r\n"), 'Weiterleitung ohne Ziel');
});

test('updateParseRelease liest eine gueltige Version', function () {
    $r = updateParseRelease(json_decode(sourceReleaseJson(), true));

    assertSame('1.7.0', $r['version']);
    assertSame('v1.7.0', $r['tag']);
    assertSame('https://api.github.com/repos/mcmaier/EhrenSache/zipball/v1.7.0', $r['zipball_url']);
    assertSame('https://github.com/mcmaier/EhrenSache/releases/tag/v1.7.0', $r['html_url']);
    assertSame('Neu: Updater', $r['notes']);
});

test('updateParseRelease weist Vorabversionen und Entwuerfe ab', function () {
    assertThrows(fn() => updateParseRelease(json_decode(sourceReleaseJson(['prerelease' => true]), true)));
    assertThrows(fn() => updateParseRelease(json_decode(sourceReleaseJson(['draft' => true]), true)));
});

test('updateParseRelease weist eine unbrauchbare Versionsangabe ab', function () {
    assertThrows(fn() => updateParseRelease(json_decode(sourceReleaseJson(['tag_name' => 'latest']), true)));
});

test('updateParseRelease weist eine fremde Paketadresse ab', function () {
    assertThrows(fn() => updateParseRelease(json_decode(
        sourceReleaseJson(['zipball_url' => 'https://evil.example/paket.zip']), true
    )));
});

test('updateParseRelease verwirft eine Release-Seite ausserhalb von github.com', function () {
    $r = updateParseRelease(json_decode(sourceReleaseJson(['html_url' => 'javascript:alert(1)']), true));

    assertSame('', $r['html_url']);
});

test('updateParseRelease kuerzt die Notizen, ohne UTF-8 zu zerbrechen', function () {
    $r = updateParseRelease(json_decode(sourceReleaseJson(['body' => str_repeat('ä', 3000)]), true));

    assertSame(2000, preg_match_all('/./su', $r['notes']));
    assertTrue(json_encode($r['notes']) !== false, 'Notizen sind kein gueltiges UTF-8 mehr');
});

test('updateFetchLatest wertet den Antwortstatus aus', function () {
    $ok = updateFetchLatest(sourceFakeHttp([['status' => 200, 'body' => sourceReleaseJson()]]));
    assertSame('1.7.0', $ok['version']);

    assertThrows(fn() => updateFetchLatest(sourceFakeHttp([['status' => 403, 'body' => '']])));
    assertThrows(fn() => updateFetchLatest(sourceFakeHttp([['status' => 404, 'body' => '']])));
    assertThrows(fn() => updateFetchLatest(sourceFakeHttp([['status' => 200, 'body' => 'kein json']])));
});

test('updateAvailable nennt nur eine hoehere Version', function () {
    assertSame('1.7.0', updateAvailable('1.6.0', ['version' => '1.7.0']));
    assertSame(null, updateAvailable('1.7.0', ['version' => '1.7.0']));
    assertSame(null, updateAvailable('1.8.0', ['version' => '1.7.0']));
    assertSame(null, updateAvailable('1.6.0', null));
    assertSame(null, updateAvailable('1.6.0', ['kein' => 'version']));
});

test('updateDownloadPackage schreibt nur ZIP-Archive', function () {
    $ziel = sys_get_temp_dir() . '/es_dl_' . uniqid() . '.zip';

    updateDownloadPackage('https://codeload.github.com/x.zip', $ziel,
        sourceFakeHttp([['status' => 200, 'body' => "PK\x03\x04rest"]]));
    assertSame(true, is_file($ziel));
    unlink($ziel);

    assertThrows(fn() => updateDownloadPackage('https://codeload.github.com/x.zip', $ziel,
        sourceFakeHttp([['status' => 200, 'body' => '<html>Fehlerseite</html>']])));
    assertSame(false, is_file($ziel), 'Eine Fehlerseite wurde als Paket abgelegt');
});

test('updateReadVersionFile liest version.json eines Verzeichnisses', function () {
    $dir = sys_get_temp_dir() . '/es_ver_' . uniqid();
    mkdir($dir);
    file_put_contents($dir . '/version.json', '{"version": "1.2.3"}');

    assertSame('1.2.3', updateReadVersionFile($dir));
    unlink($dir . '/version.json');
    assertSame(null, updateReadVersionFile($dir));
    rmdir($dir);
});

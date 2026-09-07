<?php
declare(strict_types=1);

/**
 * Der Versions-Query an den Asset-Links muss zu version.json passen.
 *
 * Ohne Build-Kette wird er von Hand gepflegt — und genau das wird vergessen.
 * Diese Suite lässt einen vergessenen Sprung auffliegen, statt ihn erst beim
 * Mitglied mit veralteter CSS sichtbar werden zu lassen.
 */

$repoRoot = dirname(__DIR__, 2);

test('version.json ist lesbar und enthaelt eine Version', function () use ($repoRoot) {
    $data = json_decode((string) file_get_contents($repoRoot . '/version.json'), true);
    assertTrue(is_array($data) && !empty($data['version']), 'version.json ohne Version');
});

test('Alle Asset-Links mit ?v= tragen die aktuelle Version', function () use ($repoRoot) {
    $version = json_decode((string) file_get_contents($repoRoot . '/version.json'), true)['version'];

    $files = [
        '/public/index.html',
        '/public/login.html',
        '/public/checkin/index.html',
        '/public/station/index.html',
    ];

    $found = 0;
    foreach ($files as $rel) {
        $html = (string) file_get_contents($repoRoot . $rel);

        if (!preg_match_all('/(?:href|src)="[^"]*\?v=([^"&]*)"/', $html, $m)) {
            continue;
        }

        foreach ($m[1] as $v) {
            $found++;
            assertSame($version, $v, "{$rel}: Versions-Query passt nicht zu version.json");
        }
    }

    assertTrue($found > 0, 'Kein einziger Asset-Link mit ?v= gefunden');
});

test('ES-Module tragen KEINEN Versions-Query', function () use ($repoRoot) {
    // Module importieren sich gegenseitig relativ. Ein Query nur am Script-Tag
    // laedt dieselbe Datei ein zweites Mal als eigenstaendiges Modul — mit
    // doppeltem Zustand. Deshalb ist das hier ein Fehler, kein Versaeumnis.
    foreach (['/public/index.html', '/public/login.html', '/public/checkin/index.html'] as $rel) {
        $html = (string) file_get_contents($repoRoot . $rel);

        preg_match_all('/<script[^>]*type="module"[^>]*>/', $html, $m);
        foreach ($m[0] as $tag) {
            assertTrue(
                strpos($tag, '?v=') === false,
                "{$rel}: Modul-Script mit Versions-Query — das erzeugt doppelte Module: {$tag}"
            );
        }
    }
});

test('Die .htaccess laesst CSS und JS revalidieren', function () use ($repoRoot) {
    // Faengt ab, was der Query nicht abdeckt: relativ importierte Module.
    $htaccess = (string) file_get_contents($repoRoot . '/public/.htaccess');

    assertTrue(
        strpos($htaccess, 'Cache-Control "no-cache, must-revalidate"') !== false,
        'Cache-Control-Regel fehlt in public/.htaccess'
    );
    assertTrue(
        strpos($htaccess, '(css|js|html)$') !== false,
        'Die Cache-Control-Regel greift nicht fuer css/js/html'
    );
});

test('getAuthHeaders baut keinen Authorization-Header ohne Token', function () use ($repoRoot) {
    // Wächter für OI-24. `Bearer ${sessionStorage.getItem('api_token')}` ergibt
    // im Dashboard buchstäblich "Bearer null" — dort liegt nie ein Token. Ein
    // solcher Header verdrängt in api.php die Session
    // (`if (!$apiToken) session_start()`) und lässt jeden Aufruf mit 401 enden.
    //
    // Vier Monate blieb das folgenlos, weil Apache den Header verschluckte, bis
    // 3d1a30e ihn für die Token-Auth durchreichte und die CSV-Exporte lahmlegte.
    // Ein serverseitiger Test kann das nicht fangen — er sieht nur, was der
    // Client schickt. Deshalb hier, an der Quelle.
    $js = (string) file_get_contents($repoRoot . '/public/js/modules/api.js');

    assertTrue(
        preg_match('/Bearer \$\{\s*sessionStorage\.getItem/', $js) === 0,
        'api.js baut einen Bearer-Header direkt aus sessionStorage — ohne Prüfung ergibt '
        . 'das "Bearer null" und bricht jeden Session-Aufruf (OI-24)'
    );
});

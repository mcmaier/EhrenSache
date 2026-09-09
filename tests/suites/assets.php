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

test('DEBUG wird aus der Umgebung abgeleitet, nicht hart gesetzt', function () use ($repoRoot) {
    // Waechter fuer den Debug-Schalter. Solange DEBUG eine handgesetzte
    // Konstante war, hing die Ruhe einer Produktivinstallation daran, dass beim
    // Merge dev -> main jemand daran denkt. In 1.4.0 hat das nicht geklappt:
    // die Check-in-PWA schrieb `debug.log("Login response:", result)` — und
    // damit das Bearer-Token — in die Konsole jedes Mitglieds.
    //
    // Der Wert wird deshalb nicht mehr gesetzt, sondern aus location.hostname
    // abgeleitet und faellt bei allem Unbekannten auf false. Dieser Test haelt
    // fest, dass niemand zum Literal zurueckkehrt.
    $files = [
        '/public/js/app.js',
        '/public/checkin/js/app.js',
        '/public/station/js/app.js',
    ];

    foreach ($files as $rel) {
        $js = (string) file_get_contents($repoRoot . $rel);

        assertTrue(
            preg_match('/const\s+DEBUG\s*=\s*(?:true|false)\s*;/', $js) === 0,
            "{$rel}: DEBUG ist hart gesetzt — auf einer Produktivinstallation "
            . 'entscheidet dann der Zufall des letzten Merges ueber die Konsolenausgabe'
        );

        assertTrue(
            preg_match('/const\s+DEBUG\s*=(.*?)const\s+debug\s*=/s', $js, $m) === 1
            && strpos($m[1], 'location.hostname') !== false,
            "{$rel}: die DEBUG-Definition wertet location.hostname nicht aus"
        );
    }
});

test('Kein ungeschuetztes console.log/warn/debug in Auslieferungsskripten', function () use ($repoRoot) {
    // Der debug-Wrapper nuetzt nichts, solange direkt daneben ungeschuetzt
    // geloggt wird. console.error bleibt erlaubt: eine Fehlermeldung soll auch
    // produktiv sichtbar sein, sie traegt keine Sitzungsdaten.
    $fremdcode = ['qrcode.js'];

    $dir = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($repoRoot . '/public', FilesystemIterator::SKIP_DOTS)
    );

    $verstoesse = [];
    foreach ($dir as $file) {
        if ($file->getExtension() !== 'js' || in_array($file->getFilename(), $fremdcode, true)) {
            continue;
        }

        $rel   = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($repoRoot)));
        $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);

        foreach ($lines as $i => $line) {
            if (preg_match('/console\.(log|warn|debug)\s*\(/', $line) !== 1) {
                continue;
            }
            // Die Wrapper-Definition selbst ist der erlaubte Aufrufer.
            if (strpos($line, 'DEBUG &&') !== false) {
                continue;
            }
            $verstoesse[] = $rel . ':' . ($i + 1) . ' — ' . trim($line);
        }
    }

    assertTrue(
        $verstoesse === [],
        "Ungeschuetzte Konsolenausgabe (console.error ist erlaubt):\n  " . implode("\n  ", $verstoesse)
    );
});

test('Die Station schaltet im Vereins-LAN nicht auf DEBUG', function () use ($repoRoot) {
    // Dashboard und PWA laufen bei der Entwicklung oft ueber die LAN-Adresse des
    // Rechners — dort ist 192.168.* bewusst debug-wuerdig. Die Station nicht:
    // sie haengt als Kiosk dauerhaft im Vereins-LAN und wird genau so
    // aufgerufen. Waeren die privaten Netze dort eingeschlossen, liefe jedes
    // Stationsgeraet im Publikumsbetrieb mit offener Konsole.
    $js = (string) file_get_contents($repoRoot . '/public/station/js/app.js');

    assertTrue(
        preg_match('/const\s+DEBUG\s*=(.*?)const\s+debug\s*=/s', $js, $m) === 1,
        'DEBUG-Definition der Station nicht gefunden'
    );
    assertTrue(
        strpos($m[1], 'startsWith(') === false,
        'Die Station leitet DEBUG aus einem Adresspraefix ab — im Vereins-LAN '
        . 'schaltet das jeden Kiosk auf laut'
    );
    assertTrue(
        strpos($m[1], '.local') === false,
        'Die Station erkennt .local als Entwicklungsumgebung — unter genau diesem '
        . 'Namen wird ein Kiosk im Vereinsnetz aber im Regelbetrieb aufgerufen'
    );
});
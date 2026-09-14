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
 * Bezugsquelle des Updaters: das neueste Release auf GitHub.
 *
 * Verifikation ausschließlich über TLS -- bewusst ohne Signatur oder Hash, siehe
 * docs/OPEN-ITEMS.md („Bewusst entschieden") und Abschnitt 6 der Spezifikation.
 * Zusätzlich muss jede Adresse, auch nach einer Weiterleitung, https sein und auf
 * einen der GitHub-Hosts zeigen.
 */
declare(strict_types=1);

const UPDATE_RELEASE_API     = 'https://api.github.com/repos/mcmaier/EhrenSache/releases/latest';
const UPDATE_ALLOWED_HOSTS   = ['api.github.com', 'codeload.github.com', 'github.com'];
const UPDATE_USER_AGENT      = 'EhrenSache-Updater';
const UPDATE_MAX_REDIRECTS   = 5;
const UPDATE_NOTES_MAX_CHARS = 2000;

function updateUrlAllowed(string $url): bool
{
    $teile = parse_url($url);
    if (!is_array($teile)) {
        return false;
    }

    return ($teile['scheme'] ?? '') === 'https'
        && in_array(strtolower($teile['host'] ?? ''), UPDATE_ALLOWED_HOSTS, true)
        && !isset($teile['user'])
        && (!isset($teile['port']) || (int) $teile['port'] === 443);
}

/** Ziel einer Weiterleitung, oder null wenn keine vorliegt. */
function updateNextLocation(int $status, string $headers): ?string
{
    if ($status < 300 || $status >= 400) {
        return null;
    }
    if (!preg_match('/^Location:\s*(\S+)\s*$/mi', $headers, $m)) {
        throw new RuntimeException("Weiterleitung ohne Zieladresse (Status {$status})");
    }
    return $m[1];
}

/**
 * GET über curl. Weiterleitungen folgt die Funktion selbst: Mit gesetztem
 * open_basedir schaltet PHP CURLOPT_FOLLOWLOCATION ab, und nur so lässt sich
 * jedes Ziel gegen die Liste der erlaubten Hosts prüfen.
 *
 * @return array{status:int, body:string}
 */
function updateHttpGet(string $url): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Die PHP-Erweiterung curl fehlt');
    }

    for ($i = 0; $i <= UPDATE_MAX_REDIRECTS; $i++) {
        if (!updateUrlAllowed($url)) {
            throw new RuntimeException("Adresse nicht zugelassen: {$url}");
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => UPDATE_USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github+json'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 120,
        ]);

        $roh = curl_exec($ch);
        if ($roh === false) {
            $fehler = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Verbindung zu GitHub fehlgeschlagen: {$fehler}");
        }

        $status     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $kopfLaenge = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $weiter = updateNextLocation($status, substr((string) $roh, 0, $kopfLaenge));
        if ($weiter === null) {
            return ['status' => $status, 'body' => substr((string) $roh, $kopfLaenge)];
        }
        $url = $weiter;
    }

    throw new RuntimeException('Zu viele Weiterleitungen beim Abruf von GitHub');
}

/**
 * @return array{version:string, tag:string, published_at:string, html_url:string, zipball_url:string, notes:string}
 */
function updateParseRelease(array $json): array
{
    $tag     = (string) ($json['tag_name'] ?? '');
    $version = ltrim($tag, 'vV');
    if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
        throw new RuntimeException("Unerwartete Versionsangabe im Release: {$tag}");
    }
    if (!empty($json['draft']) || !empty($json['prerelease'])) {
        throw new RuntimeException('Das neueste Release ist als Vorabversion markiert');
    }

    $zip = (string) ($json['zipball_url'] ?? '');
    if (!updateUrlAllowed($zip)) {
        throw new RuntimeException('Die Paketadresse des Releases ist nicht zugelassen');
    }

    $html = (string) ($json['html_url'] ?? '');
    // Ohne mbstring kürzen: /u zählt Zeichen; ungültiges UTF-8 ergibt leere Notizen.
    $notes = preg_match('/^.{0,' . UPDATE_NOTES_MAX_CHARS . '}/su', (string) ($json['body'] ?? ''), $m) ? $m[0] : '';

    return [
        'version'      => $version,
        'tag'          => $tag,
        'published_at' => (string) ($json['published_at'] ?? ''),
        'html_url'     => strpos($html, 'https://github.com/') === 0 ? $html : '',
        'zipball_url'  => $zip,
        'notes'        => $notes,
    ];
}

/** Liest das neueste Release. $http ist austauschbar, damit sich das ohne Netz prüfen lässt. */
function updateFetchLatest(?callable $http = null): array
{
    $http ??= 'updateHttpGet';
    $antwort = $http(UPDATE_RELEASE_API);

    switch ($antwort['status']) {
        case 200:
            break;
        case 404:
            throw new RuntimeException('Auf GitHub ist keine veröffentlichte Version zu finden');
        case 403:
        case 429:
            throw new RuntimeException('GitHub begrenzt gerade die Anfragen dieses Servers – bitte später erneut versuchen');
        default:
            throw new RuntimeException("GitHub antwortete mit Status {$antwort['status']}");
    }

    $json = json_decode($antwort['body'], true);
    if (!is_array($json)) {
        throw new RuntimeException('Die Antwort von GitHub ist kein gültiges JSON');
    }

    return updateParseRelease($json);
}

/** Die höhere Version aus dem Release, oder null. */
function updateAvailable(string $installed, ?array $release): ?string
{
    if ($release === null || !is_string($release['version'] ?? null)) {
        return null;
    }
    return version_compare($release['version'], $installed, '>') ? $release['version'] : null;
}

function updateDownloadPackage(string $zipballUrl, string $target, ?callable $http = null): void
{
    $http ??= 'updateHttpGet';
    $antwort = $http($zipballUrl);

    if ($antwort['status'] !== 200) {
        throw new RuntimeException("Paket konnte nicht geladen werden (Status {$antwort['status']})");
    }
    if (strncmp($antwort['body'], "PK\x03\x04", 4) !== 0) {
        throw new RuntimeException('Die heruntergeladene Datei ist kein ZIP-Archiv');
    }
    if (@file_put_contents($target, $antwort['body']) === false) {
        throw new RuntimeException('Paket konnte nicht gespeichert werden: ' . $target);
    }
}

/** Version aus version.json unterhalb von $root, oder null. */
function updateReadVersionFile(string $root): ?string
{
    $daten = json_decode((string) @file_get_contents($root . '/version.json'), true);
    return is_array($daten) && is_string($daten['version'] ?? null) ? $daten['version'] : null;
}

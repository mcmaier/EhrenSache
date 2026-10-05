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
 * Adresse des Besuchers, auch hinter einem Reverse-Proxy oder CDN.
 *
 * Steht ein Proxy vor der Installation (z. B. Cloudflare), ist REMOTE_ADDR die
 * Adresse des Proxys, nicht die des Besuchers; der Rate Limiter würde dann alle
 * Besucher hinter demselben Proxy gemeinsam zählen. Der Proxy reicht die echte
 * Adresse in einem Header weiter (CF-Connecting-IP, X-Forwarded-For).
 *
 * Einem solchen Header wird nur geglaubt, wenn die Verbindung von einer Adresse
 * kommt, die in config.php unter 'trusted_proxies' steht. Ohne diese Prüfung
 * könnte jeder Besucher den Header selbst setzen und sich je Anfrage eine neue
 * Adresse geben. Leere Liste = Verhalten wie bisher, nur REMOTE_ADDR.
 */
declare(strict_types=1);

/**
 * @param array<string,mixed> $server  in der Regel $_SERVER
 * @param string[]            $trusted Adressen oder CIDR-Bereiche (bereits geprüft,
 *                                     siehe configTrustedProxies())
 */
function clientIp(array $server, array $trusted): string
{
    $remote = isset($server['REMOTE_ADDR']) && is_string($server['REMOTE_ADDR'])
        ? $server['REMOTE_ADDR']
        : 'unknown';

    if ($trusted === [] || !ipInRanges($remote, $trusted)) {
        return $remote;
    }

    // Cloudflare setzt genau eine Adresse.
    $cf = trim((string) ($server['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) !== false) {
        return $cf;
    }

    // X-Forwarded-For: Jeder Proxy hängt rechts die Adresse an, von der er die
    // Anfrage bekam. Links steht, was der Besucher selbst mitschicken kann. Also
    // von rechts lesen und den ersten Eintrag nehmen, der kein eigener Proxy ist.
    $xff = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($xff !== '') {
        $eintraege = array_reverse(array_map('trim', explode(',', $xff)));
        foreach ($eintraege as $eintrag) {
            if (filter_var($eintrag, FILTER_VALIDATE_IP) === false) {
                break;
            }
            if (!ipInRanges($eintrag, $trusted)) {
                return $eintrag;
            }
        }
    }

    return $remote;
}

/** Liegt $ip in einer der Adressen oder CIDR-Bereiche? IPv4 und IPv6 mischen sich nicht. */
function ipInRanges(string $ip, array $ranges): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }

    foreach ($ranges as $range) {
        $teile = explode('/', (string) $range, 2);
        $netz  = @inet_pton($teile[0]);
        if ($netz === false || strlen($netz) !== strlen($bin)) {
            continue;
        }

        $bits = isset($teile[1]) ? (int) $teile[1] : strlen($bin) * 8;
        $volleBytes = intdiv($bits, 8);
        if (substr($bin, 0, $volleBytes) !== substr($netz, 0, $volleBytes)) {
            continue;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $maske = chr((0xFF << (8 - $rest)) & 0xFF);
        if ((($bin[$volleBytes] ^ $netz[$volleBytes]) & $maske) === "\x00") {
            return true;
        }
    }

    return false;
}

/** Besucheradresse der laufenden Anfrage nach der Konfiguration der Installation. */
function currentClientIp(): string
{
    $trusted = function_exists('appConfig') ? (appConfig()['trusted_proxies'] ?? []) : [];

    return clientIp($_SERVER, is_array($trusted) ? $trusted : []);
}

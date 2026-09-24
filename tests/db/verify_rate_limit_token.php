<?php
/**
 * EhrenSache - Verifikation der Rate-Grenze bei untauglichen Token
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 *
 * Nicht Teil von tests/run.php: Das Skript schöpft absichtlich das Kontingent
 * unangemeldeter Aufrufe aus (150 je Minute und Adresse). Liefe es im normalen
 * Lauf mit, blieben die wenigen unangemeldeten Aufrufe der übrigen Suiten für
 * bis zu einer Minute hängen.
 *
 * Geprüft wird die Umgehung, die beim Review am 2026-09-24 aufgefallen ist:
 * `$istAngemeldet` war schon dann wahr, wenn zum Token IRGENDEINE Zeile
 * existierte. Ein deaktiviertes Konto und ein abgelaufener Token galten damit
 * als angemeldet, übersprangen die Grenze und liefen erst danach in den 401 —
 * beliebig oft. Gegen den Stand davor endet dieses Skript mit "durchgefallen".
 *
 * Aufruf (Proxy-Umgehung nicht vergessen):
 *   http_proxy= https_proxy= NO_PROXY='*' php tests/db/verify_rate_limit_token.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api.php';

const RL_GRENZE = 150;

/** Legt ein Geraet an, liefert [user_id, token]. */
function rlDevice(): array
{
    $res = apiRequest('POST', 'users', ['token' => apiToken('admin'), 'body' => [
        'action' => 'create_device', 'device_name' => 'RL-Pruefung ' . uniqid(),
        'device_type' => 'auth_device', 'totp_enabled' => false,
    ]]);
    if ($res['status'] !== 200) {
        fwrite(STDERR, "Testgeraet konnte nicht angelegt werden: HTTP {$res['status']}\n");
        exit(1);
    }

    return [(int) $res['body']['device']['user_id'], (string) $res['body']['device']['api_token']];
}

function rlFlut(string $token, int $anzahl): array
{
    $codes = [];
    for ($i = 0; $i < $anzahl; $i++) {
        $codes[] = apiRequest('GET', 'me', ['token' => $token])['status'];
    }

    return array_count_values($codes);
}

echo "Pruefe die Rate-Grenze bei untauglichen Token\n";
echo str_repeat('-', 60), "\n";

[$deviceId, $token] = rlDevice();
$fehler = 0;

try {
    // 1. Gueltiger Token: zaehlt NICHT mit, auch weit jenseits der Grenze.
    $gueltig = rlFlut($token, RL_GRENZE + 5);
    $ok = !isset($gueltig[429]);
    echo ($ok ? '  ok   ' : '  FEHL '), "gueltiger Token bleibt ungebremst: ", json_encode($gueltig), "\n";
    $fehler += $ok ? 0 : 1;

    // 2. Konto deaktivieren -- derselbe Token traegt jetzt nicht mehr.
    apiRequest('PUT', 'users', ['token' => apiToken('admin'),
        'query' => ['id' => $deviceId], 'body' => ['is_active' => 0]]);

    $tot = rlFlut($token, RL_GRENZE + 5);
    $ok  = isset($tot[429]) && isset($tot[401]);
    echo ($ok ? '  ok   ' : '  FEHL '), "Token eines deaktivierten Kontos wird gezaehlt und gebremst: ",
         json_encode($tot), "\n";
    $fehler += $ok ? 0 : 1;
} finally {
    apiRequest('DELETE', 'users', ['token' => apiToken('admin'), 'query' => ['id' => $deviceId]]);
}

echo str_repeat('-', 60), "\n";
echo $fehler === 0 ? "bestanden\n" : "durchgefallen ({$fehler})\n";
echo "Hinweis: Das Kontingent unangemeldeter Aufrufe dieser Adresse ist jetzt fuer bis zu\n"
   . "eine Minute ausgeschoepft. Vor einem Testlauf kurz warten.\n";
exit($fehler === 0 ? 0 : 1);

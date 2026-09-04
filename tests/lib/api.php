<?php
/**
 * EhrenSache - HTTP-Schicht für den Testharness
 *
 * Authentifiziert per Bearer-Token (resource=auth). Token-Auth ist in api.php
 * von der CSRF-Prüfung ausgenommen, deshalb entfällt hier jede Session-Verwaltung.
 */
declare(strict_types=1);

function testConfig(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/../config.php';
        if (!file_exists($path)) {
            throw new RuntimeException(
                'tests/config.php fehlt — tests/config.example.php kopieren und anpassen'
            );
        }
        $cfg = require $path;
    }

    return $cfg;
}

/**
 * `cookie` schickt einen Cookie-Header mit ("PHPSESSID=..."), `set_cookie`
 * liefert den ersten Set-Cookie-Header der Antwort roh zurueck — beides nur
 * fuer Tests, die eine Session ohne Token pruefen.
 *
 * @param array{query?: array<string, scalar>, body?: mixed, token?: string, cookie?: string} $opts
 * @return array{status: int, body: mixed, raw: string, set_cookie: ?string}
 */
function apiRequest(string $method, string $resource, array $opts = []): array
{
    $cfg = testConfig();
    $url = rtrim($cfg['base_url'], '/') . '/api/api.php?resource=' . urlencode($resource);

    foreach (($opts['query'] ?? []) as $k => $v) {
        $url .= '&' . urlencode((string) $k) . '=' . urlencode((string) $v);
    }

    $headers = ['Accept: application/json'];
    if (!empty($opts['token'])) {
        $headers[] = 'Authorization: Bearer ' . $opts['token'];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    if (array_key_exists('body', $opts)) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['body']));
    }

    if (!empty($opts['cookie'])) {
        curl_setopt($ch, CURLOPT_COOKIE, $opts['cookie']);
    }

    $setCookie = null;
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $header) use (&$setCookie): int {
        if ($setCookie === null && stripos($header, 'Set-Cookie:') === 0) {
            $setCookie = trim(substr($header, strlen('Set-Cookie:')));
        }

        return strlen($header);
    });

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("HTTP-Anfrage fehlgeschlagen: {$err}");
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status'     => $status,
        'body'       => json_decode((string) $raw, true),
        'raw'        => (string) $raw,
        'set_cookie' => $setCookie,
    ];
}

/** Holt (und merkt sich) einen API-Token für eine Testrolle. */
function apiToken(string $role): string
{
    static $tokens = [];
    if (isset($tokens[$role])) {
        return $tokens[$role];
    }

    $cfg = testConfig();
    if (!isset($cfg[$role])) {
        throw new RuntimeException("Kein Testaccount für Rolle '{$role}' in tests/config.php");
    }

    $res = apiRequest('POST', 'auth', ['body' => [
        'email'    => $cfg[$role]['email'],
        'password' => $cfg[$role]['password'],
    ]]);

    if ($res['status'] !== 200 || empty($res['body']['token'])) {
        throw new RuntimeException(
            "Login als '{$role}' fehlgeschlagen: HTTP {$res['status']} " . substr($res['raw'], 0, 300)
        );
    }

    return $tokens[$role] = $res['body']['token'];
}

/** Liefert die member_id einer Testrolle, oder null wenn keine verknüpft ist. */
function apiMemberId(string $role): ?int
{
    static $ids = [];
    if (array_key_exists($role, $ids)) {
        return $ids[$role];
    }

    $cfg = testConfig();
    $res = apiRequest('POST', 'auth', ['body' => [
        'email'    => $cfg[$role]['email'],
        'password' => $cfg[$role]['password'],
    ]]);

    $memberId = $res['body']['user']['member_id'] ?? null;

    return $ids[$role] = ($memberId === null ? null : (int) $memberId);
}

/** @param array{status: int, body: mixed, raw: string} $res */
function assertStatus(int $expected, array $res, string $msg = ''): void
{
    if ($res['status'] !== $expected) {
        throw new RuntimeException(
            ($msg !== '' ? $msg . ' — ' : '')
            . "HTTP {$expected} erwartet, {$res['status']} erhalten: " . substr($res['raw'], 0, 300)
        );
    }
}

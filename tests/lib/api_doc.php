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

/**
 * Gemeinsame Werkzeuge des API.md-Abgleichs (OI-66, OI-100): Beispiel-JSON
 * unter einer Ueberschrift finden, parsen und gegen eine echte Antwort
 * vergleichen. Genutzt von tests/suites/api_doc_keys.php (Lesepfade) und
 * tests/suites/api_doc_keys_write.php (Schreibpfade und Check-in).
 * Beschreibung der Regeln im Kopf von api_doc_keys.php.
 */

require_once __DIR__ . '/api.php';

function adApiMdPath(): string
{
    return dirname(__DIR__, 2) . '/API.md';
}

/**
 * Liefert den Abschnitt unter der Überschrift $heading (## bis ####, Text
 * ohne Raute, exakte Zeile). Der Abschnitt endet vor der nächsten
 * Überschrift gleicher Ebenen (## bis ####).
 */
function adSection(string $markdown, string $heading): string
{
    $lines = explode("\n", str_replace("\r\n", "\n", $markdown));
    $start = null;
    foreach ($lines as $i => $line) {
        if (preg_match('/^#{2,4}\s+' . preg_quote($heading, '/') . '\s*$/', rtrim($line))) {
            $start = $i + 1;
            break;
        }
    }
    assertTrue($start !== null,
        "Ueberschrift '{$heading}' nicht in API.md gefunden -- Abschnitt umbenannt oder entfernt?");

    $end = count($lines);
    for ($i = $start; $i < count($lines); $i++) {
        if (preg_match('/^#{2,4}\s+\S/', $lines[$i])) {
            $end = $i;
            break;
        }
    }

    return implode("\n", array_slice($lines, $start, $end - $start));
}

/** Liefert den $blockIndex-ten (1-basiert) ```json-Block aus $section als Array. */
function adJsonBlock(string $section, int $blockIndex, string $heading): array
{
    preg_match_all('/```json\s*\n(.*?)```/s', $section, $matches);
    $blocks = $matches[1];
    assertTrue(count($blocks) >= $blockIndex,
        "Ueberschrift '{$heading}': nur " . count($blocks) . " json-Block(e) gefunden, Block {$blockIndex} erwartet");

    $raw = trim($blocks[$blockIndex - 1]);
    // Gekuerzte Beispiele ("members": [ … ], "settings": {…}) sind in API.md
    // lesbarer als volle, aber kein JSON. Sie gelten als leerer Container:
    // Geprueft wird dann nur, dass der Schluessel existiert (OI-100).
    $raw = (string) preg_replace(['/\{\s*…\s*\}/u', '/\[\s*…\s*\]/u'], ['{}', '[]'], $raw);
    // "…": "…" steht fuer "weitere Felder" -- kein Schluessel, den die
    // Antwort tragen muss.
    $raw = (string) preg_replace(['/,\s*"…"\s*:\s*"…"/u', '/"…"\s*:\s*"…"\s*,?/u'], '', $raw);
    $decoded = json_decode($raw, true);
    if ($decoded === null && $raw !== 'null') {
        // Zeilenkommentare sind kein gueltiges JSON -- ein Versuch, bevor
        // endgueltig aufgegeben wird.
        $stripped = (string) preg_replace('#^\s*//.*$#m', '', $raw);
        $decoded  = json_decode($stripped, true);
    }
    assertTrue($decoded !== null || $raw === 'null',
        "Ueberschrift '{$heading}', Block {$blockIndex}: kein gueltiges JSON in API.md -- " . substr($raw, 0, 200));

    return (array) $decoded;
}

/** Holt Block $blockIndex unter der Ueberschrift $heading aus API.md (gecacht je Prozess). */
function adDoc(string $heading, int $blockIndex): array
{
    static $markdown = null;
    if ($markdown === null) {
        $markdown = (string) sourceCode(adApiMdPath());
        assertTrue($markdown !== '', 'API.md konnte nicht gelesen werden: ' . adApiMdPath());
    }

    return adJsonBlock(adSection($markdown, $heading), $blockIndex, $heading);
}

/**
 * Vergleicht rekursiv die Schlüssel des dokumentierten Beispiels ($doc)
 * gegen die echte Antwort ($live). Jeder Schlüssel aus $doc muss in $live
 * vorkommen; fehlende Felder lassen den Test scheitern. Zusätzliche Felder
 * in $live sind erlaubt und werden nur als INFO-Zeile ausgegeben.
 *
 * Arrays: nur das erste Element wird verglichen (Objekte: alle Schlüssel).
 * $skipDive listet Pfade (Punktnotation, wie sie beim Abstieg entstehen,
 * z. B. "my_open_items.items" oder "holidays.holidays"), an denen
 * absichtlich NICHT weiter hinabgestiegen wird -- entweder weil die Elemente
 * je nach `kind` unterschiedliche Felder tragen (my_open_items.items), oder
 * weil die Schlüssel selbst Daten sind statt eines Feldschemas
 * (holidays.holidays, nach Kalenderdatum indiziert). Nur die Existenz des
 * Schlüssels wird dann geprüft, nicht sein Inhalt. Ist ein Array in der
 * echten Antwort leer (keine passenden Testdaten in dieser Instanz), wird
 * die Tiefenprüfung ebenfalls übersprungen und das sichtbar gemeldet -- kein
 * stiller Erfolg.
 *
 * @param string[] $skipDive
 */
function adCheckKeys($doc, $live, string $path, array $skipDive): void
{
    if (in_array($path, $skipDive, true)) {
        echo "  INFO  {$path}: Tiefenpruefung bewusst uebersprungen (siehe skipDive-Kommentar am Eintrag)\n";

        return;
    }

    if (is_array($doc) && array_is_list($doc)) {
        if ($doc === []) {
            return;
        }
        assertTrue(is_array($live) && array_is_list($live),
            "{$path}: API.md zeigt ein Array, die echte Antwort liefert keins");

        if (!is_array($doc[0])) {
            return; // Liste von Skalaren (z. B. available_years) -- keine Schluessel zu pruefen
        }
        if ($live === []) {
            echo "  INFO  {$path}: in dieser Instanz leer -- Tiefenpruefung uebersprungen\n";

            return;
        }
        adCheckKeys($doc[0], $live[0], "{$path}[0]", $skipDive);

        return;
    }

    // Ein Feld, das laut Doku ein Objekt traegt, darf im Bestand null sein --
    // etwa `responses` an einem Termin, dessen Terminart keine Rueckmeldung
    // vorsieht. Das ist ein zulaessiger Wert, keine Falschaussage; geprueft
    // wurde schon, dass der Schluessel ueberhaupt existiert.
    if ($live === null) {
        echo "  INFO  {$path}: in dieser Instanz null -- Tiefenpruefung uebersprungen\n";

        return;
    }

    assertTrue(is_array($live), "{$path}: API.md zeigt ein Objekt, die echte Antwort liefert keins");

    $missing = [];
    foreach ($doc as $key => $docValue) {
        if (!array_key_exists($key, $live)) {
            $missing[] = (string) $key;
            continue;
        }
        if (is_array($docValue)) {
            adCheckKeys($docValue, $live[$key], "{$path}.{$key}", $skipDive);
        }
    }
    assertTrue($missing === [],
        "{$path}: in API.md dokumentiert, in der echten Antwort nicht vorhanden: " . implode(', ', $missing));

    $extra = array_diff(array_keys($live), array_keys($doc));
    if ($extra !== []) {
        echo "  INFO  {$path}: " . count($extra) . ' zusaetzliche(s) Feld(er) in der echten Antwort ('
            . implode(', ', $extra) . ")\n";
    }
}

/** Sitzungs-Cookie eines Web-Logins (session_info kennt keine Tokens). */
function adSessionCookie(string $role): string
{
    static $cookies = [];
    if (isset($cookies[$role])) {
        return $cookies[$role];
    }
    $cfg = testConfig();
    $res = apiRequest('POST', 'login', ['body' => [
        'email' => $cfg[$role]['email'], 'password' => $cfg[$role]['password']]]);
    assertStatus(200, $res, "Web-Login als {$role}");
    assertTrue(!empty($res['set_cookie']), "Web-Login als {$role} setzt kein Cookie");

    return $cookies[$role] = explode(';', (string) $res['set_cookie'])[0];
}

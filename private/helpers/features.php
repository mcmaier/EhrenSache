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
 * Abschaltbare Funktionen (OI-62).
 *
 * Eine Liste, eine Prüfstelle, eine Antwort „abgeschaltet“ (403 mit Kennung
 * FEATURE_DISABLED). 'resources' nennt nur Ressourcen, die VOLLSTÄNDIG an der
 * Funktion hängen — api.php sperrt sie vor dem Routing. Teilpfade (einzelne
 * Exporte, Aktionen der Station, das Feld pin bei members) rufen
 * requireFeature() an ihrer Stelle auf.
 *
 * 'requires' (Etappe 2): Eine Funktion gilt nur als eingeschaltet, wenn ihre
 * eigene Einstellung '1' ist UND alle Voraussetzungen (rekursiv) an sind.
 * Die Einstellungen selbst bleiben dabei unverändert — wer die Terminplanung
 * wieder einschaltet, hat den vorigen Stand der abhängigen Schalter zurück.
 * Anwesenheit ohne Terminplanung gibt es nicht: records.appointment_id und
 * exceptions.appointment_id sind Pflicht.
 *
 * Nicht hier: Rückmeldungen je Terminart (Eigenschaft der Terminart, 409) und
 * Mail (Infrastruktur, 503 über Mailer::checkMailStatus()).
 *
 * Neue Funktion: Eintrag hier, Zeile im Schema, Wächter in
 * tests/suites/features_unit.php nachziehen.
 */
declare(strict_types=1);

require_once __DIR__ . '/utils.php';

const FEATURES = [
    'appointments' => ['setting' => 'appointments_enabled', 'default' => '1', 'requires' => [],
                       'resources' => ['appointments', 'appointment_series', 'appointment_types', 'appointment_responses', 'holidays']],
    'attendance'   => ['setting' => 'attendance_enabled',   'default' => '1', 'requires' => ['appointments'],
                       'resources' => ['records', 'exceptions', 'attendance_list', 'auto_checkin', 'totp_checkin']],
    'worktime'     => ['setting' => 'worktime_enabled',     'default' => '0', 'requires' => [], 'resources' => ['activity_types', 'work_sessions']],
    'station_pin'  => ['setting' => 'station_pin_enabled',  'default' => '0', 'requires' => [], 'resources' => ['change_pin']],
    'punctuality'  => ['setting' => 'punctuality_enabled',  'default' => '0', 'requires' => ['attendance'], 'resources' => []],
    'reliability'  => ['setting' => 'reliability_enabled',  'default' => '0', 'requires' => ['attendance'], 'resources' => []],
];

/** Der Schlüssel und alle seine Voraussetzungen, rekursiv, ohne Doppelte. */
function featureChain(string $key): array
{
    $chain = [];
    $todo  = [$key];
    while ($todo !== []) {
        $k = array_shift($todo);
        if (in_array($k, $chain, true)) {
            continue;
        }
        $chain[] = $k;
        foreach (FEATURES[$k]['requires'] as $r) {
            $todo[] = $r;
        }
    }

    return $chain;
}

/**
 * Wirksamer Stand aller Funktionen aus der eigenen Einstellung je Schlüssel.
 * Rein, ohne Datenbank: $raw bildet Schlüssel → eigene Einstellung als bool
 * ab, ein fehlender Schlüssel gilt als aus. Ergebnis in der Reihenfolge von
 * FEATURES.
 *
 * @param array<string, bool> $raw
 * @return array<string, bool>
 */
function resolveFeatures(array $raw): array
{
    $out = [];
    $resolve = function (string $key, array $pfad) use (&$resolve, &$out, $raw): bool {
        if (isset($out[$key])) {
            return $out[$key];
        }
        if (in_array($key, $pfad, true)) {
            throw new LogicException('Ring in requires: ' . implode(' -> ', [...$pfad, $key]));
        }
        $on = ($raw[$key] ?? false) === true;
        foreach (FEATURES[$key]['requires'] as $r) {
            // Erst auflösen, dann verknüpfen: sonst übersähe ein Kurzschluss den Ring.
            $on = $resolve($r, [...$pfad, $key]) && $on;
        }

        return $out[$key] = $on;
    };

    $ergebnis = [];
    foreach (array_keys(FEATURES) as $key) {
        $ergebnis[$key] = $resolve($key, []);
    }

    return $ergebnis;
}

/** Eigene Einstellungen der genannten Schlüssel aus system_settings, als bool. */
function featureRawSettings($db, $database, array $keys): array
{
    $raw = [];
    foreach ($keys as $k) {
        $f = FEATURES[$k];
        $raw[$k] = systemSetting($db, $database, $f['setting'], $f['default']) === '1';
    }

    return $raw;
}

/** Ist die Funktion eingeschaltet (samt Voraussetzungen)? Ein unbekannter Schlüssel ist ein Programmierfehler. */
function isFeatureEnabled($db, $database, string $key): bool
{
    if (!isset(FEATURES[$key])) {
        throw new InvalidArgumentException("Unbekannte Funktion: {$key}");
    }

    return resolveFeatures(featureRawSettings($db, $database, featureChain($key)))[$key];
}

/**
 * Antwortet mit 403 FEATURE_DISABLED und beendet, wenn die Funktion aus ist.
 * 'feature' nennt den gefragten Schlüssel, nicht die verursachende
 * Voraussetzung. $extra ergänzt die Antwort, etwa ['field' => 'pin'].
 */
function requireFeature($db, $database, string $key, array $extra = []): void
{
    if (isFeatureEnabled($db, $database, $key)) {
        return;
    }

    http_response_code(403);
    echo json_encode([
        'message' => 'Diese Funktion ist abgeschaltet',
        'code'    => 'FEATURE_DISABLED',
        'feature' => $key,
    ] + $extra);
    exit();
}

/** Die Funktion, an der eine Ressource vollständig hängt, sonst null. */
function featureForResource(string $resource): ?string
{
    foreach (FEATURES as $key => $f) {
        if (in_array($resource, $f['resources'], true)) {
            return $key;
        }
    }

    return null;
}

/** Alle Funktionen mit ihrem wirksamen Stand, für die Antwort von me. */
function enabledFeatures($db, $database): array
{
    return resolveFeatures(featureRawSettings($db, $database, array_keys(FEATURES)));
}

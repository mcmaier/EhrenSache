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
 * Nicht hier: Rückmeldungen (Eigenschaft der Terminart, 409) und Mail
 * (Infrastruktur, 503 über Mailer::checkMailStatus()).
 *
 * Neue Funktion: Eintrag hier, Zeile im Schema, Wächter in
 * tests/suites/features_unit.php nachziehen.
 */
declare(strict_types=1);

require_once __DIR__ . '/utils.php';

const FEATURES = [
    'worktime'    => ['setting' => 'worktime_enabled',    'default' => '0', 'resources' => ['activity_types', 'work_sessions']],
    'station_pin' => ['setting' => 'station_pin_enabled', 'default' => '0', 'resources' => ['change_pin']],
    'punctuality' => ['setting' => 'punctuality_enabled', 'default' => '0', 'resources' => []],
    'reliability' => ['setting' => 'reliability_enabled', 'default' => '0', 'resources' => []],
];

/** Ist die Funktion eingeschaltet? Ein unbekannter Schlüssel ist ein Programmierfehler. */
function isFeatureEnabled($db, $database, string $key): bool
{
    if (!isset(FEATURES[$key])) {
        throw new InvalidArgumentException("Unbekannte Funktion: {$key}");
    }
    $f = FEATURES[$key];

    return systemSetting($db, $database, $f['setting'], $f['default']) === '1';
}

/**
 * Antwortet mit 403 FEATURE_DISABLED und beendet, wenn die Funktion aus ist.
 * $extra ergänzt die Antwort, etwa ['field' => 'pin'] für das Formular.
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

/** Alle Funktionen mit ihrem Stand, für die Antwort von me. */
function enabledFeatures($db, $database): array
{
    $out = [];
    foreach (array_keys(FEATURES) as $key) {
        $out[$key] = isFeatureEnabled($db, $database, $key);
    }

    return $out;
}

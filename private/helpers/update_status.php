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
 * Der zuletzt gespeicherte Stand der Update-Prüfung in system_settings.
 *
 * Geprüft wird nur auf Knopfdruck. Das Ergebnis bleibt stehen, damit der
 * Hinweis im Dashboard nicht verloren geht; er verschwindet von selbst, sobald
 * die installierte Version die gespeicherte erreicht.
 */
declare(strict_types=1);

require_once __DIR__ . '/update_source.php';

function updateStoreCheck($db, $database, array $release, string $when): void
{
    $prefix = $database->table('');
    $stmt   = $db->prepare("
        INSERT INTO {$prefix}system_settings (setting_key, setting_value, setting_type, category)
        VALUES (?, ?, 'text', 'general')
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute(['update_check_last', $when]);
    $stmt->execute(['update_check_result', json_encode($release, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
}

/** @return array{installed:string, last_checked:?string, latest:?array, update_available:?string} */
function updateCheckStatus($db, $database, string $installed): array
{
    $prefix = $database->table('');
    $stmt   = $db->prepare("
        SELECT setting_key, setting_value FROM {$prefix}system_settings
        WHERE setting_key IN ('update_check_last', 'update_check_result')
    ");
    $stmt->execute();
    $werte = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    $latest = json_decode((string) ($werte['update_check_result'] ?? ''), true);
    $latest = is_array($latest) ? $latest : null;
    $last   = (string) ($werte['update_check_last'] ?? '');

    return [
        'installed'        => $installed,
        'last_checked'     => $last === '' ? null : $last,
        'latest'           => $latest,
        'update_available' => updateAvailable($installed, $latest),
    ];
}

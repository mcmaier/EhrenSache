<?php

/**
 * EhrenSache - Migration v1.2.4 → v1.2.5
 *
 * Änderungen:
 * - Einstellung cleanup_years_records (löst dsgvo-cleanup-years ab)
 * - Einstellung cleanup_years_worktime
 * - Einstellung cleanup_years_audit
 *
 * Hintergrund: Die DSGVO-Bereinigung kannte bis hierher genau eine Frist für
 * alles. `DATENSCHUTZ.md` 10.4 verlangt aber eine eigene Frist für die
 * Änderungshistorie, weil sie das Löschen ihrer Sitzung absichtlich überlebt.
 *
 * Der alte Schlüssel `dsgvo-cleanup-years` wandert samt Wert nach
 * `cleanup_years_records`: Ein Verein, der dort 5 Jahre eingetragen hat, soll
 * nach dem Update nicht stillschweigend wieder bei 3 stehen. Der Bindestrich
 * fiel dabei weg — alle anderen Schlüssel verwenden Unterstriche.
 *
 * Die Arbeitszeitfrist startet auf 3 Jahren wie die Anwesenheiten, die
 * Auditfrist auf 1 Jahr: Sie greift nur für verwaiste Einträge, deren Sitzung
 * bereits gelöscht ist, und diese werden anonymisiert statt gelöscht.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */

function migrate_1_2_4(PDO $pdo, string $prefix, string $configPath): array
{
    $log  = [];
    $warn = [];

    $insert = $pdo->prepare("
        INSERT IGNORE INTO `{$prefix}system_settings`
            (setting_key, setting_value, setting_type, category, description)
        VALUES (?, ?, 'number', 'general', ?)
    ");

    // ---- Anwesenheiten: bestehenden Wert übernehmen ----
    $read = $pdo->prepare("SELECT setting_value FROM `{$prefix}system_settings` WHERE setting_key = ?");

    $read->execute(['dsgvo-cleanup-years']);
    $previous = $read->fetchColumn();

    $read->execute(['cleanup_years_records']);
    $existing = $read->fetchColumn();

    if ($existing !== false) {
        // INSERT IGNORE ließe den vorhandenen Wert ohnehin stehen. Das
        // Protokoll darf dann nicht behaupten, es hätte den alten übernommen.
        $log[] = "Einstellung <code>cleanup_years_records</code> existiert bereits mit "
               . '<code>' . htmlspecialchars((string) $existing, ENT_QUOTES) . '</code> – unverändert';

        if ($previous !== false && (string) $previous !== (string) $existing) {
            $warn[] = 'Die bisherige Frist <code>dsgvo-cleanup-years</code> stand auf '
                    . '<code>' . htmlspecialchars((string) $previous, ENT_QUOTES) . '</code>, '
                    . '<code>cleanup_years_records</code> auf '
                    . '<code>' . htmlspecialchars((string) $existing, ENT_QUOTES) . '</code>. '
                    . 'Der zweite Wert gilt; bitte in den Einstellungen prüfen';
        }
    } else {
        $records = '3';
        if ($previous !== false && preg_match('/^\d+$/', (string) $previous) && (int) $previous >= 1) {
            $records = (string) (int) $previous;
        } elseif ($previous !== false) {
            $warn[] = 'Die bisherige Frist <code>dsgvo-cleanup-years</code> stand auf '
                    . '<code>' . htmlspecialchars((string) $previous, ENT_QUOTES) . '</code> – '
                    . 'kein brauchbarer Wert, es werden 3 Jahre eingetragen';
        }

        $insert->execute([
            'cleanup_years_records',
            $records,
            'Löschfrist in Jahren für Anwesenheiten und Ausnahmen',
        ]);
        $log[] = "Einstellung <code>cleanup_years_records</code> auf <code>{$records}</code> gesetzt"
               . ($previous !== false ? ' – Wert aus <code>dsgvo-cleanup-years</code> übernommen' : '');
    }

    if ($previous !== false) {
        $pdo->prepare("DELETE FROM `{$prefix}system_settings` WHERE setting_key = ?")
            ->execute(['dsgvo-cleanup-years']);
        $log[] = 'Alter Schlüssel <code>dsgvo-cleanup-years</code> entfernt';
    }

    // ---- Arbeitszeiten ----
    $insert->execute([
        'cleanup_years_worktime',
        '3',
        'Löschfrist in Jahren für Arbeitszeiten und die zugehörige Änderungshistorie',
    ]);
    $log[] = 'Einstellung <code>cleanup_years_worktime</code> auf <code>3</code> gesetzt';

    // ---- Änderungshistorie ohne Sitzung ----
    $insert->execute([
        'cleanup_years_audit',
        '1',
        'Frist in Jahren, nach der verwaiste Einträge der Änderungshistorie anonymisiert werden',
    ]);
    $log[] = 'Einstellung <code>cleanup_years_audit</code> auf <code>1</code> gesetzt – '
           . 'verwaiste Einträge werden danach anonymisiert, nicht gelöscht';

    return ['log' => $log, 'warnings' => $warn];
}

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
 * Generiert WHERE-Clause für Mitglieder-Aktivität basierend auf membership_dates
 * 
 * @param string $memberAlias Tabellen-Alias für members (z.B. 'm')
 * @param string $dateColumn Spalte mit Vergleichsdatum (z.B. 'a.date')
 * @param bool $includeInactive Auch inaktive Mitglieder einschließen (für Admin/Manager)
 * @return string SQL WHERE-Clause Teil
 */

function getMemberActivityWhere($memberAlias = 'm', $dateColumn = null, $includeInactive = false) {
    if ($includeInactive) {
        // Admin/Manager-Ansicht: Alle Mitglieder
        return "{$memberAlias}.active = 1";
    }
    
    if ($dateColumn === null) {
        // Kein Datum → nur generell aktive Mitglieder
        return "{$memberAlias}.active = 1";
    }
    
    // Prüfe Aktivität zum Termin-Datum
    global $database;
    $prefix = $database->table('');
    
    return "
        {$memberAlias}.active = 1
        AND (
            -- Keine membership_dates → immer aktiv
            NOT EXISTS (
                SELECT 1 FROM {$prefix}membership_dates md 
                WHERE md.member_id = {$memberAlias}.member_id
            )
            OR
            -- Hat membership_dates → Datum muss in Zeitraum liegen
            EXISTS (
                SELECT 1 FROM {$prefix}membership_dates md
                WHERE md.member_id = {$memberAlias}.member_id
                AND {$dateColumn} >= md.start_date
                AND ({$dateColumn} <= md.end_date OR md.end_date IS NULL)
            )
        )
    ";
}

/**
 * Generiert WHERE-Clause für Mitglieder-Aktivität für ein ganzes Jahr.
 * Prüft ob Mitglied irgendwann im angegebenen Jahr aktiv war (Periodenüberschneidung).
 *
 * @param int    $year        Jahr für den Aktivitätscheck (z.B. 2026)
 * @param string $memberAlias Tabellen-Alias für members (Standard: 'm')
 * @return string SQL WHERE-Clause Fragment
 */
function getMemberActivityWhereYear($year, $memberAlias = 'm') {
    global $database;
    $prefix = $database->table('');
    $year      = (int)$year;
    $yearStart = $year . '-01-01';
    $yearEnd   = $year . '-12-31';

    return "
        {$memberAlias}.active = 1
        AND (
            -- Keine membership_dates → immer aktiv
            NOT EXISTS (
                SELECT 1 FROM {$prefix}membership_dates md
                WHERE md.member_id = {$memberAlias}.member_id
            )
            OR
            -- Hat membership_dates → muss im Jahr irgendwann aktiv gewesen sein
            EXISTS (
                SELECT 1 FROM {$prefix}membership_dates md
                WHERE md.member_id = {$memberAlias}.member_id
                AND md.start_date <= '{$yearEnd}'
                AND (md.end_date IS NULL OR md.end_date >= '{$yearStart}')
            )
        )
    ";
}
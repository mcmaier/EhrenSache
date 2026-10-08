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
 * Zeitraum-Teil der Aktivitaetsregel fuer einen Tag (ohne members.active).
 *
 * Ein Tag zaehlt als aktiv, wenn er in keinem Zeitraum mit Status `inactive`
 * liegt und -- sofern das Mitglied Zeitraeume mit Status `active` hat -- in
 * einem davon. Ohne aktive Zeitraeume gilt das Mitglied ausserhalb der
 * inaktiven als aktiv; ohne jeden Zeitraum immer (OI-130). Bis dahin las keine
 * Abfrage den Status: Ein Zeitraum "ab 01.10. Inaktiv" wirkte wie
 * "ab 01.10. aktiv".
 *
 * Ein fehlender Status (NULL) zaehlt wie `active`, dem Default der Spalte.
 *
 * @param string $tag Alias-Endung, damit die Regel geschachtelt vorkommen kann
 */
function memberActivityPeriodsSql(string $memberAlias, string $dateExpr, string $prefix, string $tag = ''): string
{
    $ia = "md_i{$tag}";
    $aa = "md_a{$tag}";
    $ea = "md_e{$tag}";

    return "
        NOT EXISTS (
            SELECT 1 FROM {$prefix}membership_dates {$ia}
            WHERE {$ia}.member_id = {$memberAlias}.member_id
            AND {$ia}.status = 'inactive'
            AND {$dateExpr} >= {$ia}.start_date
            AND ({$dateExpr} <= {$ia}.end_date OR {$ia}.end_date IS NULL)
        )
        AND (
            -- Keine aktiven Zeitraeume -> ausserhalb der inaktiven aktiv
            NOT EXISTS (
                SELECT 1 FROM {$prefix}membership_dates {$ea}
                WHERE {$ea}.member_id = {$memberAlias}.member_id
                AND ({$ea}.status IS NULL OR {$ea}.status <> 'inactive')
            )
            OR
            -- Aktive Zeitraeume -> Datum muss in einem davon liegen
            EXISTS (
                SELECT 1 FROM {$prefix}membership_dates {$aa}
                WHERE {$aa}.member_id = {$memberAlias}.member_id
                AND ({$aa}.status IS NULL OR {$aa}.status <> 'inactive')
                AND {$dateExpr} >= {$aa}.start_date
                AND ({$dateExpr} <= {$aa}.end_date OR {$aa}.end_date IS NULL)
            )
        )
    ";
}

/**
 * Generiert WHERE-Clause für Mitglieder-Aktivität basierend auf membership_dates
 * 
 * @param string $memberAlias Tabellen-Alias für members (z.B. 'm')
 * @param string $dateColumn Spalte mit Vergleichsdatum (z.B. 'a.date') oder ein
 *                           SQL-Datumsliteral (z.B. "'2026-09-24'")
 * @param bool $includeInactive Auch inaktive Mitglieder einschließen (für Admin/Manager)
 * @param object|null $databaseOverride Statt des globalen $database — für Aufrufer,
 *                    die ihre Datenbank hereinreichen statt sie global zu halten
 *                    (seit OI-27 die Station; ohne Angabe bleibt alles wie bisher)
 * @return string SQL WHERE-Clause Teil
 */

function getMemberActivityWhere($memberAlias = 'm', $dateColumn = null, $includeInactive = false,
                                $databaseOverride = null) {
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
    $prefix = ($databaseOverride ?? $database)->table('');

    return "
        {$memberAlias}.active = 1
        AND (" . memberActivityPeriodsSql($memberAlias, $dateColumn, $prefix) . ")
    ";
}

/**
 * Ist das Mitglied am Stichtag aktiv — `members.active = 1` und der Tag nach
 * den Zeiträumen aus `membership_dates` aktiv (memberActivityPeriodsSql())?
 *
 * Eine Stelle für Einzelprüfungen: Kiosk (OI-27) und `auto_checkin` von
 * Geräten (OI-103). Das Datum geht als Literal in die Abfrage, damit
 * derselbe Weg auch gegen SQLite läuft (`tests/suites/station_unit.php`);
 * deshalb wird es hier auf YYYY-MM-DD geprüft.
 *
 * @param string $date Stichtag als YYYY-MM-DD
 */
function memberIsActiveOn($db, $database, int $memberId, string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException("memberIsActiveOn: invalid date '{$date}'");
    }

    $prefix   = $database->table('');
    $activity = getMemberActivityWhere('m', "'" . $date . "'", false, $database);

    $stmt = $db->prepare("SELECT 1 FROM {$prefix}members m
                          WHERE m.member_id = ? AND ({$activity})");
    $stmt->execute([$memberId]);

    return (bool) $stmt->fetchColumn();
}

/**
 * WHERE-Clause: Ist das Mitglied heute aktiv? Für die tagesgenaue Anzeige im
 * Dashboard (`is_active_today`, OI-130) — unabhängig vom gewählten Jahr.
 */
function memberActiveTodayWhere(string $memberAlias = 'm', $databaseOverride = null): string
{
    return getMemberActivityWhere($memberAlias, "'" . date('Y-m-d') . "'", false, $databaseOverride);
}

/**
 * Generiert WHERE-Clause für Mitglieder-Aktivität für ein ganzes Jahr.
 * Prüft, ob das Mitglied an irgendeinem Tag des Jahres aktiv war.
 *
 * Seit OI-130 genügt dafür keine Überschneidung mehr: Ein Inaktiv-Zeitraum
 * kann einen Teil des Jahres abdecken. Der Aktivstatus ändert sich nur am
 * Jahresbeginn, an einem Zeitraumbeginn oder am Tag nach einem Zeitraumende;
 * ist das Mitglied an keinem dieser Tage im Jahr aktiv, dann an keinem.
 * Geprüft wird also nur an diesen Kandidatentagen, mit derselben Tagesregel
 * wie getMemberActivityWhere().
 *
 * @param int    $year        Jahr für den Aktivitätscheck (z.B. 2026)
 * @param string $memberAlias Tabellen-Alias für members (Standard: 'm')
 * @return string SQL WHERE-Clause Fragment
 */
function getMemberActivityWhereYear($year, $memberAlias = 'm') {
    global $database;
    $prefix = $database->table('');
    $year      = (int)$year;
    $yearStart = "'" . $year . "-01-01'";
    $yearEnd   = "'" . $year . "-12-31'";
    $dayAfter  = "DATE_ADD(md_k.end_date, INTERVAL 1 DAY)";

    return "
        {$memberAlias}.active = 1
        AND (
            -- Aktiv am 1. Januar
            (" . memberActivityPeriodsSql($memberAlias, $yearStart, $prefix, '0') . ")
            OR
            -- Aktiv an einem Zeitraumbeginn im Jahr
            EXISTS (
                SELECT 1 FROM {$prefix}membership_dates md_k
                WHERE md_k.member_id = {$memberAlias}.member_id
                AND md_k.start_date BETWEEN {$yearStart} AND {$yearEnd}
                AND (" . memberActivityPeriodsSql($memberAlias, 'md_k.start_date', $prefix, '1') . ")
            )
            OR
            -- Aktiv am Tag nach einem Zeitraumende im Jahr
            EXISTS (
                SELECT 1 FROM {$prefix}membership_dates md_k
                WHERE md_k.member_id = {$memberAlias}.member_id
                AND md_k.end_date IS NOT NULL
                AND {$dayAfter} BETWEEN {$yearStart} AND {$yearEnd}
                AND (" . memberActivityPeriodsSql($memberAlias, $dayAfter, $prefix, '2') . ")
            )
        )
    ";
}

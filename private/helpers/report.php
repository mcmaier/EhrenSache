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

// ============================================
// BERICHTSAUSGABE
// ============================================

/**
 * Maskiert einen Wert fuer die HTML-Ausgabe.
 *
 * In die Berichte fliessen freie Nutzereingaben: Notizen, Ortsnamen und
 * Termintitel stammen aus Rollen unterhalb von admin. CSV ist gegenueber
 * Markup gleichgueltig, HTML ist es nicht -- ohne Maskierung waere das ein
 * gespeichertes XSS, das genau in der Ansicht zuendet, die ein Administrator
 * zum Pruefen oeffnet. Das Projekt hat keine CSP (OI-17); diese Funktion ist
 * die einzige Verteidigungslinie. Jeder Wert laeuft durch sie.
 */
function reportEscape($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Gibt einen Bericht als druckbare HTML-Seite aus und beendet die Anfrage.
 *
 * Bewusst ohne JavaScript und ohne window.print() beim Laden: Der Bericht
 * soll vor dem Drucken gelesen werden koennen.
 *
 * Ein Bericht besteht aus beliebig vielen Abschnitten. Der Arbeitszeitbericht
 * nutzt zwei (Tabelle und Summen), der Anwesenheitsbericht je Gruppe einen.
 *
 * @param array{
 *   title: string,
 *   period: string,
 *   sections: array<int, array{
 *     heading?: ?string, class?: ?string,
 *     columns: array<int, string>,
 *     column_groups?: array<int, array{label: string, span: int}>,
 *     rows: array<int, array<int, string>>,
 *     empty?: string
 *   }>,
 *   notes: array<int, string>
 * } $report
 */
function renderReport($db, $database, array $report): void
{
    // Pflichtschluessel frueh und laut abfangen. Ab dem Anwesenheitsbericht
    // werden Abschnitte dynamisch gebaut; ein vertippter Schluessel erzeugte
    // sonst eine Tabelle mit Kopfzeile und ohne Inhalt -- einen Ausdruck, der
    // falsch ist und richtig aussieht. Auf einem Nachweis ist das schlimmer
    // als ein Abbruch.
    foreach (['title', 'period', 'sections', 'notes'] as $key) {
        if (!array_key_exists($key, $report)) {
            throw new InvalidArgumentException("Bericht ohne Schluessel '{$key}'");
        }
    }

    foreach ($report['sections'] as $index => $section) {
        foreach (['columns', 'rows'] as $key) {
            if (!array_key_exists($key, $section)) {
                throw new InvalidArgumentException(
                    "Abschnitt {$index} des Berichts hat keinen Schluessel '{$key}'"
                );
            }
        }

        // Eine Gruppenzeile, die nicht genau ueber der Tabelle liegt,
        // verschiebt jede Ueberschrift um eine Spalte -- der Ausdruck ist
        // dann falsch und sieht richtig aus. Auf einem Nachweis ist ein
        // Abbruch besser, und zwar hier, vor der ersten Ausgabe.
        if (array_key_exists('column_groups', $section)) {
            $span = 0;
            foreach ($section['column_groups'] as $group) {
                $span += (int) ($group['span'] ?? 0);
            }
            if ($span !== count($section['columns'])) {
                throw new InvalidArgumentException(
                    "Abschnitt {$index}: Spaltengruppen decken {$span} Spalten ab, "
                    . 'die Tabelle hat ' . count($section['columns'])
                );
            }
        }
    }

    require_once __DIR__ . '/branding.php';
    $branding = getBrandingSettings($db, $database);

    $orgName = $branding['organization_name'] ?? '';
    $logo    = $branding['organization_logo'] ?? '';

    header('Content-Type: text/html; charset=utf-8');

    // <base>, weil der Bericht unter /api/ ausgeliefert wird, die Logo-Pfade
    // aus den Einstellungen aber relativ zum Web-Root stehen. Die Seite darf
    // deshalb nicht aus einer blob:-URL kommen -- dort greift die Basis nicht.
    echo "<!DOCTYPE html>\n<html lang=\"de\">\n<head>\n";
    echo "<meta charset=\"utf-8\">\n";
    echo "<base href=\"../\">\n";
    echo '<title>' . reportEscape($report['title']) . ' – ' . reportEscape($report['period']) . "</title>\n";
    echo "<link rel=\"stylesheet\" href=\"css/print.css\">\n";
    echo "</head>\n<body>\n";

    // W5: export steht in DEMO_READ_ONLY und ist auf der Demo erreichbar. Der
    // Bericht verlaesst als einzige der Oberflaechen den Bildschirm --
    // ausgedruckt waere er von einem echten Nachweis aeusserlich nicht zu
    // unterscheiden. demo_mode.php ist an dieser Stelle bereits ueber
    // api.php geladen. Fester Text ohne Nutzerdaten, keine Maskierung
    // noetig -- dieselbe Begruendung wie bei showDemoBanner() in theme.js.
    if (demoModeActive()) {
        echo '<div class="report-demo-notice" role="alert">'
           . '<strong>Demo-Installation — kein gültiger Nachweis.</strong> '
           . 'Alle Personen, Zeiten und Beträge auf diesem Blatt sind erfunden. '
           . 'Nicht zur Vorlage bei Dritten oder zur Abrechnung verwenden.'
           . "</div>\n";
    }

    echo "<header class=\"report-head\">\n";
    if ($logo !== '') {
        echo '<img class="report-logo" src="' . reportEscape($logo) . '" alt="' . reportEscape($orgName) . "\">\n";
    }
    echo "<div class=\"report-title\">\n";
    if ($orgName !== '') {
        echo '<p class="report-org">' . reportEscape($orgName) . "</p>\n";
    }
    echo '<h1>' . reportEscape($report['title']) . "</h1>\n";
    echo '<p class="report-period">' . reportEscape($report['period']) . "</p>\n";
    echo '<p class="report-created">Erstellt am ' . reportEscape(date('d.m.Y')) . "</p>\n";
    echo "</div>\n</header>\n";

    foreach ($report['sections'] as $section) {
        $heading = $section['heading'] ?? null;
        if ($heading !== null && $heading !== '') {
            echo '<h2 class="report-subhead">' . reportEscape($heading) . "</h2>\n";
        }

        $class = 'report-table';
        if (!empty($section['class'])) {
            $class .= ' ' . $section['class'];
        }

        echo '<table class="' . reportEscape($class) . "\">\n<thead>\n";

        if (!empty($section['column_groups'])) {
            echo "<tr class=\"report-colgroup\">";
            foreach ($section['column_groups'] as $group) {
                $label = (string) ($group['label'] ?? '');
                $css   = $label === '' ? ' class="report-colgroup-empty"' : '';
                echo '<th colspan="' . (int) $group['span'] . '"' . $css . '>'
                   . reportEscape($label) . '</th>';
            }
            echo "</tr>\n";
        }

        echo "<tr>";
        foreach ($section['columns'] as $col) {
            echo '<th>' . reportEscape($col) . '</th>';
        }
        echo "</tr>\n</thead>\n<tbody>\n";

        if ($section['rows'] === []) {
            $span = count($section['columns']);
            echo '<tr><td class="report-empty" colspan="' . $span . '">'
               . reportEscape($section['empty'] ?? 'Keine Daten für diesen Zeitraum.')
               . "</td></tr>\n";
        }

        foreach ($section['rows'] as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . reportEscape($cell) . '</td>';
            }
            echo "</tr>\n";
        }
        echo "</tbody>\n</table>\n";
    }

    echo "<footer class=\"report-foot\">\n<ul>\n";
    foreach ($report['notes'] as $note) {
        echo '<li>' . reportEscape($note) . "</li>\n";
    }
    echo "</ul>\n</footer>\n";

    echo "</body>\n</html>\n";
    exit();
}

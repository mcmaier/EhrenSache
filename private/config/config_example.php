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

// ============================================
// CONFIG EXAMPLE PHP
// ============================================
//
// Vorlage für private/config/config.php. Seit 1.6.0 enthält diese Datei
// ausschließlich Daten -- der Programmcode dazu liegt in
// private/helpers/bootstrap.php und private/helpers/database.php und wird bei
// jedem Update mit ausgetauscht.
//
// Fehlende Schlüssel sind unkritisch: Der Bootstrap füllt sie mit Defaults.
// Der Installer schreibt config.php über renderConfigFile() in
// private/helpers/config_reader.php; von Hand kopieren und anpassen geht ebenso.

return [
    'db' => [
        'host'   => 'your_host',
        'name'   => 'your_database',
        'user'   => 'your_username',
        'pass'   => 'your_password',
        'prefix' => 'your_prefix',
    ],

    // Basis-URL für Mail-Links und API-Aufrufe.
    // null = automatisch aus dem Request ermitteln. Nur setzen, wenn die
    // Erkennung nicht funktioniert, z. B. 'http://localhost/ehrensache'.
    'base_url' => null,

    // Demo-Modus für öffentlich erreichbare Installationen.
    // Eingeschaltet begrenzt er alle Zugriffe ÜBER DIE REST-API auf feste Listen:
    // schreibend sind nur Mitglieder, Termine, Anwesenheit, Anträge, Arbeitszeit,
    // Check-in und Kiosk erlaubt; Konten, Rechte, Mailversand, Dateiannahme und
    // Systemeinstellungen sind gesperrt. Lesend geht nur, was in einer der drei
    // Listen in private/helpers/demo_mode.php steht — eine dort nicht eingetragene
    // Ressource ist auch lesend gesperrt.
    // Eigene Einstiegspunkte neben public/api/api.php erfasst der Wächter NICHT.
    // Aus: false oder null. Jeder andere Wert (auch 0 oder 'false' als
    // Zeichenkette) gilt absichtlich als eingeschaltet.
    // Für eine normale Vereinsinstallation false lassen.
    'demo_mode' => false,
];

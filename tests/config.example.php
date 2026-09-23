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
 * Vorlage für tests/config.php.
 *
 * Kopieren nach tests/config.php und an die lokale Instanz anpassen.
 * tests/config.php ist in .gitignore ausgeschlossen, weil es Zugangsdaten enthält.
 */
declare(strict_types=1);

return [
    'base_url' => 'http://localhost/EhrenSache/public',
    'admin'    => ['email' => 'admin@example.com',   'password' => 'test1234'],
    'manager'  => ['email' => 'manager@example.com', 'password' => 'test1234'],
    'user'     => ['email' => 'user@example.com',    'password' => 'test1234'],
];

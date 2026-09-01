<?php
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

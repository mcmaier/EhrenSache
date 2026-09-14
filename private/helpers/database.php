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
 * Bis 1.5.1 stand diese Klasse in private/config/config.php -- einer Datei, die
 * nie überschrieben wird. Ihr Code veraltete dadurch bei jedem Release still,
 * und Migrationen mussten ihn per preg_replace nachziehen. Hier liegt er im
 * Paket und wird ganz normal mit ausgetauscht.
 *
 * getConnection() und table() verhalten sich wie zuvor, damit die
 * $database->table(...)-Aufrufe im Bestand unberührt bleiben. Neu ist nur, dass
 * der Konstruktor die Zugangsdaten bekommt: new Database(appConfig()['db']).
 */
declare(strict_types=1);

class Database
{
    /** @var array{host:string,name:string,user:string,pass:string,prefix:string} */
    private array $cfg;

    public $conn;

    public function __construct(array $dbConfig)
    {
        $this->cfg = $dbConfig + ['host' => '', 'name' => '', 'user' => '', 'pass' => '', 'prefix' => ''];
    }

    public function getConnection()
    {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                'mysql:host=' . $this->cfg['host'] . ';dbname=' . $this->cfg['name'],
                $this->cfg['user'],
                $this->cfg['pass']
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec('set names utf8mb4');
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Database connection error']);
            exit();
        }
        return $this->conn;
    }

    /** Vollständiger Tabellenname inklusive Präfix. */
    public function table($tableName)
    {
        return $this->cfg['prefix'] . $tableName;
    }
}

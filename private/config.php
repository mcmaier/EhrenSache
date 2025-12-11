<?php
class Database {
    private $host = "localhost";
    private $db_name = "ehrenzeit";
    private $username = "root";
    private $password = "";
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, 
                                  $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("set names utf8mb4");
        } catch(PDOException $e) {
            http_response_code(500);
            echo json_encode(["message" => "Database connection error"]);
            exit();
        }
        return $this->conn;
    }
}

define('AUTO_CHECKIN_TOLERANCE_HOURS', 2);

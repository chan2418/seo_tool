<?php

class Database {
    private $host;
    private $db_name;
    private $port;
    private $username;
    private $password;
    private $lastError;
    public $conn;

    public function __construct() {
        $config = require __DIR__ . '/config.php';
        $this->host = $config['db_host'];
        $this->db_name = $config['db_name'];
        $this->port = $config['db_port'] ?? null;
        $this->username = $config['db_user'];
        $this->password = $config['db_pass'];
        $this->lastError = null;
    }

    public function connect() {
        $this->conn = null;
        $this->lastError = null;

        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4"; // ensure utf8mb4
            if (!empty($this->port)) {
                $dsn .= ";port=" . (int) $this->port;
            }
            $this->conn = new PDO($dsn, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            // Log error instead of exposing it
            error_log("Connection Error: " . $e->getMessage());
            $this->lastError = $e->getMessage();
            // Return null or handle gracefully in calling code, but here we just return null on failure
        }

        return $this->conn;
    }

    public function getLastError() {
        return $this->lastError;
    }
}

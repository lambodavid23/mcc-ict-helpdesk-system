<?php
/**
 * Database Configuration
 * Smart ICT Helpdesk System - Mutare City Council
 *
 * Credentials come from the environment when it provides them, so a deployment
 * does not have to carry its database password in a tracked file. The literal
 * defaults below are the stock XAMPP setup (root, no password) and are only a
 * fallback for local development.
 *
 *   DB_HOST, DB_USER, DB_PASSWORD, DB_NAME
 */

class Database {
    private $host;
    private $username;
    private $password;
    private $database;
    public $conn;

    private static function env($name, $default) {
        $value = getenv($name);
        return ($value === false || $value === '') ? $default : $value;
    }

    public function __construct() {
        $this->host     = self::env('DB_HOST', 'localhost');
        $this->username = self::env('DB_USER', 'root');
        $this->password = self::env('DB_PASSWORD', '');
        $this->database = self::env('DB_NAME', 'mcc_helpdesk');
        $this->connect();
    }

    private function connect() {
        try {
            $this->conn = new mysqli($this->host, $this->username, $this->password, $this->database);
            if ($this->conn->connect_error) {
                // The driver message can contain the host and database name.
                // Log it for the operator, but do not put it in the response.
                error_log('MCC Helpdesk DB connection failed: ' . $this->conn->connect_error);
                die("Database connection failed. Check the DB_* settings in config/database.php.");
            }
        } catch (Exception $e) {
            error_log('MCC Helpdesk DB connection error: ' . $e->getMessage());
            die("Database connection failed. Check the DB_* settings in config/database.php.");
        }
    }

    public function getConnection() {
        return $this->conn;
    }

    public function query($sql) {
        return $this->conn->query($sql);
    }

    public function escape($string) {
        return $this->conn->real_escape_string($string);
    }

    public function getLastId() {
        return $this->conn->insert_id;
    }

}
?>

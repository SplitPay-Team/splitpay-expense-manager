<?php
class Database {
    private $connection;
    private static $instance = null;
    
    private function __construct() {
        require_once dirname(__DIR__) . '/config.php';
        
        $this->connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        if ($this->connection->connect_error) {
            die("Connection failed: " . $this->connection->connect_error);
        }
        
        $this->connection->set_charset("utf8mb4");
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function prepare($sql) {
        return $this->connection->prepare($sql);
    }
    
    public function escapeString($string) {
        return $this->connection->real_escape_string($string);
    }
    
    public function insertId() {
        return $this->connection->insert_id;
    }
    
    public function query($sql) {
        return $this->connection->query($sql);
    }
    
    public function error() {
        return $this->connection->error;
    }
}
?>

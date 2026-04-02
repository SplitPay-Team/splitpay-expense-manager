<?php
class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    public function register($username, $password) {
        $username = $this->db->escapeString($username);
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $sql = "INSERT INTO users (username, password) VALUES (?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("ss", $username, $hashedPassword);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Registration successful'];
        } else {
            return ['success' => false, 'message' => 'Username already exists'];
        }
    }
    
    public function login($username, $password) {
        $username = $this->db->escapeString($username);
        
        $sql = "SELECT id, username, password FROM users WHERE username = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            if (password_verify($password, $row['password'])) {
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['username'] = $row['username'];
                return ['success' => true, 'message' => 'Login successful'];
            }
        }
        
        return ['success' => false, 'message' => 'Invalid username or password'];
    }
    
    public function logout() {
        session_destroy();
        return ['success' => true, 'message' => 'Logged out successfully'];
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    
    public function getCurrentUser() {
        if ($this->isLoggedIn()) {
            return [
                'id' => $_SESSION['user_id'],
                'username' => $_SESSION['username']
            ];
        }
        return null;
    }
}
?>

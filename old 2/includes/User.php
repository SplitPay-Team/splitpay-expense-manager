<?php
class User {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function searchUsers($query, $excludeUserId = null) {
        $query = '%' . $this->db->escapeString($query) . '%';
        
        $sql = "SELECT id, username FROM users WHERE username LIKE ?";
        
        if ($excludeUserId) {
            $sql .= " AND id != ?";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("si", $query, $excludeUserId);
        } else {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("s", $query);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        
        return $users;
    }
    
    public function getAllUsers($excludeUserId = null) {
        $sql = "SELECT id, username FROM users";
        
        if ($excludeUserId) {
            $sql .= " WHERE id != ? ORDER BY username";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("i", $excludeUserId);
        } else {
            $sql .= " ORDER BY username";
            $stmt = $this->db->prepare($sql);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        
        return $users;
    }
    
    public function getUserById($userId) {
        $sql = "SELECT id, username FROM users WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_assoc();
    }
}
?>

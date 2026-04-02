<?php
class Payment {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function create($payerId, $amount, $description, $date, $participants, $includePayer = true) {
        // Start transaction
        $this->db->getConnection()->begin_transaction();
        
        try {
            // Insert payment
            $sql = "INSERT INTO payments (payer_id, amount, description, payment_date) VALUES (?, ?, ?, ?)";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("idss", $payerId, $amount, $description, $date);
            $stmt->execute();
            $paymentId = $this->db->insertId();
            
            // Calculate shares
            $totalParticipants = count($participants);
            if ($includePayer) {
                $totalParticipants++;
                $participants[] = $payerId;
            }
            
            $shareAmount = $amount / $totalParticipants;
            
            // Insert participants
            $sql = "INSERT INTO payment_participants (payment_id, user_id, share_amount) VALUES (?, ?, ?)";
            $stmt = $this->db->prepare($sql);
            
            foreach ($participants as $userId) {
                $stmt->bind_param("iid", $paymentId, $userId, $shareAmount);
                $stmt->execute();
            }
            
            $this->db->getConnection()->commit();
            return ['success' => true, 'message' => 'Payment created successfully', 'payment_id' => $paymentId];
            
        } catch (Exception $e) {
            $this->db->getConnection()->rollback();
            return ['success' => false, 'message' => 'Failed to create payment: ' . $e->getMessage()];
        }
    }
    
    public function getUserPayments($userId) {
        $sql = "SELECT p.*, u.username as payer_name 
                FROM payments p 
                JOIN users u ON p.payer_id = u.id 
                WHERE p.payer_id = ? OR p.id IN (
                    SELECT payment_id FROM payment_participants WHERE user_id = ?
                )
                ORDER BY p.payment_date DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("ii", $userId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $payments = [];
        while ($row = $result->fetch_assoc()) {
            $payments[] = $row;
        }
        
        return $payments;
    }
    
    public function getPaymentDetails($paymentId) {
        // Get payment info
        $sql = "SELECT p.*, u.username as payer_name 
                FROM payments p 
                JOIN users u ON p.payer_id = u.id 
                WHERE p.id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $paymentId);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();
        
        if (!$payment) {
            return null;
        }
        
        // Get participants
        $sql = "SELECT pp.*, u.username 
                FROM payment_participants pp 
                JOIN users u ON pp.user_id = u.id 
                WHERE pp.payment_id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $paymentId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $participants = [];
        while ($row = $result->fetch_assoc()) {
            $participants[] = $row;
        }
        
        $payment['participants'] = $participants;
        return $payment;
    }
    
    public function deletePayment($paymentId, $userId) {
        // Check if user is payer
        $sql = "SELECT id FROM payments WHERE id = ? AND payer_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("ii", $paymentId, $userId);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows === 0) {
            return ['success' => false, 'message' => 'You can only delete payments you created'];
        }
        
        $sql = "DELETE FROM payments WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $paymentId);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Payment deleted successfully'];
        }
        
        return ['success' => false, 'message' => 'Failed to delete payment'];
    }
    
    public function getUserBalance($userId) {
        // Money owed to user (as payer)
        $sql = "SELECT SUM(pp.share_amount - pp.settled_amount) as total_owed
                FROM payment_participants pp
                JOIN payments p ON pp.payment_id = p.id
                WHERE p.payer_id = ? AND pp.user_id != ? AND pp.settled = FALSE";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("ii", $userId, $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $owedToUser = $result['total_owed'] ?? 0;
        
        // Money user owes to others
        $sql = "SELECT SUM(pp.share_amount - pp.settled_amount) as total_owed
                FROM payment_participants pp
                JOIN payments p ON pp.payment_id = p.id
                WHERE pp.user_id = ? AND p.payer_id != ? AND pp.settled = FALSE";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("ii", $userId, $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $userOwes = $result['total_owed'] ?? 0;
        
        return [
            'net_balance' => $owedToUser - $userOwes,
            'to_receive' => $owedToUser,
            'to_pay' => $userOwes
        ];
    }
}
?>

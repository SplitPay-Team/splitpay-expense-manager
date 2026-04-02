<?php
class Settlement {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function settleParticipant($paymentId, $participantId, $amount = null) {
        // Validate inputs
        if (!$paymentId || !$participantId) {
            return ['success' => false, 'message' => 'Payment ID and Participant ID are required'];
        }
        
        $this->db->getConnection()->begin_transaction();
        
        try {
            // Get participant info with payment details
            $sql = "SELECT pp.*, p.payer_id, p.amount as total_amount, p.description 
                    FROM payment_participants pp
                    JOIN payments p ON pp.payment_id = p.id
                    WHERE pp.id = ? AND pp.payment_id = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("ii", $participantId, $paymentId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception("Participant not found for this payment");
            }
            
            $participant = $result->fetch_assoc();
            
            // Calculate remaining amount
            $shareAmount = floatval($participant['share_amount']);
            $settledAmount = floatval($participant['settled_amount']);
            $remainingAmount = $shareAmount - $settledAmount;
            
            // Determine settlement amount
            $settleAmount = $amount ? floatval($amount) : $remainingAmount;
            
            if ($settleAmount <= 0) {
                throw new Exception("Settlement amount must be greater than 0");
            }
            
            if ($settleAmount > $remainingAmount) {
                throw new Exception("Settlement amount ($settleAmount) exceeds remaining balance ($remainingAmount)");
            }
            
            $newSettledAmount = $settledAmount + $settleAmount;
            $fullySettled = (abs($newSettledAmount - $shareAmount) < 0.01); // Account for floating point
            
            // Update participant
            $sql = "UPDATE payment_participants 
                    SET settled_amount = ?, 
                        settled = ?, 
                        settled_date = NOW() 
                    WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $settled = $fullySettled ? 1 : 0;
            $stmt->bind_param("dii", $newSettledAmount, $settled, $participantId);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update settlement record");
            }
            
            $this->db->getConnection()->commit();
            
            return [
                'success' => true, 
                'message' => $fullySettled ? 'Payment fully settled' : 'Partial settlement recorded',
                'fully_settled' => $fullySettled,
                'amount' => $settleAmount,
                'remaining' => $remainingAmount - $settleAmount
            ];
            
        } catch (Exception $e) {
            $this->db->getConnection()->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function getOutstandingSettlements($userId) {
        // Get payments where user is payer and others owe money
        $sql = "SELECT 
                    pp.id as participant_id,
                    pp.user_id as debtor_id,
                    u.username as debtor_name,
                    p.id as payment_id,  /* Make sure this is selected */
                    p.description,
                    p.amount as total_amount,
                    p.payment_date,
                    pp.share_amount,
                    pp.settled_amount,
                    ROUND(pp.share_amount - pp.settled_amount, 2) as outstanding
                FROM payment_participants pp
                JOIN payments p ON pp.payment_id = p.id
                JOIN users u ON pp.user_id = u.id
                WHERE p.payer_id = ? 
                    AND pp.user_id != ? 
                    AND pp.settled = 0
                    AND (pp.share_amount - pp.settled_amount) > 0.01
                ORDER BY p.payment_date DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("ii", $userId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $settlements = [];
        while ($row = $result->fetch_assoc()) {
            $settlements[] = [
                'participant_id' => $row['participant_id'],
                'debtor_id' => $row['debtor_id'],
                'debtor_name' => $row['debtor_name'],
                'payment_id' => $row['payment_id'],  // Include this
                'description' => $row['description'],
                'total_amount' => number_format($row['total_amount'], 2),
                'share_amount' => number_format($row['share_amount'], 2),
                'settled_amount' => number_format($row['settled_amount'], 2),
                'outstanding' => number_format($row['outstanding'], 2),
                'payment_date' => date('M d, Y', strtotime($row['payment_date']))
            ];
        }
        
        return $settlements;
    }

    public function getUserSettlementHistory($userId) {
        // Get settlement history for a user
        $sql = "SELECT 
                    pp.id,
                    pp.payment_id,
                    p.description,
                    p.payer_id,
                    payer.username as payer_name,
                    debtor.username as debtor_name,
                    pp.settled_amount,
                    pp.settled_date,
                    pp.settled as fully_settled
                FROM payment_participants pp
                JOIN payments p ON pp.payment_id = p.id
                JOIN users payer ON p.payer_id = payer.id
                JOIN users debtor ON pp.user_id = debtor.id
                WHERE (p.payer_id = ? OR pp.user_id = ?)
                    AND pp.settled_amount > 0
                ORDER BY pp.settled_date DESC
                LIMIT 50";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("ii", $userId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $history = [];
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
        
        return $history;
    }
}
?>

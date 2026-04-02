<?php
/**
 * SplitPay — Session Guard Middleware
 * Include at the top of every authenticated page/endpoint.
 * Redirects to login if no valid session exists.
 */

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

function requireAuth(): void {
  if (empty($_SESSION['user_id'])) {
    if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
      http_response_code(401);
      echo json_encode(['success' => false, 'error' => 'Authentication required.']);
      exit;
    }
    header('Location: /pages/login.php');
    exit;
  }
}

function requireAdmin(int $groupId, PDO $pdo): void {
  requireAuth();
  $stmt = $pdo->prepare(
    'SELECT role FROM group_members WHERE group_id = ? AND user_id = ?'
  );
  $stmt->execute([$groupId, $_SESSION['user_id']]);
  $row = $stmt->fetch();
  if (!$row || $row['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required.']);
    exit;
  }
}

function currentUserId(): int {
  return (int)($_SESSION['user_id'] ?? 0);
}

function csrfToken(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
  $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
  }
}

// Ensure CSRF token is always initialised
csrfToken();
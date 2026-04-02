<?php
/**
 * SplitPay — Utility Helpers
 */

/**
 * Return a JSON success response and exit.
 */
function jsonSuccess(mixed $data = null, string $message = 'OK'): never {
  header('Content-Type: application/json');
  echo json_encode(['success' => true, 'message' => $message, 'data' => $data]);
  exit;
}

/**
 * Return a JSON error response and exit.
 */
function jsonError(string $message, int $status = 400): never {
  http_response_code($status);
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'error' => $message]);
  exit;
}

/**
 * Sanitise and return a POST string.
 */
function postStr(string $key, string $default = ''): string {
  return trim(htmlspecialchars($_POST[$key] ?? $default, ENT_QUOTES));
}

/**
 * Return a POST integer.
 */
function postInt(string $key, int $default = 0): int {
  return (int)($_POST[$key] ?? $default);
}

/**
 * Return a POST float.
 */
function postFloat(string $key, float $default = 0.0): float {
  return (float)($_POST[$key] ?? $default);
}

/**
 * Return a POST array (e.g. checkboxes named field[]).
 */
function postArray(string $key): array {
  $val = $_POST[$key] ?? [];
  return is_array($val) ? array_map('intval', $val) : [];
}

/**
 * Insert a notification record for a user.
 */
function notify(PDO $pdo, int $userId, string $type, int $referenceId, string $message): void {
  $stmt = $pdo->prepare(
    'INSERT INTO notifications (user_id, type, reference_id, message) VALUES (?, ?, ?, ?)'
  );
  $stmt->execute([$userId, $type, $referenceId, $message]);
}

/**
 * Fetch all group members (user_id list) for notifications.
 */
function getGroupMemberIds(PDO $pdo, int $groupId): array {
  $stmt = $pdo->prepare('SELECT user_id FROM group_members WHERE group_id = ?');
  $stmt->execute([$groupId]);
  return array_column($stmt->fetchAll(), 'user_id');
}

/**
 * Format a monetary amount for display.
 */
function money(float $amount): string {
  return number_format($amount, 2);
}

/**
 * Generate avatar initials from a display name.
 */
function initials(string $name): string {
  $words = explode(' ', trim($name));
  $init = strtoupper(substr($words[0], 0, 1));
  if (count($words) > 1) $init .= strtoupper(substr(end($words), 0, 1));
  return $init;
}
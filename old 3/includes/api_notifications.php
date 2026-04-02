<?php
/**
 * SplitPay — Notifications API
 * Actions: list, mark_read
 */

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

header('Content-Type: application/json');
requireAuth();

$action = $_GET['action'] ?? '';
$pdo    = DB::connect();
$uid    = currentUserId();

switch ($action) {

  /* ── List Notifications ── */
  case 'list':
    $limit = min(50, (int)($_GET['limit'] ?? 20));
    $stmt  = $pdo->prepare(
      "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?"
    );
    $stmt->execute([$uid, $limit]);
    jsonSuccess($stmt->fetchAll());

  /* ── Mark Read ── */
  case 'mark_read':
    verifyCsrf();
    $all = postStr('all');
    if ($all === '1') {
      $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
      $stmt->execute([$uid]);
    } else {
      $nid = postInt('notification_id');
      if (!$nid) jsonError('notification_id is required.');
      $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
      $stmt->execute([$nid, $uid]);
    }
    jsonSuccess(null, 'Marked as read.');

  default:
    jsonError('Unknown action.', 404);
}

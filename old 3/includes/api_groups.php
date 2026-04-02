<?php
/**
 * SplitPay — Groups API
 * Actions: create, list, add_member, remove_member, search_users
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

  /* ── Create Group ── */
  case 'create':
    verifyCsrf();
    $name = postStr('group_name');
    $desc = postStr('description');
    if (!$name) jsonError('Group name is required.');

    $pdo->beginTransaction();
    try {
      $stmt = $pdo->prepare('INSERT INTO groups (group_name, description, created_by) VALUES (?, ?, ?)');
      $stmt->execute([$name, $desc ?: null, $uid]);
      $gid = (int)$pdo->lastInsertId();

      $stmt = $pdo->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'admin')");
      $stmt->execute([$gid, $uid]);

      $pdo->commit();
      jsonSuccess(['group_id' => $gid], 'Group created.');
    } catch (Exception $e) {
      $pdo->rollBack();
      jsonError('Failed to create group.', 500);
    }

  /* ── List Groups ── */
  case 'list':
    $stmt = $pdo->prepare("
      SELECT g.*, gm.role,
             COUNT(DISTINCT gm2.user_id) AS member_count
      FROM groups g
      JOIN group_members gm  ON gm.group_id  = g.group_id AND gm.user_id = :uid
      LEFT JOIN group_members gm2 ON gm2.group_id = g.group_id
      GROUP BY g.group_id
      ORDER BY g.created_at DESC
    ");
    $stmt->execute(['uid' => $uid]);
    jsonSuccess($stmt->fetchAll());

  /* ── Add Member ── */
  case 'add_member':
    verifyCsrf();
    $gid     = postInt('group_id');
    $newUser = postInt('user_id');
    requireAdmin($gid, $pdo);

    // Check not already a member
    $stmt = $pdo->prepare('SELECT member_id FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$gid, $newUser]);
    if ($stmt->fetch()) jsonError('User is already a member of this group.', 409);

    $stmt = $pdo->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')");
    $stmt->execute([$gid, $newUser]);
    jsonSuccess(null, 'Member added.');

  /* ── Remove Member ── */
  case 'remove_member':
    verifyCsrf();
    $gid    = postInt('group_id');
    $target = postInt('user_id');
    requireAdmin($gid, $pdo);

    if ($target === $uid) jsonError('You cannot remove yourself.');

    $stmt = $pdo->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$gid, $target]);
    jsonSuccess(null, 'Member removed.');

  /* ── Search Users (for add member autocomplete) ── */
  case 'search_users':
    $q   = trim($_GET['q'] ?? '');
    $gid = (int)($_GET['group_id'] ?? 0);
    if (strlen($q) < 2) jsonSuccess([]);

    $stmt = $pdo->prepare("
      SELECT u.user_id, u.display_name, u.username, u.email
      FROM users u
      WHERE (u.username LIKE ? OR u.email LIKE ? OR u.display_name LIKE ?)
        AND u.user_id NOT IN (
          SELECT user_id FROM group_members WHERE group_id = ?
        )
      LIMIT 8
    ");
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like, $gid]);
    jsonSuccess($stmt->fetchAll());

  default:
    jsonError('Unknown action.', 404);
}

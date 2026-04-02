<?php
ini_set('display_errors', 1);   // show errors in browser
ini_set('log_errors', 1);       // enable logging

/**
 * SplitPay — Projects API
 * Actions: create, list
 */

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

header('Content-Type: application/json');
requireAuth();

$action = $_GET['action'] ?? '';
$pdo    = db();
$uid    = currentUserId();

switch ($action) {

  /* ── Create Project ── */
  case 'create':
    verifyCsrf();
    $gid         = postInt('group_id');
    $projectName = postStr('project_name');
    $description = postStr('description');
    $eventDate   = postStr('event_date') ?: null;

    if (!$gid || !$projectName) jsonError('Group ID and project name are required.');
    requireAdmin($gid, $pdo);

    $stmt = $pdo->prepare(
      'INSERT INTO projects (group_id, project_name, description, event_date, created_by) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$gid, $projectName, $description ?: null, $eventDate, $uid]);
    $pid = (int)$pdo->lastInsertId();

    jsonSuccess(['project_id' => $pid], 'Project created.');

  /* ── List Projects for a Group ── */
  case 'list':
    $gid = (int)($_GET['group_id'] ?? 0);
    if (!$gid) jsonError('group_id is required.');

    // Verify membership
    $stmt = $pdo->prepare('SELECT member_id FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$gid, $uid]);
    if (!$stmt->fetch()) jsonError('Access denied.', 403);

    $stmt = $pdo->prepare("
      SELECT p.*, u.display_name AS created_by_name,
             COUNT(DISTINCT e.expense_id) AS expense_count,
             COALESCE(SUM(e.amount), 0) AS total_amount
      FROM projects p
      JOIN users u ON u.user_id = p.created_by
      LEFT JOIN expenses e ON e.project_id = p.project_id
      WHERE p.group_id = ?
      GROUP BY p.project_id
      ORDER BY p.created_at DESC
    ");
    $stmt->execute([$gid]);
    jsonSuccess($stmt->fetchAll());

  default:
    jsonError('Unknown action.', 404);
}

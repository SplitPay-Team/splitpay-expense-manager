<?php
/**
 * SplitPay — Expenses API
 * Actions: add, edit, confirm, list
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

  /* ── Add Expense ── */
  case 'add':
    verifyCsrf();
    $pid          = postInt('project_id');
    $description  = postStr('description');
    $amount       = postFloat('amount');
    $paidBy       = postInt('paid_by');
    $participants = postArray('participants');

    if (!$pid || !$description || $amount <= 0 || !$paidBy || empty($participants)) {
      jsonError('All fields are required. Amount must be greater than zero.');
    }

    // Verify admin access on project's group
    $stmt = $pdo->prepare('SELECT group_id FROM projects WHERE project_id = ? AND status = "open"');
    $stmt->execute([$pid]);
    $project = $stmt->fetch();
    if (!$project) jsonError('Project not found or already settled.');
    requireAdmin($project['group_id'], $pdo);

    $pdo->beginTransaction();
    try {
      $stmt = $pdo->prepare(
        'INSERT INTO expenses (project_id, description, amount, paid_by, created_by) VALUES (?, ?, ?, ?, ?)'
      );
      $stmt->execute([$pid, $description, $amount, $paidBy, $uid]);
      $eid = (int)$pdo->lastInsertId();

      $stmtPart = $pdo->prepare(
        'INSERT INTO expense_participants (expense_id, user_id) VALUES (?, ?)'
      );
      $stmtNotif = $pdo->prepare(
        'INSERT INTO notifications (user_id, type, reference_id, message) VALUES (?, ?, ?, ?)'
      );

      // Get payer name
      $stmtU = $pdo->prepare('SELECT display_name FROM users WHERE user_id = ?');
      $stmtU->execute([$paidBy]);
      $payerName = $stmtU->fetchColumn();

      foreach ($participants as $participantId) {
        $stmtPart->execute([$eid, $participantId]);
        if ($participantId !== $uid) {
          $stmtNotif->execute([
            $participantId,
            'expense_added',
            $eid,
            "New expense: "{$description}" — {$payerName} paid " . money($amount) . ". Your share: " . money($amount / count($participants)) . "."
          ]);
        }
      }

      $pdo->commit();
      jsonSuccess(['expense_id' => $eid], 'Expense added.');
    } catch (Exception $e) {
      $pdo->rollBack();
      error_log($e->getMessage());
      jsonError('Failed to add expense.', 500);
    }

  /* ── Edit Expense ── */
  case 'edit':
    verifyCsrf();
    $eid         = postInt('expense_id');
    $description = postStr('description');
    $amount      = postFloat('amount');
    $paidBy      = postInt('paid_by');
    $participants= postArray('participants');

    if (!$eid || !$description || $amount <= 0 || !$paidBy || empty($participants)) {
      jsonError('All fields are required.');
    }

    // Fetch expense and verify it's still pending
    $stmt = $pdo->prepare("
      SELECT e.*, p.group_id FROM expenses e
      JOIN projects p ON p.project_id = e.project_id
      WHERE e.expense_id = ? AND e.status = 'pending'
    ");
    $stmt->execute([$eid]);
    $expense = $stmt->fetch();
    if (!$expense) jsonError('Expense not found or cannot be edited.');
    requireAdmin($expense['group_id'], $pdo);

    $pdo->beginTransaction();
    try {
      $stmt = $pdo->prepare(
        'UPDATE expenses SET description = ?, amount = ?, paid_by = ? WHERE expense_id = ?'
      );
      $stmt->execute([$description, $amount, $paidBy, $eid]);

      // Remove old participants and re-insert
      $pdo->prepare('DELETE FROM expense_participants WHERE expense_id = ?')->execute([$eid]);

      $stmtPart = $pdo->prepare('INSERT INTO expense_participants (expense_id, user_id) VALUES (?, ?)');
      foreach ($participants as $pid) {
        $stmtPart->execute([$eid, $pid]);
      }

      $pdo->commit();
      jsonSuccess(null, 'Expense updated.');
    } catch (Exception $e) {
      $pdo->rollBack();
      jsonError('Failed to update expense.', 500);
    }

  /* ── Confirm / Reject Expense ── */
  case 'confirm':
    verifyCsrf();
    $epId   = postInt('ep_id');
    $action2= postStr('action');

    if (!in_array($action2, ['confirm', 'reject'])) jsonError('Invalid action.');

    // Verify this ep belongs to the current user
    $stmt = $pdo->prepare("
      SELECT ep.*, e.project_id, p.group_id
      FROM expense_participants ep
      JOIN expenses e ON e.expense_id = ep.expense_id
      JOIN projects p ON p.project_id = e.project_id
      WHERE ep.ep_id = ? AND ep.user_id = ? AND ep.status = 'pending'
    ");
    $stmt->execute([$epId, $uid]);
    $ep = $stmt->fetch();
    if (!$ep) jsonError('Participant record not found or already responded.', 404);

    $newStatus = $action2 === 'confirm' ? 'confirmed' : 'rejected';

    $pdo->beginTransaction();
    try {
      $stmt = $pdo->prepare(
        "UPDATE expense_participants SET status = ?, responded_at = NOW() WHERE ep_id = ?"
      );
      $stmt->execute([$newStatus, $epId]);

      // Check if all participants have responded
      $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(status = 'pending')   AS pending_count,
               SUM(status = 'rejected')  AS rejected_count
        FROM expense_participants WHERE expense_id = ?
      ");
      $stmt->execute([$ep['expense_id']]);
      $counts = $stmt->fetch();

      if ((int)$counts['pending_count'] === 0) {
        $finalStatus = (int)$counts['rejected_count'] > 0 ? 'rejected' : 'confirmed';
        $pdo->prepare("UPDATE expenses SET status = ? WHERE expense_id = ?")
            ->execute([$finalStatus, $ep['expense_id']]);

        // Notify admin
        $stmt = $pdo->prepare("SELECT created_by, description FROM expenses WHERE expense_id = ?");
        $stmt->execute([$ep['expense_id']]);
        $expRow = $stmt->fetch();

        // Get current user's name
        $stmt = $pdo->prepare("SELECT display_name FROM users WHERE user_id = ?");
        $stmt->execute([$uid]);
        $myName = $stmt->fetchColumn();

        notify(
          $pdo,
          $expRow['created_by'],
          $action2 === 'confirm' ? 'expense_confirmed' : 'expense_rejected',
          $ep['expense_id'],
          "{$myName} {$newStatus} the expense \"{$expRow['description']}\"."
        );
      }

      $pdo->commit();
      jsonSuccess(null, 'Response recorded.');
    } catch (Exception $e) {
      $pdo->rollBack();
      jsonError('Failed to record response.', 500);
    }

  /* ── List Expenses for a Project ── */
  case 'list':
    $pid = (int)($_GET['project_id'] ?? 0);
    if (!$pid) jsonError('project_id is required.');

    $stmt = $pdo->prepare('SELECT group_id FROM projects WHERE project_id = ?');
    $stmt->execute([$pid]);
    $proj = $stmt->fetch();
    if (!$proj) jsonError('Project not found.', 404);

    // Verify membership
    $stmt = $pdo->prepare('SELECT member_id FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$proj['group_id'], $uid]);
    if (!$stmt->fetch()) jsonError('Access denied.', 403);

    $stmt = $pdo->prepare("
      SELECT e.*, u.display_name AS payer_name,
             COUNT(ep.ep_id) AS participant_count
      FROM expenses e
      JOIN users u ON u.user_id = e.paid_by
      LEFT JOIN expense_participants ep ON ep.expense_id = e.expense_id
      WHERE e.project_id = ?
      GROUP BY e.expense_id
      ORDER BY e.created_at DESC
    ");
    $stmt->execute([$pid]);
    jsonSuccess($stmt->fetchAll());

  default:
    jsonError('Unknown action.', 404);
}

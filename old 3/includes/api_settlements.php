<?php
/**
 * SplitPay — Settlements API
 * Actions: settle, report
 *
 * Implements the greedy debt-minimisation algorithm from the SRS (Section 16).
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

  /* ── Trigger Settlement ── */
  case 'settle':
    verifyCsrf();
    $pid = postInt('project_id');
    if (!$pid) jsonError('project_id is required.');

    // Load project & verify admin
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE project_id = ? AND status = 'open'");
    $stmt->execute([$pid]);
    $project = $stmt->fetch();
    if (!$project) jsonError('Project not found or already settled.');
    requireAdmin($project['group_id'], $pdo);

    // ── Step 1: Check for pending expenses ─────────────────
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE project_id = ? AND status = 'pending'");
    $stmt->execute([$pid]);
    $pendingCount = (int)$stmt->fetchColumn();
    if ($pendingCount > 0) {
      jsonError("Cannot settle — {$pendingCount} expense(s) are still pending confirmation.");
    }

    // ── Step 2 & 3: Collect confirmed expenses + compute paid/owed ──
    $stmt = $pdo->prepare("
      SELECT e.expense_id, e.amount, e.paid_by,
             ep.user_id AS participant_id
      FROM expenses e
      JOIN expense_participants ep ON ep.expense_id = e.expense_id
      WHERE e.project_id = ? AND e.status = 'confirmed'
    ");
    $stmt->execute([$pid]);
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
      // No confirmed expenses — mark settled with zero transactions
      $pdo->prepare("UPDATE projects SET status = 'settled' WHERE project_id = ?")->execute([$pid]);
      jsonSuccess(['transactions' => []], 'Project settled with no transactions needed.');
    }

    // Group rows by expense_id
    $expenseMap = [];
    foreach ($rows as $row) {
      $eid = $row['expense_id'];
      if (!isset($expenseMap[$eid])) {
        $expenseMap[$eid] = ['amount' => (float)$row['amount'], 'paid_by' => $row['paid_by'], 'participants' => []];
      }
      $expenseMap[$eid]['participants'][] = $row['participant_id'];
    }

    $paid = [];  // user_id => total paid
    $owed = [];  // user_id => total owed

    foreach ($expenseMap as $exp) {
      $payerId = $exp['paid_by'];
      $n       = count($exp['participants']);
      $share   = $exp['amount'] / $n;

      $paid[$payerId] = ($paid[$payerId] ?? 0.0) + $exp['amount'];

      foreach ($exp['participants'] as $participantId) {
        $owed[$participantId] = ($owed[$participantId] ?? 0.0) + $share;
      }
    }

    // Collect all user IDs
    $allUsers = array_unique(array_merge(array_keys($paid), array_keys($owed)));

    // ── Step 4: Net balance per user ──
    $balance = [];
    foreach ($allUsers as $u) {
      $balance[$u] = round(($paid[$u] ?? 0.0) - ($owed[$u] ?? 0.0), 2);
    }

    // ── Step 5: Separate creditors and debtors ──
    $creditors = [];
    $debtors   = [];
    foreach ($balance as $u => $b) {
      if ($b > 0.01)  $creditors[] = ['user_id' => $u, 'amount' => $b];
      if ($b < -0.01) $debtors[]   = ['user_id' => $u, 'amount' => $b];
    }

    // Sort: debtors ascending (most negative first), creditors descending
    usort($debtors,   fn($a, $b) => $a['amount'] <=> $b['amount']);
    usort($creditors, fn($a, $b) => $b['amount'] <=> $a['amount']);

    // ── Step 6: Greedy matching ──
    $transactions = [];
    $di = 0;
    $ci = 0;

    while ($di < count($debtors) && $ci < count($creditors)) {
      $debtor   = &$debtors[$di];
      $creditor = &$creditors[$ci];

      $payment = round(min(abs($debtor['amount']), $creditor['amount']), 2);

      if ($payment > 0.01) {
        $transactions[] = [
          'payer_id'    => $debtor['user_id'],
          'receiver_id' => $creditor['user_id'],
          'amount'      => $payment,
        ];
      }

      $debtor['amount']   = round($debtor['amount']   + $payment, 2);
      $creditor['amount'] = round($creditor['amount'] - $payment, 2);

      if (abs($debtor['amount'])   < 0.01) $di++;
      if (abs($creditor['amount']) < 0.01) $ci++;
    }

    // ── Step 7: Persist ──
    $pdo->beginTransaction();
    try {
      $stmtIns = $pdo->prepare(
        'INSERT INTO settlements (project_id, payer_id, receiver_id, amount) VALUES (?, ?, ?, ?)'
      );
      foreach ($transactions as $t) {
        $stmtIns->execute([$pid, $t['payer_id'], $t['receiver_id'], $t['amount']]);
      }

      $pdo->prepare("UPDATE projects SET status = 'settled' WHERE project_id = ?")->execute([$pid]);

      // Notify all project members
      $memberIds = getGroupMemberIds($pdo, $project['group_id']);
      $stmtN     = $pdo->prepare(
        'INSERT INTO notifications (user_id, type, reference_id, message) VALUES (?, ?, ?, ?)'
      );
      foreach ($memberIds as $memberId) {
        $stmtN->execute([
          $memberId,
          'settlement_ready',
          $pid,
          "Project \"{$project['project_name']}\" has been settled. Check your payment plan."
        ]);
      }

      $pdo->commit();
      jsonSuccess(['transactions' => $transactions, 'count' => count($transactions)], 'Settlement complete.');
    } catch (Exception $e) {
      $pdo->rollBack();
      error_log($e->getMessage());
      jsonError('Settlement failed.', 500);
    }

  /* ── Settlement Report ── */
  case 'report':
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
      SELECT s.*, up.display_name AS payer_name, ur.display_name AS receiver_name
      FROM settlements s
      JOIN users up ON up.user_id = s.payer_id
      JOIN users ur ON ur.user_id = s.receiver_id
      WHERE s.project_id = ?
      ORDER BY s.amount DESC
    ");
    $stmt->execute([$pid]);
    jsonSuccess($stmt->fetchAll());

  default:
    jsonError('Unknown action.', 404);
}

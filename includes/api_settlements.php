<?php
ini_set('display_errors', 1);   // show errors in browser
ini_set('log_errors', 1);       // enable logging

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
$pdo    = db();
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
    $settlements = $stmt->fetchAll();

    $stmt = $pdo->prepare("
      SELECT sp.*, fu.display_name AS from_name, tu.display_name AS to_name
      FROM settlement_payments sp
      JOIN users fu ON fu.user_id = sp.from_user_id
      JOIN users tu ON tu.user_id = sp.to_user_id
      WHERE sp.project_id = ?
      ORDER BY sp.created_at DESC
    ");
    $stmt->execute([$pid]);
    $paymentRequests = $stmt->fetchAll();

    jsonSuccess(['settlements' => $settlements, 'payment_requests' => $paymentRequests]);

  /* ── Request a partial payment (from user) ── */
  case 'payment_request':
    verifyCsrf();
    $pid  = postInt('project_id');
    $to   = postInt('to_user_id');
    $amt  = postFloat('amount');
    $note = postStr('note');

    if (!$pid || !$to || $amt <= 0) jsonError('project_id, to_user_id and amount are required.');

    $stmt = $pdo->prepare('SELECT group_id, status FROM projects WHERE project_id = ?');
    $stmt->execute([$pid]);
    $project = $stmt->fetch();
    if (!$project || $project['status'] !== 'open') jsonError('Project not found or not open for settlement.');

    // Check membership
    $stmt = $pdo->prepare('SELECT member_id FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$project['group_id'], $uid]);
    if (!$stmt->fetch()) jsonError('Access denied.', 403);

    if ($to === $uid) jsonError('Cannot request payment to yourself.');

    // verify target is member
    $stmt = $pdo->prepare('SELECT member_id FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$project['group_id'], $to]);
    if (!$stmt->fetch()) jsonError('Receiver is not a member of the project group.', 404);

    $stmt = $pdo->prepare('INSERT INTO settlement_payments (project_id, from_user_id, to_user_id, amount, note) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$pid, $uid, $to, $amt, $note]);

    jsonSuccess(['payment_id' => (int)$pdo->lastInsertId()], 'Partial payment request submitted.');

  /* ── Admin confirm/reject partial payment ── */
  case 'confirm_payment':
    verifyCsrf();
    $paymentId = postInt('payment_id');
    $action2   = postStr('action');
    if (!in_array($action2, ['confirm', 'reject'], true)) jsonError('Invalid action.');

    $stmt = $pdo->prepare('SELECT sp.*, p.group_id FROM settlement_payments sp JOIN projects p ON p.project_id = sp.project_id WHERE sp.payment_id = ?');
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch();
    if (!$payment) jsonError('Payment request not found.', 404);

    requireAdmin($payment['group_id'], $pdo);

    if ($payment['status'] !== 'pending') jsonError('Payment request is already processed.');

    $newStatus = $action2 === 'confirm' ? 'confirmed' : 'rejected';
    $stmt = $pdo->prepare('UPDATE settlement_payments SET status = ?, confirmed_by = ?, confirmed_at = NOW() WHERE payment_id = ?');
    $stmt->execute([$newStatus, $uid, $paymentId]);

    if ($newStatus === 'confirmed') {
      // keep existing history in settlement_payments; optionally create a final settlement record
      $stmt = $pdo->prepare('INSERT INTO settlements (project_id, payer_id, receiver_id, amount) VALUES (?, ?, ?, ?)');
      $stmt->execute([$payment['project_id'], $payment['from_user_id'], $payment['to_user_id'], $payment['amount']]);
    }

    jsonSuccess(null, 'Payment request ' . $newStatus . '.');

  default:
    jsonError('Unknown action.', 404);
}

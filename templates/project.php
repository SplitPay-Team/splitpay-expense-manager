<?php
require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireAuth();
$pdo = db();
$uid = currentUserId();
$pid = (int)($_GET['id'] ?? 0);

if (!$pid) { header('Location: /pages/dashboard.php'); exit; }

// Fetch project
$stmt = $pdo->prepare('SELECT p.*, g.group_name, g.group_id FROM projects p JOIN `groups` g ON g.group_id = p.group_id WHERE p.project_id = ?');
$stmt->execute([$pid]);
$project = $stmt->fetch();
if (!$project) { header('Location: /pages/dashboard.php'); exit; }

// Verify membership
$stmt = $pdo->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
$stmt->execute([$project['group_id'], $uid]);
$myMembership = $stmt->fetch();
if (!$myMembership) { header('Location: /pages/dashboard.php'); exit; }
$isAdmin = $myMembership['role'] === 'admin';

// Expenses
$stmt = $pdo->prepare("
  SELECT e.*, u.display_name AS payer_name,
         COUNT(ep.ep_id) AS participant_count,
         SUM(CASE WHEN ep.status='confirmed' THEN 1 ELSE 0 END) AS confirmed_count,
         SUM(CASE WHEN ep.status='pending'   THEN 1 ELSE 0 END) AS pending_count,
         MAX(CASE WHEN ep.user_id = :uid THEN ep.status ELSE NULL END) AS my_status
  FROM expenses e
  JOIN users u ON u.user_id = e.paid_by
  LEFT JOIN expense_participants ep ON ep.expense_id = e.expense_id
  WHERE e.project_id = :pid
  GROUP BY e.expense_id
  ORDER BY e.created_at DESC
");
$stmt->execute(['pid' => $pid, 'uid' => $uid]);
$expenses = $stmt->fetchAll();

$totalAmount    = array_sum(array_column($expenses, 'amount'));
$pendingCount   = count(array_filter($expenses, fn($e) => $e['pending_count'] > 0));
$confirmedCount = count(array_filter($expenses, fn($e) => $e['status'] === 'confirmed'));

// Group members for expense form
$stmt = $pdo->prepare("SELECT gm.user_id, u.display_name, u.username FROM group_members gm JOIN users u ON u.user_id = gm.user_id WHERE gm.group_id = ? ORDER BY u.display_name");
$stmt->execute([$project['group_id']]);
$groupMembers = $stmt->fetchAll();

// Settlement report if settled
$settlements = [];
if ($project['status'] === 'settled') {
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
}



// Other members (for payment request select)
$stmt = $pdo->prepare("SELECT u.user_id, u.display_name FROM group_members gm JOIN users u ON u.user_id = gm.user_id WHERE gm.group_id = ? AND u.user_id <> ? ORDER BY u.display_name");
$stmt->execute([$project['group_id'], $uid]);
$otherMembers = $stmt->fetchAll();

$stmtG = $pdo->prepare("SELECT g.group_id, g.group_name FROM `groups` g JOIN group_members gm ON gm.group_id = g.group_id WHERE gm.user_id = ?");
$stmtG->execute([$uid]);
$sidebarGroups = $stmtG->fetchAll();
$unreadCount   = 0;

$pageTitle  = htmlspecialchars($project['project_name'], ENT_QUOTES);
$activePage = 'group';
$breadcrumbs = [
  ['label' => 'Dashboard',              'url' => '/pages/dashboard.php'],
  ['label' => $project['group_name'],   'url' => '/pages/group.php?id=' . $project['group_id']],
  ['label' => $project['project_name'], 'url' => '']
];

include dirname(__DIR__) . '/templates/header.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><?= htmlspecialchars($project['project_name'], ENT_QUOTES) ?></h1>
    <div class="flex gap-8 mt-8">
      <span class="badge badge-<?= $project['status'] ?>"><?= ucfirst($project['status']) ?></span>
      <?php if ($project['event_date']): ?>
        <span style="font-size:12px;color:var(--text-muted);">📅 <?= date('M j, Y', strtotime($project['event_date'])) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <div class="flex gap-8">
    <?php if ($isAdmin && $project['status'] === 'open'): ?>
      <button class="btn btn-secondary" onclick="Modal.open('add-expense-modal')">+ Add Expense</button>
      <button class="btn btn-primary settle-btn" <?= $pendingCount > 0 ? 'disabled title="Pending expenses must be confirmed first"' : '' ?>>
        Settle Project
      </button>
    <?php endif; ?>
  </div>
</div>

<?php if ($pendingCount > 0 && $project['status'] === 'open'): ?>
  <div class="alert alert-warning">
    <span class="alert-icon">⚠</span>
    <span><strong><?= $pendingCount ?> expense<?= $pendingCount !== 1 ? 's' : '' ?></strong> still have pending confirmations. All must be resolved before settlement.</span>
  </div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid stagger" style="grid-template-columns:repeat(4,1fr);">
  <div class="stat-card">
    <div class="stat-label">Total Expenses</div>
    <div class="stat-value gold text-mono"><?= money($totalAmount) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Expense Count</div>
    <div class="stat-value"><?= count($expenses) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Pending</div>
    <div class="stat-value <?= $pendingCount > 0 ? 'danger' : '' ?>"><?= $pendingCount ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Confirmed</div>
    <div class="stat-value success"><?= $confirmedCount ?></div>
  </div>
</div>



<!-- Settlement Report -->
<?php if ($project['status'] === 'settled' && !empty($settlements)): ?>
  <div class="settlement-card animate-fadein">
    <h2>✦ Project Settled</h2>
    <p>All debts have been resolved. Here is the payment plan:</p>
  </div>
  <div class="mb-24">
    <?php foreach ($settlements as $s): ?>
      <div class="payment-row animate-fadein">
        <span class="payer"><?= htmlspecialchars($s['payer_name'], ENT_QUOTES) ?></span>
        <span class="arrow">→</span>
        <span class="receiver"><?= htmlspecialchars($s['receiver_name'], ENT_QUOTES) ?></span>
        <span class="amount"><?= money($s['amount']) ?></span>
      </div>
    <?php endforeach; ?>
    <?php if (empty($settlements)): ?>
      <div class="alert alert-success"><span class="alert-icon">✓</span><span>All balances are zero — no payments needed!</span></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- Expenses Table -->
<div class="card">
  <div class="card-header">
    <h3 class="card-title">Expenses</h3>
    <?php if ($isAdmin && $project['status'] === 'open'): ?>
      <button class="btn btn-secondary btn-sm" onclick="Modal.open('add-expense-modal')">+ Add Expense</button>
    <?php endif; ?>
  </div>

  <?php if (empty($expenses)): ?>
    <div class="empty-state">
      <div class="empty-icon">💸</div>
      <h3>No expenses yet</h3>
      <?php if ($isAdmin): ?>
        <p>Add the first expense to get started.</p>
        <button class="btn btn-primary" onclick="Modal.open('add-expense-modal')">Add Expense</button>
      <?php else: ?>
        <p>The admin hasn't added any expenses yet.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap" style="border:none;border-radius:0;">
      <table>
        <thead>
          <tr>
            <th>Description</th>
            <th>Paid By</th>
            <th>Amount</th>
            <th>Participants</th>
            <th>Your Status</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($expenses as $exp): ?>
            <tr>
              <td><span class="primary-text"><?= htmlspecialchars($exp['description'], ENT_QUOTES) ?></span></td>
              <td><?= htmlspecialchars($exp['payer_name'], ENT_QUOTES) ?></td>
              <td><span class="mono text-gold"><?= money($exp['amount']) ?></span></td>
              <td><?= (int)$exp['participant_count'] ?> members</td>
              <td>
                <?php if ($exp['my_status']): ?>
                  <span class="badge badge-<?= $exp['my_status'] ?>"><?= ucfirst($exp['my_status']) ?></span>
                <?php else: ?>
                  <span style="color:var(--text-muted);font-size:12px;">N/A</span>
                <?php endif; ?>
              </td>
              <td><span class="badge badge-<?= $exp['status'] ?>"><?= ucfirst($exp['status']) ?></span></td>
              <td>
                <a href="/pages/expense-detail.php?id=<?= (int)$exp['expense_id'] ?>" class="btn btn-ghost btn-sm">View →</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Add Expense Modal -->
<?php if ($isAdmin && $project['status'] === 'open'): ?>
<div class="modal-overlay" id="add-expense-modal">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title">Add Expense</h3>
      <button class="modal-close" onclick="Modal.close('add-expense-modal')">✕</button>
    </div>
    <div class="modal-body">
      <form id="add-expense-form">
        <div class="form-group">
          <label class="form-label">Description <span class="required">*</span></label>
          <input type="text" name="description" class="form-control" placeholder="e.g. Dinner at Cinnamon Grand" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Amount <span class="required">*</span></label>
            <input type="number" name="amount" class="form-control" placeholder="0.00" step="0.01" min="0.01" required>
          </div>
          <div class="form-group">
            <label class="form-label">Paid By <span class="required">*</span></label>
            <select name="paid_by" class="form-control" required>
              <option value="">Select payer…</option>
              <?php foreach ($groupMembers as $m): ?>
                <option value="<?= (int)$m['user_id'] ?>" <?= $m['user_id'] == $uid ? 'selected' : '' ?>>
                  <?= htmlspecialchars($m['display_name'], ENT_QUOTES) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Participants <span class="required">*</span></label>
          <div class="checkbox-group">
            <?php foreach ($groupMembers as $m): ?>
              <div class="checkbox-item">
                <input type="checkbox" name="participants[]" id="p_<?= $m['user_id'] ?>"
                       value="<?= (int)$m['user_id'] ?>" checked>
                <label for="p_<?= $m['user_id'] ?>"><?= htmlspecialchars($m['display_name'], ENT_QUOTES) ?></label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="Modal.close('add-expense-modal')">Cancel</button>
      <button class="btn btn-primary" id="add-expense-btn">Add Expense</button>
    </div>
  </div>
</div>

<script>
const PROJECT_ID = <?= $pid ?>;

document.getElementById('add-expense-btn').addEventListener('click', async function() {
  const form = document.getElementById('add-expense-form');
  const data = Form.serialize(form);
  data.project_id = PROJECT_ID;

  if (!data.description || !data.amount || !data.paid_by) {
    Toast.warning('Please fill in all required fields.');
    return;
  }
  if (!data.participants || data.participants.length === 0) {
    Toast.warning('Select at least one participant.');
    return;
  }

  Form.setLoading(this, true);
  try {
    const res = await API.post('expenses', 'add', data);
    if (res.success) {
      Toast.success('Expense added.');
      setTimeout(() => location.reload(), 700);
    } else {
      Toast.error(res.error || 'Failed to add expense.');
      Form.setLoading(this, false);
    }
  } catch(e) { Toast.error('Connection error.'); Form.setLoading(this, false); }
});

// Settle button
document.querySelector('.settle-btn')?.addEventListener('click', async function() {
  if (!confirm('Settle this project? This action cannot be undone.')) return;
  Form.setLoading(this, true);
  try {
    const res = await API.post('settlements', 'settle', { project_id: PROJECT_ID });
    if (res.success) {
      Toast.success('Project settled successfully!');
      setTimeout(() => location.reload(), 800);
    } else {
      Toast.error(res.error || 'Settlement failed.');
      Form.setLoading(this, false);
    }
  } catch(e) { Toast.error('Connection error.'); Form.setLoading(this, false); }
});

// Partial settlement request
const requestBtn = document.getElementById('request-payment-btn');
if (requestBtn) {
  requestBtn.addEventListener('click', async function() {
    const to = parseInt(document.getElementById('settle-to').value, 10);
    const amount = parseFloat(document.getElementById('settle-amount').value);
    const note = document.getElementById('settle-note').value.trim();

    if (!to || isNaN(amount) || amount <= 0) {
      Toast.warning('Please choose a member and enter a positive amount.');
      return;
    }

    Form.setLoading(this, true);
    try {
      const res = await API.post('settlements', 'payment_request', {
        project_id: PROJECT_ID,
        to_user_id: to,
        amount: amount,
        note: note
      });

      if (res.success) {
        Toast.success('Payment request sent.');
        setTimeout(() => location.reload(), 700);
      } else {
        Toast.error(res.error || 'Payment request failed.');
        Form.setLoading(this, false);
      }
    } catch(e) {
      Toast.error('Connection error.');
      Form.setLoading(this, false);
    }
  });
}

// Admin confirm/reject pending partial payment requests
document.querySelectorAll('.confirm-payment-btn').forEach(btn => {
  btn.addEventListener('click', async function() {
    const paymentId = this.dataset.id;
    const action = this.dataset.action;
    Form.setLoading(this, true);

    try {
      const res = await API.post('settlements', 'confirm_payment', {
        payment_id: paymentId,
        action: action
      });
      if (res.success) {
        Toast.success('Request ' + action + 'ed.');
        setTimeout(() => location.reload(), 700);
      } else {
        Toast.error(res.error || 'Action failed.');
        Form.setLoading(this, false);
      }
    } catch(e) {
      Toast.error('Connection error.');
      Form.setLoading(this, false);
    }
  });
});
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>

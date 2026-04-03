<?php
ini_set('display_errors', 1);   // show errors in browser
ini_set('log_errors', 1);       // enable logging
require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireAuth();
$pdo = db();
$uid = currentUserId();

// Fetch user groups with stats
$stmt = $pdo->prepare("
  SELECT g.group_id, g.group_name, g.description,
         COUNT(DISTINCT gm2.user_id) AS member_count,
         COUNT(DISTINCT p.project_id) AS project_count,
         gm.role
  FROM `groups` g
  JOIN group_members gm  ON gm.group_id = g.group_id AND gm.user_id = :uid
  LEFT JOIN group_members gm2 ON gm2.group_id = g.group_id
  LEFT JOIN projects p        ON p.group_id = g.group_id
  GROUP BY g.group_id, gm.role
  ORDER BY g.created_at DESC
");
$stmt->execute(['uid' => $uid]);
$groups = $stmt->fetchAll();

// Sidebar groups
$sidebarGroups = $groups;

// Recent notifications (5)
$stmtN = $pdo->prepare("
  SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5
");
$stmtN->execute([$uid]);
$recentNotifs = $stmtN->fetchAll();
$unreadCount  = count(array_filter($recentNotifs, fn($n) => !$n['is_read']));

// Pending expense confirmations for me
$stmtP = $pdo->prepare("
  SELECT ep.ep_id, e.description, e.amount, p.project_name, g.group_name,
         e.expense_id, p.project_id
  FROM expense_participants ep
  JOIN expenses  e ON e.expense_id  = ep.expense_id
  JOIN projects  p ON p.project_id  = e.project_id
  JOIN `groups`    g ON g.group_id    = p.group_id
  WHERE ep.user_id = ? AND ep.status = 'pending'
  ORDER BY e.created_at DESC
  LIMIT 10
");
$stmtP->execute([$uid]);
$pendingExpenses = $stmtP->fetchAll();

// User's expense participation
$stmtUP = $pdo->prepare("
  SELECT ep.ep_id, e.description, e.amount,
         e.status AS expense_status,
         p.project_name, g.group_name,
         u.display_name AS payer_name,
         (SELECT COUNT(*) FROM expense_participants ep2 WHERE ep2.expense_id = e.expense_id) AS participant_count
  FROM expense_participants ep
  JOIN expenses e ON e.expense_id = ep.expense_id
  JOIN projects p ON p.project_id = e.project_id
  JOIN `groups` g ON g.group_id = p.group_id
  JOIN users u ON u.user_id = e.paid_by
  WHERE ep.user_id = ?
    AND p.status <> 'settled'
  ORDER BY e.created_at DESC
  LIMIT 8
");
$stmtUP->execute([$uid]);
$userParticipations = $stmtUP->fetchAll();

// Compute total share amount for recent participations
$totalParticipationShares = array_reduce($userParticipations, function($carry, $up) {
  $count = max(1, (int) $up['participant_count']);
  $share = $up['amount'] / $count;
  return $carry + $share;
}, 0.0);

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
$breadcrumbs = [['label' => 'Dashboard']];

include dirname(__DIR__) . '/templates/header.php';
?>

<!-- Stats -->
<div class="stats-grid stagger">
  <div class="stat-card">
    <div class="stat-label">My Groups</div>
    <div class="stat-value gold"><?= count($groups) ?></div>
    <div class="stat-sub">Active memberships</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Pending Actions</div>
    <div class="stat-value <?= count($pendingExpenses) > 0 ? 'danger' : '' ?>"><?= count($pendingExpenses) ?></div>
    <div class="stat-sub">Expenses awaiting your confirmation</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Notifications</div>
    <div class="stat-value"><?= $unreadCount ?></div>
    <div class="stat-sub">Unread messages</div>
  </div>
</div>

<?php if (!empty($userParticipations)): ?>
  <div class="card mt-16">
    <div class="card-header"><h3 class="card-title">Your Recent Expense Participation</h3></div>
    <div class="table-wrap" style="border:none;border-radius:0;">
      <table>
        <thead>
          <tr>
            <th>Description</th>
            <th>Project</th>
            <th>Group</th>
            <th>Payer</th>
            <th>Share</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($userParticipations as $up): ?>
            <tr>
              <td><?= htmlspecialchars($up['description'], ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($up['project_name'], ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($up['group_name'], ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($up['payer_name'], ENT_QUOTES) ?></td>
              <td><?= money($up['amount'] / max(1, $up['participant_count'])) ?></td>
              <td><span class="badge badge-<?= $up['expense_status'] ?>"><?= ucfirst($up['expense_status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer" style="color: #a8a8a8; font-size: 17px; text-align: center; padding: 10px 0px; background: #161616; border-top: 1px solid #a0a0a0;">
      Total share amount : <strong><?= money($totalParticipationShares) ?></strong>
    </div>
  </div>
<?php endif; ?>

<?php if (!empty($pendingExpenses)): ?>
<div class="alert alert-warning animate-fadein">
  <span class="alert-icon">⚠</span>
  <span>You have <strong><?= count($pendingExpenses) ?></strong> expense<?= count($pendingExpenses) !== 1 ? 's' : '' ?> awaiting your confirmation.</span>
</div>
<?php endif; ?>

<div class="grid-2" style="gap:24px; align-items: start;">

  <!-- Groups -->
  <div><br>
    <div class="flex-between mb-16">
      <h2 class="section-title" style="margin-bottom:0;">My Groups</h2>
      <a href="/pages/group-create.php" class="btn btn-secondary btn-sm">+ New Group</a>
    </div>

    <?php if (empty($groups)): ?>
      <div class="empty-state" style="padding:40px 20px;">
        <div class="empty-icon">◈</div>
        <h3>No groups yet</h3>
        <p>Create a group to start tracking shared expenses with others.</p>
        <a href="/pages/group-create.php" class="btn btn-primary">Create Group</a>
      </div>
    <?php else: ?>
      <div class="stagger" style="display:flex;flex-direction:column;gap:12px;">
        <?php foreach ($groups as $g): ?>
          <a href="/pages/group.php?id=<?= (int)$g['group_id'] ?>" style="text-decoration:none;">
            <div class="group-card">
              <div class="flex-between">
                <div>
                  <div class="group-card-name"><?= htmlspecialchars($g['group_name'], ENT_QUOTES) ?></div>
                  <?php if ($g['description']): ?>
                    <div class="group-card-meta"><?= htmlspecialchars(mb_strimwidth($g['description'], 0, 60, '…'), ENT_QUOTES) ?></div>
                  <?php endif; ?>
                </div>
                <span class="badge badge-<?= $g['role'] ?>"><?= ucfirst($g['role']) ?></span>
              </div>
              <div class="group-card-stats">
                <div class="group-card-stat">
                  <strong><?= (int)$g['member_count'] ?></strong>
                  Members
                </div>
                <div class="group-card-stat">
                  <strong><?= (int)$g['project_count'] ?></strong>
                  Projects
                </div>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Pending Confirmations -->
  <div><br><br>
    <div class="flex-between mb-16">
      <h2 class="section-title" style="margin-bottom:0;">Pending Confirmations</h2>
    </div>

    <?php if (empty($pendingExpenses)): ?>
      <div class="empty-state" style="padding:40px 20px;">
        <div class="empty-icon">✓</div>
        <h3>All caught up!</h3>
        <p>No expenses are waiting for your confirmation.</p>
      </div>
    <?php else: ?>
      <div class="stagger" style="display:flex;flex-direction:column;gap:10px;">
        <?php foreach ($pendingExpenses as $exp): ?>
          <div class="card">
            <div class="card-body" style="padding:16px 18px;">
              <div class="flex-between" style="margin-bottom:8px;">
                <div>
                  <div style="font-weight:600;color:var(--text);font-size:14px;"><?= htmlspecialchars($exp['description'], ENT_QUOTES) ?></div>
                  <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
                    <?= htmlspecialchars($exp['group_name'], ENT_QUOTES) ?> › <?= htmlspecialchars($exp['project_name'], ENT_QUOTES) ?>
                  </div>
                </div>
                <span style="font-family:var(--font-mono);color:var(--gold);font-size:15px;"><?= money($exp['amount']) ?></span>
              </div>
              <div class="flex gap-8">
                <button class="btn btn-secondary btn-sm confirm-btn" data-ep="<?= (int)$exp['ep_id'] ?>" data-action="confirm">✓ Confirm</button>
                <button class="btn btn-danger btn-sm confirm-btn"    data-ep="<?= (int)$exp['ep_id'] ?>" data-action="reject">✕ Reject</button>
                <a href="/pages/expense-detail.php?id=<?= (int)$exp['expense_id'] ?>" class="btn btn-ghost btn-sm">View</a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- Recent Notifications -->
<?php if (!empty($recentNotifs)): ?>
<div class="mt-24">
  <div class="flex-between mb-16">
    <h2 class="section-title" style="margin-bottom:0;">Recent Notifications</h2>
    <a href="/pages/notifications.php" class="btn btn-ghost btn-sm">View All →</a>
  </div>
  <div class="card">
    <div class="card-body" style="padding:12px;">
      <div class="notif-list">
        <?php foreach ($recentNotifs as $n): ?>
          <div class="notif-item <?= !$n['is_read'] ? 'unread' : '' ?>">
            <div class="notif-icon">
              <?= $n['type'] === 'expense_added' ? '💰' : ($n['type'] === 'settlement_ready' ? '🎉' : '🔔') ?>
            </div>
            <div class="notif-content">
              <div class="notif-msg"><?= htmlspecialchars($n['message'], ENT_QUOTES) ?></div>
              <div class="notif-time"><?= date('M j, Y g:i A', strtotime($n['created_at'])) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.confirm-btn').forEach(btn => {
  btn.addEventListener('click', async function() {
    const epId   = this.dataset.ep;
    const action = this.dataset.action;
    Form.setLoading(this, true);
    try {
      const res = await API.post('expenses', 'confirm', { ep_id: epId, action });
      if (res.success) {
        Toast.success(action === 'confirm' ? 'Expense confirmed.' : 'Expense rejected.');
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

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>

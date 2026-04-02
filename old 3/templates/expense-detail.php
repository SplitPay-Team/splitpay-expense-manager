<?php
require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireAuth();
$pdo = DB::connect();
$uid = currentUserId();
$eid = (int)($_GET['id'] ?? 0);

if (!$eid) { header('Location: /pages/dashboard.php'); exit; }

// Fetch expense
$stmt = $pdo->prepare("
  SELECT e.*, p.project_name, p.group_id, p.project_id, g.group_name,
         u.display_name AS payer_name, uc.display_name AS created_by_name
  FROM expenses e
  JOIN projects p ON p.project_id = e.project_id
  JOIN groups   g ON g.group_id   = p.group_id
  JOIN users    u ON u.user_id    = e.paid_by
  JOIN users   uc ON uc.user_id   = e.created_by
  WHERE e.expense_id = ?
");
$stmt->execute([$eid]);
$expense = $stmt->fetch();
if (!$expense) { header('Location: /pages/dashboard.php'); exit; }

// Verify membership
$stmt = $pdo->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
$stmt->execute([$expense['group_id'], $uid]);
$myMembership = $stmt->fetch();
if (!$myMembership) { header('Location: /pages/dashboard.php'); exit; }
$isAdmin = $myMembership['role'] === 'admin';

// Participants
$stmt = $pdo->prepare("
  SELECT ep.*, u.display_name, u.username
  FROM expense_participants ep
  JOIN users u ON u.user_id = ep.user_id
  WHERE ep.expense_id = ?
  ORDER BY u.display_name
");
$stmt->execute([$eid]);
$participants = $stmt->fetchAll();

$myParticipation = null;
foreach ($participants as $p) {
  if ($p['user_id'] == $uid) { $myParticipation = $p; break; }
}

$sharePerPerson  = count($participants) > 0 ? $expense['amount'] / count($participants) : 0;
$allConfirmed    = !array_filter($participants, fn($p) => $p['status'] === 'pending');

$stmtG = $pdo->prepare("SELECT g.group_id, g.group_name FROM groups g JOIN group_members gm ON gm.group_id = g.group_id WHERE gm.user_id = ?");
$stmtG->execute([$uid]);
$sidebarGroups = $stmtG->fetchAll();
$unreadCount   = 0;

$pageTitle  = htmlspecialchars($expense['description'], ENT_QUOTES);
$activePage = 'group';
$breadcrumbs = [
  ['label' => 'Dashboard',                    'url' => '/pages/dashboard.php'],
  ['label' => $expense['group_name'],         'url' => '/pages/group.php?id='   . $expense['group_id']],
  ['label' => $expense['project_name'],       'url' => '/pages/project.php?id=' . $expense['project_id']],
  ['label' => 'Expense',                      'url' => '']
];

include dirname(__DIR__) . '/templates/header.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><?= htmlspecialchars($expense['description'], ENT_QUOTES) ?></h1>
    <div class="flex gap-8 mt-8">
      <span class="badge badge-<?= $expense['status'] ?>"><?= ucfirst($expense['status']) ?></span>
    </div>
  </div>
  <?php if ($isAdmin && $expense['status'] === 'pending'): ?>
    <a href="/pages/add-expense.php?edit=<?= $eid ?>" class="btn btn-secondary">Edit Expense</a>
  <?php endif; ?>
</div>

<!-- Expense Meta -->
<div class="grid-2" style="gap:20px;margin-bottom:28px;">
  <div class="card">
    <div class="card-body">
      <div class="section-title">Expense Details</div>
      <div style="display:flex;flex-direction:column;gap:12px;margin-top:8px;">
        <div class="flex-between">
          <span style="color:var(--text-muted);font-size:13px;">Amount</span>
          <span style="font-family:var(--font-mono);font-size:22px;color:var(--gold);font-weight:500;"><?= money($expense['amount']) ?></span>
        </div>
        <div class="flex-between">
          <span style="color:var(--text-muted);font-size:13px;">Paid by</span>
          <span style="font-weight:600;color:var(--text);"><?= htmlspecialchars($expense['payer_name'], ENT_QUOTES) ?></span>
        </div>
        <div class="flex-between">
          <span style="color:var(--text-muted);font-size:13px;">Share per person</span>
          <span style="font-family:var(--font-mono);color:var(--text-soft);"><?= money($sharePerPerson) ?></span>
        </div>
        <div class="flex-between">
          <span style="color:var(--text-muted);font-size:13px;">Participants</span>
          <span><?= count($participants) ?> people</span>
        </div>
        <div class="flex-between">
          <span style="color:var(--text-muted);font-size:13px;">Recorded by</span>
          <span style="font-size:13px;color:var(--text-soft);"><?= htmlspecialchars($expense['created_by_name'], ENT_QUOTES) ?></span>
        </div>
        <div class="flex-between">
          <span style="color:var(--text-muted);font-size:13px;">Date</span>
          <span style="font-size:13px;"><?= date('M j, Y g:i A', strtotime($expense['created_at'])) ?></span>
        </div>
      </div>
    </div>
  </div>

  <!-- Your Action -->
  <div>
    <?php if ($myParticipation && $myParticipation['status'] === 'pending'): ?>
      <div class="card" style="border-color:rgba(201,168,76,0.3);background:rgba(201,168,76,0.04);">
        <div class="card-body">
          <div class="section-title text-gold">Action Required</div>
          <p style="font-size:14px;color:var(--text-soft);margin:10px 0 18px;">
            You owe <strong style="color:var(--gold);font-family:var(--font-mono);"><?= money($sharePerPerson) ?></strong>.
            Please confirm or dispute your participation in this expense.
          </p>
          <div class="flex gap-8">
            <button class="btn btn-primary" id="confirm-btn" data-ep="<?= (int)$myParticipation['ep_id'] ?>" data-action="confirm">
              ✓ Confirm Participation
            </button>
            <button class="btn btn-danger" id="reject-btn" data-ep="<?= (int)$myParticipation['ep_id'] ?>" data-action="reject">
              ✕ Reject
            </button>
          </div>
        </div>
      </div>
    <?php elseif ($myParticipation): ?>
      <div class="card">
        <div class="card-body">
          <div class="section-title">Your Response</div>
          <div class="flex-center" style="padding:20px 0;gap:12px;">
            <span class="badge badge-<?= $myParticipation['status'] ?>" style="font-size:13px;padding:6px 14px;">
              <?= ucfirst($myParticipation['status']) ?>
            </span>
            <?php if ($myParticipation['responded_at']): ?>
              <span style="font-size:12px;color:var(--text-muted);">
                <?= date('M j, Y g:i A', strtotime($myParticipation['responded_at'])) ?>
              </span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="card-body text-center" style="padding:30px;">
          <p style="color:var(--text-muted);font-size:14px;">You are not a participant in this expense.</p>
        </div>
      </div>
    <?php endif; ?>

    <!-- Progress -->
    <div class="card mt-16">
      <div class="card-body">
        <div class="section-title">Confirmation Progress</div>
        <?php
          $totalP    = count($participants);
          $confirmedP= count(array_filter($participants, fn($p) => $p['status'] === 'confirmed'));
          $rejectedP = count(array_filter($participants, fn($p) => $p['status'] === 'rejected'));
          $pendingP  = $totalP - $confirmedP - $rejectedP;
          $pct       = $totalP > 0 ? round($confirmedP / $totalP * 100) : 0;
        ?>
        <div style="margin:12px 0 6px;background:var(--surface2);height:6px;border-radius:3px;overflow:hidden;">
          <div style="height:100%;width:<?= $pct ?>%;background:linear-gradient(90deg,var(--gold-dim),var(--gold));border-radius:3px;transition:width 0.5s ease;"></div>
        </div>
        <div style="font-size:12px;color:var(--text-muted);">
          <?= $confirmedP ?> confirmed · <?= $pendingP ?> pending · <?= $rejectedP ?> rejected
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Participants -->
<div class="card">
  <div class="card-header">
    <h3 class="card-title">Participant Status</h3>
  </div>
  <div class="card-body" style="padding:16px;">
    <div class="participant-status-list">
      <?php foreach ($participants as $p): ?>
        <div class="participant-status-item">
          <div class="flex gap-12" style="align-items:center;">
            <div class="member-avatar" style="width:30px;height:30px;font-size:11px;"><?= htmlspecialchars(initials($p['display_name']), ENT_QUOTES) ?></div>
            <div>
              <div class="name"><?= htmlspecialchars($p['display_name'], ENT_QUOTES) ?></div>
              <div style="font-family:var(--font-mono);font-size:11px;color:var(--text-muted);"><?= money($sharePerPerson) ?></div>
            </div>
          </div>
          <div class="actions">
            <span class="badge badge-<?= $p['status'] ?>"><?= ucfirst($p['status']) ?></span>
            <?php if ($p['responded_at']): ?>
              <span style="font-size:11px;color:var(--text-muted);"><?= date('M j', strtotime($p['responded_at'])) ?></span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if ($myParticipation && $myParticipation['status'] === 'pending'): ?>
<script>
document.querySelectorAll('#confirm-btn, #reject-btn').forEach(btn => {
  btn.addEventListener('click', async function() {
    const action = this.dataset.action;
    const epId   = this.dataset.ep;
    if (action === 'reject' && !confirm('Are you sure you want to reject this expense?')) return;
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
    } catch(e) { Toast.error('Connection error.'); Form.setLoading(this, false); }
  });
});
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>

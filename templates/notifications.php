<?php
ini_set('display_errors', 1);   // show errors in browser
ini_set('log_errors', 1);       // enable logging

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireAuth();
$pdo = db();
$uid = currentUserId();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$stmt->execute([$uid]);
$total = (int)$stmt->fetchColumn();
$pages = (int)ceil($total / $perPage);

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->execute([$uid, $perPage, $offset]);
$notifications = $stmt->fetchAll();

$unreadCount = count(array_filter($notifications, fn($n) => !$n['is_read']));

$stmtG = $pdo->prepare("SELECT g.group_id, g.group_name FROM `groups` g JOIN group_members gm ON gm.group_id = g.group_id WHERE gm.user_id = ?");
$stmtG->execute([$uid]);
$sidebarGroups = $stmtG->fetchAll();

$pageTitle  = 'Notifications';
$activePage = 'notifications';
$breadcrumbs = [['label' => 'Notifications']];

include dirname(__DIR__) . '/templates/header.php';

$typeIcons = [
  'expense_added'     => '💰',
  'expense_confirmed' => '✓',
  'expense_rejected'  => '✕',
  'settlement_ready'  => '🎉',
];
?>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title">Notifications</h1>
    <p class="page-subtitle"><?= $total ?> total · <?= $unreadCount ?> unread</p>
  </div>
  <?php if ($unreadCount > 0): ?>
    <button class="btn btn-secondary" id="mark-all-read">Mark all as read</button>
  <?php endif; ?>
</div>

<?php if (empty($notifications)): ?>
  <div class="empty-state">
    <div class="empty-icon">🔔</div>
    <h3>No notifications</h3>
    <p>You're all caught up. Notifications will appear here when there's activity in your groups.</p>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-body" style="padding:12px;">
      <div class="notif-list stagger">
        <?php foreach ($notifications as $n): ?>
          <div class="notif-item <?= !$n['is_read'] ? 'unread' : '' ?>"
               data-id="<?= (int)$n['notification_id'] ?>"
               onclick="markRead(<?= (int)$n['notification_id'] ?>, this)">
            <div class="notif-icon">
              <?= $typeIcons[$n['type']] ?? '🔔' ?>
            </div>
            <div class="notif-content">
              <div class="notif-msg"><?= htmlspecialchars($n['message'], ENT_QUOTES) ?></div>
              <div class="notif-time"><?= date('M j, Y · g:i A', strtotime($n['created_at'])) ?></div>
            </div>
            <?php if (!$n['is_read']): ?>
              <div style="width:8px;height:8px;border-radius:50%;background:var(--gold);flex-shrink:0;"></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Pagination -->
  <?php if ($pages > 1): ?>
    <div class="flex-center gap-8 mt-24">
      <?php if ($page > 1): ?>
        <a href="?page=<?= $page - 1 ?>" class="btn btn-secondary btn-sm">← Prev</a>
      <?php endif; ?>
      <span style="font-size:13px;color:var(--text-muted);">Page <?= $page ?> of <?= $pages ?></span>
      <?php if ($page < $pages): ?>
        <a href="?page=<?= $page + 1 ?>" class="btn btn-secondary btn-sm">Next →</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<script>
async function markRead(id, el) {
  if (!el.classList.contains('unread')) return;
  try {
    await API.post('notifications', 'mark_read', { notification_id: id });
    el.classList.remove('unread');
    const dot = el.querySelector('[style*="background:var(--gold)"]');
    if (dot) dot.remove();
  } catch(e) {}
}

document.getElementById('mark-all-read')?.addEventListener('click', async function() {
  Form.setLoading(this, true);
  try {
    await API.post('notifications', 'mark_read', { all: 1 });
    document.querySelectorAll('.notif-item.unread').forEach(el => {
      el.classList.remove('unread');
      const dot = el.querySelector('[style*="background:var(--gold)"]');
      if (dot) dot.remove();
    });
    this.remove();
    Toast.success('All notifications marked as read.');
  } catch(e) { Toast.error('Failed.'); Form.setLoading(this, false); }
});
</script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>

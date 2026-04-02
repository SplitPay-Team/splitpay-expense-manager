<?php
require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireAuth();
$pdo = DB::connect();
$uid = currentUserId();
$gid = (int)($_GET['id'] ?? 0);

if (!$gid) { header('Location: /pages/dashboard.php'); exit; }

// Verify membership
$stmt = $pdo->prepare('SELECT * FROM group_members WHERE group_id = ? AND user_id = ?');
$stmt->execute([$gid, $uid]);
$myMembership = $stmt->fetch();
if (!$myMembership) { header('Location: /pages/dashboard.php'); exit; }
$isAdmin = $myMembership['role'] === 'admin';

// Group info
$stmt = $pdo->prepare('SELECT * FROM groups WHERE group_id = ?');
$stmt->execute([$gid]);
$group = $stmt->fetch();
if (!$group) { header('Location: /pages/dashboard.php'); exit; }

// Members
$stmt = $pdo->prepare("
  SELECT gm.*, u.display_name, u.username, u.email
  FROM group_members gm
  JOIN users u ON u.user_id = gm.user_id
  WHERE gm.group_id = ?
  ORDER BY gm.role DESC, u.display_name ASC
");
$stmt->execute([$gid]);
$members = $stmt->fetchAll();

// Projects
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
$projects = $stmt->fetchAll();

// Sidebar groups
$stmtG = $pdo->prepare("SELECT g.group_id, g.group_name FROM groups g JOIN group_members gm ON gm.group_id = g.group_id WHERE gm.user_id = ?");
$stmtG->execute([$uid]);
$sidebarGroups = $stmtG->fetchAll();

$unreadCount = 0;
$pageTitle   = htmlspecialchars($group['group_name'], ENT_QUOTES);
$activePage  = 'group';
$breadcrumbs = [
  ['label' => 'Dashboard',           'url' => '/pages/dashboard.php'],
  ['label' => $group['group_name'],  'url' => '']
];

include dirname(__DIR__) . '/templates/header.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><?= htmlspecialchars($group['group_name'], ENT_QUOTES) ?></h1>
    <?php if ($group['description']): ?>
      <p class="page-subtitle"><?= htmlspecialchars($group['description'], ENT_QUOTES) ?></p>
    <?php endif; ?>
  </div>
  <?php if ($isAdmin): ?>
    <div class="flex gap-8">
      <button class="btn btn-secondary" onclick="Modal.open('add-member-modal')">+ Add Member</button>
      <button class="btn btn-primary"   onclick="Modal.open('create-project-modal')">+ New Project</button>
    </div>
  <?php endif; ?>
</div>

<!-- Stats -->
<div class="stats-grid stagger" style="grid-template-columns: repeat(3, 1fr); margin-bottom:28px;">
  <div class="stat-card">
    <div class="stat-label">Members</div>
    <div class="stat-value gold"><?= count($members) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Projects</div>
    <div class="stat-value"><?= count($projects) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Your Role</div>
    <div class="stat-value" style="font-size:18px;text-transform:capitalize;"><?= $myMembership['role'] ?></div>
  </div>
</div>

<div class="grid-2" style="gap:24px;align-items:start;">

  <!-- Projects -->
  <div>
    <div class="flex-between mb-16">
      <h2 class="section-title" style="margin-bottom:0;">Projects</h2>
      <?php if ($isAdmin): ?>
        <button class="btn btn-secondary btn-sm" onclick="Modal.open('create-project-modal')">+ New</button>
      <?php endif; ?>
    </div>

    <?php if (empty($projects)): ?>
      <div class="empty-state" style="padding:40px 20px;">
        <div class="empty-icon">📋</div>
        <h3>No projects yet</h3>
        <?php if ($isAdmin): ?>
          <p>Create a project to start tracking expenses.</p>
          <button class="btn btn-primary" onclick="Modal.open('create-project-modal')">Create Project</button>
        <?php else: ?>
          <p>The group admin hasn't created any projects yet.</p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="stagger" style="display:flex;flex-direction:column;gap:12px;">
        <?php foreach ($projects as $p): ?>
          <a href="/pages/project.php?id=<?= (int)$p['project_id'] ?>" style="text-decoration:none;">
            <div class="project-card">
              <div class="flex-between" style="margin-bottom:10px;">
                <div style="font-weight:600;color:var(--text);font-size:15px;"><?= htmlspecialchars($p['project_name'], ENT_QUOTES) ?></div>
                <span class="badge badge-<?= $p['status'] ?>"><?= ucfirst($p['status']) ?></span>
              </div>
              <?php if ($p['description']): ?>
                <div style="font-size:13px;color:var(--text-muted);margin-bottom:10px;"><?= htmlspecialchars(mb_strimwidth($p['description'],0,80,'…'), ENT_QUOTES) ?></div>
              <?php endif; ?>
              <div class="flex gap-16" style="font-size:12px;color:var(--text-muted);border-top:1px solid var(--border);padding-top:10px;">
                <span><strong style="color:var(--text);font-family:var(--font-mono);"><?= (int)$p['expense_count'] ?></strong> expenses</span>
                <span><strong style="color:var(--gold);font-family:var(--font-mono);"><?= money($p['total_amount']) ?></strong> total</span>
                <?php if ($p['event_date']): ?>
                  <span>📅 <?= date('M j, Y', strtotime($p['event_date'])) ?></span>
                <?php endif; ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Members -->
  <div>
    <div class="flex-between mb-16">
      <h2 class="section-title" style="margin-bottom:0;">Members (<?= count($members) ?>)</h2>
      <?php if ($isAdmin): ?>
        <button class="btn btn-secondary btn-sm" onclick="Modal.open('add-member-modal')">+ Add</button>
      <?php endif; ?>
    </div>
    <div class="member-list stagger">
      <?php foreach ($members as $m): ?>
        <div class="member-item">
          <div class="member-avatar"><?= htmlspecialchars(initials($m['display_name']), ENT_QUOTES) ?></div>
          <div class="member-info">
            <div class="member-name">
              <?= htmlspecialchars($m['display_name'], ENT_QUOTES) ?>
              <?php if ($m['user_id'] == $uid): ?><span style="font-size:11px;color:var(--text-muted);"> (You)</span><?php endif; ?>
            </div>
            <div class="member-email">@<?= htmlspecialchars($m['username'], ENT_QUOTES) ?></div>
          </div>
          <div class="member-actions">
            <span class="badge badge-<?= $m['role'] ?>"><?= ucfirst($m['role']) ?></span>
            <?php if ($isAdmin && $m['user_id'] != $uid): ?>
              <button class="btn btn-danger btn-sm remove-btn" data-uid="<?= (int)$m['user_id'] ?>" data-name="<?= htmlspecialchars($m['display_name'], ENT_QUOTES) ?>">✕</button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<!-- Add Member Modal -->
<?php if ($isAdmin): ?>
<div class="modal-overlay" id="add-member-modal">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title">Add Member</h3>
      <button class="modal-close" onclick="Modal.close('add-member-modal')">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">Search by Username or Email</label>
        <input type="text" id="member-search" class="form-control" placeholder="Start typing…">
      </div>
      <div id="search-results" style="margin-top:8px;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="Modal.close('add-member-modal')">Cancel</button>
      <button class="btn btn-primary" id="add-member-btn" disabled>Add Selected</button>
    </div>
  </div>
</div>

<!-- Create Project Modal -->
<div class="modal-overlay" id="create-project-modal">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title">New Project</h3>
      <button class="modal-close" onclick="Modal.close('create-project-modal')">✕</button>
    </div>
    <div class="modal-body">
      <form id="create-project-form">
        <div class="form-group">
          <label class="form-label">Project Name <span class="required">*</span></label>
          <input type="text" name="project_name" class="form-control" placeholder="e.g. Beach Trip 2025" required>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" placeholder="Optional description…"></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Event Date</label>
          <input type="date" name="event_date" class="form-control">
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="Modal.close('create-project-modal')">Cancel</button>
      <button class="btn btn-primary" id="create-project-btn">Create Project</button>
    </div>
  </div>
</div>

<script>
const GROUP_ID = <?= $gid ?>;
let selectedUserId = null;

// Member search
let searchTimeout;
document.getElementById('member-search').addEventListener('input', function() {
  clearTimeout(searchTimeout);
  const q = this.value.trim();
  if (q.length < 2) { document.getElementById('search-results').innerHTML = ''; return; }
  searchTimeout = setTimeout(async () => {
    try {
      const res = await API.get('groups', 'search_users', { q, group_id: GROUP_ID });
      const el = document.getElementById('search-results');
      if (res.success && res.data.length) {
        el.innerHTML = res.data.map(u => `
          <div class="member-item" style="cursor:pointer;" onclick="selectUser(${u.user_id}, '${u.display_name}', this)">
            <div class="member-avatar">${u.display_name.charAt(0).toUpperCase()}</div>
            <div class="member-info">
              <div class="member-name">${u.display_name}</div>
              <div class="member-email">@${u.username} · ${u.email}</div>
            </div>
          </div>`).join('');
      } else {
        el.innerHTML = '<p style="font-size:13px;color:var(--text-muted);padding:8px 0;">No users found.</p>';
      }
    } catch(e) { }
  }, 300);
});

function selectUser(id, name, el) {
  selectedUserId = id;
  document.querySelectorAll('#search-results .member-item').forEach(i => i.style.background = '');
  el.style.background = 'rgba(201,168,76,0.08)';
  el.style.borderColor = 'var(--gold-dim)';
  document.getElementById('add-member-btn').disabled = false;
}

document.getElementById('add-member-btn').addEventListener('click', async function() {
  if (!selectedUserId) return;
  Form.setLoading(this, true);
  try {
    const res = await API.post('groups', 'add_member', { group_id: GROUP_ID, user_id: selectedUserId });
    if (res.success) {
      Toast.success('Member added successfully.');
      setTimeout(() => location.reload(), 700);
    } else {
      Toast.error(res.error || 'Failed to add member.');
      Form.setLoading(this, false);
    }
  } catch(e) { Toast.error('Connection error.'); Form.setLoading(this, false); }
});

// Create project
document.getElementById('create-project-btn').addEventListener('click', async function() {
  const form = document.getElementById('create-project-form');
  const data = Form.serialize(form);
  data.group_id = GROUP_ID;
  if (!data.project_name) { Toast.warning('Project name is required.'); return; }
  Form.setLoading(this, true);
  try {
    const res = await API.post('projects', 'create', data);
    if (res.success) {
      Toast.success('Project created!');
      setTimeout(() => window.location.href = '/pages/project.php?id=' + res.data.project_id, 700);
    } else {
      Toast.error(res.error || 'Failed to create project.');
      Form.setLoading(this, false);
    }
  } catch(e) { Toast.error('Connection error.'); Form.setLoading(this, false); }
});

// Remove member
document.querySelectorAll('.remove-btn').forEach(btn => {
  btn.addEventListener('click', async function() {
    const name = this.dataset.name;
    if (!confirm(`Remove ${name} from this group?`)) return;
    Form.setLoading(this, true);
    try {
      const res = await API.post('groups', 'remove_member', { group_id: GROUP_ID, user_id: this.dataset.uid });
      if (res.success) { Toast.success('Member removed.'); setTimeout(() => location.reload(), 700); }
      else { Toast.error(res.error || 'Failed.'); Form.setLoading(this, false); }
    } catch(e) { Toast.error('Connection error.'); Form.setLoading(this, false); }
  });
});
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>

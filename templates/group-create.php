<?php
ini_set('display_errors', 1);   // show errors in browser
ini_set('log_errors', 1);       // enable logging

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireAuth();
$pdo = db();
$uid = currentUserId();

// Sidebar groups
$stmtG = $pdo->prepare("SELECT g.group_id, g.group_name FROM `groups` g JOIN group_members gm ON gm.group_id = g.group_id WHERE gm.user_id = ?");
$stmtG->execute([$uid]);
$sidebarGroups = $stmtG->fetchAll();

$unreadCount = 0;
$pageTitle   = 'Create Group';
$activePage  = 'dashboard';
$breadcrumbs = [
  ['label' => 'Dashboard', 'url' => '/pages/dashboard.php'],
  ['label' => 'New Group', 'url' => ''],
];

include dirname(__DIR__) . '/templates/header.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title">Create a New Group</h1>
    <p class="page-subtitle">Set up a group to start tracking shared expenses with others.</p>
  </div>
</div>

<div style="max-width:560px;">
  <div class="card animate-fadein">
    <div class="card-body" style="padding:32px;">

      <div class="form-group">
        <label class="form-label" for="group_name">Group Name <span class="required">*</span></label>
        <input type="text" id="group_name" class="form-control" placeholder="e.g. Housemates, Road Trip Crew…" maxlength="100">
      </div>

      <div class="form-group">
        <label class="form-label" for="description">Description</label>
        <textarea id="description" class="form-control" rows="3" placeholder="Optional — describe what this group is for…" maxlength="500"></textarea>
      </div>

      <div class="flex gap-8" style="margin-top:24px;">
        <button class="btn btn-primary" id="create-btn">Create Group</button>
        <a href="/pages/dashboard.php" class="btn btn-secondary">Cancel</a>
      </div>

    </div>
  </div>
</div>

<script>
document.getElementById('create-btn').addEventListener('click', async function () {
  const name = document.getElementById('group_name').value.trim();
  const desc = document.getElementById('description').value.trim();

  if (!name) {
    Toast.warning('Group name is required.');
    document.getElementById('group_name').focus();
    return;
  }

  Form.setLoading(this, true);

  try {
    const res = await API.post('groups', 'create', {
      group_name:  name,
      description: desc,
    });

    if (res.success) {
      Toast.success('Group created!');
      setTimeout(() => {
        window.location.href = '/pages/group.php?id=' + res.data.group_id;
      }, 700);
    } else {
      Toast.error(res.error || 'Failed to create group.');
      Form.setLoading(this, false);
    }
  } catch (e) {
    Toast.error('Connection error. Please try again.');
    Form.setLoading(this, false);
  }
});

// Allow Enter key to submit
document.getElementById('group_name').addEventListener('keydown', function (e) {
  if (e.key === 'Enter') document.getElementById('create-btn').click();
});
</script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>

<?php
ini_set('display_errors', 1);   // show errors in browser
ini_set('log_errors', 1);       // enable logging

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireAuth();
$pdo = db();
$uid = currentUserId();

$stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = ?');
$stmt->execute([$uid]);
$user = $stmt->fetch();

$stmtG = $pdo->prepare("SELECT g.group_id, g.group_name FROM `groups` g JOIN group_members gm ON gm.group_id = g.group_id WHERE gm.user_id = ?");
$stmtG->execute([$uid]);
$sidebarGroups = $stmtG->fetchAll();
$unreadCount   = 0;

$pageTitle  = 'Profile';
$activePage = 'profile';
$breadcrumbs = [['label' => 'Profile']];

include dirname(__DIR__) . '/templates/header.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title">My Profile</h1>
    <p class="page-subtitle">Manage your account settings</p>
  </div>
</div>

<div class="grid-2" style="gap:24px;align-items:start;">

  <!-- Profile Info -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">Account Information</h3>
    </div>
    <div class="card-body">
      <!-- Avatar -->
      <div style="text-align:center;margin-bottom:24px;">
        <div style="width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--gold-dim),var(--border-hi));display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:600;color:var(--gold-light);margin:0 auto 12px;">
          <?= htmlspecialchars(initials($user['display_name']), ENT_QUOTES) ?>
        </div>
        <div style="font-size:16px;font-weight:600;color:var(--text);"><?= htmlspecialchars($user['display_name'], ENT_QUOTES) ?></div>
        <div style="font-size:13px;color:var(--text-muted);">@<?= htmlspecialchars($user['username'], ENT_QUOTES) ?></div>
      </div>

      <div id="alert-profile"></div>

      <form id="profile-form">
        <div class="form-group">
          <label class="form-label">Display Name <span class="required">*</span></label>
          <input type="text" name="display_name" class="form-control"
                 value="<?= htmlspecialchars($user['display_name'], ENT_QUOTES) ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Email</label>
          <input type="email" class="form-control" value="<?= htmlspecialchars($user['email'], ENT_QUOTES) ?>" disabled>
          <p class="form-hint">Email cannot be changed.</p>
        </div>
        <div class="form-group">
          <label class="form-label">Username</label>
          <input type="text" class="form-control" value="<?= htmlspecialchars($user['username'], ENT_QUOTES) ?>" disabled>
        </div>
        <button type="submit" class="btn btn-primary btn-full" id="save-profile-btn">Save Changes</button>
      </form>
    </div>
  </div>

  <!-- Change Password -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">Change Password</h3>
    </div>
    <div class="card-body">
      <div id="alert-password"></div>
      <form id="password-form">
        <div class="form-group">
          <label class="form-label">Current Password <span class="required">*</span></label>
          <input type="password" name="current_password" class="form-control" placeholder="••••••••" required>
        </div>
        <div class="form-group">
          <label class="form-label">New Password <span class="required">*</span></label>
          <input type="password" name="new_password" class="form-control" placeholder="Min 8 characters" required>
        </div>
        <div class="form-group">
          <label class="form-label">Confirm New Password <span class="required">*</span></label>
          <input type="password" name="confirm_password" class="form-control" placeholder="Repeat new password" required>
        </div>
        <button type="submit" class="btn btn-secondary btn-full" id="save-password-btn">Update Password</button>
      </form>
    </div>
  </div>

</div>

<script>
document.getElementById('profile-form').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn = document.getElementById('save-profile-btn');
  Form.setLoading(btn, true);
  const data = Form.serialize(this);
  try {
    const res = await API.post('auth', 'update_profile', data);
    if (res.success) {
      Toast.success('Profile updated.');
      document.getElementById('alert-profile').innerHTML =
        '<div class="alert alert-success"><span class="alert-icon">✓</span><span>Profile saved successfully.</span></div>';
    } else {
      Toast.error(res.error || 'Update failed.');
    }
  } catch(e) { Toast.error('Connection error.'); }
  Form.setLoading(btn, false);
});

document.getElementById('password-form').addEventListener('submit', async function(e) {
  e.preventDefault();
  const data = Form.serialize(this);
  const alertEl = document.getElementById('alert-password');
  alertEl.innerHTML = '';

  if (data.new_password !== data.confirm_password) {
    alertEl.innerHTML = '<div class="alert alert-danger"><span class="alert-icon">✕</span><span>Passwords do not match.</span></div>';
    return;
  }
  if (data.new_password.length < 8) {
    alertEl.innerHTML = '<div class="alert alert-danger"><span class="alert-icon">✕</span><span>Password must be at least 8 characters.</span></div>';
    return;
  }
  const btn = document.getElementById('save-password-btn');
  Form.setLoading(btn, true);
  try {
    const res = await API.post('auth', 'change_password', data);
    if (res.success) {
      Toast.success('Password updated.');
      this.reset();
      alertEl.innerHTML = '<div class="alert alert-success"><span class="alert-icon">✓</span><span>Password changed successfully.</span></div>';
    } else {
      alertEl.innerHTML = `<div class="alert alert-danger"><span class="alert-icon">✕</span><span>${res.error || 'Failed.'}</span></div>`;
    }
  } catch(e) { Toast.error('Connection error.'); }
  Form.setLoading(btn, false);
});
</script>

<?php include dirname(__DIR__) . '/templates/footer.php'; ?>

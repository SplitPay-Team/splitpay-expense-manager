<?php
ini_set('display_errors', 1);   // show errors in browser
ini_set('log_errors', 1);       // enable logging

/**
 * SplitPay — Shared Layout Header Template
 * Include this at the top of every authenticated page
 * Expects: $pageTitle, $activePage variables to be set
 */

// Defaults
$pageTitle  = $pageTitle  ?? 'Dashboard';
$activePage = $activePage ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?> — SplitPay</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>✦</text></svg>">
</head>
<body>
<div class="wrapper">

  <!-- ── Sidebar ── -->
  <aside class="sidebar" id="sidebar">

    <div class="sidebar-logo">
      <div class="logo-mark">
        <div class="logo-icon">✦</div>
        <h1>SplitPay</h1>
      </div>
      <p class="tagline">Group Expense Manager</p>
    </div>

    <div class="sidebar-user">
      <div class="avatar"><?= strtoupper(substr($_SESSION['display_name'] ?? 'U', 0, 1)) ?></div>
      <div class="user-info">
        <div class="user-name"><?= htmlspecialchars($_SESSION['display_name'] ?? 'User', ENT_QUOTES) ?></div>
        <div class="user-role"><?= ($_SESSION['is_admin_any'] ?? false) ? 'Admin' : 'Member' ?></div>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section-label">Main</div>
      <a href="/pages/dashboard.php" class="nav-item <?= $activePage === 'dashboard' ? 'active' : '' ?>">
        <span class="nav-icon">⊞</span><span class="nav-label">Dashboard</span>
      </a>
      <a href="/pages/notifications.php" class="nav-item <?= $activePage === 'notifications' ? 'active' : '' ?>">
        <span class="nav-icon">🔔</span><span class="nav-label">Notifications</span>
        <?php if (($unreadCount ?? 0) > 0): ?>
          <span class="nav-badge"><?= (int)$unreadCount ?></span>
        <?php endif; ?>
      </a>

      <div class="nav-section-label">Groups</div>
      <?php if (!empty($sidebarGroups)): ?>
        <?php foreach ($sidebarGroups as $g): ?>
          <a href="/pages/group.php?id=<?= (int)$g['group_id'] ?>" class="nav-item <?= (($activePage === 'group') && (($_GET['id'] ?? '') == $g['group_id'])) ? 'active' : '' ?>">
            <span class="nav-icon">◈</span><span class="nav-label"><?= htmlspecialchars($g['group_name'], ENT_QUOTES) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
      <a href="/pages/group-create.php" class="nav-item">
        <span class="nav-icon">+</span><span class="nav-label">New Group</span>
      </a>

      <div class="nav-section-label">Account</div>
      <a href="/pages/profile.php" class="nav-item <?= $activePage === 'profile' ? 'active' : '' ?>">
        <span class="nav-icon">◉</span><span class="nav-label">Profile</span>
      </a>
    </nav>

    <div class="sidebar-footer">
      <a href="/api/auth.php?action=logout">
        <span>⬡</span> Sign Out
      </a>
    </div>

  </aside>

  <!-- ── Main ── -->
  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <button class="topbar-btn" id="sidebar-toggle" aria-label="Toggle sidebar">☰</button>
        <div>
          <?php if (!empty($breadcrumbs)): ?>
            <div class="topbar-breadcrumb">
              <?php foreach ($breadcrumbs as $i => $crumb): ?>
                <?php if ($i > 0): ?><span class="sep">›</span><?php endif; ?>
                <?php if (!empty($crumb['url'])): ?>
                  <a href="<?= htmlspecialchars($crumb['url'], ENT_QUOTES) ?>"><?= htmlspecialchars($crumb['label'], ENT_QUOTES) ?></a>
                <?php else: ?>
                  <span><?= htmlspecialchars($crumb['label'], ENT_QUOTES) ?></span>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <span class="topbar-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="topbar-right">
        <a href="/pages/notifications.php" class="topbar-btn" aria-label="Notifications">
          🔔
          <span class="badge hidden" id="notif-badge"></span>
        </a>
        <a href="/pages/profile.php" class="topbar-btn" aria-label="Profile">
          <?= strtoupper(substr($_SESSION['display_name'] ?? 'U', 0, 1)) ?>
        </a>
        <a href="/api/auth.php?action=logout" class="topbar-btn" aria-label="Sign Out" title="Sign Out">
          ⬡
        </a>
      </div>
    </header>

    <main class="content">
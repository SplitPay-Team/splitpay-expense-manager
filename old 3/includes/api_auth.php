<?php
/**
 * SplitPay — Auth API
 * Actions: register, login, logout, update_profile, change_password
 */

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$pdo    = DB::connect();

switch ($action) {

  /* ── Register ── */
  case 'register':
    $displayName = postStr('display_name');
    $username    = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', postStr('username')));
    $email       = filter_var(postStr('email'), FILTER_VALIDATE_EMAIL);
    $password    = $_POST['password'] ?? '';

    if (!$displayName || !$username || !$email || strlen($password) < 8) {
      jsonError('All fields are required and password must be at least 8 characters.');
    }

    // Check uniqueness
    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ? OR username = ?');
    $stmt->execute([$email, $username]);
    if ($stmt->fetch()) jsonError('Email or username is already taken.', 409);

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare(
      'INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$username, $email, $hash, $displayName]);
    jsonSuccess(null, 'Account created successfully.');

  /* ── Login ── */
  case 'login':
    $email    = filter_var(postStr('email'), FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) jsonError('Email and password are required.');

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
      jsonError('Invalid email or password.', 401);
    }

    session_regenerate_id(true);
    $_SESSION['user_id']      = $user['user_id'];
    $_SESSION['display_name'] = $user['display_name'];
    $_SESSION['username']     = $user['username'];
    jsonSuccess(['user_id' => $user['user_id']], 'Login successful.');

  /* ── Logout ── */
  case 'logout':
    session_destroy();
    header('Location: /pages/login.php');
    exit;

  /* ── Update Profile ── */
  case 'update_profile':
    requireAuth();
    $displayName = postStr('display_name');
    if (!$displayName) jsonError('Display name is required.');
    $stmt = $pdo->prepare('UPDATE users SET display_name = ? WHERE user_id = ?');
    $stmt->execute([$displayName, currentUserId()]);
    $_SESSION['display_name'] = $displayName;
    jsonSuccess(null, 'Profile updated.');

  /* ── Change Password ── */
  case 'change_password':
    requireAuth();
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password']     ?? '';

    if (strlen($new) < 8) jsonError('New password must be at least 8 characters.');

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ?');
    $stmt->execute([currentUserId()]);
    $user = $stmt->fetch();

    if (!password_verify($current, $user['password_hash'])) jsonError('Current password is incorrect.');

    $hash = password_hash($new, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
    $stmt->execute([$hash, currentUserId()]);
    jsonSuccess(null, 'Password changed.');

  default:
    jsonError('Unknown action.', 404);
}

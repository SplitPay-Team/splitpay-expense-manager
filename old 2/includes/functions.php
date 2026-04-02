<?php
/**
 * Helper functions for the application
 */

/**
 * Redirect to a specified URL
 */
function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * Sanitize input data
 */
function sanitize($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Format currency
 */
function formatCurrency($amount) {
    return number_format($amount, 2);
}

/**
 * Format date
 */
function formatDate($date, $format = 'M d, Y') {
    return date($format, strtotime($date));
}

/**
 * Check if request is POST
 */
function isPost() {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/**
 * Check if request is GET
 */
function isGet() {
    return $_SERVER['REQUEST_METHOD'] === 'GET';
}

/**
 * Get POST data safely
 */
function post($key, $default = '') {
    return isset($_POST[$key]) ? sanitize($_POST[$key]) : $default;
}

/**
 * Get GET data safely
 */
function get($key, $default = '') {
    return isset($_GET[$key]) ? sanitize($_GET[$key]) : $default;
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Display error message
 */
function displayError($message) {
    return '<div class="alert error"><span class="material-icons">error</span>' . $message . '</div>';
}

/**
 * Display success message
 */
function displaySuccess($message) {
    return '<div class="alert success"><span class="material-icons">check_circle</span>' . $message . '</div>';
}

/**
 * Get base URL
 */
function baseUrl($path = '') {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    return $protocol . '://' . $host . '/' . ltrim($path, '/');
}

/**
 * Debug function
 */
function debug($data) {
    echo '<pre>';
    print_r($data);
    echo '</pre>';
}

/**
 * Log message to file
 */
function logMessage($message) {
    $logFile = dirname(__DIR__) . '/logs/app.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message" . PHP_EOL, FILE_APPEND);
}
?>

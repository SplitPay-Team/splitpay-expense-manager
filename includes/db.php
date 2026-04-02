<?php
ini_set('display_errors', 1);   // show errors in browser
ini_set('log_errors', 1);       // enable logging

/**
 * SPLIT_PAY — PDO Singleton Connection
 * Usage: $pdo = db();
 */

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    // support both installation paths:
    // - config/config.php (installer default)
    // - root config.php (legacy / quick setups)
    $configPath = __DIR__ . '/../config/config.php';
    if (!file_exists($configPath)) {
        $configPath = dirname(__DIR__) . '/config.php';
    }

    if (!file_exists($configPath)) {
        http_response_code(500);
        die(json_encode(['success' => false, 'message' => 'Application not configured. Please run install.php']));
    }
    require_once $configPath;

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['success' => false, 'message' => 'Database connection failed.']));
    }

    return $pdo;
}
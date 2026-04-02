<?php
/**
 * SplitPay — Database Connection (PDO Singleton)
 * Usage: $pdo = DB::connect();
 */

class DB {
    private static ?PDO $instance = null;

    public static function connect(): PDO {
        if (self::$instance) return self::$instance;

        // config/config.php is one level above includes/
        $configPath = dirname(__DIR__) . '/config/config.php';

        if (!file_exists($configPath)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'App not configured. Run install.php first.']);
            exit;
        }

        $config = require $configPath;

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $config['db_host'],
            $config['db_name']
        );

        try {
            self::$instance = new PDO($dsn, $config['db_user'], $config['db_pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database connection failed. Check config.php credentials.']);
            exit;
        }

        return self::$instance;
    }
}
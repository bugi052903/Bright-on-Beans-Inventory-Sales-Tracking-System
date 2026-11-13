<?php
/**
 * Bright on Beans Coffee House
 * Database Configuration & Connection
 * 
 * This file handles MySQL database connection via PDO
 * and defines global constants used throughout the application.
 */

// ─── Database Credentials ──────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'bright_on_beans');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ─── Application Constants ─────────────────────────────────────
// Auto-detect BASE_URL dynamically so it works on any PC / folder layout
if (!defined('BASE_URL')) {
    if (isset($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['DOCUMENT_ROOT'])) {
        $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
        $currentDir = str_replace('\\', '/', dirname(__DIR__));
        $relativeUrl = str_replace($docRoot, '', $currentDir);
        define('BASE_URL', rtrim($relativeUrl, '/'));
    } else {
        define('BASE_URL', '/bright-on-beans');
    }
}
define('ROOT_PATH', dirname(__DIR__));
define('APP_NAME', 'Bright on Beans Coffee House');
define('APP_SHORT_NAME', 'Bright on Beans');
// Set Default Timezone for Philippines
date_default_timezone_set('Asia/Manila');

define('CURRENCY_SYMBOL', '₱');

// ─── Database Connection (PDO) ─────────────────────────────────
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    // In production, log this error instead of displaying it
    die("Database connection failed. Please make sure XAMPP (Apache & MySQL) is running.");
}

// ─── Session Management ────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

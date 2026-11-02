<?php
/**
 * Side-by-Side Coil Verification Table (3-Way Reconciliation System)
 * Frontend MVC Entrypoint
 * Location: /comparison_end_month/coil_verification.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database config and autoloader from root
require_once __DIR__ . '/../config.php';

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

require_once __DIR__ . '/controllers/CoilVerificationController.php';

// Initialize PDO Database Connection
$pdo = null;
try {
    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    error_log("PDO Connection Error: " . $e->getMessage());
}

// Instantiate MVC Controller & Execute Request Handler
$controller = new CoilVerificationController($pdo);
$controller->handleRequest();

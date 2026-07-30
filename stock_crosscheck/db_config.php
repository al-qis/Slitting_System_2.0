<?php
/**
 * db_config.php
 * -----------------------------------------------------------------------
 * Reuses the Slitting System's existing database connection instead of
 * duplicating credentials. It includes the main app's config.php (which
 * defines $conn) and aliases it to $mysqli for the AJAX scripts here.
 *
 * Assumes this folder (stock_crosscheck) sits directly inside the main
 * slitting_system folder:
 *   C:\Apache24\htdocs\slitting_system\config.php
 *   C:\Apache24\htdocs\slitting_system\stock_crosscheck\db_config.php
 * -----------------------------------------------------------------------
 */

require_once 'C:/Apache24/htdocs/slitting_system/config.php'; // defines $conn, $BASE_URL, timezone, etc.

if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode([
        'success' => false,
        'error'   => 'Database connection failed: ' . ($conn->connect_error ?? 'unknown error')
    ]));
}

// The rest of this tool's scripts expect $mysqli - alias it here.
$mysqli = $conn;
$mysqli->set_charset('utf8mb4');

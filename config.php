<?php
/**
 * Main System Configuration
 * Location: /slitting_system/config.php
 * -----------------------------------------------------------------------
 * Single source of truth for database connection, timezones, and base URL.
 * -----------------------------------------------------------------------
 */

// Database Credentials
$host   = 'localhost'; 
$user   = 'root';
$pass   = 'MIS_1990'; 
$dbname = 'slitting_db'; 

// Initialize MySQLi Connection
$conn = new mysqli($host, $user, $pass, $dbname);

// Handle Connection Failures
if ($conn->connect_error) { 
    die("Database Connection Failed: " . $conn->connect_error); 
} 

// Set Character Set and Timezone
$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Kuala_Lumpur');

// Dynamic Base URL Configuration
$protocol    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$host_header = $_SERVER['HTTP_HOST'] ?? 'localhost';
$BASE_URL    = "{$protocol}://{$host_header}/slitting_system";

?>
<?php
/**
 * Ajax Endpoint: Verify Supervisor PIN
 * Location: /Verification_SV/verify_supervisor_pin.php
 */

session_start();
header('Content-Type: application/json; charset=UTF-8');

// Include system configuration / database connection
$configPath = __DIR__ . '/../config.php';
if (file_exists($configPath)) {
    require_once $configPath;
}

// Reject direct GET browser access with an informative message
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'This script is a backend API endpoint. Access it by opening sfc_inventory.php or sfc.php in your browser and clicking Process.'
    ]);
    exit;
}

// Read incoming request payload (supports both JSON raw body and standard POST form data)
$inputRaw = file_get_contents('php://input');
$data = json_decode($inputRaw, true);

if (is_array($data)) {
    $pin = trim($data['pin'] ?? '');
    $itemId = isset($data['item_id']) ? (int)$data['item_id'] : 0;
} else {
    $pin = trim($_POST['pin'] ?? '');
    $itemId = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
}

// Input validation
if (empty($pin) || $itemId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Please enter a valid supervisor PIN and select an item.'
    ]);
    exit;
}

$isAuthorized = false;

// 1. Verify against database `users` table using MySQLi prepared statements
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    // Query users with supervisor/admin/slitting roles using prepared statement
    $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE role IN ('supervisor', 'admin', 'slitting', 'mkl3')");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($user = $result->fetch_assoc()) {
            // Verify pin against password hash or plain text PIN comparison
            if (password_verify($pin, $user['password']) || $pin === $user['password']) {
                $isAuthorized = true;
                break;
            }
        }
        $stmt->close();
    }
}

// 2. Hashed secret / fallback verification (e.g. standard supervisor secret PIN '1234')
if (!$isAuthorized) {
    // Hashed secret for PIN '1234' (password_hash('1234', PASSWORD_BCRYPT))
    $hashedSecret = '$2y$10$8utZ6odMkhIqAKYZrNS2u7q8zDYRdrXnNHhr1hzCub1b9evcF.K';
    if ($pin === '1234' || password_verify($pin, $hashedSecret)) {
        $isAuthorized = true;
    }
}

// Return JSON response
if ($isAuthorized) {
    $_SESSION['supervisor_authorized'] = true;
    $_SESSION['supervisor_auth_time'] = time();

    echo json_encode([
        'success'  => true,
        'message'  => 'Supervisor authorization successful.',
        'item_id'  => $itemId,
        'redirect' => 'process_sfc.php?id=' . $itemId
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid Supervisor PIN. Access denied.'
    ]);
}
exit;

<?php
/**
 * Ajax Endpoint: Verify Supervisor PIN
 * Location: /control_system/verify_supervisor_pin.php
 */

session_start();
header('Content-Type: application/json; charset=UTF-8');

// Include system configuration & Control Center storage
$configPath = __DIR__ . '/../config.php';
if (file_exists($configPath)) {
    require_once $configPath;
}

$controlStorePath = __DIR__ . '/config_store.php';
if (file_exists($controlStorePath)) {
    require_once $controlStorePath;
}

// Reject direct GET browser access with an informative message
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'This script is a backend API endpoint. Access it by opening sfc.php in your browser and clicking Process.'
    ]);
    exit;
}

// Check if SFC Process feature is BLOCKED in Control Center
if (function_exists('isButtonActive') && !isButtonActive('sfc_process')) {
    echo json_encode([
        'success' => false,
        'message' => 'SFC Process button function is currently BLOCKED in Control Center.'
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

// Verify strictly against Control Center active supervisor PIN
$activePin = function_exists('getSupervisorPin') ? getSupervisorPin() : '1234';
if ($activePin !== '' && $pin === $activePin) {
    $isAuthorized = true;
}

// Return JSON response
if ($isAuthorized) {
    $_SESSION['supervisor_authorized'] = true;
    $_SESSION['supervisor_auth_time'] = time();

    // Auto-generate new passkey in Control Center for the next coil operation
    if (function_exists('rotateSupervisorPin')) {
        rotateSupervisorPin();
    }

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

<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION['role'] !== 'slitting') {
    die("Access denied");
}

include 'config.php';

$qr = isset($_POST['qr']) ? trim($_POST['qr']) : '';

if (empty($qr)) {
    $_SESSION['error'] = "QR code cannot be empty";
    header("Location: raw_material.php");
    exit;
}

// Parse QR format: LOT=825175;COIL=FK-1
$parts = [];
if (strpos($qr, ';') !== false) {
    $pairs = explode(';', $qr);
    foreach ($pairs as $pair) {
        if (strpos($pair, '=') !== false) {
            list($key, $value) = explode('=', $pair, 2);
            $parts[trim($key)] = trim($value);
        }
    }
}

$lot_no = isset($parts['LOT']) ? $conn->real_escape_string($parts['LOT']) : '';
$coil_no = isset($parts['COIL']) ? $conn->real_escape_string($parts['COIL']) : '';

if (empty($lot_no) || empty($coil_no)) {
    $_SESSION['error'] = "Invalid QR format. Expected: LOT=xxx;COIL=xxx";
    header("Location: raw_material.php");
    exit;
}

// Determine action based on current button
$action = isset($_POST['action']) ? $_POST['action'] : 'toggle';

// Get mother coil
$query = "SELECT * FROM mother_coil WHERE lot_no='$lot_no' AND coil_no='$coil_no'";
$result = $conn->query($query);

if (!$result || $result->num_rows === 0) {
    $_SESSION['error'] = "Mother coil not found: $lot_no - $coil_no";
    header("Location: raw_material.php");
    exit;
}

$mother = $result->fetch_assoc();
$mother_id = $mother['id'];
$current_stock = $mother['stock'];

// Determine new status based on action or toggle
if ($action === 'in') {
    $new_status = 'IN';
} elseif ($action === 'out') {
    $new_status = 'OUT';
} else {
    // Toggle: if stock=1 (IN), change to OUT; if stock=0 (OUT), change to IN
    $new_status = ($current_stock == 1) ? 'OUT' : 'IN';
}

$new_stock = ($new_status === 'IN') ? 1 : 0;

// Update mother_coil
if ($new_status === 'IN') {
    $update_query = "UPDATE mother_coil 
                     SET status='IN', 
                         stock=1, 
                         date_in=NOW(),
                         scan_in_count = scan_in_count + 1
                     WHERE id=$mother_id";
} else {
    $update_query = "UPDATE mother_coil 
                     SET status='OUT', 
                         stock=0, 
                         date_out=NOW(),
                         scan_out_count = scan_out_count + 1
                     WHERE id=$mother_id";
}

if (!$conn->query($update_query)) {
    $_SESSION['error'] = "Failed to update mother coil: " . $conn->error;
    header("Location: raw_material.php");
    exit;
}

// Log the action in audit log
$action_type = ($new_status === 'IN') ? 'SCAN_IN' : 'SCAN_OUT';
$audit_query = "INSERT INTO mother_coil_audit_log (mother_id, action_type, performed_at, remark) 
                VALUES ($mother_id, '$action_type', NOW(), 'Manual scan: $lot_no $coil_no')";

if (!$conn->query($audit_query)) {
    $_SESSION['error'] = "Failed to log action: " . $conn->error;
    header("Location: raw_material.php");
    exit;
}

// Check if this coil already has a log entry
$log_check = "SELECT id FROM raw_material_log 
              WHERE mother_id=$mother_id 
              ORDER BY id DESC LIMIT 1";
$log_result = $conn->query($log_check);

if ($log_result && $log_result->num_rows > 0) {
    // Update existing log entry instead of creating a new one (NO DUPLICATES)
    $existing_log = $log_result->fetch_assoc();
    $log_id = $existing_log['id'];

    if ($new_status === 'IN') {
        $update_log = "UPDATE raw_material_log 
                       SET status='IN', 
                           date_in=NOW(),
                           date_out=NULL,
                           remark='Scanned IN: $lot_no $coil_no'
                       WHERE id=$log_id";
    } else {
        $update_log = "UPDATE raw_material_log 
                       SET status='OUT', 
                           date_out=NOW(),
                           remark='Scanned OUT: $lot_no $coil_no'
                       WHERE id=$log_id";
    }

    $conn->query($update_log);
} else {
    // Create new log entry only for first scan
    $log_query = "INSERT INTO raw_material_log (mother_id, status, action, date_in, date_out, remark) 
                  VALUES ($mother_id, '$new_status', 'normal', " . 
                  ($new_status === 'IN' ? "NOW(), NULL" : "NULL, NOW()") . 
                  ", 'Scanned: $lot_no $coil_no')";

    $conn->query($log_query);
}

// Redirection Logic
if ($new_status === 'OUT') {
    // If scanning OUT, force user to fill the Add Slitting form
    header("Location: add_slitting.php?mother_id=" . $mother_id);
} else {
    // If scanning IN, proceed to the raw material list as usual
    $_SESSION['success'] = "Mother coil $lot_no-$coil_no scanned " . strtolower($new_status);
    header("Location: raw_material.php");
}
exit;
?>
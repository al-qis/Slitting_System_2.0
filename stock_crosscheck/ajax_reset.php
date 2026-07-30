<?php
/**
 * ajax_reset.php
 * -----------------------------------------------------------------------
 * action=clear_all   -> wipes the entire scanned session (new count)
 * action=remove&raw= -> removes a single mistaken scan by its raw QR key
 * -----------------------------------------------------------------------
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['scanned'])) {
    $_SESSION['scanned'] = [];
}

$action = $_POST['action'] ?? '';

if ($action === 'clear_all') {
    $_SESSION['scanned'] = [];
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'remove') {
    $raw = $_POST['raw'] ?? '';
    if (isset($_SESSION['scanned'][$raw])) {
        unset($_SESSION['scanned'][$raw]);
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Record not found']);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);

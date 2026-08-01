<?php
/**
 * ajax_reset.php
 * -----------------------------------------------------------------------
 * action=clear_all   -> wipes the entire stock_crosscheck_scans table
 *                       (new count, shared across all devices)
 * action=remove&raw= -> removes a single mistaken scan by its raw QR key
 * -----------------------------------------------------------------------
 */

header('Content-Type: application/json');
require_once dirname(__DIR__) . '/config.php';
$mysqli = $conn;

$action = $_POST['action'] ?? '';

if ($action === 'clear_all') {
    // TRUNCATE also resets the AUTO_INCREMENT id back to 1 for the next count.
    if ($mysqli->query('TRUNCATE TABLE stock_crosscheck_scans')) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error while clearing scans.']);
    }
    exit;
}

if ($action === 'remove') {
    $raw = trim($_POST['raw'] ?? '');
    if ($raw === '') {
        echo json_encode(['success' => false, 'error' => 'Missing raw QR key.']);
        exit;
    }

    $stmt = $mysqli->prepare('DELETE FROM stock_crosscheck_scans WHERE raw = ?');
    $stmt->bind_param('s', $raw);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    if ($deleted) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Record not found']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
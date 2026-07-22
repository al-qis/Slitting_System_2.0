<?php
// batch_setup_save.php
// ============================================================
// AJAX "Save All" endpoint for batch_setup.php.
// Receives { selections: [{ id, customer, ref_no, length, nci_resolved_customer }, ...] }
// as a JSON body and persists customer_name/ref_no/actual_length for every
// roll, without navigating away from the batch grid.
// ============================================================

session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['role'])) {
    echo json_encode(['ok' => false, 'msg' => 'Session expired — please log in again.']);
    exit;
}
if ($_SESSION['role'] !== 'slitting') {
    echo json_encode(['ok' => false, 'msg' => 'Access denied.']);
    exit;
}

include 'config.php';

$body = json_decode(file_get_contents('php://input'), true);
$selections = $body['selections'] ?? null;

if (!is_array($selections) || empty($selections)) {
    echo json_encode(['ok' => false, 'msg' => 'No rolls to save.']);
    exit;
}

$stmt = $conn->prepare("UPDATE slitting_product SET customer_name = ?, ref_no = ?, actual_length = ? WHERE id = ?");
if (!$stmt) {
    echo json_encode(['ok' => false, 'msg' => 'DB prepare failed: ' . $conn->error]);
    exit;
}

$saved = 0;
$errors = [];

foreach ($selections as $sel) {
    $id       = intval($sel['id'] ?? 0);
    $customer = trim($sel['customer'] ?? '');
    $ref_no   = trim($sel['ref_no']   ?? '');
    $nciResolved = trim($sel['nci_resolved_customer'] ?? '');
    $length   = isset($sel['length']) ? (float)$sel['length'] : 0;

    if ($id <= 0)         { $errors[] = "Invalid roll ID."; continue; }
    if ($customer === '') { $errors[] = "Roll #{$id}: customer required."; continue; }
    if ($ref_no === '')   { $errors[] = "Roll #{$id}: Ref No required."; continue; }
    if ($length <= 0)     { $errors[] = "Roll #{$id}: length must be greater than 0."; continue; }

    // Same rule as select_customer.php / bulk print: for NCI MFG / NCI 2,
    // save the resolved end-customer name, not the raw dropdown code.
    $customerToSave = $customer;
    if (in_array($customer, ['NCI MFG', 'NCI 2'], true) && $nciResolved !== '') {
        $customerToSave = $nciResolved;
    }

    $stmt->bind_param("ssdi", $customerToSave, $ref_no, $length, $id);
    if ($stmt->execute()) {
        $saved++;
    } else {
        $errors[] = "Roll #{$id}: save failed — {$conn->error}";
    }
}
$stmt->close();

echo json_encode([
    'ok'  => empty($errors),
    'msg' => empty($errors)
        ? "Saved {$saved} roll(s)."
        : "Saved {$saved} roll(s), " . count($errors) . ' failed: ' . implode(' | ', $errors),
]);
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

$stmt = $conn->prepare("UPDATE slitting_product SET customer_name = ?, ref_no = ?, actual_length = ?, stock_counted = 1, is_completed = 1 WHERE id = ?");
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

    // Fetch existing state to check if it was pending before this update
    $existing = null;
    $resEx = $conn->query("SELECT * FROM slitting_product WHERE id=$id");
    if ($resEx && $resEx->num_rows > 0) {
        $existing = $resEx->fetch_assoc();
    }

    $wasPending = $existing && ((int)$existing['is_completed'] === 0);

    // Same rule as select_customer.php / bulk print: for NCI MFG / NCI 2,
    // save the resolved end-customer name, not the raw dropdown code.
    $customerToSave = $customer;
    if (in_array($customer, ['NCI MFG', 'NCI 2'], true) && $nciResolved !== '') {
        $customerToSave = $nciResolved;
    }

    $stmt->bind_param("ssdi", $customerToSave, $ref_no, $length, $id);
    if ($stmt->execute()) {
        $saved++;

        // If transitioning from pending to completed, handle leftover for cut_into_2
        if ($wasPending && $existing) {
            if ($existing['cut_type'] === 'cut_into_2'
                && floatval($existing['leftover_length'] ?? $existing['stock'] ?? 0) > 0) {

                $leftover = floatval($existing['leftover_length'] ?? $existing['stock'] ?? 0);
                $motherId = intval($existing['mother_id'] ?? 0);
                if ($motherId > 0) {
                    $mRes = $conn->query("SELECT * FROM mother_coil WHERE id={$motherId}");
                    if ($mRes && $mRes->num_rows > 0) {
                        $mother = $mRes->fetch_assoc();
                        $stock_lot_no = $existing['lot_no'] . 'a';
                        $check = $conn->query("
                            SELECT id, length FROM stock_raw_material
                            WHERE lot_no='$stock_lot_no' AND coil_no='{$existing['coil_no']}'
                        ");
                        if ($check && $check->num_rows > 0) {
                            $exStock = $check->fetch_assoc();
                            $new_length = $exStock['length'] + $leftover;
                            $conn->query("UPDATE stock_raw_material SET length=$new_length, updated_at=NOW() WHERE id={$exStock['id']}");
                        } else {
                            $ins = $conn->prepare("
                                INSERT INTO stock_raw_material
                                    (lot_no, coil_no, width, length, status, source_type, source_id, date_in)
                                VALUES (?, ?, ?, ?, 'IN', 'reslit', ?, NOW())
                            ");
                            $ins->bind_param("ssddi",
                                $stock_lot_no, $existing['coil_no'],
                                $mother['width'], $leftover, $motherId);
                            $ins->execute();
                            $ins->close();
                        }
                    }
                }
            }

            // Log process history
            $performedBy = $_SESSION['role'] ?? 'system';
            $motherId    = intval($existing['mother_id'] ?? 0) ?: null;
            $remark      = "actual_length={$length}m, customer={$customerToSave}, ref_no={$ref_no}, stock_counted=1";
            $stmtLog = $conn->prepare("
                INSERT INTO process_log
                    (entity_type, entity_id, mother_id, from_status, to_status,
                     performed_by, action_detail, remark)
                VALUES ('slitting', ?, ?, 'IN', 'IN', ?, 'batch_setup_saved', ?)
            ");
            $stmtLog->bind_param("iiss", $id, $motherId, $performedBy, $remark);
            $stmtLog->execute();
            $stmtLog->close();
        }
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
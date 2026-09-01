<?php
// mixed_batch_save_action.php
// ============================================================
// "Save Only" counterpart to mixed_batch_print_action.php — for
// mixed_batch_setup.php's grid when the operator just needs to fix
// Customer/Ref No on a batch of rolls without printing stickers.
//
// Reuses the exact same save + audit-log logic as
// mixed_batch_print_action.php (see that file's block around
// "Save each roll's OWN customer/ref_no..."), just without the
// print-tracking UPDATE or the sticker print job. Called via fetch()
// from mixed_batch_setup.php's "Save Only" button; responds with JSON
// so the grid can show per-row "Saved" status without a page reload.
//
// Receives: selections -> JSON array of { id, customer, ref_no }
// (same shape mixed_batch_print_action.php accepts; copies/
// nci_resolved_customer are accepted but ignored here since there's
// nothing to print).
// ============================================================

session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Not logged in.']);
    exit;
}
if ($_SESSION['role'] !== 'slitting') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Access denied']);
    exit;
}

include 'config.php';

// ── Helper: write one process_log row ────────────────────────────
// Identical helper to mixed_batch_print_action.php's mixed_log_process().
function mixed_save_log_process(mysqli $conn, int $entity_id, ?int $mother_id, string $action_detail, string $remark): void {
    $performed_by = $_SESSION['role'] ?? 'system';
    $entity_type  = 'slitting';
    $to_status    = 'IN';
    $stmt = $conn->prepare("
        INSERT INTO process_log
            (entity_type, entity_id, mother_id, from_status, to_status,
             performed_by, action_detail, remark)
        VALUES (?, ?, ?, NULL, ?, ?, ?, ?)
    ");
    if (!$stmt) return; // audit logging must never block the save
    $stmt->bind_param("siissss", $entity_type, $entity_id, $mother_id, $to_status, $performed_by, $action_detail, $remark);
    $stmt->execute();
    $stmt->close();
}

// ── Parse & validate payload ─────────────────────────────────────
$rawSelections = json_decode($_POST['selections'] ?? '', true);
if (!is_array($rawSelections) || empty($rawSelections)) {
    echo json_encode(['ok' => false, 'msg' => 'No rolls selected to save.']);
    exit;
}

// Same sanitize discipline as mixed_batch_print_action.php — never
// trust the JSON shape alone.
$selectionsById = [];
foreach ($rawSelections as $sel) {
    if (!is_array($sel)) continue;
    $id = intval($sel['id'] ?? 0);
    if ($id <= 0) continue;

    $customer = trim((string)($sel['customer'] ?? ''));
    $ref_no   = trim((string)($sel['ref_no']   ?? ''));
    $length   = isset($sel['length']) ? (float)$sel['length'] : 0;
    if ($customer === '' || $ref_no === '') continue; // skip silently, same as print action

    $selectionsById[$id] = [
        'id'       => $id,
        'customer' => $customer,
        'ref_no'   => $ref_no,
        'length'   => $length,
    ];
}

if (empty($selectionsById)) {
    echo json_encode(['ok' => false, 'msg' => 'No valid roll selections received — every row needs a Customer and Ref No.']);
    exit;
}

$ids = array_keys($selectionsById);

// ── Fetch + validate every selected roll still exists / isn't voided ──
// Uses the same eligibility rule as mixed_batch_setup.php / print
// action so "Save Only" can't silently write to a roll that's since
// been voided, palletised, etc. between page load and this submit.
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $conn->prepare("
    SELECT sp.id, sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
           sp.status, sp.is_completed, sp.mother_id,
           pi.pallet_id
    FROM slitting_product sp
    LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
    WHERE sp.id IN ($placeholders)
      AND (sp.is_voided = 0 OR sp.is_voided IS NULL)
");
$stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$clean    = [];
$errors   = [];
$foundIds = [];

$from = trim($_POST['from'] ?? $_GET['from'] ?? '');

foreach ($rows as $r) {
    $rid = (int)$r['id'];
    $foundIds[] = $rid;
    $sel = $selectionsById[$rid];

    if ($from === 'slitting_product') {
        $isEligible = !in_array($r['status'], ['WAITING', 'REJECTED'], true);
    } else {
        $isEligible = !in_array($r['status'], ['WAITING', 'REJECTED'], true)
            && !($r['status'] === 'IN' && ($r['is_completed'] == 0 || $r['pallet_id']));
    }

    if (!$isEligible) {
        $reason = $r['status'] === 'IN'
            ? ($r['pallet_id'] ? 'already palletised' : 'pending Actual Length')
            : ('status ' . $r['status']);
        $errors[] = "Roll #{$rid} ({$r['product']} {$r['lot_no']} {$r['coil_no']}): skipped — {$reason}.";
        continue;
    }

    $clean[] = [
        'id'        => $rid,
        'mother_id' => $r['mother_id'] ? (int)$r['mother_id'] : null,
        'customer'  => $sel['customer'],
        'ref_no'    => $sel['ref_no'],
        'length'    => $sel['length'],
    ];
}

$missingIds = array_diff($ids, $foundIds);
foreach ($missingIds as $mid) {
    $errors[] = "Roll #{$mid}: not found (or voided).";
}

if (empty($clean)) {
    echo json_encode(['ok' => false, 'msg' => 'Nothing saved — see errors.', 'errors' => $errors]);
    exit;
}

// ── Save each roll's own customer/ref_no + audit log — NO print
//    tracking touched here, that's the whole point of Save Only ──
$stmtUpdLength = $conn->prepare("UPDATE slitting_product SET customer_name = ?, ref_no = ?, actual_length = ? WHERE id = ?");
$stmtUpdNormal = $conn->prepare("UPDATE slitting_product SET customer_name = ?, ref_no = ? WHERE id = ?");

$savedIds = [];
foreach ($clean as $row) {
    if (isset($row['length']) && $row['length'] > 0) {
        $stmtUpdLength->bind_param("ssdi", $row['customer'], $row['ref_no'], $row['length'], $row['id']);
        $stmtUpdLength->execute();
    } else {
        $stmtUpdNormal->bind_param("ssi", $row['customer'], $row['ref_no'], $row['id']);
        $stmtUpdNormal->execute();
    }

    mixed_save_log_process(
        $conn,
        $row['id'],
        $row['mother_id'],
        'mixed_batch_save',
        "Mixed batch save (no print) — Customer: {$row['customer']} · Ref: {$row['ref_no']}" . ($row['length'] > 0 ? " · Length: {$row['length']}m" : "")
    );

    $savedIds[] = $row['id'];
}
if ($stmtUpdLength) $stmtUpdLength->close();
if ($stmtUpdNormal) $stmtUpdNormal->close();

echo json_encode([
    'ok'     => true,
    'saved'  => $savedIds,
    'errors' => $errors,
]);

<?php
// recoiling_handler.php
//
// KEY DESIGN DECISION:
// Recoiling = rewinding / trimming the same physical roll.
// The output is still the SAME product (same lot, coil, roll number).
//
// The correct flow is:
//   1. Rename roll_no on the SOURCE row to e.g. "R1_void_42"
//      This frees the UNIQUE KEY (lot_no, coil_no, roll_no) so the new row
//      can be inserted with the original roll_no.
//      NOTE: setting is_voided=1 alone does NOT free the unique index —
//      MySQL still sees the same column values and rejects the INSERT.
//   2. Insert the new slitting_product row with same lot/coil/roll
//      but updated actual_length and width.
//   3. Mark recoiling_product as completed.

session_start();
include 'config.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ── Helper: write one process_log row ──────────────────────────────────────
function log_process(
    mysqli  $conn,
    string  $entity_type,
    int     $entity_id,
    ?int    $mother_id,
    ?string $from_status,
    string  $to_status,
    string  $action_detail = '',
    string  $remark = ''
): void {
    $performed_by = $_SESSION['role'] ?? 'system';
    $stmt = $conn->prepare("
        INSERT INTO process_log
            (entity_type, entity_id, mother_id, from_status, to_status,
             performed_by, action_detail, remark)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "siisssss",
        $entity_type, $entity_id, $mother_id,
        $from_status, $to_status,
        $performed_by, $action_detail, $remark
    );
    $stmt->execute();
    $stmt->close();
}

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    || ($_POST['action'] ?? '') !== 'start_and_complete_recoiling'
) {
    header("Location: recoiling.php");
    exit;
}

$id = intval($_POST['id'] ?? 0);

$total_rolls = 0;
if (isset($_POST['actual_length']) && is_array($_POST['actual_length'])) {
    $total_rolls = count($_POST['actual_length']);
} elseif (isset($_POST['new_width']) && is_array($_POST['new_width'])) {
    $total_rolls = count($_POST['new_width']);
}

if ($id <= 0) {
    header("Location: recoiling.php?error=invalid_id");
    exit;
}

// 1. Fetch recoiling_product record
$stmt = $conn->prepare("SELECT * FROM recoiling_product WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$original = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$original) {
    header("Location: recoiling.php?error=not_found");
    exit;
}
if (($original['status'] ?? '') === 'completed') {
    header("Location: recoiling.php?error=already_completed&id=$id");
    exit;
}

$parent_slit_id = intval($original['slitting_product_id'] ?? 0) ?: null;
$mother_id_val  = $original['mother_id'] ?? null;
$cut_type       = trim($_POST['cut_type'] ?? 'normal');

// ── Duplicate check ONLY for cut_into_2 ───────────────────────────────────
// For normal recoil: same lot/coil/roll is intentional — skip check entirely.
// For cut_into_2: new roll numbers must not already exist (excluding source).
if ($cut_type === 'cut_into_2') {
    $duplicateLots = [];
    for ($i = 0; $i < $total_rolls; $i++) {
        $letter     = trim($_POST['letter'][$i] ?? '');
        $new_lot_no = $original['lot_no'] . ($letter !== '' ? $letter : '');
        $new_roll   = 'R' . intval($_POST['roll_number'][$i] ?? ($i + 1));
        $exclude_id = $parent_slit_id ?? 0;

        $chk = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM slitting_product
            WHERE lot_no = ? AND coil_no = ? AND roll_no = ?
              AND id != ?
        ");
        $chk->bind_param("sssi", $new_lot_no, $original['coil_no'], $new_roll, $exclude_id);
        $chk->execute();
        $cnt = (int)($chk->get_result()->fetch_assoc()['cnt'] ?? 0);
        $chk->close();

        if ($cnt > 0) {
            $duplicateLots[] = "{$new_lot_no} {$original['coil_no']} {$new_roll}";
        }
    }

    if (!empty($duplicateLots)) {
        $dupList = implode(', ', array_unique($duplicateLots));
        header("Location: recoiling.php?error=duplicate_lot&lots=" . urlencode($dupList) . "&open_id=$id");
        exit;
    }
}

$conn->begin_transaction();

try {

    // ── STEP 1: Free the UNIQUE KEY on the source row ─────────────────────
    // UNIQUE KEY is on (lot_no, coil_no, roll_no).
    // We rename roll_no to "R1_void_42" so the unique slot is freed.
    // The row is kept in the DB for history (is_voided = 1).
    if ($parent_slit_id) {
        $voided_roll = $original['roll_no'] . '_void_' . $parent_slit_id;

        $void_stmt = $conn->prepare("
            UPDATE slitting_product
            SET roll_no       = ?,
                is_recoiled   = 1,
                is_voided     = 1,
                voided_at     = NOW(),
                voided_reason = 'replaced_by_recoil'
            WHERE id = ?
        ");
        $void_stmt->bind_param("si", $voided_roll, $parent_slit_id);
        $void_stmt->execute();
        $void_stmt->close();

        log_process($conn, 'slitting', $parent_slit_id, $mother_id_val,
            'IN', 'IN',
            'voided_for_recoil',
            "roll_no renamed to {$voided_roll}, replaced by recoiling_product id={$id}"
        );
    }

    // ── STEP 2: Insert new slitting_product row(s) ────────────────────────
    $total_actual_length = 0.0;
    $summary_width       = 0.0;
    $all_remarks         = [];

    for ($i = 0; $i < $total_rolls; $i++) {
        $new_width     = floatval($_POST['new_width'][$i]     ?? 0);
        $length        = floatval($_POST['length'][$i]        ?? 0);
        $defect        = floatval($_POST['defect'][$i]        ?? 0);
        $actual_length = floatval($_POST['actual_length'][$i] ?? 0);
        $remark        = trim($_POST['remark'][$i]            ?? '');
        $roll_number   = intval($_POST['roll_number'][$i]     ?? 1);
        $letter        = trim($_POST['letter'][$i]            ?? '');

        if ($cut_type === 'normal') {
            // Keep EXACTLY same lot/coil/roll as source — now safe because
            // the source row's roll_no was renamed in STEP 1 above.
            $new_roll_no = $original['roll_no'];
            $new_lot_no  = $original['lot_no'];
        } else {
            // cut_into_2: new roll numbers R1, R2
            $new_roll_no = 'R' . $roll_number;
            $new_lot_no  = $original['lot_no'] . ($letter !== '' ? $letter : '');
        }

        if (!empty($remark) || $defect > 0) {
            $r  = "{$new_lot_no} {$original['coil_no']} / {$new_roll_no} : ";
            $r .= ($defect > 0) ? "Defect {$defect}m" : "";
            if (!empty($remark)) $r .= ($defect > 0 ? " - " : "") . $remark;
            $all_remarks[] = $r;
        }

        $insert_stmt = $conn->prepare("
            INSERT INTO slitting_product
                (recoiling_id, mother_id, parent_slit_id,
                 product, lot_no, coil_no, roll_no,
                 width, length, actual_length,
                 status, is_completed, stock_counted,
                 original_source, source, date_in)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'IN', 1, 1, ?, 'recoiling', NOW())
        ");

        $insert_stmt->bind_param(
            "iiissssddds",
            $id,
            $mother_id_val,
            $parent_slit_id,
            $original['product'],
            $new_lot_no,
            $original['coil_no'],
            $new_roll_no,
            $new_width,
            $length,
            $actual_length,
            $original['original_source']
        );

        if (!$insert_stmt->execute()) {
            throw new Exception("Insert failed: " . $insert_stmt->error);
        }
        $new_slit_id = $conn->insert_id;
        $insert_stmt->close();

        log_process($conn, 'slitting', $new_slit_id, $mother_id_val,
            null, 'IN', 'recoiling_output',
            "New row: {$new_lot_no} {$original['coil_no']} {$new_roll_no} "
            . "actual={$actual_length}m width={$new_width}mm "
            . "from recoiling_product id={$id}"
        );

        $total_actual_length += $actual_length;
        if ($i === 0) $summary_width = $new_width;
    }

    $combined_remark = !empty($all_remarks) ? implode(" | ", $all_remarks) : "";

    // ── STEP 3: Mark recoiling_product as completed ───────────────────────
    $update_stmt = $conn->prepare("
        UPDATE recoiling_product
        SET status       = 'completed',
            completed_at = NOW(),
            started_at   = NOW(),
            new_width    = ?,
            new_length   = ?,
            remark       = ?
        WHERE id = ?
    ");
    $update_stmt->bind_param("ddsi",
        $summary_width, $total_actual_length, $combined_remark, $id);
    $update_stmt->execute();
    $update_stmt->close();

    log_process($conn, 'recoiling', $id, $mother_id_val,
        'pending', 'completed', 'recoiling_complete',
        "Output rolls: {$total_rolls}, total_length={$total_actual_length}m"
    );

    $conn->commit();
    header("Location: recoiling.php?success=completed&id=$id");
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    header("Location: recoiling.php?error=process_failed&msg=" . urlencode($e->getMessage()));
    exit;
}
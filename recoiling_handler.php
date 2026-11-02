<?php
// recoiling_handler.php
//
// Three recoiling modes:
//
// 1. rewinding    — same lot/coil/roll, operator updates length only.
//                   No duplicate check (source row voided first).
//
// 2. normal       — same lot/coil/roll, defect removed from one end.
//                   actual_length = original − defect.
//                   No duplicate check (source row voided first).
//
// 3. cut_into_2   — two new rolls, BOTH lengths entered manually.
//                   coil_no and roll_no stay the same (e.g. R4 → R4, R4).
//                   Letter suffix on lot_no is OPTIONAL (a/b/c or none).
//                   Source row lot_no renamed to "*_OLD_{id}" to free UNIQUE KEY.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'config.php';
require_once 'PalletManager.php';

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

// ── AJAX Action: Operator opened modal / started recoiling process ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_recoiling_process') {
    header('Content-Type: application/json');
    $recoil_id = intval($_POST['id'] ?? 0);
    if ($recoil_id > 0) {
        $stmt = $conn->prepare("UPDATE recoiling_product SET status = 'in_progress', started_at = COALESCE(started_at, NOW()) WHERE id = ? AND status != 'completed'");
        $stmt->bind_param("i", $recoil_id);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => $ok]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

// ── AJAX Action: Operator cancelled / closed modal without completing ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_recoiling_process') {
    header('Content-Type: application/json');
    $recoil_id = intval($_POST['id'] ?? 0);
    if ($recoil_id > 0) {
        $stmt = $conn->prepare("UPDATE recoiling_product SET status = 'pending', started_at = NULL WHERE id = ? AND status = 'in_progress'");
        $stmt->bind_param("i", $recoil_id);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => $ok]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
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

// cut_type comes from the hidden field populated by selectMode() in JS
$cut_type = trim($_POST['cut_type'] ?? 'normal');

// Normalise: treat 'rewinding' same as 'normal' in terms of DB logic
// (same lot/coil/roll, source voided, one new row inserted)
$is_same_roll = in_array($cut_type, ['rewinding', 'normal']);

// ── Duplicate check — ONLY for cut_into_2 WITH a letter suffix ────────────
// If no letter suffix chosen, the source row void (which renames lot_no to
// "lot_OLD_{id}") frees the unique key, so no pre-check is needed.
if ($cut_type === 'cut_into_2') {
    $duplicates = [];
    $seen_in_batch = [];
    for ($i = 0; $i < $total_rolls; $i++) {
        $letter     = trim($_POST['letter'][$i] ?? '');
        $new_width  = floatval($_POST['new_width'][$i] ?? ($original['width'] ?? 0));
        // Roll and coil stay the same as original; only lot may get a suffix
        $new_lot_no = $original['lot_no'] . $letter;       // '' = same lot
        $new_roll   = $original['roll_no'];                // R4 stays R4
        $exclude_id = $parent_slit_id ?? 0;

        // Check for duplicates within the current submission
        $batch_key = $new_lot_no . '|' . $original['coil_no'] . '|' . $new_roll . '|' . round($new_width, 2);
        if (isset($seen_in_batch[$batch_key])) {
            $duplicates[] = "{$new_lot_no} {$original['coil_no']} {$new_roll} (width {$new_width}mm)";
        }
        $seen_in_batch[$batch_key] = true;

        // Only check against OTHER non-voided rows (not the source itself) with matching width
        $chk = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM slitting_product
            WHERE lot_no = ? AND coil_no = ? AND roll_no = ?
              AND ABS(width - ?) < 0.5
              AND id != ?
              AND (is_voided = 0 OR is_voided IS NULL)
        ");
        $chk->bind_param("sssdi", $new_lot_no, $original['coil_no'], $new_roll, $new_width, $exclude_id);
        $chk->execute();
        $cnt = (int)($chk->get_result()->fetch_assoc()['cnt'] ?? 0);
        $chk->close();

        if ($cnt > 0) {
            $duplicates[] = "{$new_lot_no} {$original['coil_no']} {$new_roll} (width {$new_width}mm)";
        }
    }

    if (!empty($duplicates)) {
        $dupList = implode(', ', array_unique($duplicates));
        header("Location: recoiling.php?error=duplicate_lot&lots=" . urlencode($dupList) . "&open_id=$id");
        exit;
    }
}
// For rewinding and normal (cut defect): no duplicate check — same roll is intended.

$conn->begin_transaction();

try {
    // ── STEP 1: Void the source slitting_product row ──────────────────────
    // Free the UNIQUE KEY (lot_no, coil_no, roll_no, roll_key) so the new row(s) can
    // reuse the same reference values.
    if ($parent_slit_id) {
        if ($cut_type === 'cut_into_2') {
            $voided_lot = $original['lot_no'] . '_OLD_' . $parent_slit_id;

            $void_stmt = $conn->prepare("
                UPDATE slitting_product
                SET lot_no        = ?,
                    roll_key      = CONCAT(COALESCE(roll_key, ''), '_OLD_', ?),
                    is_recoiled   = 1,
                    is_voided     = 1,
                    voided_at     = NOW(),
                    voided_reason = ?
                WHERE id = ?
            ");
            $reason = 'replaced_by_recoil_cut_into_2';
            $void_stmt->bind_param("sisi", $voided_lot, $parent_slit_id, $reason, $parent_slit_id);
        } else {
            // rewinding / normal: rename roll_no to free unique key
            $voided_roll = $original['roll_no'] . '_void_' . $parent_slit_id;

            $void_stmt = $conn->prepare("
                UPDATE slitting_product
                SET roll_no       = ?,
                    roll_key      = CONCAT(COALESCE(roll_key, ''), '_OLD_', ?),
                    is_recoiled   = 1,
                    is_voided     = 1,
                    voided_at     = NOW(),
                    voided_reason = ?
                WHERE id = ?
            ");
            $reason = 'replaced_by_recoil_' . $cut_type;
            $void_stmt->bind_param("sisi", $voided_roll, $parent_slit_id, $reason, $parent_slit_id);
        }

        $void_stmt->execute();
        $void_stmt->close();

        $voided_ref = ($cut_type === 'cut_into_2') ? ($voided_lot ?? '') : ($voided_roll ?? '');
        log_process($conn, 'slitting', $parent_slit_id, $mother_id_val,
            'IN', 'IN',
            'voided_for_recoil',
            "renamed to {$voided_ref}, mode={$cut_type}, recoiling_product id={$id}"
        );
    }

    // ── Fetch pallet & customer/ref information from parent roll ───────────
    $palletItem      = null;
    $parent_customer = '';
    $parent_ref_no   = '';
    if ($parent_slit_id) {
        $parent_info_stmt = $conn->prepare("SELECT customer_name, ref_no FROM slitting_product WHERE id = ? LIMIT 1");
        $parent_info_stmt->bind_param("i", $parent_slit_id);
        $parent_info_stmt->execute();
        $parent_info = $parent_info_stmt->get_result()->fetch_assoc();
        $parent_info_stmt->close();
        if ($parent_info) {
            $parent_customer = trim($parent_info['customer_name'] ?? '');
            $parent_ref_no   = trim($parent_info['ref_no'] ?? '');
        }

        $p_stmt = $conn->prepare("
            SELECT pi.id AS item_id, pi.pallet_id, pi.seq, p.pallet_no,
                   p.customer_name AS pallet_customer, p.ref_no AS pallet_ref
            FROM pallet_items pi
            JOIN pallets p ON p.id = pi.pallet_id
            WHERE pi.slitting_product_id = ?
            LIMIT 1
        ");
        $p_stmt->bind_param("i", $parent_slit_id);
        $p_stmt->execute();
        $palletItem = $p_stmt->get_result()->fetch_assoc();
        $p_stmt->close();

        if ($palletItem) {
            if ($parent_customer === '' && !empty($palletItem['pallet_customer'])) {
                $parent_customer = trim($palletItem['pallet_customer']);
            }
            if ($parent_ref_no === '' && !empty($palletItem['pallet_ref'])) {
                $parent_ref_no = trim($palletItem['pallet_ref']);
            }
        }
    }

    // ── STEP 2: Insert new slitting_product row(s) ────────────────────────
    $total_actual_length = 0.0;
    $summary_width       = 0.0;
    $all_remarks         = [];
    $created_slit_ids       = [];
    $created_actual_lengths = [];

    for ($i = 0; $i < $total_rolls; $i++) {
        $new_width     = floatval($_POST['new_width'][$i]     ?? 0);
        $length        = floatval($_POST['length'][$i]        ?? 0);
        $defect        = floatval($_POST['defect'][$i]        ?? 0);
        $actual_length = floatval($_POST['actual_length'][$i] ?? 0);
        $remark        = trim($_POST['remark'][$i]            ?? '');
        $roll_number   = intval($_POST['roll_number'][$i]     ?? 1);
        $letter        = trim($_POST['letter'][$i]            ?? '');

        if ($is_same_roll) {
            // rewinding / cut defect — keep original lot/coil/roll
            $new_roll_no = $original['roll_no'];
            $new_lot_no  = $original['lot_no'];
        } else {
            // cut_into_2 — coil_no and roll_no stay the same as original.
            // lot_no gets an optional letter suffix ('' is fine because the
            // source row was voided with lot_no renamed to *_OLD_*).
            $new_roll_no = $original['roll_no'];
            $new_lot_no  = $original['lot_no'] . $letter;   // $letter may be ''
        }

        if (!empty($remark) || $defect > 0) {
            $r  = "{$new_lot_no} {$original['coil_no']} / {$new_roll_no} : ";
            $r .= ($defect > 0) ? "Defect {$defect}m" : "";
            if (!empty($remark)) $r .= ($defect > 0 ? " - " : "") . $remark;
            $all_remarks[] = $r;
        }

        // Generate unique DB roll_key incorporating width
        $base_roll_key   = $new_lot_no . '_' . $original['coil_no'] . '_' . $new_roll_no . '_' . round($new_width, 2);
        $target_roll_key = $base_roll_key;
        $chk_key = $conn->prepare("SELECT id FROM slitting_product WHERE roll_key = ? AND (is_voided = 0 OR is_voided IS NULL)");
        $chk_key->bind_param("s", $target_roll_key);
        $chk_key->execute();
        if ($chk_key->get_result()->num_rows > 0) {
            $suffix_num = 1;
            while (true) {
                $candidate = $base_roll_key . "_" . $suffix_num;
                $chk2 = $conn->prepare("SELECT id FROM slitting_product WHERE roll_key = ? AND (is_voided = 0 OR is_voided IS NULL)");
                $chk2->bind_param("s", $candidate);
                $chk2->execute();
                if ($chk2->get_result()->num_rows === 0) {
                    $target_roll_key = $candidate;
                    $chk2->close();
                    break;
                }
                $chk2->close();
                $suffix_num++;
            }
        }
        $chk_key->close();

        $prod_month_opt = trim($_POST['production_month'] ?? 'current');
        $prod_date      = resolveProductionMonthDate($prod_month_opt);
        $date_in_val    = ($prod_month_opt === 'current') ? date('Y-m-d H:i:s') : $prod_date . ' ' . date('H:i:s');
        $stock_code     = generateStockCode($conn, $prod_date);
        $insert_stmt = $conn->prepare("
            INSERT INTO slitting_product
                (recoiling_id, mother_id, parent_slit_id,
                 product, lot_no, coil_no, roll_no, roll_key,
                 width, length, actual_length,
                 status, is_completed, stock_counted,
                 original_source, source, date_in, stock_code,
                 customer_name, ref_no)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'IN', 1, 1, ?, 'recoiling', ?, ?, ?, ?)
        ");

        $insert_stmt->bind_param(
            "iiisssssdddsssss",
            $id,
            $mother_id_val,
            $parent_slit_id,
            $original['product'],
            $new_lot_no,
            $original['coil_no'],
            $new_roll_no,
            $target_roll_key,
            $new_width,
            $length,
            $actual_length,
            $original['original_source'],
            $date_in_val,
            $stock_code,
            $parent_customer,
            $parent_ref_no
        );

        if (!$insert_stmt->execute()) {
            throw new Exception("Insert failed: " . $insert_stmt->error);
        }
        $new_slit_id = $conn->insert_id;
        $created_slit_ids[]       = $new_slit_id;
        $created_actual_lengths[] = $actual_length;
        $insert_stmt->close();

        log_process($conn, 'slitting', $new_slit_id, $mother_id_val,
            null, 'IN', 'recoiling_output',
            "Mode={$cut_type} {$new_lot_no} {$original['coil_no']} {$new_roll_no} "
            . "actual={$actual_length}m width={$new_width}mm "
            . "from recoiling_product id={$id}"
        );

        $total_actual_length += $actual_length;
        if ($i === 0) $summary_width = $new_width;
    }

    $combined_remark = !empty($all_remarks) ? implode(" | ", $all_remarks) : "";

    // ── STEP 2.5: Auto-swap pallet slot if parent roll was on a pallet ────
    if ($palletItem && !empty($created_slit_ids)) {
        $primary_slit_id = $created_slit_ids[0];
        $primary_len = $created_actual_lengths[0] ?? $total_actual_length;
        $new_pallet_stock_code = PalletManager::formatStockCode(
            $original['coil_no'],
            $summary_width,
            $primary_len
        );

        $upd_item = $conn->prepare("
            UPDATE pallet_items
            SET slitting_product_id = ?, stock_code = ?
            WHERE id = ?
        ");
        $upd_item->bind_param("isi", $primary_slit_id, $new_pallet_stock_code, $palletItem['item_id']);
        $upd_item->execute();
        $upd_item->close();

        $palletId = (int)$palletItem['pallet_id'];
        $palletNo = $palletItem['pallet_no'];
        $upd_p = $conn->prepare("UPDATE pallets SET updated_at = NOW() WHERE id = ?");
        $upd_p->bind_param("i", $palletId);
        $upd_p->execute();
        $upd_p->close();

        $log_note = "Auto-replaced voided roll #{$parent_slit_id} with recoiled roll #{$primary_slit_id} (actual length: {$primary_len}m)";
        $edit_log = $conn->prepare("
            INSERT INTO pallet_edit_log
                (pallet_id, pallet_no, action, product_id, product_ref, performed_by, note)
            VALUES (?, ?, 'auto_recoil_swap', ?, ?, ?, ?)
        ");
        $performed_by = $_SESSION['role'] ?? 'slitting';
        $prod_ref = "{$original['lot_no']} {$original['coil_no']} " . ($original['roll_no'] ?? '');
        $edit_log->bind_param("isisss", $palletId, $palletNo, $primary_slit_id, $prod_ref, $performed_by, $log_note);
        $edit_log->execute();
        $edit_log->close();

        log_process($conn, 'pallet', $palletId, $mother_id_val, 'building', 'building', 'auto_recoil_swap', $log_note);
    }

    // ── STEP 3: Mark recoiling_product as completed ───────────────────────
    $update_stmt = $conn->prepare("
        UPDATE recoiling_product
        SET status       = 'completed',
            completed_at = NOW(),
            started_at   = COALESCE(started_at, NOW()),
            new_width    = ?,
            new_length   = ?,
            remark       = ?,
            cut_type     = ?
        WHERE id = ?
    ");
    $update_stmt->bind_param("ddssi",
        $summary_width, $total_actual_length, $combined_remark, $cut_type, $id);
    $update_stmt->execute();
    $update_stmt->close();

    log_process($conn, 'recoiling', $id, $mother_id_val,
        $original['status'] ?? 'in_progress', 'completed', 'recoiling_complete',
        "Mode={$cut_type}, rolls={$total_rolls}, total_length={$total_actual_length}m"
    );

    $conn->commit();
    $print_ids_param = !empty($created_slit_ids) ? implode(',', $created_slit_ids) : '';
    if (!empty($created_slit_ids)) {
        if (count($created_slit_ids) === 1) {
            header("Location: select_customer.php?id=" . $created_slit_ids[0] . "&from=recoiling");
        } else {
            header("Location: mixed_batch_setup.php?ids=" . urlencode($print_ids_param) . "&from=recoiling");
        }
    } else {
        header("Location: recoiling.php?success=completed&id=$id");
    }
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    header("Location: recoiling.php?error=process_failed&msg=" . urlencode($e->getMessage()));
    exit;
}
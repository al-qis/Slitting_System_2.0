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
    for ($i = 0; $i < $total_rolls; $i++) {
        $letter     = trim($_POST['letter'][$i] ?? '');
        // Roll and coil stay the same as original; only lot may get a suffix
        $new_lot_no = $original['lot_no'] . $letter;       // '' = same lot
        $new_roll   = $original['roll_no'];                // R4 stays R4
        $exclude_id = $parent_slit_id ?? 0;

        // Only check against OTHER non-voided rows (not the source itself)
        $chk = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM slitting_product
            WHERE lot_no = ? AND coil_no = ? AND roll_no = ?
              AND id != ?
              AND (is_voided = 0 OR is_voided IS NULL)
        ");
        $chk->bind_param("sssi", $new_lot_no, $original['coil_no'], $new_roll, $exclude_id);
        $chk->execute();
        $cnt = (int)($chk->get_result()->fetch_assoc()['cnt'] ?? 0);
        $chk->close();

        if ($cnt > 0) {
            $duplicates[] = "{$new_lot_no} {$original['coil_no']} {$new_roll}";
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
    // Free the UNIQUE KEY (lot_no, coil_no, roll_no) so the new row(s) can
    // reuse the same reference values.
    //
    // cut_into_2 strategy: rename lot_no → "lot_OLD_{id}" (keeps roll_no
    //   intact; both output rolls inherit the original roll_no).
    // rewinding / cut defect: rename roll_no → "R1_void_{id}" as before.
    if ($parent_slit_id) {
        if ($cut_type === 'cut_into_2') {
            $voided_lot = $original['lot_no'] . '_OLD_' . $parent_slit_id;

            $void_stmt = $conn->prepare("
                UPDATE slitting_product
                SET lot_no        = ?,
                    is_recoiled   = 1,
                    is_voided     = 1,
                    voided_at     = NOW(),
                    voided_reason = ?
                WHERE id = ?
            ");
            $reason = 'replaced_by_recoil_cut_into_2';
            $void_stmt->bind_param("ssi", $voided_lot, $reason, $parent_slit_id);
        } else {
            // rewinding / normal: rename roll_no to free unique key
            $voided_roll = $original['roll_no'] . '_void_' . $parent_slit_id;

            $void_stmt = $conn->prepare("
                UPDATE slitting_product
                SET roll_no       = ?,
                    is_recoiled   = 1,
                    is_voided     = 1,
                    voided_at     = NOW(),
                    voided_reason = ?
                WHERE id = ?
            ");
            $reason = 'replaced_by_recoil_' . $cut_type;
            $void_stmt->bind_param("ssi", $voided_roll, $reason, $parent_slit_id);
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
            "Mode={$cut_type} {$new_lot_no} {$original['coil_no']} {$new_roll_no} "
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
            remark       = ?,
            cut_type     = ?
        WHERE id = ?
    ");
    $update_stmt->bind_param("ddssi",
        $summary_width, $total_actual_length, $combined_remark, $cut_type, $id);
    $update_stmt->execute();
    $update_stmt->close();

    log_process($conn, 'recoiling', $id, $mother_id_val,
        'pending', 'completed', 'recoiling_complete',
        "Mode={$cut_type}, rolls={$total_rolls}, total_length={$total_actual_length}m"
    );

    $conn->commit();
    header("Location: recoiling.php?success=completed&id=$id");
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    header("Location: recoiling.php?error=process_failed&msg=" . urlencode($e->getMessage()));
    exit;
}
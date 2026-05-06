<?php
// recoiling_handler.php — UPDATED (Schema Migration v2)
// Changes vs original:
//   • Each new slitting_product row (recoil output) gets parent_slit_id set
//     to the original slitting_product row via recoiling_product.slitting_product_id
//   • Every insert/update writes to process_log

session_start();
include 'config.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ── Helper: write one process_log row ──────────────────────────
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
        $from_status,  $to_status,
        $performed_by, $action_detail, $remark
    );
    $stmt->execute();
    $stmt->close();
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'start_and_complete_recoiling'
) {
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

    // 1. Get original recoiling_product record
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

    // UPDATED: get the parent slitting_product_id for traceability chain
    $parent_slit_id = $original['slitting_product_id'] ?? null;
    $mother_id_val  = $original['mother_id'] ?? null;

    // ── PRE-FLIGHT: duplicate lot check ───────────────────────
    $duplicateLots = [];
    for ($i = 0; $i < $total_rolls; $i++) {
        $letter     = trim($_POST['letter'][$i] ?? '');
        $new_lot_no = $original['lot_no'] . ($letter !== '' ? $letter : '');

        $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM slitting_product WHERE lot_no = ?");
        $chk->bind_param("s", $new_lot_no);
        $chk->execute();
        $cnt = (int)($chk->get_result()->fetch_assoc()['cnt'] ?? 0);
        $chk->close();

        if ($cnt > 0) { $duplicateLots[] = $new_lot_no; }
    }

    if (!empty($duplicateLots)) {
        $dupList = implode(', ', array_unique($duplicateLots));
        header("Location: recoiling.php?error=duplicate_lot&lots=" . urlencode($dupList) . "&open_id=$id");
        exit;
    }

    $conn->begin_transaction();

    try {
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

            $new_roll_no = 'R' . $roll_number;
            $new_lot_no  = $original['lot_no'] . ($letter !== '' ? $letter : '');

            if (!empty($remark) || $defect > 0 || $letter !== '') {
                $r  = "{$new_lot_no} {$original['coil_no']} / {$new_roll_no} : ";
                $r .= ($defect > 0) ? "Defect {$defect}m" : "";
                if (!empty($remark)) $r .= ($defect > 0 ? " - " : "") . $remark;
                $all_remarks[] = $r;
            }

            // UPDATED: parent_slit_id links this output back to the original slit roll
            // recoiling_id links it to the recoiling_product row
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
                "iiisssssdds",
                $id,
                $mother_id_val,
                $parent_slit_id,          // ← NEW: chain back to original roll
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

            // Log this new recoil output roll
            log_process($conn, 'slitting', $new_slit_id, $mother_id_val,
                null, 'IN', 'recoiling_output',
                "Roll {$new_roll_no} from recoiling_product id={$id}, parent_slit_id={$parent_slit_id}");

            $total_actual_length += $actual_length;
            if ($i === 0) $summary_width = $new_width;
        }

        $combined_remark = !empty($all_remarks) ? implode(" | ", $all_remarks) : "";

        // 3. Mark recoiling_product as completed
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
        $update_stmt->bind_param("ddsi", $summary_width, $total_actual_length, $combined_remark, $id);
        $update_stmt->execute();
        $update_stmt->close();

        log_process($conn, 'recoiling', $id, $mother_id_val,
            'pending', 'completed', 'recoiling_complete',
            "Output rolls: {$total_rolls}, total_length={$total_actual_length}m");

        $conn->commit();
        header("Location: recoiling.php?success=completed&id=$id");
        exit;

    } catch (Throwable $e) {
        $conn->rollback();
        header("Location: recoiling.php?error=process_failed&msg=" . urlencode($e->getMessage()));
        exit;
    }
}
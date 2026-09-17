<?php
// reslit_send_back.php
// ============================================================
// "Send Back" — a true undo of whatever action put this row into
// Reslit, while it's still pending. Mirrors recoiling_send_back.php:
// the reslit_product row is deleted outright (no soft-cancel status)
// and the item is restored in its ORIGINAL source module, wherever
// that was. process_log / source_tracking_log keep the append-only
// history, but nothing "cancelled" ever shows up in a working queue.
//
// Routing rule:
//   - reslit_product.slitting_product_id is set  -> came from
//     Finish Product's "Send to Reslit" action. Restore the
//     slitting_product row (is_reslitted = 0).
//   - reslit_product.slitting_product_id is NOT set -> came from
//     SFC Inventory's "Reslit" action (see sfc.php). Restore the
//     originating sfc row (date_out = NULL, action = NULL).
//     The exact sfc row is identified via source_sfc_id when present;
//     for legacy rows created before that column existed, fall back
//     to matching the most recently "used-by-reslit" sfc row with the
//     same lot/coil/roll.
// ============================================================

session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}
if ($_SESSION['role'] !== 'slitting') {
    die("Access denied");
}

include 'config.php';

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

function log_source_tracking(mysqli $conn, int $product_id, string $table_name, string $original_source, string $current_source, string $action): void {
    $stmt = $conn->prepare("
        INSERT INTO source_tracking_log
            (product_id, table_name, original_source, current_source, action)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("issss", $product_id, $table_name, $original_source, $current_source, $action);
    $stmt->execute();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: reslit.php");
    exit;
}

$id = intval($_POST['id'] ?? 0);

if ($id <= 0) {
    header("Location: reslit.php?error=invalid_id");
    exit;
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("SELECT * FROM reslit_product WHERE id=? FOR UPDATE");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $rp = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$rp) {
        throw new RuntimeException("not_found");
    }
    if ($rp['status'] !== 'pending') {
        throw new RuntimeException("not_pending");
    }

    $mid             = isset($rp['mother_id']) ? (int)$rp['mother_id'] : null;
    $original_source = $rp['original_source'] ?? 'raw_material';
    $slittingId      = isset($rp['slitting_product_id']) ? (int)$rp['slitting_product_id'] : 0;

    if ($slittingId > 0) {
        // ════════════════════════════════════════════════════
        // ROUTE 1: came from Finish Product -> restore it there
        // ════════════════════════════════════════════════════

        // 1) Restore the Finished Product roll to active.
        $stmt = $conn->prepare("UPDATE slitting_product SET is_reslitted=0 WHERE id=?");
        $stmt->bind_param("i", $slittingId);
        $stmt->execute();
        $stmt->close();

        // 2) Delete the reslit_product row outright — a true undo, not a
        //    soft-cancel. Any reslit_rolls children are removed too via
        //    ON DELETE CASCADE (there won't be any yet for a pending row).
        $stmt = $conn->prepare("DELETE FROM reslit_product WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        // 3) Audit log on both entities.
        log_process($conn, 'reslit', $id, $mid,
            'pending', 'returned', 'sent_back_to_finished_product',
            "Sent back to Finished Product; slitting_product id={$slittingId}");
        log_process($conn, 'slitting', $slittingId, $mid,
            'OUT', 'IN', 'returned_from_reslit',
            "Returned from reslit_product id={$id}");

        log_source_tracking($conn, $slittingId, 'reslit_product', $original_source, 'finished_product', 'sent_back_to_finished_product');

        $conn->commit();
        header("Location: reslit.php?success=returned_fp");
        exit;

    } else {
        // ════════════════════════════════════════════════════
        // ROUTE 2: came from SFC Inventory -> restore it there
        // ════════════════════════════════════════════════════

        $sfcRow      = null;
        $sourceSfcId = isset($rp['source_sfc_id']) ? (int)$rp['source_sfc_id'] : 0;

        if ($sourceSfcId > 0) {
            $stmt = $conn->prepare("SELECT * FROM sfc WHERE sfc_id=? AND is_deleted=0 FOR UPDATE");
            $stmt->bind_param("i", $sourceSfcId);
            $stmt->execute();
            $sfcRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        // Legacy fallback: rows created before source_sfc_id was captured
        // don't carry a direct link, so match the most recent sfc row
        // that was marked "used" by this exact reslit action on the
        // same lot/coil/roll.
        if (!$sfcRow) {
            $stmt = $conn->prepare("
                SELECT * FROM sfc
                WHERE lot_no=? AND coil_no=? AND roll_no=?
                  AND is_deleted=0 AND date_out IS NOT NULL AND action='RESLIT'
                ORDER BY date_out DESC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->bind_param("sss", $rp['lot_no'], $rp['coil_no'], $rp['roll_no']);
            $stmt->execute();
            $sfcRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        if (!$sfcRow) {
            throw new RuntimeException("no_source_sfc");
        }

        $sfcId = (int)$sfcRow['sfc_id'];

        // 1) Restore the SFC row to active (same shape as a fresh entry:
        //    date_out IS NULL, action IS NULL).
        $stmt = $conn->prepare("UPDATE sfc SET date_out=NULL, action=NULL WHERE sfc_id=?");
        $stmt->bind_param("i", $sfcId);
        $stmt->execute();
        $stmt->close();

        // 2) Delete the reslit_product row outright.
        $stmt = $conn->prepare("DELETE FROM reslit_product WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        // 3) Audit log on both entities.
        log_process($conn, 'reslit', $id, $mid,
            'pending', 'returned', 'sent_back_to_sfc',
            "Sent back to SFC Inventory; sfc id={$sfcId}");
        log_process($conn, 'sfc', $sfcId, $mid,
            'OUT', 'IN', 'returned_from_reslit',
            "Returned from reslit_product id={$id}");

        log_source_tracking($conn, $sfcId, 'reslit_product', $original_source, 'sfc', 'sent_back_to_sfc');

        $conn->commit();
        header("Location: reslit.php?success=returned_sfc");
        exit;
    }

} catch (Throwable $e) {
    $conn->rollback();
    $reason = $e->getMessage();

    if ($reason === 'not_found') {
        header("Location: reslit.php?error=not_found");
    } elseif ($reason === 'not_pending') {
        header("Location: reslit.php?error=not_pending");
    } elseif ($reason === 'no_source_sfc') {
        header("Location: reslit.php?" . http_build_query([
            'error' => 'return_failed',
            'msg'   => 'This record has no originating SFC entry to return to (it may already have been restored, deleted, or reused).',
        ]));
    } else {
        header("Location: reslit.php?" . http_build_query([
            'error' => 'return_failed',
            'msg'   => $reason,
        ]));
    }
    exit;
}
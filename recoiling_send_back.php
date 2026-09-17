<?php
// recoiling_send_back.php
// ============================================================
// "Send Back to Finished Product" — a true undo of send_to_recoiling
// while the item is still pending. This is NOT a soft-cancel: the
// recoiling_product row is deleted outright and the Finished Product
// roll is restored exactly as if it had never been sent, so no new
// status value or schema change is required.
//
// The process_log / source_tracking_log entries stay (they're an
// append-only audit trail, not the working record), so there's still
// a paper trail of "sent to recoiling, then sent back" if anyone
// needs to look it up later — the user just never sees a "cancelled"
// item sitting in the list.
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

// ── Helper: write one process_log row (mirrors finish_product.php) ──
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: recoiling.php");
    exit;
}

$id = intval($_POST['id'] ?? 0);

if ($id <= 0) {
    header("Location: recoiling.php?error=invalid_id");
    exit;
}

$conn->begin_transaction();
try {
    // Lock the recoiling_product row so a concurrent "Recoil" click
    // can't process it out from under this undo.
    $stmt = $conn->prepare("SELECT * FROM recoiling_product WHERE id=? FOR UPDATE");
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

    $slittingId = isset($rp['slitting_product_id']) ? (int)$rp['slitting_product_id'] : 0;
    if ($slittingId <= 0) {
        // Nothing to revert to — this recoiling record wasn't created
        // from a Finished Product roll.
        throw new RuntimeException("no_source_finished_product");
    }

    $mid             = isset($rp['mother_id']) ? (int)$rp['mother_id'] : null;
    $original_source = $rp['original_source'] ?? 'raw_material';

    // 1) Restore the Finished Product roll to active.
    $stmt = $conn->prepare("UPDATE slitting_product SET is_recoiled=0 WHERE id=?");
    $stmt->bind_param("i", $slittingId);
    $stmt->execute();
    $stmt->close();

    // 2) Delete the recoiling_product row outright — a true undo,
    //    not a soft-cancel. It disappears from the queue completely.
    $stmt = $conn->prepare("DELETE FROM recoiling_product WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // 3) Audit log on both entities (append-only history; doesn't
    //    affect what shows up in the working queue).
    log_process($conn, 'recoiling', $id, $mid,
        'pending', 'returned', 'sent_back_to_finished_product',
        "Sent back to Finished Product; slitting_product id={$slittingId}");
    log_process($conn, 'slitting', $slittingId, $mid,
        'OUT', 'IN', 'returned_from_recoiling',
        "Returned from recoiling_product id={$id}");

    $stmt = $conn->prepare("
        INSERT INTO source_tracking_log
            (product_id, table_name, original_source, current_source, action)
        VALUES (?, 'recoiling_product', ?, 'finished_product', 'sent_back_to_finished_product')
    ");
    $stmt->bind_param("is", $slittingId, $original_source);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    header("Location: recoiling.php?success=returned");
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    $reason = $e->getMessage();

    if ($reason === 'not_found') {
        header("Location: recoiling.php?error=not_found");
    } elseif ($reason === 'not_pending') {
        header("Location: recoiling.php?error=not_pending");
    } elseif ($reason === 'no_source_finished_product') {
        header("Location: recoiling.php?" . http_build_query([
            'error' => 'return_failed',
            'msg'   => 'This record has no originating Finished Product roll to return to.',
        ]));
    } else {
        header("Location: recoiling.php?" . http_build_query([
            'error' => 'return_failed',
            'msg'   => $reason,
        ]));
    }
    exit;
}

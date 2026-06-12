<?php
// pallet_qc_action.php — v3: saves checked_by + top-roll checklist state
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'qc') {
    die("Access denied");
}

// ============================================================
// HELPERS
// ============================================================
function getPallet(mysqli $conn, int $pallet_id): ?array {
    $stmt = $conn->prepare("SELECT * FROM pallets WHERE id = ?");
    $stmt->bind_param("i", $pallet_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Save the top-roll checklist state to pallet_items.
 * $topProductId is the slitting_product.id whose both checkboxes were ticked.
 * All other rows on the same pallet are explicitly cleared (defensive reset).
 */
function saveChecklistState(
    mysqli $conn,
    int    $pallet_id,
    int    $top_product_id
): void {
    // Clear all rows for this pallet first (ensures only one "top roll" exists)
    $clear = $conn->prepare("
        UPDATE pallet_items
        SET winding_condition = 0,
            hairy_rubber      = 0,
            qc_checked_at     = NULL
        WHERE pallet_id = ?
    ");
    $clear->bind_param("i", $pallet_id);
    $clear->execute();
    $clear->close();

    // Mark the specific row
    $mark = $conn->prepare("
        UPDATE pallet_items
        SET winding_condition = 1,
            hairy_rubber      = 1,
            qc_checked_at     = NOW()
        WHERE pallet_id = ?
          AND slitting_product_id = ?
    ");
    $mark->bind_param("ii", $pallet_id, $top_product_id);
    $mark->execute();
    $mark->close();
}

function approvePallet(
    mysqli $conn,
    int    $pallet_id,
    string $checked_by,
    int    $top_product_id
): array {
    $conn->begin_transaction();
    try {
        // 1. Save checklist state before changing pallet status
        saveChecklistState($conn, $pallet_id, $top_product_id);

        // 2. Approve the pallet
        $stmt = $conn->prepare("
            UPDATE pallets
            SET status     = 'approved',
                qc_comment = NULL,
                checked_by = ?
            WHERE id = ? AND status = 'pending_qc'
        ");
        $stmt->bind_param("si", $checked_by, $pallet_id);
        $stmt->execute();
        if ($stmt->affected_rows === 0) {
            throw new RuntimeException(
                'Pallet is not pending QC (may have already been processed).'
            );
        }
        $stmt->close();

        // 3. Update all rolls to APPROVED
        $stmt = $conn->prepare("
            UPDATE slitting_product sp
            JOIN pallet_items pi ON pi.slitting_product_id = sp.id
            SET sp.status = 'APPROVED'
            WHERE pi.pallet_id = ?
        ");
        $stmt->bind_param("i", $pallet_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}

function rejectPallet(
    mysqli $conn,
    int    $pallet_id,
    string $comment,
    string $checked_by,
    int    $top_product_id
): array {
    $conn->begin_transaction();
    try {
        // 1. Save checklist state
        saveChecklistState($conn, $pallet_id, $top_product_id);

        // 2. Reject the pallet
        $stmt = $conn->prepare("
            UPDATE pallets
            SET status     = 'rejected',
                qc_comment = ?,
                checked_by = ?
            WHERE id = ? AND status = 'pending_qc'
        ");
        $stmt->bind_param("ssi", $comment, $checked_by, $pallet_id);
        $stmt->execute();
        if ($stmt->affected_rows === 0) {
            throw new RuntimeException(
                'Pallet is not pending QC (may have already been processed).'
            );
        }
        $stmt->close();

        // 3. Update rolls to REJECTED with comment
        $stmt = $conn->prepare("
            UPDATE slitting_product sp
            JOIN pallet_items pi ON pi.slitting_product_id = sp.id
            SET sp.status     = 'REJECTED',
                sp.qc_comment = ?
            WHERE pi.pallet_id = ?
        ");
        $stmt->bind_param("si", $comment, $pallet_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}
// ============================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: qc_dashboard.php");
    exit;
}

$action          = $_POST['action']          ?? '';
$pallet_id       = intval($_POST['pallet_id']       ?? 0);
$checked_by      = trim($_POST['checked_by']      ?? '');
$top_product_id  = intval($_POST['top_product_id']  ?? 0);

if ($pallet_id <= 0) {
    header("Location: qc_dashboard.php?error=invalid_id");
    exit;
}

// Server-side guard: inspector must be set
if ($checked_by === '') {
    header("Location: qc_dashboard.php?error=" .
        urlencode('Please select an inspector name before proceeding.'));
    exit;
}

// Server-side guard: a top roll must have been identified
if ($top_product_id <= 0) {
    header("Location: qc_dashboard.php?error=" .
        urlencode('Please tick both checklist items on a product row before proceeding.'));
    exit;
}

if ($action === 'approve') {
    $result = approvePallet($conn, $pallet_id, $checked_by, $top_product_id);
    header($result['ok']
        ? "Location: qc_dashboard.php?approved=1"
        : "Location: qc_dashboard.php?error=" . urlencode($result['msg'] ?? 'approve_failed')
    );
    exit;
}

if ($action === 'reject') {
    $comment = trim($_POST['comment'] ?? '');
    if ($comment === '') {
        header("Location: qc_dashboard.php?error=comment_required");
        exit;
    }
    $result = rejectPallet($conn, $pallet_id, $comment, $checked_by, $top_product_id);
    header($result['ok']
        ? "Location: qc_dashboard.php?rejected=1"
        : "Location: qc_dashboard.php?error=" . urlencode($result['msg'] ?? 'reject_failed')
    );
    exit;
}

header("Location: qc_dashboard.php");
exit;
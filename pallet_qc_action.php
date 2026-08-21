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
    // Ensure coil_width column exists in pallet_items table
    $colCheck = $conn->query("SHOW COLUMNS FROM pallet_items LIKE 'coil_width'");
    if ($colCheck && $colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE pallet_items ADD COLUMN coil_width TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Coil Width checkbox was ticked by QC inspector' AFTER hairy_rubber");
    }

    // Clear all rows for this pallet first (ensures only one "top roll" exists)
    $clear = $conn->prepare("
        UPDATE pallet_items
        SET winding_condition = 0,
            hairy_rubber      = 0,
            coil_width        = 0,
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
            coil_width        = 1,
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
        // 1. Save checklist state — but ONLY if the inspector actually
        //    ticked a row before rejecting. top_product_id is optional
        //    for reject, so a value of 0 means "nothing was ticked" —
        //    in that case, skip this step entirely rather than running
        //    saveChecklistState()'s unconditional wipe for no reason.
        if ($top_product_id > 0) {
            saveChecklistState($conn, $pallet_id, $top_product_id);
        }

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

if ($checked_by === '' && !empty($_SESSION['active_qc_inspector'])) {
    $checked_by = trim($_SESSION['active_qc_inspector']);
}

if ($pallet_id <= 0) {
    header("Location: qc_dashboard.php?error=invalid_id");
    exit;
}

// Server-side guard: inspector must be set (required for BOTH actions)
if ($checked_by === '') {
    header("Location: qc_dashboard.php?error=" .
        urlencode('Please select an active inspector name in the header navbar before proceeding.'));
    exit;
}

// NOTE: the "top roll must have been identified" guard used to sit here,
// unconditionally, before the $action branch -- which meant Reject was
// being blocked by the same checklist requirement as Approve. That was
// the bug: per spec, Reject should NEVER require the checklist to be
// ticked. The guard now lives INSIDE the approve branch only, below.

if ($action === 'approve') {
    // Server-side guard: a top roll must have been identified — APPROVE ONLY.
    if ($top_product_id <= 0) {
        header("Location: qc_dashboard.php?error=" .
            urlencode('Please tick both checklist items on a product row before proceeding.'));
        exit;
    }

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
    // top_product_id is OPTIONAL for reject — the inspector did not need
    // to tick the checklist. If they happened to tick it anyway before
    // rejecting, $top_product_id will be > 0 and that row is still
    // recorded; if not, it stays 0 and rejectPallet() below skips the
    // "mark a row" step entirely (see the int>0 guard added there).
    $result = rejectPallet($conn, $pallet_id, $comment, $checked_by, $top_product_id);
    header($result['ok']
        ? "Location: qc_dashboard.php?rejected=1"
        : "Location: qc_dashboard.php?error=" . urlencode($result['msg'] ?? 'reject_failed')
    );
    exit;
}

header("Location: qc_dashboard.php");
exit;
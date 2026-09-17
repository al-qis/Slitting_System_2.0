<?php
// =============================================================
// pallet_deliver.php
// PLACEMENT: C:\Apache24\htdocs\slitting_system\pallet_deliver.php
//
// PURPOSE:
//   Single entry point for Pallet Bundle Delivery.
//   Called from:
//     (A) scan_product_action.php  → when operator scans any roll
//         that is already on an APPROVED pallet
//     (B) pallet.php / pallet table "Deliver" button  (POST form)
//     (C) AJAX from finish_product.php scan feedback popup
//
// WORKFLOW:
//   1. Receive pallet_id (from QR scan path) or derive it from
//      a product_id.
//   2. Validate pallet is in 'approved' state.
//   3. In a single transaction:
//        • UPDATE pallets  → status='delivered', delivered_at=NOW()
//        • UPDATE slitting_product (via JOIN) → status='DELIVERED',
//          delivered_at=NOW()  for every roll on that pallet
//   4. Redirect back with success/error param.
//
// EDGE CASES HANDLED:
//   • Pallet not found
//   • Pallet not in 'approved' state (building / pending_qc /
//     rejected / already delivered)
//   • Product not on any pallet (pallet_id = NULL)
//   • Roll already delivered (status = 'DELIVERED')
//   • Concurrent double-scan (transaction + affected_rows check)
// =============================================================

session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

// Both slitting operators and QC staff can trigger delivery
if (!in_array($_SESSION['role'], ['slitting', 'qc'], true)) {
    http_response_code(403);
    die("Access denied");
}

include 'config.php';

$performed_by = $_SESSION['role'] ?? 'system';

// ── Determine if this is an AJAX call ────────────────────────
$is_ajax = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || ($_POST['ajax'] ?? '') === '1'
    || ($_GET['ajax']  ?? '') === '1'
);

// ── Helper: unified response ──────────────────────────────────
function respond(bool $ok, string $msg, array $extra = [], bool $is_ajax = false, string $redirect = ''): void
{
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(array_merge(['ok' => $ok, 'msg' => $msg], $extra));
        exit;
    }
    if ($redirect) {
        header("Location: $redirect");
        exit;
    }
    // Fallback plain text
    http_response_code($ok ? 200 : 400);
    die($msg);
}

// ── Helper: write to process_log ─────────────────────────────
function log_deliver(
    mysqli $conn,
    string $entity_type,
    int    $entity_id,
    ?int   $mother_id,
    string $from_status,
    string $to_status,
    string $action_detail,
    string $performed_by
): void {
    $stmt = $conn->prepare("
        INSERT INTO process_log
            (entity_type, entity_id, mother_id,
             from_status, to_status, performed_by, action_detail)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) return;
    $stmt->bind_param(
        "siissss",
        $entity_type, $entity_id, $mother_id,
        $from_status, $to_status, $performed_by, $action_detail
    );
    $stmt->execute();
    $stmt->close();
}

// =============================================================
// MAIN LOGIC
// =============================================================

$pallet_id  = 0;
$product_id = 0;
$ref_month  = (int)date('m');
$ref_year   = (int)date('Y');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pallet_id  = intval($_POST['pallet_id']  ?? 0);
    $product_id = intval($_POST['product_id'] ?? 0);
    $ref_month  = intval($_POST['month']       ?? date('m'));
    $ref_year   = intval($_POST['year']        ?? date('Y'));
} else {
    $pallet_id  = intval($_GET['pallet_id']  ?? 0);
    $product_id = intval($_GET['product_id'] ?? 0);
    $ref_month  = intval($_GET['month']       ?? date('m'));
    $ref_year   = intval($_GET['year']        ?? date('Y'));
}

$back_url = "finish_product.php?month={$ref_month}&year={$ref_year}";

// ── Derive pallet_id from product_id if not supplied directly ─
if (!$pallet_id && $product_id) {
    $stmt = $conn->prepare("
        SELECT pi.pallet_id
        FROM pallet_items pi
        WHERE pi.slitting_product_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !$row['pallet_id']) {
        respond(false, 'This product is not assigned to any pallet. Assign it to a pallet before delivery.',
            ['code' => 'NO_PALLET', 'product_id' => $product_id],
            $is_ajax,
            $back_url . '&scan=no_pallet&pid=' . $product_id
        );
    }
    $pallet_id = (int)$row['pallet_id'];
}

if (!$pallet_id) {
    respond(false, 'No pallet ID provided.', ['code' => 'MISSING_ID'], $is_ajax, $back_url);
}

// =============================================================
// CORE: deliver_pallet_bundle()
//   Atomically delivers every roll on the pallet in one tx.
// =============================================================
function deliver_pallet_bundle(
    mysqli $conn,
    int    $pallet_id,
    string $performed_by
): array {

    // ── Step 1: fetch & lock pallet ──────────────────────────
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            SELECT id, pallet_no, status, delivered_at
            FROM pallets
            WHERE id = ?
            FOR UPDATE
        ");
        $stmt->bind_param("i", $pallet_id);
        $stmt->execute();
        $pallet = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // ── Guard: pallet must exist ─────────────────────────
        if (!$pallet) {
            $conn->rollback();
            return [
                'ok'   => false,
                'code' => 'NOT_FOUND',
                'msg'  => "Pallet #{$pallet_id} not found.",
            ];
        }

        $pallet_no = $pallet['pallet_no'];

        // ── Guard: already delivered (idempotent) ────────────
        if ($pallet['status'] === 'delivered') {
            $conn->rollback();
            return [
                'ok'          => true,   // not an error — just already done
                'code'        => 'ALREADY_DELIVERED',
                'msg'         => "Pallet {$pallet_no} was already delivered on {$pallet['delivered_at']}.",
                'pallet_no'   => $pallet_no,
                'pallet_id'   => $pallet_id,
                'roll_count'  => 0,
            ];
        }

        // ── Guard: must be approved before delivery ──────────
        $allowed_states = ['approved'];
        if (!in_array($pallet['status'], $allowed_states, true)) {
            $conn->rollback();
            $friendly = match($pallet['status']) {
                'building'   => 'still being built (not yet sent to QC).',
                'pending_qc' => 'waiting for QC approval.',
                'rejected'   => 'rejected by QC. Resolve rejected rolls before delivering.',
                default      => "in an undeliverable state: {$pallet['status']}.",
            };
            return [
                'ok'       => false,
                'code'     => 'WRONG_STATE',
                'msg'      => "Pallet {$pallet_no} is {$friendly}",
                'status'   => $pallet['status'],
                'pallet_no'=> $pallet_no,
            ];
        }

        // ── Step 2: count rolls (must have at least one) ─────
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM pallet_items
            WHERE pallet_id = ?
        ");
        $stmt->bind_param("i", $pallet_id);
        $stmt->execute();
        $roll_count = (int)$stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        if ($roll_count === 0) {
            $conn->rollback();
            return [
                'ok'   => false,
                'code' => 'EMPTY_PALLET',
                'msg'  => "Pallet {$pallet_no} has no rolls assigned to it.",
            ];
        }

        // ── Step 3: fetch roll IDs for process_log ───────────
        $stmt = $conn->prepare("
            SELECT pi.slitting_product_id AS product_id,
                   sp.mother_id,
                   sp.status AS prev_status
            FROM pallet_items pi
            JOIN slitting_product sp ON sp.id = pi.slitting_product_id
            WHERE pi.pallet_id = ?
        ");
        $stmt->bind_param("i", $pallet_id);
        $stmt->execute();
        $rolls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // ── Step 4: UPDATE pallets row ───────────────────────
        $stmt = $conn->prepare("
            UPDATE pallets
            SET status       = 'delivered',
                delivered_at = NOW()
            WHERE id = ?
              AND status = 'approved'   -- extra safety: re-check inside tx
        ");
        $stmt->bind_param("i", $pallet_id);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            // Race condition: another request delivered it first
            $stmt->close();
            $conn->rollback();
            return [
                'ok'   => true,
                'code' => 'ALREADY_DELIVERED',
                'msg'  => "Pallet {$pallet_no} was just delivered by another session.",
                'pallet_no'  => $pallet_no,
                'pallet_id'  => $pallet_id,
                'roll_count' => 0,
            ];
        }
        $stmt->close();

        // ── Step 5: UPDATE all rolls on the pallet ───────────
        // Single JOIN UPDATE — no PHP loop, no N+1 queries.
        $stmt = $conn->prepare("
            UPDATE slitting_product sp
            JOIN pallet_items pi ON pi.slitting_product_id = sp.id
            SET sp.status       = 'DELIVERED',
                sp.delivered_at = NOW()
            WHERE pi.pallet_id  = ?
        ");
        $stmt->bind_param("i", $pallet_id);
        $stmt->execute();
        $updated_rolls = $stmt->affected_rows;
        $stmt->close();

        // ── Step 6: process_log for each roll ────────────────
        foreach ($rolls as $r) {
            log_deliver(
                $conn,
                'slitting',
                (int)$r['product_id'],
                $r['mother_id'] ? (int)$r['mother_id'] : null,
                $r['prev_status'],
                'DELIVERED',
                "bundle_delivery pallet_id={$pallet_id} pallet_no={$pallet_no}",
                $performed_by
            );
        }

        $conn->commit();

        return [
            'ok'         => true,
            'code'       => 'DELIVERED',
            'msg'        => "Pallet {$pallet_no} delivered — {$updated_rolls} roll(s) updated.",
            'pallet_no'  => $pallet_no,
            'pallet_id'  => $pallet_id,
            'roll_count' => $updated_rolls,
        ];

    } catch (Throwable $e) {
        $conn->rollback();
        return [
            'ok'   => false,
            'code' => 'DB_ERROR',
            'msg'  => 'Database error: ' . $e->getMessage(),
        ];
    }
}

// =============================================================
// EXECUTE & RESPOND
// =============================================================
$result = deliver_pallet_bundle($conn, $pallet_id, $performed_by);

if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// Non-AJAX redirect
if ($result['ok']) {
    $code = $result['code'];
    if ($code === 'DELIVERED') {
        $cnt = $result['roll_count'];
        header("Location: {$back_url}&scan=pallet_delivered"
            . "&pallet_no=" . urlencode($result['pallet_no'])
            . "&roll_count={$cnt}");
    } else {
        // ALREADY_DELIVERED
        header("Location: {$back_url}&scan=already_delivered_pallet"
            . "&pallet_no=" . urlencode($result['pallet_no'] ?? ''));
    }
} else {
    header("Location: {$back_url}&scan=deliver_error"
        . "&code=" . urlencode($result['code'])
        . "&msg="  . urlencode($result['msg']));
}
exit;
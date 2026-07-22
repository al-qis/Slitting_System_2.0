<?php
// reslit_intake.php
// ============================================================
// AJAX intake endpoint for the Reslit page's unified intake bar.
// Mirrors recoiling_intake.php exactly, targeting reslit_product /
// is_reslitted instead of recoiling_product / is_recoiled. One
// always-focused text field accepts hardware scanner input,
// camera-scanned QR content, or manual typing of a combined
// Lot-Coil-Roll string — all parsed server-side here via
// parseIntakeString().
//
// Same safeguards as recoiling_intake.php: transaction + row lock,
// status/duplicate checks, is_reslitted flag, audit trail — none of
// which the older per-button flow had.
// ============================================================

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['role'])) {
    echo json_encode(['ok' => false, 'msg' => 'Session expired — please log in again.']);
    exit;
}
if ($_SESSION['role'] !== 'slitting') {
    echo json_encode(['ok' => false, 'msg' => 'Access denied.']);
    exit;
}

include 'config.php';

// ── Helper: write one process_log row (shared pattern used across the app) ──
if (!function_exists('log_process')) {
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
}

// ── Parse a single combined intake string into Lot No / Coil No / Roll No ──
// Identical rules to recoiling_intake.php's parser — kept as its own copy
// (rather than a shared include) so this file stays a self-contained drop-in,
// matching how the rest of this app's per-page handlers are structured.
if (!function_exists('parseIntakeString')) {
    function parseIntakeString(string $raw): array {
        $raw = trim($raw);

        // 1. QR / scan format: "LOT=826529;COIL=N-2;ROLL=R4"
        if (preg_match('/LOT=([^;]+);COIL=([^;]+)(?:;ROLL=([^;]+))?/i', $raw, $m)) {
            return [
                'lot_no'  => trim($m[1]),
                'coil_no' => trim($m[2]),
                'roll_no' => trim($m[3] ?? ''),
            ];
        }

        // 2 & 3. Combined typed entry — dash- or space-separated (or a mix),
        // anchored on the actual SHAPE of each field so a Coil No's own
        // internal hyphen (e.g. "N-2") is never mistaken for a separator.
        if (preg_match('/^(\d+[a-z]?)[\s\-]+([a-z]+-?\d+)[\s\-]+(r-?\d+)$/i', $raw, $m)) {
            return [
                'lot_no'  => $m[1],
                'coil_no' => $m[2],
                'roll_no' => $m[3],
            ];
        }

        // Fallback: naive 3-token whitespace split
        $tokens = preg_split('/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (count($tokens) === 3) {
            return ['lot_no' => $tokens[0], 'coil_no' => $tokens[1], 'roll_no' => $tokens[2]];
        }

        return ['lot_no' => '', 'coil_no' => '', 'roll_no' => ''];
    }
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$source = trim($body['source'] ?? ($_POST['source'] ?? 'unified')); // for the log only

// Preferred path: a single raw string from the unified intake bar.
if (isset($body['raw']) || isset($_POST['raw'])) {
    $raw = trim($body['raw'] ?? ($_POST['raw'] ?? ''));
    if ($raw === '') {
        echo json_encode(['ok' => false, 'msg' => 'Type or scan a Lot No, Coil No, and Roll No first.']);
        exit;
    }
    $parsed  = parseIntakeString($raw);
    $lot_no  = $parsed['lot_no'];
    $coil_no = $parsed['coil_no'];
    $roll_no = $parsed['roll_no'];

    if ($lot_no === '' || $coil_no === '' || $roll_no === '') {
        echo json_encode([
            'ok'  => false,
            'msg' => "Couldn't read \"{$raw}\" as Lot No + Coil No + Roll No. Try a format like " .
                     "826529-N-2-R4 or 826529 N-2 R4.",
        ]);
        exit;
    }
} else {
    // Backward-compatible path: already-split fields.
    $lot_no  = trim($body['lot_no']  ?? ($_POST['lot_no']  ?? ''));
    $coil_no = trim($body['coil_no'] ?? ($_POST['coil_no'] ?? ''));
    $roll_no = trim($body['roll_no'] ?? ($_POST['roll_no'] ?? ''));
}

// ── All three fields are required to uniquely identify one roll ──────
if ($lot_no === '' || $coil_no === '' || $roll_no === '') {
    echo json_encode(['ok' => false, 'msg' => 'Lot No, Coil No, and Roll No are all required.']);
    exit;
}

$conn->begin_transaction();
try {
    // Lock the matching slitting_product row for the duration of this
    // transaction, so two simultaneous scans of the same roll can't both
    // succeed.
    $stmt = $conn->prepare("
        SELECT * FROM slitting_product
        WHERE LOWER(lot_no) = LOWER(?) AND LOWER(coil_no) = LOWER(?) AND LOWER(roll_no) = LOWER(?)
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->bind_param("sss", $lot_no, $coil_no, $roll_no);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) {
        throw new Exception("No matching roll found for Lot {$lot_no} / Coil {$coil_no} / Roll {$roll_no}.");
    }

    $id = (int)$product['id'];

    if ((int)$product['is_voided'] === 1) {
        throw new Exception("This roll has been voided and can't be sent to reslit.");
    }
    if ((int)$product['is_reslitted'] === 1) {
        throw new Exception("This roll has already been sent to reslit.");
    }
    if ((int)$product['is_recoiled'] === 1) {
        throw new Exception("This roll has already been sent to recoiling — it can't also go to reslit.");
    }
    if ((int)$product['stock_counted'] !== 1) {
        throw new Exception("This roll hasn't been stock-counted yet (Actual Length not recorded) — finish that on Finished Product first.");
    }
    if (strtoupper(trim($product['status'] ?? '')) !== 'IN') {
        throw new Exception("This roll's status is \"{$product['status']}\" — only rolls currently IN stock can be sent to reslit.");
    }

    // 1. Flag the source roll so it no longer shows as available elsewhere
    //    (Finished Product filters out is_reslitted=1 rows), and won't be
    //    scanned into reslit twice.
    $upd = $conn->prepare("UPDATE slitting_product SET is_reslitted = 1 WHERE id = ?");
    $upd->bind_param("i", $id);
    $upd->execute();
    $upd->close();

    // 2. Create the pending reslit_product record. reslit_product has no
    //    mother_id or remark column (confirmed via this app's existing
    //    queries against it), so neither is written here.
    $ins = $conn->prepare("
        INSERT INTO reslit_product
            (slitting_product_id, product, lot_no, coil_no, roll_no,
             width, length, date_in, original_source)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ");
    $originalSource = $product['original_source'] ?? $product['source'] ?? 'raw_material';
    $ins->bind_param(
        "issssdds",
        $id,
        $product['product'],
        $product['lot_no'],
        $product['coil_no'],
        $product['roll_no'],
        $product['width'],
        $product['length'],
        $originalSource
    );
    if (!$ins->execute()) {
        throw new Exception("Failed to add to reslit queue: " . $ins->error);
    }
    $reslitId = $conn->insert_id;
    $ins->close();

    // 3. Audit trail. reslit_product has no mother_id column, so mother_id
    //    is looked up via the source slitting_product row for the log only
    //    (same approach reslit_handler.php already uses elsewhere).
    $motherId = $product['mother_id'] !== null ? (int)$product['mother_id'] : null;

    log_process($conn, 'slitting', $id, $motherId,
        'IN', 'IN', 'send_to_reslit',
        "Added via Reslit page intake ({$source}); reslit_product id={$reslitId}"
    );
    log_process($conn, 'reslit', $reslitId, $motherId,
        null, 'pending', 'created',
        "Created via Reslit page intake ({$source}) from slitting_product id={$id}"
    );

    $conn->commit();

    echo json_encode([
        'ok'  => true,
        'msg' => "Added {$product['lot_no']} {$product['coil_no']} {$product['roll_no']} to the reslit queue.",
        'roll' => [
            'id'      => $reslitId,
            'product' => $product['product'],
            'lot_no'  => $product['lot_no'],
            'coil_no' => $product['coil_no'],
            'roll_no' => $product['roll_no'],
        ],
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}

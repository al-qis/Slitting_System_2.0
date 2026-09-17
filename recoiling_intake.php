<?php
// recoiling_intake.php
// ============================================================
// AJAX intake endpoint for the Recoiling page's unified intake bar.
// One always-focused text field accepts hardware scanner input,
// camera-scanned QR content, or manual typing of a combined
// Lot-Coil-Roll string — all parsed server-side here via
// parseIntakeString(). Replaces the old flow of navigating to
// Finished Product and clicking "Send to Recoiling".
//
// NOTE: add_to_recoiling.php (the older GET-based handler) is NOT
// used by this new flow — it was missing several safeguards that
// matter a lot now that this becomes a frequently-used, primary
// intake path rather than an occasional button click:
//   - no transaction / row lock (two people could double-add the
//     same roll if they act at the same moment)
//   - never set is_recoiled=1 on the source slitting_product row,
//     so that roll would ALSO still show up as available on
//     Finished Product — meaning the same physical roll could be
//     sent to Recoiling twice, or acted on twice from two screens
//   - no status check at all — even a DELIVERED or already-voided
//     roll could be queued
//   - no audit trail (process_log / source_tracking_log)
// This endpoint fixes all of that, mirroring the same safeguards
// already used by Finished Product's own "Send to Recoiling" flow.
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
// Supports, in order of preference:
//   1. The QR/scan house format:  "LOT=826529;COIL=N-2;ROLL=R4"
//   2. Dash-separated typed entry: "826529-N-2-R4"
//   3. Space-separated typed entry: "826529 N-2 R4"
// Both (2) and (3) are handled by ONE regex rather than a naive split,
// because Coil No itself often already contains a hyphen (e.g. "N-2",
// "FK-1") — a blind split on "-" would incorrectly break "N-2" apart.
// The regex instead anchors on the actual SHAPE of each field:
//   Lot No : digits, optionally with a single trailing letter suffix
//            (e.g. "826529" or "826529a")
//   Coil No: letters, optionally "-", then digits (e.g. "N-2", "FK1")
//   Roll No: "R", optionally "-", then digits (e.g. "R4", "R-6")
// so "826529-N-2-R4" and "826529 N-2 R4" (or any mix of the two
// separators) both parse correctly, while "N-2"'s internal hyphen is
// never mistaken for a field separator.
function parseIntakeString(string $raw): array {
    $raw = trim($raw);

    // 1. QR / scan format
    if (preg_match('/LOT=([^;]+);COIL=([^;]+)(?:;ROLL=([^;]+))?/i', $raw, $m)) {
        return [
            'lot_no'  => trim($m[1]),
            'coil_no' => trim($m[2]),
            'roll_no' => trim($m[3] ?? ''),
        ];
    }

    // 2 & 3. Combined typed entry — dash- or space-separated (or a mix)
    if (preg_match('/^(\d+[a-z]?)[\s\-]+([a-z]+-?\d+)[\s\-]+(r-?\d+)$/i', $raw, $m)) {
        return [
            'lot_no'  => $m[1],
            'coil_no' => $m[2],
            'roll_no' => $m[3],
        ];
    }

    // Fallback: naive 3-token split (whitespace only) — last resort for
    // anything that doesn't match the stricter shape above.
    $tokens = preg_split('/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    if (count($tokens) === 3) {
        return ['lot_no' => $tokens[0], 'coil_no' => $tokens[1], 'roll_no' => $tokens[2]];
    }

    return ['lot_no' => '', 'coil_no' => '', 'roll_no' => ''];
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$source = trim($body['source'] ?? ($_POST['source'] ?? 'unified')); // for the log only

// Preferred path: a single raw string from the unified intake bar,
// parsed server-side as described above.
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
    // Backward-compatible path: already-split fields (kept in case
    // anything else ever calls this endpoint the old way).
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
        throw new Exception("This roll has been voided and can't be sent to recoiling.");
    }
    if ((int)$product['is_recoiled'] === 1) {
        throw new Exception("This roll has already been sent to recoiling.");
    }
    if ((int)$product['is_reslitted'] === 1) {
        throw new Exception("This roll has already been sent to reslit — it can't also go to recoiling.");
    }
    if ((int)$product['stock_counted'] !== 1) {
        throw new Exception("This roll hasn't been stock-counted yet (Actual Length not recorded) — finish that on Finished Product first.");
    }
    if (strtoupper(trim($product['status'] ?? '')) !== 'IN') {
        throw new Exception("This roll's status is \"{$product['status']}\" — only rolls currently IN stock can be sent to recoiling.");
    }

    // 1. Flag the source roll so it no longer shows as available elsewhere
    //    (Finished Product filters out is_recoiled=1 rows), and won't be
    //    scanned into recoiling twice.
    $upd = $conn->prepare("UPDATE slitting_product SET is_recoiled = 1 WHERE id = ?");
    $upd->bind_param("i", $id);
    $upd->execute();
    $upd->close();

    // 2. Create the pending recoiling_product record.
    $ins = $conn->prepare("
        INSERT INTO recoiling_product
            (slitting_product_id, mother_id, product, lot_no, coil_no, roll_no,
             width, length, actual_length, status, original_source, date_in)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
    ");
    $originalSource = $product['original_source'] ?? $product['source'] ?? 'raw_material';
    $motherId = $product['mother_id'] !== null ? (int)$product['mother_id'] : null;
    $ins->bind_param(
        "iisssssdds",
        $id,
        $motherId,
        $product['product'],
        $product['lot_no'],
        $product['coil_no'],
        $product['roll_no'],
        $product['width'],
        $product['length'],
        $product['actual_length'],
        $originalSource
    );
    if (!$ins->execute()) {
        throw new Exception("Failed to add to recoiling queue: " . $ins->error);
    }
    $recoilingId = $conn->insert_id;
    $ins->close();

    // 3. Audit trail.
    log_process($conn, 'slitting', $id, $motherId,
        'IN', 'IN', 'send_to_recoiling',
        "Added via Recoiling page intake ({$source}); recoiling_product id={$recoilingId}"
    );
    log_process($conn, 'recoiling', $recoilingId, $motherId,
        null, 'pending', 'created',
        "Created via Recoiling page intake ({$source}) from slitting_product id={$id}"
    );

    $conn->commit();

    echo json_encode([
        'ok'  => true,
        'msg' => "Added {$product['lot_no']} {$product['coil_no']} {$product['roll_no']} to the recoiling queue.",
        'roll' => [
            'id'      => $recoilingId,
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
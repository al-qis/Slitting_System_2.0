<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION['role'] !== 'supervisor' && $_SESSION['role'] !== 'slitting') {
    die("Access denied - Only supervisors or slitting staff can scan");
}

include 'config.php';

// ═══════════════════════════════════════════════════════════════
//  QR SANITIZATION — handles ALL scan sources consistently
//
//  Hardware scanner guns transmit as HID keyboard input and may
//  inject several kinds of junk around the real barcode data:
//
//  1. Control characters  (\r \n \t \x00 etc.)
//     — appended as "Enter" at end-of-scan, or as null-byte
//       padding from certain firmware.
//
//  2. GS1 / AIM prefix bytes  e.g. \x00\x00 26  or  ]C1
//     — the \x00 bytes are stripped by step 1; the remaining
//       printable residue (like "26") is stripped by step 3.
//     — AIM identifiers (]C1, ]d2, ]Q3 …) appear on some guns
//       when "AIM output" is enabled in firmware.
//
//  3. Short numeric prefix before the real payload
//     — after control chars are gone, some guns still leave a
//       1–6 digit or alphanumeric prefix BEFORE the "LOT=" key.
//     — The lookahead (?=LOT=|COIL=|\d{4,}) ensures we only
//       strip a prefix when the real payload immediately follows,
//       so genuine 4+ digit lot numbers are never eaten.
//
//  The result is identical input regardless of scan source
//  (camera widget, hardware gun, or manual keyboard entry).
// ═══════════════════════════════════════════════════════════════

$qr = isset($_POST['qr']) ? $_POST['qr'] : '';

// Step 1 — strip ALL control characters (\r \n \t \x00 …)
$qr = preg_replace('/[[:cntrl:]]/', '', $qr);

// Step 2 — strip AIM symbology identifiers  e.g. ]C1  ]d2  ]Q3
$qr = preg_replace('/^\][A-Za-z][0-9]/', '', $qr);

// Step 3 — strip short junk prefix (≤6 chars) before real data
//   Matches:  "26LOT=…"  "001LOT=…"  "&LOT=…"
//   Safe:     stops at lookahead so "826601 QM-4" is untouched
$qr = preg_replace('/^[^\w]{0,6}(?=LOT=|COIL=)/i', '', $qr);
$qr = preg_replace('/^[\w]{1,6}(?=LOT=|COIL=)/i',  '', $qr);

$qr = trim($qr);

if (empty($qr)) {
    $_SESSION['scan_warning'] = null;
    header("Location: raw_material.php");
    exit;
}

// ═══════════════════════════════════════════════════════════════
//  QR PARSING — supports two formats
//
//  Format A  (KEY=value, all scan sources):
//    LOT=826277;COIL=FK-1
//    Keys matched case-insensitively; segments without "=" skipped.
//
//  Format B  (space-separated, manual overlay entry):
//    826277 FK-1
// ═══════════════════════════════════════════════════════════════

$lot_no  = '';
$coil_no = '';

if (strpos($qr, '=') !== false) {
    // Format A — KEY=value pairs
    $pairs = [];
    foreach (explode(';', $qr) as $segment) {
        $segment = trim($segment);
        if (strpos($segment, '=') === false) continue;  // skip junk prefix segments
        [$k, $v] = explode('=', $segment, 2);
        $pairs[strtoupper(trim($k))] = trim($v);
    }
    $lot_no  = $pairs['LOT']  ?? '';
    $coil_no = $pairs['COIL'] ?? '';

} else {
    // Format B — space-separated "826277 FK-1"
    $tokens  = preg_split('/\s+/', $qr, 2);
    $lot_no  = trim($tokens[0] ?? '');
    $coil_no = trim($tokens[1] ?? '');
}

if (empty($lot_no) || empty($coil_no)) {
    $_SESSION['error'] = "Invalid QR format. Expected: LOT=xxx;COIL=xxx  or  'LotNo CoilNo'";
    header("Location: raw_material.php");
    exit;
}

// Escape for inline queries (prepared statements used below)
$lot_no  = $conn->real_escape_string($lot_no);
$coil_no = $conn->real_escape_string($coil_no);

// ── PRIORITY: leftover / Cut-Into-2 balance ───────────────────
$leftover_check = $conn->query(
    "SELECT id FROM stock_raw_material
     WHERE lot_no='$lot_no'
       AND coil_no='$coil_no'
       AND source_type='slitting_cut_into_2'
       AND status='IN'
     ORDER BY id DESC LIMIT 1"
);
if ($leftover_check && $leftover_check->num_rows > 0) {
    $leftover = $leftover_check->fetch_assoc();
    $_SESSION['success'] = "Leftover coil $lot_no $coil_no ready for slitting. Fill the form.";
    header("Location: add_slitting.php?stock_id=" . $leftover['id']);
    exit;
}

// ── Fetch mother coil ─────────────────────────────────────────
$result = $conn->query(
    "SELECT * FROM mother_coil WHERE lot_no='$lot_no' AND coil_no='$coil_no'"
);
if (!$result || $result->num_rows === 0) {
    $_SESSION['error'] = "Mother coil not found: $lot_no - $coil_no";
    header("Location: raw_material.php");
    exit;
}

$mother    = $result->fetch_assoc();
$mother_id = $mother['id'];

// ── Check if this coil has ALREADY been slitted ───────────────
$slit_check = $conn->prepare(
    "SELECT COUNT(*) AS cnt FROM slitting_product WHERE mother_id = ?"
);
$slit_check->bind_param("i", $mother_id);
$slit_check->execute();
$slit_count = (int)$slit_check->get_result()->fetch_assoc()['cnt'];
$slit_check->close();

if ($slit_count > 0) {
    $slit_rows = $conn->prepare(
        "SELECT lot_no, coil_no, roll_no, width, actual_length, length, status
         FROM slitting_product
         WHERE mother_id = ?
           AND (is_voided = 0 OR is_voided IS NULL)
         ORDER BY id ASC
         LIMIT 10"
    );
    $slit_rows->bind_param("i", $mother_id);
    $slit_rows->execute();
    $produced = $slit_rows->get_result()->fetch_all(MYSQLI_ASSOC);
    $slit_rows->close();

    $first_slit = $conn->prepare(
        "SELECT MIN(date_in) AS first_date FROM slitting_product WHERE mother_id = ?"
    );
    $first_slit->bind_param("i", $mother_id);
    $first_slit->execute();
    $first_date = $first_slit->get_result()->fetch_assoc()['first_date'];
    $first_slit->close();

    $_SESSION['scan_warning'] = [
        'type'       => 'already_used',
        'lot_no'     => $lot_no,
        'coil_no'    => $coil_no,
        'product'    => $mother['product'] ?? '',
        'slit_count' => $slit_count,
        'first_date' => $first_date,
        'produced'   => $produced,
        'mother_id'  => $mother_id,
    ];

    header("Location: raw_material.php?warn=already_used");
    exit;
}

// ── Not yet slitted — normal scan-in flow ─────────────────────
$conn->begin_transaction();
try {
    $stock_check_result = $conn->query(
        "SELECT id, status FROM stock_raw_material
         WHERE lot_no='$lot_no' AND coil_no='$coil_no' AND source_type='mother_coil'
         ORDER BY id DESC LIMIT 1"
    );

    if ($stock_check_result && $stock_check_result->num_rows > 0) {
        $existing_stock = $stock_check_result->fetch_assoc();
        $stock_id       = $existing_stock['id'];

        if ($existing_stock['status'] === 'IN') {
            $conn->commit();
            $_SESSION['success'] = "Mother coil $lot_no-$coil_no ready for slitting. Fill the form.";
            header("Location: add_slitting.php?stock_id=$stock_id");
            exit;
        } else {
            $conn->query("UPDATE stock_raw_material SET status='IN', updated_at=NOW() WHERE id=$stock_id");
            $conn->query("UPDATE mother_coil SET status='IN', stock=1 WHERE id=$mother_id");
            $conn->query("INSERT INTO mother_coil_audit_log (mother_id, action_type, performed_at, remark)
                          VALUES ($mother_id, 'SCAN_IN', NOW(), 'Toggled back IN: $lot_no $coil_no')");
            $conn->commit();
            $_SESSION['message'] = "Mother coil $lot_no-$coil_no toggled back to IN.";
            header("Location: raw_material.php");
            exit;
        }

    } else {
        $grade  = $conn->real_escape_string($mother['grade']  ?? '');
        $width  = (float)$mother['width'];
        $length = (float)$mother['length'];

        $conn->query(
            "INSERT INTO stock_raw_material
                 (lot_no, coil_no, grade, width, length, status, source_type, source_id, date_in)
             VALUES
                 ('$lot_no','$coil_no','$grade',$width,$length,'IN','mother_coil',$mother_id,NOW())"
        );
        $conn->query(
            "UPDATE mother_coil
             SET status='IN', stock=1, date_in=NOW(), scan_in_count = scan_in_count + 1
             WHERE id=$mother_id"
        );
        $conn->query(
            "INSERT INTO mother_coil_audit_log (mother_id, action_type, performed_at, remark)
             VALUES ($mother_id, 'SCAN_IN', NOW(), 'Scanned IN: $lot_no $coil_no')"
        );
        $conn->query(
            "INSERT INTO raw_material_log (mother_id, status, action, date_in, remark)
             VALUES ($mother_id, 'IN', 'normal', NOW(), 'Scanned IN: $lot_no $coil_no')"
        );
        $conn->commit();
        $_SESSION['success'] = "Mother coil $lot_no-$coil_no scanned IN. Ready for use.";
        header("Location: raw_material.php");
        exit;
    }

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = "Error: " . $e->getMessage();
    header("Location: raw_material.php");
    exit;
}
<?php
// =============================================================
// scan_product_action.php — Bundle Delivery Entry Point
// PLACEMENT: C:\Apache24\htdocs\slitting_system\scan_product_action.php
//
// When operator scans any product QR:
//   • If the product is on an APPROVED pallet → trigger
//     PalletManager::bundleDeliver() (all 1–8 rolls delivered)
//   • All other states follow original paths (unchanged)
// =============================================================

include 'config.php';
require_once 'PalletManager.php';

$protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'];
$scriptDir = rtrim(dirname($_SERVER['PHP_SELF']), '/');
$BASE_URL  = $protocol . '://' . $host . $scriptDir;

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
//  3. Short numeric/symbol prefix before the real payload
//     — after control chars are gone, some guns still leave a
//       1–6 char prefix BEFORE "LOT=" / "COIL=" / "ROLL=".
//     — The lookahead (?=LOT=|COIL=|ROLL=) ensures we only
//       strip a prefix when the real payload immediately follows.
// ═══════════════════════════════════════════════════════════════

$qr = trim($_POST['qr'] ?? '');

// Step 1 — strip ALL control characters (\r \n \t \x00 …)
$qr = preg_replace('/[[:cntrl:]]/', '', $qr);

// Step 2 — strip AIM symbology identifiers  e.g. ]C1  ]d2  ]Q3
$qr = preg_replace('/^\][A-Za-z][0-9]/', '', $qr);

// Step 3 — strip short junk prefix (≤6 chars) before real data
//   Matches:  "26LOT=…"  "001LOT=…"  "&LOT=…"
$qr = preg_replace('/^[^\w]{0,6}(?=LOT=|COIL=|ROLL=)/i', '', $qr);
$qr = preg_replace('/^[\w]{1,6}(?=LOT=|COIL=|ROLL=)/i',  '', $qr);

$qr = trim($qr);

if ($qr === '') {
    header("Location: {$BASE_URL}/finish_product.php?scan=empty"); exit;
}

// ═══════════════════════════════════════════════════════════════
//  QR PARSING — supports two formats, each with an OLD (3-field)
//  and NEW (5-field) shape. WIDTH/LENGTH are always optional: if
//  they're missing (old stickers already printed), they simply come
//  back as '' — nothing downstream breaks or throws on that.
//
//  Format A  (KEY=value, all scan sources):
//    Old:  LOT=826277;COIL=FK-1;ROLL=R1
//    New:  LOT=826277;COIL=FK-1;ROLL=R1;WIDTH=450;LENGTH=1200
//    Keys matched case-insensitively; segments without "=" skipped.
//    Note: LENGTH here is generate_qr.php's *effective* length — it
//    already prioritizes actual_length over the nominal/original length
//    whenever the roll has been measured, so this parser doesn't need
//    to know or care which one it actually is; it just reads "LENGTH".
//
//  Format B  (space-separated, manual overlay entry):
//    Old:  826277 FK-1 R1
//    New:  826277 FK-1 R1 450 1200
// ═══════════════════════════════════════════════════════════════

$lot    = '';
$coil   = '';
$roll   = '';
$width  = '';
$length = '';

if (strpos($qr, '=') !== false) {
    // Format A — KEY=value pairs
    $pairs = [];
    foreach (explode(';', $qr) as $segment) {
        $segment = trim($segment);
        if (strpos($segment, '=') === false) continue;  // skip junk prefix segments
        [$k, $v] = explode('=', $segment, 2);
        $pairs[strtoupper(trim($k))] = trim($v);
    }
    $lot    = $pairs['LOT']    ?? '';
    $coil   = $pairs['COIL']   ?? '';
    $roll   = $pairs['ROLL']   ?? '';
    $width  = $pairs['WIDTH']  ?? ''; // '' on old 3-field stickers — never errors
    $length = $pairs['LENGTH'] ?? ''; // '' on old 3-field stickers — never errors

} else {
    // Format B — space-separated "826277 FK-1 R1" or
    // "826277 FK-1 R1 450 1200"
    $tokens = preg_split('/\s+/', $qr, 5);
    $lot    = trim($tokens[0] ?? '');
    $coil   = trim($tokens[1] ?? '');
    $roll   = trim($tokens[2] ?? '');
    $width  = trim($tokens[3] ?? ''); // '' when only 3 tokens present
    $length = trim($tokens[4] ?? ''); // '' when only 3 tokens present
}

if ($lot === '' || $coil === '') {
    header("Location: {$BASE_URL}/finish_product.php?scan=invalid"); exit;
}

// ── Month/Year for redirect base ──────────────────────────────
$month = max(1, min(12, (int)($_GET['month'] ?? date('m'))));
$year  = max(2000, min(2100, (int)($_GET['year']  ?? date('Y'))));
$back  = "{$BASE_URL}/finish_product.php?month={$month}&year={$year}";

// ── Lookup product + pallet in one query ──────────────────────
$stmt = $conn->prepare("
    SELECT sp.id, sp.status, sp.is_completed, sp.stock_counted,
           sp.is_reslitted, sp.is_recoiled, sp.is_voided,
           pi.pallet_id,
           p.status   AS pallet_status,
           p.pallet_no
    FROM slitting_product sp
    LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
    LEFT JOIN pallets p       ON p.id = pi.pallet_id
    WHERE sp.lot_no   = ?
      AND sp.coil_no  = ?
      AND sp.roll_no  = ?
    ORDER BY sp.id DESC
    LIMIT 1
");
$stmt->bind_param("sss", $lot, $coil, $roll);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    header("Location: {$back}&scan=notfound&lot=".urlencode($lot)."&coil=".urlencode($coil)."&roll=".urlencode($roll));
    exit;
}

$id            = (int)$product['id'];
$status        = strtoupper(trim($product['status'] ?? ''));
$palletId      = $product['pallet_id']     ? (int)$product['pallet_id']  : null;
$palletStatus  = $product['pallet_status'] ?? null;
$palletNo      = $product['pallet_no']     ?? '';

// ── Voided ────────────────────────────────────────────────────
if ((int)$product['is_voided'] === 1) {
    header("Location: {$back}&scan=voided&pid={$id}"); exit;
}

// ── Already fully delivered ───────────────────────────────────
if ($status === 'DELIVERED') {
    header("Location: {$back}&scan=already_delivered&pid={$id}"); exit;
}

// ── Already waiting for QC ────────────────────────────────────
if ($status === 'WAITING') {
    header("Location: {$back}&scan=already_waiting&pid={$id}"); exit;
}

// ── Rejected ─────────────────────────────────────────────────
if ($status === 'REJECTED') {
    header("Location: {$back}&scan=rejected_blocked&pid={$id}"); exit;
}

// ── APPROVED — on an approved pallet → BUNDLE DELIVERY ───────
if ($status === 'APPROVED' && $palletId && $palletStatus === 'approved') {
    $pm     = new PalletManager($conn, $_SESSION['role'] ?? 'slitting');
    $result = $pm->bundleDeliver($palletId, $id);

    if ($result['ok']) {
        $code = $result['code'];
        if ($code === 'DELIVERED') {
            header("Location: pallet.php?pallet_id={$palletId}&success=delivered&pallet_no=" . urlencode($result['pallet_no']));
        } else {
            header("Location: pallet.php?pallet_id={$palletId}");
        }
    } else {
        header("Location: {$back}"
            . "&scan=deliver_error"
            . "&code=" . urlencode($result['code'])
            . "&msg="  . urlencode($result['msg']));
    }
    exit;
}

// ── APPROVED — not on a pallet (legacy individual delivery) ───
if ($status === 'APPROVED' && !$palletId) {
    $stmt = $conn->prepare(
        "UPDATE slitting_product SET status='DELIVERED', delivered_at=NOW()
         WHERE id=? AND status='APPROVED'"
    );
    $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();
    header("Location: {$back}&scan=delivered&pid={$id}"); exit;
}

// ── IN status ─────────────────────────────────────────────────
if ($status === 'IN') {

    if ((int)$product['stock_counted'] !== 1) {
        header("Location: {$back}&scan=not_stock&pid={$id}"); exit;
    }

    if ((int)$product['is_reslitted'] || (int)$product['is_recoiled']) {
        $reason = match(true) {
            (bool)$product['is_reslitted'] && (bool)$product['is_recoiled'] => 'Reslit + Recoil',
            (bool)$product['is_reslitted'] => 'Reslit',
            default => 'Recoil',
        };
        header("Location: {$back}&scan=blocked_qc&pid={$id}&reason=".urlencode($reason)); exit;
    }

    if (!$palletId) {
        header("Location: {$back}&scan=no_pallet&pid={$id}"); exit;
    }

    header("Location: {$back}"
        . "&scan=on_pallet"
        . "&pid={$id}"
        . "&pallet_id={$palletId}"
        . "&pallet_no=" . urlencode($palletNo)
        . "&pallet_status=" . urlencode($palletStatus ?? 'building'));
    exit;
}

// ── Fallback ──────────────────────────────────────────────────
header("Location: {$back}&scan=ignored&pid={$id}");
exit;
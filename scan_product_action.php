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

// ── Parse QR ──────────────────────────────────────────────────
$qr = trim($_POST['qr'] ?? '');
$qr = str_replace(["\r\n", "\r"], "\n", $qr);

if ($qr === '') {
    header("Location: {$BASE_URL}/finish_product.php?scan=empty"); exit;
}

$firstLine = strtok($qr, "\n");
$firstLine = trim(preg_replace('/[[:cntrl:]]+/', '', preg_replace('/^\][A-Za-z0-9]{2,3}/', '', trim($firstLine))));

$data = [];
parse_str(str_replace(';', '&', $firstLine), $data);
$upper = [];
foreach ($data as $k => $v) { $upper[strtoupper($k)] = $v; }

$lot  = trim($upper['LOT']  ?? '');
$coil = trim($upper['COIL'] ?? '');
$roll = trim($upper['ROLL'] ?? '');

if ($lot === '' || $coil === '' || $roll === '') {
    $parts = array_values(array_filter(array_map('trim', explode(';', $firstLine)), 'strlen'));
    $lot   = $lot  ?: ($parts[0] ?? '');
    $coil  = $coil ?: ($parts[1] ?? '');
    $roll  = $roll ?: ($parts[2] ?? '');
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
            header("Location: {$back}"
                . "&scan=pallet_delivered"
                . "&pallet_no="   . urlencode($result['pallet_no'])
                . "&roll_count="  . $result['rolls_delivered']);
        } else {
            // ALREADY_DELIVERED — idempotent
            header("Location: {$back}&scan=already_delivered_pallet&pallet_no=".urlencode($result['pallet_no'] ?? ''));
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

    // On a pallet — show its state to the operator
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
<?php
// scan_product_action.php — PLACEMENT: C:\Apache24\htdocs\slitting_system\scan_product_action.php
include 'config.php';

$protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'];
$scriptDir = rtrim(dirname($_SERVER['PHP_SELF']), '/');
$BASE_URL  = $protocol . '://' . $host . $scriptDir;

$qr = $_POST['qr'] ?? '';
$qr = trim($qr);
$qr = str_replace(["\r\n", "\r"], "\n", $qr);

if ($qr === '') { header("Location: " . $BASE_URL . "/finish_product.php?scan=empty"); exit; }

$firstLine = strtok($qr, "\n");
$firstLine = trim($firstLine);
$firstLine = preg_replace('/^\][A-Za-z0-9]{2,3}/', '', $firstLine);
$firstLine = preg_replace('/[[:cntrl:]]+/', '', $firstLine);
$firstLine = trim($firstLine);

$data = [];
parse_str(str_replace(';', '&', $firstLine), $data);
$upper = [];
foreach ($data as $k => $v) { $upper[strtoupper($k)] = $v; }
$lot  = trim($upper['LOT']  ?? '');
$coil = trim($upper['COIL'] ?? '');
$roll = trim($upper['ROLL'] ?? '');

if ($lot === '' || $coil === '' || $roll === '') {
    $parts = array_values(array_filter(array_map('trim', explode(';', $firstLine)), 'strlen'));
    $lot   = $lot  !== '' ? $lot  : ($parts[0] ?? '');
    $coil  = $coil !== '' ? $coil : ($parts[1] ?? '');
    $roll  = $roll !== '' ? $roll : ($parts[2] ?? '');
}
$lot = trim($lot); $coil = trim($coil); $roll = trim($roll);

if ($lot === '' || $coil === '') { header("Location: " . $BASE_URL . "/finish_product.php?scan=invalid"); exit; }

$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
if ($month < 1 || $month > 12)    $month = (int)date('m');
if ($year < 2000 || $year > 2100) $year  = (int)date('Y');

$redirBase = $BASE_URL . "/finish_product.php?month=$month&year=$year";

// Look up product + pallet assignment in one query
if ($roll === '') {
    $stmt = $conn->prepare("
        SELECT sp.id, sp.status, sp.is_completed, sp.stock_counted,
               sp.is_reslitted, sp.is_recoiled, sp.is_voided,
               pi.pallet_id
        FROM slitting_product sp
        LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
        WHERE sp.lot_no = ? AND sp.coil_no = ?
          AND (sp.roll_no IS NULL OR sp.roll_no = '')
        ORDER BY sp.id DESC LIMIT 1
    ");
    $stmt->bind_param("ss", $lot, $coil);
} else {
    $stmt = $conn->prepare("
        SELECT sp.id, sp.status, sp.is_completed, sp.stock_counted,
               sp.is_reslitted, sp.is_recoiled, sp.is_voided,
               pi.pallet_id
        FROM slitting_product sp
        LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
        WHERE sp.lot_no = ? AND sp.coil_no = ? AND sp.roll_no = ?
        ORDER BY sp.id DESC LIMIT 1
    ");
    $stmt->bind_param("sss", $lot, $coil, $roll);
}
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    header("Location: " . $redirBase . "&scan=notfound&lot=" . urlencode($lot) . "&coil=" . urlencode($coil) . "&roll=" . urlencode($roll));
    exit;
}

$id     = (int)$product['id'];
$status = strtoupper(trim($product['status'] ?? ''));

if ((int)$product['is_voided'] === 1) { header("Location: " . $redirBase . "&scan=voided&pid=$id"); exit; }

if ($status === 'IN') {
    if ((int)$product['stock_counted'] !== 1) { header("Location: " . $redirBase . "&scan=not_stock&pid=$id"); exit; }

    $is_reslitted = (int)$product['is_reslitted'];
    $is_recoiled  = (int)$product['is_recoiled'];
    if ($is_reslitted || $is_recoiled) {
        $reason = ($is_reslitted && $is_recoiled) ? 'Reslit + Recoil' : ($is_reslitted ? 'Reslit' : 'Recoil');
        header("Location: " . $redirBase . "&scan=blocked_qc&pid=$id&reason=" . urlencode($reason)); exit;
    }

    // ── NEW: must be on a pallet before QC ───────────────────
    $pallet_id = $product['pallet_id'] ?? null;
    if (!$pallet_id) { header("Location: " . $redirBase . "&scan=no_pallet&pid=$id"); exit; }

    // Roll is on a pallet — inform operator
    header("Location: " . $redirBase . "&scan=on_pallet&pid=$id&pallet_id=" . urlencode((string)$pallet_id));
    exit;
}

if ($status === 'WAITING')   { header("Location: " . $redirBase . "&scan=already_waiting&pid=$id"); exit; }

if ($status === 'APPROVED') {
    $stmt = $conn->prepare("UPDATE slitting_product SET status = 'DELIVERED', delivered_at = NOW() WHERE id = ? AND status = 'APPROVED'");
    $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();
    header("Location: " . $redirBase . "&scan=delivered&pid=$id"); exit;
}

if ($status === 'REJECTED')  { header("Location: " . $redirBase . "&scan=rejected_blocked&pid=$id"); exit; }
if ($status === 'DELIVERED') { header("Location: " . $redirBase . "&scan=already_delivered&pid=$id"); exit; }

header("Location: " . $redirBase . "&scan=ignored&pid=$id");
exit;
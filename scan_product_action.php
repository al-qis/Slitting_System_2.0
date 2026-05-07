<?php
include 'config.php';

// =====================================================
// FIX: Build redirect base from the actual request,
// NOT from a hardcoded $BASE_URL or localhost.
// This works whether accessed via localhost, 10.0.1.133,
// or any other IP/hostname.
// =====================================================
$protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'];                    // e.g. 10.0.1.133 or localhost
$scriptDir = rtrim(dirname($_SERVER['PHP_SELF']), '/'); // e.g. /slitting_system
$BASE_URL  = $protocol . '://' . $host . $scriptDir;

$qr = $_POST['qr'] ?? '';

// =====================================================
// 1) Normalize input
// =====================================================
$qr = trim($qr);
$qr = str_replace(["\r\n", "\r"], "\n", $qr);

if ($qr === '') {
    header("Location: " . $BASE_URL . "/finish_product.php?scan=empty");
    exit;
}

// =====================================================
// 2) First line only (QR multi-line)
// =====================================================
$firstLine = strtok($qr, "\n");
$firstLine = trim($firstLine);

// =====================================================
// 3) Strip symbology/AIM prefix (]C1, ]Q3, ]d2, etc)
// =====================================================
$firstLine = preg_replace('/^\][A-Za-z0-9]{2,3}/', '', $firstLine);

// =====================================================
// 4) Strip control chars + trim
// =====================================================
$firstLine = preg_replace('/[[:cntrl:]]+/', '', $firstLine);
$firstLine = trim($firstLine);

// =====================================================
// 5) Parse QR
// Supports: LOT=..;COIL=..;ROLL=..  OR  LOT;COIL;ROLL
// =====================================================
$data = [];
parse_str(str_replace(';', '&', $firstLine), $data);

$upper = [];
foreach ($data as $k => $v) {
    $upper[strtoupper($k)] = $v;
}

$lot  = trim($upper['LOT']  ?? '');
$coil = trim($upper['COIL'] ?? '');
$roll = trim($upper['ROLL'] ?? '');

// fallback: LOT;COIL;ROLL positional
if ($lot === '' || $coil === '' || $roll === '') {
    $parts = array_values(array_filter(array_map('trim', explode(';', $firstLine)), 'strlen'));
    $lot  = $lot  !== '' ? $lot  : ($parts[0] ?? '');
    $coil = $coil !== '' ? $coil : ($parts[1] ?? '');
    $roll = $roll !== '' ? $roll : ($parts[2] ?? '');
}

$lot  = trim($lot);
$coil = trim($coil);
$roll = trim($roll);

if ($lot === '' || $coil === '') {
    header("Location: " . $BASE_URL . "/finish_product.php?scan=invalid");
    exit;
}

// =====================================================
// 6) Keep month/year for redirect
// =====================================================
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
if ($month < 1 || $month > 12)   $month = (int)date('m');
if ($year  < 2000 || $year > 2100) $year = (int)date('Y');

$redirBase = $BASE_URL . "/finish_product.php?month=$month&year=$year";

// =====================================================
// 7) Find product
// =====================================================
if ($roll === '') {
    $stmt = $conn->prepare("
        SELECT id, status
        FROM slitting_product
        WHERE lot_no=? AND coil_no=? AND (roll_no IS NULL OR roll_no='')
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->bind_param("ss", $lot, $coil);
} else {
    $stmt = $conn->prepare("
        SELECT id, status
        FROM slitting_product
        WHERE lot_no=? AND coil_no=? AND roll_no=?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->bind_param("sss", $lot, $coil, $roll);
}
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    header("Location: " . $redirBase . "&scan=notfound");
    exit;
}

$id     = (int)$product['id'];
$status = strtoupper(trim($product['status'] ?? ''));

// =====================================================
// 8) Status flow
// IN       → WAITING
// WAITING  → stay (wait for QC)
// APPROVED → DELIVERED
// =====================================================

if ($status === 'IN') {
    $stmt = $conn->prepare("UPDATE slitting_product SET status='WAITING', date_out=NOW() WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header("Location: " . $redirBase . "&scan=waiting");
    exit;
}

if ($status === 'WAITING') {
    header("Location: " . $redirBase . "&scan=waiting");
    exit;
}

if ($status === 'APPROVED') {
    $stmt = $conn->prepare("UPDATE slitting_product SET status='DELIVERED', delivered_at=NOW() WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header("Location: " . $redirBase . "&scan=delivered");
    exit;
}

if ($status === 'DELIVERED') {
    $lot_enc  = urlencode($lot);
    $coil_enc = urlencode($coil);
    header("Location: " . $redirBase . "&scan=already_delivered&return_id=$id&lot=$lot_enc&coil=$coil_enc");
    exit;
}

header("Location: " . $redirBase . "&scan=ignored");
exit;
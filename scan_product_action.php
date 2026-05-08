<?php
include 'config.php';

// ── Build redirect base from actual request ───────────────────
$protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'];
$scriptDir = rtrim(dirname($_SERVER['PHP_SELF']), '/');
$BASE_URL  = $protocol . '://' . $host . $scriptDir;

$qr = $_POST['qr'] ?? '';

// ── 1. Normalize input ────────────────────────────────────────
$qr = trim($qr);
$qr = str_replace(["\r\n", "\r"], "\n", $qr);

if ($qr === '') {
    header("Location: " . $BASE_URL . "/finish_product.php?scan=empty");
    exit;
}

// ── 2. First line only ────────────────────────────────────────
$firstLine = strtok($qr, "\n");
$firstLine = trim($firstLine);

// ── 3. Strip symbology/AIM prefix (]C1, ]Q3, ]d2, etc.) ──────
$firstLine = preg_replace('/^\][A-Za-z0-9]{2,3}/', '', $firstLine);

// ── 4. Strip control chars ────────────────────────────────────
$firstLine = preg_replace('/[[:cntrl:]]+/', '', $firstLine);
$firstLine = trim($firstLine);

// ── 5. Parse QR: LOT=x;COIL=y;ROLL=z  OR  x;y;z ─────────────
$data = [];
parse_str(str_replace(';', '&', $firstLine), $data);

$upper = [];
foreach ($data as $k => $v) {
    $upper[strtoupper($k)] = $v;
}

$lot  = trim($upper['LOT']  ?? '');
$coil = trim($upper['COIL'] ?? '');
$roll = trim($upper['ROLL'] ?? '');

// Fallback: positional LOT;COIL;ROLL
if ($lot === '' || $coil === '' || $roll === '') {
    $parts = array_values(array_filter(array_map('trim', explode(';', $firstLine)), 'strlen'));
    $lot   = $lot  !== '' ? $lot  : ($parts[0] ?? '');
    $coil  = $coil !== '' ? $coil : ($parts[1] ?? '');
    $roll  = $roll !== '' ? $roll : ($parts[2] ?? '');
}

$lot  = trim($lot);
$coil = trim($coil);
$roll = trim($roll);

if ($lot === '' || $coil === '') {
    header("Location: " . $BASE_URL . "/finish_product.php?scan=invalid");
    exit;
}

// ── 6. Preserve month/year for redirect ──────────────────────
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
if ($month < 1 || $month > 12)    $month = (int)date('m');
if ($year  < 2000 || $year > 2100) $year  = (int)date('Y');

$redirBase = $BASE_URL . "/finish_product.php?month=$month&year=$year";

// ── 7. Find product — fetch ALL columns needed for validation ─
if ($roll === '') {
    $stmt = $conn->prepare("
        SELECT id, status, is_completed, stock_counted,
               is_reslitted, is_recoiled, is_voided
        FROM slitting_product
        WHERE lot_no = ? AND coil_no = ?
          AND (roll_no IS NULL OR roll_no = '')
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->bind_param("ss", $lot, $coil);
} else {
    $stmt = $conn->prepare("
        SELECT id, status, is_completed, stock_counted,
               is_reslitted, is_recoiled, is_voided
        FROM slitting_product
        WHERE lot_no = ? AND coil_no = ? AND roll_no = ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->bind_param("sss", $lot, $coil, $roll);
}
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── 8. Product not found ──────────────────────────────────────
if (!$product) {
    header("Location: " . $redirBase
        . "&scan=notfound"
        . "&lot="  . urlencode($lot)
        . "&coil=" . urlencode($coil)
        . "&roll=" . urlencode($roll));
    exit;
}

$id     = (int)$product['id'];
$status = strtoupper(trim($product['status'] ?? ''));

// ── 9. Voided rolls are dead — block immediately ──────────────
if ((int)$product['is_voided'] === 1) {
    header("Location: " . $redirBase . "&scan=voided&pid=$id");
    exit;
}

// ── 10. STATUS FLOW ───────────────────────────────────────────
if ($status === 'IN') {

    // ── 10a. Must have actual length recorded first ───────────
    if ((int)$product['stock_counted'] !== 1) {
        header("Location: " . $redirBase . "&scan=not_stock&pid=$id");
        exit;
    }

    // ── 10b. QC GATE: block if sent to Reslit or Recoil ──────
    //
    // Rule: A product can only enter QC when it is in pure
    // "Finish Good" state:
    //   ✅ status = 'IN'
    //   ✅ stock_counted = 1
    //   ❌ is_reslitted must be 0
    //   ❌ is_recoiled  must be 0
    //
    // Even if a product was previously Finish Good, once it has
    // been sent to Reslit or Recoil it is blocked from QC until
    // that process is completed and a NEW roll is produced.

    $is_reslitted = (int)$product['is_reslitted'];
    $is_recoiled  = (int)$product['is_recoiled'];

    if ($is_reslitted === 1 || $is_recoiled === 1) {
        if ($is_reslitted === 1 && $is_recoiled === 1) {
            $blocked_reason = 'Reslit + Recoil';
        } elseif ($is_reslitted === 1) {
            $blocked_reason = 'Reslit';
        } else {
            $blocked_reason = 'Recoil';
        }
        header("Location: " . $redirBase
            . "&scan=blocked_qc"
            . "&pid=$id"
            . "&reason=" . urlencode($blocked_reason));
        exit;
    }

    // ── 10c. Double-check: active pending records in DB ───────
    // Catches edge cases where the flag was not set correctly
    // but the reslit/recoil record still exists as pending.
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt FROM reslit_product
        WHERE lot_no = ? AND coil_no = ? AND roll_no = ?
          AND status IN ('pending', 'in_progress')
    ");
    $stmt->bind_param("sss", $lot, $coil, $roll);
    $stmt->execute();
    $pending_reslit = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt FROM recoiling_product
        WHERE lot_no = ? AND coil_no = ? AND roll_no = ?
          AND status = 'pending'
    ");
    $stmt->bind_param("sss", $lot, $coil, $roll);
    $stmt->execute();
    $pending_recoil = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    if ($pending_reslit > 0 || $pending_recoil > 0) {
        $blocked_reason = $pending_reslit > 0 ? 'Reslit (pending)' : 'Recoil (pending)';
        header("Location: " . $redirBase
            . "&scan=blocked_qc"
            . "&pid=$id"
            . "&reason=" . urlencode($blocked_reason));
        exit;
    }

    // ── 10d. All checks passed — move IN → WAITING (QC) ──────
    $stmt = $conn->prepare("
        UPDATE slitting_product
        SET status = 'WAITING', date_out = NOW()
        WHERE id = ? AND status = 'IN'
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    header("Location: " . $redirBase . "&scan=waiting&pid=$id");
    exit;
}

if ($status === 'WAITING') {
    // Already queued for QC — do nothing
    header("Location: " . $redirBase . "&scan=already_waiting&pid=$id");
    exit;
}

if ($status === 'APPROVED') {
    // QC passed — mark as DELIVERED
    $stmt = $conn->prepare("
        UPDATE slitting_product
        SET status = 'DELIVERED', delivered_at = NOW()
        WHERE id = ? AND status = 'APPROVED'
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    header("Location: " . $redirBase . "&scan=delivered&pid=$id");
    exit;
}

if ($status === 'REJECTED') {
    // Must go to Reslit or Recoil before re-entering QC
    header("Location: " . $redirBase . "&scan=rejected_blocked&pid=$id");
    exit;
}

if ($status === 'DELIVERED') {
    $lot_enc  = urlencode($lot);
    $coil_enc = urlencode($coil);
    header("Location: " . $redirBase
        . "&scan=already_delivered"
        . "&return_id=$id"
        . "&lot=$lot_enc&coil=$coil_enc");
    exit;
}

// Fallback
header("Location: " . $redirBase . "&scan=ignored&pid=$id");
exit;
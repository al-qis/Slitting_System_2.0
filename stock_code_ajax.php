<?php
// stock_code_ajax.php
// Returns the next available unique stock code for a given 4-digit YYMM prefix.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

if (!isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include 'config.php';

$prefix = trim($_GET['prefix'] ?? $_POST['prefix'] ?? '');
if (str_contains($prefix, '-')) {
    $prefix = explode('-', $prefix)[0];
}
$prefix = trim($prefix);

if (!preg_match('/^\d{4}$/', $prefix)) {
    echo json_encode(['success' => false, 'message' => 'Invalid 4-digit YYMM prefix']);
    exit;
}

$yy = (int)substr($prefix, 0, 2);
$mm = (int)substr($prefix, 2, 2);

if ($mm < 1 || $mm > 12) {
    echo json_encode(['success' => false, 'message' => 'Invalid month in YYMM prefix']);
    exit;
}

$year = 2000 + $yy;
$dateStr = sprintf('%04d-%02d-01', $year, $mm);

$nextStockCode = generateStockCode($conn, $dateStr);
$parts = explode('-', $nextStockCode);
$seq = $parts[1] ?? '';

echo json_encode([
    'success'    => true,
    'prefix'     => $prefix,
    'seq'        => $seq,
    'stock_code' => $nextStockCode
]);
exit;

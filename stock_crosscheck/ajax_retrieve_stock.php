<?php
/**
 * ajax_retrieve_stock.php
 * -----------------------------------------------------------------------
 * Searches slitting_product table by Lot No (and optional Coil No + Roll No)
 * to allow Manual Coil Entry in index.php to retrieve matching stock rolls.
 * -----------------------------------------------------------------------
 */

header('Content-Type: application/json');
require_once dirname(__DIR__) . '/config.php';
$mysqli = $conn;

$lot  = trim($_GET['lot_no']  ?? $_POST['lot_no']  ?? '');
$coil = trim($_GET['coil_no'] ?? $_POST['coil_no'] ?? '');
$roll = trim($_GET['roll_no'] ?? $_POST['roll_no'] ?? '');

if ($lot === '') {
    echo json_encode(['success' => false, 'error' => 'Please provide a Lot No.']);
    exit;
}

$sql = "SELECT id, product, lot_no, coil_no, roll_no, width,
               COALESCE(actual_length, length, 0) AS length, customer_name, ref_no
        FROM slitting_product
        WHERE (is_voided IS NULL OR is_voided = 0)
          AND LOWER(TRIM(lot_no)) = LOWER(TRIM(?))";

$types  = 's';
$params = [$lot];

if ($coil !== '') {
    $coilAlt = preg_match('/^(.*?)-(\d+)$/', $coil, $m) ? $m[1] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT) : $coil;
    $sql .= " AND (LOWER(TRIM(coil_no)) = LOWER(TRIM(?)) OR LOWER(TRIM(coil_no)) = LOWER(TRIM(?)))";
    $types .= 'ss';
    $params[] = $coil;
    $params[] = $coilAlt;
}

if ($roll !== '') {
    $rollPadded   = str_pad($roll, 2, '0', STR_PAD_LEFT);
    $rollUnpadded = ltrim($roll, '0') ?: '0';
    $sql .= " AND (LOWER(TRIM(roll_no)) = LOWER(TRIM(?)) OR LOWER(TRIM(roll_no)) = LOWER(TRIM(?)) OR LOWER(TRIM(roll_no)) = LOWER(TRIM(?)))";
    $types .= 'sss';
    $params[] = $roll;
    $params[] = $rollPadded;
    $params[] = $rollUnpadded;
}

$sql .= " ORDER BY coil_no ASC, roll_no ASC LIMIT 100";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Database prepare failed.']);
    exit;
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($row = $res->fetch_assoc()) {
    // Strip decimal trailing zeros for width/length display
    $w = (float) $row['width'];
    $l = (float) $row['length'];
    $row['width_clean']  = (fmod($w, 1) == 0) ? (string)(int)$w : (string)$w;
    $row['length_clean'] = (fmod($l, 1) == 0) ? (string)(int)$l : (string)$l;
    $items[] = $row;
}
$stmt->close();

echo json_encode(['success' => true, 'count' => count($items), 'items' => $items]);

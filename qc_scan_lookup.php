<?php
// qc_scan_lookup.php — AJAX endpoint for QC Dashboard barcode scanning
session_start();
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'qc') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Access denied.']);
    exit;
}

$rawInput = trim($_POST['qr'] ?? $_POST['scan_input'] ?? $_GET['qr'] ?? '');

if ($rawInput === '') {
    echo json_encode(['ok' => false, 'msg' => 'Empty scan input.']);
    exit;
}

// Step 1: Sanitize input (strip control chars, AIM prefixes, leading junk)
$qr = preg_replace('/[[:cntrl:]]/', '', $rawInput);
$qr = preg_replace('/^\][A-Za-z][0-9]/', '', $qr);
$qr = preg_replace('/^[^\w]{0,6}(?=LOT=|COIL=|ROLL=)/i', '', $qr);
$qr = preg_replace('/^[\w]{1,6}(?=LOT=|COIL=|ROLL=)/i',  '', $qr);
$qr = trim($qr);

$lot    = '';
$coil   = '';
$roll   = '';
$pallet = '';

if (strpos($qr, '=') !== false) {
    // Format A: KEY=VALUE pairs (e.g. LOT=826277;COIL=FK-1;ROLL=R1)
    $pairs = [];
    foreach (explode(';', $qr) as $segment) {
        $segment = trim($segment);
        if (strpos($segment, '=') === false) continue;
        [$k, $v] = explode('=', $segment, 2);
        $pairs[strtoupper(trim($k))] = trim($v);
    }
    $lot  = $pairs['LOT']  ?? '';
    $coil = $pairs['COIL'] ?? '';
    $roll = $pairs['ROLL'] ?? '';
} else {
    // Format B: Space-separated "826277 FK-1 R1" or single token
    $tokens = preg_split('/\s+/', $qr);
    if (count($tokens) >= 2) {
        $lot  = trim($tokens[0] ?? '');
        $coil = trim($tokens[1] ?? '');
        $roll = trim($tokens[2] ?? '');
    } else {
        // Single string — could be Pallet No or single product ref
        $pallet = $qr;
    }
}

// Option 1: Lookup by Product Coil (lot_no + coil_no)
if ($lot !== '' && $coil !== '') {
    $sql = "
        SELECT sp.id AS slitting_product_id, sp.lot_no, sp.coil_no, sp.roll_no, sp.product,
               sp.status AS product_status,
               p.id AS pallet_id, p.pallet_no, p.status AS pallet_status
        FROM slitting_product sp
        LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
        LEFT JOIN pallets p       ON p.id = pi.pallet_id
        WHERE sp.lot_no  = ?
          AND sp.coil_no = ?
    ";
    if ($roll !== '') {
        $sql .= " AND (sp.roll_no = ? OR sp.roll_no = ?)";
        $rollWithR = (strpos($roll, 'R') === false) ? 'R' . $roll : $roll;
        $rollWithoutR = ltrim($roll, 'R-');
    }
    $sql .= " ORDER BY sp.id DESC LIMIT 1";

    $stmt = $conn->prepare($sql);
    if ($roll !== '') {
        $stmt->bind_param("ssss", $lot, $coil, $rollWithR, $rollWithoutR);
    } else {
        $stmt->bind_param("ss", $lot, $coil);
    }
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($res) {
        if ($res['pallet_id'] && $res['pallet_status'] === 'pending_qc') {
            echo json_encode([
                'ok'                  => true,
                'match_type'          => 'product',
                'pallet_id'           => (int)$res['pallet_id'],
                'pallet_no'           => $res['pallet_no'],
                'slitting_product_id' => (int)$res['slitting_product_id'],
                'lot_no'              => $res['lot_no'],
                'coil_no'             => $res['coil_no'],
                'roll_no'             => $res['roll_no'],
                'product'             => $res['product'],
                'pallet_status'       => $res['pallet_status'],
            ]);
            exit;
        } elseif ($res['pallet_id']) {
            echo json_encode([
                'ok'            => false,
                'reason'        => 'already_processed',
                'msg'           => "Pallet {$res['pallet_no']} is already processed (Status: " . strtoupper($res['pallet_status']) . ").",
                'pallet_no'     => $res['pallet_no'],
                'pallet_status' => $res['pallet_status'],
            ]);
            exit;
        } else {
            echo json_encode([
                'ok'     => false,
                'reason' => 'not_on_pallet',
                'msg'    => "Coil {$res['lot_no']} {$res['coil_no']} is not assigned to any pending pallet.",
            ]);
            exit;
        }
    }
}

// Option 2: Lookup directly by Pallet No or Pallet ID
$searchPallet = $pallet !== '' ? $pallet : $qr;
$palletIdSearch = null;
if (preg_match('/^P(?:ALLET)?[-_\s]*(\d+)$/i', $searchPallet, $m)) {
    $palletIdSearch = (int)$m[1];
} elseif (ctype_digit($searchPallet)) {
    $palletIdSearch = (int)$searchPallet;
}

$stmt = $conn->prepare("
    SELECT p.id AS pallet_id, p.pallet_no, p.status AS pallet_status,
           (SELECT pi.slitting_product_id FROM pallet_items pi WHERE pi.pallet_id = p.id ORDER BY pi.seq ASC LIMIT 1) AS first_product_id
    FROM pallets p
    WHERE p.pallet_no = ? " . ($palletIdSearch ? "OR p.id = ?" : "") . "
    LIMIT 1
");

if ($palletIdSearch) {
    $stmt->bind_param("si", $searchPallet, $palletIdSearch);
} else {
    $stmt->bind_param("s", $searchPallet);
}

$stmt->execute();
$palletRes = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($palletRes) {
    if ($palletRes['pallet_status'] === 'pending_qc') {
        echo json_encode([
            'ok'                  => true,
            'match_type'          => 'pallet',
            'pallet_id'           => (int)$palletRes['pallet_id'],
            'pallet_no'           => $palletRes['pallet_no'],
            'slitting_product_id' => (int)$palletRes['first_product_id'],
            'pallet_status'       => $palletRes['pallet_status'],
        ]);
        exit;
    } else {
        echo json_encode([
            'ok'            => false,
            'reason'        => 'already_processed',
            'msg'           => "Pallet {$palletRes['pallet_no']} is already processed (Status: " . strtoupper($palletRes['pallet_status']) . ").",
            'pallet_no'     => $palletRes['pallet_no'],
            'pallet_status' => $palletRes['pallet_status'],
        ]);
        exit;
    }
}

// Option 3: Fallback search by lot_no, coil_no, or roll_no individually
$stmt = $conn->prepare("
    SELECT sp.id AS slitting_product_id, sp.lot_no, sp.coil_no, sp.roll_no,
           p.id AS pallet_id, p.pallet_no, p.status AS pallet_status
    FROM slitting_product sp
    JOIN pallet_items pi ON pi.slitting_product_id = sp.id
    JOIN pallets p       ON p.id = pi.pallet_id
    WHERE (sp.lot_no = ? OR sp.coil_no = ? OR sp.roll_no = ?)
    ORDER BY (p.status = 'pending_qc') DESC, sp.id DESC
    LIMIT 1
");
$stmt->bind_param("sss", $searchPallet, $searchPallet, $searchPallet);
$stmt->execute();
$fbRes = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($fbRes) {
    if ($fbRes['pallet_status'] === 'pending_qc') {
        echo json_encode([
            'ok'                  => true,
            'match_type'          => 'fallback',
            'pallet_id'           => (int)$fbRes['pallet_id'],
            'pallet_no'           => $fbRes['pallet_no'],
            'slitting_product_id' => (int)$fbRes['slitting_product_id'],
            'lot_no'              => $fbRes['lot_no'],
            'coil_no'             => $fbRes['coil_no'],
            'roll_no'             => $fbRes['roll_no'],
            'pallet_status'       => $fbRes['pallet_status'],
        ]);
        exit;
    } else {
        echo json_encode([
            'ok'            => false,
            'reason'        => 'already_processed',
            'msg'           => "Pallet {$fbRes['pallet_no']} (matched from scan) is already processed (Status: " . strtoupper($fbRes['pallet_status']) . ").",
            'pallet_no'     => $fbRes['pallet_no'],
            'pallet_status' => $fbRes['pallet_status'],
        ]);
        exit;
    }
}

// Not found at all
echo json_encode([
    'ok'     => false,
    'reason' => 'not_found',
    'msg'    => "Pallet/Coil \"{$rawInput}\" not found or already processed.",
]);
exit;

<?php
/**
 * ajax_lookup.php
 * -----------------------------------------------------------------------
 * Called once per QR scan (triggered by the scanner's Enter keypress).
 *
 * Input (POST, from the raw QR string already split client-side):
 *   raw     - the full raw QR text (used as the server-side dedupe key)
 *   lot     - LOT value
 *   coil    - COIL value
 *   roll    - ROLL value
 *   width   - WIDTH value (present only for the NEW 5-field QR format)
 *   length  - LENGTH value (present only for the NEW 5-field QR format)
 *
 * Logic:
 *   1. Reject duplicate scans (server-side, session-based - authoritative
 *      even if the browser is refreshed mid-count).
 *   2. Validate the Lot No format (4-7 mixed-case alphanumeric chars).
 *   3. Always look up the product code from coil_product_map by coil_code.
 *   4. If width/length were not supplied (OLD 3-field QR format), fetch
 *      `width` and `actual_length` from slitting_product (matched on
 *      lot_no + coil_no + roll_no).
 *   5. Build the D365 ITEM NUMBER, D365 LOT NO, and MTR.
 *   6. Store the record in the session and return it as JSON.
 * -----------------------------------------------------------------------
 */

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/db_config.php';

if (!isset($_SESSION['scanned'])) {
    $_SESSION['scanned'] = []; // keyed by raw QR string
}

function respond($ok, $data = [], $error = '') {
    echo json_encode(array_merge(['success' => $ok, 'error' => $error], $data));
    exit;
}

// ---- 1. Read + trim input -------------------------------------------------
$raw    = trim($_POST['raw']    ?? '');
$lot    = trim($_POST['lot']    ?? '');
$coil   = trim($_POST['coil']   ?? '');
$roll   = trim($_POST['roll']   ?? '');
$width  = isset($_POST['width'])  && $_POST['width']  !== '' ? trim($_POST['width'])  : null;
$length = isset($_POST['length']) && $_POST['length'] !== '' ? trim($_POST['length']) : null;

if ($raw === '' || $lot === '' || $coil === '' || $roll === '') {
    respond(false, [], 'Malformed QR code: missing LOT, COIL, or ROLL.');
}

// ---- 2. Validate Lot No format --------------------------------------------
if (!preg_match('/^[A-Za-z0-9]{4,7}$/', $lot)) {
    respond(false, [], "Invalid Lot No \"$lot\" - must be 4-7 alphanumeric characters.");
}

// ---- 3. Server-side dedupe (authoritative) --------------------------------
if (isset($_SESSION['scanned'][$raw])) {
    respond(false, ['duplicate' => true], 'Already Scanned');
}

// ---- 4a. Coil prefix - still needed to trigger the ED special format ------
// (e.g. "CH-2" -> "CH"). No longer used to determine the product code itself.
function coilPrefix($coil) {
    if (preg_match('/^([A-Za-z]+)/', $coil, $m)) {
        return $m[1];
    }
    return $coil; // fall back unchanged if it doesn't start with letters
}
$coilCode = coilPrefix($coil);

// ---- 4b. Product code + Width/Length ALWAYS come from slitting_product ----
// This is the ground-truth row for the EXACT physical item scanned (matched
// on lot_no + coil_no + roll_no). A coil family alone (e.g. "HPM") can be
// shared between multiple products, so we can no longer guess the product
// from the coil - only this specific lot's own record knows for sure.
$stmt = $mysqli->prepare(
    'SELECT product, width, actual_length FROM slitting_product
     WHERE lot_no = ? AND coil_no = ? AND roll_no = ? LIMIT 1'
);
$stmt->bind_param('sss', $lot, $coil, $roll);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if (!$row) {
    respond(false, [], "No matching record for Lot \"$lot\", Coil \"$coil\", Roll \"$roll\" in slitting_product table.");
}
$productCode = $row['product']; // ground-truth raw product name, e.g. "MV-4020"

// New format supplies width/length directly; old format falls back to DB.
if ($width === null)  { $width  = $row['width']; }
if ($length === null) { $length = $row['actual_length']; }

if ($width === null || $width === '' || $length === null || $length === '') {
    respond(false, [], "Width/Length could not be determined for Lot No \"$lot\".");
}

// slitting_product stores width/actual_length as decimal(10,2), e.g. "914.00".
// Strip trailing zeros/decimal point so whole numbers read as "914", not "914.00".
function cleanNumber($val) {
    if (!is_numeric($val)) return $val;
    if (fmod((float)$val, 1) == 0) return (string)(int)$val;
    return rtrim(rtrim(sprintf('%.2f', (float)$val), '0'), '.');
}
$width  = cleanNumber($width);
$length = cleanNumber($length);

// ---- 4c. D365 alias for the Item Number only (raw product name stays as-is
// in the "Product Code" column for traceability) - extend this list as new
// aliased products come up.
$d365ProductAliases = [
    'MV-4020' => 'JPM',
];
$d365ProductCode = $d365ProductAliases[$productCode] ?? $productCode;

// ---- 5. Format Coil to two digits (CH-2 -> CH-02) -------------------------
function formatCoil($coil) {
    if (preg_match('/^(.*?)-(\d+)$/', $coil, $m)) {
        return $m[1] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
    }
    return $coil; // fall back unchanged if it doesn't match the expected pattern
}
$coilFormatted = formatCoil($coil);

// ---- 6. Build D365 fields ---------------------------------------------------
// Width decimal formatting depends on digit count (STANDARD products only):
//   1-2 digit widths  -> 2 decimal places (e.g. 94  -> "94.00")
//   3+  digit widths  -> 1 decimal place  (e.g. 118 -> "118.0", 914 -> "914.0")
function formatWidthForItemNumber($width) {
    $intPart = (int) round((float)$width);
    $digits  = strlen((string)abs($intPart));
    $decimals = ($digits < 3) ? 2 : 1;
    return number_format((float)$width, $decimals, '.', '');
}

$mtr       = $length;
$d365LotNo = $lot . ' ' . $coilFormatted . ' ' . $roll; // always the standard format

// ---- 6a. Special D365 ITEM NUMBER format - ONLY for Coil Family "ED" (L1N2) --
// "ED" is the coil_code used to look up the product (not the product value
// itself, which resolves to something like "L1N2-2520-02"). All other coil
// families keep the standard "SF-{product code}-{width}" format.
//
// IMPORTANT: for this special case, the "2520" and "02" segments come from
// the PRODUCT CODE itself (constant per product line), NOT from the scanned
// physical Lot/Coil/Roll. Only the scanned Width feeds into this format.
// Product code is expected in the form PREFIX-LOTPART-COILPART, e.g.
// "L1N2-2520-02" -> prefix="L1N2", lotPart="2520", coilPart="02".
//
// Rules (width drives both the hyphen placement and decimal places):
//   width >= 100          -> no hyphen between lotPart/coilPart, 1 decimal  (109.5, 156.0)
//   width < 100, whole    -> no hyphen between lotPart/coilPart, 2 decimals (57.00)
//   width < 100, .5 etc.  -> KEEP hyphen between lotPart/coilPart, 1 decimal (54.5)
if (strtoupper($coilCode) === 'ED') {
    $codeParts = explode('-', $productCode);
    if (count($codeParts) >= 3) {
        $prefix   = $codeParts[0];
        $lotPart  = $codeParts[1];
        $coilPart = $codeParts[2];
    } else {
        // Product code doesn't match the expected PREFIX-LOT-COIL pattern -
        // fall back to using it as-is so nothing silently breaks.
        $prefix = $productCode;
        $lotPart = '';
        $coilPart = '';
    }

    $widthNum = (float) $width;

    if ($widthNum >= 100) {
        $widthStr = number_format($widthNum, 1, '.', '');
        $lotJoin  = $lotPart . $coilPart; // no hyphen
    } elseif (fmod($widthNum, 1) == 0.0) {
        $widthStr = number_format($widthNum, 2, '.', '');
        $lotJoin  = $lotPart . $coilPart; // no hyphen
    } else {
        $widthStr = number_format($widthNum, 1, '.', '');
        $lotJoin  = $lotPart . '-' . $coilPart; // keep hyphen
    }

    $d365ItemNumber = 'SF-' . $prefix . '-' . $lotJoin . '-' . $widthStr;
} else {
    $widthForItem   = formatWidthForItemNumber($width);
    $d365ItemNumber = 'SF-' . $d365ProductCode . '-' . $widthForItem;
}

$record = [
    'raw'              => $raw,
    'lot'              => $lot,
    'coil'             => $coil,
    'roll'             => $roll,
    'width'            => $width,
    'length'           => $length,
    'product_code'     => $productCode,
    'd365_item_number' => $d365ItemNumber,
    'd365_lot_no'      => $d365LotNo,
    'mtr'              => $mtr,
    'scanned_at'       => date('H:i:s'),
];

// Store in session so a page refresh does not lose progress.
$_SESSION['scanned'][$raw] = $record;

respond(true, ['record' => $record]);
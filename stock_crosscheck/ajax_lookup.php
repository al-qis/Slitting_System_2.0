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

// ---- 4a. Product code comes from coil_product_map, keyed by coil PREFIX ---
// The map only stores the coil family (e.g. "CH"), not the specific coil
// number - so "CH-2" needs to become "CH" before looking it up.
function coilPrefix($coil) {
    if (preg_match('/^([A-Za-z]+)/', $coil, $m)) {
        return $m[1];
    }
    return $coil; // fall back unchanged if it doesn't start with letters
}
$coilCode = coilPrefix($coil);

$stmt = $mysqli->prepare(
    'SELECT product FROM coil_product_map WHERE coil_code = ? LIMIT 1'
);
$stmt->bind_param('s', $coilCode);
$stmt->execute();
$result = $stmt->get_result();
$coilRow = $result->fetch_assoc();
$stmt->close();

if (!$coilRow) {
    respond(false, [], "Coil \"$coilCode\" (from \"$coil\") not found in coil_product_map table.");
}
$productCode = $coilRow['product'];

// ---- 4b. Width/Length (old QR format only) come from slitting_product -----
// Matched on lot_no + coil_no + roll_no together, since lot_no alone can repeat
// across multiple coils/rolls in this table (one row per individual roll).
if ($width === null || $length === null) {
    $stmt = $mysqli->prepare(
        'SELECT width, actual_length FROM slitting_product
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
} else {
    $row = [];
}

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

// ---- 5. Format Coil to two digits (CH-2 -> CH-02) -------------------------
function formatCoil($coil) {
    if (preg_match('/^(.*?)-(\d+)$/', $coil, $m)) {
        return $m[1] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
    }
    return $coil; // fall back unchanged if it doesn't match the expected pattern
}
$coilFormatted = formatCoil($coil);

// ---- 6. Build D365 fields ---------------------------------------------------
// Width decimal formatting depends on digit count:
//   1-2 digit widths  -> 2 decimal places (e.g. 94  -> "94.00")
//   3+  digit widths  -> 1 decimal place  (e.g. 118 -> "118.0", 914 -> "914.0")
function formatWidthForItemNumber($width) {
    $intPart = (int) round((float)$width);
    $digits  = strlen((string)abs($intPart));
    $decimals = ($digits < 3) ? 2 : 1;
    return number_format((float)$width, $decimals, '.', '');
}
$widthForItem   = formatWidthForItemNumber($width);
$d365ItemNumber = 'SF-' . $productCode . '-' . $widthForItem;
$d365LotNo      = $lot . ' ' . $coilFormatted . ' ' . $roll;
$mtr            = $length;

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
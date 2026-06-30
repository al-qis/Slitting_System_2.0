<?php
// =============================================================
// initial_stock_setup_add.php
// PLACEMENT: C:\Apache24\htdocs\slitting_system\initial_stock_setup_add.php
//
// PURPOSE (ONE-TIME MIGRATION TOOL):
//   Lets an authorized user register a legacy/physical product
//   directly into slitting_product WITHOUT a real mother_coil
//   record. This bypasses the normal "no mother coil -> no
//   product" rule, ONLY for initial system setup.
//
// HOW TO DISABLE THIS LATER (3 options, pick one):
//   A. Easiest:  set ENABLE_INITIAL_STOCK_SETUP = false below.
//   B. Tighter:  shrink ALLOWED_ROLES to just ['admin'] or remove
//                all roles to fully lock it out.
//   C. Cleanest: delete/rename this file + remove the button/
//                modal from finish_product.php once migration
//                is done. Nothing else in the system depends on
//                this file.
//
// SECURITY NOTE (flagging, not fixing silently):
//   This currently allows ANY logged-in role, per explicit
//   request. This is NOT recommended for anything beyond the
//   initial migration window -- it lets normal floor staff
//   bypass mother coil traceability permanently. Tighten
//   ALLOWED_ROLES below before going live.
// =============================================================

session_start();
include 'config.php';

// -------------------------------------------------------------
// CONFIG SWITCH -- flip to false to fully disable this feature
// -------------------------------------------------------------
const ENABLE_INITIAL_STOCK_SETUP = true;

// -------------------------------------------------------------
// ROLE GATE -- edit this array to restrict who can use the form
// Currently: any logged-in role (per your selection).
// Recommended before production: ['admin'] or ['admin','supervisor']
// -------------------------------------------------------------
const ALLOWED_ROLES = []; // empty array = no role restriction, any logged-in user

if (!ENABLE_INITIAL_STOCK_SETUP) {
    die("Initial Stock Setup has been disabled. This was a one-time migration tool.");
}

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if (!empty(ALLOWED_ROLES) && !in_array($_SESSION['role'], ALLOWED_ROLES, true)) {
    die("Access denied. Initial Stock Setup is restricted to: " . implode(', ', ALLOWED_ROLES));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: finish_product.php");
    exit;
}

// -------------------------------------------------------------
// 1. Collect + validate user-entered fields ONLY
//    (everything else below is hardcoded server-side)
//
//    NOTE: "Date In" and "Length" are NOT form fields anymore.
//      - Date In  -> always set to right-now, server-side.
//      - Length   -> always set equal to Actual, server-side.
//    There is nothing to read from $_POST for either of these.
//
//    Lot No and Coil No are now SEPARATE fields entered directly
//    by the user (previously Coil No was derived by splitting
//    Lot No on a space -- that logic has been removed).
// -------------------------------------------------------------
$product       = trim($_POST['product']      ?? '');
$lot_no        = trim($_POST['lot_no']       ?? '');
$coil_no       = trim($_POST['coil_no']      ?? '');
$roll_no       = trim($_POST['roll_no']      ?? '');
$width         = trim($_POST['width']        ?? '');
$actual_length = trim($_POST['actual_length']?? '');

$errors = [];

if ($product === '')        $errors[] = "Product is required.";
if ($lot_no === '')         $errors[] = "Lot No is required.";
if ($coil_no === '')        $errors[] = "Coil No is required.";
if ($roll_no === '')        $errors[] = "Roll No is required.";
if ($width === '' || !is_numeric($width))   $errors[] = "Width must be a valid number.";
if ($actual_length === '' || !is_numeric($actual_length)) {
    $errors[] = "Actual is required and must be a valid number.";
}

// Lot No format -- same 4-8 alphanumeric rule as Add Mother Coil.
// The HTML `pattern` attribute can be bypassed (devtools, direct POST),
// so it must be re-checked here.
if ($lot_no !== '' && !preg_match('/^[a-zA-Z0-9]{4,8}$/', $lot_no)) {
    $errors[] = "Lot No must be 4-8 alphanumeric characters.";
}

if (!empty($errors)) {
    die("<div style='color:red; font-family:sans-serif; padding:20px; border:1px solid red; background:#fff5f5;'>
            <h2>Validation Error</h2>

            <ul><li>" . implode('</li><li>', array_map('htmlspecialchars', $errors)) . "</li></ul>
            <button onclick='history.back()'>Go Back and Correct</button>
         </div>");
}

// -------------------------------------------------------------
// 1b. STRICT server-side re-check: Coil No must resolve to a known
//     product in coil_product_map, and the submitted Product must
//     be one of the products that Coil No actually resolves to.
//
//     This mirrors mother_coil.php's productsFromCoil() lookup --
//     same uppercasing, same leading-alphanumeric-token extraction,
//     same shrinking-prefix match against coil_product_map.coil_code.
//
//     WHY THIS EXISTS: the modal's JS disables/locks fields and
//     does the lookup client-side, but disabled inputs and client
//     JS can always be bypassed (devtools, direct POST/curl). The
//     decision was made that this tool stays STRICT -- Coil No
//     must match a real product, same as Add Mother Coil -- so
//     that rule has to be enforced here too, not just in the browser.
// -------------------------------------------------------------
function isu_products_from_coil(mysqli $conn, string $coil_no): array
{
    $coil_no = strtoupper(trim($coil_no));
    if ($coil_no === '') return [];

    preg_match('/^[A-Z0-9]+/', $coil_no, $m);
    $token = $m[0] ?? '';
    if ($token === '') return [];

    for ($len = strlen($token); $len >= 1; $len--) {
        $code = substr($token, 0, $len);

        $stmt = $conn->prepare(
            "SELECT product FROM coil_product_map WHERE coil_code = ? ORDER BY product"
        );
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (count($rows) > 0) {
            return array_column($rows, 'product');
        }
    }
    return [];
}

$matched_products = isu_products_from_coil($conn, $coil_no);

if (empty($matched_products)) {
    die("<div style='color:red; font-family:sans-serif; padding:20px; border:1px solid red; background:#fff5f5;'>
            <h2>Coil No Not Recognized</h2>
            <p>Coil No \"" . htmlspecialchars($coil_no) . "\" does not match any known product in coil_product_map.
               Initial Stock Setup requires a valid, recognized Coil No.</p>
            <button onclick='history.back()'>Go Back and Correct</button>
         </div>");
}

if (!in_array($product, $matched_products, true)) {
    die("<div style='color:red; font-family:sans-serif; padding:20px; border:1px solid red; background:#fff5f5;'>
            <h2>Product Mismatch</h2>
            <p>Submitted Product \"" . htmlspecialchars($product) . "\" does not match Coil No \"" . htmlspecialchars($coil_no) . "\".
               Expected one of: " . htmlspecialchars(implode(', ', $matched_products)) . "</p>
            <button onclick='history.back()'>Go Back and Correct</button>
         </div>");
}

$width         = (float)$width;
$actual_length = (float)$actual_length; // already validated as required + numeric above
$length        = $actual_length;        // Length is always exactly the same as Actual now

// -------------------------------------------------------------
// 2. Duplicate check (same Lot + Coil + Roll shouldn't already exist)
// -------------------------------------------------------------
$check_stmt = $conn->prepare(
    "SELECT id FROM slitting_product WHERE lot_no = ? AND coil_no = ? AND roll_no = ?"
);
$check_stmt->bind_param("sss", $lot_no, $coil_no, $roll_no);
$check_stmt->execute();
$dupe = $check_stmt->get_result();
if ($dupe->num_rows > 0) {
    $check_stmt->close();
    die("<div style='color:red; font-family:sans-serif; padding:20px; border:1px solid red; background:#fff5f5;'>
            <h2>Duplicate Entry</h2>
            <p>Lot: $lot_no, Coil: $coil_no, Roll: $roll_no already exists in the system.</p>
            <button onclick='history.back()'>Go Back and Correct</button>
         </div>");
}
$check_stmt->close();

// -------------------------------------------------------------
// 3. HARDCODED SYSTEM DEFAULTS -- not user-editable, per spec
//    - mother_id        = NULL  (mother_id column made NULLABLE)
//    - source            = 'stock'
//    - original_source   = 'initial_stock'
//    - status            = 'IN'      (treated as already-counted stock)
//    - is_completed      = 1
//    - stock_counted     = 1
//    - date_in           = right now (no form field for this anymore)
// -------------------------------------------------------------
$mother_id       = null;
$source          = 'stock';
$original_source = 'initial_stock';
$status          = 'IN';
$date_in         = date('Y-m-d H:i:s'); // always "now" -- real-time, not user-editable

$conn->begin_transaction();
try {
    // -------------------------------------------------------------
    // COLUMN / PLACEHOLDER COUNT -- kept deliberately explicit so
    // this never silently drifts out of sync again:
    //
    //   14 columns total in the INSERT below.
    //   2 of them (is_completed, stock_counted) are HARDCODED
    //   literal 1's written directly into the SQL -- they are NOT
    //   placeholders and must NOT appear in bind_param.
    //   => placeholders needed = 14 - 2 = 12 (exactly).
    //
    //   #   column            type
    //   1   mother_id         i
    //   2   source            s
    //   3   original_source   s
    //   4   product           s
    //   5   lot_no            s
    //   6   coil_no           s
    //   7   roll_no           s
    //   8   width             d
    //   9   length            d
    //  10   actual_length     d
    //  11   status            s
    //  12   date_in           s
    //  -----------------------------
    //  type string = "isssssssddss"  (exactly 12 characters)
    // -------------------------------------------------------------
    $stmt = $conn->prepare("
        INSERT INTO slitting_product
            (mother_id, source, original_source, product, lot_no, coil_no, roll_no,
             width, length, actual_length, status, is_completed, stock_counted, date_in)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?)
    ");

    $stmt->bind_param(
        "isssssssddss",
        $mother_id,
        $source,
        $original_source,
        $product,
        $lot_no,
        $coil_no,
        $roll_no,
        $width,
        $length,
        $actual_length,
        $status,
        $date_in
    );

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $new_id = $stmt->insert_id;
    $stmt->close();

    // -------------------------------------------------------------
    // 4. Generate QR payload -- same format your scanners expect
    //    LOT=xxx;COIL=xxx;ROLL=xxx  (matches scan_product_action.php)
    // -------------------------------------------------------------
    $qr_payload = "LOT={$lot_no};COIL={$coil_no};ROLL={$roll_no}";

    // If you store the QR string itself on the row, uncomment:
    // $qr_stmt = $conn->prepare("UPDATE slitting_product SET qr_code = ? WHERE id = ?");
    // $qr_stmt->bind_param("si", $qr_payload, $new_id);
    // $qr_stmt->execute();
    // $qr_stmt->close();

    $conn->commit();

} catch (Exception $e) {
    $conn->rollback();
    die("Error creating legacy stock record: " . htmlspecialchars($e->getMessage()));
}

// -------------------------------------------------------------
// 5. Redirect to a print/QR view (reuses existing sticker
//    printing flow if you have one -- adjust filename as needed)
// -------------------------------------------------------------
header("Location: finish_product.php?success=legacy_added&id={$new_id}&qr=" . urlencode($qr_payload));
exit;
<?php
// =============================================================
// initial_stock_setup_import.php
// PLACEMENT: C:\Apache24\htdocs\slitting_system\initial_stock_setup_import.php
//
// PURPOSE: Bulk Excel import for Initial Stock Setup, replacing
// manual field-by-field entry. Reuses isu_excel_parser.php for
// the actual string parsing, and the SAME strict coil->product
// validation as initial_stock_setup_add.php (a Coil No that
// doesn't resolve to a known product in coil_product_map blocks
// that row, same rule as the manual form / Add Mother Coil).
//
// EXPECTED EXCEL COLUMNS (header names, case-insensitive, any order):
//   "Item number", "Batch number", "Available physical"
//
// BEHAVIOR ON ERROR ROWS:
//   This does NOT abort the whole import on one bad row. Every
//   row is parsed and validated independently; good rows are
//   inserted, bad rows are collected into a report and shown to
//   the user at the end (with the original row number from the
//   Excel file, e.g. "Row 14: Item number ... has no width").
//   This matches how a human doing a bulk import would expect to
//   work -- fix the 3 bad rows out of 200, not redo the whole file.
// =============================================================

session_start();
include 'config.php';
require_once 'isu_excel_parser.php';

// Composer autoload for PhpSpreadsheet -- adjust path if different
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

const ENABLE_INITIAL_STOCK_SETUP = true;
const ALLOWED_ROLES = []; // same role gate as the manual entry endpoint

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

if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    die("No file uploaded, or upload failed (error code: " . ($_FILES['excel_file']['error'] ?? 'none') . ").");
}

$tmpPath = $_FILES['excel_file']['tmp_name'];
$origName = $_FILES['excel_file']['name'];
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
    die("Unsupported file type \"$ext\". Please upload .xlsx, .xls, or .csv.");
}

// -------------------------------------------------------------
// 1. Load workbook and locate the 3 required columns by header
//    name (case-insensitive, trimmed) rather than fixed column
//    letters -- so this still works if columns get reordered.
// -------------------------------------------------------------
try {
    $spreadsheet = IOFactory::load($tmpPath);
} catch (Exception $e) {
    die("Could not read the uploaded file: " . htmlspecialchars($e->getMessage()));
}

$sheet = $spreadsheet->getActiveSheet();
$rows  = $sheet->toArray(null, true, true, true); // keep column letters as keys (A, B, C...)

if (count($rows) < 2) {
    die("The uploaded file has no data rows (only a header, or is empty).");
}

$headerRow = $rows[1];
$colMap = []; // 'item_number' => 'A', 'batch_number' => 'C', etc.

foreach ($headerRow as $colLetter => $headerText) {
    $h = strtolower(trim((string)$headerText));
    if ($h === 'item number')        $colMap['item_number'] = $colLetter;
    if ($h === 'batch number')       $colMap['batch_number'] = $colLetter;
    if ($h === 'available physical') $colMap['available_physical'] = $colLetter;
}

$requiredCols = ['item_number', 'batch_number', 'available_physical'];
$missingCols = array_diff($requiredCols, array_keys($colMap));
if (!empty($missingCols)) {
    die("Missing required column(s) in the Excel file: " . implode(', ', $missingCols)
        . ". Expected headers: \"Item number\", \"Batch number\", \"Available physical\".");
}

// -------------------------------------------------------------
// 2. Same strict coil->product lookup used by the manual form.
//    Defined here too (not shared via include) so this file can
//    be dropped in independently -- consider refactoring both
//    into a shared helpers file if you'd rather not duplicate it.
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
        $stmt = $conn->prepare("SELECT product FROM coil_product_map WHERE coil_code = ? ORDER BY product");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $rowsFound = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($rowsFound) > 0) {
            return array_column($rowsFound, 'product');
        }
    }
    return [];
}

// -------------------------------------------------------------
// 3. Process every data row independently. Collect successes and
//    failures separately -- nothing is committed until ALL rows
//    have been validated, so a half-failed import never leaves
//    the database in a partially-imported state.
// -------------------------------------------------------------
$toInsert = [];   // rows that passed every check, ready for INSERT
$errors   = [];   // ['row' => excelRowNumber, 'message' => '...']

$rowNumbers = array_keys($rows);
foreach ($rowNumbers as $rowNum) {
    if ($rowNum === 1) continue; // header row, already consumed

    $rawRow = $rows[$rowNum];
    $itemNumber = (string)($rawRow[$colMap['item_number']] ?? '');
    $batchNumber = (string)($rawRow[$colMap['batch_number']] ?? '');
    $available = (string)($rawRow[$colMap['available_physical']] ?? '');

    // Skip fully blank rows silently (common at the end of a sheet)
    if (trim($itemNumber) === '' && trim($batchNumber) === '' && trim($available) === '') {
        continue;
    }

    try {
        $parsed = isu_parse_row([
            'item_number' => $itemNumber,
            'batch_number' => $batchNumber,
            'available_physical' => $available,
        ]);

        // Lot No format check -- same 4-8 alphanumeric rule as the
        // manual form / Add Mother Coil.
        if (!preg_match('/^[a-zA-Z0-9]{4,8}$/', $parsed['lot_no'])) {
            throw new InvalidArgumentException(
                "Lot No \"{$parsed['lot_no']}\" must be 4-8 alphanumeric characters."
            );
        }

        // Strict coil -> product check, same as the manual form.
        $matchedProducts = isu_products_from_coil($conn, $parsed['coil_no']);
        if (empty($matchedProducts)) {
            throw new InvalidArgumentException(
                "Coil No \"{$parsed['coil_no']}\" does not match any known product in coil_product_map."
            );
        }
        if (!in_array($parsed['product'], $matchedProducts, true)) {
            throw new InvalidArgumentException(
                "Parsed product \"{$parsed['product']}\" does not match Coil No \"{$parsed['coil_no']}\" "
                . "(expected one of: " . implode(', ', $matchedProducts) . ")."
            );
        }

        // Duplicate check against existing DB rows (same rule as manual form)
        $check = $conn->prepare(
            "SELECT id FROM slitting_product WHERE lot_no = ? AND coil_no = ? AND roll_no = ?"
        );
        $check->bind_param("sss", $parsed['lot_no'], $parsed['coil_no'], $parsed['roll_no']);
        $check->execute();
        $dupe = $check->get_result()->num_rows > 0;
        $check->close();
        if ($dupe) {
            throw new InvalidArgumentException(
                "Duplicate: Lot {$parsed['lot_no']}, Coil {$parsed['coil_no']}, Roll {$parsed['roll_no']} already exists."
            );
        }

        // Duplicate check WITHIN this same import batch (two rows in
        // the same file with the same lot+coil+roll would otherwise
        // both pass the DB check above and both get inserted).
        $batchKey = $parsed['lot_no'] . '|' . $parsed['coil_no'] . '|' . $parsed['roll_no'];
        foreach ($toInsert as $already) {
            if ($already['_batch_key'] === $batchKey) {
                throw new InvalidArgumentException(
                    "Duplicate within this file: Lot {$parsed['lot_no']}, Coil {$parsed['coil_no']}, Roll {$parsed['roll_no']} appears more than once."
                );
            }
        }

        $parsed['_batch_key'] = $batchKey;
        $parsed['_excel_row'] = $rowNum;
        $toInsert[] = $parsed;

    } catch (InvalidArgumentException $e) {
        $errors[] = ['row' => $rowNum, 'message' => $e->getMessage()];
    }
}

// -------------------------------------------------------------
// 4. PARTIAL IMPORT MODE: rows that failed validation are simply
//    skipped (not inserted) -- they do NOT block the rows that
//    passed. Both the inserted count and the error list are
//    shown together in the final report after step 5 runs.
// -------------------------------------------------------------
if (empty($toInsert) && empty($errors)) {
    die("No data rows found in the uploaded file (it may only contain a header row).");
}

if (empty($toInsert)) {
    // Every single row failed -- nothing to insert, show errors only.
    $errorHtml = '';
    foreach ($errors as $err) {
        $errorHtml .= "<li>Row {$err['row']}: " . htmlspecialchars($err['message']) . "</li>";
    }
    die("<div style='font-family:sans-serif; padding:20px; border:1px solid red; background:#fff5f5;'>
            <h2 style='color:red;'>Import Failed — all " . count($errors) . " row(s) had errors</h2>
            <p>Nothing was saved. Fix the issues below and re-upload.</p>
            <ul style='color:red;'>$errorHtml</ul>
            <button onclick='history.back()'>Go Back</button>
         </div>");
}

// -------------------------------------------------------------
// 5. Insert every row that passed validation. Rows that failed
//    validation were never added to $toInsert, so they're simply
//    absent here -- this transaction only ever contains good rows,
//    so there's nothing to roll back due to a "bad row" (only a
//    genuine DB-level failure, e.g. connection loss, would trigger
//    the catch block below).
//    Same hardcoded defaults as the manual single-entry form:
//    mother_id NULL, source='stock', original_source='initial_stock',
//    status='IN', is_completed=1, stock_counted=1, date_in=now.
// -------------------------------------------------------------
$conn->begin_transaction();
$insertedIds = [];
try {
    $stmt = $conn->prepare("
        INSERT INTO slitting_product
            (mother_id, source, original_source, product, lot_no, coil_no, roll_no,
             width, length, actual_length, status, is_completed, stock_counted, date_in)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?)
    ");

    foreach ($toInsert as $row) {
        $mother_id       = null;
        $source          = 'stock';
        $original_source = 'initial_stock';
        $status          = 'IN';
        $date_in         = date('Y-m-d H:i:s');
        $width           = (float)$row['width'];
        $length          = (float)$row['length'];
        $actual_length   = (float)$row['actual_length'];

        $stmt->bind_param(
            "isssssssddss",
            $mother_id, $source, $original_source, $row['product'],
            $row['lot_no'], $row['coil_no'], $row['roll_no'],
            $width, $length, $actual_length, $status, $date_in
        );

        if (!$stmt->execute()) {
            throw new Exception("Row {$row['_excel_row']}: " . $stmt->error);
        }
        $insertedIds[] = $stmt->insert_id;
    }
    $stmt->close();
    $conn->commit();

} catch (Exception $e) {
    $conn->rollback();
    die("Import failed during database insert: " . htmlspecialchars($e->getMessage())
        . ". None of the rows in this batch were saved (a genuine database error, not a row-validation issue).");
}

// -------------------------------------------------------------
// 6. Combined report: show what was imported AND what was skipped,
//    in the same response (partial-import mode, per your decision).
// -------------------------------------------------------------
$successCount = count($insertedIds);
$errorCount   = count($errors);

$reportHtml = "<div style='font-family:sans-serif; padding:20px; max-width:700px;'>";
$reportHtml .= "<h2>Import Complete</h2>";
$reportHtml .= "<p style='color:green; font-size:1.1em;'><strong>{$successCount}</strong> row(s) imported successfully.</p>";

if ($errorCount > 0) {
    $reportHtml .= "<p style='color:#b45309; font-size:1.1em;'><strong>{$errorCount}</strong> row(s) were skipped due to errors:</p>";
    $reportHtml .= "<ul style='color:#b45309;'>";
    foreach ($errors as $err) {
        $reportHtml .= "<li>Row {$err['row']}: " . htmlspecialchars($err['message']) . "</li>";
    }
    $reportHtml .= "</ul>";
    $reportHtml .= "<p>Fix the rows above in your Excel file and re-upload just those rows if needed.</p>";
}

$reportHtml .= "<a href='finish_product.php?success=bulk_import&count={$successCount}' class='btn' "
    . "style='display:inline-block; margin-top:10px; padding:8px 16px; background:#198754; color:white; "
    . "text-decoration:none; border-radius:4px;'>Back to Finish Product</a>";
$reportHtml .= "</div>";

die($reportHtml);


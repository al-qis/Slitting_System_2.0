<?php
/**
 * Side-by-Side Coil Verification & 3-Way Reconciliation Backend Processor
 * File Reader, Database Query (PDO MySQL), Evaluation Logic, and Excel Exporter
 * Location: /slitting_system/reconcile_side_by_side.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database config and composer autoloader
require_once __DIR__ . '/config.php';

// Composer Autoload for PhpSpreadsheet
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;

// Initialize PDO Database Connection
$pdo = null;
try {
    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    // Graceful fallback if PDO connection fails
    error_log("PDO Connection Error: " . $e->getMessage());
}

/**
 * 1. Data Source 1: Scanned Physical Store (Table 1 - Ground Truth)
 * Auto-queried from MySQL database for chosen month & year.
 */
function fetchScannedPhysicalStore(?PDO $pdo): array {
    // Ground Truth = actual scanned records only.
    // No month/year filtering and no fallback to other tables.
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT
                COALESCE(NULLIF(TRIM(d365_item_number), ''), NULLIF(TRIM(product_code), ''), 'N/A') AS d365_item_number,
                COALESCE(NULLIF(TRIM(d365_lot_no), ''), NULLIF(TRIM(lot), ''), 'N/A') AS d365_lot_no,
                CAST(COALESCE(NULLIF(mtr, ''), length, 0) AS DECIMAL(10,2)) AS mtr
            FROM stock_crosscheck_scans
            ORDER BY id DESC
        ");

        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Scanned Physical Store query error: " . $e->getMessage());
        return [];
    }
}

/**
 * 2. Data Source 2: D365 System Export File Reader (Table 2 - ERP Reference)
 * Reads uploaded .xlsx, .xls, or .csv using PhpSpreadsheet.
 */
/**
 * Normalize Lot No. for reconciliation matching.
 *
 * Purpose:
 * - Treat formatting variants such as HPM-06 and HPM-6 as the same lot.
 * - Preserve meaningful values such as 45Z08.
 * - Keep the original lot text for display/export.
 */
function normalizeLotKey(string $lot): string {
    $lot = strtoupper(trim($lot));

    // Normalize different dash characters to a normal hyphen.
    $lot = str_replace(
        ['‐', '‑', '‒', '–', '—', '−'],
        '-',
        $lot
    );

    // Normalize whitespace around hyphens.
    $lot = preg_replace('/\s*-\s*/', '-', $lot);

    // Remove leading zeroes ONLY from numeric portions immediately after a hyphen.
    // Example: HPM-06 -> HPM-6
    // Important: 45Z08 remains 45Z08.
    $lot = preg_replace_callback(
        '/-(0+)(\d+)/',
        static function ($matches) {
            $number = ltrim($matches[2], '0');
            return '-' . ($number === '' ? '0' : $number);
        },
        $lot
    );

    // Collapse repeated whitespace elsewhere.
    $lot = preg_replace('/\s+/', ' ', $lot);

    return $lot;
}

function readD365Spreadsheet(string $filePath): array {
    $d365DataMap = [];

    if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
        return [];
    }

    try {
        $spreadsheet = IOFactory::load($filePath);
        $sheet       = $spreadsheet->getActiveSheet();
        $allRows     = $sheet->toArray(null, true, true, true);

        if (empty($allRows)) {
            return [];
        }

        // Header detection from first non-empty row
        $headerRow = array_shift($allRows);
        $colItem = null;
        $colLot  = null;
        $colMtr  = null;

        foreach ($headerRow as $colLetter => $cellVal) {
            $headerText = strtolower(trim(preg_replace('/\s+/', ' ', (string)$cellVal)));
            
            // 1. Item Number matching (Item number, D365 ITEM NUMBER, Product, etc.)
            if ($colItem === null && (
                in_array($headerText, ['item number', 'item_number', 'd365 item number', 'd365_item_number', 'item no', 'item_no', 'product', 'item', 'item_code', 'item code'], true) ||
                strpos($headerText, 'item') !== false ||
                strpos($headerText, 'product') !== false
            )) {
                $colItem = $colLetter;
            } 
            
            // 2. Batch Number / Lot No matching (Batch number, D365 LOT NO, Lot no, Batch no, etc.)
            if ($colLot === null && (
                in_array($headerText, ['batch number', 'batch_number', 'batch no', 'batch_no', 'batch', 'd365 lot no', 'd365_lot_no', 'lot no', 'lot_no', 'lot number', 'lot_number', 'lot', 'serial number', 'serial_number'], true) ||
                strpos($headerText, 'batch') !== false ||
                strpos($headerText, 'lot') !== false ||
                strpos($headerText, 'serial') !== false
            )) {
                $colLot = $colLetter;
            }

            // 3. Physical Inventory / MTR matching (Physical inventory, MTR, Meter, Length, Qty, etc.)
            if ($colMtr === null && (
                in_array($headerText, ['physical inventory', 'physical_inventory', 'physical inv', 'physical_inv', 'inventory', 'physical qty', 'physical_qty', 'mtr', 'meter', 'meters', 'length', 'qty', 'quantity', 'actual mtr', 'erp mtr', 'd365 mtr', 'd365_mtr', 'on hand', 'on_hand'], true) ||
                strpos($headerText, 'inventory') !== false ||
                strpos($headerText, 'physical') !== false ||
                strpos($headerText, 'mtr') !== false ||
                strpos($headerText, 'meter') !== false ||
                strpos($headerText, 'length') !== false ||
                strpos($headerText, 'qty') !== false
            )) {
                $colMtr = $colLetter;
            }
        }

        // Default column positions if headers are standard A, B, C
        if (!$colItem) $colItem = 'A';
        if (!$colLot)  $colLot  = 'B';
        if (!$colMtr)  $colMtr  = 'C';

        foreach ($allRows as $row) {
            $rawItem = trim((string)($row[$colItem] ?? ''));
            $rawLot  = trim((string)($row[$colLot]  ?? ''));
            $rawMtr  = (string)($row[$colMtr] ?? '0');

            if ($rawItem === '' && $rawLot === '') {
                continue;
            }

            $cleanMtr = (float)preg_replace('/[^0-9.]/', '', $rawMtr);
            $d365DataMap[] = [
                'd365_item_number' => $rawItem !== '' ? $rawItem : 'N/A',
                'd365_lot_no'      => $rawLot !== '' ? $rawLot : 'N/A',
                'd365_mtr'          => round($cleanMtr, 2)
            ];
        }
    } catch (\Exception $e) {
        error_log("PhpSpreadsheet Reader Error: " . $e->getMessage());
    }

    return $d365DataMap;
}

/**
 * 3. Verification & Discrepancy Status (Table 3 - Auto Evaluation)
 * Performs 3-Way Reconciliation & Auto-Sort by Discrepancy Priority.
 */
function evaluateReconciliation(array $scannedRows, array $d365Map): array {
    $results = [];

    /*
     * 1-to-1 reconciliation
     * ----------------------
     * Every D365 record gets a unique internal index and can be consumed only once.
     * Matching priority:
     *   1. Exact normalized LOT + exact ITEM
     *   2. Exact normalized LOT
     *
     * This prevents two scanned rows from pointing to the same D365 row and
     * guarantees that a matched pair is rendered on the same reconciliation row.
     */

    // Convert the associative D365 map into a list so duplicate/similar lots
    // are still kept as separate records.
    $d365Records = [];
    foreach ($d365Map as $originalKey => $record) {
        $d365Records[] = [
            'original_key' => $originalKey,
            'item_key'     => strtoupper(trim((string)($record['d365_item_number'] ?? ''))),
            'lot_key'      => normalizeLotKey((string)($record['d365_lot_no'] ?? '')),
            'record'       => $record
        ];
    }

    $usedD365Indexes = [];

    // Find ONE unused D365 record for a scanned record.
    $findD365Match = function (string $scannedItem, string $scannedLot) use (
        &$d365Records,
        &$usedD365Indexes
    ) {
        $itemKey = strtoupper(trim($scannedItem));
        $lotKey  = normalizeLotKey($scannedLot);

        // Pass 1: strongest match = Item + normalized Lot.
        foreach ($d365Records as $idx => $candidate) {
            if (isset($usedD365Indexes[$idx])) {
                continue;
            }

            if (
                $candidate['item_key'] === $itemKey &&
                $candidate['lot_key'] === $lotKey &&
                $lotKey !== ''
            ) {
                $usedD365Indexes[$idx] = true;
                return $candidate['record'];
            }
        }

        // Pass 2: normalized Lot only.
        // Used only when the exact Item + Lot combination was not found.
        foreach ($d365Records as $idx => $candidate) {
            if (isset($usedD365Indexes[$idx])) {
                continue;
            }

            if (
                $candidate['lot_key'] === $lotKey &&
                $lotKey !== ''
            ) {
                $usedD365Indexes[$idx] = true;
                return $candidate['record'];
            }
        }

        return null;
    };

    // Loop 1: Every scanned record produces exactly ONE reconciliation row.
    foreach ($scannedRows as $scan) {
        $scannedItem = trim((string)($scan['d365_item_number'] ?? ''));
        $scannedLot  = trim((string)($scan['d365_lot_no'] ?? ''));
        $scannedMtr  = round((float)($scan['mtr'] ?? 0), 2);

        $d365Record = $findD365Match($scannedItem, $scannedLot);

        if ($d365Record !== null) {
            $d365Item = trim((string)($d365Record['d365_item_number'] ?? ''));
            $d365Lot  = trim((string)($d365Record['d365_lot_no'] ?? ''));
            $d365Mtr  = round((float)($d365Record['d365_mtr'] ?? 0), 2);

            // Compare Item Number exactly (case-insensitive).
            $statusItem = (
                $scannedItem !== '' &&
                strcasecmp($scannedItem, $d365Item) === 0
            );

            // Compare Lot Number using normalized keys.
            // HPM-06 == HPM-6, while display values remain untouched.
            $normalizedScannedLot = normalizeLotKey($scannedLot);
            $normalizedD365Lot    = normalizeLotKey($d365Lot);

            $statusLot = (
                $normalizedScannedLot !== '' &&
                $normalizedScannedLot === $normalizedD365Lot
            );

            // Compare MTR numerically.
            $statusMtr = (abs($scannedMtr - $d365Mtr) < 0.001);
            $nod       = round(abs($scannedMtr - $d365Mtr), 2);

            $hasDiscrepancy = (
                !$statusItem ||
                !$statusLot ||
                !$statusMtr ||
                $nod > 0.001
            );

            // IMPORTANT: scanned + matched D365 stay in ONE row.
            $results[] = [
                'scanned_item'    => $scannedItem,
                'scanned_lot'     => $scannedLot,
                'scanned_mtr'     => $scannedMtr,
                'd365_item'       => $d365Item,
                'd365_lot'        => $d365Lot,
                'd365_mtr'        => $d365Mtr,
                'status_item'     => $statusItem,
                'status_lot'      => $statusLot,
                'status_mtr'      => $statusMtr,
                'nod'             => $nod,
                'has_discrepancy' => $hasDiscrepancy
            ];
        } else {
            // Scanned record has no unused D365 counterpart.
            $results[] = [
                'scanned_item'    => $scannedItem,
                'scanned_lot'     => $scannedLot,
                'scanned_mtr'     => $scannedMtr,
                'd365_item'       => '-',
                'd365_lot'        => '-',
                'd365_mtr'        => 0.0,
                'status_item'     => false,
                'status_lot'      => false,
                'status_mtr'      => false,
                'nod'             => $scannedMtr,
                'has_discrepancy' => true
            ];
        }
    }

    // Loop 2: Any D365 record not consumed by a scanned record is genuinely
    // D365-only and gets its own discrepancy row.
    foreach ($d365Records as $idx => $candidate) {
        if (isset($usedD365Indexes[$idx])) {
            continue;
        }

        $d365Record = $candidate['record'];

        $d365Item = trim((string)($d365Record['d365_item_number'] ?? ''));
        $d365Lot  = trim((string)($d365Record['d365_lot_no'] ?? ''));
        $d365Mtr  = round((float)($d365Record['d365_mtr'] ?? 0), 2);

        $results[] = [
            'scanned_item'    => '-',
            'scanned_lot'     => '-',
            'scanned_mtr'     => 0.0,
            'd365_item'       => $d365Item,
            'd365_lot'        => $d365Lot,
            'd365_mtr'        => $d365Mtr,
            'status_item'     => false,
            'status_lot'      => false,
            'status_mtr'      => false,
            'nod'             => $d365Mtr,
            'has_discrepancy' => true
        ];
    }

    // Auto-sort discrepancy rows to the top.
    usort($results, function ($a, $b) {
        if ($a['has_discrepancy'] !== $b['has_discrepancy']) {
            return $a['has_discrepancy'] ? -1 : 1;
        }

        if ($a['nod'] !== $b['nod']) {
            return ($b['nod'] <=> $a['nod']);
        }

        $lotA = ($a['scanned_lot'] !== '-') ? $a['scanned_lot'] : $a['d365_lot'];
        $lotB = ($b['scanned_lot'] !== '-') ? $b['scanned_lot'] : $b['d365_lot'];

        return strcasecmp($lotA, $lotB);
    });

    return $results;
}

/**
 * 4. Core Functional Requirement 4: Export to Excel with PhpSpreadsheet
 */
function exportSideBySideExcel(array $reconciledResults, string $monthTitle, int $year): void {
    if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        die("PhpSpreadsheet library is required for Excel Export.");
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Reconciliation');

    // Title Banner
    $sheet->mergeCells('A1:J1');
    $sheet->setCellValue('A1', "SIDE-BY-SIDE COIL VERIFICATION RECONCILIATION REPORT - {$monthTitle} {$year}");
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new Color('FFFFFF'));
    $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1E293B');
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(35);

    // Super Headers (Row 3)
    $sheet->mergeCells('A3:C3');
    $sheet->setCellValue('A3', '1. SCANNED PHYSICAL STORE (GROUND TRUTH)');
    $sheet->getStyle('A3')->getFont()->setBold(true)->setColor(new Color('FFFFFF'));
    $sheet->getStyle('A3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F172A');
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->mergeCells('D3:F3');
    $sheet->setCellValue('D3', '2. D365 SYSTEM EXPORT (ERP REFERENCE)');
    $sheet->getStyle('D3')->getFont()->setBold(true)->setColor(new Color('FFFFFF'));
    $sheet->getStyle('D3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D9488');
    $sheet->getStyle('D3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->mergeCells('G3:J3');
    $sheet->setCellValue('G3', '3. VERIFICATION & DISCREPANCY STATUS (AUTO EVALUATION)');
    $sheet->getStyle('G3')->getFont()->setBold(true)->setColor(new Color('FFFFFF'));
    $sheet->getStyle('G3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF6D28D9');
    $sheet->getStyle('G3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Column Headers (Row 4)
    $columnMap = [
        'A' => 'D365 ITEM NUMBER',
        'B' => 'D365 LOT NO',
        'C' => 'MTR',
        'D' => 'D365 ITEM NUMBER',
        'E' => 'D365 LOT NO',
        'F' => 'MTR',
        'G' => 'STATUS ITEM NUMBER',
        'H' => 'STATUS LOT NO',
        'I' => 'STATUS MTR',
        'J' => 'NOD (Meters)'
    ];

    foreach ($columnMap as $col => $title) {
        $cellRef = "{$col}4";
        $sheet->setCellValue($cellRef, $title);
        $sheet->getStyle($cellRef)->getFont()->setBold(true)->setSize(10);
        $sheet->getStyle($cellRef)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');
        $sheet->getStyle($cellRef)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }
    $sheet->getRowDimension(4)->setRowHeight(24);

    // Render Data Rows (Row 5+)
    $rIdx = 5;
    foreach ($reconciledResults as $row) {
        $sheet->setCellValue("A{$rIdx}", $row['scanned_item']);
        $sheet->setCellValue("B{$rIdx}", $row['scanned_lot']);
        $sheet->setCellValue("C{$rIdx}", $row['scanned_mtr']);

        $sheet->setCellValue("D{$rIdx}", $row['d365_item']);
        $sheet->setCellValue("E{$rIdx}", $row['d365_lot']);
        $sheet->setCellValue("F{$rIdx}", $row['d365_mtr']);

        $sheet->setCellValue("G{$rIdx}", $row['status_item'] ? 'TRUE' : 'FALSE');
        $sheet->setCellValue("H{$rIdx}", $row['status_lot']  ? 'TRUE' : 'FALSE');
        $sheet->setCellValue("I{$rIdx}", $row['status_mtr']  ? 'TRUE' : 'FALSE');
        $sheet->setCellValue("J{$rIdx}", $row['nod']);

        // Alignments & Number formatting
        $sheet->getStyle("A{$rIdx}:B{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("C{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("C{$rIdx}")->getNumberFormat()->setFormatCode('#,##0.00');

        $sheet->getStyle("D{$rIdx}:E{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("F{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("F{$rIdx}")->getNumberFormat()->setFormatCode('#,##0.00');

        $sheet->getStyle("J{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("J{$rIdx}")->getNumberFormat()->setFormatCode('#,##0.00');

        // Status Styling (G, H, I)
        foreach (['G', 'H', 'I'] as $statusCol) {
            $statusVal = $sheet->getCell("{$statusCol}{$rIdx}")->getValue();
            $cellStyle = $sheet->getStyle("{$statusCol}{$rIdx}");
            $cellStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            if ($statusVal === 'TRUE') {
                $cellStyle->getFont()->setColor(new Color('FF0F5132'))->setBold(true);
                $cellStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD1E7DD');
            } else {
                // Soft red background (#f8d7da), bold red text (#721c24)
                $cellStyle->getFont()->setColor(new Color('FF721C24'))->setBold(true);
                $cellStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8D7DA');
            }
        }

        // NOD styling (J)
        $nodStyle = $sheet->getStyle("J{$rIdx}");
        if ($row['nod'] > 0) {
            // Soft yellow (#fff3cd), dark yellow text (#856404)
            $nodStyle->getFont()->setColor(new Color('FF856404'))->setBold(true);
            $nodStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF3CD');
        }

        $rIdx++;
    }

    // Auto Column Widths
    foreach (range('A', 'J') as $colLetter) {
        $sheet->getColumnDimension($colLetter)->setAutoSize(true);
    }

    // Border Styling for the whole table
    $lastRow = $rIdx - 1;
    if ($lastRow >= 4) {
        $sheet->getStyle("A3:J{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FFCBD5E1'));
    }

    // Download headers
    $fileName = "Coil_Verification_Reconciliation_" . date('Ymd_His') . ".xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// ── Controller Handler ─────────────────────────────────────────

$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

$selectedMonth = intval($_REQUEST['month'] ?? date('n'));
$selectedYear  = intval($_REQUEST['year']  ?? date('Y'));
$action        = $_REQUEST['action'] ?? '';

// Clear uploaded D365 file
if ($action === 'clear_file') {
    unset($_SESSION['last_d365_data'], $_SESSION['last_d365_filename']);
    header('Location: coil_verification.php');
    exit;
}

// Parse uploaded D365 file if provided
$d365Map = [];
if (isset($_FILES['d365_file']) && $_FILES['d365_file']['error'] === UPLOAD_ERR_OK) {
    $tmpPath = $_FILES['d365_file']['tmp_name'];
    $d365Map = readD365Spreadsheet($tmpPath);
    $_SESSION['last_d365_data'] = $d365Map;
    $_SESSION['last_d365_filename'] = $_FILES['d365_file']['name'];
} elseif (!empty($_SESSION['last_d365_data'])) {
    $d365Map = $_SESSION['last_d365_data'];
}

// Query MySQL for Ground Truth (Scanned Physical Store)
$scannedRows = fetchScannedPhysicalStore($pdo);

// Reconcile 3-way data
$reconciledResults = evaluateReconciliation($scannedRows, $d365Map);

// Handle sample template download
if ($action === 'download_template') {
    $sampleFile = __DIR__ . '/sample_d365_template.xlsx';
    if (file_exists($sampleFile)) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Sample_D365_Export_Template.xlsx"');
        header('Content-Length: ' . filesize($sampleFile));
        readfile($sampleFile);
        exit;
    }
}

// Trigger Excel Download if requested
if ($action === 'export') {
    $monthName = $monthNames[$selectedMonth] ?? 'Month';
    exportSideBySideExcel($reconciledResults, $monthName, $selectedYear);
    exit;
}

// If directly included in coil_verification.php, variables are available.
// If requested directly in browser as standalone page, load frontend UI:
if (basename($_SERVER['SCRIPT_FILENAME']) === 'reconcile_side_by_side.php') {
    require_once __DIR__ . '/coil_verification.php';
    exit;
}

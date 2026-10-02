<?php
/**
 * CoilReconciliationModel.php
 * Model for Side-by-Side Coil Verification & 3-Way Reconciliation
 * Location: /comparison_end_month/models/CoilReconciliationModel.php
 */

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;

class CoilReconciliationModel
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * 1. Data Source 1: Scanned Physical Store (Ground Truth)
     */
    public function fetchScannedPhysicalStore(): array
    {
        if (!$this->pdo) {
            return [];
        }

        try {
            $stmt = $this->pdo->query("
                SELECT
                    COALESCE(NULLIF(TRIM(d365_item_number), ''), NULLIF(TRIM(product_code), ''), 'N/A') AS d365_item_number,
                    COALESCE(NULLIF(TRIM(d365_lot_no), ''), NULLIF(TRIM(lot), ''), 'N/A') AS d365_lot_no,
                    CAST(COALESCE(NULLIF(mtr, ''), length, 0) AS DECIMAL(10,2)) AS mtr
                FROM stock_crosscheck_scans
                ORDER BY id DESC
            ");

            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log("Scanned Physical Store query error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Normalize Lot No. for reconciliation matching.
     */
    public static function normalizeLotKey(string $lot): string
    {
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

    /**
     * 2. Data Source 2: D365 System Export File Reader (ERP Reference)
     */
    public function readD365Spreadsheet(string $filePath): array
    {
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
                
                // Item Number matching
                if ($colItem === null && (
                    in_array($headerText, ['item number', 'item_number', 'd365 item number', 'd365_item_number', 'item no', 'item_no', 'product', 'item', 'item_code', 'item code'], true) ||
                    strpos($headerText, 'item') !== false ||
                    strpos($headerText, 'product') !== false
                )) {
                    $colItem = $colLetter;
                } 
                
                // Batch / Lot No matching
                if ($colLot === null && (
                    in_array($headerText, ['batch number', 'batch_number', 'batch no', 'batch_no', 'batch', 'd365 lot no', 'd365_lot_no', 'lot no', 'lot_no', 'lot number', 'lot_number', 'lot', 'serial number', 'serial_number'], true) ||
                    strpos($headerText, 'batch') !== false ||
                    strpos($headerText, 'lot') !== false ||
                    strpos($headerText, 'serial') !== false
                )) {
                    $colLot = $colLetter;
                }

                // Physical Inventory / MTR matching
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
     * 3. Verification & Discrepancy Status (3-Way Reconciliation Engine)
     */
    public function evaluateReconciliation(array $scannedRows, array $d365Map): array
    {
        $results = [];

        $d365Records = [];
        foreach ($d365Map as $originalKey => $record) {
            $d365Records[] = [
                'original_key' => $originalKey,
                'item_key'     => strtoupper(trim((string)($record['d365_item_number'] ?? ''))),
                'lot_key'      => self::normalizeLotKey((string)($record['d365_lot_no'] ?? '')),
                'record'       => $record
            ];
        }

        $usedD365Indexes = [];

        $findD365Match = function (string $scannedItem, string $scannedLot) use (
            &$d365Records,
            &$usedD365Indexes
        ) {
            $itemKey = strtoupper(trim($scannedItem));
            $lotKey  = self::normalizeLotKey($scannedLot);

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

        // Loop 1: Scanned records
        foreach ($scannedRows as $scan) {
            $scannedItem = trim((string)($scan['d365_item_number'] ?? ''));
            $scannedLot  = trim((string)($scan['d365_lot_no'] ?? ''));
            $scannedMtr  = round((float)($scan['mtr'] ?? 0), 2);

            $d365Record = $findD365Match($scannedItem, $scannedLot);

            if ($d365Record !== null) {
                $d365Item = trim((string)($d365Record['d365_item_number'] ?? ''));
                $d365Lot  = trim((string)($d365Record['d365_lot_no'] ?? ''));
                $d365Mtr  = round((float)($d365Record['d365_mtr'] ?? 0), 2);

                $statusItem = (
                    $scannedItem !== '' &&
                    strcasecmp($scannedItem, $d365Item) === 0
                );

                $normalizedScannedLot = self::normalizeLotKey($scannedLot);
                $normalizedD365Lot    = self::normalizeLotKey($d365Lot);

                $statusLot = (
                    $normalizedScannedLot !== '' &&
                    $normalizedScannedLot === $normalizedD365Lot
                );

                $statusMtr = (abs($scannedMtr - $d365Mtr) < 0.001);
                $nod       = round(abs($scannedMtr - $d365Mtr), 2);

                $hasDiscrepancy = (
                    !$statusItem ||
                    !$statusLot ||
                    !$statusMtr ||
                    $nod > 0.001
                );

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

        // Loop 2: Unmatched D365 records
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

        // Sort results
        usort($results, function ($a, $b) {
            $itemA = ($a['scanned_item'] !== '-') ? $a['scanned_item'] : $a['d365_item'];
            $itemB = ($b['scanned_item'] !== '-') ? $b['scanned_item'] : $b['d365_item'];

            $itemCompare = strnatcasecmp((string)$itemA, (string)$itemB);
            if ($itemCompare !== 0) {
                return $itemCompare;
            }

            if ($a['has_discrepancy'] !== $b['has_discrepancy']) {
                return $a['has_discrepancy'] ? -1 : 1;
            }

            $lotA = ($a['scanned_lot'] !== '-') ? $a['scanned_lot'] : $a['d365_lot'];
            $lotB = ($b['scanned_lot'] !== '-') ? $b['scanned_lot'] : $b['d365_lot'];

            $lotCompare = strnatcasecmp((string)$lotA, (string)$lotB);
            if ($lotCompare !== 0) {
                return $lotCompare;
            }

            return ($b['nod'] <=> $a['nod']);
        });

        return $results;
    }

    /**
     * Compute Summary Metrics for UI KPIs
     */
    public function calculateMetrics(array $reconciledResults, array $scannedRows, array $d365Map): array
    {
        $totalRows          = count($reconciledResults);
        $actualScannedCount = count($scannedRows);
        $d365Count          = count($d365Map);
        $trueCount          = 0;
        $falseCount         = 0;
        $totalNodVariance   = 0.0;
        $coilNodCount       = 0;

        foreach ($reconciledResults as $row) {
            if ($row['has_discrepancy']) {
                $falseCount++;
            } else {
                $trueCount++;
            }
            $totalNodVariance += $row['nod'];
            if ((float)$row['nod'] > 0) {
                $coilNodCount++;
            }
        }

        return [
            'totalRows'          => $totalRows,
            'actualScannedCount' => $actualScannedCount,
            'd365Count'          => $d365Count,
            'trueCount'          => $trueCount,
            'falseCount'         => $falseCount,
            'totalNodVariance'   => $totalNodVariance,
            'coilNodCount'       => $coilNodCount,
        ];
    }

    /**
     * 4. Export to Excel with PhpSpreadsheet
     */
    public function exportSideBySideExcel(array $reconciledResults, ?string $monthTitle = null, ?int $year = null): void
    {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            die("PhpSpreadsheet library is required for Excel Export.");
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Reconciliation');

        // Title Banner
        $reportTitle = "SIDE-BY-SIDE COIL VERIFICATION RECONCILIATION REPORT";
        if ($monthTitle !== null && $year !== null) {
            $reportTitle .= " - {$monthTitle} {$year}";
        }

        $sheet->mergeCells('A1:J1');
        $sheet->setCellValue('A1', $reportTitle);
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
                    $cellStyle->getFont()->setColor(new Color('FF721C24'))->setBold(true);
                    $cellStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8D7DA');
                }
            }

            // NOD styling (J)
            $nodStyle = $sheet->getStyle("J{$rIdx}");
            if ($row['nod'] > 0) {
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
}

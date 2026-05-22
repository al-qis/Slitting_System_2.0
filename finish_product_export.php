<?php
// finish_product_export.php
ob_start();

include 'config.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Month & Year
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
if ($month < 1 || $month > 12) { $month = (int)date('m'); }
if ($year < 2000 || $year > 2100) { $year = (int)date('Y'); }

$monthName = date("F", mktime(0,0,0,$month,1,$year));
$generated = date('d M Y, H:i');

// Query
$sql = "SELECT id, status, source, product, lot_no, coil_no, roll_no,
               width, actual_length, date_in, date_out, delivered_at
        FROM slitting_product
        WHERE (is_recoiled=0 OR is_recoiled IS NULL)
          AND (is_reslitted=0 OR is_reslitted IS NULL)
          AND (
              (status='IN'        AND MONTH(date_in)=$month        AND YEAR(date_in)=$year)
           OR (status IN ('WAITING','OUT','APPROVED')
                                  AND MONTH(date_out)=$month       AND YEAR(date_out)=$year)
           OR (status='DELIVERED' AND MONTH(delivered_at)=$month   AND YEAR(delivered_at)=$year)
          )
        ORDER BY id ASC";

$result = $conn->query($sql);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("Finish Product");

// ── Title block ──────────────────────────────────────────────
$totalCols = 'J'; // A–J (10 columns, no QR)

$sheet->mergeCells("A1:{$totalCols}1");
$sheet->setCellValue('A1', 'FINISH PRODUCT REPORT');
$sheet->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 16, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E3A5F']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(1)->setRowHeight(32);

$sheet->mergeCells("A2:{$totalCols}2");
$sheet->setCellValue('A2', "Period: {$monthName} {$year}   |   Generated: {$generated}   |   System: Slitting Management");
$sheet->getStyle('A2')->applyFromArray([
    'font'      => ['size' => 10, 'color' => ['argb' => 'FFBEE3F8']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2C5282']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(2)->setRowHeight(18);

// Spacer row
$sheet->mergeCells("A3:{$totalCols}3");
$sheet->getRowDimension(3)->setRowHeight(6);

// ── Column headers (row 4) ───────────────────────────────────
$headers = ['#', 'Status', 'Source', 'Product', 'Lot & Coil No.',
            'Roll No.', 'Width (mm)', 'Actual Length (m)', 'Date In', 'Date Out'];
$sheet->fromArray($headers, NULL, 'A4');

$sheet->getStyle('A4:J4')->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF343A40']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                     'color'       => ['argb' => 'FF6C757D']]],
]);
$sheet->getRowDimension(4)->setRowHeight(20);

// ── Data rows (start at row 5) ───────────────────────────────
$rowNum  = 5;
$counter = 0;

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $counter++;
        $lotCoil      = trim($row['lot_no'] ?? '') . ' ' . trim($row['coil_no'] ?? '');
        $formattedRoll = str_replace('R', 'R-', $row['roll_no'] ?? '');

        // Date Out: prefer delivered_at, then date_out
        $dateOut = $row['delivered_at'] ?: ($row['date_out'] ?: '-');

        $rowData = [
            $counter,
            strtoupper($row['status'] ?? '-'),
            strtoupper($row['source'] ?? '-'),
            $row['product'] ?? '-',
            $lotCoil,
            $formattedRoll,
            $row['width'] ?? 0,
            $row['actual_length'] ?? 0,
            $row['date_in']  ?: '-',
            $dateOut,
        ];

        $sheet->fromArray($rowData, NULL, "A{$rowNum}");

        // Zebra striping
        $fillColor = ($counter % 2 === 0) ? 'FFF8F9FA' : 'FFFFFFFF';
        $sheet->getStyle("A{$rowNum}:J{$rowNum}")->applyFromArray([
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $fillColor]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                             'color'       => ['argb' => 'FFDEE2E6']]],
        ]);

        // Right-align numeric columns (Width, Actual Length)
        $sheet->getStyle("G{$rowNum}:H{$rowNum}")->getAlignment()
              ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Center counter column
        $sheet->getStyle("A{$rowNum}")->getAlignment()
              ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getRowDimension($rowNum)->setRowHeight(16);
        $rowNum++;
    }
}

// ── Column widths ────────────────────────────────────────────
$colWidths = ['A'=>6, 'B'=>14, 'C'=>12, 'D'=>14, 'E'=>22,
              'F'=>10, 'G'=>12, 'H'=>18, 'I'=>18, 'J'=>18];
foreach ($colWidths as $col => $width) {
    $sheet->getColumnDimension($col)->setWidth($width);
}

// ── Outer border around data table ───────────────────────────
if ($rowNum > 5) {
    $sheet->getStyle("A4:J" . ($rowNum - 1))->applyFromArray([
        'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM,
                                    'color'       => ['argb' => 'FF343A40']]],
    ]);
}

// ── Output ───────────────────────────────────────────────────
ob_end_clean();

$filename = "FinishProduct_{$year}_{$month}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$xlsxWriter = new Xlsx($spreadsheet);
$xlsxWriter->save('php://output');
exit;
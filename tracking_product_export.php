<?php
// tracking_product_export.php
// Streams an Excel (.xlsx) file with the same filters as tracking_product.php.
// Uses PhpSpreadsheet if available, falls back to CSV if not.

// use statements must be at the top before any executable code
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Font;

session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

include 'config.php';

// ── Read filters (same as tracking_product.php) ───────────────
$search          = trim($_GET['search']   ?? '');
$filter_status   = trim($_GET['status']   ?? '');
$filter_month    = intval($_GET['month']  ?? 0);
$filter_year     = intval($_GET['year']   ?? date('Y'));
$filter_customer = trim($_GET['customer'] ?? '');

// ── Build query (identical logic to tracking_product.php) ─────
$sql = "
    SELECT
        sp.id,
        sp.product,
        sp.lot_no,
        sp.coil_no,
        sp.roll_no,
        sp.width,
        sp.actual_length,
        sp.length,
        sp.status,
        sp.stock_counted,
        sp.is_reslitted,
        sp.is_recoiled,
        sp.date_in,
        sp.date_out,
        sp.delivered_at,
        sp.original_source,
        sp.customer_name,
        sp.ref_no,
        mc.grade
    FROM slitting_product sp
    LEFT JOIN mother_coil mc ON mc.id = sp.mother_id
    WHERE sp.is_voided = 0
";

$params = [];
$types  = '';

if ($search !== '') {
    $sql   .= " AND (
                    sp.lot_no                           LIKE ? OR
                    sp.coil_no                          LIKE ? OR
                    sp.roll_no                          LIKE ? OR
                    sp.product                          LIKE ? OR
                    sp.customer_name                    LIKE ? OR
                    CONCAT(sp.lot_no, ' ', sp.coil_no)  LIKE ? OR
                    CONCAT(sp.coil_no, ' ', sp.lot_no)  LIKE ?
                )";
    $like   = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like]);
    $types .= 'sssssss';
}

if ($filter_status !== '') {
    $sql    .= " AND sp.status = ?";
    $params[] = $filter_status;
    $types  .= 's';
}

if ($filter_customer !== '') {
    $sql    .= " AND sp.customer_name LIKE ?";
    $params[] = '%' . $filter_customer . '%';
    $types  .= 's';
}

if ($filter_month > 0) {
    $sql .= " AND (
        (sp.status = 'DELIVERED' AND MONTH(sp.delivered_at) = ? AND YEAR(sp.delivered_at) = ?)
        OR
        (sp.status != 'DELIVERED' AND MONTH(sp.date_in) = ? AND YEAR(sp.date_in) = ?)
    )";
    $params = array_merge($params, [$filter_month, $filter_year, $filter_month, $filter_year]);
    $types .= 'iiii';
}

$sql .= " ORDER BY
    CASE sp.status WHEN 'DELIVERED' THEN 0 ELSE 1 END ASC,
    COALESCE(sp.delivered_at, sp.date_in) DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Helper: human-readable status label ──────────────────────
function statusLabel(array $row): string
{
    $s  = $row['status'];
    $sc = (int)($row['stock_counted'] ?? 0);
    return match($s) {
        'DELIVERED' => 'Delivered',
        'APPROVED'  => 'Approved',
        'WAITING'   => 'Waiting QC',
        'REJECTED'  => 'Rejected',
        'IN'        => $sc ? 'Finish Good' : 'In Production',
        default     => $s,
    };
}

function fmtDate(?string $dt): string
{
    if (!$dt || $dt === '0000-00-00 00:00:00' || $dt === '0000-00-00') return '';
    return date('d M Y', strtotime($dt));
}

$filename = 'product_traceability_' . date('Ymd_His') . '.xlsx';

// ── Try PhpSpreadsheet first ──────────────────────────────────
$spreadsheetAvailable = false;
foreach ([
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
] as $autoload) {
    if (file_exists($autoload)) {
        require_once $autoload;
        if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $spreadsheetAvailable = true;
            break;
        }
    }
}

if ($spreadsheetAvailable) {
    // ════════════════════════════════════════════════════════
    // XLSX output via PhpSpreadsheet
    // ════════════════════════════════════════════════════════
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Product Traceability');

    // ── Title row ─────────────────────────────────────────
    $sheet->mergeCells('A1:J1');
    $sheet->setCellValue('A1', 'Global Traceability & Delivery Tracking');
    $sheet->getStyle('A1')->applyFromArray([
        'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0F2744']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(24);

    // ── Sub-title row ──────────────────────────────────────
    $sheet->mergeCells('A2:J2');
    $filterDesc = [];
    if ($search          !== '') $filterDesc[] = 'Search: ' . $search;
    if ($filter_status   !== '') $filterDesc[] = 'Status: ' . $filter_status;
    if ($filter_customer !== '') $filterDesc[] = 'Customer: ' . $filter_customer;
    if ($filter_month     > 0)   $filterDesc[] = date('F', mktime(0,0,0,$filter_month,1)) . ' ' . $filter_year;
    $sheet->setCellValue('A2', 'Exported: ' . date('d M Y H:i')
        . ($filterDesc ? '   |   Filters: ' . implode(', ', $filterDesc) : '   |   All records'));
    $sheet->getStyle('A2')->applyFromArray([
        'font' => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF6B7280']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF8FAFF']],
    ]);

    // ── Header row (row 3) ────────────────────────────────
    $headers = ['#', 'Status', 'Source', 'Product', 'Grade',
                'Lot No', 'Coil No', 'Roll No',
                'Width (mm)', 'Length (m)', 'Actual Length (m)',
                'Customer', 'Ref No',
                'Date Produced', 'QC Date', 'Delivered At'];
    $col = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($col . '3', $h);
        $col++;
    }
    $lastCol = chr(ord('A') + count($headers) - 1);

    $sheet->getStyle('A3:' . $lastCol . '3')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1A3D6E']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                         'vertical'   => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                        'color'       => ['argb' => 'FF93C5FD']]],
    ]);
    $sheet->getRowDimension(3)->setRowHeight(18);

    // ── Data rows ──────────────────────────────────────────
    $rowNum = 4;
    foreach ($rows as $i => $row) {
        $dispLen = $row['actual_length'] ?? $row['length'] ?? '';

        $data = [
            $i + 1,
            statusLabel($row),
            strtoupper($row['original_source'] ?? 'RAW MAT'),
            $row['product']       ?? '',
            $row['grade']         ?? '',
            $row['lot_no']        ?? '',
            $row['coil_no']       ?? '',
            str_replace('R', 'R-', $row['roll_no'] ?? ''),
            (float)($row['width'] ?? 0),
            is_numeric($row['length'])       ? (float)$row['length']       : '',
            is_numeric($row['actual_length']) ? (float)$row['actual_length'] : '',
            $row['customer_name'] ?? '',
            $row['ref_no']        ?? '',
            fmtDate($row['date_in']),
            fmtDate($row['date_out']),
            fmtDate($row['delivered_at']),
        ];

        $col = 'A';
        foreach ($data as $val) {
            $sheet->setCellValue($col . $rowNum, $val);
            $col++;
        }

        // Row background — alternate + status colour tint
        $bgColor = ($i % 2 === 0) ? 'FFFFFFFF' : 'FFF8FAFF';
        if ($row['status'] === 'DELIVERED') $bgColor = 'FFD1FAE5';
        elseif ($row['status'] === 'REJECTED')  $bgColor = 'FFFEE2E2';
        elseif ($row['status'] === 'WAITING')   $bgColor = 'FFFEF3C7';

        $sheet->getStyle('A' . $rowNum . ':' . $lastCol . $rowNum)->applyFromArray([
            'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bgColor]],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                            'color'       => ['argb' => 'FFE5E7EB']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $rowNum++;
    }

    // ── Summary row ────────────────────────────────────────
    $sheet->mergeCells('A' . $rowNum . ':C' . $rowNum);
    $sheet->setCellValue('A' . $rowNum, 'Total records: ' . count($rows));
    $sheet->getStyle('A' . $rowNum)->getFont()->setBold(true);

    // ── Column widths ──────────────────────────────────────
    $widths = [5, 14, 10, 16, 10, 16, 14, 10, 11, 12, 15, 20, 16, 14, 12, 14];
    foreach ($widths as $idx => $w) {
        $sheet->getColumnDimensionByColumn($idx + 1)->setWidth($w);
    }

    // ── Freeze panes & auto-filter ─────────────────────────
    $sheet->freezePane('A4');
    $sheet->setAutoFilter('A3:' . $lastCol . '3');

    // ── Stream ─────────────────────────────────────────────
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// ════════════════════════════════════════════════════════════════
// FALLBACK: CSV download (no dependencies needed)
// ════════════════════════════════════════════════════════════════
$csvFilename = str_replace('.xlsx', '.csv', $filename);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $csvFilename . '"');
header('Cache-Control: max-age=0');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel opens it correctly
fwrite($out, "\xEF\xBB\xBF");

// Header row
fputcsv($out, [
    '#', 'Status', 'Source', 'Product', 'Grade',
    'Lot No', 'Coil No', 'Roll No',
    'Width (mm)', 'Length (m)', 'Actual Length (m)',
    'Customer', 'Ref No',
    'Date Produced', 'QC Date', 'Delivered At',
]);

foreach ($rows as $i => $row) {
    fputcsv($out, [
        $i + 1,
        statusLabel($row),
        strtoupper($row['original_source'] ?? 'RAW MAT'),
        $row['product']       ?? '',
        $row['grade']         ?? '',
        $row['lot_no']        ?? '',
        $row['coil_no']       ?? '',
        str_replace('R', 'R-', $row['roll_no'] ?? ''),
        (float)($row['width'] ?? 0),
        is_numeric($row['length'])        ? (float)$row['length']        : '',
        is_numeric($row['actual_length']) ? (float)$row['actual_length'] : '',
        $row['customer_name'] ?? '',
        $row['ref_no']        ?? '',
        fmtDate($row['date_in']),
        fmtDate($row['date_out']),
        fmtDate($row['delivered_at']),
    ]);
}

fclose($out);
exit;
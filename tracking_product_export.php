<?php
// tracking_product_export.php
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

include 'config.php';

// ── Filters ───────────────────────────────────────────────────
$search          = trim($_GET['search']   ?? '');
$filter_status   = trim($_GET['status']   ?? '');
$filter_month    = intval($_GET['month']  ?? 0);
$filter_year     = intval($_GET['year']   ?? date('Y'));
$filter_customer = trim($_GET['customer'] ?? '');

// ── Query ─────────────────────────────────────────────────────
$sql = "
    SELECT sp.id, sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
           sp.width, sp.actual_length, sp.length, sp.status, sp.stock_counted,
           sp.is_reslitted, sp.is_recoiled, sp.date_in, sp.date_out,
           sp.delivered_at, sp.original_source, sp.customer_name, sp.ref_no,
           mc.grade
    FROM slitting_product sp
    LEFT JOIN mother_coil mc ON mc.id = sp.mother_id
    WHERE sp.is_voided = 0
";

$params = []; $types = '';

if ($search !== '') {
    $sql   .= " AND (sp.lot_no LIKE ? OR sp.coil_no LIKE ? OR sp.roll_no LIKE ?
                  OR sp.product LIKE ? OR sp.customer_name LIKE ?
                  OR CONCAT(sp.lot_no,' ',sp.coil_no) LIKE ?
                  OR CONCAT(sp.coil_no,' ',sp.lot_no) LIKE ?)";
    $like   = '%' . $search . '%';
    $params = array_merge($params, [$like,$like,$like,$like,$like,$like,$like]);
    $types .= 'sssssss';
}
if ($filter_status !== '') {
    $sql .= " AND sp.status = ?"; $params[] = $filter_status; $types .= 's';
}
if ($filter_customer !== '') {
    $sql .= " AND sp.customer_name LIKE ?"; $params[] = '%'.$filter_customer.'%'; $types .= 's';
}
if ($filter_month > 0) {
    $sql .= " AND ((sp.status='DELIVERED' AND MONTH(sp.delivered_at)=? AND YEAR(sp.delivered_at)=?)
                OR (sp.status!='DELIVERED' AND MONTH(sp.date_in)=?    AND YEAR(sp.date_in)=?))";
    $params = array_merge($params, [$filter_month,$filter_year,$filter_month,$filter_year]);
    $types .= 'iiii';
}
$sql .= " ORDER BY CASE sp.status WHEN 'DELIVERED' THEN 0 ELSE 1 END ASC,
          COALESCE(sp.delivered_at, sp.date_in) DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Helpers ───────────────────────────────────────────────────
function statusLabel(array $row): string {
    return match($row['status']) {
        'DELIVERED' => 'Delivered',
        'APPROVED'  => 'Approved',
        'WAITING'   => 'Waiting QC',
        'REJECTED'  => 'Rejected',
        'IN'        => (int)($row['stock_counted']??0) ? 'Finish Good' : 'In Production',
        default     => $row['status'],
    };
}
function fmtDate(?string $dt): string {
    if (!$dt || str_starts_with($dt,'0000')) return '';
    return date('d M Y', strtotime($dt));
}

$filename  = 'product_traceability_' . date('Ymd_His') . '.xlsx';
$generated = date('d M Y, H:i');

// ── PhpSpreadsheet ────────────────────────────────────────────
foreach ([__DIR__.'/vendor/autoload.php', dirname(__DIR__).'/vendor/autoload.php'] as $al) {
    if (file_exists($al)) { require_once $al; break; }
}

if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    // CSV fallback
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.str_replace('.xlsx','.csv',$filename).'"');
    $out = fopen('php://output','w');
    fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['#','Status','Source','Product','Grade','Lot No','Coil No','Roll No',
                  'Width (mm)','Length (m)','Actual Length (m)','Customer','Ref No',
                  'Date Produced','QC Date','Delivered At']);
    foreach ($rows as $i => $row) {
        fputcsv($out,[$i+1, statusLabel($row), strtoupper($row['original_source']??''),
            $row['product']??'', $row['grade']??'', $row['lot_no']??'',
            $row['coil_no']??'', str_replace('R','R-',$row['roll_no']??''),
            (float)($row['width']??0),
            is_numeric($row['length'])        ? (float)$row['length']        : '',
            is_numeric($row['actual_length']) ? (float)$row['actual_length'] : '',
            $row['customer_name']??'', $row['ref_no']??'',
            fmtDate($row['date_in']), fmtDate($row['date_out']), fmtDate($row['delivered_at'])]);
    }
    fclose($out); exit;
}

// ── Build spreadsheet ─────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Product Traceability');

$totalCols = 'P'; // 16 columns A–P

// Row 1 — Title
$sheet->mergeCells("A1:{$totalCols}1");
$sheet->setCellValue('A1', 'GLOBAL TRACEABILITY & DELIVERY TRACKING');
$sheet->getStyle('A1')->applyFromArray([
    'font'      => ['bold'=>true,'size'=>16,'color'=>['argb'=>'FFFFFFFF']],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF0F2744']],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,
                    'vertical'  =>Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(1)->setRowHeight(32);

// Row 2 — Sub-title
$filterDesc = [];
if ($search          !== '') $filterDesc[] = 'Search: '   . $search;
if ($filter_status   !== '') $filterDesc[] = 'Status: '   . $filter_status;
if ($filter_customer !== '') $filterDesc[] = 'Customer: ' . $filter_customer;
if ($filter_month     > 0)   $filterDesc[] = date('F', mktime(0,0,0,$filter_month,1)) . ' ' . $filter_year;

$sheet->mergeCells("A2:{$totalCols}2");
$sheet->setCellValue('A2',
    'Generated: ' . $generated
    . '   |   Records: ' . count($rows)
    . ($filterDesc ? '   |   Filters: ' . implode(', ', $filterDesc) : '   |   All records'));
$sheet->getStyle('A2')->applyFromArray([
    'font'      => ['size'=>10,'color'=>['argb'=>'FFBEE3F8']],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF2C5282']],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,
                    'vertical'  =>Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(2)->setRowHeight(18);

// Row 3 — Spacer
$sheet->mergeCells("A3:{$totalCols}3");
$sheet->getRowDimension(3)->setRowHeight(6);

// Row 4 — Column headers
$headers = ['#','Status','Source','Product','Grade',
            'Lot No','Coil No','Roll No',
            'Width (mm)','Length (m)','Actual Length (m)',
            'Customer','Ref No',
            'Date Produced','QC Date','Delivered At'];

$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col.'4', $h);
    $col++;
}
$sheet->getStyle('A4:'.$totalCols.'4')->applyFromArray([
    'font'      => ['bold'=>true,'color'=>['argb'=>'FFFFFFFF']],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>'FF1A3D6E']],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,
                    'vertical'  =>Alignment::VERTICAL_CENTER],
    'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,
                                   'color'      =>['argb'=>'FF93C5FD']]],
]);
$sheet->getRowDimension(4)->setRowHeight(20);

// ── Data rows (start row 5) ───────────────────────────────────
$rowNum = 5;
foreach ($rows as $i => $row) {
    $dispLen = $row['actual_length'] ?? $row['length'] ?? '';

    $data = [
        $i + 1,                                                    // A  #
        statusLabel($row),                                         // B  Status
        strtoupper($row['original_source'] ?? 'RAW MAT'),         // C  Source
        $row['product']       ?? '',                               // D  Product
        $row['grade']         ?? '',                               // E  Grade
        $row['lot_no']        ?? '',                               // F  Lot No   ← CENTER
        $row['coil_no']       ?? '',                               // G  Coil No
        str_replace('R','R-',$row['roll_no'] ?? ''),               // H  Roll No
        (float)($row['width'] ?? 0),                               // I  Width
        is_numeric($row['length'])        ? (float)$row['length']        : '', // J
        is_numeric($row['actual_length']) ? (float)$row['actual_length'] : '', // K
        $row['customer_name'] ?? '',                               // L  Customer
        $row['ref_no']        ?? '',                               // M  Ref No
        fmtDate($row['date_in']),                                  // N
        fmtDate($row['date_out']),                                 // O
        fmtDate($row['delivered_at']),                             // P
    ];

    $col = 'A';
    foreach ($data as $val) {
        $sheet->setCellValue($col.$rowNum, $val);
        $col++;
    }

    // Row background
    $bgColor = ($i % 2 === 0) ? 'FFFFFFFF' : 'FFF8FAFF';
    if ($row['status'] === 'DELIVERED') $bgColor = 'FFD1FAE5';
    elseif ($row['status'] === 'REJECTED') $bgColor = 'FFFEE2E2';
    elseif ($row['status'] === 'WAITING')  $bgColor = 'FFFEF3C7';

    $sheet->getStyle('A'.$rowNum.':'.$totalCols.$rowNum)->applyFromArray([
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['argb'=>$bgColor]],
        'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,
                                       'color'      =>['argb'=>'FFE5E7EB']]],
        'alignment' => ['vertical'=>Alignment::VERTICAL_CENTER],
    ]);

    // ── All columns center-aligned ───────────────────────
    $sheet->getStyle('A'.$rowNum.':'.$totalCols.$rowNum)->getAlignment()
          ->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->getRowDimension($rowNum)->setRowHeight(16);
    $rowNum++;
}

// ── Total row ─────────────────────────────────────────────────
$sheet->mergeCells('A'.$rowNum.':C'.$rowNum);
$sheet->setCellValue('A'.$rowNum, 'Total records: ' . count($rows));
$sheet->getStyle('A'.$rowNum)->getFont()->setBold(true);

// ── Column widths ─────────────────────────────────────────────
$colWidths = [
    'A'=>5,  'B'=>14, 'C'=>10, 'D'=>16, 'E'=>10,
    'F'=>18, 'G'=>14, 'H'=>10, 'I'=>11, 'J'=>12,
    'K'=>15, 'L'=>20, 'M'=>16, 'N'=>14, 'O'=>12, 'P'=>14,
];
foreach ($colWidths as $c => $w) {
    $sheet->getColumnDimension($c)->setWidth($w);
}

// Outer border around data table
if ($rowNum > 5) {
    $sheet->getStyle('A4:'.$totalCols.($rowNum-1))->applyFromArray([
        'borders' => ['outline' => ['borderStyle'=>Border::BORDER_MEDIUM,
                                    'color'      =>['argb'=>'FF0F2744']]],
    ]);
}

// Freeze header + autofilter
$sheet->freezePane('A5');
$sheet->setAutoFilter('A4:'.$totalCols.'4');

// ── Stream ────────────────────────────────────────────────────
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit;
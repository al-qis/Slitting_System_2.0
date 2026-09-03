<?php
// qc_export.php — v3: merged cells for repeated pallet fields + ticked-only checklist display
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'qc') {
    http_response_code(403);
    exit('Access denied');
}

require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

// ── Filters ───────────────────────────────────────────────────
$filter_status = $_GET['status']    ?? 'all';
$search        = trim($_GET['search']    ?? '');
$date_from     = $_GET['date_from'] ?? '';
$date_to       = $_GET['date_to']   ?? '';

$where  = "WHERE p.status IN ('approved','rejected','delivered')";
$params = [];
$types  = '';

if ($filter_status === 'approved') { $where .= " AND p.status = 'approved'"; }
elseif ($filter_status === 'rejected') { $where .= " AND p.status = 'rejected'"; }
elseif ($filter_status === 'delivered') { $where .= " AND p.status = 'delivered'"; }
if ($search !== '') {
    $where .= " AND (p.pallet_no LIKE ? OR p.customer_name LIKE ? OR p.product_type LIKE ? OR p.checked_by LIKE ?)";
    $like   = '%' . $search . '%';
    $params = array_merge($params, [$like,$like,$like,$like]);
    $types .= 'ssss';
}
if ($date_from !== '') { $where .= " AND DATE(p.updated_at) >= ?"; $params[] = $date_from; $types .= 's'; }
if ($date_to   !== '') { $where .= " AND DATE(p.updated_at) <= ?"; $params[] = $date_to;   $types .= 's'; }

// Ensure coil_width column exists in pallet_items table
$colCheck = $conn->query("SHOW COLUMNS FROM pallet_items LIKE 'coil_width'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE pallet_items ADD COLUMN coil_width TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Coil Width checkbox was ticked by QC inspector' AFTER hairy_rubber");
}

// ── Query ─────────────────────────────────────────────────────
$sql = "
    SELECT
        p.id                                    AS pallet_id,
        p.pallet_no,
        p.updated_at                            AS qc_datetime,
        sp.product,
        p.customer_name,
        sp.lot_no, sp.coil_no, sp.roll_no,
        COALESCE(sp.actual_length, sp.length)   AS qty_m,
        sp.width,
        pi.winding_condition,
        pi.hairy_rubber,
        pi.coil_width,
        p.checked_by,
        p.status                                AS pallet_status,
        pi.seq,
        pallet_totals.total_rolls
    FROM pallets p
    JOIN pallet_items pi     ON pi.pallet_id          = p.id
    JOIN slitting_product sp ON sp.id                 = pi.slitting_product_id
    JOIN (
        SELECT pallet_id, COUNT(*) AS total_rolls
        FROM pallet_items
        GROUP BY pallet_id
    ) pallet_totals ON pallet_totals.pallet_id = p.id
    $where
    ORDER BY p.updated_at DESC, p.id ASC, pi.seq ASC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Group rows by pallet_id ───────────────────────────────────
// Structure: [ pallet_id => [ 'meta' => [...], 'rolls' => [...] ] ]
$pallets = [];
foreach ($rows as $r) {
    $pid = $r['pallet_id'];
    if (!isset($pallets[$pid])) {
        $pallets[$pid] = [
            'meta'  => [
                'pallet_no'     => $r['pallet_no'],
                'qc_datetime'   => $r['qc_datetime'],
                'product'       => $r['product'],
                'customer_name' => $r['customer_name'],
                'width'         => $r['width'],
                'checked_by'    => $r['checked_by'],
                'pallet_status' => $r['pallet_status'],
                'total_rolls'   => $r['total_rolls'],
            ],
            'rolls' => [],
        ];
    }
    $pallets[$pid]['rolls'][] = $r;
}

// ── Design tokens ─────────────────────────────────────────────
$C_NAVY     = '1E2D3D';
$C_DARK     = '2C3E50';
$C_WHITE    = 'FFFFFF';
$C_HDR_BDR  = '1A2940';
$C_GRID     = 'CBD5E1';

$C_APPR_ODD = 'D1FAE5';
$C_APPR_EVN = 'ECFDF5';
$C_DELV_ODD = 'EDE9FE';
$C_DELV_EVN = 'F5F3FF';
$C_RJCT_ODD = 'FEE2E2';
$C_RJCT_EVN = 'FFF5F5';

$C_PASS_FG  = '065F46';
$C_PASS_BG  = 'BBFAD3';
$C_TICK_FG  = '14532D';

// Top-roll highlight (both checkboxes ticked)
$C_TOP_BG   = 'FEF9C3';   // soft amber background — full row
$C_TOP_LEFT = 'CA8A04';   // amber left border accent
$C_TOP_FG   = '713F12';   // dark amber text
$C_TOP_PASS_BG = 'A7F3D0'; // brighter green for ticked cells on top-roll row

$LAST_COL   = 'J';

// ── Spreadsheet ───────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('QC Inspection Summary');

// ── ROW 1: Title ──────────────────────────────────────────────
$sheet->mergeCells("A1:{$LAST_COL}1");
$sheet->setCellValue('A1', 'NICHIAS MK SLITTING SYSTEM — QC Inspection Summary');
$sheet->getStyle('A1')->applyFromArray([
    'font'      => ['bold'=>true,'size'=>13,'color'=>['rgb'=>$C_WHITE],'name'=>'Arial'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_NAVY]],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,
                    'vertical'  =>Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(1)->setRowHeight(28);

// ── ROW 2: Meta ───────────────────────────────────────────────
$sheet->mergeCells("A2:{$LAST_COL}2");
$metaParts = ['Exported: ' . date('d M Y  H:i:s')];
if ($filter_status !== 'all') $metaParts[] = 'Status: ' . ucfirst($filter_status);
if ($search !== '')           $metaParts[] = 'Search: "' . $search . '"';
if ($date_from !== '')        $metaParts[] = 'From: ' . date('d M Y', strtotime($date_from));
if ($date_to   !== '')        $metaParts[] = 'To: '   . date('d M Y', strtotime($date_to));
$sheet->setCellValue('A2', implode('   |   ', $metaParts));
$sheet->getStyle('A2')->applyFromArray([
    'font'      => ['italic'=>true,'size'=>9,'color'=>['rgb'=>$C_WHITE],'name'=>'Arial'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_DARK]],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_LEFT,
                    'vertical'  =>Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(2)->setRowHeight(15);

// ── ROW 3: spacer ─────────────────────────────────────────────
$sheet->getRowDimension(3)->setRowHeight(5);

// ── ROW 4: Headers ────────────────────────────────────────────
$headers = [
    'A' => 'Pallet No.',
    'B' => 'Date Approved/Rejected',
    'C' => 'Product',
    'D' => 'Customer',
    'E' => 'Lot, Coil, Roll',
    'F' => 'Length (m)',
    'G' => 'Width (mm)',
    'H' => 'Winding Condition',
    'I' => 'No Hairy Rubber',
    'J' => 'Checked By',
];
foreach ($headers as $col => $label) {
    $sheet->setCellValue("{$col}4", $label);
}
$sheet->getStyle("A4:{$LAST_COL}4")->applyFromArray([
    'font'      => ['bold'=>true,'size'=>10,'color'=>['rgb'=>$C_WHITE],'name'=>'Arial'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_DARK]],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,
                    'vertical'  =>Alignment::VERTICAL_CENTER,
                    'wrapText'  =>true],
    'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,
                                   'color'      =>['rgb'=>$C_HDR_BDR]]],
]);
$sheet->getRowDimension(4)->setRowHeight(24);

// ── AutoFilter on header row ──────────────────────────────────
$sheet->setAutoFilter("A4:{$LAST_COL}4");

// ── Freeze rows 1–4 ──────────────────────────────────────────
$sheet->freezePane('A5');

// ── DATA ROWS ─────────────────────────────────────────────────
// Columns that repeat per pallet and must be merged vertically:
//   A = Pallet No.
//   B = Date Approved/Rejected
//   C = Product
//   D = Customer
//   G = Width (mm)
//   J = Checked By
//
// Columns that are unique per roll (NOT merged):
//   E = Lot, Coil, Roll
//   F = Length (m)
//   H = Winding Condition  ← show only if ticked
//   I = No Hairy Rubber       ← show only if ticked

$rowNum         = 5;
$totalRollsAll  = 0;
$totalPallets   = count($pallets);

// Helper: apply vertical-center + border to a range
$applyBase = function(string $range, string $bg) use ($sheet, $C_GRID) {
    $sheet->getStyle($range)->applyFromArray([
        'font'      => ['size'=>9,'name'=>'Arial','color'=>['rgb'=>'1A2332']],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$bg]],
        'alignment' => ['vertical'  =>Alignment::VERTICAL_CENTER,
                        'wrapText'  =>false],
        'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,
                                       'color'      =>['rgb'=>$C_GRID]]],
    ]);
};

foreach ($pallets as $pid => $pallet) {
    $meta        = $pallet['meta'];
    $rolls       = $pallet['rolls'];
    $rollCount   = count($rolls);
    $statusLower = strtolower($meta['pallet_status']);
    $isRejected  = $statusLower === 'rejected';
    $isDelivered = $statusLower === 'delivered';
    $isApproved  = !$isRejected; // approved AND delivered both mean "passed QC"
    $totalRollsAll += $rollCount;

    // First and last row index for this pallet's rolls
    $firstRow = $rowNum;
    $lastRow  = $rowNum + $rollCount - 1;

    // Alternating bg colours for this pallet's rolls
    if ($isRejected) {
        $bgOdd = $C_RJCT_ODD; $bgEvn = $C_RJCT_EVN;
    } elseif ($isDelivered) {
        $bgOdd = $C_DELV_ODD; $bgEvn = $C_DELV_EVN;
    } else {
        $bgOdd = $C_APPR_ODD; $bgEvn = $C_APPR_EVN;
    }

    // ── Write every roll row first ────────────────────────────
    $altIdx = 0;
    foreach ($rolls as $roll) {
        $altIdx++;
        $bg = ($altIdx % 2 === 1) ? $bgOdd : $bgEvn;

        // Is this the inspected top roll? (all 3 checkboxes ticked)
        $isTopRoll = ((int)($roll['winding_condition'] ?? 0) === 1)
                  && ((int)($roll['hairy_rubber']      ?? 0) === 1)
                  && ((int)($roll['coil_width']        ?? 0) === 1);

        $lcr = trim($roll['lot_no']) . ' ' . trim($roll['coil_no'])
             . ' – ' . str_replace('R', 'R-', trim($roll['roll_no']));

        // ── E: Lot · Coil · Roll ─────────────────────────────
        $sheet->setCellValue("E{$rowNum}", $lcr);

        // ── F: Length ─────────────────────────────────────────
        $sheet->setCellValue("F{$rowNum}", round((float)$roll['qty_m'], 1));

        // ── H: Winding Condition ──────────────────────────────
        if ((int)$roll['winding_condition'] === 1) {
            $sheet->setCellValue("H{$rowNum}", '✔  Ticked');
            $sheet->getStyle("H{$rowNum}")->applyFromArray([
                'font'      => ['bold'=>true,'size'=>9,'name'=>'Arial',
                                'color'=>['rgb'=>$C_TICK_FG]],
                'fill'      => ['fillType'=>Fill::FILL_SOLID,
                                'startColor'=>['rgb'=> $isTopRoll ? $C_TOP_PASS_BG : $C_PASS_BG]],
                'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,
                                'vertical'  =>Alignment::VERTICAL_CENTER],
                'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,
                                               'color'      =>['rgb'=>$C_GRID]]],
            ]);
        } else {
            $applyBase("H{$rowNum}", $isTopRoll ? $C_TOP_BG : $bg);
        }

        // ── I: No Hairy Rubber ───────────────────────────────────
        if ((int)$roll['hairy_rubber'] === 1) {
            $sheet->setCellValue("I{$rowNum}", '✔  Ticked');
            $sheet->getStyle("I{$rowNum}")->applyFromArray([
                'font'      => ['bold'=>true,'size'=>9,'name'=>'Arial',
                                'color'=>['rgb'=>$C_TICK_FG]],
                'fill'      => ['fillType'=>Fill::FILL_SOLID,
                                'startColor'=>['rgb'=> $isTopRoll ? $C_TOP_PASS_BG : $C_PASS_BG]],
                'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,
                                'vertical'  =>Alignment::VERTICAL_CENTER],
                'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,
                                               'color'      =>['rgb'=>$C_GRID]]],
            ]);
        } else {
            $applyBase("I{$rowNum}", $isTopRoll ? $C_TOP_BG : $bg);
        }

        // ── E & F base style (use top-roll bg if applicable) ──
        $rowBg = $isTopRoll ? $C_TOP_BG : $bg;
        $applyBase("E{$rowNum}", $rowBg);
        $sheet->getStyle("F{$rowNum}")
              ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $applyBase("F{$rowNum}", $rowBg);

        // ── Full-row top-roll treatment ───────────────────────
        if ($isTopRoll) {
            // Amber left border on col E (first visible per-roll col)
            $sheet->getStyle("E{$rowNum}")->getBorders()
                  ->getLeft()
                  ->setBorderStyle(Border::BORDER_MEDIUM)
                  ->getColor()->setRGB($C_TOP_LEFT);

            // Bold amber text on the Lot·Coil·Roll identifier
            $sheet->getStyle("E{$rowNum}")->getFont()
                  ->setBold(true)
                  ->getColor()->setRGB($C_TOP_FG);

            // Slightly taller row so the top-roll stands out
            $sheet->getRowDimension($rowNum)->setRowHeight(17);
        } else {
            $sheet->getRowDimension($rowNum)->setRowHeight(15);
        }

        $rowNum++;
    }

    // ── Now write & merge the pallet-level columns ────────────
    // These span $firstRow to $lastRow vertically.

    $qcDate = $meta['qc_datetime']
        ? date('d M Y  H:i:s', strtotime($meta['qc_datetime']))
        : '—';

    // Status suffix on the date cell
    if ($isRejected) {
        $statusTag = '  ✘ REJECTED';
        $statusFg  = '991B1B';
    } elseif ($isDelivered) {
        $statusTag = '  📦 DELIVERED';
        $statusFg  = '5B21B6';
    } else {
        $statusTag = '  ✔ APPROVED';
        $statusFg  = '065F46';
    }

    // Background for merged columns — use the odd-row colour of this pallet
    $mergeBg = $bgOdd;

    // Merged columns: A, B, C, D, G, J
    $mergedCols = [
        'A' => $meta['pallet_no'],
        'B' => $qcDate . $statusTag,
        'C' => $meta['product']       ?? '—',
        'D' => $meta['customer_name'] ?? '—',
        'G' => (int)$meta['width'],
        'J' => $meta['checked_by']    ?? '—',
    ];

    foreach ($mergedCols as $col => $value) {
        if ($firstRow === $lastRow) {
            // Single-roll pallet — no merge needed, just set value
            $sheet->setCellValue("{$col}{$firstRow}", $value);
        } else {
            $sheet->mergeCells("{$col}{$firstRow}:{$col}{$lastRow}");
            $sheet->setCellValue("{$col}{$firstRow}", $value);
        }

        // Style for merged column
        $colStyle = [
            'font'      => ['size'=>9,'name'=>'Arial','color'=>['rgb'=>'1A2332']],
            'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$mergeBg]],
            'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,
                            'vertical'  =>Alignment::VERTICAL_CENTER,
                            'wrapText'  =>true],
            'borders'   => [
                // Thin grid inside, medium on outer pallet boundary
                'allBorders' => ['borderStyle'=>Border::BORDER_THIN,
                                 'color'      =>['rgb'=>$C_GRID]],
                'outline'    => ['borderStyle'=>Border::BORDER_MEDIUM,
                                 'color'      =>['rgb'=>'94A3B8']],
            ],
        ];

        // Special colour for col B (status embedded)
        if ($col === 'B') {
            $colStyle['font']['color'] = ['rgb' => $statusFg];
            $colStyle['font']['bold']  = true;
        }
        // Pallet No bold
        if ($col === 'A') {
            $colStyle['font']['bold']       = true;
            $colStyle['font']['color']      = ['rgb' => '0F2744'];
            $colStyle['font']['name']       = 'Courier New';
            $colStyle['font']['size']       = 9;
        }

        $sheet->getStyle("{$col}{$firstRow}")->applyFromArray($colStyle);
    }

    // Total rolls annotation — small note appended to Pallet No. cell
    // Shown as a second line using line-break via wrapText
    $palletNoVal = $meta['pallet_no'] . "\n(" . $rollCount . ' roll' . ($rollCount!==1?'s':'') . ')';
    $sheet->setCellValue("A{$firstRow}", $palletNoVal);
    $sheet->getStyle("A{$firstRow}")->getAlignment()->setWrapText(true);
    // Slightly taller rows for pallet groups with wrapping
    if ($rollCount === 1) {
        $sheet->getRowDimension($firstRow)->setRowHeight(24);
    }

    // Outer border around entire pallet block (all cols, all rows)
    $sheet->getStyle("A{$firstRow}:{$LAST_COL}{$lastRow}")->applyFromArray([
        'borders' => [
            'outline' => ['borderStyle'=>Border::BORDER_MEDIUM,
                          'color'      =>['rgb'=>'64748B']],
        ],
    ]);

} // end foreach pallet

// ── Grand total footer ────────────────────────────────────────
$rowNum++;
$sheet->mergeCells("A{$rowNum}:{$LAST_COL}{$rowNum}");
$sheet->setCellValue("A{$rowNum}",
    "Grand Total: {$totalRollsAll} roll(s) across {$totalPallets} pallet(s)");
$sheet->getStyle("A{$rowNum}")->applyFromArray([
    'font'      => ['bold'=>true,'size'=>9,'color'=>['rgb'=>$C_NAVY],'name'=>'Arial'],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'E2E8F0']],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,
                    'vertical'  =>Alignment::VERTICAL_CENTER],
    'borders'   => ['outline'=>['borderStyle'=>Border::BORDER_MEDIUM,
                                'color'      =>['rgb'=>'94A3B8']]],
]);
$sheet->getRowDimension($rowNum)->setRowHeight(18);

// ── Column widths ─────────────────────────────────────────────
$colWidths = [
    'A' => 18,  // Pallet No.
    'B' => 24,  // Date + status
    'C' => 13,  // Product
    'D' => 22,  // Customer
    'E' => 26,  // Lot Coil Roll
    'F' => 12,  // Length
    'G' => 11,  // Width
    'H' => 16,  // Winding
    'I' => 14,  // No Hairy Rubber
    'J' => 16,  // Checked By
];
foreach ($colWidths as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

// ── Page setup ────────────────────────────────────────────────
$sheet->getPageSetup()
    ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
    ->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
$sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);
$sheet->getHeaderFooter()
    ->setOddHeader('&C&B&"Arial,Bold"NICHIAS QC Inspection Summary')
    ->setOddFooter('&LGenerated: ' . date('d M Y H:i') . '&RPage &P of &N');

// ── Stream ────────────────────────────────────────────────────
$filename = 'QC_Inspection_Summary_' . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

(new Xlsx($spreadsheet))->save('php://output');
exit;
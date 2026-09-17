<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\QC;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class QCController extends Controller
{
    private QC $qcModel;

    public function __construct()
    {
        parent::__construct();
        $this->qcModel = new QC();
    }

    /**
     * QC Dashboard (formerly qc_dashboard.php)
     */
    public function dashboard(): void
    {
        $this->requireRole(['qc']);

        $inspectors = $this->qcModel->getActiveInspectors();
        $activeInspectorSession = $_SESSION['active_qc_inspector'] ?? '';
        $filter_date = trim($_GET['filter_date'] ?? $_GET['date'] ?? '');

        $pallets = $this->qcModel->getPendingQcPallets($filter_date !== '' ? $filter_date : null);

        $grandTotalRolls = array_sum(array_column($pallets, 'roll_count'));
        $grandTotalWgt   = array_sum(array_column($pallets, 'total_wgt'));

        $this->render('qc/dashboard', [
            'inspectors'             => $inspectors,
            'activeInspectorSession' => $activeInspectorSession,
            'filter_date'            => $filter_date,
            'pallets'                => $pallets,
            'grandTotalRolls'        => $grandTotalRolls,
            'grandTotalWgt'          => $grandTotalWgt,
        ]);
    }

    /**
     * QC Inspection Log (formerly qc_log.php)
     */
    public function log(): void
    {
        $this->requireRole(['qc']);

        $filter_status = $_GET['status']    ?? 'all';
        $search        = trim($_GET['search']    ?? '');
        $date_from     = $_GET['date_from'] ?? '';
        $date_to       = $_GET['date_to']   ?? '';

        $inspectors = $this->qcModel->getActiveInspectors();
        $activeInspectorSession = $_SESSION['active_qc_inspector'] ?? '';

        $logData = $this->qcModel->getQcLog($filter_status, $search, $date_from, $date_to);
        $kpis    = $this->qcModel->getKpis();

        $this->render('qc/log', [
            'filter_status'          => $filter_status,
            'search'                 => $search,
            'date_from'              => $date_from,
            'date_to'                => $date_to,
            'inspectors'             => $inspectors,
            'activeInspectorSession' => $activeInspectorSession,
            'palletRows'             => $logData['palletRows'],
            'rollsByPallet'          => $logData['rollsByPallet'],
            'total_approved'         => $kpis['total_approved'],
            'total_rejected'         => $kpis['total_rejected'],
            'total_delivered'        => $kpis['total_delivered'],
            'total_passed'           => $kpis['total_passed'],
            'total'                  => $kpis['total'],
            'pass_rate'              => $kpis['pass_rate'],
        ]);
    }

    /**
     * Manage Inspectors Page (formerly qc_manage_inspectors.php)
     */
    public function manageInspectors(): void
    {
        $this->requireRole(['qc']);

        $inspectors = $this->qcModel->getAllInspectors();
        $activeCount   = count(array_filter($inspectors, fn($r) => $r['is_active']));
        $inactiveCount = count($inspectors) - $activeCount;
        $activeInspectorSession = $_SESSION['active_qc_inspector'] ?? '';

        $this->render('qc/manage_inspectors', [
            'inspectors'             => $inspectors,
            'activeCount'            => $activeCount,
            'inactiveCount'          => $inactiveCount,
            'activeInspectorSession' => $activeInspectorSession,
        ]);
    }

    /**
     * Inspectors AJAX Handlers (formerly qc_inspectors_ajax.php)
     */
    public function inspectorsAjax(): void
    {
        $this->requireRole(['qc']);

        $action = trim($_POST['action'] ?? $_GET['action'] ?? '');

        if ($action === 'list') {
            $rows = $this->qcModel->getActiveInspectors();
            $this->json(['ok' => true, 'inspectors' => $rows]);
            return;
        }

        if ($action === 'set_active_inspector') {
            $name = trim($_POST['name'] ?? '');
            $_SESSION['active_qc_inspector'] = $name;
            $this->json(['ok' => true, 'active_qc_inspector' => $name]);
            return;
        }

        if ($action === 'add') {
            $name = trim($_POST['name'] ?? '');
            $res = $this->qcModel->addInspector($name);
            $this->json($res);
            return;
        }

        if ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            $res = $this->qcModel->deleteInspector($id);
            $this->json($res);
            return;
        }

        if ($action === 'edit') {
            $id   = intval($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $res = $this->qcModel->editInspector($id, $name);
            $this->json($res);
            return;
        }

        $this->json(['ok' => false, 'msg' => 'Unknown action.']);
    }

    /**
     * Barcode scan lookup endpoint (formerly qc_scan_lookup.php)
     */
    public function scanLookup(): void
    {
        $this->requireRole(['qc']);

        $rawInput = trim($_POST['qr'] ?? $_POST['scan_input'] ?? $_GET['qr'] ?? '');
        $res = $this->qcModel->scanLookup($rawInput);
        $this->json($res);
    }

    /**
     * Legacy single roll approval / rejection (formerly qc_process.php)
     */
    public function processRoll(): void
    {
        $this->requireRole(['qc']);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id     = intval($_POST['id'] ?? 0);
            $action = $_POST['action'] ?? '';

            if ($action === 'approve') {
                $this->qcModel->processRoll($id, 'approve');
                $this->redirect("qc_dashboard.php?approved=1");
                return;
            } elseif ($action === 'reject') {
                $comment = trim($_POST['comment'] ?? '');
                if (empty($comment)) {
                    $this->redirect("qc_dashboard.php?error=comment_required");
                    return;
                }
                $this->qcModel->processRoll($id, 'reject', $comment);
                $this->redirect("qc_dashboard.php?rejected=1");
                return;
            }
        }

        $this->redirect("qc_dashboard.php");
    }

    /**
     * QC Log Excel Export (formerly qc_export.php)
     */
    public function export(): void
    {
        $this->requireRole(['qc']);

        $filter_status = $_GET['status']    ?? 'all';
        $search        = trim($_GET['search']    ?? '');
        $date_from     = $_GET['date_from'] ?? '';
        $date_to       = $_GET['date_to']   ?? '';

        $rows = $this->qcModel->getQcLogExportData($filter_status, $search, $date_from, $date_to);

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

        $C_TOP_BG   = 'FEF9C3';
        $C_TOP_LEFT = 'CA8A04';
        $C_TOP_FG   = '713F12';
        $C_TOP_PASS_BG = 'A7F3D0';

        $LAST_COL   = 'J';

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('QC Inspection Summary');

        // ROW 1: Title
        $sheet->mergeCells("A1:{$LAST_COL}1");
        $sheet->setCellValue('A1', 'NICHIAS MK SLITTING SYSTEM — QC Inspection Summary');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold'=>true,'size'=>13,'color'=>['rgb'=>$C_WHITE],'name'=>'Arial'],
            'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_NAVY]],
            'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER, 'vertical'=>Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        // ROW 2: Meta
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
            'alignment' => ['horizontal'=>Alignment::HORIZONTAL_LEFT, 'vertical'=>Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(15);

        // ROW 3: spacer
        $sheet->getRowDimension(3)->setRowHeight(5);

        // ROW 4: Headers
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
            'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER, 'vertical'=>Alignment::VERTICAL_CENTER, 'wrapText'=>true],
            'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN, 'color'=>['rgb'=>$C_HDR_BDR]]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(24);
        $sheet->setAutoFilter("A4:{$LAST_COL}4");
        $sheet->freezePane('A5');

        $rowNum         = 5;
        $totalRollsAll  = 0;
        $totalPallets   = count($pallets);

        $applyBase = function(string $range, string $bg) use ($sheet, $C_GRID) {
            $sheet->getStyle($range)->applyFromArray([
                'font'      => ['size'=>9,'name'=>'Arial','color'=>['rgb'=>'1A2332']],
                'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$bg]],
                'alignment' => ['vertical'=>Alignment::VERTICAL_CENTER, 'wrapText'=>false],
                'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN, 'color'=>['rgb'=>$C_GRID]]],
            ]);
        };

        foreach ($pallets as $pid => $pallet) {
            $meta        = $pallet['meta'];
            $rolls       = $pallet['rolls'];
            $rollCount   = count($rolls);
            $statusLower = strtolower($meta['pallet_status']);
            $isRejected  = $statusLower === 'rejected';
            $isDelivered = $statusLower === 'delivered';
            $isApproved  = !$isRejected;
            $totalRollsAll += $rollCount;

            $firstRow = $rowNum;
            $lastRow  = $rowNum + $rollCount - 1;

            if ($isRejected) {
                $bgOdd = $C_RJCT_ODD; $bgEvn = $C_RJCT_EVN;
            } elseif ($isDelivered) {
                $bgOdd = $C_DELV_ODD; $bgEvn = $C_DELV_EVN;
            } else {
                $bgOdd = $C_APPR_ODD; $bgEvn = $C_APPR_EVN;
            }

            foreach ($rolls as $idx => $r) {
                $currRow   = $firstRow + $idx;
                $rowBg     = ($idx % 2 === 0) ? $bgOdd : $bgEvn;
                $sheet->getRowDimension($currRow)->setRowHeight(18);

                $lotCoilRoll = trim("{$r['lot_no']}   {$r['coil_no']}   {$r['roll_no']}");
                $sheet->setCellValue("E{$currRow}", $lotCoilRoll);
                $sheet->getStyle("E{$currRow}")->getNumberFormat()->setFormatCode('@');
                $sheet->getStyle("E{$currRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

                $qtyM = $r['qty_m'] !== null ? (float)$r['qty_m'] : null;
                if ($qtyM !== null) {
                    $sheet->setCellValue("F{$currRow}", $qtyM);
                    $sheet->getStyle("F{$currRow}")->getNumberFormat()->setFormatCode('#,##0');
                } else {
                    $sheet->setCellValue("F{$currRow}", '—');
                }
                $sheet->getStyle("F{$currRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                $isTopRoll = ((int)$r['winding_condition'] === 1 && (int)$r['hairy_rubber'] === 1);
                $effectiveBg = $isTopRoll ? $C_TOP_BG : $rowBg;
                $applyBase("E{$currRow}:J{$currRow}", $effectiveBg);

                if ($isTopRoll) {
                    $sheet->getStyle("E{$currRow}")->getBorders()->getLeft()->applyFromArray([
                        'borderStyle' => Border::BORDER_MEDIUM,
                        'color'       => ['rgb' => $C_TOP_LEFT],
                    ]);
                    $sheet->getStyle("E{$currRow}:F{$currRow}")->getFont()->getColor()->setRGB($C_TOP_FG);
                }

                $wCheck = (int)$r['winding_condition'] === 1;
                $sheet->setCellValue("H{$currRow}", $wCheck ? '✔' : '');
                $sheet->getStyle("H{$currRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                if ($wCheck) {
                    $sheet->getStyle("H{$currRow}")->applyFromArray([
                        'font' => ['bold'=>true,'size'=>11,'color'=>['rgb'=>$C_TICK_FG],'name'=>'Arial'],
                        'fill' => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$isTopRoll ? $C_TOP_PASS_BG : $C_PASS_BG]],
                    ]);
                }

                $hCheck = (int)$r['hairy_rubber'] === 1;
                $sheet->setCellValue("I{$currRow}", $hCheck ? '✔' : '');
                $sheet->getStyle("I{$currRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                if ($hCheck) {
                    $sheet->getStyle("I{$currRow}")->applyFromArray([
                        'font' => ['bold'=>true,'size'=>11,'color'=>['rgb'=>$C_TICK_FG],'name'=>'Arial'],
                        'fill' => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$isTopRoll ? $C_TOP_PASS_BG : $C_PASS_BG]],
                    ]);
                }
            }

            // Merged columns per pallet
            $palletBg = $bgOdd;
            $mergedCols = ['A', 'B', 'C', 'D', 'G', 'J'];

            foreach ($mergedCols as $col) {
                $applyBase("{$col}{$firstRow}:{$col}{$lastRow}", $palletBg);
                if ($rollCount > 1) {
                    $sheet->mergeCells("{$col}{$firstRow}:{$col}{$lastRow}");
                }
            }

            $sheet->setCellValue("A{$firstRow}", $meta['pallet_no']);
            $sheet->getStyle("A{$firstRow}")->getNumberFormat()->setFormatCode('@');
            $sheet->getStyle("A{$firstRow}")->applyFromArray([
                'font'      => ['bold'=>true,'size'=>9,'color'=>['rgb'=>'1A2332'],'name'=>'Arial'],
                'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER, 'vertical'=>Alignment::VERTICAL_CENTER],
            ]);

            $dtStr = $meta['qc_datetime'] ? date('d M Y  H:i', strtotime($meta['qc_datetime'])) : '—';
            $sheet->setCellValue("B{$firstRow}", $dtStr);
            $sheet->getStyle("B{$firstRow}")->applyFromArray([
                'font'      => ['size'=>8.5,'color'=>['rgb'=>'475569'],'name'=>'Arial'],
                'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER, 'vertical'=>Alignment::VERTICAL_CENTER],
            ]);

            $sheet->setCellValue("C{$firstRow}", $meta['product'] ?? '—');
            $sheet->getStyle("C{$firstRow}")->applyFromArray([
                'font'      => ['bold'=>true,'size'=>9,'color'=>['rgb'=>'1A2332'],'name'=>'Arial'],
                'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER, 'vertical'=>Alignment::VERTICAL_CENTER],
            ]);

            $sheet->setCellValue("D{$firstRow}", $meta['customer_name'] ?: '—');
            $sheet->getStyle("D{$firstRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            $widthVal = $meta['width'] !== null ? (float)$meta['width'] : null;
            if ($widthVal !== null) {
                $sheet->setCellValue("G{$firstRow}", $widthVal);
                $sheet->getStyle("G{$firstRow}")->getNumberFormat()->setFormatCode('#,##0.0');
            } else {
                $sheet->setCellValue("G{$firstRow}", '—');
            }
            $sheet->getStyle("G{$firstRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            $sheet->setCellValue("J{$firstRow}", $meta['checked_by'] ?: '—');
            $sheet->getStyle("J{$firstRow}")->applyFromArray([
                'font'      => ['bold'=>true,'size'=>9,'color'=>['rgb'=>'1A2332'],'name'=>'Arial'],
                'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER, 'vertical'=>Alignment::VERTICAL_CENTER],
            ]);

            // Thick bottom border for each pallet block
            $sheet->getStyle("A{$lastRow}:{$LAST_COL}{$lastRow}")->getBorders()->getBottom()->applyFromArray([
                'borderStyle' => Border::BORDER_MEDIUM,
                'color'       => ['rgb' => $C_DARK],
            ]);

            $rowNum = $lastRow + 1;
        }

        // Auto-fit column widths
        foreach (range('A', $LAST_COL) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // File download headers
        $filename = 'QC_Inspection_Summary_' . date('Ymd_His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}

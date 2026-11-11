<?php
/**
 * CustomerTrackOrderController.php
 * Controller for Customer Order Tracking Portal
 * Location: /customer_track_order/controllers/CustomerTrackOrderController.php
 */

require_once __DIR__ . '/../models/CustomerTrackOrderModel.php';

class CustomerTrackOrderController
{
    private mysqli $conn;
    private CustomerTrackOrderModel $model;

    public function __construct(?mysqli $conn = null)
    {
        if ($conn === null) {
            $configPath = file_exists(__DIR__ . '/../../config.php') 
                ? __DIR__ . '/../../config.php' 
                : __DIR__ . '/../config.php';
            require $configPath;
        }

        $this->conn = $conn;
        $this->model = new CustomerTrackOrderModel($this->conn);
    }

    public function getModel(): CustomerTrackOrderModel
    {
        return $this->model;
    }

    /**
     * Render the Customer Order Tracking View
     */
    public function index(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $model = $this->model;

        // ── Check if Excel export is requested ──────────────────────────────
        $isExport = (isset($_GET['export']) && $_GET['export'] === 'excel') 
                 || (isset($_GET['action']) && $_GET['action'] === 'export_excel');

        if ($isExport) {
            $exportRef = $model->cleanInput($_GET['so'] ?? ($_GET['ref_no'] ?? ($_GET['order'] ?? '')));
            if ($exportRef !== '') {
                $this->exportOrderExcel($exportRef);
                return;
            }
        }

        // ── Input Sanitization ───────────────────────────────────────────────
        $searchSo       = $model->cleanInput($_GET['so'] ?? ($_GET['order'] ?? ''));
        $searchQuery    = $model->cleanInput($_GET['search'] ?? '');
        $filterCustomer = $model->cleanInput($_GET['customer'] ?? '');
        $filterStatus   = $model->cleanInput($_GET['status'] ?? '');

        // Determine assets path prefix and page URL
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if (basename($scriptDir) === 'views') {
            $pathPrefix = '../../';
            $pageUrl = '../index.php';
        } elseif (basename($scriptDir) === 'customer_track_order') {
            $pathPrefix = '../';
            $pageUrl = htmlspecialchars(basename($_SERVER['PHP_SELF'] ?? 'index.php'));
        } else {
            $pathPrefix = '';
            $pageUrl = htmlspecialchars(basename($_SERVER['PHP_SELF'] ?? 'index.php'));
        }

        // ── Customer List for Dropdown and Filter Pills ──────────────────────
        $customerList = $model->getCustomerList();

        // If searchSo is provided, or user typed an SO / Ref number in generic search
        $singleOrder = null;
        $orderRolls  = [];

        if ($searchSo !== '') {
            $orderData = $model->getSingleOrder($searchSo);
            if ($orderData !== null) {
                $singleOrder = $orderData['summary'];
                $orderRolls  = $orderData['rolls'];
            }
        } elseif ($searchQuery !== '') {
            // Check if generic search query directly matches a specific Sales Order / Ref No
            $orderData = $model->getSingleOrder($searchQuery);
            if ($orderData !== null) {
                $singleOrder = $orderData['summary'];
                $orderRolls  = $orderData['rolls'];
                $searchSo    = $singleOrder['ref_no'];
            }
        }

        // ── Multi-Order List Mode (Only needed if not in single order view) ───
        $orderList            = [];
        $totalOrdersCount     = 0;
        $deliveredOrdersCount = 0;
        $inProdOrdersCount    = 0;
        $readyOrdersCount     = 0;

        if ($singleOrder === null) {
            $listResult = $model->getOrderList($filterCustomer, $filterStatus, $searchQuery);
            $orderList            = $listResult['orders'];
            $stats                = $listResult['stats'];
            $totalOrdersCount     = $stats['total_orders'];
            $deliveredOrdersCount = $stats['delivered_orders'];
            $inProdOrdersCount    = $stats['in_prod_orders'];
            $readyOrdersCount     = $stats['ready_orders'];
        }

        $page_title = "Customer Order Tracking - MK Slitting";

        require __DIR__ . '/../views/customer_track_order_view.php';
    }

    /**
     * Handle AJAX endpoint requests
     */
    public function handleAjax(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        header('Content-Type: application/json; charset=utf-8');

        $action = $_REQUEST['action'] ?? 'search';
        $model = $this->model;

        if ($action === 'track_so') {
            $so = $model->cleanInput($_REQUEST['so'] ?? '');
            $orderData = $model->getSingleOrder($so);

            if ($orderData !== null) {
                echo json_encode([
                    'success' => true,
                    'order'   => $orderData['summary'],
                    'rolls'   => $orderData['rolls']
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Sales Order not found'
                ]);
            }
            exit;
        }

        if ($action === 'search_orders') {
            $customer = $model->cleanInput($_REQUEST['customer'] ?? '');
            $status   = $model->cleanInput($_REQUEST['status'] ?? '');
            $search   = $model->cleanInput($_REQUEST['search'] ?? '');
            $listResult = $model->getOrderList($customer, $status, $search);

            echo json_encode([
                'success' => true,
                'data'    => $listResult
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }

    /**
     * Export a Sales Order and its itemized roll breakdown to Excel (.xlsx)
     */
    public function exportOrderExcel(string $refNo, ?string $saveToFile = null): void
    {
        $refNo = trim($refNo);
        if ($refNo === '') {
            header('Location: ' . htmlspecialchars($_SERVER['PHP_SELF'] ?? 'index.php'));
            exit;
        }

        $orderData = $this->model->getSingleOrder($refNo);
        if ($orderData === null) {
            die("Sales Order not found: " . htmlspecialchars($refNo));
        }

        $summary = $orderData['summary'];
        $rolls   = $orderData['rolls'];

        // Clean filename for safety across OS and browsers
        $cleanRef  = preg_replace('/[^A-Za-z0-9_-]/', '_', $summary['ref_no'] ?: $refNo);
        $filename  = 'Order_Track_' . $cleanRef . '_' . date('Ymd_His');

        // Locate vendor autoload
        $autoloadFound = false;
        foreach ([__DIR__ . '/../../vendor/autoload.php', dirname(__DIR__, 2) . '/vendor/autoload.php', __DIR__ . '/../vendor/autoload.php'] as $al) {
            if (file_exists($al)) {
                require_once $al;
                $autoloadFound = true;
                break;
            }
        }

        if ($autoloadFound && class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $this->exportOrderXlsx($summary, $rolls, $filename . '.xlsx', $saveToFile);
        } else {
            $this->exportOrderCsv($summary, $rolls, $filename . '.csv', $saveToFile);
        }
        if ($saveToFile === null) {
            exit;
        }
    }

    /**
     * Build and stream styled Excel spreadsheet using PhpSpreadsheet
     */
    private function exportOrderXlsx(array $summary, array $rolls, string $filename, ?string $saveToFile = null): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $safeTitle = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $summary['ref_no'] ?? 'Order'), 0, 25);
        $sheet->setTitle($safeTitle ?: 'Order Track');

        $totalCols = 'M'; // 13 columns A to M

        // 1. Header Banner
        $sheet->mergeCells("A1:{$totalCols}1");
        $sheet->setCellValue('A1', 'MK SLITTING - ORDER PRODUCTION & DELIVERY TRACKING');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 15, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0F2744']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(32);

        // 2. Sub-Banner
        $sheet->mergeCells("A2:{$totalCols}2");
        $subTitle = 'Sales Order: ' . ($summary['ref_no'] ?? '—') 
                  . '   |   Customer: ' . ($summary['customer_name'] ?? '—') 
                  . '   |   Generated: ' . date('d M Y, h:i A');
        $sheet->setCellValue('A2', $subTitle);
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['size' => 10, 'bold' => true, 'color' => ['argb' => 'FFBEE3F8']],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E3A8A']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(20);

        // 3. Order Information Block
        $infoBox = [
            ['A4', 'B4', 'Sales Order / Ref No:', $summary['ref_no'] ?? '—', 'E4', 'F4', 'Customer Name:', $summary['customer_name'] ?? '—', 'I4', 'J4', 'Order Started:', (!empty($summary['earliest_date']) ? date('d M Y', strtotime($summary['earliest_date'])) : '—')],
            ['A5', 'B5', 'Product Specification:', $summary['products'] ?: '—', 'E5', 'F5', 'Overall Status:', $summary['status_text'] ?? ($summary['overall_status'] ?? '—'), 'I5', 'J5', 'Dispatched Date:', (!empty($summary['latest_delivered']) ? date('d M Y', strtotime($summary['latest_delivered'])) : '—')],
            ['A6', 'B6', 'Planned Coils:', (int)($summary['planned_rolls'] ?? 0), 'E6', 'F6', 'Produced Coils:', (int)($summary['produced_rolls_cnt'] ?? 0), 'I6', 'J6', 'Delivered Coils:', (int)($summary['delivered_rolls'] ?? 0)],
            ['A7', 'B7', 'Pending Cut:', (int)($summary['planning_rolls_cnt'] ?? 0), 'E7', 'F7', 'Total Meters Produced:', number_format((float)($summary['total_meters'] ?? 0), 1) . ' m', 'I7', 'J7', 'SFC Coils:', (int)($summary['sfc_cnt'] ?? 0)],
        ];

        foreach ($infoBox as $row) {
            // Label 1
            $sheet->setCellValue($row[0], $row[2]);
            $sheet->getStyle($row[0])->getFont()->setBold(true)->getColor()->setARGB('FF475569');
            $sheet->mergeCells($row[1] . ':D' . substr($row[1], 1));
            $sheet->setCellValue($row[1], $row[3]);
            $sheet->getStyle($row[1])->getFont()->setBold(true);

            // Label 2
            $sheet->setCellValue($row[4], $row[6]);
            $sheet->getStyle($row[4])->getFont()->setBold(true)->getColor()->setARGB('FF475569');
            $sheet->mergeCells($row[5] . ':H' . substr($row[5], 1));
            $sheet->setCellValue($row[5], $row[7]);
            $sheet->getStyle($row[5])->getFont()->setBold(true);

            // Label 3
            $sheet->setCellValue($row[8], $row[10]);
            $sheet->getStyle($row[8])->getFont()->setBold(true)->getColor()->setARGB('FF475569');
            $sheet->mergeCells($row[9] . ":{$totalCols}" . substr($row[9], 1));
            $sheet->setCellValue($row[9], $row[11]);
            $sheet->getStyle($row[9])->getFont()->setBold(true);
        }

        // Info block border styling
        $sheet->getStyle("A4:{$totalCols}7")->applyFromArray([
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF8FAFC']],
            'borders' => ['outline' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFCBD5E1']]],
        ]);

        // 4. Spacer row
        $sheet->getRowDimension(8)->setRowHeight(12);

        // 5. Table Headers (Row 9)
        $headers = [
            'A' => '#',
            'B' => 'Roll No',
            'C' => 'Lot No',
            'D' => 'Coil No',
            'E' => 'Source / Origin',
            'F' => 'Product Spec',
            'G' => 'Width (mm)',
            'H' => 'Length (m)',
            'I' => 'NOD (m)',
            'J' => 'Invoice Length (m)',
            'K' => 'Stage / Status',
            'L' => 'Pallet No',
            'M' => 'Delivery Date',
        ];

        foreach ($headers as $col => $title) {
            $sheet->setCellValue($col . '9', $title);
        }

        $sheet->getStyle("A9:{$totalCols}9")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF15803D']], // Clean emerald forest green
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FF86EFAC']]],
        ]);
        $sheet->getRowDimension(9)->setRowHeight(25);

        // 6. Data Rows
        $currentRow = 10;
        $totalWidth = 0.0;
        $totalLength = 0.0;
        $totalNod = 0.0;
        $totalInvLen = 0.0;

        foreach ($rolls as $idx => $r) {
            $isPlanOnly = !empty($r['is_planned_only']);
            $st = strtoupper($r['status'] ?? 'IN');
            $sc = (int)($r['stock_counted'] ?? 0);
            $isSentToSfc = (!empty($r['is_sent_to_sfc']) || $st === 'SFC');
            $isSfc  = (!empty($r['is_sfc']) || $isSentToSfc || strtolower(trim($r['original_source'] ?? '')) === 'sfc' || strtolower(trim($r['source'] ?? '')) === 'sfc' || strtoupper(trim($r['roll_no'] ?? '')) === 'SFC');
            $isInit = (!$isSfc && (!empty($r['is_initial_stock']) || empty($r['mother_id']) || ($r['original_source'] ?? '') === 'initial_stock'));
            $isRec  = (!empty($r['is_recoil']) || ($r['source'] ?? '') === 'recoiling' || !empty($r['recoiling_id']) || ($r['cut_type'] ?? '') === 'cut_into_2');
            $isRes  = (!empty($r['is_reslit']) || ($r['source'] ?? '') === 'reslit' || !empty($r['is_reslitted']));

            // Stage / Status label
            if ($isPlanOnly) {
                $statusLabel = 'Planned (Pending Cut)';
            } elseif ($isSentToSfc) {
                $statusLabel = 'Sent to SFC';
            } elseif ($st === 'DELIVERED') {
                $statusLabel = 'Delivered';
            } elseif ($st === 'APPROVED') {
                $statusLabel = 'Approved';
            } elseif ($st === 'WAITING') {
                $statusLabel = 'Waiting QC';
            } elseif ($sc === 1) {
                $statusLabel = 'Finish Good';
            } else {
                $statusLabel = 'In Slitting';
            }

            // Origin / Source
            if ($isPlanOnly) {
                $originLabel = 'Planned Coil';
            } elseif ($isSentToSfc) {
                $originLabel = 'SFC (Cut & Sent)';
            } elseif ($isSfc) {
                $originLabel = 'SFC';
            } elseif ($isInit) {
                $originLabel = 'Initial Stock';
            } elseif ($isRec) {
                $originLabel = 'Recoil';
            } elseif ($isRes) {
                $originLabel = 'Reslit';
            } else {
                $originLabel = 'Raw Material';
            }

            // Pallet label
            if ($isPlanOnly) {
                $palletLabel = 'Not Slit Yet';
            } elseif ($isSentToSfc) {
                $palletLabel = 'SFC Inventory';
            } elseif (!empty($r['pallet_no'])) {
                $palletLabel = $r['pallet_no'];
            } else {
                $palletLabel = 'Not Palletized';
            }

            // Delivery Date
            if ($isPlanOnly) {
                $deliveryDate = '—';
            } elseif (!empty($r['delivered_at'])) {
                $deliveryDate = date('d M Y', strtotime($r['delivered_at']));
            } elseif ($st === 'DELIVERED') {
                $deliveryDate = 'Delivered';
            } else {
                $deliveryDate = '—';
            }

            $w = (float)($r['width'] ?? 0);
            $l = (float)($r['actual_length'] ?: ($r['length'] ?? 0));
            $nod = (float)($r['nod_length'] ?? 0);
            $invLen = max(0.0, $l - $nod);

            if (!$isPlanOnly) {
                $totalWidth += $w;
                $totalLength += $l;
                $totalNod += $nod;
                $totalInvLen += $invLen;
            }

            $sheet->setCellValue("A{$currentRow}", $idx + 1);
            $sheet->setCellValue("B{$currentRow}", $this->model->formatRollNo($r['roll_no'] ?? ''));
            $sheet->setCellValue("C{$currentRow}", $r['lot_no'] ?? '—');
            $sheet->setCellValue("D{$currentRow}", $r['coil_no'] ?? '—');
            $sheet->setCellValue("E{$currentRow}", $originLabel);
            $sheet->setCellValue("F{$currentRow}", $r['product'] ?? '—');
            $sheet->setCellValue("G{$currentRow}", $w);
            $sheet->setCellValue("H{$currentRow}", $l);
            $sheet->setCellValue("I{$currentRow}", $nod > 0 ? $nod : 0.0);
            $sheet->setCellValue("J{$currentRow}", $invLen);
            $sheet->setCellValue("K{$currentRow}", $statusLabel);
            $sheet->setCellValue("L{$currentRow}", $palletLabel);
            $sheet->setCellValue("M{$currentRow}", $deliveryDate);

            // Alignments & Number formats
            $sheet->getStyle("A{$currentRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("B{$currentRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("G{$currentRow}")->getNumberFormat()->setFormatCode('#,##0.0');
            $sheet->getStyle("H{$currentRow}")->getNumberFormat()->setFormatCode('#,##0.0');
            $sheet->getStyle("I{$currentRow}")->getNumberFormat()->setFormatCode('#,##0.0');
            $sheet->getStyle("J{$currentRow}")->getNumberFormat()->setFormatCode('#,##0.0');
            $sheet->getStyle("K{$currentRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("L{$currentRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("M{$currentRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            // Highlight plan-only or SFC rows
            if ($isPlanOnly) {
                $sheet->getStyle("A{$currentRow}:M{$currentRow}")->applyFromArray([
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF5F3FF']], // Soft purple tint
                    'font' => ['color' => ['argb' => 'FF6B21A8']]
                ]);
            } elseif ($isSentToSfc) {
                $sheet->getStyle("A{$currentRow}:M{$currentRow}")->applyFromArray([
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF0FDFA']], // Soft teal tint
                ]);
            }

            if ($nod > 0) {
                $sheet->getStyle("I{$currentRow}")->applyFromArray([
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFEF3C7']], // Yellow warning
                    'font' => ['bold' => true, 'color' => ['argb' => 'FF92400E']]
                ]);
            }

            $currentRow++;
        }

        // 7. Totals Row
        $sheet->setCellValue("A{$currentRow}", 'TOTAL (PRODUCED)');
        $sheet->mergeCells("A{$currentRow}:F{$currentRow}");
        $sheet->setCellValue("G{$currentRow}", $totalWidth);
        $sheet->setCellValue("H{$currentRow}", $totalLength);
        $sheet->setCellValue("I{$currentRow}", $totalNod);
        $sheet->setCellValue("J{$currentRow}", $totalInvLen);

        $sheet->getStyle("A{$currentRow}:M{$currentRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE2E8F0']],
            'borders' => [
                'top' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN],
                'bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_DOUBLE]
            ]
        ]);
        $sheet->getStyle("G{$currentRow}")->getNumberFormat()->setFormatCode('#,##0.0');
        $sheet->getStyle("H{$currentRow}")->getNumberFormat()->setFormatCode('#,##0.0');
        $sheet->getStyle("I{$currentRow}")->getNumberFormat()->setFormatCode('#,##0.0');
        $sheet->getStyle("J{$currentRow}")->getNumberFormat()->setFormatCode('#,##0.0');

        // Apply grid borders to the data table
        $sheet->getStyle("A9:M{$currentRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFE2E8F0']]]
        ]);

        // Auto-fit column widths
        foreach (range('A', 'M') as $colLetter) {
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        // Save to file or stream to browser
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        if ($saveToFile !== null) {
            $writer->save($saveToFile);
            return;
        }

        // Clean output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Stream output to browser
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        $writer->save('php://output');
        exit;
    }

    /**
     * Fallback CSV exporter
     */
    private function exportOrderCsv(array $summary, array $rolls, string $filename, ?string $saveToFile = null): void
    {
        if ($saveToFile !== null) {
            $out = fopen($saveToFile, 'w');
        } else {
            while (ob_get_level()) {
                ob_end_clean();
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            $out = fopen('php://output', 'w');
        }
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

        // Header metadata
        fputcsv($out, ['MK SLITTING - ORDER PRODUCTION & DELIVERY TRACKING']);
        fputcsv($out, ['Sales Order / Ref No', $summary['ref_no'] ?? '', 'Customer', $summary['customer_name'] ?? '']);
        fputcsv($out, ['Product Spec', $summary['products'] ?? '', 'Status', $summary['status_text'] ?? ($summary['overall_status'] ?? '')]);
        fputcsv($out, ['Planned Rolls', (int)($summary['planned_rolls'] ?? 0), 'Produced Rolls', (int)($summary['produced_rolls_cnt'] ?? 0), 'Delivered Rolls', (int)($summary['delivered_rolls'] ?? 0)]);
        fputcsv($out, ['Generated', date('d M Y, h:i A')]);
        fputcsv($out, []); // Blank line

        // Table headers
        fputcsv($out, ['#', 'Roll No', 'Lot No', 'Coil No', 'Source / Origin', 'Product Spec', 'Width (mm)', 'Length (m)', 'NOD (m)', 'Invoice Length (m)', 'Stage / Status', 'Pallet No', 'Delivery Date']);

        foreach ($rolls as $idx => $r) {
            $isPlanOnly = !empty($r['is_planned_only']);
            $st = strtoupper($r['status'] ?? 'IN');
            $sc = (int)($r['stock_counted'] ?? 0);
            $isSentToSfc = (!empty($r['is_sent_to_sfc']) || $st === 'SFC');
            $isSfc  = (!empty($r['is_sfc']) || $isSentToSfc || strtolower(trim($r['original_source'] ?? '')) === 'sfc' || strtolower(trim($r['source'] ?? '')) === 'sfc' || strtoupper(trim($r['roll_no'] ?? '')) === 'SFC');
            $isInit = (!$isSfc && (!empty($r['is_initial_stock']) || empty($r['mother_id']) || ($r['original_source'] ?? '') === 'initial_stock'));
            $isRec  = (!empty($r['is_recoil']) || ($r['source'] ?? '') === 'recoiling' || !empty($r['recoiling_id']) || ($r['cut_type'] ?? '') === 'cut_into_2');
            $isRes  = (!empty($r['is_reslit']) || ($r['source'] ?? '') === 'reslit' || !empty($r['is_reslitted']));

            $statusLabel = $isPlanOnly ? 'Planned (Pending Cut)' : ($isSentToSfc ? 'Sent to SFC' : ($st === 'DELIVERED' ? 'Delivered' : ($st === 'APPROVED' ? 'Approved' : ($st === 'WAITING' ? 'Waiting QC' : ($sc === 1 ? 'Finish Good' : 'In Slitting')))));
            $originLabel = $isPlanOnly ? 'Planned Coil' : ($isSentToSfc ? 'SFC (Cut & Sent)' : ($isSfc ? 'SFC' : ($isInit ? 'Initial Stock' : ($isRec ? 'Recoil' : ($isRes ? 'Reslit' : 'Raw Material')))));
            $palletLabel = $isPlanOnly ? 'Not Slit Yet' : ($isSentToSfc ? 'SFC Inventory' : (!empty($r['pallet_no']) ? $r['pallet_no'] : 'Not Palletized'));
            $delDate = $isPlanOnly ? '—' : (!empty($r['delivered_at']) ? date('d M Y', strtotime($r['delivered_at'])) : ($st === 'DELIVERED' ? 'Delivered' : '—'));

            $w = (float)($r['width'] ?? 0);
            $l = (float)($r['actual_length'] ?: ($r['length'] ?? 0));
            $nod = (float)($r['nod_length'] ?? 0);
            $invLen = max(0.0, $l - $nod);

            fputcsv($out, [
                $idx + 1,
                $this->model->formatRollNo($r['roll_no'] ?? ''),
                $r['lot_no'] ?? '—',
                $r['coil_no'] ?? '—',
                $originLabel,
                $r['product'] ?? '—',
                number_format($w, 1, '.', ''),
                number_format($l, 1, '.', ''),
                number_format($nod, 1, '.', ''),
                number_format($invLen, 1, '.', ''),
                $statusLabel,
                $palletLabel,
                $delDate
            ]);
        }

        fclose($out);
        if ($saveToFile === null) {
            exit;
        }
    }
}

<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\ReslitProduct;
use Throwable;

class ReslitController extends Controller
{
    private ReslitProduct $reslitModel;

    public function __construct()
    {
        parent::__construct();
        $this->reslitModel = new ReslitProduct();
    }

    /**
     * Main Reslit view & Excel export handler
     */
    public function index(): void
    {
        $this->requireRole(['slitting']);

        if (isset($_GET['download']) && $_GET['download'] === 'excel') {
            $this->exportExcel();
            return;
        }

        $tableRows = $this->reslitModel->getMainList();
        $children  = $this->reslitModel->getChildRolls();
        $kpi       = $this->reslitModel->getKpiCounts();

        $this->render('reslit/index', [
            'tableRows'  => $tableRows,
            'children'   => $children,
            'pending'    => $kpi['pending'],
            'completed'  => $kpi['completed'],
            'page_title' => "Reslit Product Management",
        ]);
    }

    /**
     * Excel Export
     */
    private function exportExcel(): void
    {
        $rows = $this->reslitModel->getExportData();
        $filename  = 'Reslit_Report_' . date('Y-m-d') . '.xls';
        $cols      = 12;
        $generated = date('d M Y, H:i');

        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $children = $this->reslitModel->getChildRolls();

        echo '<html><head><meta charset="UTF-8"></head><body>';
        echo '<table>';
        echo '<tr><td colspan="' . $cols . '" style="background:#1e3a5f;color:#fff;font-size:18px;font-weight:bold;padding:12px 16px;letter-spacing:1px;">RESLIT PRODUCT REPORT</td></tr>';
        echo '<tr><td colspan="' . $cols . '" style="background:#2c5282;color:#bee3f8;font-size:11px;padding:4px 16px;">Generated: ' . $generated . ' &nbsp;|&nbsp; System: Slitting Management</td></tr>';
        echo '<tr><td colspan="' . $cols . '" style="background:#e2e8f0;padding:6px 16px;font-size:11px;color:#4a5568;"><strong>Note:</strong> Child rolls (↳) are shown indented under their parent reslit record.</td></tr>';
        echo '<tr><td colspan="' . $cols . '"></td></tr>';
        echo '</table>';

        echo '<table border="1" style="border-collapse:collapse;">';
        echo '<thead><tr style="background:#343a40;color:#fff;font-weight:bold;font-size:12px;">';
        echo '<th style="padding:8px 10px;">ID</th><th>Status</th><th>Cut Type</th><th>Product</th><th>Lot No.</th><th>Roll No.</th><th>Width (mm)</th><th>Length (m)</th><th>Effective Length (m)</th><th>Actual Length (m)</th><th>Date In</th><th>Completed At</th>';
        echo '</tr></thead><tbody>';

        $td  = 'style="padding:6px 10px;"';
        $tdN = 'style="padding:6px 10px;text-align:right;"';

        foreach ($rows as $row) {
            echo '<tr style="background:#ffffff;">';
            echo '<td ' . $td  . '>' . (int)$row['id'] . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars(strtoupper($row['status']   ?? '-')) . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars(strtoupper($row['cut_type'] ?? '-')) . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($row['product']  ?? '-') . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($row['lot_no']   ?? '-') . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($row['roll_no']  ?? '-') . '</td>';
            echo '<td ' . $tdN . '>' . number_format((float)($row['width']            ?? 0)) . '</td>';
            echo '<td ' . $tdN . '>' . number_format((float)($row['length']           ?? 0)) . '</td>';
            $isBalanceRow = strtoupper(trim($row['roll_no'] ?? '')) === 'BALANCE';
            echo '<td ' . $tdN . '>' . number_format((float)($row['effective_length'] ?? 0)) . '</td>';
            echo '<td ' . $tdN . '>' . ($isBalanceRow ? '-' : number_format((float)($row['actual_length'] ?? 0))) . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($row['date_in']      ?? '-') . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($row['completed_at'] ?? '-') . '</td>';
            echo '</tr>';

            $parentId = (int)$row['id'];
            if (!empty($children[$parentId])) {
                foreach ($children[$parentId] as $roll) {
                    $lotDisplay = htmlspecialchars($row['lot_no'] ?? '-') . htmlspecialchars($roll['cut_letter'] ?? '');
                    $tdC  = 'style="background:#ebf8ff;padding:5px 10px;color:#2c5282;"';
                    $tdCN = 'style="background:#ebf8ff;padding:5px 10px;color:#2c5282;text-align:right;"';
                    echo '<tr>';
                    echo '<td ' . $tdC  . '>↳</td>';
                    echo '<td ' . $tdC  . '>COMPLETED</td>';
                    echo '<td ' . $tdC  . '>-</td>';
                    echo '<td ' . $tdC  . '>' . htmlspecialchars($row['product'] ?? '-') . '</td>';
                    echo '<td ' . $tdC  . '>' . $lotDisplay . '</td>';
                    echo '<td ' . $tdC  . '>' . htmlspecialchars($roll['roll_no'] ?? '-') . '</td>';
                    echo '<td ' . $tdCN . '>' . number_format((float)($roll['new_width']     ?? 0)) . '</td>';
                    echo '<td ' . $tdCN . '>' . number_format((float)($roll['length']        ?? 0)) . '</td>';
                    echo '<td ' . $tdCN . '>-</td>';
                    echo '<td ' . $tdCN . '>' . number_format((float)($roll['actual_length'] ?? 0)) . '</td>';
                    echo '<td ' . $tdC  . '>-</td>';
                    echo '<td ' . $tdC  . '>-</td>';
                    echo '</tr>';
                }
            }
        }

        echo '</tbody></table></body></html>';
        exit;
    }

    /**
     * Unified intake endpoint
     */
    public function intake(): void
    {
        if (!isset($_SESSION['role'])) {
            $this->json(['ok' => false, 'msg' => 'Session expired — please log in again.']);
        }
        if ($_SESSION['role'] !== 'slitting') {
            $this->json(['ok' => false, 'msg' => 'Access denied.']);
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $source = trim($body['source'] ?? ($_POST['source'] ?? 'unified'));

        if (isset($body['raw']) || isset($_POST['raw'])) {
            $raw = trim($body['raw'] ?? ($_POST['raw'] ?? ''));
            if ($raw === '') {
                $this->json(['ok' => false, 'msg' => 'Type or scan a Lot No, Coil No, and Roll No first.']);
            }
            $parsed  = $this->parseIntakeString($raw);
            $lot_no  = $parsed['lot_no'];
            $coil_no = $parsed['coil_no'];
            $roll_no = $parsed['roll_no'];

            if ($lot_no === '' || $coil_no === '' || $roll_no === '') {
                $this->json([
                    'ok'  => false,
                    'msg' => "Couldn't read \"{$raw}\" as Lot No + Coil No + Roll No. Try a format like 826529-N-2-R4 or 826529 N-2 R4.",
                ]);
            }
        } else {
            $lot_no  = trim($body['lot_no']  ?? ($_POST['lot_no']  ?? ''));
            $coil_no = trim($body['coil_no'] ?? ($_POST['coil_no'] ?? ''));
            $roll_no = trim($body['roll_no'] ?? ($_POST['roll_no'] ?? ''));
        }

        if ($lot_no === '' || $coil_no === '' || $roll_no === '') {
            $this->json(['ok' => false, 'msg' => 'Lot No, Coil No, and Roll No are all required.']);
        }

        $res = $this->reslitModel->processIntake($lot_no, $coil_no, $roll_no, $source);
        $this->json($res);
    }

    /**
     * Process modal completion
     */
    public function handleProcess(): void
    {
        $this->requireRole(['slitting']);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'complete_reslit_direct') {
            $this->redirect('reslit.php');
        }

        $parentId = intval($_POST['id'] ?? 0);
        if ($parentId <= 0) {
            $this->redirect('reslit.php');
        }

        try {
            $this->reslitModel->completeProcess($parentId, $_POST);
            $this->redirect('reslit.php?success=completed');
        } catch (Throwable $e) {
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                <title>Reslit Error</title>
            </head>
            <body class="bg-light">
                <div class="container mt-5">
                    <div class="row justify-content-center">
                        <div class="col-md-8">
                            <div class="card border-danger shadow">
                                <div class="card-header bg-danger text-white">
                                    <h4><i class="bi bi-exclamation-triangle-fill me-2"></i>Reslit Process Failed</h4>
                                </div>
                                <div class="card-body p-4 text-center">
                                    <p class="lead text-danger fw-bold"><?= htmlspecialchars($e->getMessage()) ?></p>
                                    <hr>
                                    <p class="text-secondary">Go back and fix the issue, or add a letter suffix to make the lot number unique.</p>
                                    <div class="d-flex justify-content-center gap-3 mt-4">
                                        <button onclick="history.back()" class="btn btn-warning px-4 fw-bold">← Back to Form</button>
                                        <a href="reslit.php" class="btn btn-outline-secondary px-4">Cancel</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    }

    /**
     * Send back action
     */
    public function sendBack(): void
    {
        $this->requireRole(['slitting']);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('reslit.php');
        }

        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            $this->redirect('reslit.php?error=invalid_id');
        }

        try {
            $resultCode = $this->reslitModel->sendBack($id);
            $this->redirect("reslit.php?success={$resultCode}");
        } catch (Throwable $e) {
            $reason = $e->getMessage();
            if ($reason === 'not_found') {
                $this->redirect('reslit.php?error=not_found');
            } elseif ($reason === 'not_pending') {
                $this->redirect('reslit.php?error=not_pending');
            } elseif ($reason === 'no_source_sfc') {
                $this->redirect('reslit.php?' . http_build_query([
                    'error' => 'return_failed',
                    'msg'   => 'This record has no originating SFC entry to return to (it may already have been restored, deleted, or reused).',
                ]));
            } else {
                $this->redirect('reslit.php?' . http_build_query([
                    'error' => 'return_failed',
                    'msg'   => $reason,
                ]));
            }
        }
    }

    /**
     * Summary AJAX endpoint
     */
    public function summaryAjax(): void
    {
        if (!isset($_SESSION['role'])) {
            $this->json(['error' => 'Unauthorized'], 403);
        }

        $month = trim($_GET['month'] ?? '');
        $data = $this->reslitModel->getSummaryReport($month);
        $this->json($data);
    }

    /**
     * Edit reslit product record
     */
    public function edit(): void
    {
        $this->requireRole(['slitting']);

        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if ($id <= 0) {
            $this->redirect('reslit.php');
        }

        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = [
                'product' => trim($_POST['product'] ?? ''),
                'lot_no'  => trim($_POST['lot_no'] ?? ''),
                'coil_no' => trim($_POST['coil_no'] ?? ''),
                'roll_no' => trim($_POST['roll_no'] ?? ''),
                'width'   => floatval($_POST['width'] ?? 0),
                'length'  => floatval($_POST['length'] ?? 0),
            ];

            if ($this->reslitModel->updateRecord($id, $data)) {
                $this->redirect('reslit.php?success=updated');
            } else {
                $error = "Update failed.";
            }
        }

        $data = $this->reslitModel->findById($id);
        if (!$data) {
            die("Record not found.");
        }

        $this->render('reslit/edit', [
            'data'  => $data,
            'error' => $error,
        ]);
    }

    /**
     * Legacy add from finish_product
     */
    public function add(): void
    {
        $this->requireRole(['slitting']);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('finish_product.php');
        }

        $productId = intval($_POST['product_id'] ?? 0);
        if ($productId <= 0) {
            $this->redirect('finish_product.php?error=invalid_id');
        }

        if ($this->reslitModel->addFromFinishProduct($productId)) {
            $this->redirect('reslit.php?success=added');
        } else {
            $this->redirect('finish_product.php?error=add_failed');
        }
    }

    /**
     * Helper to parse combined Lot-Coil-Roll strings
     */
    private function parseIntakeString(string $raw): array
    {
        $raw = trim($raw);

        // 1. QR / scan format
        if (preg_match('/LOT=([^;]+);COIL=([^;]+)(?:;ROLL=([^;]+))?/i', $raw, $m)) {
            return [
                'lot_no'  => trim($m[1]),
                'coil_no' => trim($m[2]),
                'roll_no' => trim($m[3] ?? ''),
            ];
        }

        // 2 & 3. Combined typed entry
        if (preg_match('/^(\d+[a-z]?)[\s\-]+([a-z]+-?\d+)[\s\-]+(r-?\d+)$/i', $raw, $m)) {
            return [
                'lot_no'  => $m[1],
                'coil_no' => $m[2],
                'roll_no' => $m[3],
            ];
        }

        // Fallback: naive 3-token split
        $tokens = preg_split('/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (count($tokens) === 3) {
            return ['lot_no' => $tokens[0], 'coil_no' => $tokens[1], 'roll_no' => $tokens[2]];
        }

        return ['lot_no' => '', 'coil_no' => '', 'roll_no' => ''];
    }
}

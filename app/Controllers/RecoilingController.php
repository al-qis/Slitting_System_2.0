<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\RecoilingProduct;
use Throwable;
use RuntimeException;

class RecoilingController extends Controller
{
    private RecoilingProduct $recoilModel;

    public function __construct()
    {
        parent::__construct();
        $this->recoilModel = new RecoilingProduct();
    }

    /**
     * Main Recoiling view & Excel export handler
     */
    public function index(): void
    {
        $this->requireRole(['slitting']);

        // Handle Excel Download
        if (isset($_GET['download']) && $_GET['download'] === 'excel') {
            $this->exportExcel();
            return;
        }

        // Process alerts from URL
        $alertType    = '';
        $alertMessage = '';
        if (isset($_GET['error'])) {
            $alertType = 'danger';
            switch ($_GET['error']) {
                case 'duplicate_lot':
                    $lots         = htmlspecialchars(urldecode($_GET['lots'] ?? ''));
                    $alertMessage = "<strong>Duplicate Detected!</strong> Roll <strong>{$lots}</strong> already exists. Add a letter suffix.";
                    break;
                case 'already_completed':
                    $alertMessage = "This recoiling record is already completed.";
                    break;
                case 'process_failed':
                    $alertMessage = "Process failed: " . htmlspecialchars(urldecode($_GET['msg'] ?? ''));
                    break;
                case 'invalid_id':
                    $alertMessage = "Invalid record ID.";
                    break;
                case 'not_found':
                    $alertMessage = "Record not found.";
                    break;
                case 'not_pending':
                    $alertMessage = "Only pending recoiling records can be sent back to Finished Product.";
                    break;
                case 'return_failed':
                    $alertMessage = "Could not send this item back to Finished Product: " . htmlspecialchars(urldecode($_GET['msg'] ?? ''));
                    break;
                default:
                    $alertMessage = "An unknown error occurred.";
            }
        } elseif (isset($_GET['success'])) {
            $alertType = 'success';
            if ($_GET['success'] === 'returned') {
                $alertMessage = "<strong>Sent back to Finished Product.</strong> This item has been removed from the Recoiling queue.";
            } elseif ($_GET['success'] === 'deleted') {
                $alertMessage = "<strong>Record deleted successfully.</strong>";
            } elseif ($_GET['success'] === 'updated') {
                $alertMessage = "<strong>Record updated successfully.</strong>";
            } elseif ($_GET['success'] === 'added') {
                $alertMessage = "<strong>Product added to recoiling queue.</strong>";
            } else {
                $alertMessage = "<strong>Recoiling completed successfully!</strong> The product has been saved.";
            }
        }

        $reopenId = isset($_GET['open_id']) ? (int)$_GET['open_id'] : 0;

        $tableRows = $this->recoilModel->getMainList();
        $children  = $this->recoilModel->getChildRolls();
        $kpi       = $this->recoilModel->getKpiCounts();

        $this->render('recoiling/index', [
            'tableRows'    => $tableRows,
            'children'     => $children,
            'pending'      => $kpi['pending'],
            'completed'    => $kpi['completed'],
            'alertType'    => $alertType,
            'alertMessage' => $alertMessage,
            'reopenId'     => $reopenId,
            'page_title'   => "Recoiling Cut",
        ]);
    }

    /**
     * Excel Export
     */
    private function exportExcel(): void
    {
        $rows = $this->recoilModel->getExportData();
        $filename  = 'Recoiling_Report_' . date('Y-m-d') . '.xls';
        $cols      = 13;
        $generated = date('d M Y, H:i');

        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $children = $this->recoilModel->getChildRolls();

        echo '<html><head><meta charset="UTF-8"></head><body>';
        echo '<table>';
        echo '<tr><td colspan="' . $cols . '" style="background:#1e3a5f;color:#fff;font-size:18px;font-weight:bold;padding:12px 16px;letter-spacing:1px;">RECOILING CUT REPORT</td></tr>';
        echo '<tr><td colspan="' . $cols . '" style="background:#2c5282;color:#bee3f8;font-size:11px;padding:4px 16px;">Generated: ' . $generated . ' &nbsp;|&nbsp; System: Slitting Management</td></tr>';
        echo '<tr><td colspan="' . $cols . '" style="background:#e2e8f0;padding:6px 16px;font-size:11px;color:#4a5568;"><strong>Note:</strong> Child rolls (↳) are shown indented under their parent recoiling record.</td></tr>';
        echo '<tr><td colspan="' . $cols . '"></td></tr>';
        echo '</table>';

        echo '<table border="1" style="border-collapse:collapse;">';
        echo '<thead><tr style="background:#343a40;color:#fff;font-weight:bold;font-size:12px;">';
        echo '<th style="padding:8px 10px;">ID</th><th>Status</th><th>Cut Type</th><th>Product</th><th>Lot No.</th><th>Coil No.</th><th>Roll No.</th><th>Width (mm)</th><th>Length (m)</th><th>Actual Length (m)</th><th>New Length (m)</th><th>Date In</th><th>Completed At</th>';
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
            echo '<td ' . $td  . '>' . htmlspecialchars($row['coil_no']  ?? '-') . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($row['roll_no']  ?? '-') . '</td>';
            echo '<td ' . $tdN . '>' . number_format((float)($row['width']         ?? 0)) . '</td>';
            echo '<td ' . $tdN . '>' . number_format((float)($row['length']        ?? 0)) . '</td>';
            echo '<td ' . $tdN . '>' . number_format((float)($row['actual_length'] ?? 0)) . '</td>';
            echo '<td ' . $tdN . '>' . number_format((float)($row['new_length']    ?? 0)) . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($row['date_in']      ?? '-') . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($row['completed_at'] ?? '-') . '</td>';
            echo '</tr>';

            $recoilId = (int)$row['id'];
            if (!empty($children[$recoilId])) {
                foreach ($children[$recoilId] as $child) {
                    $tdC  = 'style="background:#ebf8ff;padding:5px 10px;color:#2c5282;"';
                    $tdCN = 'style="background:#ebf8ff;padding:5px 10px;color:#2c5282;text-align:right;"';
                    echo '<tr>';
                    echo '<td ' . $tdC  . '>↳</td>';
                    echo '<td ' . $tdC  . '>COMPLETED</td>';
                    echo '<td ' . $tdC  . '>-</td>';
                    echo '<td ' . $tdC  . '>' . htmlspecialchars($row['product'] ?? '-') . '</td>';
                    echo '<td ' . $tdC  . '>' . htmlspecialchars($child['lot_no']  ?? '-') . '</td>';
                    echo '<td ' . $tdC  . '>' . htmlspecialchars($child['coil_no'] ?? '-') . '</td>';
                    echo '<td ' . $tdC  . '>' . htmlspecialchars($child['roll_no'] ?? '-') . '</td>';
                    echo '<td ' . $tdCN . '>' . number_format((float)($child['width']         ?? 0)) . '</td>';
                    echo '<td ' . $tdCN . '>' . number_format((float)($child['length']        ?? 0)) . '</td>';
                    echo '<td ' . $tdCN . '>' . number_format((float)($child['actual_length'] ?? 0)) . '</td>';
                    echo '<td ' . $tdCN . '>-</td>';
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
     * Unified intake bar endpoint
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

        $res = $this->recoilModel->processIntake($lot_no, $coil_no, $roll_no, $source);
        $this->json($res);
    }

    /**
     * Process modal actions (start, cancel, complete)
     */
    public function handleProcess(): void
    {
        // AJAX: start recoiling process
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_recoiling_process') {
            $recoilId = intval($_POST['id'] ?? 0);
            if ($recoilId > 0) {
                $ok = $this->recoilModel->updateProcessStatus($recoilId, 'in_progress');
                $this->json(['success' => $ok]);
            }
            $this->json(['success' => false, 'error' => 'Invalid ID']);
        }

        // AJAX: cancel recoiling process
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_recoiling_process') {
            $recoilId = intval($_POST['id'] ?? 0);
            if ($recoilId > 0) {
                $ok = $this->recoilModel->updateProcessStatus($recoilId, 'pending');
                $this->json(['success' => $ok]);
            }
            $this->json(['success' => false, 'error' => 'Invalid ID']);
        }

        // Submit form: start and complete recoiling
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'start_and_complete_recoiling') {
            $this->redirect('recoiling.php');
        }

        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            $this->redirect('recoiling.php?error=invalid_id');
        }

        try {
            $this->recoilModel->completeProcess($id, $_POST);
            $this->redirect("recoiling.php?success=completed&id={$id}");
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (str_starts_with($msg, 'duplicate_lot:')) {
                $dupList = substr($msg, strlen('duplicate_lot:'));
                $this->redirect("recoiling.php?error=duplicate_lot&lots=" . urlencode($dupList) . "&open_id={$id}");
            } elseif ($msg === 'already_completed') {
                $this->redirect("recoiling.php?error=already_completed&id={$id}");
            } else {
                $this->redirect("recoiling.php?error=process_failed&msg=" . urlencode($msg));
            }
        }
    }

    /**
     * Send back to finished product
     */
    public function sendBack(): void
    {
        $this->requireRole(['slitting']);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('recoiling.php');
        }

        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            $this->redirect('recoiling.php?error=invalid_id');
        }

        try {
            $this->recoilModel->sendBackToFinishedProduct($id);
            $this->redirect('recoiling.php?success=returned');
        } catch (Throwable $e) {
            $reason = $e->getMessage();
            if ($reason === 'not_found') {
                $this->redirect('recoiling.php?error=not_found');
            } elseif ($reason === 'not_pending') {
                $this->redirect('recoiling.php?error=not_pending');
            } elseif ($reason === 'no_source_finished_product') {
                $this->redirect('recoiling.php?' . http_build_query([
                    'error' => 'return_failed',
                    'msg'   => 'This record has no originating Finished Product roll to return to.',
                ]));
            } else {
                $this->redirect('recoiling.php?' . http_build_query([
                    'error' => 'return_failed',
                    'msg'   => $reason,
                ]));
            }
        }
    }

    /**
     * Summary Report AJAX endpoint
     */
    public function summaryAjax(): void
    {
        if (!isset($_SESSION['role'])) {
            $this->json(['error' => 'Unauthorized'], 403);
        }

        $month    = trim($_GET['month']    ?? '');
        $cut_type = trim($_GET['cut_type'] ?? '');

        $data = $this->recoilModel->getSummaryReport($month, $cut_type);
        $this->json($data);
    }

    /**
     * Edit recoiling product record
     */
    public function edit(): void
    {
        $this->requireRole(['slitting']);

        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            $this->redirect('recoiling.php?error=invalid_id');
        }

        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = [
                'product'       => trim($_POST['product'] ?? ''),
                'lot_no'        => trim($_POST['lot_no'] ?? ''),
                'coil_no'       => trim($_POST['coil_no'] ?? ''),
                'roll_no'       => trim($_POST['roll_no'] ?? ''),
                'width'         => floatval($_POST['width'] ?? 0),
                'length'        => floatval($_POST['length'] ?? 0),
                'actual_length' => floatval($_POST['actual_length'] ?? 0),
            ];

            if ($this->recoilModel->updateRecord($id, $data)) {
                $this->redirect('recoiling.php?success=updated');
            } else {
                $error = "Failed to update record.";
            }
        }

        $row = $this->recoilModel->findById($id);
        if (!$row) {
            die("Record not found.");
        }

        $this->render('recoiling/edit', [
            'data'  => $row,
            'error' => $error,
        ]);
    }

    /**
     * Delete recoiling product
     */
    public function delete(): void
    {
        $this->requireRole(['slitting']);

        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            $this->redirect('recoiling.php?error=invalid_id');
        }

        if ($this->recoilModel->deleteRecord($id)) {
            $this->redirect('recoiling.php?success=deleted');
        } else {
            $this->redirect('recoiling.php?error=not_found');
        }
    }

    /**
     * Legacy add from finished product
     */
    public function add(): void
    {
        $this->requireRole(['slitting']);

        $productId    = intval($_GET['id'] ?? 0);
        $actualLength = isset($_GET['actual_length']) ? floatval($_GET['actual_length']) : null;

        if ($productId <= 0) {
            $this->redirect('finish_product.php?error=invalid_id');
        }

        if ($this->recoilModel->addFromSlitting($productId, $actualLength)) {
            $this->redirect('recoiling.php?success=added');
        } else {
            $this->redirect('finish_product.php?error=not_found');
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

        // 2 & 3. Combined typed entry — dash- or space-separated (or a mix)
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

<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Pallet;
use Throwable;

class PalletController extends Controller
{
    private Pallet $palletModel;

    public function __construct()
    {
        parent::__construct();
        $this->palletModel = new Pallet();
    }

    /**
     * Main Pallet Management View & Handlers (formerly pallet.php)
     */
    public function index(): void
    {
        $this->requireRole(['slitting']);

        $this->palletModel->ensureOperatorsTable();

        $activeOperatorSession = $_SESSION['active_operator'] ?? '';
        $actor = !empty($activeOperatorSession) ? $activeOperatorSession : ($_SESSION['role'] ?? 'system');
        $pm = $this->palletModel->getManager($actor);

        // ── AJAX: set active operator ──────────────────────────────────
        if (isset($_POST['ajax']) && $_POST['ajax'] === 'set_active_operator') {
            $opName = trim($_POST['operator_name'] ?? '');
            $_SESSION['active_operator'] = $opName;
            $palletId = (int)($_POST['pallet_id'] ?? 0);
            $this->palletModel->setActiveOperator($opName, $palletId);
            $this->json(['ok' => true, 'operator' => $opName]);
            return;
        }

        // ── AJAX: add new operator ─────────────────────────────────────
        if (isset($_POST['ajax']) && $_POST['ajax'] === 'add_operator') {
            $opName = trim($_POST['operator_name'] ?? '');
            $res = $this->palletModel->addOperator($opName);
            if ($res['ok']) {
                $_SESSION['active_operator'] = $opName;
            }
            $this->json($res);
            return;
        }

        // ── AJAX: validate pallet_no ──────────────────────────────────
        if (isset($_GET['ajax']) && $_GET['ajax'] === 'validate_pallet_no') {
            $this->json($pm->validatePalletNo(trim($_GET['pallet_no'] ?? '')));
            return;
        }

        // ── AJAX: get latest pallet numbers (none, B, BN) ─────────────
        if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_latest_pallets') {
            $this->json($pm->getLatestPalletNumbers());
            return;
        }

        // ── AJAX: get next auto-generated Pallet ID ───────────────────
        if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_next_pallet_no') {
            $yy     = trim($_GET['yy'] ?? date('y'));
            $mm     = trim($_GET['mm'] ?? date('m'));
            $suffix = trim($_GET['suffix'] ?? 'none');
            $this->json($pm->getNextPalletNo($yy, $mm, $suffix));
            return;
        }

        // ── AJAX: product lookup ──────────────────────────────────────
        if (isset($_GET['ajax']) && $_GET['ajax'] === 'lookup_product') {
            $lot   = trim($_GET['lot']   ?? '');
            $coil  = trim($_GET['coil']  ?? '');
            $roll  = trim($_GET['roll']  ?? '');
            $width = floatval($_GET['width'] ?? 0);
            $id    = intval($_GET['id']    ?? 0);

            $product = $this->palletModel->lookupProduct($id, $lot, $coil, $roll, $width);
            if (!$product) {
                $this->json(['ok' => false, 'msg' => "Roll not found: {$lot} {$coil} {$roll}"]);
                return;
            }
            $this->json(['ok' => true, 'product' => $product]);
            return;
        }

        // ── AJAX: unified pallet sidebar feed ──────────────────────────
        if (isset($_GET['ajax']) && $_GET['ajax'] === 'list_pallets') {
            $group  = trim($_GET['group']  ?? 'all');
            $search = trim($_GET['q']      ?? '');
            $sort   = trim($_GET['sort']   ?? 'latest');
            $suffix = trim($_GET['suffix'] ?? 'all');
            $date   = trim($_GET['date']   ?? '');
            $this->json($pm->listPallets($group, $search, $sort, $suffix, $date));
            return;
        }

        // ── AJAX: rename pallet (inline header edit) ────────────────────
        if (isset($_POST['ajax']) && $_POST['ajax'] === 'rename_pallet') {
            $palletId = (int)($_POST['pallet_id'] ?? 0);
            $newNo    = trim($_POST['pallet_no'] ?? '');
            if ($palletId <= 0) {
                $this->json(['ok' => false, 'msg' => 'Missing or invalid pallet_id.']);
                return;
            }
            $this->json($pm->renamePallet($palletId, $newNo));
            return;
        }

        // ── AJAX: update Customer & Ref No (inline header edit) ─────────
        if (isset($_POST['ajax']) && $_POST['ajax'] === 'update_customer_ref') {
            $palletId    = (int)($_POST['pallet_id'] ?? 0);
            $newCustomer = trim($_POST['customer_name'] ?? '');
            $newRefNo    = trim($_POST['ref_no'] ?? '');
            if ($palletId <= 0) {
                $this->json(['ok' => false, 'msg' => 'Missing or invalid pallet_id.']);
                return;
            }
            $this->json($pm->updatePalletCustomerRef($palletId, $newCustomer, $newRefNo));
            return;
        }

        // ── AJAX: update Pallet Date (inline header edit) ─────────────────
        if (isset($_POST['ajax']) && $_POST['ajax'] === 'update_pallet_date') {
            $palletId = (int)($_POST['pallet_id'] ?? 0);
            $newDate  = trim($_POST['date'] ?? '');
            if ($palletId <= 0) {
                $this->json(['ok' => false, 'msg' => 'Missing or invalid pallet_id.']);
                return;
            }
            $this->json($pm->updatePalletDate($palletId, $newDate));
            return;
        }

        // ── AJAX: deliver by scan ──────────────────────────────────────
        if (isset($_POST['ajax']) && $_POST['ajax'] === 'deliver_by_scan') {
            $raw            = trim($_POST['raw'] ?? '');
            $activePalletId = (int)($_POST['active_pallet_id'] ?? 0);
            $res = $this->palletModel->deliverByScan($raw, $activePalletId, $actor);
            $this->json($res);
            return;
        }

        // ── AJAX: Summary Pallet ───────────────────────────────────────
        if (isset($_GET['ajax']) && $_GET['ajax'] === 'summary_pallet') {
            $out = $this->palletModel->buildSummaryPalletRows();
            $this->json(['ok' => true, 'rows' => $out]);
            return;
        }

        // ── Export: Summary Pallet as Excel (.xls) ──────────────────────
        if (isset($_GET['export']) && $_GET['export'] === 'summary_pallet') {
            $this->exportSummaryExcel();
            return;
        }

        // ── POST actions ───────────────────────────────────────────────
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';

            if ($action === 'create_pallet') {
                $palletNo = trim($_POST['pallet_no'] ?? '');
                if (!$palletNo) { $this->json(['ok' => false, 'msg' => 'Pallet No is required.']); return; }
                $this->json($pm->createPallet($palletNo));
                return;
            }

            if ($action === 'add_roll') {
                $palletId  = intval($_POST['pallet_id']  ?? 0);
                $productId = intval($_POST['product_id'] ?? 0);
                if (!$palletId || !$productId) { $this->json(['ok' => false, 'msg' => 'Missing IDs.']); return; }
                $this->json($pm->addRollToPallet($palletId, $productId));
                return;
            }

            if ($action === 'remove_roll') {
                $palletId  = intval($_POST['pallet_id']  ?? 0);
                $productId = intval($_POST['product_id'] ?? 0);
                if (!$palletId || !$productId) { $this->json(['ok' => false, 'msg' => 'Missing IDs.']); return; }
                $this->json($pm->removeRollFromPallet($palletId, $productId));
                return;
            }

            if ($action === 'delete_pallet') {
                $palletId = intval($_POST['pallet_id'] ?? 0);
                $result   = $pm->deletePallet($palletId);
                if ($result['ok']) {
                    $this->redirect("pallet.php?success=pallet_deleted&pallet_no=" . urlencode($result['pallet_no'] ?? ''));
                } else {
                    $this->redirect("pallet.php?error=" . urlencode($result['msg']));
                }
                return;
            }

            if ($action === 'send_to_qc') {
                $palletId = intval($_POST['pallet_id'] ?? 0);
                $result   = $pm->sendToQC($palletId);
                if ($result['ok']) {
                    $this->redirect("pallet.php?success=sent_to_qc");
                } else {
                    $this->redirect("pallet.php?pallet_id={$palletId}&error=" . urlencode($result['msg']));
                }
                return;
            }

            if ($action === 'reopen_pallet') {
                $palletId   = intval($_POST['pallet_id'] ?? 0);
                $reason     = trim($_POST['reopen_reason'] ?? '');
                $returnType = trim($_POST['return_type'] ?? 'return_to_edit');

                if ($returnType === 'return_to_stock') {
                    $selectedCoils = $_POST['selected_coils'] ?? [];
                    if (!is_array($selectedCoils)) {
                        $selectedCoils = [];
                    }
                    $result = $pm->returnCoilsToStock($palletId, $selectedCoils, $reason);
                    if ($result['ok']) {
                        $this->redirect("pallet.php?pallet_id={$palletId}&success=returned_to_stock");
                    } else {
                        $this->redirect("pallet.php?pallet_id={$palletId}&error=" . urlencode($result['msg']));
                    }
                } else {
                    $result = $pm->reopenApprovedOrDeliveredPallet($palletId, $reason);
                    if ($result['ok']) {
                        $this->redirect("pallet.php?pallet_id={$palletId}&success=reopened");
                    } else {
                        $this->redirect("pallet.php?pallet_id={$palletId}&error=" . urlencode($result['msg']));
                    }
                }
                return;
            }

            if ($action === 'resubmit_to_qc') {
                $palletId = intval($_POST['pallet_id'] ?? 0);
                $result   = $pm->resubmitToQC($palletId);
                if ($result['ok']) {
                    $this->redirect("pallet.php?success=resubmitted");
                } else {
                    $this->redirect("pallet.php?pallet_id={$palletId}&error=" . urlencode($result['msg']));
                }
                return;
            }

            if ($action === 'deliver_pallet') {
                $palletId = intval($_POST['pallet_id'] ?? 0);
                $result   = $this->palletModel->deliverBundle($palletId, $actor);
                if ($result['ok']) {
                    $this->redirect("pallet.php?success=delivered&pallet_no=" . urlencode($result['pallet_no'] ?? ''));
                } else {
                    $this->redirect("pallet.php?error=" . urlencode($result['msg']));
                }
                return;
            }
        }

        // ── Prepare data for View ──────────────────────────────────────
        $activePalletId = intval($_GET['pallet_id'] ?? 0);
        $activePallet   = $activePalletId ? $pm->getPallet($activePalletId) : null;
        $activeItems    = $activePallet ? $this->palletModel->getPalletItemsWithWeight($activePalletId) : [];

        $itemsBySeq = [];
        foreach ($activeItems as $item) {
            $itemsBySeq[(int)$item['seq']] = $item;
        }

        $totalEstWgt = 0.0;
        foreach ($activeItems as $item) {
            $len = (float)($item['actual_length'] ?: $item['length']);
            $totalEstWgt += Pallet::calcEstWeight($len, (float)$item['width'], (float)$item['std_weight']);
        }

        $rejectedPallets = $this->palletModel->getRejectedPallets();
        $reopenedPallets = $this->palletModel->getReopenedPallets();
        $operators       = $this->palletModel->getOperators();

        $allEditLogs = [];
        $reopenLogs  = [];
        if ($activePalletId) {
            $allEditLogs = $this->palletModel->getAllEditLogs($activePalletId);
            if ($activePallet && $activePallet['status'] === 'building') {
                $reopenLogs = array_values(array_filter($allEditLogs, fn($l) => in_array($l['action'] ?? '', ['reopen', 'return_to_stock'])));
            }
        }

        $isBuilding  = $activePallet && $activePallet['status'] === 'building';
        $isRejected  = $activePallet && $activePallet['status'] === 'rejected';
        $isApproved  = $activePallet && $activePallet['status'] === 'approved';
        $isPendingQc = $activePallet && $activePallet['status'] === 'pending_qc';
        $isDelivered = $activePallet && $activePallet['status'] === 'delivered';
        $isReadOnly  = $activePallet && !in_array($activePallet['status'], ['building', 'rejected']);
        $MAX         = \PalletManager::MAX_ROLLS;

        $this->render('pallet/index', [
            'activePalletId'        => $activePalletId,
            'activePallet'          => $activePallet,
            'activeItems'           => $activeItems,
            'itemsBySeq'            => $itemsBySeq,
            'totalEstWgt'           => $totalEstWgt,
            'rejectedPallets'       => $rejectedPallets,
            'reopenedPallets'       => $reopenedPallets,
            'operators'             => $operators,
            'activeOperatorSession' => $activeOperatorSession,
            'allEditLogs'           => $allEditLogs,
            'reopenLogs'            => $reopenLogs,
            'isBuilding'            => $isBuilding,
            'isRejected'            => $isRejected,
            'isApproved'            => $isApproved,
            'isPendingQc'           => $isPendingQc,
            'isDelivered'           => $isDelivered,
            'isReadOnly'            => $isReadOnly,
            'MAX'                   => $MAX,
        ]);
    }

    /**
     * Excel Export for Summary Pallet
     */
    private function exportSummaryExcel(): void
    {
        $cat         = isset($_GET['cat']) ? trim($_GET['cat']) : '';
        $val         = isset($_GET['val']) ? trim($_GET['val']) : '';
        $statusParam = isset($_GET['status']) ? trim($_GET['status']) : '';
        $suffixParam = isset($_GET['suffix']) ? trim($_GET['suffix']) : '';

        $rows = $this->palletModel->buildSummaryPalletRows();
        $rows = $this->palletModel->filterSummaryPalletRows($rows, $cat, $val, $statusParam, $suffixParam);

        $catLabels = ['customer' => 'Customer', 'product' => 'Product Type', 'date' => 'Date', 'width' => 'Width', 'length' => 'Length', 'suffix' => 'Pallet Suffix'];
        $filterParts = [];
        if ($val !== '') {
            $filterParts[] = isset($catLabels[$cat]) ? "{$catLabels[$cat]}: {$val}" : "Search: {$val}";
        }
        if ($statusParam !== '') {
            $statusName = ucwords(str_replace('_', ' ', $statusParam));
            $filterParts[] = "Status: {$statusName}";
        }
        if ($suffixParam !== '') {
            $suffixName = ($suffixParam === 'none') ? 'None (Standard)' : strtoupper($suffixParam);
            $filterParts[] = "Suffix: {$suffixName}";
        }
        $filterLbl = !empty($filterParts) ? implode(' | ', $filterParts) : 'All Records';

        $filename = 'Summary_Pallet_' . date('Y-m-d_His') . '.xls';

        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $cols      = 10;
        $generated = date('d M Y, H:i');
        ?>
<html><head><meta charset="UTF-8"></head><body>
<table>
    <tr><td colspan="<?= $cols ?>" style="background:#1e3a5f;color:#fff;font-size:18px;font-weight:bold;padding:12px 16px;letter-spacing:1px;">PALLET SUMMARY REPORT</td></tr>
    <tr><td colspan="<?= $cols ?>" style="background:#2c5282;color:#bee3f8;font-size:11px;padding:4px 16px;">Generated: <?= htmlspecialchars($generated) ?> &nbsp;|&nbsp; Filter: <?= htmlspecialchars($filterLbl) ?> &nbsp;|&nbsp; System: Slitting Management</td></tr>
    <tr><td colspan="<?= $cols ?>"></td></tr>
</table>
<table border="1" style="border-collapse:collapse;">
    <thead>
        <tr style="background:#343a40;color:#fff;font-weight:bold;font-size:12px;">
            <th style="padding:8px 10px;">Pallet No</th>
            <th style="padding:8px 10px;">Date</th>
            <th style="padding:8px 10px;">Status</th>
            <th style="padding:8px 10px;">Stock Code</th>
            <th style="padding:8px 10px;">Product Type</th>
            <th style="padding:8px 10px;">Rolls</th>
            <th style="padding:8px 10px;">Customer</th>
            <th style="padding:8px 10px;">Ref No</th>
            <th style="padding:8px 10px;">Width (mm)</th>
            <th style="padding:8px 10px;">Length (m)</th>
        </tr>
    </thead>
    <tbody>
<?php
        $td  = 'style="padding:6px 10px;"';
        $tdN = 'style="padding:6px 10px;text-align:right;"';

        if (!empty($rows)) {
            foreach ($rows as $r) {
                $statusLbl = ucwords(str_replace('_', ' ', $r['status'] ?? '-'));
                $rollsCell = $r['roll_no'] ? trim($r['lot_coil'] . ' - ' . $r['roll_no']) : '-- no rolls --';

                echo '<tr>';
                echo '<td ' . $td  . '><b>' . htmlspecialchars($r['pallet_no'] ?? '-') . '</b></td>';
                echo '<td ' . $td  . '>' . htmlspecialchars(($r['date'] ?? '') ?: '-') . '</td>';
                echo '<td ' . $td  . '>' . htmlspecialchars($statusLbl) . '</td>';
                echo '<td ' . $td  . '>' . htmlspecialchars(($r['stock_code'] ?? '') ?: '-') . '</td>';
                echo '<td ' . $td  . '>' . htmlspecialchars(($r['product'] ?? '') ?: '-') . '</td>';
                echo '<td ' . $td  . '>' . htmlspecialchars($rollsCell) . '</td>';
                echo '<td ' . $td  . '>' . htmlspecialchars($r['customer'] ?: '-') . '</td>';
                echo '<td ' . $td  . '>' . htmlspecialchars($r['ref_no']   ?: '-') . '</td>';
                echo '<td ' . $tdN . '>' . ($r['width'] !== null ? Pallet::formatWidthDisplay($r['width']) : '-') . '</td>';
                echo '<td ' . $tdN . '>' . ($r['length'] !== null ? Pallet::formatWidthDisplay($r['length']) : '-') . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="' . $cols . '" style="padding:20px;text-align:center;color:#666;">No records found for this filter.</td></tr>';
        }
?>
    </tbody>
</table>
</body></html>
<?php
        exit;
    }

    /**
     * Bundle Delivery Endpoint (formerly pallet_deliver.php & pallet_handler.php)
     */
    public function deliver(): void
    {
        $this->requireRole(['slitting', 'qc']);

        $performedBy = $_SESSION['role'] ?? 'system';
        $isAjax = (
            (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || ($_POST['ajax'] ?? '') === '1'
            || ($_GET['ajax']  ?? '') === '1'
        );

        $palletId  = 0;
        $productId = 0;
        $refMonth  = (int)date('m');
        $refYear   = (int)date('Y');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $palletId  = intval($_POST['pallet_id']  ?? 0);
            $productId = intval($_POST['product_id'] ?? 0);
            $refMonth  = intval($_POST['month']       ?? date('m'));
            $refYear   = intval($_POST['year']        ?? date('Y'));
        } else {
            $palletId  = intval($_GET['pallet_id']  ?? 0);
            $productId = intval($_GET['product_id'] ?? 0);
            $refMonth  = intval($_GET['month']       ?? date('m'));
            $refYear   = intval($_GET['year']        ?? date('Y'));
        }

        // Check if called from pallet_handler.php
        $caller = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $backUrl = ($caller === 'pallet_handler.php')
            ? "pallet.php"
            : "finish_product.php?month={$refMonth}&year={$refYear}";

        if (!$palletId && $productId) {
            $pm = $this->palletModel->getManager($performedBy);
            $palletId = $pm->getPalletIdForProduct($productId) ?? 0;
            if (!$palletId) {
                if ($isAjax) {
                    $this->json(['ok' => false, 'msg' => 'This product is not assigned to any pallet.', 'code' => 'NO_PALLET']);
                    return;
                }
                $this->redirect($backUrl . '&scan=no_pallet&pid=' . $productId);
                return;
            }
        }

        if (!$palletId) {
            if ($isAjax) {
                $this->json(['ok' => false, 'msg' => 'No pallet ID provided.', 'code' => 'MISSING_ID']);
                return;
            }
            $this->redirect($backUrl . '?error=' . urlencode('No pallet ID provided.'));
            return;
        }

        $result = $this->palletModel->deliverBundle($palletId, $performedBy);

        if ($isAjax) {
            $this->json($result);
            return;
        }

        if ($caller === 'pallet_handler.php') {
            if ($result['ok']) {
                $this->redirect("pallet.php?success=delivered");
            } else {
                $this->redirect("pallet.php?error=" . urlencode($result['msg']));
            }
            return;
        }

        if ($result['ok']) {
            $code = $result['code'];
            if ($code === 'DELIVERED') {
                $cnt = $result['roll_count'];
                $this->redirect("{$backUrl}&scan=pallet_delivered&pallet_no=" . urlencode($result['pallet_no']) . "&roll_count={$cnt}");
            } else {
                $this->redirect("{$backUrl}&scan=already_delivered_pallet&pallet_no=" . urlencode($result['pallet_no'] ?? ''));
            }
        } else {
            $this->redirect("{$backUrl}&scan=deliver_error&code=" . urlencode($result['code']) . "&msg=" . urlencode($result['msg']));
        }
    }

    /**
     * QC Dashboard Action (formerly pallet_qc_action.php)
     */
    public function qcAction(): void
    {
        $this->requireRole(['qc']);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect("qc_dashboard.php");
            return;
        }

        $action         = $_POST['action']         ?? '';
        $palletId       = intval($_POST['pallet_id']      ?? 0);
        $checkedBy      = trim($_POST['checked_by']     ?? '');
        $topProductId   = intval($_POST['top_product_id'] ?? 0);

        if ($checkedBy === '' && !empty($_SESSION['active_qc_inspector'])) {
            $checkedBy = trim($_SESSION['active_qc_inspector']);
        }

        if ($palletId <= 0) {
            $this->redirect("qc_dashboard.php?error=invalid_id");
            return;
        }

        if ($checkedBy === '') {
            $this->redirect("qc_dashboard.php?error=" . urlencode('Please select an active inspector name in the header navbar before proceeding.'));
            return;
        }

        if ($action === 'approve') {
            if ($topProductId <= 0) {
                $this->redirect("qc_dashboard.php?error=" . urlencode('Please tick both checklist items on a product row before proceeding.'));
                return;
            }

            $result = $this->palletModel->approvePallet($palletId, $checkedBy, $topProductId);
            $this->redirect($result['ok']
                ? "qc_dashboard.php?approved=1"
                : "qc_dashboard.php?error=" . urlencode($result['msg'] ?? 'approve_failed')
            );
            return;
        }

        if ($action === 'reject') {
            $comment = trim($_POST['comment'] ?? '');
            if ($comment === '') {
                $this->redirect("qc_dashboard.php?error=comment_required");
                return;
            }

            $result = $this->palletModel->rejectPallet($palletId, $comment, $checkedBy, $topProductId);
            $this->redirect($result['ok']
                ? "qc_dashboard.php?rejected=1"
                : "qc_dashboard.php?error=" . urlencode($result['msg'] ?? 'reject_failed')
            );
            return;
        }

        $this->redirect("qc_dashboard.php");
    }

    /**
     * Scan Product Action (formerly scan_pallet_action.php)
     */
    public function scanProductAction(): void
    {
        $this->requireRole(['slitting', 'qc']);

        $lot  = trim($_GET['lot']  ?? '');
        $coil = trim($_GET['coil'] ?? '');
        $roll = trim($_GET['roll'] ?? '');

        $product = $this->palletModel->lookupProduct(0, $lot, $coil, $roll, 0.0);
        if (!$product) {
            $this->json(['ok' => false, 'msg' => 'Product not found']);
            return;
        }

        $pallet = null;
        $items  = [];
        if (!empty($product['pallet_id'])) {
            $pm = $this->palletModel->getManager();
            $pallet = $pm->getPallet((int)$product['pallet_id']);
            $items  = $pm->getPalletItems((int)$product['pallet_id']);
        }

        $this->json([
            'ok'      => true,
            'product' => $product,
            'pallet'  => $pallet,
            'items'   => $items
        ]);
    }

    /**
     * Finish Product Delivery Scan (formerly delivery_scan.php)
     */
    public function deliveryScan(): void
    {
        $this->requireRole(['slitting', 'qc']);

        $slitId = intval($_GET['id'] ?? 0);
        if (!$slitId) {
            $this->redirect("finish_product.php?error=invalid_id");
            return;
        }

        $delBy = trim($_GET['del_by'] ?? $_SESSION['role'] ?? 'system');
        $res = $this->palletModel->deliverFinishProduct($slitId, $delBy);

        if ($res['ok']) {
            $this->redirect("finish_product.php?success=delivered");
        } else {
            $this->redirect("finish_product.php?error=" . ($res['code'] ?? 'failed'));
        }
    }
}

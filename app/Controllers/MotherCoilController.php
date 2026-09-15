<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\MotherCoil;
use Throwable;

class MotherCoilController extends Controller
{
    private MotherCoil $motherModel;

    public function __construct()
    {
        parent::__construct();
        $this->motherModel = new MotherCoil();
    }

    /**
     * Main Mother Coil listing and AJAX handler
     */
    public function index(): void
    {
        // ── AJAX Endpoints ──────────────────────────────────────────
        $ajax = $_GET['ajax'] ?? '';
        if ($ajax !== '') {
            if ($ajax === 'validate_product') {
                $code = trim($_GET['code'] ?? '');
                if ($code === '') {
                    $this->json(['ok' => true, 'found' => false]);
                }
                $prod = $this->motherModel->validateProduct($code);
                $this->json(['ok' => true, 'found' => (bool)$prod, 'product' => $prod]);
            }

            if ($ajax === 'search_coil') {
                $q = trim($_GET['q'] ?? '');
                if ($q === '') {
                    $this->json(['ok' => false, 'results' => []]);
                }
                $results = $this->motherModel->searchCandidates($q);
                $this->json(['ok' => true, 'results' => $results]);
            }

            if ($ajax === 'resolve_bulk_paste' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $body = json_decode(file_get_contents('php://input'), true);
                $text = trim($body['text'] ?? '');
                $res = $this->motherModel->resolveBulkPaste($text);
                $this->json($res);
            }

            if ($ajax === 'get_product') {
                $coil = $_GET['coil'] ?? '';
                $products = $this->motherModel->productsFromCoil($coil);
                $this->json([
                    'ok'       => count($products) > 0,
                    'products' => $products,
                    'product'  => count($products) === 1 ? $products[0] : '',
                ]);
            }

            if ($ajax === 'get_slitting_plan') {
                $mother_id = intval($_GET['mother_id'] ?? 0);
                $plans = $mother_id > 0 ? $this->motherModel->findSlittingPlans($mother_id) : [];
                $this->json(['ok' => true, 'plans' => $plans]);
            }
        }

        // Enforce role
        $this->requireRole(['slitting', 'mkl3']);

        // ── POST ACTIONS (ADD / UPDATE / BULK ADD) ─────────────────
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';

            if ($action === 'bulk_add') {
                $rows = json_decode($_POST['rows_json'] ?? '[]', true);
                if (!is_array($rows)) $rows = [];

                $planRowsRaw = json_decode($_POST['plan_rows_json'] ?? '[]', true);
                $sharedPlanRows = [];
                if (is_array($planRowsRaw)) {
                    foreach ($planRowsRaw as $pr) {
                        $seq   = trim($pr['seq']   ?? '');
                        $wRaw  = trim($pr['width'] ?? '');
                        $cRaw  = trim($pr['customer'] ?? '');
                        $refNo = trim($pr['ref_no']   ?? '');
                        if ($seq === '' || $wRaw === '' || !is_numeric($wRaw)) continue;
                        $sharedPlanRows[] = [
                            'seq'      => $seq,
                            'width'    => (float)$wRaw,
                            'customer' => $cRaw,
                            'ref_no'   => $refNo,
                        ];
                    }
                }

                $res = $this->motherModel->bulkAdd($rows, $sharedPlanRows);
                $this->json($res);
            }

            if ($action === 'add') {
                $planSeqs      = $_POST['plan_seq']      ?? [];
                $planWidths    = $_POST['plan_width']    ?? [];
                $planCustomers = $_POST['plan_customer'] ?? [];
                $planRefs      = $_POST['plan_ref']      ?? [];

                $planRows = [];
                if (is_array($planSeqs) && is_array($planWidths)) {
                    foreach ($planSeqs as $i => $seqRaw) {
                        $planRows[] = [
                            'seq'      => $seqRaw,
                            'width'    => $planWidths[$i] ?? '',
                            'customer' => $planCustomers[$i] ?? '',
                            'ref_no'   => $planRefs[$i] ?? '',
                        ];
                    }
                }

                try {
                    $this->motherModel->create($_POST, $planRows);
                    $this->redirect("mother_coil.php?success=1");
                } catch (Throwable $e) {
                    die("Registration failed: " . htmlspecialchars($e->getMessage()));
                }
            }

            if ($action === 'update') {
                $id = intval($_POST['id'] ?? 0);
                if ($id <= 0) {
                    die("Invalid ID");
                }

                $planSeqs      = $_POST['plan_seq']      ?? [];
                $planWidths    = $_POST['plan_width']    ?? [];
                $planCustomers = $_POST['plan_customer'] ?? [];
                $planRefs      = $_POST['plan_ref']      ?? [];

                $planRows = [];
                if (is_array($planSeqs) && is_array($planWidths)) {
                    foreach ($planSeqs as $i => $seqRaw) {
                        $planRows[] = [
                            'seq'      => $seqRaw,
                            'width'    => $planWidths[$i] ?? '',
                            'customer' => $planCustomers[$i] ?? '',
                            'ref_no'   => $planRefs[$i] ?? '',
                        ];
                    }
                }

                try {
                    $childrenUpdated = $this->motherModel->updateRecord($id, $_POST, $planRows);
                    $this->redirect("mother_coil.php?success=update&children_updated={$childrenUpdated}");
                } catch (Throwable $e) {
                    die("<div style='color:red;font-family:sans-serif;padding:20px;border:1px solid red;background:#fff5f5;'>
                            <h2>Update Failed — No Changes Saved</h2>
                            <p><strong>Reason:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
                            <button onclick='history.back()'>Go Back and Fix</button>
                         </div>");
                }
            }
        }

        // Search & Filters
        $search      = trim($_GET['search'] ?? '');
        $printFilter = trim($_GET['print_status'] ?? '');
        if (!in_array($printFilter, ['printed', 'not_printed'], true)) {
            $printFilter = '';
        }

        $rows         = $this->motherModel->search($search, $printFilter);
        $all_products = $this->motherModel->getDistinctProducts();

        $success = $_GET['success'] ?? null;
        $error   = $_GET['error']   ?? null;

        $error_messages = [
            'not_found'     => '❌ Mother coil not found. It may have already been deleted.',
            'missing_id'    => '❌ No ID was provided.',
            'invalid_id'    => '❌ Invalid ID.',
            'delete_failed' => '❌ Delete failed: ' . htmlspecialchars(urldecode($_GET['msg'] ?? 'Unknown error')),
        ];

        $childrenUpdatedCount = isset($_GET['children_updated']) ? (int)$_GET['children_updated'] : null;

        $success_messages = [
            '1'      => '✅ Mother coil saved successfully.',
            '3'      => '✅ Mother coil deleted successfully.',
            'update' => ($childrenUpdatedCount !== null)
                ? ($childrenUpdatedCount > 0
                    ? "✅ Mother coil updated successfully. Lot No. change cascaded to <strong>{$childrenUpdatedCount}</strong> child roll(s) on Finished Product."
                    : "✅ Mother coil updated successfully. Lot No. changed, but no child rolls were found to update.")
                : '✅ Mother coil updated successfully.',
        ];

        $this->render('mother_coil/index', [
            'rows'             => $rows,
            'all_products'     => $all_products,
            'search'           => $search,
            'printFilter'      => $printFilter,
            'success'          => $success,
            'error'            => $error,
            'error_messages'   => $error_messages,
            'success_messages' => $success_messages,
            'page_title'       => 'Mother Coil',
        ]);
    }

    /**
     * Dedicated edit view (edit_mother.php)
     */
    public function edit(): void
    {
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            die("Missing ID");
        }

        $data = $this->motherModel->findById($id);
        if (!$data) {
            die("Mother coil not found");
        }

        $plans = $this->motherModel->findSlittingPlans($id);

        $this->render('mother_coil/edit', [
            'data'       => $data,
            'plans'      => $plans,
            'page_title' => 'Edit Mother Coil',
        ]);
    }

    /**
     * Update endpoint (update_mother.php)
     */
    public function update(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('mother_coil.php');
        }

        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            die("Invalid request: missing ID");
        }

        $planSeqs      = $_POST['plan_seq']      ?? [];
        $planWidths    = $_POST['plan_width']    ?? [];
        $planCustomers = $_POST['plan_customer'] ?? [];
        $planRefs      = $_POST['plan_ref']      ?? [];

        $planRows = [];
        if (is_array($planSeqs) && is_array($planWidths)) {
            foreach ($planSeqs as $i => $seqRaw) {
                $planRows[] = [
                    'seq'      => $seqRaw,
                    'width'    => $planWidths[$i] ?? '',
                    'customer' => $planCustomers[$i] ?? '',
                    'ref_no'   => $planRefs[$i] ?? '',
                ];
            }
        }

        try {
            $childrenUpdated = $this->motherModel->updateRecord($id, $_POST, $planRows);
            $this->redirect("mother_coil.php?success=update&children_updated={$childrenUpdated}");
        } catch (Throwable $e) {
            die("Update failed: " . htmlspecialchars($e->getMessage()));
        }
    }

    /**
     * Delete mother coil (delete_mother.php)
     */
    public function delete(): void
    {
        $this->requireRole(['slitting', 'mkl3']);

        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            $this->redirect('mother_coil.php?error=invalid_id');
        }

        try {
            if ($this->motherModel->deleteRecord($id)) {
                $this->redirect('mother_coil.php?success=3');
            } else {
                $this->redirect('mother_coil.php?error=not_found');
            }
        } catch (Throwable $e) {
            $this->redirect('mother_coil.php?error=delete_failed&msg=' . urlencode($e->getMessage()));
        }
    }

    /**
     * Simple legacy save (save_mother.php)
     */
    public function save(): void
    {
        try {
            $this->motherModel->create($_POST);
            $this->redirect('mother_coil.php?success=1');
        } catch (Throwable $e) {
            die("Save failed: " . htmlspecialchars($e->getMessage()));
        }
    }

    /**
     * Detail view (view_mother.php)
     */
    public function view(): void
    {
        $id = intval($_GET['id'] ?? 0);
        $data = $this->motherModel->findById($id);

        if (!$data) {
            die("<h3 style='color:red;text-align:center;margin-top:50px;'>Mother coil tidak dijumpai</h3>");
        }

        $this->render('mother_coil/view', [
            'data' => $data,
        ]);
    }

    /**
     * Mark printed beacon endpoint (mark_mother_printed.php)
     */
    public function markPrinted(): void
    {
        $id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id > 0) {
            $this->motherModel->markPrinted($id);
            http_response_code(204);
            exit;
        }
        http_response_code(400);
        exit;
    }

    /**
     * Raw Material - Available Stock (formerly raw_material.php)
     */
    public function rawMaterial(): void
    {
        $this->requireRole(['slitting', 'mkl3', 'admin']);

        $conn = \App\Core\Database::getConnection();

        $month = (int)date('m');
        $year  = (int)date('Y');

        $scanWarn = $_SESSION['scan_warning'] ?? null;
        unset($_SESSION['scan_warning']);

        $scanError   = $_SESSION['error']   ?? null;
        $scanSuccess = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);

        $available_result = $conn->query(
            "SELECT id, lot_no, coil_no, grade, width, length, status, source_type, date_in 
             FROM stock_raw_material 
             WHERE status='IN' 
             ORDER BY date_in DESC"
        );

        if ($available_result === false) {
            $available_rows  = [];
            $total_available = 0;
        } else {
            $available_rows  = $available_result->fetch_all(MYSQLI_ASSOC);
            $total_available = count($available_rows);
            $available_result->free();
        }

        $current_stock = (int)$conn->query(
            "SELECT COUNT(*) AS total FROM stock_raw_material WHERE status='IN'"
        )->fetch_assoc()['total'];

        $afterCutStock = (int)$conn->query(
            "SELECT COUNT(*) AS total FROM stock_raw_material 
             WHERE status='IN' AND source_type='slitting_cut_into_2'"
        )->fetch_assoc()['total'];

        $mtd_in = (int)$conn->query(
            "SELECT COUNT(*) AS total FROM stock_raw_material 
             WHERE MONTH(date_in) = $month AND YEAR(date_in) = $year"
        )->fetch_assoc()['total'];

        $mtd_out = (int)$conn->query(
            "SELECT COUNT(*) AS total FROM stock_raw_material 
             WHERE status = 'OUT' 
               AND MONTH(updated_at) = $month AND YEAR(updated_at) = $year"
        )->fetch_assoc()['total'];

        $mtd_in_mother = (int)$conn->query(
            "SELECT COUNT(*) AS total FROM stock_raw_material 
             WHERE source_type = 'mother_coil'
               AND MONTH(date_in) = $month AND YEAR(date_in) = $year"
        )->fetch_assoc()['total'];

        $mtd_in_leftover = (int)$conn->query(
            "SELECT COUNT(*) AS total FROM stock_raw_material 
             WHERE source_type = 'slitting_cut_into_2'
               AND MONTH(date_in) = $month AND YEAR(date_in) = $year"
        )->fetch_assoc()['total'];

        $this->render('raw_material/index', [
            'conn'            => $conn,
            'month'           => $month,
            'year'            => $year,
            'scanWarn'        => $scanWarn,
            'scanError'       => $scanError,
            'scanSuccess'     => $scanSuccess,
            'available_rows'  => $available_rows,
            'total_available' => $total_available,
            'current_stock'   => $current_stock,
            'afterCutStock'   => $afterCutStock,
            'mtd_in'          => $mtd_in,
            'mtd_out'         => $mtd_out,
            'mtd_in_mother'   => $mtd_in_mother,
            'mtd_in_leftover' => $mtd_in_leftover,
        ]);
    }

    /**
     * Raw Material Inventory & Scan (formerly raw_material_scan.php)
     */
    public function rawMaterialScan(): void
    {
        $this->requireRole(['slitting']);

        $conn = \App\Core\Database::getConnection();

        $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
        $year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');

        if ($month < 1 || $month > 12) { $month = (int)date('m'); }
        if ($year < 2020 || $year > 2030) { $year = (int)date('Y'); }

        $in = $conn->query("SELECT COUNT(*) AS total FROM mother_coil_audit_log 
                            WHERE action_type='IN' AND MONTH(performed_at)=$month AND YEAR(performed_at)=$year")
                            ->fetch_assoc()['total'];

        $out = $conn->query("SELECT COUNT(*) AS total FROM mother_coil_audit_log 
                             WHERE action_type='OUT' AND MONTH(performed_at)=$month AND YEAR(performed_at)=$year")
                             ->fetch_assoc()['total'];

        $stock = $conn->query("SELECT COUNT(*) AS total FROM mother_coil WHERE stock = 1")->fetch_assoc()['total'];

        $afterCutStock = $conn->query("SELECT COUNT(*) AS total FROM raw_material_log WHERE status='IN' AND action='cut_into_2'")
                                       ->fetch_assoc()['total'];

        $query = "SELECT log.*, mc.product, mc.grade, mc.coil_no, mc.lot_no, mc.width, mc.length 
                  FROM raw_material_log log
                  JOIN mother_coil mc ON log.mother_id = mc.id
                  WHERE (MONTH(log.date_in)=$month AND YEAR(log.date_in)=$year) 
                     OR (MONTH(log.date_out)=$month AND YEAR(log.date_out)=$year) 
                  ORDER BY log.id DESC";
        $result = $conn->query($query);

        $this->render('raw_material/scan', [
            'conn'          => $conn,
            'month'         => $month,
            'year'          => $year,
            'in'            => $in,
            'out'           => $out,
            'stock'         => $stock,
            'afterCutStock' => $afterCutStock,
            'result'        => $result,
        ]);
    }

    /**
     * Raw Material Excel Export (formerly raw_material_export.php)
     */
    public function rawMaterialExport(): void
    {
        $this->requireRole(['slitting']);

        require 'c:/laragon/www/Slitting_System_2.0/raw_material_export.php';
    }

    /**
     * Stock Raw Material Log (formerly stock_log.php)
     */
    public function stockLog(): void
    {
        $this->requireRole(['slitting', 'mkl3', 'admin']);

        $conn = \App\Core\Database::getConnection();

        $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
        $year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');

        if ($month < 1 || $month > 12) { $month = (int)date('m'); }
        if ($year < 2020 || $year > 2030) { $year = (int)date('Y'); }

        $stmt = $conn->prepare("SELECT id, lot_no, coil_no, grade, width, length, status, source_type, source_id, date_in, updated_at
                  FROM stock_raw_material
                  WHERE (MONTH(date_in) = ? AND YEAR(date_in) = ?)
                     OR (MONTH(updated_at) = ? AND YEAR(updated_at) = ?)
                  ORDER BY id DESC");
        $stmt->bind_param("iiii", $month, $year, $month, $year);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        $in_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM stock_raw_material
                                    WHERE status='IN' AND MONTH(date_in) = ? AND YEAR(date_in) = ?");
        $in_stmt->bind_param("ii", $month, $year);
        $in_stmt->execute();
        $in_count = $in_stmt->get_result()->fetch_assoc()['total'];
        $in_stmt->close();

        $out_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM stock_raw_material
                                     WHERE status='OUT' AND MONTH(updated_at) = ? AND YEAR(updated_at) = ?");
        $out_stmt->bind_param("ii", $month, $year);
        $out_stmt->execute();
        $out_count = $out_stmt->get_result()->fetch_assoc()['total'];
        $out_stmt->close();

        $current_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM stock_raw_material WHERE status='IN'");
        $current_stmt->execute();
        $current_stock = $current_stmt->get_result()->fetch_assoc()['total'];
        $current_stmt->close();

        $this->render('raw_material/stock_log', [
            'conn'          => $conn,
            'month'         => $month,
            'year'          => $year,
            'result'        => $result,
            'in_count'      => $in_count,
            'out_count'     => $out_count,
            'current_stock' => $current_stock,
        ]);
    }

    /**
     * Barcode scan action for mother coil / raw material (formerly scan_mother_action.php)
     */
    public function scanMotherAction(): void
    {
        $this->requireRole(['supervisor', 'slitting', 'mkl3', 'admin']);

        $conn = \App\Core\Database::getConnection();

        $qr = isset($_POST['qr']) ? $_POST['qr'] : '';
        $qr = preg_replace('/[[:cntrl:]]/', '', $qr);
        $qr = preg_replace('/^\][A-Za-z][0-9]/', '', $qr);
        $qr = preg_replace('/^[^\w]{0,6}(?=LOT=|COIL=)/i', '', $qr);
        $qr = preg_replace('/^[\w]{1,6}(?=LOT=|COIL=)/i',  '', $qr);
        $qr = trim($qr);

        if (empty($qr)) {
            $_SESSION['scan_warning'] = null;
            $this->redirect('raw_material.php');
            return;
        }

        $lot_no      = '';
        $coil_no     = '';
        $qr_width    = '';
        $qr_length   = '';
        $qr_id       = 0;

        if (strpos($qr, '=') !== false) {
            $pairs = [];
            foreach (explode(';', $qr) as $segment) {
                $segment = trim($segment);
                if (strpos($segment, '=') === false) continue;
                [$k, $v] = explode('=', $segment, 2);
                $pairs[strtoupper(trim($k))] = trim($v);
            }
            $lot_no    = $pairs['LOT']    ?? '';
            $coil_no   = $pairs['COIL']   ?? '';
            $qr_width  = $pairs['WIDTH']  ?? '';
            $qr_length = $pairs['LENGTH'] ?? '';
            $qr_id     = intval($pairs['ID'] ?? 0);
        } else {
            $tokens    = preg_split('/\s+/', $qr, 4);
            $lot_no    = trim($tokens[0] ?? '');
            $coil_no   = trim($tokens[1] ?? '');
            $qr_width  = trim($tokens[2] ?? '');
            $qr_length = trim($tokens[3] ?? '');
        }

        if ($qr_id > 0) {
            $id_check = $conn->query("SELECT id, lot_no, coil_no FROM stock_raw_material WHERE id=$qr_id AND status='IN'");
            if ($id_check && $id_check->num_rows > 0) {
                $st = $id_check->fetch_assoc();
                $_SESSION['success'] = "Stock item {$st['lot_no']} {$st['coil_no']} (ID: $qr_id) ready for slitting.";
                $this->redirect("add_slitting.php?stock_id=" . $st['id']);
                return;
            }
        }

        if (empty($lot_no) || empty($coil_no)) {
            $_SESSION['error'] = "Invalid QR format. Expected: LOT=xxx;COIL=xxx  or  'LotNo CoilNo'";
            $this->redirect('raw_material.php');
            return;
        }

        $lot_no      = $conn->real_escape_string($lot_no);
        $coil_no     = $conn->real_escape_string($coil_no);
        $float_width = floatval($qr_width);

        $width_clause = ($float_width > 0) ? " AND ABS(width - $float_width) < 0.5" : "";

        $leftover_check = $conn->query(
            "SELECT id, width FROM stock_raw_material
             WHERE lot_no='$lot_no'
               AND coil_no='$coil_no'
               AND source_type='slitting_cut_into_2'
               AND status='IN'
               $width_clause
             ORDER BY id DESC LIMIT 1"
        );

        if ((!$leftover_check || $leftover_check->num_rows === 0) && $float_width > 0) {
            $leftover_check = $conn->query(
                "SELECT id, width FROM stock_raw_material
                 WHERE lot_no='$lot_no'
                   AND coil_no='$coil_no'
                   AND source_type='slitting_cut_into_2'
                   AND status='IN'
                 ORDER BY id DESC LIMIT 1"
            );
        }

        if ($leftover_check && $leftover_check->num_rows > 0) {
            $leftover = $leftover_check->fetch_assoc();
            $_SESSION['success'] = "Leftover coil $lot_no $coil_no ready for slitting. Fill the form.";
            $this->redirect("add_slitting.php?stock_id=" . $leftover['id']);
            return;
        }

        $mother_width_clause = ($float_width > 0) ? " AND ABS(width - $float_width) < 0.5" : "";
        $result = $conn->query(
            "SELECT * FROM mother_coil WHERE lot_no='$lot_no' AND coil_no='$coil_no' $mother_width_clause"
        );
        if ((!$result || $result->num_rows === 0) && $float_width > 0) {
            $result = $conn->query(
                "SELECT * FROM mother_coil WHERE lot_no='$lot_no' AND coil_no='$coil_no'"
            );
        }
        if (!$result || $result->num_rows === 0) {
            $_SESSION['error'] = "Mother coil not found: $lot_no - $coil_no" . ($float_width > 0 ? " (Width: {$float_width}mm)" : "");
            $this->redirect('raw_material.php');
            return;
        }

        $mother    = $result->fetch_assoc();
        $mother_id = $mother['id'];

        $slit_check = $conn->prepare(
            "SELECT COUNT(*) AS cnt FROM slitting_product WHERE mother_id = ?"
        );
        $slit_check->bind_param("i", $mother_id);
        $slit_check->execute();
        $slit_count = (int)$slit_check->get_result()->fetch_assoc()['cnt'];
        $slit_check->close();

        if ($slit_count > 0) {
            $slit_rows = $conn->prepare(
                "SELECT lot_no, coil_no, roll_no, width, actual_length, length, status
                 FROM slitting_product
                 WHERE mother_id = ?
                   AND (is_voided = 0 OR is_voided IS NULL)
                 ORDER BY id ASC
                 LIMIT 10"
            );
            $slit_rows->bind_param("i", $mother_id);
            $slit_rows->execute();
            $produced = $slit_rows->get_result()->fetch_all(MYSQLI_ASSOC);
            $slit_rows->close();

            $first_slit = $conn->prepare(
                "SELECT MIN(date_in) AS first_date FROM slitting_product WHERE mother_id = ?"
            );
            $first_slit->bind_param("i", $mother_id);
            $first_slit->execute();
            $first_date = $first_slit->get_result()->fetch_assoc()['first_date'];
            $first_slit->close();

            $_SESSION['scan_warning'] = [
                'type'       => 'already_used',
                'lot_no'     => $lot_no,
                'coil_no'    => $coil_no,
                'product'    => $mother['product'] ?? '',
                'slit_count' => $slit_count,
                'first_date' => $first_date,
                'produced'   => $produced,
                'mother_id'  => $mother_id,
            ];

            $this->redirect("raw_material.php?warn=already_used");
            return;
        }

        $conn->begin_transaction();
        try {
            $stock_check_result = $conn->query(
                "SELECT id, status FROM stock_raw_material
                 WHERE lot_no='$lot_no' AND coil_no='$coil_no' AND source_type='mother_coil'
                 ORDER BY id DESC LIMIT 1"
            );

            if ($stock_check_result && $stock_check_result->num_rows > 0) {
                $existing_stock = $stock_check_result->fetch_assoc();
                $stock_id       = $existing_stock['id'];

                if ($existing_stock['status'] === 'IN') {
                    $conn->commit();
                    $_SESSION['success'] = "Mother coil $lot_no-$coil_no ready for slitting. Fill the form.";
                    $this->redirect("add_slitting.php?stock_id=$stock_id");
                    return;
                } else {
                    $conn->query("UPDATE stock_raw_material SET status='IN', updated_at=NOW() WHERE id=$stock_id");
                    $conn->query("UPDATE mother_coil SET status='IN', stock=1 WHERE id=$mother_id");
                    $conn->query("INSERT INTO mother_coil_audit_log (mother_id, action_type, performed_at, remark)
                                  VALUES ($mother_id, 'SCAN_IN', NOW(), 'Toggled back IN: $lot_no $coil_no')");
                    $conn->commit();
                    $_SESSION['message'] = "Mother coil $lot_no-$coil_no toggled back to IN.";
                    $this->redirect("raw_material.php");
                    return;
                }

            } else {
                $grade  = $conn->real_escape_string($mother['grade']  ?? '');
                $width  = (float)$mother['width'];
                $length = (float)$mother['length'];

                $conn->query(
                    "INSERT INTO stock_raw_material
                         (lot_no, coil_no, grade, width, length, status, source_type, source_id, date_in)
                     VALUES
                         ('$lot_no','$coil_no','$grade',$width,$length,'IN','mother_coil',$mother_id,NOW())"
                );
                $conn->query(
                    "UPDATE mother_coil
                     SET status='IN', stock=1, date_in=NOW(), scan_in_count = scan_in_count + 1
                     WHERE id=$mother_id"
                );
                $conn->query(
                    "INSERT INTO mother_coil_audit_log (mother_id, action_type, performed_at, remark)
                     VALUES ($mother_id, 'SCAN_IN', NOW(), 'Scanned IN: $lot_no $coil_no')"
                );
                $conn->query(
                    "INSERT INTO raw_material_log (mother_id, status, action, date_in, remark)
                     VALUES ($mother_id, 'IN', 'normal', NOW(), 'Scanned IN: $lot_no $coil_no')"
                );
                $conn->commit();
                $_SESSION['success'] = "Mother coil $lot_no-$coil_no scanned IN. Ready for use.";
                $this->redirect("raw_material.php");
                return;
            }

        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['error'] = "Error: " . $e->getMessage();
            $this->redirect("raw_material.php");
            return;
        }
    }
}

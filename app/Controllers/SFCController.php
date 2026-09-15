<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Models\SFC;
use mysqli;
use Exception;

class SFCController extends Controller
{
    private SFC $sfcModel;
    private mysqli $conn;

    public function __construct()
    {
        parent::__construct();
        $this->sfcModel = new SFC();
        $this->conn = Database::getConnection();
    }

    /**
     * SFC Inventory Management Dashboard (formerly sfc.php)
     */
    public function index(): void
    {
        $this->requireRole(['slitting', 'mkl3']);

        $conn = $this->conn;

        // Handle Add Initial Stock
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_initial_stock'])) {
            $products = $_POST['init_product'] ?? [];
            $lots     = $_POST['init_lot_no']  ?? [];
            $coils    = $_POST['init_coil_no'] ?? [];
            $rolls    = $_POST['init_roll_no'] ?? [];
            $widths   = $_POST['init_width']   ?? [];
            $lengths  = $_POST['init_length']  ?? [];

            try {
                $insertedCount = $this->sfcModel->addInitialStock($products, $lots, $coils, $rolls, $widths, $lengths);
                $this->redirect("sfc.php?success=1&msg=initial_stock&count=" . $insertedCount);
                return;
            } catch (Exception $e) {
                die("<div style='color:red; font-family:sans-serif; padding:20px; border:1px solid red; background:#fff5f5;'>
                        <h2>Could not add initial stock</h2>
                        <p><strong>Reason:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
                        <button onclick='history.back()' style='padding:8px 16px; margin-top:10px;'>Go Back and Correct</button>
                     </div>");
            }
        }

        // Handle Action (DELETE, RECOIL, RESLIT, SELL)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sfc_id']) && isset($_POST['action'])) {
            $sfcId  = (int)$_POST['sfc_id'];
            $action = $_POST['action'];

            try {
                $res = $this->sfcModel->processAction($sfcId, $action, $_SESSION['role'] ?? 'system');
                if ($res === 'deleted') {
                    $this->redirect("sfc.php?success=1&msg=deleted");
                } elseif ($res === 'sfc_sold') {
                    $this->redirect("finish_product.php?filter=stock&success=sfc_sold");
                } else {
                    $this->redirect("sfc.php?success=1");
                }
                return;
            } catch (Exception $e) {
                die("<div style='color:red; font-family:sans-serif; padding:20px; border:1px solid red; background:#fff5f5;'>
                        <h2>Action Error</h2>
                        <p><strong>Reason:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
                        <button onclick='history.back()' style='padding:8px 16px; margin-top:10px;'>Go Back</button>
                     </div>");
            }
        }

        // PIN verify via AJAX
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_pin'])) {
            $enteredPin    = trim($_POST['verify_pin']);
            $supervisorPin = '1234';
            $this->json(['success' => ($enteredPin === $supervisorPin)]);
            return;
        }

        // Search / fetch SFC rows
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $show_used = isset($_GET['show_used']) ? (int)$_GET['show_used'] : 0;

        if ($search !== '') {
            $baseWhere = $show_used ? "is_deleted = 0 AND " : "date_out IS NULL AND is_deleted = 0 AND ";
            $query = "SELECT * FROM sfc WHERE {$baseWhere}(
                        sfc_id LIKE ? OR product LIKE ? OR lot_no LIKE ? OR coil_no LIKE ? OR roll_no LIKE ?
                      ) ORDER BY date_created DESC";
            $stmt = $conn->prepare($query);
            $like = "%$search%";
            $stmt->bind_param("sssss", $like, $like, $like, $like, $like);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $where  = $show_used ? "WHERE is_deleted = 0" : "WHERE date_out IS NULL AND is_deleted = 0";
            $result = $conn->query("SELECT * FROM sfc $where ORDER BY date_created DESC");
        }

        $total_active = (int)$conn->query("SELECT COUNT(*) AS c FROM sfc WHERE date_out IS NULL AND is_deleted = 0")->fetch_assoc()['c'];
        $total_used   = (int)$conn->query("SELECT COUNT(*) AS c FROM sfc WHERE date_out IS NOT NULL AND is_deleted = 0")->fetch_assoc()['c'];

        $this->render('sfc/index', [
            'conn'         => $conn,
            'sfcModel'     => $this->sfcModel,
            'result'       => $result,
            'search'       => $search,
            'show_used'    => $show_used,
            'total_active' => $total_active,
            'total_used'   => $total_used,
        ]);
    }

    /**
     * SFC Origin Tracking Report (formerly sfc_tracking.php)
     */
    public function tracking(): void
    {
        $this->requireRole(['slitting', 'mkl3']);

        $conn = $this->conn;

        $month  = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
        $year   = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';

        if ($month < 1 || $month > 12)   { $month = (int)date('m'); }
        if ($year < 2000 || $year > 2100) { $year  = (int)date('Y'); }

        $slittingResult  = $this->sfcModel->runSfcTrackingQuery('slitting_product',  $month, $year, $search);
        $recoilingResult = $this->sfcModel->runSfcTrackingQuery('recoiling_product', $month, $year, $search);
        $reslitResult    = $this->sfcModel->runSfcTrackingQuery('reslit_product',    $month, $year, $search);

        $this->render('sfc/tracking', [
            'conn'            => $conn,
            'month'           => $month,
            'year'            => $year,
            'search'          => $search,
            'slittingResult'  => $slittingResult,
            'recoilingResult' => $recoilingResult,
            'reslitResult'    => $reslitResult,
        ]);
    }

    /**
     * Print single SFC Label (formerly print_sfc.php)
     */
    public function printSfc(): void
    {
        $this->requireRole(['slitting', 'mkl3']);

        require 'c:/laragon/www/Slitting_System_2.0/print_sfc.php';
    }

    /**
     * Bulk print SFC Labels console (formerly bulk_print_sfc.php)
     */
    public function bulkPrintSfc(): void
    {
        $this->requireRole(['slitting', 'mkl3']);

        require 'c:/laragon/www/Slitting_System_2.0/bulk_print_sfc.php';
    }
}

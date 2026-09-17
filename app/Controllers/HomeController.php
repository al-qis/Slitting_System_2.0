<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use mysqli;

class HomeController extends Controller
{
    private mysqli $conn;

    public function __construct()
    {
        parent::__construct();
        $this->conn = Database::getConnection();
    }

    /**
     * Main Dashboard (formerly index.php)
     */
    public function index(): void
    {
        $this->requireRole(['slitting']);

        $conn = $this->conn;
        $month = (int)date('m');
        $year  = (int)date('Y');

        // ── 1. Raw Material Available Stock ─────────────────────────
        $raw_stock = (int)$conn->query("
            SELECT COUNT(*) AS total FROM stock_raw_material WHERE status='IN'
        ")->fetch_assoc()['total'];

        $raw_mother = (int)$conn->query("
            SELECT COUNT(*) AS total FROM stock_raw_material 
            WHERE status='IN' AND source_type='mother_coil'
        ")->fetch_assoc()['total'];

        $raw_leftover = (int)$conn->query("
            SELECT COUNT(*) AS total FROM stock_raw_material 
            WHERE status='IN' AND source_type='slitting_cut_into_2'
        ")->fetch_assoc()['total'];

        // ── 2. SFC Active Items ──────────────────────────────────────
        $sfc_total = (int)$conn->query("
            SELECT COUNT(*) AS total FROM sfc WHERE date_out IS NULL
        ")->fetch_assoc()['total'];

        // ── 3. Finish Good Stock ─────────────────────────────────────
        $fg_stock = (int)$conn->query("
            SELECT COUNT(*) AS total FROM slitting_product 
            WHERE status='IN' AND stock_counted=1
            AND (is_recoiled=0 OR is_recoiled IS NULL)
            AND (is_reslitted=0 OR is_reslitted IS NULL)
        ")->fetch_assoc()['total'];

        $fg_pending = (int)$conn->query("
            SELECT COUNT(*) AS total FROM slitting_product 
            WHERE status='IN' AND is_completed=0
            AND (is_recoiled=0 OR is_recoiled IS NULL)
            AND (is_reslitted=0 OR is_reslitted IS NULL)
        ")->fetch_assoc()['total'];

        $fg_waiting = (int)$conn->query("
            SELECT COUNT(*) AS total FROM slitting_product WHERE status='WAITING'
        ")->fetch_assoc()['total'];

        // ── 4. Delivered This Month ──────────────────────────────────
        $delivered = (int)$conn->query("
            SELECT COUNT(*) AS total FROM slitting_product 
            WHERE status='DELIVERED'
            AND MONTH(delivered_at)=$month AND YEAR(delivered_at)=$year
        ")->fetch_assoc()['total'];

        $delivered_approved = (int)$conn->query("
            SELECT COUNT(*) AS total FROM slitting_product WHERE status='APPROVED'
        ")->fetch_assoc()['total'];

        $mtd_in_raw = (int)$conn->query("
            SELECT COUNT(*) AS total FROM stock_raw_material
            WHERE MONTH(date_in)=$month AND YEAR(date_in)=$year
        ")->fetch_assoc()['total'];

        // ── Recent rolls ─────────────────────────────────────────────
        $recent = $conn->query("
            SELECT lot_no, coil_no, roll_no, width, actual_length, status, date_in
            FROM slitting_product
            WHERE (is_recoiled=0 OR is_recoiled IS NULL)
              AND (is_reslitted=0 OR is_reslitted IS NULL)
            ORDER BY id DESC LIMIT 8
        ");
        $recent_rows = [];
        if ($recent) {
            while ($r = $recent->fetch_assoc()) {
                $recent_rows[] = $r;
            }
        }

        $this->render('dashboard/index', [
            'conn'               => $conn,
            'month'              => $month,
            'year'               => $year,
            'raw_stock'          => $raw_stock,
            'raw_mother'         => $raw_mother,
            'raw_leftover'       => $raw_leftover,
            'sfc_total'          => $sfc_total,
            'fg_stock'           => $fg_stock,
            'fg_pending'         => $fg_pending,
            'fg_waiting'         => $fg_waiting,
            'delivered'          => $delivered,
            'delivered_approved' => $delivered_approved,
            'mtd_in_raw'         => $mtd_in_raw,
            'recent_rows'        => $recent_rows,
        ]);
    }
}

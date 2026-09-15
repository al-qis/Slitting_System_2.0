<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use mysqli;

class ReportController extends Controller
{
    private mysqli $conn;

    public function __construct()
    {
        parent::__construct();
        $this->conn = Database::getConnection();
    }

    /**
     * Monthly Production & In/Out Report (formerly report.php)
     */
    public function monthly(): void
    {
        $this->requireRole(['slitting']);

        $conn = $this->conn;

        $monthly_summary_result = $conn->query("
            SELECT DATE_FORMAT(performed_at, '%Y-%m') AS month_year, COUNT(*) AS total_count 
            FROM mother_coil_audit_log 
            WHERE action_type IN ('IN','SCAN_IN') 
            GROUP BY month_year 
            ORDER BY month_year DESC
        ");
        $raw_material_monthly_summary = [];
        if ($monthly_summary_result) {
            while ($row = $monthly_summary_result->fetch_assoc()) {
                $raw_material_monthly_summary[] = $row;
            }
        }

        $selected_month = isset($_POST['month']) ? $_POST['month'] : date('m');
        $selected_year  = isset($_POST['year'])  ? $_POST['year']  : date('Y');

        $raw_material_data              = [];
        $slitting_product_delivered_data = [];

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $stmt_in = $conn->prepare("
                SELECT audit.performed_at AS date_in, mc.coil_no, mc.product, mc.lot_no, mc.width, mc.length 
                FROM mother_coil_audit_log audit
                JOIN mother_coil mc ON audit.mother_id = mc.id
                WHERE MONTH(audit.performed_at) = ? AND YEAR(audit.performed_at) = ? AND audit.action_type IN ('IN','SCAN_IN')
            ");
            $stmt_in->bind_param("ss", $selected_month, $selected_year);
            $stmt_in->execute();
            $result_in = $stmt_in->get_result();
            while ($row = $result_in->fetch_assoc()) $raw_material_data[] = $row;
            $stmt_in->close();

            $stmt_out = $conn->prepare("SELECT * FROM slitting_product WHERE MONTH(delivered_at) = ? AND YEAR(delivered_at) = ? AND status='DELIVERED'");
            $stmt_out->bind_param("ss", $selected_month, $selected_year);
            $stmt_out->execute();
            $result_out = $stmt_out->get_result();
            while ($row = $result_out->fetch_assoc()) $slitting_product_delivered_data[] = $row;
            $stmt_out->close();
        }

        $this->render('report/monthly', [
            'conn'                            => $conn,
            'raw_material_monthly_summary'   => $raw_material_monthly_summary,
            'selected_month'                  => $selected_month,
            'selected_year'                   => $selected_year,
            'raw_material_data'               => $raw_material_data,
            'slitting_product_delivered_data' => $slitting_product_delivered_data,
        ]);
    }

    /**
     * Process Log Viewer (formerly process_log_viewer.php)
     */
    public function processLog(): void
    {
        $this->requireRole(['slitting', 'mkl3', 'admin', 'officer', 'qc']);

        $conn = $this->conn;

        $filter_type = $_GET['entity_type'] ?? '';
        $filter_lot  = trim($_GET['lot_no']  ?? '');
        $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
        $year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');

        $sql = "SELECT
                    pl.*,
                    mc.lot_no    AS mc_lot,
                    mc.coil_no   AS mc_coil,
                    mc.product   AS mc_product,
                    sp.lot_no    AS sp_lot,
                    sp.coil_no   AS sp_coil,
                    sp.roll_no   AS sp_roll,
                    rp.lot_no    AS rp_lot,
                    rp.coil_no   AS rp_coil,
                    rp.roll_no   AS rp_roll,
                    rs.lot_no    AS rs_lot,
                    rs.coil_no   AS rs_coil,
                    rs.roll_no   AS rs_roll,
                    sfc_t.lot_no  AS sfc_lot,
                    sfc_t.coil_no AS sfc_coil,
                    sfc_t.roll_no AS sfc_roll
                FROM process_log pl
                LEFT JOIN mother_coil       mc    ON mc.id        = pl.mother_id
                LEFT JOIN slitting_product  sp    ON sp.id        = pl.entity_id AND pl.entity_type = 'slitting'
                LEFT JOIN recoiling_product rp    ON rp.id        = pl.entity_id AND pl.entity_type = 'recoiling'
                LEFT JOIN reslit_product    rs    ON rs.id        = pl.entity_id AND pl.entity_type = 'reslit'
                LEFT JOIN sfc               sfc_t ON sfc_t.sfc_id = pl.entity_id AND pl.entity_type = 'sfc'
                WHERE MONTH(pl.performed_at) = ? AND YEAR(pl.performed_at) = ?";

        $params = [$month, $year];
        $types  = "ii";

        if ($filter_type !== '') {
            $sql     .= " AND pl.entity_type = ?";
            $params[] = $filter_type;
            $types   .= "s";
        }

        if ($filter_lot !== '') {
            $like     = '%' . $filter_lot . '%';
            $sql     .= " AND (
                            mc.lot_no    LIKE ? OR
                            sp.lot_no    LIKE ? OR
                            rp.lot_no    LIKE ? OR
                            rs.lot_no    LIKE ? OR
                            sfc_t.lot_no LIKE ?
                          )";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types   .= "sssss";
        }

        $sql .= " ORDER BY pl.performed_at DESC LIMIT 500";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $this->render('report/process_log', [
            'conn'        => $conn,
            'logs'        => $logs,
            'filter_type' => $filter_type,
            'filter_lot'  => $filter_lot,
            'month'       => $month,
            'year'        => $year,
        ]);
    }
}

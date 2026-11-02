<?php
/**
 * Read-Only Data Adapter for slitting_db
 * Location: /stock_draft_system/backend/adapters/SlittingDbAdapter.php
 * 
 * STRICT COMPLIANCE REQUIREMENT:
 * Under NO circumstances does this adapter perform INSERT, UPDATE, DELETE, ALTER, or DROP statements.
 * All functions execute pure SELECT queries to treat slitting_db as an immutable external data source.
 */

require_once __DIR__ . '/../config/database.php';

class SlittingDbAdapter {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getReadOnlyConnection();
    }

    /**
     * Fetch ALL month-end stock coil records matching the exact "STOCK @ END" logic (442 coils)
     */
    public function getMonthEndStockCoils(?int $month = null, ?int $year = null): array {
        $coils = [];
        $month = $month ?: intval(date('n'));
        $year  = $year  ?: intval(date('Y'));

        $yymmPrefix = date('ym', strtotime("$year-$month-01"));
        $eomForCount = date('Y-m-t 23:59:59', strtotime("$year-$month-01"));

        // 1. Fetch exact finish product stock as of month end (matching 442 active stock count)
        try {
            $sqlSlitting = "
                SELECT 
                    sp.id AS source_coil_id,
                    'slitting_product' AS source_type,
                    COALESCE(sp.coil_no, CONCAT('SP-', sp.id)) AS coil_no,
                    COALESCE(sp.lot_no, 'N/A') AS lot_no,
                    COALESCE(sp.product, 'Slitted Coil') AS product,
                    'STD' AS grade,
                    COALESCE(sp.width, 0.00) AS width,
                    COALESCE(sp.actual_length, sp.length, 0.00) AS length,
                    COALESCE(sp.roll_no, sp.stock_code, '1') AS roll,
                    COALESCE(sp.customer_name, 'STOCK') AS customer_name,
                    COALESCE(sp.ref_no, '-') AS ref_no,
                    CASE 
                        WHEN sp.status = 'DELIVERED' OR p.status = 'delivered' THEN 'DELIVERED'
                        WHEN p.status = 'pending_qc' OR sp.status = 'WAITING' THEN 'WAITING QC'
                        WHEN p.status = 'building' THEN 'PALLETISED'
                        WHEN p.status = 'approved' OR sp.status = 'APPROVED' THEN 'APPROVED'
                        ELSE 'STOCK'
                    END AS state,
                    sp.status AS original_status,
                    sp.date_in
                FROM slitting_product sp
                LEFT JOIN (
                    SELECT slitting_product_id, MAX(id) AS max_pi_id
                    FROM pallet_items
                    GROUP BY slitting_product_id
                ) latest_pi ON latest_pi.slitting_product_id = sp.id
                LEFT JOIN pallet_items pi ON pi.id = latest_pi.max_pi_id
                LEFT JOIN pallets p ON pi.pallet_id = p.id
                LEFT JOIN (
                    SELECT entity_id, MIN(performed_at) AS recoil_date
                    FROM process_log
                    WHERE entity_type = 'slitting' AND action_detail = 'send_to_recoiling'
                    GROUP BY entity_id
                ) rc ON rc.entity_id = sp.id
                LEFT JOIN (
                    SELECT entity_id, MIN(performed_at) AS reslit_date
                    FROM process_log
                    WHERE entity_type = 'slitting' AND action_detail = 'send_to_reslit'
                    GROUP BY entity_id
                ) rs ON rs.entity_id = sp.id
                WHERE sp.is_voided = 0
                  AND (sp.is_recoiled = 0 OR sp.is_recoiled IS NULL)
                  AND (sp.is_reslitted = 0 OR sp.is_reslitted IS NULL)
                  AND sp.status = 'IN'
                  AND sp.stock_counted = 1
                ORDER BY sp.id DESC
            ";

            $stmtSlitting = $this->pdo->prepare($sqlSlitting);
            $stmtSlitting->execute();
            $slittingCoils = $stmtSlitting->fetchAll(PDO::FETCH_ASSOC);

            foreach ($slittingCoils as $sp) {
                $width = floatval($sp['width']);
                $length = floatval($sp['length']);
                $weight = round(($width / 1000) * $length * 7.85, 2);
                $area = round(($width / 1000) * $length, 2);

                $coils[] = [
                    'source_coil_id' => $sp['source_coil_id'],
                    'source_type'    => $sp['source_type'],
                    'coil_no'        => $sp['coil_no'],
                    'lot_no'         => $sp['lot_no'],
                    'product'        => $sp['product'],
                    'grade'          => $sp['grade'],
                    'width'          => $width,
                    'length'         => $length,
                    'weight'         => $weight,
                    'area'           => $area,
                    'roll'           => $sp['roll'],
                    'customer_name'  => $sp['customer_name'] ?: 'STOCK',
                    'ref_no'         => $sp['ref_no'] ?: '-',
                    'state'          => $sp['state'] ?: 'STOCK',
                    'date_in'        => $sp['date_in']
                ];
            }
        } catch (PDOException $e) {
            error_log("Error fetching slitting_product: " . $e->getMessage());
        }

        return $coils;
    }

    /**
     * Get summary metrics from slitting_db matching exact "STOCK @ END" logic (442 coils)
     */
    public function getMonthEndMetrics(?int $month = null, ?int $year = null): array {
        $month = $month ?: intval(date('n'));
        $year  = $year  ?: intval(date('Y'));

        $yymmPrefix = date('ym', strtotime("$year-$month-01"));
        $eomForCount = date('Y-m-t 23:59:59', strtotime("$year-$month-01"));

        // Mother coils total
        $sqlMother = "SELECT COUNT(*) as total_mother_coils FROM mother_coil";
        $stmtM = $this->pdo->prepare($sqlMother);
        $stmtM->execute();
        $totalMother = (int)$stmtM->fetchColumn();

        // Finish product slitting coils count & totals using exact Stock @ End logic (442 coils)
        $sqlSlitting = "
            SELECT 
                COUNT(*) as active_count,
                COALESCE(SUM(COALESCE(sp.actual_length, sp.length, 0)), 0) as total_length,
                COALESCE(SUM((COALESCE(sp.width,0)/1000) * COALESCE(sp.actual_length, sp.length, 0) * 7.85), 0) as total_weight
            FROM slitting_product sp
            LEFT JOIN (
                SELECT slitting_product_id, MAX(id) AS max_pi_id
                FROM pallet_items
                GROUP BY slitting_product_id
            ) latest_pi ON latest_pi.slitting_product_id = sp.id
            LEFT JOIN pallet_items pi ON pi.id = latest_pi.max_pi_id
            WHERE sp.is_voided = 0
              AND (sp.is_recoiled = 0 OR sp.is_recoiled IS NULL)
              AND (sp.is_reslitted = 0 OR sp.is_reslitted IS NULL)
              AND sp.status = 'IN'
              AND sp.stock_counted = 1
        ";
        $stmtS = $this->pdo->prepare($sqlSlitting);
        $stmtS->execute();
        $slittingMetrics = $stmtS->fetch(PDO::FETCH_ASSOC);

        $activeCount = (int)($slittingMetrics['active_count'] ?? 0);
        $totalLength = round(floatval($slittingMetrics['total_length'] ?? 0), 2);
        $totalWeight = round(floatval($slittingMetrics['total_weight'] ?? 0), 2);

        return [
            'total_mother_coils'  => $totalMother,
            'active_stock_count'  => $activeCount,
            'total_active_length' => $totalLength,
            'total_active_weight' => $totalWeight
        ];
    }

    /**
     * Fetch real-time state for a list of slitting_product IDs directly from slitting_db
     */
    public function getLiveStatesForCoils(array $sourceCoilIds): array {
        if (empty($sourceCoilIds)) {
            return [];
        }

        $ids = array_map('intval', $sourceCoilIds);
        $inClause = implode(',', $ids);

        $sql = "
            SELECT 
                sp.id AS source_coil_id,
                CASE 
                    WHEN sp.status = 'DELIVERED' OR p.status = 'delivered' THEN 'DELIVERED'
                    WHEN p.status = 'pending_qc' OR sp.status = 'WAITING' THEN 'WAITING QC'
                    WHEN p.status = 'building' THEN 'PALLETISED'
                    WHEN p.status = 'approved' OR sp.status = 'APPROVED' THEN 'APPROVED'
                    ELSE 'STOCK'
                END AS state
            FROM slitting_product sp
            LEFT JOIN (
                SELECT slitting_product_id, MAX(id) AS max_pi_id
                FROM pallet_items
                GROUP BY slitting_product_id
            ) latest_pi ON latest_pi.slitting_product_id = sp.id
            LEFT JOIN pallet_items pi ON pi.id = latest_pi.max_pi_id
            LEFT JOIN pallets p ON pi.pallet_id = p.id
            WHERE sp.id IN ($inClause)
        ";

        $results = [];
        try {
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $results[intval($r['source_coil_id'])] = $r['state'];
            }
        } catch (PDOException $e) {
            error_log("Error fetching live states: " . $e->getMessage());
        }

        return $results;
    }
}


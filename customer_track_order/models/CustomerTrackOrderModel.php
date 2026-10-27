<?php
/**
 * CustomerTrackOrderModel.php
 * Model for Customer Order Tracking Portal
 * Location: /customer_track_order/models/CustomerTrackOrderModel.php
 */

class CustomerTrackOrderModel
{
    private mysqli $conn;
    private static bool $hasSynced = false;

    /**
     * Base SQL condition for valid, non-voided, non-consumed rolls
     */
    private const BASE_WHERE = "sp.is_voided = 0 AND (sp.is_reslitted = 0 OR sp.is_reslitted IS NULL) AND (sp.is_recoiled = 0 OR sp.is_recoiled IS NULL)";

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Sanitize string input helper
     */
    /**
     * Automatically synchronizes missing manual rolls from slitting_product into slitting_plans
     */
    public function autoSyncMissingPlans(): int
    {
        $sql = "
            INSERT INTO slitting_plans (mother_coil_id, roll_seq, planned_width, customer_name, ref_no, sort_order)
            SELECT 
                sp.mother_id,
                sp.roll_no,
                MAX(sp.width),
                MAX(sp.customer_name),
                sp.ref_no,
                0
            FROM slitting_product sp
            LEFT JOIN slitting_plans p 
                ON p.mother_coil_id = sp.mother_id 
               AND p.roll_seq = sp.roll_no 
               AND p.ref_no = sp.ref_no
            WHERE p.id IS NULL
              AND sp.mother_id IS NOT NULL 
              AND sp.mother_id > 0
              AND sp.roll_no IS NOT NULL 
              AND sp.roll_no != ''
              AND sp.ref_no IS NOT NULL 
              AND sp.ref_no != ''
              AND sp.ref_no != 'STOCK'
              AND (sp.is_voided = 0 OR sp.is_voided IS NULL)
            GROUP BY sp.mother_id, sp.roll_no, sp.ref_no
        ";
        $this->conn->query($sql);
        return $this->conn->affected_rows;
    }

    public function cleanInput(?string $val): string
    {
        return trim((string)($val ?? ''));
    }

    /**
     * Format roll number (e.g. R1 -> R-1)
     */
    public function formatRollNo(?string $roll): string
    {
        if (!$roll) {
            return '—';
        }
        $roll = (string)$roll;
        if (preg_match('/^R\d+/i', $roll)) {
            return 'R-' . ltrim(substr($roll, 1), '-');
        }
        return htmlspecialchars($roll);
    }

    /**
     * Get distinct customers for dropdown and quick-filter pills
     */
    public function getCustomerList(): array
    {
        $customerList = [];
        $query = "
            SELECT DISTINCT customer_name 
            FROM slitting_product 
            WHERE customer_name IS NOT NULL 
              AND customer_name != '' 
              AND customer_name != 'STOCK'
              AND is_voided = 0
            ORDER BY customer_name ASC
        ";

        $res = $this->conn->query($query);
        if ($res) {
            while ($cRow = $res->fetch_assoc()) {
                $customerList[] = $cRow['customer_name'];
            }
        }

        return $customerList;
    }

    /**
     * Fetch single order deep-dive tracking data by Sales Order / Ref No
     */
    public function getSingleOrder(string $searchSo): ?array
    {
        $searchSo = trim($searchSo);
        if ($searchSo === '') {
            return null;
        }
        if (!self::$hasSynced) {
            $this->autoSyncMissingPlans();
            self::$hasSynced = true;
        }

        $baseWhere = self::BASE_WHERE;
        $orderRolls = [];

        // 1. Try exact match first
        $stmtRolls = $this->conn->prepare("
            SELECT 
                sp.id,
                sp.customer_name,
                sp.ref_no,
                sp.product,
                sp.lot_no,
                sp.coil_no,
                sp.roll_no,
                sp.width,
                sp.length,
                sp.actual_length,
                sp.status,
                sp.stock_counted,
                sp.is_completed,
                sp.date_in,
                sp.date_out,
                sp.delivered_at,
                sp.delivered_by,
                sp.qc_comment,
                sp.mother_id,
                sp.source,
                sp.original_source,
                sp.recoiling_id,
                sp.is_reslitted,
                sp.cut_type,
                mc.grade,
                pi.pallet_id,
                p.pallet_no,
                p.status AS pallet_status,
                p.delivered_at AS pallet_delivered_at
            FROM slitting_product sp
            LEFT JOIN mother_coil mc ON mc.id = sp.mother_id
            LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
            LEFT JOIN pallets p ON p.id = pi.pallet_id
            WHERE {$baseWhere}
              AND sp.ref_no = ?
            ORDER BY 
                CASE sp.status WHEN 'DELIVERED' THEN 1 ELSE 0 END ASC,
                sp.roll_no ASC,
                sp.id ASC
        ");

        if ($stmtRolls) {
            $stmtRolls->bind_param("s", $searchSo);
            $stmtRolls->execute();
            $rollsResult = $stmtRolls->get_result();
            while ($r = $rollsResult->fetch_assoc()) {
                $orderRolls[] = $r;
            }
            $stmtRolls->close();
        }

        // 2. Fallback to LIKE search if exact match yielded no rows
        if (empty($orderRolls)) {
            $likeSo = '%' . $searchSo . '%';
            $stmtRollsLike = $this->conn->prepare("
                SELECT 
                    sp.id,
                    sp.customer_name,
                    sp.ref_no,
                    sp.product,
                    sp.lot_no,
                    sp.coil_no,
                    sp.roll_no,
                    sp.width,
                    sp.length,
                    sp.actual_length,
                    sp.status,
                    sp.stock_counted,
                    sp.is_completed,
                    sp.date_in,
                    sp.date_out,
                    sp.delivered_at,
                    sp.delivered_by,
                    sp.qc_comment,
                    sp.mother_id,
                    sp.source,
                    sp.original_source,
                    sp.recoiling_id,
                    sp.is_reslitted,
                    sp.cut_type,
                    mc.grade,
                    pi.pallet_id,
                    p.pallet_no,
                    p.status AS pallet_status,
                    p.delivered_at AS pallet_delivered_at
                FROM slitting_product sp
                LEFT JOIN mother_coil mc ON mc.id = sp.mother_id
                LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                LEFT JOIN pallets p ON p.id = pi.pallet_id
                WHERE {$baseWhere}
                  AND sp.ref_no LIKE ?
                ORDER BY 
                    CASE sp.status WHEN 'DELIVERED' THEN 1 ELSE 0 END ASC,
                    sp.roll_no ASC,
                    sp.id ASC
            ");

            if ($stmtRollsLike) {
                $stmtRollsLike->bind_param("s", $likeSo);
                $stmtRollsLike->execute();
                $likeRes = $stmtRollsLike->get_result();
                while ($r = $likeRes->fetch_assoc()) {
                    $orderRolls[] = $r;
                }
                $stmtRollsLike->close();
            }
        }

        if (empty($orderRolls)) {
            return null;
        }

        // 3. Compute Single Order Aggregation & Journey Stage
        $totalRolls      = count($orderRolls);
        $deliveredRolls  = 0;
        $approvedRolls   = 0;
        $waitingRolls    = 0;
        $inProdRolls     = 0;
        $initialStockCount = 0;
        $recoilCount       = 0;
        $reslitCount       = 0;
        $totalMeters     = 0.0;
        $customerNames   = [];
        $uniqueProducts  = [];
        $uniquePallets   = [];
        $earliestDate    = null;
        $latestDelivered = null;
        $actualRefNo     = $orderRolls[0]['ref_no'];

        foreach ($orderRolls as $roll) {
            $status = strtoupper($roll['status'] ?? 'IN');
            $stockCounted = (int)($roll['stock_counted'] ?? 0);
            $meters = (float)($roll['actual_length'] ?: $roll['length'] ?: 0);
            $totalMeters += $meters;

            $isInitialStock = (empty($roll['mother_id']) || ($roll['original_source'] ?? '') === 'initial_stock');
            $isRecoil = (($roll['source'] ?? '') === 'recoiling' || !empty($roll['recoiling_id']) || ($roll['cut_type'] ?? '') === 'cut_into_2');
            $isReslit = (($roll['source'] ?? '') === 'reslit' || !empty($roll['is_reslitted']));

            if ($isInitialStock) {
                $initialStockCount++;
            } elseif ($isRecoil) {
                $recoilCount++;
            } elseif ($isReslit) {
                $reslitCount++;
            }

            if (!empty($roll['customer_name'])) {
                $customerNames[$roll['customer_name']] = true;
            }
            if (!empty($roll['product'])) {
                $uniqueProducts[$roll['product']] = true;
            }
            if (!empty($roll['pallet_no'])) {
                $pNo = $roll['pallet_no'];
                if (!isset($uniquePallets[$pNo])) {
                    $uniquePallets[$pNo] = [
                        'pallet_no'    => $pNo,
                        'status'       => $roll['pallet_status'],
                        'delivered_at' => $roll['pallet_delivered_at'],
                        'roll_count'   => 0,
                    ];
                }
                $uniquePallets[$pNo]['roll_count']++;
            }

            if ($status === 'DELIVERED') {
                $deliveredRolls++;
                if (!empty($roll['delivered_at'])) {
                    if (!$latestDelivered || strtotime($roll['delivered_at']) > strtotime($latestDelivered)) {
                        $latestDelivered = $roll['delivered_at'];
                    }
                }
            } elseif ($status === 'APPROVED') {
                $approvedRolls++;
            } elseif ($status === 'WAITING') {
                $waitingRolls++;
            } else {
                $inProdRolls++;
            }

            if (!empty($roll['date_in'])) {
                if (!$earliestDate || strtotime($roll['date_in']) < strtotime($earliestDate)) {
                    $earliestDate = $roll['date_in'];
                }
            }
        }

        // Determine Overall Progress State
        $overallStatus = 'IN_PRODUCTION';
        $progressPct   = 25;
        $stageIndex    = 1; // 1: Order Planned, 2: Slitting, 3: QC, 4: Palletizing/Ready, 5: Delivered

        if ($deliveredRolls === $totalRolls) {
            $overallStatus = 'DELIVERED';
            $progressPct   = 100;
            $stageIndex    = 5;
        } elseif ($deliveredRolls > 0) {
            $overallStatus = 'PARTIALLY_DELIVERED';
            $progressPct   = (int)round(($deliveredRolls / $totalRolls) * 100);
            if ($progressPct < 50) {
                $progressPct = 50;
            }
            $stageIndex    = 4;
        } elseif (!empty($uniquePallets) || $approvedRolls === $totalRolls) {
            $overallStatus = 'READY_FOR_DELIVERY';
            $progressPct   = 85;
            $stageIndex    = 4;
        } elseif ($waitingRolls > 0 || $approvedRolls > 0) {
            $overallStatus = 'WAITING_QC';
            $progressPct   = 55;
            $stageIndex    = 3;
        } else {
            $overallStatus = 'IN_PRODUCTION';
            $progressPct   = 30;
            $stageIndex    = 2;
        }

        $plannedRolls = $totalRolls;
        if (!empty($actualRefNo)) {
            $stmtPlan = $this->conn->prepare("SELECT COUNT(*) AS planned_rolls FROM slitting_plans WHERE ref_no = ?");
            if ($stmtPlan) {
                $stmtPlan->bind_param("s", $actualRefNo);
                $stmtPlan->execute();
                $planRow = $stmtPlan->get_result()->fetch_assoc();
                if ($planRow && (int)$planRow['planned_rolls'] > 0) {
                    $plannedRolls = (int)$planRow['planned_rolls'];
                }
                $stmtPlan->close();
            }
        }

        $excessRolls = max(0, $totalRolls - $plannedRolls);
        $excessBreakdown = [];
        if ($excessRolls > 0) {
            if ($initialStockCount > 0) {
                $excessBreakdown[] = "{$initialStockCount} Initial Stock";
            }
            if ($recoilCount > 0) {
                $excessBreakdown[] = "{$recoilCount} Recoil";
            }
            if ($reslitCount > 0) {
                $excessBreakdown[] = "{$reslitCount} Reslit";
            }
            if (empty($excessBreakdown)) {
                $excessBreakdown[] = "{$excessRolls} Extra Cut";
            }
        }

        $singleOrder = [
            'ref_no'           => $actualRefNo,
            'customer_name'    => implode(', ', array_keys($customerNames)) ?: 'N/A',
            'products'         => implode(', ', array_keys($uniqueProducts)) ?: 'N/A',
            'total_rolls'      => $totalRolls,
            'planned_rolls'    => $plannedRolls,
            'excess_rolls'     => $excessRolls,
            'excess_breakdown' => implode(', ', $excessBreakdown),
            'initial_stock_cnt'=> $initialStockCount,
            'recoil_cnt'       => $recoilCount,
            'reslit_cnt'       => $reslitCount,
            'delivered_rolls'  => $deliveredRolls,
            'approved_rolls'   => $approvedRolls,
            'waiting_rolls'    => $waitingRolls,
            'in_prod_rolls'    => $inProdRolls,
            'total_meters'     => $totalMeters,
            'overall_status'   => $overallStatus,
            'progress_pct'     => $progressPct,
            'stage_index'      => $stageIndex,
            'earliest_date'    => $earliestDate,
            'latest_delivered' => $latestDelivered,
            'pallets'          => array_values($uniquePallets),
        ];

        return [
            'summary' => $singleOrder,
            'rolls'   => $orderRolls,
        ];
    }

    /**
     * Retrieve multi-order list with summary aggregation and metrics
     */
    public function getOrderList(
        string $filterCustomer = '',
        string $filterStatus = '',
        string $searchQuery = '',
        int $limit = 100
    ): array {
        if (!self::$hasSynced) {
            $this->autoSyncMissingPlans();
            self::$hasSynced = true;
        }
        $baseWhere = self::BASE_WHERE;

        $listSql = "
            SELECT 
                sp.customer_name, 
                sp.ref_no, 
                COUNT(*) as total_rolls, 
                SUM(CASE WHEN sp.status = 'DELIVERED' THEN 1 ELSE 0 END) as delivered_rolls, 
                SUM(CASE WHEN sp.status = 'APPROVED' THEN 1 ELSE 0 END) as approved_rolls, 
                SUM(CASE WHEN sp.status = 'WAITING' THEN 1 ELSE 0 END) as waiting_rolls, 
                SUM(CASE WHEN sp.status = 'IN' THEN 1 ELSE 0 END) as in_prod_rolls, 
                SUM(COALESCE(sp.actual_length, sp.length, 0)) as total_meters,
                MAX(sp.delivered_at) as latest_delivered, 
                MIN(sp.date_in) as first_date,
                COUNT(DISTINCT p.pallet_no) as pallet_count,
                GROUP_CONCAT(DISTINCT p.pallet_no SEPARATOR ', ') as pallet_numbers,
                GROUP_CONCAT(DISTINCT sp.product SEPARATOR ', ') as products,
                SUM(CASE WHEN (sp.mother_id IS NULL OR sp.mother_id = 0 OR sp.original_source = 'initial_stock') THEN 1 ELSE 0 END) AS initial_stock_rolls,
                SUM(CASE WHEN (sp.source = 'recoiling' OR sp.recoiling_id > 0 OR sp.cut_type = 'cut_into_2') THEN 1 ELSE 0 END) AS recoil_rolls,
                SUM(CASE WHEN (sp.source = 'reslit' OR sp.is_reslitted = 1) THEN 1 ELSE 0 END) AS reslit_rolls,
                COALESCE(MAX(plan_summary.planned_rolls), COUNT(*)) as planned_rolls
            FROM slitting_product sp 
            LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id 
            LEFT JOIN pallets p ON p.id = pi.pallet_id 
            LEFT JOIN (
                SELECT ref_no, COUNT(*) as planned_rolls 
                FROM slitting_plans 
                WHERE ref_no IS NOT NULL AND ref_no != '' 
                GROUP BY ref_no
            ) plan_summary ON plan_summary.ref_no = sp.ref_no 
            WHERE {$baseWhere}
              AND sp.customer_name IS NOT NULL AND sp.customer_name != '' 
              AND sp.ref_no IS NOT NULL AND sp.ref_no != '' 
              AND sp.ref_no != 'STOCK'
        ";

        $listParams = [];
        $listTypes  = '';

        if ($filterCustomer !== '') {
            $listSql .= " AND sp.customer_name = ?";
            $listParams[] = $filterCustomer;
            $listTypes   .= 's';
        }

        if ($searchQuery !== '') {
            $listSql .= " AND (
                sp.ref_no LIKE ? OR 
                sp.customer_name LIKE ? OR 
                sp.product LIKE ? OR 
                sp.lot_no LIKE ? OR 
                sp.coil_no LIKE ? OR
                p.pallet_no LIKE ?
            )";
            $likeQ = '%' . $searchQuery . '%';
            $listParams = array_merge($listParams, [$likeQ, $likeQ, $likeQ, $likeQ, $likeQ, $likeQ]);
            $listTypes  .= 'ssssss';
        }

        $listSql .= " GROUP BY sp.customer_name, sp.ref_no ";

        // Filter by order completion stage
        if ($filterStatus === 'delivered') {
            $listSql .= " HAVING delivered_rolls = total_rolls ";
        } elseif ($filterStatus === 'in_production') {
            $listSql .= " HAVING delivered_rolls < total_rolls AND in_prod_rolls > 0 ";
        } elseif ($filterStatus === 'waiting_qc') {
            $listSql .= " HAVING delivered_rolls < total_rolls AND waiting_rolls > 0 ";
        } elseif ($filterStatus === 'approved') {
            $listSql .= " HAVING delivered_rolls < total_rolls AND approved_rolls > 0 ";
        }

        $listSql .= " ORDER BY COALESCE(latest_delivered, first_date) DESC LIMIT ?";
        $listParams[] = $limit;
        $listTypes .= 'i';

        $orderList = [];
        $totalOrdersCount = 0;
        $deliveredOrdersCount = 0;
        $inProdOrdersCount = 0;
        $readyOrdersCount = 0;

        $stmtList = $this->conn->prepare($listSql);
        if ($stmtList) {
            if (!empty($listParams)) {
                $stmtList->bind_param($listTypes, ...$listParams);
            }
            $stmtList->execute();
            $listResult = $stmtList->get_result();

            while ($row = $listResult->fetch_assoc()) {
                $tot = (int)$row['total_rolls'];
                $del = (int)$row['delivered_rolls'];
                $app = (int)$row['approved_rolls'];
                $wai = (int)$row['waiting_rolls'];
                $palletsCount = (int)$row['pallet_count'];

                if ($del === $tot) {
                    $st = 'DELIVERED';
                    $pct = 100;
                    $badgeClass = 'bg-success text-white';
                    $statusText = 'Delivered';
                    $deliveredOrdersCount++;
                } elseif ($del > 0) {
                    $st = 'PARTIALLY_DELIVERED';
                    $pct = (int)round(($del / $tot) * 100);
                    $badgeClass = 'bg-primary text-white';
                    $statusText = "Partial ({$del}/{$tot} Delivered)";
                    $inProdOrdersCount++;
                } elseif ($palletsCount > 0 || $app === $tot) {
                    $st = 'READY';
                    $pct = 85;
                    $badgeClass = 'bg-info text-dark';
                    $statusText = 'Ready for Delivery';
                    $readyOrdersCount++;
                } elseif ($wai > 0) {
                    $st = 'WAITING_QC';
                    $pct = 50;
                    $badgeClass = 'bg-warning text-dark';
                    $statusText = 'QC Inspection';
                    $inProdOrdersCount++;
                } else {
                    $st = 'IN_PRODUCTION';
                    $pct = 25;
                    $badgeClass = 'bg-secondary text-white';
                    $statusText = 'In Production';
                    $inProdOrdersCount++;
                }

                $planned = (int)($row['planned_rolls'] ?? $tot);
                if ($planned <= 0) { $planned = $tot; }
                $row['planned_rolls'] = $planned;

                $excess = max(0, $tot - $planned);
                $row['excess_rolls'] = $excess;
                $breakdownParts = [];
                if ($excess > 0) {
                    $initStk = (int)($row['initial_stock_rolls'] ?? 0);
                    $recoil  = (int)($row['recoil_rolls'] ?? 0);
                    $reslit  = (int)($row['reslit_rolls'] ?? 0);
                    if ($initStk > 0) $breakdownParts[] = "{$initStk} Initial Stock";
                    if ($recoil > 0)  $breakdownParts[] = "{$recoil} Recoil";
                    if ($reslit > 0)  $breakdownParts[] = "{$reslit} Reslit";
                    if (empty($breakdownParts)) $breakdownParts[] = "{$excess} Extra Cut";
                }
                $row['excess_breakdown'] = implode(', ', $breakdownParts);

                $row['order_status'] = $st;
                $row['progress_pct'] = $pct;
                $row['badge_class']  = $badgeClass;
                $row['status_text']  = $statusText;

                $orderList[] = $row;
                $totalOrdersCount++;
            }
            $stmtList->close();
        }

        return [
            'orders' => $orderList,
            'stats'  => [
                'total_orders'     => $totalOrdersCount,
                'delivered_orders' => $deliveredOrdersCount,
                'in_prod_orders'   => $inProdOrdersCount,
                'ready_orders'     => $readyOrdersCount,
            ]
        ];
    }
}

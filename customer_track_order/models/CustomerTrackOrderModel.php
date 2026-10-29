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
     * Automatically synchronizes slitting_plans with actual production:
     * 1. If a plan was assigned to SO-A but used for SO-B in production, reassign plan to SO-B
     * 2. Remove redundant duplicate plans for the same mother coil cut
     * 3. Insert truly missing plans for rolls cut without an initial plan
     */
    public function autoSyncMissingPlans(): int
    {
        // 1. Reassign plan ref_no and customer when production roll was assigned to another SO or STOCK
        $sqlUpdateMismatches = "
            UPDATE slitting_plans p
            INNER JOIN slitting_product sp 
                ON sp.mother_id = p.mother_coil_id 
               AND sp.roll_no = p.roll_seq
            SET p.ref_no = sp.ref_no,
                p.customer_name = COALESCE(NULLIF(sp.customer_name, ''), p.customer_name)
            WHERE (p.ref_no IS NULL OR p.ref_no = '' OR p.ref_no != sp.ref_no)
              AND (sp.is_voided = 0 OR sp.is_voided IS NULL)
              AND (sp.is_reslitted = 0 OR sp.is_reslitted IS NULL)
              AND (sp.is_recoiled = 0 OR sp.is_recoiled IS NULL)
              AND sp.ref_no IS NOT NULL 
              AND sp.ref_no != ''
        ";
        $this->conn->query($sqlUpdateMismatches);

        // 2. Remove redundant duplicate plans for the same physical cut (keep lowest ID)
        $sqlDups = "
            DELETE p1 FROM slitting_plans p1
            INNER JOIN slitting_plans p2 
                ON p1.mother_coil_id = p2.mother_coil_id 
               AND p1.roll_seq = p2.roll_seq 
               AND p1.id > p2.id
            WHERE p1.mother_coil_id IS NOT NULL 
              AND p1.mother_coil_id > 0
        ";
        $this->conn->query($sqlDups);

        // 3. Insert truly missing plans for rolls produced without any pre-existing plan
        $sqlInsert = "
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
        $this->conn->query($sqlInsert);
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
                sp.nod_length,
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
                sp.product ASC,
                sp.lot_no ASC,
                sp.coil_no ASC,
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
                    sp.nod_length,
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
                    sp.product ASC,
                    sp.lot_no ASC,
                    sp.coil_no ASC,
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

        $actualRefNo = '';
        if (!empty($orderRolls)) {
            $actualRefNo = $orderRolls[0]['ref_no'];
        } else {
            // Check if order exists in slitting_plans
            $stmtCheckPlan = $this->conn->prepare("SELECT ref_no, customer_name FROM slitting_plans WHERE ref_no = ? LIMIT 1");
            if ($stmtCheckPlan) {
                $stmtCheckPlan->bind_param("s", $searchSo);
                $stmtCheckPlan->execute();
                $cRow = $stmtCheckPlan->get_result()->fetch_assoc();
                if ($cRow) {
                    $actualRefNo = $cRow['ref_no'];
                }
                $stmtCheckPlan->close();
            }
            if (empty($actualRefNo)) {
                return null;
            }
        }

        // 3. Compute Single Order Aggregation & Journey Stage
        $producedRollsCount = count($orderRolls);
        $totalRolls         = $producedRollsCount;
        $deliveredRolls     = 0;
        $approvedRolls      = 0;
        $waitingRolls       = 0;
        $inProdRolls        = 0;
        $qcPassedRolls      = 0;
        $palletizedRolls    = 0;
        $initialStockCount  = 0;
        $recoilCount        = 0;
        $reslitCount        = 0;
        $totalMeters        = 0.0;
        $customerNames      = [];
        $uniqueProducts     = [];
        $uniquePallets      = [];
        $earliestDate       = null;
        $latestDelivered    = null;

        $producedMap = [];
        foreach ($orderRolls as &$roll) {
            $roll['is_planned_only'] = false;
            $roll['is_produced']     = true;
            $pKey = ($roll['mother_id'] ?? '') . '_' . ($roll['roll_no'] ?? '');
            $producedMap[$pKey] = true;

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
                $qcPassedRolls++;
                $palletizedRolls++;
                if (!empty($roll['delivered_at'])) {
                    if (!$latestDelivered || strtotime($roll['delivered_at']) > strtotime($latestDelivered)) {
                        $latestDelivered = $roll['delivered_at'];
                    }
                }
            } elseif ($status === 'APPROVED') {
                $approvedRolls++;
                $qcPassedRolls++;
                if (!empty($roll['pallet_no']) || !empty($roll['pallet_id'])) {
                    $palletizedRolls++;
                }
            } elseif ($status === 'WAITING') {
                $waitingRolls++;
                if (!empty($roll['pallet_no']) || !empty($roll['pallet_id'])) {
                    $palletizedRolls++;
                }
            } else {
                $inProdRolls++;
                if (!empty($roll['pallet_no']) || !empty($roll['pallet_id'])) {
                    $palletizedRolls++;
                }
            }

            if (!empty($roll['date_in'])) {
                if (!$earliestDate || strtotime($roll['date_in']) < strtotime($earliestDate)) {
                    $earliestDate = $roll['date_in'];
                }
            }
        }
        unset($roll);

        // Fetch planned coils from slitting_plans (deduplicated by mother_coil_id & roll_seq)
        $planningRollsCount = 0;
        $distinctPlanCount = 0;
        $sqlPlans = "
            SELECT 
                MIN(p.id) AS plan_id,
                p.mother_coil_id,
                MIN(p.stock_id) AS stock_id,
                p.roll_seq,
                MAX(p.planned_width) AS planned_width,
                MAX(p.customer_name) AS customer_name,
                p.ref_no,
                MIN(p.sort_order) AS sort_order,
                COALESCE(MAX(mc.coil_no), MAX(srm.coil_no), '—') AS coil_no,
                COALESCE(MAX(mc.lot_no), MAX(srm.lot_no), '—') AS lot_no,
                COALESCE(MAX(mc.product), '—') AS product,
                COALESCE(MAX(mc.grade), MAX(srm.grade), '') AS grade,
                COALESCE(MAX(mc.length), MAX(srm.length), 0) AS planned_length,
                COALESCE(MAX(mc.width), MAX(srm.width), 0) AS mother_width,
                MAX(mc.status) AS mother_status
            FROM slitting_plans p
            LEFT JOIN mother_coil mc ON mc.id = p.mother_coil_id
            LEFT JOIN stock_raw_material srm ON srm.id = p.stock_id
            WHERE p.ref_no = ?
            GROUP BY p.mother_coil_id, p.roll_seq, p.ref_no
            ORDER BY p.mother_coil_id ASC, MIN(p.sort_order) ASC, p.roll_seq ASC
        ";
        $stmtP = $this->conn->prepare($sqlPlans);
        if ($stmtP) {
            $stmtP->bind_param("s", $actualRefNo);
            $stmtP->execute();
            $plansRes = $stmtP->get_result();
            while ($plan = $plansRes->fetch_assoc()) {
                $distinctPlanCount++;
                $planKey = ($plan['mother_coil_id'] ?? '') . '_' . ($plan['roll_seq'] ?? '');
                
                // If not produced yet, add to order item rolls as a planning coil
                if (!isset($producedMap[$planKey])) {
                    $orderRolls[] = [
                        'id'                  => 'plan_' . $plan['plan_id'],
                        'plan_id'             => $plan['plan_id'],
                        'customer_name'       => $plan['customer_name'] ?: (implode(', ', array_keys($customerNames)) ?: 'N/A'),
                        'ref_no'              => $plan['ref_no'],
                        'product'             => $plan['product'],
                        'lot_no'              => $plan['lot_no'],
                        'coil_no'             => $plan['coil_no'],
                        'roll_no'             => $plan['roll_seq'],
                        'width'               => (float)$plan['planned_width'],
                        'length'              => (float)$plan['planned_length'],
                        'actual_length'       => 0,
                        'nod_length'          => null,
                        'status'              => 'PLANNED',
                        'stock_counted'       => 0,
                        'is_completed'        => 0,
                        'date_in'             => null,
                        'date_out'            => null,
                        'delivered_at'        => null,
                        'delivered_by'        => null,
                        'qc_comment'          => null,
                        'mother_id'           => $plan['mother_coil_id'],
                        'source'              => 'plan',
                        'original_source'     => 'plan',
                        'recoiling_id'        => null,
                        'is_reslitted'        => 0,
                        'cut_type'            => 'normal',
                        'grade'               => $plan['grade'],
                        'pallet_id'           => null,
                        'pallet_no'           => null,
                        'pallet_status'       => null,
                        'pallet_delivered_at' => null,
                        'is_planned_only'     => true,
                        'is_produced'         => false,
                    ];
                    $planningRollsCount++;

                    if (!empty($plan['product']) && $plan['product'] !== '—') {
                        $uniqueProducts[$plan['product']] = true;
                    }
                    if (!empty($plan['customer_name'])) {
                        $customerNames[$plan['customer_name']] = true;
                    }
                }
            }
            $stmtP->close();
        }

        // Raw count in slitting_plans for display
        $rawPlanCount = 0;
        $stmtRawPlan = $this->conn->prepare("SELECT COUNT(*) AS cnt FROM slitting_plans WHERE ref_no = ?");
        if ($stmtRawPlan) {
            $stmtRawPlan->bind_param("s", $actualRefNo);
            $stmtRawPlan->execute();
            $rawRow = $stmtRawPlan->get_result()->fetch_assoc();
            if ($rawRow) {
                $rawPlanCount = (int)$rawRow['cnt'];
            }
            $stmtRawPlan->close();
        }

        $plannedRolls = max($totalRolls, $distinctPlanCount, $rawPlanCount);
        $targetRolls  = max($totalRolls, $plannedRolls);

        // Determine Overall Progress State taking planned rolls into account
        $overallStatus = 'IN_PRODUCTION';
        $progressPct   = 25;
        $stageIndex    = 1; // 1: Order Planned, 2: Slitting, 3: QC, 4: Palletizing/Ready, 5: Delivered

        if ($deliveredRolls >= $targetRolls && $totalRolls >= $plannedRolls && $targetRolls > 0) {
            $overallStatus = 'DELIVERED';
            $progressPct   = 100;
            $stageIndex    = 5;
        } elseif ($deliveredRolls > 0) {
            $overallStatus = 'PARTIALLY_DELIVERED';
            $progressPct   = (int)round(($deliveredRolls / $targetRolls) * 100);
            if ($progressPct < 15) {
                $progressPct = 15;
            }
            $stageIndex    = 4;
        } elseif (!empty($uniquePallets) || ($approvedRolls === $totalRolls && $totalRolls >= $plannedRolls)) {
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
            'ref_no'             => $actualRefNo,
            'customer_name'      => implode(', ', array_keys($customerNames)) ?: 'N/A',
            'products'           => implode(', ', array_keys($uniqueProducts)) ?: 'N/A',
            'total_rolls'        => $totalRolls,
            'produced_rolls_cnt' => $totalRolls,
            'slit_rolls_cnt'     => $totalRolls,
            'qc_passed_cnt'      => $qcPassedRolls,
            'palletized_cnt'     => $palletizedRolls,
            'planning_rolls_cnt' => $planningRollsCount,
            'planned_rolls'      => $plannedRolls,
            'target_rolls'       => $targetRolls,
            'excess_rolls'       => $excessRolls,
            'excess_breakdown'   => implode(', ', $excessBreakdown),
            'initial_stock_cnt'  => $initialStockCount,
            'recoil_cnt'         => $recoilCount,
            'reslit_cnt'         => $reslitCount,
            'delivered_rolls'    => $deliveredRolls,
            'approved_rolls'     => $approvedRolls,
            'waiting_rolls'      => $waitingRolls,
            'in_prod_rolls'      => $inProdRolls,
            'total_meters'       => $totalMeters,
            'overall_status'     => $overallStatus,
            'progress_pct'       => $progressPct,
            'stage_index'        => $stageIndex,
            'earliest_date'      => $earliestDate,
            'latest_delivered'   => $latestDelivered,
            'pallets'            => array_values($uniquePallets),
        ];

        // Sort all coils (both produced & planning) by product (A-Z), then lot no (natural sort), then coil no, then roll no
        usort($orderRolls, function ($a, $b) {
            $cmpProduct = strnatcasecmp((string)($a['product'] ?? ''), (string)($b['product'] ?? ''));
            if ($cmpProduct !== 0) {
                return $cmpProduct;
            }

            $cmpLot = strnatcasecmp((string)($a['lot_no'] ?? ''), (string)($b['lot_no'] ?? ''));
            if ($cmpLot !== 0) {
                return $cmpLot;
            }

            $cmpCoil = strnatcasecmp((string)($a['coil_no'] ?? ''), (string)($b['coil_no'] ?? ''));
            if ($cmpCoil !== 0) {
                return $cmpCoil;
            }

            return strnatcasecmp((string)($a['roll_no'] ?? ''), (string)($b['roll_no'] ?? ''));
        });

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
            $listSql .= " HAVING delivered_rolls >= planned_rolls AND delivered_rolls = total_rolls ";
        } elseif ($filterStatus === 'in_production') {
            $listSql .= " HAVING (delivered_rolls < planned_rolls OR total_rolls < planned_rolls) AND in_prod_rolls > 0 ";
        } elseif ($filterStatus === 'waiting_qc') {
            $listSql .= " HAVING (delivered_rolls < planned_rolls OR total_rolls < planned_rolls) AND waiting_rolls > 0 ";
        } elseif ($filterStatus === 'approved') {
            $listSql .= " HAVING (delivered_rolls < planned_rolls OR total_rolls < planned_rolls) AND approved_rolls > 0 ";
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

                $planned = (int)($row['planned_rolls'] ?? $tot);
                if ($planned <= 0) { $planned = $tot; }
                $row['planned_rolls'] = $planned;
                $target = max($tot, $planned);
                $row['target_rolls']  = $target;

                if ($del >= $target && $tot >= $planned && $target > 0) {
                    $st = 'DELIVERED';
                    $pct = 100;
                    $badgeClass = 'bg-success text-white';
                    $statusText = 'Delivered';
                    $deliveredOrdersCount++;
                } elseif ($del > 0) {
                    $st = 'PARTIALLY_DELIVERED';
                    $pct = (int)round(($del / $target) * 100);
                    $badgeClass = 'bg-primary text-white';
                    $statusText = "Partial ({$del}/{$target} Delivered)";
                    $inProdOrdersCount++;
                } elseif ($palletsCount > 0 || ($app === $tot && $tot >= $planned)) {
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

                $row['order_status']   = $st;
                $row['overall_status'] = strtolower($st);
                $row['progress_pct'] = $pct;
                $row['badge_class']  = $badgeClass;
                $row['status_text']  = $statusText;

                $orderList[] = $row;
                $totalOrdersCount++;
            }
            $stmtList->close();
        }

        // Sort orders so active/in-progress orders (< 100%) appear at the top,
        // and fully completed orders (100%) are placed below in the list.
        usort($orderList, function($a, $b) {
            $aDone = ($a['progress_pct'] >= 100) ? 1 : 0;
            $bDone = ($b['progress_pct'] >= 100) ? 1 : 0;
            if ($aDone !== $bDone) {
                return $aDone <=> $bDone;
            }
            $dateA = $a['latest_delivered'] ?: $a['first_date'];
            $dateB = $b['latest_delivered'] ?: $b['first_date'];
            return strcmp($dateB, $dateA);
        });

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

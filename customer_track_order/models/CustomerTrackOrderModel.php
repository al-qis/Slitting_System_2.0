<?php
/**
 * CustomerTrackOrderModel.php
 * Model for Customer Order Tracking Portal
 * Location: /customer_track_order/models/CustomerTrackOrderModel.php
 */

class CustomerTrackOrderModel
{
    private mysqli $conn;

    /**
     * Base SQL condition for valid, non-voided rolls
     */
    private const BASE_WHERE = "(sp.is_voided = 0 OR sp.is_voided IS NULL)";

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function cleanInput(?string $val): string
    {
        return trim((string)($val ?? ''));
    }

    /**
     * Normalizes roll sequence / roll number for robust matching
     * (e.g. "R1", "R-1", "1", "r01" -> "R1")
     */
    public function normalizeRollSeq(?string $roll): string
    {
        if ($roll === null) return '';
        $clean = strtoupper(trim($roll));
        $clean = preg_replace('/[^A-Z0-9]/', '', $clean);
        if (preg_match('/^\d+$/', $clean)) {
            $clean = 'R' . (int)$clean;
        } elseif (preg_match('/^R0*(\d+.*)$/i', $clean, $m)) {
            $clean = 'R' . $m[1];
        }
        return $clean;
    }

    /**
     * Format roll number for presentation (e.g. R1 -> R-1)
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
     * Get distinct customers from both slitting_plans and slitting_product
     */
    public function getCustomerList(): array
    {
        $customerList = [];
        $query = "
            SELECT DISTINCT customer_name FROM (
                SELECT customer_name 
                FROM slitting_product 
                WHERE customer_name IS NOT NULL 
                  AND customer_name != '' 
                  AND customer_name != 'STOCK'
                  AND (is_voided = 0 OR is_voided IS NULL)
                UNION
                SELECT customer_name 
                FROM slitting_plans 
                WHERE customer_name IS NOT NULL 
                  AND customer_name != '' 
                  AND customer_name != 'STOCK'
            ) AS combined_customers
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
     * Fetch single order deep-dive tracking data by Sales Order / Ref No.
     * The order starts tracking from slitting_plans for the full total quantity,
     * and adds any recoil or reslit coils produced.
     */
    public function getSingleOrder(string $searchSo): ?array
    {
        $searchSo = trim($searchSo);
        if ($searchSo === '') {
            return null;
        }

        // 1. Resolve exact ref_no from slitting_plans or slitting_product
        $actualRefNo = '';
        $stmtCheck = $this->conn->prepare("
            SELECT ref_no FROM slitting_plans WHERE ref_no = ?
            UNION 
            SELECT ref_no FROM slitting_product WHERE (is_voided = 0 OR is_voided IS NULL) AND ref_no = ?
            LIMIT 1
        ");
        if ($stmtCheck) {
            $stmtCheck->bind_param("ss", $searchSo, $searchSo);
            $stmtCheck->execute();
            $res = $stmtCheck->get_result()->fetch_assoc();
            if ($res) {
                $actualRefNo = $res['ref_no'];
            }
            $stmtCheck->close();
        }

        // Fallback to LIKE if no exact match found
        if ($actualRefNo === '') {
            $likeSo = '%' . $searchSo . '%';
            $stmtLike = $this->conn->prepare("
                SELECT ref_no FROM slitting_plans WHERE ref_no LIKE ?
                UNION 
                SELECT ref_no FROM slitting_product WHERE (is_voided = 0 OR is_voided IS NULL) AND ref_no LIKE ?
                LIMIT 1
            ");
            if ($stmtLike) {
                $stmtLike->bind_param("ss", $likeSo, $likeSo);
                $stmtLike->execute();
                $res = $stmtLike->get_result()->fetch_assoc();
                if ($res) {
                    $actualRefNo = $res['ref_no'];
                }
                $stmtLike->close();
            }
        }

        if ($actualRefNo === '') {
            return null;
        }

        // 2. Fetch all planned coils from slitting_plans
        $sqlPlans = "
            SELECT 
                p.id AS plan_id,
                p.mother_coil_id,
                p.stock_id,
                p.reslit_id,
                p.roll_seq,
                p.planned_width,
                p.customer_name,
                p.ref_no,
                p.sort_order,
                COALESCE(mc.coil_no, srm.coil_no, '—') AS coil_no,
                COALESCE(mc.lot_no, srm.lot_no, '—') AS lot_no,
                COALESCE(mc.product, '—') AS product,
                COALESCE(mc.grade, srm.grade, '') AS grade,
                COALESCE(mc.length, srm.length, 0) AS planned_length,
                COALESCE(mc.width, srm.width, 0) AS mother_width,
                mc.status AS mother_status
            FROM slitting_plans p
            LEFT JOIN mother_coil mc ON mc.id = p.mother_coil_id
            LEFT JOIN stock_raw_material srm ON srm.id = p.stock_id
            WHERE p.ref_no = ?
            ORDER BY p.mother_coil_id ASC, p.sort_order ASC, p.id ASC
        ";
        $stmtP = $this->conn->prepare($sqlPlans);
        $plansList = [];
        if ($stmtP) {
            $stmtP->bind_param("s", $actualRefNo);
            $stmtP->execute();
            $plansRes = $stmtP->get_result();
            while ($p = $plansRes->fetch_assoc()) {
                $plansList[] = $p;
            }
            $stmtP->close();
        }

        // 3. Fetch all active produced rolls from slitting_product
        $sqlProds = "
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
            WHERE " . self::BASE_WHERE . "
              AND sp.ref_no = ?
            ORDER BY sp.id ASC
        ";
        $stmtPr = $this->conn->prepare($sqlProds);
        $prodsList = [];
        if ($stmtPr) {
            $stmtPr->bind_param("s", $actualRefNo);
            $stmtPr->execute();
            $prodRes = $stmtPr->get_result();
            while ($pr = $prodRes->fetch_assoc()) {
                $prodsList[] = $pr;
            }
            $stmtPr->close();
        }

        // 4. Index all active produced rolls into Production Pool keyed by mother_id and roll sequence
        $producedPool = [];
        foreach ($prodsList as $pr) {
            $mId = (int)($pr['mother_id'] ?? 0);
            $normRoll = $this->normalizeRollSeq($pr['roll_no']);
            $poolKey = "{$mId}_{$normRoll}";
            if (!isset($producedPool[$poolKey])) {
                $producedPool[$poolKey] = [];
            }
            $producedPool[$poolKey][] = $pr;
        }

        $orderRolls = [];
        $uniqueProducts = [];
        $customerNames  = [];
        $uniquePallets   = [];
        $earliestDate    = null;
        $latestDelivered = null;

        $deliveredRolls   = 0;
        $approvedRolls    = 0;
        $waitingRolls     = 0;
        $inProdRolls      = 0;
        $qcPassedRolls    = 0;
        $palletizedRolls  = 0;
        $nodCount         = 0;
        $totalMeters      = 0.0;
        $producedRollsCnt = 0;
        $planningRollsCnt = 0;

        // 5. Track starting from slitting_plans table (Base Full Quantity)
        foreach ($plansList as $pl) {
            $mId = (int)($pl['mother_coil_id'] ?: $pl['stock_id'] ?: 0);
            $normSeq = $this->normalizeRollSeq($pl['roll_seq']);
            $poolKey = "{$mId}_{$normSeq}";

            $matchedProd = null;
            if (!empty($producedPool[$poolKey])) {
                $matchedProd = array_shift($producedPool[$poolKey]);
            }

            if ($matchedProd) {
                $isRecoil = ($matchedProd['source'] === 'recoiling' || !empty($matchedProd['recoiling_id']) || ($matchedProd['cut_type'] ?? '') === 'cut_into_2');
                $isReslit = ($matchedProd['source'] === 'reslit' || !empty($matchedProd['is_reslitted']));

                $matchedProd['is_planned_only'] = false;
                $matchedProd['is_produced']     = true;
                $matchedProd['is_from_plan']    = true;
                $matchedProd['is_recoil']       = $isRecoil;
                $matchedProd['is_reslit']       = $isReslit;
                $matchedProd['plan_id']         = $pl['plan_id'];
                $orderRolls[] = $matchedProd;
                $producedRollsCnt++;
            } else {
                // Pending cut (in slitting plan)
                $orderRolls[] = [
                    'id'                  => 'plan_' . $pl['plan_id'],
                    'plan_id'             => $pl['plan_id'],
                    'customer_name'       => $pl['customer_name'] ?: 'N/A',
                    'ref_no'              => $pl['ref_no'],
                    'product'             => $pl['product'],
                    'lot_no'              => $pl['lot_no'],
                    'coil_no'             => $pl['coil_no'],
                    'roll_no'             => $pl['roll_seq'],
                    'width'               => (float)$pl['planned_width'],
                    'length'              => (float)$pl['planned_length'],
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
                    'mother_id'           => $pl['mother_coil_id'],
                    'source'              => 'plan',
                    'original_source'     => 'plan',
                    'recoiling_id'        => null,
                    'is_reslitted'        => 0,
                    'cut_type'            => 'normal',
                    'grade'               => $pl['grade'],
                    'pallet_id'           => null,
                    'pallet_no'           => null,
                    'pallet_status'       => null,
                    'pallet_delivered_at' => null,
                    'is_planned_only'     => true,
                    'is_produced'         => false,
                    'is_from_plan'        => true,
                    'is_recoil'           => false,
                    'is_reslit'           => false,
                ];
                $planningRollsCnt++;
            }
        }

        // 6. Add any remaining produced coils not matched to plan (additional recoil, reslit, initial stock, or extra cuts)
        foreach ($producedPool as $remainingGroup) {
            foreach ($remainingGroup as $extraProd) {
                $isRecoil = ($extraProd['source'] === 'recoiling' || !empty($extraProd['recoiling_id']) || ($extraProd['cut_type'] ?? '') === 'cut_into_2');
                $isReslit = ($extraProd['source'] === 'reslit' || !empty($extraProd['is_reslitted']));
                $isInitialStock = (empty($extraProd['mother_id']) || ($extraProd['original_source'] ?? '') === 'initial_stock');

                $extraProd['is_planned_only']  = false;
                $extraProd['is_produced']      = true;
                $extraProd['is_from_plan']     = false;
                $extraProd['is_recoil']        = $isRecoil;
                $extraProd['is_reslit']        = $isReslit;
                $extraProd['is_initial_stock'] = $isInitialStock;
                $extraProd['is_extra_cut']     = (!$isRecoil && !$isReslit && !$isInitialStock);
                $orderRolls[] = $extraProd;
                $producedRollsCnt++;
            }
        }

        // 7. Accumulate metrics from all rolls in the order
        $initialStockCount = 0;
        $recoilCount       = 0;
        $reslitCount       = 0;
        $extraCutCount     = 0;
        $extraRecoilCount  = 0;
        $extraReslitCount  = 0;

        foreach ($orderRolls as &$roll) {
            if (!empty($roll['customer_name']) && $roll['customer_name'] !== 'N/A') {
                $customerNames[$roll['customer_name']] = true;
            }
            if (!empty($roll['product']) && $roll['product'] !== '—') {
                $uniqueProducts[$roll['product']] = true;
            }

            if (!empty($roll['is_initial_stock'])) {
                $initialStockCount++;
            }
            if (!empty($roll['is_recoil'])) {
                $recoilCount++;
                if (empty($roll['is_from_plan'])) {
                    $extraRecoilCount++;
                }
            }
            if (!empty($roll['is_reslit'])) {
                $reslitCount++;
                if (empty($roll['is_from_plan'])) {
                    $extraReslitCount++;
                }
            }
            if (!empty($roll['is_extra_cut'])) {
                $extraCutCount++;
            }

            if (!empty($roll['nod_length']) && (float)$roll['nod_length'] > 0) {
                $nodCount++;
            }

            if (!empty($roll['is_produced'])) {
                $meters = (float)($roll['actual_length'] ?: $roll['length'] ?: 0);
                $totalMeters += $meters;
                $status = strtoupper($roll['status'] ?? 'IN');

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
            } else {
                // Planned length
                $meters = (float)($roll['length'] ?? 0);
                $totalMeters += $meters;
            }
        }
        unset($roll);

        // Full Total Quantity starting from slitting_plans and adding recoil / reslit / extra
        $plannedRolls = count($plansList);
        $totalRolls   = count($orderRolls);
        $targetRolls  = $totalRolls;

        // Determine Overall Progress State
        $overallStatus = 'IN_PRODUCTION';
        $progressPct   = 30;
        $stageIndex    = 2; // 1: Order Planned, 2: Slitting, 3: QC, 4: Palletizing/Ready, 5: Delivered

        if ($producedRollsCnt === 0 && $plannedRolls > 0) {
            $overallStatus = 'ORDER_PLANNED';
            $progressPct   = 15;
            $stageIndex    = 1;
        } elseif ($deliveredRolls >= $targetRolls && $targetRolls > 0) {
            $overallStatus = 'DELIVERED';
            $progressPct   = 100;
            $stageIndex    = 5;
        } elseif ($deliveredRolls > 0) {
            $overallStatus = 'PARTIALLY_DELIVERED';
            $progressPct   = (int)round(($deliveredRolls / $targetRolls) * 100);
            if ($progressPct < 20) {
                $progressPct = 20;
            }
            $stageIndex    = 4;
        } elseif (!empty($uniquePallets) || ($approvedRolls === $targetRolls && $targetRolls > 0)) {
            $overallStatus = 'READY_FOR_DELIVERY';
            $progressPct   = 85;
            $stageIndex    = 4;
        } elseif ($waitingRolls > 0 || $approvedRolls > 0) {
            $overallStatus = 'WAITING_QC';
            $progressPct   = 55;
            $stageIndex    = 3;
        } else {
            $overallStatus = 'IN_PRODUCTION';
            $progressPct   = 35;
            $stageIndex    = 2;
        }

        $excessRolls = max(0, $totalRolls - $plannedRolls);
        $excessBreakdown = [];
        if ($extraRecoilCount > 0) {
            $excessBreakdown[] = "{$extraRecoilCount} Recoil";
        }
        if ($extraReslitCount > 0) {
            $excessBreakdown[] = "{$extraReslitCount} Reslit";
        }
        if ($initialStockCount > 0) {
            $excessBreakdown[] = "{$initialStockCount} Initial Stock";
        }
        if ($extraCutCount > 0) {
            $excessBreakdown[] = "{$extraCutCount} Extra Cut";
        }

        $slitRollsCnt = $producedRollsCnt - $recoilCount - $reslitCount - $initialStockCount;
        if ($slitRollsCnt < 0) {
            $slitRollsCnt = $producedRollsCnt;
        }

        $singleOrder = [
            'ref_no'             => $actualRefNo,
            'customer_name'      => implode(', ', array_keys($customerNames)) ?: 'N/A',
            'products'           => implode(', ', array_keys($uniqueProducts)) ?: 'N/A',
            'total_rolls'        => $totalRolls,
            'produced_rolls_cnt' => $producedRollsCnt,
            'slit_rolls_cnt'     => $slitRollsCnt,
            'qc_passed_cnt'      => $qcPassedRolls,
            'palletized_cnt'     => $palletizedRolls,
            'planning_rolls_cnt' => $planningRollsCnt,
            'planned_rolls'      => $plannedRolls,
            'target_rolls'       => $targetRolls,
            'excess_rolls'       => $excessRolls,
            'excess_breakdown'   => implode(', ', $excessBreakdown),
            'initial_stock_cnt'  => $initialStockCount,
            'recoil_cnt'         => $recoilCount,
            'reslit_cnt'         => $reslitCount,
            'extra_recoil_cnt'   => $extraRecoilCount,
            'extra_reslit_cnt'   => $extraReslitCount,
            'nod_cnt'            => $nodCount,
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

        // Sort coils naturally by product (A-Z), lot no, coil no, then roll sequence
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
     * Retrieve multi-order list combining slitting_plans and slitting_product
     */
    public function getOrderList(
        string $filterCustomer = '',
        string $filterStatus = '',
        string $searchQuery = '',
        int $limit = 100
    ): array {
        // 1. Fetch plans summary grouped by ref_no
        $plansMap = [];
        $plansSql = "
            SELECT 
                p.ref_no,
                MAX(p.customer_name) AS customer_name,
                COUNT(*) AS planned_rolls,
                MIN(p.created_at) AS plan_date,
                GROUP_CONCAT(DISTINCT mc.product SEPARATOR ', ') AS plan_products
            FROM slitting_plans p
            LEFT JOIN mother_coil mc ON mc.id = p.mother_coil_id
            WHERE p.ref_no IS NOT NULL AND p.ref_no != '' AND p.ref_no != 'STOCK'
            GROUP BY p.ref_no
        ";
        $pRes = $this->conn->query($plansSql);
        if ($pRes) {
            while ($pRow = $pRes->fetch_assoc()) {
                $plansMap[$pRow['ref_no']] = $pRow;
            }
        }

        // 2. Fetch production summary grouped by ref_no
        $prodMap = [];
        $prodSql = "
            SELECT 
                sp.ref_no,
                MAX(sp.customer_name) AS customer_name,
                COUNT(*) as total_produced,
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
                SUM(CASE WHEN (sp.source = 'reslit' OR sp.is_reslitted = 1) THEN 1 ELSE 0 END) AS reslit_rolls
            FROM slitting_product sp 
            LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id 
            LEFT JOIN pallets p ON p.id = pi.pallet_id 
            WHERE " . self::BASE_WHERE . "
              AND sp.ref_no IS NOT NULL AND sp.ref_no != '' AND sp.ref_no != 'STOCK'
            GROUP BY sp.ref_no
        ";
        $prRes = $this->conn->query($prodSql);
        if ($prRes) {
            while ($prRow = $prRes->fetch_assoc()) {
                $prodMap[$prRow['ref_no']] = $prRow;
            }
        }

        // 3. Merge all distinct ref_no
        $allRefNos = array_unique(array_merge(array_keys($plansMap), array_keys($prodMap)));

        $orderList = [];
        $totalOrdersCount     = 0;
        $deliveredOrdersCount = 0;
        $inProdOrdersCount    = 0;
        $readyOrdersCount     = 0;

        foreach ($allRefNos as $refNo) {
            $pData  = $plansMap[$refNo] ?? null;
            $prData = $prodMap[$refNo] ?? null;

            $custName = trim(($prData['customer_name'] ?? '') ?: ($pData['customer_name'] ?? '') ?: '');
            if ($custName === '' || $custName === 'STOCK') {
                continue;
            }

            // Customer Filter
            if ($filterCustomer !== '' && strcasecmp($custName, $filterCustomer) !== 0) {
                continue;
            }

            $products = trim(($prData['products'] ?? '') ?: ($pData['plan_products'] ?? '') ?: '—');
            $palletNos = $prData['pallet_numbers'] ?? '';

            // Search Query Filter
            if ($searchQuery !== '') {
                $haystack = strtolower("{$refNo} {$custName} {$products} {$palletNos}");
                if (stripos($haystack, strtolower($searchQuery)) === false) {
                    continue;
                }
            }

            $planned = (int)($pData['planned_rolls'] ?? 0);
            $totalProd = (int)($prData['total_produced'] ?? 0);
            $recoil  = (int)($prData['recoil_rolls'] ?? 0);
            $reslit  = (int)($prData['reslit_rolls'] ?? 0);
            $initStk = (int)($prData['initial_stock_rolls'] ?? 0);

            // Target quantity starts from slitting_plans and adds any extra coils (recoil/reslit/extra cut/stock)
            if ($planned > 0) {
                $target = max($planned, $totalProd);
            } else {
                $target = $totalProd;
                $planned = $totalProd;
            }

            $del = (int)($prData['delivered_rolls'] ?? 0);
            $app = (int)($prData['approved_rolls'] ?? 0);
            $wai = (int)($prData['waiting_rolls'] ?? 0);
            $inp = (int)($prData['in_prod_rolls'] ?? 0);
            $palletsCount = (int)($prData['pallet_count'] ?? 0);

            // Status determination
            if ($del >= $target && $target > 0) {
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
            } elseif ($palletsCount > 0 || ($app === $target && $target > 0)) {
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
            } elseif ($totalProd > 0) {
                $st = 'IN_PRODUCTION';
                $pct = 30;
                $badgeClass = 'bg-secondary text-white';
                $statusText = 'In Production';
                $inProdOrdersCount++;
            } else {
                // Completely in planning (0 slit)
                $st = 'ORDER_PLANNED';
                $pct = 15;
                $badgeClass = 'text-white';
                $statusText = 'Order Planned';
                $inProdOrdersCount++;
            }

            // Filter by order status
            if ($filterStatus === 'delivered' && $st !== 'DELIVERED') {
                continue;
            } elseif ($filterStatus === 'in_production' && !in_array($st, ['IN_PRODUCTION', 'ORDER_PLANNED'])) {
                continue;
            } elseif ($filterStatus === 'waiting_qc' && $st !== 'WAITING_QC') {
                continue;
            } elseif ($filterStatus === 'approved' && $st !== 'READY') {
                continue;
            }

            $excess = max(0, $target - $planned);
            $breakdownParts = [];
            if ($recoil > 0)  $breakdownParts[] = "{$recoil} Recoil";
            if ($reslit > 0)  $breakdownParts[] = "{$reslit} Reslit";
            if ($initStk > 0) $breakdownParts[] = "{$initStk} Initial Stock";
            $extraCut = max(0, $excess - ($recoil + $reslit + $initStk));
            if ($extraCut > 0) $breakdownParts[] = "{$extraCut} Extra Cut";

            $orderList[] = [
                'customer_name'       => $custName,
                'ref_no'              => $refNo,
                'total_rolls'         => $totalProd,
                'planned_rolls'       => $planned,
                'target_rolls'        => $target,
                'delivered_rolls'     => $del,
                'approved_rolls'      => $app,
                'waiting_rolls'       => $wai,
                'in_prod_rolls'       => $inp,
                'total_meters'        => (float)($prData['total_meters'] ?? 0),
                'latest_delivered'    => $prData['latest_delivered'] ?? null,
                'first_date'          => ($prData['first_date'] ?? null) ?: ($pData['plan_date'] ?? null),
                'pallet_count'        => $palletsCount,
                'pallet_numbers'      => $palletNos,
                'products'            => $products,
                'excess_rolls'        => $excess,
                'excess_breakdown'    => implode(', ', $breakdownParts),
                'order_status'        => $st,
                'overall_status'      => strtolower($st),
                'progress_pct'        => $pct,
                'badge_class'         => $badgeClass,
                'status_text'         => $statusText,
                'initial_stock_rolls' => $initStk,
                'recoil_rolls'        => $recoil,
                'reslit_rolls'        => $reslit,
            ];
            $totalOrdersCount++;
        }

        // Sort orders so active/in-progress orders appear at the top, completed below
        usort($orderList, function ($a, $b) {
            $aDone = ($a['progress_pct'] >= 100) ? 1 : 0;
            $bDone = ($b['progress_pct'] >= 100) ? 1 : 0;
            if ($aDone !== $bDone) {
                return $aDone <=> $bDone;
            }
            $dateA = $a['latest_delivered'] ?: $a['first_date'];
            $dateB = $b['latest_delivered'] ?: $b['first_date'];
            return strcmp((string)$dateB, (string)$dateA);
        });

        if ($limit > 0 && count($orderList) > $limit) {
            $orderList = array_slice($orderList, 0, $limit);
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

<?php
/**
 * ProductionMonitoringModel.php
 * Model for Production Monitoring Dashboard (Real-time TV Screen)
 * Location: /monitor_screen/models/ProductionMonitoringModel.php
 */

class ProductionMonitoringModel
{
    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Format elapsed time into HH:MM:SS
     */
    public function formatElapsedTime(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours   = floor($seconds / 3600);
        $mins    = floor(($seconds % 3600) / 60);
        $secs    = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $hours, $mins, $secs);
    }

    /**
     * Helper: Get Customer Name/Codes for a Mother Coil or Slitting Product
     */
    public function resolveCustomerName($mother_id, $lot_no, $coil_no, $width = null): string
    {
        $conn = $this->conn;
        $customers = [];

        // 1. Check slitting_plans for this mother coil
        if ($mother_id > 0) {
            $stmt = $conn->prepare("
                SELECT customer_name 
                FROM slitting_plans 
                WHERE mother_coil_id = ? 
                  AND customer_name IS NOT NULL 
                  AND TRIM(customer_name) != '' 
                  AND TRIM(customer_name) != '-'
                ORDER BY sort_order ASC, id ASC
            ");
            if ($stmt) {
                $stmt->bind_param("i", $mother_id);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $c = trim($row['customer_name']);
                    if ($c !== '' && $c !== '-' && !in_array($c, $customers, true)) {
                        $customers[] = $c;
                    }
                }
                $stmt->close();
            }
        }

        // 2. Check slitting_product
        if (empty($customers)) {
            if ($mother_id > 0) {
                $stmt_sp = $conn->prepare("
                    SELECT customer_name 
                    FROM slitting_product 
                    WHERE mother_id = ? 
                      AND (is_voided = 0 OR is_voided IS NULL)
                      AND customer_name IS NOT NULL 
                      AND TRIM(customer_name) != '' 
                      AND TRIM(customer_name) != '-'
                    ORDER BY id ASC
                ");
                if ($stmt_sp) {
                    $stmt_sp->bind_param("i", $mother_id);
                    $stmt_sp->execute();
                    $res_sp = $stmt_sp->get_result();
                    while ($row = $res_sp->fetch_assoc()) {
                        $c = trim($row['customer_name']);
                        if ($c !== '' && $c !== '-' && !in_array($c, $customers, true)) {
                            $customers[] = $c;
                        }
                    }
                    $stmt_sp->close();
                }
            } elseif (!empty($lot_no) && !empty($coil_no)) {
                $stmt_sp = $conn->prepare("
                    SELECT customer_name 
                    FROM slitting_product 
                    WHERE lot_no = ? AND coil_no = ? 
                      AND (is_voided = 0 OR is_voided IS NULL)
                      AND customer_name IS NOT NULL 
                      AND TRIM(customer_name) != '' 
                      AND TRIM(customer_name) != '-'
                    ORDER BY id ASC
                ");
                if ($stmt_sp) {
                    $stmt_sp->bind_param("ss", $lot_no, $coil_no);
                    $stmt_sp->execute();
                    $res_sp = $stmt_sp->get_result();
                    while ($row = $res_sp->fetch_assoc()) {
                        $c = trim($row['customer_name']);
                        if ($c !== '' && $c !== '-' && !in_array($c, $customers, true)) {
                            $customers[] = $c;
                        }
                    }
                    $stmt_sp->close();
                }
            }
        }

        // 3. Fallback: nci_product_mapping check
        if (empty($customers)) {
            $prefix = '';
            $coil_no_clean = trim((string)$coil_no);
            if (strpos($coil_no_clean, '-') !== false) {
                $prefix = strtoupper(trim(explode('-', $coil_no_clean)[0]));
            } else {
                preg_match('/^[A-Za-z]+/', $coil_no_clean, $m);
                $prefix = strtoupper($m[0] ?? '');
            }

            if ($prefix !== '' && $width > 0) {
                $int_code = $prefix . '-' . (int)$width;
                $stmt = $conn->prepare("SELECT customer FROM nci_product_mapping WHERE internal_code = ? OR internal_code LIKE ? LIMIT 1");
                if ($stmt) {
                    $like_code = $prefix . '-%';
                    $stmt->bind_param("ss", $int_code, $like_code);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($row = $res->fetch_assoc()) {
                        $c = trim($row['customer']);
                        if ($c !== '' && $c !== '-') {
                            $customers[] = $c;
                        }
                    }
                    $stmt->close();
                }
            }
        }

        return !empty($customers) ? implode(', ', $customers) : '-';
    }

    /**
     * Helper: Get list of active rolls with width, customer name, and SO/Ref No for running/packing mother coil
     */
    public function getActiveRollList($mother_id, $lot_no, $coil_no): array
    {
        $conn = $this->conn;
        $rolls = [];

        // 1. Check slitting_product first
        if ($mother_id > 0) {
            $stmt = $conn->prepare("
                SELECT roll_no, width, customer_name, ref_no, is_completed
                FROM slitting_product
                WHERE mother_id = ? AND (is_voided = 0 OR is_voided IS NULL)
                ORDER BY id ASC
            ");
            if ($stmt) {
                $stmt->bind_param("i", $mother_id);
                $stmt->execute();
                $res = $stmt->get_result();
                $counter = 1;
                while ($row = $res->fetch_assoc()) {
                    $w   = (float)$row['width'];
                    $c   = trim((string)($row['customer_name'] ?? ''));
                    $r   = trim((string)($row['ref_no'] ?? ''));

                    $rolls[] = [
                        'roll_no'       => 'R' . $counter++,
                        'width'         => $w,
                        'width_fmt'     => ($w > 0) ? (number_format($w, 0) . ' mm') : '-',
                        'customer_name' => ($c !== '' && $c !== '-') ? $c : '-',
                        'ref_no'        => ($r !== '' && $r !== '-') ? $r : '-'
                    ];
                }
                $stmt->close();
            }
        } elseif (!empty($lot_no) && !empty($coil_no)) {
            $stmt = $conn->prepare("
                SELECT roll_no, width, customer_name, ref_no, is_completed
                FROM slitting_product
                WHERE lot_no = ? AND coil_no = ? AND (is_voided = 0 OR is_voided IS NULL)
                ORDER BY id ASC
            ");
            if ($stmt) {
                $stmt->bind_param("ss", $lot_no, $coil_no);
                $stmt->execute();
                $res = $stmt->get_result();
                $counter = 1;
                while ($row = $res->fetch_assoc()) {
                    $w   = (float)$row['width'];
                    $c   = trim((string)($row['customer_name'] ?? ''));
                    $r   = trim((string)($row['ref_no'] ?? ''));

                    $rolls[] = [
                        'roll_no'       => 'R' . $counter++,
                        'width'         => $w,
                        'width_fmt'     => ($w > 0) ? (number_format($w, 0) . ' mm') : '-',
                        'customer_name' => ($c !== '' && $c !== '-') ? $c : '-',
                        'ref_no'        => ($r !== '' && $r !== '-') ? $r : '-'
                    ];
                }
                $stmt->close();
            }
        }

        // 2. Fallback / enrich from slitting_plans if empty or if fields need filling
        if ($mother_id > 0) {
            $stmt = $conn->prepare("
                SELECT roll_seq, planned_width, customer_name, ref_no
                FROM slitting_plans
                WHERE mother_coil_id = ?
                ORDER BY sort_order ASC, id ASC
            ");
            if ($stmt) {
                $stmt->bind_param("i", $mother_id);
                $stmt->execute();
                $res = $stmt->get_result();
                $planRows = [];
                while ($pRow = $res->fetch_assoc()) {
                    $planRows[] = $pRow;
                }
                $stmt->close();

                if (empty($rolls) && !empty($planRows)) {
                    $counter = 1;
                    foreach ($planRows as $pRow) {
                        $w = (float)($pRow['planned_width'] ?? 0);
                        $c = trim((string)($pRow['customer_name'] ?? ''));
                        $r = trim((string)($pRow['ref_no'] ?? ''));
                        $rolls[] = [
                            'roll_no'       => 'R' . $counter++,
                            'width'         => $w,
                            'width_fmt'     => ($w > 0) ? (number_format($w, 0) . ' mm') : '-',
                            'customer_name' => ($c !== '' && $c !== '-') ? $c : '-',
                            'ref_no'        => ($r !== '' && $r !== '-') ? $r : '-'
                        ];
                    }
                } elseif (!empty($rolls) && !empty($planRows)) {
                    foreach ($rolls as $idx => &$rItem) {
                        if (isset($planRows[$idx])) {
                            if ($rItem['customer_name'] === '-' || $rItem['customer_name'] === '') {
                                $pc = trim((string)($planRows[$idx]['customer_name'] ?? ''));
                                if ($pc !== '' && $pc !== '-') $rItem['customer_name'] = $pc;
                            }
                            if ($rItem['ref_no'] === '-' || $rItem['ref_no'] === '') {
                                $pr = trim((string)($planRows[$idx]['ref_no'] ?? ''));
                                if ($pr !== '' && $pr !== '-') $rItem['ref_no'] = $pr;
                            }
                        }
                    }
                    unset($rItem);
                }
            }
        }

        return $rolls;
    }

    /**
     * Helper: Resolve Process Details (Slitting vs Recoiling vs Reslit)
     */
    public function resolveProcessDetails($is_recoiled, $is_reslitted, $original_source): array
    {
        $src = strtolower((string)$original_source);
        if ($is_recoiled == 1 || $src === 'recoiling') {
            return [
                'process_type'        => 'Recoiling',
                'process_badge_class' => 'bg-warning text-dark',
                'process_icon'        => 'bi-arrow-repeat'
            ];
        }
        if ($is_reslitted == 1 || $src === 'reslit') {
            return [
                'process_type'        => 'Reslit',
                'process_badge_class' => 'bg-purple text-white',
                'process_icon'        => 'bi-intersect'
            ];
        }
        return [
            'process_type'        => 'Slitting',
            'process_badge_class' => 'bg-info text-dark',
            'process_icon'        => 'bi-scissors'
        ];
    }

    /**
     * Calculate 24-Hour Production Length exclusively from Completed Mother Coil length
     */
    public function calculate24HourMotherCoilLength(): float
    {
        $conn = $this->conn;
        $now = time();
        $currentDayOfWeek = (int)date('N', $now);
        $currentHourMin   = date('H:i:s', $now);

        if ($currentHourMin >= '07:01:00') {
            $cycleStartTs = strtotime(date('Y-m-d 07:01:00', $now));
            $cycleEndTs   = strtotime('+1 day 07:00:00', strtotime(date('Y-m-d 07:00:00', $now)));
        } else {
            $cycleStartTs = strtotime('-1 day 07:01:00', strtotime(date('Y-m-d 07:00:00', $now)));
            $cycleEndTs   = strtotime(date('Y-m-d 07:00:00', $now));
        }

        $cycleStart = date('Y-m-d H:i:s', $cycleStartTs);
        $cycleEnd   = date('Y-m-d H:i:s', $cycleEndTs);

        $sql = "
            SELECT COALESCE(SUM(mc_len.length), 0) AS total_meters
            FROM (
                SELECT mc.id, mc.length, COALESCE(mc.date_out, MIN(sp.date_in)) AS slit_time
                FROM mother_coil mc
                JOIN slitting_product sp ON sp.mother_id = mc.id
                WHERE (sp.is_voided = 0 OR sp.is_voided IS NULL)
                  AND (sp.source IS NULL OR sp.source NOT IN ('recoiling', 'reslit'))
                  AND (sp.original_source IS NULL OR sp.original_source NOT IN ('recoiling', 'reslit'))
                  AND (sp.is_recoiled = 0 OR sp.is_recoiled IS NULL)
                  AND (sp.is_reslitted = 0 OR sp.is_reslitted IS NULL)
                  AND sp.recoiling_id IS NULL
                  AND sp.parent_slit_id IS NULL
                  AND (sp.is_completed = 1 OR (sp.actual_length IS NOT NULL AND sp.actual_length > 0))
                GROUP BY mc.id, mc.length
                HAVING slit_time >= ? AND slit_time <= ?
            ) AS mc_len
        ";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ss", $cycleStart, $cycleEnd);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $stmt->close();
                return (float)$row['total_meters'];
            }
            $stmt->close();
        }

        return 0.0;
    }

    /**
     * Calculate Weekly Mother Coil Length
     */
    public function calculateWeeklyMotherCoilLength(): float
    {
        $conn = $this->conn;
        $now = time();
        $currentDayOfWeek = (int)date('N', $now);
        $currentHourMin   = date('H:i:s', $now);

        if ($currentDayOfWeek === 1 && $currentHourMin < '07:01:00') {
            $mondayTimestamp = strtotime('last monday 07:01:00', $now);
        } else {
            $mondayTimestamp = strtotime('monday this week 07:01:00', $now);
        }

        $mondayCycleStart = date('Y-m-d H:i:s', $mondayTimestamp);
        $sundayCycleEnd   = date('Y-m-d H:i:s', strtotime('+7 days -1 second', $mondayTimestamp));

        $sql = "
            SELECT COALESCE(SUM(mc_len.length), 0) AS total_meters
            FROM (
                SELECT mc.id, mc.length, COALESCE(mc.date_out, MIN(sp.date_in)) AS slit_time
                FROM mother_coil mc
                JOIN slitting_product sp ON sp.mother_id = mc.id
                WHERE (sp.is_voided = 0 OR sp.is_voided IS NULL)
                  AND (sp.source IS NULL OR sp.source NOT IN ('recoiling', 'reslit'))
                  AND (sp.original_source IS NULL OR sp.original_source NOT IN ('recoiling', 'reslit'))
                  AND (sp.is_recoiled = 0 OR sp.is_recoiled IS NULL)
                  AND (sp.is_reslitted = 0 OR sp.is_reslitted IS NULL)
                  AND sp.recoiling_id IS NULL
                  AND sp.parent_slit_id IS NULL
                  AND (sp.is_completed = 1 OR (sp.actual_length IS NOT NULL AND sp.actual_length > 0))
                GROUP BY mc.id, mc.length
                HAVING slit_time >= ? AND slit_time <= ?
            ) AS mc_len
        ";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ss", $mondayCycleStart, $sundayCycleEnd);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $stmt->close();
                return (float)$row['total_meters'];
            }
            $stmt->close();
        }

        return 0.0;
    }

    /**
     * Check if production has occurred after midnight
     */
    public function hasProductionAfterMidnight(string $cycleStartDate): bool
    {
        $conn = $this->conn;
        $dayStartTs = strtotime($cycleStartDate);
        $midnightTs = strtotime('+17 hours -60 seconds', $dayStartTs); // ~00:00:00 midnight
        $dayEndTs   = strtotime('+24 hours', $dayStartTs);

        $midnightStr = date('Y-m-d H:i:s', $midnightTs);
        $dayEndStr   = date('Y-m-d H:i:s', $dayEndTs);

        $stmtCompleted = $conn->prepare("
            SELECT 1
            FROM (
                SELECT mc.id, COALESCE(mc.date_out, MIN(sp.date_in)) AS slit_time
                FROM mother_coil mc
                JOIN slitting_product sp ON sp.mother_id = mc.id
                WHERE (sp.is_voided = 0 OR sp.is_voided IS NULL)
                  AND (sp.source IS NULL OR sp.source NOT IN ('recoiling', 'reslit'))
                  AND (sp.original_source IS NULL OR sp.original_source NOT IN ('recoiling', 'reslit'))
                  AND (sp.is_recoiled = 0 OR sp.is_recoiled IS NULL)
                  AND (sp.is_reslitted = 0 OR sp.is_reslitted IS NULL)
                  AND sp.recoiling_id IS NULL
                  AND sp.parent_slit_id IS NULL
                  AND (sp.is_completed = 1 OR (sp.actual_length IS NOT NULL AND sp.actual_length > 0))
                GROUP BY mc.id
                HAVING slit_time > ? AND slit_time <= ?
            ) t
            LIMIT 1
        ");

        if ($stmtCompleted) {
            $stmtCompleted->bind_param("ss", $midnightStr, $dayEndStr);
            $stmtCompleted->execute();
            $res = $stmtCompleted->get_result();
            if ($res && $res->num_rows > 0) {
                $stmtCompleted->close();
                return true;
            }
            $stmtCompleted->close();
        }

        $stmtRunning = $conn->prepare("
            SELECT 1
            FROM slitting_product sp_run
            WHERE (sp_run.is_voided = 0 OR sp_run.is_voided IS NULL)
              AND (sp_run.is_completed = 0 OR sp_run.actual_length IS NULL OR sp_run.actual_length = 0)
              AND (sp_run.source IS NULL OR sp_run.source NOT IN ('recoiling', 'reslit'))
              AND (sp_run.original_source IS NULL OR sp_run.original_source NOT IN ('recoiling', 'reslit'))
              AND (sp_run.is_recoiled = 0 OR sp_run.is_recoiled IS NULL)
              AND (sp_run.is_reslitted = 0 OR sp_run.is_reslitted IS NULL)
              AND sp_run.recoiling_id IS NULL
              AND sp_run.parent_slit_id IS NULL
              AND sp_run.date_in > ? AND sp_run.date_in <= ?
            LIMIT 1
        ");

        if ($stmtRunning) {
            $stmtRunning->bind_param("ss", $midnightStr, $dayEndStr);
            $stmtRunning->execute();
            $res = $stmtRunning->get_result();
            if ($res && $res->num_rows > 0) {
                $stmtRunning->close();
                return true;
            }
            $stmtRunning->close();
        }

        return false;
    }

    /**
     * Get Recoiling Daily Coil & Roll Counts
     */
    public function getRecoilingDailyCoilCount(): array
    {
        $conn = $this->conn;
        $now = time();
        $currentHourMin = date('H:i:s', $now);

        if ($currentHourMin >= '07:01:00') {
            $dayStartTs = strtotime(date('Y-m-d 07:01:00', $now));
            $dayEndTs   = strtotime('+1 day 07:00:00', strtotime(date('Y-m-d 07:00:00', $now)));
            $prodDate   = date('Y-m-d', $now);
        } else {
            $dayStartTs = strtotime('-1 day 07:01:00', strtotime(date('Y-m-d 07:00:00', $now)));
            $dayEndTs   = strtotime(date('Y-m-d 07:00:00', $now));
            $prodDate   = date('Y-m-d', strtotime('-1 day', $now));
        }

        $dayStart = date('Y-m-d H:i:s', $dayStartTs);
        $dayEnd   = date('Y-m-d H:i:s', $dayEndTs);

        $sql = "
            SELECT 
                COUNT(DISTINCT rp.lot_no, rp.coil_no) AS total_coils,
                COUNT(DISTINCT CASE WHEN rp.status = 'completed' THEN CONCAT(rp.lot_no, '-', rp.coil_no) END) AS completed_coils,
                COUNT(DISTINCT CASE WHEN rp.status = 'in_progress' THEN CONCAT(rp.lot_no, '-', rp.coil_no) END) AS in_progress_coils,
                COUNT(DISTINCT rp.lot_no, rp.coil_no, rp.roll_no) AS distinct_rolls,
                COUNT(rp.id) AS total_rolls,
                SUM(CASE WHEN rp.cut_type = 'rewinding' THEN 1 ELSE 0 END) AS rewinding_count,
                SUM(CASE WHEN rp.cut_type IN ('normal', 'cut_into_2') OR (rp.cut_type IS NULL) THEN 1 ELSE 0 END) AS cut_defect_count
            FROM recoiling_product rp
            WHERE (
                (rp.status = 'completed' AND rp.completed_at >= ? AND rp.completed_at <= ?)
                OR (rp.status = 'in_progress' AND COALESCE(rp.started_at, rp.date_in) >= ? AND COALESCE(rp.started_at, rp.date_in) <= ?)
            )
        ";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ssss", $dayStart, $dayEnd, $dayStart, $dayEnd);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $stmt->close();
                return [
                    'prod_date'           => $prodDate,
                    'prod_date_formatted' => date('d M Y', strtotime($prodDate)),
                    'total_coils'         => (int)($row['total_coils'] ?? 0),
                    'completed_coils'     => (int)($row['completed_coils'] ?? 0),
                    'in_progress_coils'   => (int)($row['in_progress_coils'] ?? 0),
                    'distinct_rolls'      => (int)($row['distinct_rolls'] ?? 0),
                    'total_rolls'         => (int)($row['total_rolls'] ?? 0),
                    'rewinding_count'     => (int)($row['rewinding_count'] ?? 0),
                    'cut_defect_count'    => (int)($row['cut_defect_count'] ?? 0),
                ];
            }
            $stmt->close();
        }

        return [
            'prod_date'           => $prodDate,
            'prod_date_formatted' => date('d M Y', strtotime($prodDate)),
            'total_coils'         => 0,
            'completed_coils'     => 0,
            'in_progress_coils'   => 0,
            'distinct_rolls'      => 0,
            'total_rolls'         => 0,
            'rewinding_count'     => 0,
            'cut_defect_count'    => 0,
        ];
    }

    /**
     * Primary Data Fetcher: Full monitoring dashboard payload
     */
    public function getMonitoringData(): array
    {
        $conn = $this->conn;

        // ── Fetch ALL Active/Pending Coils for Slitting ──
        $all_active_jobs = [];

        // 1. Pending Slitting Coils
        $slitting_pending_res = $conn->query("
            SELECT 
                'Slitting' AS proc_name,
                sp.mother_id,
                sp.lot_no,
                sp.coil_no,
                sp.product,
                GROUP_CONCAT(DISTINCT CASE WHEN sp.customer_name IS NOT NULL AND TRIM(sp.customer_name) != '' AND TRIM(sp.customer_name) != '-' THEN TRIM(sp.customer_name) END SEPARATOR ', ') AS customer_name,
                MIN(sp.date_in) AS start_time,
                NULL AS active_started_at,
                'pending' AS job_status,
                'IN (pending)' AS sub_status,
                mc.width AS mother_width,
                mc.length AS mother_length,
                0 AS is_recoiled,
                0 AS is_reslitted,
                'raw_material' AS original_source,
                0 AS stock_id,
                COUNT(sp.id) AS total_rolls,
                SUM(CASE WHEN sp.is_completed = 1 THEN 1 ELSE 0 END) AS completed_rolls,
                COALESCE(mc.date_created, mc.date_in) AS mother_received_at
            FROM slitting_product sp
            LEFT JOIN mother_coil mc ON mc.id = sp.mother_id
            WHERE (sp.is_voided = 0 OR sp.is_voided IS NULL)
              AND (sp.source IS NULL OR sp.source NOT IN ('recoiling', 'reslit'))
              AND (sp.original_source IS NULL OR sp.original_source NOT IN ('recoiling', 'reslit'))
              AND (sp.is_recoiled = 0 OR sp.is_recoiled IS NULL)
              AND (sp.is_reslitted = 0 OR sp.is_reslitted IS NULL)
              AND sp.recoiling_id IS NULL
              AND sp.parent_slit_id IS NULL
              AND sp.is_completed = 0
            GROUP BY sp.mother_id, sp.lot_no, sp.coil_no, sp.product, mc.width, mc.length, mc.date_created, mc.date_in
        ");

        if ($slitting_pending_res) {
            while ($row = $slitting_pending_res->fetch_assoc()) {
                $all_active_jobs[] = $row;
            }
        }

        // Sort all active jobs by FIFO (oldest start_time / date_in first)
        usort($all_active_jobs, function ($a, $b) {
            $timeA = strtotime($a['start_time'] ?? $a['mother_received_at'] ?? 'now');
            $timeB = strtotime($b['start_time'] ?? $b['mother_received_at'] ?? 'now');
            return $timeA <=> $timeB;
        });

        // ── STEP 1: DETECT PACKING (COMPLETED) STATE ───────────────────────────
        $last_completed_item = null;
        $remaining_packing_sec = 0;

        $last_completed_res = $conn->query("
            SELECT 
                sp.mother_id,
                sp.lot_no,
                sp.coil_no,
                sp.product,
                GROUP_CONCAT(DISTINCT CASE WHEN sp.customer_name IS NOT NULL AND TRIM(sp.customer_name) != '' AND TRIM(sp.customer_name) != '-' THEN TRIM(sp.customer_name) END SEPARATOR ', ') AS customer_name,
                mc.width AS mother_width,
                mc.length AS mother_length,
                0 AS is_recoiled,
                0 AS is_reslitted,
                'raw_material' AS original_source,
                0 AS stock_id,
                COUNT(sp.id) AS total_rolls,
                MAX(sp.date_out) AS last_finished_at,
                COALESCE(mc.date_created, mc.date_in) AS mother_received_at
            FROM slitting_product sp
            LEFT JOIN mother_coil mc ON mc.id = sp.mother_id
            WHERE (sp.is_voided = 0 OR sp.is_voided IS NULL)
              AND (sp.source IS NULL OR sp.source NOT IN ('recoiling', 'reslit'))
              AND (sp.original_source IS NULL OR sp.original_source NOT IN ('recoiling', 'reslit'))
              AND (sp.is_recoiled = 0 OR sp.is_recoiled IS NULL)
              AND (sp.is_reslitted = 0 OR sp.is_reslitted IS NULL)
              AND sp.recoiling_id IS NULL
              AND sp.parent_slit_id IS NULL
              AND sp.is_completed = 1
              AND sp.date_out IS NOT NULL
              AND sp.date_out >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            GROUP BY sp.mother_id, sp.lot_no, sp.coil_no, sp.product, mc.width, mc.length, mc.date_created, mc.date_in
            HAVING (
                SELECT COUNT(*) FROM slitting_product sp_check
                WHERE sp_check.mother_id = sp.mother_id
                  AND (sp_check.is_voided = 0 OR sp_check.is_voided IS NULL)
                  AND sp_check.is_completed = 0
            ) = 0
            ORDER BY last_finished_at DESC
            LIMIT 1
        ");

        if ($last_completed_res && $last_completed_res->num_rows > 0) {
            $last_completed_item = $last_completed_res->fetch_assoc();
            $finished_ts = strtotime($last_completed_item['last_finished_at']);
            $elapsed_since_finish = time() - $finished_ts;
            $remaining_packing_sec = max(0, 300 - $elapsed_since_finish);
        }

        $running_data = [];
        $queued_in_pending = [];

        if ($last_completed_item && $remaining_packing_sec > 0) {
            // ── STATE: PACKING ──
            $mother_id = (int)$last_completed_item['mother_id'];
            $lot_no    = trim((string)$last_completed_item['lot_no']);
            $coil_no   = trim((string)$last_completed_item['coil_no']);

            $custName = $this->resolveCustomerName($mother_id, $lot_no, $coil_no, $last_completed_item['mother_width']);
            $procInfo = $this->resolveProcessDetails(
                $last_completed_item['is_recoiled'],
                $last_completed_item['is_reslitted'],
                $last_completed_item['original_source']
            );

            $mWidth  = (float)($last_completed_item['mother_width'] ?? 0);
            $mLength = (float)($last_completed_item['mother_length'] ?? 0);

            $startTimeStr = $last_completed_item['last_finished_at'] ?? null;
            $startTimestamp = $startTimeStr ? strtotime($startTimeStr) : time();

            $running_data = [
                'has_running'       => true,
                'mother_id'         => $mother_id,
                'lot_no'            => $lot_no,
                'coil_no'           => $coil_no,
                'coil_id_display'   => $lot_no . ' - ' . $coil_no,
                'product_type'      => $last_completed_item['product'] ?: '-',
                'mother_length'     => $mLength,
                'mother_length_formatted' => ($mLength > 0) ? (number_format($mLength, 0) . ' m') : '-',
                'mother_width'      => $mWidth,
                'mother_width_formatted'  => ($mWidth > 0) ? (number_format($mWidth, 0) . ' mm') : '-',
                'customer_name'     => $custName,
                'process_type'      => $procInfo['process_type'],
                'process_badge_class' => $procInfo['process_badge_class'],
                'process_icon'       => $procInfo['process_icon'],
                'status'            => 'Packing',
                'sub_status'        => 'Finished Goods Stock',
                'status_badge_class'=> 'bg-warning text-dark',
                'start_time'        => $startTimeStr ? date('Y-m-d H:i:s', $startTimestamp) : '-',
                'start_time_fmt'    => $startTimeStr ? date('h:i A', $startTimestamp) : '-',
                'start_timestamp'   => $startTimestamp,
                'elapsed_seconds'   => max(0, time() - $startTimestamp),
                'elapsed_formatted' => $this->formatElapsedTime(time() - $startTimestamp),
                'is_packing'        => true,
                'packing_remaining_seconds' => $remaining_packing_sec,
                'total_rolls'       => (int)$last_completed_item['total_rolls'],
                'completed_rolls'   => (int)$last_completed_item['total_rolls'],
                'roll_list'         => $this->getActiveRollList($mother_id, $lot_no, $coil_no)
            ];

            $queued_in_pending = $all_active_jobs;

        } elseif (!empty($all_active_jobs)) {
            // ── STATE: RUNNING ──
            $active_item = $all_active_jobs[0];
            $mother_id   = (int)$active_item['mother_id'];
            $lot_no      = trim((string)$active_item['lot_no']);
            $coil_no     = trim((string)$active_item['coil_no']);

            $custName = $this->resolveCustomerName($mother_id, $lot_no, $coil_no, $active_item['mother_width']);
            $procInfo = $this->resolveProcessDetails(
                $active_item['is_recoiled'],
                $active_item['is_reslitted'],
                $active_item['original_source']
            );

            $mWidth  = (float)($active_item['mother_width'] ?? 0);
            $mLength = (float)($active_item['mother_length'] ?? 0);

            $now = time();
            $effectiveStartTs = $now;
            $currentHourMin = date('H:i:s', $now);

            if ($currentHourMin >= '07:01:00') {
                $cycleStartTs = strtotime(date('Y-m-d 07:01:00', $now));
            } else {
                $cycleStartTs = strtotime('-1 day 07:01:00', strtotime(date('Y-m-d 07:00:00', $now)));
            }

            $rawStart = $active_item['start_time'] ?? $active_item['active_started_at'] ?? null;
            if ($rawStart) {
                $rawTs = strtotime($rawStart);
                if ($rawTs >= $cycleStartTs && $rawTs <= $now) {
                    $effectiveStartTs = $rawTs;
                } else {
                    $effectiveStartTs = $cycleStartTs;
                }
            } else {
                $effectiveStartTs = $cycleStartTs;
            }

            $elapsedSec = max(0, $now - $effectiveStartTs);

            $running_data = [
                'has_running'          => true,
                'mother_id'            => $mother_id,
                'lot_no'               => $lot_no,
                'coil_no'              => $coil_no,
                'coil_id_display'      => $lot_no . ' - ' . $coil_no,
                'product_type'         => $active_item['product'] ?: '-',
                'mother_length'        => $mLength,
                'mother_length_formatted' => ($mLength > 0) ? (number_format($mLength, 0) . ' m') : '-',
                'mother_width'         => $mWidth,
                'mother_width_formatted'  => ($mWidth > 0) ? (number_format($mWidth, 0) . ' mm') : '-',
                'customer_name'        => $custName,
                'process_type'         => $procInfo['process_type'],
                'process_badge_class'    => $procInfo['process_badge_class'],
                'process_icon'          => $procInfo['process_icon'],
                'status'               => 'Running',
                'sub_status'           => $active_item['sub_status'] ?? 'IN (pending)',
                'status_badge_class'   => 'bg-primary text-white',
                'start_time'           => date('Y-m-d H:i:s', $effectiveStartTs),
                'start_time_fmt'       => date('h:i A', $effectiveStartTs),
                'start_timestamp'      => $effectiveStartTs,
                'elapsed_seconds'      => max(0, $elapsedSec),
                'elapsed_formatted'    => $this->formatElapsedTime($elapsedSec),
                'is_packing'           => false,
                'packing_remaining_seconds' => 0,
                'total_rolls'          => (int)$active_item['total_rolls'],
                'completed_rolls'      => (int)$active_item['completed_rolls'],
                'roll_list'            => $this->getActiveRollList($mother_id, $lot_no, $coil_no)
            ];

            $queued_in_pending = array_slice($all_active_jobs, 1);

        } else {
            // ── IDLE STATE ──
            $running_data = [
                'has_running' => false,
                'message'     => 'No coil currently in active production. Machine Idle.'
            ];
            $queued_in_pending = [];
        }

        // Build waiting list display
        $waiting_list = [];
        $pos = 1;
        foreach ($queued_in_pending as $q_item) {
            $m_id   = (int)$q_item['mother_id'];
            $l_no   = trim((string)$q_item['lot_no']);
            $c_no   = trim((string)$q_item['coil_no']);
            $m_width = (float)($q_item['mother_width'] ?? 0);
            $cName  = $this->resolveCustomerName($m_id, $l_no, $c_no, $m_width);

            $pInfo = $this->resolveProcessDetails(
                $q_item['is_recoiled'],
                $q_item['is_reslitted'],
                $q_item['original_source']
            );

            $rec_at = $q_item['mother_received_at'] ?? $q_item['start_time'] ?? null;
            $len    = (float)($q_item['mother_length'] ?? 0);

            $status_label = 'IN (pending)';
            $status_badge = 'bg-warning text-dark';
            $status_desc  = 'Prepared';

            $waiting_list[] = [
                'pos'                 => $pos++,
                'stock_id'            => (int)($q_item['stock_id'] ?? 0),
                'mother_id'           => $m_id,
                'coil_id_display'     => $l_no . ' - ' . $c_no,
                'lot_no'              => $l_no,
                'coil_no'             => $c_no,
                'product_type'        => $q_item['product'] ?: '-',
                'length'              => $len,
                'length_formatted'    => ($len > 0) ? (number_format($len, 0) . ' m') : '-',
                'customer_name'       => $cName,
                'process_type'        => $pInfo['process_type'],
                'process_badge_class'  => $pInfo['process_badge_class'],
                'process_icon'         => $pInfo['process_icon'],
                'received_at'         => $rec_at,
                'received_formatted'  => $rec_at ? date('d M Y, h:i A', strtotime($rec_at)) : '-',
                'status_label'        => $status_label,
                'status_desc'         => $status_desc,
                'status_badge_class'  => $status_badge
            ];
        }

        // 24-Hour & Shift Performance Metrics
        $now = time();
        $currentHourMin = date('H:i:s', $now);
        $currentDayOfWeek = (int)date('N', $now);
        $isFriday = ($currentDayOfWeek === 5);

        if ($currentHourMin >= '07:01:00') {
            $cycleStartTs = strtotime(date('Y-m-d 07:01:00', $now));
        } else {
            $cycleStartTs = strtotime('-1 day 07:01:00', strtotime(date('Y-m-d 07:00:00', $now)));
        }
        $cycleStartDate = date('Y-m-d H:i:s', $cycleStartTs);

        $shiftTarget = 5200.0;
        if (function_exists('getSystemSetting')) {
            $cfgVal = (float)getSystemSetting($conn, 'shift_target_meters', '5200');
            if ($cfgVal > 0) $shiftTarget = $cfgVal;
        }

        $hasPostMidnightProd = $this->hasProductionAfterMidnight($cycleStartDate);
        $isPostMidnight = ($now >= strtotime('+17 hours', $cycleStartTs));

        $shiftsMultiplier = ($hasPostMidnightProd || $isPostMidnight) ? 3 : 2;
        $target24hMeters  = $shiftTarget * $shiftsMultiplier;

        $lengthCompleted24h = $this->calculate24HourMotherCoilLength();
        $runningCoilLength = ($running_data['has_running'] && !empty($running_data['mother_length'])) ? (float)$running_data['mother_length'] : 0.0;
        $runningCoilCount  = ($running_data['has_running'] && !empty($running_data['total_rolls'])) ? (int)$running_data['total_rolls'] : 0;
        $runningCoilId     = $running_data['has_running'] ? ($running_data['coil_id_display'] ?? '') : '';

        $lengthProduced24h = $lengthCompleted24h + $runningCoilLength;
        $progressPct = ($target24hMeters > 0) ? min(100.0, round(($lengthProduced24h / $target24hMeters) * 100, 1)) : 0.0;
        $weeklyTotalMeters = $this->calculateWeeklyMotherCoilLength();

        $elapsedSeconds = max(0, $now - $cycleStartTs);
        $elapsedHours   = $elapsedSeconds / 3600.0;
        $hourlyTargetRate = round($shiftTarget / 8.0, 1);
        $totalScaleHours = ($shiftsMultiplier === 3) ? 24 : 16;
        $targetProducedMeters = min($target24hMeters, round($elapsedHours * $hourlyTargetRate));
        $targetHourPercentage = ($target24hMeters > 0) ? min(100.0, round(($targetProducedMeters / $target24hMeters) * 100, 1)) : 0.0;

        $length_tracking = [
            'length_produced_24h'   => round($lengthProduced24h, 1),
            'length_completed_24h'  => round($lengthCompleted24h, 1),
            'running_coil_count'    => $runningCoilCount,
            'running_coil_length'   => round($runningCoilLength, 1),
            'running_coil_id'       => $runningCoilId,
            'shift_target_meters'   => $shiftTarget,
            'target_24h_meters'     => $target24hMeters,
            'shifts_multiplier'     => $shiftsMultiplier,
            'has_post_midnight_prod'=> $hasPostMidnightProd,
            'is_friday'             => $isFriday,
            'progress_percentage'   => $progressPct,
            'weekly_total_meters'   => round($weeklyTotalMeters, 1),
            'hourly_target_rate'    => $hourlyTargetRate,
            'target_produced_meters'=> $targetProducedMeters,
            'target_hour_percentage'=> $targetHourPercentage,
            'elapsed_hours'         => round($elapsedHours, 2),
            'total_scale_hours'     => $totalScaleHours
        ];

        // Shift Summary
        $todayDate = date('Y-m-d');
        $shift_summary = [
            'date'         => $todayDate,
            'shift1'       => 0,
            'shift2'       => 0,
            'shift3'       => 0,
            'total'        => 0,
            'active_shift' => 1,
            's1_time'      => '07:00 - 15:00',
            's2_time'      => '15:00 - 23:00',
            's3_time'      => '23:00 - 07:00'
        ];

        $shift_res = $conn->query("
            SELECT 
                SUM(CASE WHEN TIME(date_in) >= '07:00:00' AND TIME(date_in) < '15:00:00' THEN 1 ELSE 0 END) AS shift1_count,
                SUM(CASE WHEN TIME(date_in) >= '15:00:00' AND TIME(date_in) < '23:00:00' THEN 1 ELSE 0 END) AS shift2_count,
                SUM(CASE WHEN TIME(date_in) >= '23:00:00' OR TIME(date_in) < '07:00:00' THEN 1 ELSE 0 END) AS shift3_count,
                COUNT(*) AS total_today
            FROM slitting_product
            WHERE DATE(date_in) = '$todayDate'
              AND (is_voided = 0 OR is_voided IS NULL)
        ");

        if ($shift_res && $row = $shift_res->fetch_assoc()) {
            $shift_summary['shift1'] = (int)($row['shift1_count'] ?? 0);
            $shift_summary['shift2'] = (int)($row['shift2_count'] ?? 0);
            $shift_summary['shift3'] = (int)($row['shift3_count'] ?? 0);
            $shift_summary['total']  = (int)($row['total_today'] ?? 0);
        }

        $currentTime = date('H:i:s');
        if ($currentTime >= '07:00:00' && $currentTime < '15:00:00') {
            $shift_summary['active_shift'] = 1;
        } elseif ($currentTime >= '15:00:00' && $currentTime < '23:00:00') {
            $shift_summary['active_shift'] = 2;
        } else {
            $shift_summary['active_shift'] = 3;
        }

        $recoil_summary = $this->getRecoilingDailyCoilCount();

        return [
            'success'         => true,
            'timestamp'       => date('Y-m-d H:i:s'),
            'running'         => $running_data,
            'waiting_list'    => $waiting_list,
            'waiting_count'   => count($waiting_list),
            'shift_summary'   => $shift_summary,
            'recoil_summary'  => $recoil_summary,
            'length_tracking' => $length_tracking
        ];
    }
}

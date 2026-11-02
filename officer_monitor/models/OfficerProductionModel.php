<?php
/**
 * OfficerProductionModel.php
 * Model for Officer Production Monitor (Weekly Performance & Target Management)
 * Location: /monitor_screen/models/OfficerProductionModel.php
 */

class OfficerProductionModel
{
    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Compute 7 daily slots (Isnin 7:01 AM - Selasa 7:00 AM) based on Date In
     */
    public function getWeeklyPerformanceSlots(float $shiftTarget = 5200.0, ?int $customMondayTimestamp = null): array
    {
        $conn = $this->conn;
        $now = time();

        if ($customMondayTimestamp !== null) {
            $mondayTimestamp = $customMondayTimestamp;
        } else {
            $currentDayOfWeek = (int)date('N', $now); // 1 (Mon) .. 7 (Sun)
            $currentHourMin   = date('H:i:s', $now);

            if ($currentDayOfWeek === 1 && $currentHourMin < '07:01:00') {
                $mondayTimestamp = strtotime('last monday 07:01:00', $now);
            } else {
                $mondayTimestamp = strtotime('monday this week 07:01:00', $now);
            }
        }

        $days = ['Isnin', 'Selasa', 'Rabu', 'Khamis', 'Jumaat', 'Sabtu', 'Ahad'];
        $slots = [];
        $weeklyProducedTotal = 0.0;
        $weeklyTargetTotal = 0.0;

        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(mc_len.length), 0) AS total_len
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
        ");

        $stmtMidnight = $conn->prepare("
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

        $stmtMidnightRunning = $conn->prepare("
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

        $stmtRecoil = $conn->prepare("
            SELECT 
                COUNT(DISTINCT rp.lot_no, rp.coil_no) AS recoil_coils,
                COUNT(DISTINCT rp.lot_no, rp.coil_no, rp.roll_no) AS distinct_rolls,
                COUNT(rp.id) AS recoil_rolls,
                SUM(CASE WHEN rp.cut_type = 'rewinding' THEN 1 ELSE 0 END) AS rewinding_count,
                SUM(CASE WHEN rp.cut_type IN ('normal', 'cut_into_2') OR (rp.cut_type IS NULL) THEN 1 ELSE 0 END) AS cut_defect_count
            FROM recoiling_product rp
            WHERE (
                (rp.status = 'completed' AND rp.completed_at >= ? AND rp.completed_at <= ?)
                OR (rp.status = 'in_progress' AND COALESCE(rp.started_at, rp.date_in) >= ? AND COALESCE(rp.started_at, rp.date_in) <= ?)
            )
        ");

        $activeDailyTarget = $shiftTarget * 2;
        $weeklyRecoilCoilsTotal = 0;
        $weeklyRecoilRollsTotal = 0;
        $weeklyRewindingTotal = 0;
        $weeklyCutDefectTotal = 0;
        $weeklyBladeChangesTotal = 0;

        // Compute blade changes across the 7-day cycle
        $bladeSlots = $this->computeBladeChangesForCycle($mondayTimestamp);

        for ($i = 0; $i < 7; $i++) {
            $slotStartTs = strtotime("+{$i} days", $mondayTimestamp);
            $slotEndTs   = strtotime("+1 day -1 second", $slotStartTs);

            $startStr = date('Y-m-d H:i:s', $slotStartTs);
            $endStr   = date('Y-m-d H:i:s', $slotEndTs);

            $startDisplay = date('d M, h:i A', $slotStartTs);
            $endDisplay   = date('d M, h:i A', $slotEndTs);

            $produced = 0.0;
            if ($stmt) {
                $stmt->bind_param("ss", $startStr, $endStr);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $produced = floatval($row['total_len']);
                }
            }

            $slotDayOfWeek = (int)date('N', $slotStartTs);
            $isFriday = ($slotDayOfWeek === 5);

            $midnightTs = strtotime('+17 hours -60 seconds', $slotStartTs);
            $midnightStr = date('Y-m-d H:i:s', $midnightTs);

            $hasPost12am = false;
            if ($stmtMidnight) {
                $stmtMidnight->bind_param("ss", $midnightStr, $endStr);
                $stmtMidnight->execute();
                $resMid = $stmtMidnight->get_result();
                if ($resMid && $resMid->num_rows > 0) {
                    $hasPost12am = true;
                }
            }

            if (!$hasPost12am && $stmtMidnightRunning) {
                $stmtMidnightRunning->bind_param("ss", $midnightStr, $endStr);
                $stmtMidnightRunning->execute();
                $resMidRun = $stmtMidnightRunning->get_result();
                if ($resMidRun && $resMidRun->num_rows > 0) {
                    $hasPost12am = true;
                }
            }

            $isCurrentSlot = ($now >= $slotStartTs && $now <= $slotEndTs);
            if (!$hasPost12am && $isCurrentSlot && $now > $midnightTs) {
                $hasPost12am = true;
            }

            $slotShifts = $hasPost12am ? 3 : 2;
            $slotTarget = $shiftTarget * $slotShifts;

            $recoilCoils = 0;
            $recoilRolls = 0;
            $rewindingCount = 0;
            $cutDefectCount = 0;

            if ($stmtRecoil) {
                $stmtRecoil->bind_param("ssss", $startStr, $endStr, $startStr, $endStr);
                $stmtRecoil->execute();
                $resRecoil = $stmtRecoil->get_result();
                if ($rowR = $resRecoil->fetch_assoc()) {
                    $recoilCoils = (int)($rowR['recoil_coils'] ?? 0);
                    $recoilRolls = (int)($rowR['recoil_rolls'] ?? 0);
                    $rewindingCount = (int)($rowR['rewinding_count'] ?? 0);
                    $cutDefectCount = (int)($rowR['cut_defect_count'] ?? 0);
                }
            }

            $weeklyRecoilCoilsTotal += $recoilCoils;
            $weeklyRecoilRollsTotal += $recoilRolls;
            $weeklyRewindingTotal   += $rewindingCount;
            $weeklyCutDefectTotal   += $cutDefectCount;

            $weeklyProducedTotal += $produced;
            $weeklyTargetTotal   += $slotTarget;

            $bladeData = $bladeSlots[$i] ?? ['blade_changes' => 0, 'details' => [], 'coils_count' => 0];
            $bladeChanges = (int)($bladeData['blade_changes'] ?? 0);
            $bladeDetails = $bladeData['details'] ?? [];
            $coilsCount   = (int)($bladeData['coils_count'] ?? 0);
            $weeklyBladeChangesTotal += $bladeChanges;

            $variance = $produced - $slotTarget;
            $percentage = ($slotTarget > 0) ? min(100.0, round(($produced / $slotTarget) * 100, 1)) : 0.0;
            $isToday = (date('Y-m-d', $now) === date('Y-m-d', $slotStartTs));

            $slots[] = [
                'day_index'                 => $i + 1,
                'day_name'                  => $days[$i],
                'start_time'                => $startStr,
                'end_time'                  => $endStr,
                'time_slot_label'           => "{$days[$i]} 07:01 AM - " . $days[($i+1)%7] . " 07:00 AM",
                'start_display'             => $startDisplay,
                'end_display'               => $endDisplay,
                'produced_meters'           => $produced,
                'target_meters'             => $slotTarget,
                'shifts_count'              => $slotShifts,
                'has_post_12am'             => $hasPost12am,
                'has_extended_shift'        => $hasPost12am,
                'is_friday'                 => $isFriday,
                'variance_meters'           => $variance,
                'percentage'                => $percentage,
                'recoil_coils'              => $recoilCoils,
                'recoil_rolls'              => $recoilRolls,
                'rewinding_count'           => $rewindingCount,
                'cut_defect_count'          => $cutDefectCount,
                'blade_changes'             => $bladeChanges,
                'blade_change_details'      => $bladeDetails,
                'coils_count'               => $coilsCount,
                'is_today'                  => $isToday
            ];
        }

        if ($stmt) $stmt->close();
        if ($stmtMidnight) $stmtMidnight->close();
        if ($stmtMidnightRunning) $stmtMidnightRunning->close();
        if ($stmtRecoil) $stmtRecoil->close();

        $overallPct = ($weeklyTargetTotal > 0) ? min(100.0, round(($weeklyProducedTotal / $weeklyTargetTotal) * 100, 1)) : 0.0;

        return [
            'monday_cycle_start'         => date('Y-m-d H:i:s', $mondayTimestamp),
            'sunday_cycle_end'           => date('Y-m-d H:i:s', strtotime("+7 days -1 second", $mondayTimestamp)),
            'shift_target_meters'        => $shiftTarget,
            'daily_target_meters'        => $activeDailyTarget,
            'weekly_target_meters'       => $weeklyTargetTotal,
            'weekly_produced_total'      => $weeklyProducedTotal,
            'weekly_recoil_coils_total'  => $weeklyRecoilCoilsTotal,
            'weekly_recoil_rolls_total'  => $weeklyRecoilRollsTotal,
            'weekly_rewinding_total'     => $weeklyRewindingTotal,
            'weekly_cut_defect_total'    => $weeklyCutDefectTotal,
            'weekly_blade_changes_total' => $weeklyBladeChangesTotal,
            'weekly_overall_pct'         => $overallPct,
            'slots'                      => $slots
        ];
    }

    /**
     * Get system setting helper
     */
    public function getSystemSetting(string $key, string $default = ''): string
    {
        if (function_exists('getSystemSetting')) {
            return getSystemSetting($this->conn, $key, $default);
        }
        return $default;
    }

    /**
     * Set system setting helper
     */
    public function setSystemSetting(string $key, string $value): bool
    {
        if (function_exists('setSystemSetting')) {
            return setSystemSetting($this->conn, $key, $value);
        }
        return false;
    }

    /**
     * Update Shift Target
     */
    public function updateTarget(float $newTarget, string $user = 'officer'): bool
    {
        $ok = $this->setSystemSetting('shift_target_meters', (string)$newTarget);
        if ($ok) {
            $remark = "Kemas kini sasaran syif kepada {$newTarget} m";
            $stmtLog = $this->conn->prepare("
                INSERT INTO process_log
                    (entity_type, entity_id, mother_id, from_status, to_status, performed_by, action_detail, remark)
                VALUES ('system_setting', 0, NULL, 'CONFIG', 'UPDATE', ?, 'update_shift_target', ?)
            ");
            if ($stmtLog) {
                $stmtLog->bind_param("ss", $user, $remark);
                $stmtLog->execute();
                $stmtLog->close();
            }
        }
        return $ok;
    }

    /**
     * Manual Reset Weekly Cycle
     */
    public function manualReset(string $user = 'officer'): string
    {
        $nowStr = date('Y-m-d H:i:s');
        $this->setSystemSetting('last_weekly_reset_at', $nowStr);

        $remark = "Pembersihan/Reset manual kitaran pengeluaran mingguan dilaksanakan oleh pegawai.";
        $stmtLog = $this->conn->prepare("
            INSERT INTO process_log
                (entity_type, entity_id, mother_id, from_status, to_status, performed_by, action_detail, remark)
            VALUES ('stock', 0, NULL, 'ACTIVE', 'MANUAL_RESET', ?, 'weekly_manual_reset', ?)
        ");
        if ($stmtLog) {
            $stmtLog->bind_param("ss", $user, $remark);
            $stmtLog->execute();
            $stmtLog->close();
        }

        return $nowStr;
    }

    /**
     * Compute blade changes per daily slot across a 7-day cycle starting at $mondayTimestamp
     *
     * A blade change occurs when the plan width configuration of a mother coil differs
     * from the immediately preceding coil in chronological slitting sequence.
     * Example:
     *   Coil A: 56, 56, 56, 56, 56, 56, 56, 56
     *   Coil B: 415, 415 -> Plan changed (+1 Blade Change)
     *   Coil C: 415, 415 -> Same plan (0 Blade Change)
     *
     * @param int $mondayTimestamp
     * @return array Map of slot index (0..6) => ['blade_changes' => int, 'details' => array, 'coils_count' => int]
     */
    public function computeBladeChangesForCycle(int $mondayTimestamp): array
    {
        $conn = $this->conn;
        $cycleStartStr = date('Y-m-d H:i:s', $mondayTimestamp);
        $cycleEndStr   = date('Y-m-d H:i:s', strtotime("+7 days -1 second", $mondayTimestamp));

        $slotResults = [];
        for ($s = 0; $s < 7; $s++) {
            $slotResults[$s] = [
                'blade_changes' => 0,
                'details'       => [],
                'coils_count'   => 0
            ];
        }

        // 1. Fetch all mother coils produced in the 7-day cycle in chronological order
        $sqlCoils = "
            SELECT 
                mc.id, 
                mc.coil_no, 
                COALESCE(mc.date_out, MIN(sp.date_in)) AS slit_time
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
            GROUP BY mc.id, mc.coil_no
            HAVING slit_time >= ? AND slit_time <= ?
            ORDER BY slit_time ASC, mc.id ASC
        ";

        $coils = [];
        $coilIds = [];
        $stmtCoils = $conn->prepare($sqlCoils);
        if ($stmtCoils) {
            $stmtCoils->bind_param("ss", $cycleStartStr, $cycleEndStr);
            $stmtCoils->execute();
            $res = $stmtCoils->get_result();
            while ($row = $res->fetch_assoc()) {
                $coils[] = $row;
                $coilIds[] = (int)$row['id'];
            }
            $stmtCoils->close();
        }

        if (empty($coils)) {
            return $slotResults;
        }

        // 2. Fetch slitting plans for these mother coils
        $idList = implode(',', $coilIds);
        $plansMap = []; // [mother_id => [width1, width2, ...]]

        $sqlPlans = "
            SELECT mother_coil_id, planned_width
            FROM slitting_plans
            WHERE mother_coil_id IN ($idList)
              AND (stock_id IS NULL OR stock_id = 0)
            ORDER BY mother_coil_id ASC, sort_order ASC, id ASC
        ";
        $resPlans = $conn->query($sqlPlans);
        if ($resPlans) {
            while ($r = $resPlans->fetch_assoc()) {
                $mId = (int)$r['mother_coil_id'];
                $plansMap[$mId][] = (float)$r['planned_width'];
            }
        }

        // 3. Fallback: if any coil has no planned_width in slitting_plans, get actual width from slitting_product
        $missingIds = [];
        foreach ($coilIds as $cid) {
            if (empty($plansMap[$cid])) {
                $missingIds[] = $cid;
            }
        }
        if (!empty($missingIds)) {
            $missingList = implode(',', $missingIds);
            $sqlProd = "
                SELECT mother_id, width
                FROM slitting_product
                WHERE mother_id IN ($missingList)
                  AND (is_voided = 0 OR is_voided IS NULL)
                  AND (source IS NULL OR source NOT IN ('recoiling', 'reslit'))
                  AND (original_source IS NULL OR original_source NOT IN ('recoiling', 'reslit'))
                  AND (is_recoiled = 0 OR is_recoiled IS NULL)
                  AND (is_reslitted = 0 OR is_reslitted IS NULL)
                  AND recoiling_id IS NULL
                  AND parent_slit_id IS NULL
                ORDER BY mother_id ASC, id ASC
            ";
            $resProd = $conn->query($sqlProd);
            if ($resProd) {
                while ($rp = $resProd->fetch_assoc()) {
                    $mId = (int)$rp['mother_id'];
                    $plansMap[$mId][] = (float)$rp['width'];
                }
            }
        }

        // 4. Build signatures: e.g. "56, 56, 56, 56, 56, 56, 56, 56" or "415, 415"
        $signatures = [];
        foreach ($coilIds as $cid) {
            $widths = $plansMap[$cid] ?? [];
            if (!empty($widths)) {
                $formatted = array_map(function($w) {
                    return rtrim(rtrim(number_format((float)$w, 2, '.', ''), '0'), '.');
                }, $widths);
                $signatures[$cid] = implode(', ', $formatted);
            } else {
                $signatures[$cid] = '';
            }
        }

        // 5. Lookback: check last coil slit prior to cycle start (within 48 hours)
        $priorLookback = date('Y-m-d H:i:s', strtotime('-48 hours', $mondayTimestamp));
        $prevSignature = null;
        $prevCoilNo = '';

        $sqlPrior = "
            SELECT 
                mc.id, 
                mc.coil_no, 
                COALESCE(mc.date_out, MIN(sp.date_in)) AS slit_time
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
            GROUP BY mc.id, mc.coil_no
            HAVING slit_time < ? AND slit_time >= ?
            ORDER BY slit_time DESC
            LIMIT 1
        ";
        $stmtPrior = $conn->prepare($sqlPrior);
        if ($stmtPrior) {
            $stmtPrior->bind_param("ss", $cycleStartStr, $priorLookback);
            $stmtPrior->execute();
            $resPrior = $stmtPrior->get_result();
            if ($rowPrior = $resPrior->fetch_assoc()) {
                $pId = (int)$rowPrior['id'];
                $prevCoilNo = $rowPrior['coil_no'];
                $pW = [];
                $qP = $conn->query("SELECT planned_width FROM slitting_plans WHERE mother_coil_id = {$pId} AND (stock_id IS NULL OR stock_id = 0) ORDER BY sort_order ASC, id ASC");
                if ($qP) {
                    while ($pRow = $qP->fetch_assoc()) $pW[] = (float)$pRow['planned_width'];
                }
                if (empty($pW)) {
                    $qProd = $conn->query("SELECT width FROM slitting_product WHERE mother_id = {$pId} AND (is_voided = 0 OR is_voided IS NULL) ORDER BY id ASC");
                    if ($qProd) {
                        while ($pRow = $qProd->fetch_assoc()) $pW[] = (float)$pRow['width'];
                    }
                }
                if (!empty($pW)) {
                    $pFormatted = array_map(function($w) {
                        return rtrim(rtrim(number_format((float)$w, 2, '.', ''), '0'), '.');
                    }, $pW);
                    $prevSignature = implode(', ', $pFormatted);
                }
            }
            $stmtPrior->close();
        }

        // 6. Iterate through coils in chronological order and assign blade changes to daily slots
        foreach ($coils as $mc) {
            $mId = (int)$mc['id'];
            $cNo = $mc['coil_no'];
            $timeStr = $mc['slit_time'];
            $ts = strtotime($timeStr);

            $dayOffset = (int)floor(($ts - $mondayTimestamp) / 86400);
            if ($dayOffset < 0) $dayOffset = 0;
            if ($dayOffset > 6) $dayOffset = 6;

            $slotResults[$dayOffset]['coils_count']++;

            $currentSig = $signatures[$mId] ?? '';
            if ($currentSig !== '') {
                if ($prevSignature !== null && $prevSignature !== '') {
                    if ($currentSig !== $prevSignature) {
                        $slotResults[$dayOffset]['blade_changes']++;
                        $slotResults[$dayOffset]['details'][] = "Coil {$cNo} [{$currentSig}] tukar bilah dari {$prevCoilNo} [{$prevSignature}]";
                    }
                }
                $prevSignature = $currentSig;
                $prevCoilNo = $cNo;
            }
        }

        return $slotResults;
    }
}

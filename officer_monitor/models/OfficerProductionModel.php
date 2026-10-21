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
                'is_today'                  => $isToday
            ];
        }

        if ($stmt) $stmt->close();
        if ($stmtMidnight) $stmtMidnight->close();
        if ($stmtMidnightRunning) $stmtMidnightRunning->close();
        if ($stmtRecoil) $stmtRecoil->close();

        $overallPct = ($weeklyTargetTotal > 0) ? min(100.0, round(($weeklyProducedTotal / $weeklyTargetTotal) * 100, 1)) : 0.0;

        return [
            'monday_cycle_start'        => date('Y-m-d H:i:s', $mondayTimestamp),
            'sunday_cycle_end'          => date('Y-m-d H:i:s', strtotime("+7 days -1 second", $mondayTimestamp)),
            'shift_target_meters'       => $shiftTarget,
            'daily_target_meters'       => $activeDailyTarget,
            'weekly_target_meters'      => $weeklyTargetTotal,
            'weekly_produced_total'     => $weeklyProducedTotal,
            'weekly_recoil_coils_total' => $weeklyRecoilCoilsTotal,
            'weekly_recoil_rolls_total' => $weeklyRecoilRollsTotal,
            'weekly_rewinding_total'    => $weeklyRewindingTotal,
            'weekly_cut_defect_total'   => $weeklyCutDefectTotal,
            'weekly_overall_pct'        => $overallPct,
            'slots'                     => $slots
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
}

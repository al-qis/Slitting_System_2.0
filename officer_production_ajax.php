<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

require_once __DIR__ . '/config.php';

// Helper Function: Compute 7 daily slots (Isnin 7:01 AM - Selasa 7:00 AM) based on Date In
if (!function_exists('getWeeklyPerformanceSlots')) {
    function getWeeklyPerformanceSlots(mysqli $conn, float $shiftTarget = 5200.0): array {
        $now = time();
        $currentDayOfWeek = (int)date('N', $now); // 1 (Mon) .. 7 (Sun)
        $currentHourMin   = date('H:i:s', $now);

        if ($currentDayOfWeek === 1 && $currentHourMin < '07:01:00') {
            $mondayTimestamp = strtotime('last monday 07:01:00', $now);
        } else {
            $mondayTimestamp = strtotime('monday this week 07:01:00', $now);
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

        // Recoiling coil count query per day slot (distinct lot_no, coil_no)
        $stmtRecoil = $conn->prepare("
            SELECT COUNT(DISTINCT rp.lot_no, rp.coil_no) AS recoil_coils
            FROM recoiling_product rp
            WHERE (
                (rp.status = 'completed' AND rp.completed_at >= ? AND rp.completed_at <= ?)
                OR (rp.status = 'in_progress' AND COALESCE(rp.started_at, rp.date_in) >= ? AND COALESCE(rp.started_at, rp.date_in) <= ?)
            )
        ");

        $activeDailyTarget = $shiftTarget * 2; // Default for current active cycle
        $weeklyRecoilCoilsTotal = 0;

        for ($i = 0; $i < 7; $i++) {
            $startTs = strtotime("+{$i} days", $mondayTimestamp);
            $endTs   = strtotime("+1 day -1 second", $startTs);

            $startStr = date('Y-m-d H:i:s', $startTs);
            $endStr   = date('Y-m-d H:i:s', $endTs);

            // Threshold window for 3rd shift (15,600m target):
            // For Friday (Jumaat): Shift 2 extends to 01:00 AM, so target becomes 15,600m only if coil produced after 01:00 AM
            // For other days: target becomes 15,600m if coil produced after 12:00 AM midnight
            $isFriday        = ($days[$i] === 'Jumaat' || (int)date('N', $startTs) === 5);
            $thresholdTs     = strtotime(date($isFriday ? 'Y-m-d 01:00:00' : 'Y-m-d 00:00:00', strtotime('+1 day', $startTs)));
            $thresholdStr    = date('Y-m-d H:i:s', $thresholdTs);

            $startDisplay = date('d/m (D) h:i A', $startTs);
            $endDisplay   = date('d/m (D) h:i A', $endTs);

            // 1. Calculate produced meters
            $produced = 0.0;
            if ($stmt) {
                $stmt->bind_param("ss", $startStr, $endStr);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $produced = (float)$row['total_len'];
                }
            }

            // 2. Check if production occurred after threshold (01:00 AM on Friday, 12:00 AM on other days)
            $hasPost12am = false;
            if ($stmtMidnight) {
                $stmtMidnight->bind_param("ss", $thresholdStr, $endStr);
                $stmtMidnight->execute();
                $resM = $stmtMidnight->get_result();
                if ($resM && $resM->num_rows > 0) {
                    $hasPost12am = true;
                }
            }

            if (!$hasPost12am && $stmtMidnightRunning) {
                $stmtMidnightRunning->bind_param("ss", $thresholdStr, $endStr);
                $stmtMidnightRunning->execute();
                $resRun = $stmtMidnightRunning->get_result();
                if ($resRun && $resRun->num_rows > 0) {
                    $hasPost12am = true;
                }
            }

            // 3. Calculate recoiling coil count for this slot (distinct lot_no, coil_no)
            $recoilCoils = 0;
            if ($stmtRecoil) {
                $stmtRecoil->bind_param("ssss", $startStr, $endStr, $startStr, $endStr);
                $stmtRecoil->execute();
                $resRecoil = $stmtRecoil->get_result();
                if ($rowR = $resRecoil->fetch_assoc()) {
                    $recoilCoils = (int)($rowR['recoil_coils'] ?? 0);
                }
            }
            $weeklyRecoilCoilsTotal += $recoilCoils;

            // Slot Target: Default 10,400 m (2 shifts), becomes 15,600 m if production after threshold (3 shifts)
            $slotShifts = $hasPost12am ? 3 : 2;
            $slotTarget = $shiftTarget * $slotShifts;

            $weeklyProducedTotal += $produced;
            $weeklyTargetTotal   += $slotTarget;

            $variance = $produced - $slotTarget;
            $percentage = ($slotTarget > 0) ? round(($produced / $slotTarget) * 100, 1) : 0.0;
            $isToday = ($now >= $startTs && $now <= $endTs);

            if ($isToday) {
                $activeDailyTarget = $slotTarget;
            }

            $slots[] = [
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
                'is_today'                  => $isToday
            ];
        }

        if ($stmt) {
            $stmt->close();
        }
        if ($stmtMidnight) {
            $stmtMidnight->close();
        }
        if ($stmtMidnightRunning) {
            $stmtMidnightRunning->close();
        }
        if ($stmtRecoil) {
            $stmtRecoil->close();
        }

        $overallPct = ($weeklyTargetTotal > 0) ? min(100.0, round(($weeklyProducedTotal / $weeklyTargetTotal) * 100, 1)) : 0.0;

        return [
            'monday_cycle_start'        => date('Y-m-d H:i:s', $mondayTimestamp),
            'sunday_cycle_end'          => date('Y-m-d H:i:s', strtotime("+7 days -1 second", $mondayTimestamp)),
            'shift_target_meters'       => $shiftTarget,
            'daily_target_meters'       => $activeDailyTarget,
            'weekly_target_meters'      => $weeklyTargetTotal,
            'weekly_produced_total'     => $weeklyProducedTotal,
            'weekly_recoil_coils_total' => $weeklyRecoilCoilsTotal,
            'weekly_overall_pct'        => $overallPct,
            'slots'                     => $slots
        ];
    }
}

$action = $_REQUEST['action'] ?? 'get_data';

// 1. GET WEEKLY PERFORMANCE & CONFIG DATA
if ($action === 'get_data') {
    $shiftTarget = (float)getSystemSetting($conn, 'shift_target_meters', '5200');
    if ($shiftTarget <= 0) {
        $shiftTarget = 5200.0;
    }

    $weeklyData = getWeeklyPerformanceSlots($conn, $shiftTarget);
    $lastReset  = getSystemSetting($conn, 'last_weekly_reset_at', '-');

    echo json_encode([
        'success'               => true,
        'timestamp'             => date('Y-m-d H:i:s'),
        'shift_target_meters'   => $shiftTarget,
        'daily_target_meters'   => $weeklyData['daily_target_meters'],
        'weekly_target_meters'  => $weeklyData['weekly_target_meters'],
        'last_weekly_reset_at'  => $lastReset,
        'weekly_performance'    => $weeklyData
    ]);
    exit;
}

// 2. UPDATE SHIFT TARGET CONFIGURATION
if ($action === 'update_target') {
    $rawVal = $_POST['target_meters'] ?? $_GET['target_meters'] ?? '';
    $newTarget = floatval($rawVal);

    if ($newTarget <= 0) {
        echo json_encode(['success' => false, 'message' => 'Sila masukkan nilai sasaran syif yang sah (lebih daripada 0 meter).']);
        exit;
    }

    $ok = setSystemSetting($conn, 'shift_target_meters', (string)$newTarget);

    if ($ok) {
        // Audit Log
        $user = $_SESSION['role'] ?? 'officer';
        $remark = "Kemas kini sasaran syif kepada {$newTarget} m";
        $stmtLog = $conn->prepare("
            INSERT INTO process_log
                (entity_type, entity_id, mother_id, from_status, to_status, performed_by, action_detail, remark)
            VALUES ('system_setting', 0, NULL, 'CONFIG', 'UPDATE', ?, 'update_shift_target', ?)
        ");
        if ($stmtLog) {
            $stmtLog->bind_param("ss", $user, $remark);
            $stmtLog->execute();
            $stmtLog->close();
        }

        echo json_encode([
            'success'        => true,
            'message'        => 'Sasaran purata syif berjaya dikemas kini.',
            'new_target'     => $newTarget,
            'daily_target'   => $newTarget * 3,
            'weekly_target'  => $newTarget * 3 * 7
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Ralat ketika menyimpan sasaran ke pangkalan data.']);
    }
    exit;
}

// 3. MANUAL RESET WEEKLY CYCLE LOGIC
if ($action === 'manual_reset') {
    $user = $_SESSION['role'] ?? 'officer';
    $nowStr = date('Y-m-d H:i:s');
    setSystemSetting($conn, 'last_weekly_reset_at', $nowStr);

    $remark = "Pembersihan/Reset manual kitaran pengeluaran mingguan dilaksanakan oleh pegawai.";
    $stmtLog = $conn->prepare("
        INSERT INTO process_log
            (entity_type, entity_id, mother_id, from_status, to_status, performed_by, action_detail, remark)
        VALUES ('stock', 0, NULL, 'ACTIVE', 'MANUAL_RESET', ?, 'weekly_manual_reset', ?)
    ");
    if ($stmtLog) {
        $stmtLog->bind_param("ss", $user, $remark);
        $stmtLog->execute();
        $stmtLog->close();
    }

    echo json_encode([
        'success'   => true,
        'message'   => 'Reset kitaran pengeluaran mingguan berjaya dilaksanakan.',
        'timestamp' => $nowStr
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Tindakan tidak sah.']);
exit;

<?php
session_start();
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
        $dailyTarget = $shiftTarget * 3; // 24-hour target (3 shifts)
        $weeklyTargetTotal = $dailyTarget * 7;

        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(COALESCE(sp.actual_length, sp.length, 0)), 0) AS total_len
            FROM slitting_product sp
            WHERE (sp.is_voided = 0 OR sp.is_voided IS NULL)
              AND sp.date_in >= ? AND sp.date_in <= ?
        ");

        for ($i = 0; $i < 7; $i++) {
            $startTs = strtotime("+{$i} days", $mondayTimestamp);
            $endTs   = strtotime("+1 day -1 second", $startTs);

            $startStr = date('Y-m-d H:i:s', $startTs);
            $endStr   = date('Y-m-d H:i:s', $endTs);

            $startDisplay = date('d/m (D) h:i A', $startTs);
            $endDisplay   = date('d/m (D) h:i A', $endTs);

            $produced = 0.0;
            if ($stmt) {
                $stmt->bind_param("ss", $startStr, $endStr);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $produced = (float)$row['total_len'];
                }
            }

            $weeklyProducedTotal += $produced;
            $variance = $produced - $dailyTarget;
            $percentage = ($dailyTarget > 0) ? round(($produced / $dailyTarget) * 100, 1) : 0.0;
            $isToday = ($now >= $startTs && $now <= $endTs);

            $slots[] = [
                'day_name'          => $days[$i],
                'start_time'        => $startStr,
                'end_time'          => $endStr,
                'time_slot_label'   => "{$days[$i]} 07:01 AM - " . $days[($i+1)%7] . " 07:00 AM",
                'start_display'     => $startDisplay,
                'end_display'       => $endDisplay,
                'produced_meters'   => $produced,
                'target_meters'     => $dailyTarget,
                'variance_meters'   => $variance,
                'percentage'        => $percentage,
                'is_today'          => $isToday
            ];
        }

        if ($stmt) {
            $stmt->close();
        }

        $overallPct = ($weeklyTargetTotal > 0) ? min(100.0, round(($weeklyProducedTotal / $weeklyTargetTotal) * 100, 1)) : 0.0;

        return [
            'monday_cycle_start'    => date('Y-m-d H:i:s', $mondayTimestamp),
            'sunday_cycle_end'      => date('Y-m-d H:i:s', strtotime("+7 days -1 second", $mondayTimestamp)),
            'shift_target_meters'   => $shiftTarget,
            'daily_target_meters'   => $dailyTarget,
            'weekly_target_meters'  => $weeklyTargetTotal,
            'weekly_produced_total' => $weeklyProducedTotal,
            'weekly_overall_pct'    => $overallPct,
            'slots'                 => $slots
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
        'daily_target_meters'   => $shiftTarget * 3,
        'weekly_target_meters'  => $shiftTarget * 3 * 7,
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

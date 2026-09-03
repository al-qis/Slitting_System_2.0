<?php
/**
 * Main System Configuration
 * Location: /slitting_system/config.php
 * -----------------------------------------------------------------------
 * Single source of truth for database connection, timezones, and base URL.
 * -----------------------------------------------------------------------
 */

// Database Credentials
$host   = 'localhost'; 
$user   = 'root';
$pass   = 'MIS_1990'; 
$dbname = 'slitting_db'; 

// Initialize MySQLi Connection
$conn = new mysqli($host, $user, $pass, $dbname);

// Handle Connection Failures
if ($conn->connect_error) { 
    die("Database Connection Failed: " . $conn->connect_error); 
} 

// Set Character Set and Timezone
$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Kuala_Lumpur');

// Dynamic Base URL Configuration
$protocol    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$host_header = $_SERVER['HTTP_HOST'] ?? 'localhost';
$BASE_URL    = "{$protocol}://{$host_header}/slitting_system";

/**
 * ───────────────────────────────────────────────────────────────────────
 * Shift Helper Functions for Production & Mother Coil Counting
 * ───────────────────────────────────────────────────────────────────────
 * Standard Manufacturing 3-Shift Configuration:
 *  - Shift 1: 07:00:00 - 14:59:59 (07:00 - 15:00)
 *  - Shift 2: 15:00:00 - 22:59:59 (15:00 - 23:00)
 *  - Shift 3: 23:00:00 - 06:59:59 (23:00 - 07:00 next day)
 * ───────────────────────────────────────────────────────────────────────
 */

if (!function_exists('getShiftDetails')) {
    function getShiftDetails(?string $datetimeStr = null): array {
        $ts   = $datetimeStr ? strtotime($datetimeStr) : time();
        $hour = (int)date('G', $ts);
        $date = date('Y-m-d', $ts);

        if ($hour >= 7 && $hour < 15) {
            return [
                'shift'      => 1,
                'name'       => 'Shift 1',
                'label'      => 'Shift 1 (07:00 - 15:00)',
                'short'      => 'Shift 1',
                'prod_date'  => $date,
                'start_time' => "{$date} 07:00:00",
                'end_time'   => "{$date} 14:59:59",
            ];
        } elseif ($hour >= 15 && $hour < 23) {
            return [
                'shift'      => 2,
                'name'       => 'Shift 2',
                'label'      => 'Shift 2 (15:00 - 23:00)',
                'short'      => 'Shift 2',
                'prod_date'  => $date,
                'start_time' => "{$date} 15:00:00",
                'end_time'   => "{$date} 22:59:59",
            ];
        } else {
            // Shift 3: 23:00 to 07:00
            if ($hour < 7) {
                $prodDate = date('Y-m-d', strtotime('-1 day', $ts));
                $sStart   = "{$prodDate} 23:00:00";
                $sEnd     = "{$date} 06:59:59";
            } else {
                $prodDate = $date;
                $nextDate = date('Y-m-d', strtotime('+1 day', $ts));
                $sStart   = "{$prodDate} 23:00:00";
                $sEnd     = "{$nextDate} 06:59:59";
            }

            return [
                'shift'      => 3,
                'name'       => 'Shift 3',
                'label'      => 'Shift 3 (23:00 - 07:00)',
                'short'      => 'Shift 3',
                'prod_date'  => $prodDate,
                'start_time' => $sStart,
                'end_time'   => $sEnd,
            ];
        }
    }
}

if (!function_exists('getCurrentProductionDate')) {
    function getCurrentProductionDate(): string {
        $shiftInfo = getShiftDetails();
        return $shiftInfo['prod_date'];
    }
}

if (!function_exists('getMotherCoilShiftCounts')) {
    function getMotherCoilShiftCounts(mysqli $conn, ?string $targetDate = null): array {
        if (!$targetDate) {
            $targetDate = getCurrentProductionDate();
        }

        $s1_start = "{$targetDate} 07:00:00";
        $s1_end   = "{$targetDate} 14:59:59";

        $s2_start = "{$targetDate} 15:00:00";
        $s2_end   = "{$targetDate} 22:59:59";

        $s3_start = "{$targetDate} 23:00:00";
        $nextDate = date('Y-m-d', strtotime('+1 day', strtotime($targetDate)));
        $s3_end   = "{$nextDate} 06:59:59";

        $runTimeExpr = "COALESCE(
            mc.date_out,
            (SELECT MIN(al.performed_at) FROM mother_coil_audit_log al WHERE al.mother_id = mc.id AND al.action_type IN ('OUT','SCAN_OUT')),
            (SELECT MIN(sp.date_in) FROM slitting_product sp WHERE sp.mother_id = mc.id AND (sp.is_voided = 0 OR sp.is_voided IS NULL))
        )";

        $q1 = $conn->query("SELECT COUNT(DISTINCT mc.id) AS cnt FROM mother_coil mc WHERE {$runTimeExpr} >= '$s1_start' AND {$runTimeExpr} <= '$s1_end'");
        $c1 = $q1 ? (int)$q1->fetch_assoc()['cnt'] : 0;

        $q2 = $conn->query("SELECT COUNT(DISTINCT mc.id) AS cnt FROM mother_coil mc WHERE {$runTimeExpr} >= '$s2_start' AND {$runTimeExpr} <= '$s2_end'");
        $c2 = $q2 ? (int)$q2->fetch_assoc()['cnt'] : 0;

        $q3 = $conn->query("SELECT COUNT(DISTINCT mc.id) AS cnt FROM mother_coil mc WHERE {$runTimeExpr} >= '$s3_start' AND {$runTimeExpr} <= '$s3_end'");
        $c3 = $q3 ? (int)$q3->fetch_assoc()['cnt'] : 0;

        $currentShift = getShiftDetails();

        return [
            'date'         => $targetDate,
            'shift1'       => $c1,
            'shift2'       => $c2,
            'shift3'       => $c3,
            'total'        => $c1 + $c2 + $c3,
            'active_shift' => ($targetDate === $currentShift['prod_date']) ? $currentShift['shift'] : 0,
            's1_time'      => '07:00 - 15:00',
            's2_time'      => '15:00 - 23:00',
            's3_time'      => '23:00 - 07:00',
        ];
    }
}

if (!function_exists('getSystemSetting')) {
    function getSystemSetting(mysqli $conn, string $key, string $default = ''): string {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $key);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $stmt->close();
                return $row['setting_value'];
            }
            $stmt->close();
        }
        return $default;
    }
}

if (!function_exists('setSystemSetting')) {
    function setSystemSetting(mysqli $conn, string $key, string $value): bool {
        $stmt = $conn->prepare("
            INSERT INTO system_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        if ($stmt) {
            $stmt->bind_param("ss", $key, $value);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }
        return false;
    }
}

if (!function_exists('get24HourProductionLength')) {
    function get24HourProductionLength(mysqli $conn): float {
        $prodDate = getCurrentProductionDate();
        $s1_start = "{$prodDate} 07:00:00";
        $stmt = $conn->query("
            SELECT COALESCE(SUM(COALESCE(sp.actual_length, sp.length, 0)), 0) AS total_len
            FROM slitting_product sp
            WHERE (sp.is_voided = 0 OR sp.is_voided IS NULL)
              AND (
                  (
                      (sp.is_completed = 1 OR (sp.actual_length IS NOT NULL AND sp.actual_length > 0))
                      AND (
                          (sp.date_in IS NOT NULL AND sp.date_in >= '$s1_start')
                          OR (sp.date_in IS NOT NULL AND sp.date_in >= NOW() - INTERVAL 24 HOUR)
                          OR (sp.updated_at IS NOT NULL AND sp.updated_at >= NOW() - INTERVAL 24 HOUR)
                          OR (sp.date_out IS NOT NULL AND sp.date_out >= NOW() - INTERVAL 24 HOUR)
                          OR sp.mother_id IN (
                              SELECT mc.id FROM mother_coil mc 
                              WHERE mc.date_out IS NOT NULL 
                                AND (mc.date_out >= '$s1_start' OR mc.date_out >= NOW() - INTERVAL 24 HOUR)
                          )
                      )
                  )
                  OR (
                      sp.is_completed = 0
                      AND sp.mother_id = (
                          SELECT sp_run.mother_id 
                          FROM slitting_product sp_run 
                          WHERE (sp_run.is_voided = 0 OR sp_run.is_voided IS NULL)
                            AND (sp_run.is_completed = 0 OR sp_run.actual_length IS NULL OR sp_run.actual_length = 0)
                          ORDER BY sp_run.date_in ASC, sp_run.id ASC
                          LIMIT 1
                      )
                  )
              )
        ");
        if ($stmt && $row = $stmt->fetch_assoc()) {
            return (float)$row['total_len'];
        }
        return 0.0;
    }
}

if (!function_exists('getWeeklyProductionLength')) {
    function getWeeklyProductionLength(mysqli $conn): float {
        $mondayTs = strtotime('monday this week');
        $mondayStr = date('Y-m-d 00:00:00', $mondayTs);
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(COALESCE(sp.actual_length, sp.length, 0)), 0) AS total_len
            FROM slitting_product sp
            WHERE (sp.is_voided = 0 OR sp.is_voided IS NULL)
              AND (
                  (
                      (sp.is_completed = 1 OR (sp.actual_length IS NOT NULL AND sp.actual_length > 0))
                      AND (
                          (sp.date_in IS NOT NULL AND sp.date_in >= ?)
                          OR (sp.updated_at IS NOT NULL AND sp.updated_at >= ?)
                          OR (sp.date_out IS NOT NULL AND sp.date_out >= ?)
                          OR sp.mother_id IN (
                              SELECT mc.id FROM mother_coil mc 
                              WHERE mc.date_out IS NOT NULL AND mc.date_out >= ?
                          )
                      )
                  )
                  OR (
                      sp.is_completed = 0
                      AND sp.mother_id = (
                          SELECT sp_run.mother_id 
                          FROM slitting_product sp_run 
                          WHERE (sp_run.is_voided = 0 OR sp_run.is_voided IS NULL)
                            AND (sp_run.is_completed = 0 OR sp_run.actual_length IS NULL OR sp_run.actual_length = 0)
                          ORDER BY sp_run.date_in ASC, sp_run.id ASC
                          LIMIT 1
                      )
                  )
              )
        ");
        if ($stmt) {
            $stmt->bind_param("ssss", $mondayStr, $mondayStr, $mondayStr, $mondayStr);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $stmt->close();
                return (float)$row['total_len'];
            }
            $stmt->close();
        }
        return 0.0;
    }
}

?>
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

if (!function_exists('getProductionMonthOptions')) {
    /**
     * Helper to get dropdown options for coil production month (Current, Month Before, Month Forward).
     */
    function getProductionMonthOptions(?string $baseDate = null): array {
        $ts = (!empty($baseDate) && strtotime($baseDate) !== false) ? strtotime($baseDate) : time();
        $year = (int)date('Y', $ts);
        $month = (int)date('m', $ts);

        $currentTs = mktime(0, 0, 0, $month, 1, $year);
        $beforeTs  = mktime(0, 0, 0, $month - 1, 1, $year);
        $forwardTs = mktime(0, 0, 0, $month + 1, 1, $year);

        return [
            'current' => [
                'value' => 'current',
                'date' => date('Y-m-d', $currentTs),
                'yymm' => date('ym', $currentTs),
                'label' => 'Current Month (' . date('M Y', $currentTs) . ' - ' . date('ym', $currentTs) . ')',
            ],
            'before' => [
                'value' => 'before',
                'date' => date('Y-m-d', $beforeTs),
                'yymm' => date('ym', $beforeTs),
                'label' => 'Month Before (' . date('M Y', $beforeTs) . ' - ' . date('ym', $beforeTs) . ')',
            ],
            'forward' => [
                'value' => 'forward',
                'date' => date('Y-m-d', $forwardTs),
                'yymm' => date('ym', $forwardTs),
                'label' => 'Month Forward (' . date('M Y', $forwardTs) . ' - ' . date('ym', $forwardTs) . ')',
            ],
        ];
    }
}

if (!function_exists('resolveProductionMonthDate')) {
    /**
     * Resolves selected production month option ('current', 'before', 'forward' or date) into a Y-m-d date string.
     */
    function resolveProductionMonthDate(?string $selectedOption, ?string $baseDate = null): string {
        $ts = (!empty($baseDate) && strtotime($baseDate) !== false) ? strtotime($baseDate) : time();
        $year = (int)date('Y', $ts);
        $month = (int)date('m', $ts);

        if ($selectedOption === 'before') {
            return date('Y-m-d', mktime(0, 0, 0, $month - 1, 1, $year));
        } elseif ($selectedOption === 'forward') {
            return date('Y-m-d', mktime(0, 0, 0, $month + 1, 1, $year));
        } elseif (!empty($selectedOption) && strtotime($selectedOption) !== false) {
            return date('Y-m-d', strtotime($selectedOption));
        }
        return date('Y-m-d', mktime(0, 0, 0, $month, 1, $year));
    }
}

if (!function_exists('generateStockCode')) {
    /**
     * Generates a unique stock in code for a produced roll coil.
     * Format: YYMM-XXXX (e.g. 2610-0001)
     * Starts at 0001 for each month (YYMM) and increments with each coil produced.
     * Guaranteed strictly unique across all rolls.
     */
    function generateStockCode(mysqli $conn, ?string $dateStr = null): string {
        $ts = (!empty($dateStr) && strtotime($dateStr) !== false) ? strtotime($dateStr) : time();
        $prefix = date('ym', $ts); // YYMM e.g. 2610

        $maxSeq = 0;
        $stmt = $conn->prepare("
            SELECT MAX(CAST(SUBSTRING(stock_code, 6) AS UNSIGNED)) AS max_seq
            FROM slitting_product
            WHERE stock_code LIKE CONCAT(?, '-%')
        ");
        if ($stmt) {
            $stmt->bind_param("s", $prefix);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $maxSeq = (isset($res['max_seq']) && $res['max_seq'] !== null) ? (int)$res['max_seq'] : 0;
        }

        $candidateSeq = $maxSeq + 1;
        while (true) {
            $candidateCode = sprintf("%s-%04d", $prefix, $candidateSeq);

            $chkStmt = $conn->prepare("SELECT id FROM slitting_product WHERE stock_code = ? LIMIT 1");
            if ($chkStmt) {
                $chkStmt->bind_param("s", $candidateCode);
                $chkStmt->execute();
                $chkRes = $chkStmt->get_result();
                $exists = ($chkRes && $chkRes->num_rows > 0);
                $chkStmt->close();

                if (!$exists) {
                    return $candidateCode;
                }
            } else {
                return $candidateCode;
            }

            $candidateSeq++;
        }
    }
}

if (!function_exists('getRollProductionMonthYear')) {
    /**
     * Determines the effective production year and month [year, month] for a roll.
     * If stock_code starts with YYMM- (4 digits), extracts year (2000+YY) and month (MM).
     * Otherwise, falls back to date_in.
     */
    function getRollProductionMonthYear(?string $stockCode, ?string $dateIn): array {
        if (!empty($stockCode) && preg_match('/^(\d{2})(\d{2})-/', $stockCode, $m)) {
            $yy = (int)$m[1];
            $mm = (int)$m[2];
            if ($mm >= 1 && $mm <= 12) {
                return [2000 + $yy, $mm];
            }
        }
        $ts = (!empty($dateIn) && strtotime($dateIn) !== false) ? strtotime($dateIn) : time();
        return [(int)date('Y', $ts), (int)date('m', $ts)];
    }
}

if (!function_exists('getProductionMonthSqlCondition')) {
    /**
     * Returns the SQL WHERE condition snippet for filtering produced rolls by month and year.
     * Follows stock_code YYMM prefix if valid (e.g. 2611-xxxx -> Nov 2026),
     * otherwise falls back to date_in month/year.
     * Expects parameters: [$yymmPrefix, $month, $year] (types: 'sii')
     */
    function getProductionMonthSqlCondition(string $tableAlias = 'sp'): string {
        global $conn;
        static $hasStockCode = null;
        if ($hasStockCode === null && isset($conn) && $conn instanceof mysqli) {
            $check = $conn->query("SHOW COLUMNS FROM slitting_product LIKE 'stock_code'");
            $hasStockCode = ($check && $check->num_rows > 0);
        }
        $prefix = $tableAlias !== '' ? $tableAlias . '.' : '';
        if ($hasStockCode) {
            return "(CASE WHEN {$prefix}stock_code REGEXP '^[0-9]{4}-' THEN {$prefix}stock_code LIKE CONCAT(?, '-%') ELSE (MONTH({$prefix}date_in) = ? AND YEAR({$prefix}date_in) = ?) END)";
        }
        return "(MONTH({$prefix}date_in) = ? AND YEAR({$prefix}date_in) = ?)";
    }
}

if (!function_exists('getStockMonthEndSqlCondition')) {
    /**
     * Returns the SQL WHERE condition snippet for verifying if a roll was produced
     * on or before the target month-end.
     * Follows stock_code YYMM prefix if valid (e.g. 2611 is NOT included in Oct 2026 / 2610 end stock),
     * otherwise falls back to date_in <= eom.
     * Expects parameters: [$yymmPrefix, $eom] (types: 'ss')
     */
    function getStockMonthEndSqlCondition(string $tableAlias = 'sp'): string {
        global $conn;
        static $hasStockCode = null;
        if ($hasStockCode === null && isset($conn) && $conn instanceof mysqli) {
            $check = $conn->query("SHOW COLUMNS FROM slitting_product LIKE 'stock_code'");
            $hasStockCode = ($check && $check->num_rows > 0);
        }
        $prefix = $tableAlias !== '' ? $tableAlias . '.' : '';
        if ($hasStockCode) {
            return "(CASE WHEN {$prefix}stock_code REGEXP '^[0-9]{4}-' THEN SUBSTRING({$prefix}stock_code, 1, 4) <= ? ELSE {$prefix}date_in <= ? END)";
        }
        return "({$prefix}date_in <= ?)";
    }
}


if (!function_exists('resolveSlitProductCode')) {
    /**
     * Resolves the slitting child product code based on the mother product and slit width.
     * Rule: From the mother coil RS-3020 (or TS-3020, or V-coil):
     * If the slit width (mm) is 66.5, 81, 109.5, 118, or 309,
     * auto set the slitting product code as TS-3020 (White sticker).
     * Otherwise standard RS-3020 (Blue sticker).
     *
     * @param string $motherProductCode Mother product code (e.g. 'RS-3020')
     * @param bool   $isVCoil           Whether mother coil is a V-coil
     * @param float|string $slitWidth   Resulting slit roll width in mm
     * @return array ['code' => string, 'is_ts' => bool]
     */
    function resolveSlitProductCode(string $motherProductCode, bool $isVCoil, $slitWidth): array {
        // Exact TS-trigger widths (mm), compared with float tolerance.
        $tsWidths = [66.5, 81.0, 109.5, 118.0, 309.0];

        $width = (float)$slitWidth;
        $matchesTsWidth = false;
        foreach ($tsWidths as $tsw) {
            if (abs($width - $tsw) < 0.01) {
                $matchesTsWidth = true;
                break;
            }
        }

        $cleanMother = trim($motherProductCode);

        // Check if this is an RS-3020 mother coil (or TS-3020, or V-coil 3020)
        $is3020Mother = (strcasecmp($cleanMother, 'RS-3020') === 0)
            || (strcasecmp($cleanMother, 'TS-3020') === 0)
            || (stripos($cleanMother, 'RS-3020') === 0)
            || (stripos($cleanMother, 'TS-3020') === 0)
            || ($isVCoil && (stripos($cleanMother, '3020') !== false || $cleanMother === ''));

        if ($is3020Mother) {
            if ($matchesTsWidth) {
                return ['code' => 'TS-3020', 'is_ts' => true];
            } else {
                return ['code' => 'RS-3020', 'is_ts' => false];
            }
        }

        // Generic V-Coil prefix replacement fallback for other product suffixes (if any)
        $parts  = explode('-', $cleanMother, 2);
        $suffix = $parts[1] ?? $cleanMother;

        if ($isVCoil && $matchesTsWidth) {
            return ['code' => 'TS-' . $suffix, 'is_ts' => true];
        }

        if ($isVCoil) {
            return ['code' => 'RS-' . $suffix, 'is_ts' => false];
        }

        // Non-V mother coil: leave product code exactly as passed in — untouched
        return ['code' => $cleanMother, 'is_ts' => false];
    }
}

if (!function_exists('syncSlittingPlanCustomer')) {
    /**
     * Synchronizes customer_name and ref_no to slitting_plans for a given slitting_product ID.
     */
    function syncSlittingPlanCustomer(mysqli $conn, int $productId, string $customer, string $refNo): bool {
        if ($productId <= 0) return false;
        $customer = trim($customer);
        $refNo    = trim($refNo);
        if ($customer === '' && $refNo === '') return false;

        $stmt = $conn->prepare("SELECT mother_id, roll_no, width FROM slitting_product WHERE id = ? LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param("i", $productId);
        $stmt->execute();
        $prod = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$prod || empty($prod['mother_id']) || empty($prod['roll_no'])) {
            return false;
        }

        $motherId = (int)$prod['mother_id'];
        $rollSeq  = trim($prod['roll_no']);
        $width    = (float)($prod['width'] ?? 0);

        // Check if row exists in slitting_plans for this mother_id and roll_seq
        $chk = $conn->prepare("SELECT id FROM slitting_plans WHERE mother_coil_id = ? AND roll_seq = ? LIMIT 1");
        if (!$chk) return false;
        $chk->bind_param("is", $motherId, $rollSeq);
        $chk->execute();
        $hasRow = ($chk->get_result()->num_rows > 0);
        $chk->close();

        if ($hasRow) {
            $upd = $conn->prepare("UPDATE slitting_plans SET customer_name = ?, ref_no = ? WHERE mother_coil_id = ? AND roll_seq = ?");
            if ($upd) {
                $upd->bind_param("ssis", $customer, $refNo, $motherId, $rollSeq);
                $upd->execute();
                $upd->close();
            }
        } else {
            $ins = $conn->prepare("INSERT INTO slitting_plans (mother_coil_id, roll_seq, planned_width, customer_name, ref_no) VALUES (?, ?, ?, ?, ?)");
            if ($ins) {
                $ins->bind_param("isdss", $motherId, $rollSeq, $width, $customer, $refNo);
                $ins->execute();
                $ins->close();
            }
        }
        return true;
    }
}
?>
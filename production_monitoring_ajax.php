<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

if (!isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once 'config.php';
}

// Helper: Format elapsed time in HH:MM:SS
function formatElapsedTime($seconds) {
    $seconds = max(0, (int)$seconds);
    $hours   = floor($seconds / 3600);
    $mins    = floor(($seconds % 3600) / 60);
    $secs    = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $mins, $secs);
}

// Helper: Get Customer Name for a Mother Coil or Slitting Product
function resolveCustomerName($conn, $mother_id, $lot_no, $coil_no, $width = null) {
    if ($mother_id > 0) {
        $stmt = $conn->prepare("SELECT customer_name FROM slitting_plans WHERE mother_coil_id = ? AND customer_name IS NOT NULL AND customer_name != '' ORDER BY sort_order ASC, id ASC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $mother_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $stmt->close();
                return trim($row['customer_name']);
            }
            $stmt->close();
        }
    }

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
                $stmt->close();
                return trim($row['customer']);
            }
            $stmt->close();
        }
    }

    return '-';
}

// Helper: Resolve Process Details (Slitting vs Recoiling vs Reslit)
function resolveProcessDetails($is_recoiled, $is_reslitted, $original_source) {
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

// Helper: Calculate 24-Hour Production Length exclusively from Mother Coil length (excluding recoil/reslit)
function calculate24HourMotherCoilLength(mysqli $conn): float {
    $prodDate   = getCurrentProductionDate();
    $cycleStart = "{$prodDate} 07:00:00";
    $nextDate   = date('Y-m-d', strtotime('+1 day', strtotime($prodDate)));
    $cycleEnd   = "{$nextDate} 06:59:59";

    $sql = "
        SELECT COALESCE(SUM(mc.length), 0) AS total_len
        FROM mother_coil mc
        WHERE mc.id IN (
            SELECT completed_mids.mother_id FROM (
                SELECT sp.mother_id
                FROM slitting_product sp
                WHERE (sp.is_voided = 0 OR sp.is_voided IS NULL)
                  AND (sp.source IS NULL OR sp.source NOT IN ('recoiling', 'reslit'))
                  AND (sp.original_source IS NULL OR sp.original_source NOT IN ('recoiling', 'reslit'))
                  AND (sp.is_recoiled = 0 OR sp.is_recoiled IS NULL)
                  AND (sp.is_reslitted = 0 OR sp.is_reslitted IS NULL)
                  AND sp.recoiling_id IS NULL
                  AND sp.parent_slit_id IS NULL
                  AND (sp.is_completed = 1 OR (sp.actual_length IS NOT NULL AND sp.actual_length > 0))
                GROUP BY sp.mother_id
                HAVING COALESCE(MAX(sp.date_out), MIN(sp.date_in)) >= '{$cycleStart}'
                   AND COALESCE(MAX(sp.date_out), MIN(sp.date_in)) <= '{$cycleEnd}'
            ) AS completed_mids

            UNION

            SELECT running_mids.mother_id FROM (
                SELECT sp_run.mother_id
                FROM slitting_product sp_run
                WHERE (sp_run.is_voided = 0 OR sp_run.is_voided IS NULL)
                  AND (sp_run.is_completed = 0 OR sp_run.actual_length IS NULL OR sp_run.actual_length = 0)
                  AND (sp_run.source IS NULL OR sp_run.source NOT IN ('recoiling', 'reslit'))
                  AND (sp_run.original_source IS NULL OR sp_run.original_source NOT IN ('recoiling', 'reslit'))
                  AND (sp_run.is_recoiled = 0 OR sp_run.is_recoiled IS NULL)
                  AND (sp_run.is_reslitted = 0 OR sp_run.is_reslitted IS NULL)
                  AND sp_run.recoiling_id IS NULL
                  AND sp_run.parent_slit_id IS NULL
                ORDER BY sp_run.date_in ASC, sp_run.id ASC
                LIMIT 1
            ) AS running_mids
        )
    ";

    $stmt = $conn->query($sql);
    if ($stmt && $row = $stmt->fetch_assoc()) {
        return (float)$row['total_len'];
    }

    return 0.0;
}

// Helper: Calculate Weekly Production Length exclusively from Mother Coil length (excluding recoil/reslit)
function calculateWeeklyMotherCoilLength(mysqli $conn): float {
    $mondayTs  = strtotime('monday this week');
    $mondayStr = date('Y-m-d 00:00:00', $mondayTs);

    $sql = "
        SELECT COALESCE(SUM(mc.length), 0) AS total_len
        FROM mother_coil mc
        WHERE mc.id IN (
            SELECT completed_mids.mother_id FROM (
                SELECT sp.mother_id
                FROM slitting_product sp
                WHERE (sp.is_voided = 0 OR sp.is_voided IS NULL)
                  AND (sp.source IS NULL OR sp.source NOT IN ('recoiling', 'reslit'))
                  AND (sp.original_source IS NULL OR sp.original_source NOT IN ('recoiling', 'reslit'))
                  AND (sp.is_recoiled = 0 OR sp.is_recoiled IS NULL)
                  AND (sp.is_reslitted = 0 OR sp.is_reslitted IS NULL)
                  AND sp.recoiling_id IS NULL
                  AND sp.parent_slit_id IS NULL
                  AND (sp.is_completed = 1 OR (sp.actual_length IS NOT NULL AND sp.actual_length > 0))
                GROUP BY sp.mother_id
                HAVING COALESCE(MAX(sp.date_out), MIN(sp.date_in)) >= ?
            ) AS completed_mids

            UNION

            SELECT running_mids.mother_id FROM (
                SELECT sp_run.mother_id
                FROM slitting_product sp_run
                WHERE (sp_run.is_voided = 0 OR sp_run.is_voided IS NULL)
                  AND (sp_run.is_completed = 0 OR sp_run.actual_length IS NULL OR sp_run.actual_length = 0)
                  AND (sp_run.source IS NULL OR sp_run.source NOT IN ('recoiling', 'reslit'))
                  AND (sp_run.original_source IS NULL OR sp_run.original_source NOT IN ('recoiling', 'reslit'))
                  AND (sp_run.is_recoiled = 0 OR sp_run.is_recoiled IS NULL)
                  AND (sp_run.is_reslitted = 0 OR sp_run.is_reslitted IS NULL)
                  AND sp_run.recoiling_id IS NULL
                  AND sp_run.parent_slit_id IS NULL
                ORDER BY sp_run.date_in ASC, sp_run.id ASC
                LIMIT 1
            ) AS running_mids
        )
    ";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $mondayStr);
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

// Helper: Check if slitting production occurred after 12:00 AM in the given production cycle
function hasProductionAfterMidnight(mysqli $conn, string $cycleStartDate): bool {
    // A production date (e.g. 2026-09-11) has its post-12am window from +1 day 00:00:00 to +1 day 07:00:00
    $nextDate    = date('Y-m-d', strtotime('+1 day', strtotime($cycleStartDate)));
    $midnightStr = "{$nextDate} 00:00:00";
    $cycleEndStr = "{$nextDate} 07:00:00";

    // 1. Check completed slitting mother coils in the post-12am window (excluding recoil/reslit)
    $sqlCompleted = "
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
            HAVING slit_time >= ? AND slit_time <= ?
        ) t
        LIMIT 1
    ";
    $stmt = $conn->prepare($sqlCompleted);
    if ($stmt) {
        $stmt->bind_param("ss", $midnightStr, $cycleEndStr);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $stmt->close();
            return true;
        }
        $stmt->close();
    }

    // 2. Check running slitting products started after midnight in this cycle
    $sqlRunning = "
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
          AND sp_run.date_in >= ? AND sp_run.date_in <= ?
        LIMIT 1
    ";
    $stmtRun = $conn->prepare($sqlRunning);
    if ($stmtRun) {
        $stmtRun->bind_param("ss", $midnightStr, $cycleEndStr);
        $stmtRun->execute();
        $resRun = $stmtRun->get_result();
        if ($resRun && $resRun->num_rows > 0) {
            $stmtRun->close();
            return true;
        }
        $stmtRun->close();
    }

    return false;
}

// Helper: Calculate Recoiling Coil Count by distinct coil following the current production day
function getRecoilingDailyCoilCount(mysqli $conn): array {
    $prodDate = getCurrentProductionDate();
    $dayStart = "{$prodDate} 07:00:00";
    $dayEnd   = date('Y-m-d 06:59:59', strtotime('+1 day', strtotime($prodDate)));

    $sql = "
        SELECT 
            COUNT(DISTINCT rp.lot_no, rp.coil_no) AS total_coils,
            COUNT(DISTINCT CASE WHEN rp.status = 'completed' THEN CONCAT(rp.lot_no, '___', rp.coil_no) END) AS completed_coils,
            COUNT(DISTINCT CASE WHEN rp.status = 'in_progress' THEN CONCAT(rp.lot_no, '___', rp.coil_no) END) AS in_progress_coils,
            COUNT(rp.id) AS total_rolls
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
                'total_rolls'         => (int)($row['total_rolls'] ?? 0),
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
        'total_rolls'         => 0,
    ];
}

$action = $_REQUEST['action'] ?? 'get_data';

// ══════════════════════════════════════════════════════════════════════════
// 1. GET MONITORING DATA (Refined FIFO Workflow: 1 Running Slot + Queue)
// ══════════════════════════════════════════════════════════════════════════
if ($action === 'get_data') {

    // ── Fetch ALL Active/Pending Coils Across All Operations (Slitting, Recoiling, Reslit) ──
    $all_active_jobs = [];

    // 1. Pending Slitting Coils
    $slitting_pending_res = $conn->query("
        SELECT 
            'Slitting' AS proc_name,
            sp.mother_id,
            sp.lot_no,
            sp.coil_no,
            sp.product,
            MAX(sp.customer_name) AS customer_name,
            MIN(sp.date_in) AS start_time,
            MAX(sp.date_in) AS latest_time,
            MAX(sp.id) AS max_id,
            COUNT(sp.id) AS total_rolls,
            SUM(CASE WHEN sp.is_completed = 1 AND sp.actual_length IS NOT NULL AND sp.actual_length > 0 THEN 1 ELSE 0 END) AS completed_rolls,
            MAX(sp.is_recoiled) AS is_recoiled,
            MAX(sp.is_reslitted) AS is_reslitted,
            MAX(sp.original_source) AS original_source,
            MAX(mc.length) AS length
        FROM slitting_product sp
        LEFT JOIN mother_coil mc ON sp.mother_id = mc.id
        WHERE (sp.is_voided = 0 OR sp.is_voided IS NULL)
          AND (sp.is_completed = 0 OR sp.actual_length IS NULL OR sp.actual_length = 0)
        GROUP BY sp.mother_id, sp.lot_no, sp.coil_no, sp.product
    ");
    if ($slitting_pending_res) {
        while ($row = $slitting_pending_res->fetch_assoc()) {
            $all_active_jobs[] = $row;
        }
    }

    // 2. Active Recoiling Coils (ONLY in_progress — pending recoiling coils are NOT shown on monitoring)
    $recoiling_pending_res = $conn->query("
        SELECT 
            'Recoiling' AS proc_name,
            rp.mother_id,
            rp.lot_no,
            rp.coil_no,
            rp.roll_no,
            rp.product,
            MAX(rp.slitting_product_id) AS slitting_product_id,
            '' AS customer_name,
            MIN(COALESCE(rp.started_at, rp.date_in)) AS start_time,
            MAX(COALESCE(rp.started_at, rp.date_in)) AS latest_time,
            MAX(rp.id) AS max_id,
            COUNT(rp.id) AS total_rolls,
            0 AS completed_rolls,
            1 AS is_recoiled,
            0 AS is_reslitted,
            'recoiling' AS original_source,
            MAX(COALESCE(rp.length, rp.new_length, rp.actual_length, 0)) AS length
        FROM recoiling_product rp
        WHERE rp.status = 'in_progress'
        GROUP BY rp.mother_id, rp.lot_no, rp.coil_no, rp.roll_no, rp.product
    ");
    if ($recoiling_pending_res) {
        while ($row = $recoiling_pending_res->fetch_assoc()) {
            $all_active_jobs[] = $row;
        }
    }

    // 3. Pending Reslit Coils
    $reslit_pending_res = $conn->query("
        SELECT 
            'Reslit' AS proc_name,
            rp.mother_id,
            rp.lot_no,
            rp.coil_no,
            rp.product,
            '' AS customer_name,
            MIN(COALESCE(rp.started_at, rp.date_in)) AS start_time,
            MAX(COALESCE(rp.started_at, rp.date_in)) AS latest_time,
            MAX(rp.id) AS max_id,
            COUNT(rp.id) AS total_rolls,
            0 AS completed_rolls,
            0 AS is_recoiled,
            1 AS is_reslitted,
            'reslit' AS original_source,
            MAX(COALESCE(rp.length, rp.actual_length, 0)) AS length
        FROM reslit_product rp
        WHERE rp.status IN ('pending', 'in_progress')
        GROUP BY rp.mother_id, rp.lot_no, rp.coil_no, rp.product
    ");
    if ($reslit_pending_res) {
        while ($row = $reslit_pending_res->fetch_assoc()) {
            $all_active_jobs[] = $row;
        }
    }

    // Sort active jobs in FIFO order: earliest start_time first (start_time ASC, max_id ASC).
    // An already running coil keeps Slot 1 (Running), and a newly started recoiling job queues
    // into Slot 2+ (Waiting List). If no coil is running, the recoiling job takes Slot 1 (Running).
    usort($all_active_jobs, function ($a, $b) {
        $tA = strtotime($a['start_time'] ?? '1970-01-01');
        $tB = strtotime($b['start_time'] ?? '1970-01-01');
        if ($tA === $tB) {
            return ((int)($a['max_id'] ?? 0)) <=> ((int)($b['max_id'] ?? 0));
        }
        return $tA <=> $tB;
    });

    $running_data = null;
    $queued_in_pending = [];

    if (!empty($all_active_jobs)) {
        // SLOT 1: Earliest active job becomes the Current Running Coil
        $active_item = $all_active_jobs[0];
        $mother_id   = (int)$active_item['mother_id'];
        $lot_no      = $active_item['lot_no'];
        $coil_no     = $active_item['coil_no'];

        $cust_name = trim($active_item['customer_name'] ?? '');
        if ($cust_name === '' || $cust_name === '-') {
            if (!empty($active_item['slitting_product_id'])) {
                $sp_id = (int)$active_item['slitting_product_id'];
                $stmt_sp = $conn->prepare("SELECT customer_name, mother_id FROM slitting_product WHERE id = ? LIMIT 1");
                if ($stmt_sp) {
                    $stmt_sp->bind_param("i", $sp_id);
                    $stmt_sp->execute();
                    $r_sp = $stmt_sp->get_result()->fetch_assoc();
                    $stmt_sp->close();
                    if ($r_sp) {
                        if (!empty($r_sp['customer_name'])) {
                            $cust_name = trim($r_sp['customer_name']);
                        } elseif (!empty($r_sp['mother_id'])) {
                            $cust_name = resolveCustomerName($conn, (int)$r_sp['mother_id'], $lot_no, $coil_no);
                        }
                    }
                }
            }
            if ($cust_name === '' || $cust_name === '-') {
                $cust_name = resolveCustomerName($conn, $mother_id, $lot_no, $coil_no);
            }
        }

        $startTimeStr   = $active_item['start_time'];
        $startTimestamp = $startTimeStr ? strtotime($startTimeStr) : time();
        $elapsedSec     = time() - $startTimestamp;

        $proc = resolveProcessDetails(
            $active_item['is_recoiled'] ?? 0,
            $active_item['is_reslitted'] ?? 0,
            $active_item['original_source'] ?? ''
        );

        $roll_suffix = (!empty($active_item['roll_no']) && $active_item['roll_no'] !== '-') ? ' ' . $active_item['roll_no'] : '';

        $sub_status = 'IN (pending)';
        if ($active_item['proc_name'] === 'Recoiling') {
            $sub_status = 'Recoiling Process';
        } elseif ($active_item['proc_name'] === 'Reslit') {
            $sub_status = 'Reslit Process';
        }

        $active_len = (float)($active_item['length'] ?? 0);
        if ($active_len <= 0 && $mother_id > 0) {
            $stmt_ml = $conn->prepare("SELECT length FROM mother_coil WHERE id = ? LIMIT 1");
            if ($stmt_ml) {
                $stmt_ml->bind_param("i", $mother_id);
                $stmt_ml->execute();
                $r_ml = $stmt_ml->get_result()->fetch_assoc();
                $stmt_ml->close();
                if ($r_ml) {
                    $active_len = (float)$r_ml['length'];
                }
            }
        }

        $running_data = [
            'has_running'          => true,
            'mother_id'            => $mother_id,
            'lot_no'               => $lot_no,
            'coil_no'              => $coil_no,
            'coil_id_display'      => $lot_no . ' - ' . $coil_no . $roll_suffix,
            'product_type'         => $active_item['product'] ?: 'N/A',
            'mother_length'        => $active_len,
            'mother_length_formatted' => ($active_len > 0) ? number_format($active_len, 0) . ' m' : '-',
            'customer_name'        => $cust_name,
            'process_type'         => $proc['process_type'],
            'process_badge_class'  => $proc['process_badge_class'],
            'process_icon'         => $proc['process_icon'],
            'status'               => 'Running',
            'sub_status'           => $sub_status,
            'status_badge_class'   => 'bg-primary text-white',
            'start_time'           => $startTimeStr ? date('Y-m-d H:i:s', $startTimestamp) : '-',
            'start_time_fmt'       => $startTimeStr ? date('h:i A', $startTimestamp) : '-',
            'start_timestamp'      => $startTimestamp,
            'elapsed_seconds'      => max(0, $elapsedSec),
            'elapsed_formatted'    => formatElapsedTime($elapsedSec),
            'is_packing'           => false,
            'packing_remaining_seconds' => 0,
            'total_rolls'          => (int)$active_item['total_rolls'],
            'completed_rolls'      => (int)$active_item['completed_rolls']
        ];

        // Remaining IN (pending) coils wait in the Queue
        $queued_in_pending = array_slice($all_active_jobs, 1);

    } else {
        // No active running coil: check if a coil recently finished (< 60s ago -> PACKING state)
        $packing_query = "
            SELECT 
                sp.mother_id,
                sp.lot_no,
                sp.coil_no,
                sp.product,
                MAX(sp.customer_name) AS customer_name,
                MIN(sp.date_in) AS start_time,
                MAX(sp.updated_at) AS last_updated,
                COUNT(sp.id) AS total_rolls,
                MAX(mc.length) AS length
            FROM slitting_product sp
            LEFT JOIN mother_coil mc ON sp.mother_id = mc.id
            WHERE (sp.is_voided = 0 OR sp.is_voided IS NULL)
              AND sp.is_completed = 1
              AND sp.actual_length IS NOT NULL
              AND sp.actual_length > 0
            GROUP BY sp.mother_id, sp.lot_no, sp.coil_no, sp.product
            HAVING MAX(sp.updated_at) >= NOW() - INTERVAL 1 MINUTE
            ORDER BY MAX(sp.updated_at) DESC
            LIMIT 1
        ";

        $packing_res = $conn->query($packing_query);
        if ($packing_res && $packing_res->num_rows > 0) {
            $p = $packing_res->fetch_assoc();
            $mother_id  = (int)$p['mother_id'];
            $lot_no     = $p['lot_no'];
            $coil_no    = $p['coil_no'];
            $lastUpdTs  = strtotime($p['last_updated']);
            $elapsedSinceDone = time() - $lastUpdTs;
            $remainingPackingSec = max(0, 60 - $elapsedSinceDone);

            if ($remainingPackingSec > 0) {
                $cust_name = trim($p['customer_name'] ?? '');
                if ($cust_name === '' || $cust_name === '-') {
                    $cust_name = resolveCustomerName($conn, $mother_id, $lot_no, $coil_no);
                }

                $startTimeStr   = $p['start_time'];
                $startTimestamp = $startTimeStr ? strtotime($startTimeStr) : $lastUpdTs;

                $pack_len = (float)($p['length'] ?? 0);
                if ($pack_len <= 0 && $mother_id > 0) {
                    $stmt_pl = $conn->prepare("SELECT length FROM mother_coil WHERE id = ? LIMIT 1");
                    if ($stmt_pl) {
                        $stmt_pl->bind_param("i", $mother_id);
                        $stmt_pl->execute();
                        $r_pl = $stmt_pl->get_result()->fetch_assoc();
                        $stmt_pl->close();
                        if ($r_pl) {
                            $pack_len = (float)$r_pl['length'];
                        }
                    }
                }

                $running_data = [
                    'has_running'       => true,
                    'mother_id'         => $mother_id,
                    'lot_no'            => $lot_no,
                    'coil_no'           => $coil_no,
                    'coil_id_display'   => $lot_no . ' - ' . $coil_no,
                    'product_type'      => $p['product'] ?: 'N/A',
                    'mother_length'     => $pack_len,
                    'mother_length_formatted' => ($pack_len > 0) ? number_format($pack_len, 0) . ' m' : '-',
                    'customer_name'     => $cust_name,
                    'status'            => 'Packing',
                    'sub_status'        => 'Finished Goods Stock',
                    'status_badge_class'=> 'bg-warning text-dark fw-bold',
                    'start_time'        => $startTimeStr ? date('Y-m-d H:i:s', $startTimestamp) : '-',
                    'start_time_fmt'    => $startTimeStr ? date('h:i A', $startTimestamp) : '-',
                    'start_timestamp'   => $startTimestamp,
                    'elapsed_seconds'   => max(0, time() - $startTimestamp),
                    'elapsed_formatted' => formatElapsedTime(time() - $startTimestamp),
                    'is_packing'        => true,
                    'packing_remaining_seconds' => $remainingPackingSec,
                    'total_rolls'       => (int)$p['total_rolls'],
                    'completed_rolls'   => (int)$p['total_rolls']
                ];
            }
        }
    }

    if (!$running_data) {
        $running_data = [
            'has_running' => false,
            'message'     => 'No coil currently in active production. Machine Idle.'
        ];
    }

    // ── B. Build Waiting List Queue in FIFO Order ─────────────────────────────
    // Combined Queue: Prepared Coils (IN (pending)) waiting for machine + Brand New Coils (Waiting)
    $waiting_list = [];
    $pos = 1;

    // Type 1: Prepared Coils (IN (pending)) waiting in queue
    foreach ($queued_in_pending as $item) {
        $m_id = (int)$item['mother_id'];
        $l_no = $item['lot_no'];
        $c_no = $item['coil_no'];
        $cust = trim($item['customer_name'] ?? '');
        if ($cust === '' || $cust === '-') {
            if (!empty($item['slitting_product_id'])) {
                $sp_id = (int)$item['slitting_product_id'];
                $stmt_sp = $conn->prepare("SELECT customer_name, mother_id FROM slitting_product WHERE id = ? LIMIT 1");
                if ($stmt_sp) {
                    $stmt_sp->bind_param("i", $sp_id);
                    $stmt_sp->execute();
                    $r_sp = $stmt_sp->get_result()->fetch_assoc();
                    $stmt_sp->close();
                    if ($r_sp) {
                        if (!empty($r_sp['customer_name'])) {
                            $cust = trim($r_sp['customer_name']);
                        } elseif (!empty($r_sp['mother_id'])) {
                            $cust = resolveCustomerName($conn, (int)$r_sp['mother_id'], $l_no, $c_no);
                        }
                    }
                }
            }
            if ($cust === '' || $cust === '-') {
                $cust = resolveCustomerName($conn, $m_id, $l_no, $c_no);
            }
        }

        $date_str = $item['start_time'];
        $time_fmt = $date_str ? date('d M Y, h:i A', strtotime($date_str)) : '-';

        $proc = resolveProcessDetails(
            $item['is_recoiled'] ?? 0,
            $item['is_reslitted'] ?? 0,
            $item['original_source'] ?? ''
        );

        $roll_suffix = (!empty($item['roll_no']) && $item['roll_no'] !== '-') ? ' ' . $item['roll_no'] : '';

        $status_desc = 'Prepared';
        if (($item['proc_name'] ?? '') === 'Recoiling') {
            $status_desc = 'Recoiling Queue';
        } elseif (($item['proc_name'] ?? '') === 'Reslit') {
            $status_desc = 'Reslit Queue';
        }

        $item_len = (float)($item['length'] ?? 0);
        if ($item_len <= 0 && $m_id > 0) {
            $stmt_il = $conn->prepare("SELECT length FROM mother_coil WHERE id = ? LIMIT 1");
            if ($stmt_il) {
                $stmt_il->bind_param("i", $m_id);
                $stmt_il->execute();
                $r_il = $stmt_il->get_result()->fetch_assoc();
                $stmt_il->close();
                if ($r_il) {
                    $item_len = (float)$r_il['length'];
                }
            }
        }

        $waiting_list[] = [
            'pos'               => $pos++,
            'stock_id'          => 0,
            'mother_id'         => $m_id,
            'coil_id_display'   => $l_no . ' - ' . $c_no . $roll_suffix,
            'lot_no'            => $l_no,
            'coil_no'           => $c_no,
            'product_type'      => $item['product'] ?: 'N/A',
            'length'            => $item_len,
            'length_formatted'  => ($item_len > 0) ? number_format($item_len, 0) . ' m' : '-',
            'customer_name'     => $cust,
            'process_type'      => $proc['process_type'],
            'process_badge_class'=> $proc['process_badge_class'],
            'process_icon'      => $proc['process_icon'],
            'received_at'       => $date_str,
            'received_formatted'=> $time_fmt,
            'status_label'      => 'IN (pending)',
            'status_desc'       => $status_desc,
            'status_badge_class'=> 'bg-warning text-dark'
        ];
    }

    // Type 2: Brand New Coils (Status: Waiting) scanned upon receipt in warehouse
    $waiting_query = "
        SELECT 
            srm.id AS stock_id,
            srm.lot_no,
            srm.coil_no,
            srm.width,
            srm.length,
            srm.date_in,
            srm.created_at,
            mc.id AS mother_id,
            mc.product AS mother_product
        FROM stock_raw_material srm
        LEFT JOIN mother_coil mc 
            ON (srm.source_id = mc.id OR (srm.lot_no = mc.lot_no AND srm.coil_no = mc.coil_no))
        WHERE srm.status = 'IN'
          AND NOT EXISTS (
              SELECT 1 FROM slitting_product sp 
              WHERE (sp.mother_id = mc.id OR (sp.lot_no = srm.lot_no AND sp.coil_no = srm.coil_no))
                AND (sp.is_voided = 0 OR sp.is_voided IS NULL)
          )
        ORDER BY srm.date_in ASC, srm.id ASC
    ";

    $waiting_res = $conn->query($waiting_query);
    if ($waiting_res) {
        while ($w = $waiting_res->fetch_assoc()) {
            $m_id = (int)($w['mother_id'] ?? 0);
            $l_no = $w['lot_no'];
            $c_no = $w['coil_no'];
            $wid  = (float)$w['width'];
            $cust = resolveCustomerName($conn, $m_id, $l_no, $c_no, $wid);

            $date_str = $w['date_in'] ?: $w['created_at'];
            $time_fmt = $date_str ? date('d M Y, h:i A', strtotime($date_str)) : '-';

            $raw_len = (float)($w['length'] ?? 0);

            $waiting_list[] = [
                'pos'               => $pos++,
                'stock_id'          => (int)$w['stock_id'],
                'mother_id'         => $m_id,
                'coil_id_display'   => $l_no . ' - ' . $c_no,
                'lot_no'            => $l_no,
                'coil_no'           => $c_no,
                'product_type'      => $w['mother_product'] ?: 'N/A',
                'length'            => $raw_len,
                'length_formatted'  => ($raw_len > 0) ? number_format($raw_len, 0) . ' m' : '-',
                'customer_name'     => $cust,
                'process_type'      => 'Slitting',
                'process_badge_class'=> 'bg-info text-dark',
                'process_icon'      => 'bi-scissors',
                'received_at'       => $date_str,
                'received_formatted'=> $time_fmt,
                'status_label'      => 'Waiting',
                'status_desc'       => 'Raw Material',
                'status_badge_class'=> 'bg-secondary text-white'
            ];
        }
    }

    $shift_counts = getMotherCoilShiftCounts($conn);
    $shiftTargetMeters = floatval(getSystemSetting($conn, 'shift_target_meters', '5200'));
    if ($shiftTargetMeters <= 0) {
        $shiftTargetMeters = 5200.0;
    }

    $prodDate = getCurrentProductionDate();
    $hasPostMidnightProd = hasProductionAfterMidnight($conn, $prodDate);
    $shiftsMultiplier    = $hasPostMidnightProd ? 3 : 2;
    $target24hMeters     = $shiftTargetMeters * $shiftsMultiplier; // Default: 10,400 m (2 shifts), becomes 15,600 m if production after 12am

    $lengthProduced24h  = calculate24HourMotherCoilLength($conn);
    $weeklyTotalMeters  = calculateWeeklyMotherCoilLength($conn);
    $progressPercentage = ($target24hMeters > 0) ? min(100.0, round(($lengthProduced24h / $target24hMeters) * 100, 1)) : 0.0;

    $recoil_summary = getRecoilingDailyCoilCount($conn);

    echo json_encode([
        'success'         => true,
        'timestamp'       => date('Y-m-d H:i:s'),
        'running'         => $running_data,
        'waiting_list'    => $waiting_list,
        'waiting_count'   => count($waiting_list),
        'shift_summary'   => $shift_counts,
        'recoil_summary'  => $recoil_summary,
        'length_tracking' => [
            'length_produced_24h'    => $lengthProduced24h,
            'shift_target_meters'    => $shiftTargetMeters,
            'target_24h_meters'      => $target24hMeters,
            'shifts_multiplier'      => $shiftsMultiplier,
            'has_post_midnight_prod' => $hasPostMidnightProd,
            'progress_percentage'    => $progressPercentage,
            'weekly_total_meters'    => $weeklyTotalMeters
        ]
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
exit;

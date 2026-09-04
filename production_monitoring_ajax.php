<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include 'config.php';

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

$action = $_REQUEST['action'] ?? 'get_data';

// ══════════════════════════════════════════════════════════════════════════
// 1. GET MONITORING DATA (Refined FIFO Workflow: 1 Running Slot + Queue)
// ══════════════════════════════════════════════════════════════════════════
if ($action === 'get_data') {

    // ── Fetch ALL IN (pending) Coils in FIFO Order (Oldest setup date first) ──
    $all_pending_query = "
        SELECT 
            sp.mother_id,
            sp.lot_no,
            sp.coil_no,
            sp.product,
            MAX(sp.customer_name) AS customer_name,
            MIN(sp.date_in) AS start_time,
            COUNT(sp.id) AS total_rolls,
            SUM(CASE WHEN sp.is_completed = 1 AND sp.actual_length IS NOT NULL AND sp.actual_length > 0 THEN 1 ELSE 0 END) AS completed_rolls,
            MAX(sp.is_recoiled) AS is_recoiled,
            MAX(sp.is_reslitted) AS is_reslitted,
            MAX(sp.original_source) AS original_source
        FROM slitting_product sp
        WHERE (sp.is_voided = 0 OR sp.is_voided IS NULL)
          AND (sp.is_completed = 0 OR sp.actual_length IS NULL OR sp.actual_length = 0)
        GROUP BY sp.mother_id, sp.lot_no, sp.coil_no, sp.product
        ORDER BY MIN(sp.date_in) ASC, MIN(sp.id) ASC
    ";

    $pending_res = $conn->query($all_pending_query);
    $pending_coils = [];
    if ($pending_res) {
        while ($row = $pending_res->fetch_assoc()) {
            $pending_coils[] = $row;
        }
    }

    $running_data = null;
    $queued_in_pending = [];

    if (!empty($pending_coils)) {
        // SLOT 1: Oldest IN (pending) coil becomes the Current Running Coil
        $active_item = $pending_coils[0];
        $mother_id   = (int)$active_item['mother_id'];
        $lot_no      = $active_item['lot_no'];
        $coil_no     = $active_item['coil_no'];

        $cust_name = trim($active_item['customer_name'] ?? '');
        if ($cust_name === '' || $cust_name === '-') {
            $cust_name = resolveCustomerName($conn, $mother_id, $lot_no, $coil_no);
        }

        $startTimeStr   = $active_item['start_time'];
        $startTimestamp = $startTimeStr ? strtotime($startTimeStr) : time();
        $elapsedSec     = time() - $startTimestamp;

        $proc = resolveProcessDetails(
            $active_item['is_recoiled'] ?? 0,
            $active_item['is_reslitted'] ?? 0,
            $active_item['original_source'] ?? ''
        );

        $running_data = [
            'has_running'          => true,
            'mother_id'            => $mother_id,
            'lot_no'               => $lot_no,
            'coil_no'              => $coil_no,
            'coil_id_display'      => $lot_no . ' - ' . $coil_no,
            'product_type'         => $active_item['product'] ?: 'N/A',
            'customer_name'        => $cust_name,
            'process_type'         => $proc['process_type'],
            'process_badge_class'  => $proc['process_badge_class'],
            'process_icon'         => $proc['process_icon'],
            'status'               => 'Running',
            'sub_status'           => 'IN (pending)',
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

        // Remaining IN (pending) coils (index 1 onwards) wait in the Queue
        $queued_in_pending = array_slice($pending_coils, 1);

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
                COUNT(sp.id) AS total_rolls
            FROM slitting_product sp
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

                $running_data = [
                    'has_running'       => true,
                    'mother_id'         => $mother_id,
                    'lot_no'            => $lot_no,
                    'coil_no'           => $coil_no,
                    'coil_id_display'   => $lot_no . ' - ' . $coil_no,
                    'product_type'      => $p['product'] ?: 'N/A',
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
            $cust = resolveCustomerName($conn, $m_id, $l_no, $c_no);
        }

        $date_str = $item['start_time'];
        $time_fmt = $date_str ? date('d M Y, h:i A', strtotime($date_str)) : '-';

        $proc = resolveProcessDetails(
            $item['is_recoiled'] ?? 0,
            $item['is_reslitted'] ?? 0,
            $item['original_source'] ?? ''
        );

        $waiting_list[] = [
            'pos'               => $pos++,
            'stock_id'          => 0,
            'mother_id'         => $m_id,
            'coil_id_display'   => $l_no . ' - ' . $c_no,
            'lot_no'            => $l_no,
            'coil_no'           => $c_no,
            'product_type'      => $item['product'] ?: 'N/A',
            'customer_name'     => $cust,
            'process_type'      => $proc['process_type'],
            'process_badge_class'=> $proc['process_badge_class'],
            'process_icon'      => $proc['process_icon'],
            'received_at'       => $date_str,
            'received_formatted'=> $time_fmt,
            'status_label'      => 'IN (pending)',
            'status_desc'       => 'Prepared',
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

            $waiting_list[] = [
                'pos'               => $pos++,
                'stock_id'          => (int)$w['stock_id'],
                'mother_id'         => $m_id,
                'coil_id_display'   => $l_no . ' - ' . $c_no,
                'lot_no'            => $l_no,
                'coil_no'           => $c_no,
                'product_type'      => $w['mother_product'] ?: 'N/A',
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
    $target24hMeters    = $shiftTargetMeters * 3;
    $lengthProduced24h  = get24HourProductionLength($conn);
    $weeklyTotalMeters  = getWeeklyProductionLength($conn);
    $progressPercentage = ($target24hMeters > 0) ? min(100.0, round(($lengthProduced24h / $target24hMeters) * 100, 1)) : 0.0;

    echo json_encode([
        'success'         => true,
        'timestamp'       => date('Y-m-d H:i:s'),
        'running'         => $running_data,
        'waiting_list'    => $waiting_list,
        'waiting_count'   => count($waiting_list),
        'shift_summary'   => $shift_counts,
        'length_tracking' => [
            'length_produced_24h' => $lengthProduced24h,
            'shift_target_meters' => $shiftTargetMeters,
            'target_24h_meters'   => $target24hMeters,
            'progress_percentage' => $progressPercentage,
            'weekly_total_meters' => $weeklyTotalMeters
        ]
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
exit;

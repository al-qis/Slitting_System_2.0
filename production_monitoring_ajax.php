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
    // 1. Try slitting_plans by mother_id
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

    // 2. Try nci_product_mapping lookup by coil prefix and width if available
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

$action = $_REQUEST['action'] ?? 'get_data';

// ══════════════════════════════════════════════════════════════════════════
// 1. GET MONITORING DATA (Running Coil + Waiting List)
// ══════════════════════════════════════════════════════════════════════════
if ($action === 'get_data') {

    // ── A. Find Active Running Coil or Recently Completed Packing Coil ──────
    // Step A1: Check for RUNNING coils (slitting_product created, is_completed = 0 or actual_length IS NULL/0)
    $running_query = "
        SELECT 
            sp.mother_id,
            sp.lot_no,
            sp.coil_no,
            sp.product,
            MAX(sp.customer_name) AS customer_name,
            MIN(sp.date_in) AS start_time,
            COUNT(sp.id) AS total_rolls,
            SUM(CASE WHEN sp.is_completed = 1 AND sp.actual_length IS NOT NULL AND sp.actual_length > 0 THEN 1 ELSE 0 END) AS completed_rolls
        FROM slitting_product sp
        WHERE (sp.is_voided = 0 OR sp.is_voided IS NULL)
          AND (sp.is_completed = 0 OR sp.actual_length IS NULL OR sp.actual_length = 0)
        GROUP BY sp.mother_id, sp.lot_no, sp.coil_no, sp.product
        ORDER BY MIN(sp.date_in) DESC, MIN(sp.id) DESC
        LIMIT 1
    ";

    $running_res = $conn->query($running_query);
    $running_data = null;

    if ($running_res && $running_res->num_rows > 0) {
        $r = $running_res->fetch_assoc();
        $mother_id = (int)$r['mother_id'];
        $lot_no    = $r['lot_no'];
        $coil_no   = $r['coil_no'];

        // Get customer name if missing
        $cust_name = trim($r['customer_name'] ?? '');
        if ($cust_name === '' || $cust_name === '-') {
            $cust_name = resolveCustomerName($conn, $mother_id, $lot_no, $coil_no);
        }

        // Fetch rolls for modal input
        $rolls_stmt = $conn->prepare("
            SELECT id, roll_no, width, length, actual_length, is_completed, customer_name, ref_no
            FROM slitting_product
            WHERE mother_id = ? AND (is_voided = 0 OR is_voided IS NULL)
            ORDER BY id ASC
        ");
        $rolls_stmt->bind_param("i", $mother_id);
        $rolls_stmt->execute();
        $rolls = $rolls_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $rolls_stmt->close();

        $startTimeStr = $r['start_time'];
        $startTimestamp = $startTimeStr ? strtotime($startTimeStr) : time();
        $elapsedSec = time() - $startTimestamp;

        $running_data = [
            'has_running'       => true,
            'mother_id'         => $mother_id,
            'lot_no'            => $lot_no,
            'coil_no'           => $coil_no,
            'coil_id_display'   => $lot_no . ' - ' . $coil_no,
            'product_type'      => $r['product'] ?: 'N/A',
            'customer_name'     => $cust_name,
            'status'            => 'Running',
            'sub_status'        => 'IN (pending)',
            'status_badge_class'=> 'bg-primary text-white',
            'start_time'        => $startTimeStr ? date('Y-m-d H:i:s', $startTimestamp) : '-',
            'start_time_fmt'    => $startTimeStr ? date('h:i A', $startTimestamp) : '-',
            'start_timestamp'   => $startTimestamp,
            'elapsed_seconds'   => max(0, $elapsedSec),
            'elapsed_formatted' => formatElapsedTime($elapsedSec),
            'is_packing'        => false,
            'packing_remaining_seconds' => 0,
            'total_rolls'       => (int)$r['total_rolls'],
            'completed_rolls'   => (int)$r['completed_rolls'],
            'rolls'             => $rolls
        ];
    } else {
        // Step A2: No currently running coil, check if a coil recently finished (< 60s ago -> PACKING state)
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

                $startTimeStr = $p['start_time'];
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
                    'completed_rolls'   => (int)$p['total_rolls'],
                    'rolls'             => []
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

    // ── B. Fetch Waiting List (Queue) ───────────────────────────────────────
    // Stock raw material with status='IN' and not yet in slitting_product
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
    $waiting_list = [];
    $pos = 1;

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
                'width'             => $wid,
                'length'            => (float)$w['length'],
                'received_at'       => $date_str,
                'received_formatted'=> $time_fmt
            ];
        }
    }

    echo json_encode([
        'success'      => true,
        'timestamp'    => date('Y-m-d H:i:s'),
        'running'      => $running_data,
        'waiting_list' => $waiting_list,
        'waiting_count'=> count($waiting_list)
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// 2. PROCESS BARCODE / QR SCAN
// ══════════════════════════════════════════════════════════════════════════
if ($action === 'process_scan') {
    $qr = isset($_POST['qr']) ? $_POST['qr'] : '';

    // Sanitization steps matching scan_mother_action.php
    $qr = preg_replace('/[[:cntrl:]]/', '', $qr);
    $qr = preg_replace('/^\][A-Za-z][0-9]/', '', $qr);
    $qr = preg_replace('/^[^\w]{0,6}(?=LOT=|COIL=)/i', '', $qr);
    $qr = preg_replace('/^[\w]{1,6}(?=LOT=|COIL=)/i',  '', $qr);
    $qr = trim($qr);

    if (empty($qr)) {
        echo json_encode(['success' => false, 'message' => 'Empty barcode scanned']);
        exit;
    }

    $lot_no = '';
    $coil_no = '';

    if (strpos($qr, '=') !== false) {
        $pairs = [];
        foreach (explode(';', $qr) as $segment) {
            $segment = trim($segment);
            if (strpos($segment, '=') === false) continue;
            [$k, $v] = explode('=', $segment, 2);
            $pairs[strtoupper(trim($k))] = trim($v);
        }
        $lot_no  = $pairs['LOT']  ?? '';
        $coil_no = $pairs['COIL'] ?? '';
    } else {
        $tokens  = preg_split('/\s+/', $qr, 4);
        $lot_no  = trim($tokens[0] ?? '');
        $coil_no = trim($tokens[1] ?? '');
    }

    if (empty($lot_no) || empty($coil_no)) {
        echo json_encode(['success' => false, 'message' => 'Invalid barcode format. Expected LOT=xxx;COIL=xxx']);
        exit;
    }

    $lot_esc  = $conn->real_escape_string($lot_no);
    $coil_esc = $conn->real_escape_string($coil_no);

    // ── Check if coil is currently RUNNING ──────────────────────────────────
    $chk_running = $conn->query("
        SELECT mother_id FROM slitting_product
        WHERE lot_no='$lot_esc' AND coil_no='$coil_esc' AND (is_completed=0 OR actual_length IS NULL OR actual_length=0)
          AND (is_voided=0 OR is_voided IS NULL)
        LIMIT 1
    ");

    if ($chk_running && $chk_running->num_rows > 0) {
        $row = $chk_running->fetch_assoc();
        echo json_encode([
            'success'   => true,
            'type'      => 'running_coil',
            'mother_id' => (int)$row['mother_id'],
            'message'   => "Current Running Coil scanned ($lot_no - $coil_no). Ready to input actual length."
        ]);
        exit;
    }

    // ── Check if coil is in WAITING LIST ────────────────────────────────────
    $chk_waiting = $conn->query("
        SELECT id FROM stock_raw_material
        WHERE lot_no='$lot_esc' AND coil_no='$coil_esc' AND status='IN'
        ORDER BY id DESC LIMIT 1
    ");

    if ($chk_waiting && $chk_waiting->num_rows > 0) {
        $stock = $chk_waiting->fetch_assoc();
        $stock_id = (int)$stock['id'];
        echo json_encode([
            'success'   => true,
            'type'      => 'waiting_to_setup',
            'stock_id'  => $stock_id,
            'redirect'  => "add_slitting.php?stock_id={$stock_id}",
            'message'   => "Coil $lot_no - $coil_no is in Waiting List. Opening operation setup..."
        ]);
        exit;
    }

    // ── Check if coil exists in mother_coil (1st scan in warehouse) ───────
    $chk_mother = $conn->query("SELECT * FROM mother_coil WHERE lot_no='$lot_esc' AND coil_no='$coil_esc' LIMIT 1");
    if (!$chk_mother || $chk_mother->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => "Mother coil not found in database: $lot_no - $coil_no"]);
        exit;
    }

    $mother = $chk_mother->fetch_assoc();
    $mother_id = (int)$mother['id'];

    // Perform 1st Scan (Warehouse scan-in into Waiting List)
    $grade  = $conn->real_escape_string($mother['grade']  ?? '');
    $width  = (float)$mother['width'];
    $length = (float)$mother['length'];

    $conn->begin_transaction();
    try {
        $conn->query("
            INSERT INTO stock_raw_material
                (lot_no, coil_no, grade, width, length, status, source_type, source_id, date_in)
            VALUES
                ('$lot_esc','$coil_esc','$grade',$width,$length,'IN','mother_coil',$mother_id,NOW())
        ");
        $conn->query("
            UPDATE mother_coil
            SET status='IN', stock=1, date_in=NOW(), scan_in_count = scan_in_count + 1
            WHERE id=$mother_id
        ");
        $conn->query("
            INSERT INTO mother_coil_audit_log (mother_id, action_type, performed_at, remark)
            VALUES ($mother_id, 'SCAN_IN', NOW(), 'Scanned IN via Monitoring Page: $lot_no $coil_no')
        ");
        $conn->commit();

        echo json_encode([
            'success' => true,
            'type'    => 'added_to_waiting',
            'message' => "Mother coil $lot_no - $coil_no scanned for 1st time. Added to Waiting List!"
        ]);
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// ══════════════════════════════════════════════════════════════════════════
// 3. SAVE ACTUAL LENGTH (FINALIZE PRODUCTION -> PACKING STATE)
// ══════════════════════════════════════════════════════════════════════════
if ($action === 'save_actual_length') {
    $mother_id = intval($_POST['mother_id'] ?? 0);
    $rolls_data = $_POST['rolls'] ?? [];

    if ($mother_id <= 0 && empty($rolls_data)) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters for saving actual length.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        if (!empty($rolls_data) && is_array($rolls_data)) {
            // Update individual roll actual lengths
            $stmt = $conn->prepare("
                UPDATE slitting_product
                SET actual_length = ?, is_completed = 1, stock_counted = 1, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            foreach ($rolls_data as $r) {
                $pid = intval($r['id'] ?? 0);
                $alen = floatval($r['actual_length'] ?? 0);
                if ($pid > 0 && $alen > 0) {
                    $stmt->bind_param("di", $alen, $pid);
                    $stmt->execute();
                }
            }
            $stmt->close();
        } else if ($mother_id > 0) {
            $bulk_length = floatval($_POST['bulk_actual_length'] ?? 0);
            if ($bulk_length <= 0) {
                throw new Exception("Please specify a valid actual length.");
            }

            $stmt = $conn->prepare("
                UPDATE slitting_product
                SET actual_length = ?, is_completed = 1, stock_counted = 1, updated_at = CURRENT_TIMESTAMP
                WHERE mother_id = ? AND (is_completed = 0 OR actual_length IS NULL OR actual_length = 0)
            ");
            $stmt->bind_param("di", $bulk_length, $mother_id);
            $stmt->execute();
            $stmt->close();
        }

        // Log completion event in process_log
        $performed_by = $_SESSION['role'] ?? 'slitting';
        $log_stmt = $conn->prepare("
            INSERT INTO process_log
                (entity_type, entity_id, mother_id, from_status, to_status, performed_by, action_detail, remark, performed_at)
            VALUES
                ('slitting', 0, ?, 'IN', 'PACKING', ?, 'actual_length_finalized', 'Actual length updated on monitoring page. Packing status triggered for 1 min.', NOW())
        ");
        if ($log_stmt) {
            $log_stmt->bind_param("is", $mother_id, $performed_by);
            $log_stmt->execute();
            $log_stmt->close();
        }

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Actual length updated successfully! Production output finalized. Transitioned to Packing status.'
        ]);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Failed to save actual length: ' . $e->getMessage()]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
exit;

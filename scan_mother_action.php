<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

// Allow BOTH supervisor and slitting roles to scan
if ($_SESSION['role'] !== 'supervisor' && $_SESSION['role'] !== 'slitting') {
    die("Access denied - Only supervisors or slitting staff can scan");
}

include 'config.php';

// Get scan data from POST
$qr = isset($_POST['qr']) ? trim($_POST['qr']) : '';

if (empty($qr)) {
    $_SESSION['error'] = "QR code cannot be empty";
    header("Location: raw_material.php");
    exit;
}

// Parse QR format: LOT=825175;COIL=FK-1
$parts = [];
if (strpos($qr, ';') !== false) {
    $pairs = explode(';', $qr);
    foreach ($pairs as $pair) {
        if (strpos($pair, '=') !== false) {
            list($key, $value) = explode('=', $pair, 2);
            $parts[trim($key)] = trim($value);
        }
    }
}

$lot_no  = isset($parts['LOT'])  ? $conn->real_escape_string($parts['LOT'])  : '';
$coil_no = isset($parts['COIL']) ? $conn->real_escape_string($parts['COIL']) : '';

if (empty($lot_no) || empty($coil_no)) {
    $_SESSION['error'] = "Invalid QR format. Expected: LOT=xxx;COIL=xxx";
    header("Location: raw_material.php");
    exit;
}

// ============================================================================
// PRIORITY CHECK: Is this a LEFTOVER (Cut Into 2 balance) scan?
// If a stock_raw_material row exists with source_type='slitting_cut_into_2'
// and status='IN' for this lot+coil, treat it as a leftover — send straight
// to add_slitting.php WITHOUT touching mother_coil scan-in logic at all.
// ============================================================================
$leftover_check = $conn->query(
    "SELECT id FROM stock_raw_material 
     WHERE lot_no='$lot_no' 
       AND coil_no='$coil_no' 
       AND source_type='slitting_cut_into_2' 
       AND status='IN' 
     ORDER BY id DESC 
     LIMIT 1"
);

if ($leftover_check && $leftover_check->num_rows > 0) {
    // This is a leftover coil — redirect directly to slitting form
    $leftover = $leftover_check->fetch_assoc();
    $_SESSION['success'] = "Leftover coil $lot_no $coil_no ready for slitting. Fill the form.";
    header("Location: add_slitting.php?stock_id=" . $leftover['id']);
    exit;
}

// ============================================================================
// NOT a leftover — proceed with normal mother coil scan logic
// ============================================================================

// Get mother coil record
$query  = "SELECT * FROM mother_coil WHERE lot_no='$lot_no' AND coil_no='$coil_no'";
$result = $conn->query($query);

if (!$result || $result->num_rows === 0) {
    $_SESSION['error'] = "Mother coil not found: $lot_no - $coil_no";
    header("Location: raw_material.php");
    exit;
}

$mother    = $result->fetch_assoc();
$mother_id = $mother['id'];

// Start transaction for data consistency
$conn->begin_transaction();

try {
    // Check if this mother coil already exists in stock_raw_material
    $stock_check = "SELECT id, status FROM stock_raw_material 
                    WHERE lot_no='$lot_no' AND coil_no='$coil_no' AND source_type='mother_coil'
                    ORDER BY id DESC LIMIT 1";
    $stock_check_result = $conn->query($stock_check);

    if ($stock_check_result && $stock_check_result->num_rows > 0) {
        // Entry exists — check status
        $existing_stock = $stock_check_result->fetch_assoc();
        $stock_id       = $existing_stock['id'];
        $current_status = $existing_stock['status'];

        if ($current_status === 'IN') {
            // SECOND SCAN — coil is IN stock, user wants to use it for slitting
            // Do NOT mark as OUT yet — add_slitting.php handles that on save
            $conn->commit();

            $_SESSION['success'] = "Mother coil $lot_no-$coil_no ready for slitting. Fill the form.";
            header("Location: add_slitting.php?stock_id=$stock_id");
            exit;

        } else {
            // Status is OUT — toggle back to IN
            $update_stock_query = "UPDATE stock_raw_material 
                                   SET status='IN', updated_at=NOW()
                                   WHERE id=$stock_id";
            if (!$conn->query($update_stock_query)) {
                throw new Exception("Failed to update stock_raw_material: " . $conn->error);
            }

            $update_mother_query = "UPDATE mother_coil 
                                    SET status='IN', stock=1
                                    WHERE id=$mother_id";
            if (!$conn->query($update_mother_query)) {
                throw new Exception("Failed to update mother coil: " . $conn->error);
            }

            $conn->query("INSERT INTO mother_coil_audit_log (mother_id, action_type, performed_at, remark) 
                          VALUES ($mother_id, 'SCAN_IN', NOW(), 'Toggled back IN: $lot_no $coil_no')");

            $conn->commit();

            $_SESSION['message'] = "Mother coil $lot_no-$coil_no toggled back to IN";
            header("Location: raw_material.php");
            exit;
        }

    } else {
        // Entry DOESN'T exist — FIRST SCAN, create stock_raw_material entry
        $insert_stock_query = "INSERT INTO stock_raw_material 
                               (lot_no, coil_no, grade, width, length, status, source_type, source_id, date_in) 
                               VALUES 
                               ('$lot_no', '$coil_no', 
                                '" . $conn->real_escape_string($mother['grade'] ?? '') . "',
                                " . (float)$mother['width'] . ",
                                " . (float)$mother['length'] . ",
                                'IN',
                                'mother_coil',
                                $mother_id,
                                NOW())";
        if (!$conn->query($insert_stock_query)) {
            throw new Exception("Failed to insert into stock_raw_material: " . $conn->error);
        }

        $update_mother_query = "UPDATE mother_coil 
                                SET status='IN', stock=1, date_in=NOW(),
                                    scan_in_count = scan_in_count + 1
                                WHERE id=$mother_id";
        if (!$conn->query($update_mother_query)) {
            throw new Exception("Failed to update mother coil: " . $conn->error);
        }

        $conn->query("INSERT INTO mother_coil_audit_log (mother_id, action_type, performed_at, remark) 
                      VALUES ($mother_id, 'SCAN_IN', NOW(), 'Scanned IN: $lot_no $coil_no')");

        $conn->query("INSERT INTO raw_material_log (mother_id, status, action, date_in, remark) 
                      VALUES ($mother_id, 'IN', 'normal', NOW(), 'Scanned IN: $lot_no $coil_no')");

        $conn->commit();

        $_SESSION['success'] = "Mother coil $lot_no-$coil_no scanned IN. Ready for use.";
        header("Location: raw_material.php");
        exit;
    }

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = "Error: " . $e->getMessage();
    header("Location: raw_material.php");
    exit;
}

$conn->close();
?>
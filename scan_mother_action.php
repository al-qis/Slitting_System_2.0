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

$lot_no = isset($parts['LOT']) ? $conn->real_escape_string($parts['LOT']) : '';
$coil_no = isset($parts['COIL']) ? $conn->real_escape_string($parts['COIL']) : '';

if (empty($lot_no) || empty($coil_no)) {
    $_SESSION['error'] = "Invalid QR format. Expected: LOT=xxx;COIL=xxx";
    header("Location: raw_material.php");
    exit;
}

// Get mother coil
$query = "SELECT * FROM mother_coil WHERE lot_no='$lot_no' AND coil_no='$coil_no'";
$result = $conn->query($query);

if (!$result || $result->num_rows === 0) {
    $_SESSION['error'] = "Mother coil not found: $lot_no - $coil_no";
    header("Location: raw_material.php");
    exit;
}

$mother = $result->fetch_assoc();
$mother_id = $mother['id'];

// Start transaction for data consistency
$conn->begin_transaction();

try {
    // Check if this coil already exists in stock_raw_material
    $stock_check = "SELECT id, status FROM stock_raw_material 
                    WHERE lot_no='$lot_no' AND coil_no='$coil_no' AND source_type='mother_coil'
                    ORDER BY id DESC LIMIT 1";
    $stock_check_result = $conn->query($stock_check);

    if ($stock_check_result && $stock_check_result->num_rows > 0) {
        // Entry exists - Check status
        $existing_stock = $stock_check_result->fetch_assoc();
        $stock_id = $existing_stock['id'];
        $current_status = $existing_stock['status'];

        if ($current_status === 'IN') {
            // SECOND SCAN - Entry exists and status is IN
            // This is when user wants to USE the coil
            
            // DO NOT mark as OUT yet! Just redirect to add_slitting.php
            // The form will handle marking as OUT when user saves
            
            // Commit transaction (no updates made yet)
            $conn->commit();

            // REDIRECT TO add_slitting.php with stock_id
            // add_slitting.php will handle marking as OUT when user saves the form
            $_SESSION['success'] = "Mother coil $lot_no-$coil_no ready for slitting. Fill the form.";
            header("Location: add_slitting.php?stock_id=$stock_id");
            exit;

        } else {
            // Status is OUT - Toggle back to IN (optional feature)
            $update_stock_query = "UPDATE stock_raw_material 
                                   SET status='IN', 
                                       updated_at=NOW()
                                   WHERE id=$stock_id";
            
            if (!$conn->query($update_stock_query)) {
                throw new Exception("Failed to update stock_raw_material: " . $conn->error);
            }

            // Update mother_coil
            $update_mother_query = "UPDATE mother_coil 
                                    SET status='IN', 
                                        stock=1
                                    WHERE id=$mother_id";
            
            if (!$conn->query($update_mother_query)) {
                throw new Exception("Failed to update mother coil: " . $conn->error);
            }

            // Log audit
            $audit_query = "INSERT INTO mother_coil_audit_log (mother_id, action_type, performed_at, remark) 
                            VALUES ($mother_id, 'SCAN_IN', NOW(), 'Toggled back IN: $lot_no $coil_no')";
            $conn->query($audit_query);

            $conn->commit();

            $_SESSION['message'] = "Mother coil $lot_no-$coil_no toggled back to IN";
            header("Location: raw_material.php");
            exit;
        }

    } else {
        // Entry DOESN'T exist - FIRST SCAN
        // Create entry in stock_raw_material with status='IN'
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

        // Update mother_coil
        $update_mother_query = "UPDATE mother_coil 
                                SET status='IN', 
                                    stock=1, 
                                    date_in=NOW(),
                                    scan_in_count = scan_in_count + 1
                                WHERE id=$mother_id";
        
        if (!$conn->query($update_mother_query)) {
            throw new Exception("Failed to update mother coil: " . $conn->error);
        }

        // Log audit
        $audit_query = "INSERT INTO mother_coil_audit_log (mother_id, action_type, performed_at, remark) 
                        VALUES ($mother_id, 'SCAN_IN', NOW(), 'Scanned IN: $lot_no $coil_no')";
        $conn->query($audit_query);

        // Create log entry
        $log_query = "INSERT INTO raw_material_log (mother_id, status, action, date_in, remark) 
                      VALUES ($mother_id, 'IN', 'normal', NOW(), 'Scanned IN: $lot_no $coil_no')";
        $conn->query($log_query);

        // Commit transaction
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
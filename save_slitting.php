<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION['role'] !== 'slitting') {
    die("Access denied");
}

include 'config.php';

// Get form data
$source_type = isset($_POST['source_type']) ? $_POST['source_type'] : '';
$mother_id = isset($_POST['mother_id']) ? intval($_POST['mother_id']) : 0;
$stock_id = isset($_POST['stock_id']) ? intval($_POST['stock_id']) : 0;
$cut_type = isset($_POST['cut_type']) ? $_POST['cut_type'] : '';
$product = isset($_POST['product']) ? $conn->real_escape_string($_POST['product']) : '';
$lot_no = isset($_POST['lot_no']) ? $conn->real_escape_string($_POST['lot_no']) : '';
$coil_no = isset($_POST['coil_no']) ? $conn->real_escape_string($_POST['coil_no']) : '';

// Get arrays
$roll_nos = isset($_POST['roll_no']) ? $_POST['roll_no'] : [];
$cut_letters = isset($_POST['cut_letter']) ? $_POST['cut_letter'] : [];
$lengths = isset($_POST['length']) ? $_POST['length'] : [];
$widths = isset($_POST['width']) ? $_POST['width'] : [];
$send_to_sfc = isset($_POST['send_to_sfc']) ? $_POST['send_to_sfc'] : [];

// Validate required fields
if (!$mother_id || !$cut_type) {
    die("Error: Missing required fields (mother_id or cut_type)");
}

// Start transaction
$conn->begin_transaction();

try {
    // Get mother coil data
    $mother_query = "SELECT * FROM mother_coil WHERE id=$mother_id";
    $mother_result = $conn->query($mother_query);
    
    if (!$mother_result || $mother_result->num_rows === 0) {
        throw new Exception("Mother coil not found for ID: $mother_id");
    }
    
    $mother = $mother_result->fetch_assoc();

    // Handle "Cut Into 2" - Create new stock mother coil for leftover
    if ($cut_type === 'cut_into_2') {
        $slit_quantity = isset($_POST['slit_quantity']) ? floatval($_POST['slit_quantity']) : 0;
        $stock_value = isset($_POST['stock']) ? floatval($_POST['stock']) : 0;

        if ($stock_value > 0) {
            // Create new mother coil record for leftover stock
            $insert_stock_query = "INSERT INTO mother_coil 
                                   (coil_no, product, lot_no, grade, width, length, status, date_in, stock) 
                                   VALUES ('STOCK-" . time() . "', 
                                           '$product', 
                                           '$lot_no', 
                                           '" . $conn->real_escape_string($mother['grade']) . "',
                                           " . $mother['width'] . ",
                                           $stock_value,
                                           'IN',
                                           NOW(),
                                           1)";
            
            if (!$conn->query($insert_stock_query)) {
                throw new Exception("Failed to create stock coil: " . $conn->error);
            }
            
            $stock_mother_id = $conn->insert_id;

            // Log the stock creation
            $audit_query = "INSERT INTO mother_coil_audit_log (mother_id, action_type, performed_at, remark) 
                            VALUES ($stock_mother_id, 'CREATED', NOW(), 'Stock from cut_into_2')";
            $conn->query($audit_query);
        }

        // Update raw_material_log to mark stock as used
        if ($stock_id > 0) {
            $update_log_query = "UPDATE raw_material_log SET status='OUT', date_out=NOW() WHERE id=$stock_id";
            $conn->query($update_log_query);
        }
    }

    // Insert slitting products
    foreach ($roll_nos as $index => $roll_no) {
        $length = floatval($lengths[$index] ?? 0);
        $width = floatval($widths[$index] ?? 0);
        $cut_letter = $conn->real_escape_string($cut_letters[$index] ?? '');
        $is_sfc = in_array($index, $send_to_sfc) ? 1 : 0;

        // Build the INSERT query - only include columns that exist in slitting_product
        $insert_query = "INSERT INTO slitting_product 
                         (product, lot_no, coil_no, roll_no, width, length, mother_id, 
                          status, cut_type, slit_quantity, date_in, source) 
                         VALUES 
                         ('$product', 
                          '$lot_no', 
                          '$coil_no', 
                          '$roll_no', 
                          $width, 
                          $length, 
                          $mother_id, 
                          'IN', 
                          '$cut_type', 
                          " . (isset($_POST['slit_quantity']) ? floatval($_POST['slit_quantity']) : 0) . ", 
                          NOW(), 
                          '$source_type')";

        if (!$conn->query($insert_query)) {
            throw new Exception("Failed to insert slitting product: " . $conn->error);
        }

        $slitting_id = $conn->insert_id;

        // Handle SFC entries
        if ($is_sfc) {
            $sfc_query = "INSERT INTO sfc (product, lot_no, coil_no, width, length, action, date_created) 
                          VALUES ('$product', '$lot_no', '$coil_no', $width, $length, 'slitting', NOW())";
            $conn->query($sfc_query);
        }
    }

    // Update mother coil stock status if normal cut
    if ($cut_type === 'normal') {
        $update_mother_query = "UPDATE mother_coil SET stock=0, status='OUT', date_out=NOW() WHERE id=$mother_id";
        $conn->query($update_mother_query);

        // Log the action
        $audit_query = "INSERT INTO mother_coil_audit_log (mother_id, action_type, performed_at, remark) 
                        VALUES ($mother_id, 'OUT', NOW(), 'Normal slitting completed')";
        $conn->query($audit_query);
    }

    // Commit transaction
    $conn->commit();

    $_SESSION['success'] = "Slitting products saved successfully (" . count($roll_nos) . " rolls)";
    header("Location: raw_material.php");
    exit;

} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    die("Error: " . $e->getMessage());
}

$conn->close();
?>
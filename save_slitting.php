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

// Get SFC balance width for Normal Slitting
$sfc_balance_width = isset($_POST['sfc_balance_width']) ? floatval($_POST['sfc_balance_width']) : 0;

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

    $balance_stock_id = null; // Track the balance stock ID for printing

    // Handle "Cut Into 2" - Save leftover to stock_raw_material with automatic suffix
    if ($cut_type === 'cut_into_2') {
        $slit_quantity = isset($_POST['slit_quantity']) ? floatval($_POST['slit_quantity']) : 0;
        $stock_value = isset($_POST['stock']) ? floatval($_POST['stock']) : 0;

        if ($stock_value > 0) {
            // Generate automatic suffix letter for leftover lot number
            $base_lot = $lot_no;
            
            $suffix_check = "SELECT lot_no FROM stock_raw_material 
                             WHERE lot_no LIKE '" . $conn->real_escape_string($base_lot) . "%' 
                             ORDER BY lot_no DESC LIMIT 1";
            $suffix_result = $conn->query($suffix_check);
            
            $next_suffix = 'a';
            
            if ($suffix_result && $suffix_result->num_rows > 0) {
                $last_lot = $suffix_result->fetch_assoc()['lot_no'];
                $last_char = substr($last_lot, -1);
                
                if (ctype_alpha($last_char)) {
                    $next_suffix = chr(ord($last_char) + 1);
                } else {
                    $next_suffix = 'a';
                }
            }
            
            $new_lot_no = $base_lot . $next_suffix;
            
            // Insert leftover into stock_raw_material
            $insert_stock_query = "INSERT INTO stock_raw_material 
                                   (lot_no, coil_no, grade, width, length, status, source_type, source_id, date_in) 
                                   VALUES ('" . $conn->real_escape_string($new_lot_no) . "', 
                                           '$coil_no', 
                                           '" . $conn->real_escape_string($mother['grade'] ?? '') . "',
                                           " . $mother['width'] . ",
                                           $stock_value,
                                           'IN',
                                           'slitting_cut_into_2',
                                           $mother_id,
                                           NOW())";
            
            if (!$conn->query($insert_stock_query)) {
                throw new Exception("Failed to save leftover stock: " . $conn->error);
            }

            $balance_stock_id = $conn->insert_id;
        }

        // Mark the original stock_raw_material entry as OUT
        if ($stock_id > 0) {
            $update_stock_log = "UPDATE stock_raw_material 
                                 SET status='OUT', 
                                     updated_at=NOW()
                                 WHERE id=$stock_id";
            if (!$conn->query($update_stock_log)) {
                throw new Exception("Failed to mark stock as used: " . $conn->error);
            }
        }

        // Update raw_material_log for tracking
        $update_log_query = "UPDATE raw_material_log 
                             SET status='OUT', date_out=NOW() 
                             WHERE mother_id=$mother_id LIMIT 1";
        $conn->query($update_log_query);
    }

    // ============================================================================
    // EXCLUSIVE LOGIC GATES: Process each roll with conditional routing
    // ============================================================================
    
    foreach ($roll_nos as $index => $roll_no) {
        $length = floatval($lengths[$index] ?? 0);
        $width = floatval($widths[$index] ?? 0);
        $cut_letter = $conn->real_escape_string($cut_letters[$index] ?? '');
        
        // ========================================================================
        // DECISION POINT: Check if this roll should go to SFC
        // ========================================================================
        $roll_number_str = (string)($index + 1);
        $send_to_sfc_flag = in_array($roll_number_str, $send_to_sfc);
        
        // ========================================================================
        // PATH A: SEND TO SFC (if checkbox is TICKED)
        // ========================================================================
        if ($send_to_sfc_flag) {
            // Insert ONLY into sfc table
            // Do NOT insert into slitting_product for this roll
            
            $sfc_query = "INSERT INTO sfc 
                          (product, lot_no, coil_no, roll_no, width, length, action, date_created) 
                          VALUES 
                          ('$product', '$lot_no', '$coil_no', '$roll_no', $width, $length, 'slitting', NOW())";
            
            if (!$conn->query($sfc_query)) {
                throw new Exception("Failed to insert into SFC table: " . $conn->error);
            }
            
            // Log this action for audit trail
            $audit_log = "INSERT INTO slitting_audit_log (mother_id, action, roll_no, destination, created_at) 
                         VALUES ($mother_id, 'send_to_sfc', '$roll_no', 'sfc_stock', NOW())";
            $conn->query($audit_log);
            
            // STOP here for this roll - do not proceed to finished_products
            continue; // Skip the rest of the loop for this item
        }
        
        // ========================================================================
        // PATH B: GO TO FINISHED PRODUCTS (if checkbox is NOT ticked)
        // ========================================================================
        else {
            // Insert ONLY into slitting_product table
            // Do NOT insert into sfc for this roll
            
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
            
            // Log this action for audit trail
            $audit_log = "INSERT INTO slitting_audit_log (mother_id, action, roll_no, destination, created_at) 
                         VALUES ($mother_id, 'send_to_finished', '$roll_no', 'slitting_product', NOW())";
            $conn->query($audit_log);
        }
    }
    
    // ============================================================================
    // NORMAL SLITTING: Handle special case of balance width
    // ============================================================================
    
    if ($cut_type === 'normal') {
        $update_mother_query = "UPDATE mother_coil SET stock=0, status='OUT', date_out=NOW() WHERE id=$mother_id";
        if (!$conn->query($update_mother_query)) {
            throw new Exception("Failed to update mother coil: " . $conn->error);
        }

        // Update stock_raw_material to mark as OUT
        if ($stock_id > 0) {
            $update_stock_normal = "UPDATE stock_raw_material 
                                    SET status='OUT', 
                                        updated_at=NOW()
                                    WHERE id=$stock_id";
            if (!$conn->query($update_stock_normal)) {
                throw new Exception("Failed to mark stock as OUT: " . $conn->error);
            }
        }

        // Handle SFC balance width for Normal Slitting
        if ($sfc_balance_width > 0) {
            $balance_roll_no = "BALANCE";
            $balance_length = isset($_POST['length']) ? floatval($_POST['length'][0] ?? 0) : 0;
            
            // This balance width goes to SFC
            $sfc_balance_query = "INSERT INTO sfc 
                                  (product, lot_no, coil_no, roll_no, width, length, action, date_created) 
                                  VALUES 
                                  ('$product', '$lot_no', '$coil_no', '$balance_roll_no', 
                                   $sfc_balance_width, $balance_length, 'slitting_balance', NOW())";
            
            if (!$conn->query($sfc_balance_query)) {
                throw new Exception("Failed to insert SFC balance: " . $conn->error);
            }
        }

        // Log the action
        $audit_query = "INSERT INTO mother_coil_audit_log (mother_id, action_type, performed_at, remark) 
                        VALUES ($mother_id, 'OUT', NOW(), 'Normal slitting completed')";
        $conn->query($audit_query);
    }

    // For Cut Into 2: Mark mother_coil as OUT
    if ($cut_type === 'cut_into_2') {
        $update_mother_cut_into_2 = "UPDATE mother_coil SET stock=0, status='OUT', date_out=NOW() WHERE id=$mother_id";
        if (!$conn->query($update_mother_cut_into_2)) {
            throw new Exception("Failed to update mother coil for Cut Into 2: " . $conn->error);
        }
    }

    // Commit transaction
    $conn->commit();

    // For Cut Into 2: Redirect to print balance sticker page
    if ($cut_type === 'cut_into_2' && $balance_stock_id > 0) {
        header("Location: print_balance_sticker_MK_STYLE.php?stock_id=$balance_stock_id");
        exit;
    }
    
    // For normal cut: Show success message and redirect to raw_material
    $_SESSION['success'] = "✓ Slitting products saved successfully (" . count($roll_nos) . " rolls)";
    header("Location: raw_material.php");
    exit;

} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    die("Error: " . $e->getMessage());
}

$conn->close();
?>
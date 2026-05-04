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
$mother_id   = isset($_POST['mother_id'])   ? intval($_POST['mother_id'])   : 0;
$stock_id    = isset($_POST['stock_id'])    ? intval($_POST['stock_id'])    : 0;
$cut_type    = isset($_POST['cut_type'])    ? $_POST['cut_type']            : '';
$product     = isset($_POST['product'])     ? $conn->real_escape_string($_POST['product'])  : '';
$lot_no      = isset($_POST['lot_no'])      ? $conn->real_escape_string($_POST['lot_no'])   : '';
$coil_no     = isset($_POST['coil_no'])     ? $conn->real_escape_string($_POST['coil_no'])  : '';

// Get arrays
$roll_nos    = isset($_POST['roll_no'])     ? $_POST['roll_no']    : [];
$cut_letters = isset($_POST['cut_letter'])  ? $_POST['cut_letter'] : [];
$lengths     = isset($_POST['length'])      ? $_POST['length']     : [];
$widths      = isset($_POST['width'])       ? $_POST['width']      : [];
$send_to_sfc = isset($_POST['send_to_sfc']) ? $_POST['send_to_sfc']: [];

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
    $mother_query  = "SELECT * FROM mother_coil WHERE id=$mother_id";
    $mother_result = $conn->query($mother_query);

    if (!$mother_result || $mother_result->num_rows === 0) {
        throw new Exception("Mother coil not found for ID: $mother_id");
    }

    $mother = $mother_result->fetch_assoc();

    $balance_stock_id = null; // Track the balance stock ID for printing

    // ============================================================================
    // Handle "Cut Into 2" — Save leftover to stock_raw_material
    // ============================================================================
    if ($cut_type === 'cut_into_2') {
        $slit_quantity = isset($_POST['slit_quantity']) ? floatval($_POST['slit_quantity']) : 0;
        $stock_value   = isset($_POST['stock'])         ? floatval($_POST['stock'])         : 0;

        if ($stock_value > 0) {
            // Use lot_no as-is for the leftover — no automatic suffix assigned
            $new_lot_no = $lot_no;

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
                                 SET status='OUT', updated_at=NOW()
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
    // Process each roll — with per-roll lot_no suffix concatenation (THE FIX)
    // ============================================================================

    foreach ($roll_nos as $index => $roll_no) {
        $length = floatval($lengths[$index] ?? 0);
        $width  = floatval($widths[$index] ?? 0);

        // ✅ FIX: Build the modified lot_no PER ROLL by appending cut_letter
        // e.g. lot_no="111111", cut_letter="a"  →  roll_lot_no="111111a"
        //      lot_no="111111", cut_letter=""   →  roll_lot_no="111111"
        $cut_letter   = trim($cut_letters[$index] ?? '');
        $roll_lot_no  = $conn->real_escape_string($lot_no . $cut_letter);

        // Escape roll_no for use in SQL
        $roll_no_safe = $conn->real_escape_string($roll_no);

        // Determine if this roll goes to SFC (checkbox value is 1-based roll index as string)
        $roll_number_str  = (string)($index + 1);
        $send_to_sfc_flag = in_array($roll_number_str, $send_to_sfc);

        // ========================================================================
        // PATH A: SEND TO SFC (checkbox ticked)
        // ========================================================================
        if ($send_to_sfc_flag) {
            $sfc_query = "INSERT INTO sfc 
                          (product, lot_no, coil_no, roll_no, width, length, action, date_created) 
                          VALUES 
                          ('$product', '$roll_lot_no', '$coil_no', '$roll_no_safe', $width, $length, 'slitting', NOW())";

            if (!$conn->query($sfc_query)) {
                throw new Exception("Failed to insert into SFC table: " . $conn->error);
            }

            // Audit log
            $audit_log = "INSERT INTO slitting_audit_log (mother_id, action, roll_no, destination, created_at) 
                          VALUES ($mother_id, 'send_to_sfc', '$roll_no_safe', 'sfc_stock', NOW())";
            $conn->query($audit_log);

            continue; // Do not insert into slitting_product for this roll
        }

        // ========================================================================
        // PATH B: GO TO FINISHED PRODUCTS (checkbox not ticked)
        // ========================================================================
        else {
            $slit_quantity_val = isset($_POST['slit_quantity']) ? floatval($_POST['slit_quantity']) : 0;

            $insert_query = "INSERT INTO slitting_product 
                             (product, lot_no, coil_no, roll_no, width, length, mother_id, 
                              status, cut_type, slit_quantity, date_in, source) 
                             VALUES 
                             ('$product', 
                              '$roll_lot_no', 
                              '$coil_no', 
                              '$roll_no_safe', 
                              $width, 
                              $length, 
                              $mother_id, 
                              'IN', 
                              '$cut_type', 
                              $slit_quantity_val, 
                              NOW(), 
                              '$source_type')";

            if (!$conn->query($insert_query)) {
                throw new Exception("Failed to insert slitting product: " . $conn->error);
            }

            // Audit log
            $audit_log = "INSERT INTO slitting_audit_log (mother_id, action, roll_no, destination, created_at) 
                          VALUES ($mother_id, 'send_to_finished', '$roll_no_safe', 'slitting_product', NOW())";
            $conn->query($audit_log);
        }
    }

    // ============================================================================
    // NORMAL SLITTING: Mark mother coil as OUT + handle SFC balance width
    // ============================================================================
    if ($cut_type === 'normal') {
        $update_mother_query = "UPDATE mother_coil SET stock=0, status='OUT', date_out=NOW() WHERE id=$mother_id";
        if (!$conn->query($update_mother_query)) {
            throw new Exception("Failed to update mother coil: " . $conn->error);
        }

        if ($stock_id > 0) {
            $update_stock_normal = "UPDATE stock_raw_material 
                                    SET status='OUT', updated_at=NOW()
                                    WHERE id=$stock_id";
            if (!$conn->query($update_stock_normal)) {
                throw new Exception("Failed to mark stock as OUT: " . $conn->error);
            }
        }

        // Handle SFC balance width entry for Normal Slitting
        if ($sfc_balance_width > 0) {
            $balance_length    = isset($_POST['length']) ? floatval($_POST['length'][0] ?? 0) : 0;
            $balance_roll_no   = "BALANCE";

            $sfc_balance_query = "INSERT INTO sfc 
                                  (product, lot_no, coil_no, roll_no, width, length, action, date_created) 
                                  VALUES 
                                  ('$product', '$lot_no', '$coil_no', '$balance_roll_no', 
                                   $sfc_balance_width, $balance_length, 'slitting_balance', NOW())";

            if (!$conn->query($sfc_balance_query)) {
                throw new Exception("Failed to insert SFC balance: " . $conn->error);
            }
        }

        // Audit log
        $audit_query = "INSERT INTO mother_coil_audit_log (mother_id, action_type, performed_at, remark) 
                        VALUES ($mother_id, 'OUT', NOW(), 'Normal slitting completed')";
        $conn->query($audit_query);
    }

    // ============================================================================
    // CUT INTO 2: Mark mother coil as OUT
    // ============================================================================
    if ($cut_type === 'cut_into_2') {
        $update_mother_cut_into_2 = "UPDATE mother_coil SET stock=0, status='OUT', date_out=NOW() WHERE id=$mother_id";
        if (!$conn->query($update_mother_cut_into_2)) {
            throw new Exception("Failed to update mother coil for Cut Into 2: " . $conn->error);
        }
    }

    // Commit transaction
    $conn->commit();

    // Redirect to raw_material with success for both cut types
    // Note: print_balance_sticker_MK_STYLE.php redirect removed — file does not exist on server.
    // Balance stock is already saved to stock_raw_material; user can print from raw_material page.
    $_SESSION['success'] = "✓ Slitting products saved successfully (" . count($roll_nos) . " rolls)";
    header("Location: raw_material.php");
    exit;

} catch (Exception $e) {
    $conn->rollback();
    die("Error: " . $e->getMessage());
}

$conn->close();
?>
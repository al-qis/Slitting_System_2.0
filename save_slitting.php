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

// Retrieve form data
$source_type = trim($_POST['source_type'] ?? '');
$mother_id = intval($_POST['mother_id'] ?? 0);
$stock_id = intval($_POST['stock_id'] ?? 0);
$product = trim($_POST['product'] ?? '');
$lot_no = trim($_POST['lot_no'] ?? '');
$coil_no = trim($_POST['coil_no'] ?? '');
$cut_type = trim($_POST['cut_type'] ?? 'normal');
$slit_quantity = floatval($_POST['slit_quantity'] ?? 0);
$stock = floatval($_POST['stock'] ?? 0);
$sfc_balance_width = floatval($_POST['sfc_balance_width'] ?? 0);

$roll_nos = $_POST['roll_no'] ?? [];
$cut_letters = $_POST['cut_letter'] ?? [];
$lengths = $_POST['length'] ?? [];
$widths = $_POST['width'] ?? [];
$send_to_sfc = $_POST['send_to_sfc'] ?? []; // Array of roll numbers to send to SFC

// Validate input
if (count($roll_nos) === 0 || count($widths) === 0) {
    die("Error: No rolls provided");
}

$conn->begin_transaction();

try {
    // 1. Process each output roll
    for ($i = 0; $i < count($roll_nos); $i++) {
        $roll_no = trim($roll_nos[$i]);
        $cut_letter = trim($cut_letters[$i] ?? '');
        $length = floatval($lengths[$i]);
        $width = floatval($widths[$i]);
        $roll_number = $i + 1; // 1-based roll number
        
        // Build lot_no with cut letter if provided
        $modified_lot_no = $cut_letter ? ($lot_no . $cut_letter) : $lot_no;
        
        // Check if this roll is selected for SFC
        $is_for_sfc = in_array($roll_number, $send_to_sfc);
        
        if ($is_for_sfc) {
            // ROUTE 1: Send to SFC inventory
            // Insert into sfc table with roll_no included
            $stmt = $conn->prepare("INSERT INTO sfc 
                (product, lot_no, coil_no, roll_no, width, length, date_created) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())");
            
            if (!$stmt) {
                throw new Exception("SFC Prepare failed: " . $conn->error);
            }
            
            $stmt->bind_param(
                "ssssdd",
                $product,
                $modified_lot_no,
                $coil_no,
                $roll_no,
                $width,
                $length
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Error inserting to SFC: " . $stmt->error);
            }
            
            $stmt->close();
            
        } else {
            // ROUTE 2: Send to Finish Product (slitting_product)
            // Insert into slitting_product table
            $stmt = $conn->prepare("INSERT INTO slitting_product 
                (product, lot_no, coil_no, roll_no, width, length, status, date_in, cut_type, mother_id) 
                VALUES (?, ?, ?, ?, ?, ?, 'IN', NOW(), ?, ?)");
            
            if (!$stmt) {
                throw new Exception("Finish Product Prepare failed: " . $conn->error);
            }
            
            $stmt->bind_param(
                "ssssddsi",
                $product,
                $modified_lot_no,
                $coil_no,
                $roll_no,
                $width,
                $length,
                $cut_type,
                $mother_id
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Error inserting to Finish Product: " . $stmt->error);
            }
            
            $stmt->close();
        }
    }
    
    // 2. Handle leftover stock if "Cut Into 2" is selected
    if ($cut_type === 'cut_into_2' && $stock > 0) {
        $mother_stock_lot = $lot_no . 'a';
        
        $check = $conn->query("SELECT id, length FROM stock_raw_material 
                             WHERE lot_no='$mother_stock_lot' AND coil_no='{$coil_no}'");
        
        if ($check->num_rows > 0) {
            // Update existing
            $existing = $check->fetch_assoc();
            $new_length = $existing['length'] + $stock;
            $conn->query("UPDATE stock_raw_material SET length=$new_length, updated_at=NOW() 
                         WHERE lot_no='$mother_stock_lot' AND coil_no='{$coil_no}'");
        } else {
            // Create new stock record
            $default_width = floatval($widths[0] ?? 0);
            $stmt = $conn->prepare("INSERT INTO stock_raw_material 
                (lot_no, coil_no, width, length, status, source_type, source_id, date_in) 
                VALUES (?, ?, ?, ?, 'IN', 'slitting_leftover', ?, NOW())");
            
            if (!$stmt) {
                throw new Exception("Stock Prepare failed: " . $conn->error);
            }
            
            $stmt->bind_param(
                "ssddi",
                $mother_stock_lot,
                $coil_no,
                $default_width,
                $stock,
                $mother_id
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Error creating stock record: " . $stmt->error);
            }
            
            $stmt->close();
        }
    }
    
    // 3. Update mother_coil status if from fresh coil
    if ($source_type === 'mother' && $mother_id > 0) {
        // Update mother coil
        $stmt = $conn->prepare("UPDATE mother_coil SET status='OUT', date_out=NOW() WHERE id=?");
        if (!$stmt) {
            throw new Exception("Mother update prepare failed: " . $conn->error);
        }
        $stmt->bind_param("i", $mother_id);
        if (!$stmt->execute()) {
            throw new Exception("Mother update failed: " . $stmt->error);
        }
        $stmt->close();
        
        // Log to audit - use valid enum: IN, OUT, SCAN_IN, SCAN_OUT, CREATED, UPDATED
        $stmt = $conn->prepare("INSERT INTO mother_coil_audit_log 
            (mother_id, action_type, performed_at, remark) 
            VALUES (?, 'OUT', NOW(), 'Material sent to slitting production')");
        if ($stmt) {
            $stmt->bind_param("i", $mother_id);
            if (!$stmt->execute()) {
                error_log("Audit log failed: " . $stmt->error);
            }
            $stmt->close();
        }
    }
    
    // 4. Update raw_material_log status if from stock
    if ($source_type === 'stock' && $stock_id > 0) {
        $stmt = $conn->prepare("UPDATE raw_material_log SET status='OUT', date_out=NOW() WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("i", $stock_id);
            if (!$stmt->execute()) {
                error_log("Raw material log update failed: " . $stmt->error);
            }
            $stmt->close();
        }
    }
    
    $conn->commit();
    
    // Build success message
    $slitting_count = count($roll_nos);
    $sfc_count = count($send_to_sfc);
    $finish_count = $slitting_count - $sfc_count;
    
    if ($sfc_count > 0 && $finish_count > 0) {
        $message = "Created $sfc_count roll(s) to SFC and $finish_count roll(s) to Finish Product";
    } elseif ($sfc_count > 0) {
        $message = "Created $sfc_count roll(s) and sent to SFC";
    } else {
        $message = "Created $slitting_count roll(s) in Finish Product";
    }
    
    header("Location: finish_product.php?success=1&message=" . urlencode($message));
    exit;
    
} catch (Exception $e) {
    $conn->rollback();
    die("Error: " . htmlspecialchars($e->getMessage()));
}
?>
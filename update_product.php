<?php
session_start();
include 'config.php';

// Authentication Check
if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = intval($_POST['product_id'] ?? 0);
    $roll_no    = trim($_POST['roll_no'] ?? '');
    $width      = trim($_POST['width'] ?? '');
    $length     = trim($_POST['length'] ?? '');

    if ($product_id <= 0) {
        die("Error: Invalid Product ID.");
    }

    // 1. Fetch current values to perform the unique validation AND to
    //    detect whether this update actually changes any QR-affecting
    //    field (lot_no/coil_no aren't editable here, but roll_no/width/
    //    length are — same trigger rule as slitting_product.php's edit
    //    modal: the QR is generated live from the DB on every view/print,
    //    so there's nothing to "regenerate" — what matters is flagging
    //    the roll as needing a reprint via is_printed=0 whenever one of
    //    these changes, so Print Status stops showing a stale "Printed"
    //    badge for data that no longer matches the physical sticker).
    $current_stmt = $conn->prepare("SELECT lot_no, coil_no, roll_no, width, length, actual_length FROM slitting_product WHERE id = ?");
    $current_stmt->bind_param("i", $product_id);
    $current_stmt->execute();
    $current = $current_stmt->get_result()->fetch_assoc();
    $current_stmt->close();

    if (!$current) {
        die("Error: Product not found.");
    }

    $lot_no  = $current['lot_no'];
    $coil_no = $current['coil_no'];

    // 2. Duplicate Validation: Check if this Roll No already exists for this Lot and Coil
    // We exclude the current ID (id != ?) because we are updating this specific record
    $check_stmt = $conn->prepare("SELECT id FROM slitting_product WHERE lot_no = ? AND coil_no = ? AND roll_no = ? AND id != ?");
    $check_stmt->bind_param("sssi", $lot_no, $coil_no, $roll_no, $product_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $check_stmt->close();
        // Redirect back with an error message or use die()
        die("<div style='color:red; font-family:sans-serif; padding:20px; border:1px solid red; background:#fff5f5;'>
                <h2>Validation Error</h2>
                <p><strong>Reason:</strong> The combination of Lot: $lot_no, Coil: $coil_no, and Roll: $roll_no already exists in the system.</p>
                <p>Please use a unique roll number or add an alphabet suffix to the Lot number.</p>
                <button onclick='history.back()'>Go Back and Correct</button>
             </div>");
    }
    $check_stmt->close();

    // 3. Perform Update
    // Note: Adjust column names if your table uses slightly different ones (e.g. date_completed vs updated_at)
    $update_stmt = $conn->prepare("UPDATE slitting_product 
        SET roll_no = ?, width = ?, length = ?, is_completed = 1, stock_counted = 1 
        WHERE id = ?");
    $update_stmt->bind_param("sssi", $roll_no, $width, $length, $product_id);

    if ($update_stmt->execute()) {
        $update_stmt->close();

        // Same trigger fields as slitting_product.php's edit modal:
        // lot_no, coil_no, roll_no, width, length, actual_length.
        // lot_no/coil_no can't change here (not editable on this form),
        // actual_length isn't touched by this form either, so only
        // roll_no/width/length can actually differ — but compared the
        // same way for consistency, in case this form ever grows those
        // fields later.
        $qrFieldsChanged =
               (string)$current['roll_no'] !== (string)$roll_no
            || (float)$current['width']    !== (float)$width
            || (float)$current['length']   !== (float)$length;

        if ($qrFieldsChanged) {
            $resetStmt = $conn->prepare("UPDATE slitting_product SET is_printed = 0 WHERE id = ?");
            $resetStmt->bind_param("i", $product_id);
            $resetStmt->execute();
            $resetStmt->close();
        }

        header("Location: finish_product.php?success=update");
    } else {
        die("Error updating record: " . $conn->error);
    }
    exit;
} else {
    header("Location: finish_product.php");
    exit;
}
?>
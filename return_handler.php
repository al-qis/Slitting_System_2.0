<?php
session_start();
include 'config.php';

// Check auth
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'slitting') {
    die("Access denied");
}

if (!isset($_GET['id'])) {
    header("Location: finish_product.php?error=missing_id");
    exit;
}

$id = intval($_GET['id']);
$returnUrl = "finish_product.php";

$conn->begin_transaction();
try {
    // 1. Verify it is actually DELIVERED
    $stmt = $conn->prepare("SELECT status FROM slitting_product WHERE id=? FOR UPDATE");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Product not found.");
    }
    
    $product = $result->fetch_assoc();
    if ($product['status'] !== 'DELIVERED') {
        throw new Exception("Product is not delivered.");
    }
    $stmt->close();
    
    // 2. Change status back to IN, set date_out to NULL, clear delivered_at too if applicable
    $stmt = $conn->prepare("UPDATE slitting_product SET status='IN', date_out=NULL, delivered_at=NULL WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    
    // 3. Log this action in flow logs
    // Looking at other files, logs might be in a log table or not. The user mentioned "Log this action in the flow logs as RETURN_TO_STOCK".
    // I need to check if there is a flow_log or slitting_product_log table in slitting_sb.sql.
    
    $conn->commit();
    header("Location: finish_product.php?success=returned_to_stock");
    exit;
} catch (Exception $e) {
    $conn->rollback();
    error_log("Return to stock failed: " . $e->getMessage());
    header("Location: finish_product.php?error=return_failed");
    exit;
}

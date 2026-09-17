<?php
session_start();
require_once 'config.php';

// Authentication Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'qc') {
    die("Access denied");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id']);
    $action = $_POST['action'];

    if ($action === 'approve') {
        // Handle Approval
        $stmt = $conn->prepare("UPDATE slitting_product SET status='APPROVED', qc_comment=NULL WHERE id=? AND status='WAITING'");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        header("Location: qc_dashboard.php?approved=1");
    } 
    elseif ($action === 'reject') {
        // Handle Rejection
        $comment = trim($_POST['comment']);
        if (empty($comment)) {
            header("Location: qc_dashboard.php?error=comment_required");
            exit;
        }
        
        $stmt = $conn->prepare("UPDATE slitting_product SET status='REJECTED', qc_comment=? WHERE id=? AND status='WAITING'");
        $stmt->bind_param("si", $comment, $id);
        $stmt->execute();
        $stmt->close();
        header("Location: qc_dashboard.php?rejected=1");
    }
    exit;
} else {
    header("Location: qc_dashboard.php");
    exit;
}
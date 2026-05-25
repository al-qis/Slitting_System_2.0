<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if (!in_array($_SESSION['role'], ['slitting'], true)) {
    header("Location: login.php");
    exit;
}

include 'config.php';

$id = intval($_GET['id'] ?? 0);

if ($id <= 0) {
    header("Location: recoiling.php?error=invalid_id");
    exit;
}

// Get QR code path before deleting
$stmt = $conn->prepare("SELECT qr_code FROM recoiling_product WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    header("Location: recoiling.php?error=not_found");
    exit;
}

$stmt = $conn->prepare("DELETE FROM recoiling_product WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    // Delete QR code file if exists
    if (!empty($row['qr_code']) && file_exists($row['qr_code'])) {
        unlink($row['qr_code']);
    }
    $stmt->close();
    header("Location: recoiling.php?success=deleted");
    exit;
} else {
    $stmt->close();
    header("Location: recoiling.php?error=delete_failed");
    exit;
}
?>
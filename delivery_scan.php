<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if (!in_array($_SESSION['role'], ['slitting', 'qc'], true)) {
    header("Location: login.php");
    exit;
}

include 'config.php';

$slit_id = intval($_GET['id'] ?? 0);

if (!$slit_id) {
    header("Location: finish_product.php?error=invalid_id");
    exit;
}

$stmt = $conn->prepare("
    SELECT * FROM finish_product
    WHERE slit_id = ?
    ORDER BY id DESC
    LIMIT 1
");
$stmt->bind_param("i", $slit_id);
$stmt->execute();
$fin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$fin) {
    header("Location: finish_product.php?error=not_found");
    exit;
}

$stmt = $conn->prepare("
    SELECT * FROM waiting_approval
    WHERE finish_id = ?
    ORDER BY id DESC
    LIMIT 1
");
$stmt->bind_param("i", $fin['id']);
$stmt->execute();
$wait = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($wait && $wait['status'] === 'APPROVED') {
    $del_by = $conn->real_escape_string($_GET['del_by'] ?? $_SESSION['role']);

    $stmt = $conn->prepare("
        UPDATE finish_product
        SET status = 'DELIVERED',
            delivered_by = ?,
            delivered_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("si", $del_by, $fin['id']);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        UPDATE waiting_approval
        SET status = 'DELIVERED'
        WHERE id = ?
    ");
    $stmt->bind_param("i", $wait['id']);
    $stmt->execute();
    $stmt->close();

    header("Location: finish_product.php?success=delivered");
    exit;

} else {
    header("Location: finish_product.php?error=not_approved");
    exit;
}
?>
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

$product_id    = intval($_GET['id']            ?? 0);
$actual_length = $_GET['actual_length']        ?? null;

if ($product_id <= 0) {
    header("Location: finish_product.php?error=invalid_id");
    exit;
}

$stmt = $conn->prepare("SELECT * FROM slitting_product WHERE id = ?");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    header("Location: finish_product.php?error=not_found");
    exit;
}

$stmt = $conn->prepare("
    INSERT INTO recoiling_product
        (product, lot_no, coil_no, roll_no, width, length, actual_length, status, date_in)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
");

$stmt->bind_param(
    "ssssddd",
    $product['product'],
    $product['lot_no'],
    $product['coil_no'],
    $product['roll_no'],
    $product['width'],
    $product['length'],
    $actual_length
);

if ($stmt->execute()) {
    $stmt->close();
    header("Location: recoiling.php?success=added");
    exit;
} else {
    $stmt->close();
    header("Location: finish_product.php?error=recoiling_failed");
    exit;
}
?>
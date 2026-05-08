<?php
include 'config.php';

$slit_id = intval($_GET['id'] ?? 0);
if (!$slit_id) die("Invalid");

$stmt = $conn->prepare("SELECT * FROM finish_product WHERE slit_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $slit_id);
$stmt->execute();
$fin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$fin) die("Finish product not found");

$stmt = $conn->prepare("SELECT * FROM waiting_approval WHERE finish_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $fin['id']);
$stmt->execute();
$wait = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($wait && $wait['status'] == 'APPROVED') {
    $del_by = $conn->real_escape_string($_GET['del_by'] ?? 'deliverer');

    $stmt = $conn->prepare("UPDATE finish_product SET status='DELIVERED', delivered_by=?, delivered_at=NOW() WHERE id=?");
    $stmt->bind_param("si", $del_by, $fin['id']);
    $stmt->execute();
    $stmt->close();

    $stmt2 = $conn->prepare("UPDATE waiting_approval SET status='DELIVERED' WHERE id=?");
    $stmt2->bind_param("i", $wait['id']);
    $stmt2->execute();
    $stmt2->close();

    echo "<div class='alert alert-success'>Product delivered.</div>";
    echo "<a href='slitting_product.php'>Back</a>";
    exit;
} else {
    echo "<div class='alert alert-warning'>Product not approved by QC.</div>";
    echo "<a href='slitting_product.php'>Back</a>";
    exit;
}
?>
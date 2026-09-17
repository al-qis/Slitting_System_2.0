<?php
include 'config.php';
// No need QR code library - QR generated dynamically

$coil_no = $_POST['coil_no'];
$size = $_POST['size'];
$product = $_POST['product'];
$lot_no = $_POST['lot_no'];
$nominal = $_POST['nominal'];
$effective = $_POST['effective'];
$length = $_POST['length'];

// Insert Mother Coil
$stmt = $conn->prepare("INSERT INTO mother_coil (coil_no, size, product, lot_no, nominal, effective, length, status, date_in) VALUES (?, ?, ?, ?, ?, ?, ?, 'IN', NOW())");
$stmt->bind_param("sssssss", $coil_no, $size, $product, $lot_no, $nominal, $effective, $length);
$stmt->execute();
$coil_id = $stmt->insert_id;

// NO NEED TO GENERATE QR CODE FILE!
// QR will be generated dynamically when needed via generate_qr.php

header("Location: mother_coil.php?success=1");
?>
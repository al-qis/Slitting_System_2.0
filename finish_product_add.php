<?php
include 'config.php';

if($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: finish_product.php');
    exit;
}

$coil_no = $conn->real_escape_string($_POST['coil_no']);
$product = $conn->real_escape_string($_POST['product']);
$lot_no  = $conn->real_escape_string($_POST['lot_no']);
$jumlah  = intval($_POST['jumlah']);
if($jumlah < 1) $jumlah = 1;


for($i=0; $i<$jumlah; $i++){
    $stmt = $conn->prepare("
        INSERT INTO finish_product (product, lot_no, coil_no, status, date_created) 
        VALUES (?, ?, ?, 'IN', NOW())
    ");
    $stmt->bind_param("sss", $product, $lot_no, $coil_no);
    $stmt->execute();
    $new_id = $stmt->insert_id;
    $stmt->close();

    // create code
    $code = 'FIN' . str_pad($new_id, 6, '0', STR_PAD_LEFT);
    $stmt2 = $conn->prepare("UPDATE finish_product SET code=? WHERE id=?");
    $stmt2->bind_param("si", $code, $new_id);
    $stmt2->execute();
    $stmt2->close();

}

header("Location: finish_product.php?success=created");
exit;
?>
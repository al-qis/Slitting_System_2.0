<?php
include 'config.php';

$id     = $_GET['id'] ?? null;
$action = $_GET['action'] ?? null;

if ($id && $action) {
    if ($action == "in") {
        // update status IN + rekod tarikh
        $stmt = $conn->prepare("UPDATE mother_coil SET status='IN', date_in=NOW() WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        echo "✅ Mother Coil $id status updated to IN";
    }
    elseif ($action == "out") {
        // update status OUT + rekod tarikh keluar
        $stmt = $conn->prepare("UPDATE mother_coil SET status='OUT', date_out=NOW() WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        // copy ke finish_product table
        $conn->query("INSERT INTO finish_product (mother_id, coil_no, product, date_in, date_created, status) 
                      SELECT id, coil_no, product, date_in, NOW(), 'IN' 
                      FROM mother_coil WHERE id=$id");

        echo "✅ Mother Coil $id status updated to OUT & moved to Finish Product";
    }
} else {
    echo "❌ Invalid request!";
}
?>

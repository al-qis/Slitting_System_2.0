<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

include 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = intval($_POST['product_id'] ?? 0);

    $stmt = $conn->prepare("SELECT * FROM finish_product WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $product = $result->fetch_assoc();
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO reslit_product
            (status, product, lot_no, coil_no, roll_no, width, length, date_in, qr_code)
            VALUES ('pending', ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->bind_param("ssssdds",
            $product['product'],
            $product['lot_no'],
            $product['coil_no'],
            $product['roll_no'],
            $product['width'],
            $product['length'],
            $product['date_in'],
            $product['qr_code']
        );

        if ($stmt->execute()) {
            $stmt->close();
            header("Location: reslit.php?success=added");
        } else {
            $stmt->close();
            header("Location: finish_product.php?error=add_failed");
        }
    } else {
        $stmt->close();
        header("Location: finish_product.php?error=not_found");
    }
}
?>
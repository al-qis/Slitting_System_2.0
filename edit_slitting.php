<?php
include 'config.php';

$id = intval($_GET['id'] ?? 0);

if ($id == 0) {
    die("Invalid product ID");
}

$stmt = $conn->prepare("SELECT * FROM slitting_product WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    die("Product not found. ID: " . intval($id));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $roll_no     = trim($_POST['roll_no']);
    $width       = trim($_POST['width']);
    $length      = trim($_POST['length']);
    $length_type = $_POST['length_type'];

    $stmt = $conn->prepare("UPDATE slitting_product
        SET roll_no=?, width=?, length=?, length_type=?
        WHERE id=?");
    $stmt->bind_param("ssssi", $roll_no, $width, $length, $length_type, $id);
    $stmt->execute();
    $stmt->close();

    header("Location: finish_product.php?success=updated");
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Slitting Product</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
<div class="container">
    <h3 class="mb-4">Edit Slitting Product #<?= intval($id) ?></h3>

    <div class="mb-3 p-3 border rounded bg-light">
        <p class="mb-1"><strong>Product:</strong> <?= htmlspecialchars($product['product']) ?></p>
        <p class="mb-1"><strong>Lot No:</strong> <?= htmlspecialchars($product['lot_no']) ?></p>
        <p class="mb-1"><strong>Coil No:</strong> <?= htmlspecialchars($product['coil_no']) ?></p>
        <p class="mb-0"><strong>Status:</strong>
            <?php
            if ($product['status'] == 'IN' && $product['is_completed'] == 0) {
                echo '<span class="badge bg-info">IN (Pending)</span>';
            } elseif ($product['status'] == 'IN' && $product['stock_counted'] == 1) {
                echo '<span class="badge bg-primary">IN (Stock)</span>';
            } elseif ($product['status'] == 'OUT') {
                echo '<span class="badge bg-danger">OUT</span>';
            } elseif ($product['status'] == 'WAITING') {
                echo '<span class="badge bg-warning">WAITING QC</span>';
            } elseif ($product['status'] == 'DELIVERED') {
                echo '<span class="badge bg-success">DELIVERED</span>';
            } else {
                echo '<span class="badge bg-secondary">' . htmlspecialchars($product['status']) . '</span>';
            }
            ?>
        </p>
    </div>

    <form method="post">
        <div class="mb-3">
            <label class="form-label"><strong>Roll No</strong></label>
            <input type="text" name="roll_no" class="form-control"
                   value="<?= htmlspecialchars($product['roll_no'] ?? '') ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label"><strong>Width (mm)</strong></label>
            <input type="number" step="0.01" name="width" class="form-control"
                   value="<?= htmlspecialchars($product['width'] ?? '') ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label"><strong>Length (meter)</strong></label>
            <input type="number" step="0.01" name="length" class="form-control"
                   value="<?= htmlspecialchars($product['length'] ?? '') ?>">
            <small class="text-muted">Optional - can be filled later</small>
        </div>

        <div class="mb-3">
            <label class="form-label"><strong>Length Type</strong></label>
            <div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="length_type" id="normal" value="normal"
                           <?= ($product['length_type'] ?? '') == 'normal' ? 'checked' : '' ?> required>
                    <label class="form-check-label" for="normal">Normal</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="length_type" id="cut" value="cut"
                           <?= ($product['length_type'] ?? '') == 'cut' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="cut">Cut Coil</label>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-success">Update Product</button>
        <a href="finish_product.php" class="btn btn-danger">Cancel</a>
    </form>
</div>
</body>
</html>
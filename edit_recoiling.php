<?php
include 'config.php';

$id = intval($_GET['id'] ?? 0);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product = trim($_POST['product']);
    $lot_no = trim($_POST['lot_no']);
    $coil_no = trim($_POST['coil_no']);
    $roll_no = trim($_POST['roll_no']);
    $width = floatval($_POST['width']);
    $length = floatval($_POST['length']);
    $actual_length = floatval($_POST['actual_length']);
    
    $stmt = $conn->prepare("UPDATE recoiling_product SET 
        product=?, lot_no=?, coil_no=?, roll_no=?, width=?, length=?, actual_length=? 
        WHERE id=?");
    $stmt->bind_param("ssssdddi", $product, $lot_no, $coil_no, $roll_no, $width, $length, $actual_length, $id);
    
    if ($stmt->execute()) {
        $stmt->close();
        header("Location: recoiling.php?success=updated");
        exit;
    } else {
        $error = "Failed to update record.";
    }
}

// Fetch existing data
$result = $conn->query("SELECT * FROM recoiling_product WHERE id=$id");
if ($result->num_rows === 0) {
    die("Record not found.");
}
$data = $result->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Recoiling Product</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .form-container {
            max-width: 600px;
            margin: 50px auto;
            background: #fff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        h2 {
            color: #212529;
            margin-bottom: 30px;
        }

        /* ── Responsive button row ── */
        .btn-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .btn-row .btn {
            flex: 1 1 120px;   /* grow, shrink, min-width 120px */
            min-width: 120px;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="form-container">
        <h2>Edit Recoiling Product</h2>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>
        
        <form method="post">
            <div class="mb-3">
                <label class="form-label">Product Name</label>
                <input type="text" name="product" class="form-control" 
                       value="<?= htmlspecialchars($data['product']) ?>" required>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Lot No.</label>
                    <input type="text" name="lot_no" class="form-control" 
                           value="<?= htmlspecialchars($data['lot_no']) ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Coil No.</label>
                    <input type="text" name="coil_no" class="form-control" 
                           value="<?= htmlspecialchars($data['coil_no']) ?>" required>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Roll No.</label>
                <input type="text" name="roll_no" class="form-control" 
                       value="<?= htmlspecialchars($data['roll_no']) ?>">
            </div>
            
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Width</label>
                    <input type="number" step="0.01" name="width" class="form-control" 
                           value="<?= $data['width'] ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Length</label>
                    <input type="number" step="0.01" name="length" class="form-control" 
                           value="<?= $data['length'] ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Actual Length</label>
                    <input type="number" step="0.01" name="actual_length" class="form-control" 
                           value="<?= $data['actual_length'] ?>" required>
                </div>
            </div>
            
            <div class="alert alert-info">
                <small><strong>Note:</strong> Status: <?= strtoupper($data['status']) ?></small>
            </div>

            <!-- FIX: was d-flex gap-2; now btn-row for responsive wrapping.
                 FIX: Cancel <i> had class="btn btn-danger" (wrong) → now bi bi-x-circle -->
            <div class="btn-row">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i> Update
                </button>
                <a href="recoiling.php" class="btn btn-secondary">
                    <i class="bi bi-x-circle me-1"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
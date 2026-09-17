<?php
session_start();
include 'config.php';

// 1. Authentication & Role Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'slitting') {
    header("Location: login.php");
    exit;
}

// 2. Fetch Existing Data
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$stmt = $conn->prepare("SELECT * FROM reslit_product WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();

if (!$data) {
    die("Record not found.");
}

// 3. Handle Update Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product = $_POST['product'];
    $lot_no  = $_POST['lot_no'];
    $coil_no = $_POST['coil_no'];
    $roll_no = $_POST['roll_no'];
    $width   = $_POST['width'];
    $length  = $_POST['length'];

    $update_stmt = $conn->prepare("UPDATE reslit_product SET product=?, lot_no=?, coil_no=?, roll_no=?, width=?, length=? WHERE id=?");
    $update_stmt->bind_param("ssssddi", $product, $lot_no, $coil_no, $roll_no, $width, $length, $id);

    if ($update_stmt->execute()) {
        header("Location: reslit.php?success=updated");
        exit;
    } else {
        $error = "Update failed: " . $conn->error;
    }
}

$page_title = "Edit Reslit Record";
include 'header.php';
?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-warning text-dark py-3">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Reslit Information</h5>
                </div>
                <div class="card-body p-4">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?= $error ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Product Name</label>
                            <input type="text" name="product" class="form-control" value="<?= htmlspecialchars($data['product']) ?>" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Lot No</label>
                                <input type="text" name="lot_no" class="form-control" value="<?= htmlspecialchars($data['lot_no']) ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Coil No</label>
                                <input type="text" name="coil_no" class="form-control" value="<?= htmlspecialchars($data['coil_no']) ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Roll No</label>
                            <input type="text" name="roll_no" class="form-control" value="<?= htmlspecialchars($data['roll_no']) ?>" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold text-danger">Width (mm)</label>
                                <input type="number" step="0.01" name="width" class="form-control" value="<?= $data['width'] ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold text-primary">Length (mtr)</label>
                                <input type="number" step="0.01" name="length" class="form-control" value="<?= $data['length'] ?>" required>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between mt-4">
                            <a href="reslit.php" class="btn btn-secondary px-4">Cancel</a>
                            <button type="submit" class="btn btn-success px-4 fw-bold shadow-sm">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
            <p class="text-center text-muted mt-3 small">Note: Editing here only changes the record details. It does not affect physical stock until Reslit is processed.</p>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
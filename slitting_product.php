<?php
session_start();
include 'config.php';

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

// === Handle Update Product ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $coil_no = $_POST['coil_no'];
    $product = $_POST['product'];
    $lot_no  = $_POST['lot_no'];
    $roll_no = $_POST['roll_no'];
    $width   = $_POST['width'];
    $length  = $_POST['length'];

    if ($_POST['action'] === 'update') {
        $id   = intval($_POST['id']);
        $stmt = $conn->prepare("UPDATE slitting_product
            SET coil_no=?, product=?, lot_no=?, roll_no=?, width=?, length=?
            WHERE id=?");
        $stmt->bind_param("ssssssi", $coil_no, $product, $lot_no, $roll_no, $width, $length, $id);
        $stmt->execute();
        $stmt->close();

        header("Location: slitting_product.php?success=update");
        exit;
    }
}

// === Soft-delete (void) Product ===
// Hard delete removed — voiding preserves FK chain and history
if (isset($_GET['delete'])) {
    $id   = intval($_GET['delete']);
    $stmt = $conn->prepare("
        UPDATE slitting_product
        SET is_voided=1, voided_at=NOW(), voided_reason='manual_delete'
        WHERE id=?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header("Location: slitting_product.php?success=delete");
    exit;
}

// === Search Logic ===
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Tokenize search: split on whitespace so users can type e.g.
// "826403a N-4 R7" (Lot + Coil + Roll together) and get an exact
// match across all three, instead of one literal string matched
// against a single column (which would find nothing).
//
//   1 word   -> broad OR across product, coil_no, lot_no, roll_no
//               (existing behavior — unchanged)
//   2 words  -> token1 -> lot_no, token2 -> coil_no
//   3+ words -> token1 -> lot_no, token2 -> coil_no, token3 -> roll_no
//               (tokens beyond the 3rd are ignored)
$tokens = $search !== '' ? preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) : [];

// BASE condition: never show voided, recoiled, or reslitted rows
// These rows have already been sent to recoiling/reslit — they are
// replaced by a new row once that process completes.
$base_where = "
    (is_voided    = 0 OR is_voided    IS NULL)
    AND (is_recoiled  = 0 OR is_recoiled  IS NULL)
    AND (is_reslitted = 0 OR is_reslitted IS NULL)
";

if (count($tokens) === 1) {
    // Single keyword — original broad OR search across all columns
    $stmt = $conn->prepare("
        SELECT * FROM slitting_product
        WHERE ($base_where)
          AND (coil_no LIKE ? OR product LIKE ? OR lot_no LIKE ? OR roll_no LIKE ?)
        ORDER BY id DESC
    ");
    if (!$stmt) { die("Query preparation failed: " . htmlspecialchars($conn->error)); }

    $like = '%' . $tokens[0] . '%';
    $stmt->bind_param("ssss", $like, $like, $like, $like);
    $stmt->execute();
    $slitting = $stmt->get_result();
    $stmt->close();

} elseif (count($tokens) === 2) {
    // Two tokens — Lot No + Coil No
    $stmt = $conn->prepare("
        SELECT * FROM slitting_product
        WHERE ($base_where)
          AND lot_no LIKE ? AND coil_no LIKE ?
        ORDER BY id DESC
    ");
    if (!$stmt) { die("Query preparation failed: " . htmlspecialchars($conn->error)); }

    $likeLot  = '%' . $tokens[0] . '%';
    $likeCoil = '%' . $tokens[1] . '%';
    $stmt->bind_param("ss", $likeLot, $likeCoil);
    $stmt->execute();
    $slitting = $stmt->get_result();
    $stmt->close();

} elseif (count($tokens) >= 3) {
    // Three or more tokens — Lot No + Coil No + Roll No
    // (tokens beyond the 3rd are ignored)
    $stmt = $conn->prepare("
        SELECT * FROM slitting_product
        WHERE ($base_where)
          AND lot_no LIKE ? AND coil_no LIKE ? AND roll_no LIKE ?
        ORDER BY id DESC
    ");
    if (!$stmt) { die("Query preparation failed: " . htmlspecialchars($conn->error)); }

    $likeLot  = '%' . $tokens[0] . '%';
    $likeCoil = '%' . $tokens[1] . '%';
    $likeRoll = '%' . $tokens[2] . '%';
    $stmt->bind_param("sss", $likeLot, $likeCoil, $likeRoll);
    $stmt->execute();
    $slitting = $stmt->get_result();
    $stmt->close();

} else {
    $slitting = $conn->query("
        SELECT * FROM slitting_product
        WHERE $base_where
        ORDER BY id DESC
    ");
}

$success = $_GET['success'] ?? null;

// === Fetch Single Product for Edit ===
$editData = null;
if (isset($_GET['edit'])) {
    $id  = intval($_GET['edit']);
    $res = $conn->query("SELECT * FROM slitting_product WHERE id=$id");
    if ($res->num_rows > 0) $editData = $res->fetch_assoc();
    else die("Product not found!");
}

$page_title = 'Slitting Product';
include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-scissors me-2 text-primary"></i>Slitting Product Inventory</h2>
    <?php if ($success === 'update'): ?>
        <div class="alert alert-success py-2 mb-0 shadow-sm">
            <i class="bi bi-check-circle me-2"></i>Product updated!
        </div>
    <?php elseif ($success === 'delete'): ?>
        <div class="alert alert-success py-2 mb-0 shadow-sm">
            <i class="bi bi-trash me-2"></i>Product removed from inventory.
        </div>
    <?php endif; ?>
</div>

<div class="row mb-3">
    <div class="col-md-5">
        <form method="GET" action="slitting_product.php" class="input-group shadow-sm">
            <input type="text" name="search" class="form-control"
                   placeholder="Search Coil, Product, Lot, Roll... (e.g. 826403a N-4 R7)"
                   value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-search me-1"></i> Search
            </button>
            <?php if ($search !== ''): ?>
                <a href="slitting_product.php" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-dark text-white fw-bold py-3 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-task me-2"></i>Slitting Records</span>
        <?php if ($search !== ''): ?>
            <span class="badge bg-info text-dark">Results for: "<?= htmlspecialchars($search) ?>"</span>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle text-center mb-0">
            <thead class="table-light">
                <tr>
                    <th>Product</th>
                    <th>Coil No</th>
                    <th>Roll No</th>
                    <th>Width (mm)</th>
                    <th>Length (m)</th>
                    <th>QR Code</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($slitting && $slitting->num_rows > 0):
                while ($row = $slitting->fetch_assoc()):
                    $lotCoil       = htmlspecialchars(($row['lot_no'] ?? '') . ' ' . ($row['coil_no'] ?? ''));
                    $formattedRoll = str_replace('R', 'R-', $row['roll_no'] ?? '');
                    $displayLength = (!empty($row['actual_length']) && $row['actual_length'] > 0)
                                     ? $row['actual_length']
                                     : $row['length'];
                    $isActual      = (!empty($row['actual_length']) && $row['actual_length'] > 0);
            ?>
                <tr>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($row['product'] ?? '') ?></span></td>
                    <td><span class="fw-bold"><?= $lotCoil ?></span></td>
                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($formattedRoll) ?></span></td>
                    <td><?= htmlspecialchars($row['width'] ?? '') ?></td>
                    <td>
                        <span class="<?= $isActual ? 'text-primary fw-bold' : '' ?>">
                            <?= htmlspecialchars($displayLength ?? '') ?>
                        </span>
                        <?php if ($isActual): ?>
                            <br><small class="badge bg-info text-dark" style="font-size:0.65rem;">ACTUAL</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <img src="generate_qr.php?id=<?= $row['id'] ?>&type=slitting"
                             width="60" class="img-thumbnail" alt="QR">
                    </td>
                    <td>
                        <div class="btn-group shadow-sm">
                            <a href="?edit=<?= $row['id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                            <a href="select_customer.php?id=<?= $row['id'] ?>"
                               target="_blank" class="btn btn-success btn-sm">Print</a>
                            <a href="?delete=<?= $row['id'] ?>"
                               onclick="return confirm('Remove this product from inventory?')"
                               class="btn btn-danger btn-sm">Delete</a>
                        </div>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr>
                    <td colspan="7" class="py-5 text-muted">
                        No products found<?= $search !== '' ? ' matching "' . htmlspecialchars($search) . '"' : '' ?>.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($editData): ?>
<div class="modal fade show" id="editSlittingModal"
     style="display:block;" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content border-0 shadow" method="post">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $editData['id'] ?>">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-pencil-square me-2"></i>Edit Slitting Product
                </h5>
                <a href="slitting_product.php" class="btn-close"></a>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Coil No</label>
                        <input type="text" name="coil_no" class="form-control"
                               value="<?= htmlspecialchars($editData['coil_no'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Product</label>
                        <input type="text" name="product" class="form-control"
                               value="<?= htmlspecialchars($editData['product'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Lot No</label>
                        <input type="text" name="lot_no" class="form-control"
                               value="<?= htmlspecialchars($editData['lot_no'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Roll No</label>
                        <input type="text" name="roll_no" class="form-control"
                               value="<?= htmlspecialchars($editData['roll_no'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Width</label>
                        <input type="text" name="width" class="form-control"
                               value="<?= htmlspecialchars($editData['width'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Length</label>
                        <input type="text" name="length" class="form-control"
                               value="<?= htmlspecialchars($editData['length'] ?? '') ?>" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="submit" class="btn btn-success px-4">Update Product</button>
                <a href="slitting_product.php" class="btn btn-outline-danger">Cancel</a>
            </div>
        </form>
    </div>
</div>
<div class="modal-backdrop fade show"></div>
<?php endif; ?>

<div class="mt-4">
    <a href="index.php" class="btn btn-secondary shadow-sm">← Back to Dashboard</a>
</div>

<?php include 'footer.php'; ?>
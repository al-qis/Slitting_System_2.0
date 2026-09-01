<?php
session_start();
include 'config.php';

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

// === Handle Update Product ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $coil_no       = $_POST['coil_no'];
    $product       = $_POST['product'];
    $lot_no        = $_POST['lot_no'];
    $roll_no       = $_POST['roll_no'];
    $width         = $_POST['width'];
    $length        = $_POST['length'];
    $actual_length = $_POST['actual_length'] ?? '';

    if ($_POST['action'] === 'update') {
        $id = intval($_POST['id']);

        // Fetch the BEFORE values for the fields that affect the QR code's
        // embedded data — the QR is generated live from the DB on every
        // view/print (generate_qr.php), so there's no stored image to
        // regenerate; what actually needs to happen is flagging the roll
        // as no-longer-printed whenever one of these changes, so the Print
        // Status column correctly signals "this needs reprinting" instead
        // of still showing a stale "Printed" badge for data that no longer
        // matches the physical sticker.
        $beforeStmt = $conn->prepare("
            SELECT lot_no, coil_no, roll_no, width, length, actual_length
            FROM slitting_product WHERE id = ?
        ");
        $beforeStmt->bind_param("i", $id);
        $beforeStmt->execute();
        $before = $beforeStmt->get_result()->fetch_assoc();
        $beforeStmt->close();

        $stmt = $conn->prepare("UPDATE slitting_product
            SET coil_no=?, product=?, lot_no=?, roll_no=?, width=?, length=?, actual_length=?
            WHERE id=?");
        $stmt->bind_param("sssssssi", $coil_no, $product, $lot_no, $roll_no, $width, $length, $actual_length, $id);
        $stmt->execute();
        $stmt->close();

        // Trigger fields for QR regeneration — anything NOT in this list
        // (e.g. product, remark, status) never resets the print flag.
        // Compared as strings/loosely so "450" vs "450.0" etc. don't
        // falsely trigger a reprint flag for a value that didn't really
        // change; only a genuinely different value does.
        if ($before) {
            $qrFieldsChanged =
                   (string)$before['lot_no']        !== (string)$lot_no
                || (string)$before['coil_no']        !== (string)$coil_no
                || (string)$before['roll_no']        !== (string)$roll_no
                || (float)$before['width']           !== (float)$width
                || (float)$before['length']          !== (float)$length
                || (float)($before['actual_length'] ?? 0) !== (float)($actual_length !== '' ? $actual_length : 0);

            if ($qrFieldsChanged) {
                $resetStmt = $conn->prepare("
                    UPDATE slitting_product SET is_printed = 0 WHERE id = ?
                ");
                $resetStmt->bind_param("i", $id);
                $resetStmt->execute();
                $resetStmt->close();
            }
        }

        header("Location: slitting_product.php?success=update");
        exit;
    }
}

// === Soft-delete (void) Product ===
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
$tokens = $search !== '' ? preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) : [];

// Print status filter — combinable with the search above via
// ?print_status=printed|not_printed (anything else/absent = "All").
$printFilter = $_GET['print_status'] ?? '';
if (!in_array($printFilter, ['printed', 'not_printed'], true)) {
    $printFilter = '';
}
$print_where = '';
if ($printFilter === 'printed') {
    $print_where = " AND (is_printed = 1)";
} elseif ($printFilter === 'not_printed') {
    $print_where = " AND (is_printed = 0 OR is_printed IS NULL)";
}

$base_where = "
    (is_voided    = 0 OR is_voided    IS NULL)
    AND (is_recoiled  = 0 OR is_recoiled  IS NULL)
    AND (is_reslitted = 0 OR is_reslitted IS NULL)
";

if (count($tokens) === 1) {
    $stmt = $conn->prepare("
        SELECT * FROM slitting_product
        WHERE ($base_where)
          AND (coil_no LIKE ? OR product LIKE ? OR lot_no LIKE ? OR roll_no LIKE ?)
          $print_where
        ORDER BY id DESC
    ");
    if (!$stmt) { die("Query preparation failed: " . htmlspecialchars($conn->error)); }

    $like = '%' . $tokens[0] . '%';
    $stmt->bind_param("ssss", $like, $like, $like, $like);
    $stmt->execute();
    $slitting = $stmt->get_result();
    $stmt->close();

} elseif (count($tokens) === 2) {
    $stmt = $conn->prepare("
        SELECT * FROM slitting_product
        WHERE ($base_where)
          AND lot_no LIKE ? AND coil_no LIKE ?
          $print_where
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
    $stmt = $conn->prepare("
        SELECT * FROM slitting_product
        WHERE ($base_where)
          AND lot_no LIKE ? AND coil_no LIKE ? AND roll_no LIKE ?
          $print_where
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
        $print_where
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

<style>
    /* Bulk-select checkboxes — default Bootstrap checkboxes are too
       faint on this table; make them bigger and unmistakably visible. */
    .coil-select, #selectAllCoils {
        width: 1.2em;
        height: 1.2em;
        border: 2px solid #000 !important;
        box-shadow: none;
        cursor: pointer;
    }
    .coil-select:checked, #selectAllCoils:checked {
        background-color: #000 !important;
        border-color: #000 !important;
    }
    .coil-select:focus, #selectAllCoils:focus {
        box-shadow: 0 0 0 0.2rem rgba(0,0,0,0.25);
        border-color: #000;
    }
</style>

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

<div class="row mb-3 align-items-center">
    <div class="col-md-7">
        <form method="GET" action="slitting_product.php" class="input-group shadow-sm">
            <input type="text" name="search" class="form-control"
                   placeholder="Search Coil, Product, Lot, Roll... (e.g. 826403a N-4 R7)"
                   value="<?= htmlspecialchars($search) ?>">
            <select name="print_status" class="form-select" style="max-width:160px;" onchange="this.form.submit()">
                <option value="" <?= $printFilter === '' ? 'selected' : '' ?>>All Products</option>
                <option value="printed" <?= $printFilter === 'printed' ? 'selected' : '' ?>>Printed</option>
                <option value="not_printed" <?= $printFilter === 'not_printed' ? 'selected' : '' ?>>Not Printed Yet</option>
            </select>
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-search me-1"></i> Search
            </button>
            <?php if ($search !== '' || $printFilter !== ''): ?>
                <a href="slitting_product.php" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="col-md-5 text-md-end mt-2 mt-md-0">
        <span id="bulkPrintCount" class="text-muted me-2" style="font-size:13px;">0 selected</span>
        <button type="button" id="bulkPrintBtn" class="btn btn-danger text-white fw-bold" disabled onclick="goToMixedBatchSetup()">
            <i class="bi bi-printer-fill me-1"></i> Bulk Print Selected
        </button>
    </div>
</div>

<!-- Hidden form used to POST the selected IDs to mixed_batch_setup.php -->
<form id="mixedBulkPrintForm" method="post" action="mixed_batch_setup.php" style="display:none;">
    <input type="hidden" name="ids"          id="mixedBulkPrintIdsInput">
    <input type="hidden" name="from"         value="slitting_product">
    <input type="hidden" name="search"       value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="print_status" value="<?= htmlspecialchars($printFilter) ?>">
</form>

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
                    <th style="width:36px;">
                        <input type="checkbox" id="selectAllCoils" class="form-check-input"
                               onchange="toggleSelectAllCoils(this)" title="Select all">
                    </th>
                    <th>Product</th>
                    <th>Coil No</th>
                    <th>Roll No</th>
                    <th>Width (mm)</th>
                    <th>Length (m)</th>
                    <th>Print Status</th>
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
                    <td>
                        <input type="checkbox" class="form-check-input coil-select"
                               value="<?= $row['id'] ?>" onchange="updateBulkPrintButton()">
                    </td>
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
                        <?php if (!empty($row['is_printed'])): ?>
                            <span class="badge bg-success" title="Last printed <?= htmlspecialchars($row['last_printed_at'] ? date('d M Y H:i', strtotime($row['last_printed_at'])) : '') ?> by <?= htmlspecialchars($row['last_printed_by'] ?? '') ?>">
                                <i class="bi bi-printer-fill me-1"></i>Printed (<?= (int)($row['print_count'] ?? 0) ?>×)
                            </span>
                            <?php if (!empty($row['last_printed_at'])): ?>
                                <div class="small text-muted"><?= date('d M Y, H:i', strtotime($row['last_printed_at'])) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-secondary">
                                <i class="bi bi-clock me-1"></i>Not Printed Yet
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="btn-group shadow-sm">
                            <a href="?edit=<?= $row['id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                            <a href="select_customer.php?id=<?= $row['id'] ?>&from=slitting_product"
                               class="btn btn-success btn-sm">Print</a>
                            <a href="?delete=<?= $row['id'] ?>"
                               onclick="return confirm('Remove this product from inventory?')"
                               class="btn btn-danger btn-sm">Delete</a>
                        </div>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr>
                    <td colspan="9" class="py-5 text-muted">
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
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Actual Length</label>
                        <input type="text" name="actual_length" class="form-control"
                               value="<?= htmlspecialchars($editData['actual_length'] ?? '') ?>"
                               placeholder="Leave blank if not yet measured">
                        <div class="form-text">Editing this (or Lot/Coil/Roll/Width/Length) flags the roll as needing a reprint.</div>
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

<script>
function toggleSelectAllCoils(cb) {
    document.querySelectorAll('.coil-select').forEach(el => el.checked = cb.checked);
    updateBulkPrintButton();
}

function updateBulkPrintButton() {
    const boxes   = document.querySelectorAll('.coil-select');
    const checked = document.querySelectorAll('.coil-select:checked');

    document.getElementById('bulkPrintCount').textContent = `${checked.length} selected`;
    document.getElementById('bulkPrintBtn').disabled = checked.length === 0;

    // Keep "Select All" in sync: checked if every row is checked,
    // indeterminate (the dash state) if some-but-not-all are checked.
    const selectAll = document.getElementById('selectAllCoils');
    if (selectAll) {
        selectAll.checked       = boxes.length > 0 && checked.length === boxes.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
    }
}

function goToMixedBatchSetup() {
    const checked = document.querySelectorAll('.coil-select:checked');
    if (checked.length === 0) {
        alert('Select at least one product to print.');
        return;
    }
    const ids = Array.from(checked).map(el => el.value);
    document.getElementById('mixedBulkPrintIdsInput').value = JSON.stringify(ids);
    document.getElementById('mixedBulkPrintForm').submit();
}
</script>

<?php include 'footer.php'; ?>
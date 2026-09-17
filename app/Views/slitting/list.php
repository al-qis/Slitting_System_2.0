<?php
$page_title = 'Slitting Product';
include dirname(__DIR__, 3) . '/header.php';
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

<?php include dirname(__DIR__, 3) . '/footer.php'; ?>
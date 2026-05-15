<?php
include 'config.php';

/* ──────────────────────────────────────────────────────────────
   Helper: return ALL products that match a coil_no prefix
   Returns an array (may be empty, 1 item, or multiple items)
────────────────────────────────────────────────────────────── */
function productsFromCoil(mysqli $conn, string $coil_no): array
{
    $coil_no = strtoupper(trim($coil_no));
    if ($coil_no === '') return [];

    preg_match('/^[A-Z0-9]+/', $coil_no, $m);
    $token = $m[0] ?? '';
    if ($token === '') return [];

    for ($len = strlen($token); $len >= 1; $len--) {
        $code = substr($token, 0, $len);

        $stmt = $conn->prepare(
            "SELECT product FROM coil_product_map WHERE coil_code = ? ORDER BY product"
        );
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $res  = $stmt->get_result();
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (count($rows) > 0) {
            return array_column($rows, 'product');   // e.g. ['RS-3825', 'RS-3825-04']
        }
    }
    return [];
}

/* ──────────────────────────────────────────────────────────────
   AJAX endpoint: mother_coil.php?ajax=get_product&coil=...
   Now returns:
     { ok: true/false, products: [...], product: "single or empty" }
────────────────────────────────────────────────────────────── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_product') {
    header('Content-Type: application/json; charset=utf-8');

    $coil     = $_GET['coil'] ?? '';
    $products = productsFromCoil($conn, $coil);

    echo json_encode([
        'ok'       => count($products) > 0,
        'products' => $products,
        'product'  => count($products) === 1 ? $products[0] : '',   // backward-compat
    ]);
    exit;
}

/* ──────────────────────────────────────────────────────────────
   Session / auth guard
────────────────────────────────────────────────────────────── */
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if (!in_array($_SESSION['role'], ['slitting', 'mkl3'], true)) {
    die("Access denied");
}

// ── Alert state from URL ──────────────────────────────────────
$success = $_GET['success'] ?? null;
$error   = $_GET['error']   ?? null;

$error_messages = [
    'not_found'     => '❌ Mother coil not found. It may have already been deleted.',
    'missing_id'    => '❌ No ID was provided.',
    'invalid_id'    => '❌ Invalid ID.',
    'delete_failed' => '❌ Delete failed: ' . htmlspecialchars(urldecode($_GET['msg'] ?? 'Unknown error')),
];

$success_messages = [
    '1'      => '✅ Mother coil saved successfully.',
    '3'      => '✅ Mother coil deleted successfully.',
    'update' => '✅ Mother coil updated successfully.',
];

/* ──────────────────────────────────────────────────────────────
   POST ACTIONS (ADD / UPDATE)
────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';

    $id      = intval($_POST['id'] ?? 0);
    $lot_no  = trim($_POST['lot_no']  ?? '');
    $coil_no = trim($_POST['coil_no'] ?? '');
    $grade   = trim($_POST['grade']   ?? '');
    $width   = trim($_POST['width']   ?? '');
    $length  = trim($_POST['length']  ?? '');
    $product = trim($_POST['product'] ?? '');

    // For ADD: if product not posted (shouldn't happen), fall back to lookup
    if ($action === 'add' && $product === '') {
        $found = productsFromCoil($conn, $coil_no);
        $product = count($found) === 1 ? $found[0] : '';
    }

    if ($lot_no === '' || $coil_no === '' || $grade === '' || $width === '' || $length === '' || $product === '') {
        die("Required field missing (including product).");
    }

    // Duplicate check
    $check_stmt = $conn->prepare(
        "SELECT id FROM mother_coil WHERE coil_no = ? AND lot_no = ? AND id != ?"
    );
    $check_stmt->bind_param("ssi", $coil_no, $lot_no, $id);
    $check_stmt->execute();
    $check_res = $check_stmt->get_result();

    if ($check_res->num_rows > 0) {
        die("<div style='color:red;font-family:sans-serif;padding:20px;border:1px solid red;background:#fff5f5;'>
                <h2>Registration Failed</h2>
                <p><strong>Duplicate Error:</strong> The combination of Coil: <b>$coil_no</b> and Lot: <b>$lot_no</b> already exists.</p>
                <p>Please check your data or add an alphabet suffix (e.g., {$lot_no}a) if this is a repeat entry.</p>
                <button onclick='history.back()'>Go Back and Fix</button>
             </div>");
    }
    $check_stmt->close();

    if ($action === 'add') {
        $stmt = $conn->prepare("
            INSERT INTO mother_coil (product, grade, lot_no, coil_no, width, length, date_created, status)
            VALUES (?,?,?,?,?,?,NOW(),'NEW')
        ");
        if (!$stmt) die("Prepare failed: " . $conn->error);
        $stmt->bind_param("ssssss", $product, $grade, $lot_no, $coil_no, $width, $length);
        $stmt->execute();
        $stmt->close();
        header("Location: mother_coil.php?success=1");
        exit;
    }

    if ($action === 'update' && $id > 0) {
        $stmt = $conn->prepare("
            UPDATE mother_coil
            SET product=?, grade=?, lot_no=?, coil_no=?, width=?, length=?
            WHERE id=?
        ");
        if (!$stmt) die("Prepare failed: " . $conn->error);
        $stmt->bind_param("ssssssi", $product, $grade, $lot_no, $coil_no, $width, $length, $id);
        $stmt->execute();
        $stmt->close();
        header("Location: mother_coil.php?success=update");
        exit;
    }
}

/* ──────────────────────────────────────────────────────────────
   SEARCH / LIST
────────────────────────────────────────────────────────────── */
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $searchTerm = "%$search%";
    $stmt = $conn->prepare("SELECT * FROM mother_coil
                            WHERE coil_no LIKE ? OR lot_no LIKE ? OR product LIKE ?
                            ORDER BY id ASC");
    $stmt->bind_param("sss", $searchTerm, $searchTerm, $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query("SELECT * FROM mother_coil ORDER BY id ASC");
}

// Load all distinct products for the edit modal dropdown
$all_products = [];
$prod_res = $conn->query("SELECT DISTINCT product_code FROM std_wgt ORDER BY product_code");
while ($pr = $prod_res->fetch_assoc()) $all_products[] = $pr['product_code'];

$page_title = 'Mother Coil';
include 'header.php';
?>

<style>
/* ── Product select state styles ── */
#add_product_display.state-auto     { background:#F0FDF4; color:#166534; border-color:#86efac; }
#add_product_display.state-empty    { background:#FFF; color:inherit; }
#add_product_select_wrap            { display:none; }
#add_product_select_wrap.visible    { display:block; }
.product-choice-badge {
    display:inline-flex; align-items:center; gap:6px;
    font-size:11px; font-weight:600; padding:3px 10px;
    border-radius:20px; background:#EFF6FF; color:#1D4ED8;
    margin-bottom:6px;
}
</style>

<h2 class="mb-3"><i class="bi bi-layer-forward me-2"></i>Mother Coil List</h2>

<?php if ($error && isset($error_messages[$error])): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-3">
        <?= $error_messages[$error] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success && isset($success_messages[$success])): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm mb-3">
        <?= $success_messages[$success] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row mb-3">
    <div class="col-md-6">
        <?php if (in_array($_SESSION['role'], ['mkl3', 'slitting'], true)): ?>
            <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addMotherModal">
                <i class="bi bi-plus"></i> Add Mother Coil
            </button>
        <?php endif; ?>
    </div>
    <div class="col-md-6">
        <form method="GET" action="mother_coil.php" class="input-group input-group-sm">
            <input type="text" name="search" class="form-control"
                   placeholder="Search Coil No, Lot, or Product..."
                   value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Search</button>
            <?php if ($search !== ''): ?>
                <a href="mother_coil.php" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<table class="table table-bordered table-striped align-middle text-center">
    <thead class="table-dark">
        <tr>
            <th>ID</th><th>Product</th><th>Lot No.</th><th>Coil No.</th>
            <th>Grade</th><th>Width</th><th>Length (mtr)</th>
            <th>Date Created</th><th>QR Code</th><th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($result && $result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): $id = (int)$row['id']; ?>
                <tr>
                    <td><?= $id ?></td>
                    <td><?= htmlspecialchars($row['product'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['lot_no']  ?? '') ?></td>
                    <td><?= htmlspecialchars($row['coil_no'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['grade']   ?? '') ?></td>
                    <td><?= htmlspecialchars($row['width']   ?? '') ?></td>
                    <td><?= htmlspecialchars($row['length']  ?? '') ?></td>
                    <td><?= htmlspecialchars($row['date_created'] ?? '') ?></td>
                    <td>
                        <img src="generate_qr.php?product=<?= urlencode($row['product'] ?? '') ?>&lot=<?= urlencode($row['lot_no'] ?? '') ?>&coil=<?= urlencode($row['coil_no'] ?? '') ?>&width=<?= urlencode($row['width'] ?? '') ?>&length=<?= urlencode($row['length'] ?? '') ?>&type=mother"
                             width="70" alt="QR">
                    </td>
                    <td>
                    <?php if (in_array($_SESSION['role'], ['mkl3', 'slitting'], true)): ?>
                        <button type="button" class="btn btn-warning btn-sm me-1 editBtn"
                                data-id="<?= $id ?>"
                                data-product="<?= htmlspecialchars($row['product'] ?? '') ?>"
                                data-lot_no="<?= htmlspecialchars($row['lot_no']   ?? '') ?>"
                                data-coil_no="<?= htmlspecialchars($row['coil_no'] ?? '') ?>"
                                data-grade="<?= htmlspecialchars($row['grade']     ?? '') ?>"
                                data-width="<?= htmlspecialchars($row['width']     ?? '') ?>"
                                data-length="<?= htmlspecialchars($row['length']   ?? '') ?>"
                                data-bs-toggle="modal" data-bs-target="#editMotherModal">
                            Edit
                        </button>
                        <a href="delete_mother.php?id=<?= $id ?>" class="btn btn-danger btn-sm"
                           onclick="return confirm('⚠️ DELETE MOTHER COIL?\n\nThis will permanently delete all linked records.\n\nThis CANNOT be undone. Continue?')">
                            Delete
                        </a>
                        <a href="print_mother.php?id=<?= $id ?>" class="btn btn-info btn-sm" target="_blank">Print</a>
                        <a href="mother_coil_journal.php?id=<?= $id ?>" class="btn btn-info btn-sm">
                            <i class="bi bi-journal-text"></i> Journal
                        </a>
                    <?php else: ?>
                        <span class="text-muted">View only</span>
                    <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="10" class="text-center py-4 text-muted">No records found.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- ══════════════════════════════════════════
     ADD MODAL
══════════════════════════════════════════ -->
<div class="modal fade" id="addMotherModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="mother_coil.php" id="addMotherForm">
        <input type="hidden" name="action" value="add">

        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Mother Coil</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">

          <!-- Lot No (first — unlocks Coil No) -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Lot No <span class="text-danger">*</span></label>
            <input type="text" name="lot_no" id="add_lot_no" class="form-control" required
                   maxlength="8" pattern="^[a-zA-Z0-9]{4,8}$"
                   title="4–8 alphanumeric characters"
                   placeholder="e.g. 5001">
            <div class="form-text">4–8 characters, letters and numbers only.</div>
          </div>

          <!-- Coil No (unlocks after Lot No) -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Coil No <span class="text-danger">*</span></label>
            <input type="text" name="coil_no" id="add_coil_no" class="form-control" required
                   disabled placeholder="Enter coil number">
            <div class="form-text" id="add_coil_hint">Enter coil number then click elsewhere to look up product.</div>
          </div>

          <!-- Product display (auto-filled or chosen) -->
          <div class="mb-3" id="add_product_wrap">
            <label class="form-label fw-semibold">Product <span class="text-danger">*</span></label>

            <!-- CASE 1: single match → readonly display, hidden input carries value -->
            <div id="add_product_auto_wrap">
              <input type="text" id="add_product_display" class="form-control state-empty"
                     readonly placeholder="Will auto-fill after Coil No is entered">
            </div>

            <!-- CASE 2: multiple matches → dropdown -->
            <div id="add_product_select_wrap">
              <div class="product-choice-badge">
                <i class="bi bi-info-circle"></i>
                Multiple products found — please select one
              </div>
              <select name="product" id="add_product_select" class="form-select" required>
                <option value="">-- Select Product --</option>
              </select>
            </div>

            <!-- Hidden input used when auto-fill (single match) -->
            <input type="hidden" name="product" id="add_product_hidden">
          </div>

          <!-- Grade (unlocks after product resolved) -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Grade <span class="text-danger">*</span></label>
            <input type="text" name="grade" id="add_grade" class="form-control" required
                   disabled placeholder="e.g. SS400">
          </div>

          <!-- Width -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Width (mm) <span class="text-danger">*</span></label>
            <input type="number" step="0.01" name="width" id="add_width" class="form-control" required
                   disabled placeholder="e.g. 1250">
          </div>

          <!-- Length -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Length (mtr) <span class="text-danger">*</span></label>
            <input type="number" step="0.01" name="length" id="add_length" class="form-control" required
                   disabled placeholder="e.g. 500">
          </div>

        </div><!-- /modal-body -->

        <div class="modal-footer">
          <button type="submit" class="btn btn-success" id="add_submit_btn" disabled>
            <i class="bi bi-save me-1"></i> Save
          </button>
          <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════
     EDIT MODAL
══════════════════════════════════════════ -->
<div class="modal fade" id="editMotherModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="mother_coil.php">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" id="edit_id">

        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Mother Coil</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Product</label>
            <select name="product" id="edit_product" class="form-select" required>
              <option value="">-- Select Product --</option>
              <?php foreach ($all_products as $p): ?>
                <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Lot No</label>
            <input type="text" name="lot_no" id="edit_lot_no" class="form-control" required
                   maxlength="8" pattern="^[a-zA-Z0-9]{4,8}$">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Coil No</label>
            <input type="text" name="coil_no" id="edit_coil_no" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Grade</label>
            <input type="text" name="grade" id="edit_grade" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Width (mm)</label>
            <input type="number" step="0.01" name="width" id="edit_width" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Length (mtr)</label>
            <input type="number" step="0.01" name="length" id="edit_length" class="form-control" required>
          </div>
        </div>

        <div class="modal-footer">
          <button type="submit" class="btn btn-success">
            <i class="bi bi-save me-1"></i> Update
          </button>
          <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
/* ════════════════════════════════════════════════════════════
   ADD MODAL — Product lookup logic
════════════════════════════════════════════════════════════ */

const elLot     = document.getElementById('add_lot_no');
const elCoil    = document.getElementById('add_coil_no');
const elGrade   = document.getElementById('add_grade');
const elWidth   = document.getElementById('add_width');
const elLength  = document.getElementById('add_length');
const elSubmit  = document.getElementById('add_submit_btn');

const elAutoWrap   = document.getElementById('add_product_auto_wrap');
const elSelectWrap = document.getElementById('add_product_select_wrap');
const elDisplay    = document.getElementById('add_product_display');
const elSelect     = document.getElementById('add_product_select');
const elHidden     = document.getElementById('add_product_hidden');
const elCoilHint   = document.getElementById('add_coil_hint');

/* Reset the whole product area */
function resetProduct() {
    elDisplay.value = '';
    elDisplay.className = 'form-control state-empty';
    elHidden.value  = '';

    elAutoWrap.style.display   = 'block';
    elSelectWrap.classList.remove('visible');

    // Disable select so it doesn't send an empty value
    elSelect.required = false;
    elSelect.innerHTML = '<option value="">-- Select Product --</option>';

    // Also re-enable hidden (single-match path)
    elHidden.disabled = false;

    lockDownstream(true);
}

/* Lock / unlock Grade + Width + Length + Submit */
function lockDownstream(lock) {
    [elGrade, elWidth, elLength].forEach(el => {
        el.disabled = lock;
        if (lock) el.value = '';
    });
    elSubmit.disabled = lock;
}

/* Apply a resolved product value */
function applyProduct(value) {
    elHidden.value = value;
    lockDownstream(false);
    elGrade.focus();
}

/* ── Step 1: Lot No → enable Coil No ── */
function validateLotNo(input) {
    const regex = /^[a-zA-Z0-9]{4,8}$/;
    if (input.value !== '' && !regex.test(input.value)) {
        input.setCustomValidity('Lot No must be 4–8 alphanumeric characters.');
    } else {
        input.setCustomValidity('');
    }
}

elLot.addEventListener('input', function () {
    validateLotNo(this);
    const ok = this.value.trim() !== '';
    elCoil.disabled = !ok;
    if (!ok) { elCoil.value = ''; resetProduct(); }
});

/* ── Step 2: Coil No blur → lookup ── */
elCoil.addEventListener('blur', async function () {
    const coilVal = this.value.trim();
    resetProduct();

    if (!coilVal) return;

    elCoilHint.textContent = '🔍 Looking up product…';

    try {
        const res  = await fetch('mother_coil.php?ajax=get_product&coil=' + encodeURIComponent(coilVal));
        const data = await res.json();

        if (!data.ok || data.products.length === 0) {
            elCoilHint.textContent = '⚠️ Coil code not found in mapping table.';
            elDisplay.value = '';
            elDisplay.className = 'form-control';
            elDisplay.style.borderColor = '#dc3545';
            return;
        }

        elCoilHint.textContent = '';
        elDisplay.style.borderColor = '';

        if (data.products.length === 1) {
            /* ── Single match: auto-fill ── */
            elDisplay.value    = data.products[0];
            elDisplay.className = 'form-control state-auto';
            elHidden.value     = data.products[0];
            elHidden.disabled  = false;

            // Hide select, show auto display
            elAutoWrap.style.display = 'block';
            elSelectWrap.classList.remove('visible');
            elSelect.required = false;

            applyProduct(data.products[0]);

        } else {
            /* ── Multiple matches: show dropdown ── */
            elAutoWrap.style.display = 'none';
            elSelectWrap.classList.add('visible');

            // Disable hidden input (select will post instead)
            elHidden.disabled = true;
            elHidden.value    = '';

            // Populate select
            elSelect.innerHTML = '<option value="">-- Select Product --</option>';
            data.products.forEach(p => {
                const opt = document.createElement('option');
                opt.value       = p;
                opt.textContent = p;
                elSelect.appendChild(opt);
            });
            elSelect.required = true;
            elSelect.focus();
        }

    } catch (e) {
        elCoilHint.textContent = '⚠️ Network error during lookup.';
        console.error(e);
    }
});

/* ── Step 3: When dropdown changes → unlock downstream ── */
elSelect.addEventListener('change', function () {
    if (this.value) {
        applyProduct(this.value);
    } else {
        lockDownstream(true);
    }
});

/* ── Reset modal on open ── */
document.getElementById('addMotherModal').addEventListener('show.bs.modal', function () {
    document.getElementById('addMotherForm').reset();
    resetProduct();
    elCoil.disabled  = true;
    elGrade.disabled = true;
    elWidth.disabled = true;
    elLength.disabled= true;
    elSubmit.disabled= true;
    elCoilHint.textContent = 'Enter coil number then click elsewhere to look up product.';
    elDisplay.style.borderColor = '';
});

document.getElementById('addMotherModal').addEventListener('shown.bs.modal', function () {
    elLot.focus();
});

/* ════════════════════════════════════════════════════════════
   EDIT MODAL — populate fields
════════════════════════════════════════════════════════════ */
document.querySelectorAll('.editBtn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('edit_id').value      = btn.dataset.id;
        document.getElementById('edit_lot_no').value  = btn.dataset.lot_no  || '';
        document.getElementById('edit_coil_no').value = btn.dataset.coil_no || '';
        document.getElementById('edit_grade').value   = btn.dataset.grade   || '';
        document.getElementById('edit_width').value   = btn.dataset.width   || '';
        document.getElementById('edit_length').value  = btn.dataset.length  || '';

        // Set selected product in dropdown
        const sel = document.getElementById('edit_product');
        sel.value = btn.dataset.product || '';
        // If not in list (legacy product), add it
        if (sel.value === '' && btn.dataset.product) {
            const opt = document.createElement('option');
            opt.value = opt.textContent = btn.dataset.product;
            sel.appendChild(opt);
            sel.value = btn.dataset.product;
        }
    });
});

/* ── Edit Lot No validation ── */
document.getElementById('edit_lot_no').addEventListener('input', function () {
    validateLotNo(this);
});
</script>

<?php if ($_SESSION['role'] === 'slitting'): ?>
    <div><a href="index.php" class="btn btn-secondary mt-3">← Back</a></div>
<?php else: ?>
    <div><a href="logout.php" class="btn btn-secondary mt-3">Logout</a></div>
<?php endif; ?>

<?php include 'footer.php'; ?>
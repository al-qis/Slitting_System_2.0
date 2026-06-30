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

    /* ─────────────────────────────────────────────
       BULK ADD (Excel multi-row paste)
       Accepts rows_json = JSON array of
       { lot_no, coil_no, grade, width, length, product }
       Inserts each valid, non-duplicate row; reports
       per-row results as JSON instead of redirecting.
    ───────────────────────────────────────────── */
    if ($action === 'bulk_add') {
        header('Content-Type: application/json; charset=utf-8');

        $rows = json_decode($_POST['rows_json'] ?? '[]', true);
        if (!is_array($rows)) $rows = [];

        $inserted = 0;
        $skipped  = [];

        foreach ($rows as $i => $r) {
            $r_lot     = trim($r['lot_no']  ?? '');
            $r_coil    = trim($r['coil_no'] ?? '');
            $r_grade   = trim($r['grade']   ?? '');
            $r_width   = trim($r['width']   ?? '');
            $r_length  = trim($r['length']  ?? '');
            $r_product = trim($r['product'] ?? '');

            if ($r_lot === '' || $r_coil === '' || $r_grade === '' || $r_width === '' || $r_length === '' || $r_product === '') {
                $skipped[] = ['row' => $i + 1, 'reason' => 'Missing required field'];
                continue;
            }

            $dup_stmt = $conn->prepare("SELECT id FROM mother_coil WHERE coil_no = ? AND lot_no = ?");
            $dup_stmt->bind_param("ss", $r_coil, $r_lot);
            $dup_stmt->execute();
            $dup_res = $dup_stmt->get_result();
            if ($dup_res->num_rows > 0) {
                $skipped[] = ['row' => $i + 1, 'reason' => "Duplicate Lot $r_lot / Coil $r_coil"];
                $dup_stmt->close();
                continue;
            }
            $dup_stmt->close();

            $stmt = $conn->prepare("
                INSERT INTO mother_coil (product, grade, lot_no, coil_no, width, length, date_created, status)
                VALUES (?,?,?,?,?,?,NOW(),'NEW')
            ");
            $stmt->bind_param("ssssss", $r_product, $r_grade, $r_lot, $r_coil, $r_width, $r_length);
            $stmt->execute();
            $stmt->close();
            $inserted++;
        }

        echo json_encode(['ok' => true, 'inserted' => $inserted, 'skipped' => $skipped]);
        exit;
    }

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

   Tokenize search: split on whitespace so users can type e.g.
   "826403a N-4 DS-3020" (Lot + Coil + Product together) and get
   an exact match across all three, instead of one literal string
   matched against a single column (which would find nothing).

     1 word   -> broad OR across coil_no, lot_no, product
                 (existing behavior — unchanged)
     2 words  -> token1 -> lot_no, token2 -> coil_no
     3+ words -> token1 -> lot_no, token2 -> coil_no, token3 -> product
                 (tokens beyond the 3rd are ignored)
────────────────────────────────────────────────────────────── */
$search = trim($_GET['search'] ?? '');
$tokens = $search !== '' ? preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) : [];

if (count($tokens) === 1) {
    // Single keyword — original broad OR search across all columns
    $searchTerm = '%' . $tokens[0] . '%';
    $stmt = $conn->prepare("SELECT * FROM mother_coil
                            WHERE coil_no LIKE ? OR lot_no LIKE ? OR product LIKE ?
                            ORDER BY id ASC");
    $stmt->bind_param("sss", $searchTerm, $searchTerm, $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();

} elseif (count($tokens) === 2) {
    // Two tokens — Lot No + Coil No
    $likeLot  = '%' . $tokens[0] . '%';
    $likeCoil = '%' . $tokens[1] . '%';
    $stmt = $conn->prepare("SELECT * FROM mother_coil
                            WHERE lot_no LIKE ? AND coil_no LIKE ?
                            ORDER BY id ASC");
    $stmt->bind_param("ss", $likeLot, $likeCoil);
    $stmt->execute();
    $result = $stmt->get_result();

} elseif (count($tokens) >= 3) {
    // Three or more tokens — Lot No + Coil No + Product
    // (tokens beyond the 3rd are ignored)
    $likeLot     = '%' . $tokens[0] . '%';
    $likeCoil    = '%' . $tokens[1] . '%';
    $likeProduct = '%' . $tokens[2] . '%';
    $stmt = $conn->prepare("SELECT * FROM mother_coil
                            WHERE lot_no LIKE ? AND coil_no LIKE ? AND product LIKE ?
                            ORDER BY id ASC");
    $stmt->bind_param("sss", $likeLot, $likeCoil, $likeProduct);
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
            <button class="btn btn-outline-success btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#bulkAddMotherModal">
                <i class="bi bi-table"></i> Bulk Add from Excel
            </button>
        <?php endif; ?>
    </div>
    <div class="col-md-6">
        <form method="GET" action="mother_coil.php" class="input-group input-group-sm">
            <input type="text" name="search" class="form-control"
                   placeholder="Search Coil No, Lot, or Product... (e.g. 826403a N-4 DS-3020)"
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

          <!-- Excel paste shortcut (add-on; manual fields below remain fully usable) -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Shortcut: Paste Excel Row Here to Auto-Fill</label>
            <textarea id="add_paste_zone" class="form-control" rows="2"
                      placeholder="Copy a row from Excel (without headers) and paste it here…"></textarea>
            <div class="form-text">Tab-separated row from Excel — auto-fills Lot No, Coil No, Grade, Width and Length below.</div>
            <div class="alert alert-warning mt-2 mb-0 d-none" id="add_paste_warning">
              Format mismatch. Please ensure you copied a valid row from the spreadsheet.
            </div>
          </div>

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

<!-- ══════════════════════════════════════════
     BULK ADD MODAL (multi-row Excel paste)
══════════════════════════════════════════ -->
<div class="modal fade" id="bulkAddMotherModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-table me-2"></i>Bulk Add Mother Coils from Excel</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">Paste Multiple Excel Rows Here (one coil per line, no headers)</label>
          <textarea id="bulk_paste_zone" class="form-control" rows="6"
                    placeholder="Paste several rows copied from Excel — each line becomes one mother coil."></textarea>
          <div class="form-text">Each line must be tab-separated, same column layout as the single-row paste shortcut.</div>
        </div>

        <div class="d-flex gap-2 mb-3">
          <button type="button" class="btn btn-primary btn-sm" id="bulk_parse_btn">
            <i class="bi bi-list-check"></i> Parse Rows
          </button>
          <button type="button" class="btn btn-secondary btn-sm" id="bulk_clear_btn">Clear</button>
        </div>

        <div class="alert alert-warning d-none" id="bulk_parse_warning"></div>
        <div class="alert alert-success d-none" id="bulk_result_msg"></div>

        <div class="table-responsive">
          <table class="table table-sm table-bordered align-middle" id="bulk_preview_table">
            <thead class="table-light">
              <tr>
                <th>#</th><th>Lot No</th><th>Coil No</th><th>Product</th>
                <th>Grade</th><th>Width (mm)</th><th>Length (mtr)</th><th></th>
              </tr>
            </thead>
            <tbody id="bulk_preview_tbody"></tbody>
          </table>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-success" id="bulk_save_btn" disabled>
          <i class="bi bi-save me-1"></i> Save All
        </button>
        <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Close</button>
      </div>
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

const elPaste        = document.getElementById('add_paste_zone');
const elPasteWarning = document.getElementById('add_paste_warning');

function showPasteWarning(show) {
    elPasteWarning.classList.toggle('d-none', !show);
}

/* ════════════════════════════════════════════════════════════
   Excel paste shortcut — parses a tab-separated Excel row and
   auto-fills Lot No, Coil No, Grade, Width and Length.
   Manual editing of every field remains available afterwards.
════════════════════════════════════════════════════════════ */
elPaste.addEventListener('input', function () {
    handlePasteAutofill(this.value);
});

async function handlePasteAutofill(raw) {
    const text = raw.trim();
    if (!text) { showPasteWarning(false); return; }

    const cols = text.split('\t');
    if (cols.length < 6) { showPasteWarning(true); return; }

    const widthVal    = (cols[2] || '').trim();
    const lotCoilCell  = (cols[3] || '').trim();
    const lengthVal   = (cols[4] || '').trim();
    const gradeVal    = (cols[5] || '').trim();

    const lotCoilParts = lotCoilCell.split(' ').filter(Boolean);
    const widthNum  = parseFloat(widthVal);
    const lengthNum = parseFloat(lengthVal);

    if (lotCoilParts.length < 2 || isNaN(widthNum) || isNaN(lengthNum) || !gradeVal) {
        showPasteWarning(true);
        return;
    }

    showPasteWarning(false);

    const lotVal  = lotCoilParts[0];
    const coilVal = lotCoilParts.slice(1).join(' ');

    /* Fill Lot No (re-validate + unlock Coil No) */
    elLot.value = lotVal;
    elLot.dispatchEvent(new Event('input'));

    /* Fill Coil No */
    elCoil.disabled = false;
    elCoil.value = coilVal;

    /* Instantly fill + unlock Grade, Width, Length — still editable by hand */
    elGrade.disabled  = false; elGrade.value  = gradeVal;
    elWidth.disabled  = false; elWidth.value  = widthVal;
    elLength.disabled = false; elLength.value = lengthVal;
    elSubmit.disabled = false;

    /* Best-effort background product lookup; pasted values are preserved either way */
    try {
        elCoilHint.textContent = '🔍 Looking up product…';
        const res  = await fetch('mother_coil.php?ajax=get_product&coil=' + encodeURIComponent(coilVal));
        const data = await res.json();

        if (data.ok && data.products.length === 1) {
            elDisplay.value      = data.products[0];
            elDisplay.className  = 'form-control state-auto';
            elHidden.value       = data.products[0];
            elHidden.disabled    = false;
            elAutoWrap.style.display = 'block';
            elSelectWrap.classList.remove('visible');
            elSelect.required = false;
            elCoilHint.textContent = '';
        } else if (data.ok && data.products.length > 1) {
            elAutoWrap.style.display = 'none';
            elSelectWrap.classList.add('visible');
            elHidden.disabled = true;
            elHidden.value    = '';
            elSelect.innerHTML = '<option value="">-- Select Product --</option>';
            data.products.forEach(p => {
                const opt = document.createElement('option');
                opt.value = opt.textContent = p;
                elSelect.appendChild(opt);
            });
            elSelect.required = true;
            elCoilHint.textContent = '';
        } else {
            elCoilHint.textContent = '⚠️ Coil code not found in mapping table. Please select product manually if required.';
        }
    } catch (err) {
        elCoilHint.textContent = '⚠️ Network error during lookup.';
    }

    /* Re-assert pasted values in case the lookup branches above touched shared state */
    elGrade.disabled  = false; elGrade.value  = gradeVal;
    elWidth.disabled  = false; elWidth.value  = widthVal;
    elLength.disabled = false; elLength.value = lengthVal;
    elSubmit.disabled = false;
}

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
    elPaste.value = '';
    showPasteWarning(false);
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

/* ════════════════════════════════════════════════════════════
   BULK ADD MODAL — paste many Excel rows, preview, edit, save all
════════════════════════════════════════════════════════════ */
const bulkPaste     = document.getElementById('bulk_paste_zone');
const bulkParseBtn  = document.getElementById('bulk_parse_btn');
const bulkClearBtn  = document.getElementById('bulk_clear_btn');
const bulkWarning   = document.getElementById('bulk_parse_warning');
const bulkResultMsg = document.getElementById('bulk_result_msg');
const bulkTbody     = document.getElementById('bulk_preview_tbody');
const bulkSaveBtn   = document.getElementById('bulk_save_btn');

let bulkRows = []; // { lot_no, coil_no, grade, width, length, product, productOptions, productStatus }

function showBulkWarning(msg) {
    if (!msg) {
        bulkWarning.classList.add('d-none');
        bulkWarning.textContent = '';
        return;
    }
    bulkWarning.textContent = msg;
    bulkWarning.classList.remove('d-none');
}

function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

bulkClearBtn.addEventListener('click', () => {
    bulkPaste.value = '';
    bulkRows = [];
    renderBulkTable();
    showBulkWarning('');
    bulkResultMsg.classList.add('d-none');
});

bulkParseBtn.addEventListener('click', () => {
    parseBulkRows(bulkPaste.value);
});

function parseBulkRows(raw) {
    const lines = raw.split(/\r?\n/).map(l => l.trim()).filter(l => l !== '');
    if (lines.length === 0) {
        showBulkWarning('Please paste at least one row before parsing.');
        return;
    }

    const parsed   = [];
    const badLines = [];

    lines.forEach((line, idx) => {
        const cols = line.split('\t');
        if (cols.length < 6) { badLines.push(idx + 1); return; }

        const widthVal     = (cols[2] || '').trim();
        const lotCoilCell  = (cols[3] || '').trim();
        const lengthVal    = (cols[4] || '').trim();
        const gradeVal     = (cols[5] || '').trim();

        const lotCoilParts = lotCoilCell.split(' ').filter(Boolean);

        if (lotCoilParts.length < 2 || isNaN(parseFloat(widthVal)) || isNaN(parseFloat(lengthVal)) || !gradeVal) {
            badLines.push(idx + 1);
            return;
        }

        parsed.push({
            lot_no:  lotCoilParts[0],
            coil_no: lotCoilParts.slice(1).join(' '),
            grade:   gradeVal,
            width:   widthVal,
            length:  lengthVal,
            product: '',
            productOptions: [],
            productStatus: 'pending'   // pending | single | multiple | none
        });
    });

    if (badLines.length > 0) {
        showBulkWarning(
            `Format mismatch on line(s) ${badLines.join(', ')}. Please ensure every row was copied correctly from the spreadsheet. Valid rows were still parsed below.`
        );
    } else {
        showBulkWarning('');
    }

    bulkRows = parsed;
    renderBulkTable();
    bulkRows.forEach((row, i) => lookupBulkProduct(i));
}

function renderProductCell(row) {
    if (row.productStatus === 'pending') {
        return `<span class="text-muted small">🔍 Looking up…</span>`;
    }
    if (row.productStatus === 'none') {
        return `<span class="text-danger small">⚠ Not found</span>`;
    }
    if (row.productStatus === 'single') {
        return `<span class="text-success small">${escapeHtml(row.product)}</span>`;
    }
    if (row.productStatus === 'multiple') {
        const options = row.productOptions.map(p =>
            `<option value="${escapeHtml(p)}" ${p === row.product ? 'selected' : ''}>${escapeHtml(p)}</option>`
        ).join('');
        return `<select class="form-select form-select-sm bulk-product-select">
                    <option value="">-- Select --</option>${options}
                </select>`;
    }
    return '';
}

function renderBulkTable() {
    bulkTbody.innerHTML = '';
    bulkSaveBtn.disabled = bulkRows.length === 0;

    bulkRows.forEach((row, i) => {
        const tr = document.createElement('tr');
        tr.dataset.index = i;
        tr.innerHTML = `
            <td>${i + 1}</td>
            <td><input type="text" class="form-control form-control-sm bulk-lot" value="${escapeHtml(row.lot_no)}"></td>
            <td><input type="text" class="form-control form-control-sm bulk-coil" value="${escapeHtml(row.coil_no)}"></td>
            <td class="bulk-product-cell">${renderProductCell(row)}</td>
            <td><input type="text" class="form-control form-control-sm bulk-grade" value="${escapeHtml(row.grade)}"></td>
            <td><input type="number" step="0.01" class="form-control form-control-sm bulk-width" value="${escapeHtml(row.width)}"></td>
            <td><input type="number" step="0.01" class="form-control form-control-sm bulk-length" value="${escapeHtml(row.length)}"></td>
            <td><button type="button" class="btn btn-outline-danger btn-sm bulk-remove">&times;</button></td>
        `;
        bulkTbody.appendChild(tr);
    });

    bulkTbody.querySelectorAll('tr').forEach(tr => {
        const i = parseInt(tr.dataset.index, 10);

        tr.querySelector('.bulk-lot').addEventListener('input', e => bulkRows[i].lot_no = e.target.value);
        tr.querySelector('.bulk-coil').addEventListener('change', e => {
            bulkRows[i].coil_no       = e.target.value;
            bulkRows[i].product       = '';
            bulkRows[i].productStatus = 'pending';
            lookupBulkProduct(i);
        });
        tr.querySelector('.bulk-grade').addEventListener('input', e => bulkRows[i].grade = e.target.value);
        tr.querySelector('.bulk-width').addEventListener('input', e => bulkRows[i].width = e.target.value);
        tr.querySelector('.bulk-length').addEventListener('input', e => bulkRows[i].length = e.target.value);

        tr.querySelector('.bulk-remove').addEventListener('click', () => {
            bulkRows.splice(i, 1);
            renderBulkTable();
        });

        const sel = tr.querySelector('.bulk-product-select');
        if (sel) sel.addEventListener('change', e => { bulkRows[i].product = e.target.value; });
    });
}

async function lookupBulkProduct(i) {
    const row = bulkRows[i];
    if (!row || !row.coil_no) return;

    try {
        const res  = await fetch('mother_coil.php?ajax=get_product&coil=' + encodeURIComponent(row.coil_no));
        const data = await res.json();

        if (!data.ok || data.products.length === 0) {
            row.productStatus = 'none';
            row.product = '';
        } else if (data.products.length === 1) {
            row.productStatus = 'single';
            row.product = data.products[0];
        } else {
            row.productStatus  = 'multiple';
            row.productOptions = data.products;
            row.product = '';
        }
    } catch (e) {
        row.productStatus = 'none';
        row.product = '';
    }

    // Re-render only this row's product cell so other in-progress edits aren't disturbed
    const tr = bulkTbody.querySelector(`tr[data-index="${i}"]`);
    if (tr) {
        const cell = tr.querySelector('.bulk-product-cell');
        cell.innerHTML = renderProductCell(row);
        const sel = cell.querySelector('.bulk-product-select');
        if (sel) sel.addEventListener('change', e => { row.product = e.target.value; });
    }
}

bulkSaveBtn.addEventListener('click', async () => {
    const validRows = bulkRows.filter(r =>
        r.lot_no && r.coil_no && r.grade && r.width && r.length && r.product
    );

    if (validRows.length === 0) {
        showBulkWarning('No complete rows to save. Make sure every row has a resolved Product before saving.');
        return;
    }

    bulkSaveBtn.disabled = true;
    bulkSaveBtn.textContent = 'Saving…';

    try {
        const formData = new FormData();
        formData.append('action', 'bulk_add');
        formData.append('rows_json', JSON.stringify(validRows));

        const res  = await fetch('mother_coil.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.ok) {
            let msg = `✅ ${data.inserted} mother coil(s) saved successfully.`;
            if (data.skipped && data.skipped.length > 0) {
                msg += ` ${data.skipped.length} row(s) skipped: ` +
                    data.skipped.map(s => `Row ${s.row} (${s.reason})`).join('; ');
            }
            bulkResultMsg.textContent = msg;
            bulkResultMsg.classList.remove('d-none');

            if (data.inserted > 0) {
                setTimeout(() => window.location.reload(), 1200);
            }
        } else {
            showBulkWarning('Save failed. Please try again.');
        }
    } catch (e) {
        showBulkWarning('Network error while saving.');
    } finally {
        bulkSaveBtn.disabled = false;
        bulkSaveBtn.textContent = 'Save All';
    }
});

/* Reset bulk modal on open */
document.getElementById('bulkAddMotherModal').addEventListener('show.bs.modal', function () {
    bulkPaste.value = '';
    bulkRows = [];
    renderBulkTable();
    showBulkWarning('');
    bulkResultMsg.classList.add('d-none');
});
</script>

<?php if ($_SESSION['role'] === 'slitting'): ?>
    <div><a href="index.php" class="btn btn-secondary mt-3">← Back</a></div>
<?php else: ?>
    <div><a href="logout.php" class="btn btn-secondary mt-3">Logout</a></div>
<?php endif; ?>

<?php include 'footer.php'; ?>
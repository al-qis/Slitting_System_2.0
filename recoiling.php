<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}
if ($_SESSION['role'] !== 'slitting') {
    die("Access denied");
}

include 'config.php';

$query = "
    (SELECT
        rp.id, rp.status,
        IFNULL(mc.product, rp.product) as product,
        IFNULL(mc.lot_no, rp.lot_no)   as lot_no,
        IFNULL(mc.coil_no, rp.coil_no) as coil_no,
        rp.roll_no,
        IFNULL(mc.width, rp.width)     as width,
        IFNULL(mc.length, rp.length)   as length,
        IFNULL(rp.actual_length, IFNULL(mc.length, rp.length)) as actual_length,
        rp.new_length, rp.date_in, rp.completed_at, rp.remark, rp.mother_id,
        'recoiling_product' as source_table
    FROM recoiling_product rp
    LEFT JOIN mother_coil mc ON rp.mother_id = mc.id)
    UNION ALL
    (SELECT
        log.id, 'sfc' as status,
        mc.product, mc.lot_no, mc.coil_no, '-' as roll_no,
        mc.width, mc.length, mc.length as actual_length,
        NULL as new_length, log.date_in, NULL as completed_at,
        log.remark, log.mother_id,
        'raw_material_log' as source_table
    FROM raw_material_log log
    JOIN mother_coil mc ON log.mother_id = mc.id
    WHERE log.status = 'IN' AND log.action = 'sfc')
    ORDER BY
      CASE status
        WHEN 'sfc'       THEN 1
        WHEN 'pending'   THEN 2
        WHEN 'completed' THEN 3
        ELSE 4
      END,
      date_in ASC
";
$result = $conn->query($query);

$childRes = $conn->query("
    SELECT recoiling_id, lot_no, coil_no, roll_no, width, length, actual_length
    FROM slitting_product
    WHERE recoiling_id IS NOT NULL
    ORDER BY recoiling_id ASC, id ASC
");
$children = [];
if ($childRes) {
    while ($c = $childRes->fetch_assoc()) {
        $children[(int)$c['recoiling_id']][] = $c;
    }
}

$resPending   = $conn->query("SELECT COUNT(*) AS c FROM recoiling_product WHERE status='pending'");
$pending      = $resPending   ? (int)($resPending->fetch_assoc()['c'] ?? 0)   : 0;
$resCompleted = $conn->query("SELECT COUNT(*) AS c FROM recoiling_product WHERE status='completed'");
$completed    = $resCompleted ? (int)($resCompleted->fetch_assoc()['c'] ?? 0) : 0;

$tableRows = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $tableRows[] = $row;
    }
}

$alertType    = '';
$alertMessage = '';

if (isset($_GET['error'])) {
    $alertType = 'danger';
    switch ($_GET['error']) {
        case 'duplicate_lot':
            $lots         = htmlspecialchars(urldecode($_GET['lots'] ?? ''), ENT_QUOTES, 'UTF-8');
            $alertMessage = "<strong>Duplicate Detected!</strong> The roll <strong>{$lots}</strong> already exists. Add a letter suffix to make it unique.";
            break;
        case 'already_completed':
            $alertMessage = "This recoiling record has already been completed.";
            break;
        case 'process_failed':
            $msg          = htmlspecialchars(urldecode($_GET['msg'] ?? ''), ENT_QUOTES, 'UTF-8');
            $alertMessage = "Process failed: {$msg}";
            break;
        case 'invalid_id':
            $alertMessage = "Invalid record ID.";
            break;
        case 'not_found':
            $alertMessage = "Record not found.";
            break;
        default:
            $alertMessage = "An unknown error occurred.";
    }
} elseif (isset($_GET['success'])) {
    $alertType    = 'success';
    $alertMessage = "<strong>Recoiling completed successfully!</strong> The product has been saved.";
}

$reopenId = isset($_GET['open_id']) ? (int)$_GET['open_id'] : 0;

$page_title = "Recoiling Cut";
include 'header.php';
?>

<style>
    .status-cards { display:flex; gap:15px; margin-bottom:30px; }
    .status-card  { flex:1; border-radius:8px; padding:20px; text-align:center; color:#fff; box-shadow:0 2px 8px rgba(0,0,0,0.1); }
    .status-card.pending   { background: linear-gradient(135deg,#ffc107,#ff9800); }
    .status-card.completed { background: linear-gradient(135deg,#28a745,#20c997); }
    .roll-box     { border:1px solid #ddd; padding:15px; border-radius:8px; margin-bottom:15px; background:#f9f9f9; }
    .info-box     { background:#f8f9fa; border-left:5px solid #0d6efd; padding:15px; border-radius:4px; }
</style>

<?php if ($alertMessage): ?>
<div class="alert alert-<?= $alertType ?> alert-dismissible fade show mb-4" role="alert">
    <i class="bi bi-<?= $alertType === 'danger' ? 'exclamation-triangle-fill' : 'check-circle-fill' ?> me-2"></i>
    <?= $alertMessage ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-arrow-repeat"></i> Recoiling Cut</h2>
    <a href="?download=excel" class="btn btn-success shadow-sm">
        <i class="bi bi-download"></i> Download Excel
    </a>
</div>

<div class="status-cards">
    <div class="status-card pending">  <h5>Pending Items</h5>  <h2><?= $pending ?></h2></div>
    <div class="status-card completed"><h5>Completed Items</h5><h2><?= $completed ?></h2></div>
</div>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Status</th>
                    <th>Product</th>
                    <th>Lot &amp; Coil No.</th>
                    <th>Roll No.</th>
                    <th>Width</th>
                    <th>Length</th>
                    <th>New Length</th>
                    <th>Date In</th>
                    <th>Remark</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (count($tableRows) > 0): ?>
                <?php foreach ($tableRows as $row): ?>
                <?php
                    $rid       = (int)$row['id'];
                    $status    = $row['status'] ?? '';
                    $source    = $row['source_table'];
                    $isSfc     = ($status === 'sfc');
                    $canRecoil = ($status === 'pending' || $status === 'sfc');
                    $kids      = ($source === 'recoiling_product') ? ($children[$rid] ?? []) : [];
                ?>
                <tr <?= ($rid === $reopenId) ? 'id="reopen-row"' : '' ?>>
                    <td><strong><?= $rid ?></strong></td>
                    <td>
                        <span class="badge <?= $status === 'completed' ? 'bg-success' : ($status === 'sfc' ? 'bg-info' : 'bg-warning text-dark') ?>">
                            <?= htmlspecialchars(strtoupper($status)) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($row['product'] ?? '-') ?></td>
                    <td>
                        <?php
                        $display_lot  = $kids[0]['lot_no']  ?? ($row['lot_no']  ?? '-');
                        $display_coil = $kids[0]['coil_no'] ?? ($row['coil_no'] ?? '');
                        echo htmlspecialchars($display_lot . ' ' . $display_coil);
                        ?>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($kids[0]['roll_no'] ?? ($row['roll_no'] ?? '-')) ?></strong>
                    </td>
                    <td><?= number_format((float)($row['width'] ?? 0)) ?></td>
                    <td><?= number_format((float)($row['actual_length'] ?? 0)) ?></td>
                    <td>
                        <?php
                        $nl = isset($kids[0]['actual_length'])
                            ? (float)$kids[0]['actual_length']
                            : (float)($row['new_length'] ?? 0);
                        echo ($nl > 0 && !$isSfc)
                            ? '<strong class="text-success">' . number_format($nl) . '</strong>'
                            : '-';
                        ?>
                    </td>
                    <td class="small"><?= htmlspecialchars($row['date_in'] ?? '-') ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($row['remark'] ?? '-') ?></td>
                    <td>
                        <?php if ($canRecoil): ?>
                            <button type="button"
                                    class="btn btn-primary btn-sm btn-recoil"
                                    data-rid="<?= $rid ?>"
                                    data-product="<?= htmlspecialchars($row['product'] ?? '') ?>"
                                    data-lot="<?= htmlspecialchars($row['lot_no'] ?? '') ?>"
                                    data-coil="<?= htmlspecialchars($row['coil_no'] ?? '') ?>"
                                    data-roll="<?= htmlspecialchars($row['roll_no'] ?? '') ?>"
                                    data-width="<?= (float)($row['width'] ?? 0) ?>"
                                    data-length="<?= (float)($row['actual_length'] ?? 0) ?>"
                                    data-source="<?= htmlspecialchars($source) ?>">
                                <i class="bi bi-play-circle"></i> Recoil
                            </button>
                        <?php else: ?>
                            <span class="badge bg-light text-dark border">Done</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="11" class="text-center py-4 text-muted">No records found.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══ MODAL ═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="recoilingModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-arrow-repeat me-2"></i>Start Recoiling Process
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <form method="post" action="recoiling_handler.php" id="recoilingForm">
                <input type="hidden" name="action"       value="start_and_complete_recoiling">
                <input type="hidden" name="id"           id="recoil_id">
                <input type="hidden" name="source_table" id="recoil_source_table">

                <div class="modal-body">

                    <!-- Product info panel -->
                    <div class="info-box mb-4">
                        <div class="row g-2">
                            <div class="col-6">
                                <strong>Product:</strong>
                                <span id="modal_product" class="ms-1">-</span>
                            </div>
                            <div class="col-6">
                                <strong>Lot &amp; Coil:</strong>
                                <span id="modal_lot_coil" class="ms-1">-</span>
                            </div>
                            <div class="col-6">
                                <strong>Roll No:</strong>
                                <span id="modal_roll" class="ms-1 fw-bold text-primary">-</span>
                            </div>
                            <div class="col-6">
                                <strong>Original Width:</strong>
                                <span id="modal_width" class="ms-1">-</span> mm
                            </div>
                            <div class="col-12">
                                <strong>Original Length:</strong>
                                <span id="modal_length" class="ms-1">-</span> m
                                <small class="text-muted ms-2">
                                    (output will keep the same Lot, Coil, and Roll No)
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Step 1: Cut Type -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">Step 1: Select Cut Type</label>
                        <div class="d-flex gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="cut_type"
                                       id="cutNormal" value="normal"
                                       onchange="handleCutTypeChange()">
                                <label class="form-check-label" for="cutNormal">
                                    Normal recoil
                                    <small class="text-muted d-block">
                                        Same lot/coil/roll — removes defect at start or end
                                    </small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="cut_type"
                                       id="cutInto2" value="cut_into_2"
                                       onchange="handleCutTypeChange()">
                                <label class="form-check-label" for="cutInto2">
                                    Cut into 2
                                    <small class="text-muted d-block">
                                        Creates two new rolls (R1 &amp; R2)
                                    </small>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Dynamic form area -->
                    <div id="rollDetailsForm" style="display:none;">
                        <hr>
                        <h6 class="mb-3 fw-bold">Step 2: Enter Production Details</h6>
                        <div id="rollsContainer"></div>
                    </div>

                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success px-4" id="submitBtn" style="display:none;">
                        <i class="bi bi-check-circle me-1"></i> Complete Recoiling
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let productData = {};
const reopenId = <?= $reopenId ?>;

document.addEventListener('DOMContentLoaded', function () {

    document.body.addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-recoil');
        if (!btn) return;
        openRecoilModal(btn);
    });

    document.getElementById('recoilingForm').addEventListener('submit', function (e) {
        if (!confirm('Complete recoiling? This cannot be undone.')) {
            e.preventDefault();
        }
    });

    if (reopenId > 0) {
        const targetBtn = document.querySelector(`.btn-recoil[data-rid="${reopenId}"]`);
        if (targetBtn) {
            const row = document.getElementById('reopen-row');
            if (row) row.scrollIntoView({ behavior:'smooth', block:'center' });
            setTimeout(() => openRecoilModal(targetBtn), 400);
        }
    }
});

function openRecoilModal(btn) {
    productData = {
        rid    : parseInt(btn.dataset.rid),
        product: btn.dataset.product || '',
        lot_no : btn.dataset.lot    || '',
        coil_no: btn.dataset.coil   || '',
        roll_no: btn.dataset.roll   || '',
        width  : parseFloat(btn.dataset.width)  || 0,
        length : parseFloat(btn.dataset.length) || 0,
        source : btn.dataset.source || ''
    };

    document.getElementById('recoil_id').value           = productData.rid;
    document.getElementById('recoil_source_table').value = productData.source;

    document.getElementById('modal_product').textContent  = productData.product;
    document.getElementById('modal_lot_coil').textContent = productData.lot_no + ' ' + productData.coil_no;
    document.getElementById('modal_roll').textContent     = productData.roll_no || '-';
    document.getElementById('modal_width').textContent    = productData.width;
    document.getElementById('modal_length').textContent   = productData.length;

    // Reset form
    document.getElementById('cutNormal').checked          = false;
    document.getElementById('cutInto2').checked           = false;
    document.getElementById('rollsContainer').innerHTML   = '';
    document.getElementById('rollDetailsForm').style.display = 'none';
    document.getElementById('submitBtn').style.display       = 'none';

    bootstrap.Modal.getOrCreateInstance(document.getElementById('recoilingModal')).show();
}

function handleCutTypeChange() {
    const selected = document.querySelector('input[name="cut_type"]:checked');
    if (!selected) return;

    const container = document.getElementById('rollsContainer');
    container.innerHTML = '';
    document.getElementById('rollDetailsForm').style.display = 'block';
    document.getElementById('submitBtn').style.display       = 'inline-block';

    if (selected.value === 'normal') {
        container.appendChild(buildNormalForm());
    } else {
        container.appendChild(buildCutInto2FormA());
        container.appendChild(buildCutInto2FormB());
    }
}

// ── Normal recoil form ────────────────────────────────────────────────────
// Output keeps the SAME lot/coil/roll as source.
// Width is editable (operator may trim width during recoil).
// Actual length = original length minus defect removed.
function buildNormalForm() {
    const div = document.createElement('div');
    div.className = 'roll-box shadow-sm';

    // Pass the original roll_no and lot_no as hidden — same as source
    div.innerHTML = `
        <input type="hidden" name="roll_number[]" value="1">
        <input type="hidden" name="length[]"      value="${productData.length}">
        <input type="hidden" name="letter[]"      value="">

        <div class="alert alert-info py-2 small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Output: <strong>${productData.lot_no} ${productData.coil_no}
            ${productData.roll_no}</strong> — same reference, updated length &amp; width.
        </div>

        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-bold">
                    Width after recoil (mm)
                    <small class="text-muted fw-normal">— edit if trimmed</small>
                </label>
                <input type="number" step="0.01" name="new_width[]"
                       class="form-control"
                       id="normal_width"
                       value="${productData.width}"
                       min="0.01" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">Defect removed (m)</label>
                <input type="number" step="0.01" name="defect[]"
                       class="form-control border-danger"
                       value="0" min="0" id="normal_defect">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold text-success">Actual Length after recoil (m)</label>
                <input type="number" step="0.01" name="actual_length[]"
                       class="form-control fw-bold"
                       id="normal_actual"
                       value="${productData.length.toFixed(2)}"
                       required>
                <div class="form-text">Original: ${productData.length} m</div>
            </div>
            <div class="col-12">
                <input type="text" name="remark[]" class="form-control"
                       placeholder="Remark (optional)...">
            </div>
        </div>`;

    setTimeout(() => {
        const defEl = div.querySelector('#normal_defect');
        const actEl = div.querySelector('#normal_actual');
        if (defEl && actEl) {
            defEl.addEventListener('input', () => {
                const calc = productData.length - parseFloat(defEl.value || 0);
                actEl.value = (calc >= 0 ? calc : 0).toFixed(2);
            });
        }
    }, 30);

    return div;
}

// ── Cut Into 2 — Part A ───────────────────────────────────────────────────
function letterOptionsHTML() {
    return '<option value="">-- None --</option>'
         + '<option value="a">a</option>'
         + '<option value="b">b</option>'
         + '<option value="c">c</option>';
}

function buildCutInto2FormA() {
    const div = document.createElement('div');
    div.className = 'roll-box mb-3';
    div.innerHTML = `
        <h6 class="fw-bold mb-3 text-primary">Part 1 (R1)</h6>
        <input type="hidden" name="roll_number[]" value="1">
        <input type="hidden" name="length[]"      value="${productData.length}">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="small fw-bold">Letter suffix</label>
                <select class="form-select form-select-sm" name="letter[]">
                    ${letterOptionsHTML()}
                </select>
            </div>
            <div class="col-md-3">
                <label class="small fw-bold">Width (mm)</label>
                <input type="number" step="0.01" name="new_width[]"
                       class="form-control form-control-sm"
                       value="${productData.width}" required>
            </div>
            <div class="col-md-3">
                <label class="small fw-bold">Defect (m)</label>
                <input type="number" step="0.01" name="defect[]"
                       class="form-control form-control-sm" id="defectA"
                       value="0" min="0">
            </div>
            <div class="col-md-3">
                <label class="small fw-bold text-success">Actual Length (m)</label>
                <input type="number" step="0.01" name="actual_length[]"
                       class="form-control form-control-sm" id="actualA"
                       placeholder="Enter length" required>
            </div>
            <div class="col-12">
                <input type="text" name="remark[]" class="form-control form-control-sm"
                       placeholder="Remark (optional)...">
            </div>
        </div>`;

    setTimeout(() => {
        const defA = div.querySelector('#defectA');
        const actA = div.querySelector('#actualA');
        if (defA) defA.addEventListener('input', updatePartB);
        if (actA) actA.addEventListener('input', updatePartB);
    }, 30);

    return div;
}

function buildCutInto2FormB() {
    const div = document.createElement('div');
    div.className = 'roll-box';
    div.innerHTML = `
        <h6 class="fw-bold mb-3 text-success">Part 2 (R2) <small class="text-muted fw-normal">— length auto-calculated</small></h6>
        <input type="hidden" name="roll_number[]" value="2">
        <input type="hidden" name="length[]"      value="${productData.length}">
        <input type="hidden" name="defect[]"      value="0">
        <input type="hidden" name="remark[]"      value="">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="small fw-bold">Letter suffix</label>
                <select class="form-select form-select-sm" name="letter[]">
                    ${letterOptionsHTML()}
                </select>
            </div>
            <div class="col-md-3">
                <label class="small fw-bold">Width (mm)</label>
                <input type="number" step="0.01" name="new_width[]"
                       class="form-control form-control-sm"
                       value="${productData.width}" required>
            </div>
            <div class="col-md-6">
                <label class="small fw-bold">Computed Actual Length (m)</label>
                <input type="number" step="0.01" name="actual_length[]"
                       class="form-control form-control-sm bg-light fw-bold text-success"
                       id="actualB" readonly>
                <small class="text-muted">= Original − Defect A − Actual A</small>
            </div>
        </div>`;

    return div;
}

function updatePartB() {
    const defA = parseFloat(document.getElementById('defectA')?.value || 0);
    const actA = parseFloat(document.getElementById('actualA')?.value || 0);
    const actB = document.getElementById('actualB');
    if (actB) {
        const result = productData.length - defA - actA;
        actB.value = (result >= 0 ? result : 0).toFixed(2);
    }
}
</script>

<?php include 'footer.php'; ?>
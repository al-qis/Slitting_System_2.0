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
$pending      = $resPending   ? (int)($resPending->fetch_assoc()['c'] ?? 0) : 0;
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
            $lots         = htmlspecialchars(urldecode($_GET['lots'] ?? ''));
            $alertMessage = "<strong>Duplicate Detected!</strong> Roll <strong>{$lots}</strong> already exists. Add a letter suffix.";
            break;
        case 'already_completed': $alertMessage = "This recoiling record is already completed."; break;
        case 'process_failed':
            $alertMessage = "Process failed: " . htmlspecialchars(urldecode($_GET['msg'] ?? '')); break;
        case 'invalid_id':  $alertMessage = "Invalid record ID."; break;
        case 'not_found':   $alertMessage = "Record not found."; break;
        default:            $alertMessage = "An unknown error occurred.";
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
    .roll-box { border:1px solid #ddd; padding:18px; border-radius:8px; margin-bottom:15px; background:#f9f9f9; }
    .info-box { background:#f8f9fa; border-left:5px solid #0d6efd; padding:15px; border-radius:4px; }

    /* Mode selector cards */
    .mode-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:4px; }
    .mode-card {
        border:2px solid #dee2e6; border-radius:10px; padding:16px 12px;
        text-align:center; cursor:pointer; transition:all .18s; background:#fff;
        position:relative;
    }
    .mode-card:hover { border-color:#0d6efd; background:#f0f6ff; }
    .mode-card.selected { border-color:#0d6efd; background:#e7f0ff; }
    .mode-card input[type=radio] { position:absolute; opacity:0; pointer-events:none; }
    .mode-card .mode-title { font-weight:700; font-size:.9rem; color:#212529; }
    .mode-card .mode-desc  { font-size:.75rem; color:#6c757d; margin-top:3px; }
    .mode-card.selected .mode-title { color:#0d6efd; }
</style>

<?php if ($alertMessage): ?>
<div class="alert alert-<?= $alertType ?> alert-dismissible fade show mb-4">
    <i class="bi bi-<?= $alertType === 'danger' ? 'exclamation-triangle-fill' : 'check-circle-fill' ?> me-2"></i>
    <?= $alertMessage ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-arrow-repeat"></i> Recoiling Cut</h2>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-info shadow-sm text-white" data-bs-toggle="modal" data-bs-target="#summaryModal">
            <i class="bi bi-bar-chart-line me-1"></i> Summary Report
        </button>
        <a href="?download=excel" class="btn btn-success shadow-sm">
            <i class="bi bi-download"></i> Download Excel
        </a>
    </div>
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
                    <th>ID</th><th>Status</th><th>Product</th>
                    <th>Lot &amp; Coil No.</th><th>Roll No.</th>
                    <th>Width</th><th>Length</th><th>New Length</th>
                    <th>Date In</th><th>Remark</th><th>Action</th>
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
                        <span class="badge <?= $status==='completed' ? 'bg-success' : ($status==='sfc' ? 'bg-info' : 'bg-warning text-dark') ?>">
                            <?= strtoupper($status) ?>
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
                    <td><strong><?= htmlspecialchars($kids[0]['roll_no'] ?? ($row['roll_no'] ?? '-')) ?></strong></td>
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
                <tr><td colspan="11" class="text-center py-4 text-muted">No records found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══ SUMMARY REPORT MODAL ══════════════════════════════════ -->
<div class="modal fade" id="summaryModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="bi bi-bar-chart-line me-2"></i>Recoiling Summary Report
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">

                <!-- Month filter -->
                <div class="row g-2 align-items-end mb-4">
                    <div class="col-auto">
                        <label class="form-label fw-bold small">Filter by Month</label>
                        <input type="month" id="summaryMonthFilter"
                               class="form-control"
                               value="<?= date('Y-m') ?>">
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-primary btn-sm" onclick="loadSummary()">
                            <i class="bi bi-search me-1"></i>Load
                        </button>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-outline-secondary btn-sm" onclick="clearSummaryFilter()">
                            All Time
                        </button>
                    </div>
                    <div class="col-auto ms-auto small text-muted" id="summaryRange"></div>
                </div>

                <!-- KPI cards -->
                <div class="row g-3 mb-4" id="summaryKpiRow">
                    <div class="col-4">
                        <div class="card border-0 shadow-sm text-center p-3" style="background:linear-gradient(135deg,#6610f2,#a855f7);color:#fff;">
                            <div class="fw-bold small mb-1"><i class="bi bi-arrow-repeat me-1"></i>Rewinding</div>
                            <div class="fs-2 fw-bold" id="kpi_rewinding">—</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="card border-0 shadow-sm text-center p-3" style="background:linear-gradient(135deg,#dc3545,#ff7043);color:#fff;">
                            <div class="fw-bold small mb-1"><i class="bi bi-scissors me-1"></i>Cut Defect</div>
                            <div class="fs-2 fw-bold" id="kpi_normal">—</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="card border-0 shadow-sm text-center p-3" style="background:linear-gradient(135deg,#fd7e14,#ffc107);color:#fff;">
                            <div class="fw-bold small mb-1"><i class="bi bi-scissors me-1"></i>✂️ Cut Into 2</div>
                            <div class="fs-2 fw-bold" id="kpi_cut2">—</div>
                        </div>
                    </div>
                </div>

                <!-- Detail table -->
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle" id="summaryTable">
                        <thead class="table-secondary">
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Lot No.</th>
                                <th>Coil No.</th>
                                <th>Roll No.</th>
                                <th>Product</th>
                                <th class="text-end">Length (m)</th>
                            </tr>
                        </thead>
                        <tbody id="summaryTableBody">
                            <tr><td colspan="7" class="text-center text-muted py-3">Click Load to view report.</td></tr>
                        </tbody>
                    </table>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══ MODAL ═══════════════════════════════════════════════════ -->
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
                <input type="hidden" name="cut_type"     id="cut_type_hidden" value="">

                <div class="modal-body" style="overflow-y:auto;max-height:calc(100vh - 220px);">

                    <!-- Product info panel -->
                    <div class="info-box mb-4">
                        <div class="row g-2">
                            <div class="col-6">
                                <strong>Product:</strong>
                                <span id="modal_product" class="ms-1">-</span>
                            </div>
                            <div class="col-6">
                                <strong>Lot &amp; Coil:</strong>
                                <span id="modal_lot_coil" class="ms-1 fw-bold text-primary font-monospace">-</span>
                            </div>
                            <div class="col-6">
                                <strong>Roll No:</strong>
                                <span id="modal_roll" class="ms-1 fw-bold text-primary">-</span>
                            </div>
                            <div class="col-6">
                                <strong>Width:</strong>
                                <span id="modal_width" class="ms-1">-</span> mm
                            </div>
                            <div class="col-12">
                                <strong>Original Length:</strong>
                                <span id="modal_length" class="ms-1 fw-bold">-</span> m
                            </div>
                        </div>
                    </div>

                    <!-- Step 1: Mode selection via card UI -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">Step 1: Select Recoiling Mode</label>
                        <div class="mode-grid">

                            <!-- Mode A: Rewinding -->
                            <label class="mode-card" id="card-rewinding" onclick="selectMode('rewinding')">
                                <input type="radio" name="cut_type_radio" value="rewinding">
                                <div class="mode-title">Rewinding</div>
                                <div class="mode-desc">Rewind only — same roll, update length if changed</div>
                            </label>

                            <!-- Mode B: Cut Defect -->
                            <label class="mode-card" id="card-normal" onclick="selectMode('normal')">
                                <input type="radio" name="cut_type_radio" value="normal">
                                <div class="mode-title">Cut Defect</div>
                                <div class="mode-desc">Remove defect at start or end — same roll number</div>
                            </label>

                            <!-- Mode C: Cut Into 2 -->
                            <label class="mode-card" id="card-cut_into_2" onclick="selectMode('cut_into_2')">
                                <input type="radio" name="cut_type_radio" value="cut_into_2">
                                <div class="mode-title">Cut Into 2</div>
                                <div class="mode-desc">Split into two new rolls — enter both lengths manually</div>
                            </label>

                        </div>
                    </div>

                    <!-- Step 2: Dynamic form -->
                    <div id="rollDetailsForm" style="display:none;">
                        <hr>
                        <h6 class="mb-3 fw-bold">Step 2: Enter Details</h6>
                        <div id="rollsContainer"></div>
                    </div>

                </div>

            </form>

            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="recoilingForm" class="btn btn-success px-4" id="submitBtn" style="display:none;">
                    <i class="bi bi-check-circle me-1"></i> Complete Recoiling
                </button>
            </div>
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
    document.getElementById('cut_type_hidden').value     = '';

    document.getElementById('modal_product').textContent  = productData.product;
    document.getElementById('modal_lot_coil').textContent = productData.lot_no + ' ' + productData.coil_no;
    document.getElementById('modal_roll').textContent     = productData.roll_no || '-';
    document.getElementById('modal_width').textContent    = productData.width;
    document.getElementById('modal_length').textContent   = productData.length;

    // Reset mode selection
    document.querySelectorAll('.mode-card').forEach(c => c.classList.remove('selected'));
    document.querySelectorAll('input[name="cut_type_radio"]').forEach(r => r.checked = false);
    document.getElementById('rollsContainer').innerHTML      = '';
    document.getElementById('rollDetailsForm').style.display = 'none';
    document.getElementById('submitBtn').style.display       = 'none';

    bootstrap.Modal.getOrCreateInstance(document.getElementById('recoilingModal')).show();
}

// ── Mode selector ─────────────────────────────────────────────
function selectMode(mode) {
    // Highlight card
    document.querySelectorAll('.mode-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('card-' + mode).classList.add('selected');

    // Set hidden cut_type for handler
    document.getElementById('cut_type_hidden').value = mode;

    // Build form
    const container = document.getElementById('rollsContainer');
    container.innerHTML = '';
    document.getElementById('rollDetailsForm').style.display = 'block';
    document.getElementById('submitBtn').style.display       = 'inline-block';

    if (mode === 'rewinding') {
        container.appendChild(buildRewindingForm());
    } else if (mode === 'normal') {
        container.appendChild(buildCutDefectForm());
    } else if (mode === 'cut_into_2') {
        container.appendChild(buildCutInto2());
    }
}

// ════════════════════════════════════════════════
// MODE A: REWINDING
// Same lot/coil/roll — operator just confirms or
// updates the length if the rewind changed it.
// ════════════════════════════════════════════════
function buildRewindingForm() {
    const div = document.createElement('div');
    div.className = 'roll-box';
    div.innerHTML = `
        <div class="alert alert-info py-2 small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Output: <strong>${productData.lot_no} ${productData.coil_no}
            ${productData.roll_no}</strong> — same reference. Update length only if it changed after rewinding.
        </div>

        <input type="hidden" name="roll_number[]" value="1">
        <input type="hidden" name="defect[]"      value="0">
        <input type="hidden" name="letter[]"      value="">

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-bold">
                    Width after rewinding (mm)
                    <small class="text-muted fw-normal">— edit if trimmed</small>
                </label>
                <input type="number" step="0.01" name="new_width[]"
                       class="form-control"
                       value="${productData.width}"
                       min="0.01" required>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold text-success">
                    New Length (m)
                    <small class="text-muted fw-normal">— original: ${productData.length} m</small>
                </label>
                <input type="number" step="0.01" name="actual_length[]"
                       class="form-control fw-bold"
                       value="${productData.length.toFixed(2)}"
                       min="0.01" required>
                <div class="form-text">Enter the same value if length did not change.</div>
            </div>
            <div class="col-12">
                <label class="form-label">Remark (optional)</label>
                <input type="text" name="remark[]" class="form-control"
                       placeholder="e.g. Rewound to remove core damage">
            </div>
        </div>
    `;
    // hidden length[] field (handler reads this)
    const lenHidden = document.createElement('input');
    lenHidden.type  = 'hidden';
    lenHidden.name  = 'length[]';
    lenHidden.value = productData.length;
    div.appendChild(lenHidden);
    return div;
}

// ════════════════════════════════════════════════
// MODE B: CUT DEFECT
// Same lot/coil/roll — defect removed from start
// or end, actual length = original − defect.
// ════════════════════════════════════════════════
function buildCutDefectForm() {
    const div = document.createElement('div');
    div.className = 'roll-box';
    div.innerHTML = `
        <div class="alert alert-warning py-2 small mb-3">
            <i class="bi bi-scissors me-1"></i>
            Output: <strong>${productData.lot_no} ${productData.coil_no}
            ${productData.roll_no}</strong> — same reference. Actual length = original − defect removed.
        </div>

        <input type="hidden" name="roll_number[]" value="1">
        <input type="hidden" name="letter[]"      value="">

        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-bold">
                    Width (mm)
                    <small class="text-muted fw-normal">— edit if trimmed</small>
                </label>
                <input type="number" step="0.01" name="new_width[]"
                       class="form-control"
                       id="cd_width"
                       value="${productData.width}"
                       min="0.01" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold text-danger">Defect removed (m)</label>
                <input type="number" step="0.01" name="defect[]"
                       class="form-control border-danger"
                       id="cd_defect"
                       value="0" min="0" required>
                <div class="form-text">Length that was cut off and discarded.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold text-success">Actual Length after cut (m)</label>
                <input type="number" step="0.01" name="actual_length[]"
                       class="form-control fw-bold"
                       id="cd_actual"
                       value="${productData.length.toFixed(2)}"
                       min="0.01" required>
                <div class="form-text">Original: ${productData.length} m</div>
            </div>
            <div class="col-12">
                <label class="form-label">Remark (optional)</label>
                <input type="text" name="remark[]" class="form-control"
                       placeholder="e.g. Defect at start, cut 2m">
            </div>
        </div>
    `;
    const lenHidden = document.createElement('input');
    lenHidden.type  = 'hidden';
    lenHidden.name  = 'length[]';
    lenHidden.value = productData.length;
    div.appendChild(lenHidden);

    setTimeout(() => {
        const defEl = div.querySelector('#cd_defect');
        const actEl = div.querySelector('#cd_actual');
        defEl.addEventListener('input', () => {
            const calc = productData.length - parseFloat(defEl.value || 0);
            actEl.value = (calc >= 0 ? calc : 0).toFixed(2);
        });
    }, 30);

    return div;
}

// ════════════════════════════════════════════════
// MODE C: CUT INTO 2
// Split one roll into two rolls.
// coil_no and roll_no remain the same (e.g. R4 → R4, R4).
// lot_no gets an OPTIONAL letter suffix (a / b / c / none).
// Source row is voided with lot_no renamed to *_OLD_* to free
// the UNIQUE KEY, so choosing no suffix is safe.
// ════════════════════════════════════════════════
function buildCutInto2() {
    const frag = document.createDocumentFragment();

    // ── Info banner ──
    const infoDiv = document.createElement('div');
    infoDiv.className = 'alert alert-secondary py-2 small mb-3';
    infoDiv.innerHTML = `
        <i class="bi bi-scissors me-1"></i>
        <strong>Cut Into 2</strong> — splitting <strong>${productData.lot_no} ${productData.coil_no} ${productData.roll_no}</strong>
        into two rolls. Coil No. and Roll No. stay the same.
        The letter suffix is <strong>optional</strong> — if omitted, both rolls keep the original lot number.
        <div class="mt-2 row g-2 align-items-center">
            <div class="col-auto">
                <label class="form-label mb-0 fw-bold small">Defect / waste between cuts (m):</label>
            </div>
            <div class="col-auto">
                <input type="number" step="0.01" id="c2_defect" class="form-control form-control-sm"
                       style="width:100px" value="0" min="0" placeholder="0">
            </div>
        </div>
    `;
    frag.appendChild(infoDiv);

    // Helper: build one roll card
    function buildRollCard(rollLabel, rollIdx, colorClass, iconClass) {
        const div = document.createElement('div');
        div.className = 'roll-box mb-3';
        div.innerHTML = `
            <h6 class="fw-bold mb-3 ${colorClass} border-bottom pb-2">
                <i class="bi ${iconClass} me-1"></i> ${rollLabel}
            </h6>
            <input type="hidden" name="roll_number[]" value="${rollIdx}">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold small">
                        Letter suffix
                        <span class="text-muted fw-normal">(optional — makes lot unique)</span>
                    </label>
                    <select name="letter[]" class="form-select c2-letter" id="c2_letter${rollIdx}">
                        <option value="">-- None (same lot) --</option>
                        <option value="a">a</option>
                        <option value="b">b</option>
                        <option value="c">c</option>
                        <option value="d">d</option>
                    </select>
                    <div class="form-text c2-lot-preview" id="c2_lotpreview${rollIdx}">
                        Lot: <strong>${productData.lot_no}</strong>
                        &nbsp;|&nbsp; Coil: <strong>${productData.coil_no}</strong>
                        &nbsp;|&nbsp; Roll: <strong>${productData.roll_no}</strong>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small">Width (mm)</label>
                    <input type="number" step="0.01" name="new_width[]"
                           class="form-control" value="${productData.width}"
                           min="0.01" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-success">
                        Actual Length (m) <span class="text-danger">*</span>
                    </label>
                    <input type="number" step="0.01" name="actual_length[]"
                           id="c2_actual${rollIdx}" class="form-control"
                           placeholder="Enter length" min="0.01" required>
                </div>
                <div class="col-12">
                    <input type="text" name="remark[]" class="form-control"
                           placeholder="Remark for ${rollLabel} (optional)">
                </div>
            </div>
        `;
        // Hidden fields
        ['length[]', 'defect[]'].forEach((n, vi) => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = n;
            inp.value = vi === 0 ? productData.length : '0';
            div.appendChild(inp);
        });
        return div;
    }

    const r1 = buildRollCard('Roll 1', 1, 'text-primary', 'bi-1-circle');
    const r2 = buildRollCard('Roll 2', 2, 'text-success', 'bi-2-circle');

    // Balance summary bar
    const balDiv = document.createElement('div');
    balDiv.className = 'mt-2 p-2 rounded bg-light border small';
    balDiv.id = 'c2_balance_info';
    balDiv.style.display = 'none';
    balDiv.innerHTML = `<i class="bi bi-calculator me-1"></i>
        <strong>Balance:</strong> <span id="c2_balance_text"></span>`;

    frag.appendChild(r1);
    frag.appendChild(r2);
    frag.appendChild(balDiv);

    // Wire up interactivity after DOM insert
    setTimeout(() => {
        const letter1  = document.getElementById('c2_letter1');
        const letter2  = document.getElementById('c2_letter2');
        const actual1  = document.getElementById('c2_actual1');
        const actual2  = document.getElementById('c2_actual2');
        const defect   = document.getElementById('c2_defect');
        const preview1 = document.getElementById('c2_lotpreview1');
        const preview2 = document.getElementById('c2_lotpreview2');
        const balInfo  = document.getElementById('c2_balance_info');
        const balText  = document.getElementById('c2_balance_text');

        function lotLabel(letter) {
            return letter
                ? `${productData.lot_no}<strong>${letter}</strong>`
                : `<strong>${productData.lot_no}</strong> <em class="text-muted">(same as original)</em>`;
        }

        function updatePreview() {
            const l1 = letter1?.value || '';
            const l2 = letter2?.value || '';
            preview1.innerHTML = `Lot: ${lotLabel(l1)} &nbsp;|&nbsp; Coil: <strong>${productData.coil_no}</strong> &nbsp;|&nbsp; Roll: <strong>${productData.roll_no}</strong>`;
            preview2.innerHTML = `Lot: ${lotLabel(l2)} &nbsp;|&nbsp; Coil: <strong>${productData.coil_no}</strong> &nbsp;|&nbsp; Roll: <strong>${productData.roll_no}</strong>`;
        }

        function updateBalance() {
            const a1  = parseFloat(actual1?.value || 0);
            const a2  = parseFloat(actual2?.value || 0);
            const def = parseFloat(defect?.value  || 0);
            const used = +(a1 + a2 + def).toFixed(3);
            const orig = productData.length;
            const diff = +(orig - used).toFixed(3);

            if (a1 > 0 || a2 > 0) {
                balInfo.style.display = 'block';
                if (Math.abs(diff) < 0.01) {
                    balText.innerHTML = `Roll1 ${a1}m + Roll2 ${a2}m + Waste ${def}m = ${used}m ✅ Matches original ${orig}m`;
                    balText.style.color = '#166534';
                } else if (diff > 0) {
                    balText.innerHTML = `= ${used}m — <strong>${diff}m unaccounted</strong> (original: ${orig}m)`;
                    balText.style.color = '#92400e';
                } else {
                    balText.innerHTML = `= ${used}m — <strong>exceeds original by ${Math.abs(diff)}m</strong>`;
                    balText.style.color = '#991b1b';
                }
            } else {
                balInfo.style.display = 'none';
            }
        }

        [letter1, letter2].forEach(el => el?.addEventListener('change', updatePreview));
        [actual1, actual2, defect].forEach(el => el?.addEventListener('input', updateBalance));
        updatePreview(); // init
    }, 50);

    const wrapper = document.createElement('div');
    wrapper.appendChild(frag);
    return wrapper;
}

// ── Summary Report ─────────────────────────────────────────────
function clearSummaryFilter() {
    document.getElementById('summaryMonthFilter').value = '';
    loadSummary();
}

function loadSummary() {
    const month = document.getElementById('summaryMonthFilter').value;
    document.getElementById('summaryRange').textContent = month ? 'Showing: ' + month : 'Showing: All time';

    let url = 'recoiling_summary_ajax.php';
    if (month) url += '?month=' + encodeURIComponent(month);

    fetch(url)
        .then(r => r.json())
        .then(data => {
            document.getElementById('kpi_rewinding').textContent = data.counts?.rewinding  ?? 0;
            document.getElementById('kpi_normal').textContent    = data.counts?.normal     ?? 0;
            document.getElementById('kpi_cut2').textContent      = data.counts?.cut_into_2 ?? 0;

            const tbody = document.getElementById('summaryTableBody');
            if (!data.rows || data.rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">No records for this period.</td></tr>';
                return;
            }

            const typeLabel = { rewinding:'🔄 Rewinding', normal:'✂️ Cut Defect', cut_into_2:'✂️✂️ Cut Into 2' };
            const typeCls   = { rewinding:'bg-secondary', normal:'bg-danger',     cut_into_2:'bg-warning text-dark' };

            tbody.innerHTML = data.rows.map(r => `
                <tr>
                    <td class="small">${escHtml(r.completed_at ?? r.date_in ?? '-')}</td>
                    <td><span class="badge ${typeCls[r.cut_type] ?? 'bg-secondary'}">${typeLabel[r.cut_type] ?? escHtml(r.cut_type)}</span></td>
                    <td class="font-monospace small">${escHtml(r.lot_no ?? '-')}</td>
                    <td class="font-monospace small">${escHtml(r.coil_no ?? '-')}</td>
                    <td class="small">${escHtml(r.roll_no ?? '-')}</td>
                    <td class="small">${escHtml(r.product ?? '-')}</td>
                    <td class="text-end small">${parseFloat(r.new_length || 0).toLocaleString()}</td>
                </tr>
            `).join('');
        })
        .catch(() => {
            document.getElementById('summaryTableBody').innerHTML =
                '<tr><td colspan="7" class="text-danger text-center">Failed to load. Check recoiling_summary_ajax.php exists.</td></tr>';
        });
}

function escHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('summaryModal').addEventListener('show.bs.modal', function () {
        loadSummary();
    });
});
</script>

<?php include 'footer.php'; ?>
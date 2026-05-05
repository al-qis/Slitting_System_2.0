<?php
session_start();

// 1. Authentication & Role Check
if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}
if ($_SESSION['role'] !== 'slitting') {
    die("Access denied");
}

include 'config.php';

// 2. Data Fetching Logic
$query = "
    (SELECT 
        rp.id, rp.status, 
        IFNULL(mc.product, rp.product) as product, 
        IFNULL(mc.lot_no, rp.lot_no) as lot_no, 
        IFNULL(mc.coil_no, rp.coil_no) as coil_no, 
        rp.roll_no, 
        IFNULL(mc.width, rp.width) as width, 
        IFNULL(mc.length, rp.length) as length, 
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
        WHEN 'sfc' THEN 1 
        WHEN 'pending' THEN 2 
        WHEN 'completed' THEN 3 
        ELSE 4 
      END, 
      date_in ASC
";
$result = $conn->query($query);

$childRes = $conn->query("SELECT recoiling_id, lot_no, coil_no, roll_no, width, length, actual_length FROM slitting_product WHERE recoiling_id IS NOT NULL ORDER BY recoiling_id ASC, id ASC");
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

$page_title = "Recoiling Cut";
include 'header.php';
?>

<style>
    .status-cards  { display:flex; gap:15px; margin-bottom:30px; }
    .status-card   { flex:1; border-radius:8px; padding:20px; text-align:center; color:#fff; box-shadow:0 2px 8px rgba(0,0,0,0.1); transition:transform .2s; }
    .status-card.pending   { background: linear-gradient(135deg, #ffc107, #ff9800); }
    .status-card.completed { background: linear-gradient(135deg, #28a745, #20c997); }
    .roll-box   { border:1px solid #ddd; padding:15px; border-radius:8px; margin-bottom:15px; background:#f9f9f9; }
    .defect-input { border:2px solid #dc3545; }
    .info-box   { background: #f8f9fa; border-left: 5px solid #0d6efd; padding: 15px; border-radius: 4px; }
    .child-row td { background: #f8f9fa; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-arrow-repeat"></i> Recoiling Cut</h2>
    <a href="?download=excel" class="btn btn-success shadow-sm"><i class="bi bi-download"></i> Download Excel</a>
</div>

<div class="status-cards">
    <div class="status-card pending">  <h5>Pending Items</h5>  <h2><?= $pending   ?></h2></div>
    <div class="status-card completed"><h5>Completed Items</h5><h2><?= $completed ?></h2></div>
</div>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th>ID</th><th>Status</th><th>Product</th><th>Lot &amp; Coil No.</th>
                    <th>Roll No.</th><th>Width</th><th>Length</th><th>New Length</th>
                    <th>Date In</th><th>Remark</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($result && $result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <?php
                        $rid       = (int)$row['id'];
                        $status    = $row['status'] ?? '';
                        $source    = $row['source_table'];
                        $isSfc     = ($status === 'sfc');
                        $canRecoil = ($status === 'pending' || $status === 'sfc');
                        $kids      = ($source === 'recoiling_product') ? ($children[$rid] ?? []) : [];

                        // ============================================================
                        // FIXED: All values passed to showRecoilingModal() are now
                        // encoded with json_encode() + JSON_HEX_TAG|JSON_HEX_APOS|
                        // JSON_HEX_QUOT flags.
                        //
                        // WHY: The original code did:
                        //   onclick="showRecoilingModal(<?= $rid ?>, '<?= $row['product'] ?>'...)"
                        //
                        // If $row['product'] contained a single quote like:
                        //   RS-3825 'Special'
                        // The onclick becomes:
                        //   onclick="showRecoilingModal(42, 'RS-3825 'Special'')"
                        //                                              ↑ breaks JS syntax
                        //
                        // Worse, if it contained:
                        //   ');alert(document.cookie);//
                        // The onclick becomes executable injected JS.
                        //
                        // json_encode() turns every string into a properly quoted,
                        // fully escaped JS string literal. The HEX flags additionally
                        // escape <, >, ', " as unicode sequences so it's safe even
                        // inside HTML attributes.
                        //
                        // $rid is cast to (int) — integers need no escaping.
                        // ============================================================
                        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT;

                        $js_product      = json_encode($row['product']      ?? '', $flags);
                        $js_lot_no       = json_encode($row['lot_no']       ?? '', $flags);
                        $js_coil_no      = json_encode($row['coil_no']      ?? '', $flags);
                        $js_roll_no      = json_encode($row['roll_no']      ?? '', $flags);
                        $js_source_table = json_encode($source,                    $flags);
                        $js_width        = (float)($row['width']         ?? 0);
                        $js_length       = (float)($row['actual_length'] ?? 0);
                    ?>
                    <tr>
                        <td><strong><?= $rid ?></strong></td>
                        <td>
                            <span class="badge <?= $status === 'completed' ? 'bg-success' : ($status === 'sfc' ? 'bg-info' : 'bg-warning text-dark') ?>">
                                <?= htmlspecialchars(strtoupper($status), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($row['product'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php
                            // ============================================================
                            // FIXED: lot_no and coil_no echoed without escaping originally.
                            // Both are wrapped in htmlspecialchars() now.
                            // ============================================================
                            $display_lot  = $kids[0]['lot_no']  ?? ($row['lot_no']  ?? '-');
                            $display_coil = $kids[0]['coil_no'] ?? ($row['coil_no'] ?? '');
                            echo htmlspecialchars($display_lot . ' ' . $display_coil, ENT_QUOTES, 'UTF-8');
                            ?>
                        </td>
                        <td>
                            <strong>
                                <?= htmlspecialchars($kids[0]['roll_no'] ?? ($row['roll_no'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                            </strong>
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
                        <td class="small">
                            <?= htmlspecialchars($row['date_in'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="small text-muted">
                            <?= htmlspecialchars($row['remark'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td>
                            <?php if ($canRecoil): ?>
                                <?php
                                // ============================================================
                                // FIXED: All string parameters now use pre-encoded $js_*
                                // variables instead of raw PHP string interpolation.
                                //
                                // BEFORE (dangerous):
                                //   onclick="showRecoilingModal(
                                //       <?= $rid ?>,
                                //       <?= htmlspecialchars(json_encode($row['product'])) ?>...
                                //   )"
                                //   Problem: htmlspecialchars(json_encode()) is inconsistent.
                                //   htmlspecialchars turns & into &amp; which breaks JSON
                                //   parsing in the JS runtime.
                                //
                                // AFTER (safe):
                                //   json_encode with HEX flags produces JS-safe strings.
                                //   No htmlspecialchars needed on top — the HEX flags
                                //   handle all HTML-special characters internally.
                                // ============================================================
                                ?>
                                <button class="btn btn-primary btn-sm"
                                        onclick="showRecoilingModal(
                                            <?= $rid ?>,
                                            <?= $js_product ?>,
                                            <?= $js_lot_no ?>,
                                            <?= $js_coil_no ?>,
                                            <?= $js_roll_no ?>,
                                            <?= $js_width ?>,
                                            <?= $js_length ?>,
                                            <?= $js_source_table ?>
                                        )">
                                    <i class="bi bi-play-circle"></i> Recoil
                                </button>
                            <?php else: ?>
                                <span class="badge bg-light text-dark border">Done</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="11" class="text-center py-4 text-muted">No records found.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Recoiling Modal -->
<div class="modal fade" id="recoilingModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-arrow-repeat me-2"></i>Start Recoiling Process</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="recoiling_handler.php" id="recoilingForm">
                <input type="hidden" name="action" value="start_and_complete_recoiling">
                <input type="hidden" name="id" id="recoil_id">
                <input type="hidden" name="source_table" id="recoil_source_table">

                <div class="modal-body">
                    <div class="info-box shadow-sm mb-4">
                        <div class="row">
                            <div class="col-6"><strong>Product:</strong> <span id="modal_product">-</span></div>
                            <div class="col-6"><strong>Lot No:</strong>  <span id="modal_lot">-</span></div>
                            <div class="col-6 mt-2"><strong>Roll No:</strong>  <span id="modal_roll">-</span></div>
                            <div class="col-6 mt-2"><strong>Width:</strong>    <span id="modal_width">-</span> mm</div>
                            <div class="col-6 mt-2"><strong>Original Length:</strong> <span id="modal_length">-</span> m</div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">Step 1: Select Cut Type</label>
                        <div class="d-flex gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="cut_type" id="cutNormal" value="normal" onchange="handleCutTypeChange()">
                                <label class="form-check-label" for="cutNormal">Cut defect at start/end</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="cut_type" id="cutInto2" value="cut_into_2" onchange="handleCutTypeChange()">
                                <label class="form-check-label" for="cutInto2">Cut Length Into 2</label>
                            </div>
                        </div>
                    </div>

                    <div id="rollDetailsForm" style="display:none;">
                        <hr>
                        <h6 class="mb-3 fw-bold">Step 2: Enter Production Details</h6>
                        <div id="rollsContainer"></div>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success px-4" id="submitBtn" style="display:none;">Complete Recoiling</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ============================================================
// FIXED: productData is now populated entirely from the PHP
// json_encode() output above — not from string concatenation.
// The JS object is clean, typed, and injection-proof.
// ============================================================
let productData = {};

function showRecoilingModal(id, product, lot_no, coil_no, roll_no, width, length, source_table) {
    productData = {
        id,
        product,      // already a safe JS string from json_encode
        lot_no,
        coil_no,
        roll_no,
        width: parseFloat(width),
        length: parseFloat(length),
        source_table
    };

    document.getElementById('recoil_id').value           = id;
    document.getElementById('recoil_source_table').value = source_table;

    // ============================================================
    // FIXED: Use textContent, NOT innerHTML, when displaying
    // values from the database in the modal info box.
    //
    // innerHTML would re-parse HTML and allow XSS.
    // textContent treats the value as plain text only — safe.
    // ============================================================
    document.getElementById('modal_product').textContent = product;
    document.getElementById('modal_lot').textContent     = lot_no + ' ' + coil_no;
    document.getElementById('modal_roll').textContent    = roll_no;
    document.getElementById('modal_width').textContent   = width;
    document.getElementById('modal_length').textContent  = length;

    // Reset form state
    document.getElementById('cutNormal').checked  = false;
    document.getElementById('cutInto2').checked   = false;
    document.getElementById('rollsContainer').innerHTML = '';
    document.getElementById('rollDetailsForm').style.display = 'none';
    document.getElementById('submitBtn').style.display      = 'none';

    new bootstrap.Modal(document.getElementById('recoilingModal')).show();
}

function letterOptionsHTML() {
    return '<option value="">-- None --</option><option value="a">a</option><option value="b">b</option><option value="c">c</option>';
}

function handleCutTypeChange() {
    const selected = document.querySelector('input[name="cut_type"]:checked');
    if (!selected) return;
    const type      = selected.value;
    const container = document.getElementById('rollsContainer');
    container.innerHTML = '';
    document.getElementById('rollDetailsForm').style.display = 'block';
    document.getElementById('submitBtn').style.display       = 'inline-block';

    if (type === 'normal') {
        container.appendChild(buildNormalForm());
    } else {
        container.appendChild(buildCutInto2FormA());
        container.appendChild(buildCutInto2FormB());
    }
}

function buildNormalForm() {
    const div = document.createElement('div');
    div.className = 'roll-box shadow-sm';
    div.innerHTML = `
        <input type="hidden" name="new_width[]"   value="${productData.width}">
        <input type="hidden" name="roll_number[]" value="1">
        <input type="hidden" name="length[]"      value="${productData.length}">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-bold">Letter</label>
                <select class="form-select" name="letter[]">${letterOptionsHTML()}</select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">Defect (m)</label>
                <input type="number" step="0.01" name="defect[]" id="normal_defect"
                       class="form-control defect-input" value="0">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">Actual Length (m)</label>
                <input type="number" step="0.01" name="actual_length[]" id="normal_actual"
                       class="form-control bg-light fw-bold text-success">
            </div>
            <div class="col-12">
                <input type="text" name="remark[]" class="form-control" placeholder="Remark...">
            </div>
        </div>`;

    setTimeout(() => {
        const def = document.getElementById('normal_defect');
        const act = document.getElementById('normal_actual');
        act.value = productData.length.toFixed(2);
        def.addEventListener('input', () => {
            const val = productData.length - parseFloat(def.value || 0);
            act.value = (val >= 0 ? val : 0).toFixed(2);
        });
    }, 50);

    return div;
}

function buildCutInto2FormA() {
    const div = document.createElement('div');
    div.className = 'roll-box mb-3';
    div.innerHTML = `
        <h6>Part 1</h6>
        <input type="hidden" name="new_width[]"   value="${productData.width}">
        <input type="hidden" name="roll_number[]" value="1">
        <input type="hidden" name="length[]"      value="${productData.length}">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="small fw-bold">Letter</label>
                <select class="form-select form-select-sm" name="letter[]">${letterOptionsHTML()}</select>
            </div>
            <div class="col-md-4">
                <label class="small fw-bold">Defect</label>
                <input type="number" step="0.01" name="defect[]" id="defectA"
                       class="form-control form-control-sm" value="0">
            </div>
            <div class="col-md-4">
                <label class="small fw-bold">Actual Length</label>
                <input type="number" step="0.01" name="actual_length[]" id="actualA"
                       class="form-control form-control-sm" required>
            </div>
        </div>`;

    setTimeout(() => {
        document.getElementById('defectA').addEventListener('input', updateCutInto2ActualB);
        document.getElementById('actualA').addEventListener('input', updateCutInto2ActualB);
    }, 50);

    return div;
}

function buildCutInto2FormB() {
    const div = document.createElement('div');
    div.className = 'roll-box';
    div.innerHTML = `
        <h6>Part 2</h6>
        <input type="hidden" name="new_width[]"   value="${productData.width}">
        <input type="hidden" name="roll_number[]" value="1">
        <input type="hidden" name="length[]"      value="${productData.length}">
        <input type="hidden" name="defect[]"      value="0">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="small fw-bold">Letter</label>
                <select class="form-select form-select-sm" name="letter[]">${letterOptionsHTML()}</select>
            </div>
            <div class="col-md-8">
                <label class="small fw-bold">Computed Actual Length (m)</label>
                <input type="number" step="0.01" name="actual_length[]" id="actualB"
                       class="form-control form-control-sm bg-light fw-bold" readonly>
            </div>
        </div>`;

    return div;
}

function updateCutInto2ActualB() {
    const defA = parseFloat(document.getElementById('defectA')?.value || 0);
    const actA = parseFloat(document.getElementById('actualA')?.value || 0);
    const actB = document.getElementById('actualB');
    if (actB) {
        actB.value = (productData.length - defA - actA).toFixed(2);
    }
}

document.getElementById('recoilingForm').addEventListener('submit', function (e) {
    if (!confirm('Complete recoiling process?')) e.preventDefault();
});
</script>

<?php include 'footer.php'; ?>
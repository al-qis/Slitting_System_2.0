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

// ── Excel Download ────────────────────────────────────────────
if (isset($_GET['download']) && $_GET['download'] === 'excel') {
    $exRes = $conn->query("
        SELECT
            r.id, r.status, r.cut_type, r.product, r.lot_no, r.roll_no,
            r.width, r.length, r.actual_length, r.date_in, r.completed_at,
            COALESCE(NULLIF(s.actual_length, 0), r.length) AS effective_length
        FROM reslit_product r
        LEFT JOIN (
            SELECT sp1.*
            FROM slitting_product sp1
            INNER JOIN (
                SELECT lot_no, roll_no, MAX(id) AS max_id
                FROM slitting_product
                GROUP BY lot_no, roll_no
            ) sp2 ON sp1.id = sp2.max_id
        ) s ON s.lot_no = r.lot_no AND s.roll_no = r.roll_no
        ORDER BY r.id ASC
    ");

    $filename = 'Reslit_Report_' . date('Y-m-d') . '.xls';
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $cols      = 12; // total columns
    $generated = date('d M Y, H:i');
?>
<html><head><meta charset="UTF-8"></head><body>

<table>
    <!-- Title block -->
    <tr>
        <td colspan="<?= $cols ?>" style="background:#1e3a5f;color:#fff;font-size:18px;font-weight:bold;padding:12px 16px;letter-spacing:1px;">
            RESLIT PRODUCT REPORT
        </td>
    </tr>
    <tr>
        <td colspan="<?= $cols ?>" style="background:#2c5282;color:#bee3f8;font-size:11px;padding:4px 16px;">
            Generated: <?= $generated ?> &nbsp;|&nbsp; System: Slitting Management
        </td>
    </tr>
    <tr><td colspan="<?= $cols ?>" style="background:#e2e8f0;padding:6px 16px;font-size:11px;color:#4a5568;">
        <strong>Note:</strong> Child rolls (↳) are shown indented under their parent reslit record.
    </td></tr>
    <tr><td colspan="<?= $cols ?>"></td></tr><!-- spacer -->
</table>

<table border="1" style="border-collapse:collapse;">
    <thead>
        <tr style="background:#343a40;color:#fff;font-weight:bold;font-size:12px;">
            <th style="padding:8px 10px;">ID</th>
            <th style="padding:8px 10px;">Status</th>
            <th style="padding:8px 10px;">Cut Type</th>
            <th style="padding:8px 10px;">Product</th>
            <th style="padding:8px 10px;">Lot No.</th>
            <th style="padding:8px 10px;">Roll No.</th>
            <th style="padding:8px 10px;">Width (mm)</th>
            <th style="padding:8px 10px;">Length (m)</th>
            <th style="padding:8px 10px;">Effective Length (m)</th>
            <th style="padding:8px 10px;">Actual Length (m)</th>
            <th style="padding:8px 10px;">Date In</th>
            <th style="padding:8px 10px;">Completed At</th>
        </tr>
    </thead>
    <tbody>
<?php

    $td   = 'style="padding:6px 10px;"';
    $tdN  = 'style="padding:6px 10px;text-align:right;"';

    if ($exRes && $exRes->num_rows > 0) {
        while ($row = $exRes->fetch_assoc()) {
            // Zebra: white rows for parents
            echo '<tr style="background:#ffffff;">';
            echo '<td ' . $td  . '>' . (int)$row['id'] . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars(strtoupper($row['status']   ?? '-')) . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars(strtoupper($row['cut_type'] ?? '-')) . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($row['product']  ?? '-') . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($row['lot_no']   ?? '-') . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($row['roll_no']  ?? '-') . '</td>';
            echo '<td ' . $tdN . '>' . number_format((float)($row['width']            ?? 0)) . '</td>';
            echo '<td ' . $tdN . '>' . number_format((float)($row['length']           ?? 0)) . '</td>';
            echo '<td ' . $tdN . '>' . number_format((float)($row['effective_length'] ?? 0)) . '</td>';
            echo '<td ' . $tdN . '>' . number_format((float)($row['actual_length']    ?? 0)) . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($row['date_in']      ?? '-') . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($row['completed_at'] ?? '-') . '</td>';
            echo '</tr>';

            // Child rolls from reslit_rolls — light blue indent rows
            $rolls = $conn->query("SELECT * FROM reslit_rolls WHERE parent_id = " . (int)$row['id'] . " ORDER BY id ASC");
            if ($rolls && $rolls->num_rows > 0) {
                while ($roll = $rolls->fetch_assoc()) {
                    $lotDisplay = htmlspecialchars($row['lot_no'] ?? '-') . htmlspecialchars($roll['cut_letter'] ?? '');
                    $tdC  = 'style="background:#ebf8ff;padding:5px 10px;color:#2c5282;"';
                    $tdCN = 'style="background:#ebf8ff;padding:5px 10px;color:#2c5282;text-align:right;"';
                    echo '<tr>';
                    echo '<td ' . $tdC  . '>↳</td>';
                    echo '<td ' . $tdC  . '>COMPLETED</td>';
                    echo '<td ' . $tdC  . '>-</td>';
                    echo '<td ' . $tdC  . '>' . htmlspecialchars($row['product'] ?? '-') . '</td>';
                    echo '<td ' . $tdC  . '>' . $lotDisplay . '</td>';
                    echo '<td ' . $tdC  . '>' . htmlspecialchars($roll['roll_no'] ?? '-') . '</td>';
                    echo '<td ' . $tdCN . '>' . number_format((float)($roll['new_width']     ?? 0)) . '</td>';
                    echo '<td ' . $tdCN . '>' . number_format((float)($roll['length']        ?? 0)) . '</td>';
                    echo '<td ' . $tdCN . '>-</td>';
                    echo '<td ' . $tdCN . '>' . number_format((float)($roll['actual_length'] ?? 0)) . '</td>';
                    echo '<td ' . $tdC  . '>-</td>';
                    echo '<td ' . $tdC  . '>-</td>';
                    echo '</tr>';
                }
            }
        }
    }

    ?></tbody></table></body></html><?php
    exit;
}

// 2. Fetch Data with Effective Length Logic
$result = $conn->query("
    SELECT 
        r.*,
        COALESCE(NULLIF(s.actual_length, 0), r.length) AS effective_length
    FROM reslit_product r
    LEFT JOIN (
        SELECT sp1.*
        FROM slitting_product sp1
        INNER JOIN (
            SELECT lot_no, coil_no, roll_no, MAX(id) AS max_id
            FROM slitting_product
            GROUP BY lot_no, coil_no, roll_no
        ) sp2
          ON sp1.id = sp2.max_id
    ) s
      ON s.lot_no = r.lot_no
     AND s.coil_no = r.coil_no
     AND s.roll_no = r.roll_no
    ORDER BY r.id ASC
");

// 3. Set Page Title and Include Header
$page_title = "Reslit Product Management";
include 'header.php';
?>

<style>
    .status-cards { display: flex; gap: 15px; margin-bottom: 30px; }
    .status-card { flex: 1; border-radius: 8px; padding: 20px; text-align: center; color: white; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    .status-card.pending { background: linear-gradient(135deg, #ffc107, #ff9800); }
    .status-card.completed { background: linear-gradient(135deg, #28a745, #20c997); }
    
    .info-box { background: #f0f9ff; border-left: 4px solid #0d6efd; border-radius: 6px; padding: 15px; margin-bottom: 20px; }
    .slitting-box { border: 1px solid #ddd; padding: 15px; border-radius: 8px; margin-bottom: 15px; background: #f9f9f9; }
    .highlight-field { background: #fff3cd !important; border: 2px solid #ffc107 !important; }
    .child-row-bg { background-color: #f0f9ff; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-intersect me-2"></i>Reslit Product Management</h2>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-info shadow-sm text-white" data-bs-toggle="modal" data-bs-target="#summaryModal">
            <i class="bi bi-bar-chart-line me-1"></i> Summary Report
        </button>
        <a href="?download=excel" class="btn btn-success shadow-sm">
            <i class="bi bi-download me-1"></i> Download Excel
        </a>
    </div>
</div>

<?php if(isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm mb-4">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php 
            if($_GET['success'] === 'added') echo "Product successfully added to reslit list!";
            elseif($_GET['success'] === 'started') echo "Reslit process started!";
            elseif($_GET['success'] === 'completed') echo "Reslit completed! Product added to stock.";
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php
$pending = $conn->query("SELECT COUNT(*) as count FROM reslit_product WHERE status='pending'")->fetch_assoc()['count'];
$completed = $conn->query("SELECT COUNT(*) as count FROM reslit_product WHERE status='completed'")->fetch_assoc()['count'];
?>

<div class="status-cards">
    <div class="status-card pending">
        <h5>Pending Reslit</h5>
        <h2><?= $pending ?></h2>
    </div>
    <div class="status-card completed">
        <h5>Completed</h5>
        <h2><?= $completed ?></h2>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle text-center mb-0">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Status</th>
                    <th>Product</th>
                    <th>Lot & Coil No.</th>
                    <th>Roll No.</th>
                    <th>Width</th>
                    <th>Length</th>
                    <th>Actual Length</th>
                    <th>Date In</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?= $row['id'] ?></strong></td>
                            <td>
                                <?php if($row['status'] === 'pending'): ?>
                                    <span class="badge bg-warning text-dark">PENDING</span>
                                <?php else: ?>
                                    <span class="badge bg-success">COMPLETED</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($row['product'] ?? '-') ?></span></td>
                            <td><?= htmlspecialchars($row['lot_no'] ?? '-') . ' ' . htmlspecialchars($row['coil_no'] ?? '') ?></td>
                            <td><strong><?= htmlspecialchars($row['roll_no'] ?? '-') ?></strong></td>
                            <td><?= isset($row['width']) ? number_format($row['width']) : '-' ?></td>
                            <td><?= isset($row['effective_length']) ? number_format($row['effective_length']) : '-' ?></td>
                            <td class="text-success fw-bold"><?= isset($row['actual_length']) ? number_format($row['actual_length']) : '-' ?></td>
                            <td class="small"><?= htmlspecialchars($row['date_in'] ?? '-') ?></td>
                            <td>
                                <?php if ($row['status'] === 'pending'): ?>
                                    <button class="btn btn-primary btn-sm mb-1" 
                                            onclick="showReslitModal(<?= (int)$row['id'] ?>, '<?= htmlspecialchars($row['product'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($row['lot_no'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($row['coil_no'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($row['roll_no'] ?? '', ENT_QUOTES) ?>', <?= (float)($row['width'] ?? 0) ?>, <?= (float)($row['effective_length'] ?? ($row['length'] ?? 0)) ?>)">
                                        <i class="bi bi-play-circle"></i> Reslit
                                    </button>
                                    <a href="edit_reslit.php?id=<?= $row['id'] ?>" class="btn btn-warning btn-sm mb-1"><i class="bi bi-pencil"></i></a>
                                <?php else: ?>
                                    <span class="badge bg-light text-dark border">Done</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <?php
                        $rolls = $conn->query("SELECT * FROM reslit_rolls WHERE parent_id = {$row['id']} ORDER BY id ASC");
                        if ($rolls && $rolls->num_rows > 0):
                            while ($roll = $rolls->fetch_assoc()):
                        ?>
                        <tr class="child-row-bg">
                            <td class="small text-muted">↳ R<?= $roll['id'] ?></td>
                            <td><span class="badge bg-success">COMPLETED</span></td>
                            <td><?= htmlspecialchars($row['product'] ?? '-') ?></td>
                            <td class="small">
                                <?= htmlspecialchars($row['lot_no'] ?? '-') ?><?= $roll['cut_letter'] ? htmlspecialchars($roll['cut_letter']) : '' ?> <?= htmlspecialchars($row['coil_no'] ?? '') ?>
                            </td>
                            <td><strong><?= htmlspecialchars($roll['roll_no'] ?? '-') ?></strong></td>
                            <td><?= isset($roll['new_width']) ? number_format($roll['new_width']) : '-' ?></td>
                            <td><?= isset($roll['length']) ? number_format($roll['length']) : '-' ?></td>
                            <td class="text-success fw-bold"><?= isset($roll['actual_length']) ? number_format($roll['actual_length']) : '-' ?></td>
                            <td>-</td>
                            <td><span class="badge bg-light text-dark border small">Done</span></td>
                        </tr>
                        <?php endwhile; endif; ?>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="10" class="py-5 text-muted">No reslit records found.</td></tr>
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
                <div>
                    <h5 class="modal-title mb-0">
                        <i class="bi bi-bar-chart-line me-2"></i>Reslit Summary Report
                    </h5>
                    <small class="opacity-75">Reslit activity by cut type and period</small>
                </div>
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
                    <div class="col-6">
                        <div class="card border-0 shadow-sm text-center p-3"
                             style="background:linear-gradient(135deg,#dc3545,#ff7043);color:#fff;">
                            <div class="fw-bold small mb-1"><i class="bi bi-scissors me-1"></i>Normal Reslit</div>
                            <div class="fs-2 fw-bold" id="kpi_normal">—</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="card border-0 shadow-sm text-center p-3"
                             style="background:linear-gradient(135deg,#fd7e14,#ffc107);color:#fff;">
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
                                <th>Roll No.</th>
                                <th>Product</th>
                                <th class="text-end">Length (m)</th>
                                <th>Output Rolls</th>
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

<!-- ═══ RESLIT MODAL ═══════════════════════════════════════════ -->
<div class="modal fade" id="reslitModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-scissors me-2"></i>Start Reslit Process</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="reslit_handler.php" id="reslitForm">
                <input type="hidden" name="action" value="complete_reslit_direct">
                <input type="hidden" name="id" id="reslit_id">
                <input type="hidden" name="cut_type" id="cut_type_value">
                
                <div class="modal-body p-4">
                    <div class="info-box shadow-sm mb-4">
                        <div class="row g-2">
                            <div class="col-6"><strong>Product:</strong> <span id="modal_product">-</span></div>
                            <div class="col-6"><strong>Lot No:</strong> <span id="modal_lot">-</span></div>
                            <div class="col-6"><strong>Roll No:</strong> <span id="modal_roll">-</span></div>
                            <div class="col-6"><strong>Width:</strong> <span id="modal_width">-</span> mm</div>
                            <div class="col-12"><strong>Length:</strong> <span id="modal_length">-</span> mtr</div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <h6 class="fw-bold">Step 1: Select Cut Type</h6>
                        <div class="d-flex gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="cut_type" id="cutNormal" value="normal" onchange="handleCutTypeChange()">
                                <label class="form-check-label fw-bold" for="cutNormal">Normal</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="cut_type" id="cutInto2" value="cut_into_2" onchange="handleCutTypeChange()">
                                <label class="form-check-label fw-bold" for="cutInto2">Cut Into 2</label>
                            </div>
                        </div>
                    </div>
                    
                    <div id="rollCountSection" style="display:none;" class="mb-4">
                        <hr>
                        <h6 class="fw-bold mb-3">Step 2: Number of Rolls</h6>
                        <select name="total" id="total" class="form-select w-auto" onchange="generateForm()">
                            <option value="">-- Select --</option>
                            <?php for($i=1;$i<=10;$i++): ?><option value="<?= $i ?>"><?= $i ?> Roll<?= $i>1?'s':'' ?></option><?php endfor; ?>
                        </select>
                    </div>
                    
                    <div id="slittingForm"></div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success px-4 fw-bold shadow-sm" id="submitBtn" style="display:none;">
                        <i class="bi bi-check-circle me-1"></i> Complete Reslit
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let productData = {};

function showReslitModal(id, product, lot_no, coil_no, roll_no, width, length) {
    productData = { id, product, lot_no, coil_no, roll_no, width, length };
    document.getElementById('reslit_id').value = id;
    document.getElementById('modal_product').textContent = product;
    document.getElementById('modal_lot').textContent = lot_no + ' ' + coil_no;
    document.getElementById('modal_roll').textContent = roll_no;
    document.getElementById('modal_width').textContent = width;
    document.getElementById('modal_length').textContent = length;

    document.getElementById('reslitForm').reset();
    document.getElementById('rollCountSection').style.display = 'none';
    document.getElementById('slittingForm').innerHTML = '';
    document.getElementById('submitBtn').style.display = 'none';
    new bootstrap.Modal(document.getElementById('reslitModal')).show();
}

function handleCutTypeChange() {
    const cutType = document.querySelector('input[name="cut_type"]:checked')?.value;
    document.getElementById('cut_type_value').value = cutType || '';
    document.getElementById('slittingForm').innerHTML = '';
    document.getElementById('submitBtn').style.display = 'none';
    document.getElementById('total').value = '';
    
    if (cutType === 'cut_into_2') {
        document.getElementById('rollCountSection').style.display = 'none';
        document.getElementById('total').value = '2';
        generateForm();
    } else if (cutType === 'normal') {
        document.getElementById('rollCountSection').style.display = 'block';
    }
}

function generateForm() {
    let total = parseInt(document.getElementById('total').value);
    let container = document.getElementById('slittingForm');
    let submitBtn = document.getElementById('submitBtn');
    container.innerHTML = "";

    if (!total || total <= 0) { submitBtn.style.display = 'none'; return; }

    let formHTML = '<hr><h6 class="mb-3 fw-bold text-primary">Step 3: Roll Details</h6>';
    for (let i = 1; i <= total; i++) {
        formHTML += `
            <div class="slitting-box shadow-sm">
                <h6 class="fw-bold mb-3 border-bottom pb-2">Roll ${i}</h6>
                <input type="hidden" name="roll_number[]" value="R${i}">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Cut Letter</label>
                        <select name="cut_letter[]" class="form-select form-select-sm" onchange="updateLotDisplay(this, ${i - 1})">
                            <option value="">-- None --</option><option value="a">a</option><option value="b">b</option><option value="c">c</option><option value="d">d</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-danger">New Width (mm)</label>
                        <input type="number" step="0.01" name="new_width[]" class="form-control form-select-sm highlight-field" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Nominal Length</label>
                        <input type="number" step="0.01" name="length[]" class="form-control form-select-sm" value="${productData.length}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-success">Actual Length (mtr)</label>
                        <input type="number" step="0.01" name="actual_length[]" class="form-control form-select-sm highlight-field" required>
                    </div>
                </div>
                <div class="mt-2 p-2 bg-light rounded small" id="lotDisplay${i - 1}">
                    <strong>Ref:</strong> ${productData.lot_no} ${productData.coil_no} R${i} | ${productData.width}mm
                </div>
            </div>`;
    }
    container.innerHTML = formHTML;
    submitBtn.style.display = 'inline-block';
}

function updateLotDisplay(select, rollNum) {
    const cutLetter = select.value;
    const displayDiv = document.getElementById('lotDisplay' + rollNum);
    displayDiv.innerHTML = `<strong>Ref:</strong> ${productData.lot_no}${cutLetter} ${productData.coil_no} R${rollNum + 1} | ${productData.width}mm`;
}

document.getElementById('reslitForm').addEventListener('submit', function(e) {
    if (!confirm('Complete reslit process now? Product will be added back to stock.')) e.preventDefault();
});

// ── Summary Report ─────────────────────────────────────────────
function clearSummaryFilter() {
    document.getElementById('summaryMonthFilter').value = '';
    loadSummary();
}

function loadSummary() {
    const month = document.getElementById('summaryMonthFilter').value;
    document.getElementById('summaryRange').textContent = month ? 'Showing: ' + month : 'Showing: All time';

    let url = 'reslit_summary_ajax.php';
    if (month) url += '?month=' + encodeURIComponent(month);

    fetch(url)
        .then(r => r.json())
        .then(data => {
            document.getElementById('kpi_normal').textContent = data.counts?.normal     ?? 0;
            document.getElementById('kpi_cut2').textContent   = data.counts?.cut_into_2 ?? 0;

            const tbody = document.getElementById('summaryTableBody');
            if (!data.rows || data.rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">No records for this period.</td></tr>';
                return;
            }

            const typeLabel = { normal: '✂️ Normal', cut_into_2: '✂️✂️ Cut Into 2' };
            const typeCls   = { normal: 'bg-danger',  cut_into_2: 'bg-warning text-dark' };

            tbody.innerHTML = data.rows.map(r => {
                // Build output rolls summary
                let rollsHtml = '-';
                if (r.rolls && r.rolls.length > 0) {
                    rollsHtml = r.rolls.map(roll => {
                        const lotSuffix = roll.cut_letter ? `<strong>${escHtml(roll.cut_letter)}</strong>` : '';
                        return `<span class="badge bg-light text-dark border me-1">
                                    ${escHtml(roll.roll_no)}${lotSuffix}
                                    <span class="text-success">${parseFloat(roll.actual_length || 0).toLocaleString()}m</span>
                                    / ${parseFloat(roll.new_width || 0).toLocaleString()}mm
                                </span>`;
                    }).join('');
                }

                return `
                <tr>
                    <td class="small">${escHtml(r.completed_at ?? '-')}</td>
                    <td><span class="badge ${typeCls[r.cut_type] ?? 'bg-secondary'}">${typeLabel[r.cut_type] ?? escHtml(r.cut_type)}</span></td>
                    <td class="font-monospace small">${escHtml(r.lot_no ?? '-')}</td>
                    <td class="small">${escHtml(r.roll_no ?? '-')}</td>
                    <td class="small">${escHtml(r.product ?? '-')}</td>
                    <td class="text-end small">${parseFloat(r.actual_length || 0).toLocaleString()}</td>
                    <td class="small">${rollsHtml}</td>
                </tr>`;
            }).join('');
        })
        .catch(() => {
            document.getElementById('summaryTableBody').innerHTML =
                '<tr><td colspan="8" class="text-danger text-center">Failed to load. Check reslit_summary_ajax.php exists.</td></tr>';
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
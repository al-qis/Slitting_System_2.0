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

// 2. Filter Logic (Month & Year)
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');

if ($month < 1 || $month > 12) { $month = (int)date('m'); }
if ($year < 2020 || $year > 2030) { $year = (int)date('Y'); }

// 3. Summary Queries (Using new unified mother_coil_audit_log)
$in = $conn->query("SELECT COUNT(*) AS total FROM mother_coil_audit_log 
                    WHERE action_type IN ('SCAN_IN', 'IN') AND MONTH(performed_at)=$month AND YEAR(performed_at)=$year")
                    ->fetch_assoc()['total'];

$out = $conn->query("SELECT COUNT(*) AS total FROM mother_coil_audit_log 
                     WHERE action_type IN ('SCAN_OUT', 'OUT') AND MONTH(performed_at)=$month AND YEAR(performed_at)=$year")
                     ->fetch_assoc()['total'];

// Current Stock (where stock = 1, meaning IN)
$stock_query = $conn->query("SELECT COUNT(*) AS total FROM mother_coil WHERE stock = 1");
$stock = $stock_query->fetch_assoc()['total'];

// After Cut Stock (Leftovers from previous slitting)
$afterCutStock = $conn->query("SELECT COUNT(*) AS total FROM raw_material_log WHERE status='IN' AND action='cut_into_2'")
                               ->fetch_assoc()['total'];

// Get total scan counts for period
$total_scan_in = $conn->query("SELECT COALESCE(SUM(scan_in_count), 0) AS total FROM mother_coil")->fetch_assoc()['total'];
$total_scan_out = $conn->query("SELECT COALESCE(SUM(scan_out_count), 0) AS total FROM mother_coil")->fetch_assoc()['total'];

// 4. Main Data Query (Using JOIN to get specs from master mother_coil table)
$query = "SELECT log.*, mc.product, mc.grade, mc.coil_no, mc.lot_no, mc.width, mc.length, mc.stock, mc.scan_in_count, mc.scan_out_count
          FROM raw_material_log log
          JOIN mother_coil mc ON log.mother_id = mc.id
          WHERE (MONTH(log.date_in)=$month AND YEAR(log.date_in)=$year) 
             OR (MONTH(log.date_out)=$month AND YEAR(log.date_out)=$year) 
          ORDER BY log.id DESC";
$result = $conn->query($query);

$page_title = "Raw Material Inventory";
include 'header.php'; 
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-box-seam me-2"></i>Raw Material Inventory</h2>
    <div class="d-flex gap-2">
        <a href="raw_material_export.php?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-success shadow-sm">
            <i class="bi bi-file-earmark-excel me-1"></i> Download
        </a>
        <button type="button" class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#manualEntryModal">
            <i class="bi bi-pencil-square me-1"></i> Manual Entry
        </button>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-center">
            <div class="col-auto">
                <label class="small fw-bold text-muted">Month:</label>
                <select name="month" onchange="this.form.submit()" class="form-select form-select-sm w-auto d-inline-block ms-1">
                    <?php for($m=1;$m<=12;$m++): ?>
                        <option value="<?= $m ?>" <?= ($m==$month)?'selected':'' ?>>
                            <?= date("F", mktime(0,0,0,$m,1)) ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="small fw-bold text-muted">Year:</label>
                <select name="year" onchange="this.form.submit()" class="form-select form-select-sm w-auto d-inline-block ms-1">
                    <?php for($y=2024; $y<=2030; $y++): ?>
                        <option value="<?= $y ?>" <?= ($y==$year)?'selected':'' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<!-- Hidden form for scanner/manual entry submission -->
<form id="scanForm" method="post" action="scan_mother_action.php" style="position:absolute; left:-9999px;">
    <input id="qrInput" type="text" name="qr" autofocus>
</form>

<!-- Summary Cards with Tracking -->
<div class="row g-3 mb-4 text-center">
    <div class="col-md-2">
        <div class="card border-0 shadow-sm bg-white">
            <div class="card-body p-2">
                <h6 class="text-muted small mb-1">SCAN IN</h6>
                <h4 class="text-success fw-bold mb-0"><?= (int)$total_scan_in ?></h4>
                <small class="text-muted">Total Scans</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm bg-white">
            <div class="card-body p-2">
                <h6 class="text-muted small mb-1">SCAN OUT</h6>
                <h4 class="text-danger fw-bold mb-0"><?= (int)$total_scan_out ?></h4>
                <small class="text-muted">Total Scans</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm bg-white">
            <div class="card-body p-2">
                <h6 class="text-muted small mb-1">MONTHLY IN</h6>
                <h4 class="text-info fw-bold mb-0"><?= (int)$in ?></h4>
                <small class="text-muted">This Month</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm bg-white">
            <div class="card-body p-2">
                <h6 class="text-muted small mb-1">MONTHLY OUT</h6>
                <h4 class="text-warning fw-bold mb-0"><?= (int)$out ?></h4>
                <small class="text-muted">This Month</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm bg-primary text-white">
            <div class="card-body p-2">
                <h6 class="small mb-1">CURRENT STOCK</h6>
                <h4 class="fw-bold mb-0"><?= (int)$stock ?></h4>
                <small>In Warehouse</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm bg-warning text-dark">
            <div class="card-body p-2">
                <h6 class="small mb-1">AFTER CUT</h6>
                <h4 class="fw-bold mb-0"><?= (int)$afterCutStock ?></h4>
                <small>Leftover Stock</small>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white fw-bold py-3">
        <i class="bi bi-clock-history me-2"></i>Raw Material Log (Unified View)
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle text-center mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Product</th>
                    <th>Grade</th> 
                    <th>Lot No / Coil No</th> 
                    <th>Length (mtr)</th>
                    <th>Width (mm)</th>
                    <th>Scan In</th>
                    <th>Scan Out</th>
                    <th>Status</th>
                    <th>Date In</th>
                    <th>Date Out</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if($result && $result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()): 
                        $combinedLotCoil = trim(($row['lot_no'] ?? '-') . ' ' . ($row['coil_no'] ?? ''));
                        $statusBadge = $row['stock'] == 1 ? '<span class="badge bg-success">IN STOCK</span>' : '<span class="badge bg-secondary">OUT</span>';
                    ?>
                    <tr>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($row['product']) ?></span></td>
                        <td><span class="fw-bold text-primary"><?= htmlspecialchars($row['grade']) ?></span></td> 
                        <td class="fw-medium"><?= htmlspecialchars($combinedLotCoil) ?></td>
                        <td class="fw-bold"><?= number_format((float)$row['length']) ?></td>
                        <td><?= number_format((float)$row['width']) ?></td>
                        <td><span class="badge bg-info"><?= intval($row['scan_in_count']) ?></span></td>
                        <td><span class="badge bg-danger"><?= intval($row['scan_out_count']) ?></span></td>
                        <td><?= $statusBadge ?></td>
                        <td class="small"><?= $row['date_in'] ?? '-' ?></td>
                        <td class="small"><?= $row['date_out'] ?? '-' ?></td>
                        <td><span class="badge bg-info text-dark"><?= $row['action'] ?></span></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="11" class="py-4 text-muted">No records found for the selected period.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm border-0 mb-5">
    <div class="card-header bg-success text-white fw-bold py-3">
        <i class="bi bi-scissors me-2"></i>Available Stock After Cut
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle text-center mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Product</th>
                    <th>Grade</th> 
                    <th>Lot No / Coil No</th> 
                    <th>Length (mtr)</th>
                    <th>Width (mm)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $resultCut = $conn->query("SELECT log.*, mc.product, mc.grade, mc.coil_no, mc.lot_no, mc.width, mc.length 
                                           FROM raw_material_log log
                                           JOIN mother_coil mc ON log.mother_id = mc.id
                                           WHERE log.status='IN' AND log.action='cut_into_2' 
                                           ORDER BY log.id ASC");
                if($resultCut && $resultCut->num_rows > 0):
                    while($rowCut = $resultCut->fetch_assoc()): 
                        $combinedLotCoilCut = trim($rowCut['lot_no'] . ' ' . $rowCut['coil_no']);
                    ?>
                <tr>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($rowCut['product']) ?></span></td>
                    <td><span class="fw-bold text-primary"><?= htmlspecialchars($rowCut['grade']) ?></span></td>
                    <td class="fw-medium"><?= htmlspecialchars($combinedLotCoilCut) ?></td>
                    <td class="text-success fw-bold"><?= number_format((float)$rowCut['length']) ?></td>
                    <td><?= number_format((float)$rowCut['width']) ?></td>
                    <td>
                        <a href="add_slitting.php?stock_id=<?= $rowCut['id'] ?>" class="btn btn-primary btn-sm rounded-pill px-3">
                            USE <i class="bi bi-chevron-right small ms-1"></i>
                        </a>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="py-4 text-muted">No leftover stock from "Cut Into 2" process.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Manual Entry Modal -->
<div class="modal fade" id="manualEntryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Single-Box Manual Entry</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="manualEntryForm">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Enter Lot No & Coil No</label>
                        <input type="text" class="form-control form-control-lg" id="combined_input" 
                               placeholder="e.g., 826175 FK-1" required autofocus>
                        <div id="validationFeedback" class="invalid-feedback" style="display:none;">
                            Please enter both Lot No and Coil No separated by a space (e.g., 826175 FK-1).
                        </div>
                        <div class="form-text mt-2">
                            Type the <strong>Lot Number</strong>, then a <strong>space</strong>, then the <strong>Coil Number</strong>.
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary px-4" id="manualSubmitButton">Process Entry</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const qrInput = document.getElementById('qrInput');
    const scanForm = document.getElementById('scanForm');
    const combinedInput = document.getElementById('combined_input');
    const manualBtn = document.getElementById('manualSubmitButton');
    const feedback = document.getElementById('validationFeedback');
    const manualModal = document.getElementById('manualEntryModal');

    // ===== SCANNER LOGIC =====
    if(qrInput) {
        qrInput.addEventListener('keydown', function(e) {
            if(e.key === 'Enter') {
                e.preventDefault();
                if(this.value.trim() !== '') {
                    scanForm.submit();
                }
            }
        });

        // Keep scanner input focused (except when modal is open)
        document.addEventListener('click', function() {
            if(!manualModal.classList.contains('show')) {
                qrInput.focus();
            }
        });

        setInterval(() => {
            const el = document.activeElement;
            const isModalOpen = manualModal.classList.contains('show');
            
            if(!isModalOpen && !['INPUT','TEXTAREA','SELECT','BUTTON'].includes(el.tagName)) {
                qrInput.focus();
            }
        }, 500);
    }

    // ===== MANUAL ENTRY LOGIC =====
    if(manualBtn) {
        manualBtn.addEventListener('click', function() {
            const rawValue = combinedInput.value.trim();
            
            if(rawValue === '') {
                combinedInput.classList.add('is-invalid');
                feedback.style.display = 'block';
                return;
            }

            const parts = rawValue.split(/\s+/);

            if(parts.length >= 2) {
                const lotNo = parts[0];
                const coilNo = parts.slice(1).join(' ');

                combinedInput.classList.remove('is-invalid');
                feedback.style.display = 'none';

                // Format for scan_mother_action.php
                qrInput.value = `LOT=${lotNo};COIL=${coilNo}`;
                
                // Close the modal before submitting
                const modalInstance = bootstrap.Modal.getInstance(manualModal);
                if(modalInstance) {
                    modalInstance.hide();
                }

                // Submit the form
                setTimeout(() => {
                    scanForm.submit();
                }, 300);

            } else {
                combinedInput.classList.add('is-invalid');
                feedback.style.display = 'block';
            }
        });

        // Clear error when user types
        combinedInput.addEventListener('input', function() {
            combinedInput.classList.remove('is-invalid');
            feedback.style.display = 'none';
        });

        // Allow Enter key in modal to submit
        combinedInput.addEventListener('keydown', function(e) {
            if(e.key === 'Enter') {
                e.preventDefault();
                manualBtn.click();
            }
        });
    }
});
</script>

<?php include 'footer.php'; ?>
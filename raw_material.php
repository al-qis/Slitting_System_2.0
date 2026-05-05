<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

include 'config.php';

// ============================================================
// FIXED: Run the query ONCE, store result in memory using
// fetch_all() so the result set can be iterated in the table
// without running a second identical query.
// ============================================================
$available_query = "SELECT id, lot_no, coil_no, grade, width, length, status, source_type, date_in 
                    FROM stock_raw_material 
                    WHERE status='IN' 
                    ORDER BY date_in DESC";

$available_result = $conn->query($available_query);

// Guard: if query() fails it returns false, not a result object
if ($available_result === false) {
    $available_rows  = [];
    $total_available = 0;
} else {
    // fetch_all() loads all rows into a PHP array at once.
    // This frees the result object and lets us count + loop
    // without running the query a second time.
    $available_rows  = $available_result->fetch_all(MYSQLI_ASSOC);
    $total_available = count($available_rows);
    $available_result->free();
}
// ============================================================

// Summary totals — these are simple COUNT queries with no user
// input so query() is acceptable. Added error guards.
$res_stock = $conn->query("SELECT COUNT(*) AS total FROM stock_raw_material WHERE status='IN'");
$current_stock = $res_stock ? (int)$res_stock->fetch_assoc()['total'] : 0;

$res_cut = $conn->query("SELECT COUNT(*) AS total FROM stock_raw_material 
                         WHERE status='IN' AND source_type='slitting_cut_into_2'");
$afterCutStock = $res_cut ? (int)$res_cut->fetch_assoc()['total'] : 0;

$page_title = "Raw Material - Available Stock";
include 'header.php'; 
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-boxes me-2"></i>Raw Material - Available Stock</h2>
    <div class="d-flex gap-2">
        <a href="stock_log.php" class="btn btn-secondary shadow-sm">
            <i class="bi bi-clock-history me-1"></i> View Stock Log
        </a>
        <a href="raw_material_export.php" class="btn btn-success shadow-sm">
            <i class="bi bi-file-earmark-excel me-1"></i> Download
        </a>
        <button type="button" class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#manualEntryModal">
            <i class="bi bi-pencil-square me-1"></i> Manual Entry
        </button>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4 text-center">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm bg-primary text-white">
            <div class="card-body p-2">
                <h6 class="small mb-1">CURRENT AVAILABLE STOCK</h6>
                <h4 class="fw-bold mb-0"><?= $current_stock ?></h4>
                <small>Ready for Use</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm bg-info text-white">
            <div class="card-body p-2">
                <h6 class="small mb-1">MOTHER COILS</h6>
                <h4 class="fw-bold mb-0"><?= $current_stock - $afterCutStock ?></h4>
                <small>In Stock</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm bg-warning text-dark">
            <div class="card-body p-2">
                <h6 class="small mb-1">BALANCE STOCK</h6>
                <h4 class="fw-bold mb-0"><?= $afterCutStock ?></h4>
                <small>Leftovers</small>
            </div>
        </div>
    </div>
</div>

<!-- Important Notice -->
<div class="alert alert-info mb-4" role="alert">
    <i class="bi bi-info-circle me-2"></i>
    <strong>How to Use Stock:</strong> 
    Scan the QR code (mother coil or balance) to mark it as OUT. The cutting form will open automatically. 
    No manual "USE" button needed.
</div>

<!-- Available Stock for Slitting Table -->
<div class="card shadow-sm border-0 mb-5">
    <div class="card-header bg-success text-white fw-bold py-3">
        <i class="bi bi-scissors me-2"></i>Available Stock for Slitting
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle text-center mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Print</th>
                    <th>Lot No</th>
                    <th>Coil No</th>
                    <th>Grade</th>
                    <th>Length (mtr)</th>
                    <th>Width (mm)</th>
                    <th>Type</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($total_available > 0): ?>
                    <?php foreach ($available_rows as $stock):
                        // ============================================================
                        // FIXED: Iterating over the pre-fetched PHP array instead of
                        // re-running the database query a second time.
                        // ============================================================
                        $is_balance      = ($stock['source_type'] === 'slitting_cut_into_2');
                        $highlight_class = $is_balance ? 'table-warning' : '';
                        $type_badge      = $is_balance
                            ? '<span class="badge bg-warning text-dark"><i class="bi bi-arrow-return-right"></i> Leftover</span>'
                            : '<span class="badge bg-info">Mother Coil</span>';
                    ?>
                    <tr class="<?= $highlight_class ?>">
                        <td>
                            <?php if ($is_balance): ?>
                                <a href="print_leftover.php?id=<?= $stock['id'] ?>" class="btn btn-warning btn-sm">
                                    <i class="bi bi-printer-fill"></i>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= htmlspecialchars($stock['lot_no']) ?></strong></td>
                        <td><?= htmlspecialchars($stock['coil_no']) ?></td>
                        <td><?= htmlspecialchars($stock['grade'] ?? '-') ?></td>
                        <td class="text-success fw-bold"><?= number_format((float)$stock['length']) ?></td>
                        <td><?= number_format((float)$stock['width']) ?></td>
                        <td><?= $type_badge ?></td>
                        <td>
                            <span class="badge bg-success">IN</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="py-4 text-muted">No stock available for slitting.</td></tr>
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
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Manual Stock Entry</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="manualEntryForm">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Enter Lot No &amp; Coil No</label>
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

<!-- Hidden form for scanner/manual entry submission -->
<form id="scanForm" method="post" action="scan_mother_action.php" style="position:absolute; left:-9999px;">
    <input id="qrInput" type="text" name="qr" autofocus>
</form>

<?php
// ============================================================
// FIXED: Bootstrap JS removed from here.
// header.php already loads Bootstrap — loading it twice causes
// modal/dropdown conflicts and wastes bandwidth on every page load.
// The script block below only contains page-specific logic.
// ============================================================
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const qrInput      = document.getElementById('qrInput');
    const scanForm     = document.getElementById('scanForm');
    const combinedInput = document.getElementById('combined_input');
    const manualBtn    = document.getElementById('manualSubmitButton');
    const feedback     = document.getElementById('validationFeedback');
    const manualModal  = document.getElementById('manualEntryModal');

    // ===== SCANNER LOGIC =====
    if (qrInput) {
        qrInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (this.value.trim() !== '') {
                    scanForm.submit();
                }
            }
        });

        document.addEventListener('click', function() {
            if (!manualModal.classList.contains('show')) {
                qrInput.focus();
            }
        });

        setInterval(() => {
            const el          = document.activeElement;
            const isModalOpen = manualModal.classList.contains('show');
            if (!isModalOpen && !['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON'].includes(el.tagName)) {
                qrInput.focus();
            }
        }, 500);
    }

    // ===== MANUAL ENTRY LOGIC =====
    if (manualBtn) {
        manualBtn.addEventListener('click', function() {
            const rawValue = combinedInput.value.trim();

            if (rawValue === '') {
                combinedInput.classList.add('is-invalid');
                feedback.style.display = 'block';
                return;
            }

            const parts = rawValue.split(/\s+/);

            if (parts.length >= 2) {
                const lotNo  = parts[0];
                const coilNo = parts.slice(1).join(' ');

                combinedInput.classList.remove('is-invalid');
                feedback.style.display = 'none';

                qrInput.value = `LOT=${lotNo};COIL=${coilNo}`;

                const modalInstance = bootstrap.Modal.getInstance(manualModal);
                if (modalInstance) {
                    modalInstance.hide();
                }

                setTimeout(() => {
                    scanForm.submit();
                }, 300);

            } else {
                combinedInput.classList.add('is-invalid');
                feedback.style.display = 'block';
            }
        });

        combinedInput.addEventListener('input', function() {
            combinedInput.classList.remove('is-invalid');
            feedback.style.display = 'none';
        });

        combinedInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                manualBtn.click();
            }
        });
    }
});
</script>

<?php include 'footer.php'; ?>
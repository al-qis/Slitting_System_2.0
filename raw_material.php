<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

include 'config.php';

$month = (int)date('m');
$year  = (int)date('Y');

// ── Available stock rows ─────────────────────────────────────
$available_result = $conn->query(
    "SELECT id, lot_no, coil_no, grade, width, length, status, source_type, date_in 
     FROM stock_raw_material 
     WHERE status='IN' 
     ORDER BY date_in DESC"
);

if ($available_result === false) {
    $available_rows  = [];
    $total_available = 0;
} else {
    $available_rows  = $available_result->fetch_all(MYSQLI_ASSOC);
    $total_available = count($available_rows);
    $available_result->free();
}

// ── Summary counts ───────────────────────────────────────────

// Current available stock
$current_stock = (int)$conn->query(
    "SELECT COUNT(*) AS total FROM stock_raw_material WHERE status='IN'"
)->fetch_assoc()['total'];

$afterCutStock = (int)$conn->query(
    "SELECT COUNT(*) AS total FROM stock_raw_material 
     WHERE status='IN' AND source_type='slitting_cut_into_2'"
)->fetch_assoc()['total'];

// MTD IN — coils scanned/added into stock this month
$mtd_in = (int)$conn->query(
    "SELECT COUNT(*) AS total FROM stock_raw_material 
     WHERE MONTH(date_in) = $month AND YEAR(date_in) = $year"
)->fetch_assoc()['total'];

// MTD OUT — coils consumed (sent to slitting) this month
// updated_at is set when status changes to OUT in save_slitting.php
$mtd_out = (int)$conn->query(
    "SELECT COUNT(*) AS total FROM stock_raw_material 
     WHERE status = 'OUT' 
       AND MONTH(updated_at) = $month AND YEAR(updated_at) = $year"
)->fetch_assoc()['total'];

// MTD IN breakdown — mother coils vs leftovers
$mtd_in_mother = (int)$conn->query(
    "SELECT COUNT(*) AS total FROM stock_raw_material 
     WHERE source_type = 'mother_coil'
       AND MONTH(date_in) = $month AND YEAR(date_in) = $year"
)->fetch_assoc()['total'];

$mtd_in_leftover = (int)$conn->query(
    "SELECT COUNT(*) AS total FROM stock_raw_material 
     WHERE source_type = 'slitting_cut_into_2'
       AND MONTH(date_in) = $month AND YEAR(date_in) = $year"
)->fetch_assoc()['total'];

$page_title = "Raw Material - Available Stock";
include 'header.php'; 
?>

<style>
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 12px;
        margin-bottom: 24px;
    }
    .sum-card {
        background: #fff;
        border-radius: 10px;
        border: 1px solid #e5e7eb;
        border-top: 4px solid var(--c, #6b7280);
        padding: 16px 18px;
        box-shadow: 0 1px 6px rgba(0,0,0,.06);
        text-align: center;
    }
    .sum-card .num {
        font-size: 32px;
        font-weight: 900;
        color: var(--c, #374151);
        line-height: 1;
        margin-bottom: 4px;
    }
    .sum-card .lbl {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #9ca3af;
    }
    .sum-card .sub {
        font-size: 11px;
        color: #6b7280;
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px solid #f3f4f6;
    }
    .sum-card .sub strong { color: #374151; }

    /* colour themes */
    .c-stock   { --c: #059669; }
    .c-mother  { --c: #0284c7; }
    .c-balance { --c: #d97706; }
    .c-in      { --c: #16a34a; }
    .c-out     { --c: #dc2626; }

    @media(max-width:992px) {
        .summary-grid { grid-template-columns: repeat(3,1fr); }
    }
    @media(max-width:576px) {
        .summary-grid { grid-template-columns: repeat(2,1fr); }
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-boxes me-2"></i>Raw Material — Available Stock</h2>
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

<!-- ── 5 Summary Cards ─────────────────────────────────────── -->
<div class="summary-grid">

    <!-- Total available now -->
    <div class="sum-card c-stock">
        <div class="num"><?= $current_stock ?></div>
        <div class="lbl">Available Stock</div>
        <div class="sub">Ready for slitting</div>
    </div>

    <!-- Mother coils in stock -->
    <div class="sum-card c-mother">
        <div class="num"><?= $current_stock - $afterCutStock ?></div>
        <div class="lbl">Mother Coils</div>
        <div class="sub">Currently in stock</div>
    </div>

    <!-- Leftover/balance in stock -->
    <div class="sum-card c-balance">
        <div class="num"><?= $afterCutStock ?></div>
        <div class="lbl">Balance Stock</div>
        <div class="sub">Cut Into 2 leftovers</div>
    </div>

    <!-- MTD IN -->
    <div class="sum-card c-in">
        <div class="num"><?= $mtd_in ?></div>
        <div class="lbl">MTD IN</div>
        <div class="sub">
            Mother: <strong><?= $mtd_in_mother ?></strong>
            &nbsp;·&nbsp;
            Leftover: <strong><?= $mtd_in_leftover ?></strong>
        </div>
    </div>

    <!-- MTD OUT -->
    <div class="sum-card c-out">
        <div class="num"><?= $mtd_out ?></div>
        <div class="lbl">MTD OUT</div>
        <div class="sub">Consumed for slitting</div>
    </div>

</div>

<!-- ── Info notice ─────────────────────────────────────────── -->
<div class="alert alert-info mb-4" role="alert">
    <i class="bi bi-info-circle me-2"></i>
    <strong>How to Use Stock:</strong>
    Scan the QR code (mother coil or balance) to mark it as OUT. The cutting form will open automatically.
    No manual "USE" button needed.
</div>

<!-- ── Available Stock Table ───────────────────────────────── -->
<div class="card shadow-sm border-0 mb-5">
    <div class="card-header bg-success text-white fw-bold py-3 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-scissors me-2"></i>Available Stock for Slitting</span>
        <span class="badge bg-white text-success"><?= $total_available ?> items</span>
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
                    <th>Date In</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($total_available > 0): ?>
                    <?php foreach ($available_rows as $stock):
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
                        <td class="text-muted">
                            <?= $stock['date_in'] ? date('d M Y', strtotime($stock['date_in'])) : '—' ?>
                        </td>
                        <td><span class="badge bg-success">IN</span></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="9" class="py-4 text-muted">No stock available for slitting.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Manual Entry Modal ──────────────────────────────────── -->
<div class="modal fade" id="manualEntryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Manual Stock Entry</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Enter Lot No &amp; Coil No</label>
                    <input type="text" class="form-control form-control-lg" id="combined_input"
                           placeholder="e.g., 826175 FK-1" required autofocus>
                    <div id="validationFeedback" class="invalid-feedback" style="display:none;">
                        Please enter both Lot No and Coil No separated by a space.
                    </div>
                    <div class="form-text mt-2">
                        Type the <strong>Lot Number</strong>, a <strong>space</strong>, then the <strong>Coil Number</strong>.
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary px-4" id="manualSubmitButton">Process Entry</button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden scanner form -->
<form id="scanForm" method="post" action="scan_mother_action.php" style="position:absolute; left:-9999px;">
    <input id="qrInput" type="text" name="qr" autofocus>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const qrInput      = document.getElementById('qrInput');
    const scanForm     = document.getElementById('scanForm');
    const combinedInput = document.getElementById('combined_input');
    const manualBtn    = document.getElementById('manualSubmitButton');
    const feedback     = document.getElementById('validationFeedback');
    const manualModal  = document.getElementById('manualEntryModal');

    // ── Scanner focus ──
    if (qrInput) {
        qrInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (this.value.trim() !== '') scanForm.submit();
            }
        });

        document.addEventListener('click', function () {
            if (!manualModal.classList.contains('show')) qrInput.focus();
        });

        setInterval(() => {
            const el = document.activeElement;
            const isModalOpen = manualModal.classList.contains('show');
            if (!isModalOpen && !['INPUT','TEXTAREA','SELECT','BUTTON'].includes(el.tagName)) {
                qrInput.focus();
            }
        }, 500);
    }

    // ── Manual entry ──
    if (manualBtn) {
        manualBtn.addEventListener('click', function () {
            const raw = combinedInput.value.trim();
            if (raw === '') {
                combinedInput.classList.add('is-invalid');
                feedback.style.display = 'block';
                return;
            }
            const parts = raw.split(/\s+/);
            if (parts.length >= 2) {
                combinedInput.classList.remove('is-invalid');
                feedback.style.display = 'none';
                qrInput.value = `LOT=${parts[0]};COIL=${parts.slice(1).join(' ')}`;
                const mi = bootstrap.Modal.getInstance(manualModal);
                if (mi) mi.hide();
                setTimeout(() => scanForm.submit(), 300);
            } else {
                combinedInput.classList.add('is-invalid');
                feedback.style.display = 'block';
            }
        });

        combinedInput.addEventListener('input', function () {
            combinedInput.classList.remove('is-invalid');
            feedback.style.display = 'none';
        });

        combinedInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); manualBtn.click(); }
        });
    }
});
</script>

<?php include 'footer.php'; ?>
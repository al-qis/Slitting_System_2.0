<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

include 'config.php';

// ── Defensive Schema Check: Ensure stock_id exists in slitting_plans ──
$colCheck = $conn->query("SHOW COLUMNS FROM slitting_plans LIKE 'stock_id'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE slitting_plans ADD COLUMN stock_id INT NULL DEFAULT NULL AFTER mother_coil_id, ADD KEY idx_slitting_plans_stock (stock_id)");
    $conn->query("ALTER TABLE slitting_plans MODIFY COLUMN mother_coil_id INT NULL");
}

// ── AJAX Endpoint: Get Slitting Plan for Leftover Stock ───────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_stock_plan') {
    header('Content-Type: application/json; charset=utf-8');
    $stock_id = intval($_GET['stock_id'] ?? 0);
    $plans = [];
    $stock = null;
    if ($stock_id > 0) {
        $stRes = $conn->query("SELECT id, lot_no, coil_no, grade, width, length, source_id, source_type FROM stock_raw_material WHERE id = $stock_id LIMIT 1");
        if ($stRes && $stRes->num_rows > 0) {
            $stock = $stRes->fetch_assoc();
        }
        $stmt = $conn->prepare("SELECT roll_seq, planned_width, customer_name, ref_no FROM slitting_plans WHERE stock_id = ? ORDER BY sort_order ASC, id ASC");
        if ($stmt) {
            $stmt->bind_param("i", $stock_id);
            $stmt->execute();
            $plans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }
    echo json_encode(['ok' => true, 'plans' => $plans, 'stock' => $stock]);
    exit;
}

// ── POST Action: Save Slitting Plan for Leftover Stock ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_stock_plan') {
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || ($_POST['ajax'] ?? '') === '1';

    $stock_id = intval($_POST['stock_id'] ?? 0);
    if ($stock_id <= 0) {
        if ($is_ajax) { echo json_encode(['ok' => false, 'msg' => 'Invalid stock ID']); exit; }
        $_SESSION['error'] = 'Invalid stock ID.';
        header('Location: raw_material.php');
        exit;
    }

    $stRes = $conn->query("SELECT id, source_id, lot_no, coil_no, width, length FROM stock_raw_material WHERE id = $stock_id LIMIT 1");
    if (!$stRes || $stRes->num_rows === 0) {
        if ($is_ajax) { echo json_encode(['ok' => false, 'msg' => 'Stock item not found']); exit; }
        $_SESSION['error'] = 'Stock item not found.';
        header('Location: raw_material.php');
        exit;
    }
    $stockItem = $stRes->fetch_assoc();
    $mother_id = !empty($stockItem['source_id']) ? intval($stockItem['source_id']) : null;

    $planSeqs      = $_POST['plan_seq']      ?? [];
    $planWidths    = $_POST['plan_width']    ?? [];
    $planCustomers = $_POST['plan_customer'] ?? [];
    $planRefs      = $_POST['plan_ref']      ?? [];

    $conn->begin_transaction();
    try {
        $del = $conn->prepare("DELETE FROM slitting_plans WHERE stock_id = ?");
        $del->bind_param("i", $stock_id);
        $del->execute();
        $del->close();

        $savedCount = 0;
        if (is_array($planSeqs) && is_array($planWidths)) {
            $ins = $conn->prepare("
                INSERT INTO slitting_plans (mother_coil_id, stock_id, roll_seq, planned_width, customer_name, ref_no, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $order = 0;
            foreach ($planSeqs as $i => $seqRaw) {
                $seq   = trim($seqRaw);
                $wRaw  = trim($planWidths[$i] ?? '');
                $cust  = trim($planCustomers[$i] ?? '');
                $refNo = trim($planRefs[$i] ?? '');
                if ($seq === '' || $wRaw === '' || !is_numeric($wRaw)) continue;
                $order++;
                $savedCount++;
                $wVal = (float)$wRaw;
                $ins->bind_param("iisdssi", $mother_id, $stock_id, $seq, $wVal, $cust, $refNo, $order);
                $ins->execute();
            }
            $ins->close();
        }

        $conn->commit();
        $msg = $savedCount > 0
            ? "✓ Slitting plan saved for leftover coil {$stockItem['lot_no']} {$stockItem['coil_no']} ({$savedCount} rolls planned)."
            : "✓ Slitting plan cleared for leftover coil {$stockItem['lot_no']} {$stockItem['coil_no']}.";

        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'msg' => $msg, 'count' => $savedCount, 'stock_id' => $stock_id]);
            exit;
        }

        $_SESSION['success'] = $msg;
        header('Location: raw_material.php');
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => 'Error: ' . $e->getMessage()]);
            exit;
        }
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
        header('Location: raw_material.php');
        exit;
    }
}

$month = (int)date('m');
$year  = (int)date('Y');

// ── Read session warning set by scan_mother_action.php ────────
// Shown when a coil that has already been slitted is scanned.
$scanWarn = $_SESSION['scan_warning'] ?? null;
unset($_SESSION['scan_warning']);   // consume — show once only

$scanError   = $_SESSION['error']   ?? null;
$scanSuccess = $_SESSION['success'] ?? null;
unset($_SESSION['error'], $_SESSION['success']);

// ── Available stock rows ──────────────────────────────────────
$available_result = $conn->query(
    "SELECT id, lot_no, coil_no, grade, width, length, status, source_type, source_id, date_in 
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

// ── Slitting plan counts for stock items ──────────────────────
$plan_counts = [];
$pRes = $conn->query("SELECT stock_id, COUNT(*) as cnt FROM slitting_plans WHERE stock_id IS NOT NULL AND stock_id > 0 GROUP BY stock_id");
if ($pRes) {
    while ($pr = $pRes->fetch_assoc()) {
        $plan_counts[(int)$pr['stock_id']] = (int)$pr['cnt'];
    }
}

// ── Known Customer Codes for Autocomplete ─────────────────────
$knownCustomers = ['NAE','NAX','NCI MFG','TAIHO','NRI','ASHUKA','NIPPON','NTC','SGC','STAMPING','YANTAI','NIPP','NVC','NSJ','NIP','YTEC','NSA','NCI 2','STOCK','TRIAL'];
$cRes = $conn->query("SELECT DISTINCT customer FROM nci_product_mapping WHERE customer IS NOT NULL AND customer != ''");
if ($cRes) {
    while ($cr = $cRes->fetch_assoc()) {
        $c = trim($cr['customer']);
        if ($c !== '' && !in_array($c, $knownCustomers, true)) {
            $knownCustomers[] = $c;
        }
    }
}

// ── Summary counts ────────────────────────────────────────────
$current_stock = (int)$conn->query(
    "SELECT COUNT(*) AS total FROM stock_raw_material WHERE status='IN'"
)->fetch_assoc()['total'];

$afterCutStock = (int)$conn->query(
    "SELECT COUNT(*) AS total FROM stock_raw_material 
     WHERE status='IN' AND source_type='slitting_cut_into_2'"
)->fetch_assoc()['total'];

$mtd_in = (int)$conn->query(
    "SELECT COUNT(*) AS total FROM stock_raw_material 
     WHERE MONTH(date_in) = $month AND YEAR(date_in) = $year"
)->fetch_assoc()['total'];

$mtd_out = (int)$conn->query(
    "SELECT COUNT(*) AS total FROM stock_raw_material 
     WHERE status = 'OUT' 
       AND MONTH(updated_at) = $month AND YEAR(updated_at) = $year"
)->fetch_assoc()['total'];

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
/* ── Summary grid ─────────────────────────────────────────── */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px;
    margin-bottom: 24px;
}
.sum-card {
    background: #fff; border-radius: 10px;
    border: 1px solid #e5e7eb;
    border-top: 4px solid var(--c, #6b7280);
    padding: 16px 18px;
    box-shadow: 0 1px 6px rgba(0,0,0,.06);
    text-align: center;
}
.sum-card .num { font-size:32px; font-weight:900; color:var(--c,#374151); line-height:1; margin-bottom:4px; }
.sum-card .lbl { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:#9ca3af; }
.sum-card .sub { font-size:11px; color:#6b7280; margin-top:8px; padding-top:8px; border-top:1px solid #f3f4f6; }
.sum-card .sub strong { color:#374151; }
.c-stock   { --c:#059669; }
.c-mother  { --c:#0284c7; }
.c-balance { --c:#d97706; }
.c-in      { --c:#16a34a; }
.c-out     { --c:#dc2626; }

/* ── Already-used warning banner ─────────────────────────── */
.already-used-banner {
    background: #fffbeb !important;
    border: 1.5px solid #f59e0b !important;
    border-left: 5px solid #d97706 !important;
    border-radius: 12px !important;
    padding: 20px 22px !important;
}
.warn-icon-wrap {
    width: 48px; height: 48px; border-radius: 50%;
    background: #fef3c7; display: flex; align-items: center;
    justify-content: center; flex-shrink: 0;
    font-size: 22px; color: #d97706;
}
.text-warning-dark { color: #92400e; }
.warn-coil-card {
    background: #fff; border: 1px solid #fde68a;
    border-radius: 10px; padding: 14px 18px;
}
.warn-badge-lot  { font-family: monospace; font-size: 20px; font-weight: 800; color: #1e293b; }
.warn-badge-coil { font-family: monospace; font-size: 14px; color: #475569; margin-top: 2px; }
.warn-detail-row { font-size: 13px; color: #374151; margin-bottom: 4px; }
.warn-detail-row:last-child { margin-bottom: 0; }
.warn-rolls-label {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .5px; color: #92400e; margin-bottom: 8px;
}
.warn-rolls-table {
    background: #fff; border-radius: 8px; overflow: hidden;
    font-size: 12px; border: 1px solid #fde68a;
}
.warn-rolls-table thead th {
    background: #fef3c7; font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .5px; color: #92400e;
    padding: 7px 12px; border-bottom: 1px solid #fde68a;
}
.warn-rolls-table td { padding: 7px 12px; border-bottom: 1px solid #fef9c3; }
.warn-rolls-table tbody tr:last-child td { border-bottom: none; }
.warn-footer-note {
    font-size: 12px; color: #78716c;
    background: #fff7ed; border-radius: 6px;
    padding: 8px 12px; margin-top: 10px;
}
.warn-footer-note code {
    background: #fed7aa; color: #9a3412;
    padding: 1px 5px; border-radius: 3px;
}

/* ── Scanner status pill ──────────────────────────────────── */
.scanner-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    background: #f0fdf4;
    border: 1px solid #86efac;
    color: #166534;
    cursor: default;
    user-select: none;
}
.scanner-pill .dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #22c55e;
    animation: pulse-dot 2s ease-in-out infinite;
}
@keyframes pulse-dot {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: .4; transform: scale(.6); }
}

@media(max-width:992px) { .summary-grid { grid-template-columns: repeat(3,1fr); } }
@media(max-width:576px) { .summary-grid { grid-template-columns: repeat(2,1fr); } }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-boxes me-2"></i>Raw Material — Available Stock</h2>
    <div class="d-flex gap-2 align-items-center">
        <!-- Visual indicator that hardware scanner is listening -->
        <span class="scanner-pill">
            <span class="dot"></span>
            Scanner Ready
        </span>
        <a href="stock_log.php" class="btn btn-secondary shadow-sm">
            <i class="bi bi-clock-history me-1"></i> View Stock Log
        </a>
        <a href="raw_material_export.php" class="btn btn-success shadow-sm">
            <i class="bi bi-file-earmark-excel me-1"></i> Download
        </a>
        <button type="button" class="btn btn-primary shadow-sm"
                data-bs-toggle="modal" data-bs-target="#manualEntryModal">
            <i class="bi bi-pencil-square me-1"></i> Manual Entry
        </button>
    </div>
</div>

<!-- ── Session alerts ─────────────────────────────────────────── -->
<?php if ($scanError): ?>
<div class="alert alert-danger alert-dismissible fade show shadow-sm mb-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <?= htmlspecialchars($scanError) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($scanSuccess): ?>
<div class="alert alert-success alert-dismissible fade show shadow-sm mb-3">
    <i class="bi bi-check-circle-fill me-2"></i>
    <?= htmlspecialchars($scanSuccess) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     ALREADY-USED MOTHER COIL WARNING BANNER
     ═══════════════════════════════════════════════════════════ -->
<?php if ($scanWarn && $scanWarn['type'] === 'already_used'): ?>
<div class="already-used-banner alert alert-warning alert-dismissible shadow mb-4" role="alert">

    <div class="d-flex align-items-center gap-3 mb-3">
        <div class="warn-icon-wrap">
            <i class="bi bi-exclamation-triangle-fill"></i>
        </div>
        <div class="flex-grow-1">
            <h5 class="mb-1 fw-bold text-warning-dark">
                ⚠️ Mother Coil Already Used — Cannot Re-enter
            </h5>
            <div class="text-muted" style="font-size:13px;">
                This coil has already been slitted and its material is consumed.
                The system has <strong>blocked</strong> it from being added to stock again.
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>

    <!-- Coil identity card -->
    <div class="warn-coil-card mb-3">
        <div class="row g-3 align-items-center">
            <div class="col-auto">
                <div class="warn-badge-lot"><?= htmlspecialchars($scanWarn['lot_no']) ?></div>
                <div class="warn-badge-coil"><?= htmlspecialchars($scanWarn['coil_no']) ?></div>
            </div>
            <div class="col">
                <div class="warn-detail-row">
                    <i class="bi bi-tag me-1 text-muted"></i>
                    <strong>Product:</strong>&nbsp;<?= htmlspecialchars($scanWarn['product']) ?>
                </div>
                <div class="warn-detail-row">
                    <i class="bi bi-scissors me-1 text-muted"></i>
                    <strong>Slitting records:</strong>&nbsp;
                    <span class="badge bg-danger">
                        <?= $scanWarn['slit_count'] ?> roll<?= $scanWarn['slit_count'] != 1 ? 's' : '' ?> produced
                    </span>
                </div>
                <?php if ($scanWarn['first_date']): ?>
                <div class="warn-detail-row">
                    <i class="bi bi-calendar-event me-1 text-muted"></i>
                    <strong>First slitted:</strong>&nbsp;
                    <?= date('d M Y', strtotime($scanWarn['first_date'])) ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="col-auto d-flex flex-column gap-2">
                <a href="mother_coil_journal.php?id=<?= $scanWarn['mother_id'] ?>"
                   class="btn btn-outline-dark btn-sm">
                    <i class="bi bi-journal-text me-1"></i> View Full Journal
                </a>
                <a href="finish_product.php"
                   class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-check-circle me-1"></i> View Finish Products
                </a>
            </div>
        </div>
    </div>

    <!-- Rolls produced mini-table -->
    <?php if (!empty($scanWarn['produced'])): ?>
    <div class="warn-rolls-label">
        <i class="bi bi-list-ul me-1"></i>
        Rolls produced from this coil
        <?php if ($scanWarn['slit_count'] > count($scanWarn['produced'])): ?>
        <span class="text-muted fw-normal" style="letter-spacing:0">
            — showing first <?= count($scanWarn['produced']) ?> of <?= $scanWarn['slit_count'] ?>
        </span>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="warn-rolls-table table table-sm mb-0">
            <thead>
                <tr>
                    <th>Lot · Coil · Roll</th>
                    <th>Width (mm)</th>
                    <th>Length (m)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($scanWarn['produced'] as $r): ?>
            <tr>
                <td style="font-family:monospace; font-weight:600;">
                    <?= htmlspecialchars($r['lot_no']) ?>
                    <?= htmlspecialchars($r['coil_no']) ?>
                    – <?= str_replace('R', 'R-', htmlspecialchars($r['roll_no'])) ?>
                </td>
                <td><?= number_format((float)$r['width']) ?></td>
                <td>
                    <?php $len = $r['actual_length'] ?: $r['length']; ?>
                    <?= number_format((float)$len, 1) ?>
                    <?php if ($r['actual_length']): ?>
                    <span class="badge bg-info text-dark" style="font-size:9px;">ACTUAL</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                    $st = strtoupper($r['status'] ?? 'IN');
                    $bc = match($st) {
                        'DELIVERED' => 'bg-success',
                        'APPROVED'  => 'bg-primary',
                        'WAITING'   => 'bg-warning text-dark',
                        'REJECTED'  => 'bg-danger',
                        default     => 'bg-secondary',
                    };
                    ?>
                    <span class="badge <?= $bc ?>"><?= $st ?></span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Advice footer -->
    <div class="warn-footer-note mt-2">
        <i class="bi bi-lightbulb me-1"></i>
        <strong>What to do:</strong>
        If physical material still remains on this coil, register it as a
        <strong>new mother coil entry</strong> with a different Lot No
        (e.g., add a suffix: <code><?= htmlspecialchars($scanWarn['lot_no']) ?>B</code>).
        Contact your supervisor if you believe this was scanned in error.
    </div>

</div>
<?php endif; ?>

<!-- ── 5 Summary Cards ────────────────────────────────────────── -->
<div class="summary-grid">
    <div class="sum-card c-stock">
        <div class="num"><?= $current_stock ?></div>
        <div class="lbl">Available Stock</div>
        <div class="sub">Ready for slitting</div>
    </div>
    <div class="sum-card c-mother">
        <div class="num"><?= $current_stock - $afterCutStock ?></div>
        <div class="lbl">Mother Coils</div>
        <div class="sub">Currently in stock</div>
    </div>
    <div class="sum-card c-balance">
        <div class="num"><?= $afterCutStock ?></div>
        <div class="lbl">Balance Stock</div>
        <div class="sub">Cut Into 2 leftovers</div>
    </div>
    <div class="sum-card c-in">
        <div class="num"><?= $mtd_in ?></div>
        <div class="lbl">MTD IN</div>
        <div class="sub">
            Mother: <strong><?= $mtd_in_mother ?></strong>
            &nbsp;·&nbsp;
            Leftover: <strong><?= $mtd_in_leftover ?></strong>
        </div>
    </div>
    <div class="sum-card c-out">
        <div class="num"><?= $mtd_out ?></div>
        <div class="lbl">MTD OUT</div>
        <div class="sub">Consumed for slitting</div>
    </div>
</div>

<div class="alert alert-info mb-4" role="alert">
    <i class="bi bi-info-circle me-2"></i>
    <strong>How to Use Stock:</strong>
    Scan the QR code (mother coil or balance) to mark it as OUT. The cutting form will open automatically.
    No manual "USE" button needed.
</div>

<!-- ── Available Stock Table ──────────────────────────────────── -->
<div class="card shadow-sm border-0 mb-5">
    <div class="card-header bg-success text-white fw-bold py-3 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-scissors me-2"></i>Available Stock for Slitting</span>
        <span class="badge bg-white text-success"><?= $total_available ?> items</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle text-center mb-0 small" id="availableStockTable">
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
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($total_available > 0): ?>
                    <?php foreach ($available_rows as $stock):
                        $is_balance  = ($stock['source_type'] === 'slitting_cut_into_2');
                        $hl          = $is_balance ? 'table-warning' : '';
                        $type_badge  = $is_balance
                            ? '<span class="badge bg-warning text-dark"><i class="bi bi-arrow-return-right"></i> Leftover</span>'
                            : '<span class="badge bg-info">Mother Coil</span>';
                        $pCount      = $plan_counts[$stock['id']] ?? 0;
                    ?>
                    <tr class="<?= $hl ?>" id="stock_row_<?= $stock['id'] ?>">
                        <td>
                            <?php if ($is_balance): ?>
                                <a href="print_leftover.php?id=<?= $stock['id'] ?>" class="btn btn-warning btn-sm" title="Print Leftover QR Sticker">
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
                        <td class="fw-semibold"><?= number_format((float)$stock['width']) ?></td>
                        <td><?= $type_badge ?></td>
                        <td class="text-muted">
                            <?= $stock['date_in'] ? date('d M Y', strtotime($stock['date_in'])) : '—' ?>
                        </td>
                        <td><span class="badge bg-success">IN</span></td>
                        <td>
                            <?php if ($is_balance): ?>
                                <button type="button" class="btn btn-sm btn-stock-plan <?= $pCount > 0 ? 'btn-primary' : 'btn-outline-primary' ?>"
                                        id="plan_btn_<?= $stock['id'] ?>"
                                        data-stock-id="<?= $stock['id'] ?>"
                                        data-lot-no="<?= htmlspecialchars($stock['lot_no']) ?>"
                                        data-coil-no="<?= htmlspecialchars($stock['coil_no']) ?>"
                                        data-grade="<?= htmlspecialchars($stock['grade'] ?? '-') ?>"
                                        data-width="<?= htmlspecialchars($stock['width']) ?>"
                                        data-length="<?= htmlspecialchars($stock['length']) ?>"
                                        title="<?= $pCount > 0 ? 'Edit Slitting Plan (' . $pCount . ' rolls planned)' : 'Add Slitting Plan for this leftover coil' ?>">
                                    <?php if ($pCount > 0): ?>
                                        <i class="bi bi-pencil-square me-1"></i>Edit Plan
                                        <span class="badge bg-white text-primary ms-1 plan-badge-count"><?= $pCount ?></span>
                                    <?php else: ?>
                                        <i class="bi bi-plus-circle me-1"></i>+ Slitting Plan
                                    <?php endif; ?>
                                </button>
                            <?php else: ?>
                                <span class="text-muted" title="Managed in Mother Coil page">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="10" class="py-4 text-muted">No stock available for slitting.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Manual Entry Modal ─────────────────────────────────────── -->
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
                           placeholder="e.g., 826175 FK-1" required>
                    <div id="validationFeedback" class="invalid-feedback" style="display:none;">
                        Please enter both Lot No and Coil No separated by a space.
                    </div>
                    <div class="form-text mt-2">
                        Type the <strong>Lot Number</strong>, a <strong>space</strong>,
                        then the <strong>Coil Number</strong>.
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

<!-- Hidden form — camera scanner submits into this -->
<form id="scanForm" method="post" action="scan_mother_action.php">
    <input id="qrInput" type="hidden" name="qr" value="">
</form>

<script src="camera_scanner.js"></script>
<script>
// Camera scanner
initCameraScanner({
    onScan: function(decodedText) {
        document.getElementById('qrInput').value = decodedText;
        document.getElementById('scanForm').submit();
    }
});

// Manual entry — runs immediately, no DOMContentLoaded needed
// because this script is already at bottom of body
(function () {
    var btn   = document.getElementById('manualSubmitButton');
    var input = document.getElementById('combined_input');
    var fb    = document.getElementById('validationFeedback');

    if (!btn) {
        console.error('manualSubmitButton NOT FOUND in DOM');
        return;
    }

    btn.addEventListener('click', function () {
        var raw = input.value.trim();

        if (!raw) {
            input.classList.add('is-invalid');
            fb.style.display = 'block';
            return;
        }

        var parts = raw.split(/\s+/);
        if (parts.length < 2) {
            input.classList.add('is-invalid');
            fb.style.display = 'block';
            return;
        }

        var lotNo  = parts[0];
        var coilNo = parts.slice(1).join(' ');
        var qrVal  = 'LOT=' + lotNo + ';COIL=' + coilNo;

        document.getElementById('qrInput').value = qrVal;

        // Close modal safely without Bootstrap JS dependency
        var modalEl = document.getElementById('manualEntryModal');
        if (modalEl) {
            modalEl.classList.remove('show');
            modalEl.style.display = 'none';
        }
        document.body.classList.remove('modal-open');
        var backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) backdrop.remove();

        // Submit
        document.getElementById('scanForm').submit();
    });

    input.addEventListener('input', function () {
        input.classList.remove('is-invalid');
        fb.style.display = 'none';
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); btn.click(); }
    });
})();
</script>

<!-- ── Datalist for Valid Customers ───────────────────────────── -->
<datalist id="validCustomerList">
    <?php foreach ($knownCustomers as $c): ?>
        <option value="<?= htmlspecialchars($c) ?>">
    <?php endforeach; ?>
</datalist>

<!-- ── Leftover Coil Slitting Plan Modal ──────────────────────── -->
<div class="modal fade" id="leftoverPlanModal" tabindex="-1" aria-labelledby="leftoverPlanModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-success text-white py-3">
                <div>
                    <h5 class="modal-title mb-1" id="leftoverPlanModalLabel">
                        <i class="bi bi-scissors me-2"></i>Slitting Plan — Leftover Coil
                    </h5>
                    <div class="small opacity-75">
                        Plan cut sequences, widths, and customer assignments for this leftover stock piece.
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form id="leftoverPlanForm" method="post" action="raw_material.php">
                <input type="hidden" name="action" value="save_stock_plan">
                <input type="hidden" name="stock_id" id="planModalStockId" value="0">

                <div class="modal-body p-4">
                    <!-- Coil Info Summary Banner -->
                    <div class="card bg-light border-0 shadow-sm mb-4">
                        <div class="card-body py-3 px-4">
                            <div class="row g-3 align-items-center">
                                <div class="col-auto">
                                    <span class="text-muted small text-uppercase fw-bold d-block">Lot Number</span>
                                    <span class="fs-5 fw-bold text-dark font-monospace" id="planBadgeLot">—</span>
                                </div>
                                <div class="col-auto border-start ps-3">
                                    <span class="text-muted small text-uppercase fw-bold d-block">Coil Number</span>
                                    <span class="fs-5 fw-bold text-primary font-monospace" id="planBadgeCoil">—</span>
                                </div>
                                <div class="col-auto border-start ps-3">
                                    <span class="text-muted small text-uppercase fw-bold d-block">Grade</span>
                                    <span class="fs-5 fw-bold text-secondary" id="planBadgeGrade">—</span>
                                </div>
                                <div class="col-auto border-start ps-3">
                                    <span class="text-muted small text-uppercase fw-bold d-block">Leftover Width</span>
                                    <span class="fs-5 fw-bold text-dark" id="planBadgeWidth">—</span>
                                </div>
                                <div class="col-auto border-start ps-3">
                                    <span class="text-muted small text-uppercase fw-bold d-block">Length</span>
                                    <span class="fs-5 fw-bold text-success" id="planBadgeLength">—</span>
                                </div>
                                <div class="col text-end border-start ps-3">
                                    <div id="widthAllocationBadge" class="d-inline-block px-3 py-2 rounded-3 border bg-white text-start shadow-sm">
                                        <div class="d-flex align-items-center gap-3">
                                            <div>
                                                <small class="text-muted fw-bold d-block">Planned Total</small>
                                                <span class="fw-bold" id="plannedTotalWidth">0.00 mm</span>
                                            </div>
                                            <div class="border-start ps-3">
                                                <small class="text-muted fw-bold d-block">Unplanned Balance</small>
                                                <span class="fw-bold text-success" id="plannedBalanceWidth">0.00 mm</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Excel / CSV Slitting Plan Paste -->
                    <div class="p-3 bg-light rounded-3 border mb-3">
                        <label class="form-label fw-semibold small text-secondary mb-1">
                            <i class="bi bi-clipboard-plus me-1"></i> Quick Excel / CSV Slitting Plan Paste
                        </label>
                        <textarea id="leftover_plan_paste_zone" class="form-control form-control-sm border-secondary-subtle" rows="2"
                                  placeholder="Paste from Excel/CSV — Roll, Width, Customer, Ref No, e.g.&#10;R1, 125, NAE, SO-26-0110&#10;R2, 125, STAMPING, STOCK"></textarea>
                        <div class="alert alert-warning py-1 px-2 small mt-2 mb-0 d-none" id="leftover_plan_paste_warning">
                            Couldn't read that as Seq + Width pairs — check the format.
                        </div>
                    </div>

                    <!-- Structured Table / Grid for Slitting Plan Rows -->
                    <div class="card border shadow-sm mb-3">
                        <div class="card-header bg-light py-2 px-3">
                            <div class="row g-2 align-items-center text-secondary fw-bold small text-uppercase">
                                <div style="width: 85px;">Seq</div>
                                <div style="width: 130px;">Width (mm)</div>
                                <div style="width: 240px;">Customer Code</div>
                                <div class="col">Customer Ref No</div>
                                <div style="width: 45px;" class="text-end"></div>
                            </div>
                        </div>
                        <div class="card-body p-2" id="leftoverPlanRowsContainer" style="max-height: 280px; overflow-y: auto;">
                            <!-- Dynamic rows injected here -->
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-outline-primary btn-sm px-3" onclick="addLeftoverPlanRow()">
                            <i class="bi bi-plus-lg me-1"></i>Add Row Manually
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm px-3" onclick="clearLeftoverPlanRows()">
                            <i class="bi bi-trash3 me-1"></i>Clear All Rows
                        </button>
                    </div>

                    <div id="planSaveAlert" class="mt-3 d-none"></div>
                </div>

                <div class="modal-footer bg-light px-4 py-3">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success px-4" id="savePlanSubmitBtn">
                        <i class="bi bi-check-circle me-1"></i> Save Slitting Plan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ── Leftover Coil Slitting Plan Logic ─────────────────────────
(function () {
    var modalEl = document.getElementById('leftoverPlanModal');
    if (!modalEl) return;
    var planModal = new bootstrap.Modal(modalEl);

    var currentCoilWidth = 0;
    var elStockId = document.getElementById('planModalStockId');
    var elBadgeLot = document.getElementById('planBadgeLot');
    var elBadgeCoil = document.getElementById('planBadgeCoil');
    var elBadgeGrade = document.getElementById('planBadgeGrade');
    var elBadgeWidth = document.getElementById('planBadgeWidth');
    var elBadgeLength = document.getElementById('planBadgeLength');
    var elContainer = document.getElementById('leftoverPlanRowsContainer');
    var elPasteZone = document.getElementById('leftover_plan_paste_zone');
    var elPasteWarn = document.getElementById('leftover_plan_paste_warning');
    var elTotalW = document.getElementById('plannedTotalWidth');
    var elBalW = document.getElementById('plannedBalanceWidth');
    var elAllocBadge = document.getElementById('widthAllocationBadge');
    var elSaveBtn = document.getElementById('savePlanSubmitBtn');
    var elAlert = document.getElementById('planSaveAlert');
    var planForm = document.getElementById('leftoverPlanForm');

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, function(c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    function updateWidthCalculations() {
        var total = 0;
        var inputs = elContainer.querySelectorAll('input[name="plan_width[]"]');
        inputs.forEach(function (inp) {
            var val = parseFloat(inp.value);
            if (!isNaN(val) && val > 0) total += val;
        });

        elTotalW.textContent = total.toFixed(2) + ' mm';
        var balance = currentCoilWidth - total;
        elBalW.textContent = balance.toFixed(2) + ' mm';

        if (currentCoilWidth > 0 && total > currentCoilWidth + 0.01) {
            elBalW.className = 'fw-bold text-danger';
            elAllocBadge.classList.add('border-danger', 'bg-danger-subtle');
            elAllocBadge.classList.remove('bg-white');
        } else {
            elBalW.className = 'fw-bold text-success';
            elAllocBadge.classList.remove('border-danger', 'bg-danger-subtle');
            elAllocBadge.classList.add('bg-white');
        }
    }

    function renderEmptyState() {
        elContainer.innerHTML = `
            <div id="planEmptyPlaceholder" class="text-center py-4 text-muted small">
                <i class="bi bi-card-list fs-3 d-block mb-2 text-secondary opacity-50"></i>
                <div class="fw-semibold text-secondary">Slitting Plan is currently blank.</div>
                <div class="text-muted mt-1">Paste rows from Excel above or click <strong>Add Row Manually</strong> to key in planned cuts.</div>
            </div>
        `;
    }

    window.addLeftoverPlanRow = function (seq, width, customer, refNo) {
        var placeholder = document.getElementById('planEmptyPlaceholder');
        if (placeholder) {
            placeholder.remove();
        }

        seq = seq || '';
        width = (width !== undefined && width !== null) ? width : '';
        customer = customer || '';
        refNo = refNo || '';

        if (!seq) {
            var maxNum = 0;
            elContainer.querySelectorAll('input[name="plan_seq[]"]').forEach(function (inp) {
                var m = inp.value.trim().match(/^R(\d+)$/i);
                if (m) {
                    var n = parseInt(m[1], 10);
                    if (n > maxNum) maxNum = n;
                }
            });
            if (maxNum === 0) {
                maxNum = elContainer.querySelectorAll('.plan-row').length;
            }
            seq = 'R' + (maxNum + 1);
        }

        var row = document.createElement('div');
        row.className = 'row g-2 align-items-center mb-2 plan-row';
        row.innerHTML = `
            <div style="width: 85px;">
                <input type="text" name="plan_seq[]" class="form-control form-control-sm text-center fw-bold bg-light" placeholder="R1" value="${esc(seq)}">
            </div>
            <div style="width: 130px;">
                <input type="number" step="0.01" name="plan_width[]" class="form-control form-control-sm plan-width-input" placeholder="e.g. 125" value="${esc(width)}">
            </div>
            <div style="width: 240px;">
                <input type="text" name="plan_customer[]" class="form-control form-control-sm text-uppercase" list="validCustomerList" placeholder="e.g. NAE" value="${esc(customer)}">
            </div>
            <div class="col">
                <input type="text" name="plan_ref[]" class="form-control form-control-sm" placeholder="e.g. SO-26-0110" value="${esc(refNo)}">
            </div>
            <div style="width: 45px;" class="text-end">
                <button type="button" class="btn btn-sm btn-outline-danger border-0 px-2 btn-remove-row" title="Remove Roll">
                    <i class="bi bi-trash3-fill"></i>
                </button>
            </div>
        `;

        elContainer.appendChild(row);

        row.querySelector('.plan-width-input').addEventListener('input', updateWidthCalculations);
        row.querySelector('.btn-remove-row').addEventListener('click', function () {
            row.remove();
            if (elContainer.querySelectorAll('.plan-row').length === 0) {
                renderEmptyState();
            }
            updateWidthCalculations();
        });

        updateWidthCalculations();
    };

    window.clearLeftoverPlanRows = function () {
        renderEmptyState();
        updateWidthCalculations();
    };

    // Parse Excel / CSV paste
    function parsePasteText(text) {
        var lines = text.trim().split(/\r?\n/).filter(function (l) { return l.trim() !== ''; });
        var parsed = [];

        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            var cols = [];
            if (line.indexOf(',') !== -1) {
                cols = line.split(',').map(function (c) { return c.trim(); });
            } else if (line.indexOf('\t') !== -1) {
                cols = line.split('\t').map(function (c) { return c.trim(); }).filter(function (c) { return c !== ''; });
            } else {
                cols = line.trim().split(/\s+/);
            }

            if (cols.length < 2) continue;

            var seq = cols[0].trim();
            var width = parseFloat(cols[1]);
            if (seq === '' || isNaN(width)) continue;

            var customer = (cols.length >= 3) ? cols[2].trim().toUpperCase() : '';
            var refNo = (cols.length >= 4) ? cols[3].trim() : '';

            parsed.push({ seq: seq, width: width, customer: customer, refNo: refNo });
        }

        return parsed;
    }

    if (elPasteZone) {
        elPasteZone.addEventListener('input', function () {
            var text = this.value.trim();
            if (!text) {
                elPasteWarn.classList.add('d-none');
                return;
            }
            var parsed = parsePasteText(text);
            if (parsed.length === 0) {
                elPasteWarn.classList.remove('d-none');
                return;
            }
            elPasteWarn.classList.add('d-none');
            elContainer.innerHTML = '';
            parsed.forEach(function (p) {
                addLeftoverPlanRow(p.seq, p.width, p.customer, p.refNo);
            });
            updateWidthCalculations();
        });
    }

    // Attach click listener to table plan buttons
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-stock-plan');
        if (!btn) return;

        var stockId = btn.dataset.stockId;
        var lotNo = btn.dataset.lotNo || '';
        var coilNo = btn.dataset.coilNo || '';
        var grade = btn.dataset.grade || '-';
        var width = parseFloat(btn.dataset.width || 0);
        var length = parseFloat(btn.dataset.length || 0);

        currentCoilWidth = width;
        elStockId.value = stockId;
        elBadgeLot.textContent = lotNo;
        elBadgeCoil.textContent = coilNo;
        elBadgeGrade.textContent = grade;
        elBadgeWidth.textContent = width.toFixed(2) + ' mm';
        elBadgeLength.textContent = length.toFixed(1) + ' m';

        if (elPasteZone) elPasteZone.value = '';
        if (elPasteWarn) elPasteWarn.classList.add('d-none');
        if (elAlert) elAlert.classList.add('d-none');
        elContainer.innerHTML = '<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Loading slitting plan...</div>';

        updateWidthCalculations();
        planModal.show();

        fetch('raw_material.php?ajax=get_stock_plan&stock_id=' + encodeURIComponent(stockId))
            .then(function (res) { return res.json(); })
            .then(function (data) {
                elContainer.innerHTML = '';
                if (data.ok && Array.isArray(data.plans) && data.plans.length > 0) {
                    data.plans.forEach(function (p) {
                        addLeftoverPlanRow(p.roll_seq, p.planned_width, p.customer_name, p.ref_no);
                    });
                } else {
                    // Blank before the officer keys in
                    renderEmptyState();
                }
                updateWidthCalculations();
            })
            .catch(function (err) {
                console.error('Error fetching plan:', err);
                renderEmptyState();
                updateWidthCalculations();
            });
    });

    // Form submit via AJAX
    planForm.addEventListener('submit', function (e) {
        e.preventDefault();
        elSaveBtn.disabled = true;
        elSaveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

        var formData = new FormData(planForm);
        formData.append('ajax', '1');

        fetch('raw_material.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            elSaveBtn.disabled = false;
            elSaveBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Save Slitting Plan';

            if (data.ok) {
                // Update button in table
                var stockId = data.stock_id || elStockId.value;
                var tableBtn = document.getElementById('plan_btn_' + stockId);
                if (tableBtn) {
                    var count = data.count || 0;
                    if (count > 0) {
                        tableBtn.className = 'btn btn-sm btn-stock-plan btn-primary';
                        tableBtn.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Edit Plan <span class="badge bg-white text-primary ms-1 plan-badge-count">' + count + '</span>';
                        tableBtn.title = 'Edit Slitting Plan (' + count + ' rolls planned)';
                    } else {
                        tableBtn.className = 'btn btn-sm btn-stock-plan btn-outline-primary';
                        tableBtn.innerHTML = '<i class="bi bi-plus-circle me-1"></i>+ Slitting Plan';
                        tableBtn.title = 'Add Slitting Plan for this leftover coil';
                    }
                }

                planModal.hide();

                // Show success banner at top of page
                var topAlert = document.createElement('div');
                topAlert.className = 'alert alert-success alert-dismissible fade show shadow-sm mb-3';
                topAlert.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i>' + esc(data.msg) + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                var alertContainer = document.querySelector('.container-fluid') || document.querySelector('.container') || document.body;
                var heading = document.querySelector('.summary-grid') || document.querySelector('.card');
                if (heading && heading.parentNode) {
                    heading.parentNode.insertBefore(topAlert, heading);
                } else {
                    alertContainer.prepend(topAlert);
                }
                setTimeout(function() { topAlert.remove(); }, 6000);
            } else {
                if (elAlert) {
                    elAlert.className = 'alert alert-danger py-2 mt-3';
                    elAlert.textContent = data.msg || 'Failed to save plan';
                    elAlert.classList.remove('d-none');
                } else {
                    alert(data.msg || 'Failed to save plan');
                }
            }
        })
        .catch(function (err) {
            console.error('Save plan error:', err);
            elSaveBtn.disabled = false;
            elSaveBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Save Slitting Plan';
            if (elAlert) {
                elAlert.className = 'alert alert-danger py-2 mt-3';
                elAlert.textContent = 'Network or server error while saving plan.';
                elAlert.classList.remove('d-none');
            } else {
                alert('Network or server error while saving plan.');
            }
        });
    });
})();
</script>

<?php include 'footer.php'; ?>
<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION['role'] !== 'slitting') {
    die("Access denied");
}

require_once 'config.php';

// Historical monthly summary
$monthly_summary_result = $conn->query("
    SELECT DATE_FORMAT(performed_at, '%Y-%m') AS month_year, COUNT(*) AS total_count 
    FROM mother_coil_audit_log 
    WHERE action_type IN ('IN','SCAN_IN') 
    GROUP BY month_year 
    ORDER BY month_year DESC
");
$raw_material_monthly_summary = [];
if ($monthly_summary_result) {
    while ($row = $monthly_summary_result->fetch_assoc()) {
        $raw_material_monthly_summary[] = $row;
    }
}

$selected_month = isset($_POST['month']) ? $_POST['month'] : date('m');
$selected_year  = isset($_POST['year'])  ? $_POST['year']  : date('Y');

$raw_material_data              = [];
$slitting_product_delivered_data = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $stmt_in = $conn->prepare("
        SELECT audit.performed_at AS date_in, mc.coil_no, mc.product, mc.lot_no, mc.width, mc.length 
        FROM mother_coil_audit_log audit
        JOIN mother_coil mc ON audit.mother_id = mc.id
        WHERE MONTH(audit.performed_at) = ? AND YEAR(audit.performed_at) = ? AND audit.action_type IN ('IN','SCAN_IN')
    ");
    $stmt_in->bind_param("ss", $selected_month, $selected_year);
    $stmt_in->execute();
    $result_in = $stmt_in->get_result();
    while ($row = $result_in->fetch_assoc()) $raw_material_data[] = $row;
    $stmt_in->close();

    $stmt_out = $conn->prepare("SELECT * FROM slitting_product WHERE MONTH(delivered_at) = ? AND YEAR(delivered_at) = ? AND status='DELIVERED'");
    $stmt_out->bind_param("ss", $selected_month, $selected_year);
    $stmt_out->execute();
    $result_out = $stmt_out->get_result();
    while ($row = $result_out->fetch_assoc()) $slitting_product_delivered_data[] = $row;
    $stmt_out->close();
}

$page_title = "Monthly Report";
include 'header.php';
?>

<style>
    :root {
        --navy:     #0B1D35;
        --green:    #1A8754;
        --green-bg: #D1FAE5;
        --red:      #C0392B;
        --red-bg:   #FEE2E2;
        --amber:    #B45309;
        --amber-bg: #FEF3C7;
        --blue:     #1D4ED8;
        --blue-bg:  #DBEAFE;
        --surface:  #F3F4F6;
        --border:   #E2E6EA;
        --shadow:   0 2px 10px rgba(11,29,53,0.07);
        --radius:   10px;
    }

    body { background: var(--surface) !important; }

    /* ── Page header ─────────────────────────────────────────────── */
    .rpt-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
    }

    .rpt-title {
        font-size: 22px;
        font-weight: 700;
        color: var(--navy);
    }

    .rpt-title span {
        display: block;
        font-size: 12px;
        font-weight: 500;
        color: #9AAABB;
        margin-top: 2px;
    }

    /* ── Section label ───────────────────────────────────────────── */
    .section-label {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 2px;
        color: #9AAABB;
        margin-bottom: 12px;
        margin-top: 28px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .section-label::after {
        content: '';
        flex: 1;
        height: 1px;
        background: var(--border);
    }

    /* ── Filter card ─────────────────────────────────────────────── */
    .filter-card {
        background: #fff;
        border-radius: var(--radius);
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        padding: 20px 24px;
        margin-bottom: 24px;
    }

    .filter-card .form-label {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: #9AAABB;
    }

    /* ── History summary table ───────────────────────────────────── */
    .history-card {
        background: #fff;
        border-radius: var(--radius);
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        overflow: hidden;
        margin-bottom: 24px;
    }

    .history-card table thead th {
        background: var(--navy);
        color: #fff;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        padding: 12px 16px;
        border: none;
    }

    .history-card table tbody td {
        padding: 11px 16px;
        font-size: 13px;
        border-color: var(--border);
        vertical-align: middle;
    }

    .history-card table tbody tr:hover { background: var(--surface); }

    .month-badge {
        font-size: 13px;
        font-weight: 600;
        color: var(--navy);
    }

    .count-pill {
        background: var(--blue-bg);
        color: var(--blue);
        font-size: 12px;
        font-weight: 700;
        padding: 4px 14px;
        border-radius: 20px;
        display: inline-block;
    }

    /* ── KPI summary cards ───────────────────────────────────────── */
    .kpi-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
        margin-bottom: 24px;
    }

    .kpi-card {
        background: #fff;
        border-radius: var(--radius);
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        padding: 20px 22px;
        display: flex;
        align-items: center;
        gap: 18px;
        position: relative;
        overflow: hidden;
    }

    .kpi-card::after {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 3px;
        background: var(--kpi-color);
        border-radius: var(--radius) var(--radius) 0 0;
    }

    .kpi-icon {
        width: 48px; height: 48px;
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 22px;
        flex-shrink: 0;
        background: var(--kpi-bg);
        color: var(--kpi-color);
    }

    .kpi-num {
        font-size: 34px;
        font-weight: 800;
        color: var(--navy);
        line-height: 1;
        margin-bottom: 3px;
    }

    .kpi-lbl {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: #9AAABB;
    }

    .kpi-desc {
        font-size: 11.5px;
        color: #5A6A7A;
        margin-top: 4px;
    }

    /* ── Data tables ─────────────────────────────────────────────── */
    .data-card {
        background: #fff;
        border-radius: var(--radius);
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        overflow: hidden;
        margin-bottom: 24px;
    }

    .data-card-head {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 14px 18px;
        border-bottom: 1px solid var(--border);
        font-size: 13px;
        font-weight: 700;
        color: var(--navy);
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .data-card-head .head-icon {
        width: 28px; height: 28px;
        border-radius: 7px;
        display: flex; align-items: center; justify-content: center;
        font-size: 13px;
    }

    .data-card table thead th {
        background: var(--surface);
        color: #5A6A7A;
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.7px;
        padding: 10px 14px;
        border-color: var(--border);
    }

    .data-card table tbody td {
        padding: 10px 14px;
        font-size: 13px;
        border-color: var(--border);
        vertical-align: middle;
    }

    .data-card table tbody tr:hover { background: #FAFBFC; }

    /* ── Empty state ─────────────────────────────────────────────── */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #9AAABB;
    }

    .empty-state i { font-size: 48px; display: block; margin-bottom: 12px; }
    .empty-state p { font-size: 14px; }

    /* ── Print ───────────────────────────────────────────────────── */
    @media print {
        body { background: #fff !important; }
        .no-print { display: none !important; }
        .data-card, .kpi-card, .history-card { box-shadow: none !important; border: 1px solid #ddd !important; }
    }
</style>

<!-- ══ Page Header ═══════════════════════════════════════════════ -->
<div class="rpt-header no-print">
    <div class="rpt-title">
        Operational Report
        <span>Mother coil receipts &amp; finished goods deliveries</span>
    </div>
    <button onclick="window.print()" class="btn btn-dark btn-sm shadow-sm">
        <i class="bi bi-printer me-1"></i> Print Report
    </button>
</div>

<!-- Print-only header -->
<div class="text-center mb-4 d-none d-print-block">
    <h2 class="fw-bold">MONTHLY COIL MANAGEMENT REPORT</h2>
    <?php if ($_SERVER['REQUEST_METHOD'] == 'POST'): ?>
        <h4 class="text-secondary"><?= date('F', mktime(0,0,0,$selected_month,1)) . ' ' . $selected_year ?></h4>
    <?php endif; ?>
    <hr>
</div>

<!-- ══ Historical Summary ═════════════════════════════════════════ -->
<div class="section-label no-print">Mother Coil Receipt History</div>

<div class="history-card no-print">
    <div style="padding:14px 18px; border-bottom:1px solid var(--border); font-size:13px; font-weight:700; color:var(--navy);">
        <i class="bi bi-calendar3 me-2 text-primary"></i>
        All-Time Monthly Inbound Summary
        <span style="font-size:11px; color:#9AAABB; font-weight:400; margin-left:8px;">
            — Total mother coils scanned IN each month
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 text-center">
            <thead>
                <tr>
                    <th style="text-align:left;">Month</th>
                    <th>Mother Coils Received</th>
                    <th>What This Means</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($raw_material_monthly_summary)): ?>
                    <tr><td colspan="3" class="py-4 text-muted">No records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($raw_material_monthly_summary as $s): ?>
                        <tr>
                            <td class="month-badge" style="text-align:left;">
                                <?= date("F Y", strtotime($s['month_year'] . "-01")) ?>
                            </td>
                            <td>
                                <span class="count-pill"><?= (int)$s['total_count'] ?> coils</span>
                            </td>
                            <td style="font-size:12px; color:#9AAABB;">
                                <?= (int)$s['total_count'] ?> mother coil<?= $s['total_count'] != 1 ? 's' : '' ?> 
                                scanned &amp; received into raw material stock
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ══ Filter Form ════════════════════════════════════════════════ -->
<div class="section-label no-print">Generate Detailed Report</div>

<div class="filter-card no-print">
    <form action="report.php" method="post" class="row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Month</label>
            <select name="month" class="form-select form-select-sm">
                <?php for ($i = 1; $i <= 12; $i++): ?>
                    <option value="<?= str_pad($i, 2, '0', STR_PAD_LEFT) ?>" <?= ($selected_month == $i) ? 'selected' : '' ?>>
                        <?= date('F', mktime(0,0,0,$i,1)) ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Year</label>
            <select name="year" class="form-select form-select-sm">
                <?php for ($i = date('Y'); $i >= date('Y') - 5; $i--): ?>
                    <option value="<?= $i ?>" <?= ($selected_year == $i) ? 'selected' : '' ?>><?= $i ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-4">
            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-bar-chart me-1"></i> Generate Report
            </button>
        </div>
    </form>
</div>

<!-- ══ Generated Report ══════════════════════════════════════════ -->
<?php if ($_SERVER['REQUEST_METHOD'] == 'POST'): ?>

    <div class="section-label">
        Report: <?= date('F', mktime(0,0,0,$selected_month,1)) . ' ' . $selected_year ?>
    </div>

    <!-- KPI Summary -->
    <div class="kpi-row">
        <div class="kpi-card" style="--kpi-color:var(--blue); --kpi-bg:var(--blue-bg);">
            <div class="kpi-icon"><i class="bi bi-box-arrow-in-right"></i></div>
            <div>
                <div class="kpi-num"><?= count($raw_material_data) ?></div>
                <div class="kpi-lbl">Mother Coils Received</div>
                <div class="kpi-desc">Scanned into raw material stock this month</div>
            </div>
        </div>
        <div class="kpi-card" style="--kpi-color:var(--green); --kpi-bg:var(--green-bg);">
            <div class="kpi-icon"><i class="bi bi-truck"></i></div>
            <div>
                <div class="kpi-num"><?= count($slitting_product_delivered_data) ?></div>
                <div class="kpi-lbl">Finished Rolls Delivered</div>
                <div class="kpi-desc">Slitted rolls delivered to customers this month</div>
            </div>
        </div>
    </div>

    <!-- Inbound Detail -->
    <div class="data-card">
        <div class="data-card-head">
            <div class="head-icon" style="background:var(--blue-bg); color:var(--blue);">
                <i class="bi bi-box-arrow-in-right"></i>
            </div>
            Raw Material Inbound
            <span style="font-size:11px; color:#9AAABB; font-weight:400; margin-left:6px; text-transform:none; letter-spacing:0;">
                — Mother coils received &amp; scanned into stock
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered mb-0 text-center">
                <thead>
                    <tr>
                        <th style="text-align:left;">Date Received</th>
                        <th>Coil No</th>
                        <th>Product</th>
                        <th>Lot No</th>
                        <th>Width × Length</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($raw_material_data)): ?>
                        <tr><td colspan="5" class="py-4 text-muted">No inbound records for this period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($raw_material_data as $row): ?>
                            <tr>
                                <td style="text-align:left; color:#5A6A7A;">
                                    <?= date("d M Y, H:i", strtotime($row['date_in'])) ?>
                                </td>
                                <td class="fw-bold"><?= htmlspecialchars($row['coil_no']) ?></td>
                                <td>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($row['product']) ?></span>
                                </td>
                                <td><?= htmlspecialchars($row['lot_no']) ?></td>
                                <td class="fw-bold">
                                    <?= number_format($row['width'],1) ?> mm × <?= number_format($row['length'],0) ?> m
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Outbound Detail -->
    <div class="data-card">
        <div class="data-card-head">
            <div class="head-icon" style="background:var(--green-bg); color:var(--green);">
                <i class="bi bi-truck"></i>
            </div>
            Finished Goods Delivered
            <span style="font-size:11px; color:#9AAABB; font-weight:400; margin-left:6px; text-transform:none; letter-spacing:0;">
                — Slitted rolls sent out to customers
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered mb-0 text-center">
                <thead>
                    <tr>
                        <th style="text-align:left;">Delivered At</th>
                        <th>Coil No</th>
                        <th>Product</th>
                        <th>Lot / Roll</th>
                        <th>Width × Length</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($slitting_product_delivered_data)): ?>
                        <tr><td colspan="5" class="py-4 text-muted">No deliveries found for this period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($slitting_product_delivered_data as $row): ?>
                            <tr>
                                <td style="text-align:left; color:#5A6A7A;">
                                    <?= date("d M Y", strtotime($row['delivered_at'])) ?>
                                </td>
                                <td class="fw-bold"><?= htmlspecialchars($row['coil_no']) ?></td>
                                <td><?= htmlspecialchars($row['product']) ?></td>
                                <td class="text-muted small">
                                    <?= htmlspecialchars($row['lot_no']) ?> / <?= htmlspecialchars($row['roll_no']) ?>
                                </td>
                                <td class="fw-bold" style="color:var(--green);">
                                    <?= number_format($row['width'],1) ?> mm × <?= number_format($row['length'],0) ?> m
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>
    <!-- Empty state before generating -->
    <div class="empty-state no-print">
        <i class="bi bi-calendar-range text-muted"></i>
        <p>Select a month and year above, then click <strong>Generate Report</strong> to view detailed records.</p>
    </div>
<?php endif; ?>

<?php include 'footer.php'; ?>
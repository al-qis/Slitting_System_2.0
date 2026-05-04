<?php
session_start();

// 1. Authentication & Role Check
if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION['role'] !== 'slitting') {
    header("Location: index.php");
    exit;
}

include 'config.php';

// 2. Data Fetching Logic
$month = (int)date('m');
$year  = (int)date('Y');

// RAW MATERIAL SUMMARY
$in_raw = $conn->query("SELECT COUNT(*) AS total FROM mother_coil_audit_log 
                        WHERE action_type='IN' AND MONTH(performed_at)=$month AND YEAR(performed_at)=$year")
                ->fetch_assoc()['total'];

$out_raw = $conn->query("SELECT COUNT(*) AS total FROM mother_coil_audit_log 
                         WHERE action_type='OUT' AND MONTH(performed_at)=$month AND YEAR(performed_at)=$year")
                 ->fetch_assoc()['total'];

$stock_raw = $conn->query("SELECT COUNT(*) AS total FROM mother_coil WHERE stock=1")
                   ->fetch_assoc()['total'];

$afterCutStock_raw = $conn->query("SELECT COUNT(*) AS total FROM raw_material_log 
                                   WHERE status='IN' AND action='cut_into_2'")
                           ->fetch_assoc()['total'];

// FINISH PRODUCT SUMMARY
$in_finish = $conn->query("SELECT COUNT(*) AS total FROM slitting_product 
                           WHERE status='IN' AND is_completed=0
                           AND (is_recoiled=0 OR is_recoiled IS NULL) 
                           AND (is_reslitted=0 OR is_reslitted IS NULL)")
                   ->fetch_assoc()['total'];

$stock_finish = $conn->query("SELECT COUNT(*) AS total FROM slitting_product 
                              WHERE status='IN' AND stock_counted=1
                              AND (is_recoiled=0 OR is_recoiled IS NULL) 
                              AND (is_reslitted=0 OR is_reslitted IS NULL)")
                      ->fetch_assoc()['total'];

$out_finish = $conn->query("SELECT COUNT(*) AS total FROM slitting_product 
                            WHERE status='OUT' 
                            AND MONTH(date_out)=$month AND YEAR(date_out)=$year")
                    ->fetch_assoc()['total'];

$deliver_finish = $conn->query("SELECT COUNT(*) AS total FROM slitting_product 
                                WHERE status='DELIVERED' 
                                AND MONTH(delivered_at)=$month AND YEAR(delivered_at)=$year")
                        ->fetch_assoc()['total'];

// SFC & QC
$sfc_active = $conn->query("SELECT COUNT(*) AS total FROM sfc WHERE date_out IS NULL")
                    ->fetch_assoc()['total'] ?? 0;

$waiting_qc = $conn->query("SELECT COUNT(*) AS total FROM slitting_product WHERE status='WAITING'")
                    ->fetch_assoc()['total'] ?? 0;

$page_title = "Dashboard - MK Slitting";
include 'header.php';
?>

<style>
    /* ── Design tokens ───────────────────────────────────────────── */
    :root {
        --navy:      #0B1D35;
        --accent:    #E8A020;
        --steel:     #4A7FA5;
        --green:     #1A8754;
        --green-bg:  #D1FAE5;
        --red:       #C0392B;
        --red-bg:    #FEE2E2;
        --amber:     #B45309;
        --amber-bg:  #FEF3C7;
        --blue:      #1D4ED8;
        --blue-bg:   #DBEAFE;
        --purple-bg: #EDE9FE;
        --purple:    #5B21B6;
        --surface:   #F3F4F6;
        --border:    #E2E6EA;
        --shadow:    0 2px 10px rgba(11,29,53,0.07);
        --shadow-md: 0 4px 20px rgba(11,29,53,0.11);
        --radius:    10px;
    }

    /* ── Page background ─────────────────────────────────────────── */
    body { background: var(--surface) !important; }

    /* ── Page header row ─────────────────────────────────────────── */
    .dash-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
    }

    .dash-title {
        font-size: 22px;
        font-weight: 700;
        color: var(--navy);
        letter-spacing: -0.3px;
    }

    .dash-title span {
        display: block;
        font-size: 12px;
        font-weight: 500;
        color: #9AAABB;
        letter-spacing: 0;
        margin-top: 2px;
    }

    .date-badge {
        background: var(--navy);
        color: #fff;
        font-size: 12px;
        font-weight: 600;
        padding: 6px 16px;
        border-radius: 20px;
        letter-spacing: 0.3px;
    }

    /* ── Scanner alert ───────────────────────────────────────────── */
    .scan-alert {
        display: flex;
        align-items: center;
        gap: 10px;
        background: var(--blue-bg);
        border: 1px solid #BFDBFE;
        border-radius: var(--radius);
        padding: 11px 16px;
        font-size: 13px;
        color: var(--blue);
        font-weight: 500;
        margin-bottom: 20px;
    }

    /* ── Scanner live pill ───────────────────────────────────────── */
    .scanner-pill {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        background: var(--green-bg);
        color: var(--green);
        font-size: 12px;
        font-weight: 600;
        padding: 5px 14px;
        border-radius: 20px;
        border: 1px solid #A7F3D0;
    }

    .pulse-dot {
        width: 7px; height: 7px;
        border-radius: 50%;
        background: var(--green);
        animation: pulse 1.8s ease-in-out infinite;
    }

    @keyframes pulse {
        0%, 100% { opacity: 1; transform: scale(1); }
        50%       { opacity: 0.4; transform: scale(0.65); }
    }

    /* ── Section label ───────────────────────────────────────────── */
    .section-label {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 2px;
        color: #9AAABB;
        margin-bottom: 12px;
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

    /* ── KPI metric cards ────────────────────────────────────────── */
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 14px;
        margin-bottom: 24px;
    }

    .kpi-card {
        background: var(--kpi-bg, #EFF6FF);
        border-radius: var(--radius);
        border: 1px solid var(--kpi-color, var(--steel));
        border-top: 4px solid var(--kpi-color, var(--steel));
        padding: 18px 20px;
        box-shadow: var(--shadow);
        position: relative;
        overflow: hidden;
        transition: transform 0.18s, box-shadow 0.18s;
    }

    .kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    /* top accent now handled by border-top on .kpi-card */

    .kpi-icon {
        width: 36px; height: 36px;
        border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: 16px;
        margin-bottom: 14px;
        background: var(--kpi-bg, #f0f4f8);
        color: var(--kpi-color, var(--steel));
    }

    .kpi-num {
        font-size: 30px;
        font-weight: 800;
        color: var(--kpi-color, var(--navy));
        line-height: 1;
        margin-bottom: 4px;
    }

    .kpi-lbl {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.7px;
        color: #9AAABB;
    }

    .kpi-sub {
        font-size: 11px;
        color: #5A6A7A;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid rgba(0,0,0,0.08);
    }

    .kpi-sub strong { color: #5A6A7A; }

    /* ── Data panels ─────────────────────────────────────────────── */
    .panels-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        margin-bottom: 24px;
    }

    .panel {
        background: #fff;
        border-radius: var(--radius);
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        overflow: hidden;
    }

    .panel-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 18px;
        border-bottom: 1px solid var(--border);
    }

    .panel-title {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        font-weight: 700;
        color: var(--navy);
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .panel-icon {
        width: 26px; height: 26px;
        border-radius: 6px;
        display: flex; align-items: center; justify-content: center;
        font-size: 12px;
    }

    .panel-link {
        font-size: 12px;
        font-weight: 600;
        color: var(--steel);
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 3px;
        transition: color 0.15s;
    }

    .panel-link:hover { color: var(--navy); }

    .panel-body { padding: 16px 18px; }

    .stat-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
    }

    .stat-cell {
        background: #fff;
        border-radius: 8px;
        padding: 12px 8px;
        text-align: center;
        border: 1px solid var(--border);
        border-left: 3px solid currentColor;
    }

    .stat-num {
        font-size: 24px;
        font-weight: 800;
        line-height: 1;
        margin-bottom: 4px;
    }

    .stat-lbl {
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: #9AAABB;
    }

    /* ── Quick action buttons ────────────────────────────────────── */
    .actions-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
    }

    .action-btn {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 16px;
        background: #fff;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        text-decoration: none;
        color: var(--navy);
        font-weight: 600;
        font-size: 13px;
        box-shadow: var(--shadow);
        transition: all 0.18s ease;
    }

    .action-btn:hover {
        border-color: var(--navy);
        border-left: 3px solid var(--navy);
        box-shadow: var(--shadow-md);
        transform: translateY(-1px);
        color: var(--navy);
        background: #F8FAFF;
    }

    .action-icon {
        width: 36px; height: 36px;
        border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: 16px;
        flex-shrink: 0;
    }

    .action-sub {
        font-size: 11px;
        font-weight: 400;
        color: #9AAABB;
        display: block;
        margin-top: 1px;
    }

    /* ── Colour helpers ──────────────────────────────────────────── */
    .c-green  { color: var(--green); }
    .c-red    { color: var(--red); }
    .c-blue   { color: var(--blue); }
    .c-amber  { color: var(--amber); }
    .c-navy   { color: var(--navy); }
    .c-steel  { color: var(--steel); }

    /* ── Responsive ──────────────────────────────────────────────── */
    @media (max-width: 992px) {
        .kpi-grid     { grid-template-columns: repeat(2, 1fr); }
        .panels-grid  { grid-template-columns: 1fr; }
        .actions-grid { grid-template-columns: repeat(2, 1fr); }
    }

    @media (max-width: 576px) {
        .kpi-grid     { grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .kpi-num      { font-size: 24px; }
        .stat-grid    { grid-template-columns: repeat(2, 1fr); }
        .actions-grid { grid-template-columns: 1fr 1fr; }
        .dash-header  { flex-direction: column; align-items: flex-start; gap: 10px; }
    }
</style>

<!-- ══ Page Header ═══════════════════════════════════════════════ -->
<div class="dash-header">
    <div class="dash-title">
        Dashboard
        <span>Metakote Slitting — <?= date('F Y') ?></span>
    </div>
    <div class="d-flex align-items-center gap-3">
        <div class="scanner-pill">
            <div class="pulse-dot"></div>
            Scanner Ready
        </div>
        <div class="date-badge"><?= date('d M Y') ?></div>
    </div>
</div>

<!-- ══ Scan Alert ════════════════════════════════════════════════ -->
<?php if (isset($_GET['scan'])): ?>
<div class="scan-alert">
    <i class="bi bi-qr-code-scan"></i>
    Scan result: <strong><?= htmlspecialchars($_GET['scan']) ?></strong>
</div>
<?php endif; ?>

<!-- ══ KPI Cards ═════════════════════════════════════════════════ -->
<div class="section-label">Overview</div>

<div class="kpi-grid">
    <div class="kpi-card" style="--kpi-color:var(--green); --kpi-bg:var(--green-bg);">
        <div class="kpi-icon"><i class="bi bi-box-seam-fill"></i></div>
        <div class="kpi-num"><?= (int)$stock_raw ?></div>
        <div class="kpi-lbl">Mother Coils in Stock</div>
        <div class="kpi-sub">
            MTD In: <strong><?= (int)$in_raw ?></strong> &nbsp;·&nbsp; MTD Out: <strong><?= (int)$out_raw ?></strong>
        </div>
    </div>

    <div class="kpi-card" style="--kpi-color:var(--amber); --kpi-bg:var(--amber-bg);">
        <div class="kpi-icon"><i class="bi bi-scissors"></i></div>
        <div class="kpi-num"><?= (int)$in_finish ?></div>
        <div class="kpi-lbl">Pending Production</div>
        <div class="kpi-sub">Stock counted: <strong><?= (int)$stock_finish ?></strong></div>
    </div>

    <div class="kpi-card" style="--kpi-color:var(--blue); --kpi-bg:var(--blue-bg);">
        <div class="kpi-icon"><i class="bi bi-box-seam"></i></div>
        <div class="kpi-num"><?= (int)$sfc_active ?></div>
        <div class="kpi-lbl">Active SFC Items</div>
        <div class="kpi-sub">Awaiting next process</div>
    </div>

    <div class="kpi-card" style="--kpi-color:var(--red); --kpi-bg:var(--red-bg);">
        <div class="kpi-icon"><i class="bi bi-truck"></i></div>
        <div class="kpi-num"><?= (int)$deliver_finish ?></div>
        <div class="kpi-lbl">Delivered This Month</div>
        <div class="kpi-sub">
            Out: <strong><?= (int)$out_finish ?></strong> &nbsp;·&nbsp; QC Waiting: <strong><?= (int)$waiting_qc ?></strong>
        </div>
    </div>
</div>

<!-- ══ Breakdown Panels ══════════════════════════════════════════ -->
<div class="section-label">Monthly Breakdown</div>

<div class="panels-grid">

    <!-- Raw Material -->
    <div class="panel">
        <div class="panel-head">
            <div class="panel-title">
                <div class="panel-icon" style="background:var(--green-bg); color:var(--green);">
                    <i class="bi bi-boxes"></i>
                </div>
                Raw Material
            </div>
            <a href="raw_material.php" class="panel-link">
                View all <i class="bi bi-arrow-right"></i>
            </a>
        </div>
        <div class="panel-body">
            <div class="stat-grid">
                <div class="stat-cell">
                    <div class="stat-num c-green"><?= (int)$in_raw ?></div>
                    <div class="stat-lbl">MTD IN</div>
                </div>
                <div class="stat-cell">
                    <div class="stat-num c-red"><?= (int)$out_raw ?></div>
                    <div class="stat-lbl">MTD OUT</div>
                </div>
                <div class="stat-cell">
                    <div class="stat-num c-navy"><?= (int)$stock_raw ?></div>
                    <div class="stat-lbl">LIVE STOCK</div>
                </div>
                <div class="stat-cell">
                    <div class="stat-num c-amber"><?= (int)$afterCutStock_raw ?></div>
                    <div class="stat-lbl">AFTER CUT</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Slitting Product -->
    <div class="panel">
        <div class="panel-head">
            <div class="panel-title">
                <div class="panel-icon" style="background:var(--blue-bg); color:var(--blue);">
                    <i class="bi bi-check-circle"></i>
                </div>
                Slitting Product
            </div>
            <a href="finish_product.php" class="panel-link">
                View all <i class="bi bi-arrow-right"></i>
            </a>
        </div>
        <div class="panel-body">
            <div class="stat-grid">
                <div class="stat-cell">
                    <div class="stat-num c-green"><?= (int)$in_finish ?></div>
                    <div class="stat-lbl">IN</div>
                </div>
                <div class="stat-cell">
                    <div class="stat-num c-blue"><?= (int)$stock_finish ?></div>
                    <div class="stat-lbl">STOCK</div>
                </div>
                <div class="stat-cell">
                    <div class="stat-num c-red"><?= (int)$out_finish ?></div>
                    <div class="stat-lbl">OUT</div>
                </div>
                <div class="stat-cell">
                    <div class="stat-num c-amber"><?= (int)$deliver_finish ?></div>
                    <div class="stat-lbl">DELIVER</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ Quick Actions ═════════════════════════════════════════════ -->
<div class="section-label">Quick Actions</div>

<div class="actions-grid">
    <a href="raw_material.php" class="action-btn">
        <div class="action-icon" style="background:var(--green-bg); color:var(--green);">
            <i class="bi bi-boxes"></i>
        </div>
        <div>
            Raw Material
            <span class="action-sub">View &amp; scan stock</span>
        </div>
    </a>

    <a href="finish_product.php" class="action-btn">
        <div class="action-icon" style="background:var(--blue-bg); color:var(--blue);">
            <i class="bi bi-scissors"></i>
        </div>
        <div>
            Finish Product
            <span class="action-sub">Slitting records</span>
        </div>
    </a>

    <a href="sfc.php" class="action-btn">
        <div class="action-icon" style="background:var(--amber-bg); color:var(--amber);">
            <i class="bi bi-box-seam"></i>
        </div>
        <div>
            SFC Inventory
            <span class="action-sub"><?= (int)$sfc_active ?> active items</span>
        </div>
    </a>

    <a href="stock_log.php" class="action-btn">
        <div class="action-icon" style="background:var(--purple-bg); color:var(--purple);">
            <i class="bi bi-clock-history"></i>
        </div>
        <div>
            Stock Log
            <span class="action-sub">Full audit trail</span>
        </div>
    </a>

    <a href="sfc_tracking_report.php" class="action-btn">
        <div class="action-icon" style="background:#FCE7F3; color:#9D174D;">
            <i class="bi bi-graph-up"></i>
        </div>
        <div>
            SFC Report
            <span class="action-sub">Tracking &amp; analytics</span>
        </div>
    </a>

    <a href="raw_material_export.php" class="action-btn">
        <div class="action-icon" style="background:#ECFDF5; color:#065F46;">
            <i class="bi bi-file-earmark-excel"></i>
        </div>
        <div>
            Export Data
            <span class="action-sub">Download Excel</span>
        </div>
    </a>
</div>

<!-- ══ Hidden scanner form — identical to original ═══════════════ -->
<form id="scanForm" method="post" action="scan_mother_action.php" autocomplete="off" style="position:absolute; left:-9999px;">
    <input id="qrInput" type="text" name="qr" inputmode="none" autofocus>
</form>

<script>
    // Scanner Focus Logic — identical to original
    const isTouchDevice = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);

    document.addEventListener('click', function () {
        const el = document.activeElement;
        if (!isTouchDevice || (el.tagName !== 'INPUT' && el.tagName !== 'SELECT' && el.tagName !== 'TEXTAREA')) {
            document.getElementById('qrInput').focus();
        }
    });

    const qrInput = document.getElementById('qrInput');
    qrInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && this.value.trim() !== '') {
            document.getElementById('scanForm').submit();
        }
    });
</script>

<?php include 'footer.php'; ?>
<?php
$page_title = "Dashboard";
include dirname(__DIR__, 3) . '/header.php';
?>

<style>
/* ── Google Font ── */
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Mono:wght@500&display=swap');

/* ── Tokens ── */
:root {
    --bg:       #F0F2F5;
    --surface:  #FFFFFF;
    --border:   #E4E7EC;
    --navy:     #0D1B2A;
    --navy2:    #1B2E45;
    --text:     #1A2332;
    --muted:    #6B7A90;
    --blue:     #1D68BD;
    --blue-lt:  #EBF3FC;
    --teal:     #0E8A72;
    --teal-lt:  #E6F6F3;
    --amber:    #B45309;
    --amber-lt: #FEF3C7;
    --purple:   #5B32A8;
    --purple-lt:#F0EBF8;
    --green:    #15803D;
    --green-lt: #DCFCE7;
    --radius:   12px;
}

body { background: var(--bg) !important; font-family: 'DM Sans', sans-serif; color: var(--text); }

/* ── Top Bar ── */
.topbar {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 24px; padding-bottom: 16px;
    border-bottom: 1px solid var(--border);
}
.topbar-title { font-size: 20px; font-weight: 800; color: var(--navy); margin: 0; }
.topbar-sub   { font-size: 13px; color: var(--muted); margin: 0; }
.scanner-badge {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 6px 14px; border-radius: 20px;
    background: #E8F5E9; border: 1px solid #A5D6A7;
    color: #1B5E20; font-size: 12px; font-weight: 700;
}
.scanner-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #2E7D32; animation: pulse 1.8s infinite;
}
@keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(.75)} }

/* ── KPI Grid ── */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px; margin-bottom: 24px;
}
@media(max-width:992px){ .kpi-grid { grid-template-columns: repeat(2,1fr); } }
@media(max-width:576px){ .kpi-grid { grid-template-columns: 1fr; } }

.kpi-card {
    background: var(--surface);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    border-top: 4px solid var(--accent, #94A3B8);
    padding: 20px;
    display: flex; flex-direction: column; justify-content: space-between;
    transition: transform .15s, box-shadow .15s;
    text-decoration: none; color: inherit;
}
.kpi-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,.08); color: inherit; }

.kpi-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.kpi-label  { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--muted); }
.kpi-icon   {
    width: 36px; height: 36px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; background: var(--icon-bg, #F1F5F9); color: var(--accent, #64748B);
}
.kpi-num { font-size: 38px; font-weight: 800; line-height: 1; color: var(--navy); margin-bottom: 10px; }
.kpi-sub { font-size: 12px; color: var(--muted); display: flex; gap: 8px; flex-wrap: wrap; }
.kpi-sub span { font-weight: 600; color: var(--text); }

/* KPI themes */
.kpi-raw     { --accent: var(--teal);   --icon-bg: var(--teal-lt); }
.kpi-sfc     { --accent: var(--purple); --icon-bg: var(--purple-lt); }
.kpi-fg      { --accent: var(--blue);   --icon-bg: var(--blue-lt); }
.kpi-deliver { --accent: var(--green);  --icon-bg: var(--green-lt); }

/* ── Section title ── */
.sec-title {
    font-size: 13px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .6px; color: var(--muted); margin-bottom: 12px;
    display: flex; align-items: center; gap: 8px;
}

/* ── Quick Nav Grid ── */
.quick-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px; margin-bottom: 24px;
}
@media(max-width:992px){ .quick-grid { grid-template-columns: repeat(2,1fr); } }

.quick-btn {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 14px 16px;
    display: flex; align-items: center; gap: 12px;
    text-decoration: none; color: var(--navy);
    font-weight: 600; font-size: 13px;
    transition: all .15s;
}
.quick-btn:hover {
    background: var(--navy); color: #fff !important;
    border-color: var(--navy); transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(13,27,42,.15);
}
.quick-btn i { font-size: 20px; color: var(--blue); transition: color .15s; }
.quick-btn:hover i { color: #60A5FA; }

/* ── Two column row ── */
.mid-row {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 16px; margin-bottom: 24px;
}
@media(max-width:992px){ .mid-row { grid-template-columns: 1fr; } }

/* ── Table Card ── */
.card-box {
    background: var(--surface);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    overflow: hidden;
}
.card-box-header {
    padding: 14px 18px;
    display: flex; align-items: center; justify-content: space-between;
    border-bottom: 1px solid var(--border);
}
.card-box-title { font-size: 13px; font-weight: 700; color: var(--navy); margin: 0; }
.card-box-link  { font-size: 12px; font-weight: 600; color: var(--blue); text-decoration: none; }
.card-box-link:hover { text-decoration: underline; }

.recent-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.recent-table th {
    background: #F8FAFC; color: var(--muted);
    font-weight: 700; text-transform: uppercase; font-size: 10px; letter-spacing: .5px;
    padding: 8px 14px; border-bottom: 1px solid var(--border); text-align: left;
}
.recent-table td {
    padding: 9px 14px; border-bottom: 1px solid #F1F5F9;
    color: var(--text); vertical-align: middle;
}
.recent-table tr:last-child td { border-bottom: none; }
.recent-table tr:hover td { background: #F8FAFC; }
.lot-badge { font-family: 'DM Mono', monospace; font-weight: 500; font-size: 11px; }

/* Status pill */
.pill {
    display: inline-block; padding: 2px 7px; border-radius: 12px;
    font-size: 10px; font-weight: 700; letter-spacing: .3px;
}
.pill-in        { background: #DCFCE7; color: #166534; }
.pill-waiting   { background: #FEF9C3; color: #854D0E; }
.pill-delivered { background: #E0E7FF; color: #3730A3; }
.pill-approved  { background: #F0FDF4; color: #15803D; }

/* ── Process Flow Bar ── */
.flow-card {
    background: var(--surface);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    padding: 18px;
}
.flow-steps {
    display: flex; align-items: center; justify-content: space-between;
    gap: 8px; flex-wrap: wrap;
}
.flow-step {
    flex: 1; min-width: 90px;
    background: #F8FAFC; border: 1px solid var(--border);
    border-radius: 8px; padding: 10px 8px; text-align: center;
    text-decoration: none; color: inherit; transition: all .15s;
}
.flow-step:hover { background: var(--navy); color: #fff !important; border-color: var(--navy); }
.flow-step-num { font-size: 10px; font-weight: 800; color: var(--muted); margin-bottom: 2px; }
.flow-step:hover .flow-step-num { color: #94A3B8; }
.flow-step-name { font-size: 11px; font-weight: 700; color: var(--navy); }
.flow-step:hover .flow-step-name { color: #fff; }
.flow-arrow { color: var(--border); font-size: 16px; flex-shrink: 0; }

/* ── Stock Health Summary ── */
.health-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 18px; border-bottom: 1px solid #F1F5F9; font-size: 12px;
}
.health-item:last-child { border-bottom: none; }
.health-label { color: var(--muted); font-weight: 500; }
.health-val   { font-weight: 700; color: var(--navy); font-size: 13px; }
</style>

<!-- ── Topbar ── -->
<div class="topbar">
    <div>
        <h1 class="topbar-title">MK Slitting Production System</h1>
        <p class="topbar-sub">Overview &amp; Inventory Status &mdash; <?= date('l, d F Y') ?></p>
    </div>
    <div class="d-flex align-items-center gap-3">
        <span class="scanner-badge">
            <span class="scanner-dot"></span>
            Scanner Ready
        </span>
        <span style="font-size:12px; color:var(--muted);">
            Logged in as <strong><?= htmlspecialchars($_SESSION['username'] ?? $_SESSION['role']) ?></strong>
        </span>
    </div>
</div>

<!-- ── KPI Cards ── -->
<div class="kpi-grid">

    <!-- 1. Raw Material -->
    <a href="raw_material.php" class="kpi-card kpi-raw">
        <div class="kpi-header">
            <span class="kpi-label">Raw Material Stock</span>
            <div class="kpi-icon"><i class="bi bi-boxes"></i></div>
        </div>
        <div class="kpi-num"><?= number_format($raw_stock) ?></div>
        <div class="kpi-sub">
            Mother: <span><?= $raw_mother ?></span> &middot;
            Cut Into 2: <span><?= $raw_leftover ?></span> &middot;
            MTD IN: <span><?= $mtd_in_raw ?></span>
        </div>
    </a>

    <!-- 2. SFC -->
    <a href="sfc.php" class="kpi-card kpi-sfc">
        <div class="kpi-header">
            <span class="kpi-label">SFC Active Items</span>
            <div class="kpi-icon"><i class="bi bi-box-seam-fill"></i></div>
        </div>
        <div class="kpi-num"><?= number_format($sfc_total) ?></div>
        <div class="kpi-sub">
            Active in SFC buffer &middot; <span><?= $sfc_total ?> rolls</span>
        </div>
    </a>

    <!-- 3. Finish Good -->
    <a href="finish_product.php" class="kpi-card kpi-fg">
        <div class="kpi-header">
            <span class="kpi-label">Finish Good (In Stock)</span>
            <div class="kpi-icon"><i class="bi bi-check-circle-fill"></i></div>
        </div>
        <div class="kpi-num"><?= number_format($fg_stock) ?></div>
        <div class="kpi-sub">
            Pending QC: <span><?= $fg_pending ?></span> &middot;
            Waiting: <span><?= $fg_waiting ?></span>
        </div>
    </a>

    <!-- 4. Delivered MTD -->
    <a href="finish_product.php?filter=deliver&month=<?= $month ?>&year=<?= $year ?>" class="kpi-card kpi-deliver">
        <div class="kpi-header">
            <span class="kpi-label">Delivered (<?= date('M Y') ?>)</span>
            <div class="kpi-icon"><i class="bi bi-truck"></i></div>
        </div>
        <div class="kpi-num"><?= number_format($delivered) ?></div>
        <div class="kpi-sub">
            Approved: <span><?= $delivered_approved ?></span> &middot;
            Delivered MTD: <span><?= $delivered ?></span>
        </div>
    </a>

</div>

<!-- ── Flow Steps ── -->
<div class="flow-card mb-4">
    <div class="sec-title"><i class="bi bi-diagram-3-fill"></i> Production Workflow</div>
    <div class="flow-steps">
        <a href="mother_coil.php" class="flow-step">
            <div class="flow-step-num">STEP 1</div>
            <div class="flow-step-name">Mother Coil</div>
        </a>
        <i class="bi bi-chevron-right flow-arrow"></i>
        <a href="raw_material.php" class="flow-step">
            <div class="flow-step-num">STEP 2</div>
            <div class="flow-step-name">Raw Material</div>
        </a>
        <i class="bi bi-chevron-right flow-arrow"></i>
        <a href="slitting_product.php" class="flow-step">
            <div class="flow-step-num">STEP 3</div>
            <div class="flow-step-name">Slitting Plan</div>
        </a>
        <i class="bi bi-chevron-right flow-arrow"></i>
        <a href="sfc.php" class="flow-step">
            <div class="flow-step-num">STEP 4</div>
            <div class="flow-step-name">SFC Buffer</div>
        </a>
        <i class="bi bi-chevron-right flow-arrow"></i>
        <a href="finish_product.php" class="flow-step">
            <div class="flow-step-num">STEP 5</div>
            <div class="flow-step-name">Finish Product</div>
        </a>
        <i class="bi bi-chevron-right flow-arrow"></i>
        <a href="pallet.php" class="flow-step">
            <div class="flow-step-num">STEP 6</div>
            <div class="flow-step-name">Palletise &amp; Deliver</div>
        </a>
    </div>
</div>

<!-- ── Mid Row: Recent Activity + Summary ── -->
<div class="mid-row">

    <!-- Recent Slit Rolls -->
    <div class="card-box">
        <div class="card-box-header">
            <span class="card-box-title"><i class="bi bi-clock-history me-1"></i> Recently Produced Rolls</span>
            <a href="finish_product.php" class="card-box-link">View All &rarr;</a>
        </div>
        <table class="recent-table">
            <thead>
                <tr>
                    <th>Lot / Coil</th>
                    <th>Roll</th>
                    <th>Width</th>
                    <th>Length</th>
                    <th>Status</th>
                    <th>Date In</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($recent_rows)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No recent production records</td></tr>
            <?php else: ?>
                <?php foreach ($recent_rows as $r):
                    $st = strtoupper($r['status'] ?? 'IN');
                    $pillClass = match($st) {
                        'IN'        => 'pill-in',
                        'WAITING'   => 'pill-waiting',
                        'DELIVERED' => 'pill-delivered',
                        'APPROVED'  => 'pill-approved',
                        default     => 'pill-in',
                    };
                    $len = !empty($r['actual_length']) && $r['actual_length'] > 0
                        ? number_format((float)$r['actual_length'], 1) . 'm'
                        : '-';
                ?>
                <tr>
                    <td class="lot-badge">
                        <strong><?= htmlspecialchars($r['lot_no'] ?? '') ?></strong>
                        <span class="text-muted"><?= htmlspecialchars($r['coil_no'] ?? '') ?></span>
                    </td>
                    <td>
                        <span class="badge bg-light text-dark border" style="font-size:10px;">
                            <?= htmlspecialchars(str_replace('R','R-',$r['roll_no'] ?? '')) ?>
                        </span>
                    </td>
                    <td><?= number_format((float)($r['width'] ?? 0)) ?> mm</td>
                    <td><?= $len ?></td>
                    <td><span class="pill <?= $pillClass ?>"><?= $st ?></span></td>
                    <td class="text-muted" style="font-size:11px;">
                        <?= !empty($r['date_in']) ? date('d M, H:i', strtotime($r['date_in'])) : '-' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Quick Actions / Snapshot -->
    <div class="d-flex flex-column gap-3">

        <div class="card-box">
            <div class="card-box-header">
                <span class="card-box-title"><i class="bi bi-lightning-charge-fill me-1"></i> Quick Links</span>
            </div>
            <div style="padding: 10px 14px; display:flex; flex-direction:column; gap:8px;">
                <a href="raw_material.php" class="quick-btn" style="padding:10px 12px;">
                    <i class="bi bi-upc-scan"></i> Scan Raw Material
                </a>
                <a href="slitting_product.php" class="quick-btn" style="padding:10px 12px;">
                    <i class="bi bi-scissors"></i> Slitting Products
                </a>
                <a href="recoiling.php" class="quick-btn" style="padding:10px 12px;">
                    <i class="bi bi-arrow-repeat"></i> Recoiling Cut
                </a>
                <a href="reslit.php" class="quick-btn" style="padding:10px 12px;">
                    <i class="bi bi-intersect"></i> Reslit Product
                </a>
                <a href="pallet.php" class="quick-btn" style="padding:10px 12px;">
                    <i class="bi bi-archive-fill"></i> Pallet Packing
                </a>
                <a href="report.php" class="quick-btn" style="padding:10px 12px;">
                    <i class="bi bi-file-earmark-bar-graph"></i> Monthly Report
                </a>
                <a href="tracking_product.php" class="quick-btn" style="padding:10px 12px;">
                    <i class="bi bi-globe2"></i> Traceability
                </a>
            </div>
        </div>

        <div class="card-box">
            <div class="card-box-header">
                <span class="card-box-title"><i class="bi bi-speedometer me-1"></i> Snapshot</span>
            </div>
            <div>
                <div class="health-item">
                    <span class="health-label">Raw Material in Stock</span>
                    <span class="health-val"><?= $raw_stock ?> coils</span>
                </div>
                <div class="health-item">
                    <span class="health-label">SFC Buffer</span>
                    <span class="health-val"><?= $sfc_total ?> items</span>
                </div>
                <div class="health-item">
                    <span class="health-label">FG Ready in Stock</span>
                    <span class="health-val"><?= $fg_stock ?> rolls</span>
                </div>
                <div class="health-item">
                    <span class="health-label">FG Pending Status</span>
                    <span class="health-val" style="color:var(--amber);"><?= $fg_pending ?> rolls</span>
                </div>
                <div class="health-item">
                    <span class="health-label">Delivered This Month</span>
                    <span class="health-val" style="color:var(--green);"><?= $delivered ?> rolls</span>
                </div>
            </div>
        </div>

    </div>

</div>

<!-- Hidden global scanner input -->
<form id="scanForm" action="scan_product_action.php" method="POST"
      style="position:absolute; left:-9999px;">
    <input id="qrInput" type="text" name="qr" inputmode="none" autofocus>
</form>

<script>
const isTouchDevice = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);

document.addEventListener('click', function () {
    const el = document.activeElement;
    if (!isTouchDevice || !['INPUT','SELECT','TEXTAREA'].includes(el.tagName)) {
        document.getElementById('qrInput').focus();
    }
});

document.getElementById('qrInput').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && this.value.trim() !== '') {
        document.getElementById('scanForm').submit();
    }
});
</script>

<?php include dirname(__DIR__, 3) . '/footer.php'; ?>

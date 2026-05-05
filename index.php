<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION['role'] !== 'slitting') {
    header("Location: index.php");
    exit;
}

include 'config.php';

$month = (int)date('m');
$year  = (int)date('Y');

// ── 1. Raw Material Available Stock ─────────────────────────
$raw_stock = (int)$conn->query("
    SELECT COUNT(*) AS total FROM stock_raw_material WHERE status='IN'
")->fetch_assoc()['total'];

$raw_mother = (int)$conn->query("
    SELECT COUNT(*) AS total FROM stock_raw_material 
    WHERE status='IN' AND source_type='mother_coil'
")->fetch_assoc()['total'];

$raw_leftover = (int)$conn->query("
    SELECT COUNT(*) AS total FROM stock_raw_material 
    WHERE status='IN' AND source_type='slitting_cut_into_2'
")->fetch_assoc()['total'];

// ── 2. SFC Active Items ──────────────────────────────────────
$sfc_total = (int)$conn->query("
    SELECT COUNT(*) AS total FROM sfc WHERE date_out IS NULL
")->fetch_assoc()['total'];

// ── 3. Finish Good Stock ─────────────────────────────────────
$fg_stock = (int)$conn->query("
    SELECT COUNT(*) AS total FROM slitting_product 
    WHERE status='IN' AND stock_counted=1
    AND (is_recoiled=0 OR is_recoiled IS NULL)
    AND (is_reslitted=0 OR is_reslitted IS NULL)
")->fetch_assoc()['total'];

$fg_pending = (int)$conn->query("
    SELECT COUNT(*) AS total FROM slitting_product 
    WHERE status='IN' AND is_completed=0
    AND (is_recoiled=0 OR is_recoiled IS NULL)
    AND (is_reslitted=0 OR is_reslitted IS NULL)
")->fetch_assoc()['total'];

$fg_waiting = (int)$conn->query("
    SELECT COUNT(*) AS total FROM slitting_product WHERE status='WAITING'
")->fetch_assoc()['total'];

// ── 4. Delivered This Month ──────────────────────────────────
$delivered = (int)$conn->query("
    SELECT COUNT(*) AS total FROM slitting_product 
    WHERE status='DELIVERED'
    AND MONTH(delivered_at)=$month AND YEAR(delivered_at)=$year
")->fetch_assoc()['total'];

$delivered_approved = (int)$conn->query("
    SELECT COUNT(*) AS total FROM slitting_product WHERE status='APPROVED'
")->fetch_assoc()['total'];

$mtd_in_raw = (int)$conn->query("
    SELECT COUNT(*) AS total FROM stock_raw_material
    WHERE MONTH(date_in)=$month AND YEAR(date_in)=$year
")->fetch_assoc()['total'];

// ── Recent rolls ─────────────────────────────────────────────
$recent = $conn->query("
    SELECT lot_no, coil_no, roll_no, width, actual_length, status, date_in
    FROM slitting_product
    WHERE (is_recoiled=0 OR is_recoiled IS NULL)
      AND (is_reslitted=0 OR is_reslitted IS NULL)
    ORDER BY id DESC LIMIT 8
");
$recent_rows = [];
if ($recent) while ($r = $recent->fetch_assoc()) $recent_rows[] = $r;

$page_title = "Dashboard";
include 'header.php';
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
    --muted:    #8A96A3;
    --shadow-s: 0 1px 4px rgba(13,27,42,.06);
    --shadow-m: 0 4px 20px rgba(13,27,42,.10);
    --shadow-l: 0 8px 40px rgba(13,27,42,.14);
    --r:        14px;
    --r-sm:     8px;
}

body {
    background: var(--bg) !important;
    font-family: 'DM Sans', sans-serif !important;
    color: var(--text);
}

/* ── Top bar ── */
.db-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 28px;
    padding: 0 2px;
}
.db-greeting {
    font-size: 24px;
    font-weight: 800;
    color: var(--navy);
    letter-spacing: -.5px;
    line-height: 1.1;
}
.db-greeting span {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--muted);
    margin-top: 3px;
    letter-spacing: 0;
}
.db-pills {
    display: flex;
    align-items: center;
    gap: 10px;
}
.pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    padding: 7px 16px;
    border-radius: 30px;
}
.pill-date {
    background: var(--navy);
    color: #fff;
}
.pill-scanner {
    background: #E8F9F1;
    color: #0F7A4B;
    border: 1px solid #B2EDD3;
}
.dot-live {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: #0F7A4B;
    animation: blink 2s ease-in-out infinite;
}
@keyframes blink {
    0%,100% { opacity:1; transform:scale(1); }
    50%      { opacity:.3; transform:scale(.55); }
}

/* ── Section heading ── */
.sec-head {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    color: var(--muted);
    margin: 0 0 14px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.sec-head::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}

/* ══════════════════════════════════════════
   4 MAIN KPI CARDS — clean row
══════════════════════════════════════════ */
.kpi-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 28px;
}

.kpi {
    background: var(--surface);
    border-radius: var(--r);
    border: 1px solid var(--border);
    box-shadow: var(--shadow-s);
    padding: 0;
    overflow: hidden;
    text-decoration: none;
    color: var(--text);
    display: flex;
    flex-direction: column;
    transition: transform .2s, box-shadow .2s;
    position: relative;
}
.kpi:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-l);
    color: var(--text);
}

/* Coloured top stripe */
.kpi-stripe {
    height: 5px;
    background: var(--kc);
    border-radius: var(--r) var(--r) 0 0;
}

/* Icon + number block */
.kpi-body {
    padding: 20px 22px 16px;
    flex: 1;
}

.kpi-icon {
    width: 42px; height: 42px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    background: var(--ki-bg);
    color: var(--kc);
    margin-bottom: 18px;
}

.kpi-num {
    font-size: 42px;
    font-weight: 800;
    color: var(--kc);
    line-height: 1;
    letter-spacing: -1px;
    margin-bottom: 5px;
}

.kpi-label {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--muted);
}

/* Sub-row at bottom of card */
.kpi-foot {
    border-top: 1px solid var(--border);
    padding: 10px 22px;
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    background: #FAFBFC;
}
.kpi-foot-item {
    font-size: 11px;
    color: var(--muted);
    display: flex;
    align-items: center;
    gap: 4px;
}
.kpi-foot-item strong {
    color: var(--text);
    font-weight: 700;
}
.kpi-dot {
    width: 5px; height: 5px;
    border-radius: 50%;
    background: var(--kc);
    opacity: .6;
    flex-shrink: 0;
}

/* Card colour themes */
.kpi-green  { --kc: #059669; --ki-bg: #ECFDF5; }
.kpi-amber  { --kc: #D97706; --ki-bg: #FFFBEB; }
.kpi-blue   { --kc: #2563EB; --ki-bg: #EFF6FF; }
.kpi-violet { --kc: #7C3AED; --ki-bg: #F5F3FF; }

/* ══════════════════════════════════════════
   QUICK ACTIONS
══════════════════════════════════════════ */
.qa-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 28px;
}

.qa-btn {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 15px 18px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r);
    box-shadow: var(--shadow-s);
    text-decoration: none;
    color: var(--text);
    font-weight: 600;
    font-size: 13px;
    transition: all .18s;
    position: relative;
    overflow: hidden;
}
.qa-btn::before {
    content: '';
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 3px;
    background: var(--qa-c, var(--border));
    border-radius: 2px 0 0 2px;
    transform: scaleY(0);
    transition: transform .18s;
}
.qa-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-m);
    color: var(--text);
    border-color: rgba(0,0,0,.1);
}
.qa-btn:hover::before { transform: scaleY(1); }

.qa-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    background: var(--qa-bg);
    color: var(--qa-c);
}
.qa-sub {
    font-size: 11px;
    font-weight: 400;
    color: var(--muted);
    display: block;
    margin-top: 1px;
}
.qa-arrow {
    margin-left: auto;
    font-size: 14px;
    color: var(--muted);
    transition: transform .18s, color .18s;
}
.qa-btn:hover .qa-arrow {
    transform: translateX(3px);
    color: var(--qa-c);
}

/* ══════════════════════════════════════════
   RECENT ACTIVITY
══════════════════════════════════════════ */
.activity-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r);
    box-shadow: var(--shadow-s);
    overflow: hidden;
    margin-bottom: 28px;
}
.ac-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 22px;
    border-bottom: 1px solid var(--border);
}
.ac-title {
    font-size: 13px;
    font-weight: 700;
    color: var(--navy);
    text-transform: uppercase;
    letter-spacing: .5px;
    display: flex; align-items: center; gap: 8px;
}
.ac-title-icon {
    width: 28px; height: 28px;
    border-radius: 7px;
    background: #EFF6FF;
    color: #2563EB;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px;
}
.ac-link {
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
    text-decoration: none;
    display: flex; align-items: center; gap: 3px;
    transition: color .15s;
}
.ac-link:hover { color: var(--navy); }

.ac-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 22px;
    border-bottom: 1px solid #F5F6F8;
    transition: background .12s;
}
.ac-row:last-child { border-bottom: none; }
.ac-row:hover { background: #FAFBFC; }

.ac-lot {
    font-family: 'DM Mono', monospace;
    font-size: 12px;
    font-weight: 500;
    color: var(--navy);
    min-width: 180px;
    letter-spacing: -.3px;
}

.ac-chips {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    flex: 1;
}

.chip {
    font-size: 11px;
    font-weight: 600;
    padding: 3px 9px;
    border-radius: 20px;
}
.chip-w { background: #EFF6FF; color: #1E40AF; }
.chip-l { background: #F0FDF4; color: #166534; }

.ac-date {
    font-size: 11px;
    color: var(--muted);
    white-space: nowrap;
    font-family: 'DM Mono', monospace;
}

/* Status badges */
.sb {
    font-size: 10px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 5px;
    text-transform: uppercase;
    letter-spacing: .5px;
    white-space: nowrap;
}
.sb-in        { background:#DCFCE7; color:#166534; }
.sb-waiting   { background:#FEF3C7; color:#92400E; }
.sb-approved  { background:#DBEAFE; color:#1E40AF; }
.sb-delivered { background:#D1FAE5; color:#065F46; }
.sb-rejected  { background:#FEE2E2; color:#991B1B; }

/* ── Responsive ── */
@media(max-width:992px) {
    .kpi-row  { grid-template-columns: repeat(2,1fr); }
    .qa-grid  { grid-template-columns: repeat(2,1fr); }
}
@media(max-width:576px) {
    .kpi-row  { grid-template-columns: 1fr 1fr; gap:10px; }
    .kpi-num  { font-size:32px; }
    .qa-grid  { grid-template-columns: 1fr 1fr; }
    .db-topbar{ flex-direction:column; align-items:flex-start; gap:12px; }
    .ac-lot   { min-width:120px; font-size:11px; }
}
</style>

<!-- ══ Top Bar ════════════════════════════════════════════════ -->
<div class="db-topbar">
    <div class="db-greeting">
        Dashboard
        <span>Metakote Slitting &nbsp;·&nbsp; <?= date('F Y') ?></span>
    </div>
    <div class="db-pills">
        <div class="pill pill-scanner">
            <div class="dot-live"></div>
            Scanner Ready
        </div>
        <div class="pill pill-date">
            <i class="bi bi-calendar3"></i>
            <?= date('d M Y') ?>
        </div>
    </div>
</div>

<?php if (isset($_GET['scan'])): ?>
<div class="alert alert-info d-flex align-items-center gap-2 py-2 mb-3 rounded-3">
    <i class="bi bi-qr-code-scan fs-5"></i>
    <span>Scan result: <strong><?= htmlspecialchars($_GET['scan']) ?></strong></span>
</div>
<?php endif; ?>

<!-- ══ 4 KPI CARDS ════════════════════════════════════════════ -->
<div class="sec-head">Live Overview</div>

<div class="kpi-row">

    <!-- 1 · Raw Material Stock -->
    <a href="raw_material.php" class="kpi kpi-green">
        <div class="kpi-stripe"></div>
        <div class="kpi-body">
            <div class="kpi-icon">
                <i class="bi bi-boxes"></i>
            </div>
            <div class="kpi-num"><?= $raw_stock ?></div>
            <div class="kpi-label">Raw Material Stock</div>
        </div>
        <div class="kpi-foot">
            <div class="kpi-foot-item">
                <div class="kpi-dot"></div>
                Mother: <strong><?= $raw_mother ?></strong>
            </div>
            <div class="kpi-foot-item">
                <div class="kpi-dot"></div>
                Leftover: <strong><?= $raw_leftover ?></strong>
            </div>
        </div>
    </a>

    <!-- 2 · SFC Items -->
    <a href="sfc.php" class="kpi kpi-amber">
        <div class="kpi-stripe"></div>
        <div class="kpi-body">
            <div class="kpi-icon">
                <i class="bi bi-box-seam-fill"></i>
            </div>
            <div class="kpi-num"><?= $sfc_total ?></div>
            <div class="kpi-label">SFC Items</div>
        </div>
        <div class="kpi-foot">
            <div class="kpi-foot-item">
                <div class="kpi-dot"></div>
                Awaiting next process
            </div>
        </div>
    </a>

    <!-- 3 · Finish Good Stock -->
    <a href="finish_product.php" class="kpi kpi-blue">
        <div class="kpi-stripe"></div>
        <div class="kpi-body">
            <div class="kpi-icon">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <div class="kpi-num"><?= $fg_stock ?></div>
            <div class="kpi-label">Finish Good Stock</div>
        </div>
        <div class="kpi-foot">
            <div class="kpi-foot-item">
                <div class="kpi-dot"></div>
                Pending: <strong><?= $fg_pending ?></strong>
            </div>
            <div class="kpi-foot-item">
                <div class="kpi-dot"></div>
                QC Wait: <strong><?= $fg_waiting ?></strong>
            </div>
        </div>
    </a>

    <!-- 4 · Delivered This Month -->
    <a href="finish_product.php" class="kpi kpi-violet">
        <div class="kpi-stripe"></div>
        <div class="kpi-body">
            <div class="kpi-icon">
                <i class="bi bi-truck"></i>
            </div>
            <div class="kpi-num"><?= $delivered ?></div>
            <div class="kpi-label">Delivered This Month</div>
        </div>
        <div class="kpi-foot">
            <div class="kpi-foot-item">
                <div class="kpi-dot"></div>
                Approved: <strong><?= $delivered_approved ?></strong>
            </div>
            <div class="kpi-foot-item">
                <div class="kpi-dot"></div>
                MTD In: <strong><?= $mtd_in_raw ?></strong>
            </div>
        </div>
    </a>

</div>

<!-- ══ QUICK ACTIONS ══════════════════════════════════════════ -->
<div class="sec-head">Quick Actions</div>

<div class="qa-grid">
    <a href="raw_material.php" class="qa-btn" style="--qa-c:#059669; --qa-bg:#ECFDF5;">
        <div class="qa-icon"><i class="bi bi-boxes"></i></div>
        <div>
            Raw Material
            <span class="qa-sub">View &amp; scan stock</span>
        </div>
        <i class="bi bi-arrow-right qa-arrow"></i>
    </a>

    <a href="finish_product.php" class="qa-btn" style="--qa-c:#2563EB; --qa-bg:#EFF6FF;">
        <div class="qa-icon"><i class="bi bi-scissors"></i></div>
        <div>
            Finish Product
            <span class="qa-sub">Slitting records</span>
        </div>
        <i class="bi bi-arrow-right qa-arrow"></i>
    </a>

    <a href="sfc.php" class="qa-btn" style="--qa-c:#D97706; --qa-bg:#FFFBEB;">
        <div class="qa-icon"><i class="bi bi-box-seam"></i></div>
        <div>
            SFC Inventory
            <span class="qa-sub"><?= $sfc_total ?> active items</span>
        </div>
        <i class="bi bi-arrow-right qa-arrow"></i>
    </a>

    <a href="recoiling.php" class="qa-btn" style="--qa-c:#7C3AED; --qa-bg:#F5F3FF;">
        <div class="qa-icon"><i class="bi bi-arrow-repeat"></i></div>
        <div>
            Recoiling
            <span class="qa-sub">Recoil queue</span>
        </div>
        <i class="bi bi-arrow-right qa-arrow"></i>
    </a>

    <a href="mother_coil.php" class="qa-btn" style="--qa-c:#0891B2; --qa-bg:#ECFEFF;">
        <div class="qa-icon"><i class="bi bi-layer-forward"></i></div>
        <div>
            Mother Coil
            <span class="qa-sub">Coil registry</span>
        </div>
        <i class="bi bi-arrow-right qa-arrow"></i>
    </a>

    <a href="stock_log.php" class="qa-btn" style="--qa-c:#DC2626; --qa-bg:#FEF2F2;">
        <div class="qa-icon"><i class="bi bi-clock-history"></i></div>
        <div>
            Stock Log
            <span class="qa-sub">Full audit trail</span>
        </div>
        <i class="bi bi-arrow-right qa-arrow"></i>
    </a>
</div>

<!-- ══ RECENT PRODUCTION ══════════════════════════════════════ -->
<?php if (!empty($recent_rows)): ?>
<div class="sec-head">Recent Production</div>

<div class="activity-card">
    <div class="ac-head">
        <div class="ac-title">
            <div class="ac-title-icon"><i class="bi bi-activity"></i></div>
            Latest Rolls
        </div>
        <a href="finish_product.php" class="ac-link">
            View all <i class="bi bi-arrow-right"></i>
        </a>
    </div>

    <?php foreach ($recent_rows as $row):
        $st = strtoupper($row['status'] ?? 'IN');
        $badge = match($st) {
            'WAITING'   => '<span class="sb sb-waiting">Waiting QC</span>',
            'APPROVED'  => '<span class="sb sb-approved">Approved</span>',
            'DELIVERED' => '<span class="sb sb-delivered">Delivered</span>',
            'REJECTED'  => '<span class="sb sb-rejected">Rejected</span>',
            default     => '<span class="sb sb-in">In Stock</span>',
        };
    ?>
    <div class="ac-row">
        <div class="ac-lot">
            <?= htmlspecialchars($row['lot_no']) ?>
            <?= htmlspecialchars($row['coil_no']) ?>&#8209;<?= str_replace('R','R-', htmlspecialchars($row['roll_no'])) ?>
        </div>
        <div class="ac-chips">
            <?= $badge ?>
            <span class="chip chip-w"><?= number_format((float)$row['width']) ?> mm</span>
            <?php if ($row['actual_length']): ?>
                <span class="chip chip-l"><?= number_format((float)$row['actual_length'], 1) ?> m</span>
            <?php endif; ?>
        </div>
        <div class="ac-date">
            <?= $row['date_in'] ? date('d M Y', strtotime($row['date_in'])) : '' ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ Hidden scanner form ════════════════════════════════════ -->
<form id="scanForm" method="post" action="scan_mother_action.php" autocomplete="off"
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

<?php include 'footer.php'; ?>
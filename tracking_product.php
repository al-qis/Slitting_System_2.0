<?php
// tracking_product.php — Global Traceability & Delivery Tracking
// ─────────────────────────────────────────────────────────────
// UPDATE: Reslit / recoiled PARENT rolls are now HIDDEN from this
//   view entirely. A parent that was slit/recoiled into child
//   rolls never goes to a customer — only its children do — so it
//   is excluded from the table, the record count, and the KPIs.
//   This keeps the traceability view focused on rolls that
//   actually reach a customer (e.g. Roll 4 → 4A + 4B shows only
//   4A and 4B, never the consumed Roll 4).
//
//   A roll is "consumed" when is_reslitted = 1 OR is_recoiled = 1.
//   NOTE: this assumes those flags live on the PARENT row.
// ─────────────────────────────────────────────────────────────
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

include 'config.php';

// SQL fragment: a roll is "consumed" (transformed into child rolls)
// when either flag is set. Re-used across the queries below.
const CONSUMED_SQL = "(sp.is_reslitted = 1 OR sp.is_recoiled = 1)";

// ── Inputs ────────────────────────────────────────────────────
$search          = trim($_GET['search']   ?? '');
$filter_status   = trim($_GET['status']   ?? '');
$filter_month    = intval($_GET['month']  ?? 0);
$filter_year     = intval($_GET['year']   ?? date('Y'));
$filter_customer = trim($_GET['customer'] ?? '');
$filter_source   = trim($_GET['source']   ?? '');

// ── Main query ────────────────────────────────────────────────
$sql = "
    SELECT
        sp.id,
        sp.product,
        sp.lot_no,
        sp.coil_no,
        sp.roll_no,
        sp.width,
        sp.actual_length,
        sp.length,
        sp.status,
        sp.stock_counted,
        sp.is_reslitted,
        sp.is_recoiled,
        sp.date_in,
        sp.date_out,
        sp.delivered_at,
        sp.original_source,
        sp.customer_name,
        sp.ref_no,
        mc.grade
    FROM slitting_product sp
    LEFT JOIN mother_coil mc ON mc.id = sp.mother_id
    WHERE sp.is_voided = 0
      AND NOT " . CONSUMED_SQL . "
";

$params = [];
$types  = '';

// Tokenize search: split on whitespace so users can type e.g.
// "826403a N-4 R7" (Lot + Coil + Roll together) and get an exact
// match across all three, instead of relying on a fragile CONCAT
// guess at field order/spacing (the previous approach here, which
// only covered Lot+Coil combined and never handled Roll at all).
//
//   1 word   -> broad OR across lot_no, coil_no, roll_no, product,
//                customer_name (existing behavior — unchanged)
//   2 words  -> token1 -> lot_no, token2 -> coil_no
//   3+ words -> token1 -> lot_no, token2 -> coil_no, token3 -> roll_no
//                (tokens beyond the 3rd are ignored)
$tokens = $search !== '' ? preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) : [];

if (count($tokens) === 1) {
    $sql   .= " AND (
                    sp.lot_no        LIKE ? OR
                    sp.coil_no       LIKE ? OR
                    sp.roll_no       LIKE ? OR
                    sp.product       LIKE ? OR
                    sp.customer_name LIKE ?
                )";
    $like   = '%' . $tokens[0] . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
    $types .= 'sssss';

} elseif (count($tokens) === 2) {
    $sql      .= " AND sp.lot_no LIKE ? AND sp.coil_no LIKE ?";
    $likeLot   = '%' . $tokens[0] . '%';
    $likeCoil  = '%' . $tokens[1] . '%';
    $params    = array_merge($params, [$likeLot, $likeCoil]);
    $types    .= 'ss';

} elseif (count($tokens) >= 3) {
    // tokens beyond the 3rd are ignored
    $sql      .= " AND sp.lot_no LIKE ? AND sp.coil_no LIKE ? AND sp.roll_no LIKE ?";
    $likeLot   = '%' . $tokens[0] . '%';
    $likeCoil  = '%' . $tokens[1] . '%';
    $likeRoll  = '%' . $tokens[2] . '%';
    $params    = array_merge($params, [$likeLot, $likeCoil, $likeRoll]);
    $types    .= 'sss';
}

if ($filter_status !== '') {
    $sql    .= " AND sp.status = ?";
    $params[] = $filter_status;
    $types  .= 's';
}

if ($filter_customer !== '') {
    $sql    .= " AND sp.customer_name LIKE ?";
    $params[] = '%' . $filter_customer . '%';
    $types  .= 's';
}

// Source filter — mirrors the classification rule used by sourceMeta()
// below, so "what you filter by" always matches "what the badge shows".
if ($filter_source === 'sfc') {
    $sql .= " AND LOWER(sp.original_source) = 'sfc'";
} elseif ($filter_source === 'initial_stock') {
    $sql .= " AND LOWER(sp.original_source) LIKE '%initial%'";
} elseif ($filter_source === 'raw_material') {
    $sql .= " AND (sp.original_source IS NULL OR sp.original_source = ''
                OR (LOWER(sp.original_source) != 'sfc' AND LOWER(sp.original_source) NOT LIKE '%initial%'))";
}

if ($filter_month > 0) {
    $sql .= " AND (
        (sp.status = 'DELIVERED' AND MONTH(sp.delivered_at) = ? AND YEAR(sp.delivered_at) = ?)
        OR
        (sp.status != 'DELIVERED' AND MONTH(sp.date_in) = ? AND YEAR(sp.date_in) = ?)
    )";
    $params = array_merge($params, [$filter_month, $filter_year, $filter_month, $filter_year]);
    $types .= 'iiii';
}

$sql .= " ORDER BY
    CASE sp.status WHEN 'DELIVERED' THEN 0 ELSE 1 END ASC,
    COALESCE(sp.delivered_at, sp.date_in) DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Summary KPI counts (all records, not filtered) ───────────
//    Consumed parents are EXCLUDED so they don't inflate totals.
$counts_stmt = $conn->prepare("
    SELECT status, COUNT(*) AS total
    FROM slitting_product sp
    WHERE sp.is_voided = 0
      AND NOT " . CONSUMED_SQL . "
    GROUP BY status
");
$counts_stmt->execute();
$counts_raw  = $counts_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$counts_stmt->close();

$counts = ['DELIVERED' => 0, 'IN' => 0, 'WAITING' => 0, 'APPROVED' => 0, 'REJECTED' => 0];
foreach ($counts_raw as $c) {
    if (isset($counts[$c['status']])) $counts[$c['status']] = (int)$c['total'];
}

// Finish Good = IN + stock_counted (consumed parents excluded)
$fg_stmt = $conn->prepare("
    SELECT COUNT(*) AS total FROM slitting_product sp
    WHERE sp.is_voided = 0 AND sp.status = 'IN' AND sp.stock_counted = 1
      AND NOT " . CONSUMED_SQL . "
");
$fg_stmt->execute();
$counts['FINISH_GOOD'] = (int)$fg_stmt->get_result()->fetch_assoc()['total'];
$fg_stmt->close();

// ── Customer dropdown list ────────────────────────────────────
$cust_stmt = $conn->prepare("
    SELECT DISTINCT customer_name
    FROM slitting_product
    WHERE customer_name IS NOT NULL AND customer_name != ''
    ORDER BY customer_name ASC
");
$cust_stmt->execute();
$customers = array_column($cust_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'customer_name');
$cust_stmt->close();

// ── Helpers ───────────────────────────────────────────────────
function statusBadge(array $row): string
{
    $s  = $row['status'];
    $sc = (int)($row['stock_counted'] ?? 0);
    return match($s) {
        'DELIVERED' => '<span class="badge badge-delivered">Delivered</span>',
        'APPROVED'  => '<span class="badge badge-approved">Approved</span>',
        'WAITING'   => '<span class="badge badge-waiting">Waiting QC</span>',
        'REJECTED'  => '<span class="badge badge-rejected">Rejected</span>',
        'IN'        => $sc
                        ? '<span class="badge badge-stock">Finish Good</span>'
                        : '<span class="badge badge-pending">In Production</span>',
        default     => '<span class="badge badge-pending">' . htmlspecialchars($s) . '</span>',
    };
}

// Classifies sp.original_source into one of three buckets. This is the
// single source of truth for the badge — the old inline ternary only
// ever checked for 'sfc' and silently bucketed everything else
// (including Initial Stock rows) as "Raw Mat". The export used the raw
// DB value directly, which is why the two views disagreed.
//
// NOTE: matches on the substring "initial" (case-insensitive) so it
// covers likely stored variants like 'initial_stock', 'INITIAL_STOCK',
// or 'initial stock'. If your initial-stock rows use a different
// original_source value, adjust the str_contains() check below (and
// the matching one in tracking_product_export.php) to match exactly.
function sourceMeta(?string $raw): array
{
    $key = strtolower(trim((string)$raw));

    if ($key === 'sfc') {
        return ['label' => 'SFC', 'class' => 'src-sfc'];
    }
    if ($key !== '' && str_contains($key, 'initial')) {
        return ['label' => 'Initial Stock', 'class' => 'src-initial'];
    }
    return ['label' => 'Raw Mat', 'class' => 'src-raw'];
}

function fmtRoll(string $roll): string
{
    return str_replace('R', 'R-', htmlspecialchars($roll));
}

function fmtDate(?string $dt): string
{
    if (!$dt) return '—';
    return date('d M Y', strtotime($dt));
}

$page_title = 'Product Traceability';
include 'header.php';
?>

<style>
/* ── Page header ──────────────────────────────────────────── */
.trk-header {
    background: linear-gradient(135deg, #0f2744 0%, #1a3d6e 55%, #1e5fa5 100%);
    color: #fff; border-radius: 14px; padding: 26px 30px;
    margin-bottom: 22px; position: relative; overflow: hidden;
}
.trk-header::after {
    content:''; position:absolute; top:-50px; right:-40px;
    width:180px; height:180px; background:rgba(255,255,255,.05); border-radius:50%;
    pointer-events: none;
}
.trk-header h2 { font-size:20px; font-weight:800; letter-spacing:-.3px; margin:0 0 3px; }
.trk-header p  { opacity:.7; font-size:13px; margin:0; }

.btn-print-report {
    position: relative; z-index: 10;
    background: rgba(255,255,255,.15);
    color: #fff;
    border: 1px solid rgba(255,255,255,.4);
    border-radius: 7px;
    padding: 7px 16px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background .15s;
    flex-shrink: 0;
}
.btn-print-report:hover { background: rgba(255,255,255,.28); }

/* ── KPI row ──────────────────────────────────────────────── */
.kpi-row { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-bottom:20px; }
.kpi {
    background:#fff; border:1px solid #e5e7eb; border-radius:10px;
    padding:13px 14px; text-align:center; cursor:pointer;
    transition:box-shadow .14s, border-color .14s;
    text-decoration:none; display:block;
}
.kpi:hover  { box-shadow:0 4px 14px rgba(0,0,0,.09); border-color:#93c5fd; }
.kpi.active { border-color:var(--kc); box-shadow:0 0 0 2px var(--kc); }
.kpi .num   { font-size:26px; font-weight:800; color:var(--kc); line-height:1; }
.kpi .lbl   { font-size:10px; font-weight:700; text-transform:uppercase;
               letter-spacing:.7px; color:#9ca3af; margin-top:3px; }

.kpi-delivered { --kc:#059669; }
.kpi-stock     { --kc:#7c3aed; }
.kpi-waiting   { --kc:#d97706; }
.kpi-approved  { --kc:#16a34a; }
.kpi-rejected  { --kc:#dc2626; }

/* ── Filter bar ───────────────────────────────────────────── */
.filter-bar {
    background:#fff; border:1px solid #e5e7eb; border-radius:10px;
    padding:14px 18px; margin-bottom:16px;
    display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;
}
.filter-bar label {
    font-size:10px; font-weight:700; text-transform:uppercase;
    letter-spacing:.5px; color:#6b7280; display:block; margin-bottom:3px;
}
.filter-bar input,
.filter-bar select {
    border:1px solid #d1d5db; border-radius:7px; padding:7px 11px;
    font-size:13px; background:#f9fafb; color:#111; height:34px;
}
.filter-bar input:focus, .filter-bar select:focus {
    outline:none; border-color:#3b82f6; background:#fff;
    box-shadow:0 0 0 3px rgba(59,130,246,.1);
}
.srch-wrap  { position:relative; flex:1; min-width:180px; }
.srch-wrap input { width:100%; padding-left:32px; }
.srch-icon  { position:absolute; left:9px; top:50%; transform:translateY(-50%); color:#9ca3af; font-size:13px; }

.btn-search {
    background:#2563eb; color:#fff; border:none; border-radius:7px;
    padding:0 18px; height:34px; font-size:13px; font-weight:600;
    cursor:pointer; white-space:nowrap;
}
.btn-search:hover { background:#1d4ed8; }
.btn-clear {
    background:#f3f4f6; color:#374151; border:1px solid #d1d5db;
    border-radius:7px; padding:0 14px; height:34px; font-size:13px;
    font-weight:600; cursor:pointer; text-decoration:none;
    display:inline-flex; align-items:center;
}
.btn-clear:hover { background:#e5e7eb; }

/* ── Results bar ──────────────────────────────────────────── */
.results-bar {
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:10px; font-size:13px; color:#6b7280;
}
.results-bar .cnt { font-weight:700; color:#111; }
.btn-export {
    background:#16a34a; color:#fff; border:none; border-radius:7px;
    padding:6px 14px; font-size:12px; font-weight:600; cursor:pointer;
    text-decoration:none; display:inline-flex; align-items:center; gap:5px;
}
.btn-export:hover { background:#15803d; }

/* ── Table ────────────────────────────────────────────────── */
.trk-wrap {
    background:#fff; border:1px solid #e5e7eb; border-radius:12px;
    overflow:hidden; box-shadow:0 1px 5px rgba(0,0,0,.05);
}
.trk-table { width:100%; border-collapse:collapse; font-size:13px; }
.trk-table thead th {
    background:#0f2744; color:#fff;
    font-size:10px; font-weight:700; text-transform:uppercase;
    letter-spacing:.7px; padding:11px 13px; white-space:nowrap; border:none;
}
.trk-table tbody tr { border-bottom:1px solid #f3f4f6; transition:background .1s; }
.trk-table tbody tr:last-child { border-bottom:none; }
.trk-table tbody tr:hover { background:#f8faff; }
.trk-table td { padding:10px 13px; vertical-align:middle; }

/* Cells */
.roll-ref  { font-family:'Courier New',monospace; font-weight:700; font-size:13px; color:#0f2744; }
.roll-sub  { font-size:11px; color:#9ca3af; margin-top:2px; }
.meas-val  { font-weight:700; color:#111; }
.meas-unit { font-size:11px; color:#9ca3af; }
.cust-name { font-weight:600; color:#111; }
.cust-ref  { font-size:11px; color:#6b7280; margin-top:2px; font-family:monospace; }
.date-main { font-size:12px; color:#374151; }
.date-sub  { font-size:11px; color:#9ca3af; }

/* ── Status badges ────────────────────────────────────────── */
.badge {
    display:inline-block; font-size:10px; font-weight:700;
    letter-spacing:.5px; text-transform:uppercase;
    padding:3px 9px; border-radius:20px; white-space:nowrap;
}
.badge-delivered { background:#d1fae5; color:#065f46; }
.badge-approved  { background:#dbeafe; color:#1e40af; }
.badge-waiting   { background:#fef3c7; color:#92400e; }
.badge-rejected  { background:#fee2e2; color:#991b1b; }
.badge-stock     { background:#ede9fe; color:#5b21b6; }
.badge-pending   { background:#f3f4f6; color:#374151; }

.src-badge {
    font-size:9px; font-weight:700; letter-spacing:.4px; text-transform:uppercase;
    padding:2px 6px; border-radius:10px; display:inline-block; margin-top:3px;
}
.src-raw     { background:#f1f5f9; color:#475569; }
.src-sfc     { background:#ede9fe; color:#5b21b6; }
.src-initial { background:#fef3c7; color:#92400e; }

/* ── Empty state ──────────────────────────────────────────── */
.empty-state { padding:50px 20px; text-align:center; color:#9ca3af; }
.empty-state i { font-size:36px; display:block; margin-bottom:10px; }

/* ── Print ────────────────────────────────────────────────── */
@media print {
    .filter-bar, .kpi-row,
    .results-bar .btn-export,
    .no-print { display:none !important; }
    .trk-wrap  { box-shadow:none; border:1px solid #ccc; }
    .trk-table thead th { background:#0f2744 !important; -webkit-print-color-adjust:exact; }
}

@media (max-width:900px) { .kpi-row { grid-template-columns:repeat(3,1fr); } }
@media (max-width:600px) { .kpi-row { grid-template-columns:repeat(2,1fr); } .filter-bar { flex-direction:column; } }
</style>

<!-- ── Page Header ──────────────────────────────────────────── -->
<div class="trk-header">
    <div style="display:flex; justify-content:space-between; align-items:center; position:relative; z-index:10;">
        <div>
            <h2><i class="bi bi-globe2 me-2"></i>Global Traceability & Delivery Tracking</h2>
            <p>Full lifecycle — raw material to customer delivery · customer & ref captured at print time</p>
        </div>
        <button type="button" onclick="window.print()" class="btn-print-report no-print">
            <i class="bi bi-printer"></i> Print Report
        </button>
    </div>
</div>

<!-- ── KPI Cards ────────────────────────────────────────────── -->
<div class="kpi-row no-print">
    <?php
    $kpiDef = [
        ['DELIVERED',   'Delivered',   'kpi-delivered'],
        ['FINISH_GOOD', 'Finish Good', 'kpi-stock'],
        ['WAITING',     'Waiting QC',  'kpi-waiting'],
        ['APPROVED',    'Approved',    'kpi-approved'],
        ['REJECTED',    'Rejected',    'kpi-rejected'],
    ];
    foreach ($kpiDef as [$st, $label, $cls]):
        $urlStatus = ($st === 'FINISH_GOOD') ? 'IN' : $st;
        $active    = ($filter_status === $urlStatus) ? 'active' : '';
        $toggleSt  = ($filter_status === $urlStatus) ? '' : $urlStatus;
        $href      = '?' . http_build_query(array_merge($_GET, ['status' => $toggleSt]));
    ?>
    <a href="<?= htmlspecialchars($href) ?>" class="kpi <?= $cls ?> <?= $active ?>">
        <div class="num"><?= number_format($counts[$st]) ?></div>
        <div class="lbl"><?= $label ?></div>
    </a>
    <?php endforeach; ?>
</div>

<!-- ── Filter Bar ────────────────────────────────────────────── -->
<form method="GET" action="tracking_product.php" class="filter-bar no-print">

    <div class="srch-wrap">
        <label>Search</label>
        <i class="bi bi-search srch-icon"></i>
        <input type="text" name="search"
               value="<?= htmlspecialchars($search) ?>"
               placeholder="Lot No, Coil No, Roll No, Product, Customer… (e.g. 826403a N-4 R7)">
    </div>

    <div>
        <label>Customer</label>
        <select name="customer">
            <option value="">All customers</option>
            <?php foreach ($customers as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"
                    <?= ($filter_customer === $c) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label>Status</label>
        <select name="status">
            <option value="">All</option>
            <option value="DELIVERED" <?= $filter_status==='DELIVERED' ? 'selected':'' ?>>Delivered</option>
            <option value="IN"        <?= $filter_status==='IN'        ? 'selected':'' ?>>Finish Good / IN</option>
            <option value="WAITING"   <?= $filter_status==='WAITING'   ? 'selected':'' ?>>Waiting QC</option>
            <option value="APPROVED"  <?= $filter_status==='APPROVED'  ? 'selected':'' ?>>Approved</option>
            <option value="REJECTED"  <?= $filter_status==='REJECTED'  ? 'selected':'' ?>>Rejected</option>
        </select>
    </div>

    <div>
        <label>Source</label>
        <select name="source">
            <option value="">All sources</option>
            <option value="raw_material"  <?= $filter_source==='raw_material'  ? 'selected':'' ?>>Raw Mat</option>
            <option value="sfc"           <?= $filter_source==='sfc'           ? 'selected':'' ?>>SFC</option>
            <option value="initial_stock" <?= $filter_source==='initial_stock' ? 'selected':'' ?>>Initial Stock</option>
        </select>
    </div>

    <div>
        <label>Month</label>
        <select name="month">
            <option value="0">All months</option>
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= ($filter_month === $m) ? 'selected' : '' ?>>
                    <?= date('F', mktime(0,0,0,$m,1)) ?>
                </option>
            <?php endfor; ?>
        </select>
    </div>

    <div>
        <label>Year</label>
        <select name="year">
            <?php for ($y = 2024; $y <= 2030; $y++): ?>
                <option value="<?= $y ?>" <?= ($filter_year === $y) ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </div>

    <div style="display:flex; gap:8px; align-items:flex-end;">
        <button type="submit" class="btn-search">
            <i class="bi bi-search"></i> Search
        </button>
        <a href="tracking_product.php" class="btn-clear">Clear</a>
    </div>
</form>

<!-- ── Results Bar ───────────────────────────────────────────── -->
<div class="results-bar">
    <div>
        Showing <span class="cnt"><?= count($rows) ?></span> record<?= count($rows) !== 1 ? 's' : '' ?>
        <?php if ($search !== ''): ?> for <em>"<?= htmlspecialchars($search) ?>"</em><?php endif; ?>
        <?php if ($filter_status !== ''): ?> · Status: <strong><?= htmlspecialchars($filter_status) ?></strong><?php endif; ?>
        <?php if ($filter_customer !== ''): ?> · Customer: <strong><?= htmlspecialchars($filter_customer) ?></strong><?php endif; ?>
        <?php if ($filter_source   !== ''): ?> · Source: <strong><?= htmlspecialchars(sourceMeta($filter_source)['label']) ?></strong><?php endif; ?>
    </div>
    <a href="tracking_product_export.php?<?= htmlspecialchars(http_build_query($_GET)) ?>"
       class="btn-export no-print">
        <i class="bi bi-file-earmark-excel"></i> Export Excel
    </a>
</div>

<!-- ── Tracking Table ────────────────────────────────────────── -->
<div class="trk-wrap">
    <table class="trk-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Status</th>
                <th>Product / Grade</th>
                <th>Lot · Coil · Roll</th>
                <th>Width</th>
                <th>Actual Length</th>
                <th>Customer</th>
                <th>Ref No.</th>
                <th>Produced</th>
                <th>Delivered</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr>
                <td colspan="10">
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <p><?= $search !== ''
                            ? 'No records found matching "' . htmlspecialchars($search) . '".'
                            : 'No records found. Adjust your filters and try again.' ?>
                        </p>
                    </div>
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($rows as $i => $row):
                $dispLen  = $row['actual_length'] ?? $row['length'] ?? '—';
                $isActual = !empty($row['actual_length']);
                $src = sourceMeta($row['original_source'] ?? null);
            ?>
            <tr>
                <td style="color:#9ca3af; font-size:12px;"><?= $i + 1 ?></td>

                <td>
                    <?= statusBadge($row) ?>
                    <br>
                    <span class="src-badge <?= $src['class'] ?>"><?= htmlspecialchars($src['label']) ?></span>
                </td>

                <td>
                    <div class="meas-val"><?= htmlspecialchars($row['product'] ?? '—') ?></div>
                    <?php if (!empty($row['grade'])): ?>
                        <div class="roll-sub">Grade: <?= htmlspecialchars($row['grade']) ?></div>
                    <?php endif; ?>
                </td>

                <td>
                    <div class="roll-ref"><?= htmlspecialchars($row['lot_no'] ?? '') ?></div>
                    <div class="roll-sub">
                        <?= htmlspecialchars($row['coil_no'] ?? '') ?>
                        &nbsp;·&nbsp;
                        <?= fmtRoll($row['roll_no'] ?? '') ?>
                    </div>
                </td>

                <td>
                    <span class="meas-val"><?= number_format((float)($row['width'] ?? 0)) ?></span>
                    <span class="meas-unit"> mm</span>
                </td>

                <td>
                    <span class="meas-val">
                        <?= is_numeric($dispLen) ? number_format((float)$dispLen, 1) : htmlspecialchars((string)$dispLen) ?>
                    </span>
                    <span class="meas-unit"> m</span>
                    <?php if ($isActual): ?>
                        <br><span style="font-size:10px; color:#059669; font-weight:700;">ACTUAL</span>
                    <?php endif; ?>
                </td>

                <td>
                    <?php if (!empty($row['customer_name'])): ?>
                        <div class="cust-name"><?= htmlspecialchars($row['customer_name']) ?></div>
                    <?php else: ?>
                        <span style="color:#d1d5db; font-size:12px;">—</span>
                    <?php endif; ?>
                </td>

                <td>
                    <?php if (!empty($row['ref_no'])): ?>
                        <div class="cust-ref"><?= htmlspecialchars($row['ref_no']) ?></div>
                    <?php else: ?>
                        <span style="color:#d1d5db; font-size:12px;">—</span>
                    <?php endif; ?>
                </td>

                <td>
                    <div class="date-main"><?= fmtDate($row['date_in']) ?></div>
                    <?php if (!empty($row['date_out'])): ?>
                        <div class="date-sub">QC: <?= fmtDate($row['date_out']) ?></div>
                    <?php endif; ?>
                </td>

                <td>
                    <?php if (!empty($row['delivered_at'])): ?>
                        <div class="date-main" style="color:#059669; font-weight:600;">
                            <?= fmtDate($row['delivered_at']) ?>
                        </div>
                    <?php else: ?>
                        <span style="color:#d1d5db; font-size:12px;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="mt-4 no-print">
    <a href="index.php" class="btn btn-secondary">← Back to Dashboard</a>
</div>

<?php include 'footer.php'; ?>
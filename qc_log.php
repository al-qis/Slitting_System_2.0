<?php
// qc_log.php — v3: top-roll highlight using pallet_items checklist columns
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'qc') {
    header("Location: login.php");
    exit;
}

// ── Filters ───────────────────────────────────────────────────
$filter_status = $_GET['status']    ?? 'all';
$search        = trim($_GET['search']    ?? '');
$date_from     = $_GET['date_from'] ?? '';
$date_to       = $_GET['date_to']   ?? '';

// Fetch active inspectors for navbar
$stmtIns = $conn->prepare("SELECT id, name FROM qc_inspectors WHERE is_active = 1 ORDER BY name ASC");
$stmtIns->execute();
$inspectors = $stmtIns->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtIns->close();

$activeInspectorSession = $_SESSION['active_qc_inspector'] ?? '';

$where  = "WHERE p.status IN ('approved','rejected','delivered')";
$params = [];
$types  = '';

if ($filter_status === 'approved') { $where .= " AND p.status = 'approved'"; }
elseif ($filter_status === 'rejected') { $where .= " AND p.status = 'rejected'"; }
elseif ($filter_status === 'delivered') { $where .= " AND p.status = 'delivered'"; }

if ($search !== '') {
    $where   .= " AND (p.pallet_no LIKE ? OR p.customer_name LIKE ? OR p.product_type LIKE ? OR p.checked_by LIKE ?)";
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like,$like,$like,$like]);
    $types   .= 'ssss';
}
if ($date_from !== '') { $where .= " AND DATE(p.updated_at) >= ?"; $params[] = $date_from; $types .= 's'; }
if ($date_to   !== '') { $where .= " AND DATE(p.updated_at) <= ?"; $params[] = $date_to;   $types .= 's'; }

// ── Pallet-level query ────────────────────────────────────────
$sql = "
    SELECT p.id AS pallet_id, p.pallet_no, p.status AS pallet_status,
           p.customer_name, p.ref_no, p.product_type, p.width AS pallet_width,
           p.qc_comment AS rejection_reason, p.checked_by, p.updated_at AS qc_datetime,
           COUNT(pi.id) AS total_rolls
    FROM pallets p
    LEFT JOIN pallet_items pi ON pi.pallet_id = p.id
    $where
    GROUP BY p.id
    ORDER BY p.updated_at DESC
";
$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$palletRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Roll details — includes checklist columns ─────────────────
// winding_condition and hairy_rubber come from pallet_items
$rollsByPallet = [];
$palletIds = array_column($palletRows, 'pallet_id');

if (!empty($palletIds)) {
    $ph       = implode(',', array_fill(0, count($palletIds), '?'));
    $phTypes  = str_repeat('i', count($palletIds));

    $rollStmt = $conn->prepare("
        SELECT
            pi.pallet_id,
            pi.seq,
            pi.winding_condition,
            pi.hairy_rubber,
            pi.qc_checked_at,
            sp.id           AS product_id,
            sp.lot_no,
            sp.coil_no,
            sp.roll_no,
            sp.product,
            sp.width,
            sp.length,
            sp.actual_length,
            sp.status       AS roll_status,
            sp.qc_comment   AS roll_rejection,
            sp.date_in
        FROM pallet_items pi
        JOIN slitting_product sp ON sp.id = pi.slitting_product_id
        WHERE pi.pallet_id IN ($ph)
        ORDER BY pi.pallet_id ASC, pi.seq ASC
    ");
    $rollStmt->bind_param($phTypes, ...$palletIds);
    $rollStmt->execute();
    foreach ($rollStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $roll) {
        $rollsByPallet[(int)$roll['pallet_id']][] = $roll;
    }
    $rollStmt->close();
}

// ── KPI — pallet counts ───────────────────────────────────────
$kpiStmt = $conn->prepare("SELECT status, COUNT(*) AS total FROM pallets WHERE status IN ('approved','rejected','delivered') GROUP BY status");
$kpiStmt->execute();
$total_approved = 0; $total_rejected = 0; $total_delivered = 0;
foreach ($kpiStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $k) {
    if ($k['status'] === 'approved')  $total_approved  = (int)$k['total'];
    if ($k['status'] === 'rejected')  $total_rejected  = (int)$k['total'];
    if ($k['status'] === 'delivered') $total_delivered = (int)$k['total'];
}
$kpiStmt->close();
// "Passed QC" = approved (still on-site) + delivered (already shipped) — both cleared inspection.
$total_passed = $total_approved + $total_delivered;
$total        = $total_passed + $total_rejected;
$pass_rate    = $total > 0 ? round(($total_passed / $total) * 100, 1) : 0;

function fmtDate(?string $dt, string $f='d M Y'): string { return $dt ? date($f,strtotime($dt)) : '—'; }
function fmtTime(?string $dt): string { return $dt ? date('H:i:s',strtotime($dt)) : ''; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QC Inspection Log – Nichias</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background:#f4f7f9; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; font-size:14px; }
        .navbar { background:#2c3e50; }

        .page-header { display:flex; align-items:flex-start; justify-content:space-between;
                       flex-wrap:wrap; gap:12px; margin-bottom:24px; }
        .page-header h2 { font-size:22px; font-weight:800; margin:0 0 3px; color:#1a2332; }
        .page-header p  { margin:0; color:#6c757d; font-size:13px; }

        /* KPI */
        .kpi-row  { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:22px; }
        .kpi-card {
            background:#fff; border-radius:10px; box-shadow:0 1px 6px rgba(0,0,0,.07);
            border-left:5px solid var(--kc);
            display:flex; align-items:center; gap:18px; padding:18px 22px;
        }
        .kpi-icon { width:48px; height:48px; border-radius:12px; background:var(--ki-bg);
                    color:var(--kc); display:flex; align-items:center; justify-content:center;
                    font-size:22px; flex-shrink:0; }
        .kpi-num   { font-size:2rem; font-weight:800; color:var(--kc); line-height:1; }
        .kpi-label { font-size:10px; font-weight:700; text-transform:uppercase;
                     letter-spacing:.8px; color:#9ca3af; margin-top:3px; }
        .kpi-approved  { --kc:#059669; --ki-bg:#ecfdf5; }
        .kpi-delivered { --kc:#7c3aed; --ki-bg:#f5f3ff; }
        .kpi-rejected  { --kc:#dc2626; --ki-bg:#fef2f2; }
        .kpi-rate      { --kc:#2563eb; --ki-bg:#eff6ff; }

        /* Filter */
        .filter-bar { background:#fff; border-radius:10px; padding:16px 20px;
                      margin-bottom:20px; box-shadow:0 1px 5px rgba(0,0,0,.06); }
        .filter-bar label { font-size:11px; font-weight:700; color:#374151;
                            text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px; }

        /* Records card */
        .records-card { background:#fff; border-radius:10px;
                        box-shadow:0 1px 6px rgba(0,0,0,.07); overflow:hidden; }
        .records-head { display:flex; align-items:center; justify-content:space-between;
                        padding:14px 20px; border-bottom:1px solid #f0f0f0; }
        .records-head h5 { margin:0; font-weight:700; font-size:15px; color:#1a2332; }
        .rec-count-badge { background:#e0f2fe; color:#0369a1;
                           font-size:12px; font-weight:700; padding:3px 12px; border-radius:20px; }

        /* Pallet rows */
        .pallet-thead, .pallet-row {
            display:grid;
            grid-template-columns: 32px 1.6fr 148px 72px 110px 170px 1fr;
            align-items:center; gap:0; padding:12px 20px;
        }
        .pallet-thead { background:#f8f9fa; border-bottom:2px solid #e5e7eb;
                        font-size:10px; font-weight:700; text-transform:uppercase;
                        letter-spacing:.7px; color:#6b7280; }
        .pallet-row { border-bottom:1px solid #f3f4f6; cursor:pointer; transition:background .12s; }
        .pallet-row:hover { background:#f8faff; }
        .pallet-row.last  { border-bottom:none; }

        .pallet-no  { font-family:monospace; font-weight:700; font-size:13px; color:#0f2744; }
        .pallet-sub { font-size:11px; color:#9ca3af; margin-top:2px; }
        .dt-main    { font-size:13px; color:#374151; font-weight:600; }
        .dt-sub     { font-size:11px; color:#9ca3af; display:flex; align-items:center; gap:3px; }
        .roll-pill  { background:#e0f2fe; color:#0369a1; font-size:11px; font-weight:700;
                      padding:3px 10px; border-radius:20px; white-space:nowrap; display:inline-block; }

        .status-badge { display:inline-flex; align-items:center; gap:5px;
                        font-size:11px; font-weight:700; letter-spacing:.4px;
                        text-transform:uppercase; padding:4px 12px; border-radius:20px;
                        white-space:nowrap; }
        .badge-approved  { background:#d1fae5; color:#065f46; }
        .badge-delivered { background:#ede9fe; color:#5b21b6; }
        .badge-rejected  { background:#fee2e2; color:#991b1b; }

        .checked-by-chip { display:inline-flex; align-items:center; gap:5px;
                           font-size:12px; font-weight:600; color:#1e40af;
                           background:#eff6ff; border:1px solid #bfdbfe;
                           padding:3px 10px; border-radius:20px; white-space:nowrap; }
        .checked-by-chip.rejected-chip { color:#991b1b; background:#fef2f2; border-color:#fecaca; }
        .checked-by-empty { font-size:11px; color:#d1d5db; font-style:italic; }

        .rejection-wrap { background:#fff5f5; border-left:3px solid #dc2626;
                          border-radius:4px; padding:5px 10px;
                          font-size:12px; color:#842029; }

        .expand-chevron { font-size:14px; color:#9ca3af; transition:transform .2s; flex-shrink:0; }
        .expand-chevron.open { transform:rotate(90deg); color:#2563eb; }

        /* Accordion */
        .accordion-content { display:none; }
        .accordion-content.open {
            display:block; border-bottom:1px solid #e5e7eb;
            background:#f8faff; padding:0 20px 18px 52px;
        }

        /* Roll sub-table */
        .roll-table { width:100%; border-collapse:collapse; font-size:12px; }
        .roll-table th { background:#e9ecef; padding:7px 12px; font-size:10px; font-weight:700;
                         text-transform:uppercase; letter-spacing:.5px; color:#6b7280;
                         text-align:left; border:none; }
        .roll-table td { padding:9px 12px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
        .roll-table tr:last-child td { border-bottom:none; }
        .roll-table tr:hover td     { background:#f0f4ff; }
        .roll-ref   { font-family:monospace; font-weight:700; color:#0f2744; }
        .dim-val    { font-weight:700; }
        .dim-unit   { font-size:10px; color:#9ca3af; }
        .actual-badge { font-size:9px; background:#dcfce7; color:#166534;
                        padding:1px 5px; border-radius:5px; font-weight:700; margin-left:4px; }

        /* ── TOP ROLL highlight ── */
        .tr-top-roll {
            background: #f0fdf4 !important;
            border-left: 4px solid #16a34a !important;
        }
        .tr-top-roll td:first-child { padding-left: 8px; }

        /* Top-roll badge */
        .top-roll-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #dcfce7;
            color: #14532d;
            border: 1px solid #86efac;
            border-radius: 20px;
            padding: 2px 9px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
            margin-left: 6px;
            vertical-align: middle;
        }

        /* Checklist tick mini-display inside log */
        .check-tick { color:#059669; font-size:13px; }
        .check-dash { color:#d1d5db; font-size:13px; }

        .empty-state { text-align:center; padding:50px 20px; color:#9ca3af; }
        .empty-state i { font-size:38px; display:block; margin-bottom:10px; }

        @media(max-width:900px) {
            .kpi-row { grid-template-columns:1fr 1fr; }
            .pallet-thead, .pallet-row { grid-template-columns:32px 1fr 120px 70px 100px; }
            .pallet-thead > :nth-child(6), .pallet-row > :nth-child(6),
            .pallet-thead > :nth-child(7), .pallet-row > :nth-child(7) { display:none; }
        }
        @media(max-width:600px) { .kpi-row { grid-template-columns:1fr; } }

        /* Export button */
        .btn-export-xl {
            background: #217346;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            border: none;
            border-radius: 8px;
            padding: 5px 14px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: background .15s;
            white-space: nowrap;
        }
        .btn-export-xl:hover { background: #185c38; color: #fff; }
        .btn-export-xl i { font-size: 14px; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark mb-4">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="qc_dashboard.php">
            <i class="bi bi-shield-check"></i> NICHIAS QC MANAGEMENT
        </a>
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center bg-white bg-opacity-10 border border-light border-opacity-25 rounded-3 px-3 py-1 text-light">
                <i class="bi bi-person-badge-fill text-warning me-2" style="font-size:18px !important;"></i>
                <label for="globalQcInspectorSelect" class="me-2 mb-0 fw-semibold text-white" style="font-size:15px !important;">Active QC:</label>
                <select id="globalQcInspectorSelect" class="form-select border-0 shadow font-monospace fw-bold text-dark" style="width: auto; background:#fff3cd; font-size:18px !important; color:#856404; padding:6px 36px 6px 14px !important; height:auto !important;" onchange="updateGlobalQcInspector(this.value)">
                    <option value="">-- Select Active Inspector --</option>
                    <?php foreach ($inspectors as $insp): ?>
                    <option value="<?= htmlspecialchars($insp['name'], ENT_QUOTES) ?>" <?= ($activeInspectorSession === $insp['name']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($insp['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <a href="qc_manage_inspectors.php" class="btn btn-outline-light btn-sm" title="Manage Inspector Names">
                <i class="bi bi-gear-fill me-1"></i> Inspectors
            </a>
            <a href="qc_dashboard.php" class="btn btn-outline-light btn-sm">Dashboard</a>
            <a href="logout.php"  class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 pb-5">

    <div class="page-header">
        <div>
            <h2><i class="bi bi-clock-history me-2 text-secondary"></i>QC Inspection Log</h2>
            <p>Full history of approved, delivered, and rejected pallets. Expand a row to see individual rolls.</p>
        </div>
        <a href="qc_dashboard.php" class="btn btn-outline-secondary shadow-sm btn-sm px-3">
            <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
        </a>
    </div>

    <!-- KPI — pallet counts -->
    <div class="kpi-row">
        <div class="kpi-card kpi-approved">
            <div class="kpi-icon"><i class="bi bi-check-circle"></i></div>
            <div><div class="kpi-num"><?= $total_approved ?></div><div class="kpi-label">Approved (On-site)</div></div>
        </div>
        <div class="kpi-card kpi-delivered">
            <div class="kpi-icon"><i class="bi bi-truck"></i></div>
            <div><div class="kpi-num"><?= $total_delivered ?></div><div class="kpi-label">Delivered</div></div>
        </div>
        <div class="kpi-card kpi-rejected">
            <div class="kpi-icon"><i class="bi bi-x-circle"></i></div>
            <div><div class="kpi-num"><?= $total_rejected ?></div><div class="kpi-label">Total Rejected</div></div>
        </div>
        <div class="kpi-card kpi-rate">
            <div class="kpi-icon"><i class="bi bi-bar-chart"></i></div>
            <div><div class="kpi-num"><?= $pass_rate ?>%</div><div class="kpi-label">Pass Rate</div></div>
        </div>
    </div>

    <!-- Filter bar -->
    <div class="filter-bar">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label>Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Pallet No, Customer, Inspector…"
                       value="<?= htmlspecialchars($search,ENT_QUOTES) ?>">
            </div>
            <div class="col-md-2">
                <label>Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="all"       <?= $filter_status==='all'       ?'selected':'' ?>>All</option>
                    <option value="approved"  <?= $filter_status==='approved'  ?'selected':'' ?>>Approved (On-site)</option>
                    <option value="delivered" <?= $filter_status==='delivered' ?'selected':'' ?>>Delivered</option>
                    <option value="rejected"  <?= $filter_status==='rejected'  ?'selected':'' ?>>Rejected</option>
                </select>
            </div>
            <div class="col-md-2">
                <label>From Date</label>
                <input type="date" name="date_from" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($date_from,ENT_QUOTES) ?>">
            </div>
            <div class="col-md-2">
                <label>To Date</label>
                <input type="date" name="date_to" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($date_to,ENT_QUOTES) ?>">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm px-3">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <a href="qc_log.php" class="btn btn-outline-secondary btn-sm px-3">
                    <i class="bi bi-x me-1"></i> Clear
                </a>
            </div>
        </form>
    </div>

    <!-- Records -->
    <div class="records-card">
        <div class="records-head">
            <h5><i class="bi bi-list-check me-2"></i>Inspection Records</h5>
            <div class="d-flex align-items-center gap-2">
                <span class="rec-count-badge"><?= count($palletRows) ?> record(s)</span>
                <?php
                // Build export URL preserving active filters
                $exportParams = http_build_query([
                    'status'    => $filter_status,
                    'search'    => $search,
                    'date_from' => $date_from,
                    'date_to'   => $date_to,
                ]);
                $exportUrl = 'qc_export.php?' . $exportParams;
                ?>
                <a href="<?= htmlspecialchars($exportUrl) ?>"
                   class="btn btn-export-xl btn-sm"
                   title="Export current filtered view to Excel">
                    <i class="bi bi-file-earmark-excel-fill me-1"></i>
                    Export to Excel
                </a>
            </div>
        </div>

        <?php if (empty($palletRows)): ?>
        <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <p>No inspection records found<?= $search!=='' ? ' matching "'.htmlspecialchars($search).'"' : '' ?>.</p>
        </div>

        <?php else: ?>

        <!-- Column headers -->
        <div class="pallet-thead">
            <div></div>
            <div>Pallet No.</div>
            <div>QC Date &amp; Time</div>
            <div>Rolls</div>
            <div>Result</div>
            <div>Checked By</div>
            <div>Rejection Reason</div>
        </div>

        <?php foreach ($palletRows as $idx => $pallet):
            $pid         = (int)$pallet['pallet_id'];
            $statusLower = strtolower($pallet['pallet_status']);
            $isRejected  = $statusLower === 'rejected';
            $isDelivered = $statusLower === 'delivered';
            $isApproved  = !$isRejected; // approved AND delivered both mean "passed QC"
            $isLast      = ($idx === count($palletRows) - 1);
            $checkedBy   = trim($pallet['checked_by'] ?? '');
            $rolls       = $rollsByPallet[$pid] ?? [];
        ?>

        <div class="pallet-row <?= $isLast?'last':'' ?>"
             onclick="togglePallet(<?= $pid ?>)"
             id="palletRow<?= $pid ?>">

            <div><i class="bi bi-chevron-right expand-chevron" id="chevron<?= $pid ?>"></i></div>

            <div>
                <div class="pallet-no"><?= htmlspecialchars($pallet['pallet_no']) ?></div>
                <div class="pallet-sub">
                    <?php if (!empty(trim($pallet['customer_name']))): ?>
                        <?= htmlspecialchars($pallet['customer_name']) ?>
                        <?php if (!empty($pallet['ref_no'])): ?>&nbsp;·&nbsp;<?= htmlspecialchars($pallet['ref_no']) ?><?php endif; ?>
                    <?php else: ?><span style="color:#e5e7eb;">—</span><?php endif; ?>
                </div>
            </div>

            <div>
                <div class="dt-main"><?= fmtDate($pallet['qc_datetime']) ?></div>
                <div class="dt-sub"><i class="bi bi-clock" style="font-size:10px;"></i><?= fmtTime($pallet['qc_datetime']) ?></div>
            </div>

            <div>
                <span class="roll-pill">
                    <?= (int)$pallet['total_rolls'] ?> roll<?= $pallet['total_rolls']!=1?'s':'' ?>
                </span>
            </div>

            <div>
                <?php if ($isRejected): ?>
                    <span class="status-badge badge-rejected"><i class="bi bi-x-lg"></i> Rejected</span>
                <?php elseif ($isDelivered): ?>
                    <span class="status-badge badge-delivered"><i class="bi bi-truck"></i> Delivered</span>
                <?php else: ?>
                    <span class="status-badge badge-approved"><i class="bi bi-check-lg"></i> Approved</span>
                <?php endif; ?>
            </div>

            <div>
                <?php if ($checkedBy !== ''): ?>
                    <span class="checked-by-chip <?= $isRejected?'rejected-chip':'' ?>">
                        <i class="bi bi-person-check"></i>
                        <?= $isRejected?'Rejected by':'Approved by' ?>:
                        <strong><?= htmlspecialchars($checkedBy) ?></strong>
                    </span>
                <?php else: ?>
                    <span class="checked-by-empty">Not recorded</span>
                <?php endif; ?>
            </div>

            <div>
                <?php if ($isRejected && !empty($pallet['rejection_reason'])): ?>
                    <div class="rejection-wrap">
                        <i class="bi bi-chat-left-text me-1"></i>
                        <?= htmlspecialchars($pallet['rejection_reason']) ?>
                    </div>
                <?php else: ?>
                    <span style="color:#e5e7eb;font-size:12px;">—</span>
                <?php endif; ?>
            </div>

        </div><!-- /pallet-row -->

        <!-- Accordion: roll detail -->
        <div class="accordion-content" id="accordion<?= $pid ?>">
            <?php if (empty($rolls)): ?>
                <p class="text-muted small fst-italic pt-2">No roll details available.</p>
            <?php else: ?>

            <!-- Legend for top-roll badge -->
            <div class="d-flex align-items-center gap-2 mt-2 mb-2"
                 style="font-size:11px; color:#374151;">
                <span class="top-roll-badge"><i class="bi bi-star-fill"></i> Top Roll Inspected</span>
                <span>— The roll physically on top of the pallet; both Winding Condition &amp; Hairy Rubber were verified.</span>
            </div>

            <table class="roll-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Lot · Coil · Roll</th>
                        <th>Product</th>
                        <th>Width (mm)</th>
                        <th>Length (m)</th>
                        <th>Winding</th>
                        <th>Hairy Rubber</th>
                        <th>Roll Status</th>
                        
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rolls as $roll):
                    $isTopRoll = ((int)($roll['winding_condition']??0) === 1)
                              && ((int)($roll['hairy_rubber']??0) === 1);
                ?>
                <tr <?= $isTopRoll ? 'class="tr-top-roll"' : '' ?>>
                    <td style="color:#9ca3af;"><?= $roll['seq'] ?></td>
                    <td>
                        <span class="roll-ref">
                            <?= htmlspecialchars($roll['lot_no']) ?>
                            <?= htmlspecialchars($roll['coil_no']) ?>
                            – <?= str_replace('R','R-',htmlspecialchars($roll['roll_no'])) ?>
                        </span>
                        <?php if ($isTopRoll): ?>
                            <span class="top-roll-badge"
                                  title="Both Winding Condition &amp; Hairy Rubber were verified on this roll during inspection">
                                <i class="bi bi-star-fill"></i> Top Roll Inspected
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($roll['product']??'—') ?></td>
                    <td>
                        <span class="dim-val"><?= number_format((float)$roll['width']) ?></span>
                        <span class="dim-unit">mm</span>
                    </td>
                    <td>
                        <?php $displayLen=$roll['actual_length']?:$roll['length']; $isAct=!empty($roll['actual_length']); ?>
                        <span class="dim-val"><?= number_format((float)$displayLen,1) ?></span>
                        <span class="dim-unit">m</span>
                        <?php if ($isAct): ?><span class="actual-badge">ACTUAL</span><?php endif; ?>
                    </td>
                    <!-- Winding Condition tick -->
                    <td class="text-center">
                        <?php if ((int)($roll['winding_condition']??0)===1): ?>
                            <i class="bi bi-check-circle-fill check-tick" title="Winding Condition verified"></i>
                        <?php else: ?>
                            <i class="bi bi-dash-circle check-dash" title="Not checked"></i>
                        <?php endif; ?>
                    </td>
                    <!-- Hairy Rubber tick -->
                    <td class="text-center">
                        <?php if ((int)($roll['hairy_rubber']??0)===1): ?>
                            <i class="bi bi-check-circle-fill check-tick" title="Hairy Rubber verified"></i>
                        <?php else: ?>
                            <i class="bi bi-dash-circle check-dash" title="Not checked"></i>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $rs = strtoupper($roll['roll_status']??'');
                        echo match($rs) {
                            'APPROVED'  => '<span class="status-badge badge-approved" style="font-size:10px;padding:2px 8px;">Approved</span>',
                            'REJECTED'  => '<span class="status-badge badge-rejected" style="font-size:10px;padding:2px 8px;">Rejected</span>',
                            'DELIVERED' => '<span class="status-badge" style="background:#d1fae5;color:#065f46;font-size:10px;padding:2px 8px;">Delivered</span>',
                            'WAITING'   => '<span class="status-badge" style="background:#fef3c7;color:#92400e;font-size:10px;padding:2px 8px;">Waiting</span>',
                            default     => '<span class="status-badge" style="background:#f3f4f6;color:#374151;font-size:10px;padding:2px 8px;">'.htmlspecialchars($rs).'</span>',
                        };
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div><!-- /accordion -->

        <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateGlobalQcInspector(val) {
    val = (val || '').trim();
    localStorage.setItem('active_qc_inspector', val);

    const fd = new FormData();
    fd.append('action', 'set_active_inspector');
    fd.append('name', val);
    fetch('qc_inspectors_ajax.php', { method: 'POST', body: fd }).catch(() => {});
}

document.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('globalQcInspectorSelect');
    if (sel) {
        const saved = localStorage.getItem('active_qc_inspector');
        if (saved && (!sel.value || sel.value === '')) {
            for (let opt of sel.options) {
                if (opt.value === saved) {
                    sel.value = saved;
                    updateGlobalQcInspector(saved);
                    break;
                }
            }
        }
    }
});

function togglePallet(pid) {
    const content = document.getElementById('accordion' + pid);
    const chevron = document.getElementById('chevron'   + pid);
    const isOpen  = content.classList.contains('open');
    content.classList.toggle('open', !isOpen);
    chevron.classList.toggle('open', !isOpen);
}
</script>
</body>
</html>
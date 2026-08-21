<?php
// qc_dashboard.php — v5: passes top_product_id to backend for top-roll persistence
session_start();
require_once 'config.php';

function getPalletItems(mysqli $conn, int $pallet_id): array {
    $stmt = $conn->prepare("
        SELECT pi.seq, pi.added_at,
               sp.id AS product_id,
               sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
               sp.width, sp.length, sp.actual_length, sp.status,
               COALESCE(sw.std_weight, 0) AS std_weight
        FROM pallet_items pi
        JOIN slitting_product sp ON sp.id = pi.slitting_product_id
        LEFT JOIN std_wgt sw ON sw.product_code = sp.product
        WHERE pi.pallet_id = ?
        ORDER BY pi.seq ASC
    ");
    $stmt->bind_param("i", $pallet_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function calcEstWeight(float $l, float $w, float $s): float {
    if ($l <= 0 || $w <= 0 || $s <= 0) return 0.0;
    return ($l * $w / 1000) * $s;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'qc') {
    header("Location: login.php");
    exit;
}

// Active inspectors
$inspStmt = $conn->prepare(
    "SELECT id, name FROM qc_inspectors WHERE is_active = 1 ORDER BY name ASC"
);
$inspStmt->execute();
$inspectors = $inspStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$inspStmt->close();

$activeInspectorSession = $_SESSION['active_qc_inspector'] ?? '';

// Pending pallets date filtering (single date)
$filter_date = trim($_GET['filter_date'] ?? $_GET['date'] ?? '');

$where  = "WHERE p.status = 'pending_qc'";
$params = [];
$types  = '';

if ($filter_date !== '') {
    $where .= " AND DATE(p.updated_at) = ?";
    $params[] = $filter_date;
    $types   .= 's';
}

$sql = "
    SELECT p.id, p.pallet_no, p.status,
           p.customer_name, p.ref_no, p.product_type, p.width,
           p.created_at, p.updated_at,
           COUNT(pi.id) AS roll_count
    FROM pallets p
    JOIN pallet_items pi ON pi.pallet_id = p.id
    $where
    GROUP BY p.id
    ORDER BY p.updated_at DESC
";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
} else {
    $result = $conn->query($sql);
}

$pallets = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $items    = getPalletItems($conn, (int)$row['id']);
        $totalWgt = 0.0;
        foreach ($items as $item) {
            $len      = (float)($item['actual_length'] ?: $item['length']);
            $totalWgt += calcEstWeight($len, (float)$item['width'], (float)$item['std_weight']);
        }
        $row['items']     = $items;
        $row['total_wgt'] = $totalWgt;
        $pallets[]        = $row;
    }
}

$grandTotalRolls = array_sum(array_column($pallets, 'roll_count'));
$grandTotalWgt   = array_sum(array_column($pallets, 'total_wgt'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QC Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background-color:#f4f7f9; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; }
        .navbar { background-color:#2c3e50; }
        .card  { border:none; border-radius:10px; box-shadow:0 0.125rem 0.25rem rgba(0,0,0,.075); }
        .table thead th {
            background-color:#f8f9fa; text-transform:uppercase;
            font-size:.8rem; letter-spacing:.05em; color:#6c757d;
        }
        .pallet-row td { vertical-align:middle; }
        .pallet-row .pallet-no { font-weight:700; font-family:monospace; font-size:14px; }
        .roll-sub-table { font-size:12px; margin:0; }
        .roll-sub-table th { background:#e9ecef; padding:5px 10px; font-size:11px; text-transform:uppercase; }
        .roll-sub-table td { padding:5px 10px; border-bottom:1px solid #f0f0f0; }
        .expand-toggle { cursor:pointer; user-select:none; }
        .expand-toggle:hover td { background:#f8f9fa; }
        .pallet-badge   { font-size:11px; padding:4px 10px; border-radius:20px; font-weight:600; }
        .roll-ref       { font-family:monospace; font-weight:600; }
        .roll-count-pill{ background:#e0f2fe; color:#0369a1; font-weight:700;
                          padding:3px 10px; border-radius:20px; font-size:12px; }
        .constraint-chip{ font-size:11px; background:#f1f5f9; color:#475569;
                          padding:2px 8px; border-radius:10px; display:inline-block; }

        /* weight */
        .wgt-pill-pallet{
            display:inline-flex; align-items:center; gap:4px;
            background:#fef3c7; color:#92400e; border:1px solid #fcd34d;
            font-weight:700; padding:3px 10px; border-radius:20px;
            font-size:12px; white-space:nowrap;
        }
        .wgt-chip-roll{
            display:inline-flex; align-items:center; gap:3px;
            background:#fef9c3; color:#713f12; border:1px solid #fde68a;
            border-radius:8px; padding:2px 7px; font-size:11px;
            font-weight:700; white-space:nowrap;
        }
        .pallet-wgt-bar{
            display:flex; align-items:center; gap:16px;
            background:linear-gradient(135deg,#fef3c7,#fffbeb);
            border:1px solid #fcd34d; border-radius:8px;
            padding:10px 16px; margin-bottom:10px; flex-wrap:wrap;
        }
        .pallet-wgt-bar .label{
            font-size:11px; font-weight:700; text-transform:uppercase;
            letter-spacing:.5px; color:#78350f;
            display:flex; align-items:center; gap:5px;
        }
        .pallet-wgt-bar .total{ font-size:22px; font-weight:800; color:#92400e; font-family:monospace; line-height:1; }
        .pallet-wgt-bar .unit { font-size:13px; font-weight:600; color:#b45309; }
        .pallet-wgt-bar .meta { font-size:11px; color:#92400e; background:rgba(255,255,255,.6); border-radius:6px; padding:3px 9px; }
        .grand-wgt-card{ border:none; border-radius:10px; box-shadow:0 0.125rem 0.25rem rgba(0,0,0,.075);
                         background:linear-gradient(135deg,#fef3c7,#fffbeb); border-left:4px solid #f59e0b !important; }
        .grand-wgt-card .val{ font-size:1.5rem; font-weight:800; color:#92400e; line-height:1; }
        .grand-wgt-card .lbl{ font-size:.7rem; text-transform:uppercase; letter-spacing:.8px; color:#b45309; margin-top:3px; }

        /* checklist */
        .checklist-cell{ min-width:180px; }
        .checklist-item{
            display:flex; align-items:center; gap:6px;
            padding:3px 0; font-size:11px; font-weight:600;
            color:#374151; white-space:nowrap;
        }
        .checklist-item input[type="checkbox"]{
            width:15px; height:15px; cursor:pointer;
            accent-color:#059669; flex-shrink:0;
        }
        .checklist-item label{ cursor:pointer; margin:0; user-select:none; }
        .roll-check-row.row-passed td{ background:#f0fdf4 !important; transition:background .25s; }
        .roll-check-row.row-passed .checklist-item label{ color:#059669; }

        /* checklist hint */
        .checklist-hint{
            display:flex; align-items:flex-start; gap:10px;
            padding:9px 14px; border-radius:8px;
            background:#fffbeb; border:1px solid #fcd34d;
            font-size:12px; color:#78350f; margin-bottom:10px;
        }
        .checklist-hint.hint-ok{ background:#f0fdf4; border-color:#86efac; color:#14532d; }
        .checklist-hint i{ font-size:16px; flex-shrink:0; margin-top:1px; }

        /* checked-by bar */
        .checked-by-bar{
            display:flex; align-items:center; gap:12px;
            padding:12px 14px; border-radius:8px;
            background:#eff6ff; border:1px solid #bfdbfe;
            margin-bottom:10px; flex-wrap:wrap;
        }
        .checked-by-bar label{
            font-size:12px; font-weight:700; color:#1e40af;
            white-space:nowrap; margin:0; display:flex; align-items:center; gap:6px;
        }
        .checked-by-bar select{
            min-width:200px; max-width:280px;
            border:1px solid #bfdbfe; border-radius:7px;
            padding:6px 10px; font-size:13px;
            background:#fff; color:#1e3a5f; cursor:pointer; outline:none;
        }
        .checked-by-bar select:focus{ border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.12); }
        .checked-by-bar .cb-hint{ font-size:11px; color:#3b82f6; font-style:italic; }

        /* approve disabled */
        .btn-approve-pallet:disabled,
        .btn-approve-pallet[disabled]{ opacity:.4; cursor:not-allowed; pointer-events:none; }

        /* Scanning Row Highlights & Focus Effects */
        .scan-row-highlight {
            outline: 3px solid #2563eb !important;
            background-color: #eff6ff !important;
            transition: all 0.3s ease;
            animation: scanRowPulse 1.8s ease-in-out 3;
        }
        @keyframes scanRowPulse {
            0%   { background-color: #dbeafe; box-shadow: inset 0 0 12px rgba(37,99,235,0.4); }
            50%  { background-color: #bfdbfe; box-shadow: inset 0 0 20px rgba(37,99,235,0.7); }
            100% { background-color: #eff6ff; box-shadow: none; }
        }
        .scan-focus-checklist {
            border: 2px solid #2563eb !important;
            background-color: #f0f9ff !important;
            border-radius: 6px;
            padding: 6px;
            box-shadow: 0 0 12px rgba(37,99,235,0.35);
            animation: checklistPulse 2s infinite alternate;
        }
        @keyframes checklistPulse {
            from { box-shadow: 0 0 6px rgba(37,99,235,0.2); }
            to   { box-shadow: 0 0 14px rgba(37,99,235,0.6); }
        }
    </style>
</head>
<body>
<nav class="navbar navbar-dark mb-4">
    <div class="container">
        <a class="navbar-brand fw-bold" href="#">
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
            <a href="qc_log.php"  class="btn btn-outline-light btn-sm">QC Log</a>
            <a href="logout.php"  class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container pb-5">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark">Quality Control Dashboard</h2>
            <p class="text-secondary">Inspect and approve or reject pallets for dispatch.</p>
        </div>
        <button onclick="window.location.reload()" class="btn btn-white border shadow-sm">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>

    <?php if (isset($_GET['approved'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle me-2"></i> Pallet approved successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['rejected'])): ?>
    <div class="alert alert-warning alert-dismissible fade show">
        <i class="bi bi-x-circle me-2"></i> Pallet rejected. Rolls returned to operator.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <?= htmlspecialchars(urldecode($_GET['error'])) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Rapid Barcode/QR Scanning Bar -->
    <div class="card mb-4 border-0 shadow-sm bg-white rounded-3">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="d-flex align-items-center gap-2 flex-grow-1" style="min-width: 280px;">
                    <div class="input-group">
                        <span class="input-group-text bg-primary text-white border-primary">
                            <i class="bi bi-qr-code-scan"></i>
                        </span>
                        <input type="text" id="qcScanInput" class="form-control font-monospace"
                               placeholder="Scan product coil barcode / QR code or Pallet No (Hardware scanner ready)..."
                               autocomplete="off" autocorrect="off" spellcheck="false"
                               onkeydown="if(event.key==='Enter'){ handleQCScan(this.value); this.value=''; }">
                        <button type="button" class="btn btn-primary fw-bold" onclick="var el=document.getElementById('qcScanInput'); handleQCScan(el.value); el.value='';">
                            <i class="bi bi-search me-1"></i> Scan Lookup
                        </button>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-2 d-none d-md-inline-flex align-items-center gap-1" title="Hardware scanner gun automatically detected">
                        <i class="bi bi-keyboard-fill"></i> USB/BT Gun Ready
                    </span>
                </div>
            </div>
            <!-- Alert / Toast Banner for Scan Feedback -->
            <div id="qcScanAlert" class="alert alert-dismissible fade show mt-2 mb-0 d-none" role="alert">
                <span id="qcScanAlertMsg"></span>
                <button type="button" class="btn-close" onclick="document.getElementById('qcScanAlert').classList.add('d-none');"></button>
            </div>
        </div>
    </div>

    <!-- Date Filter Bar -->
    <div class="card mb-4 border-0 shadow-sm bg-white rounded-3">
        <div class="card-body p-3">
            <form method="GET" action="qc_dashboard.php" id="qcDateFilterForm" class="row g-2 align-items-center">
                <div class="col-auto">
                    <span class="fw-bold text-dark small me-1">
                        <i class="bi bi-calendar3 text-primary me-1"></i>Filter by Date:
                    </span>
                </div>
                <div class="col-md-3 col-sm-6">
                    <input type="date" id="filter_date" name="filter_date" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date) ?>" onchange="document.getElementById('qcDateFilterForm').submit()">
                </div>
                <div class="col-auto d-flex flex-wrap gap-2 align-items-center">
                    <button type="submit" class="btn btn-primary btn-sm px-3 fw-bold">
                        <i class="bi bi-funnel-fill me-1"></i> Filter
                    </button>
                    <?php if ($filter_date !== ''): ?>
                    <a href="qc_dashboard.php" class="btn btn-outline-secondary btn-sm px-3 fw-semibold">
                        <i class="bi bi-x-circle me-1"></i> Clear
                    </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setSingleDatePreset('today')">Today</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setSingleDatePreset('yesterday')">Yesterday</button>
                </div>
            </form>
        </div>
    </div>

    <!-- summary cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card p-3 border-start border-warning border-4">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 bg-warning bg-opacity-10 p-3 rounded">
                        <i class="bi bi-archive text-warning fs-3"></i>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="text-uppercase text-secondary small mb-1">Pallets Awaiting QC</h6>
                        <h3 class="fw-bold mb-0"><?= count($pallets) ?></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-3 border-start border-info border-4">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 bg-info bg-opacity-10 p-3 rounded">
                        <i class="bi bi-scissors text-info fs-3"></i>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="text-uppercase text-secondary small mb-1">Total Rolls Pending</h6>
                        <h3 class="fw-bold mb-0"><?= $grandTotalRolls ?></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card grand-wgt-card p-3 border-start">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 p-3 rounded" style="background:rgba(245,158,11,.12);">
                        <i class="bi bi-speedometer2 fs-3" style="color:#d97706;"></i>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="lbl">Total Est. Weight (Pending)</div>
                        <div class="d-flex align-items-baseline gap-1 mt-1">
                            <span class="val"><?= $grandTotalWgt > 0 ? number_format($grandTotalWgt,2) : '—' ?></span>
                            <?php if ($grandTotalWgt > 0): ?><span style="font-size:14px;font-weight:700;color:#b45309;">kg</span><?php endif; ?>
                        </div>
                        <?php if ($grandTotalRolls > 0 && $grandTotalWgt > 0): ?>
                        <div style="font-size:11px;color:#92400e;margin-top:2px;">
                            avg <?= number_format($grandTotalWgt/$grandTotalRolls,2) ?> kg/roll
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- pallets table -->
    <div class="card shadow-sm">
        <div class="card-header bg-white py-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h5 class="card-title mb-0 fw-bold"><i class="bi bi-archive me-2"></i>Pending Pallets</h5>
            <?php if ($filter_date !== ''): ?>
            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-3 py-2 rounded-pill font-monospace" style="font-size: 12px;">
                <i class="bi bi-funnel-fill me-1"></i> Date Filtered:
                <?= date('d M Y', strtotime($filter_date)) ?>
            </span>
            <?php endif; ?>
        </div>

        <?php if (empty($pallets)): ?>
        <div class="p-5 text-center text-muted">
            <i class="bi bi-check-circle-fill display-4 d-block mb-3 text-success opacity-50"></i>
            <h5>All clear — no pallets waiting for QC.</h5>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:30px;"></th>
                        <th>Pallet No.</th>
                        <th>Constraints</th>
                        <th>Rolls</th>
                        <th>Est. Weight</th>
                        <th>Sent to QC</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pallets as $pallet):
                    $pid = (int)$pallet['id'];
                ?>
                <tr class="pallet-row expand-toggle" id="pallet-row-<?= $pid ?>" data-pallet-id="<?= $pid ?>" data-pallet-no="<?= htmlspecialchars($pallet['pallet_no'], ENT_QUOTES) ?>" onclick="toggleRolls(<?= $pid ?>)">
                    <td class="text-center">
                        <i class="bi bi-chevron-right" id="chevron<?= $pid ?>" style="transition:transform .2s;"></i>
                    </td>
                    <td>
                        <div class="pallet-no"><?= htmlspecialchars($pallet['pallet_no']) ?></div>
                        <div style="font-size:11px;color:#9ca3af;">ID #<?= $pid ?></div>
                    </td>
                    <td>
                        <div class="d-flex flex-wrap gap-1">
                            <?php if (!empty(trim($pallet['customer_name']))): ?>
                            <span class="constraint-chip"><i class="bi bi-person me-1"></i><?= htmlspecialchars($pallet['customer_name']) ?></span>
                            <span class="constraint-chip"><i class="bi bi-hash me-1"></i><?= htmlspecialchars($pallet['ref_no']) ?></span>
                            <span class="constraint-chip"><i class="bi bi-tag me-1"></i><?= htmlspecialchars($pallet['product_type']) ?></span>
                            <span class="constraint-chip"><?= number_format((float)$pallet['width']) ?> mm</span>
                            <?php else: ?>
                            <span class="text-muted" style="font-size:11px;">—</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><span class="roll-count-pill"><?= (int)$pallet['roll_count'] ?> roll<?= $pallet['roll_count']!=1?'s':'' ?></span></td>
                    <td>
                        <?php if ($pallet['total_wgt']>0): ?>
                        <span class="wgt-pill-pallet"><i class="bi bi-speedometer2"></i><?= number_format($pallet['total_wgt'],2) ?> kg</span>
                        <?php else: ?><span class="text-muted" style="font-size:12px;">—</span><?php endif; ?>
                    </td>
                    <td>
                        <div style="font-size:13px;"><?= date('d M Y',strtotime($pallet['updated_at'])) ?></div>
                        <div style="font-size:11px;color:#9ca3af;"><?= date('H:i',strtotime($pallet['updated_at'])) ?></div>
                    </td>
                    <td class="text-end pe-4" onclick="event.stopPropagation()">
                        <form method="POST" action="pallet_qc_action.php" class="d-inline" id="approveForm<?= $pid ?>">
                            <input type="hidden" name="action"          value="approve">
                            <input type="hidden" name="pallet_id"       value="<?= $pid ?>">
                            <input type="hidden" name="checked_by"      id="approveCheckedBy<?= $pid ?>"     value="">
                            <!-- NEW: top_product_id hidden field -->
                            <input type="hidden" name="top_product_id"  id="approveTopProduct<?= $pid ?>"   value="">
                            <button type="submit"
                                    class="btn btn-success btn-sm px-3 shadow-sm btn-approve-pallet"
                                    id="approveBtn<?= $pid ?>"
                                    disabled
                                    onclick="return confirmApprove(<?= $pid ?>,'<?= htmlspecialchars($pallet['pallet_no'],ENT_QUOTES) ?>',<?= $pallet['total_wgt'] ?>)">
                                <i class="bi bi-check-lg"></i> Approve
                            </button>
                        </form>
                        <button type="button"
                                class="btn btn-danger btn-sm px-3 shadow-sm ms-1"
                                onclick="openRejectModal(<?= $pid ?>)">
                            <i class="bi bi-x-lg"></i> Reject
                        </button>
                    </td>
                </tr>

                <!-- Expandable content -->
                <tr id="rollsRow<?= $pid ?>" style="display:none;">
                    <td colspan="7" style="padding:0 0 14px 50px;background:#f8faff;">

                        <?php if ($pallet['total_wgt']>0): ?>
                        <div class="pallet-wgt-bar mt-2 me-3">
                            <div class="label"><i class="bi bi-speedometer2"></i>Est. Total Weight</div>
                            <div style="display:flex;align-items:baseline;gap:5px;">
                                <span class="total"><?= number_format($pallet['total_wgt'],2) ?></span>
                                <span class="unit">kg</span>
                            </div>
                            <div class="meta">
                                <?= (int)$pallet['roll_count'] ?> roll<?= $pallet['roll_count']!=1?'s':'' ?>
                                &nbsp;·&nbsp;
                                avg <?= number_format($pallet['total_wgt']/max(1,(int)$pallet['roll_count']),2) ?> kg/roll
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Checked By Indicator Bar -->
                        <div class="checked-by-bar me-3 p-2 rounded" id="checkedByBar<?= $pid ?>" style="background:#f8fafc; border:1px solid #e2e8f0;">
                            <label class="mb-0 font-monospace text-secondary fw-semibold">
                                <i class="bi bi-person-badge-fill me-1 text-primary"></i> Checked By (Header Inspector):
                            </label>
                            <span id="activeInspectorBadge<?= $pid ?>" class="fw-bold font-monospace px-2 py-1 bg-white rounded border text-dark ms-1">
                                -- Select in Header --
                            </span>
                            <span class="cb-hint ms-2" id="cbHint<?= $pid ?>">Select in header bar above</span>
                        </div>

                        <!-- Checklist hint -->
                        <div class="checklist-hint me-3" id="checklistHint<?= $pid ?>">
                            <i class="bi bi-clipboard2-check"></i>
                            <span>
                                <strong>QC Checklist required:</strong>
                                Tick <em>both</em> checkboxes on <em>at least one</em> product row,
                                and select an inspector above.
                            </span>
                        </div>

                        <!-- Roll table -->
                        <table class="roll-sub-table table table-sm mb-0 me-3">
                            <thead>
                                <tr>
                                    <th>#</th><th>Product</th><th>Lot · Coil · Roll</th>
                                    <th>Width (mm)</th><th>Actual Length (m)</th><th>Est. Weight</th>
                                    <th class="text-center" style="background:#e8f5e9;color:#166534;">
                                        <i class="bi bi-check2-circle me-1"></i>QC Checklist
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($pallet['items'] as $item):
                                $itemLen   = (float)($item['actual_length'] ?: $item['length']);
                                $itemWgt   = calcEstWeight($itemLen,(float)$item['width'],(float)$item['std_weight']);
                                $productId = (int)$item['product_id'];
                            ?>
                                <tr class="roll-check-row" id="checkRow_<?= $pid ?>_<?= $productId ?>" data-pallet-id="<?= $pid ?>" data-product-id="<?= $productId ?>" data-lot="<?= htmlspecialchars($item['lot_no'], ENT_QUOTES) ?>" data-coil="<?= htmlspecialchars($item['coil_no'], ENT_QUOTES) ?>" data-roll="<?= htmlspecialchars($item['roll_no'], ENT_QUOTES) ?>">
                                    <td><?= $item['seq'] ?></td>
                                    <td><?= htmlspecialchars($item['product']??'—') ?></td>
                                    <td class="roll-ref">
                                        <?= htmlspecialchars($item['lot_no']) ?>
                                        <?= htmlspecialchars($item['coil_no']) ?>
                                        – <?= str_replace('R','R-',htmlspecialchars($item['roll_no'])) ?>
                                    </td>
                                    <td><?= number_format((float)$item['width']) ?></td>
                                    <td>
                                        <?php $len=$item['actual_length']?:$item['length']; ?>
                                        <?= number_format((float)$len,1) ?>
                                        <?php if($item['actual_length']): ?>
                                        <span style="font-size:10px;background:#dcfce7;color:#166534;padding:1px 5px;border-radius:8px;">ACTUAL</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($itemWgt>0): ?>
                                        <span class="wgt-chip-roll"><i class="bi bi-speedometer2"></i><?= number_format($itemWgt,2) ?> kg</span>
                                        <?php else: ?><span class="text-muted" style="font-size:11px;">N/A</span><?php endif; ?>
                                    </td>
                                    <td class="checklist-cell text-center" id="checklistCell_<?= $pid ?>_<?= $productId ?>">
                                        <div class="checklist-item">
                                            <input type="checkbox"
                                                   id="winding_<?= $pid ?>_<?= $productId ?>"
                                                   onchange="evalChecklist(<?= $pid ?>,<?= $productId ?>)">
                                            <label for="winding_<?= $pid ?>_<?= $productId ?>">Winding Condition</label>
                                        </div>
                                        <div class="checklist-item">
                                            <input type="checkbox"
                                                   id="hairy_<?= $pid ?>_<?= $productId ?>"
                                                   onchange="evalChecklist(<?= $pid ?>,<?= $productId ?>)">
                                            <label for="hairy_<?= $pid ?>_<?= $productId ?>">Hairy Rubber</label>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>

                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- REJECT MODALS -->
<?php foreach ($pallets as $pallet):
    $pid = (int)$pallet['id'];
?>
<div class="modal fade" id="rejectModal<?= $pid ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="pallet_qc_action.php" class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject Pallet: <?= htmlspecialchars($pallet['pallet_no']) ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action"          value="reject">
                <input type="hidden" name="pallet_id"       value="<?= $pid ?>">
                <input type="hidden" name="checked_by"      id="rejectCheckedBy<?= $pid ?>"    value="">
                <!-- NEW: top_product_id for reject path -->
                <input type="hidden" name="top_product_id"  id="rejectTopProduct<?= $pid ?>"  value="">

                <p class="text-muted small mb-3">
                    All <strong><?= (int)$pallet['roll_count'] ?> roll<?= $pallet['roll_count']!=1?'s':'' ?></strong>
                    on pallet <strong><?= htmlspecialchars($pallet['pallet_no']) ?></strong>
                    will be marked <span class="text-danger fw-bold">REJECTED</span> and returned to the operator.
                </p>

                <?php if ($pallet['total_wgt']>0): ?>
                <div class="d-flex align-items-center gap-2 mb-3 p-2 rounded"
                     style="background:#fef3c7;border:1px solid #fcd34d;">
                    <i class="bi bi-speedometer2" style="color:#d97706;"></i>
                    <span style="font-size:13px;color:#78350f;font-weight:600;">
                        Est. Total Weight: <strong><?= number_format($pallet['total_wgt'],2) ?> kg</strong>
                    </span>
                </div>
                <?php endif; ?>

                <div class="mb-3 p-2 bg-light border rounded" style="font-size:13px;">
                    <span class="text-secondary fw-semibold">Inspector (Checked By):</span>
                    <span id="rejectModalInspectorName<?= $pid ?>" class="fw-bold font-monospace text-primary ms-1">-- Select in Header --</span>
                </div>
                <div>
                    <label class="form-label fw-bold">Reason for Rejection <span class="text-danger">*</span></label>
                    <textarea name="comment" class="form-control" rows="3" required
                              placeholder="Describe the issue clearly…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger px-4"
                        onclick="return syncRejectFields(<?= $pid ?>)">
                    <i class="bi bi-x-lg me-1"></i> Confirm Reject
                </button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ─────────────────────────────────────────────────────────────
   Global QC Inspector Persistence & State Management
───────────────────────────────────────────────────────────── */
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function updateGlobalQcInspector(val) {
    val = (val || '').trim();
    localStorage.setItem('active_qc_inspector', val);

    const fd = new FormData();
    fd.append('action', 'set_active_inspector');
    fd.append('name', val);
    fetch('qc_inspectors_ajax.php', { method: 'POST', body: fd }).catch(() => {});

    evalAllChecklists();
}

function getGlobalQcInspector() {
    const sel = document.getElementById('globalQcInspectorSelect');
    if (sel && sel.value) return sel.value.trim();
    return localStorage.getItem('active_qc_inspector') || '';
}

function evalAllChecklists() {
    document.querySelectorAll('[id^="rollsRow"]').forEach(row => {
        const pid = row.id.replace('rollsRow', '');
        evalChecklist(parseInt(pid, 10), null);
    });
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
    evalAllChecklists();
});

/* ─────────────────────────────────────────────────────────────
   toggleRolls
───────────────────────────────────────────────────────────── */
function toggleRolls(pid) {
    const row     = document.getElementById('rollsRow' + pid);
    const chevron = document.getElementById('chevron'   + pid);
    const open    = row.style.display !== 'none';
    row.style.display       = open ? 'none' : 'table-row';
    chevron.style.transform = open ? 'rotate(0deg)' : 'rotate(90deg)';
}

/* ─────────────────────────────────────────────────────────────
   evalChecklist(palletId, productId)
───────────────────────────────────────────────────────────── */
function evalChecklist(pid, productId) {
    if (productId !== null) {
        const windEl = document.getElementById('winding_' + pid + '_' + productId);
        const hairEl = document.getElementById('hairy_'   + pid + '_' + productId);
        const rowEl  = document.getElementById('checkRow_' + pid + '_' + productId);
        if (windEl && hairEl && rowEl) {
            rowEl.classList.toggle('row-passed', windEl.checked && hairEl.checked);
        }
    }

    const allWindingBoxes = document.querySelectorAll('[id^="winding_' + pid + '_"]');
    let checklistPassed = false;
    let topProductId    = 0;

    Array.from(allWindingBoxes).forEach(function(windBox) {
        const parts   = windBox.id.split('_');
        const pId     = parts[parts.length - 1];
        const hairBox = document.getElementById('hairy_' + pid + '_' + pId);
        if (windBox.checked && hairBox && hairBox.checked) {
            checklistPassed = true;
            topProductId    = parseInt(pId, 10);
        }
    });

    const inspectorVal = getGlobalQcInspector();
    const inspectorOk  = inspectorVal !== '';
    const canApprove   = checklistPassed && inspectorOk;

    // Sync active inspector badge in row
    const badge = document.getElementById('activeInspectorBadge' + pid);
    if (badge) {
        badge.textContent = inspectorOk ? inspectorVal : '-- Select in Header --';
        badge.className   = inspectorOk ? 'fw-bold font-monospace px-2 py-1 bg-white rounded border text-success ms-1' : 'fw-bold font-monospace px-2 py-1 bg-white rounded border text-danger ms-1';
    }

    // Sync hidden fields for APPROVE form
    const approveCheckedBy  = document.getElementById('approveCheckedBy'  + pid);
    const approveTopProduct = document.getElementById('approveTopProduct' + pid);
    if (approveCheckedBy)  approveCheckedBy.value  = inspectorVal;
    if (approveTopProduct) approveTopProduct.value = topProductId > 0 ? topProductId : '';

    // Sync hidden fields for REJECT form
    const rejectCheckedBy   = document.getElementById('rejectCheckedBy'   + pid);
    const rejectModalInsp   = document.getElementById('rejectModalInspectorName' + pid);
    if (rejectCheckedBy)  rejectCheckedBy.value = inspectorVal;
    if (rejectModalInsp)  rejectModalInsp.textContent = inspectorOk ? inspectorVal : 'None Selected (Select in Header)';

    // Approve button enable / disable
    const approveBtn = document.getElementById('approveBtn' + pid);
    if (approveBtn) approveBtn.disabled = !canApprove;

    // Inspector hint text & bar colour
    const cbHint = document.getElementById('cbHint' + pid);
    if (cbHint) {
        if (!inspectorOk) {
            cbHint.style.color = '#ef4444';
            cbHint.textContent = 'Please select active inspector in header navigation bar ↑';
        } else {
            cbHint.style.color = '#16a34a';
            cbHint.textContent = '✓ Active inspector selected';
        }
    }
    const cbBar = document.getElementById('checkedByBar' + pid);
    if (cbBar) {
        cbBar.style.borderColor = inspectorOk ? '#86efac' : '#fca5a5';
        cbBar.style.background  = inspectorOk ? '#f0fdf4' : '#fff1f2';
        const lbl = cbBar.querySelector('label');
        if (lbl) lbl.style.color = inspectorOk ? '#166534' : '#991b1b';
    }

    // Checklist hint banner
    const hintEl = document.getElementById('checklistHint' + pid);
    if (!hintEl) return;
    if (canApprove) {
        hintEl.classList.add('hint-ok');
        hintEl.innerHTML = '<i class="bi bi-check-circle-fill"></i>' +
            '<span><strong>All checks passed ✓</strong> — Inspector set (' + escHtml(inspectorVal) + ') and checklist complete. Approve button unlocked.</span>';
    } else if (checklistPassed && !inspectorOk) {
        hintEl.classList.remove('hint-ok');
        hintEl.innerHTML = '<i class="bi bi-person-x"></i>' +
            '<span><strong>Checklist done — select active inspector in header bar</strong> to unlock Approve.</span>';
    } else if (!checklistPassed && inspectorOk) {
        hintEl.classList.remove('hint-ok');
        hintEl.innerHTML = '<i class="bi bi-clipboard2-check"></i>' +
            '<span><strong>Inspector set (' + escHtml(inspectorVal) + ') — complete checklist:</strong> tick <em>both</em> boxes on at least one product row.</span>';
    } else {
        hintEl.classList.remove('hint-ok');
        hintEl.innerHTML = '<i class="bi bi-clipboard2-check"></i>' +
            '<span><strong>QC Checklist required:</strong> Select active inspector in header bar <em>and</em> tick both checkboxes on at least one product row.</span>';
    }
}

/* ─────────────────────────────────────────────────────────────
   confirmApprove — final client-side gate
───────────────────────────────────────────────────────────── */
function confirmApprove(pid, palletNo, totalWgt) {
    const inspector = getGlobalQcInspector();
    if (!inspector) {
        alert('Please select an active QC Inspector in the header navigation bar first.');
        return false;
    }
    const wgtLine = totalWgt > 0 ? '\nEst. Total Weight: ' + Number(totalWgt).toFixed(2) + ' kg' : '';
    return confirm('Approve all rolls on pallet ' + palletNo + '?' + wgtLine + '\nChecked By: ' + inspector);
}

/* ─────────────────────────────────────────────────────────────
   openRejectModal — syncs inspector + topProductId then shows
───────────────────────────────────────────────────────────── */
function openRejectModal(pid) {
    const inspector = getGlobalQcInspector();
    if (!inspector) {
        alert('Please select an active QC Inspector in the header navigation bar first before rejecting.');
        return false;
    }

    evalChecklist(pid, null);
    new bootstrap.Modal(document.getElementById('rejectModal' + pid)).show();
}

/* ─────────────────────────────────────────────────────────────
   syncRejectFields — validates and syncs before reject submit
───────────────────────────────────────────────────────────── */
function syncRejectFields(pid) {
    const inspector = getGlobalQcInspector();
    if (!inspector) {
        alert('Please select an active QC Inspector in the header navigation bar first.');
        return false;
    }
    const hiddenCB  = document.getElementById('rejectCheckedBy'   + pid);
    const hiddenTP  = document.getElementById('rejectTopProduct'  + pid);
    const approveTP = document.getElementById('approveTopProduct' + pid);
    if (hiddenCB)  hiddenCB.value  = inspector;
    if (hiddenTP && approveTP) hiddenTP.value = approveTP.value;

    return true;
}

/* ─────────────────────────────────────────────────────────────
   QC Dashboard Barcode/QR Scanning Engine
───────────────────────────────────────────────────────────── */

// Audio feedback (Web Audio API)
function playAudioBeep(type) {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        const ctx = new AudioCtx();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);

        if (type === 'success') {
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            osc.frequency.setValueAtTime(1174.66, ctx.currentTime + 0.1);
            gain.gain.setValueAtTime(0.3, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.25);
            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + 0.25);
        } else {
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(220, ctx.currentTime);
            osc.frequency.setValueAtTime(164.81, ctx.currentTime + 0.12);
            gain.gain.setValueAtTime(0.4, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.35);
            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + 0.35);
        }
    } catch (e) {
        console.warn('Audio feedback failed:', e);
    }
}

// Visual Toast/Alert Banner
function showScanAlert(msg, type) {
    const alertBox = document.getElementById('qcScanAlert');
    const alertMsg = document.getElementById('qcScanAlertMsg');
    if (!alertBox || !alertMsg) return;

    alertBox.className = 'alert alert-dismissible fade show mt-2 mb-0 alert-' + (type === 'success' ? 'success' : type === 'info' ? 'info' : 'danger');
    alertMsg.innerHTML = (type === 'success' ? '<i class="bi bi-check-circle-fill me-2"></i>' : type === 'info' ? '<i class="bi bi-info-circle-fill me-2"></i>' : '<i class="bi bi-exclamation-triangle-fill me-2"></i>') + msg;
    alertBox.classList.remove('d-none');

    clearTimeout(window.qcScanAlertTimer);
    if (type !== 'info') {
        window.qcScanAlertTimer = setTimeout(function() {
            alertBox.classList.add('d-none');
        }, 6000);
    }
}

// Core scan lookup trigger
function handleQCScan(scannedText) {
    if (!scannedText || !scannedText.trim()) return;
    const text = scannedText.trim();

    showScanAlert('Searching for "' + escHtml(text) + '"…', 'info');

    const fd = new FormData();
    fd.append('qr', text);

    fetch('qc_scan_lookup.php', {
        method: 'POST',
        body: fd
    })
    .then(res => res.json())
    .then(data => {
        if (data.ok) {
            playAudioBeep('success');
            showScanAlert('<strong>Matched Pallet ' + escHtml(data.pallet_no) + '!</strong> Please inspect and tick checklist items.', 'success');
            highlightAndOpenPallet(data.pallet_id, data.slitting_product_id);
        } else {
            playAudioBeep('error');
            showScanAlert(data.msg || 'Pallet/Coil not found or already processed.', 'danger');
        }
    })
    .catch(err => {
        console.error('Scan lookup error:', err);
        if (!searchLocalDOM(text)) {
            playAudioBeep('error');
            showScanAlert('Pallet/Coil not found or network lookup failed.', 'danger');
        }
    });
}

// Client-side DOM fallback
function searchLocalDOM(text) {
    text = text.toUpperCase().trim();
    const palletRows = document.querySelectorAll('[data-pallet-no]');
    for (let pRow of palletRows) {
        const pNo = (pRow.getAttribute('data-pallet-no') || '').toUpperCase();
        if (pNo === text || pNo.includes(text)) {
            const pid = parseInt(pRow.getAttribute('data-pallet-id'), 10);
            playAudioBeep('success');
            showScanAlert('<strong>Matched Pallet ' + escHtml(pRow.getAttribute('data-pallet-no')) + '!</strong>', 'success');
            highlightAndOpenPallet(pid, null);
            return true;
        }
    }
    const rollRows = document.querySelectorAll('[data-lot]');
    for (let rRow of rollRows) {
        const lot  = (rRow.getAttribute('data-lot') || '').toUpperCase();
        const coil = (rRow.getAttribute('data-coil') || '').toUpperCase();
        if (lot && coil && text.includes(lot) && text.includes(coil)) {
            const pid = parseInt(rRow.getAttribute('data-pallet-id'), 10);
            const prodId = parseInt(rRow.getAttribute('data-product-id'), 10);
            playAudioBeep('success');
            showScanAlert('<strong>Matched Roll on Pallet!</strong> Please inspect checklist items.', 'success');
            highlightAndOpenPallet(pid, prodId);
            return true;
        }
    }
    return false;
}

// Highlight & navigate to target pallet and roll
function highlightAndOpenPallet(pid, productId) {
    document.querySelectorAll('.scan-row-highlight').forEach(el => el.classList.remove('scan-row-highlight'));
    document.querySelectorAll('.scan-focus-checklist').forEach(el => el.classList.remove('scan-focus-checklist'));

    const palletRow = document.getElementById('pallet-row-' + pid);
    const rollsRow  = document.getElementById('rollsRow' + pid);

    if (!palletRow) return;

    if (rollsRow && rollsRow.style.display === 'none') {
        toggleRolls(pid);
    }

    palletRow.classList.add('scan-row-highlight');
    palletRow.scrollIntoView({ behavior: 'smooth', block: 'center' });

    if (productId) {
        const rollRow = document.getElementById('checkRow_' + pid + '_' + productId);
        const cell    = document.getElementById('checklistCell_' + pid + '_' + productId);
        const windBox = document.getElementById('winding_' + pid + '_' + productId);

        if (rollRow) rollRow.classList.add('scan-row-highlight');
        if (cell)    cell.classList.add('scan-focus-checklist');

        // Focus the first checkbox for QC user to manually inspect and tick
        if (windBox) {
            setTimeout(function() { windBox.focus(); }, 450);
        }
    }
}

// Single date preset helper function
function setSingleDatePreset(preset) {
    const dateInput = document.getElementById('filter_date');
    if (!dateInput) return;

    const d = new Date();
    if (preset === 'today') {
        dateInput.value = d.toISOString().split('T')[0];
    } else if (preset === 'yesterday') {
        d.setDate(d.getDate() - 1);
        dateInput.value = d.toISOString().split('T')[0];
    }
    const form = document.getElementById('qcDateFilterForm');
    if (form) form.submit();
}
</script>
<script src="camera_scanner.js?v=8"></script>
<script>
if (typeof initCameraScanner === 'function') {
    initCameraScanner({
        onScan: function(text) {
            handleQCScan(text);
        }
    });
}
</script>
</body>
</html>
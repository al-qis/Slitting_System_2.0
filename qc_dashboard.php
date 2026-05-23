<?php
// qc_dashboard.php — v2: Added Est. Weight display
session_start();
require_once 'config.php';

// ── Fetch pallet items WITH std_weight for Est. Weight calc ───
function getPalletItems(mysqli $conn, int $pallet_id): array {
    $stmt = $conn->prepare("
        SELECT pi.seq, pi.added_at,
               sp.id AS product_id,
               sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
               sp.width, sp.length, sp.actual_length, sp.status,
               COALESCE(sw.std_weight, 0) AS std_weight
        FROM pallet_items pi
        JOIN slitting_product sp ON sp.id = pi.slitting_product_id
        LEFT JOIN std_wgt sw     ON sw.product_code = sp.product
        WHERE pi.pallet_id = ?
        ORDER BY pi.seq ASC
    ");
    $stmt->bind_param("i", $pallet_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Est. Weight per roll: (length_m × width_mm / 1000) × std_weight
function calcEstWeight(float $lengthM, float $widthMm, float $stdWeight): float {
    if ($lengthM <= 0 || $widthMm <= 0 || $stdWeight <= 0) return 0.0;
    return ($lengthM * $widthMm / 1000) * $stdWeight;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'qc') {
    header("Location: login.php");
    exit;
}

$query = "
    SELECT p.id, p.pallet_no, p.status,
           p.customer_name, p.ref_no, p.product_type, p.width,
           p.created_at, p.updated_at,
           COUNT(pi.id) AS roll_count
    FROM pallets p
    JOIN pallet_items pi ON pi.pallet_id = p.id
    WHERE p.status = 'pending_qc'
    GROUP BY p.id
    ORDER BY p.updated_at DESC
";
$result = $conn->query($query);

$pallets = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $items = getPalletItems($conn, (int)$row['id']);

        // Calculate total Est. Weight for this pallet
        $totalWgt = 0.0;
        foreach ($items as $item) {
            $len = (float)($item['actual_length'] ?: $item['length']);
            $totalWgt += calcEstWeight($len, (float)$item['width'], (float)$item['std_weight']);
        }

        $row['items']     = $items;
        $row['total_wgt'] = $totalWgt;
        $pallets[]        = $row;
    }
}

// Grand totals for summary cards
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
        body { background-color: #f4f7f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .navbar { background-color: #2c3e50; }
        .card { border: none; border-radius: 10px; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); }

        .table thead th {
            background-color: #f8f9fa;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.05em;
            color: #6c757d;
        }
        .pallet-row td { vertical-align: middle; }
        .pallet-row .pallet-no { font-weight: 700; font-family: monospace; font-size: 14px; }

        /* ── Roll sub-table ── */
        .roll-sub-table { font-size: 12px; margin: 0; }
        .roll-sub-table th {
            background: #e9ecef; padding: 5px 10px;
            font-size: 11px; text-transform: uppercase;
        }
        .roll-sub-table td { padding: 5px 10px; border-bottom: 1px solid #f0f0f0; }

        .expand-toggle { cursor: pointer; user-select: none; }
        .expand-toggle:hover td { background: #f8f9fa; }

        .pallet-badge { font-size: 11px; padding: 4px 10px; border-radius: 20px; font-weight: 600; }
        .roll-ref     { font-family: monospace; font-weight: 600; }
        .roll-count-pill {
            background: #e0f2fe; color: #0369a1;
            font-weight: 700; padding: 3px 10px;
            border-radius: 20px; font-size: 12px;
        }
        .constraint-chip {
            font-size: 11px; background: #f1f5f9; color: #475569;
            padding: 2px 8px; border-radius: 10px; display: inline-block;
        }

        /* ── Weight chips ── */
        .wgt-pill-pallet {
            display: inline-flex; align-items: center; gap: 4px;
            background: #fef3c7; color: #92400e;
            border: 1px solid #fcd34d;
            font-weight: 700; padding: 3px 10px;
            border-radius: 20px; font-size: 12px;
            white-space: nowrap;
        }
        .wgt-pill-pallet i { font-size: 11px; }

        .wgt-chip-roll {
            display: inline-flex; align-items: center; gap: 3px;
            background: #fef9c3; color: #713f12;
            border: 1px solid #fde68a;
            border-radius: 8px; padding: 2px 7px;
            font-size: 11px; font-weight: 700;
            white-space: nowrap;
        }

        /* ── Weight summary bar inside expanded row ── */
        .pallet-wgt-bar {
            display: flex; align-items: center; gap: 16px;
            background: linear-gradient(135deg, #fef3c7, #fffbeb);
            border: 1px solid #fcd34d; border-radius: 8px;
            padding: 10px 16px; margin-bottom: 10px;
            flex-wrap: wrap;
        }
        .pallet-wgt-bar .label {
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .5px;
            color: #78350f; display: flex; align-items: center; gap: 5px;
        }
        .pallet-wgt-bar .total {
            font-size: 22px; font-weight: 800;
            color: #92400e; font-family: monospace; line-height: 1;
        }
        .pallet-wgt-bar .unit {
            font-size: 13px; font-weight: 600; color: #b45309;
        }
        .pallet-wgt-bar .meta {
            font-size: 11px; color: #92400e;
            background: rgba(255,255,255,.6); border-radius: 6px;
            padding: 3px 9px;
        }

        /* ── Grand total weight card ── */
        .grand-wgt-card {
            border: none; border-radius: 10px;
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
            background: linear-gradient(135deg, #fef3c7, #fffbeb);
            border-left: 4px solid #f59e0b !important;
        }
        .grand-wgt-card .val { font-size: 1.5rem; font-weight: 800; color: #92400e; line-height: 1; }
        .grand-wgt-card .lbl { font-size: 0.7rem; text-transform: uppercase;
                               letter-spacing: .8px; color: #b45309; margin-top: 3px; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark mb-4">
    <div class="container">
        <a class="navbar-brand fw-bold" href="#">
            <i class="bi bi-shield-check"></i> NICHIAS QC MANAGEMENT
        </a>
        <div class="d-flex">
            <span class="navbar-text text-light me-3">
                QC: <?= htmlspecialchars($_SESSION['role'] ?? 'qc') ?>
            </span>
            <a href="qc_log.php"  class="btn btn-outline-light btn-sm me-2">QC Log</a>
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

    <!-- ── Summary cards ── -->
    <div class="row g-3 mb-4">

        <!-- Pallets awaiting QC -->
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

        <!-- Total rolls pending -->
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

        <!-- Grand total Est. Weight -->
        <div class="col-md-4">
            <div class="card grand-wgt-card p-3 border-start">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 p-3 rounded" style="background:rgba(245,158,11,.12);">
                        <i class="bi bi-speedometer2 fs-3" style="color:#d97706;"></i>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="lbl">Total Est. Weight (Pending)</div>
                        <div class="d-flex align-items-baseline gap-1 mt-1">
                            <span class="val">
                                <?= $grandTotalWgt > 0 ? number_format($grandTotalWgt, 2) : '—' ?>
                            </span>
                            <?php if ($grandTotalWgt > 0): ?>
                            <span style="font-size:14px; font-weight:700; color:#b45309;">kg</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($grandTotalRolls > 0 && $grandTotalWgt > 0): ?>
                        <div style="font-size:11px; color:#92400e; margin-top:2px;">
                            avg <?= number_format($grandTotalWgt / $grandTotalRolls, 2) ?> kg/roll
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /summary row -->

    <!-- ── Pallets table ── -->
    <div class="card shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="card-title mb-0 fw-bold">
                <i class="bi bi-archive me-2"></i>Pending Pallets
            </h5>
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
                <!-- Pallet summary row -->
                <tr class="pallet-row expand-toggle" onclick="toggleRolls(<?= $pid ?>)">
                    <td class="text-center">
                        <i class="bi bi-chevron-right" id="chevron<?= $pid ?>"
                           style="transition:transform .2s;"></i>
                    </td>
                    <td>
                        <div class="pallet-no"><?= htmlspecialchars($pallet['pallet_no']) ?></div>
                        <div style="font-size:11px; color:#9ca3af;">ID #<?= $pid ?></div>
                    </td>
                    <td>
                        <div class="d-flex flex-wrap gap-1">
                            <?php if (!empty(trim($pallet['customer_name']))): ?>
                            <span class="constraint-chip">
                                <i class="bi bi-person me-1"></i><?= htmlspecialchars($pallet['customer_name']) ?>
                            </span>
                            <span class="constraint-chip">
                                <i class="bi bi-hash me-1"></i><?= htmlspecialchars($pallet['ref_no']) ?>
                            </span>
                            <span class="constraint-chip">
                                <i class="bi bi-tag me-1"></i><?= htmlspecialchars($pallet['product_type']) ?>
                            </span>
                            <span class="constraint-chip">
                                <?= number_format((float)$pallet['width']) ?> mm
                            </span>
                            <?php else: ?>
                            <span class="text-muted" style="font-size:11px;">—</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <span class="roll-count-pill">
                            <?= (int)$pallet['roll_count'] ?> roll<?= $pallet['roll_count'] != 1 ? 's' : '' ?>
                        </span>
                    </td>
                    <!-- Est. Weight column -->
                    <td>
                        <?php if ($pallet['total_wgt'] > 0): ?>
                        <span class="wgt-pill-pallet">
                            <i class="bi bi-speedometer2"></i>
                            <?= number_format($pallet['total_wgt'], 2) ?> kg
                        </span>
                        <?php else: ?>
                        <span class="text-muted" style="font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-size:13px;">
                            <?= date('d M Y', strtotime($pallet['updated_at'])) ?>
                        </div>
                        <div style="font-size:11px; color:#9ca3af;">
                            <?= date('H:i', strtotime($pallet['updated_at'])) ?>
                        </div>
                    </td>
                    <td class="text-end pe-4" onclick="event.stopPropagation()">
                        <!-- Approve -->
                        <form method="POST" action="pallet_qc_action.php" class="d-inline">
                            <input type="hidden" name="action"    value="approve">
                            <input type="hidden" name="pallet_id" value="<?= $pid ?>">
                            <button type="submit" class="btn btn-success btn-sm px-3 shadow-sm"
                                    onclick="return confirm('Approve all rolls on pallet <?= htmlspecialchars($pallet['pallet_no'], ENT_QUOTES) ?>?\n\nEst. Total Weight: <?= $pallet['total_wgt'] > 0 ? number_format($pallet['total_wgt'], 2) . ' kg' : 'N/A' ?>')">
                                <i class="bi bi-check-lg"></i> Approve
                            </button>
                        </form>

                        <!-- Reject -->
                        <button type="button"
                                class="btn btn-danger btn-sm px-3 shadow-sm ms-1"
                                data-bs-toggle="modal"
                                data-bs-target="#rejectModal<?= $pid ?>">
                            <i class="bi bi-x-lg"></i> Reject
                        </button>
                    </td>
                </tr>

                <!-- Expandable rolls sub-table -->
                <tr id="rollsRow<?= $pid ?>" style="display:none;">
                    <td colspan="7" style="padding:0 0 10px 50px; background:#f8faff;">

                        <!-- Weight summary bar -->
                        <?php if ($pallet['total_wgt'] > 0): ?>
                        <div class="pallet-wgt-bar mt-2 me-3">
                            <div class="label">
                                <i class="bi bi-speedometer2"></i>
                                Est. Total Weight
                            </div>
                            <div style="display:flex; align-items:baseline; gap:5px;">
                                <span class="total"><?= number_format($pallet['total_wgt'], 2) ?></span>
                                <span class="unit">kg</span>
                            </div>
                            <div class="meta">
                                <?= (int)$pallet['roll_count'] ?> roll<?= $pallet['roll_count'] != 1 ? 's' : '' ?>
                                &nbsp;·&nbsp;
                                avg <?= number_format($pallet['total_wgt'] / max(1, (int)$pallet['roll_count']), 2) ?> kg/roll
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Roll detail table -->
                        <table class="roll-sub-table table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Product</th>
                                    <th>Lot · Coil · Roll</th>
                                    <th>Width (mm)</th>
                                    <th>Actual Length (m)</th>
                                    <th>Est. Weight</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($pallet['items'] as $item):
                                $itemLen = (float)($item['actual_length'] ?: $item['length']);
                                $itemWgt = calcEstWeight($itemLen, (float)$item['width'], (float)$item['std_weight']);
                            ?>
                                <tr>
                                    <td><?= $item['seq'] ?></td>
                                    <td><?= htmlspecialchars($item['product'] ?? '—') ?></td>
                                    <td class="roll-ref">
                                        <?= htmlspecialchars($item['lot_no']) ?>
                                        <?= htmlspecialchars($item['coil_no']) ?>
                                        – <?= str_replace('R', 'R-', htmlspecialchars($item['roll_no'])) ?>
                                    </td>
                                    <td><?= number_format((float)$item['width']) ?></td>
                                    <td>
                                        <?php $len = $item['actual_length'] ?: $item['length']; ?>
                                        <?= number_format((float)$len, 1) ?>
                                        <?php if ($item['actual_length']): ?>
                                        <span style="font-size:10px;background:#dcfce7;color:#166534;
                                               padding:1px 5px;border-radius:8px;">ACTUAL</span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Est. Weight per roll -->
                                    <td>
                                        <?php if ($itemWgt > 0): ?>
                                        <span class="wgt-chip-roll">
                                            <i class="bi bi-speedometer2"></i>
                                            <?= number_format($itemWgt, 2) ?> kg
                                        </span>
                                        <?php else: ?>
                                        <span class="text-muted" style="font-size:11px;">N/A</span>
                                        <?php endif; ?>
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
    </div><!-- /.card -->

</div><!-- /.container -->

<!-- ── REJECT MODALS (outside <table>) ── -->
<?php foreach ($pallets as $pallet):
    $pid = (int)$pallet['id'];
?>
<div class="modal fade" id="rejectModal<?= $pid ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="pallet_qc_action.php" class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-x-circle me-2"></i>
                    Reject Pallet: <?= htmlspecialchars($pallet['pallet_no']) ?>
                </h5>
                <button type="button" class="btn-close btn-close-white"
                        data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action"    value="reject">
                <input type="hidden" name="pallet_id" value="<?= $pid ?>">

                <p class="text-muted small mb-2">
                    All <strong><?= (int)$pallet['roll_count'] ?> roll<?= $pallet['roll_count'] != 1 ? 's' : '' ?></strong>
                    on pallet <strong><?= htmlspecialchars($pallet['pallet_no']) ?></strong>
                    will be marked <span class="text-danger fw-bold">REJECTED</span>
                    and returned to the operator for rework.
                </p>

                <?php if ($pallet['total_wgt'] > 0): ?>
                <div class="d-flex align-items-center gap-2 mb-3 p-2 rounded"
                     style="background:#fef3c7; border:1px solid #fcd34d;">
                    <i class="bi bi-speedometer2" style="color:#d97706;"></i>
                    <span style="font-size:13px; color:#78350f; font-weight:600;">
                        Est. Total Weight: <strong><?= number_format($pallet['total_wgt'], 2) ?> kg</strong>
                    </span>
                </div>
                <?php endif; ?>

                <label class="form-label fw-bold">
                    Reason for Rejection <span class="text-danger">*</span>
                </label>
                <textarea name="comment" class="form-control" rows="3"
                          required
                          placeholder="Describe the issue clearly…"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                        data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger px-4">
                    <i class="bi bi-x-lg me-1"></i> Confirm Reject
                </button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleRolls(pid) {
    const row     = document.getElementById('rollsRow' + pid);
    const chevron = document.getElementById('chevron' + pid);
    const open    = row.style.display !== 'none';
    row.style.display       = open ? 'none' : 'table-row';
    chevron.style.transform = open ? 'rotate(0deg)' : 'rotate(90deg)';
}
</script>
</body>
</html>
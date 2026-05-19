<?php
// qc_dashboard.php
// ============================================================
// CHANGES FROM ORIGINAL:
//  - Now lists PALLETS in 'pending_qc' status instead of
//    individual rolls.
//  - Each pallet row expands to show all rolls inside it.
//  - Approve / Reject acts on the whole pallet at once.
//  - Form POSTs to pallet_qc_action.php (new file).
// ============================================================

session_start();
require_once 'config.php';

// ============================================================
// INLINED PALLET HELPERS — no external file needed
// ============================================================
function getPallet(mysqli $conn, int $pallet_id): ?array {
    $stmt = $conn->prepare("SELECT * FROM pallets WHERE id = ?");
    $stmt->bind_param("i", $pallet_id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}
function getPalletItems(mysqli $conn, int $pallet_id): array {
    $stmt = $conn->prepare("
        SELECT pi.seq, pi.added_at,
               sp.id AS product_id,
               sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
               sp.width, sp.length, sp.actual_length, sp.status
        FROM pallet_items pi
        JOIN slitting_product sp ON sp.id = pi.slitting_product_id
        WHERE pi.pallet_id = ?
        ORDER BY pi.seq ASC
    ");
    $stmt->bind_param("i", $pallet_id); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    return $rows;
}
// ============================================================

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'qc') {
    header("Location: login.php");
    exit;
}

// ── Fetch pallets pending QC ─────────────────────────────────
$query = "
    SELECT p.id, p.pallet_no, p.status, p.created_at, p.updated_at,
           COUNT(pi.id) AS roll_count
    FROM pallets p
    JOIN pallet_items pi ON pi.pallet_id = p.id
    WHERE p.status = 'pending_qc'
    GROUP BY p.id
    ORDER BY p.updated_at DESC
";
$result = $conn->query($query);
if ($result === false) { $result = null; }

// Gather all rolls for each pallet upfront
$pallets = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['items'] = getPalletItems($conn, (int)$row['id']);
        $pallets[]    = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QC Dashboard - Nichias Slitting System</title>
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

        /* Pallet summary row */
        .pallet-row td { vertical-align: middle; }
        .pallet-row .pallet-no { font-weight: 700; font-family: monospace; font-size: 14px; }

        /* Roll sub-table inside expanded pallet */
        .roll-sub-table { font-size: 12px; margin: 0; }
        .roll-sub-table th { background: #e9ecef; padding: 5px 10px; font-size: 11px; text-transform: uppercase; }
        .roll-sub-table td { padding: 5px 10px; border-bottom: 1px solid #f0f0f0; }

        .expand-toggle { cursor: pointer; user-select: none; }
        .expand-toggle:hover { background: #f8f9fa; }

        .pallet-badge { font-size: 11px; padding: 4px 10px; border-radius: 20px; font-weight: 600; }
        .badge-pending_qc { background: #fef3c7; color: #92400e; }

        .roll-ref { font-family: monospace; font-weight: 600; }
        .roll-count-pill { background: #e0f2fe; color: #0369a1; font-weight: 700;
                           padding: 3px 10px; border-radius: 20px; font-size: 12px; }
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
                QC: <?= htmlspecialchars($_SESSION['role'] ?? 'qc', ENT_QUOTES, 'UTF-8') ?>
            </span>
            <a href="qc_log.php"    class="btn btn-outline-light btn-sm me-2">QC Log</a>
            <a href="logout.php"    class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container pb-5">

    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark">Quality Control Dashboard</h2>
            <p class="text-secondary">Inspect and approve or reject pallets for dispatch.</p>
        </div>
        <button onclick="window.location.reload()" class="btn btn-white border shadow-sm">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>

    <!-- Alerts -->
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

    <!-- Summary card -->
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
                        <h3 class="fw-bold mb-0">
                            <?= array_sum(array_column($pallets, 'roll_count')) ?>
                        </h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Pallets table -->
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
                        <th>Rolls</th>
                        <th>Sent to QC</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pallets as $pallet):
                    $pid = (int)$pallet['id'];
                ?>
                <!-- Pallet summary row (clickable to expand) -->
                <tr class="pallet-row expand-toggle"
                    onclick="toggleRolls(<?= $pid ?>)"
                    id="palletRow<?= $pid ?>">
                    <td class="text-center">
                        <i class="bi bi-chevron-right" id="chevron<?= $pid ?>"
                           style="transition:transform .2s;"></i>
                    </td>
                    <td>
                        <div class="pallet-no"><?= htmlspecialchars($pallet['pallet_no']) ?></div>
                        <div style="font-size:11px; color:#9ca3af;">ID #<?= $pid ?></div>
                    </td>
                    <td>
                        <span class="roll-count-pill"><?= (int)$pallet['roll_count'] ?> roll<?= $pallet['roll_count'] != 1 ? 's' : '' ?></span>
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
                        <!-- Approve button -->
                        <form method="POST" action="pallet_qc_action.php" class="d-inline">
                            <input type="hidden" name="action"    value="approve">
                            <input type="hidden" name="pallet_id" value="<?= $pid ?>">
                            <button type="submit" class="btn btn-success btn-sm px-3 shadow-sm">
                                <i class="bi bi-check-lg"></i> Approve All
                            </button>
                        </form>

                        <!-- Reject button (opens modal) -->
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
                    <td colspan="5" style="padding:0 0 10px 50px; background:#f8faff;">
                        <table class="roll-sub-table table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Product</th>
                                    <th>Lot · Coil · Roll</th>
                                    <th>Width (mm)</th>
                                    <th>Actual Length (m)</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($pallet['items'] as $item): ?>
                                <tr>
                                    <td><?= $item['seq'] ?></td>
                                    <td><?= htmlspecialchars($item['product'] ?? '—') ?></td>
                                    <td class="roll-ref">
                                        <?= htmlspecialchars($item['lot_no']) ?>
                                        <?= htmlspecialchars($item['coil_no']) ?>
                                        – <?= str_replace('R','R-', htmlspecialchars($item['roll_no'])) ?>
                                    </td>
                                    <td><?= number_format((float)$item['width']) ?></td>
                                    <td>
                                        <?php
                                        $len = $item['actual_length'] ?: $item['length'];
                                        echo number_format((float)$len, 1);
                                        if ($item['actual_length']) {
                                            echo ' <span style="font-size:10px;background:#dcfce7;color:#166534;padding:1px 5px;border-radius:8px;">ACTUAL</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>

                <!-- Reject modal for this pallet -->
                <div class="modal fade" id="rejectModal<?= $pid ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <form method="POST" action="pallet_qc_action.php" class="modal-content">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title">
                                    Reject Pallet: <?= htmlspecialchars($pallet['pallet_no']) ?>
                                </h5>
                                <button type="button" class="btn-close btn-close-white"
                                        data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body text-start">
                                <input type="hidden" name="action"    value="reject">
                                <input type="hidden" name="pallet_id" value="<?= $pid ?>">
                                <p class="text-muted small">
                                    All <?= (int)$pallet['roll_count'] ?> rolls on this pallet will be
                                    marked as <strong>REJECTED</strong> and returned to the operator
                                    for rework or replacement.
                                </p>
                                <label class="form-label fw-bold">Reason for Rejection:</label>
                                <textarea name="comment" class="form-control" rows="3"
                                          required placeholder="Describe the issue clearly…"></textarea>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary"
                                        data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-danger">
                                    Confirm Reject
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleRolls(pid) {
    const rollsRow = document.getElementById('rollsRow' + pid);
    const chevron  = document.getElementById('chevron' + pid);
    const open     = rollsRow.style.display !== 'none';
    rollsRow.style.display = open ? 'none' : 'table-row';
    chevron.style.transform = open ? 'rotate(0deg)' : 'rotate(90deg)';
}
</script>
</body>
</html>
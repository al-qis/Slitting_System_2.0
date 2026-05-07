<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'qc') {
    header("Location: login.php");
    exit;
}

// Filters
$filter_status = $_GET['status'] ?? 'all';
$search        = trim($_GET['search'] ?? '');
$date_from     = $_GET['date_from'] ?? '';
$date_to       = $_GET['date_to']   ?? '';

// Build WHERE clause
$where   = "WHERE status IN ('APPROVED','REJECTED')";
$params  = [];
$types   = '';

if ($filter_status === 'approved') {
    $where .= " AND status = 'APPROVED'";
} elseif ($filter_status === 'rejected') {
    $where .= " AND status = 'REJECTED'";
}

if ($search !== '') {
    $where   .= " AND (lot_no LIKE ? OR coil_no LIKE ? OR roll_no LIKE ? OR product LIKE ?)";
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like, $like]);
    $types   .= 'ssss';
}

if ($date_from !== '') {
    $where  .= " AND DATE(updated_at) >= ?";
    $params[] = $date_from;
    $types   .= 's';
}

if ($date_to !== '') {
    $where  .= " AND DATE(updated_at) <= ?";
    $params[] = $date_to;
    $types   .= 's';
}

$sql  = "SELECT id, lot_no, coil_no, roll_no, product, width, length, actual_length,
                status, qc_comment, date_in, updated_at
         FROM slitting_product
         $where
         ORDER BY updated_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

// Summary counts
$total_approved = $conn->query("SELECT COUNT(*) AS c FROM slitting_product WHERE status='APPROVED'")->fetch_assoc()['c'];
$total_rejected = $conn->query("SELECT COUNT(*) AS c FROM slitting_product WHERE status='REJECTED'")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QC Log - Nichias Slitting System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f7f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .navbar { background-color: #2c3e50; }

        .summary-card { border: none; border-radius: 10px; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); }

        .table thead th {
            background-color: #f8f9fa;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.05em;
            color: #6c757d;
        }

        .coil-ref .lot  { font-weight: 700; font-size: 0.9rem; color: #212529; }
        .coil-ref .coil { font-size: 0.85rem; color: #495057; }
        .coil-ref .roll-badge {
            font-size: 0.7rem;
            background-color: #e9ecef;
            color: #495057;
            border: 1px solid #ced4da;
            border-radius: 4px;
            padding: 1px 6px;
            display: inline-block;
            margin-top: 2px;
        }

        .reject-comment {
            background: #fff5f5;
            border-left: 3px solid #dc3545;
            border-radius: 4px;
            padding: 6px 10px;
            font-size: 0.82rem;
            color: #842029;
            max-width: 240px;
        }

        .timestamp-block .date { font-weight: 600; font-size: 0.85rem; }
        .timestamp-block .time { font-size: 0.75rem; color: #6c757d; }

        .filter-bar { background: #fff; border-radius: 10px; padding: 16px 20px; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.06); }

        .badge-approved { background-color: #198754; }
        .badge-rejected { background-color: #dc3545; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark mb-4">
    <div class="container">
        <a class="navbar-brand fw-bold" href="qc_dashboard.php">
            <i class="bi bi-shield-check"></i> NICHIAS QC MANAGEMENT
        </a>
        <div class="d-flex align-items-center gap-2">
            <span class="navbar-text text-light">
                Logged in as: <?= htmlspecialchars($_SESSION['role'] ?? 'qc', ENT_QUOTES, 'UTF-8') ?>
            </span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container pb-5">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark mb-1">
                <i class="bi bi-clock-history me-2 text-secondary"></i>QC Inspection Log
            </h2>
            <p class="text-secondary mb-0">Full history of approved and rejected coils.</p>
        </div>
        <a href="qc_dashboard.php" class="btn btn-outline-secondary shadow-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
        </a>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="summary-card card p-3 border-start border-success border-4">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 bg-success bg-opacity-10 p-3 rounded">
                        <i class="bi bi-check-circle text-success fs-3"></i>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="text-uppercase text-secondary small mb-1">Total Approved</h6>
                        <h3 class="fw-bold mb-0 text-success"><?= (int)$total_approved ?></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="summary-card card p-3 border-start border-danger border-4">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 bg-danger bg-opacity-10 p-3 rounded">
                        <i class="bi bi-x-circle text-danger fs-3"></i>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="text-uppercase text-secondary small mb-1">Total Rejected</h6>
                        <h3 class="fw-bold mb-0 text-danger"><?= (int)$total_rejected ?></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="summary-card card p-3 border-start border-primary border-4">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 bg-primary bg-opacity-10 p-3 rounded">
                        <i class="bi bi-bar-chart text-primary fs-3"></i>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="text-uppercase text-secondary small mb-1">Pass Rate</h6>
                        <h3 class="fw-bold mb-0 text-primary">
                            <?php
                                $total = $total_approved + $total_rejected;
                                echo $total > 0 ? round(($total_approved / $total) * 100, 1) . '%' : '—';
                            ?>
                        </h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar mb-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-bold text-secondary mb-1">Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Lot, Coil, Roll, Product..."
                       value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold text-secondary mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="all"      <?= $filter_status === 'all'      ? 'selected' : '' ?>>All</option>
                    <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold text-secondary mb-1">From Date</label>
                <input type="date" name="date_from" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($date_from, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold text-secondary mb-1">To Date</label>
                <input type="date" name="date_to" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($date_to, ENT_QUOTES, 'UTF-8') ?>">
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

    <!-- Log Table -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0 fw-bold">
                <i class="bi bi-list-check me-2"></i>Inspection Records
            </h5>
            <span class="badge bg-secondary"><?= $result->num_rows ?> record(s)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">QC Date &amp; Time</th>
                        <th>Lot / Coil / Roll</th>
                        <th>Product</th>
                        <th>Dimensions</th>
                        <th>Result</th>
                        <th>Rejection Reason</th>
                        <th class="pe-4">Production Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <!-- QC Timestamp -->
                                <td class="ps-4">
                                    <?php if (!empty($row['updated_at'])): ?>
                                        <div class="timestamp-block">
                                            <div class="date">
                                                <?= htmlspecialchars(date('d M Y', strtotime($row['updated_at'])), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                            <div class="time">
                                                <i class="bi bi-clock me-1"></i>
                                                <?= htmlspecialchars(date('H:i:s', strtotime($row['updated_at'])), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Coil Reference -->
                                <td>
                                    <div class="coil-ref">
                                        <div class="lot">
                                            <?= htmlspecialchars($row['lot_no'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                                            <span class="coil ms-1">
                                                <?= htmlspecialchars($row['coil_no'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($row['roll_no'])): ?>
                                            <span class="roll-badge">
                                                <i class="bi bi-record-circle" style="font-size:0.65rem;"></i>
                                                <?= htmlspecialchars($row['roll_no'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Product -->
                                <td><?= htmlspecialchars($row['product'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>

                                <!-- Dimensions -->
                                <td>
                                    <span class="fw-medium"><?= htmlspecialchars((string)($row['width'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="text-muted small">mm</span>
                                    <i class="bi bi-x small text-muted"></i>
                                    <?php if (!empty($row['actual_length']) && $row['actual_length'] > 0): ?>
                                        <span class="text-primary fw-bold"><?= htmlspecialchars((string)$row['actual_length'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="badge bg-info text-dark" style="font-size:0.65rem;">ACTUAL</span>
                                    <?php else: ?>
                                        <span class="fw-medium"><?= htmlspecialchars((string)($row['length'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                    <span class="text-muted small">m</span>
                                </td>

                                <!-- Result Badge -->
                                <td>
                                    <?php if ($row['status'] === 'APPROVED'): ?>
                                        <span class="badge badge-approved rounded-pill px-3 py-2">
                                            <i class="bi bi-check-lg me-1"></i> APPROVED
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-rejected rounded-pill px-3 py-2">
                                            <i class="bi bi-x-lg me-1"></i> REJECTED
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Rejection Reason -->
                                <td>
                                    <?php if ($row['status'] === 'REJECTED' && !empty($row['qc_comment'])): ?>
                                        <div class="reject-comment">
                                            <i class="bi bi-chat-left-text me-1"></i>
                                            <?= htmlspecialchars($row['qc_comment'], ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    <?php elseif ($row['status'] === 'APPROVED'): ?>
                                        <span class="text-muted small">—</span>
                                    <?php else: ?>
                                        <span class="text-muted small fst-italic">No comment</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Production Date -->
                                <td class="pe-4">
                                    <?php if (!empty($row['date_in'])): ?>
                                        <div class="timestamp-block">
                                            <div class="date">
                                                <?= htmlspecialchars(date('d M Y', strtotime($row['date_in'])), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                            <div class="time">
                                                <?= htmlspecialchars(date('H:i', strtotime($row['date_in'])), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-5">
                                <div class="text-muted">
                                    <i class="bi bi-inbox display-4 d-block mb-3 opacity-25"></i>
                                    No inspection records found.
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
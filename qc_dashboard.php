<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'qc') {
    header("Location: login.php");
    exit;
}

$query  = "SELECT id, date_in, coil_no, lot_no, product, width, length, actual_length, roll_no
           FROM slitting_product
           WHERE status = 'WAITING'
           ORDER BY date_in DESC";
$result = $conn->query($query);

if ($result === false) {
    $result = null;
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
        body {
            background-color: #f4f7f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar {
            background-color: #2c3e50;
        }
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        .status-badge {
            font-size: 0.8rem;
            padding: 0.5em 1em;
        }
        .table thead th {
            background-color: #f8f9fa;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.05em;
            color: #6c757d;
        }
        .coil-ref .lot {
            font-weight: 700;
            font-size: 0.9rem;
            color: #212529;
        }
        .coil-ref .coil {
            font-size: 0.85rem;
            color: #495057;
        }
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
                Logged in as: <?= htmlspecialchars($_SESSION['role'] ?? 'qc', ENT_QUOTES, 'UTF-8') ?>
            </span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container pb-5">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark">Quality Control Dashboard</h2>
            <p class="text-secondary">Review and approve slitting products for dispatch.</p>
        </div>
        <button onclick="window.location.reload()" class="btn btn-white border shadow-sm">
            <i class="bi bi-arrow-clockwise"></i> Refresh Data
        </button>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card p-3 border-start border-warning border-4">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 bg-warning bg-opacity-10 p-3 rounded">
                        <i class="bi bi-hourglass-split text-warning fs-3"></i>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="text-uppercase text-secondary small mb-1">Awaiting Inspection</h6>
                        <h3 class="fw-bold mb-0"><?= ($result !== null) ? $result->num_rows : 0 ?></h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="card-title mb-0 fw-bold">Pending Approvals</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Production Date</th>
                        <th>Lot No.</th>
                        <th>Product Type</th>
                        <th>Dimensions (W x L)</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result !== null && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <?php $safe_id = (int)($row['id'] ?? 0); ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold small">
                                        <?= htmlspecialchars(date('d M Y', strtotime($row['date_in'])), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <div class="text-muted" style="font-size: 0.75rem;">
                                        <?= htmlspecialchars(date('H:i A', strtotime($row['date_in'])), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </td>

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

                                <td>
                                    <?= htmlspecialchars($row['product'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                                </td>

                                <td>
                                    <span class="text-dark fw-medium">
                                        <?= htmlspecialchars((string)($row['width'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <span class="text-muted small">mm</span>
                                    <i class="bi bi-x small text-muted"></i>
                                    <?php if (!empty($row['actual_length']) && $row['actual_length'] > 0): ?>
                                        <span class="text-primary fw-bold">
                                            <?= htmlspecialchars((string)$row['actual_length'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <span class="badge bg-info text-dark" style="font-size: 0.65rem;">ACTUAL</span>
                                    <?php else: ?>
                                        <span class="text-dark fw-medium">
                                            <?= htmlspecialchars((string)($row['length'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="text-muted small">m</span>
                                </td>

                                <td>
                                    <span class="badge rounded-pill bg-warning text-dark status-badge">
                                        <i class="bi bi-search"></i> PENDING
                                    </span>
                                </td>

                                <td class="text-end pe-4">
                                    <form method="POST" action="qc_process.php" class="d-inline">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="id" value="<?= $safe_id ?>">
                                        <button type="submit" class="btn btn-success btn-sm px-3 shadow-sm">
                                            <i class="bi bi-check-lg"></i> Approve
                                        </button>
                                    </form>

                                    <button type="button"
                                            class="btn btn-danger btn-sm px-3 shadow-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#rejectModal<?= $safe_id ?>">
                                        <i class="bi bi-x-lg"></i> Reject
                                    </button>

                                    <div class="modal fade"
                                         id="rejectModal<?= $safe_id ?>"
                                         tabindex="-1"
                                         aria-hidden="true">
                                        <div class="modal-dialog">
                                            <form method="POST" action="qc_process.php" class="modal-content">
                                                <div class="modal-header bg-danger text-white">
                                                    <h5 class="modal-title">
                                                        Reject:
                                                        <?= htmlspecialchars(($row['lot_no'] ?? '-') . ' ' . ($row['coil_no'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                                        <?php if (!empty($row['roll_no'])): ?>
                                                            <?= htmlspecialchars($row['roll_no'], ENT_QUOTES, 'UTF-8') ?>
                                                        <?php endif; ?>
                                                    </h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body text-start">
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="id" value="<?= $safe_id ?>">
                                                    <label class="form-label fw-bold">Reason for Rejection:</label>
                                                    <textarea name="comment"
                                                              class="form-control"
                                                              rows="3"
                                                              required
                                                              placeholder="Describe the defect..."></textarea>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-danger">Confirm Reject</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-5">
                                <div class="text-muted">
                                    <i class="bi bi-check-circle display-4 d-block mb-3 opacity-25"></i>
                                    All products have been inspected. No pending QC items.
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Officer Cross-Check Dashboard - Slitting System 2.0</title>

    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

    <style>
        :root {
            --navy-dark: #0f1b2d;
            --soft-green-bg: #dff0d8;
            --soft-green-text: #155724;
            --soft-green-border: #d6e9c6;
            --soft-red-bg: #ffcccc;
            --soft-red-text: #721c24;
            --soft-red-border: #f5c6c3;
            --bg-light: #f8fafc;
        }

        body {
            background-color: var(--bg-light);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            color: #1e293b;
        }

        .navbar-officer {
            background: linear-gradient(135deg, #0f1b2d 0%, #1e293b 100%);
            color: #ffffff;
            padding: 18px 0;
            box-shadow: 0 4px 12px rgba(15, 27, 45, 0.15);
            margin-bottom: 24px;
            position: sticky;
            top: 0;
            z-index: 1030;
        }

        .kpi-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px 20px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
            height: 100%;
        }
        .kpi-title {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-bottom: 6px;
        }
        .kpi-value {
            font-size: 1.8rem;
            font-weight: 800;
            color: #0f1b2d;
            line-height: 1;
        }

        .filter-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 24px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }

        .table-wrap {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
        }

        .table-crosscheck {
            margin-bottom: 0;
            vertical-align: middle;
        }

        .table-crosscheck thead th {
            background-color: #0f1b2d;
            color: #ffffff;
            font-weight: 600;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 14px;
            border: none;
            white-space: nowrap;
        }

        .table-crosscheck tbody td {
            padding: 12px 14px;
            font-size: 0.9rem;
            border-bottom: 1px solid #e2e8f0;
            transition: background-color 0.25s ease, color 0.25s ease;
        }

        /* Highlighting Rules specified by prompt */
        .bg-soft-green {
            background-color: var(--soft-green-bg) !important;
            color: var(--soft-green-text) !important;
        }

        .bg-soft-red {
            background-color: var(--soft-red-bg) !important;
            color: var(--soft-red-text) !important;
            font-weight: 600;
        }

        .cell-match {
            background-color: rgba(223, 240, 216, 0.6) !important;
            color: #155724 !important;
        }

        .cell-discrepancy {
            background-color: #ffcccc !important;
            color: #721c24 !important;
            font-weight: 700;
            border-left: 3px solid #dc3545 !important;
        }

        /* Interactive Discrepancy Verification Button */
        .btn-toggle-discrepancy {
            border: none;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.82rem;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .btn-toggle-discrepancy.btn-red {
            background-color: #dc3545;
            color: #ffffff;
        }
        .btn-toggle-discrepancy.btn-red:hover {
            background-color: #bb2d3b;
            transform: scale(1.03);
        }
        .btn-toggle-discrepancy.btn-verified {
            background-color: #198754;
            color: #ffffff;
        }
        .btn-toggle-discrepancy.btn-verified:hover {
            background-color: #157347;
        }

        .badge-match {
            background-color: #198754;
            color: #ffffff;
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 20px;
        }

        @media print {
            .navbar-officer, .filter-box, .no-print { display: none !important; }
            body { background: #ffffff; }
            .table-wrap { border: none; box-shadow: none; }
        }
    </style>
</head>
<body>

    <!-- Header Navigation -->
    <header class="navbar-officer">
        <div class="container-fluid px-4">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <h2 class="m-0 fw-bold fs-4 d-flex align-items-center gap-2">
                        <i class="bi bi-clipboard-check-fill text-warning"></i>
                        Officer Production Cross-Check Dashboard
                    </h2>
                    <div class="small text-slate-300 mt-1" style="color: #cbd5e1;">
                        Automated multi-parameter cross-check (Width, Length, Lot No) for Finished Products
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 no-print">
                    <span class="badge bg-secondary px-3 py-2">
                        Role: <?php echo htmlspecialchars(strtoupper($_SESSION['role'])); ?>
                    </span>
                    <a href="finish_product.php" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-arrow-left me-1"></i> Finish Product Page
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container-fluid px-4 pb-5">

        <!-- Summary KPI Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="kpi-title">Total Rolls Checked</div>
                    <div class="kpi-value" id="kpiTotalRolls"><?php echo $totalRolls; ?></div>
                    <small class="text-muted"><?php echo $monthNames[$month] . ' ' . $year; ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card" style="border-left: 4px solid #198754;">
                    <div class="kpi-title text-success">Fully Matched / Verified</div>
                    <div class="kpi-value text-success" id="kpiMatchedRolls"><?php echo $matchedRolls; ?></div>
                    <small class="text-muted">Width, Length &amp; Lot matched</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card" style="border-left: 4px solid #dc3545;">
                    <div class="kpi-title text-danger">Discrepancies Found</div>
                    <div class="kpi-value text-danger" id="kpiDiscrepancyRolls"><?php echo $discrepancyRolls; ?></div>
                    <small class="text-danger fw-semibold">
                        <?php echo "W: {$widthMismatches} | L: {$lengthMismatches} | Lot: {$lotMismatches}"; ?>
                    </small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card" style="border-left: 4px solid #0d6efd;">
                    <div class="kpi-title text-primary">Compliance Rate</div>
                    <div class="kpi-value text-primary" id="kpiComplianceRate"><?php echo $complianceRate; ?>%</div>
                    <small class="text-muted">Specification Pass Rate</small>
                </div>
            </div>
        </div>

        <!-- Filter Controls Form -->
        <div class="filter-box no-print">
            <form method="GET" action="finish_product_crosscheck.php" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted">Month</label>
                    <select name="month" class="form-select">
                        <?php foreach ($monthNames as $num => $name): ?>
                            <option value="<?php echo $num; ?>" <?php echo $num === $month ? 'selected' : ''; ?>>
                                <?php echo $name; ?> (<?php echo sprintf('%02d', $num); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted">Year</label>
                    <select name="year" class="form-select">
                        <?php for ($y = 2024; $y <= 2030; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted">Status Filter</label>
                    <select name="status_filter" class="form-select">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Rolls</option>
                        <option value="discrepancy" <?php echo $statusFilter === 'discrepancy' ? 'selected' : ''; ?>>Discrepancies Only (Red Flags)</option>
                        <option value="matched" <?php echo $statusFilter === 'matched' ? 'selected' : ''; ?>>Matched Only (Green)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted">Search Keyword</label>
                    <input type="text" name="search" class="form-control" placeholder="Coil No, Lot No, Product..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100 fw-semibold">
                        <i class="bi bi-filter me-1"></i> Filter
                    </button>
                    <a href="finish_product_crosscheck.php?month=8" class="btn btn-outline-secondary" title="Reset to August">
                        Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Cross-Check Results Table -->
        <div class="table-wrap">
            <div class="table-responsive">
                <table class="table table-crosscheck">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Mother Coil / Roll No</th>
                            <th>Product Code</th>
                            <th>Lot Number (Actual vs Plan)</th>
                            <th>Width (Actual vs Plan)</th>
                            <th>Length (Actual vs Plan)</th>
                            <th>Validation Status (Click to Verify)</th>
                            <th>Discrepancy Details</th>
                            <th class="no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <i class="bi bi-folder-x fs-1 d-block mb-2 text-secondary"></i>
                                    No finished product records found for <?php echo $monthNames[$month] . ' ' . $year; ?>.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($records as $index => $rec): 
                                $r = $rec['row'];
                                $rollId = (int)$r['product_id'];
                                $rowClass = $rec['is_discrepancy'] ? 'bg-soft-red' : 'bg-soft-green';
                            ?>
                                <tr class="<?php echo $rowClass; ?>" id="roll-row-<?php echo $rollId; ?>">
                                    <td class="fw-bold"><?php echo $index + 1; ?></td>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($r['actual_coil_no'] ?? '-'); ?></div>
                                        <span class="badge bg-dark"><?php echo htmlspecialchars($r['actual_roll_no'] ?? '-'); ?></span>
                                    </td>
                                    <td>
                                        <code><?php echo htmlspecialchars($r['product'] ?? '-'); ?></code>
                                    </td>
                                    
                                    <!-- 1. Lot Number Comparison Cell -->
                                    <td class="cell-lot <?php echo $rec['lot_match'] ? 'cell-match' : 'cell-discrepancy'; ?>">
                                        <div><strong>Actual:</strong> <?php echo htmlspecialchars($rec['actual_lot']); ?></div>
                                        <div class="small"><strong>Plan:</strong> <?php echo htmlspecialchars($rec['planned_lot']); ?></div>
                                        <?php if (!$rec['lot_match']): ?>
                                            <span class="badge bg-danger mt-1 tag-mismatch">Lot Mismatch</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 2. Width Comparison Cell -->
                                    <td class="cell-width <?php echo $rec['width_match'] ? 'cell-match' : 'cell-discrepancy'; ?>">
                                        <div><strong>Actual:</strong> <?php echo number_format($rec['actual_width'], 2); ?> mm</div>
                                        <div class="small"><strong>Plan:</strong> <?php echo number_format($rec['planned_width'], 2); ?> mm</div>
                                        <?php if (!$rec['width_match']): ?>
                                            <span class="badge bg-danger mt-1 tag-mismatch">Diff: <?php echo ($rec['width_diff'] > 0 ? '+' : '') . number_format($rec['width_diff'], 2); ?> mm</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 3. Length Comparison Cell -->
                                    <td class="cell-length <?php echo $rec['length_match'] ? 'cell-match' : 'cell-discrepancy'; ?>">
                                        <div><strong>Actual:</strong> <?php echo number_format($rec['actual_length'], 2); ?> m</div>
                                        <div class="small"><strong>Plan:</strong> <?php echo number_format($rec['planned_length'], 2); ?> m</div>
                                        <?php if (!$rec['length_match']): ?>
                                            <span class="badge bg-danger mt-1 tag-mismatch">Diff: <?php echo ($rec['length_diff'] > 0 ? '+' : '') . number_format($rec['length_diff'], 2); ?> m</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Validation Status Column with Interactive Override Button -->
                                    <td class="cell-status">
                                        <?php if ($rec['is_discrepancy']): ?>
                                            <button class="btn-toggle-discrepancy btn-red btn-toggle-verify" data-roll-id="<?php echo $rollId; ?>">
                                                <i class="bi bi-exclamation-triangle-fill me-1"></i> Discrepancy Found (Click to Verify)
                                            </button>
                                        <?php else: ?>
                                            <span class="badge-match">
                                                <i class="bi bi-check-circle-fill me-1"></i> Fully Matched
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Discrepancy Details / Reason -->
                                    <td class="small cell-reason">
                                        <?php if ($rec['is_discrepancy']): ?>
                                            <div class="text-danger fw-bold reason-text"><?php echo htmlspecialchars($rec['reasons']); ?></div>
                                        <?php else: ?>
                                            <span class="text-success"><i class="bi bi-check2"></i> Specification Pass</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="no-print">
                                        <a href="finish_product.php?search=<?php echo urlencode($r['actual_coil_no']); ?>" class="btn btn-sm btn-outline-dark" title="Inspect on Finish Product Page">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- JavaScript for Officer Interactive Discrepancy Verification Toggle -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const toggleButtons = document.querySelectorAll('.btn-toggle-verify');

            // Restore saved officer verifications from localStorage
            toggleButtons.forEach(btn => {
                const rollId = btn.getAttribute('data-roll-id');
                const isVerified = localStorage.getItem('verified_roll_' + rollId) === 'true';

                if (isVerified) {
                    applyVerificationState(btn, rollId, true);
                }

                btn.addEventListener('click', function() {
                    const currentlyVerified = localStorage.getItem('verified_roll_' + rollId) === 'true';
                    const newVerifiedState = !currentlyVerified;

                    localStorage.setItem('verified_roll_' + rollId, newVerifiedState);
                    applyVerificationState(btn, rollId, newVerifiedState);
                    updateKPICounters();
                });
            });

            function applyVerificationState(btn, rollId, isVerified) {
                const row = document.getElementById('roll-row-' + rollId);
                if (!row) return;

                const statusCell = row.querySelector('.cell-status');
                const reasonCell = row.querySelector('.reason-text');
                const cellsToChange = row.querySelectorAll('.cell-discrepancy');

                if (isVerified) {
                    row.classList.remove('bg-soft-red');
                    row.classList.add('bg-soft-green');

                    btn.classList.remove('btn-red');
                    btn.classList.add('btn-verified');
                    btn.innerHTML = '<i class="bi bi-patch-check-fill me-1"></i> Verified (Same as Product)';

                    cellsToChange.forEach(c => {
                        c.classList.remove('cell-discrepancy');
                        c.classList.add('cell-match');
                    });

                    if (reasonCell) {
                        reasonCell.className = 'text-success fw-bold reason-text';
                        reasonCell.innerHTML = '<i class="bi bi-check-all"></i> Verified &amp; Confirmed Same as Product';
                    }
                } else {
                    row.classList.remove('bg-soft-green');
                    row.classList.add('bg-soft-red');

                    btn.classList.remove('btn-verified');
                    btn.classList.add('btn-red');
                    btn.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> Discrepancy Found (Click to Verify)';

                    const mismatchCells = row.querySelectorAll('.tag-mismatch');
                    mismatchCells.forEach(tag => {
                        const td = tag.closest('td');
                        if (td) {
                            td.classList.remove('cell-match');
                            td.classList.add('cell-discrepancy');
                        }
                    });
                }
            }

            function updateKPICounters() {
                const verifiedBtns = document.querySelectorAll('.btn-toggle-verify.btn-verified');
                const verifiedCount = verifiedBtns.length;

                const baseMatched = <?php echo (int)$matchedRolls; ?>;
                const baseDiscrepancy = <?php echo (int)$discrepancyRolls; ?>;
                const totalRolls = <?php echo (int)$totalRolls; ?>;

                const newMatched = baseMatched + verifiedCount;
                const newDiscrepancy = Math.max(0, baseDiscrepancy - verifiedCount);
                const newRate = totalRolls > 0 ? ((newMatched / totalRolls) * 100).toFixed(1) : 100.0;

                const matchedEl = document.getElementById('kpiMatchedRolls');
                const discrepancyEl = document.getElementById('kpiDiscrepancyRolls');
                const rateEl = document.getElementById('kpiComplianceRate');

                if (matchedEl) matchedEl.textContent = newMatched;
                if (discrepancyEl) discrepancyEl.textContent = newDiscrepancy;
                if (rateEl) rateEl.textContent = newRate + '%';
            }
        });
    </script>
</body>
</html>

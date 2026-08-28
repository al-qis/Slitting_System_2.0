<?php
/**
 * stock_crosscheck/date_check.php
 * -----------------------------------------------------------------------
 * Date-Filtered Production Cross-Check Screen (Slitting System 2.0)
 *
 * Allows officers to select any target production date (defaulting to today's date)
 * to instantly cross-check Lot Numbers, Widths, and Lengths against slitting plans.
 * Mismatched parameters are automatically highlighted in bright red on an Excel-like grid.
 * Features interactive discrepancy verification button: Officers can click any discrepancy
 * button to confirm "Verified (Same as Product)", changing the row/cell color to soft green.
 * Includes print/export preview capability.
 * -----------------------------------------------------------------------
 */

require_once dirname(__DIR__) . '/config.php';
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}

// Allowed roles for Officer Date Cross-Check
$role = strtolower($_SESSION['role'] ?? '');
$allowed_roles = ['officer', 'slitting', 'mkl3', 'admin', 'qc'];
if (!in_array($role, $allowed_roles, true)) {
    die("<div style='padding:50px; font-family:sans-serif; text-align:center;'><h2>Access Denied</h2><p>This Date Cross-Check screen is reserved for Officers and authorized personnel only.</p><a href='../index.php'>Return to Dashboard</a></div>");
}

// ── Target Date Selection (defaults to today) ───────────────────
$targetDate = isset($_GET['target_date']) ? trim($_GET['target_date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
    $targetDate = date('Y-m-d');
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = $_GET['status_filter'] ?? 'all'; // 'all', 'discrepancy', 'matched'

// Quick date helper variables
$todayDate = date('Y-m-d');
$yesterdayDate = date('Y-m-d', strtotime('-1 day'));

// ── Query finished products for the selected target date ───────
$sql = "
    SELECT 
        sp.id AS product_id,
        sp.product,
        sp.lot_no AS actual_lot_no,
        sp.coil_no AS actual_coil_no,
        sp.roll_no AS actual_roll_no,
        sp.width AS actual_width,
        COALESCE(sp.actual_length, sp.length, 0.0) AS actual_length,
        sp.status AS production_status,
        sp.date_in,
        sp.updated_at,
        sp.customer_name,
        sp.ref_no,
        mc.id AS mother_id,
        mc.coil_no AS mother_coil_no,
        mc.lot_no AS planned_lot_no,
        mc.width AS mother_width,
        mc.length AS mother_length,
        spl.planned_width AS plan_width,
        spl.customer_name AS plan_customer,
        spl.ref_no AS plan_ref_no
    FROM slitting_product sp
    LEFT JOIN mother_coil mc ON sp.mother_id = mc.id
    LEFT JOIN slitting_plans spl ON (
        (spl.mother_coil_id = mc.id OR spl.mother_coil_id = sp.mother_id)
        AND LOWER(TRIM(spl.roll_seq)) = LOWER(TRIM(sp.roll_no))
    )
    WHERE (sp.is_voided IS NULL OR sp.is_voided = 0)
      AND (DATE(sp.date_in) = ? OR DATE(sp.updated_at) = ?)
";

$types = 'ss';
$params = [$targetDate, $targetDate];

if ($search !== '') {
    $sql .= " AND (
        sp.coil_no LIKE ? OR 
        sp.lot_no LIKE ? OR 
        sp.roll_no LIKE ? OR 
        sp.product LIKE ? OR 
        mc.coil_no LIKE ?
    )";
    $st = '%' . $search . '%';
    $types .= 'sssss';
    $params = array_merge($params, [$st, $st, $st, $st, $st]);
}

$sql .= " ORDER BY sp.id DESC";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$records = [];
$totalRolls = 0;
$matchedRolls = 0;
$discrepancyRolls = 0;
$widthMismatches = 0;
$lengthMismatches = 0;
$lotMismatches = 0;

while ($row = $result->fetch_assoc()) {
    // 1. Lot No Cross-Check (Actual vs Planned)
    $actualLot = trim($row['actual_lot_no'] ?? '');
    $plannedLot = trim($row['planned_lot_no'] ?? '');
    if ($plannedLot === '') { $plannedLot = $actualLot; }
    $lotMatch = (strcasecmp($actualLot, $plannedLot) === 0);

    // 2. Width Cross-Check (Actual vs Planned)
    $actualWidth = (float)($row['actual_width'] ?? 0.0);
    if (isset($row['plan_width']) && $row['plan_width'] !== null && (float)$row['plan_width'] > 0) {
        $plannedWidth = (float)$row['plan_width'];
    } elseif (isset($row['mother_width']) && $row['mother_width'] !== null && (float)$row['mother_width'] > 0) {
        $plannedWidth = (float)$row['mother_width'];
    } else {
        $plannedWidth = $actualWidth;
    }
    $widthDiff = round($actualWidth - $plannedWidth, 2);
    $widthMatch = abs($widthDiff) <= 0.01;

    // 3. Length Cross-Check (Actual vs Planned)
    $actualLength = (float)($row['actual_length'] ?? 0.0);
    $plannedLength = (float)($row['mother_length'] ?? $row['actual_length'] ?? 0.0);
    if ($plannedLength <= 0) { $plannedLength = $actualLength; }
    $lengthDiff = round($actualLength - $plannedLength, 2);
    $lengthMatch = abs($lengthDiff) <= 0.1;

    // Overall Validation
    $isDiscrepancy = (!$lotMatch || !$widthMatch || !$lengthMatch);

    if (!$lotMatch) $lotMismatches++;
    if (!$widthMatch) $widthMismatches++;
    if (!$lengthMatch) $lengthMismatches++;

    // Apply Filter
    if ($statusFilter === 'discrepancy' && !$isDiscrepancy) continue;
    if ($statusFilter === 'matched' && $isDiscrepancy) continue;

    $totalRolls++;
    if ($isDiscrepancy) {
        $discrepancyRolls++;
    } else {
        $matchedRolls++;
    }

    $records[] = [
        'row'            => $row,
        'actual_lot'     => $actualLot,
        'planned_lot'    => $plannedLot,
        'lot_match'      => $lotMatch,
        'actual_width'   => $actualWidth,
        'planned_width'  => $plannedWidth,
        'width_diff'     => $widthDiff,
        'width_match'    => $widthMatch,
        'actual_length'  => $actualLength,
        'planned_length' => $plannedLength,
        'length_diff'    => $lengthDiff,
        'length_match'   => $lengthMatch,
        'is_discrepancy' => $isDiscrepancy,
    ];
}
$stmt->close();

$complianceRate = ($totalRolls > 0) ? round(($matchedRolls / $totalRolls) * 100, 1) : 100.0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Date-Filtered Production Cross-Check - Slitting System 2.0</title>

    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

    <style>
        :root {
            --navy-header: #0f1b2d;
            --soft-green-bg: #dff0d8;
            --soft-green-text: #155724;
            --bright-red-bg: #ffcccc;
            --bright-red-text: #721c24;
            --excel-border: #cbd5e1;
        }

        body {
            background-color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            color: #1e293b;
        }

        .header-bar {
            background: linear-gradient(135deg, #0f1b2d 0%, #1e293b 100%);
            color: #ffffff;
            padding: 18px 0;
            margin-bottom: 22px;
            box-shadow: 0 4px 12px rgba(15, 27, 45, 0.15);
        }

        .kpi-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px 18px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        .kpi-title {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 4px;
        }
        .kpi-value {
            font-size: 1.7rem;
            font-weight: 800;
            color: #0f1b2d;
            line-height: 1;
        }

        /* Filter Controls Card */
        .filter-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 18px 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        /* Excel-Like Data Grid Table */
        .excel-grid-wrap {
            background: #ffffff;
            border: 1px solid var(--excel-border);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
        }

        .excel-table {
            margin-bottom: 0;
            border-collapse: collapse;
            font-size: 0.88rem;
        }

        .excel-table th, .excel-table td {
            border: 1px solid var(--excel-border);
            padding: 10px 12px;
            vertical-align: middle;
            transition: background-color 0.25s ease, color 0.25s ease;
        }

        .excel-table thead th {
            background-color: #0f1b2d;
            color: #ffffff;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }

        /* Highlighting Rules */
        .row-discrepancy {
            background-color: var(--bright-red-bg) !important;
            color: var(--bright-red-text) !important;
        }

        .row-match {
            background-color: var(--soft-green-bg) !important;
            color: var(--soft-green-text) !important;
        }

        .cell-match {
            background-color: rgba(223, 240, 216, 0.7) !important;
            color: #155724 !important;
        }

        .cell-discrepancy {
            background-color: var(--bright-red-bg) !important;
            color: var(--bright-red-text) !important;
            font-weight: 700;
            border-left: 3px solid #dc3545 !important;
        }

        /* Interactive Discrepancy Verification Button */
        .btn-toggle-discrepancy {
            border: none;
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 18px;
            font-size: 0.8rem;
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
            padding: 5px 10px;
            border-radius: 16px;
            font-size: 0.8rem;
        }

        @media print {
            .header-bar, .filter-card, .no-print { display: none !important; }
            body { background: #ffffff; }
            .excel-grid-wrap { border: none; box-shadow: none; }
        }
    </style>
</head>
<body>

    <!-- Header Navigation -->
    <header class="header-bar">
        <div class="container-fluid px-4">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <h1 class="m-0 fw-bold fs-4 d-flex align-items-center gap-2">
                        <i class="bi bi-calendar2-check-fill text-primary"></i>
                        Date-Filtered Production Cross-Check
                    </h1>
                    <div class="small text-slate-300 mt-1" style="color: #94a3b8;">
                        Excel-Like On-Screen Cross-Check for Specified Production Date
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 no-print">
                    <button onclick="window.print()" class="btn btn-warning btn-sm fw-semibold">
                        <i class="bi bi-printer-fill me-1"></i> Print / Export View
                    </button>
                    <a href="index.php" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-grid me-1"></i> Live Dashboard
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container-fluid px-4 pb-5">

        <!-- KPI Metrics Summary -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="kpi-title">Target Date</div>
                    <div class="kpi-value text-primary fs-3"><?php echo htmlspecialchars($targetDate); ?></div>
                    <small class="text-muted">Selected Production Date</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="kpi-title">Total Rolls</div>
                    <div class="kpi-value"><?php echo $totalRolls; ?></div>
                    <small class="text-muted">Produced / Logged on date</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card" style="border-left: 4px solid #198754;">
                    <div class="kpi-title text-success">Fully Matched / Verified</div>
                    <div class="kpi-value text-success" id="kpiMatchedRolls"><?php echo $matchedRolls; ?></div>
                    <small class="text-muted">Lot, Width &amp; Length Pass</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card" style="border-left: 4px solid #dc3545;">
                    <div class="kpi-title text-danger">Discrepancies</div>
                    <div class="kpi-value text-danger" id="kpiDiscrepancyRolls"><?php echo $discrepancyRolls; ?></div>
                    <small class="text-danger fw-semibold">
                        <?php echo "Lot: {$lotMismatches} | Width: {$widthMismatches} | Length: {$lengthMismatches}"; ?>
                    </small>
                </div>
            </div>
        </div>

        <!-- Date Filter Control Form -->
        <div class="filter-card no-print">
            <form method="GET" action="date_check.php" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted">
                        <i class="bi bi-calendar-event me-1"></i> Target Production Date
                    </label>
                    <input type="date" name="target_date" class="form-control fw-bold" value="<?php echo htmlspecialchars($targetDate); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted">Status Filter</label>
                    <select name="status_filter" class="form-select">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Rolls</option>
                        <option value="discrepancy" <?php echo $statusFilter === 'discrepancy' ? 'selected' : ''; ?>>Discrepancies Only (Red)</option>
                        <option value="matched" <?php echo $statusFilter === 'matched' ? 'selected' : ''; ?>>Matched Only (Green)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted">Search Keyword</label>
                    <input type="text" name="search" class="form-control" placeholder="Coil No, Lot No, Product..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary fw-semibold flex-fill">
                        <i class="bi bi-search me-1"></i> Cross-Check
                    </button>
                    <a href="date_check.php?target_date=<?php echo $todayDate; ?>" class="btn btn-outline-dark" title="Select Today">
                        Today
                    </a>
                    <a href="date_check.php?target_date=<?php echo $yesterdayDate; ?>" class="btn btn-outline-secondary" title="Select Yesterday">
                        Yesterday
                    </a>
                </div>
            </form>
        </div>

        <!-- Excel-Like Data Grid Table -->
        <div class="excel-grid-wrap">
            <div class="table-responsive">
                <table class="table excel-table">
                    <thead>
                        <tr>
                            <th class="text-center">#</th>
                            <th>Mother Coil No</th>
                            <th>Roll No</th>
                            <th>Product Code</th>
                            <th>Lot Number (Actual vs Plan)</th>
                            <th>Width (Actual vs Plan)</th>
                            <th>Length (Actual vs Plan)</th>
                            <th class="text-center">Cross-Check Status (Click to Verify)</th>
                            <th>Date / Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <i class="bi bi-calendar-x fs-1 d-block mb-2 text-secondary"></i>
                                    No production records found for date: <strong><?php echo htmlspecialchars($targetDate); ?></strong>.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($records as $idx => $rec): 
                                $r = $rec['row'];
                                $rollId = (int)$r['product_id'];
                                $rowClass = $rec['is_discrepancy'] ? 'row-discrepancy' : 'row-match';
                            ?>
                                <tr class="<?php echo $rowClass; ?>" id="date-roll-row-<?php echo $rollId; ?>">
                                    <td class="text-center fw-bold"><?php echo $idx + 1; ?></td>
                                    <td>
                                        <strong class="d-block"><?php echo htmlspecialchars($r['mother_coil_no'] ?? $r['actual_coil_no']); ?></strong>
                                        <span class="small text-muted"><?php echo htmlspecialchars($r['actual_coil_no']); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-dark"><?php echo htmlspecialchars($r['actual_roll_no']); ?></span>
                                    </td>
                                    <td>
                                        <code><?php echo htmlspecialchars($r['product']); ?></code>
                                    </td>

                                    <!-- 1. Lot Number Cross-Check Cell -->
                                    <td class="cell-lot <?php echo $rec['lot_match'] ? 'cell-match' : 'cell-discrepancy'; ?>">
                                        <div><strong>Act:</strong> <?php echo htmlspecialchars($rec['actual_lot']); ?></div>
                                        <div class="small"><strong>Plan:</strong> <?php echo htmlspecialchars($rec['planned_lot']); ?></div>
                                        <?php if (!$rec['lot_match']): ?>
                                            <span class="badge bg-danger mt-1 tag-mismatch">Lot Mismatch</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 2. Width Cross-Check Cell -->
                                    <td class="cell-width <?php echo $rec['width_match'] ? 'cell-match' : 'cell-discrepancy'; ?>">
                                        <div><strong>Act:</strong> <?php echo number_format($rec['actual_width'], 2); ?> mm</div>
                                        <div class="small"><strong>Plan:</strong> <?php echo number_format($rec['planned_width'], 2); ?> mm</div>
                                        <?php if (!$rec['width_match']): ?>
                                            <span class="badge bg-danger mt-1 tag-mismatch">Diff: <?php echo ($rec['width_diff'] > 0 ? '+' : '') . number_format($rec['width_diff'], 2); ?> mm</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 3. Length Cross-Check Cell -->
                                    <td class="cell-length <?php echo $rec['length_match'] ? 'cell-match' : 'cell-discrepancy'; ?>">
                                        <div><strong>Act:</strong> <?php echo number_format($rec['actual_length'], 2); ?> m</div>
                                        <div class="small"><strong>Plan:</strong> <?php echo number_format($rec['planned_length'], 2); ?> m</div>
                                        <?php if (!$rec['length_match']): ?>
                                            <span class="badge bg-danger mt-1 tag-mismatch">Diff: <?php echo ($rec['length_diff'] > 0 ? '+' : '') . number_format($rec['length_diff'], 2); ?> m</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Status Column with Interactive Discrepancy Verification Button -->
                                    <td class="text-center cell-status">
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

                                    <td class="small text-muted">
                                        <?php echo htmlspecialchars($r['date_in'] ?? $r['updated_at']); ?>
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
                const row = document.getElementById('date-roll-row-' + rollId);
                if (!row) return;

                const cellsToChange = row.querySelectorAll('.cell-discrepancy');

                if (isVerified) {
                    row.classList.remove('row-discrepancy');
                    row.classList.add('row-match');

                    btn.classList.remove('btn-red');
                    btn.classList.add('btn-verified');
                    btn.innerHTML = '<i class="bi bi-patch-check-fill me-1"></i> Verified (Same as Product)';

                    cellsToChange.forEach(c => {
                        c.classList.remove('cell-discrepancy');
                        c.classList.add('cell-match');
                    });
                } else {
                    row.classList.remove('row-match');
                    row.classList.add('row-discrepancy');

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

                const newMatched = baseMatched + verifiedCount;
                const newDiscrepancy = Math.max(0, baseDiscrepancy - verifiedCount);

                const matchedEl = document.getElementById('kpiMatchedRolls');
                const discrepancyEl = document.getElementById('kpiDiscrepancyRolls');

                if (matchedEl) matchedEl.textContent = newMatched;
                if (discrepancyEl) discrepancyEl.textContent = newDiscrepancy;
            }
        });
    </script>
</body>
</html>

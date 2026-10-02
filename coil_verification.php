<?php
/**
 * Side-by-Side Coil Verification Table (3-Way Reconciliation System)
 * Frontend Standalone Page (PHP, Bootstrap 5, JavaScript)
 * Location: /slitting_system/coil_verification.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure core logic is loaded
require_once __DIR__ . '/reconcile_side_by_side.php';

// Prepare Summary Metrics
$totalRows = count($reconciledResults);
$actualScannedCount = count($scannedRows ?? []);
$trueCount = 0;
$falseCount = 0;
$totalNodVariance = 0.0;
$d365Count = count($d365Map ?? []);
$coilNodCount = 0;

foreach ($reconciledResults as $row) {
    if ($row['has_discrepancy']) {
        $falseCount++;
    } else {
        $trueCount++;
    }
    $totalNodVariance += $row['nod'];
    if ((float)$row['nod'] > 0) {
        $coilNodCount++;
    }
}

$uploadedFileName = $_SESSION['last_d365_filename'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Side-by-Side Coil Verification | 3-Way Reconciliation</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --bs-body-font-family: 'Inter', system-ui, -apple-system, sans-serif;
            --section-store-bg: #1e293b;
            --section-d365-bg: #0f766e;
            --section-eval-bg: #5b21b6;
        }

        body {
            background-color: #f8fafc;
            color: #334155;
            font-size: 0.9rem;
        }

        .navbar-brand {
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .card-custom {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            background: #ffffff;
        }

        .kpi-card {
            border: none;
            border-radius: 12px;
            padding: 1.25rem;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
        }

        .kpi-total { background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); color: #1e40af; }
        .kpi-true { background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); color: #166534; }
        .kpi-false { background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%); color: #991b1b; }
        .kpi-nod { background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); color: #92400e; }

        /* Super Header Styling */
        .super-header-store {
            background-color: var(--section-store-bg) !important;
            color: #ffffff !important;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            border-right: 2px solid #ffffff;
        }
        .super-header-d365 {
            background-color: var(--section-d365-bg) !important;
            color: #ffffff !important;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            border-right: 2px solid #ffffff;
        }
        .super-header-eval {
            background-color: var(--section-eval-bg) !important;
            color: #ffffff !important;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }

        .sub-header {
            background-color: #f1f5f9 !important;
            color: #475569 !important;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            border-bottom: 2px solid #cbd5e1 !important;
        }

        /* Side-by-Side Table */
        .reconcile-table {
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
        }
        .reconcile-table th, .reconcile-table td {
            padding: 0.65rem 0.85rem;
            vertical-align: middle;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.85rem;
        }

        /* Column Separator Lines */
        .col-sep {
            border-right: 2px solid #cbd5e1 !important;
        }

        /* Conditional Formatting Rules (Core Requirement 2) */
        .cell-true {
            background-color: #f0fdf4;
            color: #15803d;
            font-weight: 600;
        }
        
        /* Soft red background (#f8d7da) and bold red text (#721c24) for FALSE */
        .cell-false {
            background-color: #f8d7da !important;
            color: #721c24 !important;
            font-weight: 700 !important;
        }

        /* Soft yellow background (#fff3cd) for NOD > 0 */
        .cell-nod-diff {
            background-color: #fff3cd !important;
            color: #856404 !important;
            font-weight: 700 !important;
        }

        /* Row Discrepancy Highlight */
        tr.row-discrepancy {
            border-left: 4px solid #ef4444;
        }

        .badge-true {
            background-color: #d1e7dd;
            color: #0f5132;
            font-weight: 700;
            padding: 0.35em 0.65em;
            border-radius: 6px;
        }
        .badge-false {
            background-color: #f8d7da;
            color: #721c24;
            font-weight: 800;
            padding: 0.35em 0.65em;
            border-radius: 6px;
        }

        .btn-toggle-custom input[type="radio"] {
            display: none;
        }
        .btn-toggle-custom label {
            cursor: pointer;
            padding: 0.4rem 0.9rem;
            font-size: 0.85rem;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        .btn-toggle-custom input[type="radio"]:checked + label {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        #btnShowAll:checked + label { background-color: #3b82f6; color: white; }
        #btnShowFalse:checked + label { background-color: #ef4444; color: white; }
        #btnShowTrue:checked + label { background-color: #10b981; color: white; }

        .search-box-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }
        .search-input-padding {
            padding-left: 36px;
        }
    </style>
</head>
<body>

    <!-- Header Navbar -->
    <nav class="navbar navbar-dark bg-dark shadow-sm py-2">
        <div class="container-fluid px-4">
            <a class="navbar-brand d-flex align-items-center gap-2" href="#">
                <i class="bi bi-columns-gap text-warning fs-4"></i>
                <span>Slitting System 2.0 &mdash; Side-by-Side Coil Verification</span>
            </a>
            <div class="d-flex align-items-center gap-3">
                <span class="badge bg-secondary px-3 py-2">
                    <i class="bi bi-layers-half me-1"></i> 3-Way Reconciliation
                </span>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4 py-4">

        <!-- Page Header & Title -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div>
                <h3 class="fw-bold mb-1 text-dark">Side-by-Side Coil Verification Table</h3>
                <p class="text-muted mb-0">Automated 3-Way Reconciliation: Physical Scanned Store vs D365 System Export vs Discrepancy Evaluation</p>
            </div>
            
            <div class="d-flex align-items-center gap-2">
                <!-- Download Sample Template Button -->
                <a href="reconcile_side_by_side.php?action=download_template" class="btn btn-outline-secondary fw-semibold shadow-sm d-flex align-items-center gap-2">
                    <i class="bi bi-download"></i>
                    <span>Download Sample D365 Template</span>
                </a>

                <!-- Export to Excel Button (Core Requirement 4) -->
                <form action="reconcile_side_by_side.php" method="POST" class="m-0">
                    <input type="hidden" name="month" value="<?= $selectedMonth ?>">
                    <input type="hidden" name="year" value="<?= $selectedYear ?>">
                    <input type="hidden" name="action" value="export">
                    <button type="submit" class="btn btn-success fw-bold shadow-sm d-flex align-items-center gap-2">
                        <i class="bi bi-file-earmark-excel-fill fs-5"></i>
                        <span>Export to Excel</span>
                    </button>
                </form>
            </div>
        </div>

        <!-- Controls Card: File Upload & Date Selection -->
        <div class="card card-custom mb-4">
            <div class="card-body p-4">
                <form action="reconcile_side_by_side.php" method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
                    
                    <!-- Month Selector -->
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label fw-bold text-secondary mb-1">
                            <i class="bi bi-calendar3 me-1"></i> Select Month (Ground Truth)
                        </label>
                        <select name="month" class="form-select fw-semibold">
                            <option value="0" <?= $selectedMonth === 0 ? 'selected' : '' ?>>All Months (All Active Scans)</option>
                            <?php foreach ($monthNames as $mNum => $mName): ?>
                                <option value="<?= $mNum ?>" <?= $selectedMonth === $mNum ? 'selected' : '' ?>>
                                    <?= $mName ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Year Selector -->
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label fw-bold text-secondary mb-1">
                            <i class="bi bi-calendar-event me-1"></i> Year
                        </label>
                        <select name="year" class="form-select fw-semibold">
                            <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                                <option value="<?= $y ?>" <?= $selectedYear === $y ? 'selected' : '' ?>>
                                    <?= $y ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <!-- File Upload Input (Core Requirement 3) -->
                    <div class="col-md-5">
                        <label class="form-label fw-bold text-secondary mb-1">
                            <i class="bi bi-file-earmark-spreadsheet me-1"></i> Upload D365 System Export (.xlsx, .csv)
                        </label>
                        <div class="input-group">
                            <input type="file" name="d365_file" class="form-form-control form-control" accept=".xlsx, .xls, .csv">
                            <?php if (!empty($uploadedFileName)): ?>
                                <span class="input-group-text bg-light text-success fw-semibold" title="Uploaded File">
                                    <i class="bi bi-check-circle-fill me-1"></i> <?= htmlspecialchars($uploadedFileName) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Submit / Reconcile Button -->
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100 fw-bold shadow-sm d-flex align-items-center justify-content-center gap-2">
                            <i class="bi bi-arrow-repeat fs-5"></i>
                            <span>Reconcile Data</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- KPI Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-xl-2 col-md-4 col-6">
                <div class="kpi-card kpi-total card-custom">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-uppercase fw-bold opacity-75 small">Actual Coil Scanned</div>
                            <h2 class="fw-bold mb-0 mt-1"><?= number_format($actualScannedCount) ?></h2>
                        </div>
                        <div class="fs-1 opacity-50"><i class="bi bi-upc-scan"></i></div>
                    </div>
                </div>
            </div>

            <div class="col-xl-2 col-md-4 col-6">
                <div class="kpi-card card-custom">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-uppercase fw-bold opacity-75 small">Bilangan D365</div>
                            <h2 class="fw-bold mb-0 mt-1"><?= number_format($d365Count) ?></h2>
                        </div>
                        <div class="fs-1 opacity-50"><i class="bi bi-file-earmark-spreadsheet"></i></div>
                    </div>
                </div>
            </div>

            <div class="col-xl-2 col-md-4 col-6">
                <div class="kpi-card kpi-true card-custom">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-uppercase fw-bold opacity-75 small">Fully Matched (TRUE)</div>
                            <h2 class="fw-bold mb-0 mt-1"><?= number_format($trueCount) ?></h2>
                        </div>
                        <div class="fs-1 opacity-50"><i class="bi bi-check-circle-fill"></i></div>
                    </div>
                </div>
            </div>

            <div class="col-xl-2 col-md-4 col-6">
                <div class="kpi-card kpi-false card-custom">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-uppercase fw-bold opacity-75 small">Discrepancies (FALSE)</div>
                            <h2 class="fw-bold mb-0 mt-1" id="kpiFalseCount"><?= number_format($falseCount) ?></h2>
                        </div>
                        <div class="fs-1 opacity-50"><i class="bi bi-exclamation-triangle-fill"></i></div>
                    </div>
                </div>
            </div>

            <div class="col-xl-2 col-md-4 col-6">
                <div class="kpi-card kpi-nod card-custom">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-uppercase fw-bold opacity-75 small">Bilangan Coil NOD</div>
                            <h2 class="fw-bold mb-0 mt-1"><?= number_format($coilNodCount) ?></h2>
                        </div>
                        <div class="fs-1 opacity-50"><i class="bi bi-exclamation-diamond-fill"></i></div>
                    </div>
                </div>
            </div>

            <div class="col-xl-2 col-md-4 col-6">
                <div class="kpi-card kpi-nod card-custom">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-uppercase fw-bold opacity-75 small">Total NOD Variance</div>
                            <h2 class="fw-bold mb-0 mt-1"><?= number_format($totalNodVariance, 2) ?> <span class="fs-6 font-normal">MTR</span></h2>
                        </div>
                        <div class="fs-1 opacity-50"><i class="bi bi-rulers"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Interactive Filters & Live Search Panel (Core Requirement 3) -->
        <div class="card card-custom mb-3">
            <div class="card-body py-3 px-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    
                    <!-- Radio Button Toggles for Discrepancies / Filter -->
                    <div class="d-flex align-items-center gap-2">
                        <span class="fw-bold text-muted small me-2"><i class="bi bi-filter-circle me-1"></i> Filter Status:</span>
                        <div class="bg-light p-1 rounded-3 border d-flex gap-1 btn-toggle-custom">
                            <input type="radio" name="statusFilter" id="btnShowAll" value="all" checked>
                            <label for="btnShowAll">Show All (<?= $totalRows ?>)</label>

                            <input type="radio" name="statusFilter" id="btnShowFalse" value="false">
                            <label for="btnShowFalse" class="text-danger">
                                <i class="bi bi-exclamation-circle-fill me-1"></i> Only FALSE / Discrepancies (<?= $falseCount ?>)
                            </label>

                            <input type="radio" name="statusFilter" id="btnShowTrue" value="true">
                            <label for="btnShowTrue" class="text-success">
                                <i class="bi bi-check-circle-fill me-1"></i> Only TRUE (<?= $trueCount ?>)
                            </label>
                        </div>
                    </div>

                    <!-- Real-Time Search Box Input (Core Requirement 3) -->
                    <div class="position-relative style-search flex-grow-1" style="max-width: 380px;">
                        <i class="bi bi-search search-box-icon"></i>
                        <input type="text" id="tableSearchInput" class="form-control search-input-padding" placeholder="Search Item Number or Lot Number...">
                    </div>

                </div>
            </div>
        </div>

        <!-- Main 3-Way Reconciliation Side-by-Side Table -->
        <div class="card card-custom overflow-hidden mb-5">
            <div class="table-responsive">
                <table class="table table-hover align-middle reconcile-table mb-0" id="sideBySideTable">
                    <thead>
                        <!-- Super Header (Section Groupings) -->
                        <tr>
                            <th class="text-center bg-secondary text-white border-bottom-0" style="width: 40px;">#</th>
                            
                            <!-- Table 1: Scanned Physical Store (Ground Truth) -->
                            <th colspan="3" class="text-center super-header-store py-2">
                                <i class="bi bi-upc-scan me-1"></i> 1. Scanned Physical Store (Ground Truth)
                            </th>

                            <!-- Table 2: D365 System Export (ERP Reference) -->
                            <th colspan="3" class="text-center super-header-d365 py-2">
                                <i class="bi bi-database-check me-1"></i> 2. D365 System Export (ERP Reference)
                            </th>

                            <!-- Table 3: Verification & Discrepancy Status (Auto Evaluation) -->
                            <th colspan="4" class="text-center super-header-eval py-2">
                                <i class="bi bi-shield-check me-1"></i> 3. Verification & Discrepancy Status (Auto Evaluation)
                            </th>
                        </tr>

                        <!-- Sub Column Headers -->
                        <tr>
                            <th class="text-center sub-header border-end">#</th>
                            
                            <!-- Table 1 Columns -->
                            <th class="sub-header">D365 ITEM NUMBER</th>
                            <th class="sub-header">D365 LOT NO</th>
                            <th class="sub-header text-end col-sep">MTR</th>

                            <!-- Table 2 Columns -->
                            <th class="sub-header">D365 ITEM NUMBER</th>
                            <th class="sub-header">D365 LOT NO</th>
                            <th class="sub-header text-end col-sep">MTR</th>

                            <!-- Table 3 Columns -->
                            <th class="sub-header text-center">STATUS ITEM NUMBER</th>
                            <th class="sub-header text-center">STATUS LOT NO</th>
                            <th class="sub-header text-center">STATUS MTR</th>
                            <th class="sub-header text-end">NOD (Variance)</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php if (empty($reconciledResults)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                                    <span class="fw-bold">No coil data found for the selected period.</span>
                                    <p class="small mb-0 mt-1">Please select another month or upload a D365 System Export spreadsheet.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php 
                            $rowNum = 1;
                            foreach ($reconciledResults as $row): 
                                $isDiscrepancy = $row['has_discrepancy'];
                                $rowClass = $isDiscrepancy ? 'row-discrepancy' : '';
                            ?>
                                <tr class="reconcile-row <?= $rowClass ?>" 
                                    data-discrepancy="<?= $isDiscrepancy ? 'true' : 'false' ?>"
                                    data-item="<?= htmlspecialchars(strtolower($row['scanned_item'] . ' ' . $row['d365_item'])) ?>"
                                    data-lot="<?= htmlspecialchars(strtolower($row['scanned_lot'] . ' ' . $row['d365_lot'])) ?>">

                                    <!-- Index -->
                                    <td class="text-center text-muted fw-bold border-end"><?= $rowNum++ ?></td>

                                    <!-- Table 1: Scanned Physical Store -->
                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($row['scanned_item']) ?></td>
                                    <td><code class="text-dark bg-light px-2 py-1 rounded"><?= htmlspecialchars($row['scanned_lot']) ?></code></td>
                                    <td class="text-end fw-bold text-primary col-sep"><?= number_format($row['scanned_mtr'], 2) ?></td>

                                    <!-- Table 2: D365 System Export -->
                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($row['d365_item']) ?></td>
                                    <td><code class="text-dark bg-light px-2 py-1 rounded"><?= htmlspecialchars($row['d365_lot']) ?></code></td>
                                    <td class="text-end fw-bold text-success col-sep"><?= number_format($row['d365_mtr'], 2) ?></td>

                                    <!-- Table 3: Verification & Status (Core Requirement 2 Highlighting) -->
                                    
                                    <!-- STATUS ITEM NUMBER -->
                                    <td class="text-center <?= $row['status_item'] ? 'cell-true' : 'cell-false' ?>">
                                        <?php if ($row['status_item']): ?>
                                            <span class="badge-true"><i class="bi bi-check-circle-fill me-1"></i>TRUE</span>
                                        <?php else: ?>
                                            <span class="badge-false"><i class="bi bi-x-circle-fill me-1"></i>FALSE</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- STATUS LOT NO -->
                                    <td class="text-center <?= $row['status_lot'] ? 'cell-true' : 'cell-false' ?>">
                                        <?php if ($row['status_lot']): ?>
                                            <span class="badge-true"><i class="bi bi-check-circle-fill me-1"></i>TRUE</span>
                                        <?php else: ?>
                                            <span class="badge-false"><i class="bi bi-x-circle-fill me-1"></i>FALSE</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- STATUS MTR -->
                                    <td class="text-center <?= $row['status_mtr'] ? 'cell-true' : 'cell-false' ?>">
                                        <?php if ($row['status_mtr']): ?>
                                            <span class="badge-true"><i class="bi bi-check-circle-fill me-1"></i>TRUE</span>
                                        <?php else: ?>
                                            <span class="badge-false"><i class="bi bi-x-circle-fill me-1"></i>FALSE</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- NOD (Number of Difference) -->
                                    <td class="text-end <?= $row['nod'] > 0 ? 'cell-nod-diff' : 'text-muted' ?>">
                                        <?php if ($row['nod'] > 0): ?>
                                            <span class="badge bg-warning text-dark border border-warning px-2 py-1">
                                                <i class="bi bi-exclamation-circle-fill me-1"></i><?= number_format($row['nod'], 2) ?>
                                            </span>
                                        <?php else: ?>
                                            0.00
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Client-Side Filter & Real-time Search Script (Core Requirement 3) -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const tableRows = document.querySelectorAll('#tableBody tr.reconcile-row');
            const searchInput = document.getElementById('tableSearchInput');
            const radioFilters = document.querySelectorAll('input[name="statusFilter"]');

            function filterRows() {
                const searchTerm = searchInput.value.toLowerCase().trim();
                const selectedFilter = document.querySelector('input[name="statusFilter"]:checked').value;

                tableRows.forEach(row => {
                    const isDiscrepancy = row.getAttribute('data-discrepancy') === 'true';
                    const itemData = row.getAttribute('data-item') || '';
                    const lotData = row.getAttribute('data-lot') || '';

                    // Match Status Radio Filter
                    let matchesStatus = true;
                    if (selectedFilter === 'false') {
                        matchesStatus = isDiscrepancy;
                    } else if (selectedFilter === 'true') {
                        matchesStatus = !isDiscrepancy;
                    }

                    // Match Search Query
                    let matchesSearch = true;
                    if (searchTerm !== '') {
                        matchesSearch = itemData.includes(searchTerm) || lotData.includes(searchTerm);
                    }

                    if (matchesStatus && matchesSearch) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            }

            // Bind Event Listeners
            searchInput.addEventListener('keyup', filterRows);
            radioFilters.forEach(radio => radio.addEventListener('change', filterRows));
        });
    </script>
</body>
</html>

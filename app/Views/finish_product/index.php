<?php
if (!function_exists('originLabel')) {
    function originLabel(string $origin): string {
        return \App\Models\FinishedProduct::originLabel($origin);
    }
}
if (!function_exists('cardUrl')) {
    function cardUrl(string $filterVal, int $month, int $year, string $search): string {
        return \App\Models\FinishedProduct::cardUrl($filterVal, $month, $year, $search);
    }
}
$page_title = 'Finish Product';
include dirname(__DIR__, 3) . '/header.php';
?>
<style>
table { table-layout: fixed; width: 100%; }
table th, table td { word-wrap: break-word; vertical-align: middle; font-size: 13px; }
table td img { max-width: 60px; max-height: 60px; display: block; margin: 0 auto; }
table th:nth-child(1)  { width: 32px; }   /* checkbox (Mixed Bulk Print select) */
table th:nth-child(2)  { width: 40px; }   /* # counter */
table th:nth-child(3)  { width: 100px; }  /* Status */
table th:nth-child(4)  { width: 55px; }   /* Origin */
table th:nth-child(5)  { width: 90px; }   /* Product */
table th:nth-child(6)  { width: 150px; }  /* Lot No (merged with Roll No) */
table th:nth-child(7)  { width: 55px; }   /* Width */
table th:nth-child(8)  { width: 55px; }   /* Length */
table th:nth-child(9)  { width: 65px; }   /* Actual */
table th:nth-child(10) { width: 70px; }   /* NOD */
table th:nth-child(11) { width: 90px; }   /* Pallet */
table th:nth-child(12) { width: 85px; }   /* Date In */
table th:nth-child(13) { width: 85px; }   /* Date Out */
table th:nth-child(14) { width: 140px; }  /* Action */

/* ── NOD (Notice of Defect) row highlight ──
   !important so it wins over whatever status color (table-primary,
   table-success, etc.) is already applied — a defective roll should
   always stand out regardless of its current status. */
tr.row-has-nod > * { background-color: #fff3cd !important; }
.nod-value { color: #92400e; font-weight: 700; }
.nod-badge { font-size: 9px; }

.badge-pallet { background:#e0f2fe; color:#0369a1; font-size:10px; font-weight:700;
                padding:3px 7px; border-radius:10px; white-space:nowrap; }

/* ── Origin column: badge isolation & spacing ── */
table td {
    padding-left: 8px;
    padding-right: 8px;
}
table td .origin-cell {
    display: flex;
    align-items: center;
    justify-content: center;
}
table td .origin-cell .badge {
    font-size: 10px;
    font-weight: 700;
    padding: 3px 6px;
    border-radius: 8px;
    white-space: nowrap;
    line-height: 1.3;
}
/* Generic safeguard: stop any badge from touching neighbouring text */
.badge {
    margin-right: 6px;
    white-space: nowrap;
}
.badge:last-child {
    margin-right: 0;
}
table td.lot-coil-cell {
    padding-left: 10px;
}

/* ── KPI card filter styles ── */
.kpi-card-link {
    text-decoration: none;
    transition: transform .15s, box-shadow .15s;
    display: block;
}
.kpi-card-link:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 18px rgba(0,0,0,.15);
    text-decoration: none;
}
.kpi-card-link .card {
    border: 2px solid transparent;
    transition: border-color .15s;
    cursor: pointer;
}
.kpi-card-link.active-kpi {
    transform: translateY(-3px);
}
.kpi-card-link.active-kpi .card {
    border-color: rgba(0,0,0,.35) !important;
    box-shadow: 0 0 0 3px rgba(255,255,255,.6), 0 6px 18px rgba(0,0,0,.2);
}
.kpi-active-dot {
    display: block;
    font-size: 10px;
    opacity: .85;
    margin-top: 2px;
}
.kpi-card-palletised-active .card {
    border-color: #0369a1 !important;
    box-shadow: 0 0 0 3px rgba(3,105,161,.2), 0 6px 18px rgba(0,0,0,.12) !important;
}

/* ── Row counter cell ── */
.row-counter {
    font-size: 11px;
    color: #6c757d;
    font-weight: 600;
    text-align: center;
}
</style>

<h2 class="mb-4"><i class="bi bi-check-circle me-2"></i>Finish Product</h2>

<form id="scanFormProduct" method="post" action="scan_product_action.php" autocomplete="off">
    <input id="qrInputProduct" type="hidden" name="qr" value="">
</form>

<!-- Alerts -->
<?php if (isset($_GET['error'])): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <strong>Error:</strong> <?= htmlspecialchars(urldecode($_GET['msg'] ?? $_GET['error'])) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (isset($_GET['success'])): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i>
    <?php
    $successMessages = [
        'recoiling'   => 'Roll sent to Recoiling successfully.',
        'reslit'      => 'Roll sent to Reslit successfully.',
        'sfc'         => 'Roll sent to SFC Inventory successfully.',
        'stock'       => 'Actual length saved. Roll is now in Finish Good stock.',
        'palletised'  => 'Roll added to pallet successfully.',
        'sfc_sold'    => 'SFC roll sent to Finish Product (Status: IN).',
    ];
    echo htmlspecialchars($successMessages[$_GET['success']] ?? 'Action completed successfully.');
    ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Row 1: Month / Year / Day / Origin — compact controls only -->
<div class="row mb-2 g-2 align-items-center">
    <div class="col-auto">
        <form method="get" class="d-flex gap-2 align-items-center flex-wrap">
            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
            <?php if ($filter_card !== ''): ?>
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter_card) ?>">
            <?php endif; ?>
            <?php if ($filter_nod !== ''): ?>
            <input type="hidden" name="nod" value="<?= htmlspecialchars($filter_nod) ?>">
            <?php endif; ?>
            <label class="small fw-bold">Month:</label>
            <select name="month" onchange="this.form.submit()" class="form-select form-select-sm w-auto">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= ($m == $month) ? 'selected' : '' ?>>
                        <?= date("F", mktime(0,0,0,$m,1)) ?>
                    </option>
                <?php endfor; ?>
            </select>
            <label class="small fw-bold">Year:</label>
            <select name="year" onchange="this.form.submit()" class="form-select form-select-sm w-auto">
                <?php for ($y = 2024; $y <= 2030; $y++): ?>
                    <option value="<?= $y ?>" <?= ($y == $year) ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <label class="small fw-bold">Day:</label>
            <select name="day" onchange="this.form.submit()" class="form-select form-select-sm w-auto">
                <option value="0" <?= ($day === 0) ? 'selected' : '' ?>>All Days</option>
                <?php for ($d = 1; $d <= $daysInSelectedMonth; $d++): ?>
                    <option value="<?= $d ?>" <?= ($d === $day) ? 'selected' : '' ?>><?= $d ?></option>
                <?php endfor; ?>
            </select>
            <label class="small fw-bold">Origin:</label>
            <select name="origin" onchange="this.form.submit()" class="form-select form-select-sm w-auto">
                <option value="">All Origins</option>
                <?php foreach ($originOptions as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>" <?= ($opt === $filter_origin) ? 'selected' : '' ?>>
                        <?= htmlspecialchars(originLabel($opt)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<!-- Row 2: ONE unified search bar — replaces the old separate global
     search / Width filter / dedicated Lot No + Coil No dual-input.
     Handles broad single-attribute search (ID, Product, Pallet No,
     Width...), exact Lot+Coil+Roll lookup, AND auto-triggers Batch
     Setup & Print when exactly a Lot+Coil pairing is entered (see the
     server-side redirect check near the top of this file). -->
<div class="row mb-3 g-2 align-items-center">
    <div class="col-md-8">
        <form method="get" class="input-group input-group-sm">
            <input type="hidden" name="month" value="<?= $month ?>">
            <input type="hidden" name="year"  value="<?= $year ?>">
            <input type="hidden" name="day" value="<?= $day ?>">
            <?php if ($filter_card !== ''): ?>
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter_card) ?>">
            <?php endif; ?>
            <?php if ($filter_origin !== ''): ?>
            <input type="hidden" name="origin" value="<?= htmlspecialchars($filter_origin) ?>">
            <?php endif; ?>
            <?php if ($filter_nod !== ''): ?>
            <input type="hidden" name="nod" value="<?= htmlspecialchars($filter_nod) ?>">
            <?php endif; ?>
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" name="search" class="form-control"
                   placeholder="Search ID, Product, Width, Pallet No, or type Lot + Coil (+ Roll)..."
                   value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-primary" type="submit">Search</button>
            <?php if ($search !== ''): ?>
                <a href="?month=<?= $month ?>&year=<?= $year ?>&day=<?= $day ?><?= $filter_card ? '&filter='.urlencode($filter_card) : '' ?><?= $filter_origin !== '' ? '&origin='.urlencode($filter_origin) : '' ?><?= $filter_nod !== '' ? '&nod='.urlencode($filter_nod) : '' ?>" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="mb-3 d-flex gap-2 flex-wrap">
    <a href="?<?= http_build_query(array_filter([
        'month'    => $month,
        'year'     => $year,
        'day'      => $day > 0 ? $day : null,
        'filter'   => $filter_card !== '' ? $filter_card : null,
        'search'   => $search !== '' ? $search : null,
        'origin'   => $filter_origin !== '' ? $filter_origin : null,
        'nod'      => $filter_nod !== '' ? $filter_nod : null,
        'sort_col' => $sort_col !== '' ? $sort_col : null,
        'sort_dir' => $sort_col !== '' ? $sort_dir : null,
        'download' => 'excel',
    ], fn($v) => $v !== null && $v !== '')) ?>" class="btn btn-success btn-sm">Download Excel</a>
    <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#manualEntryModal">Manual Entry</button>
    <button type="button" class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#initialStockSetupModal">
        <i class="bi bi-box-seam me-1"></i> Initial Stock Setup
    </button>
    <button type="button" class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#isuBulkImportModal">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Bulk Import (Excel)
    </button>
    <a href="sfc_tracking.php"       class="btn btn-info btn-sm">SFC Tracking Report</a>
    <a href="process_log_viewer.php" class="btn btn-secondary btn-sm">
        <i class="bi bi-clock-history me-1"></i> Process Log
    </a>
    <a href="pallet.php" class="btn btn-primary btn-sm">
        <i class="bi bi-archive me-1"></i> Manage Pallets
    </a>
    <span class="ms-auto d-flex align-items-center gap-2">
        <span id="mixedBulkPrintCount" class="text-muted" style="font-size:13px;">0 selected</span>
        <button type="button" id="mixedBulkPrintBtn" class="btn btn-danger btn-sm fw-bold" disabled
                onclick="goToMixedBatchSetup()">
            <i class="bi bi-printer-fill me-1"></i> Bulk Print Selected
        </button>
    </span>
</div>

<!-- Hidden form used to POST the mixed selection of IDs to
     mixed_batch_setup.php, where Customer/Ref No are now set
     individually per roll (instead of one global pair applied to
     everything — see mixed_batch_setup.php / mixed_batch_print_action.php). -->
<form id="mixedBulkPrintForm" method="post" action="mixed_batch_setup.php" style="display:none;">
    <input type="hidden" name="ids"      id="mixedBulkPrintIdsInput">
    <input type="hidden" name="month"    value="<?= $month ?>">
    <input type="hidden" name="year"     value="<?= $year ?>">
    <input type="hidden" name="day"      value="<?= $day ?>">
    <input type="hidden" name="search"   value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="filter"   value="<?= htmlspecialchars($filter_card) ?>">
</form>

<!-- ================================================================
     KPI SUMMARY CARDS — clickable filters
================================================================ -->
<div class="d-flex mb-3 gap-2 flex-wrap">

    <!-- IN (Pending) -->
    <?php $isActiveIn = ($filter_card === 'in_pending'); ?>
    <a href="<?= cardUrl('in_pending', $month, $year, $search) ?>"
       class="kpi-card-link flex-fill <?= $isActiveIn ? 'active-kpi' : '' ?>"
       title="Show IN (Pending) rolls only">
        <div class="card text-center text-bg-info h-100">
            <div class="card-body p-2">
                <h6 class="mb-1">IN</h6>
                <h2 class="mb-0"><?= (int)$in ?></h2>
                <?php if ($isActiveIn): ?>
                    <span class="kpi-active-dot">▲ filtered</span>
                <?php endif; ?>
            </div>
        </div>
    </a>

    <!-- STOCK -->
    <?php $isActiveStock = ($filter_card === 'stock'); ?>
    <a href="<?= cardUrl('stock', $month, $year, $search) ?>"
       class="kpi-card-link flex-fill <?= $isActiveStock ? 'active-kpi' : '' ?>"
       title="Show Finish Good stock only">
        <div class="card text-center text-bg-primary h-100">
            <div class="card-body p-2">
                <h6 class="mb-1">STOCK</h6>
                <h2 class="mb-0"><?= (int)$stock ?></h2>
                <?php if ($isActiveStock): ?>
                    <span class="kpi-active-dot">▲ filtered</span>
                <?php endif; ?>
            </div>
        </div>
    </a>

    <!-- PALLETISED -->
    <?php $isActivePal = ($filter_card === 'palletised'); ?>
    <a href="<?= cardUrl('palletised', $month, $year, $search) ?>"
       class="kpi-card-link flex-fill <?= $isActivePal ? 'active-kpi kpi-card-palletised-active' : '' ?>"
       title="Show palletised rolls only"
       style="color:#0369a1;">
        <div class="card text-center h-100" style="background:#e0f2fe;">
            <div class="card-body p-2">
                <h6 class="mb-1" style="color:#0369a1;">PALLETISED</h6>
                <h2 class="mb-0" style="color:#0369a1;"><?= (int)$palletised ?></h2>
                <?php if ($isActivePal): ?>
                    <span class="kpi-active-dot" style="color:#0369a1;">▲ filtered</span>
                <?php endif; ?>
            </div>
        </div>
    </a>

    <!-- WAITING QC -->
    <?php $isActiveWait = ($filter_card === 'waiting'); ?>
    <a href="<?= cardUrl('waiting', $month, $year, $search) ?>"
       class="kpi-card-link flex-fill <?= $isActiveWait ? 'active-kpi' : '' ?>"
       title="Show Waiting QC rolls only">
        <div class="card text-center text-bg-warning h-100">
            <div class="card-body p-2">
                <h6 class="mb-1">WAITING QC</h6>
                <h2 class="mb-0"><?= (int)$waiting ?></h2>
                <?php if ($isActiveWait): ?>
                    <span class="kpi-active-dot">▲ filtered</span>
                <?php endif; ?>
            </div>
        </div>
    </a>

    <!-- DELIVER -->
    <?php $isActiveDel = ($filter_card === 'deliver'); ?>
    <a href="<?= cardUrl('deliver', $month, $year, $search) ?>"
       class="kpi-card-link flex-fill <?= $isActiveDel ? 'active-kpi' : '' ?>"
       title="Show Delivered rolls only">
        <div class="card text-center text-bg-success h-100">
            <div class="card-body p-2">
                <h6 class="mb-1">DELIVER</h6>
                <h2 class="mb-0"><?= (int)$deliver ?></h2>
                <?php if ($isActiveDel): ?>
                    <span class="kpi-active-dot">▲ filtered</span>
                <?php endif; ?>
            </div>
        </div>
    </a>

</div>

<!-- ================================================================
     MONTHLY REPORTS — separate from the live tabs above. These use
     the same Month/Year dropdown at the top, but answer historical
     questions ("what happened in month X") instead of "what's true
     right now".
================================================================ -->
<div class="d-flex mb-3 gap-2 flex-wrap">

    <!-- Produced This Month -->
    <?php $isActivePM = ($filter_card === 'produced_month'); ?>
    <a href="<?= cardUrl('produced_month', $month, $year, $search) ?>"
       class="kpi-card-link flex-fill <?= $isActivePM ? 'active-kpi' : '' ?>"
       title="Show every roll produced (date in) during the selected month">
        <div class="card text-center h-100" style="background:#fdf4ff; border-color:#d8b4fe;">
            <div class="card-body p-2">
                <h6 class="mb-1" style="color:#7e22ce;"><i class="bi bi-calendar-plus me-1"></i>PRODUCED (<?= date("M", mktime(0,0,0,$month,1)) ?>)</h6>
                <h2 class="mb-0" style="color:#7e22ce;"><?= $producedMonthCount ?></h2>
                <?php if ($isActivePM): ?>
                    <span class="kpi-active-dot" style="color:#7e22ce;">▲ filtered</span>
                <?php endif; ?>
            </div>
        </div>
    </a>

    <!-- Stock as of Month-End -->
    <?php $isActiveSME = ($filter_card === 'stock_month_end'); ?>
    <a href="<?= cardUrl('stock_month_end', $month, $year, $search) ?>"
       class="kpi-card-link flex-fill <?= $isActiveSME ? 'active-kpi' : '' ?>"
       title="Reconstructed stock balance as of the end of the selected month">
        <div class="card text-center h-100" style="background:#fff7ed; border-color:#fdba74;">
            <div class="card-body p-2">
                <h6 class="mb-1" style="color:#c2410c;"><i class="bi bi-clock-history me-1"></i>STOCK @ END OF <?= strtoupper(date("M", mktime(0,0,0,$month,1))) ?></h6>
                <h2 class="mb-0" style="color:#c2410c;"><?= $stockMonthEndCount ?></h2>
                <?php if ($isActiveSME): ?>
                    <span class="kpi-active-dot" style="color:#c2410c;">▲ filtered</span>
                <?php endif; ?>
            </div>
        </div>
    </a>
    <!-- NOD (Notice of Defect) this month -->
    <?php
        $isActiveNod = ($filter_nod === '1');
        $nodCardParams = ['month' => $month, 'year' => $year];
        if ($day > 0) $nodCardParams['day'] = $day;
        if ($search !== '') $nodCardParams['search'] = $search;
        if ($filter_card !== '') $nodCardParams['filter'] = $filter_card;
        $nodCardParams['nod'] = $isActiveNod ? '' : '1'; // click again to toggle off
    ?>
    <a href="?<?= http_build_query($nodCardParams) ?>"
       class="kpi-card-link flex-fill <?= $isActiveNod ? 'active-kpi' : '' ?>"
       title="Show only rolls with a Notice of Defect recorded during the selected month">
        <div class="card text-center h-100" style="background:#fffbeb; border-color:#fcd34d;">
            <div class="card-body p-2">
                <h6 class="mb-1" style="color:#92400e;"><i class="bi bi-exclamation-triangle-fill me-1"></i>NOD (<?= date("M", mktime(0,0,0,$month,1)) ?>)</h6>
                <h2 class="mb-0" style="color:#92400e;"><?= $nodMonthCount ?></h2>
                <?php if ($isActiveNod): ?>
                    <span class="kpi-active-dot" style="color:#92400e;">▲ filtered</span>
                <?php endif; ?>
            </div>
        </div>
    </a>

</div>

<!-- Active tab banner -->
<div class="alert alert-info py-2 mb-3">
    <i class="bi bi-funnel-fill me-2"></i>
    Showing: <strong>
    <?= match($filter_card) {
        'in_pending'       => 'IN (Pending) only',
        'stock'            => 'Finish Good Stock only',
        'palletised'       => 'Palletised rolls only',
        'waiting'          => 'Waiting QC only',
        'deliver'          => 'Delivered only',
        'produced_month'   => 'Produced during ' . date("F Y", mktime(0,0,0,$month,1,$year)),
        'stock_month_end'  => 'Reconstructed stock balance as of end of ' . date("F Y", mktime(0,0,0,$month,1,$year)),
        default            => ''
    } ?>
    </strong>
    <?php if ($filter_nod === '1'): ?>
        <strong class="text-warning-emphasis">· NOD only</strong>
    <?php endif; ?>
    &nbsp;—&nbsp; click another card above to switch tabs. Sorted newest first.
    <?php if ($filter_card === 'stock_month_end'): ?>
        <br><small><i class="bi bi-info-circle me-1"></i>This is a historical reconstruction, not live data — actions (Add to Pallet, Reslit, etc.) are hidden here to avoid acting on a past snapshot by mistake.</small>
    <?php endif; ?>
</div>

<?php
// ── Sortable Date In / Date Out column header helper ──────────────
// Builds a link that preserves every other active filter (month, year,
// day, search, filter tab), sets sort_col to the clicked column, and
// toggles sort_dir if that column is already the active sort (otherwise
// defaults to DESC — newest first — on first click).
function sortHeaderLink(string $col, string $label, string $currentSortCol, string $currentSortDir,
                         int $month, int $year, int $day, string $search, string $filter_card,
                         string $filter_origin = '', string $filter_nod = ''): string {
    $isActive = ($currentSortCol === $col);
    $nextDir  = ($isActive && $currentSortDir === 'DESC') ? 'ASC' : 'DESC';
    $qs = http_build_query(array_filter([
        'month'    => $month,
        'year'     => $year,
        'day'      => $day > 0 ? $day : null,
        'search'   => $search !== '' ? $search : null,
        'filter'   => $filter_card !== '' ? $filter_card : null,
        'origin'   => $filter_origin !== '' ? $filter_origin : null,
        'nod'      => $filter_nod !== '' ? $filter_nod : null,
        'sort_col' => $col,
        'sort_dir' => $nextDir,
    ], fn($v) => $v !== null && $v !== ''));
    $icon = $isActive
        ? ($currentSortDir === 'DESC' ? '<i class="bi bi-arrow-down"></i>' : '<i class="bi bi-arrow-up"></i>')
        : '<i class="bi bi-arrow-down-up text-muted" style="opacity:.4"></i>';
    $activeClass = $isActive ? 'text-warning' : 'text-white';
    return "<a href=\"?{$qs}\" class=\"{$activeClass} text-decoration-none\">{$label} {$icon}</a>";
}
?>
<div class="table-responsive">
    <table class="table table-bordered table-striped align-middle text-center">
        <thead class="table-dark">
            <tr>
                <th style="width:32px;">
                    <input type="checkbox" id="selectAllMixedPrint" class="form-check-input"
                           onchange="toggleSelectAllMixedPrint(this)" title="Select all printable rolls">
                </th>
                <th>#</th><th>Status</th><th>Origin</th><th>Product</th>
                <th>Lot No</th><th>Width</th><th>Length</th>
                <th>Actual</th><th>NOD</th><th>Pallet</th>
                <th><?= sortHeaderLink('date_in',  'Date In',  $sort_col, $sort_dir, $month, $year, $day, $search, $filter_card, $filter_origin, $filter_nod) ?></th>
                <th><?= sortHeaderLink('date_out', 'Date Out', $sort_col, $sort_dir, $month, $year, $day, $search, $filter_card, $filter_origin, $filter_nod) ?></th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $rowNum = 0;
        if ($result && $result->num_rows > 0): while ($row = $result->fetch_assoc()):
            $rowNum++;
            $isFromSFC = ($row['original_source'] ?? $row['source']) === 'sfc';

            $rowClass = match($row['status']) {
                'IN'        => $row['is_completed'] == 0 ? 'table-info' : 'table-primary',
                'OUT'       => 'table-danger',
                'WAITING'   => 'table-warning',
                'APPROVED'  => 'table-success',
                'REJECTED'  => 'table-danger',
                'DELIVERED' => 'table-success',
                default     => ''
            };
            if ($isFromSFC) { $rowClass .= ' sfc-row'; }

            // NOD (Notice of Defect) — yellow highlight overrides the
            // status color so a defective roll is always visually
            // distinct, regardless of what status it's currently in.
            $hasNod = !empty($row['nod_length']) && (float)$row['nod_length'] > 0;
            if ($hasNod) { $rowClass .= ' row-has-nod'; }
            $nodDisplay = $hasNod
                ? number_format((float)$row['actual_length'] - (float)$row['nod_length'], 2)
                : '';

            $statusBadge = match($row['status']) {
                'IN'        => $row['is_completed'] == 0
                                ? '<span class="badge bg-info">IN (Pending)</span>'
                                : '<span class="badge bg-primary">IN (Stock)</span>',
                'OUT'       => '<span class="badge bg-danger">OUT</span>',
                'WAITING'   => '<span class="badge bg-warning text-dark">WAITING QC</span>',
                'APPROVED'  => '<span class="badge bg-success">APPROVED</span>',
                'REJECTED'  => '<div><span class="badge bg-danger">REJECTED</span><br>
                                    <small class="text-danger fw-bold">' . htmlspecialchars($row['qc_comment'] ?? '') . '</small></div>',
                'DELIVERED' => '<span class="badge bg-success">DELIVERED</span>',
                default     => '<span class="badge bg-secondary">' . $row['status'] . '</span>'
            };

            $originalSource = $row['original_source'] ?? $row['source'] ?? 'raw_material';
            $originDisplay  = match(trim(strtolower($originalSource))) {
                'sfc'           => ['label' => 'SFC', 'class' => 'bg-primary'],
                'raw_material'  => ['label' => 'RM',  'class' => 'bg-secondary'],
                'initial_stock' => ['label' => 'IS',  'class' => 'bg-dark'],
                default         => ['label' => strtoupper($originalSource), 'class' => 'bg-info']
            };

            $lotCoil     = trim($row['lot_no'] ?? '') . ' ' . trim($row['coil_no'] ?? '');
            $formattedRoll = str_replace('R', 'R-', trim($row['roll_no'] ?? ''));
            $lotCoilRoll = trim($lotCoil . ' ' . $formattedRoll);

            // Pallet display
            $palletDisplay = '—';
            if ($row['pallet_id']) {
                $pStatus = $row['pallet_status'] ?? 'building';
                $pBadgeClass = match($pStatus) {
                    'building'   => 'badge-pallet',
                    'pending_qc' => 'badge bg-warning text-dark',
                    'approved'   => 'badge bg-success',
                    'delivered'  => 'badge bg-success',
                    'rejected'   => 'badge bg-danger',
                    default      => 'badge bg-secondary'
                };
                $palletDisplay = '<a href="pallet.php?pallet_id=' . $row['pallet_id'] . '"
                    class="' . $pBadgeClass . '" style="font-size:10px;">'
                    . htmlspecialchars($row['pallet_no']) . '</a>';
            }

            // Same "printable" rule batch_setup.php's SQL uses: not
            // WAITING/REJECTED, and not an IN roll still pending Actual
            // Length or already palletised.
            $isPrintableForMixedBatch = !in_array($row['status'], ['WAITING', 'REJECTED'], true)
                && !($row['status'] === 'IN' && ($row['is_completed'] == 0 || $row['pallet_id']));
        ?>
            <tr class="<?= $rowClass ?>"
                data-id="<?= $row['id'] ?>"
                data-product="<?= htmlspecialchars($row['product'] ?? '') ?>"
                data-lot="<?= htmlspecialchars(trim($row['lot_no'] ?? '')) ?>">
                <td>
                    <?php if ($isPrintableForMixedBatch): ?>
                        <input type="checkbox" class="form-check-input mixed-print-select"
                               value="<?= $row['id'] ?>" onchange="updateMixedBulkPrintButton()">
                    <?php else: ?>
                        <input type="checkbox" class="form-check-input" disabled
                               title="<?= $row['status'] === 'IN' && $row['pallet_id'] ? 'Already palletised' : ($row['status'] === 'IN' ? 'Pending Actual Length' : 'Not printable in this status') ?>">
                    <?php endif; ?>
                </td>
                <td class="row-counter"><?= $rowNum ?></td>
                <td><?= $statusBadge ?></td>
                <td>
                    <div class="origin-cell">
                        <span class="badge <?= $originDisplay['class'] ?>">
                            <?= $originDisplay['label'] ?>
                        </span>
                    </div>
                </td>
                <td><?= htmlspecialchars($row['product'] ?? '') ?></td>
                <td class="lot-coil-cell"><?= htmlspecialchars($lotCoilRoll) ?></td>
                <td><?= $row['width'] ?></td>
                <td><?= $row['length'] ?></td>
                <td id="actual-display-<?= $row['id'] ?>"><?= $row['actual_length'] ?></td>
                <td>
                    <?php if ($hasNod): ?>
                        <span class="nod-value"><?= $nodDisplay ?></span>
                        <br><small class="badge bg-warning text-dark nod-badge" title="Defect length: <?= number_format((float)$row['nod_length'], 2) ?> m">NOD -<?= number_format((float)$row['nod_length'], 2) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= $palletDisplay ?></td>
                <td><?= $row['date_in'] ?></td>
                <td><?= $row['date_out'] ?></td>
                <td>
    <?php if ($filter_card !== 'stock_month_end'): ?>
        <button type="button"
                class="btn btn-outline-warning btn-sm mb-1 w-100 btn-nod"
                data-id="<?= $row['id'] ?>"
                data-actual="<?= (float)($row['actual_length'] ?? 0) ?>"
                data-nod="<?= $hasNod ? (float)$row['nod_length'] : '' ?>"
                data-label="<?= htmlspecialchars($lotCoilRoll) ?>"
                title="<?= $hasNod ? 'Edit / clear Notice of Defect' : 'Record a Notice of Defect' ?>">
            <i class="bi bi-exclamation-triangle<?= $hasNod ? '-fill' : '' ?> me-1"></i><?= $hasNod ? 'NOD' : 'NOD +' ?>
        </button>
    <?php endif; ?>
    <?php if ($filter_card === 'stock_month_end'): ?>
        <span class="text-muted small"><i class="bi bi-eye me-1"></i>View only</span>

    <?php elseif ($row['status'] === 'WAITING'): ?>
        <small><i>Waiting QC on pallet <?= htmlspecialchars($row['pallet_no'] ?? '...') ?></i></small>

    <?php elseif ($row['status'] === 'REJECTED'): ?>
        <div class="d-flex flex-column gap-1">
            <form method="post" onsubmit="return confirm('Reslit this rejected product?')">
                <input type="hidden" name="action"     value="send_to_reslit">
                <input type="hidden" name="product_id" value="<?= $row['id'] ?>">
                <input type="hidden" name="month"  value="<?= $month ?>">
                <input type="hidden" name="year"   value="<?= $year ?>">
                <input type="hidden" name="day" value="<?= $day ?>">
                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                <input type="hidden" name="filter" value="<?= htmlspecialchars($filter_card) ?>">
                <button type="submit" class="btn btn-warning btn-sm w-100">Reslit</button>
            </form>
            <form method="post" onsubmit="return confirm('Move rejected product to Recoiling?')">
                <input type="hidden" name="action"     value="send_to_recoiling">
                <input type="hidden" name="product_id" value="<?= $row['id'] ?>">
                <input type="hidden" name="month"  value="<?= $month ?>">
                <input type="hidden" name="year"   value="<?= $year ?>">
                <input type="hidden" name="day" value="<?= $day ?>">
                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                <input type="hidden" name="filter" value="<?= htmlspecialchars($filter_card) ?>">
                <button type="submit" class="btn btn-info btn-sm w-100 text-white">Recoiling</button>
            </form>
        </div>

    <?php elseif ($row['status'] === 'IN'): ?>
        <div class="d-flex flex-column gap-1">

            <?php if ($row['is_completed'] == 0): ?>
                <!-- No actual length yet — must update first -->
                <a href="?edit=<?= $row['id'] ?>&month=<?= $month ?>&year=<?= $year ?>&day=<?= $day ?>&search=<?= urlencode($search) ?><?= $filter_card ? '&filter='.urlencode($filter_card) : '' ?><?= $filter_origin !== '' ? '&origin='.urlencode($filter_origin) : '' ?><?= $filter_nod !== '' ? '&nod='.urlencode($filter_nod) : '' ?>"
                   class="btn btn-primary btn-sm w-100">Update</a>

            <?php elseif ($row['pallet_id']): ?>
                <!-- Already palletised — hide Reslit/Recoiling to prevent mistakes -->
                <a href="pallet.php?pallet_id=<?= $row['pallet_id'] ?>"
                   class="btn btn-outline-primary btn-sm w-100">
                   <i class="bi bi-archive me-1"></i><?= htmlspecialchars($row['pallet_no']) ?>
                </a>
                <small class="text-muted text-center d-block mt-1" style="font-size:10px;">
                    <i class="bi bi-lock me-1"></i>Remove from pallet to Reslit / Recoil
                </small>

            <?php else: ?>
                <!-- Stock counted, not yet on a pallet -->
                <a href="?edit=<?= $row['id'] ?>&month=<?= $month ?>&year=<?= $year ?>&day=<?= $day ?>&search=<?= urlencode($search) ?><?= $filter_card ? '&filter='.urlencode($filter_card) : '' ?><?= $filter_origin !== '' ? '&origin='.urlencode($filter_origin) : '' ?><?= $filter_nod !== '' ? '&nod='.urlencode($filter_nod) : '' ?>"
                   class="btn btn-outline-primary btn-sm w-100">Edit</a>
                <button type="button" class="btn btn-primary btn-sm w-100"
                        onclick="openSendToSfcModal(<?= (int)$row['id'] ?>, '<?= htmlspecialchars($row['product'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($row['lot_no'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($row['coil_no'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($row['roll_no'] ?? '', ENT_QUOTES) ?>', <?= (float)($row['width'] ?? 0) ?>, <?= (float)($row['actual_length'] ?: $row['length']) ?>)">
                    <i class="bi bi-box-seam me-1"></i> Send to SFC
                </button>
                <form method="post" onsubmit="return confirm('Send to reslit?')">
                    <input type="hidden" name="action"     value="send_to_reslit">
                    <input type="hidden" name="product_id" value="<?= $row['id'] ?>">
                    <input type="hidden" name="month"  value="<?= $month ?>">
                    <input type="hidden" name="year"   value="<?= $year ?>">
                    <input type="hidden" name="day" value="<?= $day ?>">
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter_card) ?>">
                    <button type="submit" class="btn btn-warning btn-sm w-100">Reslit</button>
                </form>
                <form method="post" onsubmit="return confirm('Move to Recoiling?')">
                    <input type="hidden" name="action"     value="send_to_recoiling">
                    <input type="hidden" name="product_id" value="<?= $row['id'] ?>">
                    <input type="hidden" name="month"  value="<?= $month ?>">
                    <input type="hidden" name="year"   value="<?= $year ?>">
                    <input type="hidden" name="day" value="<?= $day ?>">
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter_card) ?>">
                    <button type="submit" class="btn btn-info btn-sm w-100 text-white">Recoiling</button>
                </form>
                <?php if (!empty($row['is_printed'])): ?>
                    <span class="badge bg-success w-100 mb-1"
                          title="Last printed <?= htmlspecialchars($row['last_printed_at'] ? date('d M Y H:i', strtotime($row['last_printed_at'])) : '') ?> by <?= htmlspecialchars($row['last_printed_by'] ?? '') ?>">
                        <i class="bi bi-printer-fill"></i> Printed (<?= (int)$row['print_count'] ?>×)
                    </span>
                <?php else: ?>
                    <span class="badge bg-secondary w-100 mb-1">Not Printed</span>
                <?php endif; ?>
            <?php endif; ?>

        </div>

    <?php elseif ($row['status'] === 'APPROVED'): ?>
<?php if (!empty($row['is_printed'])): ?>
        <span class="badge bg-success w-100 mb-1"
              title="Last printed <?= htmlspecialchars($row['last_printed_at'] ? date('d M Y H:i', strtotime($row['last_printed_at'])) : '') ?> by <?= htmlspecialchars($row['last_printed_by'] ?? '') ?>">
            <i class="bi bi-printer-fill"></i> Printed (<?= (int)$row['print_count'] ?>×)
        </span>
    <?php else: ?>
        <span class="badge bg-secondary w-100 mb-1">Not Printed</span>
    <?php endif; ?>
    <a href="select_customer.php?id=<?= $row['id'] ?>" class="btn btn-success btn-sm w-100">Print & Deliver</a>

    <?php else: ?>
<?php if (!empty($row['is_printed'])): ?>
        <span class="badge bg-success w-100 mb-1"
              title="Last printed <?= htmlspecialchars($row['last_printed_at'] ? date('d M Y H:i', strtotime($row['last_printed_at'])) : '') ?> by <?= htmlspecialchars($row['last_printed_by'] ?? '') ?>">
            <i class="bi bi-printer-fill"></i> Printed (<?= (int)$row['print_count'] ?>×)
        </span>
    <?php else: ?>
        <span class="badge bg-secondary w-100 mb-1">Not Printed</span>
    <?php endif; ?>
    <a href="select_customer.php?id=<?= $row['id'] ?>" class="btn btn-success btn-sm w-100">Print</a>
    <?php endif; ?>
                </td>
            </tr>
        <?php endwhile; else: ?>
            <tr><td colspan="14" class="py-4 text-muted">
                No products found<?= $search !== '' ? ' matching "' . htmlspecialchars($search) . '"' : '' ?><?= $filter_card !== '' ? ' for the selected filter' : '' ?>.
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Actual Length Edit Modal -->
<?php if ($editData): ?>
<script src="https://unpkg.com/imask"></script>
<div class="modal fade show" id="updateModal"
     style="display:block; background: rgba(0,0,0,0.6);" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content shadow-lg border-0">
            <!-- Modal Header -->
            <div class="modal-header bg-dark text-white py-3">
                <div>
                    <h5 class="modal-title mb-1 text-white">
                        <i class="bi bi-box-seam-fill me-2 text-warning"></i>
                        Manage Product &amp; Lot Batch — Lot <strong><?= htmlspecialchars(trim($editData['lot_no'] ?? '')) ?></strong> / Coil <strong><?= htmlspecialchars(trim($editData['coil_no'] ?? '')) ?></strong>
                    </h5>
                    <div class="small text-light-50">
                        Selected Item: <strong><?= htmlspecialchars($editData['product'] ?? '') ?> (<?= htmlspecialchars(str_replace('R', 'R-', $editData['roll_no'] ?? '')) ?>)</strong> · Total <strong><?= count($batchRolls) ?></strong> roll(s) in this Lot &amp; Coil
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="batch_setup.php?lot_no=<?= urlencode(trim($editData['lot_no'] ?? '')) ?>&coil_no=<?= urlencode(trim($editData['coil_no'] ?? '')) ?>&mother_id=<?= intval($editData['mother_id'] ?? 0) ?>&month=<?= $month ?>&year=<?= $year ?>&day=<?= $day ?>&search=<?= urlencode($search) ?>&filter=<?= urlencode($filter_card) ?>"
                       class="btn btn-outline-light btn-sm" title="Open in dedicated full page">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Full Page View
                    </a>
                    <a href="finish_product.php?month=<?= $month ?>&year=<?= $year ?>&day=<?= $day ?>&search=<?= urlencode($search) ?><?= $filter_card ? '&filter='.urlencode($filter_card) : '' ?><?= $filter_origin !== '' ? '&origin='.urlencode($filter_origin) : '' ?><?= $filter_nod !== '' ? '&nod='.urlencode($filter_nod) : '' ?>"
                       class="btn-close btn-close-white" title="Close modal"></a>
                </div>
            </div>

            <!-- Modal Body -->
            <div class="modal-body p-3 bg-light">
                <!-- Consolidated Batch Assignment Panel Toolbar (Copy to All Rows) -->
                <div class="card mb-3 shadow-sm border-danger-subtle" style="background: #fff8f8;">
                    <div class="card-header bg-danger-subtle fw-bold py-2 text-danger-emphasis d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-sliders me-1"></i> Batch Assignment Toolbar — Copy to All Rows</span>
                        <span class="badge bg-danger text-white"><?= htmlspecialchars($editData['product'] ?? '') ?></span>
                    </div>
                    <div class="card-body py-2">
                        <div class="row g-2 align-items-end">
                            <div class="col-6 col-md-3">
                                <label class="small fw-bold mb-1">Actual Length (meters)</label>
                                <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="modalCopyAllActualLength"
                                       placeholder="e.g. 500" value="<?= htmlspecialchars((string)($editData['actual_length'] ?? '')) ?>">
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="small fw-bold mb-1">Customer</label>
                                <select class="form-select form-select-sm" id="modalCopyAllCustomer">
                                    <option value="">-- Select Customer --</option>
                                    <option value="NAE">NICHIAS AUTOPARTS EUROPE (NAE)</option>
                                    <option value="NAX">NAX MFG, SA.DE C.V</option>
                                    <option value="NCI MFG">NCI MFG., INC.</option>
                                    <option value="TAIHO">TAIHO MFG OF TN. INC</option>
                                    <option value="NRI">PT NICHIAS ROCKWOOL IND.</option>
                                    <option value="ASHUKA">ASHUKA TECHNOLOGIES SDN. BHD.</option>
                                    <option value="NIPPON">NTC(NIPPON GASKET)</option>
                                    <option value="NTC">NICHIAS THAILAND</option>
                                    <option value="SGC">SHANGHAI XINGSHENG</option>
                                    <option value="STAMPING">MK STAMPING</option>
                                    <option value="YANTAI">NICHIAS (SHANGHAI) AUTOPARTS TRADING</option>
                                    <option value="NIPP">NICHIAS IND.PRODUCTS PVT. LTD.</option>
                                    <option value="NVC">NICHIAS VIETNAM CO., LTD</option>
                                    <option value="NSJ">NC-PT NICHIAS SUNIJAYA</option>
                                    <option value="NIP">SUZHOU NICHIAS IND. PRODUCTS</option>
                                    <option value="YTEC">YTEC CO., LTD.</option>
                                    <option value="NSA">NICHIAS SOUTH EAST ASIA (UP PACKING)</option>
                                    <option value="NCI 2">NCI 2</option>
                                    <option value="STOCK">STOCK</option>
                                    <option value="TRIAL">TRIAL</option>
                                    <option value="OTHER">OTHER (type below)</option>
                                </select>
                                <input type="text" class="form-control form-control-sm mt-1" id="modalCopyAllCustomOther"
                                       placeholder="Customer name (if OTHER)" style="display:none;">
                            </div>
                            <div class="col-8 col-md-3">
                                <label class="small fw-bold mb-1">Ref No</label>
                                <input type="text" class="form-control form-control-sm" id="modalCopyAllRefNo" value="SO-" placeholder="SO-00-0000">
                            </div>
                            <div class="col-4 col-md-1">
                                <label class="small fw-bold mb-1">Copies</label>
                                <select class="form-select form-select-sm" id="modalCopyAllCopies">
                                    <option value="">—</option>
                                    <option value="0">0 (skip)</option>
                                    <option value="1">1</option>
                                    <option value="2">2</option>
                                    <option value="3">3</option>
                                    <option value="4">4</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-2">
                                <button type="button" class="btn btn-outline-danger btn-sm w-100 fw-bold" onclick="modalCopyToAllRows()">
                                    <i class="bi bi-arrow-down-square me-1"></i> Copy to All
                                </button>
                            </div>
                        </div>
                        <div class="form-text mb-0">Fills every row below with Customer, Ref No, Actual Length, and Print Copies — then adjust any row if needed.</div>
                    </div>
                </div>

                <!-- Section 3: All Rolls Grid for Lot & Coil Match -->
                <div class="card shadow-sm border-secondary-subtle">
                    <div class="card-header bg-dark text-white py-2 d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><i class="bi bi-list-check me-1"></i> Associated Rolls Grid (Lot <?= htmlspecialchars(trim($editData['lot_no'] ?? '')) ?> / Coil <?= htmlspecialchars(trim($editData['coil_no'] ?? '')) ?>)</span>
                        <span class="badge bg-secondary"><?= count($batchRolls) ?> Roll(s) Found</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 380px;">
                            <table class="table table-bordered table-hover table-sm mb-0 align-middle" id="modalBatchGridTable">
                                <thead class="table-dark sticky-top" style="z-index: 5;">
                                    <tr>
                                        <th style="width:14%;">Roll No &amp; Product</th>
                                        <th style="width:14%;">Width / Actual Length</th>
                                        <th style="width:28%;">Customer</th>
                                        <th style="width:26%;">Ref No.</th>
                                        <th style="width:9%;">Copies</th>
                                        <th style="width:9%;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($batchRolls as $idx => $r): ?>
                                    <tr data-id="<?= $r['id'] ?>" data-row="<?= $idx ?>"
                                        data-is-printed="<?= !empty($r['is_printed']) ? 1 : 0 ?>"
                                        data-print-count="<?= (int)($r['print_count'] ?? 0) ?>"
                                        class="<?= ((int)$r['id'] === (int)$editData['id']) ? 'table-warning' : '' ?>">
                                        <td>
                                            <strong><?= htmlspecialchars(str_replace('R', 'R-', $r['roll_no'] ?? '')) ?></strong>
                                            <?php if ((int)$r['id'] === (int)$editData['id']): ?>
                                                <span class="badge bg-primary ms-1" style="font-size:9px;">Selected</span>
                                            <?php endif; ?>
                                            <div class="text-muted small"><?= htmlspecialchars($r['product'] ?? '') ?></div>
                                            <?php if (!empty($r['is_printed'])): ?>
                                                <span class="badge bg-success" style="font-size:10px;"
                                                      title="Last printed <?= htmlspecialchars($r['last_printed_at'] ? date('d M Y H:i', strtotime($r['last_printed_at'])) : '') ?> by <?= htmlspecialchars($r['last_printed_by'] ?? '') ?>">
                                                    <i class="bi bi-printer-fill"></i> Printed (<?= (int)$r['print_count'] ?>×)
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary" style="font-size:10px;">Not Printed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="fw-semibold"><?= number_format((float)$r['width'], 0) ?> mm</span>
                                            <?php $curLength = (!empty($r['actual_length']) && $r['actual_length'] > 0) ? $r['actual_length'] : $r['length']; ?>
                                            <div class="input-group input-group-sm mt-1">
                                                <input type="number" step="0.01" min="0"
                                                       class="form-control form-control-sm modal-row-length" data-row="<?= $idx ?>"
                                                       value="<?= htmlspecialchars($curLength ?? '') ?>">
                                                <span class="input-group-text px-1" style="font-size:10px;">m</span>
                                            </div>
                                        </td>
                                        <td>
                                            <?php
                                                $saved = trim($r['customer_name'] ?? '');
                                                $knownCustomers = ['NAE','NAX','NCI MFG','TAIHO','NRI','ASHUKA','NIPPON','NTC','SGC','STAMPING','YANTAI','NIPP','NVC','NSJ','NIP','YTEC','NSA','NCI 2','STOCK','TRIAL'];
                                                $isOther = ($saved !== '' && !in_array($saved, $knownCustomers, true));

                                                $rawRefNo = trim($r['ref_no'] ?? '');
                                                $isStock = ($saved === 'STOCK' || $rawRefNo === 'STOCK');
                                                $displayRefNo = $isStock ? 'STOCK' : ($rawRefNo !== '' ? $rawRefNo : 'SO-');
                                            ?>
                                            <select class="form-select form-select-sm modal-row-customer" data-row="<?= $idx ?>"
                                                    onchange="modalHandleRowCustomerChange(<?= $idx ?>)">
                                                <option value=""         <?= $saved===''         ?'selected':'' ?>>-- Select Customer --</option>
                                                <option value="NAE"      <?= $saved==='NAE'      ?'selected':'' ?>>NICHIAS AUTOPARTS EUROPE (NAE)</option>
                                                <option value="NAX"      <?= $saved==='NAX'      ?'selected':'' ?>>NAX MFG, SA.DE C.V</option>
                                                <option value="NCI MFG"  <?= $saved==='NCI MFG'  ?'selected':'' ?>>NCI MFG., INC.</option>
                                                <option value="TAIHO"    <?= $saved==='TAIHO'    ?'selected':'' ?>>TAIHO MFG OF TN. INC</option>
                                                <option value="NRI"      <?= $saved==='NRI'      ?'selected':'' ?>>PT NICHIAS ROCKWOOL IND.</option>
                                                <option value="ASHUKA"   <?= $saved==='ASHUKA'   ?'selected':'' ?>>ASHUKA TECHNOLOGIES SDN. BHD.</option>
                                                <option value="NIPPON"   <?= $saved==='NIPPON'   ?'selected':'' ?>>NTC(NIPPON GASKET)</option>
                                                <option value="NTC"      <?= $saved==='NTC'      ?'selected':'' ?>>NICHIAS THAILAND</option>
                                                <option value="SGC"      <?= $saved==='SGC'      ?'selected':'' ?>>SHANGHAI XINGSHENG</option>
                                                <option value="STAMPING" <?= $saved==='STAMPING' ?'selected':'' ?>>MK STAMPING</option>
                                                <option value="YANTAI"   <?= $saved==='YANTAI'   ?'selected':'' ?>>NICHIAS (SHANGHAI) AUTOPARTS TRADING</option>
                                                <option value="NIPP"     <?= $saved==='NIPP'     ?'selected':'' ?>>NICHIAS IND.PRODUCTS PVT. LTD.</option>
                                                <option value="NVC"      <?= $saved==='NVC'      ?'selected':'' ?>>NICHIAS VIETNAM CO., LTD</option>
                                                <option value="NSJ"      <?= $saved==='NSJ'      ?'selected':'' ?>>NC-PT NICHIAS SUNIJAYA</option>
                                                <option value="NIP"      <?= $saved==='NIP'      ?'selected':'' ?>>SUZHOU NICHIAS IND. PRODUCTS</option>
                                                <option value="YTEC"     <?= $saved==='YTEC'     ?'selected':'' ?>>YTEC CO., LTD.</option>
                                                <option value="NSA"     <?= $saved==='NSA'     ?'selected':'' ?>>NICHIAS SOUTH EAST ASIA (UP PACKING)</option>
                                                <option value="NCI 2"    <?= $saved==='NCI 2'    ?'selected':'' ?>>NCI 2</option>
                                                <option value="STOCK"    <?= $saved==='STOCK'    ?'selected':'' ?>>STOCK</option>
                                                <option value="TRIAL"    <?= $saved==='TRIAL'    ?'selected':'' ?>>TRIAL</option>
                                                <option value="OTHER"    <?= $isOther            ?'selected':'' ?>>OTHER (type below)</option>
                                            </select>
                                            <input type="text" class="form-control form-control-sm modal-row-custom-customer mt-1" data-row="<?= $idx ?>"
                                                   placeholder="Enter customer name" style="display:<?= $isOther?'block':'none' ?>;"
                                                   value="<?= $isOther ? htmlspecialchars($saved) : '' ?>">
                                            <div class="text-muted modal-nci-note mt-1" data-row="<?= $idx ?>" style="display:none; font-size:11px;"></div>
                                        </td>
                                        <td>
                                            <div class="form-check mb-1">
                                                <input class="form-check-input modal-row-stock-override" type="checkbox"
                                                       id="modalRowStock<?= $idx ?>" data-row="<?= $idx ?>" <?= $isStock ? 'checked' : '' ?>>
                                                <label class="form-check-label small" for="modalRowStock<?= $idx ?>">Set to STOCK</label>
                                            </div>
                                            <input type="text" class="form-control form-control-sm modal-row-refno" data-row="<?= $idx ?>"
                                                   value="<?= htmlspecialchars($displayRefNo) ?>" placeholder="SO-00-0000" <?= $isStock ? 'readonly' : '' ?>>
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm modal-row-copies" data-row="<?= $idx ?>">
                                                <option value="0">0 (skip)</option>
                                                <option value="1">1</option>
                                                <option value="2" selected>2</option>
                                                <option value="3">3</option>
                                                <option value="4">4</option>
                                            </select>
                                        </td>
                                        <td class="modal-row-status text-muted small" data-row="<?= $idx ?>">
                                            <?php if ((int)$r['is_completed'] === 0): ?>
                                                <span class="badge bg-info">Pending</span>
                                            <?php else: ?>
                                                <span class="badge bg-primary">Stock</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="modal-footer bg-light py-2 d-flex justify-content-between align-items-center">
                <div id="modalSaveAllFeedback" class="small"></div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary" id="modalSaveAllBtn" onclick="modalSaveAllRows()">
                        <i class="bi bi-floppy-fill me-1"></i> Save All to Stock
                    </button>
                    <button type="button" class="btn btn-danger" id="modalPrintAllBtn" onclick="modalPrintAllStickers()">
                        <i class="bi bi-printer-fill me-1"></i> Save &amp; Print All Stickers
                    </button>
                    <a href="finish_product.php?month=<?= $month ?>&year=<?= $year ?>&day=<?= $day ?>&search=<?= urlencode($search) ?><?= $filter_card ? '&filter='.urlencode($filter_card) : '' ?><?= $filter_origin !== '' ? '&origin='.urlencode($filter_origin) : '' ?><?= $filter_nod !== '' ? '&nod='.urlencode($filter_nod) : '' ?>"
                       class="btn btn-secondary">Close</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Hidden form used to POST the batch print job in a new tab -->
<form id="modalBatchPrintForm" method="post" action="batch_print_action.php" target="_blank" style="display:none;">
    <input type="hidden" name="selections" id="modalBatchPrintSelectionsInput">
    <input type="hidden" name="lot_no"  value="<?= htmlspecialchars(trim($editData['lot_no'] ?? '')) ?>">
    <input type="hidden" name="coil_no" value="<?= htmlspecialchars(trim($editData['coil_no'] ?? '')) ?>">
    <input type="hidden" name="month"   value="<?= $month ?>">
    <input type="hidden" name="year"    value="<?= $year ?>">
    <input type="hidden" name="day"     value="<?= $day ?>">
    <input type="hidden" name="search"  value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="filter"  value="<?= htmlspecialchars($filter_card) ?>">
</form>

<script>
const MODAL_NCI_CUSTOMERS = ['NCI MFG', 'NCI 2'];
const modalRefNoMasks = {};

function modalDestroyRefMaskForRow(idx) {
    if (modalRefNoMasks[idx]) { modalRefNoMasks[idx].destroy(); modalRefNoMasks[idx] = null; }
}

function modalApplyMaskForRow(idx, val) {
    modalDestroyRefMaskForRow(idx);
    const refEl   = document.querySelector(`.modal-row-refno[data-row="${idx}"]`);
    const stockEl = document.querySelector(`.modal-row-stock-override[data-row="${idx}"]`);
    if (!refEl) return;

    if (stockEl?.checked || MODAL_NCI_CUSTOMERS.includes(val)) {
        return;
    }

    if (val === 'STAMPING') {
        modalRefNoMasks[idx] = IMask(refEl, {
            mask: 'MS-0000000[ a]',
            blocks: { a: { mask: /[A-Z]/ } },
            prepareChar: (str) => str.toUpperCase()
        });
        if (!modalRefNoMasks[idx].value || modalRefNoMasks[idx].value === 'SO-' || modalRefNoMasks[idx].value === 'STOCK') {
            modalRefNoMasks[idx].value = 'MS-';
        }
    } else {
        modalRefNoMasks[idx] = IMask(refEl, { mask: 'SO-00-0000' });
        if (!modalRefNoMasks[idx].value || modalRefNoMasks[idx].value === 'STOCK') {
            modalRefNoMasks[idx].value = 'SO-';
        }
    }
}

function modalRefNoMatchesActiveRuleForRow(idx) {
    const refEl   = document.querySelector(`.modal-row-refno[data-row="${idx}"]`);
    const custEl  = document.querySelector(`.modal-row-customer[data-row="${idx}"]`);
    const stockEl = document.querySelector(`.modal-row-stock-override[data-row="${idx}"]`);
    if (!refEl || !custEl) return true;

    if (stockEl?.checked) return true;
    const val = refEl.value.trim();
    const cust = custEl.value;

    if (MODAL_NCI_CUSTOMERS.includes(cust)) return val !== '';
    if (cust === 'STAMPING') return /^MS-\d{7}( [A-Z])?$/.test(val);
    return /^SO-\d{2}-\d{4}$/.test(val);
}

function modalGetRowCount() {
    return document.querySelectorAll('#modalBatchGridTable tbody tr').length;
}

async function modalHandleRowCustomerChange(rowIdx) {
    const sel      = document.querySelector(`.modal-row-customer[data-row="${rowIdx}"]`);
    const otherEl  = document.querySelector(`.modal-row-custom-customer[data-row="${rowIdx}"]`);
    const noteEl   = document.querySelector(`.modal-nci-note[data-row="${rowIdx}"]`);
    const refEl    = document.querySelector(`.modal-row-refno[data-row="${rowIdx}"]`);
    const stockEl  = document.querySelector(`.modal-row-stock-override[data-row="${rowIdx}"]`);
    const tr       = document.querySelectorAll('#modalBatchGridTable tbody tr')[rowIdx];
    const productId = tr ? tr.dataset.id : null;
    const val = sel.value;

    otherEl.style.display = (val === 'OTHER') ? 'block' : 'none';

    if (val === 'STOCK') {
        modalDestroyRefMaskForRow(rowIdx);
        if (stockEl) stockEl.checked = true;
        refEl.value = 'STOCK';
        refEl.readOnly = true;
        noteEl.style.display = 'none';
        noteEl.innerHTML = '';
        return;
    }

    if (stockEl) stockEl.checked = false;
    refEl.readOnly = false;
    if (refEl.value === 'STOCK' || !refEl.value.trim()) {
        refEl.value = 'SO-';
    }

    modalApplyMaskForRow(rowIdx, val);

    if (!MODAL_NCI_CUSTOMERS.includes(val)) {
        noteEl.style.display = 'none';
        noteEl.innerHTML = '';
        return;
    }

    noteEl.style.display = 'block';
    noteEl.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Looking up Ref No…';

    try {
        const res  = await fetch(`select_customer.php?ajax=nci_lookup&id=${productId}`);
        const data = await res.json();
        if (!data.ok) {
            noteEl.innerHTML = `<span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>${data.msg} — fill Ref No manually.</span>`;
            return;
        }
        refEl.value = data.part_no_full;
        refEl.dataset.nciResolvedCustomer = data.customer_info;
        noteEl.innerHTML = `<i class="bi bi-check-circle me-1"></i>Customer on sticker: <strong>${data.customer_info}</strong>`;
    } catch (e) {
        noteEl.innerHTML = '<span class="text-danger">Network error during lookup.</span>';
    }
}

document.getElementById('modalCopyAllCustomer')?.addEventListener('change', function () {
    const val = this.value;
    document.getElementById('modalCopyAllCustomOther').style.display =
        (val === 'OTHER') ? 'block' : 'none';
    const copyAllRefEl = document.getElementById('modalCopyAllRefNo');
    if (copyAllRefEl) {
        if (val === 'STOCK') {
            copyAllRefEl.value = 'STOCK';
        } else if (copyAllRefEl.value === 'STOCK') {
            copyAllRefEl.value = 'SO-';
        }
    }
});

document.querySelectorAll('.modal-row-stock-override').forEach((cb) => {
    const idx = cb.dataset.row;
    cb.addEventListener('change', () => {
        const refEl  = document.querySelector(`.modal-row-refno[data-row="${idx}"]`);
        const custEl = document.querySelector(`.modal-row-customer[data-row="${idx}"]`);
        if (cb.checked) {
            modalDestroyRefMaskForRow(idx);
            refEl.value = 'STOCK';
            refEl.readOnly = true;
            if (custEl && custEl.value === '') {
                custEl.value = 'STOCK';
            }
        } else {
            refEl.readOnly = false;
            refEl.value = 'SO-';
            if (custEl && custEl.value === 'STOCK') {
                custEl.value = '';
            }
            modalApplyMaskForRow(idx, custEl.value);
        }
    });
});

(function initModalRowRefNoMasks() {
    const rowCount = modalGetRowCount();
    for (let idx = 0; idx < rowCount; idx++) {
        const custEl  = document.querySelector(`.modal-row-customer[data-row="${idx}"]`);
        const refEl   = document.querySelector(`.modal-row-refno[data-row="${idx}"]`);
        const stockEl = document.querySelector(`.modal-row-stock-override[data-row="${idx}"]`);
        if (!custEl || !refEl) continue;

        if (stockEl?.checked || refEl.value.trim() === 'STOCK') {
            if (stockEl) stockEl.checked = true;
            refEl.value = 'STOCK';
            refEl.readOnly = true;
        } else {
            if (!refEl.value.trim()) {
                refEl.value = 'SO-';
            }
            modalApplyMaskForRow(idx, custEl.value);
        }
    }
})();

async function modalCopyToAllRows() {
    const sel       = document.getElementById('modalCopyAllCustomer');
    const otherEl   = document.getElementById('modalCopyAllCustomOther');
    const refEl     = document.getElementById('modalCopyAllRefNo');
    const lengthEl  = document.getElementById('modalCopyAllActualLength');
    const copiesEl  = document.getElementById('modalCopyAllCopies');

    const customerVal = sel ? sel.value : '';
    const refVal      = refEl ? refEl.value.trim().replace(/\s+/g, '') : '';
    const lengthVal   = lengthEl ? lengthEl.value.trim() : '';
    const copiesVal   = copiesEl ? copiesEl.value : '';

    if (!customerVal && !lengthVal) {
        alert('Set Customer or Actual Length to copy to all rows.');
        return;
    }
    if (customerVal === 'OTHER' && !otherEl.value.trim()) { alert('Enter the customer name.'); return; }

    const rowCount = modalGetRowCount();
    for (let idx = 0; idx < rowCount; idx++) {
        const rowSel      = document.querySelector(`.modal-row-customer[data-row="${idx}"]`);
        const rowOtherEl  = document.querySelector(`.modal-row-custom-customer[data-row="${idx}"]`);
        const rowRefEl    = document.querySelector(`.modal-row-refno[data-row="${idx}"]`);
        const rowLengthEl = document.querySelector(`.modal-row-length[data-row="${idx}"]`);
        const rowCopiesEl = document.querySelector(`.modal-row-copies[data-row="${idx}"]`);
        const rowStockEl  = document.querySelector(`.modal-row-stock-override[data-row="${idx}"]`);

        if (customerVal) {
            rowSel.value = customerVal;
            if (customerVal === 'OTHER') rowOtherEl.value = otherEl.value.trim();

            if (customerVal === 'STOCK') {
                if (rowStockEl) rowStockEl.checked = true;
                rowRefEl.readOnly = true;
                if (refVal !== '') rowRefEl.value = refVal;
            } else {
                if (rowStockEl) rowStockEl.checked = false;
                rowRefEl.readOnly = false;
                if (refVal !== '') rowRefEl.value = refVal;
            }
            await modalHandleRowCustomerChange(idx);
        }

        if (lengthVal !== '' && rowLengthEl) {
            rowLengthEl.value = lengthVal;
        }

        if (copiesVal && rowCopiesEl) {
            rowCopiesEl.value = copiesVal;
        }
    }
}

function modalSetRowStatus(rowIdx, text, isError) {
    const el = document.querySelector(`.modal-row-status[data-row="${rowIdx}"]`);
    if (!el) return;
    el.textContent = text;
    el.className = 'modal-row-status small ' + (isError ? 'text-danger fw-bold' : 'text-success fw-bold');
}

function modalCollectSelections() {
    const trs = Array.from(document.querySelectorAll('#modalBatchGridTable tbody tr'));
    const selections = [];
    let hasError = false;

    trs.forEach((tr, idx) => {
        const sel      = document.querySelector(`.modal-row-customer[data-row="${idx}"]`);
        const otherEl  = document.querySelector(`.modal-row-custom-customer[data-row="${idx}"]`);
        const refEl    = document.querySelector(`.modal-row-refno[data-row="${idx}"]`);
        const copiesEl = document.querySelector(`.modal-row-copies[data-row="${idx}"]`);
        const lengthEl = document.querySelector(`.modal-row-length[data-row="${idx}"]`);
        const stockEl  = document.querySelector(`.modal-row-stock-override[data-row="${idx}"]`);

        let customer = sel.value;
        if (customer === 'OTHER') customer = otherEl.value.trim();

        let ref_no = refEl.value.trim();
        if (stockEl?.checked) {
            ref_no = 'STOCK';
        }

        const parsedCopies = parseInt(copiesEl.value, 10);
        const copies = isNaN(parsedCopies) ? 2 : parsedCopies;
        const length = parseFloat(lengthEl.value);

        if (!customer) { modalSetRowStatus(idx, 'Select customer', true); hasError = true; return; }
        if (!ref_no)   { modalSetRowStatus(idx, 'Ref No required', true);   hasError = true; return; }
        if (!stockEl?.checked && !modalRefNoMatchesActiveRuleForRow(idx)) {
            modalSetRowStatus(idx, 'Format invalid', true);
            hasError = true;
            return;
        }
        if (isNaN(length) || length <= 0) { modalSetRowStatus(idx, 'Length must be > 0', true); hasError = true; return; }

        modalSetRowStatus(idx, copies === 0 ? 'Saved (skip print)' : 'Ready', false);
        selections.push({
            id:                    tr.dataset.id,
            customer:              customer,
            ref_no:                ref_no,
            copies:                copies,
            length:                length,
            nci_resolved_customer: refEl.dataset.nciResolvedCustomer || '',
        });
    });

    return hasError ? null : selections;
}

async function modalSaveAllRows() {
    const feedback = document.getElementById('modalSaveAllFeedback');
    const selections = modalCollectSelections();
    if (!selections) {
        feedback.innerHTML = '<span class="text-danger fw-bold"><i class="bi bi-exclamation-circle me-1"></i>Fix highlighted row errors first.</span>';
        return;
    }

    const btn = document.getElementById('modalSaveAllBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';

    try {
        const resp = await fetch('batch_setup_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ selections }),
        });
        const result = await resp.json();
        if (result.ok) {
            feedback.innerHTML = `<span class="text-success fw-bold"><i class="bi bi-check-circle me-1"></i>${result.msg} Reloading page...</span>`;
            setTimeout(() => {
                const params = new URLSearchParams(window.location.search);
                params.set('success', 'stock');
                params.delete('edit');
                window.location.search = params.toString();
            }, 600);
        } else {
            feedback.innerHTML = `<span class="text-danger fw-bold"><i class="bi bi-exclamation-circle me-1"></i>${result.msg}</span>`;
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-floppy-fill me-1"></i> Save All to Stock';
        }
    } catch (e) {
        feedback.innerHTML = '<span class="text-danger fw-bold">Network error while saving.</span>';
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-floppy-fill me-1"></i> Save All to Stock';
    }
}

async function modalPrintAllStickers() {
    const feedback = document.getElementById('modalSaveAllFeedback');
    const selections = modalCollectSelections();
    if (!selections) {
        if (feedback) {
            feedback.innerHTML = '<span class="text-danger fw-bold"><i class="bi bi-exclamation-circle me-1"></i>Fix highlighted row errors before printing.</span>';
        }
        return;
    }

    const trs = Array.from(document.querySelectorAll('#modalBatchGridTable tbody tr'));
    const alreadyPrinted = [];
    selections.forEach((sel, idx) => {
        if (sel.copies > 0) {
            const tr = trs[idx];
            if (tr && tr.dataset.isPrinted === '1') {
                const rollLabel = tr.querySelector('strong')?.textContent?.trim() || `Roll #${sel.id}`;
                alreadyPrinted.push(`${rollLabel} (printed ${tr.dataset.printCount}×)`);
            }
        }
    });

    if (alreadyPrinted.length > 0) {
        const proceed = confirm(
            `${alreadyPrinted.length} roll(s) in this batch were already printed:\n\n` +
            alreadyPrinted.join('\n') +
            `\n\nPrint again anyway?`
        );
        if (!proceed) return;
    }

    if (feedback) {
        feedback.innerHTML = '<span class="text-primary fw-bold"><span class="spinner-border spinner-border-sm me-1"></span> Saving all updates to stock inventory before printing…</span>';
    }

    try {
        const saveResp = await fetch('batch_setup_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ selections }),
        });
        const saveResult = await saveResp.json();
        if (!saveResult.ok) {
            if (feedback) {
                feedback.innerHTML = `<span class="text-danger fw-bold"><i class="bi bi-exclamation-circle me-1"></i>Save failed: ${saveResult.msg}</span>`;
            }
            return;
        }
    } catch (e) {
        if (feedback) {
            feedback.innerHTML = '<span class="text-danger fw-bold">Network error saving to stock prior to print.</span>';
        }
        return;
    }

    if (feedback) {
        feedback.innerHTML = '<span class="text-success fw-bold"><i class="bi bi-check-circle me-1"></i>Saved to stock! Opening batch print view…</span>';
    }

    document.getElementById('modalBatchPrintSelectionsInput').value = JSON.stringify(selections);
    document.getElementById('modalBatchPrintForm').submit();
}

// Single roll quick sync behavior
const inputLength = document.getElementById('actualLengthInput');
const inputCoil   = document.getElementById('coilNoInput');
const inputLot    = document.getElementById('lotNoInput');
const btnSave     = document.getElementById('saveStockBtn');
const syncNote    = document.getElementById('syncNote');
const correctionNote = document.getElementById('correctionNote');
const isCompleted = inputLength ? inputLength.dataset.isCompleted === '1' : false;

function findMatchingRows() {
    if (!inputLength) return [];
    const product = inputLength.dataset.product;
    const lot      = inputLength.dataset.lot;
    const selfId   = inputLength.dataset.selfId;
    return Array.from(document.querySelectorAll('tr[data-id]')).filter(tr =>
        tr.dataset.id !== selfId &&
        tr.dataset.product === product &&
        tr.dataset.lot === lot
    );
}

function refreshSaveButton() {
    if (!inputLength || !btnSave) return;
    btnSave.disabled = (inputLength.value === "" || parseFloat(inputLength.value) <= 0);
}

if (isCompleted && correctionNote) {
    correctionNote.style.display = 'block';
}

if (inputLength) {
    inputLength.addEventListener('input', () => {
        refreshSaveButton();
        if (!isCompleted) {
            const matches = findMatchingRows();
            if (syncNote) syncNote.style.display = matches.length > 0 ? 'inline' : 'none';
            matches.forEach(tr => {
                const cell = document.getElementById('actual-display-' + tr.dataset.id);
                if (cell) cell.textContent = inputLength.value;
            });
        }
    });
}

if (inputCoil) inputCoil.addEventListener('input', refreshSaveButton);
if (inputLot) inputLot.addEventListener('input', refreshSaveButton);
refreshSaveButton();
</script>
<?php endif; ?>

<!-- Manual Entry Modal -->
<!-- ═══ NOTICE OF DEFECT (NOD) MODAL ══════════════════════════════ -->
<div class="modal fade" id="nodModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" id="nodForm">
            <input type="hidden" name="action" value="save_nod">
            <input type="hidden" name="id" id="nod_id">
            <input type="hidden" name="month"  value="<?= $month ?>">
            <input type="hidden" name="year"   value="<?= $year ?>">
            <input type="hidden" name="day"    value="<?= $day ?>">
            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter_card) ?>">
            <div class="modal-header bg-warning">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Notice of Defect</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2"><strong>Roll:</strong> <span id="nod_roll_label">-</span></p>
                <p class="mb-3"><strong>Actual Length:</strong> <span id="nod_actual_label">-</span> m</p>
                <div class="mb-2">
                    <label class="form-label fw-bold">NOD Length (m)</label>
                    <input type="number" step="0.01" min="0" name="nod_length" id="nod_length_input"
                           class="form-control" placeholder="Leave blank to clear an existing NOD">
                    <div class="form-text">Optional. Leave blank and save to remove an existing NOD from this roll.</div>
                </div>
                <div class="alert alert-warning py-2 mb-0" id="nod_preview" style="display:none;">
                    Resulting length: <strong id="nod_preview_value">-</strong> m
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-danger me-auto d-none" id="nod_remove_btn"
                        onclick="if(confirm('Remove the NOD from this roll? This restores it to a normal roll.')){ document.getElementById('nod_length_input').value=''; document.getElementById('nodForm').submit(); }">
                    <i class="bi bi-x-circle me-1"></i> Remove NOD
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-warning">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Notice of Defect (NOD) modal ─────────────────────────────
// Kept in its own <script> tag, separate from other page scripts,
// so it can't be silently disabled if unrelated code elsewhere on
// the page throws an error first.
(function () {
    const nodModalEl   = document.getElementById('nodModal');
    const nodIdInput   = document.getElementById('nod_id');
    const nodRollLabel = document.getElementById('nod_roll_label');
    const nodActualLbl = document.getElementById('nod_actual_label');
    const nodLengthIn  = document.getElementById('nod_length_input');
    const nodPreview   = document.getElementById('nod_preview');
    const nodPreviewVal= document.getElementById('nod_preview_value');
    const nodRemoveBtn = document.getElementById('nod_remove_btn');
    if (!nodModalEl || !nodLengthIn) return;

    let currentActual = 0;

    function updatePreview() {
        const nodVal = parseFloat(nodLengthIn.value);
        if (!isNaN(nodVal) && nodVal > 0) {
            const result = currentActual - nodVal;
            if (nodPreviewVal) nodPreviewVal.textContent = result.toFixed(2);
            if (nodPreview) nodPreview.style.display = 'block';
        } else {
            if (nodPreview) nodPreview.style.display = 'none';
        }
    }
    nodLengthIn.addEventListener('input', updatePreview);

    document.body.addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-nod');
        if (!btn) return;

        currentActual = parseFloat(btn.dataset.actual) || 0;
        const existingNod = btn.dataset.nod || '';

        if (nodIdInput)   nodIdInput.value = btn.dataset.id;
        if (nodRollLabel) nodRollLabel.textContent = btn.dataset.label || '-';
        if (nodActualLbl) nodActualLbl.textContent = currentActual.toFixed(2);
        nodLengthIn.value = existingNod;
        updatePreview();

        // Only offer "Remove NOD" when this roll actually has one already
        if (nodRemoveBtn) nodRemoveBtn.classList.toggle('d-none', existingNod === '');

        if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            console.error('Bootstrap JS is not loaded — cannot open the NOD modal.');
            return;
        }
        bootstrap.Modal.getOrCreateInstance(nodModalEl).show();
    });
})();
</script>

<div class="modal fade" id="manualEntryModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header">
            <h5>Manual Entry</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form method="post" action="scan_product_action.php">
            <div class="modal-body">
                <input type="text" class="form-control" name="qr"
                       placeholder="LOT;COIL;ROLL" required>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Submit</button>
            </div>
        </form>
    </div></div>
</div>

<!-- =============================================================
     Initial Stock Setup Modal — one-time legacy migration tool.
     Submits to initial_stock_setup_add.php (separate file).

     Field-unlock behavior now mirrors Add Mother Coil (#addMotherModal):
       Lot No (4–8 alphanumeric, validated live)
         -> unlocks Coil No
       Coil No entered + blurred
         -> AJAX lookup against mother_coil.php?ajax=get_product
            -> single match  : Product auto-filled (readonly), rest unlocks
            -> multiple match: Product <select> shown, rest unlocks once chosen
            -> no match      : BLOCKED — Coil No must match a known product,
                                same strict behavior as Add Mother Coil.
         -> unlocks Roll No, Width, Actual + enables Save button

     Date In: not a visible field — always set to "now" server-side
     in initial_stock_setup_add.php.

     Length: not a visible field — backend sets it equal to Actual.
   ============================================================= -->
<div class="modal fade" id="initialStockSetupModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form action="initial_stock_setup_add.php" method="POST" id="initialStockSetupForm">

        <div class="modal-header bg-warning-subtle">
          <h5 class="modal-title">
            <i class="bi bi-exclamation-triangle"></i>
            Initial Stock Setup &mdash; Legacy Product Entry
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">

          <div class="alert alert-warning small mb-3">
            <strong>One-time migration tool.</strong> Use this only to register
            physical stock that already exists in the warehouse from old mother
            coils not in this system. This bypasses normal mother coil
            traceability &mdash; do not use for new production.
          </div>

          <!-- Lot No (first — unlocks Coil No) -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Lot No <span class="text-danger">*</span></label>
            <input type="text" name="lot_no" id="isu_lot_no" class="form-control" required
                   maxlength="8" pattern="^[a-zA-Z0-9]{4,8}$"
                   title="4–8 alphanumeric characters"
                   placeholder="e.g. 5001">
            <div class="form-text">4–8 characters, letters and numbers only.</div>
          </div>

          <!-- Coil No (unlocks after Lot No) -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Coil No <span class="text-danger">*</span></label>
            <input type="text" name="coil_no" id="isu_coil_no" class="form-control" required
                   disabled placeholder="Enter coil number">
            <div class="form-text" id="isu_coil_hint">Enter coil number then click elsewhere to look up product.</div>
          </div>

          <!-- Product display (auto-filled or chosen) -->
          <div class="mb-3" id="isu_product_wrap">
            <label class="form-label fw-semibold">Product <span class="text-danger">*</span></label>

            <!-- CASE 1: single match → readonly display, hidden input carries value -->
            <div id="isu_product_auto_wrap">
              <input type="text" id="isu_product_display" class="form-control"
                     readonly placeholder="Will auto-fill after Coil No is entered">
            </div>

            <!-- CASE 2: multiple matches → dropdown -->
            <div id="isu_product_select_wrap" class="d-none">
              <div class="small text-info mb-1">
                <i class="bi bi-info-circle"></i>
                Multiple products found — please select one
              </div>
              <select id="isu_product_select" class="form-select" required>
                <option value="">-- Select Product --</option>
              </select>
            </div>

            <!-- Hidden input actually submitted to the backend -->
            <input type="hidden" name="product" id="isu_product_hidden">
          </div>

          <div class="row g-3">

            <div class="col-md-4">
              <label class="form-label">Roll No <span class="text-danger">*</span></label>
              <input type="text" name="roll_no" id="isu_roll_no" class="form-control" required disabled placeholder="e.g. R1">
            </div>

            <div class="col-md-4">
              <label class="form-label">Width (mm) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="width" id="isu_width" class="form-control" required disabled>
            </div>

            <div class="col-md-4">
              <label class="form-label">Actual (Weight/Value) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="actual_length" id="isu_actual_length" class="form-control" required disabled>
              <div class="form-text">This is also saved as the roll's Length automatically.</div>
            </div>

          </div>

          <div class="mt-3 small text-muted">
            <strong>Auto-applied on save (not editable here):</strong>
            Date In = <code>now</code>,
            Length = <code>same as Actual</code>,
            Source = <code>stock</code>,
            Original Source = <code>initial_stock</code>,
            Mother Coil = <code>none (NULL)</code>,
            Status = <code>IN</code>.
            A QR code will be generated automatically using your Lot/Roll info.
          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning" id="isu_submit_btn" disabled>
            <i class="bi bi-save"></i> Register Legacy Stock
          </button>
        </div>

      </form>
    </div>
  </div>
</div>
<script>
(function () {
    const lotInput      = document.getElementById('isu_lot_no');
    const coilInput     = document.getElementById('isu_coil_no');
    const coilHint      = document.getElementById('isu_coil_hint');

    const productAutoWrap   = document.getElementById('isu_product_auto_wrap');
    const productSelectWrap = document.getElementById('isu_product_select_wrap');
    const productDisplay    = document.getElementById('isu_product_display');
    const productSelect     = document.getElementById('isu_product_select');
    const productHidden     = document.getElementById('isu_product_hidden');

    const rollInput   = document.getElementById('isu_roll_no');
    const widthInput  = document.getElementById('isu_width');
    const actualInput = document.getElementById('isu_actual_length');
    const submitBtn    = document.getElementById('isu_submit_btn');

    const lotPattern = /^[a-zA-Z0-9]{4,8}$/;

    function lockDownstreamFields() {
        [rollInput, widthInput, actualInput].forEach(el => { el.disabled = true; });
        submitBtn.disabled = true;
        productHidden.value = '';
        productDisplay.value = '';
        productDisplay.placeholder = 'Will auto-fill after Coil No is entered';
        productSelectWrap.classList.add('d-none');
        productSelect.disabled = true;   // FIX: hidden required <select> must be disabled
                                          // or it silently blocks native form submission
        productAutoWrap.classList.remove('d-none');
    }

    // ── Step 1: Lot No format check unlocks Coil No ──────────
    lotInput.addEventListener('input', function () {
        const valid = lotPattern.test(this.value.trim());
        coilInput.disabled = !valid;
        if (!valid) {
            coilInput.value = '';
            lockDownstreamFields();
        }
    });

    // ── Step 2: Coil No blur triggers product lookup ─────────
    coilInput.addEventListener('blur', function () {
        const coil = this.value.trim();
        lockDownstreamFields();

        if (coil === '') return;

        coilHint.textContent = 'Looking up product...';

        fetch('mother_coil.php?ajax=get_product&coil=' + encodeURIComponent(coil))
            .then(res => res.json())
            .then(data => {
                if (!data.ok || !data.products || data.products.length === 0) {
                    coilHint.textContent = 'No matching product found for this Coil No. Cannot proceed — please verify the Coil No.';
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Coil No Not Recognized',
                            text: 'This Coil No does not match any known product. Initial Stock Setup requires a valid Coil No.'
                        });
                    }
                    return;
                }

                if (data.products.length === 1) {
                    // Single match — auto-fill, readonly
                    productAutoWrap.classList.remove('d-none');
                    productSelectWrap.classList.add('d-none');
                    productSelect.disabled = true;  // FIX
                    productDisplay.value = data.products[0];
                    productHidden.value  = data.products[0];
                    coilHint.textContent = 'Product matched automatically.';
                    unlockRemainingFields();
                } else {
                    // Multiple matches — show dropdown, wait for user choice
                    productAutoWrap.classList.add('d-none');
                    productSelectWrap.classList.remove('d-none');
                    productSelect.disabled = false;  // FIX: re-enable, it's now in use
                    productSelect.innerHTML = '<option value="">-- Select Product --</option>' +
                        data.products.map(p => `<option value="${p}">${p}</option>`).join('');
                    coilHint.textContent = 'Multiple products found — please select one above.';
                    // Remaining fields unlock only once a product is chosen below.
                }
            })
            .catch(() => {
                coilHint.textContent = 'Lookup failed — check your connection and try again.';
            });
    });

    productSelect.addEventListener('change', function () {
        if (this.value) {
            productHidden.value = this.value;
            unlockRemainingFields();
        } else {
            productHidden.value = '';
            lockDownstreamFieldsKeepCoil();
        }
    });

    function unlockRemainingFields() {
        [rollInput, widthInput, actualInput].forEach(el => { el.disabled = false; });
        submitBtn.disabled = false;
    }

    function lockDownstreamFieldsKeepCoil() {
        [rollInput, widthInput, actualInput].forEach(el => { el.disabled = true; });
        submitBtn.disabled = true;
    }

    document.getElementById('initialStockSetupForm').addEventListener('submit', function (e) {
        const form = this;

        if (!productHidden.value) {
            e.preventDefault();
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'warning', title: 'Product Not Resolved', text: 'Please enter a valid Coil No so the Product can be matched before saving.' });
            }
            return;
        }

        if (form.dataset.confirmed === 'true') {
            return; // already confirmed -> let it submit normally
        }

        e.preventDefault();

        if (typeof Swal === 'undefined') {
            form.submit();
            return;
        }

        Swal.fire({
            title: 'Confirm Legacy Stock Entry',
            text: 'This will bypass mother coil tracking and create the product directly. Continue?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, register it',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                form.dataset.confirmed = 'true';
                form.submit();
            }
        });
    });

    // Reset the whole modal state every time it's reopened
    document.getElementById('initialStockSetupModal').addEventListener('hidden.bs.modal', function () {
        lotInput.value = '';
        coilInput.value = '';
        coilInput.disabled = true;
        coilHint.textContent = 'Enter coil number then click elsewhere to look up product.';
        rollInput.value = '';
        widthInput.value = '';
        actualInput.value = '';
        lockDownstreamFields();
        document.getElementById('initialStockSetupForm').dataset.confirmed = 'false';
    });
})();
</script>

<!-- =============================================================
     Initial Stock Setup — Excel Bulk Import Modal
     Submits to initial_stock_setup_import.php.
     Sits ALONGSIDE the manual single-entry form above (both
     available), per request. Partial-import mode: rows that
     pass validation are saved; rows that fail are skipped and
     reported, they do not block the rows that succeeded.
   ============================================================= -->
<div class="modal fade" id="isuBulkImportModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form action="initial_stock_setup_import.php" method="POST" enctype="multipart/form-data">

        <div class="modal-header bg-success-subtle">
          <h5 class="modal-title">
            <i class="bi bi-file-earmark-spreadsheet"></i>
            Initial Stock Setup &mdash; Bulk Excel Import
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">

          <div class="alert alert-info small mb-3">
            Your Excel file must have these column headers (any order):
            <code>Item number</code>, <code>Batch number</code>, <code>Available physical</code>.
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Excel File <span class="text-danger">*</span></label>
            <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls,.csv" required>
            <div class="form-text">.xlsx, .xls, or .csv</div>
          </div>

          <div class="small text-muted">
            Rows that fail validation (bad format, unrecognized Coil No, duplicates)
            will be <strong>skipped and listed in a report</strong> &mdash; valid rows
            in the same file are still imported.
          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-upload"></i> Upload &amp; Import
          </button>
        </div>

      </form>
    </div>
  </div>
</div>

<script>
initCameraScanner({
    onScan: function(decodedText) {
        document.getElementById('qrInputProduct').value = decodedText;
        document.getElementById('scanFormProduct').submit();
    }
});

// ── Tooltips ────────────────────────────────────────────────
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
tooltipTriggerList.map(el => new bootstrap.Tooltip(el));


// ── SweetAlert2 scan result notifications ──────────────────
(function () {
    const params = new URLSearchParams(window.location.search);
    const scan   = params.get('scan');
    const reason = params.get('reason') ?? '';
    const pid    = params.get('pid')    ?? '';

    if (!scan) return;

    function escHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    const alerts = {
        'waiting':          { icon:'success', title:'Sent to QC ✓', text:'Product moved to QC Waiting.', timer:2500, showConfirmButton:false },
        'delivered':        { icon:'success', title:'Delivered ✓',  text:'Product marked as Delivered.',  timer:2500, showConfirmButton:false },
        'blocked_qc':       { icon:'error',   title:'Cannot Send to QC',
                              html:'<p>Current blocking reason: <strong style="color:#dc3545">' + escHtml(reason) + '</strong></p>' + (pid?'<p style="font-size:11px">Roll ID: #'+escHtml(pid)+'</p>':''),
                              confirmButtonText:'Understood', confirmButtonColor:'#dc3545' },
        'rejected_blocked': { icon:'warning', title:'Product is Rejected',
                              html:'<p>Send it to <strong>Reslit</strong> or <strong>Recoil</strong> before scanning again.</p>',
                              confirmButtonText:'OK', confirmButtonColor:'#f59e0b' },
        'not_stock':        { icon:'warning', title:'Not in Finish Good Yet',
                              html:'<p>Actual length not recorded. Click <strong>Update</strong> first.</p>',
                              confirmButtonText:'OK' },
        'no_pallet':        { icon:'info',    title:'No Pallet Assigned',
                              html:'<p>This roll is ready but not on a pallet yet.</p><p>Go to <strong>Pallet Management</strong> to create or open a pallet and scan this roll in.</p>',
                              confirmButtonText:'Go to Pallets',
                              preConfirm: () => { window.location.href = 'pallet.php'; } },
        'already_waiting':  { icon:'info',   title:'Already in QC Queue', text:'Already waiting for QC approval.', timer:2500, showConfirmButton:false },
        'already_delivered':{ icon:'info',   title:'Already Delivered',   text:'This product has already been delivered.', timer:2500, showConfirmButton:false },
        'voided':           { icon:'error',  title:'Roll is Voided',       text:'This roll has been voided.',             confirmButtonColor:'#dc3545' },
        'notfound':         { icon:'error',  title:'Product Not Found',    html:'<p>No matching product found.</p>',       confirmButtonColor:'#dc3545' },
        'invalid':          { icon:'error',  title:'Invalid QR Code',      text:'Expected format: LOT;COIL;ROLL',          confirmButtonColor:'#dc3545' },
        'empty':            { icon:'warning',title:'Nothing Scanned',      text:'Empty scan. Please try again.',           timer:2000, showConfirmButton:false },
    };

    const cfg = alerts[scan];
    if (!cfg) return;

    if (typeof Swal !== 'undefined') {
        Swal.fire(cfg).then(() => { if (qIn) qIn.focus(); });
    }

    const cleanUrl = window.location.pathname
        + '?month=' + (params.get('month') ?? '')
        + '&year='  + (params.get('year')  ?? '')
        + (params.get('filter') ? '&filter=' + encodeURIComponent(params.get('filter')) : '');
    window.history.replaceState({}, '', cleanUrl);
})();

// ── Mixed Bulk Print: selection across any product/coil ─────────────
function toggleSelectAllMixedPrint(cb) {
    document.querySelectorAll('.mixed-print-select').forEach(el => el.checked = cb.checked);
    updateMixedBulkPrintButton();
}

function updateMixedBulkPrintButton() {
    const boxes   = document.querySelectorAll('.mixed-print-select');
    const checked = document.querySelectorAll('.mixed-print-select:checked');

    document.getElementById('mixedBulkPrintCount').textContent = `${checked.length} selected`;
    document.getElementById('mixedBulkPrintBtn').disabled = checked.length === 0;

    const selectAll = document.getElementById('selectAllMixedPrint');
    if (selectAll) {
        selectAll.checked       = boxes.length > 0 && checked.length === boxes.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
    }
}

// Customer/Ref No are no longer collected here in one shared modal —
// they're set per roll on the Mixed Batch Setup page instead. This just
// forwards the checked IDs there.
function goToMixedBatchSetup() {
    const checked = document.querySelectorAll('.mixed-print-select:checked');
    if (checked.length === 0) {
        alert('Select at least one roll to bulk print.');
        return;
    }

    const ids = Array.from(checked).map(el => el.value);
    document.getElementById('mixedBulkPrintIdsInput').value = JSON.stringify(ids);
    document.getElementById('mixedBulkPrintForm').submit();
}

// ── Set Customer for whole Mother Coil batch ─────────────────────────
// Reuses the same mixed_batch_setup.php pipeline as "Bulk Print
// Selected" — the button's data-batch-ids already carries every
// sibling roll id (same mother_id, filtered for voided/recoiled/
// reslitted), so this is just a one-click version of checking those
// boxes and hitting Bulk Print. Works the same whether the batch is
// one roll or many — mixed_batch_setup.php already handles per-roll
// Customer/Ref No entry for any batch size.
function setCustomerForBatch(btn) {
    let ids = [];
    try {
        ids = JSON.parse(btn.dataset.batchIds || '[]');
    } catch (e) {
        ids = [];
    }
    if (!ids.length) {
        alert('No eligible rolls found for this Mother Coil batch.');
        return;
    }
    document.getElementById('mixedBulkPrintIdsInput').value = JSON.stringify(ids);
    document.getElementById('mixedBulkPrintForm').submit();
}

function openSendToSfcModal(id, product, lotNo, coilNo, rollNo, width, length) {
    document.getElementById('sfcModalProductId').value = id;
    document.getElementById('sfcModalProduct').textContent = product || '-';
    document.getElementById('sfcModalLotNo').textContent = lotNo || '-';
    document.getElementById('sfcModalCoilNo').textContent = coilNo || '-';
    document.getElementById('sfcModalRollNo').textContent = rollNo ? rollNo.replace('R','R-') : '-';
    document.getElementById('sfcModalWidth').textContent = width || '-';
    document.getElementById('sfcModalLength').textContent = length || '-';

    const modalEl = document.getElementById('sendToSfcModal');
    if (modalEl) {
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.show();
        } else if (typeof $ !== 'undefined' && $.fn && $.fn.modal) {
            $(modalEl).modal('show');
        }
    }
}
</script>

<!-- ── CONFIRM SEND TO SFC MODAL ──────────────────────────────── -->
<div class="modal fade" id="sendToSfcModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow border-0">
      <div class="modal-header bg-primary text-white py-2">
        <h5 class="modal-title fs-6 fw-bold">
          <i class="bi bi-box-seam me-2"></i>Confirm Send to SFC Inventory
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="finish_product.php">
        <div class="modal-body py-3">
          <input type="hidden" name="action" value="send_to_sfc">
          <input type="hidden" name="product_id" id="sfcModalProductId" value="">
          <input type="hidden" name="month" value="<?= $month ?>">
          <input type="hidden" name="year" value="<?= $year ?>">
          <input type="hidden" name="day" value="<?= $day ?>">
          <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
          <input type="hidden" name="filter" value="<?= htmlspecialchars($filter_card) ?>">

          <div class="alert alert-primary py-2 mb-3 small d-flex align-items-center gap-2" style="background:#e0f2fe; color:#0369a1; border:1px solid #7dd3fc;">
            <i class="bi bi-question-circle-fill fs-4 text-primary flex-shrink-0"></i>
            <div>
              Are you sure you want to send this coil to <strong>SFC Inventory</strong>?
            </div>
          </div>

          <div class="card bg-light border p-3 mb-2" style="font-size:13px;">
            <div class="row g-2">
              <div class="col-6"><strong>Product:</strong> <span id="sfcModalProduct" class="fw-bold text-dark">-</span></div>
              <div class="col-6"><strong>Lot No:</strong> <span id="sfcModalLotNo" class="fw-bold text-dark">-</span></div>
              <div class="col-6"><strong>Coil No:</strong> <span id="sfcModalCoilNo" class="fw-bold text-dark">-</span></div>
              <div class="col-6"><strong>Roll No:</strong> <span id="sfcModalRollNo" class="fw-bold text-dark">-</span></div>
              <div class="col-6"><strong>Width:</strong> <span id="sfcModalWidth" class="fw-bold text-dark">-</span> mm</div>
              <div class="col-6"><strong>Length:</strong> <span id="sfcModalLength" class="fw-bold text-dark">-</span> m</div>
            </div>
          </div>
        </div>
        <div class="modal-footer py-2 px-3 bg-light">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm fw-bold">
            <i class="bi bi-box-seam me-1"></i>Confirm Send to SFC
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<div><a href="index.php" class="btn btn-secondary mt-3">← Back</a></div>
<?php include dirname(__DIR__, 3) . '/footer.php'; ?>
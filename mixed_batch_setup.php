<?php
// mixed_batch_setup.php
// ============================================================
// Mixed Batch Setup — the checkbox-driven counterpart to
// batch_setup.php, but for an arbitrary mix of products/coils
// instead of one Lot+Coil group.
//
// Entry point: finish_product.php's "Bulk Print Selected" button now
// POSTs the checked slitting_product IDs here (same `ids` JSON-array
// contract mixed_batch_print_action.php always accepted) instead of
// opening a modal with one shared Customer/Ref No pair. GET with a
// comma-separated `ids` list is also accepted for simple link-based
// integrations.
//
// Shows every printable roll in a grid: Product/Lot/Coil/Roll
// (read-only), Customer + Ref No (editable inline per row, prefilled
// from whatever is already saved on that roll). "Apply to All Rows"
// quick-fills Customer/Ref No across every row as a convenience —
// individual rows can still be overridden afterward.
//
// "Print All Stickers" submits the per-row selections to
// mixed_batch_print_action.php, which saves each roll's own
// Customer/Ref No (no more overwriting everything with one global
// value) and then runs the same consolidated print job as before.
//
// NCI MFG / NCI 2 are no longer blanket-excluded: each row resolves
// its own part number via select_customer.php's ajax=nci_lookup,
// exactly like batch_setup.php does per row, since that lookup is
// already keyed off a single product's id/width and works fine one
// row at a time even inside a mixed batch.
// ============================================================

session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}
if ($_SESSION['role'] !== 'slitting') {
    die("Access denied");
}

include 'config.php';

// ── Parse the selected IDs (POST JSON preferred, GET CSV as fallback) ──
$rawIds = null;
if (isset($_POST['ids'])) {
    $rawIds = json_decode($_POST['ids'], true);
} elseif (isset($_GET['ids'])) {
    $rawIds = array_map('trim', explode(',', $_GET['ids']));
}

$ids = is_array($rawIds)
    ? array_values(array_unique(array_filter(array_map('intval', $rawIds))))
    : [];

// ── Preserve return state back to finish_product.php ─────────────
$backMonth  = isset($_REQUEST['month'])  ? (int)$_REQUEST['month']  : (int)date('m');
$backYear   = isset($_REQUEST['year'])   ? (int)$_REQUEST['year']   : (int)date('Y');
$backDay    = isset($_REQUEST['day'])    ? (int)$_REQUEST['day']    : 0;
$backSearch = trim($_REQUEST['search'] ?? '');
$backFilter = trim($_REQUEST['filter'] ?? '');
$backUrl = 'finish_product.php?' . http_build_query(array_filter([
    'month'  => $backMonth,
    'year'   => $backYear,
    'day'    => $backDay > 0 ? $backDay : null,
    'search' => $backSearch !== '' ? $backSearch : null,
    'filter' => $backFilter !== '' ? $backFilter : null,
]));

if (empty($ids)) {
    die('<p style="font-family:Arial;padding:24px;">No rolls were selected.</p>'
        . '<a href="' . htmlspecialchars($backUrl) . '">&larr; Back to List</a>');
}

// ── Fetch every printable roll among the selected IDs ─────────────
// Same printability rule finish_product.php / batch_setup.php use:
// not voided, not WAITING/REJECTED, and not an IN roll still pending
// Actual Length or already palletised.
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $conn->prepare("
    SELECT sp.id, sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
           sp.width, sp.length, sp.actual_length,
           sp.status, sp.is_completed, sp.customer_name, sp.ref_no,
           sp.is_printed, sp.print_count, sp.last_printed_at, sp.last_printed_by,
           pi.pallet_id
    FROM slitting_product sp
    LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
    WHERE sp.id IN ($placeholders)
      AND (sp.is_voided = 0 OR sp.is_voided IS NULL)
");
$stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
$stmt->execute();
$allRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$rolls  = [];
$errors = [];
$foundIds = [];

foreach ($allRows as $r) {
    $foundIds[] = (int)$r['id'];

    $isPrintable = !in_array($r['status'], ['WAITING', 'REJECTED'], true)
        && !($r['status'] === 'IN' && ($r['is_completed'] == 0 || $r['pallet_id']));

    if (!$isPrintable) {
        $reason = $r['status'] === 'IN'
            ? ($r['pallet_id'] ? 'already palletised' : 'pending Actual Length')
            : ('status ' . $r['status']);
        $errors[] = "Roll #{$r['id']} ({$r['product']} {$r['lot_no']} {$r['coil_no']}): skipped — {$reason}.";
        continue;
    }

    $rolls[] = $r;
}

$missingIds = array_diff($ids, $foundIds);
foreach ($missingIds as $mid) {
    $errors[] = "Roll #{$mid}: not found (or voided).";
}

// Keep rolls in a stable, readable order — grouped by product/lot/coil
// so a mixed batch doesn't read as a random shuffle.
usort($rolls, function ($a, $b) {
    return [$a['product'], $a['lot_no'], $a['coil_no'], $a['roll_no']]
       <=> [$b['product'], $b['lot_no'], $b['coil_no'], $b['roll_no']];
});
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Mixed Batch Setup — <?= count($rolls) ?> roll(s)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f5f6f8; padding-bottom: 90px; }
        .page-header {
            background: #212529; color: #fff; padding: 16px 20px; margin-bottom: 16px;
        }
        .page-header h4 { margin: 0; }
        .page-header .sub { font-size: 13px; color: #ccc; margin-top: 2px; }
        .container-narrow { max-width: 1100px; margin: 0 auto; padding: 0 16px; }
        .copy-all-card { border: 1px solid #f5c2c7; background: #fff5f5; }
        .grid-table th { white-space: nowrap; }
        .grid-table td { vertical-align: middle; }
        .readonly-cell { color: #555; font-size: 13px; }
        .row-status { font-size: 12px; }
        .nci-note { font-size: 12px; }
        .action-bar {
            position: fixed; bottom: 0; left: 0; right: 0; z-index: 20;
            background: #fff; border-top: 1px solid #ddd;
            padding: 12px 16px; display: flex; gap: 10px; justify-content: flex-end;
            flex-wrap: wrap;
        }
        .action-bar .btn { min-height: 44px; font-weight: 600; }
        #printAllFeedback { font-size: 13px; align-self: center; margin-right: auto; }
    </style>
</head>
<body>

<div class="page-header">
    <div class="container-narrow d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h4><i class="bi bi-printer-fill me-2"></i>Mixed Batch Setup</h4>
            <div class="sub">Mixed selection · <?= count($rolls) ?> roll(s) — set Customer &amp; Ref No individually per roll</div>
        </div>
        <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to List
        </a>
    </div>
</div>

<div class="container-narrow">

<?php if (!empty($errors)): ?>
    <div class="alert alert-warning py-2 mb-3">
        <strong>Skipped roll(s):</strong>
        <ul class="mb-0" style="font-size:13px;">
            <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (empty($rolls)): ?>
    <div class="alert alert-warning">
        No printable rolls among the selected items.
        <br><small>Rolls still pending Actual Length, already palletised, WAITING QC, or REJECTED are not shown here.</small>
    </div>
<?php else: ?>

    <!-- Apply to All Rows ──────────────────────────────────────── -->
    <div class="card copy-all-card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3">
                    <label class="small fw-bold mb-1">Actual Length (meters)</label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="copyAllActualLength" placeholder="e.g. 500">
                </div>
                <div class="col-12 col-md-3">
                    <label class="small fw-bold mb-1">Apply to All Rows — Customer</label>
                    <select class="form-select form-select-sm" id="copyAllCustomer">
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
                        <option value="NIP">NICHIAS IND.PRODUCTS PVT. LTD.</option>
                        <option value="NVC">NICHIAS VIETNAM CO., LTD</option>
                        <option value="NCS">NC-PT NICHIAS SUNIJAYA</option>
                        <option value="SNP">SUZHOU NICHIAS IND. PRODUCTS</option>
                        <option value="YTEC">YTEC CO., LTD.</option>
                        <option value="NSEA">NICHIAS SOUTH EAST ASIA (UP PACKING)</option>
                        <option value="NCI 2">NCI 2</option>
                        <option value="STOCK">STOCK</option>
                        <option value="TRIAL">TRIAL</option>
                        <option value="OTHER">OTHER (type below)</option>
                    </select>
                    <input type="text" class="form-control form-control-sm mt-1" id="copyAllCustomOther"
                           placeholder="Customer name (if OTHER)" style="display:none;">
                </div>
                <div class="col-8 col-md-3">
                    <label class="small fw-bold mb-1">Ref No</label>
                    <input type="text" class="form-control form-control-sm" id="copyAllRefNo" value="SO-" placeholder="SO-00-0000">
                </div>
                <div class="col-4 col-md-1">
                    <label class="small fw-bold mb-1">Copies</label>
                    <select class="form-select form-select-sm" id="copyAllCopies">
                        <option value="">—</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3" selected>3</option>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <button type="button" class="btn btn-outline-danger btn-sm w-100 fw-bold" onclick="applyToAllRows()">
                        <i class="bi bi-arrow-down-square me-1"></i> Apply to All
                    </button>
                </div>
            </div>
            <div class="form-text mb-0">Fills every row below with Customer, Ref No, Actual Length, and Copies — then adjust any row that differs. NCI MFG / NCI 2 rows still resolve their own Ref No individually.</div>
        </div>
    </div>

    <?php $alreadyPrintedCount = count(array_filter($rolls, fn($r) => !empty($r['is_printed']))); ?>
    <?php if ($alreadyPrintedCount > 0): ?>
    <div class="alert alert-warning py-2 mb-3">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        <strong><?= $alreadyPrintedCount ?> of <?= count($rolls) ?></strong> roll(s) in this batch were already printed before.
        Check the badges in the grid below before printing again.
    </div>
    <?php endif; ?>

    <!-- Mixed Batch Grid ───────────────────────────────────────── -->
    <div class="table-responsive">
        <table class="table table-bordered table-sm grid-table bg-white" id="batchGridTable">
            <thead class="table-dark">
                <tr>
                    <th style="width:20%;">Product / Coil / Roll</th>
                    <th style="width:16%;">Width / Actual Length</th>
                    <th style="width:25%;">Customer</th>
                    <th style="width:22%;">Ref No.</th>
                    <th style="width:8%;">Copies</th>
                    <th style="width:9%;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rolls as $idx => $r): ?>
                <tr data-id="<?= $r['id'] ?>" data-row="<?= $idx ?>"
                    data-is-printed="<?= !empty($r['is_printed']) ? 1 : 0 ?>"
                    data-print-count="<?= (int)($r['print_count'] ?? 0) ?>">
                    <td class="readonly-cell">
                        <strong><?= htmlspecialchars($r['product'] ?? ('Roll #' . $r['id'])) ?></strong>
                        <div class="text-muted">
                            <?= htmlspecialchars(trim(($r['lot_no'] ?? '') . ' ' . ($r['coil_no'] ?? ''))) ?>
                            · Roll <?= htmlspecialchars(str_replace('R', 'R-', $r['roll_no'] ?? '')) ?>
                        </div>
                        <?php if (!empty($r['is_printed'])): ?>
                            <span class="badge bg-success mt-1" style="font-size:10px;"
                                  title="Last printed <?= htmlspecialchars($r['last_printed_at'] ? date('d M Y H:i', strtotime($r['last_printed_at'])) : '') ?> by <?= htmlspecialchars($r['last_printed_by'] ?? '') ?>">
                                <i class="bi bi-printer-fill"></i> Printed (<?= (int)$r['print_count'] ?>×)
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary mt-1" style="font-size:10px;">Not Printed</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="fw-semibold"><?= number_format((float)($r['width'] ?? 0), 0) ?> mm</span>
                        <?php $curLength = (!empty($r['actual_length']) && $r['actual_length'] > 0) ? $r['actual_length'] : ($r['length'] ?? ''); ?>
                        <div class="input-group input-group-sm mt-1">
                            <input type="number" step="0.01" min="0"
                                   class="form-control form-control-sm row-length" data-row="<?= $idx ?>"
                                   value="<?= htmlspecialchars((string)$curLength) ?>">
                            <span class="input-group-text px-1" style="font-size:10px;">m</span>
                        </div>
                    </td>
                    <td>
                        <?php
                            $saved = trim($r['customer_name'] ?? '');
                            $knownCustomers = ['NAE','NAX','NCI MFG','TAIHO','NRI','ASHUKA','NIPPON','NTC','SGC','STAMPING','YANTAI','NIP','NVC','NCS','SNP','YTEC','NSEA','NCI 2','STOCK','TRIAL'];
                            $isOther = ($saved !== '' && !in_array($saved, $knownCustomers, true));

                            $rawRefNo = trim($r['ref_no'] ?? '');
                            $isStock = ($saved === 'STOCK' || $rawRefNo === 'STOCK');
                            $displayRefNo = $isStock ? 'STOCK' : ($rawRefNo !== '' ? $rawRefNo : 'SO-');
                        ?>
                        <select class="form-select form-select-sm row-customer" data-row="<?= $idx ?>"
                                onchange="handleRowCustomerChange(<?= $idx ?>)">
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
                            <option value="NIP"      <?= $saved==='NIP'      ?'selected':'' ?>>NICHIAS IND.PRODUCTS PVT. LTD.</option>
                            <option value="NVC"      <?= $saved==='NVC'      ?'selected':'' ?>>NICHIAS VIETNAM CO., LTD</option>
                            <option value="NCS"      <?= $saved==='NCS'      ?'selected':'' ?>>NC-PT NICHIAS SUNIJAYA</option>
                            <option value="SNP"      <?= $saved==='SNP'      ?'selected':'' ?>>SUZHOU NICHIAS IND. PRODUCTS</option>
                            <option value="YTEC"     <?= $saved==='YTEC'     ?'selected':'' ?>>YTEC CO., LTD.</option>
                            <option value="NSEA"     <?= $saved==='NSEA'     ?'selected':'' ?>>NICHIAS SOUTH EAST ASIA (UP PACKING)</option>
                            <option value="NCI 2"    <?= $saved==='NCI 2'    ?'selected':'' ?>>NCI 2</option>
                            <option value="STOCK"    <?= $saved==='STOCK'    ?'selected':'' ?>>STOCK</option>
                            <option value="TRIAL"    <?= $saved==='TRIAL'    ?'selected':'' ?>>TRIAL</option>
                            <option value="OTHER"    <?= $isOther            ?'selected':'' ?>>OTHER (type below)</option>
                        </select>
                        <input type="text" class="form-control form-control-sm row-custom-customer mt-1" data-row="<?= $idx ?>"
                               placeholder="Enter customer name" style="display:<?= $isOther?'block':'none' ?>;"
                               value="<?= $isOther ? htmlspecialchars($saved) : '' ?>">
                        <div class="text-muted nci-note mt-1" data-row="<?= $idx ?>" style="display:none;"></div>
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm row-refno" data-row="<?= $idx ?>"
                               value="<?= htmlspecialchars($displayRefNo) ?>" placeholder="SO-00-0000">
                    </td>
                    <td>
                        <select class="form-select form-select-sm row-copies" data-row="<?= $idx ?>">
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3" selected>3</option>
                        </select>
                    </td>
                    <td class="row-status text-muted" data-row="<?= $idx ?>">—</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>

</div>

<!-- Sticky bottom action bar ────────────────────────────────────── -->
<div class="action-bar">
    <span id="printAllFeedback"></span>
    <button type="button" class="btn btn-outline-dark" id="saveOnlyBtn" onclick="saveOnly()" <?= empty($rolls)?'disabled':'' ?>>
        <i class="bi bi-save me-1"></i> Save Only
    </button>
    <button type="button" class="btn btn-danger" id="printAllBtn" onclick="printAllStickers()" <?= empty($rolls)?'disabled':'' ?>>
        <i class="bi bi-printer-fill me-1"></i> Print All Stickers
    </button>
</div>

<!-- Hidden form used to POST the print job in a new tab -->
<form id="mixedPrintForm" method="post" action="mixed_batch_print_action.php" target="_blank" style="display:none;">
    <input type="hidden" name="selections" id="mixedPrintSelectionsInput">
    <input type="hidden" name="month"   value="<?= $backMonth ?>">
    <input type="hidden" name="year"    value="<?= $backYear ?>">
    <input type="hidden" name="day"     value="<?= $backDay ?>">
    <input type="hidden" name="search"  value="<?= htmlspecialchars($backSearch) ?>">
    <input type="hidden" name="filter"  value="<?= htmlspecialchars($backFilter) ?>">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const NCI_CUSTOMERS = ['NCI MFG', 'NCI 2'];

function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g,
        c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function getRowCount() {
    return document.querySelectorAll('#batchGridTable tbody tr').length;
}

// ── Row Customer <select> change → OTHER toggle + NCI auto-lookup ──
// Identical pattern to batch_setup.php: each row resolves its own
// part number independently, which is exactly what a mixed batch of
// different products/widths needs (a single shared value can't work
// for NCI MFG / NCI 2 here).
async function handleRowCustomerChange(rowIdx) {
    const sel      = document.querySelector(`.row-customer[data-row="${rowIdx}"]`);
    const otherEl  = document.querySelector(`.row-custom-customer[data-row="${rowIdx}"]`);
    const noteEl   = document.querySelector(`.nci-note[data-row="${rowIdx}"]`);
    const refEl    = document.querySelector(`.row-refno[data-row="${rowIdx}"]`);
    const tr       = document.querySelectorAll('#batchGridTable tbody tr')[rowIdx];
    const productId = tr ? tr.dataset.id : null;
    const val = sel.value;

    otherEl.style.display = (val === 'OTHER') ? 'block' : 'none';

    if (val === 'STOCK') {
        refEl.value = 'STOCK';
        noteEl.style.display = 'none';
        noteEl.innerHTML = '';
        return;
    }

    if (refEl.value === 'STOCK' || !refEl.value.trim()) {
        refEl.value = 'SO-';
    }

    if (!NCI_CUSTOMERS.includes(val)) {
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
            noteEl.innerHTML = `<span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>${escHtml(data.msg)} — fill Ref No manually.</span>`;
            return;
        }
        refEl.value = data.part_no_full;
        refEl.dataset.nciResolvedCustomer = data.customer_info;
        noteEl.innerHTML = `<i class="bi bi-check-circle me-1"></i>Customer on sticker: <strong>${escHtml(data.customer_info)}</strong>`;
    } catch (e) {
        noteEl.innerHTML = '<span class="text-danger">Network error during lookup.</span>';
    }
}

document.getElementById('copyAllCustomer')?.addEventListener('change', function () {
    document.getElementById('copyAllCustomOther').style.display =
        (this.value === 'OTHER') ? 'block' : 'none';
});

// ── Apply to All Rows (convenience only — every row stays editable) ──
async function applyToAllRows() {
    const sel       = document.getElementById('copyAllCustomer');
    const otherEl   = document.getElementById('copyAllCustomOther');
    const refEl     = document.getElementById('copyAllRefNo');
    const lengthEl  = document.getElementById('copyAllActualLength');
    const copiesEl  = document.getElementById('copyAllCopies');

    const customerVal = sel ? sel.value : '';
    const refVal      = refEl ? refEl.value.trim() : '';
    const lengthVal   = lengthEl ? lengthEl.value.trim() : '';
    const copiesVal   = copiesEl ? copiesEl.value : '';

    if (!customerVal && !lengthVal) { alert('Set Customer or Actual Length to apply to all rows.'); return; }
    if (customerVal === 'OTHER' && !otherEl.value.trim()) { alert('Enter the customer name.'); return; }

    const rowCount = getRowCount();
    for (let idx = 0; idx < rowCount; idx++) {
        const rowSel      = document.querySelector(`.row-customer[data-row="${idx}"]`);
        const rowOtherEl  = document.querySelector(`.row-custom-customer[data-row="${idx}"]`);
        const rowRefEl    = document.querySelector(`.row-refno[data-row="${idx}"]`);
        const rowLengthEl = document.querySelector(`.row-length[data-row="${idx}"]`);
        const rowCopiesEl = document.querySelector(`.row-copies[data-row="${idx}"]`);

        if (customerVal) {
            rowSel.value = customerVal;
            rowOtherEl.style.display = (customerVal === 'OTHER') ? 'block' : 'none';
            if (customerVal === 'OTHER') rowOtherEl.value = otherEl.value.trim();
            if (refVal !== '') rowRefEl.value = refVal;
        }

        if (lengthVal !== '' && rowLengthEl) {
            rowLengthEl.value = lengthVal;
        }

        if (copiesVal && rowCopiesEl) {
            rowCopiesEl.value = copiesVal;
        }

        // NCI rows still resolve their own Ref No per product/width —
        // a shared Ref No wouldn't be valid across a mixed batch.
        if (customerVal && NCI_CUSTOMERS.includes(customerVal)) {
            await handleRowCustomerChange(idx);
        }
    }
}

function setRowStatus(rowIdx, text, isError) {
    const el = document.querySelector(`.row-status[data-row="${rowIdx}"]`);
    if (!el) return;
    el.textContent = text;
    el.className = 'row-status ' + (isError ? 'text-danger' : 'text-success');
}

// ── Collect + validate every row into a selections[] payload ──────
function collectSelections() {
    const trs = Array.from(document.querySelectorAll('#batchGridTable tbody tr'));
    const selections = [];
    let hasError = false;

    trs.forEach((tr, idx) => {
        const sel      = document.querySelector(`.row-customer[data-row="${idx}"]`);
        const otherEl  = document.querySelector(`.row-custom-customer[data-row="${idx}"]`);
        const refEl    = document.querySelector(`.row-refno[data-row="${idx}"]`);
        const copiesEl = document.querySelector(`.row-copies[data-row="${idx}"]`);
        const lengthEl = document.querySelector(`.row-length[data-row="${idx}"]`);

        let customer = sel.value;
        if (customer === 'OTHER') customer = otherEl.value.trim();
        const ref_no = refEl.value.trim();
        const parsedCopies = parseInt(copiesEl.value, 10);
        const copies = (parsedCopies >= 1 && parsedCopies <= 3) ? parsedCopies : 3;
        const length = lengthEl ? parseFloat(lengthEl.value) : 0;

        if (!customer) { setRowStatus(idx, 'Select a customer', true); hasError = true; return; }
        if (!ref_no)   { setRowStatus(idx, 'Ref No required', true);   hasError = true; return; }
        if (isNaN(length) || length <= 0) { setRowStatus(idx, 'Length must be > 0', true); hasError = true; return; }

        setRowStatus(idx, `OK · ${copies}x`, false);
        selections.push({
            id:                    tr.dataset.id,
            customer:              customer,
            ref_no:                ref_no,
            length:                length,
            copies:                copies,
            nci_resolved_customer: refEl.dataset.nciResolvedCustomer || '',
        });
    });

    return hasError ? null : selections;
}

// ── Print All Stickers (saves each row's own values, then prints) ──
function printAllStickers() {
    const selections = collectSelections();
    if (!selections) {
        document.getElementById('printAllFeedback').innerHTML =
            '<span class="text-danger">Fix the highlighted rows before printing.</span>';
        return;
    }

    const trs = Array.from(document.querySelectorAll('#batchGridTable tbody tr'));
    const alreadyPrinted = [];
    selections.forEach((sel, idx) => {
        const tr = trs[idx];
        if (tr && tr.dataset.isPrinted === '1') {
            const rollLabel = tr.querySelector('.readonly-cell strong')?.textContent?.trim() || `Roll #${sel.id}`;
            alreadyPrinted.push(`${rollLabel} (printed ${tr.dataset.printCount}×)`);
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

    document.getElementById('mixedPrintSelectionsInput').value = JSON.stringify(selections);
    document.getElementById('mixedPrintForm').submit();
}

// ── Save Only (no printing) ───────────────────────────────────────
// Same per-row validation as Print All, but posts to the save-only
// endpoint via fetch instead of submitting a form — so the operator
// stays on this grid, sees a per-row "Saved" confirmation, and no
// print job / new tab is triggered. Nothing here touches is_printed
// or print_count; that's the whole point of this button existing.
async function saveOnly() {
    const selections = collectSelections();
    if (!selections) {
        document.getElementById('printAllFeedback').innerHTML =
            '<span class="text-danger">Fix the highlighted rows before saving.</span>';
        return;
    }

    const saveBtn = document.getElementById('saveOnlyBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Saving…';
    document.getElementById('printAllFeedback').textContent = '';

    try {
        const res  = await fetch('mixed_batch_save_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'selections=' + encodeURIComponent(JSON.stringify(selections)),
        });
        const data = await res.json();

        const trs = Array.from(document.querySelectorAll('#batchGridTable tbody tr'));
        if (data.ok) {
            const savedSet = new Set((data.saved || []).map(String));
            trs.forEach((tr, idx) => {
                if (savedSet.has(String(tr.dataset.id))) {
                    setRowStatus(idx, 'Saved ✓', false);
                }
            });
            const skipped = (data.errors || []).length;
            document.getElementById('printAllFeedback').innerHTML =
                `<span class="text-success"><i class="bi bi-check-circle me-1"></i>Saved ${data.saved.length} roll(s).</span>` +
                (skipped > 0 ? ` <span class="text-danger ms-2">${skipped} skipped — see below.</span>` : '');
            if (skipped > 0) {
                console.warn('Save Only — skipped rolls:', data.errors);
            }
        } else {
            document.getElementById('printAllFeedback').innerHTML =
                `<span class="text-danger">${escHtml(data.msg || 'Save failed.')}</span>`;
        }
    } catch (e) {
        document.getElementById('printAllFeedback').innerHTML =
            '<span class="text-danger">Network error while saving.</span>';
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-save me-1"></i> Save Only';
    }
}
</script>
</body>
</html>
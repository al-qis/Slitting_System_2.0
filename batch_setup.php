<?php
// batch_setup.php
// ============================================================
// Batch Setup & Print — dedicated page (not a modal).
//
// Entry point: finish_product.php's Lot No / Coil No filter links here
// with ?lot_no=...&coil_no=... once both are set.
//
// Shows every printable roll under that exact Lot + Coil combination in
// one grid: Roll No / Size (read-only), Customer + Ref No (editable
// inline per row). "Copy to All Rows" quick-fills Customer/Ref No across
// every row. "Save All" persists via AJAX without navigating away.
// "Print All Stickers" saves, then opens a consolidated print job
// (batch_print_action.php) — one browser print dialog for every roll.
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

$lot_no  = trim($_GET['lot_no']  ?? '');
$coil_no = trim($_GET['coil_no'] ?? '');

// ── Preserve return state back to finish_product.php ─────────────
$backMonth  = isset($_GET['month'])  ? (int)$_GET['month']  : (int)date('m');
$backYear   = isset($_GET['year'])   ? (int)$_GET['year']   : (int)date('Y');
$backDay    = isset($_GET['day'])    ? (int)$_GET['day']    : 0;
$backSearch = trim($_GET['search'] ?? '');
$backFilter = trim($_GET['filter'] ?? '');
$backUrl = 'finish_product.php?' . http_build_query(array_filter([
    'month'  => $backMonth,
    'year'   => $backYear,
    'day'    => $backDay > 0 ? $backDay : null,
    'search' => $backSearch !== '' ? $backSearch : null,
    'filter' => $backFilter !== '' ? $backFilter : null,
]));

if ($lot_no === '' || $coil_no === '') {
    die('<p style="font-family:Arial;padding:24px;">Lot No and Coil No are both required.</p>'
        . '<a href="' . htmlspecialchars($backUrl) . '">&larr; Back to List</a>');
}

// ── Fetch every printable roll under this exact Lot + Coil ────────
// "Printable" mirrors the same rule finish_product.php uses to show a
// select_customer.php link: not WAITING/REJECTED, and not an IN roll
// that's still pending Actual Length or already palletised.
$stmt = $conn->prepare("
    SELECT sp.id, sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
           sp.width, sp.length, sp.actual_length, sp.status,
           sp.is_completed, sp.customer_name, sp.ref_no,
           sp.is_printed, sp.print_count, sp.last_printed_at, sp.last_printed_by,
           pi.pallet_id
    FROM slitting_product sp
    LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
    WHERE sp.is_voided = 0
      AND sp.lot_no  = ?
      AND sp.coil_no = ?
      AND sp.status NOT IN ('WAITING', 'REJECTED')
      AND NOT (sp.status = 'IN' AND (sp.is_completed = 0 OR pi.pallet_id IS NOT NULL))
    ORDER BY sp.roll_no ASC, sp.id ASC
");
$stmt->bind_param("ss", $lot_no, $coil_no);
$stmt->execute();
$rolls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Batch Setup — <?= htmlspecialchars($lot_no) ?> <?= htmlspecialchars($coil_no) ?></title>
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
        /* Sticky action bar at the bottom so Save All / Print All are
           always reachable on a tablet without scrolling to the end. */
        .action-bar {
            position: fixed; bottom: 0; left: 0; right: 0; z-index: 20;
            background: #fff; border-top: 1px solid #ddd;
            padding: 12px 16px; display: flex; gap: 10px; justify-content: flex-end;
            flex-wrap: wrap;
        }
        .action-bar .btn { min-height: 44px; font-weight: 600; }
        #saveAllFeedback { font-size: 13px; align-self: center; margin-right: auto; }
    </style>
</head>
<body>

<div class="page-header">
    <div class="container-narrow d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h4><i class="bi bi-printer-fill me-2"></i>Batch Setup &amp; Print</h4>
            <div class="sub">Lot No <strong><?= htmlspecialchars($lot_no) ?></strong> · Coil No <strong><?= htmlspecialchars($coil_no) ?></strong> · <?= count($rolls) ?> roll(s)</div>
        </div>
        <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to List
        </a>
    </div>
</div>

<div class="container-narrow">

<?php if (empty($rolls)): ?>
    <div class="alert alert-warning">
        No printable rolls found for Lot No <strong><?= htmlspecialchars($lot_no) ?></strong>, Coil No <strong><?= htmlspecialchars($coil_no) ?></strong>.
        <br><small>Rolls still pending Actual Length, already palletised, WAITING QC, or REJECTED are not shown here.</small>
    </div>
<?php else: ?>

    <!-- Copy to All Rows ────────────────────────────────────────── -->
    <div class="card copy-all-card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label class="small fw-bold mb-1">Copy to All Rows — Customer</label>
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
                        <option value="NCI 2">NCI 2</option>
                        <option value="STOCK">STOCK</option>
                        <option value="TRIAL">TRIAL</option>
                        <option value="OTHER">OTHER (type below)</option>
                    </select>
                    <input type="text" class="form-control form-control-sm mt-1" id="copyAllCustomOther"
                           placeholder="Customer name (if OTHER)" style="display:none;">
                </div>
                <div class="col-8 col-md-5">
                    <label class="small fw-bold mb-1">Ref No</label>
                    <input type="text" class="form-control form-control-sm" id="copyAllRefNo" placeholder="Ref No">
                </div>
                <div class="col-4 col-md-1">
                    <label class="small fw-bold mb-1">Copies</label>
                    <select class="form-select form-select-sm" id="copyAllCopies">
                        <option value="">—</option>
                        <option value="0">0 (skip)</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                        <option value="4">4</option>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="copyToAllRows()">
                        <i class="bi bi-arrow-down-square me-1"></i> Copy to All Rows
                    </button>
                </div>
            </div>
            <div class="form-text mb-0">Fills every row below with the same Customer/Ref No — then adjust any row that differs.</div>
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

    <!-- Batch Grid ──────────────────────────────────────────────── -->
    <div class="table-responsive">
        <table class="table table-bordered table-sm grid-table bg-white" id="batchGridTable">
            <thead class="table-dark">
                <tr>
                    <th style="width:9%;">Roll No.</th>
                    <th style="width:12%;">Size / Actual</th>
                    <th style="width:26%;">Customer</th>
                    <th style="width:26%;">Ref No.</th>
                    <th style="width:10%;">Copies</th>
                    <th style="width:17%;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rolls as $idx => $r): ?>
                <tr data-id="<?= $r['id'] ?>" data-row="<?= $idx ?>"
                    data-is-printed="<?= !empty($r['is_printed']) ? 1 : 0 ?>"
                    data-print-count="<?= (int)($r['print_count'] ?? 0) ?>">
                    <td class="readonly-cell">
                        <strong><?= htmlspecialchars(str_replace('R', 'R-', $r['roll_no'] ?? '')) ?></strong>
                        <div class="text-muted"><?= htmlspecialchars($r['product'] ?? '') ?></div>
                        <?php if (!empty($r['is_printed'])): ?>
                            <span class="badge bg-success mt-1" style="font-size:10px;"
                                  title="Last printed <?= htmlspecialchars($r['last_printed_at'] ? date('d M Y H:i', strtotime($r['last_printed_at'])) : '') ?> by <?= htmlspecialchars($r['last_printed_by'] ?? '') ?>">
                                <i class="bi bi-printer-fill"></i> Printed (<?= (int)$r['print_count'] ?>×)
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary mt-1" style="font-size:10px;">Not Printed</span>
                        <?php endif; ?>
                    </td>
                    <td class="readonly-cell">
                        <?= number_format((float)$r['width'], 0) ?> mm<br>
                        <?= number_format((float)($r['actual_length'] ?? $r['length']), 0) ?> Mtr
                    </td>
                    <td>
                        <?php
                            $saved = trim($r['customer_name'] ?? '');
                            $knownCustomers = ['NAE','NAX','NCI MFG','TAIHO','NRI','ASHUKA','NIPPON','NTC','SGC','STAMPING','YANTAI','NIP','NVC','NCS','SNP','NCI 2','STOCK','TRIAL'];
                            $isOther = ($saved !== '' && !in_array($saved, $knownCustomers, true));
                        ?>
                        <select class="form-select form-select-sm row-customer" data-row="<?= $idx ?>"
                                onchange="handleRowCustomerChange(<?= $idx ?>)">
                            <option value="">-- Select Customer --</option>
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
                            <option value="NCI 2"    <?= $saved==='NCI 2'    ?'selected':'' ?>>NCI 2</option>
                            <option value="STOCK"    <?= ($saved===''||$saved==='STOCK')?'selected':'' ?>>STOCK</option>
                            <option value="TRIAL"    <?= $saved==='TRIAL'    ?'selected':'' ?>>TRIAL</option>
                            <option value="OTHER"    <?= $isOther ?'selected':'' ?>>OTHER (type below)</option>
                        </select>
                        <input type="text" class="form-control form-control-sm row-custom-customer mt-1" data-row="<?= $idx ?>"
                               placeholder="Enter customer name" style="display:<?= $isOther?'block':'none' ?>;"
                               value="<?= $isOther ? htmlspecialchars($saved) : '' ?>">
                        <div class="text-muted nci-note mt-1" data-row="<?= $idx ?>" style="display:none;"></div>
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm row-refno" data-row="<?= $idx ?>"
                               value="<?= htmlspecialchars(trim($r['ref_no'] ?? '')) ?>" placeholder="Ref No">
                    </td>
                    <td>
                        <select class="form-select form-select-sm row-copies" data-row="<?= $idx ?>">
                            <option value="0">0 (skip)</option>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3" selected>3</option>
                             <option value="4" >4</option>
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
    <span id="saveAllFeedback"></span>
    <button type="button" class="btn btn-primary" id="saveAllBtn" onclick="saveAllRows()" <?= empty($rolls)?'disabled':'' ?>>
        <i class="bi bi-floppy-fill me-1"></i> Save All
    </button>
    <button type="button" class="btn btn-danger" id="printAllBtn" onclick="printAllStickers()" <?= empty($rolls)?'disabled':'' ?>>
        <i class="bi bi-printer-fill me-1"></i> Print All Stickers
    </button>
</div>

<!-- Hidden form used to POST the print job in a new tab -->
<form id="batchPrintForm" method="post" action="batch_print_action.php" target="_blank" style="display:none;">
    <input type="hidden" name="selections" id="batchPrintSelectionsInput">
    <input type="hidden" name="lot_no"  value="<?= htmlspecialchars($lot_no) ?>">
    <input type="hidden" name="coil_no" value="<?= htmlspecialchars($coil_no) ?>">
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
async function handleRowCustomerChange(rowIdx) {
    const sel      = document.querySelector(`.row-customer[data-row="${rowIdx}"]`);
    const otherEl  = document.querySelector(`.row-custom-customer[data-row="${rowIdx}"]`);
    const noteEl   = document.querySelector(`.nci-note[data-row="${rowIdx}"]`);
    const refEl    = document.querySelector(`.row-refno[data-row="${rowIdx}"]`);
    const tr       = document.querySelectorAll('#batchGridTable tbody tr')[rowIdx];
    const productId = tr ? tr.dataset.id : null;
    const val = sel.value;

    otherEl.style.display = (val === 'OTHER') ? 'block' : 'none';

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

// ── Copy to All Rows ─────────────────────────────────────────────
async function copyToAllRows() {
    const sel      = document.getElementById('copyAllCustomer');
    const otherEl  = document.getElementById('copyAllCustomOther');
    const refEl    = document.getElementById('copyAllRefNo');
    const copiesEl = document.getElementById('copyAllCopies');

    const customerVal = sel.value;
    const refVal       = refEl.value.trim();
    const copiesVal    = copiesEl.value;

    if (!customerVal) { alert('Select a customer to copy to all rows first.'); return; }
    if (customerVal === 'OTHER' && !otherEl.value.trim()) { alert('Enter the customer name.'); return; }

    const rowCount = getRowCount();
    for (let idx = 0; idx < rowCount; idx++) {
        const rowSel      = document.querySelector(`.row-customer[data-row="${idx}"]`);
        const rowOtherEl  = document.querySelector(`.row-custom-customer[data-row="${idx}"]`);
        const rowRefEl    = document.querySelector(`.row-refno[data-row="${idx}"]`);
        const rowCopiesEl = document.querySelector(`.row-copies[data-row="${idx}"]`);

        rowSel.value = customerVal;
        rowOtherEl.style.display = (customerVal === 'OTHER') ? 'block' : 'none';
        if (customerVal === 'OTHER') rowOtherEl.value = otherEl.value.trim();
        if (refVal !== '') rowRefEl.value = refVal;
        if (copiesVal) rowCopiesEl.value = copiesVal;

        if (NCI_CUSTOMERS.includes(customerVal)) {
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

        let customer = sel.value;
        if (customer === 'OTHER') customer = otherEl.value.trim();
        const ref_no = refEl.value.trim();
        // NOTE: `|| 1` here would silently turn a deliberate "0 (skip)"
        // selection back into 1, since 0 is falsy in JS — use isNaN
        // instead so 0 is preserved as a valid, meaningful value.
        const parsedCopies = parseInt(copiesEl.value, 10);
        const copies = isNaN(parsedCopies) ? 1 : parsedCopies;

        if (!customer) { setRowStatus(idx, 'Select a customer', true); hasError = true; return; }
        if (!ref_no)   { setRowStatus(idx, 'Ref No required', true);   hasError = true; return; }

        setRowStatus(idx, copies === 0 ? 'Saved, print skipped' : 'OK', false);
        selections.push({
            id:                    tr.dataset.id,
            customer:              customer,
            ref_no:                ref_no,
            copies:                copies,
            nci_resolved_customer: refEl.dataset.nciResolvedCustomer || '',
        });
    });

    return hasError ? null : selections;
}

// ── Save All (AJAX, stays on page) ────────────────────────────────
async function saveAllRows() {
    const feedback = document.getElementById('saveAllFeedback');
    const selections = collectSelections();
    if (!selections) {
        feedback.innerHTML = '<span class="text-danger">Fix the highlighted rows first.</span>';
        return;
    }

    const btn = document.getElementById('saveAllBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';

    try {
        const resp = await fetch('batch_setup_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ selections }),
        });
        const result = await resp.json();
        feedback.innerHTML = result.ok
            ? `<span class="text-success"><i class="bi bi-check-circle me-1"></i>${escHtml(result.msg)}</span>`
            : `<span class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>${escHtml(result.msg)}</span>`;
    } catch (e) {
        feedback.innerHTML = '<span class="text-danger">Network error while saving.</span>';
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-floppy-fill me-1"></i> Save All';
}

// ── Print All Stickers (saves, then opens consolidated print job) ──
function printAllStickers() {
    const selections = collectSelections();
    if (!selections) {
        document.getElementById('saveAllFeedback').innerHTML =
            '<span class="text-danger">Fix the highlighted rows before printing.</span>';
        return;
    }

    // ── Warn if any selected roll (with copies > 0) was already printed ──
    // collectSelections() pushes one entry per row, in the same order as
    // the table rows, skipping none (an error would have returned null
    // above) — so selections[i] always corresponds to trs[i].
    const trs = Array.from(document.querySelectorAll('#batchGridTable tbody tr'));
    const alreadyPrinted = [];
    selections.forEach((sel, idx) => {
        if (sel.copies > 0) {
            const tr = trs[idx];
            if (tr && tr.dataset.isPrinted === '1') {
                const rollLabel = tr.querySelector('.readonly-cell strong')?.textContent?.trim() || `Roll #${sel.id}`;
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

    document.getElementById('batchPrintSelectionsInput').value = JSON.stringify(selections);
    document.getElementById('batchPrintForm').submit();
}
</script>
</body>
</html>
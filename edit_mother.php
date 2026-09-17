<?php
include 'config.php';

if (!isset($_GET['id'])) {
    die("Missing ID");
}

$id = intval($_GET['id']);

$res = $conn->query("SELECT * FROM mother_coil WHERE id=$id");
if (!$res || $res->num_rows === 0) die("Mother coil not found");
$data = $res->fetch_assoc();

// Fetch existing slitting plan rows
$plansStmt = $conn->prepare("SELECT roll_seq, planned_width, customer_name, ref_no FROM slitting_plans WHERE mother_coil_id = ? ORDER BY sort_order ASC, id ASC");
$plansStmt->bind_param("i", $id);
$plansStmt->execute();
$plans = $plansStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$plansStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Mother Coil</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; padding: 20px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
    </style>
</head>
<body>
<datalist id="validCustomerList">
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
  <option value="NCI 2">NCI MFG (LINE 2)</option>
  <option value="STOCK">STOCK</option>
  <option value="TRIAL">TRIAL</option>
</datalist>

<div class="container my-4" style="max-width: 960px;">
    <div class="card border-0 shadow-lg p-4">
        <h3 class="mb-4 fw-bold text-dark"><i class="bi bi-pencil-square text-warning me-2"></i>Edit Mother Coil</h3>
        <form method="post" action="update_mother.php">
            <input type="hidden" name="id" value="<?= $data['id'] ?>">

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Lot No</label>
                    <input type="text" name="lot_no" class="form-control" value="<?= htmlspecialchars($data['lot_no'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Coil No</label>
                    <input type="text" name="coil_no" class="form-control" value="<?= htmlspecialchars($data['coil_no'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Product</label>
                    <input type="text" name="product" class="form-control" value="<?= htmlspecialchars($data['product'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Grade</label>
                    <input type="text" name="grade" class="form-control" value="<?= htmlspecialchars($data['grade'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Width (mm)</label>
                    <input type="number" step="0.01" name="width" class="form-control" value="<?= htmlspecialchars($data['width'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Length (mtr)</label>
                    <input type="number" step="0.01" name="length" class="form-control" value="<?= htmlspecialchars($data['length'] ?? '') ?>" required>
                </div>
            </div>

            <!-- Slitting Plan Section -->
            <hr class="my-4">
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label fw-bold fs-6 text-dark mb-0">
                        <i class="bi bi-list-ol text-primary me-2"></i>Slitting Plan <span class="badge bg-secondary-subtle text-secondary ms-1">Optional</span>
                    </label>
                </div>
                <div class="form-text text-muted mb-3">
                    Modify or add planned cut widths, customer codes, and ref numbers for this mother coil.
                </div>

                <!-- Quick Paste Area -->
                <div class="p-3 bg-light rounded-3 border mb-3">
                    <label class="form-label fw-semibold small text-secondary mb-1">
                        <i class="bi bi-clipboard-plus me-1"></i> Quick Excel / CSV Slitting Plan Paste
                    </label>
                    <textarea id="edit_plan_paste_zone" class="form-control form-control-sm border-secondary-subtle" rows="2"
                              placeholder="Paste from Excel/CSV — Roll, Width, Customer, Ref No, e.g.&#10;R1, 125, NAE, SO-26-0110&#10;R2, 125, STAMPING, STOCK"></textarea>
                    <div class="alert alert-warning py-1 px-2 small mt-2 mb-0 d-none" id="edit_plan_paste_warning">
                        Couldn't read that as Seq + Width pairs — check the format.
                    </div>
                </div>

                <!-- Structured Table / Grid for Slitting Plan Rows -->
                <div class="card border shadow-sm mb-3">
                    <div class="card-header bg-light py-2 px-3">
                        <div class="row g-2 align-items-center text-secondary fw-bold small text-uppercase">
                            <div style="width: 85px;">Seq</div>
                            <div style="width: 125px;">Width (mm)</div>
                            <div style="width: 230px;">Customer Code</div>
                            <div class="col">Customer Ref No</div>
                            <div style="width: 40px;" class="text-end"></div>
                        </div>
                    </div>
                    <div class="card-body p-2" id="editPlanRowsContainer" style="max-height: 280px; overflow-y: auto;">
                        <!-- Dynamic rows injected here -->
                    </div>
                </div>

                <button type="button" class="btn btn-outline-primary btn-sm px-3" onclick="addPlanRow()">
                    <i class="bi bi-plus-lg me-1"></i>Add Row Manually
                </button>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-success px-4"><i class="bi bi-check-circle me-1"></i> Update</button>
                <a href="mother_coil.php" class="btn btn-secondary px-4">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
const elPlanPaste   = document.getElementById('edit_plan_paste_zone');
const elPlanRows    = document.getElementById('editPlanRowsContainer');
const elPlanWarning = document.getElementById('edit_plan_paste_warning');

const VALID_CUSTOMERS = ['NAE','NAX','NCI MFG','TAIHO','NRI','ASHUKA','NIPPON','NTC','SGC','STAMPING','YANTAI','NIPP','NVC','NSJ','NIP','YTEC','NSA','NCI 2','STOCK','TRIAL'];

function planEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g,
        c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function normalizeCustomerCode(input) {
    if (!input) return '';
    const clean = input.trim().toUpperCase();
    const found = VALID_CUSTOMERS.find(c => c.toUpperCase() === clean);
    return found || '';
}

function validatePlanCustomerInput(input) {
    const val = input.value.trim().toUpperCase();
    if (!val) {
        input.classList.remove('is-invalid');
        input.title = "";
        return;
    }
    const isValid = VALID_CUSTOMERS.some(c => c.toUpperCase() === val);
    if (!isValid) {
        input.classList.add('is-invalid');
        input.title = "Invalid Customer Code! Must be one of: " + VALID_CUSTOMERS.join(', ');
    } else {
        input.classList.remove('is-invalid');
        input.title = "";
    }
}

function parsePlanPasteText(text) {
    const lines  = text.trim().split(/\r?\n/).filter(l => l.trim() !== '');
    const parsed = [];

    for (const line of lines) {
        let cols = [];
        if (line.includes(',')) {
            cols = line.split(',').map(c => c.trim());
        } else if (line.includes('\t')) {
            cols = line.split('\t').map(c => c.trim()).filter(c => c !== '');
        } else {
            cols = line.trim().split(/\s+/);
        }

        if (cols.length < 2) continue;

        const seq   = cols[0].trim();
        const width = parseFloat(cols[1]);
        if (seq === '' || isNaN(width)) continue;

        const rawCustomer = (cols.length >= 3) ? cols[2].trim() : '';
        const customer    = normalizeCustomerCode(rawCustomer);
        const refNo       = (cols.length >= 4) ? cols[3].trim() : '';
        const rawCustInvalid = (rawCustomer !== '' && customer === '');

        parsed.push({ seq, width, customer, refNo, rawCustomer, rawCustInvalid });
    }

    return parsed;
}

function addPlanRow(seq = '', width = '', customer = '', refNo = '', rawCustomer = '', rawCustInvalid = false) {
    if (!seq) {
        let maxNum = 0;
        elPlanRows.querySelectorAll('.plan-row input[name="plan_seq[]"]').forEach(input => {
            const m = input.value.trim().match(/^R(\d+)$/i);
            if (m) {
                const num = parseInt(m[1], 10);
                if (num > maxNum) maxNum = num;
            }
        });
        if (maxNum === 0) {
            maxNum = elPlanRows.querySelectorAll('.plan-row').length;
        }
        seq = 'R' + (maxNum + 1);
    }

    const custVal = customer || rawCustomer;
    const invalidClass = rawCustInvalid ? 'is-invalid' : '';
    const invalidTitle = rawCustInvalid ? `Invalid Customer Code '${rawCustomer}'` : '';

    const row = document.createElement('div');
    row.className = 'row g-2 align-items-center mb-2 plan-row';
    row.innerHTML = `
        <div style="width: 85px;">
            <input type="text" name="plan_seq[]" class="form-control form-control-sm text-center fw-bold bg-light" placeholder="R1" value="${planEsc(seq)}">
        </div>
        <div style="width: 125px;">
            <input type="number" step="0.01" name="plan_width[]" class="form-control form-control-sm plan-width-input" placeholder="125" value="${planEsc(width)}">
        </div>
        <div style="width: 230px;">
            <input type="text" name="plan_customer[]" class="form-control form-control-sm text-uppercase ${invalidClass}" list="validCustomerList" placeholder="e.g. NAE" value="${planEsc(custVal)}" title="${invalidTitle}" oninput="validatePlanCustomerInput(this)">
        </div>
        <div class="col">
            <input type="text" name="plan_ref[]" class="form-control form-control-sm" placeholder="e.g. SO-26-0110" value="${planEsc(refNo)}">
        </div>
        <div style="width: 40px;" class="text-end">
            <button type="button" class="btn btn-sm btn-outline-danger border-0 px-2" title="Remove Roll" onclick="this.closest('.plan-row').remove()">
                <i class="bi bi-trash3-fill"></i>
            </button>
        </div>
    `;
    elPlanRows.appendChild(row);

    if (!width) {
        const widthInput = row.querySelector('.plan-width-input');
        if (widthInput) widthInput.focus();
    }
}

function clearPlanRows() {
    elPlanRows.innerHTML = '';
}

elPlanPaste.addEventListener('input', function () {
    const text = this.value.trim();
    if (!text) { elPlanWarning.classList.add('d-none'); return; }

    const parsed = parsePlanPasteText(text);
    if (parsed.length === 0) { elPlanWarning.classList.remove('d-none'); return; }

    elPlanWarning.classList.add('d-none');
    clearPlanRows();
    parsed.forEach(p => addPlanRow(p.seq, p.width, p.customer, p.refNo, p.rawCustomer, p.rawCustInvalid));
});

// Load existing plans on page load
const existingPlans = <?= json_encode($plans) ?>;
if (Array.isArray(existingPlans) && existingPlans.length > 0) {
    existingPlans.forEach(p => addPlanRow(p.roll_seq, p.planned_width, p.customer_name, p.ref_no));
}
</script>
</body>
</html>
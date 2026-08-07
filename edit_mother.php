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
$plansStmt = $conn->prepare("SELECT roll_seq, planned_width FROM slitting_plans WHERE mother_coil_id = ? ORDER BY sort_order ASC, id ASC");
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
<div class="container" style="max-width: 650px;">
    <div class="card p-4">
        <h3 class="mb-4"><i class="bi bi-pencil-square me-2"></i>Edit Mother Coil</h3>
        <form method="post" action="update_mother.php">
            <input type="hidden" name="id" value="<?= $data['id'] ?>">

            <div class="mb-3">
                <label class="form-label fw-semibold">Product</label>
                <input type="text" name="product" class="form-control" value="<?= htmlspecialchars($data['product'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Lot No</label>
                <input type="text" name="lot_no" class="form-control" value="<?= htmlspecialchars($data['lot_no'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Coil No</label>
                <input type="text" name="coil_no" class="form-control" value="<?= htmlspecialchars($data['coil_no'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Grade</label>
                <input type="text" name="grade" class="form-control" value="<?= htmlspecialchars($data['grade'] ?? '') ?>">
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-semibold">Width (mm)</label>
                    <input type="number" step="0.01" name="width" class="form-control" value="<?= htmlspecialchars($data['width'] ?? '') ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-semibold">Length (mtr)</label>
                    <input type="number" step="0.01" name="length" class="form-control" value="<?= htmlspecialchars($data['length'] ?? '') ?>" required>
                </div>
            </div>

            <!-- Slitting Plan Section -->
            <hr class="my-4">
            <div class="mb-3">
                <label class="form-label fw-semibold">
                    <i class="bi bi-list-ol me-1"></i>Slitting Plan <span class="text-muted fw-normal">(optional)</span>
                </label>
                <div class="form-text mb-2">
                    Modify or add planned cut widths for this mother coil.
                </div>

                <textarea id="edit_plan_paste_zone" class="form-control form-control-sm mb-2" rows="2"
                          placeholder="Paste directly from Excel — Seq (tab) Width, one roll per line, e.g.&#10;R1&#9;415&#10;R2&#9;109.5"></textarea>
                <div class="alert alert-warning py-1 px-2 small mt-1 mb-2 d-none" id="edit_plan_paste_warning">
                    Couldn't read that as Seq + Width pairs — check the format.
                </div>

                <div id="editPlanRowsContainer" class="mb-2"></div>

                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addPlanRow()">
                    <i class="bi bi-plus-lg me-1"></i>Add Row Manually
                </button>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-success"><i class="bi bi-save me-1"></i> Update</button>
                <a href="mother_coil.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
const elPlanPaste   = document.getElementById('edit_plan_paste_zone');
const elPlanRows    = document.getElementById('editPlanRowsContainer');
const elPlanWarning = document.getElementById('edit_plan_paste_warning');

function planEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g,
        c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function parsePlanPasteText(text) {
    const lines  = text.trim().split(/\r?\n/).filter(l => l.trim() !== '');
    const parsed = [];

    for (const line of lines) {
        let cols = line.split('\t').map(c => c.trim()).filter(c => c !== '');
        if (cols.length < 2) cols = line.trim().split(/\s+/);
        if (cols.length < 2) continue;

        const seq   = cols[0];
        const width = parseFloat(cols[1]);
        if (seq === '' || isNaN(width)) continue;

        parsed.push({ seq, width });
    }

    return parsed;
}

function addPlanRow(seq = '', width = '') {
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

    const row = document.createElement('div');
    row.className = 'input-group input-group-sm mb-1 plan-row';
    row.innerHTML = `
        <span class="input-group-text">Seq</span>
        <input type="text" name="plan_seq[]" class="form-control" placeholder="R1" value="${planEsc(seq)}">
        <span class="input-group-text">Width (mm)</span>
        <input type="number" step="0.01" name="plan_width[]" class="form-control plan-width-input" placeholder="e.g. 415" value="${planEsc(width)}">
        <button type="button" class="btn btn-outline-danger" onclick="this.closest('.plan-row').remove()">
            <i class="bi bi-x-lg"></i>
        </button>
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
    parsed.forEach(p => addPlanRow(p.seq, p.width));
});

// Load existing plans on page load
const existingPlans = <?= json_encode($plans) ?>;
if (Array.isArray(existingPlans) && existingPlans.length > 0) {
    existingPlans.forEach(p => addPlanRow(p.roll_seq, p.planned_width));
}
</script>
</body>
</html>
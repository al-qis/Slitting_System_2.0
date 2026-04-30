<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

// ONLY slitting role can access
if ($_SESSION['role'] !== 'slitting') {
    die("Access denied");
}

include 'config.php';

$from_stock = isset($_GET['stock_id']);
$source_data = null;
$source_type = '';
$mother_id = null;
$stock_id = null;

if ($from_stock) {
    // From Stock Raw Material (leftovers or mother coils in stock)
    $stock_id = intval($_GET['stock_id']);
    
    // Query from stock_raw_material table (where inventory is tracked)
    $query = "SELECT * FROM stock_raw_material WHERE id=$stock_id AND status='IN'";
    $source_data = $conn->query($query)->fetch_assoc();
    $source_type = 'stock';

    if (!$source_data) {
        die("<div style='color:red; padding:20px; border:1px solid red; background:#fff5f5; border-radius:8px;'>
                <h3>❌ Stock Not Found or Already Used</h3>
                <p>The stock item you're trying to use either:</p>
                <ul>
                    <li>❌ Does not exist</li>
                    <li>❌ Already marked as OUT (already used)</li>
                    <li>❌ Was deleted</li>
                </ul>
                <p><strong>Status in database:</strong> status != 'IN'</p>
                <button onclick='history.back()' style='padding:10px 20px; background:#007bff; color:white; border:none; border-radius:5px; cursor:pointer;'>← Go Back</button>
             </div>");
    }
    
    // For leftovers, we need to find the original mother coil
    // But for now, we'll use source_id if available, or get it from lot_no matching
    if ($source_data['source_id']) {
        $mother_id = $source_data['source_id'];
    } else {
        // Fallback: try to find a mother coil with matching lot_no
        $mother_query = "SELECT id FROM mother_coil 
                         WHERE lot_no LIKE '" . $conn->real_escape_string($source_data['lot_no']) . "%' 
                         LIMIT 1";
        $mother_result = $conn->query($mother_query);
        if ($mother_result && $mother_result->num_rows > 0) {
            $mother_id = $mother_result->fetch_assoc()['id'];
        } else {
            $mother_id = 0; // Will be handled in save_slitting
        }
    }

} else {
    // From fresh Mother Coil (direct from mother_coil table)
    if (!isset($_GET['mother_id'])) {
        die("Error: mother_id was not provided.");
    }
    $mother_id = intval($_GET['mother_id']);
    $source_data = $conn->query("SELECT * FROM mother_coil WHERE id=$mother_id")->fetch_assoc();
    $source_type = 'mother';

    if (!$source_data) {
        die("Mother coil not found for ID: $mother_id");
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Add Slitting Product</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; padding:20px; }
        .card { border: none; border-radius: 12px; }
        .slitting-box { border: 1px solid #dee2e6; padding: 20px; border-radius: 10px; margin-bottom: 20px; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .info-badge { background: #e7f1ff; color: #0d6efd; padding: 10px; border-radius: 6px; font-size: 0.85em; margin-top: 15px; border-left: 4px solid #0d6efd; }
        .source-info { border-left: 5px solid #198754; }
        .source-info-leftover { border-left: 5px solid #ffc107; background-color: #fffbf0; }
    </style>
</head>
<body>

    <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3><i class="bi bi-scissors me-2"></i>Production Slitting</h3>
        <a href="<?= $from_stock ? 'raw_material.php' : 'index.php' ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>

    <div class="card shadow-sm mb-4 <?= ($source_data['source_type'] ?? '') === 'slitting_cut_into_2' ? 'source-info-leftover' : 'source-info' ?>">
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <small class="text-muted d-block">Lot No</small>
                    <span class="fw-bold"><?= htmlspecialchars($source_data['lot_no'] ?? '-') ?></span>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Coil No</small>
                    <span class="fw-bold"><?= htmlspecialchars($source_data['coil_no'] ?? '-') ?></span>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Grade</small>
                    <span class="badge bg-primary"><?= htmlspecialchars($source_data['grade'] ?? '-') ?></span>
                </div>
                <div class="col-md-3 text-end">
                    <small class="text-muted d-block"><?= $from_stock ? 'Current Length' : 'Input' ?> Length</small>
                    <span class="h5 mb-0 text-success fw-bold"><?= number_format($source_data['length'] ?? 0, 2) ?> m</span>
                </div>
            </div>
            
            <!-- Show if this is a leftover (Balance after cut) -->
            <?php if($from_stock && ($source_data['source_type'] ?? '') === 'slitting_cut_into_2'): ?>
                <div class="mt-3 alert alert-warning mb-0">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>This is Balance Stock (Leftover from Cut Into 2)</strong>
                    <br><small>Lot: <?= htmlspecialchars($source_data['lot_no']) ?> | Source: Cut Into 2</small>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <form method="post" action="save_slitting.php">
        <input type="hidden" name="source_type" value="<?= $source_type ?>">
        <input type="hidden" name="mother_id" value="<?= $mother_id ?>">
        <?php if($from_stock && $stock_id): ?>
            <input type="hidden" name="stock_id" value="<?= $stock_id ?>">
        <?php endif; ?>
        
        <input type="hidden" name="product" value="<?= htmlspecialchars($source_data['product'] ?? '') ?>">
        <input type="hidden" name="lot_no" value="<?= htmlspecialchars($source_data['lot_no'] ?? '') ?>">
        <input type="hidden" name="coil_no" value="<?= htmlspecialchars($source_data['coil_no'] ?? '') ?>">

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">1. Select Cut Type</h5>
                <div class="btn-group w-100" role="group" aria-label="Cut Type Selection">
                    <input type="radio" class="btn-check" name="cut_type" id="cutNormal" value="normal" onchange="handleCutTypeChange()" required>
                    <label class="btn btn-outline-success py-3 fw-bold" for="cutNormal">
                        <i class="bi bi-scissors me-2"></i> Normal Slitting
                    </label>

                    <input type="radio" class="btn-check" name="cut_type" id="cutInto2" value="cut_into_2" onchange="handleCutTypeChange()" required>
                    <label class="btn btn-outline-warning py-3 fw-bold" for="cutInto2">
                        <i class="bi bi-intersect me-2"></i> Cut Into 2 (Keep Leftover)
                    </label>
                </div>
            </div>
        </div>

        <div id="cutInto2Section" style="display:none;" class="card shadow-sm mb-4 border-warning">
            <div class="card-body bg-light-subtle">
                <h5 class="mb-3 text-warning-emphasis">2. Slit Calculation</h5>
                <div class="row">
                    <div class="col-md-6 mb-2">
                        <label class="form-label fw-bold">Slit Quantity (Length Used)</label>
                        <div class="input-group">
                            <input type="number" step="0.1" name="slit_quantity" id="slitQuantity" class="form-control form-control-lg" placeholder="0.0" oninput="calculateStock()">
                            <span class="input-group-text">meters</span>
                        </div>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label fw-bold">Remaining Stock</label>
                        <div class="input-group">
                            <input type="number" step="0.1" name="stock" id="stock" class="form-control form-control-lg bg-white" readonly>
                            <span class="input-group-text">meters</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="rollCountSection" style="display:none;" class="card shadow-sm mb-4">
            <div class="card-body">
                <h5 class="mb-3" id="outputStepTitle">2. Output Configuration</h5>
                <div class="row align-items-center">
                    <div class="col-auto">
                        <label class="form-label mb-0 fw-bold">Number of Output Rolls:</label>
                    </div>
                    <div class="col-auto">
                        <select name="total" id="total" class="form-select form-select-lg w-auto" onchange="generateForm()" required>
                            <option value="">-- Choose --</option>
                            <?php for($i=1;$i<=20;$i++): ?>
                                <option value="<?= $i ?>"><?= $i ?> Roll<?= $i>1?'s':'' ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div id="slittingForm"></div>

        <div id="normalCutSfcSection" style="display: none;" class="card shadow-sm mb-4 border-info">
            <div class="card-body">
                <h5 class="text-info-emphasis"><i class="bi bi-box-seam me-2"></i>Save to SFC </h5>
                <p class="text-muted small">Capture unused width as SFC scrap/balance.</p>
                <div class="input-group">
                    <span class="input-group-text">Balance Width</span>
                    <input type="number" step="0.1" name="sfc_balance_width" class="form-control" placeholder="Optional (mm)">
                    <span class="input-group-text">mm</span>
                </div>
            </div>
        </div>

        <div class="mb-5">
            <button type="submit" class="btn btn-primary btn-lg px-5 shadow" id="submitBtn" style="display:none;">
                <i class="bi bi-save me-2"></i> Save Production Data
            </button>
        </div>
    </form>
</div>

<script>
const sourceData = {
    lotNo: '<?= htmlspecialchars($source_data['lot_no'] ?? '') ?>',
    coilNo: '<?= htmlspecialchars($source_data['coil_no'] ?? '') ?>',
    originalLength: <?= floatval($source_data['length'] ?? 0) ?>,
    fromStock: <?= $from_stock ? 'true' : 'false' ?>
};

function calculateStock() {
    const slitQty = parseFloat(document.getElementById('slitQuantity').value) || 0;
    const stock = sourceData.originalLength - slitQty;
    const stockField = document.getElementById('stock');
    
    stockField.value = stock.toFixed(2);
    stockField.style.color = stock < 0 ? '#dc3545' : (stock === 0 ? '#ffc107' : '#198754');

    // Sync length to all rolls in "Cut Into 2" mode
    const lengths = document.querySelectorAll('.length-input');
    lengths.forEach(input => {
        if(document.querySelector('input[name="cut_type"]:checked').value === 'cut_into_2') {
            input.value = slitQty;
        }
    });
}

function handleCutTypeChange(){
    const cutType = document.querySelector('input[name="cut_type"]:checked')?.value;
    const outputStepTitle = document.getElementById('outputStepTitle');
    
    // UI Toggles
    document.getElementById('cutInto2Section').style.display = (cutType === 'cut_into_2') ? 'block' : 'none';
    document.getElementById('rollCountSection').style.display = (cutType) ? 'block' : 'none';
    document.getElementById('normalCutSfcSection').style.display = (cutType === 'normal') ? 'block' : 'none';
    
    // Fix Step Numbering
    if (cutType === 'cut_into_2') {
        outputStepTitle.innerText = "3. Output Configuration";
    } else {
        outputStepTitle.innerText = "2. Output Configuration";
    }

    // Reset output form
    document.getElementById('total').value = '';
    document.getElementById('slittingForm').innerHTML = '';
    document.getElementById('submitBtn').style.display = 'none';
}

function generateForm(){
    const total = parseInt(document.getElementById('total').value);
    const container = document.getElementById('slittingForm');
    const cutType = document.querySelector('input[name="cut_type"]:checked').value;
    
    if (!total) {
        container.innerHTML = '';
        document.getElementById('submitBtn').style.display = 'none';
        return;
    }

    // Determine the default length based on selection
    let defaultLength = (cutType === 'cut_into_2') 
        ? (parseFloat(document.getElementById('slitQuantity').value) || 0) 
        : sourceData.originalLength;

    let html = "";
    for (let i = 1; i <= total; i++) {
        html += `
            <div class="slitting-box">
                <div class="d-flex justify-content-between border-bottom pb-2 mb-3 align-items-center">
                    <h5 class="mb-0 text-primary">Output Roll #${i}</h5>
                    <div class="d-flex gap-2 align-items-center">
                        <span class="badge bg-light text-dark border">Label: R${i}</span>
                        <div class="form-check">
                            <input class="form-check-input sfc-checkbox" type="checkbox" name="send_to_sfc[]" id="sfcCheck${i-1}" value="${i}">
                            <label class="form-check-label small" for="sfcCheck${i-1}" title="Send this roll to SFC inventory">
                                <i class="bi bi-box-seam text-primary"></i> To SFC
                            </label>
                        </div>
                    </div>
                </div>
                <input type="hidden" name="roll_no[]" value="R${i}">

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Cut Letter</label>
                        <select name="cut_letter[]" class="form-select" onchange="updateLotLabel(${i-1})">
                            <option value="">Standard</option>
                            <option value="a">a</option>
                            <option value="b">b</option>
                            <option value="c">c</option>
                            <option value="d">d</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Length (m)</label>
                        <input type="number" step="0.1" name="length[]" class="form-control length-input" 
                               value="${defaultLength}" readonly required>
                        <small class="text-muted">Auto-filled based on selection</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Width (mm)</label>
                        <input type="number" step="0.1" name="width[]" class="form-control" placeholder="Enter Width" required>
                    </div>
                </div>
                
                <div class="info-badge" id="infoBadge${i-1}">
                    <i class="bi bi-tag me-1"></i> ${sourceData.lotNo} ${sourceData.coilNo}-R${i}
                </div>
            </div>
        `;
    }

    container.innerHTML = html;
    document.getElementById('submitBtn').style.display = 'inline-block';
}

function updateLotLabel(idx) {
    const selectElem = document.querySelectorAll('select[name="cut_letter[]"]')[idx];
    const letter = selectElem.value;
    const badge = document.getElementById(`infoBadge${idx}`);
    badge.innerHTML = `<i class="bi bi-tag me-1"></i> ${sourceData.lotNo}${letter} ${sourceData.coilNo}-R${idx+1}`;
}
</script>

</body>
</html>
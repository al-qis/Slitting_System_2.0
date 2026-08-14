<?php
include 'config.php';

// Strips trailing zeros for display (109.50 -> "109.5", 375.00 -> "375"),
// capped at 2 decimal places — same fix applied to the sticker pattern
// files, so this preview matches exactly what actually prints.
// function_exists guard in case this also ends up in config.php later.
if (!function_exists('formatStickerDecimal')) {
    function formatStickerDecimal($value): string {
        $formatted = number_format((float)$value, 2, '.', '');
        $formatted = rtrim($formatted, '0');
        $formatted = rtrim($formatted, '.');
        return $formatted;
    }
}

function getCoilPrefix($coil_no) {
    $coil_no = trim((string)$coil_no);
    if ($coil_no === '') return '';
    if (strpos($coil_no, '-') !== false) {
        return strtoupper(trim(explode('-', $coil_no)[0]));
    }
    preg_match('/^[A-Za-z]+/', $coil_no, $m);
    return strtoupper($m[0] ?? '');
}

// ═══════════════════════════════════════════════════════════════
// AJAX: NCI MFG / NCI 2 lookup
// Looks up nci_product_mapping by Internal Code (<coil prefix>-<width mm>).
// Returns JSON { ok, internal_code, parts:[...] }
// The front-end uses this to auto-fill the Ref No field.
// ═══════════════════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'nci_lookup') {
    header('Content-Type: application/json');

    $pid = intval($_GET['id'] ?? 0);
    if ($pid <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid product ID.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT coil_no, width FROM slitting_product WHERE id = ?");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode(['ok' => false, 'msg' => 'Product not found.']);
        exit;
    }

    $prefix = getCoilPrefix($row['coil_no'] ?? '');
    $width  = (int)($row['width'] ?? 0);

    if ($prefix === '' || $width <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Cannot determine internal code from this roll (missing coil prefix or width).']);
        exit;
    }

    $internal_code = $prefix . '-' . $width;

    $stmt2 = $conn->prepare("SELECT customer, part_no FROM nci_product_mapping WHERE internal_code = ? LIMIT 1");
    $stmt2->bind_param("s", $internal_code);
    $stmt2->execute();
    $nci = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();

    if (!$nci) {
        echo json_encode(['ok' => false, 'msg' => "No NCI mapping found for internal code {$internal_code}."]);
        exit;
    }

    // Return parts as array (split on /) so JS can join them back as needed.
    // Customer is returned for info display only — no override on the sticker.
    $parts = array_values(array_filter(array_map('trim', explode('/', $nci['part_no'] ?? '')), fn($v) => $v !== ''));
    if (empty($parts)) $parts = [''];

    echo json_encode([
        'ok'            => true,
        'internal_code' => $internal_code,
        'customer_info' => trim($nci['customer'] ?? ''),
        'parts'         => $parts,
        'part_no_full'  => $nci['part_no'],
    ]);
    exit;
}

if (!isset($_GET['id'])) {
    die("Product ID required");
}

$id = intval($_GET['id']);

// ── Fetch product ───────────────────────────────────────────────
$result = $conn->query("SELECT * FROM slitting_product WHERE id=$id");
$product = $result->fetch_assoc();

// ── Read existing saved customer & ref_no ───────────────────────
$savedCustomer = trim($product['customer_name'] ?? '');
$savedRefNo    = trim($product['ref_no']        ?? '');

// ═══════════════════════════════════════════════════════════════
// AJAX SAVE
// Triggered by the Save button via fetch().
// Returns JSON { ok, msg } — no page change, no print dialog.
// ═══════════════════════════════════════════════════════════════
if (
    isset($_POST['action']) && $_POST['action'] === 'save_only' &&
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) {
    header('Content-Type: application/json');

    $pid      = intval($_POST['id']       ?? 0);
    $customer = trim($_POST['customer']   ?? '');
    $ref_no   = trim($_POST['ref_no']     ?? '');

    if ($customer === 'OTHER') {
        $customer = trim($_POST['custom_customer'] ?? '');
    }

    // For NCI MFG / NCI 2, save the resolved end-customer name from the mapping
    // table rather than the raw dropdown code, so the DB reflects the actual
    // customer that will print on the sticker.
    if (in_array($customer, ['NCI MFG', 'NCI 2'], true)) {
        $nci_resolved = trim($_POST['nci_resolved_customer'] ?? '');
        if ($nci_resolved !== '') {
            $customer = $nci_resolved;
        }
    }

    if ($pid <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid product ID.']); exit; }
    if ($customer === '') { echo json_encode(['ok'=>false,'msg'=>'Please select a customer first.']); exit; }
    if ($ref_no   === '') { echo json_encode(['ok'=>false,'msg'=>'Ref No cannot be empty.']); exit; }

    $stmt = $conn->prepare("UPDATE slitting_product SET customer_name=?, ref_no=? WHERE id=?");
    if (!$stmt) { echo json_encode(['ok'=>false,'msg'=>'DB error: '.$conn->error]); exit; }
    $stmt->bind_param("ssi", $customer, $ref_no, $pid);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode([
        'ok'  => $ok,
        'msg' => $ok ? "Saved — Customer: {$customer} · Ref: {$ref_no}" : 'Save failed: '.$conn->error,
    ]);
    exit;
}

// ===== PRODUCT -> COLOR MAP =====
$PRODUCT_COLOR = [
  'DS-3020'=>'GREEN','DS-3825'=>'GREEN','DS-4525'=>'GREEN','DS-5030'=>'GREEN','DS-8460'=>'GREEN',
  'GB-6440'=>'YELLOW','GB-6440-S101'=>'YELLOW','KB-6440'=>'YELLOW',
  'RS-3020'=>'BLUE','RS-3825'=>'BLUE','RS-3825-04'=>'BLUE','RS-4020'=>'BLUE','RS-4025'=>'BLUE',
  'RS-4525'=>'BLUE','RS-5030'=>'BLUE','RS-6040'=>'BLUE','RS-7050'=>'BLUE',
  'RU-5040-1'=>'BLUE','RU-5040-1-S101'=>'BLUE','RV-3825'=>'BLUE',
  'JV-3825'=>'WHITE','JZ-2520'=>'WHITE','JZ-2520-2C'=>'WHITE','JZ-2820'=>'WHITE','JZ-3020'=>'WHITE','JZ-4020'=>'WHITE',
  'L1N2-2520-02'=>'WHITE','LN-1715-1'=>'WHITE','LN-2520'=>'WHITE','LN-2520-04'=>'WHITE',
  'LZ-2420'=>'WHITE','LZ-2520'=>'WHITE','MV-4020'=>'WHITE',
  'PS-6020'=>'WHITE','PS-8525'=>'WHITE',
  'TS-2620'=>'WHITE','TS-3020'=>'WHITE','TS-3525'=>'WHITE','TS-4025'=>'WHITE','TS-4525'=>'WHITE','TS-5030'=>'WHITE','TS-9080'=>'WHITE',
  'TU-2620'=>'WHITE','TU-2620-C'=>'WHITE','TU-3020'=>'WHITE','TU-4020'=>'WHITE','YW-2520'=>'WHITE',
];

function stickerBgColor(string $productCode, array $map): string {
  $code = strtoupper(trim($productCode));
  $name = $map[$code] ?? 'WHITE';
  return match($name){
    'GREEN'  => '#129e16',
    'YELLOW' => '#FFFF00',
    'BLUE'   => '#0099ff',
    default  => '#ffffff',
  };
}

$stickerBg = stickerBgColor($product['product'] ?? '', $PRODUCT_COLOR);

if (!$product) { die("Product not found"); }

$lotCoil = trim($product['lot_no']) . ' ' . trim($product['coil_no']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Print Sticker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 40px 20px; }
        .preview-container {
            max-width: 650px; margin: 0 auto; padding: 40px;
            border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); position: relative;
        }
        .preview-table { width: 100%; margin-bottom: 30px; }
        .preview-table td { padding: 12px 10px; border-bottom: 1px solid #eee; }
        .preview-table td:first-child { font-weight: bold; width: 130px; color: #333; }
        .preview-table td:nth-child(2) { width: 20px; text-align: center; }
        .preview-table td:last-child { color: #000; font-size: 18px; }
        .qr-preview { position: absolute; top: 40px; right: 40px; text-align: center; background: transparent !important; }
        .badge-text { display: inline-block; background: #e3f2fd; padding: 2px 8px; border-radius: 4px; font-size: 11px; margin-bottom: 10px; }
        .qr-preview img { width: 120px; height: 120px; border: none; background: transparent; display: block; margin: 0 auto; }
        .roll-number { font-size: 48px; font-weight: bold; color: #333; margin-top: -10px; }
        .editable-row { background: #f8f9fa; }
        .form-control, .form-select { font-size: 16px; padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; }
        .action-buttons { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        .btn-action { padding: 12px 30px; font-size: 16px; border: none; border-radius: 4px; cursor: pointer; }
        .btn-print { background: #0aa80a; color: white; }
        .btn-save { background: #1976D2; color: white; display: inline-flex; align-items: center; gap: 6px; }
        .btn-save:hover { background: #1565c0; }
        .btn-save:disabled { background: #90a4ae; cursor: not-allowed; }

        /* ── Line A toggle button ── */
        .btn-line-toggle {
            background: #546e7a;
            color: white;
            border: 2px solid transparent;
            transition: background .2s, border-color .2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-line-toggle:hover { background: #455a64; }
        .btn-line-toggle.active {
            background: #bf360c;
            border-color: #7f0000;
        }
        .btn-line-toggle.active:hover { background: #a52c0a; }

        /* ── Line A indicator badge (shown below buttons when active) ── */
        #lineABadge {
            display: none;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 600;
            background: #fbe9e7;
            color: #bf360c;
            border: 1px solid #ffab91;
            margin-top: 10px;
        }
        #lineABadge.show { display: flex; justify-content: center; }

        #saveFeedback { display: none; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 4px; font-size: 13px; font-weight: 600; margin-top: 10px; }
        #saveFeedback.show { display: flex; justify-content: center; }
        #saveFeedback.state-ok { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
        #saveFeedback.state-error { background: #fdecea; color: #c62828; border: 1px solid #ef9a9a; }
        .spin { display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(255,255,255,.4); border-top-color: #fff; border-radius: 50%; animation: spin .6s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .btn-back { background: #757575; color: white; text-decoration: none; display: inline-block; }
        .btn-back:hover { background: #616161; color: white; }
        #nciMatchPanel { font-size: 13px; }
        @media print {
            body { background: white !important; padding: 0 !important; }
            .preview-container { background: <?= $stickerBg ?> !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            .action-buttons, .btn, .badge-text { display: none !important; }
        }
    </style>
</head>
<body>

<div class="preview-container" style="background: white;">
    <div class="qr-preview">
        <div class="badge-text">INTERNAL USE</div>
        <img src="generate_qr.php?id=<?= $id ?>&type=slitting" alt="QR Code">
        <div class="roll-number"><?= htmlspecialchars($product['roll_no'] ?? '') ?></div>
    </div>

    <form method="POST" action="print_product.php" id="mainForm">
        <input type="hidden" name="id" value="<?= $id ?>">

        <!-- Line A/B flag — toggled by the Line A button below -->
        <input type="hidden" name="line" id="lineInput" value="B">

        <table class="preview-table">
        
<tr>
    <td>TOMBO No.</td><td>:</td>
    <td>
        <strong>
            <?php 
                // Default value
                $tomboNo = "1600 (METAKOTE)"; 
                
                // Check if MV, HBV, or PS exists in the product name
                if (isset($product['product']) && (
                    stripos($product['product'], 'MV') !== false || 
                    stripos($product['product'], 'HBV') !== false ||
                    stripos($product['product'], 'PS') !== false
                )) {
                    $tomboNo = "1608 (METAFOAM)";
                }
                
                echo htmlspecialchars($tomboNo);
            ?>
        </strong>
    </td>
</tr>
            <tr>
                <td>Grade</td><td>:</td>
                <td><strong><?= htmlspecialchars($product['product'] ?? '') ?></strong></td>
            </tr>
            <tr>
                <td>Size</td><td>:</td>
                <td><strong><?= formatStickerDecimal($product['width']) ?> mm x <?= formatStickerDecimal($product['actual_length'] ?? $product['length']) ?> Mtr</strong></td>
            </tr>
            <tr>
                <td>Lot No.</td><td>:</td>
                <td><strong><?= htmlspecialchars($lotCoil) ?></strong></td>
            </tr>

            <!-- Customer - Editable -->
            <tr class="editable-row">
                <td>Customer</td><td>:</td>
                <td>
                    <select name="customer" id="customer" class="form-select" required
                            onchange="handleCustomerChange(this.value)">
                        <option value="">-- Select Customer --</option>
                        <option value="NAE"     <?= $savedCustomer==='NAE'     ?'selected':'' ?>>NICHIAS AUTOPARTS EUROPE (NAE)</option>
                        <option value="NAX"     <?= $savedCustomer==='NAX'     ?'selected':'' ?>>NAX MFG, SA.DE C.V</option>
                        <option value="NCI MFG" <?= $savedCustomer==='NCI MFG' ?'selected':'' ?>>NCI MFG., INC.</option>
                        <option value="TAIHO"   <?= $savedCustomer==='TAIHO'   ?'selected':'' ?>>TAIHO MFG OF TN. INC</option>
                        <option value="NRI"     <?= $savedCustomer==='NRI'     ?'selected':'' ?>>PT NICHIAS ROCKWOOL IND.</option>
                        <option value="ASHUKA"  <?= $savedCustomer==='ASHUKA'  ?'selected':'' ?>>ASHUKA TECHNOLOGIES SDN. BHD.</option>
                        <option value="NIPPON"  <?= $savedCustomer==='NIPPON'  ?'selected':'' ?>>NTC(NIPPON GASKET)</option>
                        <option value="NTC"     <?= $savedCustomer==='NTC'     ?'selected':'' ?>>NICHIAS THAILAND</option>
                        <option value="SGC"     <?= $savedCustomer==='SGC'     ?'selected':'' ?>>SHANGHAI XINGSHENG</option>
                        <option value="STAMPING"<?= $savedCustomer==='STAMPING'?'selected':'' ?>>MK STAMPING</option>
                        <option value="YANTAI"  <?= $savedCustomer==='YANTAI'  ?'selected':'' ?>>NICHIAS (SHANGHAI) AUTOPARTS TRADING</option>
                        <option value="NIPP"    <?= $savedCustomer==='NIPP'    ?'selected':'' ?>>NICHIAS IND.PRODUCTS PVT. LTD.</option>
                        <option value="NVC"     <?= $savedCustomer==='NVC'     ?'selected':'' ?>>NICHIAS VIETNAM CO., LTD</option>
                        <option value="NSJ"     <?= $savedCustomer==='NSJ'     ?'selected':'' ?>>NC-PT NICHIAS SUNIJAYA</option>
                        <option value="NIP"     <?= $savedCustomer==='NIP'     ?'selected':'' ?>>SUZHOU NICHIAS IND. PRODUCTS</option>
                        <option value="YTEC"    <?= $savedCustomer==='YTEC'    ?'selected':'' ?>>YTEC CO., LTD.</option>
                        <option value="NSA"    <?= $savedCustomer==='NSA'    ?'selected':'' ?>>NICHIAS SOUTH EAST ASIA (UP PACKING)</option>
                        <option value="NCI 2"   <?= $savedCustomer==='NCI 2'   ?'selected':'' ?>>NCI 2</option>
                        <option value="STOCK"   <?= ($savedCustomer==='' || $savedCustomer==='STOCK') ?'selected':'' ?>>STOCK</option>
                        <option value="TRIAL"   <?= $savedCustomer==='TRIAL'   ?'selected':'' ?>>TRIAL</option>
                        <option value="OTHER"   <?= ($savedCustomer!=='' && !in_array($savedCustomer,['NAE','NAX','NCI MFG','TAIHO','NRI','ASHUKA','NIPPON','NTC','SGC','STAMPING','YANTAI','NIPP','NVC','NSJ','NIP','YTEC','NSA','NCI 2','STOCK','TRIAL'])) ?'selected':'' ?>>OTHER (type below)</option>
                    </select>
                    <input type="text" name="custom_customer" id="custom_customer"
                           class="form-control mt-2" placeholder="Enter customer name"
                           style="display:none;">
                    <!-- NCI match status panel — shown only for NCI MFG / NCI 2 -->
                    <div id="nciMatchPanel" class="mt-2" style="display:none;">
                        <div id="nciMatchContent"></div>
                    </div>
                    <!-- Carries the resolved end-customer name for save_only handler -->
                    <input type="hidden" name="nci_resolved_customer" id="nci_resolved_customer" value="">
                </td>
            </tr>

            <!-- Ref No - Editable -->
            <tr class="editable-row">
                <td>Ref. No.</td><td>:</td>
                <td>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="stockOverride">
                        <label class="form-check-label" for="stockOverride">Set to STOCK</label>
                    </div>
                    <input type="text" name="ref_no" id="ref_no"
                           class="form-control" value="<?= htmlspecialchars($savedRefNo ?: 'STOCK') ?>" required>
                </td>
            </tr>
        </table>

        <div class="action-buttons">

            <!-- Line A toggle — when active, "Sticker B" label is hidden on the printed sticker -->
            <button type="button" class="btn-action btn-line-toggle" id="lineToggleBtn"
                    onclick="toggleLine()">
                <i class="bi bi-layers" id="lineToggleIcon"></i>
                <span id="lineToggleLabel">Line A</span>
            </button>

            <button type="button" class="btn-action btn-save" id="saveBtn" onclick="saveOnly()">
                <i class="bi bi-floppy-fill"></i> Save
            </button>
            <button type="submit" class="btn-action btn-print">
                <i class="bi bi-printer-fill"></i> Print Sticker
            </button>
            <a href="finish_product.php" class="btn-action btn-back">
                <i class="bi bi-arrow-left"></i> Back to List
            </a>
        </div>

        <!-- Line A active indicator -->
        <div id="lineABadge">
            <i class="bi bi-exclamation-circle-fill"></i>
            Line A selected — <strong>"Sticker B"</strong> label will be hidden on the printed sticker.
        </div>

        <div id="saveFeedback"></div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/imask"></script>
<script>
const PRODUCT_ID   = <?= (int)$id ?>;
const nciPanel     = document.getElementById('nciMatchPanel');
const nciContent   = document.getElementById('nciMatchContent');
const refNoInput   = document.getElementById('ref_no');
const stockOverride = document.getElementById('stockOverride');

// ── Ref No dynamic masking ───────────────────────────────────────
// NCI MFG / NCI 2 already have their own dedicated mapping logic
// (see checkNciMatch below) — never touch the Ref No field for them.
const NCI_CUSTOMERS = ['NCI MFG', 'NCI 2'];
let refNoMask = null;

function destroyRefMask() {
    if (refNoMask) { refNoMask.destroy(); refNoMask = null; }
}

function applyMaskForCustomer(val) {
    destroyRefMask();

    if (stockOverride.checked || NCI_CUSTOMERS.includes(val)) {
        return; // STOCK override or NCI dedicated logic — no masking
    }

    if (val === 'STAMPING') {
        // MS + 7 digits, optional trailing space + uppercase letter
        refNoMask = IMask(refNoInput, {
            mask: 'MS-0000000[ a]',
            blocks: {
                a: { mask: /[A-Z]/ }
            },
            prepareChar: (str) => str.toUpperCase()
        });
        if (!refNoInput.value || refNoInput.value === 'STOCK') {
            refNoMask.value = 'MS-';
        }
    } else {
        // Default rule: SO-XX-XXXX (no spaces)
        refNoMask = IMask(refNoInput, {
            mask: 'SO-00-0000'
        });
        if (!refNoInput.value || refNoInput.value === 'STOCK') {
            refNoMask.value = 'SO-';
        }
    }
}

function refNoMatchesActiveRule() {
    const val = refNoInput.value.trim();
    const cust = document.getElementById('customer').value;

    if (stockOverride.checked) return val === 'STOCK';
    if (NCI_CUSTOMERS.includes(cust)) return val !== ''; // dedicated logic, just require non-empty

    if (cust === 'STAMPING') return /^MS-\d{7}( [A-Z])?$/.test(val);
    return /^SO-\d{2}-\d{4}$/.test(val);
}

// Safety net: for customers where no IMask pattern is active (e.g. NCI MFG /
// NCI 2, which use their own auto-fill logic), strip any spaces a user might
// manually type or paste around the Ref No so it never ends up with the old
// "SO - 26 - 1062" style spacing.
refNoInput.addEventListener('input', () => {
    if (refNoMask || stockOverride.checked) return; // IMask / STOCK already control formatting
    const cleaned = refNoInput.value.replace(/\s+/g, '');
    if (cleaned !== refNoInput.value) {
        const pos = refNoInput.selectionStart;
        refNoInput.value = cleaned;
        refNoInput.setSelectionRange(pos, pos);
    }
});

stockOverride.addEventListener('change', () => {
    if (stockOverride.checked) {
        destroyRefMask();
        refNoInput.value = 'STOCK';
        refNoInput.readOnly = true;
    } else {
        refNoInput.readOnly = false;
        refNoInput.value = '';
        applyMaskForCustomer(document.getElementById('customer').value);
    }
});

// ── Line A / B toggle ──────────────────────────────────────────
// Default: Line B (Sticker B shown).
// When user clicks the button it turns red (active) = Line A,
// which will suppress the "Sticker B" label on the printed sticker.
let lineA = false;

function toggleLine() {
    lineA = !lineA;
    const btn    = document.getElementById('lineToggleBtn');
    const label  = document.getElementById('lineToggleLabel');
    const input  = document.getElementById('lineInput');
    const badge  = document.getElementById('lineABadge');

    if (lineA) {
        btn.classList.add('active');
        label.textContent = 'Line A (active)';
        input.value = 'A';
        badge.classList.add('show');
    } else {
        btn.classList.remove('active');
        label.textContent = 'Line A';
        input.value = 'B';
        badge.classList.remove('show');
    }
}

function handleCustomerChange(val) {
    document.getElementById('custom_customer').style.display =
        val === 'OTHER' ? 'block' : 'none';
    checkNciMatch(val);
    applyMaskForCustomer(val);
}

// ── NCI MFG / NCI 2 auto-fill ──────────────────────────────────
async function checkNciMatch(val) {
    if (val !== 'NCI MFG' && val !== 'NCI 2') {
        nciPanel.style.display = 'none';
        nciContent.innerHTML   = '';
        return;
    }

    nciPanel.style.display = 'block';
    nciContent.innerHTML   = '<small class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Looking up Ref No…</small>';

    try {
        const res  = await fetch(`select_customer.php?ajax=nci_lookup&id=${PRODUCT_ID}`);
        const data = await res.json();

        if (!data.ok) {
            nciContent.innerHTML = `<div class="alert alert-warning py-2 mb-0">
                <i class="bi bi-exclamation-triangle me-1"></i>${escHtml(data.msg)}
                <div class="mt-1" style="font-size:12px;">Please fill in Ref No manually.</div>
            </div>`;
            return;
        }

        // Fill Ref No with the raw part_no from the table (all values included).
        refNoInput.value = data.part_no_full;
        document.getElementById('nci_resolved_customer').value = data.customer_info;

        nciContent.innerHTML = `<div class="alert alert-info py-2 mb-0" style="font-size:12px;">
            <i class="bi bi-check-circle me-1"></i>
            Internal Code: <strong>${escHtml(data.internal_code)}</strong><br>
            Customer on sticker: <strong>${escHtml(data.customer_info)}</strong><br>
            Ref No: <strong>${escHtml(data.part_no_full)}</strong>
        </div>`;

    } catch (e) {
        nciContent.innerHTML = '<div class="alert alert-danger py-2 mb-0">Network error during lookup.</div>';
    }
}

// ── AJAX Save ──────────────────────────────────────────────────
async function saveOnly() {
    const btn      = document.getElementById('saveBtn');
    const selEl    = document.getElementById('customer');
    const refEl    = document.getElementById('ref_no');
    const customEl = document.getElementById('custom_customer');
    const ref_no   = refEl.value.trim();

    if (!selEl.value) {
        showFeedback(false, 'Please select a customer first.');
        selEl.focus();
        return;
    }
    if (!ref_no) {
        showFeedback(false, 'Ref No cannot be empty.');
        refEl.focus();
        return;
    }
    if (!refNoMatchesActiveRule()) {
        showFeedback(false, 'Ref No format is invalid for the selected customer.');
        refEl.focus();
        return;
    }

    btn.disabled  = true;
    btn.innerHTML = '<span class="spin"></span> Saving…';

    const fd = new FormData();
    fd.append('action',          'save_only');
    fd.append('id',              PRODUCT_ID);
    fd.append('customer',        selEl.value);
    fd.append('custom_customer', customEl.value.trim());
    fd.append('ref_no',          ref_no);

    let result;
    try {
        const resp = await fetch(window.location.href, {
            method:  'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body:    fd,
        });
        result = await resp.json();
    } catch (err) {
        result = { ok: false, msg: 'Network error: ' + err.message };
    }

    btn.disabled  = false;
    btn.innerHTML = '<i class="bi bi-floppy-fill"></i> Save';
    showFeedback(result.ok, result.msg);
}

function showFeedback(ok, msg) {
    const el = document.getElementById('saveFeedback');
    el.className = 'show ' + (ok ? 'state-ok' : 'state-error');
    el.innerHTML = (ok
        ? '<i class="bi bi-check-circle-fill"></i> '
        : '<i class="bi bi-exclamation-circle-fill"></i> '
    ) + escHtml(msg);
    if (ok) setTimeout(() => { el.className = ''; el.innerHTML = ''; }, 5000);
}

function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g,
        c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

document.getElementById('mainForm').addEventListener('submit', (e) => {
    if (!refNoMatchesActiveRule()) {
        e.preventDefault();
        showFeedback(false, 'Ref No format is invalid for the selected customer.');
        refNoInput.focus();
    }
});

// On load: restore state
(function () {
    const knownOptions = ['NAE','NAX','NCI MFG','TAIHO','NRI','ASHUKA',
                          'NIPPON','NTC','SGC','STAMPING','YANTAI','NIPP',
                          'NVC','NSJ','NIP','YTEC','NSA','NCI 2','STOCK','TRIAL',''];
    const saved = <?= json_encode($savedCustomer) ?>;
    if (saved !== '' && !knownOptions.includes(saved)) {
        const customEl = document.getElementById('custom_customer');
        customEl.value = saved;
        customEl.style.display = 'block';
    }

    const custVal = document.getElementById('customer').value;
    if (custVal === 'STOCK' || refNoInput.value.trim() === 'STOCK') {
        stockOverride.checked = true;
        refNoInput.readOnly = true;
    } else {
        applyMaskForCustomer(custVal);
    }

    checkNciMatch(custVal);
})();
</script>
</body>
</html>
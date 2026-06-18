<?php
include 'config.php';

if (!isset($_GET['id'])) {
    die("Product ID required");
}

$id = intval($_GET['id']);

// ── Fetch product ─────────────────────────────────────────────
$result = $conn->query("SELECT * FROM slitting_product WHERE id=$id");
$product = $result->fetch_assoc();

// ── Read existing saved customer & ref_no ─────────────────────
$savedCustomer = trim($product['customer_name'] ?? '');
$savedRefNo    = trim($product['ref_no']        ?? '');

// ═══════════════════════════════════════════════════════════════
// AJAX SAVE — only addition to the original file.
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

if (!$product) {
    die("Product not found");
}

// Gabungkan Lot No + Coil No
$lotCoil = trim($product['lot_no']) . ' ' . trim($product['coil_no']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Print Sticker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            padding: 40px 20px;
        }

        .preview-container {
            max-width: 650px;
            margin: 0 auto;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: relative;
        }

        .preview-table {
            width: 100%;
            margin-bottom: 30px;
        }

        .preview-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #eee;
        }

        .preview-table td:first-child {
            font-weight: bold;
            width: 130px;
            color: #333;
        }

        .preview-table td:nth-child(2) {
            width: 20px;
            text-align: center;
        }

        .preview-table td:last-child {
            color: #000;
            font-size: 18px;
        }

        .qr-preview {
            position: absolute;
            top: 40px;
            right: 40px;
            text-align: center;
            background: transparent !important;
        }

        .badge-text {
            display: inline-block;
            background: #e3f2fd;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            margin-bottom: 10px;
        }

        .qr-preview img {
            width: 120px;
            height: 120px;
            border: none;
            background: transparent;
            display: block;
            margin: 0 auto;
        }

        .roll-number {
            font-size: 48px;
            font-weight: bold;
            color: #333;
            margin-top: -10px;
        }

        .editable-row {
            background: #f8f9fa;
        }

        .form-control, .form-select {
            font-size: 16px;
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-action {
            padding: 12px 30px;
            font-size: 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-print {
            background: #0aa80a;
            color: white;
        }

        .btn-print:hover {
            background: #0aa80a;
        }

        /* ── Save button — only new style added ── */
        .btn-save {
            background: #1976D2;
            color: white;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-save:hover { background: #1565c0; }
        .btn-save:disabled { background: #90a4ae; cursor: not-allowed; }

        /* ── Save feedback — only new style added ── */
        #saveFeedback {
            display: none;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 600;
            margin-top: 10px;
        }
        #saveFeedback.show        { display: flex; justify-content: center; }
        #saveFeedback.state-ok    { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
        #saveFeedback.state-error { background: #fdecea; color: #c62828; border: 1px solid #ef9a9a; }

        /* Spinner */
        .spin {
            display: inline-block;
            width: 14px; height: 14px;
            border: 2px solid rgba(255,255,255,.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .btn-back {
            background: #757575;
            color: white;
            text-decoration: none;
            display: inline-block;
        }

        .btn-back:hover {
            background: #616161;
            color: white;
        }

       @media print {
    body {
        background: white !important;
        padding: 0 !important;
    }

    .preview-container {
        background: <?= $stickerBg ?> !important;

        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .action-buttons,
    .btn,
    .badge-text {
        display: none !important;
    }
}
    </style>
</head>
<body>

<!-- Preview Container -->
<div class="preview-container" style="background: white;">
<!-- QR Code -->
    <div class="qr-preview">
        <div class="badge-text">INTERNAL USE</div>
        <img src="generate_qr.php?id=<?= $id ?>&type=slitting" alt="QR Code">
        <div class="roll-number"><?= htmlspecialchars($product['roll_no'] ?? '') ?></div>
    </div>

    <form method="POST" action="print_product.php" id="mainForm">
        <input type="hidden" name="id" value="<?= $id ?>">
        
        <table class="preview-table">
            <tr>
                <td>TOMBO No.</td>
                <td>:</td>
                <td><strong><?= htmlspecialchars($product['tombo_no'] ?? '1600 (METAKOTE)') ?></strong></td>
            </tr>
            <tr>
                <td>Grade</td>
                <td>:</td>
                <td><strong><?= htmlspecialchars($product['product'] ?? '') ?></strong></td>
            </tr>
            <tr>
                <td>Size</td>
                <td>:</td>
                <td><strong><?= number_format($product['width'], 0) ?> mm x <?= number_format($product['actual_length'] ?? $product['length'], 0) ?> Mtr</strong></td>
            </tr>
            <tr>
                <td>Lot No.</td>
                <td>:</td>
                <td><strong><?= htmlspecialchars($lotCoil) ?></strong></td>
            </tr>
            
            <!-- Customer - Editable -->
            <tr class="editable-row">
                <td>Customer</td>
                <td>:</td>
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
                        <option value="NIP"     <?= $savedCustomer==='NIP'     ?'selected':'' ?>>NICHIAS IND.PRODUCTS PVT. LTD.</option>
                        <option value="NVC"     <?= $savedCustomer==='NVC'     ?'selected':'' ?>>NICHIAS VIETNAM CO., LTD</option>
                        <option value="NCS"     <?= $savedCustomer==='NCS'     ?'selected':'' ?>>NC-PT NICHIAS SUNIJAYA</option>
                        <option value="SNP"     <?= $savedCustomer==='SNP'     ?'selected':'' ?>>SUZHOU NICHIAS IND. PRODUCTS</option>
                        <option value="NCI 2"   <?= $savedCustomer==='NCI 2'   ?'selected':'' ?>>NCI 2</option>
                        <option value="STOCK"   <?= ($savedCustomer==='' || $savedCustomer==='STOCK') ?'selected':'' ?>>STOCK</option>
                        <option value="TRIAL"   <?= $savedCustomer==='TRIAL'   ?'selected':'' ?>>TRIAL</option>
                        <option value="OTHER"   <?= ($savedCustomer!=='' && !in_array($savedCustomer,['NAE','NAX','NCI MFG','TAIHO','NRI','ASHUKA','NIPPON','NTC','SGC','STAMPING','YANTAI','NIP','NVC','NCS','SNP','NCI 2','STOCK','TRIAL'])) ?'selected':'' ?>>OTHER (type below)</option>
                    </select>
                    <!-- Hidden custom customer field — shown when OTHER selected -->
                    <input type="text" name="custom_customer" id="custom_customer"
                           class="form-control mt-2" placeholder="Enter customer name"
                           style="display:none;">
                </td>
            </tr>
            
            <!-- Ref No - Editable -->
            <tr class="editable-row">
                <td>Ref. No.</td>
                <td>:</td>
                <td>
                    <input type="text" name="ref_no" id="ref_no"
                           class="form-control" value="<?= htmlspecialchars($savedRefNo ?: 'STOCK') ?>" required>
                </td>
            </tr>
        </table>

        <div class="action-buttons">
            <!-- ★ NEW: Save button — saves without printing ★ -->
            <button type="button" class="btn-action btn-save" id="saveBtn"
                    onclick="saveOnly()">
                <i class="bi bi-floppy-fill"></i> Save
            </button>

            <button type="submit" class="btn-action btn-print">
                <i class="bi bi-printer-fill"></i> Print Sticker
            </button>
            <a href="finish_product.php" class="btn-action btn-back">
                <i class="bi bi-arrow-left"></i> Back to List
            </a>
        </div>

        <!-- Save feedback — appears below buttons after AJAX save -->
        <div id="saveFeedback"></div>

    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const PRODUCT_ID = <?= (int)$id ?>;

// Show / hide custom customer text input
function handleCustomerChange(val) {
    document.getElementById('custom_customer').style.display =
        val === 'OTHER' ? 'block' : 'none';
}

// ── AJAX Save ─────────────────────────────────────────────────
async function saveOnly() {
    const btn      = document.getElementById('saveBtn');
    const selEl    = document.getElementById('customer');
    const refEl    = document.getElementById('ref_no');
    const customEl = document.getElementById('custom_customer');

    const ref_no = refEl.value.trim();

    // Basic client validation
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

    // Show spinner, disable button
    btn.disabled = true;
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

    // Restore button
    btn.disabled = false;
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

// ── On page load: reveal custom field if OTHER was saved ──────
(function () {
    const knownOptions = ['NAE','NAX','NCI MFG','TAIHO','NRI','ASHUKA',
                          'NIPPON','NTC','SGC','STAMPING','YANTAI','NIP',
                          'NVC','NCS','SNP','NCI 2','STOCK','TRIAL',''];
    const saved = <?= json_encode($savedCustomer) ?>;
    if (saved !== '' && !knownOptions.includes(saved)) {
        // Custom customer was saved — show the text input pre-filled
        const customEl = document.getElementById('custom_customer');
        customEl.value = saved;
        customEl.style.display = 'block';
    }
})();
</script>
</body>
</html>
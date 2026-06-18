<?php
session_start();
include 'config.php';

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
// AJAX SAVE ENDPOINT
// Called by the Save button via fetch() — no page reload.
// Returns JSON { ok: bool, msg: string }
// ═══════════════════════════════════════════════════════════════
if (
    isset($_POST['action']) && $_POST['action'] === 'save_customer' &&
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) {
    header('Content-Type: application/json');

    $id       = intval($_POST['id']       ?? 0);
    $customer = trim($_POST['customer']   ?? '');
    $ref_no   = trim($_POST['ref_no']     ?? '');

    if ($id <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid product ID.']);
        exit;
    }
    if ($customer === '') {
        echo json_encode(['ok' => false, 'msg' => 'Customer cannot be empty.']);
        exit;
    }

    $stmt = $conn->prepare(
        "UPDATE slitting_product SET customer_name = ?, ref_no = ? WHERE id = ?"
    );
    if (!$stmt) {
        echo json_encode(['ok' => false, 'msg' => 'DB prepare failed: ' . $conn->error]);
        exit;
    }
    $stmt->bind_param("ssi", $customer, $ref_no, $id);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode([
        'ok'  => $ok,
        'msg' => $ok
            ? "Saved: {$customer} / {$ref_no}"
            : 'Update failed: ' . $conn->error,
    ]);
    exit;
}

// ── Resolve ID, customer, ref_no ─────────────────────────────
$id       = null;
$customer = 'STOCK';
$ref_no   = 'STOCK';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id       = intval($_POST['id']);
    $customer = $_POST['customer'] === 'OTHER' ? $_POST['custom_customer'] : $_POST['customer'];
    $ref_no   = $_POST['ref_no'];
} elseif (isset($_GET['id'])) {
    $id       = intval($_GET['id']);
    $customer = $_GET['customer'] ?? 'STOCK';
    if ($customer === 'OTHER' && isset($_GET['custom_customer'])) {
        $customer = $_GET['custom_customer'];
    }
    $ref_no = $_GET['ref_no'] ?? 'STOCK';
} else {
    die("Product ID required");
}

// ── Fetch product ─────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT sp.*, mc.product as mother_product, sw.std_weight
    FROM slitting_product sp
    LEFT JOIN mother_coil mc ON sp.mother_id = mc.id
    LEFT JOIN std_wgt sw ON sp.product = sw.product_code
    WHERE sp.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) die("Product not found");
$product = $result->fetch_assoc();

// ── NCI auto-lookup ───────────────────────────────────────────
if ($customer === 'NCI') {
    $product_width = intval($product['width']);
    $stmt_nci = $conn->prepare("SELECT * FROM nci_product_mapping
                WHERE grade = ?
                AND CAST(REPLACE(size_width, ' mm', '') AS UNSIGNED) = ?
                LIMIT 1");
    $stmt_nci->bind_param("si", $product['product'], $product_width);
    $stmt_nci->execute();
    $result_nci = $stmt_nci->get_result();
    if ($result_nci->num_rows > 0) {
        $nci_data = $result_nci->fetch_assoc();
        if ($nci_data['customer']) $customer = $nci_data['customer'];
        if ($ref_no === 'STOCK' && $nci_data['part_no']) $ref_no = $nci_data['part_no'];
    }
    $stmt_nci->close();
}

// ── NCI 2 resolution ─────────────────────────────────────────
if ($customer === 'NCI 2') {
    $coil_no = $product['coil_no'] ?? '';
    $width   = (int)($product['width'] ?? 0);
    $prefix  = getCoilPrefix($coil_no);

    if ($prefix !== '' && $width > 0) {
        $internal_code = $prefix . '-' . $width;
        $stmt_nci2 = $conn->prepare("
            SELECT customer, part_no
            FROM nci_product_mapping
            WHERE internal_code = ?
            LIMIT 1
        ");
        $stmt_nci2->bind_param("s", $internal_code);
        $stmt_nci2->execute();
        $row = $stmt_nci2->get_result()->fetch_assoc();
        $stmt_nci2->close();

        if ($row) {
            $customer = $row['customer'];
            $ref_no   = $row['part_no'];
        }
    }
}

// ── Determine pattern ─────────────────────────────────────────
$pattern = 'pattern2';

$original_customer_request = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $original_customer_request = $_POST['customer'] === 'OTHER'
        ? $_POST['custom_customer']
        : $_POST['customer'];
} elseif (isset($_GET['id'])) {
    $original_customer_request = $_GET['customer'] ?? 'STOCK';
    if ($original_customer_request === 'OTHER' && isset($_GET['custom_customer'])) {
        $original_customer_request = $_GET['custom_customer'];
    }
}

$pattern1_customers = ['NAE', 'NRI', 'STAMPING'];
$pattern2_customers = ['NAX', 'TAIHO', 'ASHUKA', 'NTC', 'STOCK', 'NCI MFG', 'NIP', 'SGC', 'MTX', 'NVC', 'NCS', 'SNP'];
$pattern3_customers = ['YANTAI'];
$pattern4_customers = ['NCI 2'];

if      (in_array($original_customer_request, $pattern1_customers)) $pattern = 'pattern1';
elseif  (in_array($original_customer_request, $pattern2_customers)) $pattern = 'pattern2';
elseif  (in_array($original_customer_request, $pattern3_customers)) $pattern = 'pattern3';
elseif  (in_array($original_customer_request, $pattern4_customers)) $pattern = 'pattern4';

// ── Sticker data ──────────────────────────────────────────────
$tomboNo = "1600 (METAKOTE)";
if (strpos($product['product'], 'MV') !== false) $tomboNo = "1608 (METAFOAM)";

$lotNo = trim($product['lot_no']) . ' ' . trim($product['coil_no']);

$protocol   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$host       = $_SERVER['HTTP_HOST'];
$basePath   = rtrim(dirname($_SERVER['PHP_SELF']), '/');
$qrImageUrl = $protocol . "://" . $host . $basePath . "/generate_qr.php?id=" . $id . "&type=slitting";

// ── Sticker colour ────────────────────────────────────────────
$PRODUCT_COLOR = [
    "DS-3020"=>"GREEN","DS-3825"=>"GREEN","DS-4525"=>"GREEN",
    "DS-5030"=>"GREEN","DS-8460"=>"GREEN",
    "GB-6440"=>"YELLOW","GB-6440-S101"=>"YELLOW","KB-6440"=>"YELLOW",
    "JV-3825"=>"WHITE","JZ-2520"=>"WHITE","JZ-2520-2C"=>"WHITE",
    "JZ-2820"=>"WHITE","JZ-3020"=>"WHITE","JZ-4020"=>"WHITE",
    "L1N2-2520-02"=>"WHITE","LN-1715-1"=>"WHITE","LN-2520"=>"WHITE",
    "LN-2520-04"=>"WHITE","LZ-2420"=>"WHITE","LZ-2520"=>"WHITE",
    "MV-4020"=>"WHITE","PS-6020"=>"WHITE","PS-8525"=>"WHITE",
    "TS-2620"=>"WHITE","TS-3020"=>"WHITE","TS-3525"=>"WHITE",
    "TS-4025"=>"WHITE","TS-4525"=>"WHITE","TS-5030"=>"WHITE",
    "TS-9080"=>"WHITE","TU-2620"=>"WHITE","TU-2620-C"=>"WHITE",
    "TU-3020"=>"WHITE","TU-4020"=>"WHITE","YW-2520"=>"WHITE",
    "RS-3020"=>"BLUE","RS-3825"=>"BLUE","RS-3825-04"=>"BLUE",
    "RS-4020"=>"BLUE","RS-4025"=>"BLUE","RS-4525"=>"BLUE",
    "RS-5030"=>"BLUE","RS-6040"=>"BLUE","RS-7050"=>"BLUE",
    "RU-5040-1"=>"BLUE","RU-5040-1-S101"=>"BLUE","RV-3825"=>"BLUE",
];
$BG        = ['BLUE'=>'#0099ff','GREEN'=>'#129e16','YELLOW'=>'#FFFF00','WHITE'=>'#ffffff'];
$gradeKey  = strtoupper(trim($product['product'] ?? ''));
$colorName = $PRODUCT_COLOR[$gradeKey] ?? 'WHITE';

// ── NCI 2 colour override ─────────────────────────────────────
if ($original_customer_request === 'NCI 2') {
    $NCI2_COLOR = [
        'RS-3825|115' => 'GREEN', 'RS-3825|120' => 'GREEN',
        'RS-4020|125' => 'GREEN',
        'KB-6440|101' => 'BLUE',  'KB-6440|111' => 'BLUE',
        'KB-6440|113' => 'BLUE',  'KB-6440|136' => 'BLUE',
        'KB-6440|137' => 'BLUE',  'KB-6440|141' => 'BLUE',
        'KB-6440|155' => 'BLUE',  'KB-6440|167' => 'BLUE',
        'KB-6440|210' => 'BLUE',
        'TU-3020|313' => 'GREEN',
        'TS-3525|154' => 'GREEN', 'TS-3525|89'  => 'GREEN',
        'TU-4020|313' => 'GREEN',
    ];
    $nci2ColorKey = $gradeKey . '|' . (string)(int)($product['width'] ?? 0);
    if (isset($NCI2_COLOR[$nci2ColorKey])) {
        $colorName = $NCI2_COLOR[$nci2ColorKey];
    }
}

$stickerBg = $BG[$colorName] ?? '#ffffff';

// ── Load sticker pattern ──────────────────────────────────────
$patternFile = "sticker_patterns/{$pattern}.php";
if (!file_exists($patternFile)) die("Error: Pattern file not found.");
include $patternFile;

$isPreview = isset($_GET['customer']) || isset($_POST['customer']);

// ── Save on normal POST (print path) ─────────────────────────
$skip = ['STOCK', 'TRIAL', 'SFC', ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id > 0 && !in_array($customer, $skip, true)) {
    // Only save when coming from the print form (not from the AJAX save above)
    if (!isset($_POST['action'])) {
        $stmt = $conn->prepare("UPDATE slitting_product SET customer_name=?, ref_no=? WHERE id=?");
        if ($stmt) {
            $stmt->bind_param("ssi", $customer, $ref_no, $id);
            $stmt->execute();
            $stmt->close();
        }
    }
}

// ── Was this just saved (via normal POST)?  ──────────────────
// Pass a flag so the JS can show the confirmation without AJAX
$justSaved = (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    !isset($_POST['action']) &&
    $id > 0
);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Print Sticker - <?= htmlspecialchars($customer) ?></title>
    <style>
        @media print {
            @page { size: 120mm 47mm; margin: 0; }
            body  { margin: 0; padding: 0; }
            .no-print { display: none !important; }
            .qs24-floating-btn,[class*="floating"]{display:none!important;visibility:hidden!important;opacity:0!important;}
            .sticker-bg-wrap {
                background: #ffffff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:Arial,sans-serif; background:#f5f5f5; }
        @media screen { body { padding:20px; } }

        <?php echo $patternCSS; ?>

        .sticker-bg-wrap .p1-sticker,
        .sticker-bg-wrap .p2-sticker,
        .sticker-bg-wrap .p3-sticker,
        .sticker-bg-wrap .p4-sticker,
        .sticker-bg-wrap .sticker-container
        { background:transparent!important; background-color:transparent!important; }

        /* ── Info bar ── */
        .info-bar {
            max-width:120mm; margin:0 auto 10px; padding:10px;
            background:#e3f2fd; border-left:4px solid #2196F3; border-radius:4px;
            font-size:13px;
        }
        .info-bar strong { color:#1976D2; }

        /* ── Sticker wrapper ── */
        .sticker-bg-wrap {
            width:120mm; height:47mm;
            margin:0 auto; overflow:hidden; position:relative;
        }
        @media screen {
            .sticker-bg-wrap {
                background: <?= $stickerBg ?> !important;
                box-shadow: 0 2px 10px rgba(0,0,0,.12);
                border-radius: 6px;
            }
        }
        @media print {
            .sticker-bg-wrap { box-shadow:none!important; border-radius:0!important; }
        }

        /* ── Action toolbar ── */
        .action-toolbar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
            max-width: 500px;
            margin: 16px auto 0;
        }

        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 10px 22px; font-size: 14px; font-weight: 600;
            cursor: pointer; border: none; border-radius: 6px;
            text-decoration: none; transition: all .15s;
        }
        .btn:hover { opacity: .88; transform: translateY(-1px); }
        .btn:active { transform: translateY(0); }

        .btn-print  { background: #4CAF50; color: #fff; }
        .btn-save   { background: #1976D2; color: #fff; }
        .btn-back   { background: #666;    color: #fff; }

        /* ── Save feedback ── */
        #saveFeedback {
            display: none;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            transition: opacity .3s;
        }
        #saveFeedback.show        { display: inline-flex; }
        #saveFeedback.state-ok    { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
        #saveFeedback.state-error { background: #fdecea; color: #c62828; border: 1px solid #ef9a9a; }

        /* Spinner inside Save button */
        .spin {
            display: inline-block;
            width: 14px; height: 14px;
            border: 2px solid rgba(255,255,255,.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .6s linear infinite;
            flex-shrink: 0;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        button[type="button"]:not(.btn), .floating-btn, iframe { display:none!important; }
    </style>
</head>
<body>

<?php if ($isPreview): ?>
<div class="no-print info-bar">
    <strong>Preview</strong> &nbsp;·&nbsp;
    Pattern: <?= ucfirst($pattern) ?> &nbsp;·&nbsp;
    Customer: <?= htmlspecialchars($customer) ?> &nbsp;·&nbsp;
    Ref No: <?= htmlspecialchars($ref_no) ?> &nbsp;·&nbsp;
    Colour: <?= htmlspecialchars($colorName) ?>
</div>
<?php endif; ?>

<div class="sticker-bg-wrap">
<?php
if (function_exists('render_sticker')) {
    echo render_sticker($product, $customer, $ref_no, $tomboNo, $lotNo, $qrImageUrl);
} else {
    echo "<div style='padding:20px;background:red;color:white;'>Error: render_sticker() not found.</div>";
}
?>
</div>

<div class="no-print action-toolbar">

    <!-- Print button — triggers browser print dialog -->
    <button type="button" class="btn btn-print" onclick="window.print()">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
            <path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/>
            <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4V3zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2H5zm7 2v3a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1z"/>
        </svg>
        Print Sticker
    </button>

    <!-- Save button — AJAX, no reload, no print dialog -->
    <button type="button" class="btn btn-save" id="saveBtn"
            onclick="saveCustomerData()">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
            <path d="M2 1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H9.5a1 1 0 0 0-1 1v7.293l2.646-2.647a.5.5 0 0 1 .708.708l-3.5 3.5a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L7.5 9.293V2a2 2 0 0 1 2-2H14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2h2.5a.5.5 0 0 1 0 1H2z"/>
        </svg>
        Save
    </button>

    <!-- Inline feedback (shown after save attempt) -->
    <span id="saveFeedback"></span>

    <!-- Navigation buttons -->
    <a href="select_customer.php?id=<?= $id ?>" class="btn btn-back">
        ← Change Customer
    </a>
    <a href="finish_product.php" class="btn btn-back">
        ← Back to List
    </a>

</div>

<script>
// ── Data to save ──────────────────────────────────────────────
const PRODUCT_ID = <?= (int)$id ?>;
const CUSTOMER   = <?= json_encode($customer) ?>;
const REF_NO     = <?= json_encode($ref_no) ?>;

// ── Show instant feedback if this page was loaded from a normal
//    POST (old print-to-save path) — so user knows it was saved
<?php if ($justSaved): ?>
window.addEventListener('DOMContentLoaded', () => {
    showFeedback(true, 'Saved: <?= addslashes(htmlspecialchars($customer)) ?> / <?= addslashes(htmlspecialchars($ref_no)) ?>');
});
<?php endif; ?>

// ── Save via AJAX ─────────────────────────────────────────────
async function saveCustomerData() {
    const btn = document.getElementById('saveBtn');

    // Show spinner
    btn.disabled = true;
    btn.innerHTML = '<span class="spin"></span> Saving…';

    const fd = new FormData();
    fd.append('action',   'save_customer');
    fd.append('id',       PRODUCT_ID);
    fd.append('customer', CUSTOMER);
    fd.append('ref_no',   REF_NO);

    let result;
    try {
        const resp = await fetch(window.location.pathname, {
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
    btn.innerHTML = `
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
            <path d="M2 1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H9.5a1 1 0 0 0-1 1v7.293l2.646-2.647a.5.5 0 0 1 .708.708l-3.5 3.5a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L7.5 9.293V2a2 2 0 0 1 2-2H14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2h2.5a.5.5 0 0 1 0 1H2z"/>
        </svg>
        Save`;

    showFeedback(result.ok, result.msg);
}

function showFeedback(ok, msg) {
    const el = document.getElementById('saveFeedback');
    el.className = 'show ' + (ok ? 'state-ok' : 'state-error');
    el.innerHTML = (ok
        ? '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/></svg>'
        : '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/><path d="M7.002 11a1 1 0 1 1 2 0 1 1 0 0 1-2 0zM7.1 4.995a.905.905 0 1 1 1.8 0l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 4.995z"/></svg>'
    ) + ' ' + escHtml(msg);

    // Auto-dismiss success after 4 s
    if (ok) setTimeout(() => { el.className = ''; }, 4000);
}

function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g,
        c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
</script>

</body>
</html>
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

// ── NCI auto-lookup (original NCI customer) ───────────────────
// This handles the case where customer = 'NCI' (single auto-match by grade+width)
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
// MUST run before pattern selection and render_sticker(), so the
// correct customer + part_no are used in pattern4 and the sticker.
//
// Logic: derive the internal_code from the coil prefix + width
// (e.g. coil "A12" with width 115 → internal_code "A-115"),
// then look up customer + part_no from nci_product_mapping.
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
        // If no mapping found, customer stays 'NCI 2' and pattern4
        // will still be used — the sticker will show 'NCI 2' and
        // the original ref_no so the operator can notice the gap.
    }
}

// ── Determine pattern ─────────────────────────────────────────
// NOTE: pattern4 is keyed on the *original* customer value 'NCI 2'.
// After the NCI 2 block above, $customer has been overwritten with
// the real customer name (e.g. "DELPHI (Mexico)"), so we can no
// longer rely on in_array() for pattern4. We capture the pattern
// BEFORE the customer is overwritten, or use a flag.
//
// Solution: check for NCI 2 before the resolution block above is
// needed — we do this by tracking whether the incoming customer
// was 'NCI 2'.

// Re-read the original requested customer to decide the pattern.
$pattern = 'pattern2';

// Determine the originally-requested customer (before any NCI resolution)
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
$pattern2_customers = ['NAX', 'TAIHO', 'ASHUKA', 'NTC', 'STOCK', 'NCI MFG', 'NIP', 'SGC', 'MTX'];
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

// ── Sticker colour — MUST be set BEFORE include $patternFile ─
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
// For NCI 2 orders, colour is determined by product grade + width,
// overriding the default grade-only lookup above.
// Key format: "GRADE|WIDTH_MM"  (width as plain integer string)
if ($original_customer_request === 'NCI 2') {
    $NCI2_COLOR = [
        // RS-3825 — default is BLUE; NCI 2 uses GREEN for these widths
        'RS-3825|115' => 'GREEN',
        'RS-3825|120' => 'GREEN',
        // RS-4020 — default is BLUE; NCI 2 uses GREEN
        'RS-4020|125' => 'GREEN',
        // KB-6440 — default is YELLOW; NCI 2 uses BLUE for all widths
        'KB-6440|101' => 'BLUE',
        'KB-6440|111' => 'BLUE',
        'KB-6440|113' => 'BLUE',
        'KB-6440|136' => 'BLUE',
        'KB-6440|137' => 'BLUE',
        'KB-6440|141' => 'BLUE',
        'KB-6440|155' => 'BLUE',
        'KB-6440|167' => 'BLUE',
        'KB-6440|210' => 'BLUE',
        // TU-3020 — default is WHITE; NCI 2 uses GREEN
        'TU-3020|313' => 'GREEN',
        // TS-3525 — default is WHITE; NCI 2 uses GREEN
        'TS-3525|154' => 'GREEN',
        'TS-3525|89'  => 'GREEN',
        // TU-4020 — default is WHITE; NCI 2 uses GREEN
        'TU-4020|313' => 'GREEN',
    ];
    $nci2ColorKey = $gradeKey . '|' . (string)(int)($product['width'] ?? 0);
    if (isset($NCI2_COLOR[$nci2ColorKey])) {
        $colorName = $NCI2_COLOR[$nci2ColorKey];
    }
}

$stickerBg = $BG[$colorName] ?? '#ffffff';

// ── Load pattern AFTER $colorName is set ─────────────────────
$patternFile = "sticker_patterns/{$pattern}.php";
if (!file_exists($patternFile)) die("Error: Pattern file not found.");
include $patternFile;

$isPreview = isset($_GET['customer']) || isset($_POST['customer']);

// ── Save customer_name + ref_no ───────────────────────────────
$skip = ['STOCK', 'TRIAL', 'SFC', ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id > 0 && !in_array($customer, $skip, true)) {
    $stmt = $conn->prepare("UPDATE slitting_product SET customer_name=?, ref_no=? WHERE id=?");
    if ($stmt) {
        $stmt->bind_param("ssi", $customer, $ref_no, $id);
        $stmt->execute();
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Print Sticker - <?= htmlspecialchars($customer) ?></title>
    <style>
        @media print {
            @page { size: 120mm 47mm; margin: 0; }
            body  { margin: 0; padding: 0; }
            .no-print { display: none; }
            .qs24-floating-btn,[class*="floating"]{display:none!important;visibility:hidden!important;opacity:0!important;}
            .sticker-bg-wrap {
                background: #ffffff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:Arial,sans-serif; padding:0; background:#f5f5f5; }
        @media screen { body { padding:20px; } }

        <?php echo $patternCSS; ?>

        /* Force inner elements transparent so wrapper bg colour shows on screen */
        .sticker-bg-wrap .p1-sticker,
        .sticker-bg-wrap .p2-sticker,
        .sticker-bg-wrap .p3-sticker,
        .sticker-bg-wrap .p4-sticker,
        .sticker-bg-wrap .sticker-container
        { background:transparent!important; background-color:transparent!important; }

        .no-print { text-align:center; margin:20px 0; }
        .info-bar {
            max-width:120mm; margin:0 auto 10px; padding:10px;
            background:#e3f2fd; border-left:4px solid #2196F3; border-radius:4px;
        }
        .info-bar strong { color:#1976D2; }
        .btn { padding:10px 30px; font-size:16px; cursor:pointer; margin:0 10px; border:none; border-radius:4px; }
        .btn-print { background:#4CAF50; color:white; }
        .btn-back  { background:#666; color:white; text-decoration:none; display:inline-block; }
        button[type="button"]:not(.btn),.floating-btn,iframe { display:none!important; }

        /* Sticker wrapper — coloured on screen, white on print */
        .sticker-bg-wrap {
            width:120mm; height:47mm;
            margin:0 auto; overflow:hidden; position:relative;
        }
        @media screen {
            .sticker-bg-wrap {
                background: <?= $stickerBg ?> !important;
                box-shadow: 0 2px 10px rgba(0,0,0,0.12);
                border-radius: 6px;
            }
        }
        @media print {
            .sticker-bg-wrap {
                box-shadow: none !important;
                border-radius: 0 !important;
            }
        }
    </style>
</head>
<body>

<?php if ($isPreview): ?>
<div class="no-print info-bar">
    <strong>Preview Mode</strong> |
    Pattern: <?= ucfirst($pattern) ?> |
    Customer: <?= htmlspecialchars($customer) ?> |
    Ref No: <?= htmlspecialchars($ref_no) ?> |
    Color: <?= htmlspecialchars($colorName) ?>
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

<div class="no-print">
    <button type="button" class="btn btn-print" onclick="window.print()">Print Sticker</button>
    <a href="select_customer.php?id=<?= $id ?>" class="btn btn-back">← Edit Customer/Ref</a>
    <a href="finish_product.php" class="btn btn-back">← Back to List</a>
</div>

</body>
</html>
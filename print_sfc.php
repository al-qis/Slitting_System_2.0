<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if (!in_array($_SESSION['role'], ['slitting', 'mkl3'], true)) {
    die("Access denied for this role.");
}

include 'config.php';

// ===== HELPER: coil prefix (same as print_sticker.php) =====
function getCoilPrefix($coil_no) {
    $coil_no = trim((string)$coil_no);
    if ($coil_no === '') return '';
    if (strpos($coil_no, '-') !== false) {
        return strtoupper(trim(explode('-', $coil_no)[0]));
    }
    preg_match('/^[A-Za-z]+/', $coil_no, $m);
    return strtoupper($m[0] ?? '');
}

// ===== VALIDATE SFC ID =====
$sfc_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($sfc_id <= 0) die("Error: Invalid SFC ID.");

// ===== FETCH SFC RECORD =====
$stmt = $conn->prepare("SELECT * FROM sfc WHERE sfc_id = ?");
$stmt->bind_param("i", $sfc_id);
$stmt->execute();
$sfc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sfc) die("Error: SFC record not found for ID #$sfc_id.");

// ===== FETCH GRADE + MOTHER DATA via lot_no =====
$grade      = '-';
$mother_id  = null;
$std_weight = null;

$lot_base = rtrim($sfc['lot_no'], 'abcdefghijklmnopqrstuvwxyz');
$lot_like = $lot_base . '%';

$mc_stmt = $conn->prepare(
    "SELECT mc.id, mc.grade, sw.std_weight
     FROM mother_coil mc
     LEFT JOIN std_wgt sw ON mc.product = sw.product_code
     WHERE mc.lot_no LIKE ?
     LIMIT 1"
);
$mc_stmt->bind_param("s", $lot_like);
$mc_stmt->execute();
$mc_row = $mc_stmt->get_result()->fetch_assoc();
$mc_stmt->close();

if ($mc_row) {
    $grade      = $mc_row['grade'];
    $mother_id  = $mc_row['id'];
    $std_weight = $mc_row['std_weight'];
}

// ===== BUILD PRODUCT ARRAY to match what pattern files expect =====
// Pattern files use $product[] keys identical to slitting_product columns.
// We map sfc fields onto those same keys so patterns work unchanged.
$product = [
    'id'            => $sfc['sfc_id'],
    'product'       => $sfc['product'],          // grade code e.g. "LN-2520"
    'lot_no'        => $sfc['lot_no'],
    'coil_no'       => $sfc['coil_no'],
    // Suppress "BALANCE" — only real roll numbers like R1, R2 appear on the sticker
    'roll_no'       => (strtoupper(trim($sfc['roll_no'])) === 'BALANCE') ? '' : $sfc['roll_no'],
    'width'         => $sfc['width'],
    'length'        => $sfc['length'],
    'actual_length' => $sfc['length'],            // SFC has no actual_length; use length
    'mother_id'     => $mother_id,
    'mother_product'=> $grade,
    'std_weight'    => $std_weight,
    'status'        => 'IN',
    'source'        => 'sfc',
];

// ===== CUSTOMER & REF NO =====
// SFC internal labels are always STOCK unless overridden via GET
// SFC labels always show SFC as customer and ref — constant, not user-selectable
$customer = 'SFC';
$ref_no   = 'SFC';

// ===== DETERMINE PATTERN (same logic as print_sticker.php) =====
$pattern = 'pattern2'; // default

$pattern1_customers = ['NAE', 'NRI', 'STAMPING'];
$pattern2_customers = ['NAX', 'TAIHO', 'ASHUKA', 'NTC', 'STOCK', 'NCI MFG', 'NIP'];
$pattern3_customers = ['YANTAI'];
$pattern4_customers = ['NCI 2'];

if      (in_array($customer, $pattern1_customers)) $pattern = 'pattern1';
elseif  (in_array($customer, $pattern2_customers)) $pattern = 'pattern2';
elseif  (in_array($customer, $pattern3_customers)) $pattern = 'pattern3';
elseif  (in_array($customer, $pattern4_customers)) $pattern = 'pattern4';

// ===== TOMBO No (same logic as print_sticker.php) =====
$tomboNo = "1600 (METAKOTE)";
if (strpos($product['product'], 'MV') !== false) {
    $tomboNo = "1608 (METAFOAM)";
}

// ===== LOT NO display string =====
$lotNo = trim($product['lot_no']) . ' ' . trim($product['coil_no']);

// ===== QR IMAGE URL =====
// Points to generate_qr.php — we pass lot+coil so the same scanner logic works.
// SFC rolls use the mother-coil QR format (LOT=...;COIL=...) since there is no
// slitting_product row for an SFC item.
$protocol   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$host       = $_SERVER['HTTP_HOST'];
$basePath   = rtrim(dirname($_SERVER['PHP_SELF']), '/');
$qrImageUrl = $protocol . "://" . $host . $basePath
            . "/generate_qr.php"
            . "?lot="  . urlencode($product['lot_no'])
            . "&coil=" . urlencode($product['coil_no']);

// ===== LOAD PATTERN FILE =====
$patternFile = "sticker_patterns/{$pattern}.php";
if (!file_exists($patternFile)) {
    die("Error: Pattern file '{$pattern}.php' not found in sticker_patterns/ folder.");
}
include $patternFile;

// ===== STICKER BACKGROUND COLOR MAP (identical to print_sticker.php) =====
$PRODUCT_COLOR = [
    "DS-3020"           => "GREEN",
    "DS-3825"           => "GREEN",
    "DS-4525"           => "GREEN",
    "DS-5030"           => "GREEN",
    "DS-8460"           => "GREEN",

    "GB-6440"           => "YELLOW",
    "GB-6440-S101"      => "YELLOW",
    "KB-6440"           => "YELLOW",

    "JV-3825"           => "WHITE",
    "JZ-2520"           => "WHITE",
    "JZ-2520-2C"        => "WHITE",
    "JZ-2820"           => "WHITE",
    "JZ-3020"           => "WHITE",
    "JZ-4020"           => "WHITE",
    "L1N2-2520-02"      => "WHITE",
    "LN-1715-1"         => "WHITE",
    "LN-2520"           => "WHITE",
    "LN-2520-04"        => "WHITE",
    "LZ-2420"           => "WHITE",
    "LZ-2520"           => "WHITE",
    "MV-4020"           => "WHITE",
    "PS-6020"           => "WHITE",
    "PS-8525"           => "WHITE",
    "TS-2620"           => "WHITE",
    "TS-3020"           => "WHITE",
    "TS-3525"           => "WHITE",
    "TS-4025"           => "WHITE",
    "TS-4525"           => "WHITE",
    "TS-5030"           => "WHITE",
    "TS-9080"           => "WHITE",
    "TU-2620"           => "WHITE",
    "TU-2620-C"         => "WHITE",
    "TU-3020"           => "WHITE",
    "TU-4020"           => "WHITE",
    "YW-2520"           => "WHITE",

    "RS-3020"           => "BLUE",
    "RS-3825"           => "BLUE",
    "RS-3825-04"        => "BLUE",
    "RS-4020"           => "BLUE",
    "RS-4025"           => "BLUE",
    "RS-4525"           => "BLUE",
    "RS-5030"           => "BLUE",
    "RS-6040"           => "BLUE",
    "RS-7050"           => "BLUE",
    "RU-5040-1"         => "BLUE",
    "RU-5040-1-S101"    => "BLUE",
    "RV-3825"           => "BLUE",
];

$gradeKey   = strtoupper(trim($product['product'] ?? ''));
$colorName  = $PRODUCT_COLOR[$gradeKey] ?? 'WHITE';

$BG = [
    'BLUE'   => '#0099ff',
    'GREEN'  => '#129e16',
    'YELLOW' => '#FFFF00',
    'WHITE'  => '#ffffff',
];

$stickerBg = $BG[$colorName] ?? '#ffffff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print SFC Label #<?= $sfc_id ?></title>
    <style>
        @media print {
            @page {
                size: 120mm 47mm;
                margin: 0;
            }
            body { margin: 0; padding: 0; }
            .no-print { display: none; }

            .qs24-floating-btn,
            [class*="floating"] {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
            }

            .sticker-bg-wrap {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            padding: 0;
        }

        @media screen {
            body { padding: 20px; }
        }

        /* ===== INJECT PATTERN CSS ===== */
        <?php echo $patternCSS; ?>

        /* Force sticker inner elements to use transparent bg so wrapper colour shows */
        .sticker-bg-wrap .sticker,
        .sticker-bg-wrap .sticker-container,
        .sticker-bg-wrap .sticker-wrap,
        .sticker-bg-wrap .sticker-area,
        .sticker-bg-wrap .label,
        .sticker-bg-wrap .label-container {
            background: transparent !important;
            background-color: transparent !important;
        }

        .sticker-bg-wrap table,
        .sticker-bg-wrap tr,
        .sticker-bg-wrap td,
        .sticker-bg-wrap th {
            background: transparent !important;
            background-color: transparent !important;
        }

        /* ===== COMMON ===== */
        .no-print {
            text-align: center;
            margin: 20px 0;
        }

        .info-bar {
            max-width: 120mm;
            margin: 0 auto 10px;
            padding: 10px;
            background: #e3f2fd;
            border-left: 4px solid #2196F3;
            border-radius: 4px;
            font-size: 13px;
        }

        .info-bar strong { color: #1976D2; }

        .btn {
            padding: 10px 30px;
            font-size: 16px;
            cursor: pointer;
            margin: 0 10px;
            border: none;
            border-radius: 4px;
        }

        .btn-print  { background: #4CAF50; color: white; }
        .btn-back   { background: #666; color: white; text-decoration: none; display: inline-block; }

        button[type="button"]:not(.btn),
        .floating-btn,
        iframe { display: none !important; }

        /* ===== STICKER BACKGROUND WRAPPER ===== */
        .sticker-bg-wrap {
            width: 120mm;
            height: 47mm;
            margin: 0 auto;
            overflow: hidden;
            position: relative;
        }

        /* Colour is SCREEN ONLY — visual alert to tell user which sticker colour to prepare */
        @media screen {
            .sticker-bg-wrap {
                background: <?= $stickerBg ?> !important;
                box-shadow: 0 2px 10px rgba(0,0,0,0.12);
                border-radius: 6px;
            }
        }

        /* On print: always white — text prints clean on the physical blank sticker */
        @media print {
            .sticker-bg-wrap {
                background: #ffffff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }
        }
    </style>
</head>
<body>

<!-- Info bar (screen only) -->
<div class="no-print info-bar">
    <strong>SFC Label Preview</strong> |
    SFC ID: #<?= $sfc_id ?> |
    Pattern: <?= ucfirst($pattern) ?> |
    Customer: <?= htmlspecialchars($customer) ?> |
    Sticker Color: <?= htmlspecialchars($colorName) ?>
</div>

<?php
// Render sticker using the same pattern render_sticker() function
echo '<div class="sticker-bg-wrap">';
if (function_exists('render_sticker')) {
    echo render_sticker($product, $customer, $ref_no, $tomboNo, $lotNo, $qrImageUrl);
} else {
    echo "<div style='padding:20px; background:red; color:white;'>Error: render_sticker() not found in pattern file!</div>";
}
echo '</div>';
?>

<div class="no-print">
    <button class="btn btn-print" onclick="window.print()">🖨 Print Sticker</button>
    <a href="sfc.php" class="btn btn-back">← Back to List</a>
</div>

</body>
</html>
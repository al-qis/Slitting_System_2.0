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

// Embed mode - used when this page is loaded inside an <iframe> for a
// consolidated bulk print job (bulk_print_sfc.php). Hides the toolbar /
// preview info bar and print/back buttons so only the 120mm x 47mm sticker
// itself renders, matching print_product.php and print_mother.php.
$embed = (($_GET['embed'] ?? '') === '1');

function getCoilPrefix($coil_no) {
    $coil_no = trim((string)$coil_no);
    if ($coil_no === '') return '';
    if (strpos($coil_no, '-') !== false) {
        return strtoupper(trim(explode('-', $coil_no)[0]));
    }
    preg_match('/^[A-Za-z]+/', $coil_no, $m);
    return strtoupper($m[0] ?? '');
}

// ── Validate SFC ID ───────────────────────────────────────────
$sfc_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($sfc_id <= 0) die("Error: Invalid SFC ID.");

// ── Fetch SFC record ──────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM sfc WHERE sfc_id = ?");
$stmt->bind_param("i", $sfc_id);
$stmt->execute();
$sfc = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$sfc) die("Error: SFC record not found for ID #$sfc_id.");

// ── Fetch grade + std_weight via lot_no ───────────────────────
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

// ── Build $product array ──────────────────────────────────────
$rawRoll = trim($sfc['roll_no'] ?? '');
$hasRoll = ($rawRoll !== '' && strtoupper($rawRoll) !== 'BALANCE');
$rollRaw = strtoupper($rawRoll);
$rollDisplay = preg_replace('/^R(\d+)$/i', 'R-$1', $rollRaw);
if ($rollDisplay === '') $rollDisplay = $rollRaw;

$product = [
    'id'            => $sfc['sfc_id'],
    'product'       => $sfc['product'],
    'lot_no'        => $sfc['lot_no'],
    'coil_no'       => $sfc['coil_no'],
    'roll_no'       => $hasRoll ? $rawRoll : '',
    'width'         => $sfc['width'],
    'length'        => $sfc['length'],
    'actual_length' => $sfc['length'],
    'mother_id'     => $mother_id,
    'mother_product'=> $grade,
    'std_weight'    => $std_weight,
    'status'        => 'IN',
    'source'        => 'sfc',
];

// ── Customer & ref ────────────────────────────────────────────
$customer = 'SFC';
$ref_no   = 'SFC';

// ── Determine pattern ─────────────────────────────────────────
$pattern = 'pattern2';
$pattern1_customers = ['NAE', 'NRI', 'STAMPING'];
$pattern2_customers = ['NAX', 'TAIHO', 'ASHUKA', 'NTC', 'STOCK', 'NCI MFG', 'NIPP', 'NIP'];
$pattern3_customers = ['YANTAI'];
$pattern4_customers = ['NCI 2'];

if      (in_array($customer, $pattern1_customers)) $pattern = 'pattern1';
elseif  (in_array($customer, $pattern2_customers)) $pattern = 'pattern2';
elseif  (in_array($customer, $pattern3_customers)) $pattern = 'pattern3';
elseif  (in_array($customer, $pattern4_customers)) $pattern = 'pattern4';

// ── Tombo + Lot display ───────────────────────────────────────
$tomboNo = "1600 (METAKOTE)";
if (strpos($product['product'], 'MV') !== false) $tomboNo = "1608 (METAFOAM)";

$lotNo = trim($product['lot_no']) . ' ' . trim($product['coil_no']);

// ── QR URL ────────────────────────────────────────────────────
// Includes roll (when this SFC entry actually has one — "BALANCE" is
// blanked out above into $product['roll_no'], same as everywhere else
// this value is used) so a scan can uniquely identify this exact roll,
// not just its mother Lot+Coil, which is ambiguous when multiple rolls
// share the same mother coil.
$protocol   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$host       = $_SERVER['HTTP_HOST'];
$basePath   = rtrim(dirname($_SERVER['PHP_SELF']), '/');
$qrImageUrl = $protocol . "://" . $host . $basePath
            . "/generate_qr.php"
            . "?lot="  . urlencode($product['lot_no'])
            . "&coil=" . urlencode($product['coil_no']);
if (trim($product['roll_no'] ?? '') !== '') {
    $qrImageUrl .= "&roll=" . urlencode($product['roll_no']);
}
if (!empty($product['width'])) {
    $qrImageUrl .= "&width=" . urlencode($product['width']);
}
if (!empty($product['length'])) {
    $qrImageUrl .= "&length=" . urlencode($product['length']);
}

// ── Sticker colour — set BEFORE include $patternFile ─────────
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
$stickerBg = $BG[$colorName] ?? '#ffffff';

// ── Est. Wgt: ((length × width) / 1000) × std_weight ─────────
$est_wgt = null;
$_w_mm   = floatval($product['width']      ?? 0);
$_l_m    = floatval($product['length']     ?? 0);
$_std    = floatval($product['std_weight'] ?? 0);
if ($_w_mm > 0 && $_l_m > 0 && $_std > 0) {
    $est_wgt = round(($_l_m * $_w_mm / 1000) * $_std);
}

$colourDisplay = ucfirst(strtolower($colorName));

// ── Load pattern AFTER $colorName is set ─────────────────────
$patternFile = "sticker_patterns/{$pattern}.php";
if (!file_exists($patternFile)) {
    die("Error: Pattern file '{$pattern}.php' not found in sticker_patterns/ folder.");
}
include $patternFile;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print SFC Label #<?= $sfc_id ?></title>
    <style>
        @media print {
            @page { size: 120mm 47mm; margin: 0; }
            body  { margin: 0; padding: 0; }
            .no-print { display: none; }
            .qs24-floating-btn,[class*="floating"]{display:none!important;visibility:hidden!important;opacity:0!important;}
            .sticker-bg-wrap{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;}
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 0; }
        @media screen { body { padding: 20px; } }

        <?php echo $patternCSS; ?>

        .sticker-bg-wrap .sticker,.sticker-bg-wrap .sticker-container,
        .sticker-bg-wrap .sticker-wrap,.sticker-bg-wrap .sticker-area,
        .sticker-bg-wrap .label,.sticker-bg-wrap .label-container
        { background:transparent!important; background-color:transparent!important; }
        .sticker-bg-wrap table,.sticker-bg-wrap tr,
        .sticker-bg-wrap td,.sticker-bg-wrap th
        { background:transparent!important; background-color:transparent!important; }

        /* Force hide INTERNAL USE */
        .internal-use { display: none !important; }

        /* Hide default pattern roll block to avoid duplicate/conflicting positioning */
        .sticker-bg-wrap .p2-roll,
        .sticker-bg-wrap .p1-roll,
        .sticker-bg-wrap .p3-roll,
        .sticker-bg-wrap .p4-roll {
            display: none !important;
        }

        /* SFC text / Roll — directly below the QR code, right panel */
        .sfc-label-overlay {
            position: absolute;
            right: 1.5mm;
            width: 33mm;
            font-family: "Arial Narrow", Arial, sans-serif;
            color: #000;
            text-align: center;
            white-space: nowrap;
            pointer-events: none;
            z-index: 20;
        }

        /* When SFC has NO roll: single large SFC text */
        .sfc-label-overlay.sfc-no-roll {
            top: 30.5mm;
            font-size: 40px;
            font-weight: 500;
            line-height: 1;
        }

        /* When SFC HAS a roll: display both SFC tag and Roll No */
        .sfc-label-overlay.sfc-has-roll {
            top: 29.5mm;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .sfc-overlay-tag {
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 0.8px;
            line-height: 1.1;
        }

        .sfc-overlay-roll {
            font-size: 30px;
            font-weight: 600;
            line-height: 1;
            margin-top: 0.5mm;
        }

        .no-print { text-align: center; margin: 20px 0; }
        .info-bar {
            max-width: 120mm; margin: 0 auto 10px; padding: 10px;
            background: #e3f2fd; border-left: 4px solid #2196F3;
            border-radius: 4px; font-size: 13px;
        }
        .info-bar strong { color: #1976D2; }
        .btn { padding:10px 30px; font-size:16px; cursor:pointer; margin:0 10px; border:none; border-radius:4px; }
        .btn-print { background:#4CAF50; color:white; }
        .btn-back  { background:#666; color:white; text-decoration:none; display:inline-block; }
        button[type="button"]:not(.btn),.floating-btn,iframe { display:none!important; }

        /* Sticker wrapper */
        .sticker-bg-wrap {
            width: 120mm; height: 47mm;
            margin: 0 auto; overflow: hidden; position: relative;
        }
        @media screen {
            .sticker-bg-wrap {
                background: <?= $stickerBg ?> !important;
                box-shadow: 0 2px 10px rgba(0,0,0,0.12);
                border-radius: 6px;
            }
        }
        @media print {
            .sticker-bg-wrap { box-shadow:none!important; border-radius:0!important; }
        }

        /* Colour name — top-right, above QR */
        .colour-overlay {
            position: absolute;
            top: 1mm;
            right: 2mm;
            font-family: Arial, sans-serif;
            font-size: 7.5pt;
            font-weight: 900;
            color: #000;
            text-align: right;
            white-space: nowrap;
            pointer-events: none;
            z-index: 20;
        }

        <?php if ($embed): ?>
        html, body {
            margin: 0 !important;
            padding: 0 !important;
            background: transparent !important;
            overflow: hidden !important;
            width: 120mm !important;
            height: 47mm !important;
        }
        .info-bar, .no-print {
            display: none !important;
        }
        .sticker-bg-wrap {
            margin: 0 !important;
            box-shadow: none !important;
            border-radius: 0 !important;
        }
        <?php endif; ?>

    </style>
</head>
<body>

<?php if (!$embed): ?>
<div class="no-print info-bar">
    <strong>SFC Label Preview</strong> |
    SFC ID: #<?= $sfc_id ?> |
    Roll: <?= htmlspecialchars($product['roll_no'] !== '' ? $product['roll_no'] : '(none)') ?> |
    Pattern: <?= ucfirst($pattern) ?> |
    Customer: <?= htmlspecialchars($customer) ?> |
    Sticker Color: <?= htmlspecialchars($colorName) ?>
</div>
<?php endif; ?>

<?php
echo '<div class="sticker-bg-wrap">';

if (function_exists('render_sticker')) {
    echo render_sticker($product, $customer, $ref_no, $tomboNo, $lotNo, $qrImageUrl);
} else {
    echo "<div style='padding:20px;background:red;color:white;'>Error: render_sticker() not found in pattern file!</div>";
}


// SFC label & roll — below QR
if ($hasRoll) {
    echo '<div class="sfc-label-overlay sfc-has-roll">';
    echo '<div class="sfc-overlay-tag">SFC</div>';
    echo '<div class="sfc-overlay-roll">' . htmlspecialchars($rollDisplay) . '</div>';
    echo '</div>';
} else {
    echo '<div class="sfc-label-overlay sfc-no-roll">SFC</div>';
}


echo '</div>';
?>

<?php if (!$embed): ?>
<div class="no-print">
    <button type="button" class="btn btn-print" onclick="window.print()">🖨 Print Sticker</button>
    <a href="sfc.php" class="btn btn-back">← Back to List</a>
</div>
<?php endif; ?>

</body>
</html>
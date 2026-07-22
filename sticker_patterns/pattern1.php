<?php
// sticker_patterns/pattern1.php
// Used by: NAE, NRI, STAMPING
// Style: matches pattern2 latest version

$colourLabel = ucfirst(strtolower($colorName ?? 'WHITE'));

$patternCSS = '

.p1-sticker {
    width: 120mm;
    height: 47mm;
    box-sizing: border-box;
    font-family: "Arial Narrow", Arial, sans-serif;
    position: relative;
    display: flex;
    flex-direction: row;
    border: none;
    padding: 0;
    overflow: hidden;
    background: transparent;
}

.p1-left {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.p1-row {
    flex: 1.0;
    display: flex;
    flex-direction: row;
    align-items: center;
    border-bottom: none;
    padding: 0 0 0 4.5mm;
    box-sizing: border-box;
}

.p1-lbl {
    font-size: 16px;
    font-weight: 400;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    white-space: nowrap;
    width: 19mm;
    flex-shrink: 0;
    line-height: 1;
}

.p1-colon {
    font-size: 14px;
    color: #000;
    width: 3.5mm;
    flex-shrink: 0;
    font-family: "Arial Narrow", Arial, sans-serif;
    line-height: 1;
    padding-left: 0.5mm;
}

.p1-val-area {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    position: relative;
}

.p1-val-area.has-line::after {
    content: "";
    position: absolute;
    bottom: 1.5px;
    left: 1mm;
    right: 1mm;
    height: 1px;
    background: #000;
}

.p1-val-tombo {
    font-size: 13px;
    font-weight: 400;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    text-decoration: underline;
    text-align: center;
    line-height: 1;
}

.p1-val-grade {
    font-size: 24px;
    font-weight: 400;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    text-align: center;
    line-height: 1;
}

.p1-val-lot {
    font-size: 24px;
    font-weight: 400;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    text-align: center;
    line-height: 1;
}

.p1-val-cust {
    font-size: 15px;
    font-weight: 400;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    text-align: center;
    line-height: 1;
}

.p1-val-ref {
    font-size: 20px;
    font-weight: 400;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    text-align: center;
    line-height: 1;
}

.p1-size-inner {
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: center;
    gap: 1.5mm;
    width: 100%;
}
.p1-size-num {
    font-size: 25px;
    font-weight: 300;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    line-height: 1;
    min-width: 8mm;
    text-align: right;
}
.p1-size-unit {
    font-size: 25px;
    font-weight: 300;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    line-height: 1;
}
.p1-size-x {
    font-size: 23px;
    font-weight: 500;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    line-height: 1;
}

/* Est Wgt inline on last row */
.p1-estwgt-inline {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    justify-content: center;
    padding-right: 2mm;
    flex-shrink: 0;
}
.p1-estwgt-lbl {
    font-size: 10px;
    font-weight: 700;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    white-space: nowrap;
    line-height: 1.2;
}
.p1-estwgt-val {
    font-size: 20px;
    font-weight: 300;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    line-height: 1.2;
    text-align: right;
}

/* ── RIGHT SECTION ───────────────────────────── */
.p1-right {
    width: 33mm;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    box-sizing: border-box;
    padding: 1mm 1.5mm 1mm 1.5mm;
}

.p1-top-block {
    display: flex;
    flex-direction: row;
    align-items: flex-start;
    justify-content: space-between;
    width: 100%;
}

.p1-colour-block {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    line-height: 1.25;
    padding-top: 0.5mm;
}
.p1-colour-name {
    font-size: 14px;
    font-weight: 700;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    white-space: nowrap;
    margin-top: 2mm;
}

.p1-sticker-b {
    font-size: 11px;
    font-weight: 700;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    white-space: nowrap;
}

.p1-qr img {
    width: 19mm;
    height: 19mm;
    display: block;
    background: #fff;
    margin-top: 3mm;
}

.p1-internal {
    font-size: 11px;
    font-weight: 300;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #1b1b1b;
    text-align: center;
    white-space: nowrap;
    line-height: 1;
    width: 100%;
    margin-top: 0.5mm;
    padding-left: 10mm;
}

.p1-roll {
    font-size: 27px;
    font-weight: 300;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    line-height: 1;
    white-space: nowrap;
    text-align: left;
    width: 100%;
    margin-top: -1mm;
}

.p1-wgt-block {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    margin-top: 1mm;
}
.p1-wgt-lbl {
    font-size: 10px;
    font-weight: 700;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    white-space: nowrap;
    line-height: 1.2;
}
.p1-wgt-val {
    font-size: 14px;
    font-weight: 300;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    line-height: 1.2;
}
';

// ── Canonical customer code => full name map ──────────────────
function getCustomerFullName($code) {
    $customers = [
        'NAE'     => 'NICHIAS AUTOPARTS EUROPE (NAE)',
        'NAX'     => 'NAX MFG, SA.DE C.V',
        'NCI MFG' => 'NCI MFG., INC.',
        'TAIHO'   => 'TAIHO MFG OF TN. INC',
        'NRI'     => 'PT NICHIAS ROCKWOOL IND.',
        'ASHUKA'  => 'ASHUKA TECHNOLOGIES SDN. BHD.',
        'NIPPON'  => 'NTC(NIPPON GASKET)',
        'NTC'     => 'NICHIAS THAILAND',
        'SGC'     => 'SHANGHAI XINGSHENG',
        'STAMPING'=> 'MK STAMPING',
        'YANTAI'  => 'NICHIAS (SHANGHAI) AUTOPARTS TRADING',
        'NIP'     => 'NICHIAS IND. PRODUCTS PVT. LTD.',
        'STOCK'   => 'STOCK',
        'TRIAL'   => 'TRIAL',
        'SFC'     => 'SFC',
        'MTX'     => 'NC-PT NRI(FORWARD MATRIX)',
        'YTEC'    => 'YTEC CO., LTD.',
        'NVC'     => 'NICHIAS VIETNAM CO., LTD',
        'NCS'     => 'NC-PT NICHIAS SUNIJAYA',
        'SNP'     => 'SUZHOU NICHIAS IND. PRODUCTS',
        'NSEA'    => 'NICHIAS SOUTH EAST ASIA (UP PACKING)',
    ];
    return $customers[$code] ?? $code;
}

// Strips trailing zeros for sticker display (109.50 -> "109.5",
// 375.00 -> "375"), capped at 2 decimal places. function_exists guard
// in case this also gets added to config.php later — avoids a fatal
// "cannot redeclare" if this file and config.php both define it.
if (!function_exists('formatStickerDecimal')) {
    function formatStickerDecimal($value): string {
        $formatted = number_format((float)$value, 2, '.', '');
        $formatted = rtrim($formatted, '0');
        $formatted = rtrim($formatted, '.');
        return $formatted;
    }
}

function render_sticker(
    array  $product,
    string $customer,
    string $ref_no,
    string $tomboNo,
    string $lotNo,
    string $qrImageUrl
): string {

    $width_mm = floatval($product['width']      ?? 0);
    $length_m = floatval($product['length']     ?? 0);
    $actual_m = floatval($product['actual_length'] ?? $product['length'] ?? 0);
    $std_wgt  = floatval($product['std_weight'] ?? 0);
    $est_wgt  = ($width_mm > 0 && $actual_m > 0 && $std_wgt > 0)
        ? round(($actual_m * $width_mm / 1000) * $std_wgt) : '-';

    $rollRaw     = strtoupper(trim($product['roll_no'] ?? ''));
    $rollDisplay = preg_replace('/^R(\d+)$/i', 'R-$1', $rollRaw);
    if ($rollDisplay === '') $rollDisplay = '-';

    $w_disp = formatStickerDecimal($product['width'] ?? 0);
    $l_disp = formatStickerDecimal($actual_m);

    $colourLabel     = ucfirst(strtolower($GLOBALS['colorName'] ?? 'White'));
    $customerDisplay = getCustomerFullName($customer);

    // "Sticker B" is shown by default (Line B). Hidden when Line A is selected.
    $stickerBLabel = ($GLOBALS['showStickerB'] ?? true)
        ? '<span class="p1-sticker-b">Sticker B</span>'
        : '';

    $h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    return '
<div class="p1-sticker">

    <div class="p1-left">

        <div class="p1-row" style="margin-bottom:-2mm;">
            <div class="p1-lbl">TOMBO No.</div>
            <div class="p1-colon">:</div>
            <div class="p1-val-area">
                <span class="p1-val-tombo">' . $h($tomboNo) . '</span>
            </div>
        </div>

        <div class="p1-row">
            <div class="p1-lbl">Grade</div>
            <div class="p1-colon">:</div>
            <div class="p1-val-area has-line">
                <span class="p1-val-grade">' . $h($product['product'] ?? '-') . '</span>
            </div>
        </div>

        <div class="p1-row">
            <div class="p1-lbl">Size</div>
            <div class="p1-colon">:</div>
            <div class="p1-val-area has-line">
                <div class="p1-size-inner">
                    <span class="p1-size-num">' . $w_disp . '</span>
                    <span class="p1-size-unit">mm</span>
                    <span class="p1-size-x">x</span>
                    <span class="p1-size-num">' . $l_disp . '</span>
                    <span class="p1-size-unit">Mtr</span>
                </div>
            </div>
        </div>

        <div class="p1-row">
            <div class="p1-lbl">Lot No.</div>
            <div class="p1-colon">:</div>
            <div class="p1-val-area has-line">
                <span class="p1-val-lot">' . $h($lotNo) . '</span>
            </div>
        </div>

        <div class="p1-row">
            <div class="p1-lbl">Customer</div>
            <div class="p1-colon">:</div>
            <div class="p1-val-area has-line">
                <span class="p1-val-cust">' . $h($customerDisplay) . '</span>
            </div>
        </div>

        <!-- Ref No + Est Wgt on same row -->
        <div class="p1-row">
            <div class="p1-lbl">Ref. No.</div>
            <div class="p1-colon">:</div>
            <div class="p1-val-area">
                <span class="p1-val-ref">' . $h($ref_no) . '</span>
            </div>
            <div class="p1-estwgt-inline">

            </div>
        </div>

    </div>

    <div class="p1-right">
        <div class="p1-top-block">
            <div class="p1-colour-block">
                <span class="p1-colour-name">' . $h($colourLabel) . '</span>
                ' . $stickerBLabel . '
            </div>
            <div class="p1-qr">
                <img src="' . $h($qrImageUrl) . '" alt="QR Code">
            </div>
        </div>
        <div class="p1-internal">INTERNAL USE</div>
        <div class="p1-roll">' . $h($rollDisplay) . '</div>
        <div class="p1-wgt-block">
            <span class="p1-wgt-lbl">Est. Wgt (kg)</span>
            <span class="p1-wgt-val">' . $est_wgt . '</span>
        </div>
    </div>

</div>';
}
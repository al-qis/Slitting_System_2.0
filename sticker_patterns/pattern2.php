<?php
// sticker_patterns/pattern2.php
// Used by: NAX, TAIHO, ASHUKA, NTC, STOCK, NCI MFG, NIP, SFC, MTX, NVC, NCS, SNP

$colourLabel = ucfirst(strtolower($colorName ?? 'WHITE'));

$patternCSS = '

.p2-sticker {
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

.p2-left {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.p2-row {
    flex: 1.0;
    display: flex;
    flex-direction: row;
    align-items: center;
    border-bottom: none;
    padding: 0 0 0 4.5mm;
    box-sizing: border-box;
}

.p2-lbl {
    font-size: 16px;
    font-weight: 400;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    white-space: nowrap;
    width: 19mm;
    flex-shrink: 0;
    line-height: 1;
}

.p2-colon {
    font-size: 14px;
    color: #000;
    width: 3.5mm;
    flex-shrink: 0;
    font-family: "Arial Narrow", Arial, sans-serif;
    line-height: 1;
    padding-left: 0.5mm;
}

.p2-val-area {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    position: relative;
}

.p2-val-area.has-line::after {
    content: "";
    position: absolute;
    bottom: 1.5px;
    left: 1mm;
    right: 1mm;
    height: 1px;
    background: #000;
}

.p2-val-tombo {
    font-size: 13px;
    font-weight: 400;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    text-decoration: underline;
    text-align: center;
    line-height: 1;
}

.p2-val-grade {
    font-size: 24px;
    font-weight: 400;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    text-align: center;
    line-height: 1;
}

.p2-val-lot {
    font-size: 24px;
    font-weight: 400;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    text-align: center;
    line-height: 1;
}

.p2-val-cust {
    font-size: 16px;
    font-weight: 400;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    text-align: center;
    line-height: 1;
}

.p2-val-ref {
    font-size: 18px;
    font-weight: 400;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    text-align: center;
    line-height: 1;
}

.p2-size-inner {
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: center;
    gap: 1.5mm;
    width: 100%;
}
.p2-size-num {
    font-size: 25px;
    font-weight: 300;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    line-height: 1;
    min-width: 8mm;
    text-align: right;
}
.p2-size-unit {
    font-size: 25px;
    font-weight: 300;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    line-height: 1;
}
.p2-size-x {
    font-size: 23px;
    font-weight: 500;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    line-height: 1;
}

/* ── RIGHT SECTION ───────────────────────────── */
.p2-right {
    width: 33mm;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    box-sizing: border-box;
    padding: 1mm 1.5mm 1mm 1.5mm;
}

.p2-top-block {
    display: flex;
    flex-direction: row;
    align-items: flex-start;
    justify-content: space-between;
    width: 100%;
}

.p2-colour-block {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    line-height: 1.25;
    padding-top: 0.5mm;
}
.p2-colour-name {
    font-size: 14px;
    font-weight: 700;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    white-space: nowrap;
    margin-top: 2mm;
}

.p2-sticker-b {
    font-size: 11px;
    font-weight: 700;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    white-space: nowrap;
}

.p2-qr img {
    width: 19mm;
    height: 19mm;
    display: block;
    background: #fff;
    margin-top: 3mm;
}

.p2-internal {
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

.p2-roll {
    font-size: 35px;
    font-weight: 300;
    font-family: "Arial Narrow", Arial, sans-serif;
    color: #000;
    line-height: 1;
    white-space: nowrap;
    text-align: left;
    width: 100%;
    margin-top: 5mm;
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

    $rollRaw     = strtoupper(trim($product['roll_no'] ?? ''));
    $rollDisplay = preg_replace('/^R(\d+)$/i', 'R-$1', $rollRaw);
    if ($rollDisplay === '') $rollDisplay = '-';

    $w_disp          = formatStickerDecimal($product['width'] ?? 0);
    $l_disp          = formatStickerDecimal($product['actual_length'] ?? $product['length'] ?? 0);
    $customerDisplay = getCustomerFullName($customer);
    $colourLabel     = ucfirst(strtolower($GLOBALS['colorName'] ?? 'White'));

    // "Sticker B" is shown by default (Line B). Hidden when Line A is selected.
    $stickerBLabel = ($GLOBALS['showStickerB'] ?? true)
        ? '<span class="p2-sticker-b">Sticker B</span>'
        : '';

    $h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    return '
<div class="p2-sticker">

    <div class="p2-left">
        <div class="p2-row" style="margin-bottom:-2mm;">
            <div class="p2-lbl">TOMBO No.</div>
            <div class="p2-colon">:</div>
            <div class="p2-val-area">
                <span class="p2-val-tombo">' . $h($tomboNo) . '</span>
            </div>
        </div>
        <div class="p2-row">
            <div class="p2-lbl">Grade</div>
            <div class="p2-colon">:</div>
            <div class="p2-val-area has-line">
                <span class="p2-val-grade">' . $h($product['product'] ?? '-') . '</span>
            </div>
        </div>
        <div class="p2-row">
            <div class="p2-lbl">Size</div>
            <div class="p2-colon">:</div>
            <div class="p2-val-area has-line">
                <div class="p2-size-inner">
                    <span class="p2-size-num">' . $w_disp . '</span>
                    <span class="p2-size-unit">mm</span>
                    <span class="p2-size-x">x</span>
                    <span class="p2-size-num">' . $l_disp . '</span>
                    <span class="p2-size-unit">Mtr</span>
                </div>
            </div>
        </div>
        <div class="p2-row">
            <div class="p2-lbl">Lot No.</div>
            <div class="p2-colon">:</div>
            <div class="p2-val-area has-line">
                <span class="p2-val-lot">' . $h($lotNo) . '</span>
            </div>
        </div>
        <div class="p2-row">
            <div class="p2-lbl">Customer</div>
            <div class="p2-colon">:</div>
            <div class="p2-val-area has-line">
                <span class="p2-val-cust">' . $h($customerDisplay) . '</span>
            </div>
        </div>
        <div class="p2-row">
            <div class="p2-lbl">Ref. No.</div>
            <div class="p2-colon">:</div>
            <div class="p2-val-area">
                <span class="p2-val-ref">' . $h($ref_no) . '</span>
            </div>
        </div>
    </div>

    <div class="p2-right">
        <div class="p2-top-block">
            <div class="p2-colour-block">
                <span class="p2-colour-name">' . $h($colourLabel) . '</span>
                ' . $stickerBLabel . '
            </div>
            <div class="p2-qr">
                <img src="' . $h($qrImageUrl) . '" alt="QR Code">
            </div>
        </div>
        <div class="p2-internal">INTERNAL USE</div>
        <div class="p2-roll">' . $h($rollDisplay) . '</div>
    </div>

</div>';
}
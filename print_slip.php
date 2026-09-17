<?php
// print_slip.php
// ============================================================
// Prints the "Warehousing Slip" (form MS-WH-01(QR)) for one pallet.
// Linked from pallet.php's Action column: print_slip.php?pallet_no=XYZ
//
// Field mapping (per the physical slip):
//   Customer     -> pallets.customer_name
//   Date         -> pallets.created_at (falls back to today if null)
//   SOS No.      -> pallets.ref_no
//   Serial No.   -> pallets.pallet_no
//   Product Type -> pallets.product_type
//   Pallet No.   -> left blank (warehouse fills this in by hand)
//
// Main table is one row per roll on the pallet (pallet_items, joined
// to slitting_product for the roll's own details). "Coils" is always
// 1 per row, since each pallet_items row already represents a single
// roll/coil in this system.
// ============================================================

session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

include 'config.php';
require_once 'PalletManager.php';

// ── Securely resolve the pallet from ?pallet_no= ──────────────────
$palletNo = trim($_GET['pallet_no'] ?? '');
if ($palletNo === '') {
    die('<p style="font-family:Arial;padding:24px;">Pallet No is required.</p>');
}

$stmt = $conn->prepare("SELECT * FROM pallets WHERE pallet_no = ? LIMIT 1");
$stmt->bind_param("s", $palletNo);
$stmt->execute();
$pallet = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pallet) {
    die('<p style="font-family:Arial;padding:24px;">Pallet "' . htmlspecialchars($palletNo) . '" not found.</p>');
}

// ── Fetch every roll on this pallet ────────────────────────────────
// std_wgt is joined the same way pallet.php's getPalletItemsWithWeight()
// does, so Nett Wgt here matches what the app shows elsewhere.
$stmt = $conn->prepare("
    SELECT pi.seq, pi.stock_code,
           sp.lot_no, sp.coil_no, sp.roll_no, sp.product,
           sp.width, sp.length, sp.actual_length, sp.nod_length,
           COALESCE(sw.std_weight, 0) AS std_weight
    FROM pallet_items pi
    JOIN slitting_product sp ON sp.id = pi.slitting_product_id
    LEFT JOIN std_wgt sw     ON sw.product_code = sp.product
    WHERE pi.pallet_id = ?
    ORDER BY pi.seq ASC
");
$stmt->bind_param("i", $pallet['id']);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Build display rows: effective length, Nett Wgt, Stock Code fallback ──
function calcNettWeight(float $lengthM, float $widthMm, float $stdWeight): float {
    if ($lengthM <= 0 || $widthMm <= 0 || $stdWeight <= 0) return 0.0;
    return ($lengthM * $widthMm / 1000) * $stdWeight;
}

$rows = [];
foreach ($items as $it) {
    $rawLen = (!empty($it['actual_length']) && $it['actual_length'] > 0)
        ? (float)$it['actual_length'] : (float)$it['length'];
    $nodLen = !empty($it['nod_length']) ? (float)$it['nod_length'] : 0.0;
    $lenVal = max(0.0, $rawLen - $nodLen);

    $stockCode = PalletManager::formatStockCode($it['coil_no'], $it['width'], $lenVal);

    $rows[] = [
        'stock_code' => $stockCode ?: '-',
        'lot_no'     => trim(($it['lot_no'] ?? '') . ' ' . ($it['coil_no'] ?? '')) ?: '-',
        'length'     => $lenVal,
        'width'      => (float)($it['width'] ?? 0),
        'coils'      => 1, // each pallet_items row = one roll/coil
        'roll_no'    => $it['roll_no'] ? str_replace('R', 'R-', $it['roll_no']) : '-',
        'nett_wgt'   => calcNettWeight($lenVal, (float)($it['width'] ?? 0), (float)$it['std_weight']),
    ];
}

// Pad blank rows so the printed table always shows at least 8 lines,
// matching the physical slip's fixed layout.
$MIN_ROWS = 8;
while (count($rows) < $MIN_ROWS) {
    $rows[] = null; // null = render as an empty row
}

$dateStr = $pallet['created_at'] ? date('d/m/Y', strtotime($pallet['created_at'])) : date('d/m/Y');

$h = fn($s) => htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');

function fmtNum(mixed$v): string {
    if ($v === null || $v === '' || (float)$v == 0) return '';
    $f = number_format((float)$v, 2, '.', '');
    return str_ends_with($f, '.00') ? substr($f, 0, -3) : $f;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Warehousing Slip — <?= $h($pallet['pallet_no']) ?></title>
<style id="basePrintStyle">
    * { box-sizing: border-box; }
    body {
        font-family: Arial, Helvetica, sans-serif;
        background: #e2e8f0;
        margin: 0;
        padding: 20px 10px;
        color: #000;
    }

    /* Screen preview sheet styled to 9.5" x 5.5" with user-defined margins in inches */
    .sheet-wrapper {
        display: flex;
        justify-content: center;
        width: 100%;
        overflow-x: auto;
        padding: 10px 0;
    }
    .sheet {
        width: 9.5in;
        min-height: 5.5in;
        height: 5.5in;
        max-width: 9.5in;
        background: #fff;
        padding: 0.19in 0.5in 0.13in 0.15in; /* Top: 0.19in, Right: 0.5in, Bottom: 0.13in, Left: 0.15in */
        box-sizing: border-box;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.18);
        border: 1px solid #cbd5e1;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    /* ── Header ─────────────────────────────────────────── */
    .slip-header {
        position: relative;
        text-align: center;
        margin-bottom: 2mm;
    }
    .slip-header h1 {
        font-size: 11pt;
        font-weight: bold;
        margin: 0;
        line-height: 1.25;
        letter-spacing: 0.3px;
    }
    .form-code {
        position: absolute;
        top: 0;
        right: 0;
        font-size: 8.5pt;
        font-weight: bold;
    }

    /* ── Top info table ─────────────────────────────────── */
    table.info-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 2mm;
    }
    table.info-table td {
        border: 1px solid #000000;
        padding: 0.8mm 2mm;
        font-size: 10pt;
        vertical-align: middle;
        line-height: 1.2;
    }
    table.info-table td.label {
        width: 15%;
        font-weight: normal;
    }
    table.info-table td.value {
        width: 35%;
        font-weight: 600;
    }

    /* ── Main data table ────────────────────────────────── */
    table.data-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 2mm;
    }
    table.data-table th, table.data-table td {
        border: 1px solid #000000;
        padding: 0.8mm 1mm;
        text-align: center;
        font-size: 9.5pt;
        line-height: 1.2;
    }
    table.data-table thead th {
        font-weight: bold;
        background: #f5f5f5;
        padding: 0.8mm 1mm;
    }
    table.data-table col.col-stock   { width: 22%; }
    table.data-table col.col-lot     { width: 18%; }
    table.data-table col.col-length  { width: 12%; }
    table.data-table col.col-width   { width: 12%; }
    table.data-table col.col-coils   { width: 8%;  }
    table.data-table col.col-roll    { width: 12%; }
    table.data-table col.col-wgt     { width: 16%; }
    table.data-table td.data-row     { height: 7.6mm; }

    /* ── Footer signature blocks ────────────────────────── */
    .footer-wrap {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        margin-top: 1mm;
        margin-bottom: 0;
    }
    table.footer-table {
        width: 58%;
        margin-left: auto;
        border-collapse: collapse;
        margin-bottom: 0;
    }
    table.footer-table th, table.footer-table td {
        border: 1px solid #000000;
        padding: 0.8mm 1.5mm;
        font-size: 8pt;
        vertical-align: top;
    }
    table.footer-table th {
        text-align: center;
        background: #f5f5f5;
        font-weight: bold;
        padding: 0.8mm 1.5mm;
    }

    /* Row 2: blank signature space */
    table.footer-table td.sig-space-cell {
        height: 13.5mm;
        vertical-align: top;
    }
    /* Row 3: separate Date row */
    table.footer-table td.date-cell {
        height: auto;
        vertical-align: middle;
        padding: 0.8mm 1.5mm;
    }

    .sig-line { display: flex; align-items: center; gap: 1.5mm; }
    .sig-line label { font-weight: bold; white-space: nowrap; }

    .prod-name-input, .prod-date-input {
        border: none;
        border-bottom: 1px solid #999;
        font-family: inherit;
        font-size: 8pt;
        padding: 0.5mm 1mm;
        background: #fffef2;
    }
    .prod-name-input { flex: 1; min-width: 0; }
    .prod-date-input { width: auto; }
    .prod-date-text { display: none; font-size: 8pt; font-family: inherit; }

    .legend {
        white-space: nowrap;
        flex-shrink: 0;
        font-size: 7pt;
        font-weight: 500;
        padding-right: 2mm;
        box-sizing: border-box;
    }

    /* ── On-screen Control Toolbar ──────────────────────── */
    .no-print-toolbar {
        max-width: 9.5in;
        margin: 0 auto 14px auto;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .toolbar-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
        background: #1e293b;
        color: #fff;
        padding: 10px 16px;
        border-radius: 6px;
    }
    .toolbar-title {
        font-size: 14px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .paper-size-tag {
        background: #0284c7;
        color: #fff;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: bold;
        letter-spacing: 0.5px;
    }
    .btn-group-print {
        display: flex;
        gap: 8px;
    }
    .print-btn {
        padding: 8px 18px;
        background: #2563eb;
        color: #fff;
        border: none;
        cursor: pointer;
        font-size: 13px;
        font-weight: 600;
        border-radius: 4px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: background 0.15s;
    }
    .print-btn:hover { background: #1d4ed8; }
    .print-btn.secondary { background: #475569; }
    .print-btn.secondary:hover { background: #334155; }

    /* Margin Adjustment Panel */
    .margin-panel {
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        padding: 8px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        font-size: 12px;
    }
    .margin-inputs {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
    .margin-field {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-weight: 600;
        color: #334155;
    }
    .margin-field input {
        width: 58px;
        padding: 3px 6px;
        font-size: 12px;
        border: 1px solid #94a3b8;
        border-radius: 4px;
        text-align: center;
        background: #f8fafc;
    }
    .margin-actions {
        display: flex;
        gap: 6px;
    }
    .margin-btn {
        padding: 4px 10px;
        font-size: 11px;
        font-weight: 600;
        border-radius: 4px;
        border: 1px solid #cbd5e1;
        background: #f1f5f9;
        cursor: pointer;
        color: #334155;
    }
    .margin-btn:hover { background: #e2e8f0; }

    .print-hints {
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        padding: 8px 14px;
        font-size: 11.5px;
        color: #334155;
        line-height: 1.4;
    }
    .print-hints strong { color: #0f172a; }

    /* ── Print rules (Strict 9.5" × 5.5" with Margins in Inch) ────────── */
    @media print {
        @page {
            size: 9.5in 5.5in;
            margin-top: 0.19in;
            margin-bottom: 0.13in;
            margin-left: 0.15in;
            margin-right: 0.5in;
        }
        html, body {
            width: 100% !important;
            height: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
            overflow: hidden !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        .sheet-wrapper {
            display: block !important;
            width: 100% !important;
            height: 100% !important;
            padding: 0 !important;
            margin: 0 !important;
            overflow: hidden !important;
        }
        .sheet {
            width: 100% !important;
            max-width: 100% !important;
            min-height: auto !important;
            height: calc(5.5in - 0.19in - 0.13in) !important;
            max-height: calc(5.5in - 0.19in - 0.13in) !important;
            margin: 0 !important;
            padding: 0 !important;
            border: none !important;
            box-shadow: none !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
            page-break-after: avoid !important;
            break-after: avoid !important;
            page-break-before: avoid !important;
            break-before: avoid !important;
            overflow: hidden !important;
        }
        table, tr, td, th, tbody, thead {
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }
        .no-print, .no-print-toolbar {
            display: none !important;
        }

        /* Swap date picker input to crisp clean text on print */
        .prod-date-input {
            display: none !important;
        }
        .prod-date-text {
            display: inline !important;
        }
        input[type="date"]::-webkit-calendar-picker-indicator {
            display: none !important;
        }
    }
</style>
</head>
<body>

<div class="no-print-toolbar">
    <div class="toolbar-actions">
        <div class="toolbar-title">
            <span>Warehousing Slip Print Preview</span>
            <span class="paper-size-tag">9.5" × 5.5" (11"/2)</span>
        </div>
        <div class="btn-group-print">
            <button class="print-btn" onclick="window.print()">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                Print Slip
            </button>
            <button class="print-btn secondary" onclick="window.close()">Close</button>
        </div>
    </div>
    <div class="margin-panel">
        <div class="margin-inputs">
            <span style="font-weight:700; color:#0f172a; margin-right:4px;">Print Margins (inch):</span>
            <label class="margin-field">Top: <input type="number" step="0.01" min="0" max="4" id="mTop" value="0.19" onchange="applyMargins()"></label>
            <label class="margin-field">Bottom: <input type="number" step="0.01" min="0" max="4" id="mBottom" value="0.13" onchange="applyMargins()"></label>
            <label class="margin-field">Left: <input type="number" step="0.01" min="0" max="4" id="mLeft" value="0.15" onchange="applyMargins()"></label>
            <label class="margin-field">Right: <input type="number" step="0.01" min="0" max="4" id="mRight" value="0.5" onchange="applyMargins()"></label>
        </div>
        <div class="margin-actions">
            <button type="button" class="margin-btn" onclick="applyMargins()">Apply</button>
            <button type="button" class="margin-btn" onclick="resetMargins()">Reset</button>
        </div>
    </div>
    <div class="print-hints">
        <strong>Printer Settings for Continuous Form (9.5" × 5.5"):</strong><br>
        • <strong>Paper Size:</strong> Select <code>9.5 x 5.5 in</code> (or <code>Half Letter / Fanfold 241 × 140 mm</code>). If not listed, add Custom Paper Size (Width: 9.5", Height: 5.5") in Windows Print Server Properties.<br>
        • <strong>Margins:</strong> <code>None</code> or <code>Minimum</code> (CSS controls top: 0.19", bottom: 0.13", left: 0.15", right: 0.5")&nbsp;&nbsp;|&nbsp;&nbsp;• <strong>Scale:</strong> <code>100%</code>&nbsp;&nbsp;|&nbsp;&nbsp;• <strong>Headers/Footers:</strong> <code>Unchecked</code>
    </div>
</div>

<div class="sheet-wrapper">
<div class="sheet">

    <div class="slip-header">
        <h1>METAKOTE SLITTING DEPARTMENT<br>WAREHOUSING SLIP</h1>
        <span class="form-code">MS-WH-01(QR)</span>
    </div>

    <table class="info-table">
        <tr>
            <td class="label">Customer</td>
            <td class="value"><?= $h($pallet['customer_name']) ?></td>
            <td class="label">Date</td>
            <td class="value"><?= $h($dateStr) ?></td>
        </tr>
        <tr>
            <td class="label">SOS No.</td>
            <td class="value"><?= $h($pallet['ref_no']) ?></td>
            <td class="label">Serial No.</td>
            <td class="value"><?= $h($pallet['pallet_no']) ?></td>
        </tr>
        <tr>
            <td class="label">Product Type :</td>
            <td class="value"><?= $h($pallet['product_type']) ?></td>
            <td class="label">Pallet No.</td>
            <td class="value">&nbsp;</td> <!-- left blank for warehouse use -->
        </tr>
    </table>

    <table class="data-table">
        <colgroup>
            <col class="col-stock">
            <col class="col-lot">
            <col class="col-length">
            <col class="col-width">
            <col class="col-coils">
            <col class="col-roll">
            <col class="col-wgt">
        </colgroup>
        <thead>
            <tr>
                <th rowspan="2">Stock Code :</th>
                <th rowspan="2">Lot No.</th>
                <th colspan="2">Size</th>
                <th rowspan="2">Coils</th>
                <th rowspan="2">Roll No.</th>
                <th rowspan="2">Nett Wgt<br>(kg)</th>
            </tr>
            <tr>
                <th>Length (mtr)</th>
                <th>width (mm)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <?php if ($r === null): ?>
                    <td class="data-row"></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                <?php else: ?>
                    <td class="data-row" style="text-align:center;"><?= $h($r['stock_code']) ?></td>
                    <td><?= $h($r['lot_no']) ?></td>
                    <td><?= $h(fmtNum($r['length'])) ?></td>
                    <td><?= $h(fmtNum($r['width'])) ?></td>
                    <td><?= (int)$r['coils'] ?></td>
                    <td><?= $h($r['roll_no']) ?></td>
                    <td><?= $r['nett_wgt'] > 0 ? $h(number_format($r['nett_wgt'], 2)) : '' ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer-wrap">
        <div class="legend">
            1.White - Attached with F/G&nbsp;&nbsp;&nbsp;&nbsp;2.Yellow - Warehouse&nbsp;&nbsp;&nbsp;&nbsp;3.Blue - Production
        </div>

        <table class="footer-table">
            <thead>
                <tr>
                    <th style="width:50%;">Production</th>
                    <th style="width:50%;">Warehouse</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="sig-space-cell">&nbsp;</td>
                    <td class="sig-space-cell">&nbsp;</td>
                </tr>
                <tr>
                    <td class="date-cell">
                        <div class="sig-line">
                            <label for="prodDate">Date:</label>
                            <input type="date" id="prodDate" name="prod_date"
                                   class="prod-date-input" value="<?= date('Y-m-d') ?>"
                                   oninput="syncDateText(this.value)">
                            <span id="prodDateText" class="prod-date-text"><?= date('d/m/Y') ?></span>
                        </div>
                    </td>
                    <td class="date-cell">
                        <div class="sig-line">
                            <label>Date:</label>
                            <span>&nbsp;</span>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

</div>
</div>

<script>
function syncDateText(val) {
    if (!val) {
        document.getElementById('prodDateText').textContent = '';
        return;
    }
    var parts = val.split('-');
    if (parts.length === 3) {
        document.getElementById('prodDateText').textContent = parts[2] + '/' + parts[1] + '/' + parts[0];
    }
}

function applyMargins() {
    var top = parseFloat(document.getElementById('mTop').value);
    var bottom = parseFloat(document.getElementById('mBottom').value);
    var left = parseFloat(document.getElementById('mLeft').value);
    var right = parseFloat(document.getElementById('mRight').value);

    if (isNaN(top)) top = 0.19;
    if (isNaN(bottom)) bottom = 0.13;
    if (isNaN(left)) left = 0.15;
    if (isNaN(right)) right = 0.5;

    // Update screen sheet padding
    var sheet = document.querySelector('.sheet');
    if (sheet) {
        sheet.style.paddingTop = top + 'in';
        sheet.style.paddingRight = right + 'in';
        sheet.style.paddingBottom = bottom + 'in';
        sheet.style.paddingLeft = left + 'in';
    }

    // Update print @page margin rule dynamically
    var styleTag = document.getElementById('dynamicPrintMargins');
    if (!styleTag) {
        styleTag = document.createElement('style');
        styleTag.id = 'dynamicPrintMargins';
        document.head.appendChild(styleTag);
    }
    styleTag.innerHTML = '@media print { @page { size: 9.5in 5.5in; margin: ' + top + 'in ' + right + 'in ' + bottom + 'in ' + left + 'in !important; } }';

    try {
        localStorage.setItem('slip_margin_top', top);
        localStorage.setItem('slip_margin_bottom', bottom);
        localStorage.setItem('slip_margin_left', left);
        localStorage.setItem('slip_margin_right', right);
    } catch(e) {}
}

function resetMargins() {
    document.getElementById('mTop').value = '0.19';
    document.getElementById('mBottom').value = '0.13';
    document.getElementById('mLeft').value = '0.15';
    document.getElementById('mRight').value = '0.5';
    applyMargins();
}

// Load saved margins on startup or initialize default
(function() {
    try {
        var savedTop = localStorage.getItem('slip_margin_top');
        if (savedTop !== null) {
            if (parseFloat(savedTop) === 1.9) {
                savedTop = '0.19';
                localStorage.setItem('slip_margin_top', '0.19');
            }
            document.getElementById('mTop').value = savedTop;
            document.getElementById('mBottom').value = localStorage.getItem('slip_margin_bottom') || '0.13';
            document.getElementById('mLeft').value = localStorage.getItem('slip_margin_left') || '0.15';
            document.getElementById('mRight').value = localStorage.getItem('slip_margin_right') || '0.5';
            applyMargins();
        }
    } catch(e) {}
})();
</script>

</body>
</html>
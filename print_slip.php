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
           sp.width, sp.length, sp.actual_length,
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
    $lenVal = (!empty($it['actual_length']) && $it['actual_length'] > 0)
        ? (float)$it['actual_length'] : (float)$it['length'];

    // Trust the value stored on pallet_items; only recompute for legacy
    // rows added before the stock_code column existed.
    $stockCode = $it['stock_code'];
    if (empty($stockCode)) {
        $stockCode = PalletManager::formatStockCode($it['coil_no'], $it['width'], $lenVal);
    }

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

function fmtNum($v): string {
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
<style>
    * { box-sizing: border-box; }
    body {
        font-family: Arial, Helvetica, sans-serif;
        background: #eee;
        margin: 0;
        padding: 20px;
        color: #000;
    }
    .sheet {
        width: 8.5in;
        max-width: 100%;
        margin: 0 auto;
        background: #fff;
        padding: 4mm 5mm;
        box-sizing: border-box;
    }

    /* ── Header ─────────────────────────────────────────── */
    .slip-header {
        position: relative;
        text-align: center;
        margin-bottom: 2mm;
    }
    .slip-header h1 {
        font-size: 10.5pt;
        font-weight: bold;
        margin: 0;
        line-height: 1.25;
    }
    .form-code {
        position: absolute;
        top: 0;
        right: 0;
        font-size: 8pt;
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
        padding: 1mm 2mm;
        font-size: 10pt;
        vertical-align: middle;
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
    }
    table.data-table thead th { font-weight: bold; background: #f5f5f5; }
    table.data-table col.col-stock   { width: 20%; }
    table.data-table col.col-lot     { width: 15%; } /* lot no: 4-7 mixed-case alnum chars, keep roomy */
    table.data-table col.col-length  { width: 10%; }
    table.data-table col.col-width   { width: 10%; }
    table.data-table col.col-coils   { width: 7%;  }
    table.data-table col.col-roll    { width: 12%; }
    table.data-table col.col-wgt     { width: 12%; }
    table.data-table td.data-row     { height: 8mm; }

    /* ── Footer signature blocks ────────────────────────── */
    .footer-wrap {
        display: flex;
        align-items: flex-end; /* legend baseline lines up with the table's bottom (Date) row */
        margin-bottom: 0;
    }
    table.footer-table {
        width: 59%;         /* ← make this smaller/bigger to shrink/grow the box */
        margin-left: auto;  /* pushes it flush against the right edge */
        border-collapse: collapse;
        margin-bottom: 0;
    }
    table.footer-table th, table.footer-table td {
        border: 1px solid #bebebe;
        padding: 1mm 1.5mm;
        font-size: 8pt;
        vertical-align: top;
    }
    table.footer-table th { text-align: center; background: #f5f5f5; }

    /* Row 2: large blank signature space */
    table.footer-table td.sig-space-cell {
        height: 15mm;
        vertical-align: top;
    }
    /* Row 3: separate Date row — the border between this <tr> and the
       signature-space <tr> above is what gives the strict horizontal
       divider line from the physical form; no extra CSS needed for it,
       it's just a natural consequence of them being distinct rows. */
    table.footer-table td.date-cell {
        height: auto;
        vertical-align: middle;
        padding: 1mm 1.5mm;
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

    .legend {
        white-space: nowrap; /* keep the whole legend on one line */
        flex-shrink: 0;      /* don't let the flex container squeeze it to wrap */
        font-size: 6.5pt;
        padding-right: 2mm;
        box-sizing: border-box;
    }

    .no-print { text-align: center; margin-top: 6mm; }
    .print-btn {
        padding: 10px 24px;
        background: #0066cc;
        color: #fff;
        border: none;
        cursor: pointer;
        font-size: 15px;
        border-radius: 4px;
        margin: 0 4px;
    }
    .print-btn.secondary { background: #666; }

    /* ── Print rules ────────────────────────────────────── */
    @media print {
        @page { size: 21.5cm 14cm; margin: 3mm; }
        body { background: #fff; padding: 0; }
        .sheet { width: 100%; padding: 0; }
        .no-print { display: none !important; }

        /* Inputs render as plain text on paper — no border, no
           background, no native date-picker calendar icon. */
        .prod-name-input, .prod-date-input {
            border: none !important;
            background: transparent !important;
            -webkit-appearance: none;
            appearance: none;
        }
        input[type="date"]::-webkit-calendar-picker-indicator {
            display: none !important;
        }
        input[type="date"] {
            -webkit-appearance: none;
            appearance: none;
        }
    }
</style>
</head>
<body>

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
                                   class="prod-date-input" value="<?= date('Y-m-d') ?>">
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

<div class="no-print">
    <button class="print-btn" onclick="window.print()">Print</button>
    <button class="print-btn secondary" onclick="window.close()">Close</button>
</div>

</body>
</html>
<?php
include 'config.php';

$id  = intval($_GET['id'] ?? 0);
$row = $conn->query("SELECT * FROM stock_raw_material WHERE id=$id")->fetch_assoc();

if (!$row) {
    die("Stock record not found.");
}

// Fetch grade + product from mother_coil via source_id, then fallback to lot_no
$mother = null;
if (!empty($row['source_id'])) {
    $mother = $conn->query("SELECT * FROM mother_coil WHERE id=" . intval($row['source_id']))->fetch_assoc();
}
if (!$mother) {
    $lot_safe = $conn->real_escape_string(rtrim($row['lot_no'], 'abcdefghijklmnopqrstuvwxyz'));
    $mother   = $conn->query("SELECT * FROM mother_coil WHERE lot_no LIKE '$lot_safe%' LIMIT 1")->fetch_assoc();
}

$grade   = $mother['grade']   ?? '-';
$product = $mother['product'] ?? '-';

// Combine Lot No + Coil No (same as print_mother.php)
$lotCoil = trim($row['lot_no']) . ' ' . trim($row['coil_no'] ?? '');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Print Leftover - <?= htmlspecialchars($lotCoil) ?></title>

    <style>
        @media print {
            @page {
                size: 148mm 105mm;
                margin: 0;
            }

            html, body {
                margin: 0 !important;
                padding: 0 !important;
                width: 148mm;
                height: 105mm;
            }

            .container {
                width: 148mm !important;
                height: 105mm !important;
                max-width: none !important;
                margin: 0 !important;
                border: 2px solid #000;
                padding: 10mm !important;
                box-sizing: border-box;
                page-break-after: always;
            }

            .no-print {
                display: none !important;
            }
        }

        body {
            font-family: Arial, sans-serif;
            padding: 50px;
        }

        .container {
            max-width: 500px;
            margin: 0 auto;
            border: 2px solid #000;
            padding: 10px;
            box-sizing: border-box;
        }

        .header {
            display: flex;
            align-items: center;
            border-bottom: 3px solid #000;
            padding-bottom: 0px;
            margin-bottom: 20px;
        }

        .title {
            font-size: 30px;
            font-weight: bold;
        }

        .content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .info-table {
            flex: 1;
            margin-right: 20px;
        }

        .info-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .info-table td {
            border: 1px solid #000;
            padding: 10px;
            font-size: 16px;
        }

        .info-table td:first-child {
            font-weight: bold;
            width: 120px;
            background-color: #f0f0f0;
        }

        .qr-section {
            text-align: center;
            flex-shrink: 0;
        }

        .qr-section img {
            width: 120px;
            height: 120px;
            border: 1px solid #000;
            padding: 5px;
        }

        .print-btn {
            margin-top: 20px;
            padding: 10px 20px;
            background-color: #0066cc;
            color: white;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <div class="title">MK SLITTING</div>
        </div>

        <div class="content">
            <div class="info-table">
                <table>
                    <tr>
                        <td>PRODUCT</td>
                        <td><?= htmlspecialchars($product) ?></td>
                    </tr>
                    <tr>
                        <td>LOT NO.</td>
                        <td><?= htmlspecialchars($lotCoil) ?></td>
                    </tr>
                    <tr>
                        <td>GRADE</td>
                        <td><?= htmlspecialchars($grade) ?></td>
                    </tr>
                    <tr>
                        <td>WIDTH</td>
                        <td><?= htmlspecialchars($row['width'] ?? '-') ?> mm</td>
                    </tr>
                    <tr>
                        <td>LENGTH</td>
                        <td><?= htmlspecialchars($row['length'] ?? '-') ?> mtr</td>
                    </tr>
                </table>
            </div>

            <div class="qr-section">
                <img
                    src="generate_qr.php?product=<?= urlencode($product) ?>
                    &lot=<?= urlencode($row['lot_no']) ?>
                    &coil=<?= urlencode($row['coil_no'] ?? '') ?>
                    &grade=<?= urlencode($grade) ?>
                    &width=<?= urlencode($row['width'] ?? '') ?>
                    &length=<?= urlencode($row['length'] ?? '') ?>
                    &type=mother"
                    alt="QR Code">
            </div>
        </div>
    </div>

    <div class="no-print" style="text-align: center;">
        <button class="print-btn" onclick="window.print()">Print</button>
        <button class="print-btn" style="background-color: #666;" onclick="history.back()">Back</button>
    </div>
</body>
</html>
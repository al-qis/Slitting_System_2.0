<?php
include 'config.php';

$id = intval($_GET['id'] ?? 0);
$row = $conn->query("SELECT * FROM mother_coil WHERE id=$id")->fetch_assoc();

if (!$row) {
    die("Mother Coil not found");
}

// Embed mode — used when this page is loaded inside an <iframe> for a
// consolidated print job (e.g. print_mother_batch_action.php). Hides the
// Print/Back buttons so only the sticker itself renders, matching the
// same embed=1 convention already used by print_product.php. Also skips
// the "mark as printed" beacon here — the batch job marks all its items
// printed itself, in one pass, rather than each iframe doing it
// individually.
$embed = (($_GET['embed'] ?? '') === '1');

// Gabungkan Lot No + Coil No
$lotCoil = trim($row['lot_no']) . ' ' . trim($row['coil_no']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Print Mother Coil - <?= htmlspecialchars($row['product']) ?></title>

    <style>
        @media print {
            @page {
                size: 120mm 47mm;
                margin: 0;
            }

            html, body {
                margin: 0 !important;
                padding: 0 !important;
                width: 120mm;
                height: 47mm;
            }

            .container {
                width: 100% !important;
                height: 100% !important;
                margin: 0 !important;
                page-break-after: always;
            }

            .no-print {
                display: none !important;
            }
        }

        /* =========================
           STICKER LAYOUT — fixed
           120mm (W) x 47mm (H)
           ========================= */
        body {
            font-family: Arial, sans-serif;
            padding: 10mm;
        }

        .container {
            width: 120mm;
            height: 47mm;
            margin: 0 auto;
            border: 0.4mm solid #000;
            padding: 1.5mm 2.5mm;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .header {
            flex-shrink: 0;
            border-bottom: 0.6mm solid #000;
            padding-bottom: 0.6mm;
            margin-bottom: 1mm;
        }

        .title {
            font-size: 16pt;
            font-weight: bold;
            letter-spacing: 0.3px;
            line-height: 1;
        }

        .content {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 2mm;
            min-height: 0;
        }

        .info-table {
            flex: 1;
            min-width: 0;
        }

        .info-table table {
            width: 100%;
            height: 100%;
            border-collapse: collapse;
        }

        .info-table td {
            border: 0.2mm solid #000;
            padding: 1mm 2mm;
            line-height: 1.15;
        }

        .info-table td:first-child {
            font-weight: bold;
            font-size: 10pt;
            width: 26mm;
            background-color: #f0f0f0;
        }

        .info-table td:last-child {
            font-size: 14pt;
            font-weight: 700;
        }

        .qr-section {
            flex-shrink: 0;
            width: 40mm;
            height: 40mm;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .qr-section img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border: 0.2mm solid #000;
            padding: 1mm;
            box-sizing: border-box;
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
                        <td><?= htmlspecialchars($row['product']) ?></td>
                    </tr>
                    <tr>
                        <td>LOT NO.</td>
                        <td><?= htmlspecialchars($lotCoil) ?></td>
                    </tr>
                    <tr>
                        <td>GRADE</td>
                        <td><?= htmlspecialchars($row['grade']) ?></td>
                    </tr>
                    <tr>
                        <td>WIDTH</td>
                        <td><?= htmlspecialchars($row['width']) ?> mm</td>
                    </tr>
                    <tr>
                        <td>LENGTH</td>
                        <td><?= htmlspecialchars($row['length']) ?> mtr</td>
                    </tr>
                </table>
            </div>

            <div class="qr-section">
                <img
                    src="generate_qr.php?product=<?= urlencode($row['product']) ?>&lot=<?= urlencode($row['lot_no']) ?>&coil=<?= urlencode($row['coil_no']) ?>&grade=<?= urlencode($row['grade']) ?>&width=<?= urlencode($row['width']) ?>&length=<?= urlencode($row['length']) ?>&type=mother"
                    alt="QR Code">
            </div>
        </div>
    </div>

    <?php if (!$embed): ?>
    <div class="no-print" style="text-align: center;">
        <button class="print-btn" onclick="markPrintedThenPrint()">Print</button>
        <button class="print-btn" style="background-color: #666;" onclick="window.close()">Back</button>
    </div>

    <script>
    // Fires the moment the operator clicks Print — marks this coil as
    // printed (so the Mother Coil list shows its status), then opens the
    // browser's print dialog. Uses sendBeacon so the request reliably
    // fires even though window.print() blocks the JS thread right after.
    function markPrintedThenPrint() {
        const id = <?= (int)$row['id'] ?>;
        try {
            navigator.sendBeacon('mark_mother_printed.php', new URLSearchParams({ id }));
        } catch (e) {
            fetch('mark_mother_printed.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + id,
            });
        }
        window.print();
    }
    </script>
    <?php endif; ?>
</body>
</html>
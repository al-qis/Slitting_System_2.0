<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

include 'config.php';

// Get the stock_id for the balance (leftover) from URL
$stock_id = isset($_GET['stock_id']) ? intval($_GET['stock_id']) : 0;

if ($stock_id === 0) {
    die("Error: stock_id not provided");
}

// Get the balance stock data
$query = "SELECT * FROM stock_raw_material WHERE id=$stock_id AND source_type='slitting_cut_into_2'";
$result = $conn->query($query);

if (!$result || $result->num_rows === 0) {
    die("Error: Balance stock not found for ID: $stock_id");
}

$stock = $result->fetch_assoc();

// Get the original mother coil data for product info
$mother_query = "SELECT product FROM mother_coil WHERE id=" . intval($stock['source_id']);
$mother_result = $conn->query($mother_query);
$mother = $mother_result ? $mother_result->fetch_assoc() : null;
$product = $mother ? $mother['product'] : 'UNKNOWN';

// Generate QR code data
$qr_data = "LOT=" . urlencode($stock['lot_no']) . "|COIL=" . urlencode($stock['coil_no']) . "|TYPE=BALANCE|ID=" . $stock_id;
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=300&data=" . urlencode($qr_data);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Print Balance Stock - MK SLITTING</title>
    <style>
        body {
            margin: 0;
            padding: 20px;
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border: 3px solid #000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .header {
            text-align: center;
            border-bottom: 3px solid #000;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .header h1 {
            margin: 0;
            font-size: 32px;
            font-weight: bold;
            letter-spacing: 2px;
        }

        .header .subtitle {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
            font-style: italic;
        }

        .content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            align-items: center;
        }

        .info-table {
            width: 100%;
        }

        .info-row {
            display: grid;
            grid-template-columns: 150px 1fr;
            border: 2px solid #000;
            min-height: 50px;
            align-items: center;
        }

        .info-row:first-child {
            border-top: 3px solid #000;
        }

        .info-row:last-child {
            border-bottom: 3px solid #000;
        }

        .info-label {
            background-color: #f0f0f0;
            border-right: 2px solid #000;
            padding: 10px;
            font-weight: bold;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .info-value {
            padding: 10px 15px;
            font-size: 18px;
            font-weight: bold;
            font-family: 'Courier New', monospace;
        }

        .qr-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .qr-code {
            border: 3px solid #000;
            padding: 10px;
            background: white;
            margin-bottom: 15px;
        }

        .qr-code img {
            width: 300px;
            height: 300px;
            display: block;
        }

        .balance-badge {
            background-color: #ffc107;
            border: 3px solid #000;
            padding: 15px 30px;
            font-size: 24px;
            font-weight: bold;
            text-transform: uppercase;
            text-align: center;
            letter-spacing: 2px;
            width: 100%;
            box-sizing: border-box;
        }

        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #000;
            font-size: 12px;
            color: #666;
        }

        .footer-text {
            margin: 5px 0;
        }

        .button-group {
            text-align: center;
            margin-top: 30px;
            display: no-print;
        }

        .button-group button {
            padding: 12px 40px;
            margin: 0 10px;
            font-size: 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }

        .btn-print {
            background-color: #0066cc;
            color: white;
        }

        .btn-print:hover {
            background-color: #0052a3;
        }

        .btn-back {
            background-color: #666;
            color: white;
        }

        .btn-back:hover {
            background-color: #555;
        }

        @media print {
            body {
                margin: 0;
                padding: 0;
                background: white;
            }

            .container {
                max-width: 100%;
                margin: 0;
                padding: 40px;
                border: 3px solid #000;
                box-shadow: none;
                page-break-after: avoid;
            }

            .button-group {
                display: none !important;
            }

            .info-row {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- HEADER -->
    <div class="header">
        <h1>MK SLITTING</h1>
        <div class="subtitle">BALANCE STOCK (Cut Into 2 Leftover)</div>
    </div>

    <!-- CONTENT -->
    <div class="content">
        
        <!-- LEFT: INFO TABLE -->
        <div class="info-table">
            
            <div class="info-row">
                <div class="info-label">PRODUCT</div>
                <div class="info-value"><?= htmlspecialchars($product) ?></div>
            </div>

            <div class="info-row">
                <div class="info-label">LOT NO.</div>
                <div class="info-value"><?= htmlspecialchars($stock['lot_no']) ?></div>
            </div>

            <div class="info-row">
                <div class="info-label">COIL NO.</div>
                <div class="info-value"><?= htmlspecialchars($stock['coil_no']) ?></div>
            </div>

            <div class="info-row">
                <div class="info-label">GRADE</div>
                <div class="info-value"><?= htmlspecialchars($stock['grade'] ?? '-') ?></div>
            </div>

            <div class="info-row">
                <div class="info-label">WIDTH</div>
                <div class="info-value"><?= number_format((float)$stock['width'], 2) ?> mm</div>
            </div>

            <div class="info-row">
                <div class="info-label">LENGTH</div>
                <div class="info-value"><?= number_format((float)$stock['length'], 2) ?> mtr</div>
            </div>

        </div>

        <!-- RIGHT: QR CODE -->
        <div class="qr-section">
            <div class="qr-code">
                <img src="<?= htmlspecialchars($qr_url) ?>" alt="QR Code">
            </div>
            <div class="balance-badge">⚠️ BALANCE</div>
        </div>

    </div>

    <!-- FOOTER -->
    <div class="footer">
        <div class="footer-text">Stock ID: #<?= $stock['id'] ?> | Generated: <?= date('Y-m-d H:i:s') ?></div>
        <div class="footer-text">Scan QR code to continue processing</div>
    </div>

</div>

<!-- BUTTONS -->
<div class="button-group">
    <button class="btn-print" onclick="window.print()">
        📄 Print Label
    </button>
    <button class="btn-back" onclick="window.history.back()">
        ← Back
    </button>
</div>

</body>
</html>
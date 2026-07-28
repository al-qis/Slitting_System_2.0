<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

include 'config.php';

// Get balance stock items (leftovers from Cut Into 2)
$balance_query = "SELECT id, lot_no, coil_no, grade, width, length, status, source_type, date_in 
                  FROM stock_raw_material 
                  WHERE source_type='slitting_cut_into_2' AND status='IN'
                  ORDER BY date_in DESC";
$balance_result = $conn->query($balance_query);

// Build QR code data for each leftover
// Format: LOT=<lot_no>;COIL=<coil_no>;WIDTH=<width>;LENGTH=<length>;TYPE=BALANCE;ID=<stock_id>
// Semicolon-delimited to match the format scan_mother_action.php /
// scan_product_action.php / generate_qr.php already parse (their
// Format-A parser splits on ';' then 'KEY=value' — TYPE and ID are
// extra fields those parsers simply ignore, kept here only as
// human-readable/debugging metadata, not for any parsing logic).
$qr_data = [];
if ($balance_result) {
    while ($row = $balance_result->fetch_assoc()) {
        $qr_content = "LOT=" . $row['lot_no']
                    . ";COIL=" . $row['coil_no']
                    . ";WIDTH=" . $row['width']
                    . ";LENGTH=" . $row['length']
                    . ";TYPE=BALANCE;ID=" . $row['id'];
        $qr_data[$row['id']] = [
            'content' => $qr_content,
            'lot_no' => $row['lot_no'],
            'coil_no' => $row['coil_no'],
            'grade' => $row['grade'],
            'length' => $row['length'],
            'width' => $row['width']
        ];
    }
}

// Check if requesting single QR code as image
if (isset($_GET['generate_qr']) && isset($_GET['stock_id'])) {
    $stock_id = intval($_GET['stock_id']);
    if (isset($qr_data[$stock_id])) {
        // Local Endroid-based generator — works fully offline, unlike the
        // old api.qrserver.com redirect this replaced.
        $d = $qr_data[$stock_id];
        header("Location: generate_qr.php?type=balance"
            . "&lot="    . urlencode($d['lot_no'])
            . "&coil="   . urlencode($d['coil_no'])
            . "&width="  . urlencode($d['width'])
            . "&length=" . urlencode($d['length']));
        exit;
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Generate QR Codes - Balance Stock</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .container { max-width: 1400px; }
        .header-section {
            background: rgba(255,255,255,0.95);
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            backdrop-filter: blur(10px);
        }
        .header-section h2 { 
            color: #667eea;
            font-weight: 700;
            margin-bottom: 5px;
        }
        .header-section p { color: #666; margin: 0; }
        .qr-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        .qr-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            transition: all 0.3s cubic-bezier(0.23, 1, 0.320, 1);
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }
        .qr-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }
        .qr-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 40px rgba(102, 126, 234, 0.3);
            border-color: #667eea;
        }
        .qr-card-header {
            margin-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 15px;
        }
        .lot-code {
            font-size: 1.3em;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 5px;
            font-family: 'Courier New', monospace;
        }
        .coil-info {
            font-size: 0.9em;
            color: #888;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .qr-image-container {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
            min-height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .qr-image-container img {
            max-width: 100%;
            max-height: 280px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        }
        .stock-details {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 0.9em;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            align-items: center;
        }
        .detail-row:last-child { margin-bottom: 0; }
        .detail-label {
            color: #666;
            font-weight: 600;
        }
        .detail-value {
            color: #333;
            font-weight: 700;
            font-family: 'Courier New', monospace;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .btn-qr {
            flex: 1;
            padding: 12px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9em;
        }
        .btn-download {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        .btn-download:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .btn-print {
            background: #f0f0f0;
            color: #333;
        }
        .btn-print:hover {
            background: #e0e0e0;
        }
        .badge-balance {
            background: linear-gradient(135deg, #ffc107, #ff9800);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 10px;
        }
        .empty-state {
            background: white;
            border-radius: 15px;
            padding: 60px 30px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .empty-state i {
            font-size: 4em;
            color: #ddd;
            margin-bottom: 20px;
        }
        .empty-state h3 { color: #999; margin-bottom: 10px; }
        .empty-state p { color: #bbb; }
        @media print {
            body { background: white; }
            .header-section { box-shadow: none; border: 1px solid #ddd; }
            .qr-grid { grid-template-columns: repeat(3, 1fr); }
            .action-buttons { display: none; }
            .qr-card { page-break-inside: avoid; }
        }
        .tab-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        .tab-btn {
            padding: 12px 25px;
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid white;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .tab-btn:hover, .tab-btn.active {
            background: white;
            color: #667eea;
        }
    </style>
</head>
<body>

    <div class="container py-5">
        <!-- Header -->
        <div class="header-section">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h2><i class="bi bi-qr-code me-2"></i>Generate QR Codes for Balance Stock</h2>
                    <p>Print QR codes for leftover coils from "Cut Into 2" operations</p>
                </div>
                <a href="raw_material.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Back to Stock
                </a>
            </div>
        </div>

        <!-- Tab Buttons -->
        <div class="tab-buttons">
            <button class="tab-btn active" onclick="filterByStatus('all')">All (<?= count($qr_data) ?>)</button>
            <button class="tab-btn" onclick="filterByStatus('ready')">Ready to Print</button>
            <button class="tab-btn" onclick="printAll()"><i class="bi bi-printer me-1"></i>Print All</button>
        </div>

        <!-- QR Code Grid -->
        <?php if (count($qr_data) > 0): ?>
            <div class="qr-grid" id="qrGrid">
                <?php foreach ($qr_data as $stock_id => $data): ?>
                    <div class="qr-card" data-stock-id="<?= $stock_id ?>">
                        <!-- Badge -->
                        <span class="badge-balance">
                            <i class="bi bi-arrow-return me-1"></i>Balance Stock
                        </span>

                        <!-- Header -->
                        <div class="qr-card-header">
                            <div class="lot-code"><?= htmlspecialchars($data['lot_no']) ?> <?= htmlspecialchars($data['coil_no']) ?></div>
                            <div class="coil-info">
                                <span><strong>Grade:</strong> <?= htmlspecialchars($data['grade']) ?></span>
                                <span><strong>Width:</strong> <?= htmlspecialchars($data['width']) ?>mm</span>
                            </div>
                        </div>

                        <!-- QR Code Image -->
                        <div class="qr-image-container">
                            <img src="generate_qr.php?type=balance&lot=<?= urlencode($data['lot_no']) ?>&coil=<?= urlencode($data['coil_no']) ?>&width=<?= urlencode($data['width']) ?>&length=<?= urlencode($data['length']) ?>"
                                 alt="QR Code for <?= htmlspecialchars($data['lot_no']) ?>"
                                 class="qr-code-img">
                        </div>

                        <!-- Stock Details -->
                        <div class="stock-details">
                            <div class="detail-row">
                                <span class="detail-label">Current Length:</span>
                                <span class="detail-value"><?= number_format($data['length'], 2) ?> m</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Type:</span>
                                <span class="detail-value">Balance from Cut</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Status:</span>
                                <span class="detail-value"><span class="badge bg-success">IN</span></span>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <button class="btn-qr btn-download" onclick="downloadQR(<?= $stock_id ?>, '<?= htmlspecialchars($data['lot_no']) ?>')">
                                <i class="bi bi-download me-1"></i>Download
                            </button>
                            <button class="btn-qr btn-print" onclick="printSingle(<?= $stock_id ?>)">
                                <i class="bi bi-printer me-1"></i>Print
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <h3>No Balance Stock Available</h3>
                <p>There are no leftover items from "Cut Into 2" operations yet.</p>
                <p style="margin-top: 15px;">
                    <a href="raw_material.php" class="btn btn-outline-primary">
                        <i class="bi bi-arrow-left me-1"></i>Go to Raw Material
                    </a>
                </p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function downloadQR(stockId, lotNo) {
            const qrImage = document.querySelector(`[data-stock-id="${stockId}"] .qr-code-img`);
            const link = document.createElement('a');
            link.href = qrImage.src;
            link.download = `QR_${lotNo.replace(/\s+/g, '_')}_${stockId}.png`;
            link.click();
        }

        function printSingle(stockId) {
            const card = document.querySelector(`[data-stock-id="${stockId}"]`);
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>QR Code</title>
                    <style>
                        body { margin: 20px; font-family: Arial, sans-serif; }
                        .print-card { 
                            width: 4in; 
                            height: 5in; 
                            border: 1px solid #ccc;
                            padding: 0.25in;
                            text-align: center;
                            page-break-inside: avoid;
                        }
                        .lot-code { font-size: 18px; font-weight: bold; margin-bottom: 10px; font-family: 'Courier New', monospace; }
                        .coil-info { font-size: 12px; margin-bottom: 15px; }
                        .qr-code { margin: 10px 0; }
                        .qr-code img { max-width: 100%; height: auto; }
                        .details { font-size: 11px; margin-top: 10px; }
                    </style>
                </head>
                <body>
                    <div class="print-card">
                        ${card.innerHTML}
                    </div>
                    <script>
                        window.print();
                        window.close();
                    <\/script>
                </body>
                </html>
            `);
            printWindow.document.close();
        }

        function printAll() {
            window.print();
        }

        function filterByStatus(status) {
            // Update button states
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            // Filter cards
            document.querySelectorAll('.qr-card').forEach(card => {
                if (status === 'all') {
                    card.style.display = 'block';
                } else {
                    // Add more filter logic as needed
                    card.style.display = 'block';
                }
            });
        }
    </script>

</body>
</html>
<?php $conn->close(); ?>
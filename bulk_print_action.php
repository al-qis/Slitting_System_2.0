<?php
session_start();
include 'config.php';

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

$base_where = "
    (is_voided    = 0 OR is_voided    IS NULL)
    AND (is_recoiled  = 0 OR is_recoiled  IS NULL)
    AND (is_reslitted = 0 OR is_reslitted IS NULL)
";

$result = null;

// ── Preferred path: explicit selection (checkbox-driven) ───────────
// finish_product.php now POSTs the exact IDs the user ticked, as a
// JSON array in `ids`. This is what makes selective bulk print work
// — we only ever print exactly what was checked, nothing more.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ids'])) {
    $rawIds = json_decode($_POST['ids'], true);
    if (!is_array($rawIds) || empty($rawIds)) {
        die("No products selected.");
    }

    // Sanitize: cast everything to int, drop zeros/duplicates. This is
    // what actually protects the query — never trust the JSON shape
    // alone, since it's client-supplied.
    $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds))));
    if (empty($ids)) {
        die("No valid product IDs selected.");
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types        = str_repeat('i', count($ids));

    // ORDER BY FIELD(...) preserves the order the user selected/saw them
    // in, rather than falling back to whatever order id DESC happens to
    // produce — nicer when printing a hand-picked batch.
    $stmt = $conn->prepare("
        SELECT id, customer_name, ref_no
        FROM slitting_product
        WHERE id IN ($placeholders)
          AND ($base_where)
        ORDER BY FIELD(id, $placeholders)
    ");
    $stmt->bind_param($types . $types, ...array_merge($ids, $ids));
    $stmt->execute();
    $result = $stmt->get_result();

    // Only used for the "for search: ..." label below when a selection
    // was made without an accompanying search term.
    $search = '';

} else {
    // ── Fallback: legacy "print everything matching this search" ───
    // Kept for any caller that hasn't been migrated to the checkbox UI.
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $tokens = $search !== '' ? preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) : [];

    // Updated sorting from ORDER BY roll_no ASC to ORDER BY id DESC to match slitting_product.php
    if (count($tokens) === 1) {
        $stmt = $conn->prepare("SELECT id, customer_name, ref_no FROM slitting_product WHERE ($base_where) AND (coil_no LIKE ? OR product LIKE ? OR lot_no LIKE ? OR roll_no LIKE ?) ORDER BY id DESC");
        $like = '%' . $tokens[0] . '%';
        $stmt->bind_param("ssss", $like, $like, $like, $like);
        $stmt->execute();
        $result = $stmt->get_result();
    } elseif (count($tokens) === 2) {
        $stmt = $conn->prepare("SELECT id, customer_name, ref_no FROM slitting_product WHERE ($base_where) AND lot_no LIKE ? AND coil_no LIKE ? ORDER BY id DESC");
        $likeLot  = '%' . $tokens[0] . '%';
        $likeCoil = '%' . $tokens[1] . '%';
        $stmt->bind_param("ss", $likeLot, $likeCoil);
        $stmt->execute();
        $result = $stmt->get_result();
    } elseif (count($tokens) >= 3) {
        $stmt = $conn->prepare("SELECT id, customer_name, ref_no FROM slitting_product WHERE ($base_where) AND lot_no LIKE ? AND coil_no LIKE ? AND roll_no LIKE ? ORDER BY id DESC");
        $likeLot  = '%' . $tokens[0] . '%';
        $likeCoil = '%' . $tokens[1] . '%';
        $likeRoll = '%' . $tokens[2] . '%';
        $stmt->bind_param("sss", $likeLot, $likeCoil, $likeRoll);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        die("Please search for a specific product or batch to bulk print, or select products individually.");
    }
}

$products = [];
while ($row = $result->fetch_assoc()) {
    $customerNameClean = trim((string)$row['customer_name']);
    $refNoClean        = trim((string)$row['ref_no']);

    $products[] = [
        'id'            => $row['id'],
        'customer_name' => !empty($customerNameClean) ? $customerNameClean : 'STOCK',
        'ref_no'        => !empty($refNoClean) ? $refNoClean : 'STOCK'
    ];
}
$stmt->close();

if (empty($products)) {
    die("No active inventory rows matched the search criteria.");
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Bulk Print Slitting Stickers (3 Copies Each)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f8f9fa; padding: 30px 15px; }
        .print-frame { 
            width: 120mm; 
            height: 47mm; 
            border: 1px dashed #bbb; 
            display: block; 
            margin: 0 auto 15px; 
            background: white; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; background: none; }
            .print-frame { border: none; margin: 0; box-shadow: none; page-break-after: always; }
        }
    </style>
</head>
<body>

<div class="container text-center mb-4 no-print" style="max-width: 600px;">
    <div class="card shadow-sm p-4 border-0">
        <h4 class="fw-bold text-success mb-2"><i class="bi bi-printer-fill me-2"></i>Bulk Print Console</h4>
        <p class="text-muted">Found <strong><?= count($products) ?></strong> matching products. Printing <strong>3 copies</strong> for each (Total: <?= count($products) * 3 ?> stickers) for search: "<strong><?= htmlspecialchars($search) ?></strong>"</p>
        <div class="d-flex gap-2 justify-content-center">
            <button onclick="window.print()" class="btn btn-success px-4 py-2 fw-bold shadow-sm">
                <i class="bi bi-printer me-1"></i> Print All Stickers
            </button>
            <button onclick="window.close()" class="btn btn-outline-secondary px-3">Close Window</button>
        </div>
    </div>
</div>

<div class="print-container">
    <?php foreach ($products as $prod): ?>
        <?php for ($i = 1; $i <= 3; $i++): ?>
            <?php 
                // Only the FIRST of the 3 copies triggers mark_printed on
                // the backend — matches the single-print flow, where
                // clicking "Print" once counts as one print_count
                // increment no matter how many physical copies ($copies)
                // come out of that one click. Copies 2 and 3 here are
                // purely visual/physical duplicates of the same action.
                $markPrint = ($i === 1) ? '1' : '0';
                $iframeSrc = "print_product.php?id=" . $prod['id'] . 
                             "&customer=" . urlencode($prod['customer_name']) . 
                             "&ref_no=" . urlencode($prod['ref_no']) . 
                             "&embed=1&mark_print=" . $markPrint;
            ?>
            <iframe src="<?= $iframeSrc ?>" class="print-frame" frameborder="0"></iframe>
        <?php endfor; ?>
    <?php endforeach; ?>
</div>

</body>
</html>
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

// ── Read selected IDs ──────────────────────────────────────────
// POST from sfc.php's "Bulk Print Selected" form (sfc_ids[]). GET with
// a comma-separated ?ids= is also accepted so this page can be linked
// to directly / reprinted from a bookmark if needed.
$ids = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sfc_ids'])) {
    $ids = array_map('intval', (array)$_POST['sfc_ids']);
} elseif (isset($_GET['ids'])) {
    $ids = array_map('intval', explode(',', $_GET['ids']));
}
$ids = array_values(array_unique(array_filter($ids, fn($id) => $id > 0)));

if (empty($ids)) {
    die("No SFC items selected.");
}

// ── Validate against the DB, preserving the order the user selected ──
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types        = str_repeat('i', count($ids));

$stmt = $conn->prepare("
    SELECT sfc_id, product, lot_no, coil_no, roll_no
    FROM sfc
    WHERE sfc_id IN ($placeholders)
    ORDER BY FIELD(sfc_id, $placeholders)
");
$stmt->bind_param($types . $types, ...array_merge($ids, $ids));
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();

if (empty($items)) {
    die("No matching SFC items found for the selected IDs.");
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Bulk Print SFC Labels</title>
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
        <h4 class="fw-bold text-success mb-2"><i class="bi bi-printer-fill me-2"></i>Bulk Print Console — SFC Labels</h4>
        <p class="text-muted">
            <strong><?= count($items) ?></strong> label<?= count($items) === 1 ? '' : 's' ?> selected.
        </p>
        <div class="d-flex gap-2 justify-content-center">
            <button onclick="window.print()" class="btn btn-success px-4 py-2 fw-bold shadow-sm">
                <i class="bi bi-printer me-1"></i> Print All Labels
            </button>
            <button onclick="window.close()" class="btn btn-outline-secondary px-3">Close Window</button>
        </div>
    </div>
</div>

<div class="print-container">
    <?php foreach ($items as $item): ?>
        <iframe src="print_sfc.php?id=<?= (int)$item['sfc_id'] ?>" class="print-frame" frameborder="0"></iframe>
    <?php endforeach; ?>
</div>

</body>
</html>
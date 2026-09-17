<?php
include 'config.php';

$id = intval($_GET['id'] ?? 0);
$data = $conn->query("SELECT * FROM mother_coil WHERE id=$id")->fetch_assoc();

if(!$data){
    die("<h3 style='color:red;text-align:center;margin-top:50px;'>Mother coil tidak dijumpai</h3>");
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Mother Coil Detail</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f8f9fa; }
        .card { margin:auto; max-width:500px; margin-top:50px; }
        .card-header { background:#343a40; color:#fff; font-size:18px; font-weight:bold; }
        .table td { padding:8px; }
    </style>
</head>
<body>
<div class="container">
    <div class="card shadow">
        <div class="card-header text-center">
            Mother Coil Detail
        </div>
        <div class="card-body">
            <table class="table table-bordered">
                <tr><th>Coil No</th><td><?= htmlspecialchars($data['coil_no']) ?></td></tr>
                <tr><th>Product</th><td><?= htmlspecialchars($data['product']) ?></td></tr>
                <tr><th>Lot No</th><td><?= htmlspecialchars($data['lot_no']) ?></td></tr>
                <tr><th>Size (mtr)</th><td><?= htmlspecialchars($data['size']) ?></td></tr>
                <tr><th>Nominal</th><td><?= htmlspecialchars($data['nominal']) ?></td></tr>
                <tr><th>Effective</th><td><?= htmlspecialchars($data['effective']) ?></td></tr>
                <tr><th>Length</th><td><?= htmlspecialchars($data['length']) ?></td></tr>
                <tr><th>Date Created</th><td><?= htmlspecialchars($data['date_created']) ?></td></tr>
            </table>
        </div>
    </div>
</div>
</body>
</html>

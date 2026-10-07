<?php
/**
 * Backfill Stock Code Script
 * -------------------------------------------------------------------
 * Generates and updates missing stock_code (NULL or empty) for 
 * existing slitting_product records based on their date_in / created_at.
 * -------------------------------------------------------------------
 */

session_start();

if (!isset($_SESSION['role'])) {
    die("Akses Ditolak. Sila log masuk ke dalam sistem dahulu.");
}

require_once 'config.php';

echo "<h2>Penyelenggaraan Stock Code (Backfill Utility)</h2>";

// Check if stock_code column exists
$colCheck = $conn->query("SHOW COLUMNS FROM slitting_product LIKE 'stock_code'");
if (!$colCheck || $colCheck->num_rows === 0) {
    die("<p style='color:red;'>Ralat: Kolum 'stock_code' tiada dalam jadual slitting_product. Sila jalankan ALTER TABLE terlebih dahulu.</p>");
}

// Find all records where stock_code is NULL or empty
$query = "
    SELECT id, date_in, created_at
    FROM slitting_product
    WHERE stock_code IS NULL OR TRIM(stock_code) = ''
    ORDER BY COALESCE(date_in, created_at) ASC, id ASC
";

$res = $conn->query($query);
$totalToUpdate = $res->num_rows;

if ($totalToUpdate === 0) {
    echo "<p style='color:green; font-weight:bold;'>Semua rekod slitting_product sudah mempunyai stock_code!</p>";
    exit;
}

echo "<p>Menjumpai <strong>{$totalToUpdate}</strong> rekod tanpa stock_code. Memulakan proses penjanaan...</p>";

$updatedCount = 0;
$updateStmt = $conn->prepare("UPDATE slitting_product SET stock_code = ? WHERE id = ?");

while ($row = $res->fetch_assoc()) {
    $id = (int)$row['id'];
    $dateStr = !empty($row['date_in']) ? $row['date_in'] : $row['created_at'];

    // Generate unique stock code based on production date
    $newStockCode = generateStockCode($conn, $dateStr);

    $updateStmt->bind_param("si", $newStockCode, $id);
    if ($updateStmt->execute()) {
        $updatedCount++;
    }
}

$updateStmt->close();

echo "<hr>";
echo "<h3 style='color:green;'>Selesai!</h3>";
echo "<p>Sebanyak <strong>{$updatedCount} / {$totalToUpdate}</strong> rekod telah berjaya dikemaskini dengan stock_code baharu.</p>";
?>

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

// Mode check: ?force=1 to re-sequence ALL records in strict FIFO order
$forceResequence = (isset($_GET['force']) && $_GET['force'] === '1');

if ($forceResequence) {
    echo "<p style='color:orange; font-weight:bold;'>MOD RESET ACTIVE: Menyusun semula SEMUA stock_code mengikut urutan asal (FIFO: date_in ASC, id ASC)...</p>";
    $query = "SELECT id, date_in FROM slitting_product WHERE is_voided = 0 ORDER BY COALESCE(date_in, '1970-01-01') ASC, id ASC";
} else {
    $query = "SELECT id, date_in FROM slitting_product WHERE (stock_code IS NULL OR TRIM(stock_code) = '') AND is_voided = 0 ORDER BY COALESCE(date_in, '1970-01-01') ASC, id ASC";
}

$res = $conn->query($query);
$totalToUpdate = $res ? $res->num_rows : 0;

if ($totalToUpdate === 0) {
    echo "<p style='color:green; font-weight:bold;'>Tiada rekod untuk dikemaskini!</p>";
    echo "<p><a href='?force=1' class='btn btn-warning'>Klik di sini jika mahu Susun Semula (Re-sequence) SEMUA Stock Code mengikut urutan FIFO</a></p>";
    exit;
}

echo "<p>Memproses <strong>{$totalToUpdate}</strong> rekod mengikut urutan pengeluaran (FIFO)...</p>";

// Reset tracking monthly sequences
$monthlyCounters = [];
$updatedCount = 0;
$updateStmt = $conn->prepare("UPDATE slitting_product SET stock_code = ? WHERE id = ?");

while ($row = $res->fetch_assoc()) {
    $id = (int)$row['id'];
    $dateStr = !empty($row['date_in']) ? $row['date_in'] : date('Y-m-d H:i:s');
    $ts = strtotime($dateStr) ?: time();
    $prefix = date('ym', $ts); // YYMM e.g. 2610

    if (!isset($monthlyCounters[$prefix])) {
        $monthlyCounters[$prefix] = 1;
    } else {
        $monthlyCounters[$prefix]++;
    }

    $seq = $monthlyCounters[$prefix];
    $newStockCode = sprintf("%s-%04d", $prefix, $seq);

    $updateStmt->bind_param("si", $newStockCode, $id);
    if ($updateStmt->execute()) {
        $updatedCount++;
    }
}

$updateStmt->close();

echo "<hr>";
echo "<h3 style='color:green;'>Selesai!</h3>";
echo "<p>Sebanyak <strong>{$updatedCount}</strong> rekod telah berjaya disusun mengikut urutan tarikh pengeluaran (FIFO).</p>";
echo "<p><a href='finish_product.php'>Kembali ke Finished Product</a></p>";
?>

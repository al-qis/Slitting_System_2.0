<?php
session_start();
if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}
include 'config.php';

header('Content-Type: text/plain');

// Test 1: DB connection
echo "=== DB CONNECTION ===\n";
echo $conn ? "OK\n" : "FAILED\n";

// Test 2: reslit_product table exists
echo "\n=== TABLE: reslit_product ===\n";
$r = $conn->query("SHOW COLUMNS FROM reslit_product");
if ($r) {
    while ($row = $r->fetch_assoc()) echo $row['Field'] . " (" . $row['Type'] . ")\n";
} else {
    echo "ERROR: " . $conn->error . "\n";
}

// Test 3: reslit_rolls table exists
echo "\n=== TABLE: reslit_rolls ===\n";
$r = $conn->query("SHOW COLUMNS FROM reslit_rolls");
if ($r) {
    while ($row = $r->fetch_assoc()) echo $row['Field'] . " (" . $row['Type'] . ")\n";
} else {
    echo "ERROR: " . $conn->error . "\n";
}

// Test 4: slitting_product join
echo "\n=== TEST QUERY ===\n";
$r = $conn->query("
    SELECT r.id, r.status, r.product, r.lot_no, r.width, r.length, r.actual_length, r.date_in,
           COALESCE(NULLIF(s.actual_length, 0), r.length) AS effective_length
    FROM reslit_product r
    LEFT JOIN (
        SELECT sp1.*
        FROM slitting_product sp1
        INNER JOIN (
            SELECT lot_no, coil_no, roll_no, MAX(id) AS max_id
            FROM slitting_product
            GROUP BY lot_no, coil_no, roll_no
        ) sp2 ON sp1.id = sp2.max_id
    ) s ON s.lot_no = r.lot_no AND s.coil_no = r.coil_no AND s.roll_no = r.roll_no
    LIMIT 3
");
if ($r) {
    echo "Query OK, rows: " . $r->num_rows . "\n";
    while ($row = $r->fetch_assoc()) print_r($row);
} else {
    echo "ERROR: " . $conn->error . "\n";
}
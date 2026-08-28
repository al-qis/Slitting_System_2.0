<?php
/**
 * ajax_scans.php
 * -----------------------------------------------------------------------
 * Returns the full current list of scanned records as JSON. Polled by
 * index.php every few seconds so a second device (e.g. supervisor's PC)
 * sees new scans made from the tablet without a manual page refresh.
 * -----------------------------------------------------------------------
 */

header('Content-Type: application/json');
require_once dirname(__DIR__) . '/config.php';
$mysqli = $conn;

$records = [];
$sql = "SELECT raw, lot, coil, roll, width, length, product_code,
               d365_item_number, d365_lot_no, mtr,
               DATE_FORMAT(scanned_at, '%H:%i:%s') AS scanned_at
        FROM stock_crosscheck_scans
        ORDER BY id DESC";
if ($res = $mysqli->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        $records[] = $row;
    }
}

echo json_encode(['success' => true, 'records' => $records]);

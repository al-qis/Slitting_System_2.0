// scan_pallet_action.php (new file)
// Step 1: find the slitting_product row from the QR
$stmt = $conn->prepare("
    SELECT sp.id, sp.status, sp.stock_counted,
           pi.pallet_id
    FROM slitting_product sp
    LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
    WHERE sp.lot_no = ? AND sp.coil_no = ? AND sp.roll_no = ?
    LIMIT 1
");
$stmt->bind_param("sss", $lot, $coil, $roll);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

// Step 2: if already on a pallet, load the whole pallet
if ($product['pallet_id']) {
    $pallet = getPallet($conn, $product['pallet_id']);
    $items  = getPalletItems($conn, $product['pallet_id']);
    // → show QC the complete pallet, not just one roll
}
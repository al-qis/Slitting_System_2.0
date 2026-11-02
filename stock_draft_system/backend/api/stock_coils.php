<?php
/**
 * API Endpoint: Stock Coils Search, Filter & Status Update
 * Location: /stock_draft_system/backend/api/stock_coils.php
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../models/DraftCoil.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$draftCoil = new DraftCoil();

try {
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $coilId    = intval($input['coil_id'] ?? 0);
        $newStatus = trim($input['new_status'] ?? '');
        $remarks   = trim($input['remarks'] ?? '');
        $officerId = trim($input['officer_id'] ?? 'Production Officer');

        if ($coilId <= 0 || empty($newStatus)) {
            echo json_encode(['ok' => false, 'error' => 'Coil ID and new status are required.']);
            exit;
        }

        $allowed = ['AVAILABLE', 'RESERVED', 'USED', 'OUTGOING', 'CANCELLED'];
        if (!in_array($newStatus, $allowed)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid status value.']);
            exit;
        }

        $success = $draftCoil->updateStatus($coilId, $newStatus, null, 'MANUAL_STATUS_OVERRIDE', $remarks, $officerId);
        if ($success) {
            echo json_encode(['ok' => true, 'msg' => 'Coil status updated successfully.']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Failed to update coil status.']);
        }
        exit;
    }

    // GET Request: Filterable list of stock coils
    $filters = [
        'status'   => $_GET['status'] ?? '',
        'state'    => $_GET['state'] ?? '',
        'product'  => $_GET['product'] ?? '',
        'grade'    => $_GET['grade'] ?? '',
        'customer' => $_GET['customer'] ?? '',
        'search'   => $_GET['search'] ?? '',
        'limit'    => isset($_GET['limit']) ? intval($_GET['limit']) : 100,
        'offset'   => isset($_GET['offset']) ? intval($_GET['offset']) : 0
    ];

    $coils     = $draftCoil->getCoils($filters);
    $stats     = $draftCoil->getSummaryStats();
    $customers = $draftCoil->getUniqueCustomers();
    $products  = $draftCoil->getUniqueProducts();

    echo json_encode([
        'ok'        => true,
        'stats'     => $stats,
        'customers' => $customers,
        'products'  => $products,
        'coils'     => $coils
    ]);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

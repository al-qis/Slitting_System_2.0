<?php
/**
 * API Endpoint: Draft Orders Creation, Listing, Confirmation & Cancellation
 * Location: /stock_draft_system/backend/api/drafts.php
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../models/DraftOrder.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$draftOrderModel = new DraftOrder();

try {
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $action = $input['action'] ?? 'create';

        if ($action === 'create') {
            $result = $draftOrderModel->createDraft($input);
            echo json_encode($result);
            exit;
        } elseif ($action === 'update') {
            $result = $draftOrderModel->updateDraft($input);
            echo json_encode($result);
            exit;
        } elseif ($action === 'confirm') {
            $draftOrderId = intval($input['draft_order_id'] ?? 0);
            $officerId = trim($input['officer_id'] ?? 'Production Officer');
            $result = $draftOrderModel->confirmDraft($draftOrderId, $officerId);
            echo json_encode($result);
            exit;
        } elseif ($action === 'cancel') {
            $draftOrderId = intval($input['draft_order_id'] ?? 0);
            $reason = trim($input['reason'] ?? 'Cancelled by user');
            $officerId = trim($input['officer_id'] ?? 'Production Officer');
            $result = $draftOrderModel->cancelDraft($draftOrderId, $reason, $officerId);
            echo json_encode($result);
            exit;
        } else {
            echo json_encode(['ok' => false, 'error' => 'Unknown POST action.']);
            exit;
        }
    }

    // GET Request: List drafts or fetch single draft by ID
    if (isset($_GET['id']) && intval($_GET['id']) > 0) {
        $single = $draftOrderModel->getDraftOrderById(intval($_GET['id']));
        if ($single) {
            echo json_encode(['ok' => true, 'order' => $single]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Draft order not found.']);
        }
        exit;
    }

    $filters = [
        'status' => $_GET['status'] ?? '',
        'search' => $_GET['search'] ?? ''
    ];

    $orders = $draftOrderModel->getDraftOrders($filters);
    echo json_encode(['ok' => true, 'orders' => $orders]);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

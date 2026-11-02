<?php
/**
 * API Endpoint: Coil Hold Operations (Hold / Release / Listing)
 * Location: /stock_draft_system/backend/api/coil_holds.php
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../models/DraftCoil.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$draftCoil = new DraftCoil();

try {
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $action = trim($input['action'] ?? $_GET['action'] ?? '');

        if ($action === 'hold') {
            $coilId        = intval($input['coil_id'] ?? 0);
            $note          = trim($input['note'] ?? '');
            $officer       = trim($input['officer'] ?? $input['officer_id'] ?? 'Production Officer');
            $draftCustomer = trim($input['draft_customer'] ?? '');
            $draftSos      = trim($input['draft_sos'] ?? $input['draft_sos_no'] ?? '');
            if ($officer === '') {
                $officer = 'Production Officer';
            }

            if ($coilId <= 0 || empty($note)) {
                echo json_encode(['ok' => false, 'error' => 'Coil ID and hold note are required.']);
                exit;
            }

            $success = $draftCoil->setHold($coilId, $note, $officer, $draftCustomer, $draftSos);
            if ($success) {
                echo json_encode(['ok' => true, 'msg' => 'Coil placed on hold.']);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Failed to place coil on hold.']);
            }
            exit;
        } elseif ($action === 'release') {
            $coilId  = intval($input['coil_id'] ?? 0);
            $officer = trim($input['officer'] ?? $input['officer_id'] ?? 'Production Officer');
            if ($officer === '') {
                $officer = 'Production Officer';
            }

            if ($coilId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Coil ID is required.']);
                exit;
            }

            $success = $draftCoil->releaseHold($coilId, $officer);
            if ($success) {
                echo json_encode(['ok' => true, 'msg' => 'Hold cleared.']);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Failed to clear hold.']);
            }
            exit;
        } else {
            echo json_encode(['ok' => false, 'error' => 'Invalid or missing action. Must be "hold" or "release".']);
            exit;
        }
    }

    // GET Request: return list of all on-hold coils
    $coils = $draftCoil->getOnHoldCoils();

    echo json_encode([
        'ok'    => true,
        'coils' => $coils
    ]);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

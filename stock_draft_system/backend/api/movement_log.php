<?php
/**
 * API Endpoint: Stock Movement Audit Log
 * Location: /stock_draft_system/backend/api/movement_log.php
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../models/StockMovementLog.php';

try {
    $limit = intval($_GET['limit'] ?? 100);
    $logs = StockMovementLog::getLogs($limit);

    echo json_encode([
        'ok'   => true,
        'logs' => $logs
    ]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

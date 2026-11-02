<?php
/**
 * API Endpoint: Month-End Stock Inspection & Snapshot Sync
 * Location: /stock_draft_system/backend/api/month_end_stock.php
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../adapters/SlittingDbAdapter.php';
require_once __DIR__ . '/../models/DraftCoil.php';

$action = $_GET['action'] ?? 'get';

try {
    $adapter = new SlittingDbAdapter();

    if ($action === 'sync') {
        $draftCoil = new DraftCoil();
        $result = $draftCoil->syncFromSlittingDb();
        echo json_encode(['ok' => true, 'result' => $result]);
        exit;
    }

    // Default 'get': Fetch Read-Only month-end stock list from slitting_db
    $coils = $adapter->getMonthEndStockCoils();
    $metrics = $adapter->getMonthEndMetrics();

    echo json_encode([
        'ok' => true,
        'metrics' => $metrics,
        'coils'   => $coils
    ]);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

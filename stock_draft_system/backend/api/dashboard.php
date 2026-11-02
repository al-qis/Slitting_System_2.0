<?php
/**
 * API Endpoint: Dashboard Metrics
 * Location: /stock_draft_system/backend/api/dashboard.php
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../adapters/SlittingDbAdapter.php';
require_once __DIR__ . '/../models/DraftCoil.php';

try {
    $adapter = new SlittingDbAdapter();
    $monthEndMetrics = $adapter->getMonthEndMetrics();

    $draftCoilModel = new DraftCoil();
    $draftStats = $draftCoilModel->getSummaryStats();

    echo json_encode([
        'ok' => true,
        'data' => [
            'slitting_db_metrics' => $monthEndMetrics,
            'draft_system_metrics' => $draftStats
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

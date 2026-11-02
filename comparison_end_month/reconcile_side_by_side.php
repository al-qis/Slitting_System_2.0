<?php
/**
 * Side-by-Side Coil Verification & 3-Way Reconciliation Backend Processor
 * Legacy Handler Wrapper & MVC Controller Dispatcher
 * Location: /comparison_end_month/reconcile_side_by_side.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database config and composer autoloader from root
require_once __DIR__ . '/../config.php';

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

require_once __DIR__ . '/controllers/CoilVerificationController.php';

// Initialize PDO Database Connection
$pdo = null;
try {
    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    error_log("PDO Connection Error: " . $e->getMessage());
}

// Global Model Instance for Backward-Compatible Procedural Function Calls
$globalModel = new CoilReconciliationModel($pdo);

if (!function_exists('fetchScannedPhysicalStore')) {
    function fetchScannedPhysicalStore(?PDO $pdo = null): array {
        global $globalModel;
        $model = $pdo ? new CoilReconciliationModel($pdo) : $globalModel;
        return $model->fetchScannedPhysicalStore();
    }
}

if (!function_exists('normalizeLotKey')) {
    function normalizeLotKey(string $lot): string {
        return CoilReconciliationModel::normalizeLotKey($lot);
    }
}

if (!function_exists('readD365Spreadsheet')) {
    function readD365Spreadsheet(string $filePath): array {
        global $globalModel;
        return $globalModel->readD365Spreadsheet($filePath);
    }
}

if (!function_exists('evaluateReconciliation')) {
    function evaluateReconciliation(array $scannedRows, array $d365Map): array {
        global $globalModel;
        return $globalModel->evaluateReconciliation($scannedRows, $d365Map);
    }
}

if (!function_exists('exportSideBySideExcel')) {
    function exportSideBySideExcel(array $reconciledResults, ?string $monthTitle = null, ?int $year = null): void {
        global $globalModel;
        $globalModel->exportSideBySideExcel($reconciledResults, $monthTitle, $year);
    }
}

// Controller Dispatcher Execution
$controller = new CoilVerificationController($pdo);
$controller->handleRequest();

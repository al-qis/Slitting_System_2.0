<?php
/**
 * CoilVerificationController.php
 * Controller for Side-by-Side Coil Verification & 3-Way Reconciliation
 * Location: /comparison_end_month/controllers/CoilVerificationController.php
 */

require_once __DIR__ . '/../models/CoilReconciliationModel.php';

class CoilVerificationController
{
    private CoilReconciliationModel $model;

    public function __construct(?PDO $pdo = null)
    {
        $this->model = new CoilReconciliationModel($pdo);
    }

    /**
     * Get Model instance
     */
    public function getModel(): CoilReconciliationModel
    {
        return $this->model;
    }

    /**
     * Main Request Lifecycle Handler
     */
    public function handleRequest(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $action = $_REQUEST['action'] ?? '';

        // Action: Clear uploaded D365 file
        if ($action === 'clear_file') {
            unset($_SESSION['last_d365_data'], $_SESSION['last_d365_filename']);
            header('Location: coil_verification.php');
            exit;
        }

        // Action: Parse uploaded D365 file if provided
        $d365Map = [];
        if (isset($_FILES['d365_file']) && $_FILES['d365_file']['error'] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['d365_file']['tmp_name'];
            $d365Map = $this->model->readD365Spreadsheet($tmpPath);
            $_SESSION['last_d365_data'] = $d365Map;
            $_SESSION['last_d365_filename'] = $_FILES['d365_file']['name'];
        } elseif (!empty($_SESSION['last_d365_data'])) {
            $d365Map = $_SESSION['last_d365_data'];
        }

        // Query MySQL for Ground Truth (Scanned Physical Store)
        $scannedRows = $this->model->fetchScannedPhysicalStore();

        // Reconcile 3-way data
        $reconciledResults = $this->model->evaluateReconciliation($scannedRows, $d365Map);

        // Action: Download sample template
        if ($action === 'download_template') {
            $sampleFile = file_exists(__DIR__ . '/../sample_d365_template.xlsx')
                ? __DIR__ . '/../sample_d365_template.xlsx'
                : __DIR__ . '/../../sample_d365_template.xlsx';

            if (file_exists($sampleFile)) {
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="Sample_D365_Export_Template.xlsx"');
                header('Content-Length: ' . filesize($sampleFile));
                readfile($sampleFile);
                exit;
            }
        }

        // Action: Export to Excel
        if ($action === 'export') {
            $this->model->exportSideBySideExcel($reconciledResults);
            exit;
        }

        // Compute summary metrics for KPI cards
        $metrics = $this->model->calculateMetrics($reconciledResults, $scannedRows, $d365Map);
        $uploadedFileName = $_SESSION['last_d365_filename'] ?? '';

        // Render the View template
        require __DIR__ . '/../views/coil_verification_view.php';
    }
}

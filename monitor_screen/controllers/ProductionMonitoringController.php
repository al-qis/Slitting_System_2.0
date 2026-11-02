<?php
/**
 * ProductionMonitoringController.php
 * Controller for Production Monitoring Dashboard (Real-time TV Display)
 * Location: /monitor_screen/controllers/ProductionMonitoringController.php
 */

require_once __DIR__ . '/../models/ProductionMonitoringModel.php';

class ProductionMonitoringController
{
    private mysqli $conn;
    private ProductionMonitoringModel $model;

    public function __construct(?mysqli $conn = null)
    {
        if ($conn === null) {
            require __DIR__ . '/../../config.php';
        }
        $this->conn = $conn;
        $this->model = new ProductionMonitoringModel($this->conn);
    }

    public function getModel(): ProductionMonitoringModel
    {
        return $this->model;
    }

    /**
     * Render the Monitoring View HTML
     */
    public function index(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['role'])) {
            header("Location: ../login.php");
            exit;
        }

        $conn = $this->conn;
        $page_title = "Real-Time Production Monitoring";
        $hide_sidebar = true;
        $pathPrefix = '../';

        require __DIR__ . '/../views/production_monitoring_view.php';
    }

    /**
     * Handle AJAX endpoint: action=get_data
     */
    public function ajaxGetData(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        header('Content-Type: application/json; charset=utf-8');

        if (!isset($_SESSION['role'])) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }

        $data = $this->model->getMonitoringData();
        echo json_encode($data);
        exit;
    }

    /**
     * General Request Dispatcher
     */
    public function handleRequest(): void
    {
        $action = $_REQUEST['action'] ?? '';
        if ($action === 'get_data') {
            $this->ajaxGetData();
        } else {
            $this->index();
        }
    }
}

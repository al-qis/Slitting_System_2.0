<?php
/**
 * CustomerTrackOrderController.php
 * Controller for Customer Order Tracking Portal
 * Location: /customer_track_order/controllers/CustomerTrackOrderController.php
 */

require_once __DIR__ . '/../models/CustomerTrackOrderModel.php';

class CustomerTrackOrderController
{
    private mysqli $conn;
    private CustomerTrackOrderModel $model;

    public function __construct(?mysqli $conn = null)
    {
        if ($conn === null) {
            $configPath = file_exists(__DIR__ . '/../../config.php') 
                ? __DIR__ . '/../../config.php' 
                : __DIR__ . '/../config.php';
            require $configPath;
        }

        $this->conn = $conn;
        $this->model = new CustomerTrackOrderModel($this->conn);
    }

    public function getModel(): CustomerTrackOrderModel
    {
        return $this->model;
    }

    /**
     * Render the Customer Order Tracking View
     */
    public function index(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $model = $this->model;

        // ── Input Sanitization ───────────────────────────────────────────────
        $searchSo       = $model->cleanInput($_GET['so'] ?? ($_GET['order'] ?? ''));
        $searchQuery    = $model->cleanInput($_GET['search'] ?? '');
        $filterCustomer = $model->cleanInput($_GET['customer'] ?? '');
        $filterStatus   = $model->cleanInput($_GET['status'] ?? '');

        // If user typed an SO pattern in generic search, prioritize it as single order
        if ($searchSo === '' && $searchQuery !== '' && preg_match('/^SO-[\d-]+/i', $searchQuery)) {
            $searchSo = $searchQuery;
        }

        // Determine assets path prefix and page URL
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if (basename($scriptDir) === 'views') {
            $pathPrefix = '../../';
            $pageUrl = '../index.php';
        } elseif (basename($scriptDir) === 'customer_track_order') {
            $pathPrefix = '../';
            $pageUrl = htmlspecialchars(basename($_SERVER['PHP_SELF'] ?? 'index.php'));
        } else {
            $pathPrefix = '';
            $pageUrl = htmlspecialchars(basename($_SERVER['PHP_SELF'] ?? 'index.php'));
        }

        // ── Customer List for Dropdown and Filter Pills ──────────────────────
        $customerList = $model->getCustomerList();

        // ── Single Order Lookup Mode ─────────────────────────────────────────
        $singleOrder = null;
        $orderRolls  = [];

        if ($searchSo !== '') {
            $orderData = $model->getSingleOrder($searchSo);
            if ($orderData !== null) {
                $singleOrder = $orderData['summary'];
                $orderRolls  = $orderData['rolls'];
            }
        }

        // ── Multi-Order List Mode (Only needed if not in single order view) ───
        $orderList            = [];
        $totalOrdersCount     = 0;
        $deliveredOrdersCount = 0;
        $inProdOrdersCount    = 0;
        $readyOrdersCount     = 0;

        if ($singleOrder === null) {
            $listResult = $model->getOrderList($filterCustomer, $filterStatus, $searchQuery);
            $orderList            = $listResult['orders'];
            $stats                = $listResult['stats'];
            $totalOrdersCount     = $stats['total_orders'];
            $deliveredOrdersCount = $stats['delivered_orders'];
            $inProdOrdersCount    = $stats['in_prod_orders'];
            $readyOrdersCount     = $stats['ready_orders'];
        }

        $page_title = "Customer Order Tracking - MK Slitting";

        require __DIR__ . '/../views/customer_track_order_view.php';
    }

    /**
     * Handle AJAX endpoint requests
     */
    public function handleAjax(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        header('Content-Type: application/json; charset=utf-8');

        $action = $_REQUEST['action'] ?? 'search';
        $model = $this->model;

        if ($action === 'track_so') {
            $so = $model->cleanInput($_REQUEST['so'] ?? '');
            $orderData = $model->getSingleOrder($so);

            if ($orderData !== null) {
                echo json_encode([
                    'success' => true,
                    'order'   => $orderData['summary'],
                    'rolls'   => $orderData['rolls']
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Sales Order not found'
                ]);
            }
            exit;
        }

        if ($action === 'search_orders') {
            $customer = $model->cleanInput($_REQUEST['customer'] ?? '');
            $status   = $model->cleanInput($_REQUEST['status'] ?? '');
            $search   = $model->cleanInput($_REQUEST['search'] ?? '');
            $listResult = $model->getOrderList($customer, $status, $search);

            echo json_encode([
                'success' => true,
                'data'    => $listResult
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }
}

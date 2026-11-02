<?php
/**
 * OfficerProductionController.php
 * Controller for Officer Production Monitor
 * Location: /monitor_screen/controllers/OfficerProductionController.php
 */

require_once __DIR__ . '/../models/OfficerProductionModel.php';

class OfficerProductionController
{
    private mysqli $conn;
    private OfficerProductionModel $model;

    public function __construct(?mysqli $conn = null)
    {
        if ($conn === null) {
            require __DIR__ . '/../../config.php';
        }
        $this->conn = $conn;
        $this->model = new OfficerProductionModel($this->conn);
    }

    public function getModel(): OfficerProductionModel
    {
        return $this->model;
    }

    /**
     * Render Officer Monitor View HTML
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
        $page_title = "Papan Pemantauan Pengeluaran Pegawai (Officer Monitor)";
        $pathPrefix = '../';

        $shiftTarget = (float)$this->model->getSystemSetting('shift_target_meters', '5200');
        if ($shiftTarget <= 0) {
            $shiftTarget = 5200.0;
        }
        $dailyTargetDefault = $shiftTarget * 2;
        $weeklyTargetDefault = $dailyTargetDefault * 7;

        require __DIR__ . '/../views/officer_production_view.php';
    }

    /**
     * Handle AJAX requests for Officer Monitor
     */
    public function handleAjax(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        header('Content-Type: application/json; charset=utf-8');

        if (!isset($_SESSION['role'])) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
            exit;
        }

        $action = $_REQUEST['action'] ?? 'get_data';

        if ($action === 'get_data') {
            $shiftTarget = (float)$this->model->getSystemSetting('shift_target_meters', '5200');
            if ($shiftTarget <= 0) {
                $shiftTarget = 5200.0;
            }

            $weeklyData = $this->model->getWeeklyPerformanceSlots($shiftTarget);
            $currentMondayTs  = strtotime($weeklyData['monday_cycle_start']);
            $lastWeekMondayTs = strtotime('-7 days', $currentMondayTs);
            $lastWeekData     = $this->model->getWeeklyPerformanceSlots($shiftTarget, $lastWeekMondayTs);

            $lastReset = $this->model->getSystemSetting('last_weekly_reset_at', '-');

            echo json_encode([
                'success'               => true,
                'timestamp'             => date('Y-m-d H:i:s'),
                'shift_target_meters'   => $shiftTarget,
                'daily_target_meters'   => $weeklyData['daily_target_meters'],
                'weekly_target_meters'  => $weeklyData['weekly_target_meters'],
                'last_weekly_reset_at'  => $lastReset,
                'weekly_performance'    => $weeklyData,
                'last_week_performance' => $lastWeekData
            ]);
            exit;
        }

        if ($action === 'update_target') {
            $rawVal = $_POST['target_meters'] ?? $_GET['target_meters'] ?? '';
            $newTarget = floatval($rawVal);

            if ($newTarget <= 0) {
                echo json_encode(['success' => false, 'message' => 'Sila masukkan nilai sasaran syif yang sah (lebih daripada 0 meter).']);
                exit;
            }

            $user = $_SESSION['role'] ?? 'officer';
            $ok = $this->model->updateTarget($newTarget, $user);

            if ($ok) {
                echo json_encode([
                    'success'        => true,
                    'message'        => 'Sasaran purata syif berjaya dikemas kini.',
                    'new_target'     => $newTarget,
                    'daily_target'   => $newTarget * 3,
                    'weekly_target'  => $newTarget * 3 * 7
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Ralat ketika menyimpan sasaran ke pangkalan data.']);
            }
            exit;
        }

        if ($action === 'manual_reset') {
            $user = $_SESSION['role'] ?? 'officer';
            $nowStr = $this->model->manualReset($user);

            echo json_encode([
                'success'   => true,
                'message'   => 'Reset kitaran pengeluaran mingguan berjaya dilaksanakan.',
                'timestamp' => $nowStr
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Tindakan tidak sah.']);
        exit;
    }

    /**
     * General Request Dispatcher
     */
    public function handleRequest(): void
    {
        $action = $_REQUEST['action'] ?? '';
        if ($action !== '') {
            $this->handleAjax();
        } else {
            $this->index();
        }
    }
}

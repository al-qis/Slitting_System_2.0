<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Models\Batch;
use mysqli;

class BatchController extends Controller
{
    private Batch $batchModel;
    private mysqli $conn;

    public function __construct()
    {
        parent::__construct();
        $this->batchModel = new Batch();
        $this->conn = Database::getConnection();
    }

    /**
     * Batch Setup & Print Page (formerly batch_setup.php)
     */
    public function setup(): void
    {
        $this->requireRole(['slitting']);

        $lot_no    = trim($_GET['lot_no']   ?? '');
        $coil_no   = trim($_GET['coil_no']  ?? '');
        $mother_id = intval($_GET['mother_id'] ?? 0);

        $backMonth  = isset($_GET['month'])  ? (int)$_GET['month']  : (int)date('m');
        $backYear   = isset($_GET['year'])   ? (int)$_GET['year']   : (int)date('Y');
        $backDay    = isset($_GET['day'])    ? (int)$_GET['day']    : 0;
        $backSearch = trim($_GET['search'] ?? '');
        $backFilter = trim($_GET['filter'] ?? '');
        $backUrl = 'finish_product.php?' . http_build_query(array_filter([
            'month'  => $backMonth,
            'year'   => $backYear,
            'day'    => $backDay > 0 ? $backDay : null,
            'search' => $backSearch !== '' ? $backSearch : null,
            'filter' => $backFilter !== '' ? $backFilter : null,
        ]));

        if ($lot_no === '' || $coil_no === '') {
            die('<p style="font-family:Arial;padding:24px;">Lot No and Coil No are both required.</p>'
                . '<a href="' . htmlspecialchars($backUrl) . '">&larr; Back to List</a>');
        }

        $rolls = $this->batchModel->getRollsForBatchSetup($lot_no, $coil_no, $mother_id);

        $this->render('batch/setup', [
            'conn'       => $this->conn,
            'lot_no'     => $lot_no,
            'coil_no'    => $coil_no,
            'mother_id'  => $mother_id,
            'backMonth'  => $backMonth,
            'backYear'   => $backYear,
            'backDay'    => $backDay,
            'backSearch' => $backSearch,
            'backFilter' => $backFilter,
            'backUrl'    => $backUrl,
            'rolls'      => $rolls,
        ]);
    }

    /**
     * AJAX "Save All" for batch_setup.php (formerly batch_setup_save.php)
     */
    public function saveAll(): void
    {
        $this->requireRole(['slitting']);

        $body = json_decode(file_get_contents('php://input'), true);
        $selections = $body['selections'] ?? null;

        if (!is_array($selections) || empty($selections)) {
            $this->json(['ok' => false, 'msg' => 'No rolls to save.']);
            return;
        }

        $performedBy = $_SESSION['role'] ?? 'system';
        $res = $this->batchModel->saveBatchSelections($selections, $performedBy);
        $this->json($res);
    }

    /**
     * Consolidated Batch Print Action (formerly batch_print_action.php)
     */
    public function batchPrint(): void
    {
        $this->requireRole(['slitting']);

        require 'c:/laragon/www/Slitting_System_2.0/batch_print_action.php';
    }

    /**
     * Mixed Batch Setup Page (formerly mixed_batch_setup.php)
     */
    public function mixedSetup(): void
    {
        $this->requireRole(['slitting']);

        $rawIds = null;
        if (isset($_POST['ids'])) {
            $rawIds = json_decode($_POST['ids'], true);
        } elseif (isset($_GET['ids'])) {
            $rawIds = array_map('trim', explode(',', $_GET['ids']));
        }

        $ids = is_array($rawIds)
            ? array_values(array_unique(array_filter(array_map('intval', $rawIds))))
            : [];

        $from            = trim($_REQUEST['from'] ?? $_REQUEST['source'] ?? '');
        $backMonth       = isset($_REQUEST['month'])  ? (int)$_REQUEST['month']  : (int)date('m');
        $backYear        = isset($_REQUEST['year'])   ? (int)$_REQUEST['year']   : (int)date('Y');
        $backDay         = isset($_REQUEST['day'])    ? (int)$_REQUEST['day']    : 0;
        $backSearch      = trim($_REQUEST['search'] ?? '');
        $backFilter      = trim($_REQUEST['filter'] ?? '');
        $backPrintFilter = trim($_REQUEST['print_status'] ?? '');

        if ($from === 'slitting_product') {
            $backUrl = 'slitting_product.php?' . http_build_query(array_filter([
                'search'       => $backSearch !== '' ? $backSearch : null,
                'print_status' => $backPrintFilter !== '' ? $backPrintFilter : null,
            ]));
        } else {
            $backUrl = 'finish_product.php?' . http_build_query(array_filter([
                'month'  => $backMonth,
                'year'   => $backYear,
                'day'    => $backDay > 0 ? $backDay : null,
                'search' => $backSearch !== '' ? $backSearch : null,
                'filter' => $backFilter !== '' ? $backFilter : null,
            ]));
        }

        if (empty($ids)) {
            die('<p style="font-family:Arial;padding:24px;">No rolls were selected.</p>'
                . '<a href="' . htmlspecialchars($backUrl) . '">&larr; Back to List</a>');
        }

        $data = $this->batchModel->getRollsForMixedBatchSetup($ids, $from);
        $rolls  = $data['rolls'];
        $errors = $data['errors'];

        $this->render('batch/mixed_setup', [
            'conn'            => $this->conn,
            'from'            => $from,
            'backMonth'       => $backMonth,
            'backYear'        => $backYear,
            'backDay'         => $backDay,
            'backSearch'      => $backSearch,
            'backFilter'      => $backFilter,
            'backPrintFilter' => $backPrintFilter,
            'backUrl'         => $backUrl,
            'rolls'           => $rolls,
            'errors'          => $errors,
        ]);
    }

    /**
     * AJAX "Save Only" for mixed batch (formerly mixed_batch_save_action.php)
     */
    public function mixedSave(): void
    {
        $this->requireRole(['slitting']);

        require 'c:/laragon/www/Slitting_System_2.0/mixed_batch_save_action.php';
    }

    /**
     * Consolidated Mixed Batch Print Action (formerly mixed_batch_print_action.php)
     */
    public function mixedPrint(): void
    {
        $this->requireRole(['slitting']);

        require 'c:/laragon/www/Slitting_System_2.0/mixed_batch_print_action.php';
    }
}

<?php
session_start();

// 1. Authentication & Role Check
if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION['role'] !== 'slitting') {
    die("Access denied");
}

include 'config.php';
require_once __DIR__ . '/control_system/config_store.php';
$isReslitActive = function_exists('isButtonActive') ? isButtonActive('reslit_reslit') : true;

// ── Defensive Schema Check: Ensure reslit_id exists in slitting_plans ──
$colCheck = $conn->query("SHOW COLUMNS FROM slitting_plans LIKE 'reslit_id'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE slitting_plans ADD COLUMN reslit_id INT NULL DEFAULT NULL AFTER stock_id, ADD KEY idx_slitting_plans_reslit (reslit_id)");
    $conn->query("ALTER TABLE slitting_plans MODIFY COLUMN mother_coil_id INT NULL");
}

// ── AJAX Endpoint: Get Slitting Plan for Reslit Roll ───────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_reslit_plan') {
    header('Content-Type: application/json; charset=utf-8');
    $reslit_id = intval($_GET['reslit_id'] ?? 0);
    $plans = [];
    $reslit = null;
    if ($reslit_id > 0) {
        $rRes = $conn->query("SELECT id, product, lot_no, coil_no, roll_no, width, length, mother_id FROM reslit_product WHERE id = $reslit_id LIMIT 1");
        if ($rRes && $rRes->num_rows > 0) {
            $reslit = $rRes->fetch_assoc();
        }
        $stmt = $conn->prepare("SELECT roll_seq, planned_width, customer_name, ref_no FROM slitting_plans WHERE reslit_id = ? ORDER BY sort_order ASC, id ASC");
        if ($stmt) {
            $stmt->bind_param("i", $reslit_id);
            $stmt->execute();
            $plans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }
    echo json_encode(['ok' => true, 'plans' => $plans, 'reslit' => $reslit]);
    exit;
}

// ── POST Action: Save Slitting Plan for Reslit Roll ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_reslit_plan') {
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || ($_POST['ajax'] ?? '') === '1';

    $reslit_id = intval($_POST['reslit_id'] ?? 0);
    if ($reslit_id <= 0) {
        if ($is_ajax) { echo json_encode(['ok' => false, 'msg' => 'Invalid reslit ID']); exit; }
        $_SESSION['error'] = 'Invalid reslit ID.';
        header('Location: reslit.php');
        exit;
    }

    $rRes = $conn->query("SELECT id, mother_id, lot_no, coil_no, roll_no, width, length FROM reslit_product WHERE id = $reslit_id LIMIT 1");
    if (!$rRes || $rRes->num_rows === 0) {
        if ($is_ajax) { echo json_encode(['ok' => false, 'msg' => 'Reslit record not found']); exit; }
        $_SESSION['error'] = 'Reslit record not found.';
        header('Location: reslit.php');
        exit;
    }
    $reslitItem = $rRes->fetch_assoc();
    $mother_id = !empty($reslitItem['mother_id']) ? intval($reslitItem['mother_id']) : null;

    $planSeqs      = $_POST['plan_seq']      ?? [];
    $planWidths    = $_POST['plan_width']    ?? [];
    $planCustomers = $_POST['plan_customer'] ?? [];
    $planRefs      = $_POST['plan_ref']      ?? [];

    $conn->begin_transaction();
    try {
        $del = $conn->prepare("DELETE FROM slitting_plans WHERE reslit_id = ?");
        $del->bind_param("i", $reslit_id);
        $del->execute();
        $del->close();

        $savedCount = 0;
        if (is_array($planSeqs) && is_array($planWidths)) {
            $ins = $conn->prepare("
                INSERT INTO slitting_plans (mother_coil_id, stock_id, reslit_id, roll_seq, planned_width, customer_name, ref_no, sort_order)
                VALUES (?, NULL, ?, ?, ?, ?, ?, ?)
            ");
            $order = 0;
            foreach ($planSeqs as $i => $seqRaw) {
                $seq   = trim($seqRaw);
                $wRaw  = trim($planWidths[$i] ?? '');
                $cust  = trim($planCustomers[$i] ?? '');
                $refNo = trim($planRefs[$i] ?? '');
                if ($seq === '' || $wRaw === '' || !is_numeric($wRaw)) continue;
                $order++;
                $savedCount++;
                $wVal = (float)$wRaw;
                $ins->bind_param("iisdssi", $mother_id, $reslit_id, $seq, $wVal, $cust, $refNo, $order);
                $ins->execute();
            }
            $ins->close();
        }

        $conn->commit();
        $label = trim(($reslitItem['lot_no'] ?? '') . ' ' . ($reslitItem['coil_no'] ?? '') . ' ' . ($reslitItem['roll_no'] ?? ''));
        $msg = $savedCount > 0
            ? "✓ Slitting plan saved for reslit roll {$label} ({$savedCount} rolls planned)."
            : "✓ Slitting plan cleared for reslit roll {$label}.";

        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'msg' => $msg, 'count' => $savedCount, 'reslit_id' => $reslit_id]);
            exit;
        }

        $_SESSION['success'] = $msg;
        header('Location: reslit.php');
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => 'Error: ' . $e->getMessage()]);
            exit;
        }
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
        header('Location: reslit.php');
        exit;
    }
}

// ── Slitting plan counts for reslit items ─────────────────────
$reslit_plan_counts = [];
$rpRes = $conn->query("SELECT reslit_id, COUNT(*) as cnt FROM slitting_plans WHERE reslit_id IS NOT NULL AND reslit_id > 0 GROUP BY reslit_id");
if ($rpRes) {
    while ($rpr = $rpRes->fetch_assoc()) {
        $reslit_plan_counts[(int)$rpr['reslit_id']] = (int)$rpr['cnt'];
    }
}

// ── Known Customer Codes for Autocomplete ─────────────────────
$knownCustomers = ['NAE','NAX','NCI MFG','TAIHO','NRI','ASHUKA','NIPPON','NTC','SGC','STAMPING','YANTAI','NIPP','NVC','NSJ','NIP','YTEC','NSA','NCI 2','STOCK','TRIAL'];
$cRes = $conn->query("SELECT DISTINCT customer FROM nci_product_mapping WHERE customer IS NOT NULL AND customer != ''");
if ($cRes) {
    while ($cr = $cRes->fetch_assoc()) {
        $c = trim($cr['customer']);
        if ($c !== '' && !in_array($c, $knownCustomers, true)) {
            $knownCustomers[] = $c;
        }
    }
}

// ── Excel Download ────────────────────────────────────────────
if (isset($_GET['download']) && $_GET['download'] === 'excel') {
    $exRes = $conn->query("
        SELECT
            r.id, r.status, r.cut_type, r.product, r.lot_no, r.roll_no,
            r.width, r.length, r.actual_length, r.date_in, r.completed_at,
            COALESCE(NULLIF(s.actual_length, 0), r.length) AS effective_length
        FROM reslit_product r
        LEFT JOIN (
            SELECT sp1.*
            FROM slitting_product sp1
            INNER JOIN (
                SELECT lot_no, roll_no, MAX(id) AS max_id
                FROM slitting_product
                GROUP BY lot_no, roll_no
            ) sp2 ON sp1.id = sp2.max_id
        ) s ON s.lot_no = r.lot_no AND s.roll_no = r.roll_no
        ORDER BY r.id ASC
    ");

    $filename = 'Reslit_Report_' . date('Y-m-d') . '.xls';
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $cols      = 12; // total columns
    $generated = date('d M Y, H:i');
?>
<html><head><meta charset="UTF-8"></head><body>

<table>
    <!-- Title block -->
    <tr>
        <td colspan="<?= $cols ?>" style="background:#1e3a5f;color:#fff;font-size:18px;font-weight:bold;padding:12px 16px;letter-spacing:1px;">
            RESLIT PRODUCT REPORT
        </td>
    </tr>
    <tr>
        <td colspan="<?= $cols ?>" style="background:#2c5282;color:#bee3f8;font-size:11px;padding:4px 16px;">
            Generated: <?= $generated ?> &nbsp;|&nbsp; System: Slitting Management
        </td>
    </tr>
    <tr><td colspan="<?= $cols ?>" style="background:#e2e8f0;padding:6px 16px;font-size:11px;color:#4a5568;">
        <strong>Note:</strong> Child rolls (↳) are shown indented under their parent reslit record.
    </td></tr>
    <tr><td colspan="<?= $cols ?>"></td></tr><!-- spacer -->
</table>

<table border="1" style="border-collapse:collapse;">
    <thead>
        <tr style="background:#343a40;color:#fff;font-weight:bold;font-size:12px;">
            <th style="padding:8px 10px;">ID</th>
            <th style="padding:8px 10px;">Status</th>
            <th style="padding:8px 10px;">Cut Type</th>
            <th style="padding:8px 10px;">Product</th>
            <th style="padding:8px 10px;">Lot No.</th>
            <th style="padding:8px 10px;">Roll No.</th>
            <th style="padding:8px 10px;">Width (mm)</th>
            <th style="padding:8px 10px;">Length (m)</th>
            <th style="padding:8px 10px;">Effective Length (m)</th>
            <th style="padding:8px 10px;">Actual Length (m)</th>
            <th style="padding:8px 10px;">Date In</th>
            <th style="padding:8px 10px;">Completed At</th>
        </tr>
    </thead>
    <tbody>
<?php

    $td   = 'style="padding:6px 10px;"';
    $tdN  = 'style="padding:6px 10px;text-align:right;"';

    if ($exRes && $exRes->num_rows > 0) {
        while ($row = $exRes->fetch_assoc()) {
            // Zebra: white rows for parents
            echo '<tr style="background:#ffffff;">';
            echo '<td ' . $td  . '>' . (int)$row['id'] . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars(strtoupper($row['status']   ?? '-')) . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars(strtoupper($row['cut_type'] ?? '-')) . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($row['product']  ?? '-') . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($row['lot_no']   ?? '-') . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($row['roll_no']  ?? '-') . '</td>';
            echo '<td ' . $tdN . '>' . number_format((float)($row['width']            ?? 0)) . '</td>';
            echo '<td ' . $tdN . '>' . number_format((float)($row['length']           ?? 0)) . '</td>';
            $isBalanceRow = strtoupper(trim($row['roll_no'] ?? '')) === 'BALANCE';
            echo '<td ' . $tdN . '>' . number_format((float)($row['effective_length'] ?? 0)) . '</td>';
            echo '<td ' . $tdN . '>' . ($isBalanceRow ? '-' : number_format((float)($row['actual_length'] ?? 0))) . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($row['date_in']      ?? '-') . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($row['completed_at'] ?? '-') . '</td>';
            echo '</tr>';

            // Child rolls from reslit_rolls — light blue indent rows
            $rolls = $conn->query("SELECT * FROM reslit_rolls WHERE parent_id = " . (int)$row['id'] . " ORDER BY id ASC");
            if ($rolls && $rolls->num_rows > 0) {
                while ($roll = $rolls->fetch_assoc()) {
                    $lotDisplay = htmlspecialchars($row['lot_no'] ?? '-') . htmlspecialchars($roll['cut_letter'] ?? '');
                    $tdC  = 'style="background:#ebf8ff;padding:5px 10px;color:#2c5282;"';
                    $tdCN = 'style="background:#ebf8ff;padding:5px 10px;color:#2c5282;text-align:right;"';
                    echo '<tr>';
                    echo '<td ' . $tdC  . '>↳</td>';
                    echo '<td ' . $tdC  . '>COMPLETED</td>';
                    echo '<td ' . $tdC  . '>-</td>';
                    echo '<td ' . $tdC  . '>' . htmlspecialchars($row['product'] ?? '-') . '</td>';
                    echo '<td ' . $tdC  . '>' . $lotDisplay . '</td>';
                    echo '<td ' . $tdC  . '>' . htmlspecialchars($roll['roll_no'] ?? '-') . '</td>';
                    echo '<td ' . $tdCN . '>' . number_format((float)($roll['new_width']     ?? 0)) . '</td>';
                    echo '<td ' . $tdCN . '>' . number_format((float)($roll['length']        ?? 0)) . '</td>';
                    echo '<td ' . $tdCN . '>-</td>';
                    echo '<td ' . $tdCN . '>' . number_format((float)($roll['actual_length'] ?? 0)) . '</td>';
                    echo '<td ' . $tdC  . '>-</td>';
                    echo '<td ' . $tdC  . '>-</td>';
                    echo '</tr>';
                }
            }
        }
    }

    ?></tbody></table></body></html><?php
    exit;
}

// 2. Fetch Data with Effective Length Logic
$result = $conn->query("
    SELECT 
        r.*,
        COALESCE(NULLIF(s.actual_length, 0), r.length) AS effective_length
    FROM reslit_product r
    LEFT JOIN (
        SELECT sp1.*
        FROM slitting_product sp1
        INNER JOIN (
            SELECT lot_no, coil_no, roll_no, MAX(id) AS max_id
            FROM slitting_product
            GROUP BY lot_no, coil_no, roll_no
        ) sp2
          ON sp1.id = sp2.max_id
    ) s
      ON s.lot_no = r.lot_no
     AND s.coil_no = r.coil_no
     AND s.roll_no = r.roll_no
    ORDER BY r.id ASC
");

// 3. Set Page Title and Include Header
$page_title = "Reslit Product Management";
include 'header.php';
?>

<style>
    .status-cards { display: flex; gap: 15px; margin-bottom: 30px; }
    .status-card {
        flex: 1; border-radius: 8px; padding: 20px; text-align: center; color: white;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        cursor: pointer; user-select: none;
        border: 3px solid transparent;
        opacity: .72;
        transition: transform .15s ease, opacity .15s ease, box-shadow .15s ease, border-color .15s ease;
    }
    .status-card:hover { opacity: 1; transform: translateY(-2px); }
    .status-card.active {
        opacity: 1;
        border-color: #fff;
        box-shadow: 0 6px 16px rgba(0,0,0,0.28);
        transform: translateY(-2px);
    }
    .status-card.pending { background: linear-gradient(135deg, #ffc107, #ff9800); }
    .status-card.completed { background: linear-gradient(135deg, #28a745, #20c997); }

    /* Reslit search bar */
    #reslitSearchWrap { padding: 14px 16px 0; }
    #reslitSearchInput:focus { box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
    
    .info-box { background: #f0f9ff; border-left: 4px solid #0d6efd; border-radius: 6px; padding: 15px; margin-bottom: 20px; }
    .slitting-box { border: 1px solid #ddd; padding: 15px; border-radius: 8px; margin-bottom: 15px; background: #f9f9f9; }
    .highlight-field { background: #fff3cd !important; border: 2px solid #ffc107 !important; }
    .child-row-bg { background-color: #f0f9ff; }

    /* ── Compact rolls table for reslit output (matches add_slitting.php) ── */
    #rollsTableWrap {
        max-height: 45vh;
        overflow-y: auto;
        border: 1px solid #dee2e6;
        border-radius: 10px;
    }
    #rollsTable { margin-bottom: 0; }
    #rollsTable thead th {
        position: sticky;
        top: 0;
        z-index: 5;
        background: #f8f9fa;
        white-space: nowrap;
    }
    #rollsTable td, #rollsTable th { vertical-align: middle; padding: 8px 10px; }
    #rollsTable input.form-control, #rollsTable select.form-select { min-width: 90px; }
    #bulkApplyBar {
        background: #e7f1ff;
        border: 1px solid #b6d4fe;
        border-radius: 8px;
        padding: 10px 14px;
    }

    /* ── SFC toggle — visible pill with icon + text, matches add_slitting.php ── */
    .sfc-toggle {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 10px;
        border: 1px solid #ced4da;
        border-radius: 20px;
        background: #fff;
        cursor: pointer;
        user-select: none;
        transition: all .15s ease;
        font-size: .8rem;
        font-weight: 600;
        color: #6c757d;
    }
    .sfc-toggle:hover { border-color: #0d6efd; background: #f0f6ff; }
    .sfc-toggle .sfc-checkbox {
        width: 18px;
        height: 18px;
        margin: 0;
        cursor: pointer;
    }
    .sfc-toggle:has(.sfc-checkbox:checked) {
        background: #cfe2ff;
        border-color: #0d6efd;
        color: #0d6efd;
    }
    .sfc-toggle.sfc-active {
        background: #cfe2ff;
        border-color: #0d6efd;
        color: #0d6efd;
    }
    tr.sfc-row-active { background-color: #f0f6ff !important; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-intersect me-2"></i>Reslit Product Management</h2>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-info shadow-sm text-white" data-bs-toggle="modal" data-bs-target="#summaryModal">
            <i class="bi bi-bar-chart-line me-1"></i> Summary Report
        </button>
        <a href="?download=excel" class="btn btn-success shadow-sm">
            <i class="bi bi-download me-1"></i> Download Excel
        </a>
    </div>
</div>

<!-- ═══ ADD PRODUCT TO RESLIT — one unified intake bar ═══════════
     No mode selection: this single field handles hardware-scanner
     input (which types like a keyboard, ending in Enter), manual
     typing of a combined Lot-Coil-Roll string, and camera-scanned
     QR content (fed in via the floating scan button). Server-side
     parsing lives in reslit_intake.php. Same pattern as recoiling.php. -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white">
        <span class="fw-bold"><i class="bi bi-upc-scan text-primary me-1"></i> Add Product to Reslit</span>
    </div>
    <div class="card-body">
        <form id="intakeForm" class="d-flex gap-2" onsubmit="return submitIntake(event)">
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-upc-scan"></i></span>
                <input type="text" id="intakeInput" class="form-control form-control-lg"
                       placeholder="Scan, or type Lot-Coil-Roll (e.g. 826529-N-2-R4)"
                       autocomplete="off" autofocus>
            </div>
            <button type="submit" class="btn btn-primary btn-lg px-4" id="intakeSubmitBtn">
                <i class="bi bi-plus-lg me-1"></i>Add
            </button>
        </form>
        <div class="form-text mt-1">
            Ready for scanner input — just scan a label. Typing manually? Use
            <code>LotNo-CoilNo-RollNo</code> or <code>LotNo CoilNo RollNo</code>, then press Enter.
        </div>
        <div id="intakeFeedback" class="alert py-2 mb-0 mt-3" style="display:none;"></div>
    </div>
</div>

<?php 
$sessSuccess = $_SESSION['success'] ?? null;
$sessError   = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<?php if ($sessSuccess): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm mb-4">
        <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($sessSuccess) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($sessError): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($sessError) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php 
$autoPrintUrl = '';
if(isset($_GET['success'])): 
    $printBtnHtml = '';
    if ($_GET['success'] === 'completed') {
        $printIdsRaw = $_GET['print_ids'] ?? '';
        $printIds = array_values(array_filter(array_map('intval', explode(',', $printIdsRaw))));
        if (!empty($printIds)) {
            $printUrl = (count($printIds) === 1)
                ? "select_customer.php?id=" . $printIds[0] . "&from=reslit"
                : "mixed_batch_setup.php?ids=" . implode(',', $printIds) . "&from=reslit";
            
            $printBtnHtml = ' <a href="' . htmlspecialchars($printUrl) . '" target="_blank" class="btn btn-sm btn-success text-white fw-bold ms-2 shadow-sm"><i class="bi bi-printer-fill me-1"></i> Print Sticker Now</a>';
            if (!empty($_GET['auto_print'])) {
                $autoPrintUrl = $printUrl . "&autoprint=1";
            }
        }
    }
?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm mb-4">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php 
            if($_GET['success'] === 'added') echo "Product successfully added to reslit list!";
            elseif($_GET['success'] === 'started') echo "Reslit process started!";
            elseif($_GET['success'] === 'completed') echo "Reslit completed! Product added to stock." . $printBtnHtml;
            elseif($_GET['success'] === 'returned_fp') echo "<strong>Sent back to Finished Product.</strong> This item has been removed from the Reslit queue.";
            elseif($_GET['success'] === 'returned_sfc') echo "<strong>Sent back to SFC Inventory.</strong> This item has been removed from the Reslit queue.";
            elseif($_GET['success'] === 'returned') echo "<strong>Sent back.</strong> This item has been removed from the Reslit queue.";
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if(isset($_GET['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php
            switch ($_GET['error']) {
                case 'not_pending':
                    echo "Only pending reslit records can be sent back.";
                    break;
                case 'not_found':
                    echo "Record not found.";
                    break;
                case 'invalid_id':
                    echo "Invalid record ID.";
                    break;
                case 'return_failed':
                    echo "Could not send this item back: " . htmlspecialchars(urldecode($_GET['msg'] ?? ''));
                    break;
                default:
                    echo "An unknown error occurred.";
            }
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php
$pending = $conn->query("SELECT COUNT(*) as count FROM reslit_product WHERE status='pending'")->fetch_assoc()['count'];
$completed = $conn->query("SELECT COUNT(*) as count FROM reslit_product WHERE status='completed'")->fetch_assoc()['count'];
?>

<div class="status-cards">
    <div class="status-card pending active" id="statusCardPending" onclick="setReslitStatusFilter('pending', this)">
        <h5>Pending Reslit</h5>
        <h2><?= $pending ?></h2>
    </div>
    <div class="status-card completed" id="statusCardCompleted" onclick="setReslitStatusFilter('completed', this)">
        <h5>Completed</h5>
        <h2><?= $completed ?></h2>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div id="reslitSearchWrap">
        <div class="input-group">
            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
            <input type="text" id="reslitSearchInput" class="form-control"
                   placeholder="Search Lot, Coil, or Roll — e.g. &quot;826529&quot;, &quot;N-2&quot;, or &quot;826529 N-2 R4&quot;"
                   oninput="applyReslitFilters()">
            <button class="btn btn-outline-secondary" type="button" onclick="clearReslitSearch()" title="Clear search">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle text-center mb-0">
            <thead class="table-dark">
                <tr>
                    <th>No.</th>
                    <th>Status</th>
                    <th>Product</th>
                    <th>Lot & Coil No.</th>
                    <th>Roll No.</th>
                    <th>Width</th>
                    <th>Length</th>
                    <th>Actual Length</th>
                    <th>Date In</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="reslitTableBody">
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php $pendingNum = 0; $completedNum = 0; ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <?php
                            $parentStatusGroup = ($row['status'] === 'completed') ? 'completed' : 'pending';
                            // Number each status group on its own, starting at 1 —
                            // Pending and Completed are shown one at a time via the
                            // status-card filter, so each should read 1, 2, 3...
                            // independent of how many rows are in the other group.
                            if ($parentStatusGroup === 'completed') {
                                $completedNum++;
                                $rowNum = $completedNum;
                            } else {
                                $pendingNum++;
                                $rowNum = $pendingNum;
                            }
                            $parentSearchBlob = strtolower(trim(
                                ($row['product'] ?? '') . ' ' . ($row['lot_no'] ?? '') . ' ' .
                                ($row['coil_no'] ?? '') . ' ' . ($row['roll_no'] ?? '')
                            ));
                        ?>
                        <tr data-status-group="<?= htmlspecialchars($parentStatusGroup) ?>"
                            data-search="<?= htmlspecialchars($parentSearchBlob) ?>">
                            <td><?= $rowNum ?></td>
                            <td>
                                <?php if($row['status'] === 'in_progress'): ?>
                                    <span class="badge bg-primary">IN PROGRESS</span>
                                <?php elseif($row['status'] === 'pending'): ?>
                                    <span class="badge bg-warning text-dark">PENDING</span>
                                <?php else: ?>
                                    <span class="badge bg-success">COMPLETED</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($row['product'] ?? '-') ?></span></td>
                            <td><?= htmlspecialchars($row['lot_no'] ?? '-') . ' ' . htmlspecialchars($row['coil_no'] ?? '') ?></td>
                            <td><strong><?= htmlspecialchars($row['roll_no'] ?? '-') ?></strong></td>
                            <td><?= isset($row['width']) ? number_format($row['width']) : '-' ?></td>
                            <td><?= isset($row['effective_length']) ? number_format($row['effective_length']) : '-' ?></td>
                            <td class="text-success fw-bold">
                                <?php if (strtoupper(trim($row['roll_no'] ?? '')) === 'BALANCE'): ?>
                                    <span class="text-muted">-</span>
                                <?php else: ?>
                                    <?= isset($row['actual_length']) ? number_format($row['actual_length']) : '-' ?>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= htmlspecialchars($row['date_in'] ?? '-') ?></td>
                            <td>
                                <?php if ($row['status'] === 'pending' || $row['status'] === 'in_progress'): ?>
                                    <?php if ($isReslitActive): ?>
                                        <button class="btn btn-primary btn-sm mb-1" 
                                                onclick="showReslitModal(<?= (int)$row['id'] ?>, '<?= htmlspecialchars($row['product'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($row['lot_no'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($row['coil_no'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($row['roll_no'] ?? '', ENT_QUOTES) ?>', <?= (float)($row['width'] ?? 0) ?>, <?= (float)($row['effective_length'] ?? ($row['length'] ?? 0)) ?>)">
                                            <i class="bi bi-play-circle"></i> Reslit
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-secondary btn-sm mb-1 disabled opacity-75" title="Reslit button function is BLOCKED by Control Center" disabled>
                                            <i class="bi bi-slash-circle"></i> Reslit
                                        </button>
                                    <?php endif; ?>
                                    <?php $pCount = $reslit_plan_counts[(int)$row['id']] ?? 0; ?>
                                    <button type="button"
                                            class="btn btn-sm btn-reslit-plan <?= $pCount > 0 ? 'btn-info text-white' : 'btn-outline-primary' ?> mb-1"
                                            id="plan_btn_<?= $row['id'] ?>"
                                            data-reslit-id="<?= (int)$row['id'] ?>"
                                            data-product="<?= htmlspecialchars($row['product'] ?? '', ENT_QUOTES) ?>"
                                            data-lot-no="<?= htmlspecialchars($row['lot_no'] ?? '', ENT_QUOTES) ?>"
                                            data-coil-no="<?= htmlspecialchars($row['coil_no'] ?? '', ENT_QUOTES) ?>"
                                            data-roll-no="<?= htmlspecialchars($row['roll_no'] ?? '', ENT_QUOTES) ?>"
                                            data-width="<?= (float)($row['width'] ?? 0) ?>"
                                            data-length="<?= (float)($row['effective_length'] ?? ($row['length'] ?? 0)) ?>"
                                            title="<?= $pCount > 0 ? 'Edit Slitting Plan (' . $pCount . ' rolls planned)' : 'Add Slitting Plan for this roll' ?>">
                                        <?php if ($pCount > 0): ?>
                                            <i class="bi bi-pencil-square me-1"></i>Edit Plan
                                            <span class="badge bg-white text-dark ms-1 plan-badge-count"><?= $pCount ?></span>
                                        <?php else: ?>
                                            <i class="bi bi-plus-circle me-1"></i>+ Slitting Plan
                                        <?php endif; ?>
                                    </button>
                                    <a href="edit_reslit.php?id=<?= $row['id'] ?>" class="btn btn-warning btn-sm mb-1" title="Edit Record"><i class="bi bi-pencil"></i></a>
                                    <?php
                                        // Route label mirrors reslit_send_back.php's own routing rule:
                                        // slitting_product_id set -> Finish Product, otherwise -> SFC Inventory.
                                        $sendBackOrigin = !empty($row['slitting_product_id']) ? 'Finished Product' : 'SFC Inventory';
                                    ?>
                                    <?php if ($row['status'] === 'pending'): ?>
                                        <button type="button"
                                                class="btn btn-outline-danger btn-sm mb-1 btn-send-back-reslit"
                                                data-rid="<?= (int)$row['id'] ?>"
                                                data-origin="<?= htmlspecialchars($sendBackOrigin, ENT_QUOTES) ?>"
                                                data-label="<?= htmlspecialchars(($row['lot_no'] ?? '-') . ' ' . ($row['coil_no'] ?? '') . ' / Roll ' . ($row['roll_no'] ?? '-')) ?>"
                                                title="Send this item back to <?= htmlspecialchars($sendBackOrigin) ?>">
                                            <i class="bi bi-arrow-return-left"></i> Send Back
                                        </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php
                                    $pCount = $reslit_plan_counts[(int)$row['id']] ?? 0;
                                    if ($pCount > 0):
                                    ?>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-secondary mb-1 btn-reslit-plan"
                                                id="plan_btn_<?= $row['id'] ?>"
                                                data-reslit-id="<?= (int)$row['id'] ?>"
                                                data-product="<?= htmlspecialchars($row['product'] ?? '', ENT_QUOTES) ?>"
                                                data-lot-no="<?= htmlspecialchars($row['lot_no'] ?? '', ENT_QUOTES) ?>"
                                                data-coil-no="<?= htmlspecialchars($row['coil_no'] ?? '', ENT_QUOTES) ?>"
                                                data-roll-no="<?= htmlspecialchars($row['roll_no'] ?? '', ENT_QUOTES) ?>"
                                                data-width="<?= (float)($row['width'] ?? 0) ?>"
                                                data-length="<?= (float)($row['effective_length'] ?? ($row['length'] ?? 0)) ?>"
                                                title="View Slitting Plan (<?= $pCount ?> rolls)">
                                            <i class="bi bi-card-checklist me-1"></i>Plan <span class="badge bg-secondary ms-1"><?= $pCount ?></span>
                                        </button>
                                    <?php endif; ?>
                                    <?php
                                    $reslitProdIds = [];
                                    if (!empty($row['slitting_product_id'])) {
                                        $spRes = $conn->query("SELECT id FROM slitting_product WHERE source = 'reslit' AND parent_slit_id = " . (int)$row['slitting_product_id'] . " AND (is_voided = 0 OR is_voided IS NULL)");
                                        if ($spRes && $spRes->num_rows > 0) {
                                            while ($spRow = $spRes->fetch_assoc()) {
                                                $reslitProdIds[] = (int)$spRow['id'];
                                            }
                                        }
                                    }
                                    if (!empty($reslitProdIds)):
                                        $reslitPrintUrl = (count($reslitProdIds) === 1)
                                            ? "select_customer.php?id=" . $reslitProdIds[0] . "&from=reslit"
                                            : "mixed_batch_setup.php?ids=" . implode(',', $reslitProdIds) . "&from=reslit";
                                    ?>
                                        <a href="<?= htmlspecialchars($reslitPrintUrl) ?>" target="_blank" class="btn btn-outline-success btn-sm shadow-sm" title="Print Sticker">
                                            <i class="bi bi-printer-fill me-1"></i> Print Sticker
                                        </a>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark border">Done</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <?php
                        $rolls = $conn->query("SELECT * FROM reslit_rolls WHERE parent_id = {$row['id']} ORDER BY id ASC");
                        if ($rolls && $rolls->num_rows > 0):
                            while ($roll = $rolls->fetch_assoc()):
                                $childSearchBlob = strtolower(trim(
                                    ($row['product'] ?? '') . ' ' . ($row['lot_no'] ?? '') .
                                    ($roll['cut_letter'] ?? '') . ' ' . ($row['coil_no'] ?? '') . ' ' .
                                    ($roll['roll_no'] ?? '')
                                ));
                        ?>
                        <tr class="child-row-bg"
                            data-status-group="<?= htmlspecialchars($parentStatusGroup) ?>"
                            data-search="<?= htmlspecialchars($childSearchBlob) ?>">
                            <td class="small text-muted">↳</td>
                            <td><span class="badge bg-success">COMPLETED</span></td>
                            <td><?= htmlspecialchars($row['product'] ?? '-') ?></td>
                            <td class="small">
                                <?= htmlspecialchars($row['lot_no'] ?? '-') ?><?= $roll['cut_letter'] ? htmlspecialchars($roll['cut_letter']) : '' ?> <?= htmlspecialchars($row['coil_no'] ?? '') ?>
                            </td>
                            <td><strong><?= htmlspecialchars($roll['roll_no'] ?? '-') ?></strong></td>
                            <td><?= isset($roll['new_width']) ? number_format($roll['new_width']) : '-' ?></td>
                            <td><?= isset($roll['length']) ? number_format($roll['length']) : '-' ?></td>
                            <td class="text-success fw-bold"><?= isset($roll['actual_length']) ? number_format($roll['actual_length']) : '-' ?></td>
                            <td>-</td>
                            <td><span class="badge bg-light text-dark border small">Done</span></td>
                        </tr>
                        <?php endwhile; endif; ?>
                    <?php endwhile; ?>
                    <tr id="reslitNoResultsRow" style="display:none;">
                        <td colspan="10" class="py-5 text-muted">
                            No <span id="reslitNoResultsStatus">pending</span> records match your search.
                        </td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="10" class="py-5 text-muted">No reslit records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══ Hidden form: Send Back to Finished Product ═══════════════ -->
<form id="sendBackReslitForm" method="post" action="reslit_send_back.php" style="display:none;">
    <input type="hidden" name="id" id="sendBackReslitId" value="">
</form>

<!-- ═══ SUMMARY REPORT MODAL ══════════════════════════════════ -->
<div class="modal fade" id="summaryModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-info text-white">
                <div>
                    <h5 class="modal-title mb-0">
                        <i class="bi bi-bar-chart-line me-2"></i>Reslit Summary Report
                    </h5>
                    <small class="opacity-75">Reslit activity by cut type and period</small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">

                <!-- Month filter -->
                <div class="row g-2 align-items-end mb-4">
                    <div class="col-auto">
                        <label class="form-label fw-bold small">Filter by Month</label>
                        <input type="month" id="summaryMonthFilter"
                               class="form-control"
                               value="<?= date('Y-m') ?>">
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-primary btn-sm" onclick="loadSummary()">
                            <i class="bi bi-search me-1"></i>Load
                        </button>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-outline-secondary btn-sm" onclick="clearSummaryFilter()">
                            All Time
                        </button>
                    </div>
                    <div class="col-auto ms-auto small text-muted" id="summaryRange"></div>
                </div>

                <!-- KPI cards -->
                <div class="row g-3 mb-4" id="summaryKpiRow">
                    <div class="col-6">
                        <div class="card border-0 shadow-sm text-center p-3"
                             style="background:linear-gradient(135deg,#dc3545,#ff7043);color:#fff;">
                            <div class="fw-bold small mb-1"><i class="bi bi-scissors me-1"></i>Normal Reslit</div>
                            <div class="fs-2 fw-bold" id="kpi_normal">—</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="card border-0 shadow-sm text-center p-3"
                             style="background:linear-gradient(135deg,#fd7e14,#ffc107);color:#fff;">
                            <div class="fw-bold small mb-1"><i class="bi bi-scissors me-1"></i>✂️ Cut Into 2</div>
                            <div class="fs-2 fw-bold" id="kpi_cut2">—</div>
                        </div>
                    </div>
                </div>

                <!-- Detail table -->
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle" id="summaryTable">
                        <thead class="table-secondary">
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Lot No.</th>
                                <th>Roll No.</th>
                                <th>Product</th>
                                <th class="text-end">Length (m)</th>
                                <th>Output Rolls</th>
                            </tr>
                        </thead>
                        <tbody id="summaryTableBody">
                            <tr><td colspan="7" class="text-center text-muted py-3">Click Load to view report.</td></tr>
                        </tbody>
                    </table>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══ RESLIT MODAL ═══════════════════════════════════════════ -->
<div class="modal fade" id="reslitModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-scissors me-2"></i>Start Reslit Process</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="reslit_handler.php" id="reslitForm">
                <input type="hidden" name="action" value="complete_reslit_direct">
                <input type="hidden" name="id" id="reslit_id">
                <input type="hidden" name="cut_type" id="cut_type_value">
                
                <div class="modal-body p-4">
                    <div class="info-box shadow-sm mb-4">
                        <div class="row g-2">
                            <div class="col-6"><strong>Product:</strong> <span id="modal_product">-</span></div>
                            <div class="col-6"><strong>Lot No:</strong> <span id="modal_lot">-</span></div>
                            <div class="col-6"><strong>Roll No:</strong> <span id="modal_roll">-</span></div>
                            <div class="col-6"><strong>Width:</strong> <span id="modal_width">-</span> mm</div>
                            <div class="col-12"><strong>Length:</strong> <span id="modal_length">-</span> mtr</div>
                        </div>
                    </div>

                    <div id="reslitPlanAlert" class="alert alert-info py-2 mb-3" style="display:none;">
                        <i class="bi bi-clipboard-check me-2"></i>
                        <span id="reslitPlanAlertText">A slitting plan is available for this roll.</span>
                    </div>

                    <div class="mb-4">
                        <h6 class="fw-bold">Step 1: Select Cut Type</h6>
                        <div class="d-flex gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="cut_type" id="cutNormal" value="normal" onchange="handleCutTypeChange()">
                                <label class="form-check-label fw-bold" for="cutNormal">Normal</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="cut_type" id="cutInto2" value="cut_into_2" onchange="handleCutTypeChange()">
                                <label class="form-check-label fw-bold" for="cutInto2">Cut Into 2</label>
                            </div>
                        </div>
                    </div>
                    
                    <div id="cutInto2Section" style="display:none;" class="card shadow-sm mb-4 border-warning">
                        <div class="card-body bg-light-subtle">
                            <h6 class="mb-3 text-warning-emphasis">Step 2: Slit Calculation</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Slit Quantity (Length Used)</label>
                                    <div class="input-group">
                                        <input type="number" step="0.01" name="slit_quantity" id="slitQuantity"
                                               class="form-control" placeholder="0.0" oninput="calculateReslitStock()">
                                        <span class="input-group-text">meters</span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Remaining Stock (Leftover)</label>
                                    <div class="input-group">
                                        <input type="number" step="0.01" name="stock" id="reslitStock"
                                               class="form-control bg-white" readonly>
                                        <span class="input-group-text">meters</span>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3">
                                <label class="sfc-toggle" for="leftoverSfcCheck">
                                    <input class="form-check-input sfc-checkbox" type="checkbox" name="leftover_to_sfc"
                                           id="leftoverSfcCheck" value="1" onchange="toggleLeftoverSfcHighlight(this)">
                                    <span class="sfc-toggle-text"><i class="bi bi-box-seam-fill me-1"></i>Send Leftover to SFC</span>
                                </label>
                                <div class="form-text mt-1" id="leftoverRouteHint">
                                    Unticked: leftover goes to <strong>Finished Product</strong> only. Ticked: leftover goes to <strong>SFC</strong> only — never both.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="rollCountSection" style="display:none;" class="mb-4">
                        <hr>
                        <h6 class="fw-bold mb-3" id="rollCountTitle">Step 2: Number of Rolls</h6>
                        <select name="total" id="total" class="form-select w-auto" onchange="generateForm()">
                            <option value="">-- Select --</option>
                            <?php for($i=1;$i<=10;$i++): ?><option value="<?= $i ?>"><?= $i ?> Roll<?= $i>1?'s':'' ?></option><?php endfor; ?>
                        </select>
                    </div>

                    <div id="slittingForm"></div>
                    <div class="form-text mt-1"><i class="bi bi-box-seam-fill me-1 text-primary"></i>Tick <strong>To SFC</strong> for any roll that should be sent to SFC instead of finished stock.</div>

                    <!-- ── SFC Balance Width Section ── -->
                    <div id="reslitCutSfcSection" style="display:none;" class="card shadow-sm mt-3 mb-2 border-info">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <h6 class="mb-0 text-info-emphasis fw-bold">
                                    <i class="bi bi-box-seam me-2"></i>Save Balance Width to SFC
                                </h6>
                                <span class="badge bg-info-subtle text-info border border-info-subtle">Optional</span>
                            </div>
                            <p class="text-muted small mb-2">Capture remaining width as an SFC scrap/balance roll (Roll No: <strong>BALANCE</strong>).</p>
                            <div class="input-group">
                                <span class="input-group-text">Balance Width</span>
                                <input type="number" step="0.01" name="sfc_balance_width" id="reslit_sfc_balance_width" class="form-control" placeholder="0.00">
                                <span class="input-group-text">mm</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-check form-switch mt-3 p-2 bg-light border rounded">
                        <input class="form-check-input ms-0 me-2" type="checkbox" name="auto_print" id="reslit_auto_print" value="1" checked>
                        <label class="form-check-label fw-bold text-primary" for="reslit_auto_print">
                            <i class="bi bi-printer-fill me-1"></i> Cetak sticker automatik selepas selesai
                        </label>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success px-4 fw-bold shadow-sm" id="submitBtn" style="display:none;">
                        <i class="bi bi-check-circle me-1"></i> Complete Reslit
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<datalist id="validCustomerList">
    <?php foreach ($knownCustomers as $c): ?>
        <option value="<?= htmlspecialchars($c) ?>">
    <?php endforeach; ?>
</datalist>

<!-- ═══ RESLIT SLITTING PLAN MODAL ═════════════════════════════ -->
<div class="modal fade" id="reslitPlanModal" tabindex="-1" aria-labelledby="reslitPlanModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white py-3">
                <div>
                    <h5 class="modal-title mb-1" id="reslitPlanModalLabel">
                        <i class="bi bi-scissors me-2"></i>Slitting Plan — Reslit Roll
                    </h5>
                    <div class="small opacity-75">
                        Plan cut sequences, widths, and customer assignments for this reslit roll.
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form id="reslitPlanForm" method="post" action="reslit.php">
                <input type="hidden" name="action" value="save_reslit_plan">
                <input type="hidden" name="reslit_id" id="planModalReslitId" value="0">

                <div class="modal-body p-4">
                    <!-- Roll Info Summary Banner -->
                    <div class="card bg-light border-0 shadow-sm mb-4">
                        <div class="card-body py-3 px-4">
                            <div class="row g-3 align-items-center">
                                <div class="col-auto">
                                    <span class="text-muted small text-uppercase fw-bold d-block">Product</span>
                                    <span class="fs-5 fw-bold text-dark" id="reslitPlanBadgeProduct">—</span>
                                </div>
                                <div class="col-auto border-start ps-3">
                                    <span class="text-muted small text-uppercase fw-bold d-block">Lot & Coil No.</span>
                                    <span class="fs-5 fw-bold text-primary font-monospace" id="reslitPlanBadgeLotCoil">—</span>
                                </div>
                                <div class="col-auto border-start ps-3">
                                    <span class="text-muted small text-uppercase fw-bold d-block">Roll No.</span>
                                    <span class="fs-5 fw-bold text-dark font-monospace" id="reslitPlanBadgeRoll">—</span>
                                </div>
                                <div class="col-auto border-start ps-3">
                                    <span class="text-muted small text-uppercase fw-bold d-block">Original Width</span>
                                    <span class="fs-5 fw-bold text-dark" id="reslitPlanBadgeWidth">—</span>
                                </div>
                                <div class="col-auto border-start ps-3">
                                    <span class="text-muted small text-uppercase fw-bold d-block">Length</span>
                                    <span class="fs-5 fw-bold text-success" id="reslitPlanBadgeLength">—</span>
                                </div>
                                <div class="col text-end border-start ps-3">
                                    <div id="reslitWidthAllocBadge" class="d-inline-block px-3 py-2 rounded-3 border bg-white text-start shadow-sm">
                                        <div class="d-flex align-items-center gap-3">
                                            <div>
                                                <small class="text-muted fw-bold d-block">Planned Total</small>
                                                <span class="fw-bold" id="plannedTotalReslitWidth">0.00 mm</span>
                                            </div>
                                            <div class="border-start ps-3">
                                                <small class="text-muted fw-bold d-block">Unplanned Balance</small>
                                                <span class="fw-bold text-success" id="plannedBalanceReslitWidth">0.00 mm</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Excel / CSV Slitting Plan Paste -->
                    <div class="p-3 bg-light rounded-3 border mb-3">
                        <label class="form-label fw-semibold small text-secondary mb-1">
                            <i class="bi bi-clipboard-plus me-1"></i> Quick Excel / CSV Slitting Plan Paste
                        </label>
                        <textarea id="reslit_plan_paste_zone" class="form-control form-control-sm border-secondary-subtle" rows="2"
                                  placeholder="Paste from Excel/CSV — Roll, Width, Customer, Ref No, e.g.&#10;R1, 125, NAE, SO-26-0110&#10;R2, 125, STAMPING, STOCK"></textarea>
                        <div class="alert alert-warning py-1 px-2 small mt-2 mb-0 d-none" id="reslit_plan_paste_warning">
                            Couldn't read that as Seq + Width pairs — check the format.
                        </div>
                    </div>

                    <!-- Structured Table / Grid for Slitting Plan Rows -->
                    <div class="card border shadow-sm mb-3">
                        <div class="card-header bg-light py-2 px-3">
                            <div class="row g-2 align-items-center text-secondary fw-bold small text-uppercase">
                                <div style="width: 85px;">Seq</div>
                                <div style="width: 130px;">Width (mm)</div>
                                <div style="width: 240px;">Customer Code</div>
                                <div class="col">Customer Ref No</div>
                                <div style="width: 45px;" class="text-end"></div>
                            </div>
                        </div>
                        <div class="card-body p-2" id="reslitPlanRowsContainer" style="max-height: 280px; overflow-y: auto;">
                            <!-- Dynamic rows injected here -->
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-outline-primary btn-sm px-3" onclick="addReslitPlanRow()">
                            <i class="bi bi-plus-lg me-1"></i>Add Row Manually
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm px-3" onclick="clearReslitPlanRows()">
                            <i class="bi bi-trash3 me-1"></i>Clear All Rows
                        </button>
                    </div>

                    <div id="reslitPlanSaveAlert" class="mt-3 d-none"></div>
                </div>

                <div class="modal-footer bg-light px-4 py-3">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success px-4" id="saveReslitPlanSubmitBtn">
                        <i class="bi bi-check-circle me-1"></i> Save Slitting Plan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
let productData = {};

// ── Add Product to Reslit — one unified intake bar ───────────────
// No mode toggle: submitIntake() handles Enter-key submission from the
// form (hardware keyboard-wedge scanners "type" their payload then send
// Enter, so this just works automatically) as well as a manual click on
// the Add button. handleIntakeScan() is the camera-scanner widget's
// onScan callback (wired up in the DOMContentLoaded listener further
// down) — it feeds into the exact same single code path. Same pattern
// as recoiling.php's intake bar.

function submitIntake(e) {
    e.preventDefault();
    const input = document.getElementById('intakeInput');
    const raw = input.value.trim();
    if (!raw) return false;
    addToReslitQueue(raw, 'unified');
    return false;
}

function handleIntakeScan(scanned) {
    if (!scanned) return;
    const input = document.getElementById('intakeInput');
    input.value = scanned;
    addToReslitQueue(scanned, 'scan');
}

function addToReslitQueue(raw, source) {
    const submitBtn = document.getElementById('intakeSubmitBtn');
    const input     = document.getElementById('intakeInput');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    }

    fetch('reslit_intake.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ raw: raw, source: source })
    })
    .then(r => r.json())
    .then(data => {
        showIntakeFeedback(data.ok, data.msg);
        input.value = '';
        if (data.ok) {
            if (data.action === 'open_modal' && data.roll) {
                // 2nd scan: Open Reslit Process modal directly!
                showReslitModal(
                    data.roll.id,
                    data.roll.product,
                    data.roll.lot_no,
                    data.roll.coil_no,
                    data.roll.roll_no,
                    data.roll.width,
                    data.roll.length
                );
            } else {
                // 1st scan: Brief pause then reload to show new pending item in table
                setTimeout(() => window.location.reload(), 1400);
            }
        } else {
            // Failed — no reload, so refocus immediately so the operator
            // can correct and retry without touching the mouse.
            input.focus();
        }
    })
    .catch(() => {
        showIntakeFeedback(false, 'Network error — could not reach the server.');
        input.focus();
    })
    .finally(() => {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add';
        }
    });
}

function showIntakeFeedback(ok, msg) {
    const box = document.getElementById('intakeFeedback');
    if (!box) return;
    box.className = 'alert py-2 mb-0 mt-3 alert-' + (ok ? 'success' : 'danger');
    box.innerHTML = '<i class="bi bi-' + (ok ? 'check-circle-fill' : 'exclamation-triangle-fill') + ' me-2"></i>' + msg;
    box.style.display = '';
}

// ── KPI Card Filter + Global Search (Pending/Completed table) ───
let reslitStatusFilter = 'pending';

function setReslitStatusFilter(status, cardEl) {
    reslitStatusFilter = status;
    document.querySelectorAll('.status-card').forEach(c => c.classList.remove('active'));
    if (cardEl) cardEl.classList.add('active');
    applyReslitFilters();
}

function clearReslitSearch() {
    const input = document.getElementById('reslitSearchInput');
    if (input) input.value = '';
    applyReslitFilters();
}

function applyReslitFilters() {
    const input = document.getElementById('reslitSearchInput');
    const raw = (input ? input.value : '').trim().toLowerCase();
    // Split on whitespace so a combined query like "826529 N-2 R4" requires
    // every token to appear somewhere in the row (Lot + Coil + Roll + Product
    // are concatenated server-side into data-search), while a single token
    // like "826529" or "N-2" still matches on its own.
    const tokens = raw.split(/\s+/).filter(Boolean);

    const rows = document.querySelectorAll('#reslitTableBody tr[data-status-group]');
    let visibleCount = 0;

    rows.forEach(row => {
        const matchesStatus = row.dataset.statusGroup === reslitStatusFilter;
        const haystack = row.dataset.search || '';
        const matchesSearch = tokens.length === 0 || tokens.every(t => haystack.includes(t));
        const show = matchesStatus && matchesSearch;
        row.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });

    const emptyRow = document.getElementById('reslitNoResultsRow');
    if (emptyRow) {
        emptyRow.style.display = visibleCount === 0 ? '' : 'none';
        const label = document.getElementById('reslitNoResultsStatus');
        if (label) label.textContent = reslitStatusFilter;
    }
}

const isReslitActive = <?= $isReslitActive ? 'true' : 'false' ?>;
let activeReslitPlan = [];

function showReslitModal(id, product, lot_no, coil_no, roll_no, width, length) {
    if (!isReslitActive) {
        alert('The Reslit button function is currently BLOCKED in Control Center.');
        return;
    }
    reslitFormSubmitted = false;
    productData = { id, product, lot_no, coil_no, roll_no, width, length };
    activeReslitPlan = [];

    const planAlert = document.getElementById('reslitPlanAlert');
    if (planAlert) planAlert.style.display = 'none';

    if (id > 0) {
        const fd = new FormData();
        fd.append('action', 'start_reslit_process');
        fd.append('id', id);
        fetch('reslit_handler.php', { method: 'POST', body: fd }).catch(console.error);

        // Fetch slitting plan if available for this roll
        fetch('reslit.php?ajax=get_reslit_plan&reslit_id=' + encodeURIComponent(id))
            .then(res => res.json())
            .then(data => {
                if (data.ok && Array.isArray(data.plans) && data.plans.length > 0) {
                    activeReslitPlan = data.plans;
                    if (planAlert) {
                        const alertText = document.getElementById('reslitPlanAlertText');
                        if (alertText) {
                            alertText.innerHTML = `<i class="bi bi-clipboard-check me-2"></i>A slitting plan is available for this roll — <strong>${data.plans.length} roll(s) planned</strong>. Choose Normal or Cut Into 2 below and it will be applied automatically.`;
                        }
                        planAlert.style.display = 'block';
                    }
                    const checkedCut = document.querySelector('input[name="cut_type"]:checked');
                    if (checkedCut) {
                        applyPlanToReslitForm();
                    }
                }
            })
            .catch(console.error);
    }

    document.getElementById('reslit_id').value = id;
    document.getElementById('modal_product').textContent = product;
    document.getElementById('modal_lot').textContent = lot_no + ' ' + coil_no;
    document.getElementById('modal_roll').textContent = roll_no;
    document.getElementById('modal_width').textContent = width;
    document.getElementById('modal_length').textContent = length;

    document.getElementById('reslitForm').reset();
    document.getElementById('cutInto2Section').style.display = 'none';
    document.getElementById('reslitStock').value = '';
    document.getElementById('rollCountSection').style.display = 'none';
    document.getElementById('slittingForm').innerHTML = '';
    document.getElementById('submitBtn').style.display = 'none';

    const sfcBalInput = document.getElementById('reslit_sfc_balance_width');
    if (sfcBalInput) {
        sfcBalInput.value = '';
        sfcBalInput.dataset.userEdited = 'false';
        if (!sfcBalInput.dataset.listenerAttached) {
            sfcBalInput.dataset.listenerAttached = 'true';
            sfcBalInput.addEventListener('input', function () {
                this.dataset.userEdited = 'true';
            });
        }
    }
    const sfcBalSec = document.getElementById('reslitCutSfcSection');
    if (sfcBalSec) sfcBalSec.style.display = 'none';

    bootstrap.Modal.getOrCreateInstance(document.getElementById('reslitModal')).show();
}

function calculateReslitStock() {
    const slitQty = parseFloat(document.getElementById('slitQuantity').value) || 0;
    const original = parseFloat(productData.length) || 0;
    const stock = original - slitQty;
    const stockField = document.getElementById('reslitStock');
    stockField.value = stock.toFixed(2);
    stockField.style.color = stock < 0 ? '#dc3545' : (stock === 0 ? '#ffc107' : '#198754');

    // Keep the Nominal Length column in the rolls table (if already
    // generated) in sync with the Slit Quantity — the output rolls are
    // being cut from that quantity, not the full original roll length.
    document.querySelectorAll('#slittingForm .nominal-length-input').forEach(input => {
        input.value = slitQty > 0 ? slitQty : original;
    });
}

function toggleLeftoverSfcHighlight(checkbox) {
    const pill = checkbox.closest('.sfc-toggle');
    pill.classList.toggle('sfc-active', checkbox.checked);
    const hint = document.getElementById('leftoverRouteHint');
    hint.innerHTML = checkbox.checked
        ? 'Leftover will be routed to <strong>SFC</strong> only.'
        : 'Unticked: leftover goes to <strong>Finished Product</strong> only. Ticked: leftover goes to <strong>SFC</strong> only — never both.';
}

function handleCutTypeChange() {
    const cutType = document.querySelector('input[name="cut_type"]:checked')?.value;
    document.getElementById('cut_type_value').value = cutType || '';
    document.getElementById('slittingForm').innerHTML = '';
    document.getElementById('submitBtn').style.display = 'none';
    document.getElementById('total').value = '';

    const rollCountTitle = document.getElementById('rollCountTitle');

    if (cutType === 'cut_into_2') {
        document.getElementById('cutInto2Section').style.display = 'block';
        document.getElementById('rollCountSection').style.display = 'block';
        if (rollCountTitle) rollCountTitle.innerText = 'Step 3: Number of Rolls';
    } else if (cutType === 'normal') {
        document.getElementById('cutInto2Section').style.display = 'none';
        document.getElementById('rollCountSection').style.display = 'block';
        if (rollCountTitle) rollCountTitle.innerText = 'Step 2: Number of Rolls';
    }

    if (activeReslitPlan && activeReslitPlan.length > 0) {
        applyPlanToReslitForm();
    }
}

function applyPlanToReslitForm() {
    if (!activeReslitPlan || activeReslitPlan.length === 0) return;
    const totalSelect = document.getElementById('total');
    if (totalSelect) {
        totalSelect.value = String(Math.min(activeReslitPlan.length, 10));
    }
    generateForm();
    fillReslitRollsFromPlan();
}

function fillReslitRollsFromPlan() {
    if (!activeReslitPlan || activeReslitPlan.length === 0) return;
    const widthInputs = document.querySelectorAll('#slittingForm .width-input');
    const custInputs = document.querySelectorAll('#slittingForm input[name="customer_name[]"]');
    const refInputs = document.querySelectorAll('#slittingForm input[name="ref_no[]"]');
    activeReslitPlan.forEach((p, idx) => {
        if (widthInputs[idx]) {
            widthInputs[idx].value = p.planned_width;
        }
        if (custInputs[idx]) {
            custInputs[idx].value = p.customer_name || '';
        }
        if (refInputs[idx]) {
            refInputs[idx].value = p.ref_no || '';
        }
        const lotDisp = document.getElementById('lotDisplay' + idx);
        if (lotDisp && p.customer_name) {
            const existingBadge = lotDisp.querySelector('.badge-plan-cust');
            if (existingBadge) existingBadge.remove();
            const badge = document.createElement('span');
            badge.className = 'badge bg-info-subtle text-info border border-info-subtle ms-1 badge-plan-cust';
            badge.textContent = p.customer_name + (p.ref_no ? ' / ' + p.ref_no : '');
            lotDisp.appendChild(badge);
        }
    });
    calculateReslitWidths();
}

function calculateReslitWidths() {
    const origWidth = parseFloat(productData.width) || 0;
    const widthInputs = document.querySelectorAll('#slittingForm .width-input');
    let totalCut = 0;
    widthInputs.forEach(inp => {
        totalCut += parseFloat(inp.value) || 0;
    });

    const balance = origWidth - totalCut;
    const origDisp = document.getElementById('modalOriginalWidthDisplay');
    const cutDisp = document.getElementById('modalTotalCutWidthDisplay');
    const balDisp = document.getElementById('modalBalanceWidthDisplay');
    const warnEl = document.getElementById('modalWidthWarning');
    const sfcSection = document.getElementById('reslitCutSfcSection');
    const sfcInput = document.getElementById('reslit_sfc_balance_width');

    if (origDisp) origDisp.textContent = origWidth.toFixed(2) + ' mm';
    if (cutDisp) cutDisp.textContent = totalCut.toFixed(2) + ' mm';

    if (balDisp) {
        balDisp.textContent = balance.toFixed(2) + ' mm';
        if (balance < -0.01) {
            balDisp.className = 'fs-5 text-danger fw-bold';
        } else if (Math.abs(balance) <= 0.01) {
            balDisp.className = 'fs-5 text-muted fw-bold';
        } else {
            balDisp.className = 'fs-5 text-success fw-bold';
        }
    }

    if (warnEl) {
        if (balance < -0.01) {
            warnEl.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1"></i><strong>Warning:</strong> Total cut width (${totalCut.toFixed(2)} mm) exceeds original width (${origWidth.toFixed(2)} mm) by ${Math.abs(balance).toFixed(2)} mm!`;
            warnEl.style.display = 'block';
        } else {
            warnEl.style.display = 'none';
        }
    }

    if (sfcSection) {
        if (balance > 0.01) {
            sfcSection.style.display = 'block';
            if (sfcInput && (!sfcInput.dataset.userEdited || sfcInput.dataset.userEdited === 'false')) {
                sfcInput.value = balance.toFixed(2);
            }
        } else {
            sfcSection.style.display = 'none';
            if (sfcInput && (!sfcInput.dataset.userEdited || sfcInput.dataset.userEdited === 'false')) {
                sfcInput.value = '';
            }
        }
    }
}

function generateForm() {
    let total = parseInt(document.getElementById('total').value);
    let container = document.getElementById('slittingForm');
    let submitBtn = document.getElementById('submitBtn');
    container.innerHTML = "";

    if (!total || total <= 0) {
        submitBtn.style.display = 'none';
        const sfcSec = document.getElementById('reslitCutSfcSection');
        if (sfcSec) sfcSec.style.display = 'none';
        return;
    }

    // ── Nominal Length default: for Cut Into 2, this should be the
    // Slit Quantity (Length Used) the user just entered — NOT the full
    // original roll length, since only part of it is being used here.
    const cutType = document.querySelector('input[name="cut_type"]:checked')?.value;
    const slitQty = parseFloat(document.getElementById('slitQuantity')?.value) || 0;
    const defaultNominalLength = (cutType === 'cut_into_2' && slitQty > 0)
        ? slitQty
        : productData.length;

    // ── Width Allocation Summary Card + Bulk "Apply to All" toolbar ──
    let html = `
        <div class="card bg-light-subtle border shadow-sm mb-3">
            <div class="card-body py-2 px-3">
                <div class="row g-2 align-items-center text-center">
                    <div class="col-sm-4">
                        <span class="text-muted small d-block">Original Width</span>
                        <strong class="fs-5" id="modalOriginalWidthDisplay">${parseFloat(productData.width || 0).toFixed(2)} mm</strong>
                    </div>
                    <div class="col-sm-4 border-start border-end">
                        <span class="text-muted small d-block">Total Cut Width</span>
                        <strong class="fs-5 text-primary" id="modalTotalCutWidthDisplay">0.00 mm</strong>
                    </div>
                    <div class="col-sm-4">
                        <span class="text-muted small d-block">Remaining Balance Width</span>
                        <strong class="fs-5 text-success" id="modalBalanceWidthDisplay">${parseFloat(productData.width || 0).toFixed(2)} mm</strong>
                    </div>
                </div>
                <div id="modalWidthWarning" class="alert alert-danger py-1 px-2 mt-2 mb-0 small text-center" style="display:none;"></div>
            </div>
        </div>

        <hr class="my-2">
        <h6 class="mb-3 fw-bold text-primary">Roll Output Details</h6>
        <div id="bulkApplyBar" class="mb-2 d-flex flex-wrap gap-2 align-items-center">
            <i class="bi bi-lightning-charge-fill text-primary"></i>
            <strong class="small">Apply to All Rolls:</strong>
            <div class="input-group input-group-sm" style="width:150px;">
                <span class="input-group-text">Letter</span>
                <select id="bulkCutLetterInput" class="form-select">
                    <option value="">-- None --</option>
                    <option value="a">a</option>
                    <option value="b">b</option>
                    <option value="c">c</option>
                    <option value="d">d</option>
                </select>
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="applyCutLetterToAll()">
                <i class="bi bi-check2-all me-1"></i>Apply Letter
            </button>
            <div class="input-group input-group-sm" style="width:180px;">
                <span class="input-group-text">Width</span>
                <input type="number" step="0.01" id="bulkWidthInput" class="form-control" placeholder="e.g. 50">
                <span class="input-group-text">mm</span>
            </div>
            <button type="button" class="btn btn-primary btn-sm" onclick="applyWidthToAll()">
                <i class="bi bi-check2-all me-1"></i>Apply Width
            </button>
            <div class="input-group input-group-sm" style="width:180px;">
                <span class="input-group-text">Act. Length</span>
                <input type="number" step="0.01" id="bulkActualLengthInput" class="form-control" placeholder="e.g. 100">
                <span class="input-group-text">m</span>
            </div>
            <button type="button" class="btn btn-success btn-sm" onclick="applyActualLengthToAll()">
                <i class="bi bi-check2-all me-1"></i>Apply Act. Length
            </button>
            <span class="text-muted small ms-auto">${total} roll${total > 1 ? 's' : ''} — scroll the table below if needed</span>
        </div>
        <div id="rollsTableWrap">
        <table class="table table-bordered table-hover align-middle" id="rollsTable">
            <thead>
                <tr>
                    <th>Roll</th>
                    <th>Cut Letter</th>
                    <th>Nominal Length</th>
                    <th>New Width (mm)</th>
                    <th>Actual Length (mtr)</th>
                    <th class="text-center">To SFC</th>
                    <th>Lot Preview</th>
                </tr>
            </thead>
            <tbody>
    `;

    for (let i = 1; i <= total; i++) {
        const pRow = (activeReslitPlan && activeReslitPlan[i - 1]) ? activeReslitPlan[i - 1] : null;
        const pWidth = pRow ? pRow.planned_width : '';
        const pCust = pRow ? (pRow.customer_name || '') : '';
        const pRef = pRow ? (pRow.ref_no || '') : '';
        const custBadgeHtml = pCust ? `<span class="badge bg-info-subtle text-info border border-info-subtle ms-1 badge-plan-cust">${escHtml(pCust)}${pRef ? ' / ' + escHtml(pRef) : ''}</span>` : '';

        html += `
                <tr>
                    <td>
                        <span class="badge bg-light text-dark border">R${i}</span>
                        <input type="hidden" name="roll_number[]" value="R${i}">
                    </td>
                    <td>
                        <select name="cut_letter[]" class="form-select form-select-sm cut-letter-input" onchange="updateLotDisplay(this, ${i - 1})">
                            <option value="">-- None --</option>
                            <option value="a">a</option>
                            <option value="b">b</option>
                            <option value="c">c</option>
                            <option value="d">d</option>
                        </select>
                    </td>
                    <td>
                        <input type="number" step="0.01" name="length[]" class="form-control form-control-sm nominal-length-input" value="${defaultNominalLength}">
                    </td>
                    <td>
                        <input type="number" step="0.01" name="new_width[]" class="form-control form-control-sm highlight-field width-input" value="${pWidth}" required oninput="calculateReslitWidths()">
                        <input type="hidden" name="customer_name[]" value="${escHtml(pCust)}">
                        <input type="hidden" name="ref_no[]" value="${escHtml(pRef)}">
                    </td>
                    <td>
                        <input type="number" step="0.01" name="actual_length[]" class="form-control form-control-sm highlight-field actual-length-input" placeholder="Optional">
                    </td>
                    <td class="text-center sfc-cell">
                        <label class="sfc-toggle" for="sfcCheck${i - 1}">
                            <input class="form-check-input sfc-checkbox" type="checkbox" name="send_to_sfc[]" id="sfcCheck${i - 1}" value="R${i}"
                                   onchange="toggleSfcRowHighlight(this)">
                            <span class="sfc-toggle-text"><i class="bi bi-box-seam-fill me-1"></i>SFC</span>
                        </label>
                    </td>
                    <td>
                        <small class="text-primary" id="lotDisplay${i - 1}">
                            <i class="bi bi-tag me-1"></i>${productData.lot_no} ${productData.coil_no} R${i} | ${productData.width}mm
                            ${custBadgeHtml}
                        </small>
                    </td>
                </tr>
        `;
    }

    html += `
            </tbody>
        </table>
        </div>
    `;

    container.innerHTML = html;
    calculateReslitWidths();
    submitBtn.style.display = 'inline-block';
}

function applyCutLetterToAll() {
    const bulkVal = document.getElementById('bulkCutLetterInput').value;
    document.querySelectorAll('#slittingForm .cut-letter-input').forEach((select, idx) => {
        select.value = bulkVal;
        updateLotDisplay(select, idx);
    });
}

function applyWidthToAll() {
    const bulkVal = document.getElementById('bulkWidthInput').value;
    if (bulkVal === '' || isNaN(parseFloat(bulkVal))) {
        alert('Enter a width value first.');
        return;
    }
    document.querySelectorAll('#slittingForm .width-input').forEach(input => {
        input.value = bulkVal;
    });
    calculateReslitWidths();
}

function applyActualLengthToAll() {
    const bulkVal = document.getElementById('bulkActualLengthInput').value;
    if (bulkVal === '' || isNaN(parseFloat(bulkVal))) {
        alert('Enter an actual length value first.');
        return;
    }
    document.querySelectorAll('#slittingForm .actual-length-input').forEach(input => {
        input.value = bulkVal;
    });
}

function toggleSfcRowHighlight(checkbox) {
    const row = checkbox.closest('tr');
    const pill = checkbox.closest('.sfc-toggle');
    row.classList.toggle('sfc-row-active', checkbox.checked);
    pill.classList.toggle('sfc-active', checkbox.checked);
}

function updateLotDisplay(select, rollNum) {
    const cutLetter = select.value;
    const displayDiv = document.getElementById('lotDisplay' + rollNum);
    displayDiv.innerHTML = `<i class="bi bi-tag me-1"></i>${productData.lot_no}${cutLetter} ${productData.coil_no} R${rollNum + 1} | ${productData.width}mm`;
}

document.getElementById('reslitForm').addEventListener('submit', function(e) {
    const cutType = document.querySelector('input[name="cut_type"]:checked')?.value;
    if (cutType === 'cut_into_2') {
        const slitQty = parseFloat(document.getElementById('slitQuantity').value) || 0;
        if (slitQty <= 0) {
            e.preventDefault();
            alert('Enter a Slit Quantity (Length Used) greater than 0 before completing a Cut Into 2 reslit.');
            return;
        }
        const stock = parseFloat(document.getElementById('reslitStock').value);
        if (isNaN(stock) || stock < 0) {
            e.preventDefault();
            alert('Slit Quantity cannot exceed the original length — check the Remaining Stock value.');
            return;
        }
    }

    const origWidth = parseFloat(productData.width) || 0;
    let totalCut = 0;
    document.querySelectorAll('#slittingForm .width-input').forEach(inp => {
        totalCut += parseFloat(inp.value) || 0;
    });
    if (origWidth > 0 && totalCut > origWidth + 0.01) {
        e.preventDefault();
        alert('Total cut width (' + totalCut.toFixed(2) + ' mm) exceeds the original roll width (' + origWidth.toFixed(2) + ' mm). Please adjust roll widths before completing.');
        return;
    }

    const sfcBalVal = parseFloat(document.getElementById('reslit_sfc_balance_width')?.value) || 0;
    if (sfcBalVal < 0) {
        e.preventDefault();
        alert('SFC balance width cannot be negative.');
        return;
    }

    if (!confirm('Complete reslit process now? Product will be added back to stock.')) {
        e.preventDefault();
        return;
    }
    reslitFormSubmitted = true;
});

// ── Summary Report ─────────────────────────────────────────────
function clearSummaryFilter() {
    document.getElementById('summaryMonthFilter').value = '';
    loadSummary();
}

function loadSummary() {
    const month = document.getElementById('summaryMonthFilter').value;
    document.getElementById('summaryRange').textContent = month ? 'Showing: ' + month : 'Showing: All time';

    let url = 'reslit_summary_ajax.php';
    if (month) url += '?month=' + encodeURIComponent(month);

    fetch(url)
        .then(r => r.json())
        .then(data => {
            document.getElementById('kpi_normal').textContent = data.counts?.normal     ?? 0;
            document.getElementById('kpi_cut2').textContent   = data.counts?.cut_into_2 ?? 0;

            const tbody = document.getElementById('summaryTableBody');
            if (!data.rows || data.rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">No records for this period.</td></tr>';
                return;
            }

            const typeLabel = { normal: '✂️ Normal', cut_into_2: '✂️✂️ Cut Into 2' };
            const typeCls   = { normal: 'bg-danger',  cut_into_2: 'bg-warning text-dark' };

            tbody.innerHTML = data.rows.map(r => {
                // Build output rolls summary
                let rollsHtml = '-';
                if (r.rolls && r.rolls.length > 0) {
                    rollsHtml = r.rolls.map(roll => {
                        const lotSuffix = roll.cut_letter ? `<strong>${escHtml(roll.cut_letter)}</strong>` : '';
                        return `<span class="badge bg-light text-dark border me-1">
                                    ${escHtml(roll.roll_no)}${lotSuffix}
                                    <span class="text-success">${parseFloat(roll.actual_length || 0).toLocaleString()}m</span>
                                    / ${parseFloat(roll.new_width || 0).toLocaleString()}mm
                                </span>`;
                    }).join('');
                }

                return `
                <tr>
                    <td class="small">${escHtml(r.completed_at ?? '-')}</td>
                    <td><span class="badge ${typeCls[r.cut_type] ?? 'bg-secondary'}">${typeLabel[r.cut_type] ?? escHtml(r.cut_type)}</span></td>
                    <td class="font-monospace small">${escHtml(r.lot_no ?? '-')}</td>
                    <td class="small">${escHtml(r.roll_no ?? '-')}</td>
                    <td class="small">${escHtml(r.product ?? '-')}</td>
                    <td class="text-end small">${String(r.roll_no ?? '').toUpperCase().trim() === 'BALANCE' ? '-' : parseFloat(r.actual_length || 0).toLocaleString()}</td>
                    <td class="small">${rollsHtml}</td>
                </tr>`;
            }).join('');
        })
        .catch(() => {
            document.getElementById('summaryTableBody').innerHTML =
                '<tr><td colspan="8" class="text-danger text-center">Failed to load. Check reslit_summary_ajax.php exists.</td></tr>';
        });
}

function escHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

let reslitFormSubmitted = false;

document.addEventListener('DOMContentLoaded', function () {
    // Apply the default 'pending' filter to the table on first load
    applyReslitFilters();

    const reslitModalEl = document.getElementById('reslitModal');
    if (reslitModalEl) {
        reslitModalEl.addEventListener('hidden.bs.modal', function () {
            if (!reslitFormSubmitted && productData.id > 0) {
                const fd = new FormData();
                fd.append('action', 'cancel_reslit_process');
                fd.append('id', productData.id);
                fetch('reslit_handler.php', { method: 'POST', body: fd }).catch(console.error);
            }
        });
    }

    window.addEventListener('beforeunload', function () {
        if (!reslitFormSubmitted && productData.id > 0) {
            const m = document.getElementById('reslitModal');
            if (m && m.classList.contains('show')) {
                const fd = new FormData();
                fd.append('action', 'cancel_reslit_process');
                fd.append('id', productData.id);
                navigator.sendBeacon('reslit_handler.php', fd);
            }
        }
    });

    // Initialize the shared camera scanner widget (same library used by
    // sfc.php / finish_product.php / recoiling.php) — it manages its own
    // floating trigger UI, so no custom container or "start camera"
    // button is needed here.
    initCameraScanner({
        onScan: handleIntakeScan
    });

    document.getElementById('summaryModal').addEventListener('show.bs.modal', function () {
        loadSummary();
    });

    // ── Send Back to Finished Product (with confirmation) ──────
    document.querySelectorAll('.btn-send-back-reslit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const rid    = btn.dataset.rid;
            const label  = btn.dataset.label || 'this item';
            const origin = btn.dataset.origin || 'its original source';

            function doSubmit() {
                document.getElementById('sendBackReslitId').value = rid;
                document.getElementById('sendBackReslitForm').submit();
            }

            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'warning',
                    title: `Send back to ${origin}?`,
                    html: `Are you sure you want to send <strong>${label}</strong> back to ${origin}?<br><span class="text-muted small">It will be removed from the Reslit queue.</span>`,
                    showCancelButton: true,
                    confirmButtonText: 'Yes, send it back',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#dc3545'
                }).then(function (result) {
                    if (result.isConfirmed) doSubmit();
                });
            } else {
                if (confirm(`Are you sure you want to send this item back to ${origin}?`)) {
                    doSubmit();
                }
            }
        });
    });
});

// ── Reslit Slitting Plan Modal Logic ────────────────────────────
(function () {
    var modalEl = document.getElementById('reslitPlanModal');
    if (!modalEl) return;
    var planModal = new bootstrap.Modal(modalEl);

    var currentRollWidth = 0;
    var elReslitId = document.getElementById('planModalReslitId');
    var elBadgeProd = document.getElementById('reslitPlanBadgeProduct');
    var elBadgeLotCoil = document.getElementById('reslitPlanBadgeLotCoil');
    var elBadgeRoll = document.getElementById('reslitPlanBadgeRoll');
    var elBadgeWidth = document.getElementById('reslitPlanBadgeWidth');
    var elBadgeLength = document.getElementById('reslitPlanBadgeLength');
    var elContainer = document.getElementById('reslitPlanRowsContainer');
    var elPasteZone = document.getElementById('reslit_plan_paste_zone');
    var elPasteWarn = document.getElementById('reslit_plan_paste_warning');
    var elTotalW = document.getElementById('plannedTotalReslitWidth');
    var elBalW = document.getElementById('plannedBalanceReslitWidth');
    var elAllocBadge = document.getElementById('reslitWidthAllocBadge');
    var elSaveBtn = document.getElementById('saveReslitPlanSubmitBtn');
    var elAlert = document.getElementById('reslitPlanSaveAlert');
    var planForm = document.getElementById('reslitPlanForm');

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, function(c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    function updateReslitWidthCalculations() {
        var total = 0;
        var inputs = elContainer.querySelectorAll('input[name="plan_width[]"]');
        inputs.forEach(function (inp) {
            var val = parseFloat(inp.value);
            if (!isNaN(val) && val > 0) total += val;
        });

        elTotalW.textContent = total.toFixed(2) + ' mm';
        var balance = currentRollWidth - total;
        elBalW.textContent = balance.toFixed(2) + ' mm';

        if (currentRollWidth > 0 && total > currentRollWidth + 0.01) {
            elBalW.className = 'fw-bold text-danger';
            elAllocBadge.classList.add('border-danger', 'bg-danger-subtle');
            elAllocBadge.classList.remove('bg-white');
        } else {
            elBalW.className = 'fw-bold text-success';
            elAllocBadge.classList.remove('border-danger', 'bg-danger-subtle');
            elAllocBadge.classList.add('bg-white');
        }
    }

    function renderEmptyState() {
        elContainer.innerHTML = `
            <div id="reslitPlanEmptyPlaceholder" class="text-center py-4 text-muted small">
                <i class="bi bi-card-list fs-3 d-block mb-2 text-secondary opacity-50"></i>
                <div class="fw-semibold text-secondary">Slitting Plan is currently blank.</div>
                <div class="text-muted mt-1">Paste rows from Excel above or click <strong>Add Row Manually</strong> to key in planned cuts.</div>
            </div>
        `;
    }

    window.addReslitPlanRow = function (seq, width, customer, refNo) {
        var placeholder = document.getElementById('reslitPlanEmptyPlaceholder');
        if (placeholder) {
            placeholder.remove();
        }

        seq = seq || '';
        width = (width !== undefined && width !== null) ? width : '';
        customer = customer || '';
        refNo = refNo || '';

        if (!seq) {
            var maxNum = 0;
            elContainer.querySelectorAll('input[name="plan_seq[]"]').forEach(function (inp) {
                var m = inp.value.trim().match(/^R(\d+)$/i);
                if (m) {
                    var n = parseInt(m[1], 10);
                    if (n > maxNum) maxNum = n;
                }
            });
            if (maxNum === 0) {
                maxNum = elContainer.querySelectorAll('.plan-row').length;
            }
            seq = 'R' + (maxNum + 1);
        }

        var row = document.createElement('div');
        row.className = 'row g-2 align-items-center mb-2 plan-row';
        row.innerHTML = `
            <div style="width: 85px;">
                <input type="text" name="plan_seq[]" class="form-control form-control-sm text-center fw-bold bg-light" placeholder="R1" value="${esc(seq)}">
            </div>
            <div style="width: 130px;">
                <input type="number" step="0.01" name="plan_width[]" class="form-control form-control-sm plan-width-input" placeholder="e.g. 125" value="${esc(width)}">
            </div>
            <div style="width: 240px;">
                <input type="text" name="plan_customer[]" class="form-control form-control-sm text-uppercase" list="validCustomerList" placeholder="e.g. NAE" value="${esc(customer)}">
            </div>
            <div class="col">
                <input type="text" name="plan_ref[]" class="form-control form-control-sm" placeholder="e.g. SO-26-0110" value="${esc(refNo)}">
            </div>
            <div style="width: 45px;" class="text-end">
                <button type="button" class="btn btn-sm btn-outline-danger border-0 px-2 btn-remove-row" title="Remove Roll">
                    <i class="bi bi-trash3-fill"></i>
                </button>
            </div>
        `;

        elContainer.appendChild(row);

        row.querySelector('.plan-width-input').addEventListener('input', updateReslitWidthCalculations);
        row.querySelector('.btn-remove-row').addEventListener('click', function () {
            row.remove();
            if (elContainer.querySelectorAll('.plan-row').length === 0) {
                renderEmptyState();
            }
            updateReslitWidthCalculations();
        });

        updateReslitWidthCalculations();
    };

    window.clearReslitPlanRows = function () {
        renderEmptyState();
        updateReslitWidthCalculations();
    };

    // Parse Excel / CSV paste
    function parsePasteText(text) {
        var lines = text.trim().split(/\r?\n/).filter(function (l) { return l.trim() !== ''; });
        var parsed = [];

        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            var cols = [];
            if (line.indexOf(',') !== -1) {
                cols = line.split(',').map(function (c) { return c.trim(); });
            } else if (line.indexOf('\t') !== -1) {
                cols = line.split('\t').map(function (c) { return c.trim(); }).filter(function (c) { return c !== ''; });
            } else {
                cols = line.trim().split(/\s+/);
            }

            if (cols.length < 2) continue;

            var seq = cols[0].trim();
            var width = parseFloat(cols[1]);
            if (seq === '' || isNaN(width)) continue;

            var customer = (cols.length >= 3) ? cols[2].trim().toUpperCase() : '';
            var refNo = (cols.length >= 4) ? cols[3].trim() : '';

            parsed.push({ seq: seq, width: width, customer: customer, refNo: refNo });
        }

        return parsed;
    }

    if (elPasteZone) {
        elPasteZone.addEventListener('input', function () {
            var text = this.value.trim();
            if (!text) {
                elPasteWarn.classList.add('d-none');
                return;
            }
            var parsed = parsePasteText(text);
            if (parsed.length === 0) {
                elPasteWarn.classList.remove('d-none');
                return;
            }
            elPasteWarn.classList.add('d-none');
            elContainer.innerHTML = '';
            parsed.forEach(function (p) {
                addReslitPlanRow(p.seq, p.width, p.customer, p.refNo);
            });
            updateReslitWidthCalculations();
        });
    }

    // Attach click listener to table plan buttons
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-reslit-plan');
        if (!btn) return;

        var reslitId = btn.dataset.reslitId;
        var product = btn.dataset.product || '-';
        var lotNo = btn.dataset.lotNo || '';
        var coilNo = btn.dataset.coilNo || '';
        var rollNo = btn.dataset.rollNo || '-';
        var width = parseFloat(btn.dataset.width || 0);
        var length = parseFloat(btn.dataset.length || 0);

        currentRollWidth = width;
        elReslitId.value = reslitId;
        elBadgeProd.textContent = product;
        elBadgeLotCoil.textContent = (lotNo + ' ' + coilNo).trim();
        elBadgeRoll.textContent = rollNo;
        elBadgeWidth.textContent = width.toFixed(2) + ' mm';
        elBadgeLength.textContent = length.toFixed(1) + ' m';

        if (elPasteZone) elPasteZone.value = '';
        if (elPasteWarn) elPasteWarn.classList.add('d-none');
        if (elAlert) elAlert.classList.add('d-none');
        elContainer.innerHTML = '<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Loading slitting plan...</div>';

        updateReslitWidthCalculations();
        planModal.show();

        fetch('reslit.php?ajax=get_reslit_plan&reslit_id=' + encodeURIComponent(reslitId))
            .then(function (res) { return res.json(); })
            .then(function (data) {
                elContainer.innerHTML = '';
                if (data.ok && Array.isArray(data.plans) && data.plans.length > 0) {
                    data.plans.forEach(function (p) {
                        addReslitPlanRow(p.roll_seq, p.planned_width, p.customer_name, p.ref_no);
                    });
                } else {
                    renderEmptyState();
                }
                updateReslitWidthCalculations();
            })
            .catch(function (err) {
                console.error('Fetch plan error:', err);
                elContainer.innerHTML = '<div class="alert alert-danger py-2 m-2">Failed to load existing plan.</div>';
            });
    });

    // Handle plan form AJAX submission
    planForm.addEventListener('submit', function (e) {
        e.preventDefault();

        elSaveBtn.disabled = true;
        elSaveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
        if (elAlert) elAlert.classList.add('d-none');

        var formData = new FormData(planForm);
        formData.append('ajax', '1');

        fetch('reslit.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            elSaveBtn.disabled = false;
            elSaveBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Save Slitting Plan';

            if (data.ok) {
                // Update button in table
                var reslitId = data.reslit_id || elReslitId.value;
                var tableBtn = document.getElementById('plan_btn_' + reslitId);
                if (tableBtn) {
                    var count = data.count || 0;
                    if (count > 0) {
                        tableBtn.className = 'btn btn-sm btn-reslit-plan btn-info text-white mb-1';
                        tableBtn.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Edit Plan <span class="badge bg-white text-dark ms-1 plan-badge-count">' + count + '</span>';
                        tableBtn.title = 'Edit Slitting Plan (' + count + ' rolls planned)';
                    } else {
                        tableBtn.className = 'btn btn-sm btn-reslit-plan btn-outline-primary mb-1';
                        tableBtn.innerHTML = '<i class="bi bi-plus-circle me-1"></i>+ Slitting Plan';
                        tableBtn.title = 'Add Slitting Plan for this roll';
                    }
                }

                planModal.hide();

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Slitting Plan Saved',
                        text: data.msg || 'The slitting plan has been updated.',
                        timer: 2000,
                        showConfirmButton: false,
                        toast: true,
                        position: 'top-end'
                    });
                }
            } else {
                if (elAlert) {
                    elAlert.className = 'alert alert-danger py-2 mt-3';
                    elAlert.textContent = data.msg || 'Failed to save slitting plan.';
                    elAlert.classList.remove('d-none');
                } else {
                    alert(data.msg || 'Failed to save slitting plan.');
                }
            }
        })
        .catch(function (err) {
            console.error('Save plan error:', err);
            elSaveBtn.disabled = false;
            elSaveBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Save Slitting Plan';
            if (elAlert) {
                elAlert.className = 'alert alert-danger py-2 mt-3';
                elAlert.textContent = 'Network or server error while saving plan.';
                elAlert.classList.remove('d-none');
            } else {
                alert('Network or server error while saving plan.');
            }
        });
    });
})();
</script>

<?php if (!empty($autoPrintUrl)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    window.open(<?= json_encode($autoPrintUrl) ?>, '_blank');
});
</script>
<?php endif; ?>

<?php include 'footer.php'; ?>
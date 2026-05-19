<?php
// finish_product.php
// ============================================================
// CHANGES FROM ORIGINAL:
//  1. Scan no longer sends individual rolls to QC directly.
//     Instead it redirects operator to pallet.php to assign
//     the roll to a pallet first.
//  2. Status badges updated — palletised rolls show which
//     pallet they are on.
//  3. "Send to QC" column button removed; replaced with
//     "Add to Pallet" link when roll is in Finish Good state.
//  4. All other existing logic (reslit, recoiling, actual
//     length update, Excel export) is unchanged.
// ============================================================

session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION['role'] !== 'slitting') {
    die("Access denied");
}

include 'config.php';

// ============================================================
// INLINED PALLET HELPERS — no external file needed
// ============================================================
function getPallet(mysqli $conn, int $pallet_id): ?array {
    $stmt = $conn->prepare("SELECT * FROM pallets WHERE id = ?");
    $stmt->bind_param("i", $pallet_id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}
function getPalletItems(mysqli $conn, int $pallet_id): array {
    $stmt = $conn->prepare("
        SELECT pi.seq, pi.added_at,
               sp.id AS product_id,
               sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
               sp.width, sp.length, sp.actual_length, sp.status
        FROM pallet_items pi
        JOIN slitting_product sp ON sp.id = pi.slitting_product_id
        WHERE pi.pallet_id = ?
        ORDER BY pi.seq ASC
    ");
    $stmt->bind_param("i", $pallet_id); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    return $rows;
}
// ============================================================

// ── Helper: write one process_log row ────────────────────────
function log_process(
    mysqli  $conn,
    string  $entity_type,
    int     $entity_id,
    ?int    $mother_id,
    ?string $from_status,
    string  $to_status,
    string  $action_detail = '',
    string  $remark = ''
): void {
    $performed_by = $_SESSION['role'] ?? 'system';
    $stmt = $conn->prepare("
        INSERT INTO process_log
            (entity_type, entity_id, mother_id, from_status, to_status,
             performed_by, action_detail, remark)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "siisssss",
        $entity_type, $entity_id, $mother_id,
        $from_status, $to_status,
        $performed_by, $action_detail, $remark
    );
    $stmt->execute();
    $stmt->close();
}

// ── Excel export redirect ─────────────────────────────────────
if (isset($_GET['download']) && $_GET['download'] === 'excel') {
    $m = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
    $y = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
    header("Location: finish_product_export.php?month=$m&year=$y");
    exit;
}

$month  = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year   = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($month < 1 || $month > 12) { $month = (int)date('m'); }
if ($year < 2000 || $year > 2100) { $year = (int)date('Y'); }

// ── Save Actual Length (OK / Stock) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'update_ok') {

    $id            = intval($_POST['id']);
    $actual_length = trim($_POST['actual_length']);

    $stmt = $conn->prepare("
        UPDATE slitting_product
        SET actual_length=?, stock_counted=1, is_completed=1
        WHERE id=?
    ");
    $stmt->bind_param("si", $actual_length, $id);
    $stmt->execute();
    $stmt->close();

    $product = $conn->query("SELECT * FROM slitting_product WHERE id=$id")->fetch_assoc();

    // If cut_into_2 — write leftover balance to stock_raw_material
    if ($product && $product['cut_type'] === 'cut_into_2'
        && floatval($product['leftover_length'] ?? $product['stock'] ?? 0) > 0) {

        $leftover = floatval($product['leftover_length'] ?? $product['stock'] ?? 0);
        $mother   = $conn->query("SELECT * FROM mother_coil WHERE id={$product['mother_id']}")->fetch_assoc();
        if ($mother) {
            $stock_lot_no = $product['lot_no'] . 'a';
            $check = $conn->query("
                SELECT id, length FROM stock_raw_material
                WHERE lot_no='$stock_lot_no' AND coil_no='{$product['coil_no']}'
            ");
            if ($check->num_rows > 0) {
                $existing   = $check->fetch_assoc();
                $new_length = $existing['length'] + $leftover;
                $conn->query("UPDATE stock_raw_material SET length=$new_length, updated_at=NOW() WHERE id={$existing['id']}");
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO stock_raw_material
                        (lot_no, coil_no, width, length, status, source_type, source_id, date_in)
                    VALUES (?, ?, ?, ?, 'IN', 'reslit', ?, NOW())
                ");
                $stmt->bind_param("ssddi",
                    $stock_lot_no, $product['coil_no'],
                    $mother['width'], $leftover, $product['mother_id']);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    log_process($conn, 'slitting', $id, intval($product['mother_id'] ?? 0) ?: null,
        'IN', 'IN', 'actual_length_saved',
        "actual_length={$actual_length}m, stock_counted=1");

    header("Location: finish_product.php?month=$month&year=$year&success=stock");
    exit;
}

// ── Send to Recoiling ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'send_to_recoiling') {

    $id = intval($_POST['product_id']);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM slitting_product WHERE id=? FOR UPDATE");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $p = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$p) throw new RuntimeException("Roll #$id not found.");
        if ($p['is_recoiled']) throw new RuntimeException("Roll #$id is already sent to recoiling.");

        $mid             = intval($p['mother_id'] ?? 0) ?: null;
        $original_source = $p['original_source'] ?? 'raw_material';
        $prev_status     = $p['status'];

        $stmt = $conn->prepare("UPDATE slitting_product SET is_recoiled=1 WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("
            INSERT INTO recoiling_product
                (slitting_product_id, mother_id, status, product,
                 lot_no, coil_no, roll_no, width, length, actual_length, original_source)
            VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "iissssddds",
            $id, $mid,
            $p['product'], $p['lot_no'], $p['coil_no'], $p['roll_no'],
            $p['width'], $p['length'], $p['actual_length'],
            $original_source
        );
        $stmt->execute();
        $new_recoil_id = $conn->insert_id;
        $stmt->close();

        log_process($conn, 'slitting', $id, $mid,
            $prev_status, 'OUT', 'send_to_recoiling',
            "Sent to recoiling_product id={$new_recoil_id}");
        log_process($conn, 'recoiling', $new_recoil_id, $mid,
            null, 'pending', 'received_from_slitting',
            "From slitting_product id={$id}");

        $stmt = $conn->prepare("
            INSERT INTO source_tracking_log
                (product_id, table_name, original_source, current_source, action)
            VALUES (?, 'recoiling_product', ?, 'recoiling', 'send_to_recoiling')
        ");
        $stmt->bind_param("is", $id, $original_source);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        header("Location: finish_product.php?success=recoiling");
        exit;

    } catch (Throwable $e) {
        $conn->rollback();
        header("Location: finish_product.php?error=recoiling_failed&msg=" . urlencode($e->getMessage()));
        exit;
    }
}

// ── Send to Reslit ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'send_to_reslit') {

    $id = intval($_POST['product_id']);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM slitting_product WHERE id=? FOR UPDATE");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $p = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$p) throw new RuntimeException("Roll #$id not found.");
        if ($p['is_reslitted']) throw new RuntimeException("Roll #$id is already sent to reslit.");

        $mid             = intval($p['mother_id'] ?? 0) ?: null;
        $original_source = $p['original_source'] ?? 'raw_material';
        $prev_status     = $p['status'];

        $stmt = $conn->prepare("UPDATE slitting_product SET is_reslitted=1 WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        $insert_length = $p['actual_length'] ?: $p['length'];

        $stmt = $conn->prepare("
            INSERT INTO reslit_product
                (slitting_product_id, status, product,
                 lot_no, coil_no, roll_no, width, length, date_in, original_source)
            VALUES (?, 'pending', ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->bind_param(
            "issssdds",
            $id,
            $p['product'], $p['lot_no'], $p['coil_no'], $p['roll_no'],
            $p['width'],
            $insert_length,
            $original_source
        );
        $stmt->execute();
        $new_reslit_id = $conn->insert_id;
        $stmt->close();

        log_process($conn, 'slitting', $id, $mid,
            $prev_status, 'OUT', 'send_to_reslit',
            "Sent to reslit_product id={$new_reslit_id}");
        log_process($conn, 'reslit', $new_reslit_id, $mid,
            null, 'pending', 'received_from_slitting',
            "From slitting_product id={$id}");

        $stmt = $conn->prepare("
            INSERT INTO source_tracking_log
                (product_id, table_name, original_source, current_source, action)
            VALUES (?, 'reslit_product', ?, 'reslit', 'send_to_reslit')
        ");
        $stmt->bind_param("is", $id, $original_source);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        header("Location: finish_product.php?success=reslit");
        exit;

    } catch (Throwable $e) {
        $conn->rollback();
        header("Location: finish_product.php?error=reslit_failed&msg=" . urlencode($e->getMessage()));
        exit;
    }
}

// ── Main query ────────────────────────────────────────────────
// Now also LEFT JOINs pallet_items and pallets so we can show
// which pallet each roll is on.
$baseSql = "
    SELECT sp.*,
           pi.pallet_id,
           p.pallet_no,
           p.status AS pallet_status
    FROM slitting_product sp
    LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
    LEFT JOIN pallets p       ON p.id = pi.pallet_id
    WHERE sp.is_voided = 0
      AND (sp.is_recoiled = 0 OR sp.is_recoiled IS NULL)
      AND (sp.is_reslitted = 0 OR sp.is_reslitted IS NULL)
      AND (
          (sp.status = 'IN' AND MONTH(sp.date_in) = ? AND YEAR(sp.date_in) = ?)
          OR (sp.status IN ('WAITING','OUT','APPROVED','REJECTED')
              AND MONTH(sp.date_out) = ? AND YEAR(sp.date_out) = ?)
          OR (sp.status = 'DELIVERED'
              AND MONTH(sp.delivered_at) = ? AND YEAR(sp.delivered_at) = ?)
      )";

if ($search !== '') {
    $baseSql .= " AND (sp.product LIKE ? OR sp.lot_no LIKE ? OR sp.coil_no LIKE ? OR sp.roll_no LIKE ? OR sp.id LIKE ? OR p.pallet_no LIKE ?)";
}
$baseSql .= " ORDER BY sp.id ASC";

$stmt = $conn->prepare($baseSql);
if (!$stmt) { die("Query prepare failed: " . htmlspecialchars($conn->error)); }

if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt->bind_param("iiiiiissssss",
        $month, $year, $month, $year, $month, $year,
        $like, $like, $like, $like, $like, $like);
} else {
    $stmt->bind_param("iiiiii", $month, $year, $month, $year, $month, $year);
}

$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

// Summary counts
$in      = $conn->query("SELECT IFNULL(COUNT(*),0) AS total FROM slitting_product WHERE is_voided=0 AND status='IN' AND is_completed=0 AND (is_recoiled=0 OR is_recoiled IS NULL) AND (is_reslitted=0 OR is_reslitted IS NULL) AND MONTH(date_in)=$month AND YEAR(date_in)=$year")->fetch_assoc()['total'];
$stock   = $conn->query("SELECT IFNULL(COUNT(*),0) AS total FROM slitting_product WHERE is_voided=0 AND status='IN' AND stock_counted=1 AND (is_recoiled=0 OR is_recoiled IS NULL) AND (is_reslitted=0 OR is_reslitted IS NULL) AND MONTH(date_in)=$month AND YEAR(date_in)=$year")->fetch_assoc()['total'];
$waiting = $conn->query("SELECT IFNULL(COUNT(*),0) AS total FROM slitting_product WHERE is_voided=0 AND status='WAITING' AND MONTH(date_out)=$month AND YEAR(date_out)=$year")->fetch_assoc()['total'];
$deliver = $conn->query("SELECT IFNULL(COUNT(*),0) AS total FROM slitting_product WHERE is_voided=0 AND status='DELIVERED' AND MONTH(delivered_at)=$month AND YEAR(delivered_at)=$year")->fetch_assoc()['total'];

// How many rolls are currently assigned to a building pallet (palletised)
$palletised = $conn->query("
    SELECT IFNULL(COUNT(*),0) AS total
    FROM pallet_items pi
    JOIN pallets p ON p.id = pi.pallet_id
    JOIN slitting_product sp ON sp.id = pi.slitting_product_id
    WHERE p.status = 'building'
      AND MONTH(sp.date_in) = $month AND YEAR(sp.date_in) = $year
")->fetch_assoc()['total'];

$editData = null;
if (isset($_GET['edit'])) {
    $eid = intval($_GET['edit']);
    $res = $conn->query("SELECT * FROM slitting_product WHERE id=$eid");
    if ($res->num_rows > 0) $editData = $res->fetch_assoc();
}

$page_title = 'Finish Product';
include 'header.php';
?>

<style>
table { table-layout: fixed; width: 100%; }
table th, table td { word-wrap: break-word; vertical-align: middle; font-size: 13px; }
table td img { max-width: 60px; max-height: 60px; display: block; margin: 0 auto; }
table th:nth-child(1)  { width: 40px; }
table th:nth-child(2)  { width: 100px; }
table th:nth-child(3)  { width: 65px; }
table th:nth-child(4)  { width: 85px; }
table th:nth-child(5)  { width: 100px; }
table th:nth-child(6)  { width: 65px; }
table th:nth-child(7)  { width: 55px; }
table th:nth-child(8)  { width: 55px; }
table th:nth-child(9)  { width: 65px; }
table th:nth-child(10) { width: 90px; }  /* pallet column */
table th:nth-child(11) { width: 85px; }
table th:nth-child(12) { width: 85px; }
table th:nth-child(13) { width: 130px; }

.badge-pallet { background:#e0f2fe; color:#0369a1; font-size:10px; font-weight:700;
                padding:3px 7px; border-radius:10px; white-space:nowrap; }
</style>

<h2 class="mb-4"><i class="bi bi-check-circle me-2"></i>Finish Product</h2>

<form id="scanFormProduct" method="post" action="scan_product_action.php" autocomplete="off">
    <input id="qrInputProduct" type="text" name="qr" inputmode="none"
           style="position:fixed; left:-9999px; opacity:0;" autofocus>
</form>

<!-- Alerts -->
<?php if (isset($_GET['error'])): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <strong>Error:</strong> <?= htmlspecialchars(urldecode($_GET['msg'] ?? $_GET['error'])) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (isset($_GET['success'])): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i>
    <?php
    $successMessages = [
        'recoiling'   => 'Roll sent to Recoiling successfully.',
        'reslit'      => 'Roll sent to Reslit successfully.',
        'stock'       => 'Actual length saved. Roll is now in Finish Good stock.',
        'palletised'  => 'Roll added to pallet successfully.',
    ];
    echo htmlspecialchars($successMessages[$_GET['success']] ?? 'Action completed successfully.');
    ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Month/Year filter + search -->
<div class="row mb-3 g-2 align-items-center">
    <div class="col-auto">
        <form method="get" class="d-flex gap-2 align-items-center">
            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
            <label class="small fw-bold">Month:</label>
            <select name="month" onchange="this.form.submit()" class="form-select form-select-sm w-auto">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= ($m == $month) ? 'selected' : '' ?>>
                        <?= date("F", mktime(0,0,0,$m,1)) ?>
                    </option>
                <?php endfor; ?>
            </select>
            <label class="small fw-bold">Year:</label>
            <select name="year" onchange="this.form.submit()" class="form-select form-select-sm w-auto">
                <?php for ($y = 2024; $y <= 2030; $y++): ?>
                    <option value="<?= $y ?>" <?= ($y == $year) ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </div>
    <div class="col-md-4">
        <form method="get" class="input-group input-group-sm">
            <input type="hidden" name="month" value="<?= $month ?>">
            <input type="hidden" name="year"  value="<?= $year ?>">
            <input type="text" name="search" class="form-control"
                   placeholder="Search ID, Product, Lot, Coil, Pallet No..."
                   value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
            <?php if ($search !== ''): ?>
                <a href="?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="mb-3 d-flex gap-2 flex-wrap">
    <a href="?month=<?= $month ?>&year=<?= $year ?>&download=excel" class="btn btn-success btn-sm">Download Excel</a>
    <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#manualEntryModal">Manual Entry</button>
    <a href="sfc_tracking.php"       class="btn btn-info btn-sm">SFC Tracking Report</a>
    <a href="process_log_viewer.php" class="btn btn-secondary btn-sm">
        <i class="bi bi-clock-history me-1"></i> Process Log
    </a>
    <!-- NEW: quick link to pallet page -->
    <a href="pallet.php" class="btn btn-primary btn-sm">
        <i class="bi bi-archive me-1"></i> Manage Pallets
    </a>
</div>

<!-- KPI summary cards — now includes Palletised count -->
<div class="d-flex mb-4 gap-2 flex-wrap">
    <div class="card flex-fill text-center text-bg-info">   <div class="card-body p-2"><h6>IN</h6>          <h2><?= (int)$in ?></h2>         </div></div>
    <div class="card flex-fill text-center text-bg-primary"><div class="card-body p-2"><h6>STOCK</h6>       <h2><?= (int)$stock ?></h2>      </div></div>
    <div class="card flex-fill text-center" style="background:#e0f2fe;"><div class="card-body p-2"><h6 style="color:#0369a1;">PALLETISED</h6><h2 style="color:#0369a1;"><?= (int)$palletised ?></h2></div></div>
    <div class="card flex-fill text-center text-bg-warning"><div class="card-body p-2"><h6>WAITING QC</h6> <h2><?= (int)$waiting ?></h2>    </div></div>
    <div class="card flex-fill text-center text-bg-success"><div class="card-body p-2"><h6>DELIVER</h6>    <h2><?= (int)$deliver ?></h2>    </div></div>
</div>

<div class="table-responsive">
    <table class="table table-bordered table-striped align-middle text-center">
        <thead class="table-dark">
            <tr>
                <th>ID</th><th>Status</th><th>Origin</th><th>Product</th>
                <th>Lot No</th><th>Roll No.</th><th>Width</th><th>Length</th>
                <th>Actual</th><th>Pallet</th><th>Date In</th><th>Date Out</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($result && $result->num_rows > 0): while ($row = $result->fetch_assoc()):
            $isFromSFC = ($row['original_source'] ?? $row['source']) === 'sfc';

            $rowClass = match($row['status']) {
                'IN'        => $row['is_completed'] == 0 ? 'table-info' : 'table-primary',
                'OUT'       => 'table-danger',
                'WAITING'   => 'table-warning',
                'APPROVED'  => 'table-success',
                'REJECTED'  => 'table-danger',
                'DELIVERED' => 'table-success',
                default     => ''
            };
            if ($isFromSFC) { $rowClass .= ' sfc-row'; }

            $statusBadge = match($row['status']) {
                'IN'        => $row['is_completed'] == 0
                                ? '<span class="badge bg-info">IN (Pending)</span>'
                                : '<span class="badge bg-primary">IN (Stock)</span>',
                'OUT'       => '<span class="badge bg-danger">OUT</span>',
                'WAITING'   => '<span class="badge bg-warning text-dark">WAITING QC</span>',
                'APPROVED'  => '<span class="badge bg-success">APPROVED</span>',
                'REJECTED'  => '<div><span class="badge bg-danger">REJECTED</span><br>
                                    <small class="text-danger fw-bold">' . htmlspecialchars($row['qc_comment'] ?? '') . '</small></div>',
                'DELIVERED' => '<span class="badge bg-success">DELIVERED</span>',
                default     => '<span class="badge bg-secondary">' . $row['status'] . '</span>'
            };

            $originalSource = $row['original_source'] ?? $row['source'] ?? 'raw_material';
            $originDisplay  = match(trim(strtolower($originalSource))) {
                'sfc'          => ['label' => 'SFC',     'class' => 'bg-primary',   'icon' => '📦'],
                'raw_material' => ['label' => 'RAW MAT', 'class' => 'bg-secondary', 'icon' => '📋'],
                default        => ['label' => strtoupper($originalSource), 'class' => 'bg-info', 'icon' => '📊']
            };

            $lotCoil = trim($row['lot_no'] ?? '') . ' ' . trim($row['coil_no'] ?? '');

            // Pallet display
            $palletDisplay = '—';
            if ($row['pallet_id']) {
                $pStatus = $row['pallet_status'] ?? 'building';
                $pBadgeClass = match($pStatus) {
                    'building'   => 'badge-pallet',
                    'pending_qc' => 'badge bg-warning text-dark',
                    'approved'   => 'badge bg-success',
                    'delivered'  => 'badge bg-success',
                    'rejected'   => 'badge bg-danger',
                    default      => 'badge bg-secondary'
                };
                $palletDisplay = '<a href="pallet.php?pallet_id=' . $row['pallet_id'] . '"
                    class="' . $pBadgeClass . '" style="font-size:10px;">'
                    . htmlspecialchars($row['pallet_no']) . '</a>';
            }
        ?>
            <tr class="<?= $rowClass ?>">
                <td><?= $row['id'] ?></td>
                <td><?= $statusBadge ?></td>
                <td>
                    <span class="badge <?= $originDisplay['class'] ?>">
                        <?= $originDisplay['label'] ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($row['product'] ?? '') ?></td>
                <td><?= htmlspecialchars($lotCoil) ?></td>
                <td><?= str_replace('R', 'R-', htmlspecialchars($row['roll_no'] ?? '')) ?></td>
                <td><?= $row['width'] ?></td>
                <td><?= $row['length'] ?></td>
                <td><?= $row['actual_length'] ?></td>
                <td><?= $palletDisplay ?></td>
                <td><?= $row['date_in'] ?></td>
                <td><?= $row['date_out'] ?></td>
                <td>
    <?php if ($row['status'] === 'WAITING'): ?>
        <small><i>Waiting QC on pallet <?= htmlspecialchars($row['pallet_no'] ?? '...') ?></i></small>

    <?php elseif ($row['status'] === 'REJECTED'): ?>
        <div class="d-flex flex-column gap-1">
            <form method="post" onsubmit="return confirm('Reslit this rejected product?')">
                <input type="hidden" name="action"     value="send_to_reslit">
                <input type="hidden" name="product_id" value="<?= $row['id'] ?>">
                <button type="submit" class="btn btn-warning btn-sm w-100">Reslit</button>
            </form>
            <form method="post" onsubmit="return confirm('Move rejected product to Recoiling?')">
                <input type="hidden" name="action"     value="send_to_recoiling">
                <input type="hidden" name="product_id" value="<?= $row['id'] ?>">
                <button type="submit" class="btn btn-info btn-sm w-100 text-white">Recoiling</button>
            </form>
        </div>

    <?php elseif ($row['status'] === 'IN'): ?>
        <div class="d-flex flex-column gap-1">

            <?php if ($row['is_completed'] == 0): ?>
                <!-- No actual length yet — must update first -->
                <a href="?edit=<?= $row['id'] ?>&month=<?= $month ?>&year=<?= $year ?>&search=<?= urlencode($search) ?>"
                   class="btn btn-primary btn-sm w-100">Update</a>

            <?php elseif ($row['pallet_id']): ?>
                <!-- Already palletised -->
                <a href="pallet.php?pallet_id=<?= $row['pallet_id'] ?>"
                   class="btn btn-outline-primary btn-sm w-100">
                   <i class="bi bi-archive me-1"></i><?= htmlspecialchars($row['pallet_no']) ?>
                </a>
                <form method="post" onsubmit="return confirm('Send to reslit?')">
                    <input type="hidden" name="action"     value="send_to_reslit">
                    <input type="hidden" name="product_id" value="<?= $row['id'] ?>">
                    <button type="submit" class="btn btn-warning btn-sm w-100">Reslit</button>
                </form>
                <form method="post" onsubmit="return confirm('Move to Recoiling?')">
                    <input type="hidden" name="action"     value="send_to_recoiling">
                    <input type="hidden" name="product_id" value="<?= $row['id'] ?>">
                    <button type="submit" class="btn btn-info btn-sm w-100 text-white">Recoiling</button>
                </form>

            <?php else: ?>
                <!-- Stock counted, not yet on a pallet -->
                <a href="?edit=<?= $row['id'] ?>&month=<?= $month ?>&year=<?= $year ?>&search=<?= urlencode($search) ?>"
                   class="btn btn-outline-primary btn-sm w-100">Edit Length</a>
                <!-- ★ NEW: Add to Pallet button ★ -->
                <a href="pallet.php" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-archive me-1"></i> Add to Pallet
                </a>
                <form method="post" onsubmit="return confirm('Send to reslit?')">
                    <input type="hidden" name="action"     value="send_to_reslit">
                    <input type="hidden" name="product_id" value="<?= $row['id'] ?>">
                    <button type="submit" class="btn btn-warning btn-sm w-100">Reslit</button>
                </form>
                <form method="post" onsubmit="return confirm('Move to Recoiling?')">
                    <input type="hidden" name="action"     value="send_to_recoiling">
                    <input type="hidden" name="product_id" value="<?= $row['id'] ?>">
                    <button type="submit" class="btn btn-info btn-sm w-100 text-white">Recoiling</button>
                </form>
                <a href="select_customer.php?id=<?= $row['id'] ?>" class="btn btn-secondary btn-sm w-100">Print Only</a>
            <?php endif; ?>

        </div>

    <?php elseif ($row['status'] === 'APPROVED'): ?>
        <a href="select_customer.php?id=<?= $row['id'] ?>" class="btn btn-success btn-sm w-100">Print & Deliver</a>

    <?php else: ?>
        <a href="select_customer.php?id=<?= $row['id'] ?>" class="btn btn-success btn-sm w-100">Print</a>
    <?php endif; ?>
                </td>
            </tr>
        <?php endwhile; else: ?>
            <tr><td colspan="13" class="py-4 text-muted">
                No products found<?= $search !== '' ? ' matching "' . htmlspecialchars($search) . '"' : '' ?>.
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Actual Length Edit Modal -->
<?php if ($editData): ?>
<div class="modal fade show" id="updateModal"
     style="display:block; background: rgba(0,0,0,0.5);" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <input type="hidden" name="action" value="update_ok">
            <input type="hidden" name="id"     value="<?= $editData['id'] ?>">
            <div class="modal-header bg-primary text-white">
                <h5>Update Product</h5>
                <a href="finish_product.php?month=<?= $month ?>&year=<?= $year ?>&search=<?= urlencode($search) ?>"
                   class="btn-close"></a>
            </div>
            <div class="modal-body">
                <p>
                    <strong>Product:</strong>
                    <?= htmlspecialchars($editData['product'] ?? '') ?>
                    (<?= $editData['roll_no'] ?>)
                </p>
                <div class="mb-3">
                    <label class="form-label">Actual Length (meter)</label>
                    <input type="number" step="0.01" name="actual_length"
                           id="actualLengthInput" class="form-control"
                           value="<?= $editData['actual_length'] ?>" required autofocus>
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-success" id="saveStockBtn" disabled>
                        Save to Stock
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
    const inputLength = document.getElementById('actualLengthInput');
    const btnSave     = document.getElementById('saveStockBtn');
    inputLength.addEventListener('input', () => {
        btnSave.disabled = (inputLength.value === "" || parseFloat(inputLength.value) <= 0);
    });
</script>
<?php endif; ?>

<!-- Manual Entry Modal -->
<div class="modal fade" id="manualEntryModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header">
            <h5>Manual Entry</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form method="post" action="scan_product_action.php">
            <div class="modal-body">
                <input type="text" class="form-control" name="qr"
                       placeholder="LOT;COIL;ROLL" required>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Submit</button>
            </div>
        </form>
    </div></div>
</div>

<script>
// ── QR Scanner ─────────────────────────────────────────────
const qIn = document.getElementById('qrInputProduct');
const qFm = document.getElementById('scanFormProduct');

setInterval(() => {
    if (!document.querySelector('.modal.show') &&
        !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
        qIn.focus();
    }
}, 800);

qIn.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && qIn.value.trim() !== '') qFm.submit();
});

// ── Tooltips ────────────────────────────────────────────────
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
tooltipTriggerList.map(el => new bootstrap.Tooltip(el));

// ── SweetAlert2 scan result notifications ──────────────────
(function () {
    const params = new URLSearchParams(window.location.search);
    const scan   = params.get('scan');
    const reason = params.get('reason') ?? '';
    const pid    = params.get('pid')    ?? '';

    if (!scan) return;

    function escHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    const alerts = {
        'waiting':          { icon:'success', title:'Sent to QC ✓', text:'Product moved to QC Waiting.', timer:2500, showConfirmButton:false },
        'delivered':        { icon:'success', title:'Delivered ✓',  text:'Product marked as Delivered.',  timer:2500, showConfirmButton:false },
        'blocked_qc':       { icon:'error',   title:'Cannot Send to QC',
                              html:'<p>Current blocking reason: <strong style="color:#dc3545">' + escHtml(reason) + '</strong></p>' + (pid?'<p style="font-size:11px">Roll ID: #'+escHtml(pid)+'</p>':''),
                              confirmButtonText:'Understood', confirmButtonColor:'#dc3545' },
        'rejected_blocked': { icon:'warning', title:'Product is Rejected',
                              html:'<p>Send it to <strong>Reslit</strong> or <strong>Recoil</strong> before scanning again.</p>',
                              confirmButtonText:'OK', confirmButtonColor:'#f59e0b' },
        'not_stock':        { icon:'warning', title:'Not in Finish Good Yet',
                              html:'<p>Actual length not recorded. Click <strong>Update</strong> first.</p>',
                              confirmButtonText:'OK' },
        'no_pallet':        { icon:'info',    title:'No Pallet Assigned',
                              html:'<p>This roll is ready but not on a pallet yet.</p><p>Go to <strong>Pallet Management</strong> to create or open a pallet and scan this roll in.</p>',
                              confirmButtonText:'Go to Pallets',
                              preConfirm: () => { window.location.href = 'pallet.php'; } },
        'already_waiting':  { icon:'info',   title:'Already in QC Queue', text:'Already waiting for QC approval.', timer:2500, showConfirmButton:false },
        'already_delivered':{ icon:'info',   title:'Already Delivered',   text:'This product has already been delivered.', timer:2500, showConfirmButton:false },
        'voided':           { icon:'error',  title:'Roll is Voided',       text:'This roll has been voided.',             confirmButtonColor:'#dc3545' },
        'notfound':         { icon:'error',  title:'Product Not Found',    html:'<p>No matching product found.</p>',       confirmButtonColor:'#dc3545' },
        'invalid':          { icon:'error',  title:'Invalid QR Code',      text:'Expected format: LOT;COIL;ROLL',          confirmButtonColor:'#dc3545' },
        'empty':            { icon:'warning',title:'Nothing Scanned',      text:'Empty scan. Please try again.',           timer:2000, showConfirmButton:false },
    };

    const cfg = alerts[scan];
    if (!cfg) return;

    if (typeof Swal !== 'undefined') {
        Swal.fire(cfg).then(() => { if (qIn) qIn.focus(); });
    }

    const cleanUrl = window.location.pathname
        + '?month=' + (params.get('month') ?? '')
        + '&year='  + (params.get('year')  ?? '');
    window.history.replaceState({}, '', cleanUrl);
})();
</script>

<div><a href="index.php" class="btn btn-secondary mt-3">← Back</a></div>
<?php include 'footer.php'; ?>
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
//  5. KPI cards are now clickable filter links — clicking a
//     card filters the table to that status only, with an
//     active highlight and a dismissible banner.
//  6. Reslit / Recoiling buttons hidden for palletised rolls
//     to prevent operator mistakes.
//  7. ID column hidden — replaced with sequential row number (#).
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

// ── Card filter (tab system) ───────────────────────────────────
// Values: in_pending | stock | palletised | waiting | deliver
// Defaults to "in_pending" (the IN tab) when the page first loads.
$filter_card = $_GET['filter'] ?? 'in_pending';
if (!in_array($filter_card, ['in_pending', 'stock', 'palletised', 'waiting', 'deliver'], true)) {
    $filter_card = 'in_pending';
}

if ($month < 1 || $month > 12) { $month = (int)date('m'); }
if ($year < 2000 || $year > 2100) { $year = (int)date('Y'); }

// ── Helper: build tab URL preserving all params ────────────────
function cardUrl(string $filterVal, int $month, int $year, string $search): string {
    $params = ['month' => $month, 'year' => $year, 'filter' => $filterVal];
    if ($search !== '') $params['search'] = $search;
    return '?' . http_build_query($params);
}

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

// ── Batch Update Actual Length (syncs all rolls of same Product + Lot No) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'batch_update_actual_length') {

    $actual_length = trim($_POST['actual_length']);
    $product       = trim($_POST['product']);
    $lot_no        = trim($_POST['lot_no']);

    // Find every not-yet-completed row sharing the same Product + Lot No
    $stmt = $conn->prepare("
        SELECT * FROM slitting_product
        WHERE product=? AND lot_no=? AND status='IN' AND is_completed=0
    ");
    $stmt->bind_param("ss", $product, $lot_no);
    $stmt->execute();
    $group = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($group)) {
        // Fallback: at least update the row the user actually clicked on
        $id = intval($_POST['id']);
        $row = $conn->query("SELECT * FROM slitting_product WHERE id=$id")->fetch_assoc();
        if ($row) $group = [$row];
    }

    // Single efficient batch update for actual_length across the whole group
    $stmt = $conn->prepare("
        UPDATE slitting_product
        SET actual_length=?, stock_counted=1, is_completed=1
        WHERE product=? AND lot_no=? AND status='IN' AND is_completed=0
    ");
    $stmt->bind_param("sss", $actual_length, $product, $lot_no);
    $stmt->execute();
    $stmt->close();

    // Replicate per-row side effects (leftover transfer + audit log) for each row in the group
    foreach ($group as $item) {
        $id = $item['id'];

        if ($item['cut_type'] === 'cut_into_2'
            && floatval($item['leftover_length'] ?? $item['stock'] ?? 0) > 0) {

            $leftover = floatval($item['leftover_length'] ?? $item['stock'] ?? 0);
            $mother   = $conn->query("SELECT * FROM mother_coil WHERE id={$item['mother_id']}")->fetch_assoc();
            if ($mother) {
                $stock_lot_no = $item['lot_no'] . 'a';
                $check = $conn->query("
                    SELECT id, length FROM stock_raw_material
                    WHERE lot_no='$stock_lot_no' AND coil_no='{$item['coil_no']}'
                ");
                if ($check->num_rows > 0) {
                    $existing   = $check->fetch_assoc();
                    $new_length = $existing['length'] + $leftover;
                    $conn->query("UPDATE stock_raw_material SET length=$new_length, updated_at=NOW() WHERE id={$existing['id']}");
                } else {
                    $ins = $conn->prepare("
                        INSERT INTO stock_raw_material
                            (lot_no, coil_no, width, length, status, source_type, source_id, date_in)
                        VALUES (?, ?, ?, ?, 'IN', 'reslit', ?, NOW())
                    ");
                    $ins->bind_param("ssddi",
                        $stock_lot_no, $item['coil_no'],
                        $mother['width'], $leftover, $item['mother_id']);
                    $ins->execute();
                    $ins->close();
                }
            }
        }

        log_process($conn, 'slitting', $id, intval($item['mother_id'] ?? 0) ?: null,
            'IN', 'IN', 'actual_length_saved',
            "actual_length={$actual_length}m, stock_counted=1, batch_synced=" . (count($group) > 1 ? 'yes' : 'no'));
    }

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

// ── Card filter SQL condition + per-tab "latest first" sort column ──
$cardCondition = '';
$sortColumn    = 'sp.date_in';
if ($filter_card === 'in_pending') {
    $cardCondition = " AND sp.status = 'IN' AND sp.is_completed = 0";
    $sortColumn    = 'sp.date_in';
} elseif ($filter_card === 'stock') {
    $cardCondition = " AND sp.status = 'IN' AND sp.stock_counted = 1 AND pi.pallet_id IS NULL";
    $sortColumn    = 'sp.date_in';
} elseif ($filter_card === 'palletised') {
    $cardCondition = " AND sp.status = 'IN' AND pi.pallet_id IS NOT NULL AND p.status = 'building'";
    $sortColumn    = 'sp.date_in';
} elseif ($filter_card === 'waiting') {
    $cardCondition = " AND sp.status = 'WAITING'";
    $sortColumn    = 'sp.date_out';
} elseif ($filter_card === 'deliver') {
    $cardCondition = " AND sp.status = 'DELIVERED'";
    $sortColumn    = 'sp.delivered_at';
}

// ── Main query ────────────────────────────────────────────────
// LEFT JOINs pallet_items and pallets so we can show
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
      )
      {$cardCondition}";

if ($search !== '') {
    $baseSql .= " AND (sp.product LIKE ? OR sp.lot_no LIKE ? OR sp.coil_no LIKE ? OR sp.roll_no LIKE ? OR sp.id LIKE ? OR p.pallet_no LIKE ?)";
}
$baseSql .= " ORDER BY {$sortColumn} DESC, sp.id DESC";

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

// Summary counts (always full month — not affected by card filter)
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
table th:nth-child(1)  { width: 40px; }   /* # counter */
table th:nth-child(2)  { width: 100px; }  /* Status */
table th:nth-child(3)  { width: 110px; }  /* Origin */
table th:nth-child(4)  { width: 90px; }   /* Product */
table th:nth-child(5)  { width: 110px; }  /* Lot No */
table th:nth-child(6)  { width: 65px; }   /* Roll No */
table th:nth-child(7)  { width: 55px; }   /* Width */
table th:nth-child(8)  { width: 55px; }   /* Length */
table th:nth-child(9)  { width: 65px; }   /* Actual */
table th:nth-child(10) { width: 90px; }   /* Pallet */
table th:nth-child(11) { width: 85px; }   /* Date In */
table th:nth-child(12) { width: 85px; }   /* Date Out */
table th:nth-child(13) { width: 130px; }  /* Action */

.badge-pallet { background:#e0f2fe; color:#0369a1; font-size:10px; font-weight:700;
                padding:3px 7px; border-radius:10px; white-space:nowrap; }

/* ── Origin column: badge isolation & spacing ── */
table td {
    padding-left: 8px;
    padding-right: 8px;
}
table td .origin-cell {
    display: flex;
    align-items: center;
    gap: 6px;
}
table td .origin-cell .badge {
    font-size: 10px;
    font-weight: 700;
    padding: 4px 8px;
    border-radius: 10px;
    white-space: nowrap;
    line-height: 1.4;
    flex-shrink: 0;
}
/* Generic safeguard: stop any badge from touching neighbouring text */
.badge {
    margin-right: 6px;
    white-space: nowrap;
}
.badge:last-child {
    margin-right: 0;
}
table td.lot-coil-cell {
    padding-left: 10px;
}

/* ── KPI card filter styles ── */
.kpi-card-link {
    text-decoration: none;
    transition: transform .15s, box-shadow .15s;
    display: block;
}
.kpi-card-link:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 18px rgba(0,0,0,.15);
    text-decoration: none;
}
.kpi-card-link .card {
    border: 2px solid transparent;
    transition: border-color .15s;
    cursor: pointer;
}
.kpi-card-link.active-kpi {
    transform: translateY(-3px);
}
.kpi-card-link.active-kpi .card {
    border-color: rgba(0,0,0,.35) !important;
    box-shadow: 0 0 0 3px rgba(255,255,255,.6), 0 6px 18px rgba(0,0,0,.2);
}
.kpi-active-dot {
    display: block;
    font-size: 10px;
    opacity: .85;
    margin-top: 2px;
}
.kpi-card-palletised-active .card {
    border-color: #0369a1 !important;
    box-shadow: 0 0 0 3px rgba(3,105,161,.2), 0 6px 18px rgba(0,0,0,.12) !important;
}

/* ── Row counter cell ── */
.row-counter {
    font-size: 11px;
    color: #6c757d;
    font-weight: 600;
    text-align: center;
}
</style>

<h2 class="mb-4"><i class="bi bi-check-circle me-2"></i>Finish Product</h2>

<form id="scanFormProduct" method="post" action="scan_product_action.php" autocomplete="off">
    <input id="qrInputProduct" type="hidden" name="qr" value="">
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
            <?php if ($filter_card !== ''): ?>
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter_card) ?>">
            <?php endif; ?>
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
            <?php if ($filter_card !== ''): ?>
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter_card) ?>">
            <?php endif; ?>
            <input type="text" name="search" class="form-control"
                   placeholder="Search ID, Product, Lot, Coil, Pallet No..."
                   value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
            <?php if ($search !== ''): ?>
                <a href="?month=<?= $month ?>&year=<?= $year ?><?= $filter_card ? '&filter='.urlencode($filter_card) : '' ?>" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="mb-3 d-flex gap-2 flex-wrap">
    <a href="?month=<?= $month ?>&year=<?= $year ?>&download=excel" class="btn btn-success btn-sm">Download Excel</a>
    <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#manualEntryModal">Manual Entry</button>
    <button type="button" class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#initialStockSetupModal">
        <i class="bi bi-box-seam me-1"></i> Initial Stock Setup
    </button>
    <button type="button" class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#isuBulkImportModal">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Bulk Import (Excel)
    </button>
    <a href="sfc_tracking.php"       class="btn btn-info btn-sm">SFC Tracking Report</a>
    <a href="process_log_viewer.php" class="btn btn-secondary btn-sm">
        <i class="bi bi-clock-history me-1"></i> Process Log
    </a>
    <a href="pallet.php" class="btn btn-primary btn-sm">
        <i class="bi bi-archive me-1"></i> Manage Pallets
    </a>
</div>

<!-- ================================================================
     KPI SUMMARY CARDS — clickable filters
================================================================ -->
<div class="d-flex mb-3 gap-2 flex-wrap">

    <!-- IN (Pending) -->
    <?php $isActiveIn = ($filter_card === 'in_pending'); ?>
    <a href="<?= cardUrl('in_pending', $month, $year, $search) ?>"
       class="kpi-card-link flex-fill <?= $isActiveIn ? 'active-kpi' : '' ?>"
       title="Show IN (Pending) rolls only">
        <div class="card text-center text-bg-info h-100">
            <div class="card-body p-2">
                <h6 class="mb-1">IN</h6>
                <h2 class="mb-0"><?= (int)$in ?></h2>
                <?php if ($isActiveIn): ?>
                    <span class="kpi-active-dot">▲ filtered</span>
                <?php endif; ?>
            </div>
        </div>
    </a>

    <!-- STOCK -->
    <?php $isActiveStock = ($filter_card === 'stock'); ?>
    <a href="<?= cardUrl('stock', $month, $year, $search) ?>"
       class="kpi-card-link flex-fill <?= $isActiveStock ? 'active-kpi' : '' ?>"
       title="Show Finish Good stock only">
        <div class="card text-center text-bg-primary h-100">
            <div class="card-body p-2">
                <h6 class="mb-1">STOCK</h6>
                <h2 class="mb-0"><?= (int)$stock ?></h2>
                <?php if ($isActiveStock): ?>
                    <span class="kpi-active-dot">▲ filtered</span>
                <?php endif; ?>
            </div>
        </div>
    </a>

    <!-- PALLETISED -->
    <?php $isActivePal = ($filter_card === 'palletised'); ?>
    <a href="<?= cardUrl('palletised', $month, $year, $search) ?>"
       class="kpi-card-link flex-fill <?= $isActivePal ? 'active-kpi kpi-card-palletised-active' : '' ?>"
       title="Show palletised rolls only"
       style="color:#0369a1;">
        <div class="card text-center h-100" style="background:#e0f2fe;">
            <div class="card-body p-2">
                <h6 class="mb-1" style="color:#0369a1;">PALLETISED</h6>
                <h2 class="mb-0" style="color:#0369a1;"><?= (int)$palletised ?></h2>
                <?php if ($isActivePal): ?>
                    <span class="kpi-active-dot" style="color:#0369a1;">▲ filtered</span>
                <?php endif; ?>
            </div>
        </div>
    </a>

    <!-- WAITING QC -->
    <?php $isActiveWait = ($filter_card === 'waiting'); ?>
    <a href="<?= cardUrl('waiting', $month, $year, $search) ?>"
       class="kpi-card-link flex-fill <?= $isActiveWait ? 'active-kpi' : '' ?>"
       title="Show Waiting QC rolls only">
        <div class="card text-center text-bg-warning h-100">
            <div class="card-body p-2">
                <h6 class="mb-1">WAITING QC</h6>
                <h2 class="mb-0"><?= (int)$waiting ?></h2>
                <?php if ($isActiveWait): ?>
                    <span class="kpi-active-dot">▲ filtered</span>
                <?php endif; ?>
            </div>
        </div>
    </a>

    <!-- DELIVER -->
    <?php $isActiveDel = ($filter_card === 'deliver'); ?>
    <a href="<?= cardUrl('deliver', $month, $year, $search) ?>"
       class="kpi-card-link flex-fill <?= $isActiveDel ? 'active-kpi' : '' ?>"
       title="Show Delivered rolls only">
        <div class="card text-center text-bg-success h-100">
            <div class="card-body p-2">
                <h6 class="mb-1">DELIVER</h6>
                <h2 class="mb-0"><?= (int)$deliver ?></h2>
                <?php if ($isActiveDel): ?>
                    <span class="kpi-active-dot">▲ filtered</span>
                <?php endif; ?>
            </div>
        </div>
    </a>

</div>

<!-- Active tab banner -->
<div class="alert alert-info py-2 mb-3">
    <i class="bi bi-funnel-fill me-2"></i>
    Showing: <strong>
    <?= match($filter_card) {
        'in_pending'  => 'IN (Pending) only',
        'stock'       => 'Finish Good Stock only',
        'palletised'  => 'Palletised rolls only',
        'waiting'     => 'Waiting QC only',
        'deliver'     => 'Delivered only',
        default       => ''
    } ?>
    </strong>
    &nbsp;—&nbsp; click another card above to switch tabs. Sorted newest first.
</div>

<div class="table-responsive">
    <table class="table table-bordered table-striped align-middle text-center">
        <thead class="table-dark">
            <tr>
                <th>#</th><th>Status</th><th>Origin</th><th>Product</th>
                <th>Lot No</th><th>Roll No.</th><th>Width</th><th>Length</th>
                <th>Actual</th><th>Pallet</th><th>Date In</th><th>Date Out</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $rowNum = 0;
        if ($result && $result->num_rows > 0): while ($row = $result->fetch_assoc()):
            $rowNum++;
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
            <tr class="<?= $rowClass ?>"
                data-id="<?= $row['id'] ?>"
                data-product="<?= htmlspecialchars($row['product'] ?? '') ?>"
                data-lot="<?= htmlspecialchars(trim($row['lot_no'] ?? '')) ?>">
                <td class="row-counter"><?= $rowNum ?></td>
                <td><?= $statusBadge ?></td>
                <td>
                    <div class="origin-cell">
                        <span class="badge <?= $originDisplay['class'] ?>">
                            <?= $originDisplay['label'] ?>
                        </span>
                    </div>
                </td>
                <td><?= htmlspecialchars($row['product'] ?? '') ?></td>
                <td class="lot-coil-cell"><?= htmlspecialchars($lotCoil) ?></td>
                <td><?= str_replace('R', 'R-', htmlspecialchars($row['roll_no'] ?? '')) ?></td>
                <td><?= $row['width'] ?></td>
                <td><?= $row['length'] ?></td>
                <td id="actual-display-<?= $row['id'] ?>"><?= $row['actual_length'] ?></td>
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
                <a href="?edit=<?= $row['id'] ?>&month=<?= $month ?>&year=<?= $year ?>&search=<?= urlencode($search) ?><?= $filter_card ? '&filter='.urlencode($filter_card) : '' ?>"
                   class="btn btn-primary btn-sm w-100">Update</a>

            <?php elseif ($row['pallet_id']): ?>
                <!-- Already palletised — hide Reslit/Recoiling to prevent mistakes -->
                <a href="pallet.php?pallet_id=<?= $row['pallet_id'] ?>"
                   class="btn btn-outline-primary btn-sm w-100">
                   <i class="bi bi-archive me-1"></i><?= htmlspecialchars($row['pallet_no']) ?>
                </a>
                <small class="text-muted text-center d-block mt-1" style="font-size:10px;">
                    <i class="bi bi-lock me-1"></i>Remove from pallet to Reslit / Recoil
                </small>

            <?php else: ?>
                <!-- Stock counted, not yet on a pallet -->
                <a href="?edit=<?= $row['id'] ?>&month=<?= $month ?>&year=<?= $year ?>&search=<?= urlencode($search) ?><?= $filter_card ? '&filter='.urlencode($filter_card) : '' ?>"
                   class="btn btn-outline-primary btn-sm w-100">Edit Length</a>
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
                No products found<?= $search !== '' ? ' matching "' . htmlspecialchars($search) . '"' : '' ?><?= $filter_card !== '' ? ' for the selected filter' : '' ?>.
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
            <input type="hidden" name="action" value="batch_update_actual_length">
            <input type="hidden" name="id"      value="<?= $editData['id'] ?>">
            <input type="hidden" name="product" value="<?= htmlspecialchars($editData['product'] ?? '') ?>">
            <input type="hidden" name="lot_no"  value="<?= htmlspecialchars(trim($editData['lot_no'] ?? '')) ?>">
            <div class="modal-header bg-primary text-white">
                <h5>Update Product</h5>
                <a href="finish_product.php?month=<?= $month ?>&year=<?= $year ?>&search=<?= urlencode($search) ?><?= $filter_card ? '&filter='.urlencode($filter_card) : '' ?>"
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
                           value="<?= $editData['actual_length'] ?>" required autofocus
                           data-self-id="<?= $editData['id'] ?>"
                           data-product="<?= htmlspecialchars($editData['product'] ?? '') ?>"
                           data-lot="<?= htmlspecialchars(trim($editData['lot_no'] ?? '')) ?>">
                    <small class="text-muted" id="syncNote" style="display:none;">
                        This will also update other rolls sharing the same Product &amp; Lot No.
                    </small>
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
    const syncNote    = document.getElementById('syncNote');

    function findMatchingRows() {
        const product = inputLength.dataset.product;
        const lot      = inputLength.dataset.lot;
        const selfId   = inputLength.dataset.selfId;
        return Array.from(document.querySelectorAll('tr[data-id]')).filter(tr =>
            tr.dataset.id !== selfId &&
            tr.dataset.product === product &&
            tr.dataset.lot === lot
        );
    }

    inputLength.addEventListener('input', () => {
        btnSave.disabled = (inputLength.value === "" || parseFloat(inputLength.value) <= 0);

        const matches = findMatchingRows();
        syncNote.style.display = matches.length > 0 ? 'inline' : 'none';

        // Live preview: instantly reflect the new value on matching visible rows
        matches.forEach(tr => {
            const cell = document.getElementById('actual-display-' + tr.dataset.id);
            if (cell) cell.textContent = inputLength.value;
        });
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

<!-- =============================================================
     Initial Stock Setup Modal — one-time legacy migration tool.
     Submits to initial_stock_setup_add.php (separate file).

     Field-unlock behavior now mirrors Add Mother Coil (#addMotherModal):
       Lot No (4–8 alphanumeric, validated live)
         -> unlocks Coil No
       Coil No entered + blurred
         -> AJAX lookup against mother_coil.php?ajax=get_product
            -> single match  : Product auto-filled (readonly), rest unlocks
            -> multiple match: Product <select> shown, rest unlocks once chosen
            -> no match      : BLOCKED — Coil No must match a known product,
                                same strict behavior as Add Mother Coil.
         -> unlocks Roll No, Width, Actual + enables Save button

     Date In: not a visible field — always set to "now" server-side
     in initial_stock_setup_add.php.

     Length: not a visible field — backend sets it equal to Actual.
   ============================================================= -->
<div class="modal fade" id="initialStockSetupModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form action="initial_stock_setup_add.php" method="POST" id="initialStockSetupForm">

        <div class="modal-header bg-warning-subtle">
          <h5 class="modal-title">
            <i class="bi bi-exclamation-triangle"></i>
            Initial Stock Setup &mdash; Legacy Product Entry
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">

          <div class="alert alert-warning small mb-3">
            <strong>One-time migration tool.</strong> Use this only to register
            physical stock that already exists in the warehouse from old mother
            coils not in this system. This bypasses normal mother coil
            traceability &mdash; do not use for new production.
          </div>

          <!-- Lot No (first — unlocks Coil No) -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Lot No <span class="text-danger">*</span></label>
            <input type="text" name="lot_no" id="isu_lot_no" class="form-control" required
                   maxlength="8" pattern="^[a-zA-Z0-9]{4,8}$"
                   title="4–8 alphanumeric characters"
                   placeholder="e.g. 5001">
            <div class="form-text">4–8 characters, letters and numbers only.</div>
          </div>

          <!-- Coil No (unlocks after Lot No) -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Coil No <span class="text-danger">*</span></label>
            <input type="text" name="coil_no" id="isu_coil_no" class="form-control" required
                   disabled placeholder="Enter coil number">
            <div class="form-text" id="isu_coil_hint">Enter coil number then click elsewhere to look up product.</div>
          </div>

          <!-- Product display (auto-filled or chosen) -->
          <div class="mb-3" id="isu_product_wrap">
            <label class="form-label fw-semibold">Product <span class="text-danger">*</span></label>

            <!-- CASE 1: single match → readonly display, hidden input carries value -->
            <div id="isu_product_auto_wrap">
              <input type="text" id="isu_product_display" class="form-control"
                     readonly placeholder="Will auto-fill after Coil No is entered">
            </div>

            <!-- CASE 2: multiple matches → dropdown -->
            <div id="isu_product_select_wrap" class="d-none">
              <div class="small text-info mb-1">
                <i class="bi bi-info-circle"></i>
                Multiple products found — please select one
              </div>
              <select id="isu_product_select" class="form-select" required>
                <option value="">-- Select Product --</option>
              </select>
            </div>

            <!-- Hidden input actually submitted to the backend -->
            <input type="hidden" name="product" id="isu_product_hidden">
          </div>

          <div class="row g-3">

            <div class="col-md-4">
              <label class="form-label">Roll No <span class="text-danger">*</span></label>
              <input type="text" name="roll_no" id="isu_roll_no" class="form-control" required disabled>
            </div>

            <div class="col-md-4">
              <label class="form-label">Width (mm) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="width" id="isu_width" class="form-control" required disabled>
            </div>

            <div class="col-md-4">
              <label class="form-label">Actual (Weight/Value) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="actual_length" id="isu_actual_length" class="form-control" required disabled>
              <div class="form-text">This is also saved as the roll's Length automatically.</div>
            </div>

          </div>

          <div class="mt-3 small text-muted">
            <strong>Auto-applied on save (not editable here):</strong>
            Date In = <code>now</code>,
            Length = <code>same as Actual</code>,
            Source = <code>stock</code>,
            Original Source = <code>initial_stock</code>,
            Mother Coil = <code>none (NULL)</code>,
            Status = <code>IN</code>.
            A QR code will be generated automatically using your Lot/Roll info.
          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning" id="isu_submit_btn" disabled>
            <i class="bi bi-save"></i> Register Legacy Stock
          </button>
        </div>

      </form>
    </div>
  </div>
</div>
<script>
(function () {
    const lotInput      = document.getElementById('isu_lot_no');
    const coilInput     = document.getElementById('isu_coil_no');
    const coilHint      = document.getElementById('isu_coil_hint');

    const productAutoWrap   = document.getElementById('isu_product_auto_wrap');
    const productSelectWrap = document.getElementById('isu_product_select_wrap');
    const productDisplay    = document.getElementById('isu_product_display');
    const productSelect     = document.getElementById('isu_product_select');
    const productHidden     = document.getElementById('isu_product_hidden');

    const rollInput   = document.getElementById('isu_roll_no');
    const widthInput  = document.getElementById('isu_width');
    const actualInput = document.getElementById('isu_actual_length');
    const submitBtn    = document.getElementById('isu_submit_btn');

    const lotPattern = /^[a-zA-Z0-9]{4,8}$/;

    function lockDownstreamFields() {
        [rollInput, widthInput, actualInput].forEach(el => { el.disabled = true; });
        submitBtn.disabled = true;
        productHidden.value = '';
        productDisplay.value = '';
        productDisplay.placeholder = 'Will auto-fill after Coil No is entered';
        productSelectWrap.classList.add('d-none');
        productAutoWrap.classList.remove('d-none');
    }

    // ── Step 1: Lot No format check unlocks Coil No ──────────
    lotInput.addEventListener('input', function () {
        const valid = lotPattern.test(this.value.trim());
        coilInput.disabled = !valid;
        if (!valid) {
            coilInput.value = '';
            lockDownstreamFields();
        }
    });

    // ── Step 2: Coil No blur triggers product lookup ─────────
    coilInput.addEventListener('blur', function () {
        const coil = this.value.trim();
        lockDownstreamFields();

        if (coil === '') return;

        coilHint.textContent = 'Looking up product...';

        fetch('mother_coil.php?ajax=get_product&coil=' + encodeURIComponent(coil))
            .then(res => res.json())
            .then(data => {
                if (!data.ok || !data.products || data.products.length === 0) {
                    coilHint.textContent = 'No matching product found for this Coil No. Cannot proceed — please verify the Coil No.';
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Coil No Not Recognized',
                            text: 'This Coil No does not match any known product. Initial Stock Setup requires a valid Coil No.'
                        });
                    }
                    return;
                }

                if (data.products.length === 1) {
                    // Single match — auto-fill, readonly
                    productAutoWrap.classList.remove('d-none');
                    productSelectWrap.classList.add('d-none');
                    productDisplay.value = data.products[0];
                    productHidden.value  = data.products[0];
                    coilHint.textContent = 'Product matched automatically.';
                    unlockRemainingFields();
                } else {
                    // Multiple matches — show dropdown, wait for user choice
                    productAutoWrap.classList.add('d-none');
                    productSelectWrap.classList.remove('d-none');
                    productSelect.innerHTML = '<option value="">-- Select Product --</option>' +
                        data.products.map(p => `<option value="${p}">${p}</option>`).join('');
                    coilHint.textContent = 'Multiple products found — please select one above.';
                    // Remaining fields unlock only once a product is chosen below.
                }
            })
            .catch(() => {
                coilHint.textContent = 'Lookup failed — check your connection and try again.';
            });
    });

    productSelect.addEventListener('change', function () {
        if (this.value) {
            productHidden.value = this.value;
            unlockRemainingFields();
        } else {
            productHidden.value = '';
            lockDownstreamFieldsKeepCoil();
        }
    });

    function unlockRemainingFields() {
        [rollInput, widthInput, actualInput].forEach(el => { el.disabled = false; });
        submitBtn.disabled = false;
    }

    function lockDownstreamFieldsKeepCoil() {
        [rollInput, widthInput, actualInput].forEach(el => { el.disabled = true; });
        submitBtn.disabled = true;
    }

    document.getElementById('initialStockSetupForm').addEventListener('submit', function (e) {
        const form = this;

        if (!productHidden.value) {
            e.preventDefault();
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'warning', title: 'Product Not Resolved', text: 'Please enter a valid Coil No so the Product can be matched before saving.' });
            }
            return;
        }

        if (form.dataset.confirmed === 'true') {
            return; // already confirmed -> let it submit normally
        }

        e.preventDefault();

        if (typeof Swal === 'undefined') {
            form.submit();
            return;
        }

        Swal.fire({
            title: 'Confirm Legacy Stock Entry',
            text: 'This will bypass mother coil tracking and create the product directly. Continue?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, register it',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                form.dataset.confirmed = 'true';
                form.submit();
            }
        });
    });

    // Reset the whole modal state every time it's reopened
    document.getElementById('initialStockSetupModal').addEventListener('hidden.bs.modal', function () {
        lotInput.value = '';
        coilInput.value = '';
        coilInput.disabled = true;
        coilHint.textContent = 'Enter coil number then click elsewhere to look up product.';
        rollInput.value = '';
        widthInput.value = '';
        actualInput.value = '';
        lockDownstreamFields();
        document.getElementById('initialStockSetupForm').dataset.confirmed = 'false';
    });
})();
</script>

<!-- =============================================================
     Initial Stock Setup — Excel Bulk Import Modal
     Submits to initial_stock_setup_import.php.
     Sits ALONGSIDE the manual single-entry form above (both
     available), per request. Partial-import mode: rows that
     pass validation are saved; rows that fail are skipped and
     reported, they do not block the rows that succeeded.
   ============================================================= -->
<div class="modal fade" id="isuBulkImportModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form action="initial_stock_setup_import.php" method="POST" enctype="multipart/form-data">

        <div class="modal-header bg-success-subtle">
          <h5 class="modal-title">
            <i class="bi bi-file-earmark-spreadsheet"></i>
            Initial Stock Setup &mdash; Bulk Excel Import
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">

          <div class="alert alert-info small mb-3">
            Your Excel file must have these column headers (any order):
            <code>Item number</code>, <code>Batch number</code>, <code>Available physical</code>.
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Excel File <span class="text-danger">*</span></label>
            <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls,.csv" required>
            <div class="form-text">.xlsx, .xls, or .csv</div>
          </div>

          <div class="small text-muted">
            Rows that fail validation (bad format, unrecognized Coil No, duplicates)
            will be <strong>skipped and listed in a report</strong> &mdash; valid rows
            in the same file are still imported.
          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-upload"></i> Upload &amp; Import
          </button>
        </div>

      </form>
    </div>
  </div>
</div>

<script>
initCameraScanner({
    onScan: function(decodedText) {
        document.getElementById('qrInputProduct').value = decodedText;
        document.getElementById('scanFormProduct').submit();
    }
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
        + '&year='  + (params.get('year')  ?? '')
        + (params.get('filter') ? '&filter=' + encodeURIComponent(params.get('filter')) : '');
    window.history.replaceState({}, '', cleanUrl);
})();
</script>

<div><a href="index.php" class="btn btn-secondary mt-3">← Back</a></div>
<?php include 'footer.php'; ?>
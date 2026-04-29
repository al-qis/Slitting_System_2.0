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

// --- Handle Excel Export ---
if (isset($_GET['download']) && $_GET['download'] === 'excel') {
    $m = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
    $y = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    header("Location: finish_product_export.php?month=$m&year=$y");
    exit;
}

// Params selection
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($month < 1 || $month > 12) { $month = (int)date('m'); }
if ($year < 2000 || $year > 2100) { $year = (int)date('Y'); }

// --- Handle QC Approve (If triggered from this page) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'qc_approve') {
    $id = intval($_POST['product_id']);
    $stmt = $conn->prepare("UPDATE slitting_product SET status='APPROVED', qc_comment=NULL WHERE id=? AND status='WAITING'");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        header("Location: finish_product.php?success=qc_approved");
    } else {
        header("Location: finish_product.php?error=qc_approve_failed");
    }
    $stmt->close();
    exit;
}

// --- Handle Save Actual Length + OK ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_ok') {
    $id = intval($_POST['id']);
    $actual_length = trim($_POST['actual_length']);
    
    $stmt = $conn->prepare("UPDATE slitting_product SET actual_length=?, stock_counted=1, is_completed=1 WHERE id=?");
    $stmt->bind_param("si", $actual_length, $id);
    $stmt->execute();
    $stmt->close();
    
    $product = $conn->query("SELECT * FROM slitting_product WHERE id=$id")->fetch_assoc();
    if($product && $product['cut_type'] === 'cut_into_2' && $product['stock'] > 0) {
        $mother = $conn->query("SELECT * FROM mother_coil WHERE id={$product['mother_id']}")->fetch_assoc();
        if($mother) {
            $stock_lot_no = $product['lot_no'] . 'a';
            $check = $conn->query("SELECT id, length FROM stock_raw_material WHERE lot_no='$stock_lot_no' AND coil_no='{$product['coil_no']}'");
            if($check->num_rows > 0) {
                $existing = $check->fetch_assoc();
                $new_length = $existing['length'] + $product['stock'];
                $conn->query("UPDATE stock_raw_material SET length=$new_length, updated_at=NOW() WHERE id={$existing['id']}");
            } else {
                $stmt = $conn->prepare("INSERT INTO stock_raw_material (lot_no, coil_no, width, length, status, source_type, source_id, date_in) VALUES (?, ?, ?, ?, 'IN', 'reslit', ?, NOW())");
                $stmt->bind_param("ssddi", $stock_lot_no, $product['coil_no'], $mother['width'], $product['stock'], $product['mother_id']);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    header("Location: finish_product.php?month=$month&year=$year&success=stock");
    exit;
}

// --- Handle Send to Recoiling ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_to_recoiling') {
    $id = intval($_POST['product_id']);
    $res = $conn->query("SELECT * FROM slitting_product WHERE id = $id");
    if ($res->num_rows > 0) {
        $p = $res->fetch_assoc();
        
        // PERMANENT SOURCE: Keep original_source from slitting_product
        $original_source = $p['original_source'] ?? 'raw_material';
        
        $stmt = $conn->prepare("INSERT INTO recoiling_product (status, product, lot_no, coil_no, roll_no, width, length, actual_length, original_source) VALUES ('pending', ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssddds", $p['product'], $p['lot_no'], $p['coil_no'], $p['roll_no'], $p['width'], $p['length'], $p['actual_length'], $original_source);
        if ($stmt->execute()) {
            $stmt->close();
            $conn->query("UPDATE slitting_product SET is_recoiled=1 WHERE id=$id");
            
            // Log source tracking
            $stmt = $conn->prepare("INSERT INTO source_tracking_log (product_id, table_name, original_source, current_source, action) VALUES (?, 'recoiling_product', ?, 'recoiling', 'send_to_recoiling')");
            $stmt->bind_param("iss", $id, $original_source);
            $stmt->execute();
            $stmt->close();
            
            header("Location: finish_product.php?success=recoiling");
            exit;
        }
    }
}

// --- Handle Send to Reslit ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_to_reslit') {
    $id = intval($_POST['product_id']);
    $res = $conn->query("SELECT * FROM slitting_product WHERE id = $id");
    if ($res->num_rows > 0) {
        $p = $res->fetch_assoc();
        
        // PERMANENT SOURCE: Keep original_source from slitting_product
        $original_source = $p['original_source'] ?? 'raw_material';
        
        $stmt = $conn->prepare("INSERT INTO reslit_product (status, product, lot_no, coil_no, roll_no, width, length, date_in, original_source) VALUES ('pending', ?, ?, ?, ?, ?, ?, NOW(), ?)");
        $stmt->bind_param("ssssdds", $p['product'], $p['lot_no'], $p['coil_no'], $p['roll_no'], $p['width'], $p['length'], $original_source);
        if ($stmt->execute()) {
            $stmt->close();
            $conn->query("UPDATE slitting_product SET is_reslitted=1 WHERE id=$id");
            
            // Log source tracking
            $stmt = $conn->prepare("INSERT INTO source_tracking_log (product_id, table_name, original_source, current_source, action) VALUES (?, 'reslit_product', ?, 'reslit', 'send_to_reslit')");
            $stmt->bind_param("iss", $id, $original_source);
            $stmt->execute();
            $stmt->close();
            
            header("Location: finish_product.php?success=reslit");
            exit;
        }
    }
}

// Build Search Condition
$searchSql = "";
if ($search !== '') {
    $searchSql = " AND (product LIKE '%$search%' OR lot_no LIKE '%$search%' OR coil_no LIKE '%$search%' OR roll_no LIKE '%$search%' OR id LIKE '%$search%') ";
}

// Fetch Table Results (Including REJECTED status)
$sql = "SELECT * FROM slitting_product WHERE (is_recoiled=0 OR is_recoiled IS NULL) AND (is_reslitted=0 OR is_reslitted IS NULL)
        AND ((status='IN' AND MONTH(date_in)=$month AND YEAR(date_in)=$year) 
             OR (status IN ('WAITING','OUT','APPROVED','REJECTED') AND MONTH(date_out)=$month AND YEAR(date_out)=$year) 
             OR (status='DELIVERED' AND MONTH(delivered_at)=$month AND YEAR(delivered_at)=$year))
        $searchSql
        ORDER BY id ASC";
$result = $conn->query($sql);

// Summaries
$in = $conn->query("SELECT IFNULL(COUNT(*),0) AS total FROM slitting_product WHERE status='IN' AND is_completed=0 AND (is_recoiled=0 OR is_recoiled IS NULL) AND (is_reslitted=0 OR is_reslitted IS NULL) AND MONTH(date_in)=$month AND YEAR(date_in)=$year")->fetch_assoc()['total'];
$stock = $conn->query("SELECT IFNULL(COUNT(*),0) AS total FROM slitting_product WHERE status='IN' AND stock_counted=1 AND (is_recoiled=0 OR is_recoiled IS NULL) AND (is_reslitted=0 OR is_reslitted IS NULL) AND MONTH(date_in)=$month AND YEAR(date_in)=$year")->fetch_assoc()['total'];
$waiting = $conn->query("SELECT IFNULL(COUNT(*),0) AS total FROM slitting_product WHERE status='WAITING' AND MONTH(date_out)=$month AND YEAR(date_out)=$year")->fetch_assoc()['total'];
$deliver = $conn->query("SELECT IFNULL(COUNT(*),0) AS total FROM slitting_product WHERE status='DELIVERED' AND MONTH(delivered_at)=$month AND YEAR(delivered_at)=$year")->fetch_assoc()['total'];

$editData = null;
if(isset($_GET['edit'])){
    $id = intval($_GET['edit']);
    $res = $conn->query("SELECT * FROM slitting_product WHERE id=$id");
    if($res->num_rows > 0) $editData = $res->fetch_assoc();
}

$page_title = 'Finish Product';
include 'header.php';
?>

<style>
    .pending-row { background-color: #fff3cd; }
    .completed-row { background-color: #d1e7dd; }
    .sfc-row { background-color: #e7f3ff; border-left: 4px solid #0066ff; }
    table { table-layout: fixed; width: 100%; }
    table th, table td { word-wrap: break-word; vertical-align: middle; font-size: 13px; }
    table td img { max-width: 60px; max-height: 60px; display: block; margin: 0 auto; }
    /* Column Widths */
    table th:nth-child(1) { width: 45px; } table th:nth-child(2) { width: 110px; } table th:nth-child(3) { width: 70px; }
    table th:nth-child(4) { width: 90px; } table th:nth-child(5) { width: 110px; } table th:nth-child(6) { width: 70px; }
    table th:nth-child(7) { width: 60px; } table th:nth-child(8) { width: 60px; } table th:nth-child(9) { width: 70px; }
    table th:nth-child(10) { width: 90px; } table th:nth-child(11) { width: 90px; } table th:nth-child(12) { width: 70px; }
    table th:nth-child(13) { width: 140px; }
</style>

<h2 class="mb-4"><i class="bi bi-check-circle me-2"></i>Finish Product</h2>

<form id="scanFormProduct" method="post" action="scan_product_action.php" autocomplete="off">
    <input id="qrInputProduct" type="text" name="qr" inputmode="none" style="position:fixed; left:-9999px; opacity:0;" autofocus>
</form>

<div class="row mb-3 g-2 align-items-center">
    <div class="col-auto">
        <form method="get" class="d-flex gap-2 align-items-center">
            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
            <label class="small fw-bold">Month:</label>
            <select name="month" onchange="this.form.submit()" class="form-select form-select-sm w-auto">
                <?php for($m=1;$m<=12;$m++): ?>
                    <option value="<?= $m ?>" <?= ($m==$month)?'selected':'' ?>><?= date("F", mktime(0,0,0,$m,1)) ?></option>
                <?php endfor; ?>
            </select>
            <label class="small fw-bold">Year:</label>
            <select name="year" onchange="this.form.submit()" class="form-select form-select-sm w-auto">
                <?php for($y=2024; $y<=2030; $y++): ?>
                    <option value="<?= $y ?>" <?= ($y==$year)?'selected':'' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </div>

    <div class="col-md-4">
        <form method="get" class="input-group input-group-sm">
            <input type="hidden" name="month" value="<?= $month ?>">
            <input type="hidden" name="year" value="<?= $year ?>">
            <input type="text" name="search" class="form-control" placeholder="Search ID, Product, Lot, Coil..." value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
            <?php if ($search !== ''): ?>
                <a href="?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="mb-3 d-flex gap-2">
    <a href="?month=<?= $month ?>&year=<?= $year ?>&download=excel" class="btn btn-success btn-sm">Download Excel</a>
    <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#manualEntryModal">Manual Entry</button>
    <a href="sfc_tracking_report.php" class="btn btn-info btn-sm">SFC Tracking Report</a>
</div>

<div class="d-flex mb-4 gap-2">
    <div class="card flex-fill text-center text-bg-info"><div class="card-body p-2"><h6>IN</h6><h2><?= (int)$in ?></h2></div></div>
    <div class="card flex-fill text-center text-bg-primary"><div class="card-body p-2"><h6>STOCK</h6><h2><?= (int)$stock ?></h2></div></div>
    <div class="card flex-fill text-center text-bg-warning"><div class="card-body p-2"><h6>WAITING</h6><h2><?= (int)$waiting ?></h2></div></div>
    <div class="card flex-fill text-center text-bg-success"><div class="card-body p-2"><h6>DELIVER</h6><h2><?= (int)$deliver ?></h2></div></div>
</div>

<div class="table-responsive">
    <table class="table table-bordered table-striped align-middle text-center">
        <thead class="table-dark">
            <tr>
                <th>ID</th><th>Status</th><th>Origin</th><th>Product</th><th>Lot No</th><th>Roll No.</th>
                <th>Width</th><th>Length</th><th>Actual</th><th>Date Out</th><th>Delivered</th><th>QR</th><th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if($result && $result->num_rows>0): while($row=$result->fetch_assoc()): 
            // Check if this is from SFC
            $isFromSFC = ($row['original_source'] ?? $row['source']) === 'sfc';
            
            // Handle REJECTED color and badge
            $rowClass = match($row['status']) {
                'IN' => $row['is_completed'] == 0 ? 'table-info' : 'table-primary',
                'OUT' => 'table-danger', 
                'WAITING' => 'table-warning', 
                'APPROVED' => 'table-success', 
                'REJECTED' => 'table-danger',
                'DELIVERED' => 'table-success', 
                default => ''
            };
            
            if ($isFromSFC) {
                $rowClass .= ' sfc-row';
            }

            $statusBadge = match($row['status']) {
                'IN' => $row['is_completed'] == 0 ? '<span class="badge bg-info">IN (Pending)</span>' : '<span class="badge bg-primary">IN (Stock)</span>',
                'OUT' => '<span class="badge bg-danger">OUT</span>', 
                'WAITING' => '<span class="badge bg-warning text-dark">WAITING QC</span>',
                'APPROVED' => '<span class="badge bg-success">APPROVED</span>',
                'REJECTED' => '<div><span class="badge bg-danger" data-bs-toggle="tooltip" data-bs-placement="top" title="Reason: ' . htmlspecialchars($row['qc_comment'] ?? 'No reason provided') . '">REJECTED <i class="bi bi-info-circle"></i></span><br><small class="text-danger fw-bold">' . htmlspecialchars($row['qc_comment'] ?? '') . '</small></div>',
                'DELIVERED' => '<span class="badge bg-success">DELIVERED</span>', 
                default => '<span class="badge bg-secondary">'.$row['status'].'</span>'
            };

            // PERMANENT SOURCE DISPLAY
            $originalSource = $row['original_source'] ?? $row['source'] ?? 'raw_material';
            $originDisplay = match(trim(strtolower($originalSource))) {
                'sfc' => ['label' => 'SFC', 'class' => 'bg-primary', 'icon' => '📦'],
                'raw_material' => ['label' => 'RAW MAT', 'class' => 'bg-secondary', 'icon' => '📋'],
                default => ['label' => strtoupper($originalSource), 'class' => 'bg-info', 'icon' => '📊']
            };

            

            $lotCoil = trim($row['lot_no'] ?? '') . ' ' . trim($row['coil_no'] ?? '');

            $originBadge = ($row['original_source'] === 'sfc') 
        ? '<br><span class="badge bg-info text-dark" style="font-size: 0.65rem;">SFC ORIGIN</span>' 
        : '<br><span class="badge bg-light text-muted" style="font-size: 0.65rem;">RAW MATERIAL</span>'
        
        ?>
            <tr class="<?= $rowClass ?>">
                <td><?= $row['id'] ?></td>
                <td><?= $statusBadge ?></td>
                <td>
                    <span class="badge <?= $originDisplay['class'] ?>" title="Original source: <?= htmlspecialchars($originalSource) ?>">
                        <?= $originDisplay['label'] ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($row['product'] ?? '') ?></td>
                <td><?= htmlspecialchars($lotCoil ?? '') ?></td>
                <td><?= str_replace('R', 'R-', htmlspecialchars($row['roll_no'] ?? '')) ?></td>
                <td><?= $row['width'] ?></td><td><?= $row['length'] ?></td><td><?= $row['actual_length'] ?></td>
                <td><?= $row['date_out'] ?></td><td><?= $row['delivered_at'] ?></td>
                <td><img src="generate_qr.php?id=<?= $row['id'] ?>&type=slitting" alt="QR"></td>
                <td>
    <?php if($row['status'] == 'WAITING'): ?>
        <small><i>Waiting QC...</i></small>
    
    <?php elseif($row['status'] == 'REJECTED'): ?>
        <div class="d-flex flex-column gap-1">
            <form method="post" onsubmit="return confirm('Reslit this rejected product?')">
                <input type="hidden" name="action" value="send_to_reslit"><input type="hidden" name="product_id" value="<?= $row['id'] ?>">
                <button type="submit" class="btn btn-warning btn-sm w-100">Reslit</button>
            </form>
            <form method="post" onsubmit="return confirm('Move rejected product to Recoiling?')">
                <input type="hidden" name="action" value="send_to_recoiling"><input type="hidden" name="product_id" value="<?= $row['id'] ?>">
                <button type="submit" class="btn btn-info btn-sm w-100 text-white">Recoiling</button>
            </form>
        </div>

    <?php elseif($row['status'] == 'IN'): ?>
        <div class="d-flex flex-column gap-1">
            <a href="?edit=<?= $row['id'] ?>&month=<?= $month ?>&year=<?= $year ?>&search=<?= urlencode($search) ?>" 
               class="btn <?= $row['is_completed'] == 0 ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm w-100">
               <?= $row['is_completed'] == 0 ? 'Update' : 'Edit Length' ?>
            </a>

            <?php if($row['stock_counted'] == 1): ?>
                <form method="post" onsubmit="return confirm('Send to reslit?')">
                    <input type="hidden" name="action" value="send_to_reslit"><input type="hidden" name="product_id" value="<?= $row['id'] ?>">
                    <button type="submit" class="btn btn-warning btn-sm w-100">Reslit</button>
                </form>
                <form method="post" onsubmit="return confirm('Move to Recoiling?')">
                    <input type="hidden" name="action" value="send_to_recoiling"><input type="hidden" name="product_id" value="<?= $row['id'] ?>">
                    <button type="submit" class="btn btn-info btn-sm w-100 text-white">Recoiling</button>
                </form>
                <a href="select_customer.php?id=<?= $row['id'] ?>" class="btn btn-secondary btn-sm w-100">Print Only</a>
            <?php endif; ?>
        </div>

    <?php elseif($row['status'] == 'APPROVED'): ?>
        <a href="select_customer.php?id=<?= $row['id'] ?>" class="btn btn-success btn-sm w-100">Print & Deliver</a>
    
    <?php else: ?>
        <a href="select_customer.php?id=<?= $row['id'] ?>" class="btn btn-success btn-sm w-100">Print</a>
    <?php endif; ?>
</td>
            </tr>
        <?php endwhile; else: ?>
            <tr><td colspan="13" class="py-4 text-muted">No products found matching "<?= htmlspecialchars($search) ?>"</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if($editData): ?>
<div class="modal fade show" id="updateModal" style="display:block; background: rgba(0,0,0,0.5);" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <input type="hidden" name="action" value="update_ok"><input type="hidden" name="id" value="<?= $editData['id'] ?>">
            <div class="modal-header bg-primary text-white"><h5>Update Product</h5><a href="finish_product.php?month=<?= $month ?>&year=<?= $year ?>&search=<?= urlencode($search) ?>" class="btn-close"></a></div>
            <div class="modal-body">
                <p><strong>Product:</strong> <?= htmlspecialchars($editData['product'] ?? '') ?> (<?= $editData['roll_no'] ?>)</p>
                <div class="mb-3">
                    <label class="form-label">Actual Length (meter)</label>
                    <input type="number" step="0.01" name="actual_length" id="actualLengthInput" 
       class="form-control" value="<?= $editData['actual_length'] ?>" required autofocus>
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-success" id="saveStockBtn" disabled>Save to Stock</button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
    const inputLength = document.getElementById('actualLengthInput');
    const btnSave = document.getElementById('saveStockBtn');
    inputLength.addEventListener('input', () => {
        btnSave.disabled = (inputLength.value === "" || parseFloat(inputLength.value) <= 0);
    });
</script>
<?php endif; ?>

<div class="modal fade" id="manualEntryModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5>Manual Entry</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form method="post" action="scan_product_action.php"><div class="modal-body">
            <input type="text" class="form-control" name="qr" placeholder="LOT;COIL;ROLL" required>
        </div><div class="modal-footer"><button type="submit" class="btn btn-primary">Submit</button></div></form>
    </div></div>
</div>

<script>
    // Scanner focus logic
    const qIn = document.getElementById('qrInputProduct');
    const qFm = document.getElementById('scanFormProduct');
    setInterval(() => {
        if(!document.querySelector('.modal.show') && !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) qIn.focus();
    }, 800);
    qIn.addEventListener('keydown', (e) => { if(e.key==='Enter' && qIn.value.trim()!=='') qFm.submit(); });

    // Enable Tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
      return new bootstrap.Tooltip(tooltipTriggerEl)
    })
</script>

<div><a href="index.php" class="btn btn-secondary mt-3">← Back</a></div>
<?php include 'footer.php'; ?>
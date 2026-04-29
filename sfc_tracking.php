<?php
session_start();

// Authentication Check
if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if (!in_array($_SESSION['role'], ['slitting', 'mkl3'], true)) {
    die("Access denied");
}

include 'config.php';

// Get filter parameters
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($month < 1 || $month > 12) { $month = (int)date('m'); }
if ($year < 2000 || $year > 2100) { $year = (int)date('Y'); }

$page_title = "SFC Tracking Report";
include 'header.php';
?>

<style>
    .badge-sfc { background-color: #0066ff; }
    .table-sfc-row { background-color: #e7f3ff; border-left: 4px solid #0066ff; }
    table th { background-color: #003366; color: white; }
    .stage-badge { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; }
    .stage-slitting { background-color: #dcefff; color: #003d82; }
    .stage-recoiling { background-color: #fff4dcef; color: #8b5e00; }
    .stage-reslit { background-color: #e8f5e9; color: #1b5e20; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-graph-up me-2"></i>SFC Origin Tracking Report</h2>
    <a href="sfc.php" class="btn btn-secondary btn-sm">← Back to SFC Inventory</a>
</div>

<div class="alert alert-info mb-4">
    <i class="bi bi-info-circle me-2"></i>
    <strong>SFC Tracking:</strong> This report shows all products that originated from SFC inventory and their current locations (Slitting, Recoiling, or Reslit).
</div>

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

<!-- SLITTING PRODUCTS FROM SFC -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white fw-bold py-3">
        <i class="bi bi-scissors me-2"></i>SFC Products in Slitting (Finish Products)
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle text-center mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Product</th>
                    <th>Lot & Coil</th>
                    <th>Roll No</th>
                    <th>Width</th>
                    <th>Length</th>
                    <th>Status</th>
                    <th>Date In</th>
                    <th>Stage</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $slittingQuery = "SELECT id, product, lot_no, coil_no, roll_no, width, length, status, date_in, original_source 
                                 FROM slitting_product 
                                 WHERE original_source = 'sfc' AND (MONTH(date_in)=$month AND YEAR(date_in)=$year OR date_in IS NULL)";
                if ($search !== '') {
                    $slittingQuery .= " AND (product LIKE '%$search%' OR lot_no LIKE '%$search%' OR coil_no LIKE '%$search%' OR id LIKE '%$search%')";
                }
                $slittingQuery .= " ORDER BY id DESC";
                
                $slittingResult = $conn->query($slittingQuery);
                
                if ($slittingResult && $slittingResult->num_rows > 0):
                    while ($row = $slittingResult->fetch_assoc()):
                ?>
                <tr class="table-sfc-row">
                    <td class="fw-bold"><?= $row['id'] ?></td>
                    <td><span class="badge badge-sfc"><?= htmlspecialchars($row['product']) ?></span></td>
                    <td class="small"><?= htmlspecialchars($row['lot_no'] ?? '-') ?> | <?= htmlspecialchars($row['coil_no'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($row['roll_no'] ?? '-') ?></td>
                    <td><?= number_format($row['width'] ?? 0, 2) ?></td>
                    <td><?= number_format($row['length'] ?? 0, 2) ?></td>
                    <td><span class="badge bg-info"><?= htmlspecialchars($row['status']) ?></span></td>
                    <td class="small"><?= $row['date_in'] ?></td>
                    <td><span class="stage-badge stage-slitting"><i class="bi bi-scissors me-1"></i>Slitting</span></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="9" class="py-4 text-muted">No SFC products in slitting stage</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- RECOILING PRODUCTS FROM SFC -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white fw-bold py-3">
        <i class="bi bi-arrow-repeat me-2"></i>SFC Products in Recoiling
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle text-center mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Product</th>
                    <th>Lot & Coil</th>
                    <th>Roll No</th>
                    <th>Width</th>
                    <th>Length</th>
                    <th>Status</th>
                    <th>Date In</th>
                    <th>Stage</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $recoilingQuery = "SELECT id, product, lot_no, coil_no, roll_no, width, length, status, date_in, original_source 
                                  FROM recoiling_product 
                                  WHERE original_source = 'sfc' AND (MONTH(date_in)=$month AND YEAR(date_in)=$year OR date_in IS NULL)";
                if ($search !== '') {
                    $recoilingQuery .= " AND (product LIKE '%$search%' OR lot_no LIKE '%$search%' OR coil_no LIKE '%$search%' OR id LIKE '%$search%')";
                }
                $recoilingQuery .= " ORDER BY id DESC";
                
                $recoilingResult = $conn->query($recoilingQuery);
                
                if ($recoilingResult && $recoilingResult->num_rows > 0):
                    while ($row = $recoilingResult->fetch_assoc()):
                ?>
                <tr class="table-sfc-row">
                    <td class="fw-bold"><?= $row['id'] ?></td>
                    <td><span class="badge badge-sfc"><?= htmlspecialchars($row['product']) ?></span></td>
                    <td class="small"><?= htmlspecialchars($row['lot_no'] ?? '-') ?> | <?= htmlspecialchars($row['coil_no'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($row['roll_no'] ?? '-') ?></td>
                    <td><?= number_format($row['width'] ?? 0, 2) ?></td>
                    <td><?= number_format($row['length'] ?? 0, 2) ?></td>
                    <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($row['status']) ?></span></td>
                    <td class="small"><?= $row['date_in'] ?></td>
                    <td><span class="stage-badge stage-recoiling"><i class="bi bi-arrow-repeat me-1"></i>Recoiling</span></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="9" class="py-4 text-muted">No SFC products in recoiling stage</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- RESLIT PRODUCTS FROM SFC -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-dark text-white fw-bold py-3">
        <i class="bi bi-intersect me-2"></i>SFC Products in Reslit
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle text-center mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Product</th>
                    <th>Lot & Coil</th>
                    <th>Roll No</th>
                    <th>Width</th>
                    <th>Length</th>
                    <th>Status</th>
                    <th>Date In</th>
                    <th>Stage</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $reslitQuery = "SELECT id, product, lot_no, coil_no, roll_no, width, length, status, date_in, original_source 
                               FROM reslit_product 
                               WHERE original_source = 'sfc' AND (MONTH(date_in)=$month AND YEAR(date_in)=$year OR date_in IS NULL)";
                if ($search !== '') {
                    $reslitQuery .= " AND (product LIKE '%$search%' OR lot_no LIKE '%$search%' OR coil_no LIKE '%$search%' OR id LIKE '%$search%')";
                }
                $reslitQuery .= " ORDER BY id DESC";
                
                $reslitResult = $conn->query($reslitQuery);
                
                if ($reslitResult && $reslitResult->num_rows > 0):
                    while ($row = $reslitResult->fetch_assoc()):
                ?>
                <tr class="table-sfc-row">
                    <td class="fw-bold"><?= $row['id'] ?></td>
                    <td><span class="badge badge-sfc"><?= htmlspecialchars($row['product']) ?></span></td>
                    <td class="small"><?= htmlspecialchars($row['lot_no'] ?? '-') ?> | <?= htmlspecialchars($row['coil_no'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($row['roll_no'] ?? '-') ?></td>
                    <td><?= number_format($row['width'] ?? 0, 2) ?></td>
                    <td><?= number_format($row['length'] ?? 0, 2) ?></td>
                    <td><span class="badge bg-success"><?= htmlspecialchars($row['status']) ?></span></td>
                    <td class="small"><?= $row['date_in'] ?></td>
                    <td><span class="stage-badge stage-reslit"><i class="bi bi-intersect me-1"></i>Reslit</span></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="9" class="py-4 text-muted">No SFC products in reslit stage</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- SUMMARY STATISTICS -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <h6 class="text-muted small mb-1">IN SLITTING</h6>
                <h3 class="text-primary fw-bold">
                    <?php 
                    $slittingCount = $conn->query("SELECT COUNT(*) as cnt FROM slitting_product WHERE original_source='sfc'")->fetch_assoc()['cnt'];
                    echo (int)$slittingCount;
                    ?>
                </h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <h6 class="text-muted small mb-1">IN RECOILING</h6>
                <h3 class="text-warning fw-bold">
                    <?php 
                    $recoilingCount = $conn->query("SELECT COUNT(*) as cnt FROM recoiling_product WHERE original_source='sfc'")->fetch_assoc()['cnt'];
                    echo (int)$recoilingCount;
                    ?>
                </h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <h6 class="text-muted small mb-1">IN RESLIT</h6>
                <h3 class="text-success fw-bold">
                    <?php 
                    $reslitCount = $conn->query("SELECT COUNT(*) as cnt FROM reslit_product WHERE original_source='sfc'")->fetch_assoc()['cnt'];
                    echo (int)$reslitCount;
                    ?>
                </h3>
            </div>
        </div>
    </div>
</div>

<div><a href="sfc.php" class="btn btn-secondary mt-3">← Back to SFC</a></div>

<?php include 'footer.php'; ?>
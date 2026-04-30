<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

include 'config.php';

// 2. Filter Logic (Month & Year)
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');

if ($month < 1 || $month > 12) { $month = (int)date('m'); }
if ($year < 2020 || $year > 2030) { $year = (int)date('Y'); }

// Query for log - all records (IN and OUT)
$query = "SELECT id, lot_no, coil_no, grade, width, length, status, source_type, source_id, date_in, updated_at
          FROM stock_raw_material
          WHERE (MONTH(date_in)=$month AND YEAR(date_in)=$year) 
             OR (MONTH(updated_at)=$month AND YEAR(updated_at)=$year)
          ORDER BY id DESC";
$result = $conn->query($query);

// Count totals for the period
$in_count = $conn->query("SELECT COUNT(*) AS total FROM stock_raw_material 
                          WHERE status='IN' AND MONTH(date_in)=$month AND YEAR(date_in)=$year")
                          ->fetch_assoc()['total'];

$out_count = $conn->query("SELECT COUNT(*) AS total FROM stock_raw_material 
                           WHERE status='OUT' AND MONTH(updated_at)=$month AND YEAR(updated_at)=$year")
                           ->fetch_assoc()['total'];

$page_title = "Stock Raw Material Log";
include 'header.php'; 
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-clock-history me-2"></i>Stock Raw Material Log</h2>
    <div class="d-flex gap-2">
        <a href="raw_material.php" class="btn btn-primary shadow-sm">
            <i class="bi bi-boxes me-1"></i> Back to Available Stock
        </a>
        <a href="stock_log_export.php?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-success shadow-sm">
            <i class="bi bi-file-earmark-excel me-1"></i> Download
        </a>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-center">
            <div class="col-auto">
                <label class="small fw-bold text-muted">Month:</label>
                <select name="month" onchange="this.form.submit()" class="form-select form-select-sm w-auto d-inline-block ms-1">
                    <?php for($m=1;$m<=12;$m++): ?>
                        <option value="<?= $m ?>" <?= ($m==$month)?'selected':'' ?>>
                            <?= date("F", mktime(0,0,0,$m,1)) ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="small fw-bold text-muted">Year:</label>
                <select name="year" onchange="this.form.submit()" class="form-select form-select-sm w-auto d-inline-block ms-1">
                    <?php for($y=2024; $y<=2030; $y++): ?>
                        <option value="<?= $y ?>" <?= ($y==$year)?'selected':'' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4 text-center">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-white">
            <div class="card-body p-2">
                <h6 class="text-muted small mb-1">SCAN IN</h6>
                <h4 class="text-success fw-bold mb-0"><?= (int)$in_count ?></h4>
                <small class="text-muted">This Month</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-white">
            <div class="card-body p-2">
                <h6 class="text-muted small mb-1">SCAN OUT</h6>
                <h4 class="text-danger fw-bold mb-0"><?= (int)$out_count ?></h4>
                <small class="text-muted">This Month</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-white">
            <div class="card-body p-2">
                <h6 class="text-muted small mb-1">TOTAL ENTRIES</h6>
                <h4 class="text-info fw-bold mb-0"><?= (int)$in_count + (int)$out_count ?></h4>
                <small class="text-muted">This Period</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-primary text-white">
            <div class="card-body p-2">
                <h6 class="small mb-1">CURRENT STOCK</h6>
                <h4 class="fw-bold mb-0">
                    <?php 
                    $current = $conn->query("SELECT COUNT(*) AS total FROM stock_raw_material WHERE status='IN'")->fetch_assoc()['total'];
                    echo $current;
                    ?>
                </h4>
                <small>Available</small>
            </div>
        </div>
    </div>
</div>

<!-- Stock Raw Material Log Table -->
<div class="card shadow-sm border-0 mb-5">
    <div class="card-header bg-dark text-white fw-bold py-3">
        <i class="bi bi-table me-2"></i>Complete Stock Tracking History
        <span class="badge bg-info ms-2">All Transactions</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle text-center mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Stock ID</th>
                    <th>Lot No</th>
                    <th>Coil No</th>
                    <th>Grade</th>
                    <th>Length (mtr)</th>
                    <th>Width (mm)</th>
                    <th>Status</th>
                    <th>Source</th>
                    <th>Date In</th>
                    <th>Last Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php if($result && $result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()): 
                        $statusBadge = $row['status'] == 'IN' ? '<span class="badge bg-success">IN STOCK</span>' : '<span class="badge bg-secondary">OUT</span>';
                        $sourceBadge = ($row['source_type'] == 'slitting_cut_into_2') 
                                     ? '<span class="badge bg-warning text-dark">Cut Into 2</span>' 
                                     : '<span class="badge bg-info">Mother Coil</span>';
                        $rowClass = ($row['source_type'] == 'slitting_cut_into_2') ? 'table-warning' : '';
                    ?>
                    <tr class="<?= $rowClass ?>">
                        <td class="fw-bold">#<?= $row['id'] ?></td>
                        <td><?= htmlspecialchars($row['lot_no']) ?></td>
                        <td><?= htmlspecialchars($row['coil_no']) ?></td>
                        <td><?= htmlspecialchars($row['grade'] ?? '-') ?></td>
                        <td class="fw-bold"><?= number_format((float)$row['length']) ?></td>
                        <td><?= number_format((float)$row['width']) ?></td>
                        <td><?= $statusBadge ?></td>
                        <td><?= $sourceBadge ?></td>
                        <td class="small"><?= $row['date_in'] ?? '-' ?></td>
                        <td class="small"><?= $row['updated_at'] ?? '-' ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="10" class="py-4 text-muted">No stock records found for the selected period.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Show success message if set
<?php if (isset($_SESSION['success'])): ?>
    alert('<?= htmlspecialchars($_SESSION['success']) ?>');
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>
</script>

<?php include 'footer.php'; ?>
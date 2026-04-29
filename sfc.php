<?php
session_start();

// 1. Authentication & Role Check
if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if (!in_array($_SESSION['role'], ['slitting','mkl3'], true)) {
    die("Access denied for this role.");
}

include 'config.php';

// 2. Handle Action (Post Submission)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sfc_id']) && isset($_POST['action'])) {
    $sfcId = (int)$_POST['sfc_id'];
    $action = $_POST['action']; 

    $stmt = $conn->prepare("SELECT * FROM sfc WHERE sfc_id = ? AND date_out IS NULL");
    $stmt->bind_param("i", $sfcId);
    $stmt->execute();
    $sfc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($sfc) {
        $conn->begin_transaction();
        try {
            $original_source = 'sfc';

            if ($action === 'RECOIL') {
                $stmt = $conn->prepare("INSERT INTO recoiling_product (product, lot_no, coil_no, roll_no, width, length, status, date_in, original_source) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), ?)");
                $stmt->bind_param("ssssddds", 
                        $sfc['product'],
                        $sfc['lot_no'], 
                        $sfc['coil_no'],
                        $sfc['roll_no'],
                        $sfc['width'], 
                        $sfc['length'],
                        $original_source
                );
                $stmt->execute();
                $stmt->close();
                log_source_tracking($conn, 0, 'recoiling_product', $original_source, 'sfc', 'RECOIL_FROM_SFC');

            } elseif ($action === 'RESLIT') {
                $stmt = $conn->prepare("INSERT INTO reslit_product (product, lot_no, coil_no, roll_no, width, length, status, date_in, original_source) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), ?)");
                $stmt->bind_param("ssssddds", 
                        $sfc['product'], 
                        $sfc['lot_no'], 
                        $sfc['coil_no'],
                        $sfc['roll_no'],
                        $sfc['width'], 
                        $sfc['length'],
                        $original_source
                );
                $stmt->execute();
                $stmt->close();
                log_source_tracking($conn, 0, 'reslit_product', $original_source, 'sfc', 'RESLIT_FROM_SFC');

            } elseif ($action === 'SELL') {
                $stmt = $conn->prepare("INSERT INTO slitting_product (product, lot_no, coil_no, roll_no, width, length, status, date_in, date_out, cut_type, source, original_source) VALUES (?, ?, ?, ?, ?, ?, 'WAITING', NOW(), NOW(), 'sfc_sell', 'sfc', ?)");
                $stmt->bind_param("sssssddds", 
                        $sfc['product'], 
                        $sfc['lot_no'], 
                        $sfc['coil_no'],
                        $sfc['roll_no'],
                        $sfc['width'], 
                        $sfc['length'],
                        $original_source
                );
                $stmt->execute();
                $stmt->close();
                log_source_tracking($conn, 0, 'slitting_product', $original_source, 'sfc', 'SELL_FROM_SFC');
            }

            $updateStmt = $conn->prepare("UPDATE sfc SET date_out = NOW(), action = ? WHERE sfc_id = ?");
            $updateStmt->bind_param("si", $action, $sfcId);
            $updateStmt->execute();
            $updateStmt->close();

            $conn->commit();
            
            if ($action === 'SELL') {
                header("Location: finish_product.php?success=1&msg=sfc_sold");
            } else {
                header("Location: sfc.php?success=1");
            }
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            die("An error occurred: " . $e->getMessage());
        }
    } else {
        die("SFC not found or already used.");
    }
}

function log_source_tracking($conn, $product_id, $table_name, $original_source, $current_source, $action) {
    $stmt = $conn->prepare("INSERT INTO source_tracking_log (product_id, table_name, original_source, current_source, action) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $product_id, $table_name, $original_source, $current_source, $action);
    $stmt->execute();
    $stmt->close();
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($search !== '') {
    $query = "SELECT * FROM sfc WHERE date_out IS NULL AND (
                sfc_id LIKE ? OR 
                product LIKE ? OR 
                lot_no LIKE ? OR 
                coil_no LIKE ? OR
                roll_no LIKE ?
              ) ORDER BY date_created DESC";
    $stmt = $conn->prepare($query);
    $likeSearch = "%$search%";
    $stmt->bind_param("sssss", $likeSearch, $likeSearch, $likeSearch, $likeSearch, $likeSearch);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query("SELECT * FROM sfc WHERE date_out IS NULL ORDER BY date_created DESC");
}

$page_title = "SFC Inventory";
include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-box-seam-fill me-2 text-primary"></i>SFC Inventory Management</h2>
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success py-2 mb-0 shadow-sm">
            <i class="bi bi-check-circle me-2"></i>Action processed successfully!
        </div>
    <?php endif; ?>
</div>

<div class="row mb-3">
    <div class="col-md-5">
        <form method="GET" action="sfc.php" class="input-group shadow-sm">
            <input type="text" name="search" class="form-control" placeholder="Search ID, Product, Lot, or Roll..." value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-search me-1"></i> Search
            </button>
            <?php if ($search !== ''): ?>
                <a href="sfc.php" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="col-md-7 text-end">
        <a href="sfc_tracking_report.php" class="btn btn-info btn-sm">
            <i class="bi bi-graph-up me-1"></i> View SFC Tracking Report
        </a>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-dark text-white fw-bold py-3 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-task me-2"></i>Available SFC Material</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle text-center mb-0">
            <thead class="table-light">
                <tr>
                    <th>SFC ID</th>
                    <th>Product</th>
                    <th>Lot No</th>
                    <th>Roll No</th>
                    <th>Width (mm)</th>
                    <th>Length (m)</th>
                    <th>Date Created</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td class="fw-bold text-muted">#<?= htmlspecialchars($row['sfc_id']) ?></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($row['product']) ?></span></td>
                            <td class="small fw-bold">
                                <?= htmlspecialchars($row['lot_no']) ?> <?= htmlspecialchars($row['coil_no']) ?>
                            </td>
                            <td><span class="badge outline-primary text-dark border"><?= htmlspecialchars($row['roll_no']) ?></span></td>
                            <td><?= number_format($row['width']) ?> </td>
                            <td class="text-primary fw-bold"><?= number_format($row['length'], 2) ?></td>
                            <td class="small text-muted"><?= date('d/M/Y', strtotime($row['date_created'])) ?></td>
                            <td>
                                <button type="button" class="btn btn-primary btn-sm px-3 rounded-pill actionBtn shadow-sm" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#actionModal"
                                        data-sfc-id="<?= $row['sfc_id'] ?>"
                                        data-sfc-details="<?= htmlspecialchars($row['product']) ?> (<?= number_format($row['length'], 2) ?>m)">
                                    Process SFC
                                </button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="py-5 text-muted">No SFC inventory found matching your criteria.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="actionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">Select Next Process</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center p-4">
        <p class="text-muted small text-uppercase fw-bold">Processing Material:</p>
        <h5 id="sfcDetails" class="fw-bold mb-4"></h5>
        <form id="actionForm" method="post" action="sfc.php">
            <input type="hidden" name="sfc_id" id="sfc_id_input">
            <div class="d-grid gap-3">
                <button type="submit" name="action" value="RECOIL" class="btn btn-warning py-2 fw-bold shadow-sm">
                    <i class="bi bi-arrow-repeat me-2"></i> RECOIL
                </button>
                <button type="submit" name="action" value="RESLIT" class="btn btn-info py-2 fw-bold text-white shadow-sm">
                    <i class="bi bi-intersect me-2"></i> RESLIT
                </button>
                <button type="submit" name="action" value="SELL" class="btn btn-success py-2 fw-bold shadow-sm">
                    <i class="bi bi-cart-check me-2"></i> SELL (To QC)
                </button>
            </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var actionModal = document.getElementById('actionModal');
    if(actionModal) {
        actionModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            document.getElementById('sfc_id_input').value = button.getAttribute('data-sfc-id');
            document.getElementById('sfcDetails').textContent = button.getAttribute('data-sfc-details');
        });
    }
});
</script>

<?php include 'footer.php'; ?>
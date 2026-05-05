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
                $stmt->bind_param("ssssdds", 
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
                $stmt->bind_param("ssssdds", 
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
                $stmt->bind_param("ssssdds", 
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

// 3. Handle PIN verify via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_pin'])) {
    $enteredPin = trim($_POST['verify_pin']);
    // ── Set your supervisor PIN here ──
    $supervisorPin = '1234';
    header('Content-Type: application/json');
    echo json_encode(['success' => ($enteredPin === $supervisorPin)]);
    exit;
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

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><i class="bi bi-box-seam-fill me-2 text-primary"></i>SFC Inventory Management</h2>
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success py-2 mb-0 shadow-sm">
            <i class="bi bi-check-circle me-2"></i>Action processed successfully!
        </div>
    <?php endif; ?>
</div>

<!-- Hidden QR scan input (always listening in background) -->
<input id="qrScanInput" type="text" inputmode="none"
       style="position:fixed; left:-9999px; opacity:0;" autofocus>

<!-- Scan feedback alert -->
<div id="qrAlert" class="d-none alert py-2 mb-3 shadow-sm"></div>

<!-- QR Scan status indicator -->
<div class="alert alert-secondary py-2 mb-3 d-flex align-items-center gap-2">
    <i class="bi bi-qr-code-scan fs-5"></i>
    <span class="small">QR Scanner ready — scan an SFC label anytime, or click <strong>Process SFC</strong> (supervisor PIN required).</span>
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
        <a href="sfc_tracking.php" class="btn btn-info btn-sm">
            <i class="bi bi-graph-up me-1"></i> View SFC Tracking Report
        </a>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-dark text-white fw-bold py-3">
        <i class="bi bi-list-task me-2"></i>Available SFC Material
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle text-center mb-0">
            <thead class="table-light">
                <tr>
                    <th>Print</th>
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
                            <td>
                                <a href="print_sfc.php?id=<?= (int)$row['sfc_id'] ?>"
                                   target="_blank"
                                   class="btn btn-outline-dark btn-sm"
                                   title="Print Label #<?= (int)$row['sfc_id'] ?>">
                                    <i class="bi bi-printer-fill"></i>
                                </a>
                            </td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($row['product']) ?></span></td>
                            <td class="small fw-bold">
                                <?= htmlspecialchars($row['lot_no']) ?> <?= htmlspecialchars($row['coil_no']) ?>
                            </td>
                            <td><span class="badge outline-primary text-dark border"><?= htmlspecialchars($row['roll_no']) ?></span></td>
                            <td><?= number_format($row['width']) ?></td>
                            <td class="text-primary fw-bold"><?= number_format($row['length'], 2) ?></td>
                            <td class="small text-muted"><?= date('d/M/Y', strtotime($row['date_created'])) ?></td>
                            <td>
                                <!-- Button click requires PIN -->
                                <button type="button"
                                        class="btn btn-primary btn-sm px-3 rounded-pill actionBtn shadow-sm"
                                        data-sfc-id="<?= $row['sfc_id'] ?>"
                                        data-sfc-lot="<?= htmlspecialchars($row['lot_no']) ?>"
                                        data-sfc-coil="<?= htmlspecialchars($row['coil_no']) ?>"
                                        data-sfc-details="<?= htmlspecialchars($row['product']) ?> | <?= htmlspecialchars($row['lot_no']) ?> <?= htmlspecialchars($row['coil_no']) ?> | <?= number_format($row['length'], 2) ?>m">
                                    <i class="bi bi-shield-lock-fill me-1"></i> Process SFC
                                </button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="py-5 text-muted">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                            No SFC inventory found matching your criteria.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── PIN Modal (button click only) ─────────────────────────────────────── -->
<div class="modal fade" id="pinModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-warning text-dark">
        <h5 class="modal-title"><i class="bi bi-shield-lock-fill me-2"></i>Supervisor PIN</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center p-4">
        <p class="text-muted small mb-3">Enter supervisor PIN to proceed.</p>
        <input type="password" id="pinInput" class="form-control form-control-lg text-center mb-2"
               placeholder="••••" maxlength="10" autocomplete="off">
        <div id="pinError" class="text-danger small d-none mt-1">
            <i class="bi bi-x-circle me-1"></i>Incorrect PIN. Try again.
        </div>
      </div>
      <div class="modal-footer justify-content-center border-0 pt-0">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" id="pinSubmitBtn" class="btn btn-warning fw-bold px-4">
            <i class="bi bi-unlock-fill me-1"></i> Unlock
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── Action Modal (scan = direct, button = after PIN) ───────────────────── -->
<div class="modal fade" id="actionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="bi bi-gear-fill me-2"></i>Select Next Process</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center p-4">
        <p class="text-muted small text-uppercase fw-bold mb-1">Processing Material:</p>
        <h5 id="sfcDetails" class="fw-bold mb-1 text-primary"></h5>
        <p class="text-muted small mb-4">SFC ID: <span id="sfcIdDisplay" class="fw-bold text-dark"></span></p>
        <form id="actionForm" method="post" action="sfc.php">
            <input type="hidden" name="sfc_id" id="sfc_id_input">
            <div class="d-grid gap-3">
                <button type="submit" name="action" value="RECOIL"
                        class="btn btn-warning py-2 fw-bold shadow-sm"
                        onclick="return confirm('Send to Recoiling?')">
                    <i class="bi bi-arrow-repeat me-2"></i> RECOIL
                </button>
                <button type="submit" name="action" value="RESLIT"
                        class="btn btn-info py-2 fw-bold text-white shadow-sm"
                        onclick="return confirm('Send to Reslit?')">
                    <i class="bi bi-intersect me-2"></i> RESLIT
                </button>
                <button type="submit" name="action" value="SELL"
                        class="btn btn-success py-2 fw-bold shadow-sm"
                        onclick="return confirm('Send to QC / Finish Product?')">
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

    const pinModal    = new bootstrap.Modal(document.getElementById('pinModal'));
    const actionModal = new bootstrap.Modal(document.getElementById('actionModal'));
    const pinInput    = document.getElementById('pinInput');
    const pinError    = document.getElementById('pinError');
    const pinSubmitBtn = document.getElementById('pinSubmitBtn');
    const qrInput     = document.getElementById('qrScanInput');
    const qrAlert     = document.getElementById('qrAlert');

    let pendingSfcId      = null;
    let pendingSfcDetails = null;

    // ── Helper: populate & open action modal ──────────────────────────────
    function openActionModal(sfcId, details) {
        document.getElementById('sfc_id_input').value      = sfcId;
        document.getElementById('sfcIdDisplay').textContent = sfcId;
        document.getElementById('sfcDetails').textContent   = details;
        actionModal.show();
    }

    // ── Button click → PIN modal first ───────────────────────────────────
    document.querySelectorAll('.actionBtn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            pendingSfcId      = this.getAttribute('data-sfc-id');
            pendingSfcDetails = this.getAttribute('data-sfc-details');

            // Reset PIN modal state
            pinInput.value = '';
            pinError.classList.add('d-none');
            pinModal.show();

            // Auto-focus PIN input after modal animates in
            document.getElementById('pinModal').addEventListener('shown.bs.modal', function handler() {
                pinInput.focus();
                this.removeEventListener('shown.bs.modal', handler);
            });
        });
    });

    // ── PIN submit (button click) ─────────────────────────────────────────
    function submitPin() {
        const pin = pinInput.value.trim();
        if (!pin) return;

        pinSubmitBtn.disabled = true;
        pinSubmitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Checking...';

        fetch('sfc.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'verify_pin=' + encodeURIComponent(pin)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                pinModal.hide();
                // Wait for PIN modal to fully close before opening action modal
                document.getElementById('pinModal').addEventListener('hidden.bs.modal', function handler() {
                    this.removeEventListener('hidden.bs.modal', handler);
                    openActionModal(pendingSfcId, pendingSfcDetails);
                });
            } else {
                pinError.classList.remove('d-none');
                pinInput.value = '';
                pinInput.focus();
            }
        })
        .catch(() => {
            pinError.innerHTML = '<i class="bi bi-wifi-off me-1"></i>Network error. Try again.';
            pinError.classList.remove('d-none');
        })
        .finally(() => {
            pinSubmitBtn.disabled = false;
            pinSubmitBtn.innerHTML = '<i class="bi bi-unlock-fill me-1"></i> Unlock';
        });
    }

    pinSubmitBtn.addEventListener('click', submitPin);

    // Allow Enter key in PIN input
    pinInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') submitPin();
    });

    // ── QR Scanner → direct (no PIN) ─────────────────────────────────────
    setInterval(() => {
        const tag       = document.activeElement.tagName;
        const modalOpen = document.querySelector('.modal.show');
        if (!modalOpen && !['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) {
            qrInput.focus();
        }
    }, 800);

    qrInput.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        const scanned = qrInput.value.trim();
        qrInput.value = '';
        if (!scanned) return;

        console.log('QR Scanned:', scanned);

        // Parse QR formats
        const matchLotCoil  = scanned.match(/Lot=([^;]+);COIL=([^;]+)/i);
        const matchPrefixed = scanned.match(/^sfc[_-]?(\d+)$/i);
        const matchPlain    = scanned.match(/^(\d+)$/);

        let btn = null;

        if (matchLotCoil) {
            const lot  = matchLotCoil[1].trim().toLowerCase();
            const coil = matchLotCoil[2].trim().toLowerCase();
            document.querySelectorAll('.actionBtn').forEach(b => {
                const bLot  = (b.getAttribute('data-sfc-lot')  || '').trim().toLowerCase();
                const bCoil = (b.getAttribute('data-sfc-coil') || '').trim().toLowerCase();
                if (bLot === lot && bCoil === coil) btn = b;
            });
        } else if (matchPrefixed) {
            btn = document.querySelector(`.actionBtn[data-sfc-id="${matchPrefixed[1]}"]`);
        } else if (matchPlain) {
            btn = document.querySelector(`.actionBtn[data-sfc-id="${matchPlain[1]}"]`);
        } else {
            showAlert('danger', '<i class="bi bi-exclamation-triangle-fill me-2"></i>QR not recognised: <strong>' + scanned + '</strong>');
            return;
        }

        if (btn) {
            hideAlert();
            // Scan bypasses PIN — open action modal directly
            openActionModal(btn.getAttribute('data-sfc-id'), btn.getAttribute('data-sfc-details'));
        } else {
            showAlert('danger', '<i class="bi bi-exclamation-triangle-fill me-2"></i>No matching SFC found for: <strong>' + scanned + '</strong>. Already processed or not in list.');
        }
    });

    function showAlert(type, msg) {
        qrAlert.className = 'alert alert-' + type + ' py-2 mb-3 shadow-sm d-flex align-items-center gap-2';
        qrAlert.innerHTML = msg;
        qrAlert.classList.remove('d-none');
        setTimeout(hideAlert, 5000);
    }

    function hideAlert() {
        qrAlert.classList.add('d-none');
    }
});
</script>

<?php include 'footer.php'; ?>
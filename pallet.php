<?php
// =============================================================
// pallet.php — Pallet Management UI (Upgraded)
// PLACEMENT: C:\Apache24\htdocs\slitting_system\pallet.php
//
// CHANGES FROM PREVIOUS VERSION:
//   • Pallet No is now entered MANUALLY by the operator
//     (format SFS-XXXX-XXX or SFS-XXXX-XXX (A)) — no auto-gen
//   • Max rolls: 8 (was 6)
//   • Matching constraints enforced: Customer + Ref No +
//     Product Type + Width must be identical across all rolls
//   • Uses PalletManager class for all business logic
//   • "Create Pallet" modal now collects pallet_no + first product
//   • Mismatch errors shown inline in the scan feedback area
// =============================================================

session_start();

if (!isset($_SESSION['role'])) { header("Location: login.php"); exit; }
if ($_SESSION['role'] !== 'slitting') { die("Access denied"); }

include 'config.php';
require_once 'PalletManager.php';

$pm           = new PalletManager($conn, $_SESSION['role']);
$performed_by = $_SESSION['role'];

// ── AJAX: validate pallet_no format before form submit ────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'validate_pallet_no') {
    header('Content-Type: application/json');
    $no = trim($_GET['pallet_no'] ?? '');
    echo json_encode($pm->validatePalletNo($no));
    exit;
}

// ── AJAX: product lookup by lot/coil/roll ─────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'lookup_product') {
    header('Content-Type: application/json');
    $lot  = trim($_GET['lot']  ?? '');
    $coil = trim($_GET['coil'] ?? '');
    $roll = trim($_GET['roll'] ?? '');
    if (!$lot || !$coil) { echo json_encode(['ok' => false, 'msg' => 'Incomplete data.']); exit; }

    $stmt = $conn->prepare("
        SELECT sp.id, sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
               sp.width, sp.actual_length, sp.length,
               sp.stock_counted, sp.status, sp.is_voided,
               sp.customer_name, sp.ref_no,
               pi.pallet_id, p.pallet_no
        FROM slitting_product sp
        LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
        LEFT JOIN pallets p       ON p.id = pi.pallet_id
        WHERE sp.lot_no = ? AND sp.coil_no = ? AND sp.roll_no = ?
          AND (sp.is_voided = 0 OR sp.is_voided IS NULL)
        LIMIT 1
    ");
    $stmt->bind_param("sss", $lot, $coil, $roll);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) { echo json_encode(['ok' => false, 'msg' => "Roll not found: {$lot} {$coil} {$roll}"]); exit; }
    echo json_encode(['ok' => true, 'product' => $row]);
    exit;
}

// ── POST: Create pallet (pallet_no only) ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_pallet') {
    header('Content-Type: application/json');
    $palletNo = trim($_POST['pallet_no'] ?? '');
    if (!$palletNo) {
        echo json_encode(['ok' => false, 'msg' => 'Pallet No is required.']);
        exit;
    }
    echo json_encode($pm->createPallet($palletNo));
    exit;
}

// ── POST: Add roll (AJAX) ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_roll') {
    header('Content-Type: application/json');
    $palletId  = intval($_POST['pallet_id']  ?? 0);
    $productId = intval($_POST['product_id'] ?? 0);
    if (!$palletId || !$productId) { echo json_encode(['ok' => false, 'msg' => 'Missing IDs.']); exit; }
    echo json_encode($pm->addRollToPallet($palletId, $productId));
    exit;
}

// ── POST: Remove roll (AJAX) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_roll') {
    header('Content-Type: application/json');
    $palletId  = intval($_POST['pallet_id']  ?? 0);
    $productId = intval($_POST['product_id'] ?? 0);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT status FROM pallets WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $palletId); $stmt->execute();
        $pal = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$pal || $pal['status'] !== 'building') throw new RuntimeException('Pallet cannot be modified.');
        $stmt = $conn->prepare("DELETE FROM pallet_items WHERE pallet_id = ? AND slitting_product_id = ?");
        $stmt->bind_param("ii", $palletId, $productId); $stmt->execute(); $stmt->close();
        // Re-sequence
        $stmt = $conn->prepare("SELECT id FROM pallet_items WHERE pallet_id = ? ORDER BY seq ASC");
        $stmt->bind_param("i", $palletId); $stmt->execute();
        $ids = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        foreach ($ids as $i => $r) { $s = $i + 1; $conn->query("UPDATE pallet_items SET seq=$s WHERE id={$r['id']}"); }
        $conn->commit();
        echo json_encode(['ok' => true, 'msg' => 'Roll removed.']);
    } catch (Throwable $e) { $conn->rollback(); echo json_encode(['ok' => false, 'msg' => $e->getMessage()]); }
    exit;
}

// ── POST: Send to QC ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_to_qc') {
    $palletId = intval($_POST['pallet_id'] ?? 0);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM pallet_items WHERE pallet_id = ?");
        $stmt->bind_param("i", $palletId); $stmt->execute();
        $cnt = (int)$stmt->get_result()->fetch_assoc()['cnt']; $stmt->close();
        if ($cnt < PalletManager::MIN_ROLLS) throw new RuntimeException('Pallet is empty.');
        $stmt = $conn->prepare("UPDATE pallets SET status='pending_qc' WHERE id=? AND status='building'");
        $stmt->bind_param("i", $palletId); $stmt->execute();
        if ($stmt->affected_rows === 0) throw new RuntimeException('Pallet is not in building state.');
        $stmt->close();
        $stmt = $conn->prepare("UPDATE slitting_product sp JOIN pallet_items pi ON pi.slitting_product_id=sp.id SET sp.status='WAITING', sp.date_out=NOW() WHERE pi.pallet_id=?");
        $stmt->bind_param("i", $palletId); $stmt->execute(); $stmt->close();
        $conn->commit();
        header("Location: pallet.php?success=sent_to_qc"); exit;
    } catch (Throwable $e) { $conn->rollback(); header("Location: pallet.php?pallet_id={$palletId}&error=".urlencode($e->getMessage())); exit; }
}

// ── POST: Deliver ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deliver_pallet') {
    $palletId = intval($_POST['pallet_id'] ?? 0);
    $result   = $pm->bundleDeliver($palletId);
    if ($result['ok']) { header("Location: pallet.php?success=delivered&pallet_no=".urlencode($result['pallet_no'] ?? '')); }
    else               { header("Location: pallet.php?error=".urlencode($result['msg'])); }
    exit;
}

// ── Page data ─────────────────────────────────────────────────
$activePalletId = intval($_GET['pallet_id'] ?? 0);
$activePallet   = $activePalletId ? $pm->getPallet($activePalletId) : null;
$activeItems    = $activePallet   ? $pm->getPalletItems($activePalletId) : [];

$openPallets = $conn->query(
    "SELECT p.*, COUNT(pi.id) AS item_count
     FROM pallets p LEFT JOIN pallet_items pi ON pi.pallet_id = p.id
     WHERE p.status = 'building'
     GROUP BY p.id ORDER BY p.created_at DESC LIMIT 30"
)->fetch_all(MYSQLI_ASSOC);

$allPallets = $conn->query(
    "SELECT p.*, COUNT(pi.id) AS item_count
     FROM pallets p LEFT JOIN pallet_items pi ON pi.pallet_id = p.id
     GROUP BY p.id ORDER BY p.created_at DESC LIMIT 60"
)->fetch_all(MYSQLI_ASSOC);

$page_title = 'Pallet Management';
include 'header.php';

$MAX = PalletManager::MAX_ROLLS;
?>
<style>
.pallet-sidebar{position:sticky;top:20px}
.roll-card{border:1px solid var(--bs-border-color);border-radius:8px;padding:12px 14px;
           background:#fff;margin-bottom:8px;transition:box-shadow .15s}
.roll-card:hover{box-shadow:0 2px 8px rgba(0,0,0,.1)}
.roll-seq{width:28px;height:28px;border-radius:50%;background:#0d6efd;color:#fff;
          display:inline-flex;align-items:center;justify-content:center;
          font-size:12px;font-weight:700;flex-shrink:0}
.pallet-progress{height:8px;border-radius:4px;background:#e9ecef;overflow:hidden}
.pallet-progress-bar{height:100%;border-radius:4px;background:#0d6efd;transition:width .3s}
.scan-flash{animation:flashBg .5s ease-out}
@keyframes flashBg{0%{background:#d1fae5}100%{background:transparent}}
.badge-building{background:#e0f2fe;color:#0369a1}
.badge-pending_qc{background:#fef3c7;color:#92400e}
.badge-approved{background:#dcfce7;color:#166534}
.badge-rejected{background:#fee2e2;color:#991b1b}
.badge-delivered{background:#d1fae5;color:#065f46}
.pallet-table td,.pallet-table th{vertical-align:middle;font-size:13px}
.pallet-table th{font-size:11px;text-transform:uppercase;letter-spacing:.5px}
.constraint-badge{font-size:10px;padding:2px 7px;border-radius:10px;
                  background:#f1f5f9;color:#475569;font-weight:600}
/* Pallet No input styling */
#palletNoInput{font-family:monospace;letter-spacing:.5px;font-size:15px}
#palletNoInput.is-valid{border-color:#198754}
#palletNoInput.is-invalid{border-color:#dc3545}
.pallet-no-hint{font-size:11px;color:#6c757d;margin-top:3px}
.mismatch-detail{font-size:11px;background:#fee2e2;border-radius:6px;
                 padding:8px 10px;color:#991b1b;margin-top:6px}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-archive me-2"></i>Pallet Management</h2>
    <button type="button" class="btn btn-success shadow-sm"
            data-bs-toggle="modal" data-bs-target="#createPalletModal">
        <i class="bi bi-plus-lg me-1"></i> New Pallet
    </button>
</div>

<?php if (isset($_GET['success'])): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i>
    <?php
    $m = ['created'=>'Pallet created.','sent_to_qc'=>'Pallet sent to QC.','delivered'=>'Pallet '.(htmlspecialchars($_GET['pallet_no'] ?? '')).' delivered successfully.'];
    echo $m[$_GET['success']] ?? 'Done.';
    ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-triangle me-2"></i>
    <?= htmlspecialchars(urldecode($_GET['error'])) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- LEFT: Active pallet scanner -->
    <div class="col-md-7">
        <?php if ($activePallet && $activePallet['status'] === 'building'): ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <div>
                    <span class="fw-bold"><?= htmlspecialchars($activePallet['pallet_no']) ?></span>
                    <small class="ms-2 opacity-75">
                        <?= htmlspecialchars($activePallet['product_type']) ?>
                        · <?= number_format((float)$activePallet['width']) ?>mm
                    </small>
                </div>
                <span class="badge bg-white text-primary" id="rollCountBadge">
                    <?= count($activeItems) ?> / <?= $MAX ?> rolls
                </span>
            </div>

            <!-- Matching constraints — shown only after first roll sets them -->
            <?php if (!empty(trim($activePallet['customer_name']))): ?>
            <div class="px-3 pt-2 pb-1 border-bottom d-flex flex-wrap gap-2 align-items-center">
                <span class="constraint-badge">
                    <i class="bi bi-person-check me-1"></i>
                    <?= htmlspecialchars($activePallet['customer_name']) ?>
                </span>
                <span class="constraint-badge">
                    <i class="bi bi-hash me-1"></i>
                    <?= htmlspecialchars($activePallet['ref_no']) ?>
                </span>
                <span class="constraint-badge">
                    <i class="bi bi-tag me-1"></i>
                    <?= htmlspecialchars($activePallet['product_type']) ?>
                </span>
                <span class="constraint-badge">
                    <i class="bi bi-arrows-expand me-1"></i>
                    <?= number_format((float)$activePallet['width']) ?> mm
                </span>
                <small class="text-muted align-self-center" style="font-size:10px;">
                    All rolls must match these values
                </small>
            </div>
            <?php else: ?>
            <div class="px-3 py-2 border-bottom">
                <small class="text-muted">
                    <i class="bi bi-qr-code-scan me-1"></i>
                    Scan the first roll — its Customer, Ref No, Product Type and Width
                    will lock as the constraints for this pallet.
                </small>
            </div>
            <?php endif; ?>

            <div class="card-body p-4">
                <div class="pallet-progress mb-3">
                    <div class="pallet-progress-bar" id="palletProgressBar"
                         style="width:<?= (count($activeItems) / $MAX * 100) ?>%"></div>
                </div>

                <div class="alert alert-info py-2 mb-3">
                    <i class="bi bi-qr-code-scan me-1"></i>
                    Scan a product QR code, or type Lot + Coil + Roll below.
                </div>

                <!-- Manual lookup -->
                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <input type="text" id="manLot"  class="form-control form-control-sm"
                               placeholder="Lot No" autocomplete="off">
                    </div>
                    <div class="col-md-3">
                        <input type="text" id="manCoil" class="form-control form-control-sm"
                               placeholder="Coil No" autocomplete="off">
                    </div>
                    <div class="col-md-3">
                        <input type="text" id="manRoll" class="form-control form-control-sm"
                               placeholder="Roll No" autocomplete="off">
                    </div>
                    <div class="col-md-3">
                        <button type="button" class="btn btn-primary btn-sm w-100"
                                onclick="manualLookup()">
                            <i class="bi bi-search me-1"></i> Find & Add
                        </button>
                    </div>
                </div>

                <div id="scanFeedback" class="mb-3" style="min-height:40px;"></div>

                <div id="rollList">
                    <?php foreach ($activeItems as $item): ?>
                    <div class="roll-card d-flex align-items-center gap-3"
                         id="rollCard<?= $item['product_id'] ?>">
                        <span class="roll-seq"><?= $item['seq'] ?></span>
                        <div class="flex-grow-1">
                            <div class="fw-bold small">
                                <?= htmlspecialchars($item['lot_no']) ?>
                                <?= htmlspecialchars($item['coil_no']) ?>
                                – <?= str_replace('R','R-',htmlspecialchars($item['roll_no'])) ?>
                            </div>
                            <div class="text-muted" style="font-size:11px;">
                                <?= htmlspecialchars($item['product']) ?> |
                                <?= number_format((float)$item['width']) ?>mm |
                                <?= number_format((float)($item['actual_length'] ?: $item['length']), 1) ?>m
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-danger btn-sm"
                                onclick="removeRoll(<?= $activePalletId ?>, <?= $item['product_id'] ?>, this)">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                    <?php endforeach; ?>

                    <?php for ($s = count($activeItems) + 1; $s <= $MAX; $s++): ?>
                    <div class="roll-card d-flex align-items-center gap-3 text-muted slot-empty"
                         id="slot<?= $s ?>">
                        <span class="roll-seq" style="background:#dee2e6;color:#6c757d;">
                            <?= $s ?>
                        </span>
                        <span style="font-size:13px;">Empty slot <?= $s ?></span>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="card-footer bg-light d-flex justify-content-between align-items-center">
                <span class="text-muted small">
                    Created: <?= date('d M Y H:i', strtotime($activePallet['created_at'])) ?>
                </span>
                <div class="d-flex gap-2">
                    <a href="pallet.php" class="btn btn-outline-secondary btn-sm">Close panel</a>
                    <form method="post">
                        <input type="hidden" name="action"    value="send_to_qc">
                        <input type="hidden" name="pallet_id" value="<?= $activePalletId ?>">
                        <button type="submit" class="btn btn-warning btn-sm fw-bold"
                                id="sendToQcBtn"
                                <?= count($activeItems) < 1 ? 'disabled' : '' ?>
                                onclick="return confirm('Send pallet to QC? No more rolls can be added after this.')">
                            <i class="bi bi-send me-1"></i> Send to QC
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <?php elseif ($activePallet): ?>
        <div class="alert alert-secondary">
            <strong><?= htmlspecialchars($activePallet['pallet_no']) ?></strong> —
            <span class="badge badge-<?= $activePallet['status'] ?>">
                <?= strtoupper(str_replace('_',' ',$activePallet['status'])) ?>
            </span>
            — read-only.
            <a href="pallet.php" class="ms-2">← Back to list</a>
        </div>

        <?php else: ?>
        <div class="card shadow-sm border-0 text-center py-5">
            <div class="text-muted mb-3">
                <i class="bi bi-archive" style="font-size:3rem;"></i>
            </div>
            <h5 class="text-muted">No pallet selected</h5>
            <p class="text-muted small">
                Select an open pallet from the right, or create a new one.
            </p>
            <button type="button" class="btn btn-success mt-2"
                    data-bs-toggle="modal" data-bs-target="#createPalletModal">
                <i class="bi bi-plus-lg me-1"></i> Create New Pallet
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- RIGHT: Sidebar -->
    <div class="col-md-5 pallet-sidebar">
        <?php if (!empty($openPallets)): ?>
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-dark text-white fw-bold py-2">
                <i class="bi bi-list-task me-2"></i>Open Pallets
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($openPallets as $op): ?>
                <a href="pallet.php?pallet_id=<?= $op['id'] ?>"
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center
                          <?= ($op['id'] == $activePalletId) ? 'active' : '' ?>">
                    <div>
                        <div class="fw-bold small"><?= htmlspecialchars($op['pallet_no']) ?></div>
                        <div style="font-size:10px;"
                             class="<?= ($op['id'] == $activePalletId) ? 'text-white-50' : 'text-muted' ?>">
                            <?= htmlspecialchars($op['customer_name']) ?>
                            · <?= htmlspecialchars($op['product_type']) ?>
                            · <?= number_format((float)$op['width']) ?>mm
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span style="font-size:11px;"><?= $op['item_count'] ?>/<?= $MAX ?></span>
                        <div class="pallet-progress" style="width:60px;">
                            <div class="pallet-progress-bar"
                                 style="width:<?= ($op['item_count'] / $MAX * 100) ?>%;
                                        <?= ($op['id'] == $activePalletId) ? 'background:#fff;' : '' ?>">
                            </div>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-dark text-white fw-bold py-2">
                <i class="bi bi-table me-2"></i>All Pallets (Recent 60)
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover pallet-table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Pallet No</th>
                            <th>Status</th>
                            <th>Rolls</th>
                            <th>Customer</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($allPallets as $pal): ?>
                    <tr>
                        <td class="fw-bold" style="font-family:monospace;font-size:12px;">
                            <?= htmlspecialchars($pal['pallet_no']) ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= $pal['status'] ?>">
                                <?= strtoupper(str_replace('_',' ',$pal['status'])) ?>
                            </span>
                        </td>
                        <td><?= $pal['item_count'] ?>/<?= $MAX ?></td>
                        <td class="text-muted" style="font-size:11px;">
                            <?= htmlspecialchars($pal['customer_name']) ?>
                        </td>
                        <td>
                            <?php if ($pal['status'] === 'building'): ?>
                            <a href="pallet.php?pallet_id=<?= $pal['id'] ?>"
                               class="btn btn-outline-primary btn-sm">Open</a>
                            <?php elseif ($pal['status'] === 'approved'): ?>
                            <form method="post" class="d-inline"
                                  onsubmit="return confirm('Mark entire pallet as DELIVERED?')">
                                <input type="hidden" name="action"    value="deliver_pallet">
                                <input type="hidden" name="pallet_id" value="<?= $pal['id'] ?>">
                                <button class="btn btn-success btn-sm">
                                    <i class="bi bi-truck me-1"></i>Deliver
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ── CREATE PALLET MODAL ───────────────────────────────────── -->
<div class="modal fade" id="createPalletModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header" style="background:#0f2744;">
        <h5 class="modal-title text-white">
          <i class="bi bi-plus-circle me-2"></i>New Pallet
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pb-2">

        <div class="mb-3">
          <label class="form-label fw-bold mb-1">
            Pallet Serial No <span class="text-danger">*</span>
          </label>
          <input type="text" id="palletNoInput" class="form-control"
                 placeholder="SFS-0024-001 or SFS-0024-001 (A)"
                 autocomplete="off" spellcheck="false"
                 style="font-family:monospace;letter-spacing:.4px;">
          <div class="form-text">
            <code>SFS-XXXX-XXX</code> or <code>SFS-XXXX-XXX (A)</code>
          </div>
          <div id="palletNoFeedback" class="mt-1" style="font-size:12px;min-height:18px;"></div>
        </div>

        <div class="alert alert-info py-2 mb-0" style="font-size:12px;">
          <i class="bi bi-info-circle me-1"></i>
          The <strong>first roll</strong> you scan after creating will set the
          Customer, Ref No, Product Type and Width — all subsequent rolls must match.
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success btn-sm" id="createPalletBtn" disabled
                onclick="submitCreatePallet()">
          <i class="bi bi-plus-lg me-1"></i> Create Pallet
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Hidden QR scanner input -->
<input id="qrScanInput" type="text" inputmode="none"
       style="position:fixed;left:-9999px;opacity:0;" autofocus>

<script>
const PALLET_ID = <?= $activePalletId ?: 'null' ?>;
const MAX_ROLLS = <?= $MAX ?>;
let   rollCount = <?= count($activeItems) ?>;
const qrInput   = document.getElementById('qrScanInput');

// ── Keep QR input focused ──────────────────────────────────────
setInterval(() => {
    const a = document.activeElement;
    const modalOpen = document.querySelector('.modal.show');
    if (!modalOpen && !['INPUT','TEXTAREA','SELECT'].includes(a.tagName)) {
        qrInput.focus();
    }
}, 600);

qrInput.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter') return;
    const raw = this.value.trim(); this.value = '';
    if (!raw || !PALLET_ID) return;
    processQR(raw);
});

// ── QR parse ───────────────────────────────────────────────────
function parseQR(raw) {
    const parts = {};
    raw.split(';').forEach(p => {
        const idx = p.indexOf('=');
        if (idx > -1) parts[p.substring(0, idx).trim().toUpperCase()]
            = decodeURIComponent(p.substring(idx + 1).trim());
    });
    return { lot: parts.LOT || '', coil: parts.COIL || '', roll: parts.ROLL || '' };
}
async function processQR(raw) {
    const { lot, coil, roll } = parseQR(raw);
    if (!lot || !coil) { showFeedback('Could not parse QR: ' + escHtml(raw), false); return; }
    await lookupAndAdd(lot, coil, roll);
}

// ── Manual lookup ─────────────────────────────────────────────
async function manualLookup() {
    const lot  = document.getElementById('manLot').value.trim();
    const coil = document.getElementById('manCoil').value.trim();
    const roll = document.getElementById('manRoll').value.trim();
    if (!lot || !coil || !roll) { showFeedback('Enter Lot, Coil and Roll No.', false); return; }
    await lookupAndAdd(lot, coil, roll);
    ['manLot','manCoil','manRoll'].forEach(id => document.getElementById(id).value = '');
}

async function lookupAndAdd(lot, coil, roll) {
    if (!PALLET_ID) return;
    if (rollCount >= MAX_ROLLS) { showFeedback(`Pallet is full (${MAX_ROLLS}/${MAX_ROLLS}).`, false); return; }

    // Step 1: lookup
    let lk;
    try {
        lk = await fetch(`pallet.php?ajax=lookup_product&lot=${enc(lot)}&coil=${enc(coil)}&roll=${enc(roll)}`)
                 .then(r => r.json());
    } catch { showFeedback('Network error during lookup.', false); return; }
    if (!lk.ok) { showFeedback(lk.msg, false); return; }

    const p = lk.product;
    if (p.is_voided == 1)     { showFeedback('This roll has been voided.', false); return; }
    if (p.stock_counted != 1) { showFeedback(`Roll ${lot} ${coil} ${roll} — actual length not saved yet.`, false); return; }
    if (p.pallet_id)          { showFeedback(`Already on pallet ${escHtml(p.pallet_no)}.`, false); return; }

    // Step 2: add (server validates matching constraints)
    const fd = new FormData();
    fd.append('action','add_roll'); fd.append('pallet_id', PALLET_ID); fd.append('product_id', p.id);
    let ad;
    try { ad = await fetch('pallet.php', {method:'POST', body:fd}).then(r => r.json()); }
    catch { showFeedback('Network error while adding roll.', false); return; }

    if (!ad.ok) {
        // Surface mismatch detail clearly
        showFeedback(ad.msg, false);
        return;
    }

    rollCount = ad.roll_count;
    addRollCard(ad.seq, p);
    updateProgress(rollCount);
    showFeedback(`✓ Added: ${escHtml(lot)} ${escHtml(coil)} – R${escHtml(roll)} (slot ${ad.seq})`, true);
}

function addRollCard(seq, p) {
    document.getElementById('slot' + seq)?.remove();
    const len  = p.actual_length > 0 ? p.actual_length : p.length;
    const html = `<div class="roll-card d-flex align-items-center gap-3 scan-flash" id="rollCard${p.id}">
        <span class="roll-seq">${seq}</span>
        <div class="flex-grow-1">
            <div class="fw-bold small">${escHtml(p.lot_no)} ${escHtml(p.coil_no)} – ${escHtml(p.roll_no.replace('R','R-'))}</div>
            <div class="text-muted" style="font-size:11px;">
                ${escHtml(p.product)} | ${(+p.width).toFixed(0)}mm | ${(+len).toFixed(1)}m
            </div>
        </div>
        <button type="button" class="btn btn-outline-danger btn-sm"
                onclick="removeRoll(${PALLET_ID}, ${p.id}, this)">
            <i class="bi bi-x"></i>
        </button>
    </div>`;
    document.getElementById('rollList').insertAdjacentHTML('beforeend', html);
}

async function removeRoll(palletId, productId, btn) {
    if (!confirm('Remove this roll from the pallet?')) return;
    const fd = new FormData();
    fd.append('action','remove_roll'); fd.append('pallet_id',palletId); fd.append('product_id',productId);
    const d = await fetch('pallet.php',{method:'POST',body:fd}).then(r=>r.json());
    if (d.ok) {
        document.getElementById('rollCard'+productId)?.remove();
        rollCount = Math.max(0, rollCount - 1);
        updateProgress(rollCount);
        const slot = rollCount + 1;
        document.getElementById('rollList').insertAdjacentHTML('beforeend',
            `<div class="roll-card d-flex align-items-center gap-3 text-muted slot-empty" id="slot${slot}">
                <span class="roll-seq" style="background:#dee2e6;color:#6c757d;">${slot}</span>
                <span style="font-size:13px;">Empty slot ${slot}</span>
            </div>`);
        reNumberSeq();
        showFeedback('Roll removed.', true);
    } else { showFeedback(d.msg, false); }
}

function reNumberSeq() {
    let n = 1;
    document.querySelectorAll('#rollList .roll-seq').forEach(el => {
        if (!el.closest('.slot-empty')) el.textContent = n++;
    });
}
function updateProgress(count) {
    document.getElementById('palletProgressBar').style.width = (count/MAX_ROLLS*100)+'%';
    document.getElementById('rollCountBadge').textContent    = count+' / '+MAX_ROLLS+' rolls';
    const btn = document.getElementById('sendToQcBtn');
    if (btn) btn.disabled = count < 1;
}
function showFeedback(msg, ok) {
    document.getElementById('scanFeedback').innerHTML =
        `<div class="alert alert-${ok?'success':'danger'} py-2 mb-0 d-flex align-items-center gap-2">
           <i class="bi bi-${ok?'check-circle-fill':'exclamation-triangle-fill'}"></i>
           <span>${msg}</span>
         </div>`;
    setTimeout(() => { document.getElementById('scanFeedback').innerHTML=''; }, 5000);
}

// ── Create pallet modal logic ─────────────────────────────────
let palletNoValid = false;
let palletNoTimer;

// Reset state each time the modal opens
document.getElementById('createPalletModal')?.addEventListener('show.bs.modal', () => {
    palletNoValid = false;
    const inp = document.getElementById('palletNoInput');
    inp.value = '';
    inp.classList.remove('is-valid','is-invalid');
    document.getElementById('palletNoFeedback').innerHTML = '';
    document.getElementById('createPalletBtn').disabled = true;
    setTimeout(() => inp.focus(), 300);
});

// Live pallet_no validation (debounced 500 ms)
document.getElementById('palletNoInput')?.addEventListener('input', function() {
    clearTimeout(palletNoTimer);
    palletNoValid = false;
    document.getElementById('createPalletBtn').disabled = true;
    const val = this.value.trim();
    if (!val) { document.getElementById('palletNoFeedback').innerHTML = ''; this.classList.remove('is-valid','is-invalid'); return; }
    palletNoTimer = setTimeout(async () => {
        try {
            const r = await fetch(`pallet.php?ajax=validate_pallet_no&pallet_no=${enc(val)}`).then(x => x.json());
            const fb  = document.getElementById('palletNoFeedback');
            this.classList.remove('is-valid','is-invalid');
            if (r.ok) {
                fb.innerHTML = `<span class="text-success"><i class="bi bi-check-circle me-1"></i>Format valid &amp; available</span>`;
                this.classList.add('is-valid');
                palletNoValid = true;
                document.getElementById('createPalletBtn').disabled = false;
            } else {
                fb.innerHTML = `<span class="text-danger"><i class="bi bi-x-circle me-1"></i>${escHtml(r.msg)}</span>`;
                this.classList.add('is-invalid');
            }
        } catch (_) { /* network hiccup — leave neutral */ }
    }, 500);
});

// Also allow Enter key inside the input to submit
document.getElementById('palletNoInput')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && palletNoValid) submitCreatePallet();
});

async function submitCreatePallet() {
    const palletNo = document.getElementById('palletNoInput').value.trim();
    if (!palletNo || !palletNoValid) return;
    const btn = document.getElementById('createPalletBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Creating…';
    const fd = new FormData();
    fd.append('action', 'create_pallet');
    fd.append('pallet_no', palletNo);
    let r;
    try { r = await fetch('pallet.php', {method:'POST', body:fd}).then(x => x.json()); }
    catch (_) { r = {ok: false, msg: 'Network error. Please try again.'}; }
    if (r.ok) {
        window.location.href = `pallet.php?pallet_id=${r.pallet_id}&success=created`;
    } else {
        document.getElementById('palletNoFeedback').innerHTML =
            `<span class="text-danger"><i class="bi bi-x-circle me-1"></i>${escHtml(r.msg)}</span>`;
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plus-lg me-1"></i> Create Pallet';
    }
}

// ── Utilities ──────────────────────────────────────────────────
function escHtml(s) {
    return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function enc(s) { return encodeURIComponent(s); }
</script>

<?php include 'footer.php'; ?>
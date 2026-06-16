<?php
// =============================================================
// pallet.php  —  v7 (Flexible Manual Entry Format)
// NEW: The manual / scanned input now accepts TWO formats:
//   A) KEY=value pairs (camera QR / hardware scanner):
//        LOT=826277;COIL=FK-1;ROLL=R1
//   B) Plain space-separated values (typed by hand):
//        826277 FK-1 R1
//   parseQR() auto-detects which one it received.
//
// Retained from v6: triple duplicate-scan guard (isAdding lock,
//   same-pallet drop, slot-present drop); scanner cache-busted
//   (?v=7).
// Retained from v5: no first-roll reload; constraint badges live.
// Retained from v4: client guard only blocks rolls on a DIFFERENT
//   pallet.
// Retained from v3: Est. Weight display (per-roll + running total).
//   wgt = (actual_length_m × width_mm / 1000) × std_weight
// =============================================================

session_start();

if (!isset($_SESSION['role'])) { header("Location: login.php"); exit; }
if ($_SESSION['role'] !== 'slitting') { die("Access denied"); }

include 'config.php';
require_once 'PalletManager.php';

$pm           = new PalletManager($conn, $_SESSION['role']);
$performed_by = $_SESSION['role'];

// ── AJAX: validate pallet_no ──────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'validate_pallet_no') {
    header('Content-Type: application/json');
    echo json_encode($pm->validatePalletNo(trim($_GET['pallet_no'] ?? '')));
    exit;
}

// ── AJAX: product lookup (now includes std_weight) ────────────
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
               pi.pallet_id, p.pallet_no,
               COALESCE(sw.std_weight, 0) AS std_weight
        FROM slitting_product sp
        LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
        LEFT JOIN pallets p       ON p.id = pi.pallet_id
        LEFT JOIN std_wgt sw      ON sw.product_code = sp.product
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

// ── POST: Create pallet ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_pallet') {
    header('Content-Type: application/json');
    $palletNo = trim($_POST['pallet_no'] ?? '');
    if (!$palletNo) { echo json_encode(['ok' => false, 'msg' => 'Pallet No is required.']); exit; }
    echo json_encode($pm->createPallet($palletNo));
    exit;
}

// ── POST: Add roll ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_roll') {
    header('Content-Type: application/json');
    $palletId  = intval($_POST['pallet_id']  ?? 0);
    $productId = intval($_POST['product_id'] ?? 0);
    if (!$palletId || !$productId) { echo json_encode(['ok' => false, 'msg' => 'Missing IDs.']); exit; }
    echo json_encode($pm->addRollToPallet($palletId, $productId));
    exit;
}

// ── POST: Remove roll ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_roll') {
    header('Content-Type: application/json');
    $palletId  = intval($_POST['pallet_id']  ?? 0);
    $productId = intval($_POST['product_id'] ?? 0);
    if (!$palletId || !$productId) { echo json_encode(['ok' => false, 'msg' => 'Missing IDs.']); exit; }
    echo json_encode($pm->removeRollFromPallet($palletId, $productId));
    exit;
}

// ── POST: Delete entire pallet ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_pallet') {
    $palletId = intval($_POST['pallet_id'] ?? 0);
    $result   = $pm->deletePallet($palletId);
    if ($result['ok']) {
        header("Location: pallet.php?success=pallet_deleted&pallet_no=" . urlencode($result['pallet_no'] ?? ''));
    } else {
        header("Location: pallet.php?error=" . urlencode($result['msg']));
    }
    exit;
}

// ── POST: Send to QC (first submission) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_to_qc') {
    $palletId = intval($_POST['pallet_id'] ?? 0);
    $result   = $pm->sendToQC($palletId);
    if ($result['ok']) { header("Location: pallet.php?success=sent_to_qc"); }
    else               { header("Location: pallet.php?pallet_id={$palletId}&error=" . urlencode($result['msg'])); }
    exit;
}

// ── POST: Reopen rejected pallet for editing ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reopen_pallet') {
    $palletId = intval($_POST['pallet_id'] ?? 0);
    $result   = $pm->reopenRejectedPallet($palletId);
    if ($result['ok']) {
        header("Location: pallet.php?pallet_id={$palletId}&success=reopened");
    } else {
        header("Location: pallet.php?pallet_id={$palletId}&error=" . urlencode($result['msg']));
    }
    exit;
}

// ── POST: Resubmit edited pallet to QC ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resubmit_to_qc') {
    $palletId = intval($_POST['pallet_id'] ?? 0);
    $result   = $pm->resubmitToQC($palletId);
    if ($result['ok']) { header("Location: pallet.php?success=resubmitted"); }
    else               { header("Location: pallet.php?pallet_id={$palletId}&error=" . urlencode($result['msg'])); }
    exit;
}

// ── POST: Deliver ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deliver_pallet') {
    $palletId = intval($_POST['pallet_id'] ?? 0);
    $result   = $pm->bundleDeliver($palletId);
    if ($result['ok']) { header("Location: pallet.php?success=delivered&pallet_no=" . urlencode($result['pallet_no'] ?? '')); }
    else               { header("Location: pallet.php?error=" . urlencode($result['msg'])); }
    exit;
}

// ── Page data ─────────────────────────────────────────────────
$activePalletId = intval($_GET['pallet_id'] ?? 0);
$activePallet   = $activePalletId ? $pm->getPallet($activePalletId) : null;

// Extended getPalletItems with std_weight for weight calculation
function getPalletItemsWithWeight(mysqli $conn, int $pallet_id): array {
    $stmt = $conn->prepare("
        SELECT pi.seq, pi.added_at,
               sp.id AS product_id,
               sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
               sp.width, sp.length, sp.actual_length, sp.status,
               sp.customer_name, sp.ref_no,
               COALESCE(sw.std_weight, 0) AS std_weight
        FROM pallet_items pi
        JOIN slitting_product sp ON sp.id = pi.slitting_product_id
        LEFT JOIN std_wgt sw     ON sw.product_code = sp.product
        WHERE pi.pallet_id = ?
        ORDER BY pi.seq ASC
    ");
    $stmt->bind_param("i", $pallet_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Helper: calculate estimated weight for one roll
function calcEstWeight(float $lengthM, float $widthMm, float $stdWeight): float {
    if ($lengthM <= 0 || $widthMm <= 0 || $stdWeight <= 0) return 0.0;
    return ($lengthM * $widthMm / 1000) * $stdWeight;
}

$activeItems = $activePallet ? getPalletItemsWithWeight($conn, $activePalletId) : [];

// Build a lookup: seq → item, so PHP can render all 8 slots in order
$itemsBySeq = [];
foreach ($activeItems as $item) {
    $itemsBySeq[(int)$item['seq']] = $item;
}

// Pre-calculate total Est. Weight for PHP-rendered slots
$totalEstWgt = 0.0;
foreach ($activeItems as $item) {
    $len = (float)($item['actual_length'] ?: $item['length']);
    $totalEstWgt += calcEstWeight($len, (float)$item['width'], (float)$item['std_weight']);
}

// Pallets in 'building' state (open)
$openPallets = $conn->query(
    "SELECT p.*, COUNT(pi.id) AS item_count
     FROM pallets p LEFT JOIN pallet_items pi ON pi.pallet_id = p.id
     WHERE p.status = 'building'
     GROUP BY p.id ORDER BY p.created_at DESC LIMIT 30"
)->fetch_all(MYSQLI_ASSOC);

// Pallets rejected by QC
$rejectedPallets = $conn->query(
    "SELECT p.*, COUNT(pi.id) AS item_count
     FROM pallets p LEFT JOIN pallet_items pi ON pi.pallet_id = p.id
     WHERE p.status = 'rejected'
     GROUP BY p.id ORDER BY p.rejected_at DESC, p.updated_at DESC LIMIT 20"
)->fetch_all(MYSQLI_ASSOC);

$allPallets = $conn->query(
    "SELECT p.*, COUNT(pi.id) AS item_count
     FROM pallets p LEFT JOIN pallet_items pi ON pi.pallet_id = p.id
     GROUP BY p.id ORDER BY p.created_at DESC LIMIT 60"
)->fetch_all(MYSQLI_ASSOC);

$page_title = 'Pallet Management';
include 'header.php';

$MAX = PalletManager::MAX_ROLLS;

$isBuilding = $activePallet && $activePallet['status'] === 'building';
$isRejected = $activePallet && $activePallet['status'] === 'rejected';
$isReadOnly = $activePallet && !in_array($activePallet['status'], ['building', 'rejected']);
?>
<style>
/* ── Layout ── */
.pallet-sidebar   { position:sticky; top:20px; }

/* ── Slot cards ── */
.slot-card {
    border: 1px solid var(--bs-border-color);
    border-radius: 8px;
    padding: 12px 14px;
    background: #fff;
    margin-bottom: 8px;
    transition: box-shadow .15s, background .15s;
    min-height: 68px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.slot-card.slot-empty {
    background: #f8f9fa;
    border-style: dashed;
    color: #adb5bd;
}
.slot-card.scan-flash { animation: flashBg .5s ease-out; }
@keyframes flashBg { 0%{background:#d1fae5} 100%{background:#fff} }

/* Sequence bubble */
.roll-seq {
    width: 28px; height: 28px; border-radius: 50%;
    background: #0d6efd; color: #fff;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700; flex-shrink: 0;
}
.slot-empty .roll-seq { background: #dee2e6; color: #6c757d; }

/* ── Weight chip ── */
.wgt-chip {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fcd34d;
    border-radius: 10px;
    padding: 2px 8px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
    flex-shrink: 0;
}
.wgt-chip i { font-size: 10px; }

/* ── Total weight summary bar ── */
.weight-summary-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: linear-gradient(135deg, #fef3c7 0%, #fffbeb 100%);
    border: 1px solid #fcd34d;
    border-radius: 8px;
    padding: 10px 14px;
    margin-bottom: 14px;
    flex-wrap: wrap;
    gap: 8px;
}
.weight-summary-bar .wgt-label {
    font-size: 12px;
    font-weight: 700;
    color: #78350f;
    display: flex;
    align-items: center;
    gap: 6px;
}
.weight-summary-bar .wgt-total {
    font-size: 20px;
    font-weight: 800;
    color: #92400e;
    font-family: 'Courier New', monospace;
}
.weight-summary-bar .wgt-unit {
    font-size: 13px;
    font-weight: 600;
    color: #b45309;
    margin-left: 2px;
}
.weight-summary-bar .wgt-avg {
    font-size: 11px;
    color: #92400e;
    background: rgba(255,255,255,.6);
    border-radius: 6px;
    padding: 3px 9px;
}

/* Progress bar */
.pallet-progress     { height:8px; border-radius:4px; background:#e9ecef; overflow:hidden; }
.pallet-progress-bar { height:100%; border-radius:4px; background:#0d6efd; transition:width .3s; }

/* Status badges */
.badge-building   { background:#e0f2fe; color:#0369a1; }
.badge-pending_qc { background:#fef3c7; color:#92400e; }
.badge-approved   { background:#dcfce7; color:#166534; }
.badge-rejected   { background:#fee2e2; color:#991b1b; }
.badge-delivered  { background:#d1fae5; color:#065f46; }

.pallet-table td, .pallet-table th { vertical-align:middle; font-size:13px; }
.pallet-table th  { font-size:11px; text-transform:uppercase; letter-spacing:.5px; }

.constraint-badge { font-size:10px; padding:2px 7px; border-radius:10px;
                    background:#f1f5f9; color:#475569; font-weight:600; }

/* Rejected banner */
.rejected-banner   { background:#fee2e2; border:1.5px solid #fca5a5; border-radius:10px;
                     padding:14px 18px; margin-bottom:16px; }
.rejected-banner h6 { color:#991b1b; font-weight:700; margin:0 0 4px; }
.rejected-banner p  { color:#7f1d1d; font-size:12px; margin:0; }

/* Edit mode label */
.edit-mode-pill { display:inline-flex; align-items:center; gap:5px; font-size:11px;
                  font-weight:700; padding:3px 10px; border-radius:20px;
                  background:#fef3c7; color:#92400e; border:1px solid #fcd34d; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-archive me-2"></i>Pallet Management</h2>
    <button type="button" class="btn btn-success shadow-sm"
            data-bs-toggle="modal" data-bs-target="#createPalletModal">
        <i class="bi bi-plus-lg me-1"></i> New Pallet
    </button>
</div>

<!-- Alerts -->
<?php
$successMsgs = [
    'created'        => 'Pallet created. Scan the first roll to lock its constraints.',
    'sent_to_qc'     => 'Pallet submitted to QC successfully.',
    'resubmitted'    => 'Pallet re-submitted to QC after editing.',
    'reopened'       => 'Pallet reopened for editing — remove defective rolls and add replacements.',
    'delivered'      => 'Pallet ' . htmlspecialchars($_GET['pallet_no'] ?? '') . ' delivered.',
    'pallet_deleted' => 'Pallet ' . htmlspecialchars($_GET['pallet_no'] ?? '') . ' deleted. Rolls returned to stock.',
];
if (isset($_GET['success'])): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i>
    <?= $successMsgs[$_GET['success']] ?? 'Done.' ?>
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

<!-- Rejected pallets notification strip -->
<?php if (!empty($rejectedPallets) && !$activePalletId): ?>
<div class="rejected-banner">
    <h6><i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?= count($rejectedPallets) ?> Rejected Pallet<?= count($rejectedPallets) > 1 ? 's' : '' ?> Need Attention
    </h6>
    <p>QC has rejected the following pallets. Click "Edit" to reopen and fix them.</p>
    <div class="d-flex flex-wrap gap-2 mt-2">
        <?php foreach ($rejectedPallets as $rp): ?>
        <a href="pallet.php?pallet_id=<?= $rp['id'] ?>"
           class="btn btn-danger btn-sm">
            <i class="bi bi-pencil me-1"></i>
            <?= htmlspecialchars($rp['pallet_no']) ?>
            (<?= $rp['item_count'] ?> roll<?= $rp['item_count'] != 1 ? 's' : '' ?>)
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ═══════════════════════════════════════════════════════
         LEFT: Active pallet panel
    ═══════════════════════════════════════════════════════════ -->
    <div class="col-md-7">

        <?php if ($isBuilding): ?>
        <!-- BUILDING STATE -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <div>
                    <span class="fw-bold"><?= htmlspecialchars($activePallet['pallet_no']) ?></span>
                    <?php if (($activePallet['edit_count'] ?? 0) > 0): ?>
                    <span class="edit-mode-pill ms-2">
                        <i class="bi bi-pencil-fill"></i>
                        EDIT #<?= $activePallet['edit_count'] ?>
                    </span>
                    <?php endif; ?>
                </div>
                <span class="badge bg-white text-primary" id="rollCountBadge">
                    <?= count($activeItems) ?> / <?= $MAX ?> rolls
                </span>
            </div>

            <!-- Constraint badges -->
            <!-- NOTE: this header carries id="constraintHeader" so JS can
                 fill it live after the first roll, removing the need for a
                 full page reload before scanning the second roll. -->
            <?php if (!empty(trim($activePallet['customer_name'] ?? ''))): ?>
            <div id="constraintHeader" class="px-3 pt-2 pb-1 border-bottom d-flex flex-wrap gap-2 align-items-center">
                <span class="constraint-badge"><i class="bi bi-person-check me-1"></i><?= htmlspecialchars($activePallet['customer_name']) ?></span>
                <span class="constraint-badge"><i class="bi bi-hash me-1"></i><?= htmlspecialchars($activePallet['ref_no']) ?></span>
                <span class="constraint-badge"><i class="bi bi-tag me-1"></i><?= htmlspecialchars($activePallet['product_type']) ?></span>
                <span class="constraint-badge"><i class="bi bi-arrows-expand me-1"></i><?= number_format((float)$activePallet['width']) ?> mm</span>
                <small class="text-muted align-self-center" style="font-size:10px;">All rolls must match</small>
            </div>
            <?php else: ?>
            <div id="constraintHeader" class="px-3 py-2 border-bottom">
                <small class="text-muted">
                    <i class="bi bi-qr-code-scan me-1"></i>
                    Scan the first roll — its Customer, Ref No, Product Type and Width will lock as constraints.
                </small>
            </div>
            <?php endif; ?>

            <div class="card-body p-4">
                <div class="pallet-progress mb-2">
                    <div class="pallet-progress-bar" id="palletProgressBar"
                         style="width:<?= (count($activeItems) / $MAX * 100) ?>%"></div>
                </div>

                <!-- ── Total Est. Weight Summary Bar ── -->
                <div class="weight-summary-bar" id="weightSummaryBar">
                    <div class="wgt-label">
                        <i class="bi bi-speedometer2"></i>
                        Est. Total Weight
                    </div>
                    <div style="display:flex; align-items:baseline; gap:6px;">
                        <span class="wgt-total" id="totalWeightDisplay">
                            <?= $totalEstWgt > 0 ? number_format($totalEstWgt, 2) : '—' ?>
                        </span>
                        <span class="wgt-unit"><?= $totalEstWgt > 0 ? 'kg' : '' ?></span>
                    </div>
                    <div class="wgt-avg" id="avgWeightDisplay">
                        <?php if (count($activeItems) > 0 && $totalEstWgt > 0): ?>
                            avg <?= number_format($totalEstWgt / count($activeItems), 2) ?> kg/roll
                        <?php else: ?>
                            no weight data
                        <?php endif; ?>
                    </div>
                </div>

                <div class="alert alert-info py-2 mb-3">
                    <i class="bi bi-qr-code-scan me-1"></i>
                    Scan a product QR, or type Lot + Coil + Roll below.
                </div>

                <!-- Manual entry row -->
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

                <!-- Combined single-line manual entry (space-separated) -->
                <div class="row g-2 mb-3">
                    <div class="col-md-9">
                        <input type="text" id="manCombined" class="form-control form-control-sm"
                               placeholder="Or type all-in-one:  826277 FK-1 R1"
                               autocomplete="off" autocorrect="off" spellcheck="false"
                               style="font-family:monospace;">
                    </div>
                    <div class="col-md-3">
                        <button type="button" class="btn btn-outline-primary btn-sm w-100"
                                onclick="combinedLookup()">
                            <i class="bi bi-box-arrow-in-down me-1"></i> Add
                        </button>
                    </div>
                </div>

                <div id="scanFeedback" class="mb-3" style="min-height:40px;"></div>

                <!-- =====================================================
                     SLOT LIST — PHP renders ALL 8 slots in order.
                     Each filled slot now shows a weight chip.
                ===================================================== -->
                <div id="rollList">
                    <?php for ($s = 1; $s <= $MAX; $s++):
                        $item = $itemsBySeq[$s] ?? null;
                    ?>
                    <?php if ($item):
                        $itemLen = (float)($item['actual_length'] ?: $item['length']);
                        $itemWgt = calcEstWeight($itemLen, (float)$item['width'], (float)$item['std_weight']);
                    ?>
                    <!-- FILLED SLOT -->
                    <div class="slot-card"
                         id="slot<?= $s ?>"
                         data-slot="<?= $s ?>"
                         data-filled="1"
                         data-weight="<?= number_format($itemWgt, 4) ?>">
                        <span class="roll-seq"><?= $s ?></span>
                        <div class="flex-grow-1">
                            <div class="fw-bold small">
                                <?= htmlspecialchars($item['lot_no']) ?>
                                <?= htmlspecialchars($item['coil_no']) ?>
                                &ndash; <?= str_replace('R', 'R-', htmlspecialchars($item['roll_no'])) ?>
                            </div>
                            <div class="text-muted" style="font-size:11px;">
                                <?= htmlspecialchars($item['product']) ?> |
                                <?= number_format((float)$item['width']) ?>mm |
                                <?= number_format($itemLen, 1) ?>m
                            </div>
                        </div>
                        <!-- Weight chip -->
                        <span class="wgt-chip" title="Est. Weight = (<?= number_format($itemLen,1) ?>m × <?= number_format((float)$item['width']) ?>mm / 1000) × <?= $item['std_weight'] ?>">
                            <i class="bi bi-speedometer2"></i>
                            <?= $itemWgt > 0 ? number_format($itemWgt, 2) . ' kg' : 'N/A' ?>
                        </span>
                        <button type="button"
                                class="btn btn-outline-danger btn-sm"
                                title="Remove this roll from the pallet"
                                data-product-id="<?= $item['product_id'] ?>"
                                onclick="removeRoll(<?= $activePalletId ?>, <?= $item['product_id'] ?>, <?= $s ?>, this)">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>

                    <?php else: ?>
                    <!-- EMPTY SLOT -->
                    <div class="slot-card slot-empty"
                         id="slot<?= $s ?>"
                         data-slot="<?= $s ?>"
                         data-filled="0"
                         data-weight="0">
                        <span class="roll-seq"><?= $s ?></span>
                        <span style="font-size:13px;">Empty slot <?= $s ?></span>
                    </div>
                    <?php endif; ?>
                    <?php endfor; ?>
                </div><!-- #rollList -->
            </div>

            <div class="card-footer bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex gap-2">
                    <a href="pallet.php" class="btn btn-outline-secondary btn-sm">Close panel</a>
                    <form method="post"
                          onsubmit="return confirm('Delete pallet <?= htmlspecialchars($activePallet['pallet_no'], ENT_QUOTES) ?>?\n\nAll rolls will be returned to stock — the products themselves are NOT deleted.')">
                        <input type="hidden" name="action"    value="delete_pallet">
                        <input type="hidden" name="pallet_id" value="<?= $activePalletId ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-trash3 me-1"></i> Delete Pallet
                        </button>
                    </form>
                </div>
                <div class="d-flex gap-2">
                    <?php
                    $isEdit   = ($activePallet['edit_count'] ?? 0) > 0;
                    $qcAction = $isEdit ? 'resubmit_to_qc' : 'send_to_qc';
                    $qcLabel  = $isEdit ? 'Re-submit to QC' : 'Send to QC';
                    ?>
                    <form method="post">
                        <input type="hidden" name="action"    value="<?= $qcAction ?>">
                        <input type="hidden" name="pallet_id" value="<?= $activePalletId ?>">
                        <button type="submit"
                                class="btn btn-warning btn-sm fw-bold"
                                id="sendToQcBtn"
                                <?= count($activeItems) < 1 ? 'disabled' : '' ?>
                                onclick="return confirm('<?= $isEdit
                                    ? 'Re-submit this edited pallet to QC?'
                                    : 'Send pallet to QC? No more rolls can be added after this.' ?>')">
                            <i class="bi bi-send me-1"></i> <?= $qcLabel ?>
                        </button>
                    </form>
                </div>
            </div>
        </div><!-- /building card -->

        <?php elseif ($isRejected): ?>
        <!-- REJECTED STATE -->
        <div class="card shadow-sm border-0 mb-4 border-danger">
            <div class="card-header text-white d-flex justify-content-between align-items-center"
                 style="background:#991b1b;">
                <div>
                    <i class="bi bi-x-circle me-2"></i>
                    <strong><?= htmlspecialchars($activePallet['pallet_no']) ?></strong>
                    <span class="badge bg-white text-danger ms-2">QC REJECTED</span>
                </div>
                <span class="badge bg-white text-danger">
                    <?= count($activeItems) ?> roll<?= count($activeItems) != 1 ? 's' : '' ?>
                </span>
            </div>

            <?php if (!empty($activePallet['qc_comment'])): ?>
            <div class="px-4 py-3 border-bottom" style="background:#fff5f5;">
                <div class="d-flex align-items-start gap-2">
                    <i class="bi bi-chat-left-dots-fill text-danger mt-1"></i>
                    <div>
                        <div class="fw-bold text-danger" style="font-size:12px;">QC Rejection Reason</div>
                        <div class="mt-1"><?= htmlspecialchars($activePallet['qc_comment']) ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card-body p-4">
                <?php
                $rejectedTotalWgt = 0.0;
                foreach ($activeItems as $item) {
                    $len = (float)($item['actual_length'] ?: $item['length']);
                    $rejectedTotalWgt += calcEstWeight($len, (float)$item['width'], (float)($item['std_weight'] ?? 0));
                }
                ?>
                <?php if ($rejectedTotalWgt > 0): ?>
                <div class="weight-summary-bar mb-3">
                    <div class="wgt-label"><i class="bi bi-speedometer2"></i> Est. Total Weight</div>
                    <div style="display:flex; align-items:baseline; gap:6px;">
                        <span class="wgt-total"><?= number_format($rejectedTotalWgt, 2) ?></span>
                        <span class="wgt-unit">kg</span>
                    </div>
                    <div class="wgt-avg"><?= count($activeItems) ?> roll<?= count($activeItems) != 1 ? 's' : '' ?></div>
                </div>
                <?php endif; ?>

                <p class="text-muted mb-3" style="font-size:13px;">
                    <i class="bi bi-info-circle me-1"></i>
                    Click <strong>Edit Pallet</strong> to reopen it. You can then remove the defective roll(s),
                    add replacement rolls, and re-submit to QC.
                </p>
                <?php foreach ($activeItems as $item):
                    $itemLen = (float)($item['actual_length'] ?: $item['length']);
                    $itemWgt = calcEstWeight($itemLen, (float)$item['width'], (float)($item['std_weight'] ?? 0));
                ?>
                <div class="slot-card">
                    <span class="roll-seq" style="background:#991b1b;"><?= $item['seq'] ?></span>
                    <div class="flex-grow-1">
                        <div class="fw-bold small">
                            <?= htmlspecialchars($item['lot_no']) ?>
                            <?= htmlspecialchars($item['coil_no']) ?>
                            &ndash; <?= str_replace('R','R-', htmlspecialchars($item['roll_no'])) ?>
                        </div>
                        <div class="text-muted" style="font-size:11px;">
                            <?= htmlspecialchars($item['product']) ?> |
                            <?= number_format((float)$item['width']) ?>mm |
                            <?= number_format($itemLen, 1) ?>m
                        </div>
                    </div>
                    <?php if ($itemWgt > 0): ?>
                    <span class="wgt-chip">
                        <i class="bi bi-speedometer2"></i>
                        <?= number_format($itemWgt, 2) ?> kg
                    </span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="card-footer bg-light d-flex justify-content-between align-items-center">
                <div class="d-flex gap-2">
                    <a href="pallet.php" class="btn btn-outline-secondary btn-sm">← Back</a>
                    <form method="post"
                          onsubmit="return confirm('Delete this rejected pallet?\nRolls will be returned to stock.')">
                        <input type="hidden" name="action"    value="delete_pallet">
                        <input type="hidden" name="pallet_id" value="<?= $activePalletId ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-trash3 me-1"></i> Delete Pallet
                        </button>
                    </form>
                </div>
                <form method="post">
                    <input type="hidden" name="action"    value="reopen_pallet">
                    <input type="hidden" name="pallet_id" value="<?= $activePalletId ?>">
                    <button type="submit" class="btn btn-warning fw-bold"
                            onclick="return confirm('Reopen pallet <?= htmlspecialchars($activePallet['pallet_no'], ENT_QUOTES) ?> for editing?\nAll rolls will be reset to IN so you can modify the pallet.')">
                        <i class="bi bi-pencil-fill me-1"></i> Edit Pallet
                    </button>
                </form>
            </div>
        </div>

        <?php elseif ($activePallet): ?>
        <!-- READ-ONLY (approved / delivered / pending_qc) -->
        <div class="alert alert-secondary">
            <strong><?= htmlspecialchars($activePallet['pallet_no']) ?></strong> —
            <span class="badge badge-<?= $activePallet['status'] ?>">
                <?= strtoupper(str_replace('_', ' ', $activePallet['status'])) ?>
            </span>
            — read-only.
            <a href="pallet.php" class="ms-2">← Back to list</a>
        </div>

        <?php else: ?>
        <!-- NOTHING SELECTED -->
        <div class="card shadow-sm border-0 text-center py-5">
            <div class="text-muted mb-3"><i class="bi bi-archive" style="font-size:3rem;"></i></div>
            <h5 class="text-muted">No pallet selected</h5>
            <p class="text-muted small">Select an open pallet, or create a new one.</p>
            <button type="button" class="btn btn-success mt-2"
                    data-bs-toggle="modal" data-bs-target="#createPalletModal">
                <i class="bi bi-plus-lg me-1"></i> Create New Pallet
            </button>
        </div>
        <?php endif; ?>
    </div><!-- /col-md-7 -->

    <!-- ═══════════════════════════════════════════════════════
         RIGHT: Sidebar
    ═══════════════════════════════════════════════════════════ -->
    <div class="col-md-5 pallet-sidebar">

        <?php if (!empty($rejectedPallets)): ?>
        <div class="card shadow-sm border-0 mb-3 border-danger">
            <div class="card-header fw-bold py-2" style="background:#fee2e2;color:#991b1b;">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                Rejected by QC (<?= count($rejectedPallets) ?>)
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($rejectedPallets as $rp): ?>
                <a href="pallet.php?pallet_id=<?= $rp['id'] ?>"
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center
                          <?= ($rp['id'] == $activePalletId) ? 'active' : '' ?>">
                    <div>
                        <div class="fw-bold small"><?= htmlspecialchars($rp['pallet_no']) ?></div>
                        <div style="font-size:10px;" class="text-muted">
                            <?= htmlspecialchars($rp['customer_name'] ?? '—') ?>
                        </div>
                    </div>
                    <span class="badge bg-danger"><?= $rp['item_count'] ?> rolls</span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Open pallets -->
        <?php if (!empty($openPallets)): ?>
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-dark text-white fw-bold py-2">
                <i class="bi bi-list-task me-2"></i>Open Pallets (<?= count($openPallets) ?>)
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
                            <?= htmlspecialchars($op['customer_name'] ?: 'No constraint set yet') ?>
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

        <!-- All pallets table -->
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
                                <?= strtoupper(str_replace('_', ' ', $pal['status'])) ?>
                            </span>
                        </td>
                        <td><?= $pal['item_count'] ?>/<?= $MAX ?></td>
                        <td class="text-muted" style="font-size:11px;">
                            <?= htmlspecialchars($pal['customer_name'] ?: '—') ?>
                        </td>
                        <td>
                            <?php if ($pal['status'] === 'building'): ?>
                            <a href="pallet.php?pallet_id=<?= $pal['id'] ?>"
                               class="btn btn-outline-primary btn-sm">Open</a>
                            <?php elseif ($pal['status'] === 'rejected'): ?>
                            <a href="pallet.php?pallet_id=<?= $pal['id'] ?>"
                               class="btn btn-danger btn-sm">Edit</a>
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
    </div><!-- /col-md-5 sidebar -->
</div><!-- /row -->

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
          <div class="form-text"><code>SFS-XXXX-XXX</code> or <code>SFS-XXXX-XXX (A)</code></div>
          <div id="palletNoFeedback" class="mt-1" style="font-size:12px;min-height:18px;"></div>
        </div>
        <div class="alert alert-info py-2 mb-0" style="font-size:12px;">
          <i class="bi bi-info-circle me-1"></i>
          The <strong>first roll</strong> scanned will set the Customer, Ref No, Product Type and Width constraints.
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

<input id="qrScanInput" type="hidden" value="">

<script>
// ─────────────────────────────────────────────────────────────
// CONSTANTS
// ─────────────────────────────────────────────────────────────
const PALLET_ID = <?= $activePalletId ?: 'null' ?>;
const MAX_ROLLS = <?= $MAX ?>;
let   rollCount = <?= count($activeItems) ?>;

// Guard against overlapping/duplicate scan submissions. While a
// lookup+add round-trip is in flight, ignore any further scans —
// this is the front-line defence against a camera double-decode.
let   isAdding  = false;

// ─────────────────────────────────────────────────────────────
// EST. WEIGHT HELPERS
// ─────────────────────────────────────────────────────────────

/**
 * Calculate est. weight for one roll.
 * Formula: (length_m × width_mm / 1000) × std_weight
 */
function calcWeight(lengthM, widthMm, stdWeight) {
    if (!lengthM || !widthMm || !stdWeight) return 0;
    return (parseFloat(lengthM) * parseFloat(widthMm) / 1000) * parseFloat(stdWeight);
}

/**
 * Recalculate the total weight from all filled slot data-weight attributes
 * and update the weight summary bar.
 */
function recalcTotalWeight() {
    const slots   = document.querySelectorAll('#rollList [data-slot][data-filled="1"]');
    let   total   = 0;
    let   count   = 0;

    slots.forEach(slot => {
        const w = parseFloat(slot.dataset.weight || 0);
        if (w > 0) { total += w; count++; }
    });

    const dispEl = document.getElementById('totalWeightDisplay');
    const unitEl = dispEl ? dispEl.nextElementSibling : null;
    const avgEl  = document.getElementById('avgWeightDisplay');

    if (!dispEl) return;

    if (total > 0) {
        dispEl.textContent = total.toFixed(2);
        if (unitEl) unitEl.textContent = 'kg';
        if (avgEl)  avgEl.textContent  = 'avg ' + (total / slots.length).toFixed(2) + ' kg/roll';
    } else {
        dispEl.textContent = '—';
        if (unitEl) unitEl.textContent = '';
        if (avgEl)  avgEl.textContent  = 'no weight data';
    }
}

// ─────────────────────────────────────────────────────────────
// updateConstraintBadges(product)
// After the FIRST roll seeds the pallet constraints, fill the
// header badges live. This replaces the old location.reload(),
// which created a window where the next scan failed.
// ─────────────────────────────────────────────────────────────
function updateConstraintBadges(p) {
    const header = document.getElementById('constraintHeader');
    if (!header) return;
    header.className = 'px-3 pt-2 pb-1 border-bottom d-flex flex-wrap gap-2 align-items-center';
    header.innerHTML = `
        <span class="constraint-badge"><i class="bi bi-person-check me-1"></i>${escHtml(p.customer_name || '')}</span>
        <span class="constraint-badge"><i class="bi bi-hash me-1"></i>${escHtml(p.ref_no || '')}</span>
        <span class="constraint-badge"><i class="bi bi-tag me-1"></i>${escHtml(p.product || '')}</span>
        <span class="constraint-badge"><i class="bi bi-arrows-expand me-1"></i>${(+p.width).toFixed(0)} mm</span>
        <small class="text-muted align-self-center" style="font-size:10px;">All rolls must match</small>
    `;
}

// ─────────────────────────────────────────────────────────────
// QR / INPUT PARSING
// Accepts TWO formats:
//   A) KEY=value pairs (camera QR / hardware scanner):
//        LOT=826277;COIL=FK-1;ROLL=R1
//   B) Plain space-separated values (typed by hand):
//        826277 FK-1 R1
// ─────────────────────────────────────────────────────────────
function parseQR(raw) {
    raw = (raw || '').trim();

    // ── Format A: contains '=' → KEY=value;KEY=value ──────────
    if (raw.indexOf('=') > -1) {
        const parts = {};
        raw.split(';').forEach(p => {
            const idx = p.indexOf('=');
            if (idx > -1) {
                parts[p.substring(0, idx).trim().toUpperCase()]
                    = decodeURIComponent(p.substring(idx + 1).trim());
            }
        });
        return { lot: parts.LOT || '', coil: parts.COIL || '', roll: parts.ROLL || '' };
    }

    // ── Format B: no '=' → split on whitespace ────────────────
    //    First token = Lot, second = Coil, third = Roll.
    //    Extra tokens are ignored.
    const tokens = raw.split(/\s+/).filter(Boolean);
    return {
        lot:  tokens[0] || '',
        coil: tokens[1] || '',
        roll: tokens[2] || '',
    };
}

async function processQR(raw) {
    const { lot, coil, roll } = parseQR(raw);
    if (!lot || !coil) { showFeedback('Could not parse input: ' + escHtml(raw), false); return; }
    await lookupAndAdd(lot, coil, roll);
}

// ─────────────────────────────────────────────────────────────
// MANUAL ENTRY — three separate boxes (Lot / Coil / Roll)
// ─────────────────────────────────────────────────────────────
async function manualLookup() {
    const lot  = document.getElementById('manLot').value.trim();
    const coil = document.getElementById('manCoil').value.trim();
    const roll = document.getElementById('manRoll').value.trim();
    if (!lot || !coil || !roll) {
        showFeedback('Enter Lot, Coil and Roll No.', false);
        return;
    }
    await lookupAndAdd(lot, coil, roll);
    ['manLot', 'manCoil', 'manRoll'].forEach(id => document.getElementById(id).value = '');
}

// ─────────────────────────────────────────────────────────────
// MANUAL ENTRY — single combined box (space-separated)
//   e.g. "826277 FK-1 R1"
// ─────────────────────────────────────────────────────────────
async function combinedLookup() {
    const el  = document.getElementById('manCombined');
    const val = el.value.trim();
    if (!val) { showFeedback('Type the roll, e.g. 826277 FK-1 R1', false); el.focus(); return; }
    await processQR(val);
    el.value = '';
    el.focus();
}

// ─────────────────────────────────────────────────────────────
// LOOKUP + ADD
// ─────────────────────────────────────────────────────────────
async function lookupAndAdd(lot, coil, roll) {
    if (!PALLET_ID) return;

    // Drop overlapping scans (e.g. camera double-decode) while a
    // previous add is still being processed.
    if (isAdding) return;

    if (rollCount >= MAX_ROLLS) {
        showFeedback(`Pallet is full (${MAX_ROLLS}/${MAX_ROLLS}).`, false);
        return;
    }

    isAdding = true;
    try {
        let lk;
        try {
            lk = await fetch(
                `pallet.php?ajax=lookup_product&lot=${enc(lot)}&coil=${enc(coil)}&roll=${enc(roll)}`
            ).then(r => r.json());
        } catch {
            showFeedback('Network error during lookup.', false);
            return;
        }
        if (!lk.ok) { showFeedback(lk.msg, false); return; }

        const p = lk.product;

        if (p.is_voided == 1) { showFeedback('This roll has been voided.', false); return; }
        if (p.stock_counted != 1) { showFeedback(`Roll ${lot} ${coil} ${roll} — actual length not saved yet.`, false); return; }

        // ── Already on a DIFFERENT pallet → real error ───────────
        if (p.pallet_id && p.pallet_id != PALLET_ID) {
            showFeedback(`Already on pallet ${escHtml(p.pallet_no)}.`, false);
            return;
        }

        // ── Already on THIS pallet → duplicate scan of a roll we
        //    just added. Silently ignore so a camera double-decode
        //    cannot trigger a server "already on pallet" error or
        //    jam the UI. It's already shown in a slot. ───────────
        if (p.pallet_id && p.pallet_id == PALLET_ID) {
            return;
        }

        // ── Belt-and-suspenders: if a slot already shows this
        //    product id, treat it as a duplicate too (covers the
        //    race where the lookup hasn't caught up to the just-
        //    added row yet). ───────────────────────────────────
        if (document.querySelector(`#rollList [data-product-id="${p.id}"]`)) {
            return;
        }

        const fd = new FormData();
        fd.append('action',     'add_roll');
        fd.append('pallet_id',  PALLET_ID);
        fd.append('product_id', p.id);

        let ad;
        try {
            ad = await fetch('pallet.php', { method: 'POST', body: fd }).then(r => r.json());
        } catch {
            showFeedback('Network error while adding roll.', false);
            return;
        }
        if (!ad.ok) { showFeedback(ad.msg, false); return; }

        rollCount = ad.roll_count;
        fillSlot(ad.seq, p);
        updateProgress(rollCount);

        // Clear manual entry fields after ANY successful add
        ['manLot', 'manCoil', 'manRoll', 'manCombined'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });

        showFeedback(
            `✓ Added: ${escHtml(lot)} ${escHtml(coil)} – R-${escHtml(roll)} (slot ${ad.seq})`,
            true
        );

        // First roll seeds the pallet constraints. Update the badges
        // live instead of reloading the page — the old reload created
        // a window where scanning the 2nd roll failed with a stale
        // "already on pallet" error and forced a manual refresh.
        if (ad.seq === 1) {
            updateConstraintBadges(p);
        }
    } finally {
        // Always release the lock so the next legitimate scan works.
        isAdding = false;
    }
}

// ─────────────────────────────────────────────────────────────
// fillSlot(seq, product)
// Fills an empty slot IN-PLACE; also renders weight chip.
// ─────────────────────────────────────────────────────────────
function fillSlot(seq, p) {
    const slotEl = document.getElementById('slot' + seq);
    if (!slotEl) return;

    const len       = parseFloat(p.actual_length) > 0 ? parseFloat(p.actual_length) : parseFloat(p.length);
    const stdWeight = parseFloat(p.std_weight) || 0;
    const wgt       = calcWeight(len, p.width, stdWeight);
    const wgtStr    = wgt > 0 ? wgt.toFixed(2) + ' kg' : 'N/A';

    slotEl.classList.remove('slot-empty');
    slotEl.setAttribute('data-filled', '1');
    slotEl.setAttribute('data-weight', wgt.toFixed(4));

    slotEl.innerHTML = `
        <span class="roll-seq">${seq}</span>
        <div class="flex-grow-1">
            <div class="fw-bold small">
                ${escHtml(p.lot_no)} ${escHtml(p.coil_no)}
                &ndash; ${escHtml(p.roll_no.replace(/^R/, 'R-'))}
            </div>
            <div class="text-muted" style="font-size:11px;">
                ${escHtml(p.product)} |
                ${(+p.width).toFixed(0)}mm |
                ${len.toFixed(1)}m
            </div>
        </div>
        <span class="wgt-chip" title="Est. Weight = (${len.toFixed(1)}m × ${(+p.width).toFixed(0)}mm / 1000) × ${stdWeight}">
            <i class="bi bi-speedometer2"></i>
            ${wgtStr}
        </span>
        <button type="button"
                class="btn btn-outline-danger btn-sm"
                title="Remove this roll from the pallet"
                data-product-id="${p.id}"
                onclick="removeRoll(${PALLET_ID}, ${p.id}, ${seq}, this)">
            <i class="bi bi-x-lg"></i>
        </button>
    `;

    // Flash animation
    slotEl.classList.add('scan-flash');
    slotEl.addEventListener('animationend', () => slotEl.classList.remove('scan-flash'), { once: true });

    // Update total weight
    recalcTotalWeight();
}

// ─────────────────────────────────────────────────────────────
// removeRoll(palletId, productId, seq, btn)
// ─────────────────────────────────────────────────────────────
async function removeRoll(palletId, productId, seq, btn) {
    if (!confirm('Remove this roll from the pallet?\nThe roll will return to Finish Good stock.')) return;
    btn.disabled = true;

    const fd = new FormData();
    fd.append('action',     'remove_roll');
    fd.append('pallet_id',  palletId);
    fd.append('product_id', productId);

    let d;
    try {
        d = await fetch('pallet.php', { method: 'POST', body: fd }).then(r => r.json());
    } catch {
        showFeedback('Network error while removing roll.', false);
        btn.disabled = false;
        return;
    }

    if (!d.ok) {
        showFeedback(d.msg, false);
        btn.disabled = false;
        return;
    }

    rollCount = d.new_count;
    updateProgress(rollCount);
    clearSlot(seq);
    resequenceSlots();
    recalcTotalWeight();   // ← update weight after removal
    showFeedback(d.msg, true);

    if (rollCount === 0) setTimeout(() => location.reload(), 1000);
}

// ─────────────────────────────────────────────────────────────
// clearSlot(seq)
// ─────────────────────────────────────────────────────────────
function clearSlot(seq) {
    const slotEl = document.getElementById('slot' + seq);
    if (!slotEl) return;
    slotEl.classList.add('slot-empty');
    slotEl.setAttribute('data-filled', '0');
    slotEl.setAttribute('data-weight', '0');
    slotEl.innerHTML = `
        <span class="roll-seq">${seq}</span>
        <span style="font-size:13px;">Empty slot ${seq}</span>
    `;
}

// ─────────────────────────────────────────────────────────────
// resequenceSlots()
// ─────────────────────────────────────────────────────────────
function resequenceSlots() {
    const allSlots = Array.from(
        document.querySelectorAll('#rollList [data-slot]')
    ).sort((a, b) => +a.dataset.slot - +b.dataset.slot);

    let filledCount = 0;
    allSlots.forEach(slotEl => {
        const slotNo = +slotEl.dataset.slot;
        if (slotEl.dataset.filled === '1') {
            filledCount++;
            const newSeq = filledCount;
            const bubble = slotEl.querySelector('.roll-seq');
            if (bubble) bubble.textContent = newSeq;
            const removeBtn = slotEl.querySelector('button[data-product-id]');
            if (removeBtn) {
                const pid = removeBtn.getAttribute('data-product-id');
                removeBtn.setAttribute('onclick', `removeRoll(${PALLET_ID}, ${pid}, ${newSeq}, this)`);
            }
        } else {
            const bubble = slotEl.querySelector('.roll-seq');
            if (bubble) bubble.textContent = slotNo;
            const label = slotEl.querySelector('span:not(.roll-seq)');
            if (label) label.textContent = `Empty slot ${slotNo}`;
        }
    });
}

// ─────────────────────────────────────────────────────────────
// UI HELPERS
// ─────────────────────────────────────────────────────────────
function updateProgress(count) {
    const bar   = document.getElementById('palletProgressBar');
    const badge = document.getElementById('rollCountBadge');
    const btn   = document.getElementById('sendToQcBtn');
    if (bar)   bar.style.width = (count / MAX_ROLLS * 100) + '%';
    if (badge) badge.textContent = count + ' / ' + MAX_ROLLS + ' rolls';
    if (btn)   btn.disabled = count < 1;
}

function showFeedback(msg, ok) {
    const el = document.getElementById('scanFeedback');
    if (!el) return;
    el.innerHTML = `
        <div class="alert alert-${ok ? 'success' : 'danger'} py-2 mb-0 d-flex align-items-center gap-2">
            <i class="bi bi-${ok ? 'check-circle-fill' : 'exclamation-triangle-fill'}"></i>
            <span>${msg}</span>
        </div>`;
    setTimeout(() => { el.innerHTML = ''; }, 5000);
}

// ─────────────────────────────────────────────────────────────
// Enter key on the combined box → Add
// ─────────────────────────────────────────────────────────────
document.getElementById('manCombined')?.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); combinedLookup(); }
});

// ─────────────────────────────────────────────────────────────
// CREATE PALLET MODAL
// ─────────────────────────────────────────────────────────────
let palletNoValid = false;
let palletNoTimer;

document.getElementById('createPalletModal')?.addEventListener('show.bs.modal', () => {
    palletNoValid = false;
    const inp = document.getElementById('palletNoInput');
    inp.value = '';
    inp.classList.remove('is-valid', 'is-invalid');
    document.getElementById('palletNoFeedback').innerHTML = '';
    document.getElementById('createPalletBtn').disabled = true;
    setTimeout(() => inp.focus(), 300);
});

document.getElementById('palletNoInput')?.addEventListener('input', function () {
    clearTimeout(palletNoTimer);
    palletNoValid = false;
    document.getElementById('createPalletBtn').disabled = true;
    const val = this.value.trim();
    if (!val) {
        document.getElementById('palletNoFeedback').innerHTML = '';
        this.classList.remove('is-valid', 'is-invalid');
        return;
    }
    palletNoTimer = setTimeout(async () => {
        try {
            const r = await fetch(
                `pallet.php?ajax=validate_pallet_no&pallet_no=${enc(val)}`
            ).then(x => x.json());
            const fb = document.getElementById('palletNoFeedback');
            this.classList.remove('is-valid', 'is-invalid');
            if (r.ok) {
                fb.innerHTML = `<span class="text-success"><i class="bi bi-check-circle me-1"></i>Valid &amp; available</span>`;
                this.classList.add('is-valid');
                palletNoValid = true;
                document.getElementById('createPalletBtn').disabled = false;
            } else {
                fb.innerHTML = `<span class="text-danger"><i class="bi bi-x-circle me-1"></i>${escHtml(r.msg)}</span>`;
                this.classList.add('is-invalid');
            }
        } catch (_) {}
    }, 500);
});

document.getElementById('palletNoInput')?.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && palletNoValid) submitCreatePallet();
});

async function submitCreatePallet() {
    const palletNo = document.getElementById('palletNoInput').value.trim();
    if (!palletNo || !palletNoValid) return;

    const btn = document.getElementById('createPalletBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Creating…';

    const fd = new FormData();
    fd.append('action',    'create_pallet');
    fd.append('pallet_no', palletNo);

    let r;
    try {
        r = await fetch('pallet.php', { method: 'POST', body: fd }).then(x => x.json());
    } catch (_) {
        r = { ok: false, msg: 'Network error.' };
    }

    if (r.ok) {
        window.location.href = `pallet.php?pallet_id=${r.pallet_id}&success=created`;
    } else {
        document.getElementById('palletNoFeedback').innerHTML =
            `<span class="text-danger"><i class="bi bi-x-circle me-1"></i>${escHtml(r.msg)}</span>`;
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plus-lg me-1"></i> Create Pallet';
    }
}

// ─────────────────────────────────────────────────────────────
// UTILITIES
// ─────────────────────────────────────────────────────────────
function escHtml(s) {
    return String(s ?? '').replace(
        /[&<>"']/g,
        c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])
    );
}
function enc(s) { return encodeURIComponent(s ?? ''); }
</script>

<!-- Cache-busted (?v=7) so the browser always loads the latest scanner. -->
<script src="camera_scanner.js?v=7"></script>
<script>
if (PALLET_ID) {
    initCameraScanner({
        onScan: function(decodedText) {
            processQR(decodedText);
        }
    });
}
</script>

<?php include 'footer.php'; ?>
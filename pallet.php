<?php
// =============================================================
// pallet.php  —  v2 (Pallet Upgrade)
// FIX: Slot insertion now replaces the correct empty slot
//      instead of appending to the bottom of the list.
//      removeRoll() restores the empty slot at the correct
//      sequential position instead of always appending at end.
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

// ── AJAX: product lookup ──────────────────────────────────────
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
$activeItems    = $activePallet   ? $pm->getPalletItems($activePalletId) : [];

// Build a lookup: seq → item, so PHP can render all 8 slots in order
$itemsBySeq = [];
foreach ($activeItems as $item) {
    $itemsBySeq[(int)$item['seq']] = $item;
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

/* ── Slot cards ──
   Every slot (filled or empty) is the same height so the list
   never jumps when a card swaps from empty → filled.           */
.slot-card {
    border: 1px solid var(--bs-border-color);
    border-radius: 8px;
    padding: 12px 14px;
    background: #fff;
    margin-bottom: 8px;
    transition: box-shadow .15s, background .15s;
    min-height: 60px;          /* keeps empty slots the same height as filled ones */
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
.slot-empty .roll-seq {
    background: #dee2e6;
    color: #6c757d;
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
            <?php if (!empty(trim($activePallet['customer_name'] ?? ''))): ?>
            <div class="px-3 pt-2 pb-1 border-bottom d-flex flex-wrap gap-2 align-items-center">
                <span class="constraint-badge"><i class="bi bi-person-check me-1"></i><?= htmlspecialchars($activePallet['customer_name']) ?></span>
                <span class="constraint-badge"><i class="bi bi-hash me-1"></i><?= htmlspecialchars($activePallet['ref_no']) ?></span>
                <span class="constraint-badge"><i class="bi bi-tag me-1"></i><?= htmlspecialchars($activePallet['product_type']) ?></span>
                <span class="constraint-badge"><i class="bi bi-arrows-expand me-1"></i><?= number_format((float)$activePallet['width']) ?> mm</span>
                <small class="text-muted align-self-center" style="font-size:10px;">All rolls must match</small>
            </div>
            <?php else: ?>
            <div class="px-3 py-2 border-bottom">
                <small class="text-muted">
                    <i class="bi bi-qr-code-scan me-1"></i>
                    Scan the first roll — its Customer, Ref No, Product Type and Width will lock as constraints.
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

                <div id="scanFeedback" class="mb-3" style="min-height:40px;"></div>

                <!-- =====================================================
                     SLOT LIST
                     PHP renders ALL 8 slots in order, 1..MAX_ROLLS.
                     Each slot has:
                       id="slot{N}"           — targeted by JS
                       data-slot="{N}"        — used by JS sort helper
                       data-filled="0|1"      — 0=empty, 1=has product
                     JS replaces the inner content of slot{N} in-place,
                     so the visual order never changes.
                ===================================================== -->
                <div id="rollList">
                    <?php for ($s = 1; $s <= $MAX; $s++):
                        $item = $itemsBySeq[$s] ?? null;
                    ?>
                    <?php if ($item): ?>
                    <!-- FILLED SLOT -->
                    <div class="slot-card"
                         id="slot<?= $s ?>"
                         data-slot="<?= $s ?>"
                         data-filled="1">
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
                                <?= number_format((float)($item['actual_length'] ?: $item['length']), 1) ?>m
                            </div>
                        </div>
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
                         data-filled="0">
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
                <p class="text-muted mb-3" style="font-size:13px;">
                    <i class="bi bi-info-circle me-1"></i>
                    Click <strong>Edit Pallet</strong> to reopen it. You can then remove the defective roll(s),
                    add replacement rolls, and re-submit to QC. All matching constraints still apply.
                </p>
                <?php foreach ($activeItems as $item): ?>
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
                            <?= number_format((float)($item['actual_length'] ?: $item['length']), 1) ?>m
                        </div>
                    </div>
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

        <!-- Rejected pallets (urgent) -->
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

<!-- Hidden QR input always focused for scanner gun -->
<input id="qrScanInput" type="text" inputmode="none"
       style="position:fixed;left:-9999px;opacity:0;" autofocus>

<script>
// ─────────────────────────────────────────────────────────────
// CONSTANTS (injected from PHP)
// ─────────────────────────────────────────────────────────────
const PALLET_ID = <?= $activePalletId ?: 'null' ?>;
const MAX_ROLLS = <?= $MAX ?>;
let   rollCount = <?= count($activeItems) ?>;
const qrInput   = document.getElementById('qrScanInput');

// ─────────────────────────────────────────────────────────────
// SCANNER FOCUS — keep the hidden input focused so barcode
// gun input is always captured even after UI interactions.
// ─────────────────────────────────────────────────────────────
setInterval(() => {
    const a = document.activeElement;
    if (!document.querySelector('.modal.show') &&
        !['INPUT', 'TEXTAREA', 'SELECT'].includes(a.tagName)) {
        qrInput.focus();
    }
}, 600);

qrInput.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    const raw = this.value.trim();
    this.value = '';
    if (!raw || !PALLET_ID) return;
    processQR(raw);
});

// ─────────────────────────────────────────────────────────────
// QR PARSING — format: LOT=xxx;COIL=xxx;ROLL=xxx
// ─────────────────────────────────────────────────────────────
function parseQR(raw) {
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

async function processQR(raw) {
    const { lot, coil, roll } = parseQR(raw);
    if (!lot || !coil) { showFeedback('Could not parse QR: ' + escHtml(raw), false); return; }
    await lookupAndAdd(lot, coil, roll);
}

// ─────────────────────────────────────────────────────────────
// MANUAL ENTRY
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
// LOOKUP + ADD
// Validates the product then POSTs to add_roll.
// On success: calls fillSlot(seq, product) which replaces the
// matching empty slot IN-PLACE — no appending, no reordering.
// ─────────────────────────────────────────────────────────────
async function lookupAndAdd(lot, coil, roll) {
    if (!PALLET_ID) return;
    if (rollCount >= MAX_ROLLS) {
        showFeedback(`Pallet is full (${MAX_ROLLS}/${MAX_ROLLS}).`, false);
        return;
    }

    // Step 1: look up the product
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

    // Client-side guards
    if (p.is_voided == 1) {
        showFeedback('This roll has been voided.', false);
        return;
    }
    if (p.stock_counted != 1) {
        showFeedback(`Roll ${lot} ${coil} ${roll} — actual length not saved yet.`, false);
        return;
    }
    if (p.pallet_id) {
        showFeedback(`Already on pallet ${escHtml(p.pallet_no)}.`, false);
        return;
    }

    // Step 2: add the roll to the pallet
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

    // Step 3: update the UI — fill the correct slot in-place
    rollCount = ad.roll_count;
    fillSlot(ad.seq, p);          // <── KEY FIX: targets slot by seq number
    updateProgress(rollCount);
    showFeedback(
        `✓ Added: ${escHtml(lot)} ${escHtml(coil)} – R-${escHtml(roll)} (slot ${ad.seq})`,
        true
    );

    // If the first roll was added, reload so constraint badges appear
    if (ad.seq === 1) setTimeout(() => location.reload(), 1200);
}

// ─────────────────────────────────────────────────────────────
// fillSlot(seq, product)
//
// THE CORE FIX:
//   Instead of appending a new card to #rollList, we find the
//   existing slot element with id="slot{seq}" and REPLACE its
//   innerHTML in-place.  The slot stays exactly where it was
//   in the DOM — slot 2 is always between slot 1 and slot 3.
// ─────────────────────────────────────────────────────────────
function fillSlot(seq, p) {
    const slotEl = document.getElementById('slot' + seq);
    if (!slotEl) return;   // safety: should never happen

    const len = parseFloat(p.actual_length) > 0 ? p.actual_length : p.length;

    // Remove the "empty" class so styling changes to filled
    slotEl.classList.remove('slot-empty');
    slotEl.setAttribute('data-filled', '1');

    // Replace the inner content — the outer <div id="slot{seq}"> stays put
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
                ${(+len).toFixed(1)}m
            </div>
        </div>
        <button type="button"
                class="btn btn-outline-danger btn-sm"
                title="Remove this roll from the pallet"
                data-product-id="${p.id}"
                onclick="removeRoll(${PALLET_ID}, ${p.id}, ${seq}, this)">
            <i class="bi bi-x-lg"></i>
        </button>
    `;

    // Brief green flash to confirm the scan
    slotEl.classList.add('scan-flash');
    slotEl.addEventListener('animationend', () => slotEl.classList.remove('scan-flash'), { once: true });
}

// ─────────────────────────────────────────────────────────────
// removeRoll(palletId, productId, seq, btn)
//
// THE CORE FIX (remove side):
//   We know the slot number (seq) from the data attribute on
//   the remove button, so we can restore EXACTLY that slot to
//   its empty state without rebuilding any other slot.
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

    // Restore the slot to its empty state IN-PLACE.
    // After PalletManager::removeRollFromPallet() re-sequences, the
    // removed position may now be the last occupied+1 slot — but
    // visually the simplest and most correct thing is to restore
    // THIS slot (seq) to empty and then re-sequence all slot labels.
    clearSlot(seq);

    // Re-sequence: walk all 8 slots and renumber filled ones 1..N,
    // then update the empty slot labels to match their position.
    resequenceSlots();

    showFeedback(d.msg, true);

    // If pallet is now empty, reload so constraint strip clears
    if (rollCount === 0) setTimeout(() => location.reload(), 1000);
}

// ─────────────────────────────────────────────────────────────
// clearSlot(seq)
// Restores a single slot element to its "empty" visual state.
// ─────────────────────────────────────────────────────────────
function clearSlot(seq) {
    const slotEl = document.getElementById('slot' + seq);
    if (!slotEl) return;

    slotEl.classList.add('slot-empty');
    slotEl.setAttribute('data-filled', '0');
    slotEl.innerHTML = `
        <span class="roll-seq">${seq}</span>
        <span style="font-size:13px;">Empty slot ${seq}</span>
    `;
}

// ─────────────────────────────────────────────────────────────
// resequenceSlots()
//
// After a removal, PalletManager compacts seq numbers on the
// DB side (1,2,3 with no gaps).  We mirror that on the client:
//   1. Collect all filled slots in DOM order (data-slot asc).
//   2. Re-number their visible bubble 1..N.
//   3. Update their onclick seq argument so future removes work.
// ─────────────────────────────────────────────────────────────
function resequenceSlots() {
    // Gather all slots in order
    const allSlots = Array.from(
        document.querySelectorAll('#rollList [data-slot]')
    ).sort((a, b) => +a.dataset.slot - +b.dataset.slot);

    let filledCount = 0;
    allSlots.forEach(slotEl => {
        const slotNo = +slotEl.dataset.slot;

        if (slotEl.dataset.filled === '1') {
            filledCount++;
            const newSeq = filledCount;

            // Update the visible bubble number
            const bubble = slotEl.querySelector('.roll-seq');
            if (bubble) bubble.textContent = newSeq;

            // Update the remove-button's seq argument so the next
            // removeRoll() call passes the correct (compacted) slot
            const removeBtn = slotEl.querySelector('button[data-product-id]');
            if (removeBtn) {
                const pid = removeBtn.getAttribute('data-product-id');
                removeBtn.setAttribute(
                    'onclick',
                    `removeRoll(${PALLET_ID}, ${pid}, ${newSeq}, this)`
                );
            }
        } else {
            // Keep empty-slot label in sync with its fixed position
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

<?php include 'footer.php'; ?>
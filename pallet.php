<?php
// pallet.php — PLACEMENT: C:\Apache24\htdocs\slitting_system\pallet.php
session_start();

if (!isset($_SESSION['role'])) { header("Location: login.php"); exit; }
if ($_SESSION['role'] !== 'slitting') { die("Access denied"); }

include 'config.php';

// ============================================================
// INLINED PALLET HELPERS — no require_once needed
// ============================================================
function generatePalletNo(mysqli $conn): string {
    $date = date('Ymd'); $prefix = "PLT-{$date}-";
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM pallets WHERE pallet_no LIKE ?");
    $like = $prefix . '%'; $stmt->bind_param("s", $like); $stmt->execute();
    $seq = (int)$stmt->get_result()->fetch_assoc()['cnt'] + 1; $stmt->close();
    return $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
}
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
function findPalletByRoll(mysqli $conn, string $lot, string $coil, string $roll): ?array {
    $stmt = $conn->prepare("
        SELECT p.*, pi.slitting_product_id AS scanned_product_id
        FROM slitting_product sp
        JOIN pallet_items pi ON pi.slitting_product_id = sp.id
        JOIN pallets p ON p.id = pi.pallet_id
        WHERE sp.lot_no = ? AND sp.coil_no = ? AND sp.roll_no = ?
        LIMIT 1
    ");
    $stmt->bind_param("sss", $lot, $coil, $roll); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}
function addRollToPallet(mysqli $conn, int $pallet_id, int $product_id, string $performed_by = 'slitting'): array {
    $pallet = getPallet($conn, $pallet_id);
    if (!$pallet) return ['ok' => false, 'msg' => 'Pallet not found.', 'count' => 0];
    if ($pallet['status'] !== 'building') return ['ok' => false, 'msg' => 'Pallet is already closed (status: ' . $pallet['status'] . ').', 'count' => 0];
    $stmt = $conn->prepare("SELECT id, stock_counted, status, is_voided FROM slitting_product WHERE id = ?");
    $stmt->bind_param("i", $product_id); $stmt->execute();
    $roll = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$roll) return ['ok' => false, 'msg' => 'Product roll not found.', 'count' => 0];
    if ((int)$roll['is_voided'] === 1) return ['ok' => false, 'msg' => 'This roll has been voided.', 'count' => 0];
    if ((int)$roll['stock_counted'] !== 1) return ['ok' => false, 'msg' => 'Actual length not saved yet. Update the roll first.', 'count' => 0];
    $stmt = $conn->prepare("SELECT pi.pallet_id, p.pallet_no FROM pallet_items pi JOIN pallets p ON p.id = pi.pallet_id WHERE pi.slitting_product_id = ?");
    $stmt->bind_param("i", $product_id); $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($existing) return ['ok' => false, 'msg' => 'Already on pallet ' . $existing['pallet_no'] . '.', 'count' => 0];
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM pallet_items WHERE pallet_id = ?");
    $stmt->bind_param("i", $pallet_id); $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['cnt']; $stmt->close();
    if ($count >= 6) return ['ok' => false, 'msg' => 'Pallet is full (maximum 6 rolls).', 'count' => $count];
    $seq = $count + 1;
    $stmt = $conn->prepare("INSERT INTO pallet_items (pallet_id, slitting_product_id, seq, added_by) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiis", $pallet_id, $product_id, $seq, $performed_by);
    try { $stmt->execute(); $stmt->close(); }
    catch (Throwable $e) { return ['ok' => false, 'msg' => 'Database error: ' . $e->getMessage(), 'count' => $count]; }
    return ['ok' => true, 'msg' => "Roll added at position {$seq}.", 'count' => $seq];
}
function removeRollFromPallet(mysqli $conn, int $pallet_id, int $product_id): array {
    $pallet = getPallet($conn, $pallet_id);
    if (!$pallet || $pallet['status'] !== 'building') return ['ok' => false, 'msg' => 'Pallet cannot be modified.'];
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM pallet_items WHERE pallet_id = ? AND slitting_product_id = ?");
        $stmt->bind_param("ii", $pallet_id, $product_id); $stmt->execute(); $stmt->close();
        $stmt = $conn->prepare("SELECT id FROM pallet_items WHERE pallet_id = ? ORDER BY seq ASC");
        $stmt->bind_param("i", $pallet_id); $stmt->execute();
        $ids = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        foreach ($ids as $idx => $row) { $newSeq = $idx + 1; $conn->query("UPDATE pallet_items SET seq=$newSeq WHERE id={$row['id']}"); }
        $conn->commit();
        return ['ok' => true, 'msg' => 'Roll removed from pallet.'];
    } catch (Throwable $e) { $conn->rollback(); return ['ok' => false, 'msg' => $e->getMessage()]; }
}
function sendPalletToQC(mysqli $conn, int $pallet_id): array {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM pallet_items WHERE pallet_id = ?");
    $stmt->bind_param("i", $pallet_id); $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['cnt']; $stmt->close();
    if ($count < 1) return ['ok' => false, 'msg' => 'Cannot send an empty pallet to QC.'];
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE pallets SET status = 'pending_qc' WHERE id = ? AND status = 'building'");
        $stmt->bind_param("i", $pallet_id); $stmt->execute();
        if ($stmt->affected_rows === 0) throw new RuntimeException('Pallet is not in building state.');
        $stmt->close();
        $stmt = $conn->prepare("UPDATE slitting_product sp JOIN pallet_items pi ON pi.slitting_product_id = sp.id SET sp.status = 'WAITING', sp.date_out = NOW() WHERE pi.pallet_id = ?");
        $stmt->bind_param("i", $pallet_id); $stmt->execute(); $stmt->close();
        $conn->commit();
        return ['ok' => true, 'msg' => "Pallet sent to QC with {$count} roll(s)."];
    } catch (Throwable $e) { $conn->rollback(); return ['ok' => false, 'msg' => $e->getMessage()]; }
}
function approvePallet(mysqli $conn, int $pallet_id): array {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE pallets SET status = 'approved', qc_comment = NULL WHERE id = ? AND status = 'pending_qc'");
        $stmt->bind_param("i", $pallet_id); $stmt->execute();
        if ($stmt->affected_rows === 0) throw new RuntimeException('Pallet is not pending QC.');
        $stmt->close();
        $stmt = $conn->prepare("UPDATE slitting_product sp JOIN pallet_items pi ON pi.slitting_product_id = sp.id SET sp.status = 'APPROVED' WHERE pi.pallet_id = ?");
        $stmt->bind_param("i", $pallet_id); $stmt->execute(); $stmt->close();
        $conn->commit(); return ['ok' => true];
    } catch (Throwable $e) { $conn->rollback(); return ['ok' => false, 'msg' => $e->getMessage()]; }
}
function rejectPallet(mysqli $conn, int $pallet_id, string $comment): array {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE pallets SET status = 'rejected', qc_comment = ? WHERE id = ? AND status = 'pending_qc'");
        $stmt->bind_param("si", $comment, $pallet_id); $stmt->execute();
        if ($stmt->affected_rows === 0) throw new RuntimeException('Pallet is not pending QC.');
        $stmt->close();
        $stmt = $conn->prepare("UPDATE slitting_product sp JOIN pallet_items pi ON pi.slitting_product_id = sp.id SET sp.status = 'REJECTED', sp.qc_comment = ? WHERE pi.pallet_id = ?");
        $stmt->bind_param("si", $comment, $pallet_id); $stmt->execute(); $stmt->close();
        $conn->commit(); return ['ok' => true];
    } catch (Throwable $e) { $conn->rollback(); return ['ok' => false, 'msg' => $e->getMessage()]; }
}
function deliverPallet(mysqli $conn, int $pallet_id): array {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE pallets SET status = 'delivered', delivered_at = NOW() WHERE id = ? AND status = 'approved'");
        $stmt->bind_param("i", $pallet_id); $stmt->execute();
        if ($stmt->affected_rows === 0) throw new RuntimeException('Pallet is not in approved state.');
        $stmt->close();
        $stmt = $conn->prepare("UPDATE slitting_product sp JOIN pallet_items pi ON pi.slitting_product_id = sp.id SET sp.status = 'DELIVERED', sp.delivered_at = NOW() WHERE pi.pallet_id = ?");
        $stmt->bind_param("i", $pallet_id); $stmt->execute(); $stmt->close();
        $conn->commit(); return ['ok' => true];
    } catch (Throwable $e) { $conn->rollback(); return ['ok' => false, 'msg' => $e->getMessage()]; }
}
// ============================================================
// END INLINED HELPERS
// ============================================================

$performed_by = $_SESSION['role'] ?? 'slitting';

// ── POST: Create new pallet ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_pallet') {
    $pallet_no = generatePalletNo($conn);
    $stmt = $conn->prepare("INSERT INTO pallets (pallet_no, status, created_by) VALUES (?, 'building', ?)");
    $stmt->bind_param("ss", $pallet_no, $performed_by);
    $stmt->execute();
    $new_id = $conn->insert_id;
    $stmt->close();
    header("Location: pallet.php?pallet_id={$new_id}&success=created");
    exit;
}

// ── POST: Add roll (AJAX) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_roll') {
    $pallet_id  = intval($_POST['pallet_id'] ?? 0);
    $product_id = intval($_POST['product_id'] ?? 0);
    header('Content-Type: application/json');
    if (!$pallet_id || !$product_id) { echo json_encode(['ok' => false, 'msg' => 'Missing pallet or product ID.']); exit; }
    echo json_encode(addRollToPallet($conn, $pallet_id, $product_id, $performed_by));
    exit;
}

// ── POST: Remove roll (AJAX) ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_roll') {
    $pallet_id  = intval($_POST['pallet_id'] ?? 0);
    $product_id = intval($_POST['product_id'] ?? 0);
    header('Content-Type: application/json');
    echo json_encode(removeRollFromPallet($conn, $pallet_id, $product_id));
    exit;
}

// ── POST: Send to QC ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_to_qc') {
    $pallet_id = intval($_POST['pallet_id'] ?? 0);
    $result    = sendPalletToQC($conn, $pallet_id);
    if ($result['ok']) { header("Location: pallet.php?success=sent_to_qc"); }
    else               { header("Location: pallet.php?pallet_id={$pallet_id}&error=" . urlencode($result['msg'])); }
    exit;
}

// ── POST: Deliver pallet ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deliver_pallet') {
    $pallet_id = intval($_POST['pallet_id'] ?? 0);
    $result    = deliverPallet($conn, $pallet_id);
    if ($result['ok']) { header("Location: pallet.php?success=delivered"); }
    else               { header("Location: pallet.php?error=" . urlencode($result['msg'])); }
    exit;
}

// ── GET: AJAX product lookup ─────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'lookup_product') {
    header('Content-Type: application/json');
    $lot  = trim($_GET['lot']  ?? '');
    $coil = trim($_GET['coil'] ?? '');
    $roll = trim($_GET['roll'] ?? '');
    if (!$lot || !$coil) { echo json_encode(['ok' => false, 'msg' => 'Incomplete QR data.']); exit; }
    $stmt = $conn->prepare("
        SELECT sp.id, sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
               sp.width, sp.actual_length, sp.length,
               sp.stock_counted, sp.status, sp.is_voided,
               pi.pallet_id, p.pallet_no
        FROM slitting_product sp
        LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
        LEFT JOIN pallets p ON p.id = pi.pallet_id
        WHERE sp.lot_no = ? AND sp.coil_no = ? AND sp.roll_no = ?
          AND (sp.is_voided = 0 OR sp.is_voided IS NULL)
        LIMIT 1
    ");
    $stmt->bind_param("sss", $lot, $coil, $roll); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row) { echo json_encode(['ok' => false, 'msg' => "Roll not found: {$lot} {$coil} {$roll}"]); exit; }
    echo json_encode(['ok' => true, 'product' => $row]);
    exit;
}

// ── Page data ────────────────────────────────────────────────
$active_pallet_id = intval($_GET['pallet_id'] ?? 0);
$active_pallet    = $active_pallet_id ? getPallet($conn, $active_pallet_id) : null;
$active_items     = $active_pallet    ? getPalletItems($conn, $active_pallet_id) : [];

$open_pallets = $conn->query(
    "SELECT p.*, COUNT(pi.id) AS item_count
     FROM pallets p
     LEFT JOIN pallet_items pi ON pi.pallet_id = p.id
     WHERE p.status = 'building'
     GROUP BY p.id
     ORDER BY p.created_at DESC
     LIMIT 20"
)->fetch_all(MYSQLI_ASSOC);

$all_pallets = $conn->query(
    "SELECT p.*, COUNT(pi.id) AS item_count
     FROM pallets p
     LEFT JOIN pallet_items pi ON pi.pallet_id = p.id
     GROUP BY p.id
     ORDER BY p.created_at DESC
     LIMIT 50"
)->fetch_all(MYSQLI_ASSOC);

$page_title = 'Pallet Management';
include 'header.php';
?>

<style>
.pallet-sidebar   { position:sticky; top:20px; }
.roll-card        { border:1px solid var(--bs-border-color); border-radius:8px; padding:12px 14px;
                    background:#fff; margin-bottom:8px; transition:box-shadow .15s; }
.roll-card:hover  { box-shadow:0 2px 8px rgba(0,0,0,.1); }
.roll-seq         { width:28px; height:28px; border-radius:50%; background:#0d6efd; color:#fff;
                    display:inline-flex; align-items:center; justify-content:center;
                    font-size:12px; font-weight:700; flex-shrink:0; }
.pallet-progress  { height:8px; border-radius:4px; background:#e9ecef; overflow:hidden; }
.pallet-progress-bar { height:100%; border-radius:4px; background:#0d6efd; transition:width .3s; }
.scan-flash       { animation:flashBg .5s ease-out; }
@keyframes flashBg { 0%{background:#d1fae5} 100%{background:transparent} }
.badge-building   { background:#e0f2fe; color:#0369a1; }
.badge-pending_qc { background:#fef3c7; color:#92400e; }
.badge-approved   { background:#dcfce7; color:#166534; }
.badge-rejected   { background:#fee2e2; color:#991b1b; }
.badge-delivered  { background:#d1fae5; color:#065f46; }
.pallet-table td  { vertical-align:middle; font-size:13px; }
.pallet-table th  { font-size:11px; text-transform:uppercase; letter-spacing:.5px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-archive me-2"></i>Pallet Management</h2>
    <form method="post">
        <input type="hidden" name="action" value="create_pallet">
        <button type="submit" class="btn btn-success shadow-sm">
            <i class="bi bi-plus-lg me-1"></i> New Pallet
        </button>
    </form>
</div>

<?php if (isset($_GET['success'])): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i>
    <?php $msgs = ['created'=>'New pallet created. Start scanning rolls in.','sent_to_qc'=>'Pallet sent to QC successfully.','delivered'=>'Pallet marked as delivered.']; echo htmlspecialchars($msgs[$_GET['success']] ?? 'Done.'); ?>
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
        <?php if ($active_pallet && $active_pallet['status'] === 'building'): ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-qr-code-scan me-2"></i><strong><?= htmlspecialchars($active_pallet['pallet_no']) ?></strong></span>
                <span class="badge bg-white text-primary" id="rollCountBadge"><?= count($active_items) ?> / 6 rolls</span>
            </div>
            <div class="card-body p-4">
                <div class="pallet-progress mb-3">
                    <div class="pallet-progress-bar" id="palletProgressBar" style="width:<?= (count($active_items) / 6 * 100) ?>%"></div>
                </div>
                <div class="alert alert-info py-2 mb-3">
                    <i class="bi bi-qr-code-scan me-1"></i>
                    Scanner ready — scan any product QR code to add it to this pallet. Or type Lot + Coil + Roll below.
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-3"><input type="text" id="manLot"  class="form-control form-control-sm" placeholder="Lot No"  autocomplete="off"></div>
                    <div class="col-md-3"><input type="text" id="manCoil" class="form-control form-control-sm" placeholder="Coil No" autocomplete="off"></div>
                    <div class="col-md-3"><input type="text" id="manRoll" class="form-control form-control-sm" placeholder="Roll No" autocomplete="off"></div>
                    <div class="col-md-3">
                        <button type="button" class="btn btn-primary btn-sm w-100" onclick="manualLookup()">
                            <i class="bi bi-search me-1"></i> Find & Add
                        </button>
                    </div>
                </div>
                <div id="scanFeedback" class="mb-3" style="min-height:40px;"></div>
                <div id="rollList">
                    <?php foreach ($active_items as $item): ?>
                    <div class="roll-card d-flex align-items-center gap-3" id="rollCard<?= $item['product_id'] ?>">
                        <span class="roll-seq"><?= $item['seq'] ?></span>
                        <div class="flex-grow-1">
                            <div class="fw-bold small">
                                <?= htmlspecialchars($item['lot_no']) ?> <?= htmlspecialchars($item['coil_no']) ?>
                                – <?= str_replace('R','R-', htmlspecialchars($item['roll_no'])) ?>
                            </div>
                            <div class="text-muted" style="font-size:11px;">
                                <?= htmlspecialchars($item['product']) ?> | <?= number_format($item['width']) ?>mm |
                                <?= number_format($item['actual_length'] ?: $item['length'], 1) ?>m
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-danger btn-sm"
                                onclick="removeRoll(<?= $active_pallet_id ?>, <?= $item['product_id'] ?>, this)">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                    <?php endforeach; ?>
                    <?php for ($s = count($active_items) + 1; $s <= 6; $s++): ?>
                    <div class="roll-card d-flex align-items-center gap-3 text-muted slot-empty" id="slot<?= $s ?>">
                        <span class="roll-seq" style="background:#dee2e6;color:#6c757d;"><?= $s ?></span>
                        <span style="font-size:13px;">Empty slot <?= $s ?></span>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
            <div class="card-footer bg-light d-flex justify-content-between align-items-center">
                <span class="text-muted small">Created: <?= date('d M Y H:i', strtotime($active_pallet['created_at'])) ?></span>
                <div class="d-flex gap-2">
                    <a href="pallet.php" class="btn btn-outline-secondary btn-sm">Close panel</a>
                    <form method="post">
                        <input type="hidden" name="action"    value="send_to_qc">
                        <input type="hidden" name="pallet_id" value="<?= $active_pallet_id ?>">
                        <button type="submit" class="btn btn-warning btn-sm fw-bold" id="sendToQcBtn"
                                <?= count($active_items) < 1 ? 'disabled' : '' ?>
                                onclick="return confirm('Send this pallet to QC? You cannot add more rolls after this.')">
                            <i class="bi bi-send me-1"></i> Send to QC
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php elseif ($active_pallet): ?>
        <div class="alert alert-secondary">
            <strong><?= htmlspecialchars($active_pallet['pallet_no']) ?></strong> —
            <span class="badge badge-<?= $active_pallet['status'] ?>"><?= strtoupper(str_replace('_', ' ', $active_pallet['status'])) ?></span>
            — cannot be edited. <a href="pallet.php" class="ms-2">← Back to list</a>
        </div>
        <?php else: ?>
        <div class="card shadow-sm border-0 text-center py-5">
            <div class="text-muted mb-3"><i class="bi bi-archive" style="font-size:3rem;"></i></div>
            <h5 class="text-muted">No pallet selected</h5>
            <p class="text-muted small">Click an open pallet on the right, or create a new one.</p>
            <form method="post" class="mt-2">
                <input type="hidden" name="action" value="create_pallet">
                <button type="submit" class="btn btn-success"><i class="bi bi-plus-lg me-1"></i> Create New Pallet</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- RIGHT: Sidebar -->
    <div class="col-md-5 pallet-sidebar">
        <?php if (!empty($open_pallets)): ?>
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-dark text-white fw-bold py-2">
                <i class="bi bi-list-task me-2"></i>Open Pallets
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($open_pallets as $op): ?>
                <a href="pallet.php?pallet_id=<?= $op['id'] ?>"
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= ($op['id'] == $active_pallet_id) ? 'active' : '' ?>">
                    <div>
                        <div class="fw-bold small"><?= htmlspecialchars($op['pallet_no']) ?></div>
                        <div style="font-size:11px;" class="<?= ($op['id'] == $active_pallet_id) ? 'text-white-50' : 'text-muted' ?>"><?= date('d M H:i', strtotime($op['created_at'])) ?></div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span style="font-size:11px;"><?= $op['item_count'] ?>/6</span>
                        <div class="pallet-progress" style="width:60px;">
                            <div class="pallet-progress-bar" style="width:<?= ($op['item_count'] / 6 * 100) ?>%;<?= ($op['id'] == $active_pallet_id) ? 'background:#fff;' : '' ?>"></div>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-dark text-white fw-bold py-2">
                <i class="bi bi-table me-2"></i>All Pallets (Recent 50)
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover pallet-table mb-0">
                    <thead class="table-light">
                        <tr><th>Pallet No</th><th>Status</th><th>Rolls</th><th>Created</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($all_pallets as $pal): ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($pal['pallet_no']) ?></td>
                        <td><span class="badge badge-<?= $pal['status'] ?>"><?= strtoupper(str_replace('_',' ',$pal['status'])) ?></span></td>
                        <td><?= $pal['item_count'] ?>/6</td>
                        <td class="text-muted"><?= date('d M Y', strtotime($pal['created_at'])) ?></td>
                        <td>
                            <?php if ($pal['status'] === 'building'): ?>
                            <a href="pallet.php?pallet_id=<?= $pal['id'] ?>" class="btn btn-outline-primary btn-sm">Open</a>
                            <?php elseif ($pal['status'] === 'approved'): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Mark as DELIVERED?')">
                                <input type="hidden" name="action"    value="deliver_pallet">
                                <input type="hidden" name="pallet_id" value="<?= $pal['id'] ?>">
                                <button class="btn btn-success btn-sm">Deliver</button>
                            </form>
                            <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<input id="qrScanInput" type="text" inputmode="none" style="position:fixed;left:-9999px;opacity:0;" autofocus>

<script>
const PALLET_ID = <?= $active_pallet_id ?: 'null' ?>;
const MAX_ROLLS = 6;
let   rollCount = <?= count($active_items) ?>;
const qrInput   = document.getElementById('qrScanInput');

setInterval(() => {
    const active = document.activeElement;
    if (!['INPUT','TEXTAREA','SELECT'].includes(active.tagName) || active === qrInput) qrInput.focus();
}, 600);

qrInput.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter') return;
    const raw = this.value.trim(); this.value = '';
    if (!raw || !PALLET_ID) return;
    processQR(raw);
});

function parseQR(raw) {
    const parts = {};
    raw.split(';').forEach(p => {
        const idx = p.indexOf('=');
        if (idx > -1) parts[p.substring(0, idx).trim().toUpperCase()] = decodeURIComponent(p.substring(idx + 1).trim());
    });
    return { lot: parts.LOT || '', coil: parts.COIL || '', roll: parts.ROLL || '' };
}

async function processQR(raw) {
    const { lot, coil, roll } = parseQR(raw);
    if (!lot || !coil) { showFeedback('Could not parse QR: ' + escHtml(raw), false); return; }
    await lookupAndAdd(lot, coil, roll);
}

async function manualLookup() {
    const lot  = document.getElementById('manLot').value.trim();
    const coil = document.getElementById('manCoil').value.trim();
    const roll = document.getElementById('manRoll').value.trim();
    if (!lot || !coil || !roll) { showFeedback('Please enter Lot No, Coil No and Roll No.', false); return; }
    await lookupAndAdd(lot, coil, roll);
    document.getElementById('manLot').value = document.getElementById('manCoil').value = document.getElementById('manRoll').value = '';
}

async function lookupAndAdd(lot, coil, roll) {
    if (!PALLET_ID) return;
    if (rollCount >= MAX_ROLLS) { showFeedback('Pallet is full (6 rolls maximum).', false); return; }
    const url = `pallet.php?ajax=lookup_product&lot=${encodeURIComponent(lot)}&coil=${encodeURIComponent(coil)}&roll=${encodeURIComponent(roll)}`;
    let lookupData;
    try { lookupData = await fetch(url).then(r => r.json()); }
    catch { showFeedback('Network error during lookup.', false); return; }
    if (!lookupData.ok) { showFeedback(lookupData.msg, false); return; }
    const p = lookupData.product;
    if (p.is_voided == 1)    { showFeedback('This roll has been voided.', false); return; }
    if (p.stock_counted != 1){ showFeedback(`Roll ${lot} ${coil} ${roll} — actual length not saved yet.`, false); return; }
    if (p.pallet_id)         { showFeedback(`Already on pallet ${escHtml(p.pallet_no)}.`, false); return; }
    const fd = new FormData();
    fd.append('action', 'add_roll'); fd.append('pallet_id', PALLET_ID); fd.append('product_id', p.id);
    let addData;
    try { addData = await fetch('pallet.php', { method: 'POST', body: fd }).then(r => r.json()); }
    catch { showFeedback('Network error while adding roll.', false); return; }
    if (!addData.ok) { showFeedback(addData.msg, false); return; }
    rollCount = addData.count;
    addRollCard(addData.count, p);
    updateProgress(rollCount);
    showFeedback(`✓ Added: ${escHtml(lot)} ${escHtml(coil)} – R${escHtml(roll)} (position ${addData.count})`, true);
}

function addRollCard(seq, p) {
    const emptySlot = document.getElementById('slot' + seq);
    if (emptySlot) emptySlot.remove();
    const len  = p.actual_length > 0 ? p.actual_length : p.length;
    const roll = p.roll_no.replace('R', 'R-');
    const html = `<div class="roll-card d-flex align-items-center gap-3 scan-flash" id="rollCard${p.id}">
        <span class="roll-seq">${seq}</span>
        <div class="flex-grow-1">
            <div class="fw-bold small">${escHtml(p.lot_no)} ${escHtml(p.coil_no)} – ${escHtml(roll)}</div>
            <div class="text-muted" style="font-size:11px;">${escHtml(p.product)} | ${Number(p.width).toFixed(0)}mm | ${Number(len).toFixed(1)}m</div>
        </div>
        <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeRoll(${PALLET_ID}, ${p.id}, this)">
            <i class="bi bi-x"></i>
        </button>
    </div>`;
    document.getElementById('rollList').insertAdjacentHTML('beforeend', html);
}

async function removeRoll(palletId, productId, btn) {
    if (!confirm('Remove this roll from the pallet?')) return;
    const fd = new FormData();
    fd.append('action', 'remove_roll'); fd.append('pallet_id', palletId); fd.append('product_id', productId);
    const data = await fetch('pallet.php', { method: 'POST', body: fd }).then(r => r.json());
    if (data.ok) {
        document.getElementById('rollCard' + productId)?.remove();
        rollCount = Math.max(0, rollCount - 1);
        updateProgress(rollCount);
        const newSlot = rollCount + 1;
        document.getElementById('rollList').insertAdjacentHTML('beforeend',
            `<div class="roll-card d-flex align-items-center gap-3 text-muted slot-empty" id="slot${newSlot}">
                <span class="roll-seq" style="background:#dee2e6;color:#6c757d;">${newSlot}</span>
                <span style="font-size:13px;">Empty slot ${newSlot}</span>
            </div>`);
        reNumberSeq();
        showFeedback('Roll removed.', true);
    } else { showFeedback(data.msg, false); }
}

function reNumberSeq() {
    let n = 1;
    document.querySelectorAll('#rollList .roll-seq').forEach(el => { if (!el.closest('.slot-empty')) el.textContent = n++; });
}
function updateProgress(count) {
    document.getElementById('palletProgressBar').style.width = (count / MAX_ROLLS * 100) + '%';
    document.getElementById('rollCountBadge').textContent    = count + ' / 6 rolls';
    const btn = document.getElementById('sendToQcBtn');
    if (btn) btn.disabled = (count < 1);
}
function showFeedback(msg, ok) {
    const el = document.getElementById('scanFeedback');
    el.innerHTML = `<div class="alert alert-${ok ? 'success' : 'danger'} py-2 mb-0 d-flex align-items-center gap-2"><i class="bi bi-${ok ? 'check-circle-fill' : 'exclamation-triangle-fill'}"></i><span>${msg}</span></div>`;
    setTimeout(() => { el.innerHTML = ''; }, 4000);
}
function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
</script>
<?php include 'footer.php'; ?>
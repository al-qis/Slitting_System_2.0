<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if (!in_array($_SESSION['role'], ['slitting','mkl3'], true)) {
    die("Access denied for this role.");
}

include 'config.php';

// ── Handle Action ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sfc_id']) && isset($_POST['action'])) {
    $sfcId  = (int)$_POST['sfc_id'];
    $action = $_POST['action'];

    $stmt = $conn->prepare("SELECT * FROM sfc WHERE sfc_id = ? AND date_out IS NULL AND is_deleted = 0");
    $stmt->bind_param("i", $sfcId);
    $stmt->execute();
    $sfc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($sfc) {
        $conn->begin_transaction();
        try {
            $original_source = 'sfc';

            // ── DELETE — error-correction only, no downstream inserts ──
            // Only reachable for entries that are still active (date_out
            // IS NULL, checked above), i.e. nothing has been produced
            // from this SFC item yet. Soft-deleted so the audit trail
            // (and any accidental-delete recovery) is preserved; every
            // stock count/listing query below already excludes
            // is_deleted = 1 rows, so live SFC metrics stay accurate
            // automatically — there's no separate stored balance to fix.
            if ($action === 'DELETE') {
                $deletedBy = $_SESSION['role'] ?? 'system';
                $delStmt = $conn->prepare("
                    UPDATE sfc SET is_deleted = 1, deleted_at = NOW(), deleted_by = ?
                    WHERE sfc_id = ?
                ");
                $delStmt->bind_param("si", $deletedBy, $sfcId);
                $delStmt->execute();
                $delStmt->close();

                log_source_tracking($conn, $sfcId, 'sfc', $original_source, 'sfc', 'DELETED_FROM_SFC');

                $conn->commit();
                header("Location: sfc.php?success=1&msg=deleted");
                exit;
            }

            if ($action === 'RECOIL') {
                $stmt = $conn->prepare("INSERT INTO recoiling_product
                    (mother_id, product, lot_no, coil_no, roll_no, width, length, status, date_in, original_source)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), ?)");
                $stmt->bind_param("issssdds",
                    $sfc['mother_id'],
                    $sfc['product'], $sfc['lot_no'], $sfc['coil_no'],
                    $sfc['roll_no'], $sfc['width'],  $sfc['length'], $original_source);
                $stmt->execute();
                $stmt->close();
                log_source_tracking($conn, 0, 'recoiling_product', $original_source, 'sfc', 'RECOIL_FROM_SFC');

            } elseif ($action === 'RESLIT') {
                $stmt = $conn->prepare("INSERT INTO reslit_product
                    (mother_id, product, lot_no, coil_no, roll_no, width, length, status, date_in, original_source)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), ?)");
                $stmt->bind_param("issssdds",
                    $sfc['mother_id'],
                    $sfc['product'], $sfc['lot_no'], $sfc['coil_no'],
                    $sfc['roll_no'], $sfc['width'],  $sfc['length'], $original_source);
                $stmt->execute();
                $stmt->close();
                log_source_tracking($conn, 0, 'reslit_product', $original_source, 'sfc', 'RESLIT_FROM_SFC');

            } elseif ($action === 'SELL') {
                $stmt = $conn->prepare("INSERT INTO slitting_product
                    (mother_id, product, lot_no, coil_no, roll_no, width, length,
                     status, date_in, date_out, cut_type, source, original_source)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'WAITING', NOW(), NOW(), 'sfc_sell', 'sfc', ?)");
                $stmt->bind_param("issssdds",
                    $sfc['mother_id'],
                    $sfc['product'], $sfc['lot_no'], $sfc['coil_no'],
                    $sfc['roll_no'], $sfc['width'],  $sfc['length'], $original_source);
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
        die("SFC not found, already used, or already deleted.");
    }
}

// ── PIN verify via AJAX ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_pin'])) {
    $enteredPin    = trim($_POST['verify_pin']);
    $supervisorPin = '1234';
    header('Content-Type: application/json');
    echo json_encode(['success' => ($enteredPin === $supervisorPin)]);
    exit;
}

function log_source_tracking($conn, $product_id, $table_name, $original_source, $current_source, $action) {
    $stmt = $conn->prepare("INSERT INTO source_tracking_log
        (product_id, table_name, original_source, current_source, action)
        VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $product_id, $table_name, $original_source, $current_source, $action);
    $stmt->execute();
    $stmt->close();
}

// ── Helper: get output rolls produced FROM a SFC item ────────
// Checks slitting_product, reslit_product, recoiling_product
// matching by lot_no + coil_no + roll_no (the SFC roll that was sent)
function getSfcOutputs(mysqli $conn, array $sfc): array {
    $lot  = $conn->real_escape_string($sfc['lot_no']  ?? '');
    $coil = $conn->real_escape_string($sfc['coil_no'] ?? '');
    $roll = $conn->real_escape_string($sfc['roll_no'] ?? '');
    $out  = [];

    // 1. Reslit outputs — find reslit_product matching this SFC's lot/coil/roll
    //    then get all reslit_rolls from that parent
    $rp = $conn->query("
        SELECT rp.id, rp.status, rp.lot_no, rp.coil_no, rp.roll_no
        FROM reslit_product rp
        WHERE rp.lot_no='$lot' AND rp.coil_no='$coil' AND rp.roll_no='$roll'
          AND rp.original_source='sfc'
        ORDER BY rp.id DESC LIMIT 1
    ")->fetch_assoc();

    if ($rp) {
        $rpId    = intval($rp['id']);
        $rr_res  = $conn->query("
            SELECT rr.*, rp.lot_no AS parent_lot, rp.coil_no AS parent_coil
            FROM reslit_rolls rr
            JOIN reslit_product rp ON rp.id = rr.parent_id
            WHERE rr.parent_id = $rpId
            ORDER BY rr.id ASC
        ");
        $rolls = [];
        if ($rr_res) while ($r = $rr_res->fetch_assoc()) $rolls[] = $r;

        $out[] = [
            'process'    => 'RESLIT',
            'parent_id'  => $rpId,
            'status'     => $rp['status'],
            'rolls'      => $rolls,
        ];
    }

    // 2. Recoiling outputs — find recoiling_product, then slitting_product outputs
    $rcp = $conn->query("
        SELECT rcp.id, rcp.status, rcp.lot_no, rcp.coil_no, rcp.roll_no
        FROM recoiling_product rcp
        WHERE rcp.lot_no='$lot' AND rcp.coil_no='$coil' AND rcp.roll_no='$roll'
          AND rcp.original_source='sfc'
        ORDER BY rcp.id DESC LIMIT 1
    ")->fetch_assoc();

    if ($rcp) {
        $rcpId    = intval($rcp['id']);
        $sp_res   = $conn->query("
            SELECT id, lot_no, coil_no, roll_no, width, actual_length, length, status
            FROM slitting_product
            WHERE recoiling_id = $rcpId
            ORDER BY id ASC
        ");
        $rolls = [];
        if ($sp_res) while ($r = $sp_res->fetch_assoc()) $rolls[] = $r;

        $out[] = [
            'process'   => 'RECOIL',
            'parent_id' => $rcpId,
            'status'    => $rcp['status'],
            'rolls'     => $rolls,
        ];
    }

    // 3. Direct SELL to slitting_product (action=SELL)
    $sp_res = $conn->query("
        SELECT id, lot_no, coil_no, roll_no, width, actual_length, length, status
        FROM slitting_product
        WHERE lot_no='$lot' AND coil_no='$coil' AND roll_no='$roll'
          AND source='sfc' AND original_source='sfc'
        ORDER BY id DESC LIMIT 1
    ")->fetch_assoc();

    if ($sp_res) {
        $out[] = [
            'process'   => 'SELL',
            'parent_id' => $sp_res['id'],
            'status'    => $sp_res['status'],
            'rolls'     => [$sp_res],
        ];
    }

    return $out;
}

// ── Search / fetch SFC rows ──────────────────────────────────
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$show_used = isset($_GET['show_used']) ? (int)$_GET['show_used'] : 0;

if ($search !== '') {
    $baseWhere = $show_used ? "is_deleted = 0 AND " : "date_out IS NULL AND is_deleted = 0 AND ";
    $query = "SELECT * FROM sfc WHERE {$baseWhere}(
                sfc_id LIKE ? OR product LIKE ? OR lot_no LIKE ? OR coil_no LIKE ? OR roll_no LIKE ?
              ) ORDER BY date_created DESC";
    $stmt = $conn->prepare($query);
    $like = "%$search%";
    $stmt->bind_param("sssss", $like, $like, $like, $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $where  = $show_used ? "WHERE is_deleted = 0" : "WHERE date_out IS NULL AND is_deleted = 0";
    $result = $conn->query("SELECT * FROM sfc $where ORDER BY date_created DESC");
}

// Counts
$total_active = (int)$conn->query("SELECT COUNT(*) AS c FROM sfc WHERE date_out IS NULL AND is_deleted = 0")->fetch_assoc()['c'];
$total_used   = (int)$conn->query("SELECT COUNT(*) AS c FROM sfc WHERE date_out IS NOT NULL AND is_deleted = 0")->fetch_assoc()['c'];

$page_title = "SFC Inventory";
include 'header.php';
?>

<style>
    .sfc-output-block {
        margin-top: 10px;
        border-radius: 8px;
        padding: 10px 14px;
        font-size: .82rem;
    }
    .sfc-output-reslit  { background:#fffbf0; border:1px solid #fde68a; }
    .sfc-output-recoil  { background:#eff6ff; border:1px solid #bfdbfe; }
    .sfc-output-sell    { background:#f0fdf4; border:1px solid #bbf7d0; }
    .sfc-output-title   { font-size:.72rem; font-weight:700; text-transform:uppercase;
                          letter-spacing:1px; margin-bottom:6px; display:flex; align-items:center; gap:6px; }
    .sfc-roll-row       { display:flex; align-items:center; gap:8px; flex-wrap:wrap;
                          padding:5px 8px; border-radius:6px; background:rgba(255,255,255,.7);
                          margin-bottom:4px; border:1px solid #e5e7eb; }
    .sfc-roll-row:last-child { margin-bottom:0; }
    .roll-ref  { font-family:monospace; font-weight:700; font-size:.85rem; }
    .chip-sm   { font-size:.68rem; padding:1px 7px; border-radius:20px; font-weight:600; }
    .chip-w    { background:#e8f4fd; color:#0369a1; }
    .chip-l    { background:#f0fdf4; color:#166534; }
    .chip-act  { background:#fef3c7; color:#92400e; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><i class="bi bi-box-seam-fill me-2 text-primary"></i>SFC Inventory Management</h2>
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success py-2 mb-0 shadow-sm">
            <i class="bi bi-check-circle me-2"></i>
            <?= ($_GET['msg'] ?? '') === 'deleted' ? 'Item deleted from SFC Inventory.' : 'Action processed successfully!' ?>
        </div>
    <?php endif; ?>
</div>

<input id="qrScanInput" type="hidden" value="">
<div id="qrAlert" class="d-none alert py-2 mb-3 shadow-sm"></div>

<div class="alert alert-secondary py-2 mb-3 d-flex align-items-center gap-2">
    <i class="bi bi-qr-code-scan fs-5"></i>
    <span class="small">QR Scanner ready — scan an SFC label anytime, or click <strong>Process SFC</strong> (supervisor PIN required).</span>
</div>

<!-- Summary counts -->
<div class="d-flex gap-3 mb-3">
    <div class="card border-0 shadow-sm px-4 py-2 text-center">
        <div class="fw-bold fs-4 text-primary"><?= $total_active ?></div>
        <div class="small text-muted">Active</div>
    </div>
    <div class="card border-0 shadow-sm px-4 py-2 text-center">
        <div class="fw-bold fs-4 text-secondary"><?= $total_used ?></div>
        <div class="small text-muted">Used / Out</div>
    </div>
</div>

<div class="row mb-3 g-2 align-items-center">
    <div class="col-md-5">
        <form method="GET" action="sfc.php" class="input-group shadow-sm">
            <input type="hidden" name="show_used" value="<?= $show_used ?>">
            <input type="text" name="search" class="form-control"
                   placeholder="Search ID, Product, Lot, or Roll..."
                   value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-search me-1"></i> Search
            </button>
            <?php if ($search !== ''): ?>
                <a href="sfc.php?show_used=<?= $show_used ?>" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="col-auto">
        <a href="sfc.php?show_used=<?= $show_used ? 0 : 1 ?>&search=<?= urlencode($search) ?>"
           class="btn btn-sm <?= $show_used ? 'btn-dark' : 'btn-outline-dark' ?>">
            <i class="bi bi-<?= $show_used ? 'eye-slash' : 'eye' ?> me-1"></i>
            <?= $show_used ? 'Hide Used' : 'Show Used / Out' ?>
        </a>
    </div>
    <div class="col-md-auto ms-auto">
        <a href="sfc_tracking.php" class="btn btn-info btn-sm">
            <i class="bi bi-graph-up me-1"></i> SFC Tracking Report
        </a>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-dark text-white fw-bold py-3">
        <i class="bi bi-list-task me-2"></i>SFC Material
        <?= $show_used ? '(All — including used)' : '(Active only)' ?>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Print</th>
                    <th>Product</th>
                    <th>Lot No · Coil · Roll</th>
                    <th>Width (mm)</th>
                    <th>Length (m)</th>
                    <th>Date Created</th>
                    <th>Status / Output</th>
                    <th class="no-print">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($result && $result->num_rows > 0):
                while ($row = $result->fetch_assoc()):
                    $isUsed   = !empty($row['date_out']);
                    $outputs  = $isUsed ? getSfcOutputs($conn, $row) : [];
                    $rowClass = $isUsed ? 'table-secondary' : '';
            ?>
                <tr class="<?= $rowClass ?>" style="vertical-align:top">
                    <td>
                        <a href="print_sfc.php?id=<?= (int)$row['sfc_id'] ?>"
                           target="_blank" class="btn btn-outline-dark btn-sm">
                            <i class="bi bi-printer-fill"></i>
                        </a>
                    </td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($row['product']) ?></span></td>
                    <td class="fw-bold" style="font-family:monospace; font-size:.88rem;">
                        <?= htmlspecialchars($row['lot_no']) ?>
                        <?= htmlspecialchars($row['coil_no']) ?>
                        <?php if ($row['roll_no'] && strtoupper($row['roll_no']) !== 'BALANCE'): ?>
                            – <?= str_replace('R','R-', htmlspecialchars($row['roll_no'])) ?>
                        <?php elseif ($row['roll_no']): ?>
                            <span class="badge bg-warning text-dark ms-1" style="font-size:.65rem">BALANCE</span>
                        <?php endif; ?>
                    </td>
                    <td><?= number_format($row['width']) ?></td>
                    <td class="text-primary fw-bold"><?= number_format($row['length'], 2) ?></td>
                    <td class="small text-muted"><?= date('d M Y', strtotime($row['date_created'])) ?></td>

                    <!-- Status + Output Rolls column -->
                    <td>
                        <?php if (!$isUsed): ?>
                            <span class="badge bg-success">IN SFC</span>

                        <?php else: ?>
                            <!-- Used status badge -->
                            <?php
                            $actionLabel = match(strtoupper($row['action'] ?? '')) {
                                'RESLIT'  => ['RESLIT',   'bg-warning text-dark'],
                                'RECOIL'  => ['RECOIL',   'bg-primary'],
                                'SELL'    => ['SELL → QC','bg-success'],
                                default   => [htmlspecialchars($row['action'] ?? 'USED'), 'bg-secondary'],
                            };
                            ?>
                            <span class="badge <?= $actionLabel[1] ?> mb-1">
                                USED → <?= $actionLabel[0] ?>
                            </span>
                            <small class="text-muted d-block mb-1">
                                Out: <?= date('d M Y', strtotime($row['date_out'])) ?>
                            </small>

                            <!-- Output rolls produced from this SFC -->
                            <?php if (!empty($outputs)): ?>
                                <?php foreach ($outputs as $out):
                                    $outClass = match($out['process']) {
                                        'RESLIT' => 'sfc-output-reslit',
                                        'RECOIL' => 'sfc-output-recoil',
                                        default  => 'sfc-output-sell',
                                    };
                                    $outIcon = match($out['process']) {
                                        'RESLIT' => 'bi-arrow-repeat',
                                        'RECOIL' => 'bi-arrow-counterclockwise',
                                        default  => 'bi-cart-check',
                                    };
                                    $outColor = match($out['process']) {
                                        'RESLIT' => '#92400e',
                                        'RECOIL' => '#1d4ed8',
                                        default  => '#166534',
                                    };
                                ?>
                                <div class="sfc-output-block <?= $outClass ?>">
                                    <div class="sfc-output-title" style="color:<?= $outColor ?>">
                                        <i class="bi <?= $outIcon ?>"></i>
                                        <?= $out['process'] ?> Output
                                        <span class="badge <?= $out['status']==='completed'||$out['status']==='DELIVERED' ? 'bg-success' : 'bg-warning text-dark' ?>"
                                              style="font-size:.6rem">
                                            <?= strtoupper($out['status']) ?>
                                        </span>
                                        <span class="ms-auto text-muted" style="font-size:.65rem; font-weight:400;">
                                            <?= count($out['rolls']) ?> roll<?= count($out['rolls'])>1?'s':'' ?>
                                        </span>
                                    </div>

                                    <?php if (!empty($out['rolls'])): ?>
                                        <?php foreach ($out['rolls'] as $r):
                                            // Determine ref label for each roll type
                                            if ($out['process'] === 'RESLIT') {
                                                $ref = htmlspecialchars(($row['lot_no'] ?? '') . ($r['cut_letter'] ?? ''))
                                                     . ' ' . htmlspecialchars($row['coil_no'] ?? '')
                                                     . ' – ' . str_replace('R','R-', htmlspecialchars($r['roll_no'] ?? ''));
                                                $w   = $r['new_width']    ?? 0;
                                                $l   = $r['length']       ?? 0;
                                                $act = $r['actual_length'] ?? 0;
                                                $st  = $r['status']        ?? 'pending';
                                            } else {
                                                $ref = htmlspecialchars($r['lot_no'] ?? '')
                                                     . ' ' . htmlspecialchars($r['coil_no'] ?? '')
                                                     . ' – ' . str_replace('R','R-', htmlspecialchars($r['roll_no'] ?? ''));
                                                $w   = $r['width']        ?? 0;
                                                $l   = $r['length']       ?? 0;
                                                $act = $r['actual_length'] ?? 0;
                                                $st  = $r['status']        ?? 'IN';
                                            }
                                            $stBadge = match(strtoupper($st)) {
                                                'DELIVERED' => '<span class="badge bg-success" style="font-size:.6rem">DELIVERED</span>',
                                                'APPROVED'  => '<span class="badge bg-primary" style="font-size:.6rem">APPROVED</span>',
                                                'WAITING'   => '<span class="badge bg-warning text-dark" style="font-size:.6rem">WAITING</span>',
                                                'COMPLETED' => '<span class="badge bg-success" style="font-size:.6rem">COMPLETED</span>',
                                                'IN'        => '<span class="badge bg-info text-dark" style="font-size:.6rem">IN STOCK</span>',
                                                default     => '<span class="badge bg-secondary" style="font-size:.6rem">'.htmlspecialchars($st).'</span>',
                                            };
                                        ?>
                                        <div class="sfc-roll-row">
                                            <i class="bi bi-arrow-right-circle text-muted"></i>
                                            <span class="roll-ref"><?= $ref ?></span>
                                            <span class="chip-sm chip-w"><?= number_format((float)$w) ?> mm</span>
                                            <?php if ($act > 0): ?>
                                                <span class="chip-sm chip-act">Actual: <?= number_format((float)$act, 1) ?> m</span>
                                            <?php elseif ($l > 0): ?>
                                                <span class="chip-sm chip-l">Nom: <?= number_format((float)$l, 1) ?> m</span>
                                            <?php endif; ?>
                                            <?= $stBadge ?>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-muted fst-italic" style="font-size:.75rem">
                                            <i class="bi bi-hourglass-split me-1"></i>No output rolls yet
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted small fst-italic mt-1">
                                    <i class="bi bi-hourglass-split me-1"></i>Output not recorded yet
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>

                    <td class="no-print">
                        <?php if (!$isUsed): ?>
                            <div class="d-flex gap-1">
                                <button type="button"
                                        class="btn btn-primary btn-sm px-3 rounded-pill actionBtn shadow-sm"
                                        data-sfc-id="<?= $row['sfc_id'] ?>"
                                        data-sfc-lot="<?= htmlspecialchars($row['lot_no']) ?>"
                                        data-sfc-coil="<?= htmlspecialchars($row['coil_no']) ?>"
                                        data-sfc-roll="<?= htmlspecialchars($row['roll_no'] ?? '') ?>"
                                        data-sfc-details="<?= htmlspecialchars($row['product']) ?> | <?= htmlspecialchars($row['lot_no']) ?> <?= htmlspecialchars($row['coil_no']) ?> | <?= number_format($row['length'], 2) ?>m">
                                    <i class="bi bi-gear-fill me-1"></i> Process
                                </button>
                                <button type="button"
                                        class="btn btn-outline-danger btn-sm rounded-pill deleteBtn shadow-sm"
                                        title="Delete this SFC entry"
                                        data-sfc-id="<?= $row['sfc_id'] ?>"
                                        data-sfc-details="<?= htmlspecialchars($row['product']) ?> | <?= htmlspecialchars($row['lot_no']) ?> <?= htmlspecialchars($row['coil_no']) ?> | <?= number_format($row['length'], 2) ?>m">
                                    <i class="bi bi-trash-fill"></i>
                                </button>
                            </div>
                        <?php else: ?>
                            <span class="text-muted small">Done</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile;
            else: ?>
                <tr>
                    <td colspan="8" class="py-5 text-muted text-center">
                        <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                        No SFC inventory found.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- PIN Modal -->
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

<!-- Action Modal -->
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

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Delete</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center p-4">
        <p class="text-muted small text-uppercase fw-bold mb-1">Deleting:</p>
        <h5 id="deleteSfcDetails" class="fw-bold mb-3 text-danger"></h5>
        <p class="mb-0">Are you sure you want to delete this product from SFC Inventory? This action cannot be undone.</p>
        <form id="deleteForm" method="post" action="sfc.php">
            <input type="hidden" name="sfc_id" id="delete_sfc_id_input">
            <input type="hidden" name="action" value="DELETE">
        </form>
      </div>
      <div class="modal-footer justify-content-center border-0 pt-0">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" id="deleteConfirmBtn" class="btn btn-danger fw-bold px-4">
            <i class="bi bi-trash-fill me-1"></i> Delete
        </button>
      </div>
    </div>
  </div>
</div>

<script src="camera_scanner.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const pinModal     = new bootstrap.Modal(document.getElementById('pinModal'));
    const actionModal  = new bootstrap.Modal(document.getElementById('actionModal'));
    const deleteModal  = new bootstrap.Modal(document.getElementById('deleteModal'));
    const pinInput     = document.getElementById('pinInput');
    const pinError     = document.getElementById('pinError');
    const pinSubmitBtn = document.getElementById('pinSubmitBtn');
    const qrInput      = document.getElementById('qrScanInput');
    const qrAlert      = document.getElementById('qrAlert');

    let pendingSfcId = null, pendingSfcDetails = null;

    function openActionModal(sfcId, details) {
        document.getElementById('sfc_id_input').value      = sfcId;
        document.getElementById('sfcIdDisplay').textContent = sfcId;
        document.getElementById('sfcDetails').textContent   = details;
        actionModal.show();
    }
    // Scanning intentionally skips the Supervisor PIN gate entirely and
    // calls this directly (see initCameraScanner's onScan below, which
    // now lives in this same closure) — unlike the manual PROCESS button.

    function openDeleteModal(sfcId, details) {
        document.getElementById('delete_sfc_id_input').value = sfcId;
        document.getElementById('deleteSfcDetails').textContent = details;
        deleteModal.show();
    }

    document.querySelectorAll('.actionBtn').forEach(btn => {
        btn.addEventListener('click', function () {
            // PIN gate removed — anyone can open Select Next Process directly.
            openActionModal(this.dataset.sfcId, this.dataset.sfcDetails);
        });
    });

    // Delete also goes through the same supervisor PIN gate as Process —
    // it's at least as consequential, so it gets the same safeguard,
    // followed by the dedicated confirmation dialog requested below.
    document.querySelectorAll('.deleteBtn').forEach(btn => {
        btn.addEventListener('click', function () {
            pendingSfcId      = this.dataset.sfcId;
            pendingSfcDetails = this.dataset.sfcDetails;
            pinInput.value    = '';
            pinError.classList.add('d-none');
            pinModal.show();
            document.getElementById('pinModal').addEventListener('shown.bs.modal', function h() {
                pinInput.focus();
                this.removeEventListener('shown.bs.modal', h);
            });
        });
    });

    document.getElementById('deleteConfirmBtn').addEventListener('click', function () {
        document.getElementById('deleteForm').submit();
    });

    function submitPin() {
        const pin = pinInput.value.trim();
        if (!pin) return;
        pinSubmitBtn.disabled = true;
        pinSubmitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Checking...';

        fetch('sfc.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'verify_pin=' + encodeURIComponent(pin)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                pinModal.hide();
                document.getElementById('pinModal').addEventListener('hidden.bs.modal', function h() {
                    this.removeEventListener('hidden.bs.modal', h);
                    openDeleteModal(pendingSfcId, pendingSfcDetails);
                });
            } else {
                pinError.classList.remove('d-none');
                pinInput.value = '';
                pinInput.focus();
            }
        })
        .catch(() => {
            pinError.innerHTML = '<i class="bi bi-wifi-off me-1"></i>Network error.';
            pinError.classList.remove('d-none');
        })
        .finally(() => {
            pinSubmitBtn.disabled = false;
            pinSubmitBtn.innerHTML = '<i class="bi bi-unlock-fill me-1"></i> Unlock';
        });
    }

    pinSubmitBtn.addEventListener('click', submitPin);
    pinInput.addEventListener('keydown', e => { if (e.key === 'Enter') submitPin(); });

    function showAlert(type, msg) {
        qrAlert.className = 'alert alert-' + type + ' py-2 mb-3 shadow-sm';
        qrAlert.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i>' + msg;
        qrAlert.classList.remove('d-none');
        setTimeout(hideAlert, 5000);
    }
    function hideAlert() { qrAlert.classList.add('d-none'); }

    // ── Camera / hardware QR scanner ─────────────────────────
    // Kept INSIDE this same DOMContentLoaded closure (not a separate
    // <script> block) so onScan can call showAlert/hideAlert/openActionModal
    // directly — a prior version split this into its own script tag and
    // those functions were out of scope there, which silently broke the
    // scan-to-modal flow with a ReferenceError.
    initCameraScanner({
        onScan: function(scanned) {
        if (!scanned) return;

        // ROLL is optional in the match — older/simpler SFC labels may only
        // encode LOT+COIL, but if ROLL is present we use it too since that's
        // the only combination guaranteed unique (see server-side matching
        // elsewhere in this file, which always keys on lot+coil+roll).
        var matchLotCoil  = scanned.match(/LOT=([^;]+);COIL=([^;]+)(?:;ROLL=([^;]+))?/i);
        var matchPrefixed = scanned.match(/^sfc[_-]?(\d+)$/i);
        var matchPlain    = scanned.match(/^(\d+)$/);
        var btn = null;

        if (matchLotCoil) {
            var lot  = matchLotCoil[1].trim().toLowerCase();
            var coil = matchLotCoil[2].trim().toLowerCase();
            var roll = (matchLotCoil[3] || '').trim().toLowerCase();

            var candidates = [];
            document.querySelectorAll('.actionBtn').forEach(function(b) {
                if ((b.dataset.sfcLot||'').toLowerCase()===lot &&
                    (b.dataset.sfcCoil||'').toLowerCase()===coil) {
                    candidates.push(b);
                }
            });

            if (roll) {
                // ROLL was in the scan — narrow down to the exact roll,
                // fully unambiguous regardless of how many share the Lot+Coil.
                candidates = candidates.filter(function(b) {
                    return (b.dataset.sfcRoll||'').toLowerCase() === roll;
                });
            }

            if (candidates.length === 1) {
                btn = candidates[0];
            } else if (candidates.length > 1) {
                // Genuinely ambiguous: this QR (Lot+Coil, no Roll) matches
                // more than one active SFC entry. Don't guess — that risks
                // processing the wrong roll. Ask the user to disambiguate
                // manually instead.
                hideAlert();
                showAlert('danger',
                    'Multiple active SFC entries match Lot ' + matchLotCoil[1].trim() +
                    ' / Coil ' + matchLotCoil[2].trim() + '. This QR doesn\'t include a Roll No. ' +
                    'to tell them apart — please use the <strong>Process</strong> button on the ' +
                    'correct row instead.'
                );
                return;
            }
            // candidates.length === 0 falls through to the "no match" alert below.
        } else if (matchPrefixed) {
            btn = document.querySelector('.actionBtn[data-sfc-id="'+matchPrefixed[1]+'"]');
        } else if (matchPlain) {
            btn = document.querySelector('.actionBtn[data-sfc-id="'+matchPlain[1]+'"]');
        } else {
            showAlert('danger', 'QR not recognised: <strong>' + scanned + '</strong>');
            return;
        }

        if (btn) {
            hideAlert();
            // Scanning intentionally skips the Supervisor PIN gate and goes
            // straight to Select Next Process. Called directly (no window.
            // prefix needed) since this now lives in the SAME closure as
            // openActionModal, showAlert, and hideAlert.
            openActionModal(btn.dataset.sfcId, btn.dataset.sfcDetails);
        } else {
            showAlert('danger', 'No matching active SFC found for: <strong>' + scanned + '</strong>');
        }
        } // end onScan
    }); // end initCameraScanner
}); // end DOMContentLoaded
</script>

<?php include 'footer.php'; ?>
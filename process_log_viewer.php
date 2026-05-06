<?php
// process_log_viewer.php
// Two columns: Mother Coil (source) | Product Roll (the actual slit output)

session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

include 'config.php';

$filter_type = $_GET['entity_type'] ?? '';
$filter_lot  = trim($_GET['lot_no']  ?? '');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');

// ── Main query ─────────────────────────────────────────────────
// Join mother_coil for Column 1 (source mother)
// Join slitting_product for Column 2 (the actual slit roll)
// Only join slitting_product when entity_type = 'slitting'
$sql = "SELECT
            pl.*,
            mc.lot_no   AS mc_lot,
            mc.coil_no  AS mc_coil,
            mc.product  AS mc_product,
            sp.lot_no   AS sp_lot,
            sp.coil_no  AS sp_coil,
            sp.roll_no  AS sp_roll,
            rp.lot_no   AS rp_lot,
            rp.coil_no  AS rp_coil,
            rp.roll_no  AS rp_roll,
            rs.lot_no   AS rs_lot,
            rs.coil_no  AS rs_coil,
            rs.roll_no  AS rs_roll,
            sfc.lot_no  AS sfc_lot,
            sfc.coil_no AS sfc_coil,
            sfc.roll_no AS sfc_roll
        FROM process_log pl
        LEFT JOIN mother_coil      mc  ON mc.id      = pl.mother_id
        LEFT JOIN slitting_product sp  ON sp.id      = pl.entity_id AND pl.entity_type = 'slitting'
        LEFT JOIN recoiling_product rp ON rp.id      = pl.entity_id AND pl.entity_type = 'recoiling'
        LEFT JOIN reslit_product    rs ON rs.id      = pl.entity_id AND pl.entity_type = 'reslit'
        LEFT JOIN sfc               sfc ON sfc.sfc_id = pl.entity_id AND pl.entity_type = 'sfc'
        WHERE MONTH(pl.performed_at) = ? AND YEAR(pl.performed_at) = ?";

$params = [$month, $year];
$types  = "ii";

if ($filter_type !== '') {
    $sql     .= " AND pl.entity_type = ?";
    $params[] = $filter_type;
    $types   .= "s";
}

if ($filter_lot !== '') {
    $sql     .= " AND (mc.lot_no LIKE ? OR sp.lot_no LIKE ? OR sfc.lot_no LIKE ?)";
    $like     = '%' . $filter_lot . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= "sss";
}

$sql .= " ORDER BY pl.performed_at DESC LIMIT 500";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Helper: get product lot/coil/roll from the joined row ──────
// Returns array [lot, coil, roll] depending on entity_type
function getProductRef(array $log): array {
    switch ($log['entity_type']) {
        case 'slitting':
            return [$log['sp_lot'] ?? '', $log['sp_coil'] ?? '', $log['sp_roll'] ?? ''];
        case 'recoiling':
            return [$log['rp_lot'] ?? '', $log['rp_coil'] ?? '', $log['rp_roll'] ?? ''];
        case 'reslit':
            return [$log['rs_lot'] ?? '', $log['rs_coil'] ?? '', $log['rs_roll'] ?? ''];
        case 'sfc':
            return [$log['sfc_lot'] ?? '', $log['sfc_coil'] ?? '', $log['sfc_roll'] ?? ''];
        default:
            return ['', '', ''];
    }
}

$page_title = "Process Log Viewer";
include 'header.php';
?>

<style>
    .log-badge-slitting  { background:#dbeafe; color:#1e40af; }
    .log-badge-recoiling { background:#fef3c7; color:#92400e; }
    .log-badge-reslit    { background:#d1fae5; color:#065f46; }
    .log-badge-sfc       { background:#ede9fe; color:#5b21b6; }
    .log-badge-stock     { background:#f1f5f9; color:#334155; }
    .from-to  { font-family: monospace; font-size: 12px; }
    .ref-cell { font-family: monospace; font-size: 12px; line-height: 1.6; }
    .roll-tag {
        display: inline-block;
        background: #f0fdf4;
        color: #166534;
        border: 1px solid #bbf7d0;
        border-radius: 5px;
        padding: 1px 7px;
        font-weight: 700;
        font-size: 11px;
    }
    .mother-tag {
        display: inline-block;
        background: #eff6ff;
        color: #1e40af;
        border: 1px solid #bfdbfe;
        border-radius: 5px;
        padding: 1px 7px;
        font-weight: 600;
        font-size: 11px;
    }
    .arrow-col { color: #94a3b8; font-size: 16px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-clock-history me-2"></i>Process Log — Full Audit Trail</h2>
    <a href="finish_product.php" class="btn btn-secondary btn-sm">← Back</a>
</div>

<!-- Filter bar -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="small fw-bold">Month</label>
                <select name="month" class="form-select form-select-sm">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>>
                            <?= date('M', mktime(0,0,0,$m,1)) ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="small fw-bold">Year</label>
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y = 2024; $y <= 2030; $y++): ?>
                        <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="small fw-bold">Process Type</label>
                <select name="entity_type" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="slitting"  <?= $filter_type==='slitting'  ? 'selected' : '' ?>>Slitting</option>
                    <option value="recoiling" <?= $filter_type==='recoiling' ? 'selected' : '' ?>>Recoiling</option>
                    <option value="reslit"    <?= $filter_type==='reslit'    ? 'selected' : '' ?>>Reslit</option>
                    <option value="sfc"       <?= $filter_type==='sfc'       ? 'selected' : '' ?>>SFC</option>
                    <option value="stock"     <?= $filter_type==='stock'     ? 'selected' : '' ?>>Stock</option>
                </select>
            </div>
            <div class="col-auto">
                <label class="small fw-bold">Search Lot No</label>
                <input type="text" name="lot_no" class="form-control form-control-sm"
                       style="width:140px"
                       value="<?= htmlspecialchars($filter_lot) ?>"
                       placeholder="e.g. 111111">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-search me-1"></i>Filter
                </button>
                <a href="process_log_viewer.php" class="btn btn-outline-secondary btn-sm ms-1">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-dark text-white fw-bold py-2 d-flex justify-content-between">
        <span><i class="bi bi-list-ul me-2"></i>Log Entries</span>
        <span class="badge bg-info text-dark"><?= count($logs) ?> records</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle text-center mb-0 small">
            <thead class="table-light">
                <tr>
                    <th style="width:40px">#</th>
                    <th style="width:90px">Process</th>
                    <th class="text-start">Mother Coil</th>
                    <th style="width:20px"></th>
                    <th class="text-start">Product</th>
                    <th style="width:110px">Status Change</th>
                    <th style="width:120px">Action</th>
                    <th style="width:60px">By</th>
                    <th style="width:120px">When</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="9" class="py-5 text-muted">No log entries found for this period.</td></tr>
            <?php else: ?>
                <?php $rowNum = 0; ?>
                <?php foreach ($logs as $log):
                    [$pLot, $pCoil, $pRoll] = getProductRef($log);
                ?>
                <tr>
                    <td class="text-muted"><?= ++$rowNum ?></td>

                    <td>
                        <span class="badge log-badge-<?= $log['entity_type'] ?>" style="font-size:11px">
                            <?= strtoupper($log['entity_type']) ?>
                        </span>
                    </td>

                    <!-- Column 1: Mother Coil -->
                    <td class="text-start ref-cell">
                        <?php if ($log['mc_lot']): ?>
                            <span class="mother-tag">
                                <?= htmlspecialchars($log['mc_lot']) ?>
                                <?= htmlspecialchars($log['mc_coil']) ?>
                            </span><br>
                            <small class="text-muted"><?= htmlspecialchars($log['mc_product'] ?? '') ?></small>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>

                    <!-- Arrow separator -->
                    <td class="arrow-col">→</td>

                    <!-- Column 2: Product Roll (the actual slit/reslit/recoil output) -->
                    <td class="text-start ref-cell">
                        <?php if ($pLot !== '' || $pCoil !== ''): ?>
                            <span class="fw-bold"><?= htmlspecialchars($pLot) ?></span>
                            <span class="text-muted mx-1"><?= htmlspecialchars($pCoil) ?></span>
                            <?php if ($pRoll !== ''): ?>
                                <span class="roll-tag"><?= htmlspecialchars(str_replace('R','R-',$pRoll)) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="from-to">
                        <?php if ($log['from_status']): ?>
                            <span class="text-muted"><?= htmlspecialchars($log['from_status']) ?></span>
                            <span class="text-secondary mx-1">→</span>
                        <?php endif; ?>
                        <strong class="text-success"><?= htmlspecialchars($log['to_status']) ?></strong>
                    </td>

                    <td>
                        <span class="badge bg-light text-dark border" style="font-size:10px">
                            <?= htmlspecialchars($log['action_detail'] ?? '—') ?>
                        </span>
                    </td>

                    <td class="text-muted"><?= htmlspecialchars($log['performed_by'] ?? '—') ?></td>

                    <td class="text-muted" style="white-space:nowrap">
                        <?= date('d M Y H:i', strtotime($log['performed_at'])) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">
    <a href="finish_product.php" class="btn btn-secondary">← Back to Finish Product</a>
</div>

<?php include 'footer.php'; ?>
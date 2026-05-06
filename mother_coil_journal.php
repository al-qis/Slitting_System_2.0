<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if (!in_array($_SESSION['role'], ['slitting', 'mkl3'], true)) {
    die("Access denied");
}

include 'config.php';

$mother_id = intval($_GET['id'] ?? 0);
if ($mother_id <= 0) die("Invalid mother coil ID.");

// ── Mother coil ──────────────────────────────────────────────
$mother = $conn->query("SELECT * FROM mother_coil WHERE id=$mother_id")->fetch_assoc();
if (!$mother) die("Mother coil not found.");

// ── 1. Direct slit rolls ──
// Simple query — avoids ONLY_FULL_GROUP_BY issue
$slit_res = $conn->query("
    SELECT *
    FROM slitting_product
    WHERE mother_id = $mother_id
      AND (recoiling_id IS NULL OR recoiling_id = 0)
      AND (parent_slit_id IS NULL OR parent_slit_id = 0)
    ORDER BY id ASC
");
$slit_rows = [];
while ($r = $slit_res->fetch_assoc()) $slit_rows[] = $r;

// ── 2. Recoiling entries — use slitting_product_id FK (UPDATED) ──
$recoiling_map = []; // keyed by slitting_product.id
if (!empty($slit_rows)) {
    foreach ($slit_rows as $sr) {
        $sid = intval($sr['id']);
        // UPDATED: direct FK lookup instead of string match
        $rp = $conn->query("
            SELECT rp.*,
                   COUNT(sp_out.id) AS output_count
            FROM recoiling_product rp
            LEFT JOIN slitting_product sp_out ON sp_out.recoiling_id = rp.id
            WHERE rp.slitting_product_id = $sid
            GROUP BY rp.id
            ORDER BY rp.id DESC LIMIT 1
        ")->fetch_assoc();
        if ($rp) $recoiling_map[$sid] = $rp;
    }
}

// ── 3. Recoil output rolls — keyed by recoiling_product.id ──
// UPDATED: uses parent_slit_id to confirm the chain
$recoil_output_map = [];
$ro_res = $conn->query("
    SELECT sp.*
    FROM slitting_product sp
    WHERE sp.recoiling_id IS NOT NULL
      AND sp.mother_id = $mother_id
    ORDER BY sp.recoiling_id ASC, sp.id ASC
");
if ($ro_res) {
    while ($r = $ro_res->fetch_assoc()) {
        $recoil_output_map[(int)$r['recoiling_id']][] = $r;
    }
}

// ── 4. Reslit entries — use slitting_product_id FK (UPDATED) ──
// For both original rolls AND recoil outputs
$reslit_entry_map = []; // keyed by slitting_product.id → reslit_product row
$reslit_rolls_map = []; // keyed by reslit_product.id   → [reslit_rolls rows]

// Collect all slit IDs including recoil outputs
$all_slit_ids = array_column($slit_rows, 'id');
foreach ($recoil_output_map as $outputs) {
    foreach ($outputs as $ro) {
        $all_slit_ids[] = intval($ro['id']);
    }
}
$all_slit_ids = array_unique($all_slit_ids);

if (!empty($all_slit_ids)) {
    $id_list = implode(',', $all_slit_ids);

    // UPDATED: direct FK via slitting_product_id
    $rs_res = $conn->query("
        SELECT rp.*, rp.slitting_product_id AS source_slit_id
        FROM reslit_product rp
        WHERE rp.slitting_product_id IN ($id_list)
        ORDER BY rp.id ASC
    ");
    if ($rs_res) {
        while ($r = $rs_res->fetch_assoc()) {
            $reslit_entry_map[(int)$r['source_slit_id']][] = $r;
        }
    }

    // Get all reslit_rolls for those reslit_products
    $reslit_product_ids = [];
    foreach ($reslit_entry_map as $entries) {
        foreach ($entries as $e) $reslit_product_ids[] = intval($e['id']);
    }
    if (!empty($reslit_product_ids)) {
        $rp_list = implode(',', $reslit_product_ids);
        $rr_res  = $conn->query("
            SELECT rr.*, sp.status AS output_status, sp.delivered_at, sp.id AS output_slit_id
            FROM reslit_rolls rr
            LEFT JOIN slitting_product sp
                ON  sp.lot_no  = CONCAT(rr.cut_letter, '')
                AND sp.roll_no = rr.roll_no
                AND sp.source  = 'reslit'
            WHERE rr.parent_id IN ($rp_list)
            ORDER BY rr.parent_id ASC, rr.id ASC
        ");
        // Simpler join: just get reslit_rolls by parent_id
        $rr_res = $conn->query("
            SELECT rr.*
            FROM reslit_rolls rr
            WHERE rr.parent_id IN ($rp_list)
            ORDER BY rr.parent_id ASC, rr.id ASC
        ");
        if ($rr_res) {
            while ($r = $rr_res->fetch_assoc()) {
                $reslit_rolls_map[(int)$r['parent_id']][] = $r;
            }
        }
    }
}

// ── 5. SFC entries — UPDATED: use mother_id FK ──────────────
$sfc_rows = [];
$sfc_res  = $conn->query("
    SELECT * FROM sfc
    WHERE mother_id = $mother_id
    ORDER BY sfc_id ASC
");
// Fallback to lot_no match if mother_id column not populated
if (!$sfc_res || $sfc_res->num_rows === 0) {
    $lot_esc = $conn->real_escape_string($mother['lot_no']);
    $coil_esc = $conn->real_escape_string($mother['coil_no']);
    $sfc_res = $conn->query("
        SELECT * FROM sfc
        WHERE coil_no = '$coil_esc'
          AND lot_no LIKE '$lot_esc%'
        ORDER BY sfc_id ASC
    ");
}
if ($sfc_res) while ($r = $sfc_res->fetch_assoc()) $sfc_rows[] = $r;

// ── 6. Leftover stock entries (Cut Into 2 balance) ──────────
$leftover_rows = [];
$lr_res = $conn->query("
    SELECT * FROM stock_raw_material
    WHERE source_id = $mother_id
      AND source_type = 'slitting_cut_into_2'
    ORDER BY id ASC
");
if ($lr_res) while ($r = $lr_res->fetch_assoc()) $leftover_rows[] = $r;

// ── 7. SFC output rolls — reslit_rolls and recoil outputs from SFC items ──
// For each SFC item linked to this mother, find what rolls were produced
$sfc_output_rows = []; // flat array of rows for summary table
foreach ($sfc_rows as $sf) {
    $sfLot  = $conn->real_escape_string($sf['lot_no']  ?? '');
    $sfCoil = $conn->real_escape_string($sf['coil_no'] ?? '');
    $sfRoll = $conn->real_escape_string($sf['roll_no'] ?? '');
    $sfAction = strtoupper($sf['action'] ?? '');

    if ($sfAction === 'RESLIT') {
        // Find reslit_product for this SFC item, then its reslit_rolls
        $rp = $conn->query("
            SELECT id, status FROM reslit_product
            WHERE lot_no='$sfLot' AND coil_no='$sfCoil' AND roll_no='$sfRoll'
              AND original_source='sfc'
            ORDER BY id DESC LIMIT 1
        ")->fetch_assoc();
        if ($rp) {
            $rpId = intval($rp['id']);
            $rr_res = $conn->query("
                SELECT rr.*, '{$sfLot}' AS sfc_lot, '{$sfCoil}' AS sfc_coil,
                       'RESLIT' AS sfc_process, '{$rp['status']}' AS parent_status
                FROM reslit_rolls rr
                WHERE rr.parent_id = $rpId
                ORDER BY rr.id ASC
            ");
            if ($rr_res) {
                while ($r = $rr_res->fetch_assoc()) {
                    $r['_sfc_id']     = $sf['sfc_id'];
                    $r['_sfc_action'] = 'RESLIT';
                    $sfc_output_rows[] = $r;
                }
            }
        }
    } elseif ($sfAction === 'RECOIL') {
        // Find recoiling_product then its slitting_product outputs
        $rcp = $conn->query("
            SELECT id, status FROM recoiling_product
            WHERE lot_no='$sfLot' AND coil_no='$sfCoil' AND roll_no='$sfRoll'
              AND original_source='sfc'
            ORDER BY id DESC LIMIT 1
        ")->fetch_assoc();
        if ($rcp) {
            $rcpId  = intval($rcp['id']);
            $sp_res = $conn->query("
                SELECT id, lot_no, coil_no, roll_no, width, length, actual_length,
                       status, date_in, 'RECOIL' AS sfc_process
                FROM slitting_product
                WHERE recoiling_id = $rcpId
                ORDER BY id ASC
            ");
            if ($sp_res) {
                while ($r = $sp_res->fetch_assoc()) {
                    $r['_sfc_id']     = $sf['sfc_id'];
                    $r['_sfc_action'] = 'RECOIL';
                    $sfc_output_rows[] = $r;
                }
            }
        }
    } elseif ($sfAction === 'SELL') {
        // Direct slitting_product from SFC sell
        $sp = $conn->query("
            SELECT id, lot_no, coil_no, roll_no, width, length, actual_length,
                   status, date_in, 'SELL' AS sfc_process
            FROM slitting_product
            WHERE lot_no='$sfLot' AND coil_no='$sfCoil' AND roll_no='$sfRoll'
              AND source='sfc' AND original_source='sfc'
            ORDER BY id DESC LIMIT 1
        ")->fetch_assoc();
        if ($sp) {
            $sp['_sfc_id']     = $sf['sfc_id'];
            $sp['_sfc_action'] = 'SELL';
            $sfc_output_rows[] = $sp;
        }
    }
}

// ── 8. Process log summary for this mother ───────────────────
$log_summary = $conn->query("
    SELECT entity_type,
           COUNT(*)                                  AS total_events,
           MIN(performed_at)                         AS first_event,
           MAX(performed_at)                         AS last_event,
           GROUP_CONCAT(DISTINCT to_status ORDER BY performed_at SEPARATOR ', ') AS statuses
    FROM process_log
    WHERE mother_id = $mother_id
    GROUP BY entity_type
    ORDER BY first_event ASC
")->fetch_all(MYSQLI_ASSOC);

// ── Summary stats ────────────────────────────────────────────
$input_length    = (float)$mother['length'];
$total_output_m  = array_sum(array_column($slit_rows, 'actual_length'));
// Add recoil outputs to total
foreach ($recoil_output_map as $outputs) {
    $total_output_m += array_sum(array_column($outputs, 'actual_length'));
}
$utilization = $input_length > 0 ? round(($total_output_m / $input_length) * 100, 1) : 0;

$total_rolls     = count($slit_rows);
$delivered_count = count(array_filter($slit_rows, fn($r) => strtoupper($r['status']) === 'DELIVERED'));
$pending_count   = count(array_filter($slit_rows, fn($r) => strtoupper($r['status']) === 'IN' && !$r['is_completed']));
$stock_count     = count(array_filter($slit_rows, fn($r) => strtoupper($r['status']) === 'IN' && $r['stock_counted']));
$waiting_count   = count(array_filter($slit_rows, fn($r) => strtoupper($r['status']) === 'WAITING'));
$approved_count  = count(array_filter($slit_rows, fn($r) => strtoupper($r['status']) === 'APPROVED'));

// ── Helpers ──────────────────────────────────────────────────
function statusBadge(string $status): string {
    return match(strtoupper($status)) {
        'IN'        => '<span class="s-badge s-in">IN STOCK</span>',
        'WAITING'   => '<span class="s-badge s-waiting">WAITING QC</span>',
        'APPROVED'  => '<span class="s-badge s-approved">APPROVED</span>',
        'DELIVERED' => '<span class="s-badge s-delivered">DELIVERED</span>',
        'REJECTED'  => '<span class="s-badge s-rejected">REJECTED</span>',
        'PENDING'   => '<span class="s-badge s-pending">PENDING</span>',
        'COMPLETED' => '<span class="s-badge s-completed">COMPLETED</span>',
        default     => '<span class="s-badge s-pending">'.htmlspecialchars($status).'</span>',
    };
}
function dotColor(string $status): string {
    return match(strtoupper($status)) {
        'DELIVERED' => '#0a9960', 'APPROVED'  => '#1a73e8',
        'WAITING'   => '#d4a017', 'REJECTED'  => '#e94560',
        'COMPLETED' => '#0a9960', 'PENDING'   => '#d4a017',
        default     => '#6b7280',
    };
}
function fmtRoll(string $roll): string {
    return str_replace('R', 'R-', htmlspecialchars($roll));
}

$page_title = "Mother Coil Journal";
include 'header.php';
?>

<style>
:root {
    --ink:#1a1a2e; --steel:#16213e; --accent:#0f3460;
    --gold:#e94560; --green:#0a9960; --amber:#d4a017; --sky:#1a73e8;
}

/* ── Journal Header ── */
.journal-header {
    background: linear-gradient(135deg, var(--ink) 0%, var(--steel) 60%, var(--accent) 100%);
    color:#fff; border-radius:16px; padding:32px; margin-bottom:28px;
    position:relative; overflow:hidden;
}
.journal-header::before {
    content:''; position:absolute; top:-60px; right:-60px;
    width:220px; height:220px; background:rgba(233,69,96,.12); border-radius:50%;
}
.coil-badge {
    display:inline-block; background:var(--gold); color:#fff;
    font-size:.7rem; font-weight:700; letter-spacing:2px; text-transform:uppercase;
    padding:3px 10px; border-radius:4px; margin-bottom:8px;
}
.journal-header h2 { font-size:2rem; font-weight:800; letter-spacing:-.5px; margin:0 0 4px; }
.journal-header .sub { opacity:.7; font-size:.9rem; }

/* ── Stat pills ── */
.stat-pill {
    background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.2);
    border-radius:10px; padding:12px 20px; text-align:center; min-width:110px;
}
.stat-pill .val { font-size:1.5rem; font-weight:800; }
.stat-pill .lbl { font-size:.7rem; opacity:.65; letter-spacing:1px; text-transform:uppercase; }
.util-bar-wrap  { height:8px; background:rgba(255,255,255,.15); border-radius:4px; margin-top:10px; }
.util-bar       { height:8px; border-radius:4px; background:linear-gradient(90deg,#0a9960,#e94560); }

/* ── Mini stat cards (roll counts) ── */
.mini-stats { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:24px; }
.mini-stat  {
    flex:1; min-width:100px; background:#fff; border-radius:10px;
    border:1px solid #e5e7eb; padding:12px 16px; text-align:center;
    box-shadow:0 1px 4px rgba(0,0,0,.05);
}
.mini-stat .n  { font-size:1.6rem; font-weight:800; line-height:1; }
.mini-stat .l  { font-size:.68rem; font-weight:600; text-transform:uppercase;
                 letter-spacing:1px; color:#9ca3af; margin-top:4px; }

/* ── Process log summary bar ── */
.log-summary-bar {
    background:#f8faff; border:1px solid #dbeafe; border-radius:10px;
    padding:12px 18px; margin-bottom:24px; display:flex; gap:16px; flex-wrap:wrap;
    align-items:center;
}
.log-pill {
    display:inline-flex; align-items:center; gap:6px;
    font-size:.75rem; font-weight:600; padding:4px 10px;
    border-radius:20px; border:1px solid transparent;
}
.lp-slitting  { background:#dbeafe; color:#1e40af; border-color:#bfdbfe; }
.lp-recoiling { background:#fef3c7; color:#92400e; border-color:#fde68a; }
.lp-reslit    { background:#d1fae5; color:#065f46; border-color:#a7f3d0; }
.lp-sfc       { background:#ede9fe; color:#5b21b6; border-color:#ddd6fe; }
.lp-stock     { background:#f1f5f9; color:#334155; border-color:#cbd5e1; }

/* ── Section title ── */
.section-title {
    display:flex; align-items:center; gap:10px;
    font-size:.8rem; font-weight:700; letter-spacing:2px; text-transform:uppercase;
    color:#888; margin:28px 0 14px;
}
.section-title::after { content:''; flex:1; height:1px; background:#e0e0e0; }

/* ── Timeline ── */
.timeline { position:relative; padding-left:36px; }
.timeline::before {
    content:''; position:absolute; left:11px; top:0; bottom:0;
    width:2px; background:linear-gradient(to bottom,#ddd 80%,transparent);
}
.tl-item { position:relative; margin-bottom:12px; }
.tl-dot  {
    position:absolute; left:-29px; top:14px;
    width:14px; height:14px; border-radius:50%; border:2px solid #fff;
}

/* ── Roll card ── */
.tl-card {
    background:#fff; border:1px solid #e8e8e8; border-radius:10px;
    padding:13px 16px; transition:box-shadow .15s;
}
.tl-card:hover { box-shadow:0 4px 18px rgba(0,0,0,.08); }
.tl-card-head  { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:8px; }
.tl-card-body  { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
.tl-card-foot  {
    margin-top:8px; padding-top:8px; border-top:1px solid #f3f4f6;
    font-size:.72rem; color:#9ca3af; display:flex; gap:16px; flex-wrap:wrap;
}

.tl-ref {
    font-family:'Courier New',monospace; font-weight:700; font-size:.95rem;
    color:var(--ink);
}

/* ── Chips ── */
.chip { font-size:.7rem; padding:2px 9px; border-radius:20px; font-weight:600; }
.chip-width  { background:#e8f4fd; color:#0369a1; }
.chip-len    { background:#f0fdf4; color:#166534; }
.chip-actual { background:#fef3c7; color:#92400e; }
.chip-date   { background:#f3f4f6; color:#6b7280; }
.chip-cut    { background:#fce7f3; color:#9d174d; }
.chip-left   { background:#fff7ed; color:#c2410c; }

/* ── Status badges ── */
.s-badge { font-size:.65rem; font-weight:700; letter-spacing:1px; padding:3px 8px;
           border-radius:4px; text-transform:uppercase; white-space:nowrap; }
.s-in        { background:#dcfce7; color:#166534; }
.s-waiting   { background:#fef9c3; color:#854d0e; }
.s-approved  { background:#dbeafe; color:#1e40af; }
.s-delivered { background:#d1fae5; color:#065f46; }
.s-rejected  { background:#fee2e2; color:#991b1b; }
.s-pending   { background:#fef3c7; color:#92400e; }
.s-completed { background:#d1fae5; color:#065f46; }
.s-sfc       { background:#e0e7ff; color:#3730a3; }

/* ── Process flags ── */
.pf { font-size:.65rem; font-weight:700; letter-spacing:.8px; padding:3px 9px;
      border-radius:4px; text-transform:uppercase; white-space:nowrap; }
.pf-normal    { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.pf-reslit    { background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; }
.pf-recoiling { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
.pf-sfc       { background:#f5f3ff; color:#5b21b6; border:1px solid #ddd6fe; }
.pf-cut2      { background:#fdf2f8; color:#86198f; border:1px solid #f5d0fe; }
.pf-output    { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
.pf-leftover  { background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; }

/* ── Sub-blocks (recoiling / reslit / leftover) ── */
.sub-block {
    margin-left:26px; margin-top:8px; border-radius:10px; padding:12px 16px;
}
.sub-block-recoil  { background:#f0f7ff; border:1px solid #bfdbfe; }
.sub-block-reslit  { background:#fffbf0; border:1px solid #fde68a; }
.sub-block-leftover{ background:#fff7ed; border:1px solid #fed7aa; }
.sub-block-sfc     { background:#f5f3ff; border:1px solid #ddd6fe; }

.sub-block-title {
    font-size:.72rem; font-weight:700; letter-spacing:1.5px; text-transform:uppercase;
    margin-bottom:8px; display:flex; align-items:center; gap:6px;
}
.sbt-recoil  { color:#1d4ed8; }
.sbt-reslit  { color:#92400e; }
.sbt-leftover{ color:#c2410c; }
.sbt-sfc     { color:#5b21b6; }

.sub-row {
    background:#fff; border-radius:8px; padding:9px 13px;
    display:flex; align-items:center; gap:8px; flex-wrap:wrap;
    margin-bottom:6px; font-size:.85rem; border:1px solid #e5e7eb;
}
.sub-row:last-child { margin-bottom:0; }
.sub-row-ref { font-family:monospace; font-weight:700; min-width:160px; }

/* ── Trace chain badge ── */
.chain-badge {
    font-size:.65rem; background:#1e293b; color:#94a3b8;
    padding:2px 8px; border-radius:4px; font-family:monospace;
}

/* ── Empty ── */
.empty-state {
    padding:28px; text-align:center; color:#aaa;
    background:#fafafa; border:1px dashed #ddd; border-radius:10px; font-size:.85rem;
}

@media print {
    .no-print { display:none !important; }
    .journal-header { background:#1a1a2e !important; -webkit-print-color-adjust:exact; }
}
</style>

<!-- ═══════════════════════════════════════════
     HEADER
═══════════════════════════════════════════ -->
<div class="journal-header">
    <a href="mother_coil.php" class="btn btn-sm btn-outline-light mb-3 no-print">
        <i class="bi bi-arrow-left"></i> Back to Mother Coils
    </a>
    <div class="coil-badge">Mother Coil Journal</div>
    <h2><?= htmlspecialchars($mother['lot_no']) ?> &nbsp;·&nbsp; <?= htmlspecialchars($mother['coil_no']) ?></h2>
    <div class="sub">
        <?= htmlspecialchars($mother['product']) ?>
        &nbsp;|&nbsp; Grade: <strong><?= htmlspecialchars($mother['grade']) ?></strong>
        &nbsp;|&nbsp; Width: <strong><?= number_format((float)$mother['width']) ?> mm</strong>
        &nbsp;|&nbsp; Original Length: <strong><?= number_format($input_length, 1) ?> m</strong>
        &nbsp;|&nbsp; Status: <strong><?= htmlspecialchars($mother['status']) ?></strong>
    </div>
    <div class="d-flex flex-wrap gap-3 mt-4">
        <div class="stat-pill">
            <div class="val"><?= count($slit_rows) ?></div>
            <div class="lbl">Direct Rolls</div>
        </div>
        <div class="stat-pill">
            <div class="val"><?= $utilization ?>%</div>
            <div class="lbl">Utilisation</div>
            <div class="util-bar-wrap">
                <div class="util-bar" style="width:<?= min($utilization,100) ?>%"></div>
            </div>
        </div>
        <div class="stat-pill">
            <div class="val"><?= count($leftover_rows) ?></div>
            <div class="lbl">Leftovers</div>
        </div>
        <div class="stat-pill">
            <div class="val"><?= count($sfc_rows) ?></div>
            <div class="lbl">SFC Items</div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     MINI ROLL STATUS CARDS
═══════════════════════════════════════════ -->
<div class="mini-stats">
    <div class="mini-stat">
        <div class="n text-secondary"><?= $total_rolls ?></div>
        <div class="l">Total Rolls</div>
    </div>
    <div class="mini-stat">
        <div class="n text-info"><?= $pending_count ?></div>
        <div class="l">Pending</div>
    </div>
    <div class="mini-stat">
        <div class="n text-primary"><?= $stock_count ?></div>
        <div class="l">In Stock</div>
    </div>
    <div class="mini-stat">
        <div class="n text-warning"><?= $waiting_count ?></div>
        <div class="l">Waiting QC</div>
    </div>
    <div class="mini-stat">
        <div class="n" style="color:#2563eb"><?= $approved_count ?></div>
        <div class="l">Approved</div>
    </div>
    <div class="mini-stat">
        <div class="n text-success"><?= $delivered_count ?></div>
        <div class="l">Delivered</div>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     PROCESS LOG ACTIVITY SUMMARY
═══════════════════════════════════════════ -->
<?php if (!empty($log_summary)): ?>
<div class="log-summary-bar">
    <span style="font-size:.72rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:1px;">
        <i class="bi bi-clock-history me-1"></i> Activity Log:
    </span>
    <?php foreach ($log_summary as $ls):
        $cls = 'lp-' . $ls['entity_type'];
    ?>
    <span class="log-pill <?= $cls ?>">
        <strong><?= strtoupper($ls['entity_type']) ?></strong>
        <?= $ls['total_events'] ?> events
        · Last: <?= date('d M H:i', strtotime($ls['last_event'])) ?>
    </span>
    <?php endforeach; ?>
    <a href="process_log_viewer.php?lot_no=<?= urlencode($mother['lot_no']) ?>" 
       class="btn btn-outline-primary btn-sm ms-auto no-print" style="font-size:.72rem">
        <i class="bi bi-list-ul me-1"></i> Full Log
    </a>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════
     SLITTING OUTPUT ROLLS — TIMELINE
═══════════════════════════════════════════ -->
<div class="section-title">
    <i class="bi bi-scissors text-success"></i>
    Slitting Output Rolls
    <span class="badge bg-success ms-1"><?= count($slit_rows) ?></span>
</div>

<?php if (count($slit_rows) > 0): ?>
<div class="timeline">
<?php foreach ($slit_rows as $sp):
    $spStatus      = strtoupper($sp['status'] ?? 'IN');
    $spDot         = dotColor($spStatus);
    $isCut2        = ($sp['cut_type'] ?? 'normal') === 'cut_into_2';
    $leftoverLen   = floatval($sp['leftover_length'] ?? $sp['stock'] ?? 0);

    // Reslit entries for this roll (UPDATED: via FK)
    $rslEntries    = $reslit_entry_map[(int)$sp['id']] ?? [];

    // Recoiling entry for this roll (UPDATED: via FK)
    $recoilEntry   = $recoiling_map[(int)$sp['id']] ?? null;
    $recoilOutputs = $recoilEntry ? ($recoil_output_map[(int)$recoilEntry['id']] ?? []) : [];
?>
<div class="tl-item">
    <div class="tl-dot" style="background:<?= $spDot ?>; box-shadow:0 0 0 3px <?= $spDot ?>33;"></div>

    <!-- ── Main Roll Card ── -->
    <div class="tl-card">

        <!-- Head row: ref + status + flags -->
        <div class="tl-card-head">
            <span class="tl-ref">
                <?= htmlspecialchars($sp['lot_no'] ?? '') ?>
                <?= htmlspecialchars($sp['coil_no'] ?? '') ?>
                – <?= fmtRoll($sp['roll_no'] ?? '') ?>
            </span>
            <?= statusBadge($spStatus) ?>
            <?php if ($isCut2): ?>
                <span class="pf pf-cut2"><i class="bi bi-intersect me-1"></i>Cut Into 2</span>
            <?php else: ?>
                <span class="pf pf-normal"><i class="bi bi-scissors me-1"></i>Normal</span>
            <?php endif; ?>
            <?php if ($sp['is_reslitted']): ?>
                <span class="pf pf-reslit"><i class="bi bi-arrow-repeat me-1"></i>Sent to Reslit</span>
            <?php endif; ?>
            <?php if ($sp['is_recoiled']): ?>
                <span class="pf pf-recoiling"><i class="bi bi-arrow-counterclockwise me-1"></i>Sent to Recoiling</span>
            <?php endif; ?>
            <?php if ($sp['original_source'] === 'sfc'): ?>
                <span class="pf pf-sfc"><i class="bi bi-box-seam me-1"></i>From SFC</span>
            <?php endif; ?>
        </div>

        <!-- Body row: measurements -->
        <div class="tl-card-body">
            <span class="chip chip-width"><?= number_format((float)($sp['width'] ?? 0)) ?> mm</span>
            <span class="chip chip-len">Nom: <?= number_format((float)($sp['length'] ?? 0), 1) ?> m</span>
            <?php if ($sp['actual_length']): ?>
                <span class="chip chip-actual">Actual: <?= number_format((float)$sp['actual_length'], 1) ?> m</span>
            <?php else: ?>
                <span class="chip" style="background:#fef2f2;color:#dc2626">Actual: Not set</span>
            <?php endif; ?>
            <?php if ($isCut2 && $leftoverLen > 0): ?>
                <span class="chip chip-left">
                    <i class="bi bi-arrow-return-right me-1"></i>Leftover: <?= number_format($leftoverLen, 1) ?> m
                </span>
            <?php endif; ?>
            <?php if ($sp['date_in']): ?>
                <span class="chip chip-date"><?= date('d M Y', strtotime($sp['date_in'])) ?></span>
            <?php endif; ?>
        </div>

        <!-- Foot row: trace chain + log info -->
        <div class="tl-card-foot">
            <span>
                <i class="bi bi-diagram-2 me-1"></i>
                <span class="chain-badge">mother#<?= $mother_id ?></span>
                → <span class="chain-badge">slit#<?= $sp['id'] ?></span>
                <?php if ($sp['parent_slit_id']): ?>
                    (via recoil of <span class="chain-badge">slit#<?= $sp['parent_slit_id'] ?></span>)
                <?php endif; ?>
            </span>
            <?php if ($sp['date_out']): ?>
                <span><i class="bi bi-box-arrow-right me-1"></i>Out: <?= date('d M Y', strtotime($sp['date_out'])) ?></span>
            <?php endif; ?>
            <?php if ($sp['delivered_at']): ?>
                <span><i class="bi bi-truck me-1"></i>Delivered: <?= date('d M Y', strtotime($sp['delivered_at'])) ?></span>
            <?php endif; ?>
            <span class="ms-auto text-muted">ID #<?= $sp['id'] ?> · source: <?= htmlspecialchars($sp['source'] ?? 'raw_material') ?></span>
        </div>
    </div>

    <!-- ── Leftover Stock Sub-block ── -->
    <?php if ($isCut2 && $leftoverLen > 0):
        // Find matching leftover in stock
        $matchLeftover = null;
        foreach ($leftover_rows as $lr) {
            if (strpos($lr['lot_no'], $sp['lot_no']) !== false) {
                $matchLeftover = $lr;
                break;
            }
        }
    ?>
    <div class="sub-block sub-block-leftover">
        <div class="sub-block-title sbt-leftover">
            <i class="bi bi-arrow-return-right"></i> Leftover Balance → Stock
        </div>
        <div class="sub-row">
            <span class="pf pf-leftover">BALANCE STOCK</span>
            <span class="sub-row-ref"><?= htmlspecialchars($sp['lot_no']) ?> <?= htmlspecialchars($sp['coil_no']) ?></span>
            <span class="chip chip-left"><?= number_format($leftoverLen, 1) ?> m remaining</span>
            <?php if ($matchLeftover): ?>
                <span class="chip chip-date">Added: <?= date('d M Y', strtotime($matchLeftover['date_in'])) ?></span>
                <span class="badge <?= $matchLeftover['status']==='IN' ? 'bg-success' : 'bg-secondary' ?>">
                    <?= $matchLeftover['status'] === 'IN' ? 'Still in Stock' : 'Used / OUT' ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Reslit Sub-block ── -->
    <?php foreach ($rslEntries as $rsEntry):
        $rsRolls = $reslit_rolls_map[(int)$rsEntry['id']] ?? [];
    ?>
    <div class="sub-block sub-block-reslit">
        <div class="sub-block-title sbt-reslit">
            <i class="bi bi-arrow-repeat"></i>
            Reslit Process
            <span class="badge <?= $rsEntry['status']==='completed' ? 'bg-success' : 'bg-warning text-dark' ?>" style="font-size:.6rem">
                <?= strtoupper($rsEntry['status']) ?>
            </span>
            <span style="font-weight:400; color:#78716c; font-size:.7rem;">
                · <?= date('d M Y', strtotime($rsEntry['date_in'])) ?>
            </span>
            <span class="chain-badge ms-auto">reslit#<?= $rsEntry['id'] ?></span>
        </div>

        <?php if (!empty($rsRolls)): ?>
            <?php foreach ($rsRolls as $rr): ?>
            <div class="sub-row">
                <i class="bi bi-arrow-right-circle text-warning"></i>
                <span class="sub-row-ref">
                    <?= htmlspecialchars(($sp['lot_no'] ?? '') . ($rr['cut_letter'] ?? '')) ?>
                    <?= htmlspecialchars($sp['coil_no'] ?? '') ?>
                    – <?= fmtRoll($rr['roll_no'] ?? '') ?>
                </span>
                <span class="chip chip-width"><?= number_format((float)($rr['new_width'] ?? 0)) ?> mm</span>
                <span class="chip chip-len">Nom: <?= number_format((float)($rr['length'] ?? 0), 1) ?> m</span>
                <span class="chip chip-actual">Actual: <?= number_format((float)($rr['actual_length'] ?? 0), 1) ?> m</span>
                <?php if ($rr['cut_letter']): ?>
                    <span class="badge bg-light text-dark border" style="font-size:.65rem">
                        Letter: <?= htmlspecialchars($rr['cut_letter']) ?>
                    </span>
                <?php endif; ?>
                <span class="pf pf-reslit ms-auto">Reslit Output</span>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-muted small fst-italic ps-2">
                <i class="bi bi-hourglass-split me-1"></i>Reslit queued — no output rolls yet.
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <!-- ── Recoiling Sub-block ── -->
    <?php if ($recoilEntry): ?>
    <div class="sub-block sub-block-recoil">
        <div class="sub-block-title sbt-recoil">
            <i class="bi bi-arrow-counterclockwise"></i>
            Recoiling Process
            <span class="badge <?= $recoilEntry['status']==='completed' ? 'bg-success' : 'bg-warning text-dark' ?>" style="font-size:.6rem">
                <?= strtoupper($recoilEntry['status']) ?>
            </span>
            <span style="font-weight:400; color:#64748b; font-size:.7rem;">
                · In: <?= date('d M Y', strtotime($recoilEntry['date_in'])) ?>
                <?php if ($recoilEntry['completed_at']): ?>
                    · Done: <?= date('d M Y', strtotime($recoilEntry['completed_at'])) ?>
                <?php endif; ?>
            </span>
            <span class="chain-badge ms-auto">recoil#<?= $recoilEntry['id'] ?></span>
        </div>

        <?php if ($recoilEntry['remark']): ?>
        <div class="small text-muted mb-2 ps-1">
            <i class="bi bi-chat-text me-1"></i><?= htmlspecialchars($recoilEntry['remark']) ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($recoilOutputs)): ?>
            <?php foreach ($recoilOutputs as $ro):
                $roStatus    = strtoupper($ro['status'] ?? 'IN');
                // Reslit children of this recoil output
                $roRslEntries = $reslit_entry_map[(int)$ro['id']] ?? [];
            ?>
            <div class="sub-row">
                <i class="bi bi-arrow-right-circle-fill text-primary"></i>
                <span class="sub-row-ref">
                    <?= htmlspecialchars($ro['lot_no'] ?? '') ?>
                    <?= htmlspecialchars($ro['coil_no'] ?? '') ?>
                    – <?= fmtRoll($ro['roll_no'] ?? '') ?>
                </span>
                <?= statusBadge($roStatus) ?>
                <span class="chip chip-width"><?= number_format((float)($ro['width'] ?? 0)) ?> mm</span>
                <span class="chip chip-len">Nom: <?= number_format((float)($ro['length'] ?? 0), 1) ?> m</span>
                <?php if ($ro['actual_length']): ?>
                    <span class="chip chip-actual">Actual: <?= number_format((float)$ro['actual_length'], 1) ?> m</span>
                <?php endif; ?>
                <?php if ($ro['date_in']): ?>
                    <span class="chip chip-date"><?= date('d M Y', strtotime($ro['date_in'])) ?></span>
                <?php endif; ?>
                <span class="pf pf-output ms-auto">Recoil Output</span>
                <!-- Trace chain -->
                <span class="chain-badge">slit#<?= $ro['id'] ?> · parent#<?= $ro['parent_slit_id'] ?></span>

                <?php if ($ro['is_reslitted']): ?>
                    <span class="pf pf-reslit"><i class="bi bi-arrow-repeat me-1"></i>→ Reslit</span>
                <?php endif; ?>
                <?php if ($roStatus === 'DELIVERED'): ?>
                    <span class="pf pf-normal"><i class="bi bi-truck me-1"></i>Delivered</span>
                <?php endif; ?>
            </div>

            <!-- Reslit children of recoil output -->
            <?php foreach ($roRslEntries as $roRsl):
                $roRslRolls = $reslit_rolls_map[(int)$roRsl['id']] ?? [];
            ?>
            <div style="margin-left:18px; margin-top:6px; margin-bottom:6px;">
                <div class="sub-block sub-block-reslit" style="padding:8px 12px;">
                    <div class="sub-block-title sbt-reslit" style="margin-bottom:6px;">
                        <i class="bi bi-arrow-repeat"></i> Reslit of Recoil Output
                        <span class="badge <?= $roRsl['status']==='completed' ? 'bg-success' : 'bg-warning text-dark' ?>" style="font-size:.6rem">
                            <?= strtoupper($roRsl['status']) ?>
                        </span>
                        <span class="chain-badge ms-auto">reslit#<?= $roRsl['id'] ?></span>
                    </div>
                    <?php foreach ($roRslRolls as $rrr): ?>
                    <div class="sub-row" style="background:#fffbf0; border-color:#fde68a;">
                        <i class="bi bi-arrow-return-right text-warning"></i>
                        <span class="sub-row-ref" style="font-size:.8rem;">
                            <?= htmlspecialchars(($ro['lot_no'] ?? '') . ($rrr['cut_letter'] ?? '')) ?>
                            <?= htmlspecialchars($ro['coil_no'] ?? '') ?>
                            – <?= fmtRoll($rrr['roll_no'] ?? '') ?>
                        </span>
                        <span class="chip chip-width"><?= number_format((float)($rrr['new_width'] ?? 0)) ?> mm</span>
                        <span class="chip chip-actual"><?= number_format((float)($rrr['actual_length'] ?? 0), 1) ?> m</span>
                        <span class="pf pf-reslit ms-auto">Reslit Output</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-muted small fst-italic ps-2">
                <i class="bi bi-hourglass-split me-1"></i>Recoiling in progress — no output rolls yet.
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div><!-- /tl-item -->
<?php endforeach; ?>
</div><!-- /timeline -->

<?php else: ?>
<div class="empty-state"><i class="bi bi-inbox me-2"></i>No slitting outputs recorded yet.</div>
<?php endif; ?>


<!-- ═══════════════════════════════════════════
     SFC SECTION
═══════════════════════════════════════════ -->
<?php if (count($sfc_rows) > 0): ?>
<div class="section-title">
    <i class="bi bi-box-seam" style="color:#5b21b6"></i>
    SFC Items (Scrap / Balance Width)
    <span class="badge ms-1" style="background:#5b21b6"><?= count($sfc_rows) ?></span>
</div>
<div class="timeline">
<?php foreach ($sfc_rows as $sf):
    $sfDot = $sf['date_out'] ? '#6b7280' : '#5b21b6';
?>
<div class="tl-item">
    <div class="tl-dot" style="background:<?= $sfDot ?>; box-shadow:0 0 0 3px <?= $sfDot ?>33;"></div>
    <div class="tl-card">
        <div class="tl-card-head">
            <span class="tl-ref">
                <?= htmlspecialchars($sf['lot_no']) ?>
                <?= htmlspecialchars($sf['coil_no']) ?>
                <?= $sf['roll_no'] ? '– ' . fmtRoll($sf['roll_no']) : '' ?>
            </span>
            <?php if ($sf['date_out']): ?>
                <span class="s-badge" style="background:#e5e7eb;color:#374151">
                    USED → <?= htmlspecialchars($sf['action'] ?? '') ?>
                </span>
            <?php else: ?>
                <span class="s-badge s-sfc">IN SFC</span>
            <?php endif; ?>
            <span class="pf pf-sfc ms-auto"><i class="bi bi-box-seam me-1"></i>SFC</span>
        </div>
        <div class="tl-card-body">
            <span class="chip chip-width"><?= number_format((float)($sf['width'] ?? 0)) ?> mm</span>
            <span class="chip chip-len"><?= number_format((float)($sf['length'] ?? 0), 2) ?> m</span>
            <span class="chip chip-date"><?= date('d M Y', strtotime($sf['date_created'])) ?></span>
            <?php if ($sf['date_out']): ?>
                <span class="chip chip-date">Out: <?= date('d M Y', strtotime($sf['date_out'])) ?></span>
            <?php endif; ?>
        </div>
        <div class="tl-card-foot">
            <?php if ($sf['mother_id']): ?>
                <span class="chain-badge">mother#<?= $sf['mother_id'] ?></span>
                → <span class="chain-badge">sfc#<?= $sf['sfc_id'] ?></span>
            <?php endif; ?>
            <span class="text-muted">Action: <?= htmlspecialchars($sf['action'] ?? 'slitting') ?></span>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>


<!-- ═══════════════════════════════════════════
     LEFTOVER STOCK SECTION
═══════════════════════════════════════════ -->
<?php if (count($leftover_rows) > 0): ?>
<div class="section-title">
    <i class="bi bi-box-arrow-in-left" style="color:#c2410c"></i>
    Leftover Stock (Cut Into 2 Balances)
    <span class="badge ms-1" style="background:#c2410c"><?= count($leftover_rows) ?></span>
</div>
<div class="timeline">
<?php foreach ($leftover_rows as $lr):
    $lDot = $lr['status'] === 'IN' ? '#c2410c' : '#6b7280';
?>
<div class="tl-item">
    <div class="tl-dot" style="background:<?= $lDot ?>; box-shadow:0 0 0 3px <?= $lDot ?>33;"></div>
    <div class="tl-card">
        <div class="tl-card-head">
            <span class="tl-ref">
                <?= htmlspecialchars($lr['lot_no']) ?>
                <?= htmlspecialchars($lr['coil_no']) ?>
            </span>
            <span class="badge <?= $lr['status']==='IN' ? 'bg-success' : 'bg-secondary' ?>">
                <?= $lr['status'] === 'IN' ? 'IN STOCK' : 'USED / OUT' ?>
            </span>
            <span class="pf pf-leftover ms-auto">
                <i class="bi bi-arrow-return-right me-1"></i>Leftover Balance
            </span>
        </div>
        <div class="tl-card-body">
            <span class="chip chip-left"><?= number_format((float)($lr['length'] ?? 0), 1) ?> m</span>
            <span class="chip chip-width"><?= number_format((float)($lr['width'] ?? 0)) ?> mm</span>
            <?php if ($lr['date_in']): ?>
                <span class="chip chip-date">In: <?= date('d M Y', strtotime($lr['date_in'])) ?></span>
            <?php endif; ?>
            <?php if ($lr['updated_at'] && $lr['status'] === 'OUT'): ?>
                <span class="chip chip-date">Used: <?= date('d M Y', strtotime($lr['updated_at'])) ?></span>
            <?php endif; ?>
        </div>
        <div class="tl-card-foot">
            <span class="chain-badge">mother#<?= $lr['source_id'] ?></span>
            → <span class="chain-badge">stock#<?= $lr['id'] ?></span>
            <span class="text-muted">Grade: <?= htmlspecialchars($lr['grade'] ?? '—') ?></span>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>


<?php if (count($slit_rows) === 0 && count($sfc_rows) === 0 && count($leftover_rows) === 0): ?>
<div class="empty-state mt-4">
    <i class="bi bi-journal-x" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
    No production output recorded for this mother coil yet.
</div>
<?php endif; ?>


<!-- ═══════════════════════════════════════════
     FULL SUMMARY TABLE
═══════════════════════════════════════════ -->
<?php if (count($slit_rows) > 0): ?>
<div class="section-title mt-5">
    <i class="bi bi-table text-secondary"></i> Full Output Summary
</div>
<div class="table-responsive">
    <table class="table table-bordered table-sm align-middle text-center" style="font-size:.82rem;">
        <thead class="table-dark">
            <tr>
                <th>#</th>
                <th>Lot · Coil · Roll</th>
                <th>Cut Type</th>
                <th>Stage</th>
                <th>Width (mm)</th>
                <th>Nom (m)</th>
                <th>Actual (m)</th>
                <th>Leftover (m)</th>
                <th>Status</th>
                <th>Date In</th>
                <th>Trace Chain</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $tableIdx = 1;
        foreach ($slit_rows as $sp):
            $process     = 'Normal';
            $leftoverLen = floatval($sp['leftover_length'] ?? $sp['stock'] ?? 0);
            if (($sp['cut_type'] ?? '') === 'cut_into_2') $process = 'Cut Into 2';
            if ($sp['is_reslitted']) $process = 'Reslit';
            if ($sp['is_recoiled'])  $process = 'Recoiling';

            $pClass = match($process) {
                'Reslit'     => 'text-warning fw-bold',
                'Recoiling'  => 'text-primary fw-bold',
                'Cut Into 2' => 'fw-bold' ,
                default      => 'text-success',
            };
        ?>
        <tr>
            <td class="text-muted"><?= $tableIdx++ ?></td>
            <td style="font-family:monospace; font-weight:700; text-align:left;">
                <?= htmlspecialchars($sp['lot_no']) ?>
                <?= htmlspecialchars($sp['coil_no']) ?>
                – <?= fmtRoll($sp['roll_no']) ?>
            </td>
            <td><span class="badge bg-secondary"><?= htmlspecialchars($sp['cut_type'] ?? 'normal') ?></span></td>
            <td class="<?= $pClass ?>"><?= $process ?></td>
            <td><?= number_format((float)($sp['width'] ?? 0)) ?></td>
            <td><?= number_format((float)($sp['length'] ?? 0), 1) ?></td>
            <td><?= $sp['actual_length'] ? number_format((float)$sp['actual_length'], 1) : '<span class="text-danger">—</span>' ?></td>
            <td><?= $leftoverLen > 0 ? number_format($leftoverLen, 1) : '—' ?></td>
            <td><?= match(strtoupper($sp['status'] ?? 'IN')) {
                'IN'        => '<span class="badge bg-info text-dark">STOCK</span>',
                'WAITING'   => '<span class="badge bg-warning text-dark">WAITING</span>',
                'APPROVED'  => '<span class="badge bg-primary">APPROVED</span>',
                'DELIVERED' => '<span class="badge bg-success">DELIVERED</span>',
                'REJECTED'  => '<span class="badge bg-danger">REJECTED</span>',
                default     => '<span class="badge bg-secondary">'.htmlspecialchars($sp['status']).'</span>',
            } ?></td>
            <td class="text-muted"><?= $sp['date_in'] ? date('d/m/y', strtotime($sp['date_in'])) : '—' ?></td>
            <td style="font-family:monospace; font-size:.7rem; color:#94a3b8;">
                m#<?= $mother_id ?>→s#<?= $sp['id'] ?>
            </td>
        </tr>

        <?php
        // Recoil outputs sub-rows
        $recoilEntry   = $recoiling_map[(int)$sp['id']] ?? null;
        $recoilOutputs = $recoilEntry ? ($recoil_output_map[(int)$recoilEntry['id']] ?? []) : [];
        foreach ($recoilOutputs as $ro):
            $roLeftover = floatval($ro['leftover_length'] ?? $ro['stock'] ?? 0);
        ?>
        <tr style="background:#f0f7ff;">
            <td class="text-muted"><?= $tableIdx++ ?></td>
            <td style="font-family:monospace; font-weight:700; text-align:left; padding-left:28px;">
                ↳ <?= htmlspecialchars($ro['lot_no']) ?>
                <?= htmlspecialchars($ro['coil_no']) ?>
                – <?= fmtRoll($ro['roll_no']) ?>
            </td>
            <td><span class="badge bg-info text-dark"><?= htmlspecialchars($ro['cut_type'] ?? 'normal') ?></span></td>
            <td class="text-primary fw-bold">Recoil Output</td>
            <td><?= number_format((float)($ro['width'] ?? 0)) ?></td>
            <td><?= number_format((float)($ro['length'] ?? 0), 1) ?></td>
            <td><?= $ro['actual_length'] ? number_format((float)$ro['actual_length'], 1) : '<span class="text-danger">—</span>' ?></td>
            <td><?= $roLeftover > 0 ? number_format($roLeftover, 1) : '—' ?></td>
            <td><?= match(strtoupper($ro['status'] ?? 'IN')) {
                'IN'        => '<span class="badge bg-info text-dark">STOCK</span>',
                'WAITING'   => '<span class="badge bg-warning text-dark">WAITING</span>',
                'APPROVED'  => '<span class="badge bg-primary">APPROVED</span>',
                'DELIVERED' => '<span class="badge bg-success">DELIVERED</span>',
                'REJECTED'  => '<span class="badge bg-danger">REJECTED</span>',
                default     => '<span class="badge bg-secondary">'.htmlspecialchars($ro['status']).'</span>',
            } ?></td>
            <td class="text-muted"><?= $ro['date_in'] ? date('d/m/y', strtotime($ro['date_in'])) : '—' ?></td>
            <td style="font-family:monospace; font-size:.7rem; color:#94a3b8;">
                m#<?= $mother_id ?>→s#<?= $sp['id'] ?>→r#<?= $recoilEntry['id'] ?>→s#<?= $ro['id'] ?>
            </td>
        </tr>
        <?php endforeach; ?>

        <?php endforeach; ?>

        <!-- ── SFC Output Rows ── -->
        <?php if (!empty($sfc_output_rows)): ?>
        <tr>
            <td colspan="11" class="text-center py-1"
                style="background:#f5f3ff; font-size:.7rem; font-weight:700;
                       letter-spacing:1.5px; text-transform:uppercase; color:#5b21b6;">
                <i class="bi bi-box-seam me-1"></i>
                SFC Outputs — Rolls produced from SFC items of this mother coil
            </td>
        </tr>
        <?php foreach ($sfc_output_rows as $sfr):
            $sfAction = $sfr['_sfc_action'] ?? '';

            // Build lot·coil·roll reference
            if ($sfAction === 'RESLIT') {
                $sfRef  = htmlspecialchars(($sfr['sfc_lot'] ?? '') . ($sfr['cut_letter'] ?? ''))
                        . ' ' . htmlspecialchars($sfr['sfc_coil'] ?? '')
                        . ' – ' . fmtRoll($sfr['roll_no'] ?? '');
                $sfW    = floatval($sfr['new_width']     ?? 0);
                $sfL    = floatval($sfr['length']        ?? 0);
                $sfAct  = floatval($sfr['actual_length'] ?? 0);
                $sfSt   = 'completed'; // reslit_rolls don't carry status, parent does
                $sfDate = '—';
            } else {
                $sfRef  = htmlspecialchars($sfr['lot_no']  ?? '')
                        . ' ' . htmlspecialchars($sfr['coil_no'] ?? '')
                        . ' – ' . fmtRoll($sfr['roll_no'] ?? '');
                $sfW    = floatval($sfr['width']        ?? 0);
                $sfL    = floatval($sfr['length']       ?? 0);
                $sfAct  = floatval($sfr['actual_length'] ?? 0);
                $sfSt   = $sfr['status'] ?? 'IN';
                $sfDate = isset($sfr['date_in']) && $sfr['date_in'] ? date('d/m/y', strtotime($sfr['date_in'])) : '—';
            }

            $sfBadge = match($sfAction) {
                'RESLIT' => '<span class="badge" style="background:#92400e">SFC→RESLIT</span>',
                'RECOIL' => '<span class="badge" style="background:#1d4ed8">SFC→RECOIL</span>',
                'SELL'   => '<span class="badge" style="background:#166534">SFC→SELL</span>',
                default  => '<span class="badge bg-secondary">SFC</span>',
            };

            $sfStBadge = match(strtoupper($sfSt)) {
                'IN'        => '<span class="badge bg-info text-dark">STOCK</span>',
                'WAITING'   => '<span class="badge bg-warning text-dark">WAITING</span>',
                'APPROVED'  => '<span class="badge bg-primary">APPROVED</span>',
                'DELIVERED' => '<span class="badge bg-success">DELIVERED</span>',
                'REJECTED'  => '<span class="badge bg-danger">REJECTED</span>',
                'COMPLETED' => '<span class="badge bg-success">COMPLETED</span>',
                default     => '<span class="badge bg-secondary">'.htmlspecialchars($sfSt).'</span>',
            };
        ?>
        <tr style="background:#faf5ff; border-left:3px solid #7c3aed;">
            <td class="text-muted"><?= $tableIdx++ ?></td>
            <td style="font-family:monospace; font-weight:700; text-align:left;">
                <?= $sfRef ?>
            </td>
            <td><?= $sfBadge ?></td>
            <td style="color:#5b21b6; font-weight:700;">SFC Output</td>
            <td><?= $sfW > 0 ? number_format($sfW) : '—' ?></td>
            <td><?= $sfL > 0 ? number_format($sfL, 1) : '—' ?></td>
            <td><?= $sfAct > 0 ? number_format($sfAct, 1) : '<span class="text-danger">—</span>' ?></td>
            <td>—</td>
            <td><?= $sfStBadge ?></td>
            <td class="text-muted"><?= $sfDate ?></td>
            <td style="font-family:monospace; font-size:.7rem; color:#7c3aed;">
                m#<?= $mother_id ?>→sfc#<?= $sfr['_sfc_id'] ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>

        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="mt-4 mb-5 d-flex gap-2 no-print">
    <a href="mother_coil.php" class="btn btn-secondary">← Back</a>
    <button onclick="window.print()" class="btn btn-outline-dark">
        <i class="bi bi-printer me-1"></i> Print Journal
    </button>
    <a href="process_log_viewer.php?lot_no=<?= urlencode($mother['lot_no']) ?>"
       class="btn btn-outline-primary">
        <i class="bi bi-clock-history me-1"></i> View Full Process Log
    </a>
</div>

<?php include 'footer.php'; ?>
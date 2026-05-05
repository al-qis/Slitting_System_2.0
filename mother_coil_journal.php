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

// ── Mother coil info ─────────────────────────────────────────
$mother = $conn->query("SELECT * FROM mother_coil WHERE id = $mother_id")->fetch_assoc();
if (!$mother) die("Mother coil not found.");

// ── 1. All DIRECT slitting products from this mother coil ────
//       (first-generation rolls, NOT recoiling outputs)
$slit_res = $conn->query("
    SELECT * FROM slitting_product
    WHERE mother_id = $mother_id
      AND (recoiling_id IS NULL OR recoiling_id = 0)
    ORDER BY id ASC
");
$slit_rows = [];
while ($r = $slit_res->fetch_assoc()) $slit_rows[] = $r;

// ── 2. Recoiling entries that came from those slit rolls ─────
//       We join recoiling_product to slitting_product to find
//       which recoiling record each slit roll went into.
$slit_ids = array_column($slit_rows, 'id');

// Map: slitting_product.id  →  recoiling_product row
$recoiling_map = [];  // keyed by slitting_product id
if (!empty($slit_ids)) {
    $id_list = implode(',', $slit_ids);
    // recoiling_product doesn't store slit_id directly, but we can
    // match on lot_no + coil_no + roll_no which are copied on send_to_recoiling
    foreach ($slit_rows as $sr) {
        $ln = $conn->real_escape_string($sr['lot_no']  ?? '');
        $cn = $conn->real_escape_string($sr['coil_no'] ?? '');
        $rn = $conn->real_escape_string($sr['roll_no'] ?? '');
        $rp = $conn->query("
            SELECT * FROM recoiling_product
            WHERE lot_no='$ln' AND coil_no='$cn' AND roll_no='$rn'
            ORDER BY id DESC LIMIT 1
        ")->fetch_assoc();
        if ($rp) $recoiling_map[$sr['id']] = $rp;
    }
}

// ── 3. Recoiling OUTPUT rolls (slitting_product with recoiling_id set) ──
//       keyed by recoiling_product.id
$recoil_output_map = []; // recoiling_product.id → [ slitting_product rows ]
$ro_res = $conn->query("
    SELECT sp.*
    FROM slitting_product sp
    WHERE sp.recoiling_id IS NOT NULL
      AND sp.recoiling_id IN (
          SELECT id FROM recoiling_product
          WHERE lot_no LIKE '" . $conn->real_escape_string($mother['lot_no']) . "%'
            AND coil_no = '" . $conn->real_escape_string($mother['coil_no']) . "'
      )
    ORDER BY sp.recoiling_id ASC, sp.id ASC
");
if ($ro_res) {
    while ($r = $ro_res->fetch_assoc()) {
        $recoil_output_map[(int)$r['recoiling_id']][] = $r;
    }
}

// ── 4. Reslit children ───────────────────────────────────────
//       For any roll (original OR recoil output), check reslit_rolls
$reslit_map = []; // keyed by "lot_no|coil_no|roll_no"
$rsl_res = $conn->query("
    SELECT rp.lot_no, rp.coil_no, rp.roll_no,
           rr.roll_no AS child_roll, rr.new_width, rr.actual_length AS child_actual, rr.cut_letter
    FROM reslit_product rp
    JOIN reslit_rolls rr ON rr.parent_id = rp.id
    WHERE rp.lot_no  LIKE '" . $conn->real_escape_string($mother['lot_no']) . "%'
      AND rp.coil_no = '" . $conn->real_escape_string($mother['coil_no']) . "'
    ORDER BY rp.id ASC, rr.id ASC
");
if ($rsl_res) {
    while ($r = $rsl_res->fetch_assoc()) {
        $key = $r['lot_no'] . '|' . $r['coil_no'] . '|' . $r['roll_no'];
        $reslit_map[$key][] = $r;
    }
}

// ── 5. SFC entries ───────────────────────────────────────────
$sfc_res = $conn->query("
    SELECT * FROM sfc
    WHERE lot_no  LIKE '" . $conn->real_escape_string($mother['lot_no']) . "%'
      AND coil_no = '" . $conn->real_escape_string($mother['coil_no']) . "'
    ORDER BY sfc_id ASC
");
$sfc_rows = [];
if ($sfc_res) while ($r = $sfc_res->fetch_assoc()) $sfc_rows[] = $r;

// ── Summary ──────────────────────────────────────────────────
$total_output  = array_sum(array_column($slit_rows, 'actual_length'));
$input_length  = (float)$mother['length'];
$utilization   = $input_length > 0 ? round(($total_output / $input_length) * 100, 1) : 0;

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
        default     => '<span class="s-badge s-pending">' . htmlspecialchars($status) . '</span>',
    };
}

function dotColor(string $status): string {
    return match(strtoupper($status)) {
        'DELIVERED' => '#0a9960',
        'APPROVED'  => '#1a73e8',
        'WAITING'   => '#d4a017',
        'REJECTED'  => '#e94560',
        'COMPLETED' => '#0a9960',
        'PENDING'   => '#d4a017',
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
    --ink:   #1a1a2e;
    --steel: #16213e;
    --accent:#0f3460;
    --gold:  #e94560;
    --green: #0a9960;
    --amber: #d4a017;
    --sky:   #1a73e8;
}

/* ── Header ── */
.journal-header {
    background: linear-gradient(135deg, var(--ink) 0%, var(--steel) 60%, var(--accent) 100%);
    color: #fff; border-radius: 16px; padding: 32px; margin-bottom: 28px;
    position: relative; overflow: hidden;
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

.stat-pill {
    background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.2);
    border-radius:10px; padding:12px 20px; text-align:center; min-width:110px;
}
.stat-pill .val { font-size:1.5rem; font-weight:800; }
.stat-pill .lbl { font-size:.7rem; opacity:.65; letter-spacing:1px; text-transform:uppercase; }
.util-bar-wrap { height:8px; background:rgba(255,255,255,.15); border-radius:4px; margin-top:10px; }
.util-bar      { height:8px; border-radius:4px; background:linear-gradient(90deg,#0a9960,#e94560); }

/* ── Section title ── */
.section-title {
    display:flex; align-items:center; gap:10px;
    font-size:.8rem; font-weight:700; letter-spacing:2px; text-transform:uppercase;
    color:#888; margin:32px 0 14px;
}
.section-title::after { content:''; flex:1; height:1px; background:#e0e0e0; }

/* ── Timeline ── */
.timeline { position:relative; padding-left:36px; }
.timeline::before {
    content:''; position:absolute; left:11px; top:0; bottom:0;
    width:2px; background:linear-gradient(to bottom,#ddd 80%,transparent);
}

.tl-item { position:relative; margin-bottom:10px; animation:fadeUp .3s ease both; }
@keyframes fadeUp { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }

.tl-dot {
    position:absolute; left:-29px; top:14px;
    width:14px; height:14px; border-radius:50%;
    border:2px solid #fff;
}

.tl-card {
    background:#fff; border:1px solid #e8e8e8; border-radius:10px;
    padding:13px 16px; display:flex; align-items:center; gap:12px;
    flex-wrap:wrap; transition:box-shadow .15s;
}
.tl-card:hover { box-shadow:0 4px 18px rgba(0,0,0,.08); }

.tl-ref {
    font-family:'Courier New',monospace; font-weight:700; font-size:.9rem;
    color:var(--ink); min-width:180px;
}

.tl-meta { display:flex; gap:7px; flex-wrap:wrap; align-items:center; flex:1; }

/* ── Chips ── */
.chip { font-size:.7rem; padding:2px 9px; border-radius:20px; font-weight:600; }
.chip-width  { background:#e8f4fd; color:#0369a1; }
.chip-len    { background:#f0fdf4; color:#166534; }
.chip-actual { background:#fef3c7; color:#92400e; }
.chip-date   { background:#f3f4f6; color:#6b7280; }
.chip-cut    { background:#fce7f3; color:#9d174d; }

/* ── Status badges ── */
.s-badge { font-size:.65rem; font-weight:700; letter-spacing:1px; padding:3px 8px; border-radius:4px; text-transform:uppercase; white-space:nowrap; }
.s-in        { background:#dcfce7; color:#166534; }
.s-waiting   { background:#fef9c3; color:#854d0e; }
.s-approved  { background:#dbeafe; color:#1e40af; }
.s-delivered { background:#d1fae5; color:#065f46; }
.s-rejected  { background:#fee2e2; color:#991b1b; }
.s-pending   { background:#fef3c7; color:#92400e; }
.s-completed { background:#d1fae5; color:#065f46; }
.s-sfc       { background:#e0e7ff; color:#3730a3; }

/* ── Process flags ── */
.process-flag {
    font-size:.65rem; font-weight:700; letter-spacing:1px; padding:3px 9px;
    border-radius:4px; text-transform:uppercase; white-space:nowrap;
}
.flag-normal    { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.flag-reslit    { background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; }
.flag-recoiling { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
.flag-sfc       { background:#f5f3ff; color:#5b21b6; border:1px solid #ddd6fe; }
.flag-cut2      { background:#fdf2f8; color:#86198f; border:1px solid #f5d0fe; }
.flag-output    { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }

/* ── Child rows ── */
.child-wrap  { margin-left:24px; margin-top:6px; }
.child-card  {
    background:#f8faff; border:1px dashed #c7d9f9; border-radius:8px;
    padding:10px 14px; display:flex; align-items:center; gap:10px;
    flex-wrap:wrap; font-size:.85rem; margin-bottom:6px;
}
.child-card .tl-ref { font-size:.85rem; min-width:160px; }

/* ── Recoiling block ── */
.recoil-block {
    margin-left:24px; margin-top:8px;
    background:#f0f7ff; border:1px solid #bfdbfe;
    border-radius:10px; padding:12px 16px;
}
.recoil-block-title {
    font-size:.72rem; font-weight:700; letter-spacing:1.5px;
    text-transform:uppercase; color:#1d4ed8; margin-bottom:8px;
    display:flex; align-items:center; gap:6px;
}
.recoil-output-row {
    background:#fff; border:1px solid #dbeafe; border-radius:8px;
    padding:9px 13px; display:flex; align-items:center; gap:10px;
    flex-wrap:wrap; margin-bottom:6px; font-size:.85rem;
}
.recoil-output-row:last-child { margin-bottom:0; }

/* ── Empty ── */
.empty-state {
    padding:28px; text-align:center; color:#aaa;
    background:#fafafa; border:1px dashed #ddd;
    border-radius:10px; font-size:.85rem;
}
</style>

<!-- ─── HEADER ─── -->
<div class="journal-header">
    <a href="mother_coil.php" class="btn btn-sm btn-outline-light mb-3 d-inline-flex align-items-center gap-1">
        <i class="bi bi-arrow-left"></i> Back to Mother Coils
    </a>
    <div class="coil-badge">Mother Coil Journal</div>
    <h2><?= htmlspecialchars($mother['lot_no']) ?> &nbsp;·&nbsp; <?= htmlspecialchars($mother['coil_no']) ?></h2>
    <div class="sub">
        <?= htmlspecialchars($mother['product']) ?>
        &nbsp;|&nbsp; Grade: <strong><?= htmlspecialchars($mother['grade']) ?></strong>
        &nbsp;|&nbsp; Width: <strong><?= number_format((float)$mother['width']) ?> mm</strong>
        &nbsp;|&nbsp; Original Length: <strong><?= number_format($input_length, 1) ?> m</strong>
    </div>
    <div class="d-flex flex-wrap gap-3 mt-4">
        <div class="stat-pill">
            <div class="val"><?= number_format($input_length) ?> <small style="font-size:.7rem">m</small></div>
            <div class="lbl">Input Length</div>
        </div>
        <div class="stat-pill">
            <div class="val"><?= count($slit_rows) ?></div>
            <div class="lbl">Direct Rolls</div>
        </div>
        <div class="stat-pill">
            <div class="val"><?= number_format($total_output, 1) ?> <small style="font-size:.7rem">m</small></div>
            <div class="lbl">Total Output</div>
        </div>
        <div class="stat-pill">
            <div class="val"><?= $utilization ?>%</div>
            <div class="lbl">Utilisation</div>
            <div class="util-bar-wrap">
                <div class="util-bar" style="width:<?= min($utilization,100) ?>%"></div>
            </div>
        </div>
    </div>
</div>

<!-- ─── SLITTING OUTPUT ROLLS ─── -->
<div class="section-title">
    <i class="bi bi-scissors text-success"></i>
    Slitting Output Rolls
    <span class="badge bg-success ms-1"><?= count($slit_rows) ?></span>
</div>

<?php if (count($slit_rows) > 0): ?>
<div class="timeline">
<?php foreach ($slit_rows as $idx => $sp):

    $spStatus  = strtoupper($sp['status'] ?? 'IN');
    $spDot     = dotColor($spStatus);
    $ref       = htmlspecialchars($sp['lot_no'] ?? '') . ' '
               . htmlspecialchars($sp['coil_no'] ?? '') . '-'
               . fmtRoll($sp['roll_no'] ?? '');

    // Process flags for this roll
    $flags = [];
    if (($sp['cut_type'] ?? 'normal') === 'cut_into_2')
        $flags[] = '<span class="process-flag flag-cut2"><i class="bi bi-intersect me-1"></i>Cut Into 2</span>';
    else
        $flags[] = '<span class="process-flag flag-normal"><i class="bi bi-scissors me-1"></i>Normal</span>';

    if ($sp['is_reslitted'])
        $flags[] = '<span class="process-flag flag-reslit"><i class="bi bi-arrow-repeat me-1"></i>Reslit</span>';

    if ($sp['is_recoiled'])
        $flags[] = '<span class="process-flag flag-recoiling"><i class="bi bi-arrow-counterclockwise me-1"></i>Recoiling</span>';

    // Reslit children
    $rslKey      = ($sp['lot_no'] ?? '') . '|' . ($sp['coil_no'] ?? '') . '|' . ($sp['roll_no'] ?? '');
    $rslChildren = $reslit_map[$rslKey] ?? [];

    // Recoiling entry for this roll
    $recoilEntry   = $recoiling_map[$sp['id']] ?? null;
    $recoilOutputs = $recoilEntry ? ($recoil_output_map[(int)$recoilEntry['id']] ?? []) : [];
?>
    <div class="tl-item">
        <div class="tl-dot" style="background:<?= $spDot ?>; box-shadow:0 0 0 2px <?= $spDot ?>44;"></div>

        <!-- Main roll card -->
        <div class="tl-card">
            <div class="tl-ref"><?= $ref ?></div>
            <div class="tl-meta">
                <?= statusBadge($spStatus) ?>
                <span class="chip chip-width"><?= number_format((float)($sp['width'] ?? 0)) ?> mm</span>
                <span class="chip chip-len">Nom: <?= number_format((float)($sp['length'] ?? 0), 1) ?> m</span>
                <?php if ($sp['actual_length']): ?>
                    <span class="chip chip-actual">Actual: <?= number_format((float)$sp['actual_length'], 1) ?> m</span>
                <?php endif; ?>
                <?php if ($sp['date_in']): ?>
                    <span class="chip chip-date"><?= date('d M Y', strtotime($sp['date_in'])) ?></span>
                <?php endif; ?>
                <?php if (($sp['cut_type'] ?? '') === 'cut_into_2' && ($sp['stock'] ?? 0) > 0): ?>
                    <span class="chip chip-cut">Balance: <?= number_format((float)$sp['stock'], 1) ?> m</span>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-1 flex-wrap"><?= implode('', $flags) ?></div>
        </div>

        <!-- ── Reslit children (indented) ── -->
        <?php if (!empty($rslChildren)): ?>
        <div class="child-wrap">
            <?php foreach ($rslChildren as $rc): ?>
            <div class="child-card">
                <i class="bi bi-arrow-return-right text-warning"></i>
                <span class="tl-ref">
                    <?= htmlspecialchars(($sp['lot_no'] ?? '') . ($rc['cut_letter'] ?? '')) ?>
                    <?= htmlspecialchars($sp['coil_no'] ?? '') ?>-<?= fmtRoll($rc['child_roll'] ?? '') ?>
                </span>
                <span class="chip chip-width"><?= number_format((float)($rc['new_width'] ?? 0)) ?> mm</span>
                <span class="chip chip-actual"><?= number_format((float)($rc['child_actual'] ?? 0), 1) ?> m</span>
                <span class="process-flag flag-reslit"><i class="bi bi-arrow-repeat me-1"></i>Reslit Output</span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── Recoiling chain ── -->
        <?php if ($recoilEntry): ?>
        <div class="recoil-block">
            <div class="recoil-block-title">
                <i class="bi bi-arrow-counterclockwise"></i>
                Sent to Recoiling
                &nbsp;·&nbsp;
                <span class="badge <?= $recoilEntry['status'] === 'completed' ? 'bg-success' : 'bg-warning text-dark' ?>" style="font-size:.65rem">
                    <?= strtoupper($recoilEntry['status']) ?>
                </span>
                &nbsp;·&nbsp;
                <span style="color:#555; font-weight:400; font-size:.7rem;">
                    <?= htmlspecialchars($recoilEntry['date_in'] ?? '') ?>
                </span>
            </div>

            <?php if (!empty($recoilOutputs)): ?>
                <?php foreach ($recoilOutputs as $ro):
                    $roStatus = strtoupper($ro['status'] ?? 'IN');
                    // Check if this recoil output was further reslitted
                    $roRslKey      = ($ro['lot_no'] ?? '') . '|' . ($ro['coil_no'] ?? '') . '|' . ($ro['roll_no'] ?? '');
                    $roRslChildren = $reslit_map[$roRslKey] ?? [];
                ?>
                <div class="recoil-output-row">
                    <i class="bi bi-arrow-right-circle-fill text-primary"></i>
                    <span class="tl-ref">
                        <?= htmlspecialchars($ro['lot_no'] ?? '') ?>
                        <?= htmlspecialchars($ro['coil_no'] ?? '') ?>-<?= fmtRoll($ro['roll_no'] ?? '') ?>
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
                    <span class="process-flag flag-output ms-auto"><i class="bi bi-box-arrow-in-right me-1"></i>Recoil Output</span>

                    <?php if ($ro['is_reslitted']): ?>
                        <span class="process-flag flag-reslit"><i class="bi bi-arrow-repeat me-1"></i>Reslit</span>
                    <?php endif; ?>
                    <?php if ($ro['status'] === 'DELIVERED'): ?>
                        <span class="process-flag flag-normal"><i class="bi bi-truck me-1"></i>Delivered</span>
                    <?php endif; ?>
                </div>

                <!-- Reslit children of recoil output -->
                <?php if (!empty($roRslChildren)): ?>
                <div style="margin-left:20px; margin-bottom:6px;">
                    <?php foreach ($roRslChildren as $rrc): ?>
                    <div class="child-card" style="background:#fffbf0; border-color:#fed7aa;">
                        <i class="bi bi-arrow-return-right text-warning"></i>
                        <span class="tl-ref" style="font-size:.8rem;">
                            <?= htmlspecialchars(($ro['lot_no'] ?? '') . ($rrc['cut_letter'] ?? '')) ?>
                            <?= htmlspecialchars($ro['coil_no'] ?? '') ?>-<?= fmtRoll($rrc['child_roll'] ?? '') ?>
                        </span>
                        <span class="chip chip-width"><?= number_format((float)($rrc['new_width'] ?? 0)) ?> mm</span>
                        <span class="chip chip-actual"><?= number_format((float)($rrc['child_actual'] ?? 0), 1) ?> m</span>
                        <span class="process-flag flag-reslit"><i class="bi bi-arrow-repeat me-1"></i>Reslit Output</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-muted small fst-italic ps-2">
                    <i class="bi bi-hourglass-split me-1"></i>Recoiling in progress — no output rolls yet.
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
<?php endforeach; ?>
</div>

<?php else: ?>
    <div class="empty-state"><i class="bi bi-inbox me-2"></i>No slitting outputs recorded yet.</div>
<?php endif; ?>


<!-- ─── SFC ─── -->
<?php if (count($sfc_rows) > 0): ?>
<div class="section-title">
    <i class="bi bi-box-seam" style="color:#5b21b6"></i>
    SFC (Scrap / Balance Width)
    <span class="badge ms-1" style="background:#5b21b6"><?= count($sfc_rows) ?></span>
</div>
<div class="timeline">
<?php foreach ($sfc_rows as $sf):
    $sfDot = $sf['date_out'] ? '#6b7280' : '#5b21b6';
?>
    <div class="tl-item">
        <div class="tl-dot" style="background:<?= $sfDot ?>; box-shadow:0 0 0 2px <?= $sfDot ?>44;"></div>
        <div class="tl-card">
            <div class="tl-ref">
                <?= htmlspecialchars($sf['lot_no']) ?> <?= htmlspecialchars($sf['coil_no']) ?>
                <?= $sf['roll_no'] ? '-'.fmtRoll($sf['roll_no']) : '' ?>
            </div>
            <div class="tl-meta">
                <?php if ($sf['date_out']): ?>
                    <span class="s-badge" style="background:#e5e7eb;color:#374151">USED: <?= htmlspecialchars($sf['action'] ?? '') ?></span>
                <?php else: ?>
                    <span class="s-badge s-sfc">IN SFC</span>
                <?php endif; ?>
                <span class="chip chip-width"><?= number_format((float)($sf['width'] ?? 0)) ?> mm</span>
                <span class="chip chip-len"><?= number_format((float)($sf['length'] ?? 0), 2) ?> m</span>
                <span class="chip chip-date"><?= date('d M Y', strtotime($sf['date_created'])) ?></span>
            </div>
            <span class="process-flag flag-sfc"><i class="bi bi-box-seam me-1"></i>SFC</span>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (count($slit_rows) === 0 && count($sfc_rows) === 0): ?>
    <div class="empty-state mt-4">
        <i class="bi bi-journal-x" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
        No production output has been recorded for this mother coil yet.
    </div>
<?php endif; ?>


<!-- ─── FULL SUMMARY TABLE ─── -->
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
                <th>Type</th>
                <th>Stage</th>
                <th>Width (mm)</th>
                <th>Nom (m)</th>
                <th>Actual (m)</th>
                <th>Status</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $grandTotal = 0;
        $tableIdx   = 1;

        foreach ($slit_rows as $sp):
            $process = 'Normal';
            if (($sp['cut_type'] ?? '') === 'cut_into_2') $process = 'Cut Into 2';
            if ($sp['is_reslitted'])  $process = 'Reslit';
            if ($sp['is_recoiled'])   $process = 'Recoiling';

            $pClass = match($process) {
                'Reslit'     => 'text-warning fw-bold',
                'Recoiling'  => 'text-primary fw-bold',
                'Cut Into 2' => 'text-purple fw-bold',
                default      => 'text-success',
            };
            $grandTotal += (float)($sp['actual_length'] ?? 0);
        ?>
        <tr>
            <td class="text-muted"><?= $tableIdx++ ?></td>
            <td style="font-family:monospace; font-weight:700; text-align:left;">
                <?= htmlspecialchars($sp['lot_no']) ?> <?= htmlspecialchars($sp['coil_no']) ?>-<?= fmtRoll($sp['roll_no']) ?>
            </td>
            <td><?= htmlspecialchars($sp['cut_type'] ?? 'normal') ?></td>
            <td class="<?= $pClass ?>"><?= $process ?></td>
            <td><?= number_format((float)($sp['width'] ?? 0)) ?></td>
            <td><?= number_format((float)($sp['length'] ?? 0), 1) ?></td>
            <td><?= $sp['actual_length'] ? number_format((float)$sp['actual_length'], 1) : '—' ?></td>
            <td>
                <?= match(strtoupper($sp['status'] ?? 'IN')) {
                    'IN'        => '<span class="badge bg-info text-dark">STOCK</span>',
                    'WAITING'   => '<span class="badge bg-warning text-dark">WAITING</span>',
                    'APPROVED'  => '<span class="badge bg-primary">APPROVED</span>',
                    'DELIVERED' => '<span class="badge bg-success">DELIVERED</span>',
                    'REJECTED'  => '<span class="badge bg-danger">REJECTED</span>',
                    default     => '<span class="badge bg-secondary">'.htmlspecialchars($sp['status']).'</span>',
                } ?>
            </td>
            <td class="text-muted"><?= $sp['date_in'] ? date('d/m/y', strtotime($sp['date_in'])) : '—' ?></td>
        </tr>

        <?php
        // Recoil outputs sub-rows
        $recoilEntry   = $recoiling_map[$sp['id']] ?? null;
        $recoilOutputs = $recoilEntry ? ($recoil_output_map[(int)$recoilEntry['id']] ?? []) : [];
        foreach ($recoilOutputs as $ro):
            $grandTotal += (float)($ro['actual_length'] ?? 0);
        ?>
        <tr style="background:#f0f7ff;">
            <td class="text-muted"><?= $tableIdx++ ?></td>
            <td style="font-family:monospace; font-weight:700; text-align:left; padding-left:24px;">
                ↳ <?= htmlspecialchars($ro['lot_no']) ?> <?= htmlspecialchars($ro['coil_no']) ?>-<?= fmtRoll($ro['roll_no']) ?>
            </td>
            <td><?= htmlspecialchars($ro['cut_type'] ?? 'normal') ?></td>
            <td class="text-primary fw-bold">Recoil Output</td>
            <td><?= number_format((float)($ro['width'] ?? 0)) ?></td>
            <td><?= number_format((float)($ro['length'] ?? 0), 1) ?></td>
            <td><?= $ro['actual_length'] ? number_format((float)$ro['actual_length'], 1) : '—' ?></td>
            <td>
                <?= match(strtoupper($ro['status'] ?? 'IN')) {
                    'IN'        => '<span class="badge bg-info text-dark">STOCK</span>',
                    'WAITING'   => '<span class="badge bg-warning text-dark">WAITING</span>',
                    'APPROVED'  => '<span class="badge bg-primary">APPROVED</span>',
                    'DELIVERED' => '<span class="badge bg-success">DELIVERED</span>',
                    'REJECTED'  => '<span class="badge bg-danger">REJECTED</span>',
                    default     => '<span class="badge bg-secondary">'.htmlspecialchars($ro['status']).'</span>',
                } ?>
            </td>
            <td class="text-muted"><?= $ro['date_in'] ? date('d/m/y', strtotime($ro['date_in'])) : '—' ?></td>
        </tr>
        <?php endforeach; ?>

        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="mt-4 mb-5 d-flex gap-2">
    <a href="mother_coil.php" class="btn btn-secondary">← Back</a>
    <button onclick="window.print()" class="btn btn-outline-dark">
        <i class="bi bi-printer me-1"></i> Print Journal
    </button>
</div>

<?php include 'footer.php'; ?>
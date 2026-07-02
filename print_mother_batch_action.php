<?php
// print_mother_batch_action.php
// ============================================================
// "Print All Selected Coils" — consolidated print job for the Bulk
// Print for Slitting Plan feature on mother_coil.php.
//
// Receives mother_coil_ids (JSON array of mother_coil.id, in the exact
// order the operator arranged on screen — position = print order).
//
// Marks each coil printed (printed_at = NOW()), then renders one page
// with an off-screen <iframe> per coil, each pointing at
// print_mother.php?embed=1&id=X, loaded sequentially with a per-item
// timeout so one slow/stuck coil can't stall the whole batch — same
// proven pattern used for Finish Product's batch sticker printing —
// then a single window.print() covers everything at once.
// ============================================================

session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}
if (!in_array($_SESSION['role'], ['slitting', 'mkl3'], true)) {
    die("Access denied");
}

include 'config.php';

$raw = $_POST['mother_coil_ids'] ?? '';
$ids = json_decode($raw, true);

if (!is_array($ids) || empty($ids)) {
    die("No coils selected. <a href=\"mother_coil.php\">&larr; Back to Mother Coil List</a>");
}

$ids = array_values(array_unique(array_map('intval', $ids)));
$ids = array_filter($ids, fn($id) => $id > 0);

if (empty($ids)) {
    die("No valid coils selected. <a href=\"mother_coil.php\">&larr; Back to Mother Coil List</a>");
}

// ── Fetch details, preserving the operator's arranged order ───────
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $conn->prepare("
    SELECT id, product, lot_no, coil_no, grade, width, length
    FROM mother_coil
    WHERE id IN ($placeholders)
");
$stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($rows)) {
    die("None of the selected coils could be found (they may have been deleted). "
        . '<a href="mother_coil.php">&larr; Back to Mother Coil List</a>');
}

$rowsById = [];
foreach ($rows as $r) { $rowsById[(int)$r['id']] = $r; }
$ordered = [];
foreach ($ids as $id) {
    if (isset($rowsById[$id])) { $ordered[] = $rowsById[$id]; }
}

$skippedCount = count($ids) - count($ordered);

// ── Mark printed, in the same order ────────────────────────────────
$stmtMark = $conn->prepare("UPDATE mother_coil SET printed_at = NOW() WHERE id = ?");
foreach ($ordered as $row) {
    $mid = (int)$row['id'];
    $stmtMark->bind_param("i", $mid);
    $stmtMark->execute();
}
$stmtMark->close();

$totalItems = count($ordered);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Slitting Plan Print — <?= $totalItems ?> coil(s)</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, Arial, sans-serif; margin: 0; padding: 0;
            background: #f2f2f2; color: #222;
        }
        .topbar {
            position: sticky; top: 0; z-index: 10;
            background: #fff; border-bottom: 1px solid #ddd;
            padding: 14px 16px; box-shadow: 0 2px 6px rgba(0,0,0,.06);
        }
        .topbar-title { font-size: 15px; font-weight: 700; margin: 0 0 2px; }
        .topbar-sub   { font-size: 13px; color: #666; margin: 0 0 10px; }
        .topbar-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 12px 20px; font-size: 15px; font-weight: 700;
            border: none; border-radius: 8px; cursor: pointer; text-decoration: none;
            min-height: 44px; flex: 1 1 auto;
        }
        .btn-print { background: #2e7d32; color: #fff; }
        .btn-back  { background: #616161; color: #fff; }
        .spinner {
            width: 14px; height: 14px; border: 2px solid rgba(255,255,255,.4);
            border-top-color: #fff; border-radius: 50%;
            animation: spin .7s linear infinite; display: inline-block;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .manifest { padding: 14px 16px 40px; max-width: 720px; margin: 0 auto; }
        .manifest-card {
            background: #fff; border: 1px solid #e0e0e0; border-radius: 10px;
            padding: 12px 14px; margin-bottom: 10px;
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
        }
        .manifest-main .product { font-size: 15px; font-weight: 700; }
        .manifest-main .sub     { font-size: 13px; color: #666; margin-top: 2px; }
        .manifest-seq {
            background: #e3f2fd; color: #1565c0; font-weight: 700;
            border-radius: 50%; width: 26px; height: 26px;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; flex-shrink: 0;
        }
        .manifest-badge {
            background: #e8f5e9; color: #2e7d32; font-weight: 700;
            border-radius: 8px; padding: 6px 12px; font-size: 13px; white-space: nowrap;
        }
        .manifest-badge.is-error { background: #fdecea; color: #c62828; }
        .manifest-badge.is-loading { background: #fff3e0; color: #e65100; }
        .manifest-card.has-error { border-color: #ef9a9a; background: #fff8f8; }
        .errors-box {
            max-width: 720px; margin: 14px auto 0; padding: 10px 14px;
            background: #fdecea; border: 1px solid #ef9a9a; border-radius: 8px;
            color: #c62828; font-size: 13px;
        }

        .print-frames { position: absolute; top: 0; left: -99999px; }
        .sticker-frame { width: 120mm; height: 47mm; border: none; display: block; }

        @media print {
            .topbar, .manifest, .errors-box { display: none !important; }
            .print-frames { position: static; left: auto; }
            .sticker-frame { margin: 0; page-break-after: always; width: 120mm; height: 47mm; }
            @page { size: 120mm 47mm; margin: 0; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <p class="topbar-title" id="loadStatus">
        <span class="spinner" id="loadSpinner"></span>
        Preparing <?= $totalItems ?> coil(s)…
    </p>
    <p class="topbar-sub">Marked as printed. Review below in your arranged order, then Print All.</p>
    <div class="topbar-actions">
        <button type="button" class="btn btn-print" id="printAllBtn" onclick="window.print()">🖨️ Print All</button>
        <a href="mother_coil.php" class="btn btn-back">← Back to Mother Coil List</a>
    </div>
</div>

<?php if ($skippedCount > 0): ?>
<div class="errors-box">
    <?= $skippedCount ?> coil(s) were skipped — not found (may have been deleted).
</div>
<?php endif; ?>

<div class="manifest">
    <?php foreach ($ordered as $idx => $row): ?>
    <div class="manifest-card" data-idx="<?= $idx ?>" data-id="<?= $row['id'] ?>">
        <span class="manifest-seq"><?= $idx + 1 ?></span>
        <div class="manifest-main" style="flex:1;">
            <div class="product"><?= htmlspecialchars($row['product']) ?></div>
            <div class="sub">Lot <?= htmlspecialchars($row['lot_no']) ?> · Coil <?= htmlspecialchars($row['coil_no']) ?> · Grade <?= htmlspecialchars($row['grade']) ?></div>
        </div>
        <div class="manifest-badge is-loading" data-badge="<?= $idx ?>">
            <i class="bi bi-hourglass-split"></i> Loading…
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="print-frames">
<?php foreach ($ordered as $idx => $row): ?>
    <iframe class="sticker-frame" data-idx="<?= $idx ?>"
            data-src="print_mother.php?embed=1&id=<?= urlencode($row['id']) ?>"></iframe>
<?php endforeach; ?>
</div>

<script>
const TOTAL_FRAMES = <?= $totalItems ?>;
const FRAME_TIMEOUT_MS = 8000;
let settledFrames = 0;
let failedFrames  = 0;
let autoPrinted   = false;

const frames = Array.from(document.querySelectorAll('.sticker-frame'));

function setBadge(idx, state, text) {
    const badge = document.querySelector(`.manifest-badge[data-badge="${idx}"]`);
    const card  = document.querySelector(`.manifest-card[data-idx="${idx}"]`);
    if (!badge) return;
    badge.className = 'manifest-badge' + (state === 'error' ? ' is-error' : state === 'loading' ? ' is-loading' : '');
    badge.innerHTML = text;
    if (card) card.classList.toggle('has-error', state === 'error');
}

function updateTopStatus() {
    if (settledFrames < TOTAL_FRAMES) {
        document.getElementById('loadStatus').textContent = `Loading ${settledFrames} of ${TOTAL_FRAMES} coil(s)…`;
        return;
    }
    document.getElementById('loadSpinner').style.display = 'none';
    if (failedFrames === 0) {
        document.getElementById('loadStatus').textContent = `Ready — all ${TOTAL_FRAMES} coil(s) loaded.`;
    } else {
        document.getElementById('loadStatus').textContent =
            `Ready — ${TOTAL_FRAMES - failedFrames} of ${TOTAL_FRAMES} loaded ok, ${failedFrames} failed (see highlighted row${failedFrames > 1 ? 's' : ''} below). You can still print the rest.`;
    }
    if (!autoPrinted) {
        autoPrinted = true;
        setTimeout(() => window.print(), 300);
    }
}

function loadFrame(i) {
    if (i >= frames.length) return;
    const frame = frames[i];
    const idx   = parseInt(frame.dataset.idx, 10);
    let settled = false;

    const timer = setTimeout(() => {
        if (settled) return;
        settled = true;
        failedFrames++;
        settledFrames++;
        setBadge(idx, 'error', '<i class="bi bi-exclamation-triangle"></i> Timed out');
        updateTopStatus();
        loadFrame(i + 1);
    }, FRAME_TIMEOUT_MS);

    frame.addEventListener('load', () => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        settledFrames++;
        setBadge(idx, 'ok', '<i class="bi bi-check2"></i> Ready');
        updateTopStatus();
        loadFrame(i + 1);
    });

    frame.addEventListener('error', () => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        failedFrames++;
        settledFrames++;
        setBadge(idx, 'error', '<i class="bi bi-exclamation-triangle"></i> Failed to load');
        updateTopStatus();
        loadFrame(i + 1);
    });

    frame.src = frame.dataset.src;
}

if (TOTAL_FRAMES === 0) {
    document.getElementById('loadSpinner').style.display = 'none';
    document.getElementById('loadStatus').textContent = 'No coils to print.';
} else {
    loadFrame(0);
}
</script>

</body>
</html>

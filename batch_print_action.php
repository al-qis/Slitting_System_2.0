<?php
// batch_print_action.php
// ============================================================
// Consolidated print job for batch_setup.php's "Print All Stickers".
//
// Receives { selections: [{ id, customer, ref_no, nci_resolved_customer }] }
// (one entry per roll — no per-roll copy count in this workflow; each
// roll gets exactly one sticker, matching the physical dispatch process).
//
// For each roll:
//   1. Saves customer_name / ref_no (same UPDATE used by batch_setup_save.php).
//   2. Writes a process_log audit entry (batch_print action_detail).
//
// Renders one page with an off-screen <iframe> per roll, each pointing
// at print_product.php?embed=1 (existing, unmodified pattern/colour/NCI
// resolution logic — nothing duplicated). The operator sees a clean,
// mobile-friendly manifest instead of a wall of tiny physical-size
// stickers; once every iframe loads, one window.print() call opens the
// browser's native print dialog for the whole batch at once.
// ============================================================

session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}
if ($_SESSION['role'] !== 'slitting') {
    die("Access denied");
}

include 'config.php';

// ── Helper: write one process_log row ────────────────────────────
function batch_log_process(mysqli $conn, int $entity_id, string $action_detail, string $remark): void {
    $performed_by = $_SESSION['role'] ?? 'system';
    $entity_type  = 'slitting';
    $to_status    = 'IN';
    $stmt = $conn->prepare("
        INSERT INTO process_log
            (entity_type, entity_id, mother_id, from_status, to_status,
             performed_by, action_detail, remark)
        VALUES (?, ?, NULL, NULL, ?, ?, ?, ?)
    ");
    if (!$stmt) return; // audit logging must never block the print job
    $stmt->bind_param("sissss", $entity_type, $entity_id, $to_status, $performed_by, $action_detail, $remark);
    $stmt->execute();
    $stmt->close();
}

// ── Parse & validate payload ─────────────────────────────────────
$raw = $_POST['selections'] ?? '';
$selections = json_decode($raw, true);

if (!is_array($selections) || empty($selections)) {
    die("No rolls selected for batch print.");
}

$lot_no  = trim($_POST['lot_no']  ?? '');
$coil_no = trim($_POST['coil_no'] ?? '');

$clean  = [];
$errors = [];

foreach ($selections as $sel) {
    $id       = intval($sel['id'] ?? 0);
    $customer = trim($sel['customer'] ?? '');
    $ref_no   = trim($sel['ref_no']   ?? '');
    // 0 is a valid, deliberate choice — "save the customer/ref no, but
    // skip printing a sticker for this roll in this batch". Only clamp
    // the upper bound and reject negative/garbage input.
    $copies   = max(0, min(4, intval($sel['copies'] ?? 4)));
    $nciResolved = trim($sel['nci_resolved_customer'] ?? '');

    if ($id <= 0)         { $errors[] = "Invalid roll ID."; continue; }
    if ($customer === '') { $errors[] = "Roll #{$id}: customer is required."; continue; }
    if ($ref_no === '')   { $errors[] = "Roll #{$id}: Ref No is required."; continue; }

    $customerToSave = $customer;
    if (in_array($customer, ['NCI MFG', 'NCI 2'], true) && $nciResolved !== '') {
        $customerToSave = $nciResolved;
    }

    $clean[] = [
        'id'               => $id,
        'customer'         => $customer,        // resolves pattern/colour/NCI via print_product.php
        'customer_to_save' => $customerToSave,   // what gets persisted to the DB
        'ref_no'           => $ref_no,
        'copies'           => $copies,
    ];
}

if (empty($clean)) {
    die("<h3>Batch print failed</h3><ul><li>" . implode('</li><li>', array_map('htmlspecialchars', $errors)) . "</li></ul>"
        . '<a href="finish_product.php">&larr; Back to List</a>');
}

// ── Fetch prior state (product/lot/coil/roll + print-tracking) BEFORE
//    any updates, so we know whether each roll was already printed and
//    can log/display that accurately.
$ids = array_column($clean, 'id');
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmtInfo = $conn->prepare("
    SELECT id, product, lot_no, coil_no, roll_no,
           is_printed, print_count, mother_id
    FROM slitting_product WHERE id IN ($placeholders)
");
$stmtInfo->bind_param(str_repeat('i', count($ids)), ...$ids);
$stmtInfo->execute();
$infoRows = $stmtInfo->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtInfo->close();

$infoById = [];
foreach ($infoRows as $ir) { $infoById[(int)$ir['id']] = $ir; }

// ── Save customer / ref_no for each roll, mark print-tracking, + audit log ──
$performedBy = $_SESSION['role'] ?? 'system';
$stmtUpd = $conn->prepare("UPDATE slitting_product SET customer_name = ?, ref_no = ? WHERE id = ?");
$stmtPrint = $conn->prepare("
    UPDATE slitting_product
    SET is_printed = 1,
        print_count = print_count + 1,
        first_printed_at = COALESCE(first_printed_at, NOW()),
        last_printed_at  = NOW(),
        last_printed_by  = ?
    WHERE id = ?
");

foreach ($clean as &$row) {
    $stmtUpd->bind_param("ssi", $row['customer_to_save'], $row['ref_no'], $row['id']);
    $stmtUpd->execute();

    $info = $infoById[$row['id']] ?? null;
    $row['product_label']  = $info['product'] ?? ('Roll #' . $row['id']);
    $row['lot_coil_label'] = trim(($info['lot_no'] ?? '') . ' ' . ($info['coil_no'] ?? ''));
    $row['roll_label']     = str_replace('R', 'R-', $info['roll_no'] ?? '');
    $row['was_already_printed'] = (bool)($info['is_printed'] ?? false);

    // Only rolls that actually get a sticker generated (copies > 0) count
    // as "printed" — a roll saved with 0 copies was deliberately skipped.
    if ($row['copies'] > 0) {
        $stmtPrint->bind_param("si", $performedBy, $row['id']);
        $stmtPrint->execute();

        batch_log_process(
            $conn,
            $row['id'],
            'batch_print',
            "Batch print — Lot {$lot_no} Coil {$coil_no} · Customer: {$row['customer_to_save']} · Ref: {$row['ref_no']} · Copies: {$row['copies']}"
            . ($row['was_already_printed'] ? " · REPRINT (was {$info['print_count']}x)" : " · first print")
        );
    } else {
        batch_log_process(
            $conn,
            $row['id'],
            'batch_print_skipped',
            "Batch save (no print) — Lot {$lot_no} Coil {$coil_no} · Customer: {$row['customer_to_save']} · Ref: {$row['ref_no']} · Copies: 0"
        );
    }
}
unset($row);
$stmtUpd->close();
$stmtPrint->close();

// ── Back-to-list URL, preserving tab/filter/search state ──────────
$backMonth  = intval($_POST['month'] ?? date('m'));
$backYear   = intval($_POST['year']  ?? date('Y'));
$backDay    = intval($_POST['day']   ?? 0);
$backSearch = trim($_POST['search'] ?? '');
$backFilter = trim($_POST['filter'] ?? '');
$backUrl = 'finish_product.php?' . http_build_query(array_filter([
    'month'  => $backMonth,
    'year'   => $backYear,
    'day'    => $backDay > 0 ? $backDay : null,
    'search' => $backSearch !== '' ? $backSearch : null,
    'filter' => $backFilter !== '' ? $backFilter : null,
]));
$batchSetupUrl = 'batch_setup.php?' . http_build_query([
    'lot_no' => $lot_no, 'coil_no' => $coil_no,
    'month' => $backMonth, 'year' => $backYear, 'day' => $backDay, 'search' => $backSearch, 'filter' => $backFilter,
]);
$totalCopies = array_sum(array_column($clean, 'copies'));
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Batch Print — <?= htmlspecialchars($lot_no) ?> <?= htmlspecialchars($coil_no) ?></title>
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
        .btn-print:disabled { background: #9e9e9e; }
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
        .manifest-main .cust    { font-size: 13px; color: #333; margin-top: 4px; }
        .manifest-badge {
            background: #e8f5e9; color: #2e7d32; font-weight: 700;
            border-radius: 8px; padding: 6px 12px; font-size: 13px; white-space: nowrap;
        }
        .manifest-badge.is-error { background: #fdecea; color: #c62828; }
        .manifest-badge.is-loading { background: #fff3e0; color: #e65100; }
        .manifest-badge.is-skipped { background: #f1f3f5; color: #666; }
        .manifest-card.has-error { border-color: #ef9a9a; background: #fff8f8; }
        .manifest-card.is-skipped { opacity: .65; }
        .errors-box {
            max-width: 720px; margin: 14px auto 0; padding: 10px 14px;
            background: #fdecea; border: 1px solid #ef9a9a; border-radius: 8px;
            color: #c62828; font-size: 13px;
        }

        /* Actual stickers used for printing — kept off-screen; they still
           load normally (offscreen != display:none) so the load/print
           flow works, but the operator never sees a wall of tiny
           physical-size (120mm) elements crammed onto a tablet screen. */
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
        Preparing <?= count($clean) ?> roll(s) — <?= $totalCopies ?> sticker(s) total…
    </p>
    <p class="topbar-sub">Lot <?= htmlspecialchars($lot_no) ?> · Coil <?= htmlspecialchars($coil_no) ?> — saved to database.
        <?php $skippedCount = count(array_filter($clean, fn($r) => $r['copies'] === 0)); ?>
        <?php if ($skippedCount > 0): ?>
            <?= $skippedCount ?> roll<?= $skippedCount > 1 ? 's' : '' ?> saved but skipped (0 copies).
        <?php endif; ?>
        Review below, then Print All.</p>
    <div class="topbar-actions">
        <button type="button" class="btn btn-print" id="printAllBtn" onclick="window.print()">🖨️ Print All</button>
        <a href="<?= htmlspecialchars($batchSetupUrl) ?>" class="btn btn-back">← Back to Batch Setup</a>
        <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-back">← Back to List</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="errors-box">
    <strong>Skipped rows:</strong>
    <ul style="margin:6px 0 0; padding-left:18px;">
        <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="manifest">
    <?php foreach ($clean as $idx => $row): ?>
    <div class="manifest-card <?= $row['copies'] === 0 ? 'is-skipped' : '' ?>" data-idx="<?= $idx ?>" data-id="<?= $row['id'] ?>">
        <div class="manifest-main">
            <div class="product"><?= htmlspecialchars($row['product_label']) ?></div>
            <div class="sub"><?= htmlspecialchars($row['lot_coil_label']) ?> · Roll <?= htmlspecialchars($row['roll_label']) ?></div>
            <div class="cust"><?= htmlspecialchars($row['customer_to_save']) ?> · Ref: <?= htmlspecialchars($row['ref_no']) ?>
                <?php if (!empty($row['was_already_printed'])): ?>
                    <span style="color:#c62828;font-weight:700;">· REPRINT</span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($row['copies'] === 0): ?>
        <div class="manifest-badge is-skipped">
            <i class="bi bi-slash-circle"></i> Skipped (saved, 0 copies)
        </div>
        <?php else: ?>
        <div class="manifest-badge" data-badge="<?= $idx ?>" data-total="<?= $row['copies'] ?>" data-loaded="0" data-failed="0">
            <i class="bi bi-hourglass-split"></i> 0 / <?= $row['copies'] ?> loaded…
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<div class="print-frames">
<?php
$frameCount = 0;
foreach ($clean as $idx => $row):
    $src = 'print_product.php?embed=1'
        . '&id=' . urlencode($row['id'])
        . '&customer=' . urlencode($row['customer'])
        . '&ref_no=' . urlencode($row['ref_no']);
    for ($c = 1; $c <= $row['copies']; $c++):
        $frameCount++;
?>
    <iframe class="sticker-frame" data-frame="<?= $frameCount ?>" data-idx="<?= $idx ?>" data-src="<?= htmlspecialchars($src) ?>"></iframe>
<?php
    endfor;
endforeach;
?>
</div>

<script>
const TOTAL_FRAMES   = <?= $frameCount ?>;
const FRAME_TIMEOUT_MS = 8000; // don't let one slow/stuck roll block the rest forever
let settledFrames = 0;
let failedFrames  = 0;
let autoPrinted   = false;

const frames = Array.from(document.querySelectorAll('.sticker-frame'));

function markFrameResult(idx, ok) {
    const badge = document.querySelector(`.manifest-badge[data-badge="${idx}"]`);
    const card  = document.querySelector(`.manifest-card[data-idx="${idx}"]`);
    if (!badge) return;

    const total = parseInt(badge.dataset.total, 10) || 1;
    let loaded  = parseInt(badge.dataset.loaded, 10) || 0;
    let failed  = parseInt(badge.dataset.failed, 10) || 0;

    if (ok) loaded++; else failed++;
    badge.dataset.loaded = loaded;
    badge.dataset.failed = failed;

    const settledForCard = loaded + failed;
    if (settledForCard < total) {
        badge.className = 'manifest-badge is-loading';
        badge.innerHTML = `<i class="bi bi-hourglass-split"></i> ${settledForCard} / ${total} loaded…`;
    } else if (failed === 0) {
        badge.className = 'manifest-badge';
        badge.innerHTML = `<i class="bi bi-check2"></i> ${total} sticker${total > 1 ? 's' : ''}`;
    } else {
        badge.className = 'manifest-badge is-error';
        badge.innerHTML = `<i class="bi bi-exclamation-triangle"></i> ${loaded}/${total} ok, ${failed} failed`;
    }
    if (card) card.classList.toggle('has-error', failed > 0);
}

function updateTopStatus() {
    if (settledFrames < TOTAL_FRAMES) {
        document.getElementById('loadStatus').textContent = `Loading ${settledFrames} of ${TOTAL_FRAMES} sticker(s)…`;
        return;
    }
    document.getElementById('loadSpinner').style.display = 'none';
    if (failedFrames === 0) {
        document.getElementById('loadStatus').textContent = `Ready — all ${TOTAL_FRAMES} sticker(s) loaded.`;
    } else {
        document.getElementById('loadStatus').textContent =
            `Ready — ${TOTAL_FRAMES - failedFrames} of ${TOTAL_FRAMES} sticker(s) loaded ok, ${failedFrames} failed (see highlighted row${failedFrames > 1 ? 's' : ''} below). You can still print the rest.`;
    }
    if (!autoPrinted) {
        autoPrinted = true;
        setTimeout(() => window.print(), 300);
    }
}

// Loads frames ONE AT A TIME rather than all at once — firing many
// requests simultaneously on a shop-floor tablet's Wi-Fi is exactly what
// caused a single slow/dropped request to stall the whole batch with no
// recovery. With copies now up to 4 per roll, this matters even more.
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
        markFrameResult(idx, false);
        updateTopStatus();
        loadFrame(i + 1);
    }, FRAME_TIMEOUT_MS);

    frame.addEventListener('load', () => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        settledFrames++;
        markFrameResult(idx, true);
        updateTopStatus();
        loadFrame(i + 1);
    });

    frame.addEventListener('error', () => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        failedFrames++;
        settledFrames++;
        markFrameResult(idx, false);
        updateTopStatus();
        loadFrame(i + 1);
    });

    frame.src = frame.dataset.src;
}

if (TOTAL_FRAMES === 0) {
    document.getElementById('loadSpinner').style.display = 'none';
    document.getElementById('loadStatus').textContent = 'No stickers to print.';
} else {
    loadFrame(0);
}
</script>

</body>
</html>
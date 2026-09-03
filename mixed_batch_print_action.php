<?php
// mixed_batch_print_action.php
// ============================================================
// Consolidated print job for finish_product.php's "Bulk Print Selected"
// (mixed-selection flow) — the checkbox-driven counterpart to
// batch_print_action.php, but WITHOUT the single Lot+Coil restriction.
//
// Receives (from mixed_batch_setup.php's grid — see that file):
//   selections -> JSON array of { id, customer, ref_no, nci_resolved_customer }
//                 one entry per roll, each with ITS OWN Customer/Ref No.
//
// For each roll:
//   1. Saves that roll's own customer_name / ref_no (per-row now —
//      no more one shared value overwriting every roll in the batch;
//      see mixed_batch_setup.php for where the user sets these).
//   2. Marks print-tracking (is_printed, print_count, timestamps) — one
//      copy per roll; there's no per-roll copy count in this simplified
//      flow (use Batch Setup & Print for per-roll copy control).
//   3. Writes a process_log audit entry (mixed_batch_print action_detail)
//      recording that roll's own Customer/Ref No.
//
// NCI MFG / NCI 2 are supported per row: mixed_batch_setup.php resolves
// each row's part number individually (product + width lookup is
// per-roll data, so it works fine even inside a mixed batch — it's
// only a single SHARED value across different products that doesn't
// make sense, which is what this flow no longer does).
//
// Rendering reuses the exact same off-screen iframe + sequential-load +
// auto-print pattern as batch_print_action.php (nothing duplicated in
// spirit, just adapted for a flat per-roll selection list).
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
function mixed_log_process(mysqli $conn, int $entity_id, ?int $mother_id, string $action_detail, string $remark): void {
    $performed_by = $_SESSION['role'] ?? 'system';
    $entity_type  = 'slitting';
    $to_status    = 'IN';
    $stmt = $conn->prepare("
        INSERT INTO process_log
            (entity_type, entity_id, mother_id, from_status, to_status,
             performed_by, action_detail, remark)
        VALUES (?, ?, ?, NULL, ?, ?, ?, ?)
    ");
    if (!$stmt) return; // audit logging must never block the print job
    $stmt->bind_param("siissss", $entity_type, $entity_id, $mother_id, $to_status, $performed_by, $action_detail, $remark);
    $stmt->execute();
    $stmt->close();
}

// ── Parse & validate payload ─────────────────────────────────────
$rawSelections = json_decode($_POST['selections'] ?? '', true);
if (!is_array($rawSelections) || empty($rawSelections)) {
    die("No rolls selected for bulk print.");
}

// Sanitize each selection: valid int id, non-empty customer/ref_no.
// Never trust the JSON shape alone — this mirrors the same
// intval/dedup discipline bulk_print_action.php uses for IDs.
$selectionsById = [];
foreach ($rawSelections as $sel) {
    if (!is_array($sel)) continue;
    $id = intval($sel['id'] ?? 0);
    if ($id <= 0) continue;

    $customer = trim((string)($sel['customer'] ?? ''));
    $ref_no   = trim((string)($sel['ref_no']   ?? ''));
    if ($customer === '' || $ref_no === '') continue; // validated client-side too; skip silently rather than die on one bad row

    // Copies: 1, 2, or 3 per roll — default 3 (matches the grid's
    // default) if missing or out of range, rather than trusting the
    // client's number blindly.
    $copies = intval($sel['copies'] ?? 3);
    if ($copies < 1 || $copies > 3) { $copies = 3; }
    $length = isset($sel['length']) ? (float)$sel['length'] : 0;

    // Last one wins if the client somehow sent the same id twice.
    $selectionsById[$id] = [
        'id'                    => $id,
        'customer'              => $customer,
        'ref_no'                => $ref_no,
        'length'                => $length,
        'copies'                => $copies,
        'nci_resolved_customer' => trim((string)($sel['nci_resolved_customer'] ?? '')),
    ];
}

if (empty($selectionsById)) {
    die("No valid roll selections received — every row needs a Customer and Ref No.");
}

$ids = array_keys($selectionsById);

// ── Fetch + validate every selected roll is actually printable ───
// Same rule batch_setup.php's SQL uses: not voided, not WAITING/REJECTED,
// and not an IN roll still pending Actual Length or already palletised.
// This re-validates server-side rather than trusting the setup page —
// a roll's state may have changed between page load and this submit.
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $conn->prepare("
    SELECT sp.id, sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
           sp.status, sp.is_completed, sp.is_printed, sp.print_count, sp.mother_id,
           pi.pallet_id
    FROM slitting_product sp
    LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
    WHERE sp.id IN ($placeholders)
      AND (sp.is_voided = 0 OR sp.is_voided IS NULL)
");
$stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$clean  = [];
$errors = [];
$foundIds = [];

$from = trim($_POST['from'] ?? $_GET['from'] ?? '');

foreach ($rows as $r) {
    $rid = (int)$r['id'];
    $foundIds[] = $rid;
    $sel = $selectionsById[$rid];

    if ($from === 'slitting_product') {
        $isPrintable = true;
    } else {
        $isPrintable = !in_array($r['status'], ['WAITING', 'REJECTED'], true)
            && !($r['status'] === 'IN' && ($r['is_completed'] == 0 || $r['pallet_id']));
    }

    if (!$isPrintable) {
        $reason = $r['status'] === 'IN'
            ? ($r['pallet_id'] ? 'already palletised' : 'pending Actual Length')
            : ('status ' . $r['status']);
        $errors[] = "Roll #{$rid} ({$r['product']} {$r['lot_no']} {$r['coil_no']}): skipped — {$reason}.";
        continue;
    }

    $clean[] = [
        'id'               => $rid,
        'product_label'    => $r['product'] ?? ('Roll #' . $rid),
        'lot_coil_label'   => trim(($r['lot_no'] ?? '') . ' ' . ($r['coil_no'] ?? '')),
        'roll_label'       => str_replace('R', 'R-', $r['roll_no'] ?? ''),
        'mother_id'        => $r['mother_id'] ? (int)$r['mother_id'] : null,
        'was_already_printed' => (bool)$r['is_printed'],
        'prior_print_count'   => (int)$r['print_count'],
        'customer'         => $sel['customer'],
        'ref_no'           => $sel['ref_no'],
        'length'           => $sel['length'],
        'copies'           => $sel['copies'],
        'nci_resolved_customer' => $sel['nci_resolved_customer'],
    ];
}

$missingIds = array_diff($ids, $foundIds);
foreach ($missingIds as $mid) {
    $errors[] = "Roll #{$mid}: not found (or voided).";
}

if (empty($clean)) {
    die("<h3>Bulk print failed — nothing printable</h3><ul><li>" . implode('</li><li>', array_map('htmlspecialchars', $errors)) . "</li></ul>"
        . '<a href="finish_product.php">&larr; Back to List</a>');
}

// ── Save each roll's OWN customer/ref_no, mark print-tracking, and
//    audit log — per row now, instead of one global pair for everyone ──
$performedBy = $_SESSION['role'] ?? 'system';
$stmtUpdLength = $conn->prepare("UPDATE slitting_product SET customer_name = ?, ref_no = ?, actual_length = ? WHERE id = ?");
$stmtUpdNormal = $conn->prepare("UPDATE slitting_product SET customer_name = ?, ref_no = ? WHERE id = ?");
$stmtPrint = $conn->prepare("
    UPDATE slitting_product
    SET is_printed = 1,
        print_count = print_count + ?,
        first_printed_at = COALESCE(first_printed_at, NOW()),
        last_printed_at  = NOW(),
        last_printed_by  = ?
    WHERE id = ?
");

foreach ($clean as $row) {
    if (isset($row['length']) && $row['length'] > 0) {
        $stmtUpdLength->bind_param("ssdi", $row['customer'], $row['ref_no'], $row['length'], $row['id']);
        $stmtUpdLength->execute();
    } else {
        $stmtUpdNormal->bind_param("ssi", $row['customer'], $row['ref_no'], $row['id']);
        $stmtUpdNormal->execute();
    }

    $stmtPrint->bind_param("isi", $row['copies'], $performedBy, $row['id']);
    $stmtPrint->execute();

    mixed_log_process(
        $conn,
        $row['id'],
        $row['mother_id'],
        'mixed_batch_print',
        "Mixed bulk print — Customer: {$row['customer']} · Ref: {$row['ref_no']} · {$row['copies']}x copies"
        . ($row['length'] > 0 ? " · Length: {$row['length']}m" : "")
        . ($row['was_already_printed'] ? " · REPRINT (was {$row['prior_print_count']}x)" : " · first print")
    );
}
if ($stmtUpdLength) $stmtUpdLength->close();
if ($stmtUpdNormal) $stmtUpdNormal->close();
$stmtPrint->close();

// ── Back-to-list URL, preserving tab/filter/search state ──────────
$from = trim($_POST['from'] ?? $_GET['from'] ?? '');
if ($from === 'slitting_product') {
    $backSearch      = trim($_POST['search'] ?? '');
    $backPrintFilter = trim($_POST['print_status'] ?? '');
    $backUrl = 'slitting_product.php?' . http_build_query(array_filter([
        'search'       => $backSearch !== '' ? $backSearch : null,
        'print_status' => $backPrintFilter !== '' ? $backPrintFilter : null,
    ]));
} else {
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
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Mixed Bulk Print — <?= count($clean) ?> roll(s)</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background: #f5f6f8; }
        .topbar {
            background: #212529; color: #fff; padding: 14px 16px; text-align: center;
            position: sticky; top: 0; z-index: 10;
        }
        .topbar-title { margin: 0 0 4px; font-size: 15px; font-weight: 700; }
        .topbar-sub { margin: 0 0 10px; font-size: 12.5px; color: #ccc; }
        .topbar-actions { display: flex; gap: 8px; justify-content: center; }
        .btn { border: none; border-radius: 6px; padding: 9px 16px; font-size: 13.5px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-print { background: #198754; color: #fff; }
        .btn-back  { background: #e9ecef; color: #333; }
        .spinner {
            display: inline-block; width: 13px; height: 13px; border: 2px solid #ccc;
            border-top-color: #198754; border-radius: 50%; animation: spin 0.8s linear infinite;
            margin-right: 6px; vertical-align: -2px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .manifest { max-width: 720px; margin: 14px auto 0; padding: 0 14px; }
        .manifest-card {
            display: flex; justify-content: space-between; align-items: center;
            background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
            padding: 10px 14px; margin-bottom: 8px; font-size: 13px;
        }
        .manifest-card.has-error { border-color: #ef9a9a; background: #fff8f8; }
        .manifest-main .product { font-weight: 700; }
        .manifest-main .sub { color: #666; font-size: 12px; }
        .manifest-main .cust { color: #444; font-size: 12px; margin-top: 2px; }
        .manifest-badge {
            font-size: 12px; font-weight: 600; color: #856404; background: #fff3cd;
            padding: 4px 10px; border-radius: 20px; white-space: nowrap;
        }
        .manifest-badge.is-loading { background: #fff3cd; color: #856404; }
        .manifest-badge.is-error   { background: #fdecea; color: #c62828; }

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
        Preparing <?= array_sum(array_column($clean, 'copies')) ?> sticker(s) for <?= count($clean) ?> roll(s)…
    </p>
    <p class="topbar-sub">Mixed selection — each roll's own Customer &amp; Ref No (set in Mixed Batch Setup)
        has been saved to the database. Review, then Print All.</p>
    <div class="topbar-actions">
        <button type="button" class="btn btn-print" id="printAllBtn" onclick="window.print()">🖨️ Print All</button>
        <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-back">← Back to List</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="errors-box">
    <strong>Skipped roll(s):</strong>
    <ul style="margin:6px 0 0; padding-left:18px;">
        <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="manifest">
    <?php foreach ($clean as $idx => $row): ?>
    <div class="manifest-card" data-idx="<?= $idx ?>" data-id="<?= $row['id'] ?>">
        <div class="manifest-main">
            <div class="product"><?= htmlspecialchars($row['product_label']) ?></div>
            <div class="sub"><?= htmlspecialchars($row['lot_coil_label']) ?> · Roll <?= htmlspecialchars($row['roll_label']) ?></div>
            <div class="cust"><?= htmlspecialchars($row['customer']) ?> · Ref: <?= htmlspecialchars($row['ref_no']) ?>
                · <?= (int)$row['copies'] ?>x <?= $row['copies'] == 1 ? 'copy' : 'copies' ?>
                <?php if (!empty($row['nci_resolved_customer'])): ?>
                    <span style="color:#666;">(<?= htmlspecialchars($row['nci_resolved_customer']) ?>)</span>
                <?php endif; ?>
                <?php if (!empty($row['was_already_printed'])): ?>
                    <span style="color:#c62828;font-weight:700;">· REPRINT</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="manifest-badge" data-badge="<?= $idx ?>" data-total="<?= (int)$row['copies'] ?>" data-loaded="0" data-failed="0">
            <i class="bi bi-hourglass-split"></i> loading…
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="print-frames">
<?php
// One iframe PER COPY — a roll with 3 copies gets 3 sticker frames, all
// tagged with the same data-row so the manifest badge above can track
// "how many of this roll's copies have loaded" rather than assuming a
// strict 1 frame == 1 roll relationship (that assumption no longer
// holds now that copies can be > 1).
$frameIdx = 0;
foreach ($clean as $rowIdx => $row):
    $src = 'print_product.php?embed=1'
        . '&id=' . urlencode($row['id'])
        . '&customer=' . urlencode($row['customer'])
        . '&ref_no=' . urlencode($row['ref_no']);
    for ($c = 0; $c < $row['copies']; $c++):
?>
    <iframe class="sticker-frame" data-frame="<?= $frameIdx ?>" data-row="<?= $rowIdx ?>" data-src="<?= htmlspecialchars($src) ?>"></iframe>
<?php
        $frameIdx++;
    endfor;
endforeach;
?>
</div>

<script>
const TOTAL_FRAMES     = <?= array_sum(array_column($clean, 'copies')) ?>;
const FRAME_TIMEOUT_MS = 8000; // don't let one slow/stuck roll block the rest forever
let settledFrames = 0;
let failedFrames  = 0;
let autoPrinted   = false;

const frames = Array.from(document.querySelectorAll('.sticker-frame'));

// Each row's badge tracks its OWN copies (data-total) — read once up
// front so multiple frames belonging to the same roll accumulate into
// one "x of y copies ready" status instead of each frame stomping on
// the last one's result.
const rowStats = {};
document.querySelectorAll('.manifest-badge').forEach(badge => {
    rowStats[badge.dataset.badge] = {
        total:   parseInt(badge.dataset.total, 10) || 1,
        settled: 0,
        failed:  0,
    };
});

function markFrameResult(rowIdx, ok) {
    const badge = document.querySelector(`.manifest-badge[data-badge="${rowIdx}"]`);
    const card  = document.querySelector(`.manifest-card[data-idx="${rowIdx}"]`);
    const stats = rowStats[rowIdx];
    if (!badge || !stats) return;

    stats.settled++;
    if (!ok) stats.failed++;

    const allSettled = stats.settled >= stats.total;
    if (!allSettled) {
        badge.className = 'manifest-badge is-loading';
        badge.innerHTML = `<i class="bi bi-hourglass-split"></i> ${stats.settled}/${stats.total} loaded…`;
        return;
    }

    if (stats.failed === 0) {
        badge.className = 'manifest-badge';
        badge.innerHTML = `<i class="bi bi-check2"></i> ready (${stats.total}x)`;
    } else {
        badge.className = 'manifest-badge is-error';
        badge.innerHTML = `<i class="bi bi-exclamation-triangle"></i> ${stats.total - stats.failed}/${stats.total} ready`;
    }
    if (card) card.classList.toggle('has-error', stats.failed > 0);
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
            `Ready — ${TOTAL_FRAMES - failedFrames} of ${TOTAL_FRAMES} sticker(s) loaded ok, ${failedFrames} failed (see highlighted row${failedFrames > 1 ? 's' : ''} above). You can still print the rest.`;
    }
    if (!autoPrinted) {
        autoPrinted = true;
        setTimeout(() => window.print(), 300);
    }
}

// Loads frames ONE AT A TIME rather than all at once — firing many
// requests simultaneously on a shop-floor tablet's Wi-Fi is exactly what
// caused a single slow/dropped request to stall the whole batch with no
// recovery (same reasoning as batch_print_action.php).
function loadFrame(i) {
    if (i >= frames.length) return;
    const frame = frames[i];
    const rowIdx = frame.dataset.row;
    let settled = false;

    const timer = setTimeout(() => {
        if (settled) return;
        settled = true;
        failedFrames++;
        settledFrames++;
        markFrameResult(rowIdx, false);
        updateTopStatus();
        loadFrame(i + 1);
    }, FRAME_TIMEOUT_MS);

    frame.addEventListener('load', () => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        settledFrames++;
        markFrameResult(rowIdx, true);
        updateTopStatus();
        loadFrame(i + 1);
    });

    frame.addEventListener('error', () => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        failedFrames++;
        settledFrames++;
        markFrameResult(rowIdx, false);
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
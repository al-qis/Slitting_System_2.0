<?php
// bulk_print_sfc.php
// ============================================================
// Consolidated bulk print console for SFC material labels.
//
// Receives:
//   sfc_ids[] (via POST from sfc.php checkbox selection) or
//   ids (via GET/POST comma-separated or JSON array for bookmarks/reprints).
//
// Renders one page with an off-screen <iframe> per SFC item pointing at
// print_sfc.php?embed=1&id=X, loaded sequentially with a per-item timeout
// to prevent mobile/tablet stalls. Once all iframes load, window.print()
// is automatically invoked to trigger the label printer for all selected
// SFC labels at once.
// ============================================================

session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if (!in_array($_SESSION['role'], ['slitting', 'mkl3'], true)) {
    die("Access denied for this role.");
}

include 'config.php';

// ── Read selected IDs ──────────────────────────────────────────
$ids = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['sfc_ids'])) {
        $ids = array_map('intval', (array)$_POST['sfc_ids']);
    } elseif (isset($_POST['ids'])) {
        $raw = $_POST['ids'];
        if (is_array($raw)) {
            $ids = array_map('intval', $raw);
        } else {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $ids = array_map('intval', $decoded);
            } else {
                $ids = array_map('intval', explode(',', $raw));
            }
        }
    }
} elseif (isset($_GET['ids'])) {
    $ids = array_map('intval', explode(',', $_GET['ids']));
} elseif (isset($_GET['sfc_ids'])) {
    $ids = array_map('intval', (array)$_GET['sfc_ids']);
}
$ids = array_values(array_unique(array_filter($ids, fn($id) => $id > 0)));

if (empty($ids)) {
    die("<h3>No SFC items selected for bulk print.</h3><p><a href=\"sfc.php\">&larr; Back to SFC Material List</a></p>");
}

// ── Validate against DB & preserve user selection order ───────
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $conn->prepare("
    SELECT sfc_id, product, lot_no, coil_no, roll_no, width, length
    FROM sfc
    WHERE sfc_id IN ($placeholders)
");
$stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($rows)) {
    die("<h3>No matching SFC items found for the selected IDs.</h3><p><a href=\"sfc.php\">&larr; Back to SFC Material List</a></p>");
}

$rowsById = [];
foreach ($rows as $r) {
    $rowsById[(int)$r['sfc_id']] = $r;
}
$items = [];
foreach ($ids as $id) {
    if (isset($rowsById[$id])) {
        $items[] = $rowsById[$id];
    }
}
$totalItems   = count($items);
$skippedCount = count($ids) - $totalItems;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Bulk Print SFC Labels — <?= $totalItems ?> label(s)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0; padding: 0;
            background: #f4f6f8; color: #212529;
        }

        .topbar {
            position: sticky; top: 0; z-index: 100;
            background: #ffffff; border-bottom: 1px solid #dee2e6;
            padding: 16px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .topbar-title {
            font-size: 16px; font-weight: 700; margin: 0 0 4px;
            display: flex; align-items: center; gap: 8px; color: #198754;
        }
        .topbar-sub { font-size: 13px; color: #6c757d; margin: 0 0 12px; }
        .topbar-actions { display: flex; gap: 10px; flex-wrap: wrap; }

        .btn-custom {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 10px 18px; font-size: 14px; font-weight: 600;
            border: none; border-radius: 6px; cursor: pointer; text-decoration: none;
            transition: all 0.15s ease-in-out;
        }
        .btn-print { background: #198754; color: #ffffff; }
        .btn-print:hover { background: #146c43; color: #ffffff; }
        .btn-back { background: #495057; color: #ffffff; }
        .btn-back:hover { background: #343a40; color: #ffffff; }
        .btn-close-win { background: #e9ecef; color: #495057; }
        .btn-close-win:hover { background: #dee2e6; color: #212529; }

        .spinner {
            width: 16px; height: 16px; border: 2px solid rgba(25, 135, 84, 0.25);
            border-top-color: #198754; border-radius: 50%;
            animation: spin 0.7s linear infinite; display: inline-block;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .manifest { padding: 18px 20px 40px; max-width: 760px; margin: 0 auto; }
        .manifest-card {
            background: #ffffff; border: 1px solid #e9ecef; border-radius: 8px;
            padding: 12px 16px; margin-bottom: 10px;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            transition: border-color 0.2s;
        }
        .manifest-card.has-error { border-color: #f8d7da; background: #fff8f8; }
        .manifest-seq {
            background: #e7f1ff; color: #0d6efd; font-weight: 700;
            border-radius: 50%; width: 28px; height: 28px;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; flex-shrink: 0;
        }
        .manifest-main { flex: 1; min-width: 0; }
        .manifest-main .prod-title { font-size: 14px; font-weight: 700; }
        .manifest-main .sub-info { font-size: 13px; color: #6c757d; margin-top: 2px; font-family: monospace; }
        .manifest-badge {
            background: #e8f5e9; color: #198754; font-weight: 600;
            border-radius: 6px; padding: 5px 12px; font-size: 12px; white-space: nowrap;
        }
        .manifest-badge.is-error { background: #f8d7da; color: #dc3545; }
        .manifest-badge.is-loading { background: #fff3cd; color: #997404; }

        .errors-box {
            max-width: 760px; margin: 14px auto 0; padding: 10px 14px;
            background: #f8d7da; border: 1px solid #f5c2c7; border-radius: 6px;
            color: #842029; font-size: 13px;
        }

        /* Actual sticker frames — off-screen during display */
        .print-frames { position: absolute; top: 0; left: -99999px; }
        .sticker-frame { width: 120mm; height: 47mm; border: none; display: block; }

        @media print {
            .topbar, .manifest, .errors-box, .no-print { display: none !important; }
            body { padding: 0 !important; margin: 0 !important; background: none !important; }
            .print-frames { position: static !important; left: auto !important; }
            .sticker-frame {
                margin: 0 !important;
                page-break-after: always !important;
                break-after: page !important;
                width: 120mm !important;
                height: 47mm !important;
                border: none !important;
                display: block !important;
            }
            @page { size: 120mm 47mm; margin: 0; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <p class="topbar-title">
        <span class="spinner" id="loadSpinner"></span>
        <span id="loadStatus">Preparing <?= $totalItems ?> SFC label(s)…</span>
    </p>
    <p class="topbar-sub">All stickers are loaded sequentially to prevent browser timeouts. Once ready, the print dialog opens automatically.</p>
    <div class="topbar-actions">
        <button type="button" class="btn-custom btn-print" id="printAllBtn" onclick="window.print()">
            <i class="bi bi-printer-fill"></i> Print All Labels
        </button>
        <a href="sfc.php" class="btn-custom btn-back">
            <i class="bi bi-arrow-left"></i> Back to SFC List
        </a>
        <button type="button" class="btn-custom btn-close-win" onclick="window.close()">
            <i class="bi bi-x-circle"></i> Close Window
        </button>
    </div>
</div>

<?php if ($skippedCount > 0): ?>
<div class="errors-box">
    <strong>Note:</strong> <?= $skippedCount ?> SFC item(s) could not be found (they may have been deleted).
</div>
<?php endif; ?>

<div class="manifest">
    <?php foreach ($items as $idx => $row): ?>
        <?php
            $isBalance = (strtoupper(trim($row['roll_no'] ?? '')) === 'BALANCE');
            $rollDisplay = $isBalance ? 'BALANCE' : ($row['roll_no'] ? str_replace('R', 'R-', $row['roll_no']) : '-');
        ?>
        <div class="manifest-card" data-idx="<?= $idx ?>" data-id="<?= (int)$row['sfc_id'] ?>">
            <span class="manifest-seq"><?= $idx + 1 ?></span>
            <div class="manifest-main">
                <div class="prod-title">
                    <span class="badge bg-secondary me-1"><?= htmlspecialchars($row['product']) ?></span>
                    SFC #<?= (int)$row['sfc_id'] ?>
                </div>
                <div class="sub-info">
                    <?= htmlspecialchars($row['lot_no']) ?> <?= htmlspecialchars($row['coil_no']) ?>
                    <?php if ($isBalance): ?>
                        · <span class="badge bg-warning text-dark">BALANCE</span>
                    <?php elseif ($rollDisplay !== '-'): ?>
                        · Roll: <?= htmlspecialchars($rollDisplay) ?>
                    <?php endif; ?>
                    · <?= number_format($row['width'], 1) ?>mm × <?= number_format($row['length'], 2) ?>m
                </div>
            </div>
            <div class="manifest-badge is-loading" data-badge="<?= $idx ?>">
                <i class="bi bi-hourglass-split"></i> Loading…
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="print-frames">
    <?php foreach ($items as $idx => $row): ?>
        <iframe class="sticker-frame" data-idx="<?= $idx ?>"
                data-src="print_sfc.php?embed=1&id=<?= (int)$row['sfc_id'] ?>"></iframe>
    <?php endforeach; ?>
</div>

<script>
const TOTAL_FRAMES     = <?= $totalItems ?>;
const FRAME_TIMEOUT_MS = 8000;
let settledFrames      = 0;
let failedFrames       = 0;
let autoPrinted        = false;

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
    const statusEl = document.getElementById('loadStatus');
    const spinner  = document.getElementById('loadSpinner');

    if (settledFrames < TOTAL_FRAMES) {
        if (statusEl) statusEl.textContent = `Loading ${settledFrames} of ${TOTAL_FRAMES} label(s)…`;
        return;
    }

    if (spinner) spinner.style.display = 'none';

    if (failedFrames === 0) {
        if (statusEl) statusEl.textContent = `Ready — all ${TOTAL_FRAMES} label(s) loaded.`;
    } else {
        if (statusEl) {
            statusEl.textContent = `Ready — ${TOTAL_FRAMES - failedFrames} of ${TOTAL_FRAMES} loaded ok, ${failedFrames} failed. You can still print the loaded labels.`;
        }
    }

    if (!autoPrinted && failedFrames < TOTAL_FRAMES) {
        autoPrinted = true;
        setTimeout(() => window.print(), 350);
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
    const spinner = document.getElementById('loadSpinner');
    if (spinner) spinner.style.display = 'none';
    const statusEl = document.getElementById('loadStatus');
    if (statusEl) statusEl.textContent = 'No labels to print.';
} else {
    loadFrame(0);
}
</script>

</body>
</html>

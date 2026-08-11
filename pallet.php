<?php
// =============================================================
// pallet.php  —  v7 (Flexible Manual Entry Format)
// NEW: The manual / scanned input now accepts TWO formats:
//   A) KEY=value pairs (camera QR / hardware scanner):
//        LOT=826277;COIL=FK-1;ROLL=R1
//   B) Plain space-separated values (typed by hand):
//        826277 FK-1 R1
//   parseQR() auto-detects which one it received.
//
// Retained from v6: triple duplicate-scan guard (isAdding lock,
//   same-pallet drop, slot-present drop); scanner cache-busted
//   (?v=7).
// Retained from v5: no first-roll reload; constraint badges live.
// Retained from v4: client guard only blocks rolls on a DIFFERENT
//   pallet.
// Retained from v3: Est. Weight display (per-roll + running total).
//   wgt = (actual_length_m × width_mm / 1000) × std_weight
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

// ── AJAX: product lookup (now includes std_weight) ────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'lookup_product') {
    header('Content-Type: application/json');
    $lot  = trim($_GET['lot']  ?? '');
    $coil = trim($_GET['coil'] ?? '');
    $roll = trim($_GET['roll'] ?? '');
    if (!$lot || !$coil || !$roll) { echo json_encode(['ok' => false, 'msg' => 'Please specify full Lot No, Coil No, and Roll No.']); exit; }
    $stmt = $conn->prepare("
        SELECT sp.id, sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
               sp.width, sp.actual_length, sp.length, sp.nod_length,
               sp.stock_counted, sp.status, sp.is_voided,
               sp.customer_name, sp.ref_no,
               pi.pallet_id, p.pallet_no,
               COALESCE(sw.std_weight, 0) AS std_weight
        FROM slitting_product sp
        LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
        LEFT JOIN pallets p       ON p.id = pi.pallet_id
        LEFT JOIN std_wgt sw      ON sw.product_code = sp.product
        WHERE sp.lot_no = ? AND sp.coil_no = ? AND sp.roll_no = ?
          AND (sp.is_voided = 0 OR sp.is_voided IS NULL)
        LIMIT 1
    ");
    $stmt->bind_param("sss", $lot, $coil, $roll);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { echo json_encode(['ok' => false, 'msg' => "Roll not found: {$lot} {$coil} {$roll}"]); exit; }
    $lenForCode = (!empty($row['actual_length']) && $row['actual_length'] > 0) ? $row['actual_length'] : $row['length'];
    $row['stock_code'] = PalletManager::formatStockCode($row['coil_no'], $row['width'], $lenForCode);
    echo json_encode(['ok' => true, 'product' => $row]);
    exit;
}

// ── AJAX: unified pallet sidebar feed ──────────────────────────
// Powers the redesigned right-panel list: one call returns pallets
// pre-filtered (status tab + search) and pre-sorted, ready to render.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'list_pallets') {
    header('Content-Type: application/json');
    $group  = trim($_GET['group']  ?? 'all');
    $search = trim($_GET['q']      ?? '');
    $sort   = trim($_GET['sort']   ?? 'id');
    echo json_encode($pm->listPallets($group, $search, $sort));
    exit;
}

// ── AJAX: rename pallet (inline header edit) ────────────────────
// POST because it's a mutation, unlike the read-only GET ajax blocks
// above. Returns the same shape as the other PalletManager calls so
// the frontend can reuse one error-handling pattern.
if (isset($_POST['ajax']) && $_POST['ajax'] === 'rename_pallet') {
    header('Content-Type: application/json');
    $palletId = (int)($_POST['pallet_id'] ?? 0);
    $newNo    = trim($_POST['pallet_no'] ?? '');
    if ($palletId <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Missing or invalid pallet_id.']);
        exit;
    }
    echo json_encode($pm->renamePallet($palletId, $newNo));
    exit;
}

// ── AJAX: update Customer & Ref No (inline header edit) ─────────
// Propagates the new values down to every slitting_product row on
// this pallet, so pallet and roll data never drift apart.
if (isset($_POST['ajax']) && $_POST['ajax'] === 'update_customer_ref') {
    header('Content-Type: application/json');
    $palletId    = (int)($_POST['pallet_id'] ?? 0);
    $newCustomer = trim($_POST['customer_name'] ?? '');
    $newRefNo    = trim($_POST['ref_no'] ?? '');
    if ($palletId <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Missing or invalid pallet_id.']);
        exit;
    }
    echo json_encode($pm->updatePalletCustomerRef($palletId, $newCustomer, $newRefNo));
    exit;
}

// ═══════════════════════════════════════════════════════════════
// AJAX: DELIVER BY SCAN  (Method 2 — global scan-to-deliver)
//
// The operator scans ANY roll's QR from anywhere on pallet.php —
// no need to open/select a specific pallet first. We resolve the
// scanned roll → its pallet → bundleDeliver() in a single call.
//
// POST (mutation): { ajax: 'deliver_by_scan', raw: '<scanned string>' }
//
// Accepts the same two formats as everywhere else in the system:
//   A) KEY=value pairs (camera QR / hardware scanner):
//        LOT=826277;COIL=FK-1;ROLL=R1
//   B) Plain space-separated values (typed by hand):
//        826277 FK-1 R1
//
// Response codes (mirror PalletManager::bundleDeliver()):
//   DELIVERED         → pallet approved, now delivered (this call did it)
//   ALREADY_DELIVERED → pallet was already delivered (not an error)
//   WRONG_STATE       → pallet is building / pending_qc / rejected
//   NO_PALLET         → scanned roll isn't assigned to any pallet
//   NOT_FOUND         → scanned roll doesn't exist / bad scan
//   VOIDED            → scanned roll was voided
//   PARSE_ERROR       → couldn't read lot/coil from the scanned text
// ═══════════════════════════════════════════════════════════════
if (isset($_POST['ajax']) && $_POST['ajax'] === 'deliver_by_scan') {
    header('Content-Type: application/json');

    $raw = trim($_POST['raw'] ?? '');
    $activePalletId = (int)($_POST['active_pallet_id'] ?? 0);

    // Strip control characters a hardware scanner gun may inject
    $raw = trim(preg_replace('/[[:cntrl:]]/', '', $raw));

    if ($raw === '') {
        echo json_encode(['ok' => false, 'code' => 'PARSE_ERROR', 'msg' => 'Nothing scanned.']);
        exit;
    }

    $targetPalletId = 0;
    $targetPalletNo = '';
    $targetStatus   = '';

    // ── Check if scanned value is a Pallet Serial No directly ──────
    $formattedPalletNo = PalletManager::formatPalletNo($raw);
    $stmtPallet = $conn->prepare("
        SELECT id, pallet_no, status, delivered_at
        FROM pallets
        WHERE pallet_no = ? OR pallet_no = ?
        LIMIT 1
    ");
    $stmtPallet->bind_param("ss", $raw, $formattedPalletNo);
    $stmtPallet->execute();
    $palletRow = $stmtPallet->get_result()->fetch_assoc();
    $stmtPallet->close();

    if ($palletRow) {
        $targetPalletId = (int)$palletRow['id'];
        $targetPalletNo = $palletRow['pallet_no'];
        $targetStatus   = $palletRow['status'];
    } else {
        $lot = $coil = $roll = '';

        if (strpos($raw, '=') !== false) {
            // Format A — KEY=value;KEY=value
            foreach (explode(';', $raw) as $segment) {
                $segment = trim($segment);
                if (strpos($segment, '=') === false) continue;
                [$k, $v] = explode('=', $segment, 2);
                $k = strtoupper(trim($k));
                $v = trim($v);
                if ($k === 'LOT')  $lot  = $v;
                if ($k === 'COIL') $coil = $v;
                if ($k === 'ROLL') $roll = $v;
            }
        } else {
            // Format B — space-separated "826277 FK-1 R1"
            $tokens = preg_split('/\s+/', $raw, 3);
            $lot    = trim($tokens[0] ?? '');
            $coil   = trim($tokens[1] ?? '');
            $roll   = trim($tokens[2] ?? '');
        }

        // ── REQUIREMENT 1: Restrict Search to Full Identifiers ──────
        // Searching by Lot No alone is disabled. Must specify Lot, Coil, and Roll!
        if ($lot === '' || $coil === '' || $roll === '') {
            echo json_encode([
                'ok'   => false,
                'code' => 'INCOMPLETE_IDENTIFIER',
                'msg'  => 'Please specify full Lot No, Coil No, and Roll No (e.g. 826277 FK-1 R1) or a Pallet Serial No (e.g. SFS-2607-001).'
            ]);
            exit;
        }

        $cleanRoll = ltrim(strtoupper($roll), 'R-');
        $cleanRoll = 'R' . ltrim($cleanRoll, 'R');

        $stmt = $conn->prepare("
            SELECT sp.id, sp.is_voided,
                   pi.pallet_id,
                   p.status AS pallet_status, p.pallet_no
            FROM slitting_product sp
            LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
            LEFT JOIN pallets p       ON p.id = pi.pallet_id
            WHERE sp.lot_no = ? AND sp.coil_no = ? AND (sp.roll_no = ? OR sp.roll_no = ?)
            ORDER BY sp.id DESC
            LIMIT 1
        ");
        $stmt->bind_param("ssss", $lot, $coil, $roll, $cleanRoll);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            echo json_encode([
                'ok'   => false,
                'code' => 'NOT_FOUND',
                'msg'  => "No product found matching: {$lot} {$coil} {$roll}"
            ]);
            exit;
        }

        if ((int)$row['is_voided'] === 1) {
            echo json_encode([
                'ok'   => false,
                'code' => 'VOIDED',
                'msg'  => "Product {$lot} {$coil} {$roll} has been voided."
            ]);
            exit;
        }

        if (!$row['pallet_id']) {
            echo json_encode([
                'ok'   => false,
                'code' => 'NO_PALLET',
                'msg'  => "Product {$lot} {$coil} {$roll} is not assigned to any pallet."
            ]);
            exit;
        }

        $targetPalletId = (int)$row['pallet_id'];
        $targetPalletNo = $row['pallet_no'];
        $targetStatus   = $row['pallet_status'];
    }

    // ── REQUIREMENT 2: Approved Pallet Transition & Auto-Delivery ──
    // If the previous open pallet in user's session was APPROVED,
    // scanning a product for a new pallet automatically bundle-delivers the previous approved pallet!
    $prevDelivered = false;
    $prevPalletNo  = '';

    if ($activePalletId > 0 && $activePalletId !== $targetPalletId) {
        $prevPallet = $pm->getPallet($activePalletId);
        if ($prevPallet && $prevPallet['status'] === 'approved') {
            $deliverRes = $pm->bundleDeliver($activePalletId);
            if ($deliverRes['ok']) {
                $prevDelivered = true;
                $prevPalletNo  = $prevPallet['pallet_no'];
            }
        }
    }

    echo json_encode([
        'ok'             => true,
        'pallet_id'      => $targetPalletId,
        'pallet_no'      => $targetPalletNo,
        'status'         => $targetStatus,
        'prev_delivered' => $prevDelivered,
        'prev_pallet_no' => $prevPalletNo,
    ]);
    exit;
}

// ── Shared: build the flattened Summary Pallet dataset ─────────
function buildSummaryPalletRows(mysqli $conn): array {
    $rows = $conn->query("
        SELECT p.id AS pallet_id, p.pallet_no, p.status, pi.stock_code AS pi_stock_code,
               sp.roll_no, sp.lot_no, sp.coil_no, sp.product,
               sp.customer_name, sp.ref_no, sp.width, sp.length, sp.actual_length
        FROM pallets p
        LEFT JOIN pallet_items pi     ON pi.pallet_id = p.id
        LEFT JOIN slitting_product sp ON sp.id = pi.slitting_product_id
        ORDER BY p.created_at DESC, pi.seq ASC
    ")->fetch_all(MYSQLI_ASSOC);

    return array_map(function ($r) {
        // Stock Code = SFS-{Coil No}-{Width}-{Length}, e.g. SFS-FK-357-796
        // Trusts the value stored on pallet_items (set at insert time via
        // PalletManager); only recomputes for legacy rows added before that
        // column existed, so old pallets still show a code.
        $stockCode = $r['pi_stock_code'];
        if (empty($stockCode) && !empty($r['coil_no'])) {
            $lenVal = (!empty($r['actual_length']) && $r['actual_length'] > 0)
                ? $r['actual_length'] : $r['length'];
            $stockCode = PalletManager::formatStockCode($r['coil_no'], $r['width'] ?? 0, $lenVal ?? 0);
        }

        return [
            'pallet_id'  => $r['pallet_id'],
            'pallet_no'  => $r['pallet_no'],
            'status'     => $r['status'],
            'stock_code' => $stockCode,
            'roll_no'    => $r['roll_no'] ? str_replace('R', 'R-', $r['roll_no']) : null,
            'lot_coil'   => trim(($r['lot_no'] ?? '') . ' ' . ($r['coil_no'] ?? '')),
            'product'    => $r['product'],
            'customer'   => $r['customer_name'],
            'ref_no'     => $r['ref_no'],
            'width'      => $r['width'] !== null ? (float)$r['width'] : null,
        ];
    }, $rows);
}

// ── Shared: apply the same category/value filter used by the modal's
//    client-side JS, so the exported Excel matches whatever the user
//    was actually looking at when they clicked Export. ─────────────
function filterSummaryPalletRows(array $rows, string $cat, string $val): array {
    if ($val === '') return $rows;

    if ($cat === 'status' || $cat === 'customer') {
        return array_values(array_filter($rows, fn($r) => (string)($r[$cat] ?? '') === $val));
    }

    if ($cat === 'width') {
        return array_values(array_filter($rows, function ($r) use ($val) {
            return $r['width'] !== null && str_contains((string)round($r['width']), $val);
        }));
    }

    // All Fields — free text across everything visible in the table
    $needle = strtolower($val);
    return array_values(array_filter($rows, function ($r) use ($needle) {
        foreach ([$r['pallet_no'], $r['status'], $r['stock_code'], $r['lot_coil'], $r['roll_no'], $r['customer'], $r['ref_no'], $r['width']] as $field) {
            if ($field !== null && str_contains(strtolower((string)$field), $needle)) return true;
        }
        return false;
    }));
}

// ── AJAX: Summary Pallet — flattened pallet + nested product rows ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'summary_pallet') {
    header('Content-Type: application/json');

    $out = buildSummaryPalletRows($conn);

    echo json_encode(['ok' => true, 'rows' => $out]);
    exit;
}

// ── Export: Summary Pallet as Excel (.xls) ──────────────────────
// Respects the same category/value filter the modal has active, so
// the download matches whatever the user was looking at on screen.
if (isset($_GET['export']) && $_GET['export'] === 'summary_pallet') {
    $cat = isset($_GET['cat']) ? trim($_GET['cat']) : '';
    $val = isset($_GET['val']) ? trim($_GET['val']) : '';

    $rows = buildSummaryPalletRows($conn);
    $rows = filterSummaryPalletRows($rows, $cat, $val);

    $catLabels = ['status' => 'Status', 'customer' => 'Customer', 'width' => 'Width'];
    $filterLbl = ($val !== '' && isset($catLabels[$cat]))
        ? "{$catLabels[$cat]}: {$val}"
        : (($val !== '') ? "Search: {$val}" : 'All Records');

    $filename = 'Summary_Pallet_' . date('Y-m-d_His') . '.xls';

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $cols      = 7; // Pallet No, Status, Stock Code, Rolls, Customer, Ref No, Width
    $generated = date('d M Y, H:i');
    ?>
<html><head><meta charset="UTF-8"></head><body>

<table>
    <tr>
        <td colspan="<?= $cols ?>" style="background:#1e3a5f;color:#fff;font-size:18px;font-weight:bold;padding:12px 16px;letter-spacing:1px;">
            PALLET SUMMARY REPORT
        </td>
    </tr>
    <tr>
        <td colspan="<?= $cols ?>" style="background:#2c5282;color:#bee3f8;font-size:11px;padding:4px 16px;">
            Generated: <?= htmlspecialchars($generated) ?> &nbsp;|&nbsp; Filter: <?= htmlspecialchars($filterLbl) ?> &nbsp;|&nbsp; System: Slitting Management
        </td>
    </tr>
    <tr><td colspan="<?= $cols ?>"></td></tr><!-- spacer -->
</table>

<table border="1" style="border-collapse:collapse;">
    <thead>
        <tr style="background:#343a40;color:#fff;font-weight:bold;font-size:12px;">
            <th style="padding:8px 10px;">Pallet No</th>
            <th style="padding:8px 10px;">Status</th>
            <th style="padding:8px 10px;">Stock Code</th>
            <th style="padding:8px 10px;">Rolls</th>
            <th style="padding:8px 10px;">Customer</th>
            <th style="padding:8px 10px;">Ref No</th>
            <th style="padding:8px 10px;">Width (mm)</th>
        </tr>
    </thead>
    <tbody>
<?php
    $td  = 'style="padding:6px 10px;"';
    $tdN = 'style="padding:6px 10px;text-align:right;"';

    if (!empty($rows)) {
        foreach ($rows as $r) {
            $statusLbl = ucwords(str_replace('_', ' ', $r['status'] ?? '-'));
            $rollsCell = $r['roll_no'] ? trim($r['lot_coil'] . ' - ' . $r['roll_no']) : '-- no rolls --';

            echo '<tr>';
            echo '<td ' . $td  . '><b>' . htmlspecialchars($r['pallet_no'] ?? '-') . '</b></td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($statusLbl) . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars(($r['stock_code'] ?? '') ?: '-') . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($rollsCell) . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($r['customer'] ?: '-') . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($r['ref_no']   ?: '-') . '</td>';
            echo '<td ' . $tdN . '>' . ($r['width'] !== null ? number_format($r['width']) : '-') . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="' . $cols . '" style="padding:20px;text-align:center;color:#666;">No records found for this filter.</td></tr>';
    }
?>
    </tbody>
</table>
</body></html>
<?php
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

// ── POST: Reopen pallet for editing / corrections ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reopen_pallet') {
    $palletId = intval($_POST['pallet_id'] ?? 0);
    $result   = $pm->reopenApprovedOrDeliveredPallet($palletId);
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

// Extended getPalletItems with std_weight for weight calculation
function getPalletItemsWithWeight(mysqli $conn, int $pallet_id): array {
    $stmt = $conn->prepare("
        SELECT pi.seq, pi.added_at, pi.stock_code,
               sp.id AS product_id,
               sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
               sp.width, sp.length, sp.actual_length, sp.nod_length, sp.status,
               sp.customer_name, sp.ref_no,
               COALESCE(sw.std_weight, 0) AS std_weight
        FROM pallet_items pi
        JOIN slitting_product sp ON sp.id = pi.slitting_product_id
        LEFT JOIN std_wgt sw     ON sw.product_code = sp.product
        WHERE pi.pallet_id = ?
        ORDER BY pi.seq ASC
    ");
    $stmt->bind_param("i", $pallet_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        $lenVal = (float)($row['actual_length'] ?: $row['length']);
        if (empty($row['stock_code'])) {
            $row['stock_code'] = PalletManager::formatStockCode($row['coil_no'], $row['width'], $lenVal);
        } else {
            // Re-format to ensure latest SFS-coil_alpha-length-width standard is reflected
            $row['stock_code'] = PalletManager::formatStockCode($row['coil_no'], $row['width'], $lenVal);
        }
    }
    unset($row);
    return $rows;
}

// Helper: calculate estimated weight for one roll
function calcEstWeight(float $lengthM, float $widthMm, float $stdWeight): float {
    if ($lengthM <= 0 || $widthMm <= 0 || $stdWeight <= 0) return 0.0;
    return ($lengthM * $widthMm / 1000) * $stdWeight;
}

$activeItems = $activePallet ? getPalletItemsWithWeight($conn, $activePalletId) : [];

// Build a lookup: seq → item, so PHP can render all 8 slots in order
$itemsBySeq = [];
foreach ($activeItems as $item) {
    $itemsBySeq[(int)$item['seq']] = $item;
}

// Pre-calculate total Est. Weight for PHP-rendered slots
$totalEstWgt = 0.0;
foreach ($activeItems as $item) {
    $len = (float)($item['actual_length'] ?: $item['length']);
    $totalEstWgt += calcEstWeight($len, (float)$item['width'], (float)$item['std_weight']);
}

// Pallets rejected by QC — still queried server-side because the
// "Need Attention" banner near the top of the page renders on first
// paint, before any client-side fetch has a chance to run. The unified
// sidebar list below gets its rejected pallets from ajax=list_pallets
// (group=open) instead, so this is no longer duplicated into the sidebar.
$rejectedPallets = $conn->query(
    "SELECT p.*, COUNT(pi.id) AS item_count
     FROM pallets p LEFT JOIN pallet_items pi ON pi.pallet_id = p.id
     WHERE p.status = 'rejected'
     GROUP BY p.id ORDER BY p.rejected_at DESC, p.updated_at DESC LIMIT 20"
)->fetch_all(MYSQLI_ASSOC);

$page_title = 'Pallet Management';
include 'header.php';

$MAX = PalletManager::MAX_ROLLS;

$isBuilding  = $activePallet && $activePallet['status'] === 'building';
$isRejected  = $activePallet && $activePallet['status'] === 'rejected';
$isApproved  = $activePallet && $activePallet['status'] === 'approved';
$isPendingQc = $activePallet && $activePallet['status'] === 'pending_qc';
$isDelivered = $activePallet && $activePallet['status'] === 'delivered';
$isReadOnly  = $activePallet && !in_array($activePallet['status'], ['building', 'rejected']);
?>
<style>
/* ── Layout ── */
.pallet-sidebar   { position:sticky; top:20px; }

/* ── Unified pallet list (redesigned sidebar) ── */
.pallet-list-card   { display:flex; flex-direction:column; height:78vh; min-height:520px; max-height:860px; overflow:hidden; }
.pallet-list-header  { flex:0 0 auto; }
.pallet-list-header .form-control,
.pallet-list-header .form-select {
    background:#2b3035; border-color:#495057; color:#f8f9fa; font-size:12.5px;
}
.pallet-list-header .form-control::placeholder { color:#adb5bd; }
.pallet-list-header .form-control:focus,
.pallet-list-header .form-select:focus {
    background:#2b3035; color:#fff; border-color:#6c757d; box-shadow:none;
}

.pallet-tab-group        { background:#212529; border-radius:6px; padding:2px; }
.pallet-tab-group .pallet-tab {
    color:#adb5bd; border:0; font-size:11.5px; font-weight:600; padding:4px 8px;
    border-radius:4px; letter-spacing:.01em;
}
.pallet-tab-group .pallet-tab:hover  { color:#fff; }
.pallet-tab-group .pallet-tab.active { background:#fff; color:#212529; }
.pallet-tab-group .pallet-tab .tab-count { opacity:.65; font-weight:500; margin-left:2px; }

.pallet-list-scroll {
    flex:1 1 auto; overflow-y:auto; padding:10px; background:#f8f9fa;
}

.pallet-card {
    display:block; background:#fff; border:1px solid var(--bs-border-color);
    border-left:4px solid #adb5bd; border-radius:6px; padding:9px 11px;
    margin-bottom:8px; text-decoration:none; color:inherit;
    transition:box-shadow .12s, transform .12s;
}
.pallet-card:hover  { box-shadow:0 2px 10px rgba(0,0,0,.1); transform:translateY(-1px); color:inherit; }

.pallet-card.border-building   { border-left-color:#0ea5e9; }
.pallet-card.border-pending_qc { border-left-color:#f59e0b; }
.pallet-card.border-approved   { border-left-color:#22c55e; }
.pallet-card.border-rejected   { border-left-color:#ef4444; }
.pallet-card.border-delivered  { border-left-color:#10b981; }

/* .active is declared last so it wins the cascade over the status
   border-color rules above, regardless of which status class is present */
.pallet-card.active { background:#0d6efd; border-left-color:#fff; color:#fff; }
.pallet-card.active .pallet-card-customer,
.pallet-card.active .pallet-card-rolls,
.pallet-card.active .pallet-card-lot { color:rgba(255,255,255,.75) !important; }
.pallet-card.active .pallet-progress { background:rgba(255,255,255,.25); }

.pallet-card-top      { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; margin-bottom:5px; }
.pallet-card-id        { font-family:'Courier New', monospace; font-weight:700; font-size:13px; }
.pallet-card-open-pill {
    display:inline-flex; align-items:center; gap:3px;
    font-family:'Segoe UI', Arial, sans-serif; font-weight:700; font-size:9px;
    text-transform:uppercase; letter-spacing:.3px;
    background:rgba(255,255,255,.25); color:#fff;
    border-radius:8px; padding:1px 6px; margin-left:6px; vertical-align:middle;
}
.pallet-card-lot        { font-family:'Courier New', monospace; font-size:10px; color:#adb5bd; margin-top:1px; }
.pallet-card-customer  { font-size:12.5px; font-weight:600; margin-bottom:7px; }
.pallet-card-rolls      { font-size:11px; font-weight:600; color:#6c757d; white-space:nowrap; }

.pallet-list-empty, .pallet-list-loading { text-align:center; color:#adb5bd; font-size:13px; padding:36px 16px; }

/* ── Inline pallet-no rename (header banner) ── */
.pallet-rename-header { position:relative; }
.pallet-rename-text    { font-size:15px; }
.pallet-rename-edit-btn {
    padding:2px 7px; opacity:.8; border:0; background:transparent; line-height:1;
}
.pallet-rename-edit-btn:hover { opacity:1; background:rgba(255,255,255,.18); border-radius:4px; }
#palletRenameForm.d-flex { display:flex !important; }
.pallet-rename-input {
    width:230px; font-family:'Courier New', monospace; font-weight:700; font-size:14px;
    border:0; box-shadow:0 0 0 2px rgba(255,255,255,.6) inset;
}
.pallet-rename-input:focus { box-shadow:0 0 0 2px #fff inset; }
#palletRenameSaveBtn, #palletRenameCancelBtn { padding:3px 8px; line-height:1; }
.pallet-rename-error {
    position:absolute; top:100%; left:0; margin-top:6px;
    background:#fff; color:#dc3545; border:1px solid #dc3545;
    border-radius:4px; padding:4px 9px; font-size:11px; font-weight:600;
    white-space:nowrap; z-index:20; box-shadow:0 2px 8px rgba(0,0,0,.18);
}

/* ── Inline Customer / Ref No edit (constraint badge header) ── */
.constraint-edit-btn { font-size:11px; opacity:.7; line-height:1; }
.constraint-edit-btn:hover { opacity:1; }
#constraintEditForm.d-flex { display:flex !important; }
.constraint-edit-input { width:120px; font-size:11px; padding:2px 6px; height:auto; }
.constraint-edit-error {
    position:absolute; top:100%; left:12px; margin-top:2px;
    background:#fff; color:#dc3545; border:1px solid #dc3545;
    border-radius:4px; padding:3px 8px; font-size:11px; font-weight:600;
    white-space:nowrap; z-index:20; box-shadow:0 2px 8px rgba(0,0,0,.18);
}

/* ── Manual entry — now the single/primary way to add a roll by hand ── */
.manual-entry-input {
    font-family:'Courier New', monospace;
    font-size:16px;
    font-weight:600;
    padding:.55rem .75rem;
    letter-spacing:.01em;
}
.manual-entry-input:focus {
    border-color:#0d6efd;
    box-shadow:0 0 0 .2rem rgba(13,110,253,.18);
}

/* ── Slot cards ── */
.slot-card {
    border: 1px solid var(--bs-border-color);
    border-radius: 8px;
    padding: 12px 14px;
    background: #fff;
    margin-bottom: 8px;
    transition: box-shadow .15s, background .15s;
    min-height: 68px;
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
.slot-empty .roll-seq { background: #dee2e6; color: #6c757d; }

/* ── Weight chip ── */
.wgt-chip {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fcd34d;
    border-radius: 10px;
    padding: 2px 8px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
    flex-shrink: 0;
}
.wgt-chip i { font-size: 10px; }

/* ── NOD chip ── */
.nod-chip {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
    border-radius: 10px;
    padding: 2px 8px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
    flex-shrink: 0;
}
.nod-chip i { font-size: 10px; }

/* ── Total weight summary bar ── */
.weight-summary-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: linear-gradient(135deg, #fef3c7 0%, #fffbeb 100%);
    border: 1px solid #fcd34d;
    border-radius: 8px;
    padding: 10px 14px;
    margin-bottom: 14px;
    flex-wrap: wrap;
    gap: 8px;
}
.weight-summary-bar .wgt-label {
    font-size: 12px;
    font-weight: 700;
    color: #78350f;
    display: flex;
    align-items: center;
    gap: 6px;
}
.weight-summary-bar .wgt-total {
    font-size: 20px;
    font-weight: 800;
    color: #92400e;
    font-family: 'Courier New', monospace;
}
.weight-summary-bar .wgt-unit {
    font-size: 13px;
    font-weight: 600;
    color: #b45309;
    margin-left: 2px;
}
.weight-summary-bar .wgt-avg {
    font-size: 11px;
    color: #92400e;
    background: rgba(255,255,255,.6);
    border-radius: 6px;
    padding: 3px 9px;
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
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-primary shadow-sm"
                data-bs-toggle="modal" data-bs-target="#summaryPalletModal"
                onclick="loadSummaryPallet()">
            <i class="bi bi-table me-1"></i> Summary Pallet
        </button>
        <button type="button" class="btn btn-success shadow-sm"
                data-bs-toggle="modal" data-bs-target="#createPalletModal">
            <i class="bi bi-plus-lg me-1"></i> New Pallet
        </button>
    </div>
</div>

<!-- Alerts -->
<?php
$successMsgs = [
    'created'        => 'Pallet created. Scan the first roll to lock its constraints.',
    'sent_to_qc'     => 'Pallet submitted to QC successfully.',
    'resubmitted'    => 'Pallet re-submitted to QC after editing.',
    'reopened'       => 'Pallet returned to edit mode — make necessary corrections, then re-submit to QC.',
    'delivered'      => 'Pallet ' . htmlspecialchars($_GET['pallet_no'] ?? '') . ' delivered.',
    'prev_delivered' => 'Previous approved Pallet ' . htmlspecialchars($_GET['prev_no'] ?? '') . ' was automatically delivered as you transitioned to a new pallet.',
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

<!-- ═══════════════════════════════════════════════════════════
     METHOD 2 — Global "Scan to Deliver" panel
     Always visible on pallet.php, regardless of whether a pallet
     is selected. Scanning (camera or hardware scanner gun) or
     typing any roll here resolves it → its pallet → delivers the
     whole pallet in one shot via ajax=deliver_by_scan below.
     No need to open the pallet first.
═══════════════════════════════════════════════════════════════ -->
<div class="card shadow-sm border-0 mb-3" id="deliverScanCard" style="border-left:4px solid #0284c7;">
    <div class="card-body py-3 d-flex align-items-center gap-3 flex-wrap">
        <div class="flex-grow-1" style="min-width:240px;">
            <div class="fw-bold" style="font-size:13px;">
                <i class="bi bi-search me-1 text-primary"></i> Find Pallet by Roll / Pallet QR
            </div>
            <div class="text-muted" style="font-size:12px;">
                Type <strong>Lot No + Coil No + Roll No</strong> (e.g. <code>826277 FK-1 R1</code>), scan QR code, or type Pallet No to view its pallet details.
            </div>
        </div>
        <div class="d-flex gap-2" style="min-width:320px;">
            <input type="text" id="deliverScanInput" class="form-control form-control-sm"
                   placeholder="Lot + Coil + Roll (e.g. 826277 FK-1 R1) or 7-digit Pallet No"
                   autocomplete="off" autocorrect="off" spellcheck="false">
            <button type="button" class="btn btn-primary btn-sm px-3 shadow-sm" onclick="triggerDeliverScan()">
                <i class="bi bi-search me-1"></i> Search
            </button>
        </div>
    </div>
    <div id="deliverScanFeedback" class="px-3 pb-2" style="font-size:12.5px; min-height:18px;"></div>
</div>

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
                <div class="d-flex align-items-center gap-2 pallet-rename-header">
                    <!-- Display mode -->
                    <span class="fw-bold pallet-rename-text" id="palletRenameDisplay"><?= htmlspecialchars($activePallet['pallet_no']) ?></span>
                    <button type="button" class="btn btn-sm text-white pallet-rename-edit-btn" id="palletRenameEditBtn"
                            title="Rename pallet" onclick="startEditPalletRename()">
                        <i class="bi bi-pencil-square"></i>
                    </button>

                    <!-- Edit mode (hidden until the pencil is clicked) -->
                    <div class="d-none align-items-center gap-1" id="palletRenameForm">
                        <input type="text" class="form-control form-control-sm pallet-rename-input" id="palletRenameInput"
                               value="<?= htmlspecialchars($activePallet['pallet_no']) ?>"
                               maxlength="30" autocomplete="off"
                               onkeydown="onPalletRenameKeydown(event)">
                        <button type="button" class="btn btn-sm btn-light text-success" id="palletRenameSaveBtn"
                                title="Save (Enter)" onclick="savePalletRename()">
                            <i class="bi bi-check-lg"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-light text-danger" id="palletRenameCancelBtn"
                                title="Cancel (Esc)" onclick="cancelEditPalletRename()">
                            <i class="bi bi-x-lg"></i>
                        </button>
                        <span class="pallet-rename-error d-none" id="palletRenameError"></span>
                    </div>

                    <?php if (($activePallet['edit_count'] ?? 0) > 0): ?>
                    <span class="edit-mode-pill ms-1">
                        <i class="bi bi-pencil-fill"></i>
                        EDIT #<?= $activePallet['edit_count'] ?>
                    </span>
                    <?php endif; ?>
                </div>
                <span class="badge bg-white text-primary" id="rollCountBadge">
                    <?= count($activeItems) ?> / <?= $MAX ?> rolls
                </span>
            </div>

            <!-- Warehousing Slip Header Grid -->
            <?php if (!empty(trim($activePallet['customer_name'] ?? ''))): ?>
            <div id="constraintHeader" class="table-responsive mb-0 p-3 border-bottom bg-light position-relative">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold text-secondary" style="font-size:12px;">
                        <i class="bi bi-file-text me-1"></i> WAREHOUSING SLIP HEADER
                    </span>
                    <span class="badge bg-secondary">MS-WH-01(QR)</span>
                </div>

                <div id="constraintDisplayGroup">
                    <table class="table table-sm table-bordered align-middle mb-0" style="font-size:13px; background:#fff; border-color:#cbd5e1;">
                        <tbody>
                            <tr>
                                <td class="bg-light fw-bold text-muted" style="width:15%;">Customer</td>
                                <td class="fw-bold text-dark" style="width:35%;">
                                    <span id="constraintCustomerText"><?= htmlspecialchars($activePallet['customer_name']) ?></span>
                                    <button type="button" class="btn btn-sm btn-link p-0 text-primary ms-1 constraint-edit-btn" id="constraintEditBtn"
                                            title="Edit Customer / Ref No" onclick="startEditConstraint()">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                </td>
                                <td class="bg-light fw-bold text-muted" style="width:15%;">Date</td>
                                <td class="fw-bold text-dark" style="width:35%;"><?= date('d/m/Y', strtotime($activePallet['created_at'] ?? 'now')) ?></td>
                            </tr>
                            <tr>
                                <td class="bg-light fw-bold text-muted">SOS No.</td>
                                <td class="fw-bold text-dark"><span id="constraintRefNoText"><?= htmlspecialchars($activePallet['ref_no']) ?></span></td>
                                <td class="bg-light fw-bold text-muted">Serial No.</td>
                                <td class="fw-bold text-primary"><?= htmlspecialchars($activePallet['pallet_no']) ?></td>
                            </tr>
                            <tr>
                                <td class="bg-light fw-bold text-muted">Product Type :</td>
                                <td class="fw-bold text-dark"><?= htmlspecialchars($activePallet['product_type']) ?></td>
                                <td class="bg-light fw-bold text-muted">Width (mm)</td>
                                <td class="fw-bold text-dark"><?= number_format((float)$activePallet['width']) ?> mm</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Edit mode (hidden until pencil clicked) -->
                <div class="d-none align-items-center gap-1 flex-wrap mt-2" id="constraintEditForm">
                    <input type="text" class="form-control form-control-sm constraint-edit-input" id="constraintCustomerInput"
                           value="<?= htmlspecialchars($activePallet['customer_name']) ?>"
                           placeholder="Customer" maxlength="120" autocomplete="off"
                           onkeydown="onConstraintEditKeydown(event)">
                    <input type="text" class="form-control form-control-sm constraint-edit-input" id="constraintRefNoInput"
                           value="<?= htmlspecialchars($activePallet['ref_no']) ?>"
                           placeholder="Ref No" maxlength="80" autocomplete="off"
                           onkeydown="onConstraintEditKeydown(event)">
                    <button type="button" class="btn btn-sm btn-success" id="constraintSaveBtn"
                            title="Save (Enter)" onclick="saveConstraintEdit()">
                        <i class="bi bi-check-lg me-1"></i> Save
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="constraintCancelBtn"
                            title="Cancel (Esc)" onclick="cancelEditConstraint()">
                        <i class="bi bi-x-lg me-1"></i> Cancel
                    </button>
                </div>

                <span class="constraint-edit-error d-none" id="constraintEditError"></span>
            </div>
            <?php else: ?>
            <div id="constraintHeader" class="px-3 py-2 border-bottom bg-light">
                <small class="text-muted">
                    <i class="bi bi-qr-code-scan me-1"></i>
                    Scan the first roll — its Customer, Ref No, Product Type and Width will lock as constraints in Warehousing Slip format.
                </small>
            </div>
            <?php endif; ?>

            <div class="card-body p-4">
                <div class="pallet-progress mb-2">
                    <div class="pallet-progress-bar" id="palletProgressBar"
                         style="width:<?= (count($activeItems) / $MAX * 100) ?>%"></div>
                </div>

                <!-- ── Total Est. Weight Summary Bar ── -->
                <div class="weight-summary-bar" id="weightSummaryBar">
                    <div class="wgt-label">
                        <i class="bi bi-speedometer2"></i>
                        Est. Total Weight
                    </div>
                    <div style="display:flex; align-items:baseline; gap:6px;">
                        <span class="wgt-total" id="totalWeightDisplay">
                            <?= $totalEstWgt > 0 ? number_format($totalEstWgt, 2) : '—' ?>
                        </span>
                        <span class="wgt-unit"><?= $totalEstWgt > 0 ? 'kg' : '' ?></span>
                    </div>
                    <div class="wgt-avg" id="avgWeightDisplay">
                        <?php if (count($activeItems) > 0 && $totalEstWgt > 0): ?>
                            avg <?= number_format($totalEstWgt / count($activeItems), 2) ?> kg/roll
                        <?php else: ?>
                            no weight data
                        <?php endif; ?>
                    </div>
                </div>

                <div class="alert alert-info py-2 mb-3">
                    <i class="bi bi-qr-code-scan me-1"></i>
                    Scan a product QR, or type the item below.
                </div>

                <!-- Single manual-entry input — replaces the old 3-box
                     (Lot/Coil/Roll) + separate all-in-one row. Accepts
                     both "826277 FK-1 R1" (space-separated) and
                     "LOT=826277;COIL=FK-1;ROLL=R1" (scanner format);
                     see parseQR(). -->
                <div class="mb-3">
                    <label for="manCombined" class="form-label fw-bold mb-1" style="font-size:12.5px;">
                        Scan or Type Item <span class="text-muted fw-normal">(e.g. 826277 FK-1 R1 or Lot;Coil;Roll)</span>
                    </label>
                    <div class="row g-2">
                        <div class="col-9">
                            <input type="text" id="manCombined" class="form-control manual-entry-input"
                                   placeholder="826277 FK-1 R1"
                                   autocomplete="off" autocorrect="off" spellcheck="false">
                        </div>
                        <div class="col-3">
                            <button type="button" class="btn btn-primary w-100 h-100"
                                    onclick="combinedLookup()">
                                <i class="bi bi-box-arrow-in-down me-1"></i> Add
                            </button>
                        </div>
                    </div>
                </div>

                <div id="scanFeedback" class="mb-3" style="min-height:40px;"></div>

                <!-- =====================================================
                     SLOT LIST — Table grid matching Warehousing Slip format.
                ===================================================== -->
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-hover align-middle text-center mb-0" style="font-size:13px; border-color:#cbd5e1;">
                        <thead class="table-light border-bottom border-secondary" style="font-size:12px;">
                            <tr>
                                <th rowspan="2" class="align-middle" style="width: 25%;">Stock Code :</th>
                                <th rowspan="2" class="align-middle" style="width: 22%;">Lot No.</th>
                                <th colspan="2" class="align-middle">Size</th>
                                <th rowspan="2" class="align-middle" style="width: 8%;">Coils</th>
                                <th rowspan="2" class="align-middle" style="width: 12%;">Roll No.</th>
                                <th rowspan="2" class="align-middle" style="width: 15%;">Nett Wgt (kg)</th>
                                <th rowspan="2" class="align-middle" style="width: 8%;">Action</th>
                            </tr>
                            <tr>
                                <th style="width: 12%;">Length (mtr)</th>
                                <th style="width: 12%;">width (mm)</th>
                            </tr>
                        </thead>
                        <tbody id="rollList">
                        <?php for ($s = 1; $s <= $MAX; $s++):
                            $item = $itemsBySeq[$s] ?? null;
                        ?>
                        <?php if ($item):
                            $itemLen = (float)($item['actual_length'] ?: $item['length']);
                            $itemWgt = calcEstWeight($itemLen, (float)$item['width'], (float)$item['std_weight']);
                            $itemNod = (float)($item['nod_length'] ?? 0);
                            $hasNod  = $itemNod > 0;
                            $netLen  = $itemLen - $itemNod;
                        ?>
                        <tr id="slot<?= $s ?>" data-slot="<?= $s ?>" data-filled="1" data-product-id="<?= $item['product_id'] ?>" data-weight="<?= number_format($itemWgt, 4) ?>">
                            <td class="fw-bold text-start ps-3" style="font-family:monospace; font-size:12px;"><?= htmlspecialchars(($item['stock_code'] ?? '') ?: '-') ?></td>
                            <td><?= htmlspecialchars($item['lot_no']) ?> <?= htmlspecialchars($item['coil_no']) ?></td>
                            <td>
                                <?= number_format($itemLen, 1) ?>
                                <?php if ($hasNod): ?>
                                <br><span class="nod-chip" title="Actual <?= number_format($itemLen, 2) ?>m &minus; NOD <?= number_format($itemNod, 2) ?>m = <?= number_format($netLen, 2) ?>m"><i class="bi bi-exclamation-triangle-fill"></i> NOD &minus;<?= number_format($itemNod, 2) ?> &rarr; <?= number_format($netLen, 2) ?>m</span>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format((float)$item['width']) ?></td>
                            <td>1</td>
                            <td class="fw-bold"><?= str_replace('R','R-', htmlspecialchars($item['roll_no'])) ?></td>
                            <td class="fw-bold text-end pe-3 text-primary"><?= $itemWgt > 0 ? number_format($itemWgt, 2) : '-' ?></td>
                            <td>
                                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2" title="Remove this roll" data-product-id="<?= $item['product_id'] ?>" onclick="removeRoll(<?= $activePalletId ?>, <?= $item['product_id'] ?>, <?= $s ?>, this)">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </td>
                        </tr>
                        <?php else: ?>
                        <tr id="slot<?= $s ?>" data-slot="<?= $s ?>" data-filled="0" data-weight="0" class="table-light text-muted">
                            <td class="text-start ps-3 text-muted">&mdash; Empty Slot <?= $s ?> &mdash;</td>
                            <td>&mdash;</td>
                            <td>&mdash;</td>
                            <td>&mdash;</td>
                            <td>&mdash;</td>
                            <td>&mdash;</td>
                            <td>&mdash;</td>
                            <td>&mdash;</td>
                        </tr>
                        <?php endif; ?>
                        <?php endfor; ?>
                        </tbody>
                    </table>
                </div><!-- .table-responsive -->
            </div>

            <div class="card-footer bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex gap-2">
                    <a href="pallet.php" class="btn btn-outline-secondary btn-sm">Close panel</a>
                    <a href="print_slip.php?pallet_no=<?= urlencode($activePallet['pallet_no']) ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-printer me-1"></i> Print Slip
                    </a>
                    <form method="post"
                          onsubmit="return confirm('Delete pallet ' + currentPalletNo + '?\n\nAll rolls will be returned to stock — the products themselves are NOT deleted.')">
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
                <div class="d-flex align-items-center gap-2 pallet-rename-header">
                    <i class="bi bi-x-circle me-1"></i>
                    <strong class="pallet-rename-text" id="palletRenameDisplay"><?= htmlspecialchars($activePallet['pallet_no']) ?></strong>
                    <button type="button" class="btn btn-sm text-white pallet-rename-edit-btn" id="palletRenameEditBtn"
                            title="Rename pallet" onclick="startEditPalletRename()">
                        <i class="bi bi-pencil-square"></i>
                    </button>

                    <div class="d-none align-items-center gap-1" id="palletRenameForm">
                        <input type="text" class="form-control form-control-sm pallet-rename-input" id="palletRenameInput"
                               value="<?= htmlspecialchars($activePallet['pallet_no']) ?>"
                               maxlength="30" autocomplete="off"
                               onkeydown="onPalletRenameKeydown(event)">
                        <button type="button" class="btn btn-sm btn-light text-success" id="palletRenameSaveBtn"
                                title="Save (Enter)" onclick="savePalletRename()">
                            <i class="bi bi-check-lg"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-light text-danger" id="palletRenameCancelBtn"
                                title="Cancel (Esc)" onclick="cancelEditPalletRename()">
                            <i class="bi bi-x-lg"></i>
                        </button>
                        <span class="pallet-rename-error d-none" id="palletRenameError"></span>
                    </div>

                    <span class="badge bg-white text-danger ms-1">QC REJECTED</span>
                </div>
                <span class="badge bg-white text-danger">
                    <?= count($activeItems) ?> roll<?= count($activeItems) != 1 ? 's' : '' ?>
                </span>
            </div>

            <!-- Warehousing Slip Header Grid -->
            <?php if (!empty(trim($activePallet['customer_name'] ?? ''))): ?>
            <div class="table-responsive mb-0 p-3 border-bottom bg-light">
                <table class="table table-sm table-bordered align-middle mb-0" style="font-size:13px; background:#fff; border-color:#cbd5e1;">
                    <tbody>
                        <tr>
                            <td class="bg-light fw-bold text-muted" style="width:15%;">Customer</td>
                            <td class="fw-bold text-dark" style="width:35%;"><?= htmlspecialchars($activePallet['customer_name'] ?: '-') ?></td>
                            <td class="bg-light fw-bold text-muted" style="width:15%;">Date</td>
                            <td class="fw-bold text-dark" style="width:35%;"><?= date('d/m/Y', strtotime($activePallet['created_at'] ?? 'now')) ?></td>
                        </tr>
                        <tr>
                            <td class="bg-light fw-bold text-muted">SOS No.</td>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($activePallet['ref_no'] ?: '-') ?></td>
                            <td class="bg-light fw-bold text-muted">Serial No.</td>
                            <td class="fw-bold text-primary"><?= htmlspecialchars($activePallet['pallet_no'] ?: '-') ?></td>
                        </tr>
                        <tr>
                            <td class="bg-light fw-bold text-muted">Product Type :</td>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($activePallet['product_type'] ?: '-') ?></td>
                            <td class="bg-light fw-bold text-muted">Width (mm)</td>
                            <td class="fw-bold text-dark"><?= $activePallet['width'] ? number_format((float)$activePallet['width']) . ' mm' : '-' ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

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
                <?php
                $rejectedTotalWgt = 0.0;
                foreach ($activeItems as $item) {
                    $len = (float)($item['actual_length'] ?: $item['length']);
                    $rejectedTotalWgt += calcEstWeight($len, (float)$item['width'], (float)($item['std_weight'] ?? 0));
                }
                ?>
                <?php if ($rejectedTotalWgt > 0): ?>
                <div class="weight-summary-bar mb-3">
                    <div class="wgt-label"><i class="bi bi-speedometer2"></i> Est. Total Weight</div>
                    <div style="display:flex; align-items:baseline; gap:6px;">
                        <span class="wgt-total"><?= number_format($rejectedTotalWgt, 2) ?></span>
                        <span class="wgt-unit">kg</span>
                    </div>
                    <div class="wgt-avg"><?= count($activeItems) ?> roll<?= count($activeItems) != 1 ? 's' : '' ?></div>
                </div>
                <?php endif; ?>

                <p class="text-muted mb-3" style="font-size:13px;">
                    <i class="bi bi-info-circle me-1"></i>
                    Click <strong>Edit Pallet</strong> to reopen it. You can then remove the defective roll(s),
                    add replacement rolls, and re-submit to QC.
                </p>

                <!-- Warehousing Slip Roll Items Table -->
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-hover align-middle text-center mb-0" style="font-size:13px; border-color:#cbd5e1;">
                        <thead class="table-light border-bottom border-secondary" style="font-size:12px;">
                            <tr>
                                <th rowspan="2" class="align-middle" style="width: 25%;">Stock Code :</th>
                                <th rowspan="2" class="align-middle" style="width: 25%;">Lot No.</th>
                                <th colspan="2" class="align-middle">Size</th>
                                <th rowspan="2" class="align-middle" style="width: 8%;">Coils</th>
                                <th rowspan="2" class="align-middle" style="width: 12%;">Roll No.</th>
                                <th rowspan="2" class="align-middle" style="width: 18%;">Nett Wgt (kg)</th>
                            </tr>
                            <tr>
                                <th style="width: 12%;">Length (mtr)</th>
                                <th style="width: 12%;">width (mm)</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activeItems as $item):
                            $itemLen = (float)($item['actual_length'] ?: $item['length']);
                            $itemWgt = calcEstWeight($itemLen, (float)$item['width'], (float)($item['std_weight'] ?? 0));
                            $itemNod = (float)($item['nod_length'] ?? 0);
                            $hasNod  = $itemNod > 0;
                            $netLen  = $itemLen - $itemNod;
                        ?>
                        <tr>
                            <td class="fw-bold text-start ps-3" style="font-family:monospace; font-size:12px;"><?= htmlspecialchars(($item['stock_code'] ?? '') ?: '-') ?></td>
                            <td><?= htmlspecialchars($item['lot_no']) ?> <?= htmlspecialchars($item['coil_no']) ?></td>
                            <td>
                                <?= number_format($itemLen, 1) ?>
                                <?php if ($hasNod): ?>
                                <br><span class="nod-chip" title="Actual <?= number_format($itemLen, 2) ?>m &minus; NOD <?= number_format($itemNod, 2) ?>m = <?= number_format($netLen, 2) ?>m"><i class="bi bi-exclamation-triangle-fill"></i> NOD &minus;<?= number_format($itemNod, 2) ?> &rarr; <?= number_format($netLen, 2) ?>m</span>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format((float)$item['width']) ?></td>
                            <td>1</td>
                            <td class="fw-bold"><?= str_replace('R','R-', htmlspecialchars($item['roll_no'])) ?></td>
                            <td class="fw-bold text-end pe-3 text-primary"><?= $itemWgt > 0 ? number_format($itemWgt, 2) : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card-footer bg-light d-flex justify-content-between align-items-center">
                <div class="d-flex gap-2">
                    <a href="pallet.php" class="btn btn-outline-secondary btn-sm">← Back</a>
                    <a href="print_slip.php?pallet_no=<?= urlencode($activePallet['pallet_no']) ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-printer me-1"></i> Print Slip
                    </a>
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
                            onclick="return confirm('Reopen pallet ' + currentPalletNo + ' for editing?\nAll rolls will be reset to IN so you can modify the pallet.')">
                        <i class="bi bi-pencil-fill me-1"></i> Edit Pallet
                    </button>
                </form>
            </div>
        </div>

        <?php elseif ($isPendingQc): ?>
        <!-- ═══════════════════════════════════════════════════════
             PENDING QC STATE — Submitted to QC, awaiting review
        ═══════════════════════════════════════════════════════════ -->
        <div class="card shadow-sm border-0 mb-4" style="border-left:4px solid #f59e0b !important;">
            <div class="card-header text-white d-flex justify-content-between align-items-center"
                 style="background:#d97706;">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-clock-history me-1"></i>
                    <strong><?= htmlspecialchars($activePallet['pallet_no']) ?></strong>
                    <span class="badge bg-white text-warning ms-1" style="color:#b45309 !important;">PENDING QC — AWAITING QC APPROVAL</span>
                </div>
                <span class="badge bg-white text-warning" style="color:#b45309 !important;">
                    <?= count($activeItems) ?> roll<?= count($activeItems) != 1 ? 's' : '' ?>
                </span>
            </div>

            <!-- Warehousing Slip Header Grid -->
            <?php if (!empty(trim($activePallet['customer_name'] ?? ''))): ?>
            <div class="table-responsive mb-0 p-3 border-bottom bg-light">
                <table class="table table-sm table-bordered align-middle mb-0" style="font-size:13px; background:#fff; border-color:#cbd5e1;">
                    <tbody>
                        <tr>
                            <td class="bg-light fw-bold text-muted" style="width:15%;">Customer</td>
                            <td class="fw-bold text-dark" style="width:35%;"><?= htmlspecialchars($activePallet['customer_name'] ?: '-') ?></td>
                            <td class="bg-light fw-bold text-muted" style="width:15%;">Date</td>
                            <td class="fw-bold text-dark" style="width:35%;"><?= date('d/m/Y', strtotime($activePallet['created_at'] ?? 'now')) ?></td>
                        </tr>
                        <tr>
                            <td class="bg-light fw-bold text-muted">SOS No.</td>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($activePallet['ref_no'] ?: '-') ?></td>
                            <td class="bg-light fw-bold text-muted">Serial No.</td>
                            <td class="fw-bold text-primary"><?= htmlspecialchars($activePallet['pallet_no'] ?: '-') ?></td>
                        </tr>
                        <tr>
                            <td class="bg-light fw-bold text-muted">Product Type :</td>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($activePallet['product_type'] ?: '-') ?></td>
                            <td class="bg-light fw-bold text-muted">Width (mm)</td>
                            <td class="fw-bold text-dark"><?= $activePallet['width'] ? number_format((float)$activePallet['width']) . ' mm' : '-' ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="card-body p-4">
                <?php
                $pendingTotalWgt = 0.0;
                foreach ($activeItems as $item) {
                    $len = (float)($item['actual_length'] ?: $item['length']);
                    $pendingTotalWgt += calcEstWeight($len, (float)$item['width'], (float)($item['std_weight'] ?? 0));
                }
                ?>
                <?php if ($pendingTotalWgt > 0): ?>
                <div class="weight-summary-bar mb-3">
                    <div class="wgt-label"><i class="bi bi-speedometer2"></i> Est. Total Weight</div>
                    <div style="display:flex; align-items:baseline; gap:6px;">
                        <span class="wgt-total"><?= number_format($pendingTotalWgt, 2) ?></span>
                        <span class="wgt-unit">kg</span>
                    </div>
                    <div class="wgt-avg"><?= count($activeItems) ?> roll<?= count($activeItems) != 1 ? 's' : '' ?></div>
                </div>
                <?php endif; ?>

                <div class="alert alert-warning py-2 mb-3" style="font-size:13px; background:#fffbe8; border-color:#fde68a; color:#92400e;">
                    <i class="bi bi-clock-history me-1"></i>
                    This pallet has been submitted to QC and is currently awaiting approval.
                </div>

                <!-- Warehousing Slip Roll Items Table -->
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-hover align-middle text-center mb-0" style="font-size:13px; border-color:#cbd5e1;">
                        <thead class="table-light border-bottom border-secondary" style="font-size:12px;">
                            <tr>
                                <th rowspan="2" class="align-middle" style="width: 25%;">Stock Code :</th>
                                <th rowspan="2" class="align-middle" style="width: 25%;">Lot No.</th>
                                <th colspan="2" class="align-middle">Size</th>
                                <th rowspan="2" class="align-middle" style="width: 8%;">Coils</th>
                                <th rowspan="2" class="align-middle" style="width: 12%;">Roll No.</th>
                                <th rowspan="2" class="align-middle" style="width: 18%;">Nett Wgt (kg)</th>
                            </tr>
                            <tr>
                                <th style="width: 12%;">Length (mtr)</th>
                                <th style="width: 12%;">width (mm)</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activeItems as $item):
                            $itemLen = (float)($item['actual_length'] ?: $item['length']);
                            $itemWgt = calcEstWeight($itemLen, (float)$item['width'], (float)($item['std_weight'] ?? 0));
                            $itemNod = (float)($item['nod_length'] ?? 0);
                            $hasNod  = $itemNod > 0;
                            $netLen  = $itemLen - $itemNod;
                        ?>
                        <tr>
                            <td class="fw-bold text-start ps-3" style="font-family:monospace; font-size:12px;"><?= htmlspecialchars(($item['stock_code'] ?? '') ?: '-') ?></td>
                            <td><?= htmlspecialchars($item['lot_no']) ?> <?= htmlspecialchars($item['coil_no']) ?></td>
                            <td>
                                <?= number_format($itemLen, 1) ?>
                                <?php if ($hasNod): ?>
                                <br><span class="nod-chip" title="Actual <?= number_format($itemLen, 2) ?>m &minus; NOD <?= number_format($itemNod, 2) ?>m = <?= number_format($netLen, 2) ?>m"><i class="bi bi-exclamation-triangle-fill"></i> NOD &minus;<?= number_format($itemNod, 2) ?> &rarr; <?= number_format($netLen, 2) ?>m</span>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format((float)$item['width']) ?></td>
                            <td>1</td>
                            <td class="fw-bold"><?= str_replace('R','R-', htmlspecialchars($item['roll_no'])) ?></td>
                            <td class="fw-bold text-end pe-3 text-primary"><?= $itemWgt > 0 ? number_format($itemWgt, 2) : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card-footer bg-light d-flex justify-content-between align-items-center">
                <div class="d-flex gap-2">
                    <a href="pallet.php" class="btn btn-outline-secondary btn-sm">← Back</a>
                    <a href="print_slip.php?pallet_no=<?= urlencode($activePallet['pallet_no']) ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-printer me-1"></i> Print Slip
                    </a>
                </div>
                <span class="text-muted small"><i class="bi bi-lock-fill me-1"></i>Read-Only (Pending QC)</span>
            </div>
        </div>

        <?php elseif ($isApproved): ?>
        <!-- ═══════════════════════════════════════════════════════
             APPROVED STATE — Method 1: Manual "Deliver Pallet" button
             Ready to ship. Every roll goes out together via
             PalletManager::bundleDeliver() (server-side action
             already wired at the top of this file: action=deliver_pallet).
        ═══════════════════════════════════════════════════════════ -->
        <div class="card shadow-sm border-0 mb-4" style="border-left:4px solid #16a34a !important;">
            <div class="card-header text-white d-flex justify-content-between align-items-center"
                 style="background:#166534;">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-check-circle-fill me-1"></i>
                    <strong><?= htmlspecialchars($activePallet['pallet_no']) ?></strong>
                    <span class="badge bg-white text-success ms-1">APPROVED — READY TO DELIVER</span>
                </div>
                <span class="badge bg-white text-success">
                    <?= count($activeItems) ?> roll<?= count($activeItems) != 1 ? 's' : '' ?>
                </span>
            </div>

            <!-- Warehousing Slip Header Grid -->
            <?php if (!empty(trim($activePallet['customer_name'] ?? ''))): ?>
            <div class="table-responsive mb-0 p-3 border-bottom bg-light">
                <table class="table table-sm table-bordered align-middle mb-0" style="font-size:13px; background:#fff; border-color:#cbd5e1;">
                    <tbody>
                        <tr>
                            <td class="bg-light fw-bold text-muted" style="width:15%;">Customer</td>
                            <td class="fw-bold text-dark" style="width:35%;"><?= htmlspecialchars($activePallet['customer_name'] ?: '-') ?></td>
                            <td class="bg-light fw-bold text-muted" style="width:15%;">Date</td>
                            <td class="fw-bold text-dark" style="width:35%;"><?= date('d/m/Y', strtotime($activePallet['created_at'] ?? 'now')) ?></td>
                        </tr>
                        <tr>
                            <td class="bg-light fw-bold text-muted">SOS No.</td>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($activePallet['ref_no'] ?: '-') ?></td>
                            <td class="bg-light fw-bold text-muted">Serial No.</td>
                            <td class="fw-bold text-primary"><?= htmlspecialchars($activePallet['pallet_no'] ?: '-') ?></td>
                        </tr>
                        <tr>
                            <td class="bg-light fw-bold text-muted">Product Type :</td>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($activePallet['product_type'] ?: '-') ?></td>
                            <td class="bg-light fw-bold text-muted">Width (mm)</td>
                            <td class="fw-bold text-dark"><?= $activePallet['width'] ? number_format((float)$activePallet['width']) . ' mm' : '-' ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="card-body p-4">
                <?php
                $approvedTotalWgt = 0.0;
                foreach ($activeItems as $item) {
                    $len = (float)($item['actual_length'] ?: $item['length']);
                    $approvedTotalWgt += calcEstWeight($len, (float)$item['width'], (float)($item['std_weight'] ?? 0));
                }
                ?>
                <?php if ($approvedTotalWgt > 0): ?>
                <div class="weight-summary-bar mb-3">
                    <div class="wgt-label"><i class="bi bi-speedometer2"></i> Est. Total Weight</div>
                    <div style="display:flex; align-items:baseline; gap:6px;">
                        <span class="wgt-total"><?= number_format($approvedTotalWgt, 2) ?></span>
                        <span class="wgt-unit">kg</span>
                    </div>
                    <div class="wgt-avg"><?= count($activeItems) ?> roll<?= count($activeItems) != 1 ? 's' : '' ?></div>
                </div>
                <?php endif; ?>

                <p class="text-muted mb-3" style="font-size:13px;">
                    <i class="bi bi-info-circle me-1"></i>
                    QC has approved this pallet. Click <strong>Deliver Pallet</strong> below, or scan any
                    roll on this pallet from anywhere on this page — all <?= count($activeItems) ?> roll(s)
                    will be marked <strong>DELIVERED</strong> together.
                </p>

                <!-- Warehousing Slip Roll Items Table -->
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-hover align-middle text-center mb-0" style="font-size:13px; border-color:#cbd5e1;">
                        <thead class="table-light border-bottom border-secondary" style="font-size:12px;">
                            <tr>
                                <th rowspan="2" class="align-middle" style="width: 25%;">Stock Code :</th>
                                <th rowspan="2" class="align-middle" style="width: 25%;">Lot No.</th>
                                <th colspan="2" class="align-middle">Size</th>
                                <th rowspan="2" class="align-middle" style="width: 8%;">Coils</th>
                                <th rowspan="2" class="align-middle" style="width: 12%;">Roll No.</th>
                                <th rowspan="2" class="align-middle" style="width: 18%;">Nett Wgt (kg)</th>
                            </tr>
                            <tr>
                                <th style="width: 12%;">Length (mtr)</th>
                                <th style="width: 12%;">width (mm)</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activeItems as $item):
                            $itemLen = (float)($item['actual_length'] ?: $item['length']);
                            $itemWgt = calcEstWeight($itemLen, (float)$item['width'], (float)($item['std_weight'] ?? 0));
                            $itemNod = (float)($item['nod_length'] ?? 0);
                            $hasNod  = $itemNod > 0;
                            $netLen  = $itemLen - $itemNod;
                        ?>
                        <tr>
                            <td class="fw-bold text-start ps-3" style="font-family:monospace; font-size:12px;"><?= htmlspecialchars(($item['stock_code'] ?? '') ?: '-') ?></td>
                            <td><?= htmlspecialchars($item['lot_no']) ?> <?= htmlspecialchars($item['coil_no']) ?></td>
                            <td>
                                <?= number_format($itemLen, 1) ?>
                                <?php if ($hasNod): ?>
                                <br><span class="nod-chip" title="Actual <?= number_format($itemLen, 2) ?>m &minus; NOD <?= number_format($itemNod, 2) ?>m = <?= number_format($netLen, 2) ?>m"><i class="bi bi-exclamation-triangle-fill"></i> NOD &minus;<?= number_format($itemNod, 2) ?> &rarr; <?= number_format($netLen, 2) ?>m</span>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format((float)$item['width']) ?></td>
                            <td>1</td>
                            <td class="fw-bold"><?= str_replace('R','R-', htmlspecialchars($item['roll_no'])) ?></td>
                            <td class="fw-bold text-end pe-3 text-primary"><?= $itemWgt > 0 ? number_format($itemWgt, 2) : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card-footer bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex gap-2">
                    <a href="pallet.php" class="btn btn-outline-secondary btn-sm">← Back</a>
                    <a href="print_slip.php?pallet_no=<?= urlencode($activePallet['pallet_no']) ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-printer me-1"></i> Print Slip
                    </a>
                    <form method="post" class="d-inline"
                          onsubmit="return confirm('Return approved pallet ' + currentPalletNo + ' to edit mode for corrections?\n\nThis will reset the pallet to BUILDING state and require re-submitting to QC once corrections are completed.')">
                        <input type="hidden" name="action"    value="reopen_pallet">
                        <input type="hidden" name="pallet_id" value="<?= $activePalletId ?>">
                        <button type="submit" class="btn btn-outline-warning btn-sm fw-bold">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Return to Edit
                        </button>
                    </form>
                </div>

                <form method="post"
                      onsubmit="return confirm('Deliver pallet ' + currentPalletNo + '?\n\nAll <?= count($activeItems) ?> roll(s) will be marked DELIVERED.')">
                    <input type="hidden" name="action"    value="deliver_pallet">
                    <input type="hidden" name="pallet_id" value="<?= $activePalletId ?>">
                    <button type="submit" class="btn btn-success fw-bold">
                        <i class="bi bi-truck me-1"></i> Deliver Pallet
                    </button>
                </form>
            </div>
        </div>

        <?php elseif ($isDelivered): ?>
        <!-- ═══════════════════════════════════════════════════════
             DELIVERED STATE — Historical record of delivered pallet
        ═══════════════════════════════════════════════════════════ -->
        <div class="card shadow-sm border-0 mb-4" style="border-left:4px solid #10b981 !important;">
            <div class="card-header text-white d-flex justify-content-between align-items-center"
                 style="background:#065f46;">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-truck me-1"></i>
                    <strong><?= htmlspecialchars($activePallet['pallet_no']) ?></strong>
                    <span class="badge bg-white text-success ms-1">DELIVERED</span>
                </div>
                <span class="badge bg-white text-success">
                    <?= count($activeItems) ?> roll<?= count($activeItems) != 1 ? 's' : '' ?>
                </span>
            </div>

            <!-- Warehousing Slip Header Grid -->
            <?php if (!empty(trim($activePallet['customer_name'] ?? ''))): ?>
            <div class="table-responsive mb-0 p-3 border-bottom bg-light">
                <table class="table table-sm table-bordered align-middle mb-0" style="font-size:13px; background:#fff; border-color:#cbd5e1;">
                    <tbody>
                        <tr>
                            <td class="bg-light fw-bold text-muted" style="width:15%;">Customer</td>
                            <td class="fw-bold text-dark" style="width:35%;"><?= htmlspecialchars($activePallet['customer_name'] ?: '-') ?></td>
                            <td class="bg-light fw-bold text-muted" style="width:15%;">Date</td>
                            <td class="fw-bold text-dark" style="width:35%;"><?= date('d/m/Y', strtotime($activePallet['created_at'] ?? 'now')) ?></td>
                        </tr>
                        <tr>
                            <td class="bg-light fw-bold text-muted">SOS No.</td>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($activePallet['ref_no'] ?: '-') ?></td>
                            <td class="bg-light fw-bold text-muted">Serial No.</td>
                            <td class="fw-bold text-primary"><?= htmlspecialchars($activePallet['pallet_no'] ?: '-') ?></td>
                        </tr>
                        <tr>
                            <td class="bg-light fw-bold text-muted">Product Type :</td>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($activePallet['product_type'] ?: '-') ?></td>
                            <td class="bg-light fw-bold text-muted">Width (mm)</td>
                            <td class="fw-bold text-dark"><?= $activePallet['width'] ? number_format((float)$activePallet['width']) . ' mm' : '-' ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="card-body p-4">
                <?php
                $deliveredTotalWgt = 0.0;
                foreach ($activeItems as $item) {
                    $len = (float)($item['actual_length'] ?: $item['length']);
                    $deliveredTotalWgt += calcEstWeight($len, (float)$item['width'], (float)($item['std_weight'] ?? 0));
                }
                ?>
                <?php if ($deliveredTotalWgt > 0): ?>
                <div class="weight-summary-bar mb-3">
                    <div class="wgt-label"><i class="bi bi-speedometer2"></i> Est. Total Weight</div>
                    <div style="display:flex; align-items:baseline; gap:6px;">
                        <span class="wgt-total"><?= number_format($deliveredTotalWgt, 2) ?></span>
                        <span class="wgt-unit">kg</span>
                    </div>
                    <div class="wgt-avg"><?= count($activeItems) ?> roll<?= count($activeItems) != 1 ? 's' : '' ?></div>
                </div>
                <?php endif; ?>

                <div class="alert alert-success py-2 mb-3" style="font-size:13px; background:#d1fae5; border-color:#a7f3d0; color:#065f46;">
                    <i class="bi bi-check-circle-fill me-1"></i>
                    This pallet has been delivered.
                </div>

                <!-- Warehousing Slip Roll Items Table -->
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-hover align-middle text-center mb-0" style="font-size:13px; border-color:#cbd5e1;">
                        <thead class="table-light border-bottom border-secondary" style="font-size:12px;">
                            <tr>
                                <th rowspan="2" class="align-middle" style="width: 25%;">Stock Code :</th>
                                <th rowspan="2" class="align-middle" style="width: 25%;">Lot No.</th>
                                <th colspan="2" class="align-middle">Size</th>
                                <th rowspan="2" class="align-middle" style="width: 8%;">Coils</th>
                                <th rowspan="2" class="align-middle" style="width: 12%;">Roll No.</th>
                                <th rowspan="2" class="align-middle" style="width: 18%;">Nett Wgt (kg)</th>
                            </tr>
                            <tr>
                                <th style="width: 12%;">Length (mtr)</th>
                                <th style="width: 12%;">width (mm)</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activeItems as $item):
                            $itemLen = (float)($item['actual_length'] ?: $item['length']);
                            $itemWgt = calcEstWeight($itemLen, (float)$item['width'], (float)($item['std_weight'] ?? 0));
                            $itemNod = (float)($item['nod_length'] ?? 0);
                            $hasNod  = $itemNod > 0;
                            $netLen  = $itemLen - $itemNod;
                        ?>
                        <tr>
                            <td class="fw-bold text-start ps-3" style="font-family:monospace; font-size:12px;"><?= htmlspecialchars(($item['stock_code'] ?? '') ?: '-') ?></td>
                            <td><?= htmlspecialchars($item['lot_no']) ?> <?= htmlspecialchars($item['coil_no']) ?></td>
                            <td>
                                <?= number_format($itemLen, 1) ?>
                                <?php if ($hasNod): ?>
                                <br><span class="nod-chip" title="Actual <?= number_format($itemLen, 2) ?>m &minus; NOD <?= number_format($itemNod, 2) ?>m = <?= number_format($netLen, 2) ?>m"><i class="bi bi-exclamation-triangle-fill"></i> NOD &minus;<?= number_format($itemNod, 2) ?> &rarr; <?= number_format($netLen, 2) ?>m</span>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format((float)$item['width']) ?></td>
                            <td>1</td>
                            <td class="fw-bold"><?= str_replace('R','R-', htmlspecialchars($item['roll_no'])) ?></td>
                            <td class="fw-bold text-end pe-3 text-primary"><?= $itemWgt > 0 ? number_format($itemWgt, 2) : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card-footer bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex gap-2">
                    <a href="pallet.php" class="btn btn-outline-secondary btn-sm">← Back</a>
                    <a href="print_slip.php?pallet_no=<?= urlencode($activePallet['pallet_no']) ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-printer me-1"></i> Print Slip
                    </a>
                    <form method="post" class="d-inline"
                          onsubmit="return confirm('Return delivered pallet ' + currentPalletNo + ' to edit mode for corrections?\n\nThis will reset the pallet to BUILDING state, reset all rolls to IN, and require re-submitting to QC once corrections are completed.')">
                        <input type="hidden" name="action"    value="reopen_pallet">
                        <input type="hidden" name="pallet_id" value="<?= $activePalletId ?>">
                        <button type="submit" class="btn btn-outline-warning btn-sm fw-bold">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Return to Edit
                        </button>
                    </form>
                </div>
                <span class="text-muted small"><i class="bi bi-lock-fill me-1"></i>Read-Only (Delivered)</span>
            </div>
        </div>

        <?php elseif ($activePallet): ?>
        <!-- READ-ONLY FALLBACK -->
        <div class="alert alert-secondary">
            <strong><?= htmlspecialchars($activePallet['pallet_no']) ?></strong> —
            <span class="badge badge-<?= htmlspecialchars($activePallet['status']) ?>">
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

        <!-- Unified pallet list: replaces the old separate
             Rejected / Open Pallets / All Pallets blocks.
             Rendered client-side via ajax=list_pallets so the same
             endpoint serves the All / Open / QC / Closed tabs, search,
             and sort — see loadPalletList() near the bottom of the page. -->
        <div class="card shadow-sm border-0 pallet-list-card">
            <div class="card-header bg-dark text-white py-2 pallet-list-header">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold"><i class="bi bi-boxes me-2"></i>Pallets</span>
                    <span class="badge bg-secondary" id="palletListCount">—</span>
                </div>
                <div class="position-relative mb-2">
                    <i class="bi bi-search position-absolute"
                       style="left:10px; top:50%; transform:translateY(-50%); font-size:12px; color:#adb5bd;"></i>
                    <input type="text" id="palletSearchInput"
                           class="form-control form-control-sm ps-4"
                           placeholder="Type 7 numbers (e.g. 8888888), Pallet No, or Customer…">
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <div class="btn-group btn-group-sm pallet-tab-group flex-grow-1" id="palletTabGroup">
                        <button type="button" class="btn pallet-tab active" data-group="all">
                            All <span class="tab-count" data-count="all"></span>
                        </button>
                        <button type="button" class="btn pallet-tab" data-group="open">
                            Open <span class="tab-count" data-count="open"></span>
                        </button>
                        <button type="button" class="btn pallet-tab" data-group="qc">
                            QC <span class="tab-count" data-count="qc"></span>
                        </button>
                        <button type="button" class="btn pallet-tab" data-group="closed">
                            Closed <span class="tab-count" data-count="closed"></span>
                        </button>
                    </div>
                    <select id="palletSortSelect" class="form-select form-select-sm" style="width:auto;">
                        <option value="id" selected>Pallet No (A–Z)</option>
                        <option value="updated">Recently Updated</option>
                        <option value="capacity">Capacity</option>
                    </select>
                </div>
            </div>
            <div class="pallet-list-scroll" id="palletListScroll">
                <div class="pallet-list-loading" id="palletListLoading">
                    <div class="spinner-border spinner-border-sm me-2"></div>Loading pallets…
                </div>
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
                 placeholder="e.g. 2607185 or 2607185(A)"
                 autocomplete="off" spellcheck="false"
                 style="font-family:monospace;letter-spacing:.4px;">
          <div class="form-text">Just type the digits — <code>SFS-</code> and the dashes are added automatically. Format: <code>SFS-XXXX-XXX</code> or <code>SFS-XXXX-XXX (A)</code></div>
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

<!-- =============================================================
     SUMMARY PALLET MODAL
     Flattened view of every pallet + its nested products (rolls),
     loaded via ajax=summary_pallet and filtered entirely client-side.
   ============================================================= -->
<div class="modal fade" id="summaryPalletModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="background:#0f2744;">
        <h5 class="modal-title text-white">
          <i class="bi bi-table me-2"></i>Summary Pallet
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <!-- Filter controls -->
        <div class="row g-2 mb-3">
          <div class="col-md-3">
            <select id="summaryFilterCategory" class="form-select form-select-sm" onchange="onSummaryCategoryChange()">
              <option value="">All Fields</option>
              <option value="status">Status</option>
              <option value="customer">Customer</option>
              <option value="width">Width</option>
            </select>
          </div>
          <div class="col-md-5">
            <!-- Text input — used for "All Fields" free search, and for Width -->
            <input type="text" id="summaryFilterValueText" class="form-control form-control-sm"
                   placeholder="Search Pallet No, Status, Stock Code, Rolls, Customer, Ref No, Width..."
                   oninput="applySummaryFilter()">
            <!-- Dropdown — used for Status / Customer, populated dynamically with distinct values -->
            <select id="summaryFilterValueSelect" class="form-select form-select-sm d-none" onchange="applySummaryFilter()">
              <option value="">All</option>
            </select>
          </div>
          <div class="col-md-2">
            <button type="button" class="btn btn-outline-secondary btn-sm w-100" onclick="clearSummaryFilter()">
              <i class="bi bi-x-lg me-1"></i>Clear
            </button>
          </div>
          <div class="col-md-2 d-flex align-items-center justify-content-md-end">
            <span class="text-muted small" id="summaryResultCount"></span>
          </div>
        </div>

        <div id="summaryLoading" class="text-center text-muted py-5">
          <div class="spinner-border spinner-border-sm me-2"></div> Loading summary…
        </div>

        <div class="table-responsive d-none" id="summaryTableWrap">
          <table class="table table-sm table-hover table-bordered align-middle text-center mb-0 pallet-table">
            <thead class="table-dark">
              <tr>
                <th>Pallet No</th>
                <th>Status</th>
                <th>Stock Code</th>
                <th>Rolls</th>
                <th>Customer</th>
                <th>Ref No</th>
                <th>Width</th>
              </tr>
            </thead>
            <tbody id="summaryTableBody"></tbody>
          </table>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-success btn-sm" onclick="exportSummaryPallet()">
          <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
        </button>
      </div>
    </div>
  </div>
</div>

<input id="qrScanInput" type="hidden" value="">

<script>
// ─────────────────────────────────────────────────────────────
// CONSTANTS
// ─────────────────────────────────────────────────────────────
const PALLET_ID   = <?= $activePalletId ?: 'null' ?>;
const MAX_ROLLS   = <?= $MAX ?>;
const IS_BUILDING = <?= json_encode($isBuilding) ?>;
let currentPalletNo = <?= json_encode($activePallet['pallet_no'] ?? '') ?>;
let   rollCount = <?= count($activeItems) ?>;

// Guard against overlapping/duplicate scan submissions. While a
// lookup+add round-trip is in flight, ignore any further scans —
// this is the front-line defence against a camera double-decode.
let   isAdding  = false;

// ─────────────────────────────────────────────────────────────
// EST. WEIGHT HELPERS
// ─────────────────────────────────────────────────────────────

/**
 * Calculate est. weight for one roll.
 * Formula: (length_m × width_mm / 1000) × std_weight
 */
function calcWeight(lengthM, widthMm, stdWeight) {
    if (!lengthM || !widthMm || !stdWeight) return 0;
    return (parseFloat(lengthM) * parseFloat(widthMm) / 1000) * parseFloat(stdWeight);
}

/**
 * Recalculate the total weight from all filled slot data-weight attributes
 * and update the weight summary bar.
 */
function recalcTotalWeight() {
    const slots   = document.querySelectorAll('#rollList [data-slot][data-filled="1"]');
    let   total   = 0;
    let   count   = 0;

    slots.forEach(slot => {
        const w = parseFloat(slot.dataset.weight || 0);
        if (w > 0) { total += w; count++; }
    });

    const dispEl = document.getElementById('totalWeightDisplay');
    const unitEl = dispEl ? dispEl.nextElementSibling : null;
    const avgEl  = document.getElementById('avgWeightDisplay');

    if (!dispEl) return;

    if (total > 0) {
        dispEl.textContent = total.toFixed(2);
        if (unitEl) unitEl.textContent = 'kg';
        if (avgEl)  avgEl.textContent  = 'avg ' + (total / slots.length).toFixed(2) + ' kg/roll';
    } else {
        dispEl.textContent = '—';
        if (unitEl) unitEl.textContent = '';
        if (avgEl)  avgEl.textContent  = 'no weight data';
    }
}

// ─────────────────────────────────────────────────────────────
// updateConstraintBadges(product)
// After the FIRST roll seeds the pallet constraints, fill the
// header badges live. This replaces the old location.reload(),
// which created a window where the next scan failed.
// Mirrors the PHP-rendered markup exactly (including the inline
// Customer/Ref No edit controls) so the pencil-edit feature works
// immediately, without needing a page reload first.
// ─────────────────────────────────────────────────────────────
function updateConstraintBadges(p) {
    const header = document.getElementById('constraintHeader');
    if (!header) return;
    header.className = 'table-responsive mb-0 p-3 border-bottom bg-light position-relative';
    const palletNoText = typeof currentPalletNo !== 'undefined' ? currentPalletNo : '';
    const todayStr = new Date().toLocaleDateString('en-GB');

    header.innerHTML = `
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="fw-bold text-secondary" style="font-size:12px;">
                <i class="bi bi-file-text me-1"></i> WAREHOUSING SLIP HEADER
            </span>
            <span class="badge bg-secondary">MS-WH-01(QR)</span>
        </div>

        <div id="constraintDisplayGroup">
            <table class="table table-sm table-bordered align-middle mb-0" style="font-size:13px; background:#fff; border-color:#cbd5e1;">
                <tbody>
                    <tr>
                        <td class="bg-light fw-bold text-muted" style="width:15%;">Customer</td>
                        <td class="fw-bold text-dark" style="width:35%;">
                            <span id="constraintCustomerText">${escHtml(p.customer_name || '-')}</span>
                            <button type="button" class="btn btn-sm btn-link p-0 text-primary ms-1 constraint-edit-btn" id="constraintEditBtn" title="Edit Customer / Ref No" onclick="startEditConstraint()">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                        </td>
                        <td class="bg-light fw-bold text-muted" style="width:15%;">Date</td>
                        <td class="fw-bold text-dark" style="width:35%;">${todayStr}</td>
                    </tr>
                    <tr>
                        <td class="bg-light fw-bold text-muted">SOS No.</td>
                        <td class="fw-bold text-dark"><span id="constraintRefNoText">${escHtml(p.ref_no || '-')}</span></td>
                        <td class="bg-light fw-bold text-muted">Serial No.</td>
                        <td class="fw-bold text-primary">${escHtml(palletNoText)}</td>
                    </tr>
                    <tr>
                        <td class="bg-light fw-bold text-muted">Product Type :</td>
                        <td class="fw-bold text-dark">${escHtml(p.product || '-')}</td>
                        <td class="bg-light fw-bold text-muted">Width (mm)</td>
                        <td class="fw-bold text-dark">${(+p.width).toFixed(0)} mm</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="d-none align-items-center gap-1 flex-wrap mt-2" id="constraintEditForm">
            <input type="text" class="form-control form-control-sm constraint-edit-input" id="constraintCustomerInput"
                   value="${escHtml(p.customer_name || '')}" placeholder="Customer" maxlength="120" autocomplete="off"
                   onkeydown="onConstraintEditKeydown(event)">
            <input type="text" class="form-control form-control-sm constraint-edit-input" id="constraintRefNoInput"
                   value="${escHtml(p.ref_no || '')}" placeholder="Ref No" maxlength="80" autocomplete="off"
                   onkeydown="onConstraintEditKeydown(event)">
            <button type="button" class="btn btn-sm btn-success" id="constraintSaveBtn"
                    title="Save (Enter)" onclick="saveConstraintEdit()">
                <i class="bi bi-check-lg me-1"></i> Save
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger" id="constraintCancelBtn"
                    title="Cancel (Esc)" onclick="cancelEditConstraint()">
                <i class="bi bi-x-lg me-1"></i> Cancel
            </button>
        </div>
        <span class="constraint-edit-error d-none" id="constraintEditError"></span>
    `;
    currentConstraintCustomer = p.customer_name || '';
    currentConstraintRefNo    = p.ref_no || '';
}

// ─────────────────────────────────────────────────────────────
// refreshConstraintRefBadge(refNo)
// For rolls AFTER the first: the pallet's Ref No can silently
// auto-upgrade server-side (STOCK → a real SO number, the first
// time a non-STOCK roll is added — see PalletManager::addRollToPallet).
// This just re-syncs the visible badge + edit-form state so the UI
// reflects that without a page reload.
// ─────────────────────────────────────────────────────────────
function refreshConstraintRefBadge(refNo) {
    const el = document.getElementById('constraintRefNoText');
    if (el && refNo !== undefined && refNo !== currentConstraintRefNo) {
        el.textContent = refNo;
        currentConstraintRefNo = refNo;
    }
}

// ─────────────────────────────────────────────────────────────
// QR / INPUT PARSING
// Accepts TWO formats:
//   A) KEY=value pairs (camera QR / hardware scanner):
//        LOT=826277;COIL=FK-1;ROLL=R1
//   B) Plain space-separated values (typed by hand):
//        826277 FK-1 R1
// ─────────────────────────────────────────────────────────────
function parseQR(raw) {
    raw = (raw || '').trim();

    // ── Format A: contains '=' → KEY=value;KEY=value ──────────
    if (raw.indexOf('=') > -1) {
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

    // ── Format B: no '=' → split on whitespace ────────────────
    //    First token = Lot, second = Coil, third = Roll.
    //    Extra tokens are ignored.
    const tokens = raw.split(/\s+/).filter(Boolean);
    return {
        lot:  tokens[0] || '',
        coil: tokens[1] || '',
        roll: tokens[2] || '',
    };
}

async function processQR(raw) {
    const { lot, coil, roll } = parseQR(raw);
    if (!lot || !coil) { showFeedback('Could not parse input: ' + escHtml(raw), false); return; }
    await lookupAndAdd(lot, coil, roll);
}

// ─────────────────────────────────────────────────────────────
// METHOD 2 — SCAN TO DELIVER
// Routes a scan (camera or hardware gun) to the right behaviour:
//   • Actively building a pallet → add the roll to it (existing
//     Method-building flow, processQR/lookupAndAdd above).
//   • Anywhere else on this page → resolve the scanned roll to
//     its pallet and, if approved, deliver the whole pallet.
// ─────────────────────────────────────────────────────────────
function routeScan(raw) {
    if (PALLET_ID && IS_BUILDING) {
        processQR(raw);
    } else {
        processDeliveryScan(raw);
    }
}

let isDelivering = false;

async function processDeliveryScan(raw) {
    raw = (raw || '').trim();
    if (!raw) return;

    // Drop overlapping scans (e.g. camera double-decode, or a
    // hardware scanner burst landing while a request is in flight).
    if (isDelivering) return;
    isDelivering = true;

    showDeliverFeedback('Looking up pallet…', null);
    try {
        const fd = new FormData();
        fd.append('ajax', 'deliver_by_scan');
        fd.append('raw', raw);
        if (typeof PALLET_ID !== 'undefined' && PALLET_ID) {
            fd.append('active_pallet_id', PALLET_ID);
        }

        let res;
        try {
            const resp = await fetch('pallet.php', { method: 'POST', body: fd });
            const text = await resp.text();
            try {
                res = JSON.parse(text);
            } catch (jsonErr) {
                console.error("Server non-JSON response:", text);
                showDeliverFeedback('Server error: ' + (text.substring(0, 100) || 'Invalid response'), false);
                return;
            }
        } catch (fetchErr) {
            showDeliverFeedback('Network error while delivering.', false);
            return;
        }

        if (res.ok && res.pallet_id) {
            if (res.prev_delivered && res.prev_pallet_no) {
                showDeliverFeedback(`✓ Previous approved Pallet ${escHtml(res.prev_pallet_no)} delivered! Opening Pallet ${escHtml(res.pallet_no)}...`, true);
            } else {
                showDeliverFeedback(`✓ Found Pallet ${escHtml(res.pallet_no || '')}. Opening pallet information...`, true);
            }
            setTimeout(() => {
                let url = `pallet.php?pallet_id=${res.pallet_id}`;
                if (res.prev_delivered && res.prev_pallet_no) {
                    url += `&success=prev_delivered&prev_no=${encodeURIComponent(res.prev_pallet_no)}`;
                }
                window.location.href = url;
            }, 350);
        } else {
            showDeliverFeedback(res.msg || 'Pallet not found for that search query.', false);
        }
    } finally {
        isDelivering = false;
    }
}

function triggerDeliverScan() {
    const el = document.getElementById('deliverScanInput');
    if (!el) return;
    const val = el.value.trim();
    if (!val) { showDeliverFeedback('Scan or type a roll first, e.g. 826277 FK-1 R1', false); el.focus(); return; }
    processDeliveryScan(val);
    el.value = '';
    el.focus();
}

function showDeliverFeedback(msg, ok) {
    const el = document.getElementById('deliverScanFeedback');
    if (!el) return;
    const cls = ok === true ? 'text-success fw-bold' : (ok === false ? 'text-danger fw-bold' : 'text-muted');
    el.innerHTML = `<span class="${cls}">${escHtml(msg)}</span>`;
}

document.getElementById('deliverScanInput')?.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        triggerDeliverScan();
    }
});

// Hardware scanner guns behave as a keyboard — auto-focus the
// delivery input on load (unless a pallet is actively being built,
// where manCombined should keep focus instead) so a gun scan lands
// straight in the box without the operator clicking into it first.
window.addEventListener('load', function () {
    if (!IS_BUILDING) {
        document.getElementById('deliverScanInput')?.focus();
    }
});

// ─────────────────────────────────────────────────────────────
// MANUAL ENTRY — single combined box (the sole manual entry
// method now — replaces the old 3-box Lot/Coil/Roll row)
//   e.g. "826277 FK-1 R1"  or  "LOT=826277;COIL=FK-1;ROLL=R1"
// ─────────────────────────────────────────────────────────────
async function combinedLookup() {
    const el  = document.getElementById('manCombined');
    const val = el.value.trim();
    if (!val) { showFeedback('Type the roll, e.g. 826277 FK-1 R1', false); el.focus(); return; }
    await processQR(val);
    el.value = '';
    el.focus();
}

// ─────────────────────────────────────────────────────────────
// LOOKUP + ADD
// ─────────────────────────────────────────────────────────────
async function lookupAndAdd(lot, coil, roll) {
    if (!PALLET_ID) return;

    // Drop overlapping scans (e.g. camera double-decode) while a
    // previous add is still being processed.
    if (isAdding) return;

    if (rollCount >= MAX_ROLLS) {
        showFeedback(`Pallet is full (${MAX_ROLLS}/${MAX_ROLLS}).`, false);
        return;
    }

    isAdding = true;
    try {
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

        if (p.is_voided == 1) { showFeedback('This roll has been voided.', false); return; }
        if (p.stock_counted != 1) { showFeedback(`Roll ${lot} ${coil} ${roll} — actual length not saved yet.`, false); return; }

        // ── Already on a DIFFERENT pallet → real error ───────────
        if (p.pallet_id && p.pallet_id != PALLET_ID) {
            showFeedback(`Already on pallet ${escHtml(p.pallet_no)}.`, false);
            return;
        }

        // ── Already on THIS pallet → duplicate scan of a roll we
        //    just added. Silently ignore so a camera double-decode
        //    cannot trigger a server "already on pallet" error or
        //    jam the UI. It's already shown in a slot. ───────────
        if (p.pallet_id && p.pallet_id == PALLET_ID) {
            return;
        }

        // ── Belt-and-suspenders: if a slot already shows this
        //    product id, treat it as a duplicate too (covers the
        //    race where the lookup hasn't caught up to the just-
        //    added row yet). ───────────────────────────────────
        if (document.querySelector(`#rollList [data-product-id="${p.id}"]`)) {
            return;
        }

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

        rollCount = ad.roll_count;
        fillSlot(ad.seq, p);
        updateProgress(rollCount);

        // Clear the manual entry field after ANY successful add
        const manCombinedEl = document.getElementById('manCombined');
        if (manCombinedEl) manCombinedEl.value = '';

        showFeedback(
            `✓ Added: ${escHtml(lot)} ${escHtml(coil)} – R-${escHtml(roll)} (slot ${ad.seq})`,
            true
        );

        // First roll seeds the pallet constraints. Update the badges
        // live instead of reloading the page — the old reload created
        // a window where scanning the 2nd roll failed with a stale
        // "already on pallet" error and forced a manual refresh.
        if (ad.seq === 1) {
            updateConstraintBadges({
                customer_name: ad.customer_name,
                ref_no:        ad.ref_no,
                product:       ad.product_type,
                width:         ad.width,
            });
        } else {
            // Later rolls don't reseed the header, but the pallet's Ref
            // No can auto-upgrade server-side (STOCK → a real SO number
            // the first time a non-STOCK roll is added) — re-sync the
            // badge text in that case.
            refreshConstraintRefBadge(ad.ref_no);
        }
    } finally {
        // Always release the lock so the next legitimate scan works.
        isAdding = false;
    }
}

// ─────────────────────────────────────────────────────────────
// fillSlot(seq, product)
// Fills an empty slot IN-PLACE; also renders weight chip.
// ─────────────────────────────────────────────────────────────
function fillSlot(seq, p) {
    const slotEl = document.getElementById('slot' + seq);
    if (!slotEl) return;

    const len       = parseFloat(p.actual_length) > 0 ? parseFloat(p.actual_length) : parseFloat(p.length);
    const stdWeight = parseFloat(p.std_weight) || 0;
    const wgt       = calcWeight(len, p.width, stdWeight);
    const wgtStr    = wgt > 0 ? wgt.toFixed(2) : '-';

    const nod       = parseFloat(p.nod_length) || 0;
    const hasNod    = nod > 0;
    const netLen    = len - nod;
    const nodChip   = hasNod
        ? `<br><span class="nod-chip" title="Actual ${len.toFixed(2)}m − NOD ${nod.toFixed(2)}m = ${netLen.toFixed(2)}m">
               <i class="bi bi-exclamation-triangle-fill"></i> NOD −${nod.toFixed(2)} → ${netLen.toFixed(2)}m
           </span>`
        : '';

    slotEl.classList.remove('table-light', 'text-muted');
    slotEl.setAttribute('data-filled', '1');
    slotEl.setAttribute('data-product-id', p.id);
    slotEl.setAttribute('data-weight', wgt.toFixed(4));

    slotEl.innerHTML = `
        <td class="fw-bold text-start ps-3" style="font-family:monospace; font-size:12px;">
            ${p.stock_code ? escHtml(p.stock_code) : '-'}
        </td>
        <td>
            ${escHtml(p.lot_no)} ${escHtml(p.coil_no)}
        </td>
        <td>${len.toFixed(1)}${nodChip}</td>
        <td>${(+p.width).toFixed(0)}</td>
        <td>1</td>
        <td class="fw-bold">${escHtml(p.roll_no.replace(/^R/, 'R-'))}</td>
        <td class="fw-bold text-end pe-3 text-primary">${wgtStr}</td>
        <td>
            <button type="button"
                    class="btn btn-outline-danger btn-sm py-0 px-2"
                    title="Remove this roll from the pallet"
                    data-product-id="${p.id}"
                    onclick="removeRoll(${PALLET_ID}, ${p.id}, ${seq}, this)">
                <i class="bi bi-x-lg"></i>
            </button>
        </td>
    `;

    slotEl.classList.add('scan-flash');
    slotEl.addEventListener('animationend', () => slotEl.classList.remove('scan-flash'), { once: true });
    recalcTotalWeight();
}

function clearSlot(seq) {
    const slotEl = document.getElementById('slot' + seq);
    if (!slotEl) return;
    slotEl.classList.add('table-light', 'text-muted');
    slotEl.setAttribute('data-filled', '0');
    slotEl.removeAttribute('data-product-id');
    slotEl.setAttribute('data-weight', '0');
    slotEl.innerHTML = `
        <td class="text-start ps-3 text-muted">&mdash; Empty Slot ${seq} &mdash;</td>
        <td>&mdash;</td>
        <td>&mdash;</td>
        <td>&mdash;</td>
        <td>&mdash;</td>
        <td>&mdash;</td>
        <td>&mdash;</td>
        <td>&mdash;</td>
    `;
}

// ─────────────────────────────────────────────────────────────
// resequenceSlots()
// ─────────────────────────────────────────────────────────────
function resequenceSlots() {
    const allSlots = Array.from(
        document.querySelectorAll('#rollList [data-slot]')
    ).sort((a, b) => +a.dataset.slot - +b.dataset.slot);

    let filledCount = 0;
    allSlots.forEach(slotEl => {
        const slotNo = +slotEl.dataset.slot;
        if (slotEl.dataset.filled === '1') {
            filledCount++;
            const newSeq = filledCount;
            const bubble = slotEl.querySelector('.roll-seq');
            if (bubble) bubble.textContent = newSeq;
            const removeBtn = slotEl.querySelector('button[data-product-id]');
            if (removeBtn) {
                const pid = removeBtn.getAttribute('data-product-id');
                removeBtn.setAttribute('onclick', `removeRoll(${PALLET_ID}, ${pid}, ${newSeq}, this)`);
            }
        } else {
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
// Enter key on the combined box → Add
// ─────────────────────────────────────────────────────────────
document.getElementById('manCombined')?.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); combinedLookup(); }
});

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

// ── Pallet No auto-formatting ───────────────────────────────────
// User only types digits (and optionally letters for a suffix like
// "A" or "AB"); we build "SFS-XXXX-XXX" or "SFS-XXXX-XXX (A)" for
// them in real time. Always rebuilding from scratch (rather than
// patching the string) means stray characters can never sneak in
// and break the format, no matter what the user types or pastes.
const PALLET_NO_PREFIX = 'SFS-';

function formatPalletNo(raw) {
    if (!raw) return '';
    const hasTrailingSpace = raw.endsWith(' ') || raw.endsWith(' (');
    const cleaned = raw.replace(/^\s*SFS-?\s*/i, '');
    const digits  = cleaned.replace(/[^0-9]/g, '').slice(0, 7);   // XXXX + XXX
    const letters = cleaned.replace(/[^A-Za-z]/g, '').toUpperCase().slice(0, 3); // optional (B)/(BN)/(A)

    if (!digits && !letters) return raw;

    let out = PALLET_NO_PREFIX + (digits.length <= 4 ? digits : digits.slice(0, 4) + '-' + digits.slice(4));
    if (letters) {
        out += ` (${letters})`;
    } else if (hasTrailingSpace && digits.length === 7) {
        out += ' ';
    }
    return out;
}

document.getElementById('palletNoInput')?.addEventListener('input', function () {
    clearTimeout(palletNoTimer);
    palletNoValid = false;
    document.getElementById('createPalletBtn').disabled = true;

    const raw      = this.value;
    const selStart = this.selectionStart;

    // Preserve cursor position across reformatting: count how many real
    // "content" characters (digits/letters typed by the user, not the
    // literal SFS-/-/space/() the mask inserts) sit before the cursor,
    // ignoring the fixed "SFS-" prefix — this is what makes backspace
    // and mid-string edits land in the right place instead of always
    // jumping to the end of the field.
    const prefixMatch     = raw.match(/^\s*SFS-?\s*/i);
    const prefixLen       = prefixMatch ? prefixMatch[0].length : 0;
    const afterPrefixCur  = Math.max(0, selStart - prefixLen);
    const contentBefore   = raw.slice(prefixLen).slice(0, afterPrefixCur).replace(/[^0-9A-Za-z]/g, '').length;

    const formatted = formatPalletNo(raw);
    this.value = formatted;

    if (!formatted) {
        document.getElementById('palletNoFeedback').innerHTML = '';
        this.classList.remove('is-valid', 'is-invalid');
        return;
    }

    let seen = 0, newPos = formatted.length;
    for (let i = PALLET_NO_PREFIX.length; i < formatted.length; i++) {
        if (/[0-9A-Za-z]/.test(formatted[i])) seen++;
        if (seen === contentBefore) { newPos = i + 1; break; }
    }
    if (contentBefore === 0) newPos = PALLET_NO_PREFIX.length;
    this.setSelectionRange(newPos, newPos);

    const val = formatted.trim();
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

// ─────────────────────────────────────────────────────────────
// SUMMARY PALLET
// Loads a flattened pallet + nested-product dataset once per modal
// open, then filters/renders entirely client-side (no re-fetch per
// keystroke).
// ─────────────────────────────────────────────────────────────
let summaryData    = [];
let summaryLoaded  = false;

const SUMMARY_STATUS_BADGE = {
    building:   'badge-building',
    pending_qc: 'badge-pending_qc',
    approved:   'badge-approved',
    rejected:   'badge-rejected',
    delivered:  'badge-delivered',
};

function summaryStatusLabel(status) {
    return String(status ?? '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

async function loadSummaryPallet() {
    // Reset filter UI each time the modal is opened
    document.getElementById('summaryFilterCategory').value = '';
    document.getElementById('summaryFilterValueText').value = '';
    document.getElementById('summaryFilterValueText').classList.remove('d-none');
    document.getElementById('summaryFilterValueSelect').classList.add('d-none');

    document.getElementById('summaryLoading').classList.remove('d-none');
    document.getElementById('summaryTableWrap').classList.add('d-none');

    try {
        const res  = await fetch('pallet.php?ajax=summary_pallet');
        const data = await res.json();
        if (!data.ok) throw new Error(data.msg || 'Failed to load summary.');
        summaryData   = data.rows;
        summaryLoaded = true;
        renderSummaryTable(summaryData);
    } catch (e) {
        document.getElementById('summaryLoading').innerHTML =
            `<div class="alert alert-danger py-2 mb-0">Failed to load summary: ${escHtml(e.message)}</div>`;
        return;
    }

    document.getElementById('summaryLoading').classList.add('d-none');
    document.getElementById('summaryTableWrap').classList.remove('d-none');
}

function renderSummaryTable(rows) {
    const tbody = document.getElementById('summaryTableBody');
    const countEl = document.getElementById('summaryResultCount');

    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="7" class="py-4 text-muted">No matching records.</td></tr>`;
        countEl.textContent = '0 rows';
        return;
    }

    tbody.innerHTML = rows.map(r => {
        const badgeClass = SUMMARY_STATUS_BADGE[r.status] || 'badge-building';
        const rollsCell  = r.roll_no
            ? `<span class="fw-bold">${escHtml(r.lot_coil)}</span> &ndash; ${escHtml(r.roll_no)}`
            : '<span class="text-muted">&mdash; no rolls &mdash;</span>';
        return `
            <tr>
                <td class="fw-bold">${r.pallet_id ? `<a href="pallet.php?pallet_id=${r.pallet_id}" class="text-decoration-none">${escHtml(r.pallet_no)}</a>` : escHtml(r.pallet_no)}</td>
                <td><span class="badge ${badgeClass}">${escHtml(summaryStatusLabel(r.status))}</span></td>
                <td style="font-family:monospace;font-size:12px;">${r.stock_code ? escHtml(r.stock_code) : '<span class="text-muted">&mdash;</span>'}</td>
                <td>${rollsCell}</td>
                <td>${r.customer ? escHtml(r.customer) : '<span class="text-muted">&mdash;</span>'}</td>
                <td>${r.ref_no ? escHtml(r.ref_no) : '<span class="text-muted">&mdash;</span>'}</td>
                <td>${r.width !== null ? (+r.width).toFixed(0) + ' mm' : '<span class="text-muted">&mdash;</span>'}</td>
            </tr>
        `;
    }).join('');

    countEl.textContent = rows.length + (rows.length === 1 ? ' row' : ' rows');
}

// When the filter category changes, swap between the free-text
// search box (All Fields / Width) and a dropdown of distinct values
// (Status / Customer) so users pick from what's actually in the data.
function onSummaryCategoryChange() {
    const cat        = document.getElementById('summaryFilterCategory').value;
    const textInput  = document.getElementById('summaryFilterValueText');
    const selectInput = document.getElementById('summaryFilterValueSelect');

    if (cat === 'status' || cat === 'customer') {
        const distinct = [...new Set(
            summaryData
                .map(r => cat === 'status' ? r.status : r.customer)
                .filter(v => v !== null && v !== '')
        )].sort();

        selectInput.innerHTML = '<option value="">All</option>' +
            distinct.map(v => `<option value="${escHtml(v)}">${escHtml(cat === 'status' ? summaryStatusLabel(v) : v)}</option>`).join('');

        textInput.classList.add('d-none');
        selectInput.classList.remove('d-none');
    } else {
        // "All Fields" and "Width" both use free-text search
        textInput.value = '';
        textInput.placeholder = (cat === 'width')
            ? 'Type a width in mm, e.g. 309'
            : 'Search Pallet No, Status, Rolls, Customer, Ref No, Width...';
        textInput.classList.remove('d-none');
        selectInput.classList.add('d-none');
    }

    applySummaryFilter();
}

function applySummaryFilter() {
    const cat = document.getElementById('summaryFilterCategory').value;
    let rows = summaryData;

    if (cat === 'status' || cat === 'customer') {
        const val = document.getElementById('summaryFilterValueSelect').value;
        if (val !== '') {
            rows = rows.filter(r => String(r[cat] ?? '') === val);
        }
    } else if (cat === 'width') {
        const val = document.getElementById('summaryFilterValueText').value.trim();
        if (val !== '') {
            rows = rows.filter(r => r.width !== null && String(Math.round(r.width)).includes(val));
        }
    } else {
        // All Fields — free text across everything visible in the table
        const val = document.getElementById('summaryFilterValueText').value.trim().toLowerCase();
        if (val !== '') {
            rows = rows.filter(r => [
                r.pallet_no, r.status, r.stock_code, r.lot_coil, r.roll_no,
                r.customer, r.ref_no, r.width
            ].some(v => v !== null && String(v).toLowerCase().includes(val)));
        }
    }

    renderSummaryTable(rows);
}

function clearSummaryFilter() {
    document.getElementById('summaryFilterCategory').value = '';
    document.getElementById('summaryFilterValueText').value = '';
    document.getElementById('summaryFilterValueText').classList.remove('d-none');
    document.getElementById('summaryFilterValueSelect').classList.add('d-none');
    renderSummaryTable(summaryData);
}

// ─────────────────────────────────────────────────────────────
// INLINE PALLET NO RENAME (header banner)
// ─────────────────────────────────────────────────────────────
let palletRenameSaving = false;

function startEditPalletRename() {
    document.getElementById('palletRenameDisplay').classList.add('d-none');
    document.getElementById('palletRenameEditBtn').classList.add('d-none');
    const form = document.getElementById('palletRenameForm');
    form.classList.remove('d-none');
    form.classList.add('d-flex');
    hidePalletRenameError();

    const input = document.getElementById('palletRenameInput');
    input.value = currentPalletNo;
    input.focus();
    input.select();
}

function cancelEditPalletRename() {
    const form = document.getElementById('palletRenameForm');
    form.classList.add('d-none');
    form.classList.remove('d-flex');
    document.getElementById('palletRenameDisplay').classList.remove('d-none');
    document.getElementById('palletRenameEditBtn').classList.remove('d-none');
    document.getElementById('palletRenameInput').value = currentPalletNo;
    hidePalletRenameError();
}

function onPalletRenameKeydown(event) {
    if (event.key === 'Enter')  { event.preventDefault(); savePalletRename(); }
    if (event.key === 'Escape') { event.preventDefault(); cancelEditPalletRename(); }
}

function showPalletRenameError(msg) {
    const el = document.getElementById('palletRenameError');
    el.textContent = msg;
    el.classList.remove('d-none');
}
function hidePalletRenameError() {
    document.getElementById('palletRenameError').classList.add('d-none');
}

async function savePalletRename() {
    if (palletRenameSaving) return;

    const input    = document.getElementById('palletRenameInput');
    const newValue = input.value.trim();

    if (newValue === '') {
        showPalletRenameError('Pallet No cannot be empty.');
        input.focus();
        return;
    }
    if (newValue === currentPalletNo) {
        cancelEditPalletRename();
        return;
    }

    palletRenameSaving = true;
    document.getElementById('palletRenameSaveBtn').disabled = true;
    document.getElementById('palletRenameCancelBtn').disabled = true;
    hidePalletRenameError();

    try {
        const res = await fetch('pallet.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                ajax:      'rename_pallet',
                pallet_id: PALLET_ID,
                pallet_no: newValue,
            }),
        });
        const data = await res.json();

        if (!data.ok) {
            showPalletRenameError(data.msg || 'Could not rename pallet.');
            input.focus();
            return;
        }

        // Success — update the header in place and drop out of edit mode.
        currentPalletNo = data.pallet_no;
        document.getElementById('palletRenameDisplay').textContent = currentPalletNo;
        cancelEditPalletRename();

        // Refresh the right-side Pallets list so the renamed card shows
        // immediately, without a full page reload.
        if (typeof loadPalletList === 'function') loadPalletList();

    } catch (e) {
        showPalletRenameError('Network error — please try again.');
        input.focus();
    } finally {
        palletRenameSaving = false;
        document.getElementById('palletRenameSaveBtn').disabled = false;
        document.getElementById('palletRenameCancelBtn').disabled = false;
    }
}

// ─────────────────────────────────────────────────────────────
// INLINE CUSTOMER / REF NO EDIT (constraint badge header)
// Same pattern as the pallet-no rename above: click pencil → edit
// two fields → save via AJAX → PalletManager::updatePalletCustomerRef()
// propagates the new values to every roll (slitting_product) on
// this pallet so nothing drifts out of sync.
// ─────────────────────────────────────────────────────────────
let constraintEditSaving = false;
let currentConstraintCustomer = <?= json_encode($activePallet['customer_name'] ?? '') ?>;
let currentConstraintRefNo    = <?= json_encode($activePallet['ref_no'] ?? '') ?>;

function startEditConstraint() {
    document.getElementById('constraintDisplayGroup').classList.add('d-none');
    const form = document.getElementById('constraintEditForm');
    form.classList.remove('d-none');
    form.classList.add('d-flex');
    document.getElementById('constraintHintText').classList.add('d-none');
    hideConstraintEditError();

    const customerInput = document.getElementById('constraintCustomerInput');
    const refNoInput     = document.getElementById('constraintRefNoInput');
    customerInput.value = currentConstraintCustomer;
    refNoInput.value    = currentConstraintRefNo;
    customerInput.focus();
    customerInput.select();
}

function cancelEditConstraint() {
    const form = document.getElementById('constraintEditForm');
    form.classList.add('d-none');
    form.classList.remove('d-flex');
    document.getElementById('constraintDisplayGroup').classList.remove('d-none');
    document.getElementById('constraintHintText').classList.remove('d-none');
    document.getElementById('constraintCustomerInput').value = currentConstraintCustomer;
    document.getElementById('constraintRefNoInput').value    = currentConstraintRefNo;
    hideConstraintEditError();
}

function onConstraintEditKeydown(event) {
    if (event.key === 'Enter')  { event.preventDefault(); saveConstraintEdit(); }
    if (event.key === 'Escape') { event.preventDefault(); cancelEditConstraint(); }
}

function showConstraintEditError(msg) {
    const el = document.getElementById('constraintEditError');
    el.textContent = msg;
    el.classList.remove('d-none');
}
function hideConstraintEditError() {
    document.getElementById('constraintEditError').classList.add('d-none');
}

async function saveConstraintEdit() {
    if (constraintEditSaving) return;

    const customerInput = document.getElementById('constraintCustomerInput');
    const refNoInput     = document.getElementById('constraintRefNoInput');
    const newCustomer    = customerInput.value.trim();
    const newRefNo       = refNoInput.value.trim();

    if (newCustomer === '') {
        showConstraintEditError('Customer cannot be empty.');
        customerInput.focus();
        return;
    }
    if (newRefNo === '') {
        showConstraintEditError('Ref No cannot be empty.');
        refNoInput.focus();
        return;
    }
    if (newCustomer === currentConstraintCustomer && newRefNo === currentConstraintRefNo) {
        cancelEditConstraint();
        return;
    }

    constraintEditSaving = true;
    document.getElementById('constraintSaveBtn').disabled = true;
    document.getElementById('constraintCancelBtn').disabled = true;
    hideConstraintEditError();

    try {
        const res = await fetch('pallet.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                ajax:          'update_customer_ref',
                pallet_id:     PALLET_ID,
                customer_name: newCustomer,
                ref_no:        newRefNo,
            }),
        });
        const data = await res.json();

        if (!data.ok) {
            showConstraintEditError(data.msg || 'Could not update Customer / Ref No.');
            customerInput.focus();
            return;
        }

        // Success — update the badges in place and drop out of edit mode.
        currentConstraintCustomer = data.customer_name;
        currentConstraintRefNo    = data.ref_no;
        document.getElementById('constraintCustomerText').textContent = currentConstraintCustomer;
        document.getElementById('constraintRefNoText').textContent    = currentConstraintRefNo;
        cancelEditConstraint();

        // Refresh the right-side Pallets list so the updated card shows
        // immediately, without a full page reload.
        if (typeof loadPalletList === 'function') loadPalletList();

    } catch (e) {
        showConstraintEditError('Network error — please try again.');
        customerInput.focus();
    } finally {
        constraintEditSaving = false;
        document.getElementById('constraintSaveBtn').disabled = false;
        document.getElementById('constraintCancelBtn').disabled = false;
    }
}

// ─────────────────────────────────────────────────────────────
// UNIFIED PALLET LIST (redesigned sidebar)
// Fetches the search-filtered set once from ajax=list_pallets,
// then filters by tab and re-sorts entirely client-side — tab
// clicks are instant, only the search box and sort dropdown
// trigger a re-fetch. Reuses SUMMARY_STATUS_BADGE / summaryStatusLabel
// so status colors/labels stay identical to the Summary Pallet modal.
// ─────────────────────────────────────────────────────────────
let palletListData  = [];
let palletActiveTab = 'all';
let palletSearchDebounce = null;

async function loadPalletList() {
    const q    = document.getElementById('palletSearchInput').value.trim();
    const sort = document.getElementById('palletSortSelect').value;

    document.getElementById('palletListScroll').innerHTML =
        `<div class="pallet-list-loading"><div class="spinner-border spinner-border-sm me-2"></div>Loading pallets…</div>`;

    try {
        const params = new URLSearchParams({ ajax: 'list_pallets', group: 'all', q, sort });
        const res  = await fetch('pallet.php?' + params.toString());
        const data = await res.json();
        if (!data.ok) throw new Error(data.msg || 'Failed to load pallets.');
        palletListData = data.rows;
    } catch (e) {
        document.getElementById('palletListScroll').innerHTML =
            `<div class="alert alert-danger py-2 m-2 mb-0">Failed to load pallets: ${escHtml(e.message)}</div>`;
        return;
    }

    renderPalletList();
}

function renderPalletList() {
    const counts = { all: palletListData.length, open: 0, qc: 0, closed: 0 };
    palletListData.forEach(p => { if (counts[p.status_group] !== undefined) counts[p.status_group]++; });
    document.querySelectorAll('.tab-count').forEach(el => {
        el.textContent = counts[el.dataset.count] ?? 0;
    });

    const rows = palletActiveTab === 'all'
        ? [...palletListData]
        : palletListData.filter(p => p.status_group === palletActiveTab);

    // Pin the pallet the user currently has open to the very top of the
    // list, regardless of sort order, so it's always easy to find.
    if (PALLET_ID) {
        const activeIdx = rows.findIndex(p => Number(p.id) === Number(PALLET_ID));
        if (activeIdx > 0) {
            const [activeRow] = rows.splice(activeIdx, 1);
            rows.unshift(activeRow);
        }
    }

    document.getElementById('palletListCount').textContent =
        rows.length + (rows.length === 1 ? ' pallet' : ' pallets');

    const scroll = document.getElementById('palletListScroll');

    if (!rows.length) {
        scroll.innerHTML = `<div class="pallet-list-empty">No pallets match your filters.</div>`;
        return;
    }

    scroll.innerHTML = rows.map(p => {
        const badgeClass = SUMMARY_STATUS_BADGE[p.status] || 'badge-building';
        const isActive   = PALLET_ID && Number(p.id) === Number(PALLET_ID);
        const pct        = Math.min(100, Math.round((p.roll_count / p.max_rolls) * 100));
        return `
            <a href="pallet.php?pallet_id=${p.id}"
               class="pallet-card border-${escHtml(p.status)} ${isActive ? 'active' : ''}">
                <div class="pallet-card-top">
                    <div>
                        <div class="pallet-card-id">
                            ${escHtml(p.pallet_no)}
                            ${isActive ? '<span class="pallet-card-open-pill"><i class="bi bi-eye-fill"></i> Open</span>' : ''}
                        </div>
                        ${p.lot_nos ? `<div class="pallet-card-lot">${escHtml(p.lot_nos)}</div>` : ''}
                    </div>
                    <span class="badge ${badgeClass}">${escHtml(summaryStatusLabel(p.status))}</span>
                </div>
                <div class="pallet-card-customer">${escHtml(p.customer_name || 'No constraint set yet')}</div>
                <div class="d-flex align-items-center gap-2">
                    <div class="pallet-progress flex-grow-1">
                        <div class="pallet-progress-bar" style="width:${pct}%;"></div>
                    </div>
                    <span class="pallet-card-rolls">${p.roll_count}/${p.max_rolls}</span>
                </div>
            </a>
        `;
    }).join('');
}

document.getElementById('palletTabGroup').addEventListener('click', e => {
    const btn = e.target.closest('.pallet-tab');
    if (!btn) return;
    document.querySelectorAll('.pallet-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    palletActiveTab = btn.dataset.group;
    renderPalletList();
});

document.getElementById('palletSearchInput')?.addEventListener('input', function() {
    let val = this.value;
    let cleaned = val.replace(/^\s*SFS-?\s*/i, '');
    let digits = cleaned.replace(/[^0-9]/g, '');
    if (digits.length >= 4 && !val.endsWith(' ')) {
        let formatted = formatPalletNo(val);
        if (formatted && formatted !== val) {
            this.value = formatted;
        }
    }
    clearTimeout(palletSearchDebounce);
    palletSearchDebounce = setTimeout(loadPalletList, 250);
});

document.getElementById('palletSortSelect').addEventListener('change', loadPalletList);

loadPalletList();

function exportSummaryPallet() {
    const cat = document.getElementById('summaryFilterCategory').value;
    let val = '';

    if (cat === 'status' || cat === 'customer') {
        val = document.getElementById('summaryFilterValueSelect').value;
    } else {
        val = document.getElementById('summaryFilterValueText').value.trim();
    }

    const params = new URLSearchParams({ export: 'summary_pallet' });
    if (cat) params.set('cat', cat);
    if (val) params.set('val', val);

    // Plain navigation — the server responds with Content-Disposition:
    // attachment, so this triggers a download without leaving the page.
    window.location.href = 'pallet.php?' + params.toString();
}


</script>

<!-- Cache-busted (?v=8) so the browser always loads the latest scanner.
     Always initialized now (not gated on PALLET_ID) so Method 2
     "Scan to Deliver" works from the pallet list view too — routeScan()
     decides whether a scan adds a roll (mid-build) or delivers a
     pallet (everywhere else). -->
<script src="camera_scanner.js?v=8"></script>
<script>
initCameraScanner({
    onScan: function(decodedText) {
        routeScan(decodedText);
    }
});
</script>

<?php include 'footer.php'; ?>
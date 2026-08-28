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

// Auto-create operators table if not exists
$conn->query("
    CREATE TABLE IF NOT EXISTS operators (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$activeOperatorSession = $_SESSION['active_operator'] ?? '';
$actor        = !empty($activeOperatorSession) ? $activeOperatorSession : ($_SESSION['role'] ?? 'system');
$pm           = new PalletManager($conn, $actor);
$performed_by = $actor;

// Fetch operators
$operators = [];
$opRes = $conn->query("SELECT id, name FROM operators WHERE is_active = 1 ORDER BY name ASC");
if ($opRes) {
    while ($r = $opRes->fetch_assoc()) {
        $operators[] = $r;
    }
}

// ── AJAX: set active operator ──────────────────────────────────
if (isset($_POST['ajax']) && $_POST['ajax'] === 'set_active_operator') {
    header('Content-Type: application/json');
    $opName = trim($_POST['operator_name'] ?? '');
    $_SESSION['active_operator'] = $opName;
    $palletId = (int)($_POST['pallet_id'] ?? 0);
    if ($palletId > 0 && $opName !== '') {
        $stmt = $conn->prepare("UPDATE pallets SET created_by = ? WHERE id = ? AND status = 'building'");
        $stmt->bind_param("si", $opName, $palletId);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['ok' => true, 'operator' => $opName]);
    exit;
}

// ── AJAX: add new operator ─────────────────────────────────────
if (isset($_POST['ajax']) && $_POST['ajax'] === 'add_operator') {
    header('Content-Type: application/json');
    $opName = trim($_POST['operator_name'] ?? '');
    if ($opName === '') {
        echo json_encode(['ok' => false, 'msg' => 'Operator name cannot be empty.']);
        exit;
    }
    $stmt = $conn->prepare("INSERT INTO operators (name, is_active) VALUES (?, 1) ON DUPLICATE KEY UPDATE is_active = 1");
    if ($stmt) {
        $stmt->bind_param("s", $opName);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) {
            $_SESSION['active_operator'] = $opName;
            echo json_encode(['ok' => true, 'name' => $opName, 'msg' => 'Operator added successfully.']);
            exit;
        }
    }
    echo json_encode(['ok' => false, 'msg' => 'Failed to save operator.']);
    exit;
}

// ── AJAX: validate pallet_no ──────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'validate_pallet_no') {
    header('Content-Type: application/json');
    echo json_encode($pm->validatePalletNo(trim($_GET['pallet_no'] ?? '')));
    exit;
}

// ── AJAX: get latest pallet numbers (none, B, BN) ─────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_latest_pallets') {
    header('Content-Type: application/json');
    echo json_encode($pm->getLatestPalletNumbers());
    exit;
}

// ── AJAX: get next auto-generated Pallet ID ───────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_next_pallet_no') {
    header('Content-Type: application/json');
    $yy     = trim($_GET['yy'] ?? date('y'));
    $mm     = trim($_GET['mm'] ?? date('m'));
    $suffix = trim($_GET['suffix'] ?? 'none');
    echo json_encode($pm->getNextPalletNo($yy, $mm, $suffix));
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
    $sort   = trim($_GET['sort']   ?? 'latest');
    $suffix = trim($_GET['suffix'] ?? 'all');
    $date   = trim($_GET['date']   ?? '');
    echo json_encode($pm->listPallets($group, $search, $sort, $suffix, $date));
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
        SELECT p.id AS pallet_id, p.pallet_no, p.status, p.created_at AS pallet_date, pi.stock_code AS pi_stock_code,
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
        $lenVal = (!empty($r['actual_length']) && $r['actual_length'] > 0)
            ? $r['actual_length'] : $r['length'];
        $stockCode = !empty($r['coil_no'])
            ? PalletManager::formatStockCode($r['coil_no'], $r['width'] ?? 0, $lenVal ?? 0)
            : ($r['pi_stock_code'] ?? null);

        $formattedDate = !empty($r['pallet_date']) ? date('d/m/Y', strtotime($r['pallet_date'])) : '-';

        return [
            'pallet_id'  => $r['pallet_id'],
            'pallet_no'  => $r['pallet_no'],
            'date'       => $formattedDate,
            'status'     => $r['status'],
            'stock_code' => $stockCode,
            'roll_no'    => $r['roll_no'] ? str_replace('R', 'R-', $r['roll_no']) : null,
            'lot_coil'   => trim(($r['lot_no'] ?? '') . ' ' . ($r['coil_no'] ?? '')),
            'product'    => $r['product'],
            'customer'   => $r['customer_name'],
            'ref_no'     => $r['ref_no'],
            'width'      => $r['width'] !== null ? (float)$r['width'] : null,
            'length'     => $lenVal !== null ? (float)$lenVal : null,
        ];
    }, $rows);
}

function getPalletSuffix(string $palletNo): string {
    $palletNo = trim($palletNo);
    if (preg_match('/\(([A-Z0-9]+)\)$/i', $palletNo, $m)) {
        return strtoupper($m[1]);
    }
    return 'none';
}

// ── Shared: apply the same category/value filter + status + suffix filter
//    used by the modal's client-side JS, so the exported Excel matches
//    whatever the user was actually looking at when they clicked Export. ──
function filterSummaryPalletRows(array $rows, string $cat, string $val, string $statusFilter = '', string $suffixFilter = ''): array {
    // 1. Main Filter
    if ($val !== '') {
        if ($cat === 'date') {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $val, $m)) {
                $val = "{$m[3]}/{$m[2]}/{$m[1]}";
            }
            $rows = array_values(array_filter($rows, fn($r) => (string)($r['date'] ?? '') === $val));
        } elseif ($cat === 'customer' || $cat === 'product') {
            $rows = array_values(array_filter($rows, fn($r) => (string)($r[$cat] ?? '') === $val));
        } elseif ($cat === 'suffix') {
            $rows = array_values(array_filter($rows, function ($r) use ($val) {
                $s = getPalletSuffix((string)($r['pallet_no'] ?? ''));
                return ($val === 'none') ? ($s === 'none') : (strcasecmp($s, $val) === 0);
            }));
        } elseif ($cat === 'width') {
            $rows = array_values(array_filter($rows, function ($r) use ($val) {
                return $r['width'] !== null && str_contains((string)$r['width'], $val);
            }));
        } elseif ($cat === 'length') {
            $rows = array_values(array_filter($rows, function ($r) use ($val) {
                return $r['length'] !== null && str_contains((string)$r['length'], $val);
            }));
        } else {
            // All Fields — free text across everything visible in the table
            $needle = strtolower($val);
            $rows = array_values(array_filter($rows, function ($r) use ($needle) {
                foreach ([$r['pallet_no'], $r['date'], $r['status'], $r['stock_code'], $r['product'], $r['lot_coil'], $r['roll_no'], $r['customer'], $r['ref_no'], $r['width'], $r['length']] as $field) {
                    if ($field !== null && str_contains(strtolower((string)$field), $needle)) return true;
                }
                return false;
            }));
        }
    }

    // 2. Sub Filter by Status
    if ($statusFilter !== '') {
        $rows = array_values(array_filter($rows, fn($r) => (string)($r['status'] ?? '') === $statusFilter));
    }

    // 3. Sub Filter by Suffix
    if ($suffixFilter !== '') {
        $rows = array_values(array_filter($rows, function ($r) use ($suffixFilter) {
            $s = getPalletSuffix((string)($r['pallet_no'] ?? ''));
            return ($suffixFilter === 'none') ? ($s === 'none') : (strcasecmp($s, $suffixFilter) === 0);
        }));
    }

    return $rows;
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
    $cat         = isset($_GET['cat']) ? trim($_GET['cat']) : '';
    $val         = isset($_GET['val']) ? trim($_GET['val']) : '';
    $statusParam = isset($_GET['status']) ? trim($_GET['status']) : '';
    $suffixParam = isset($_GET['suffix']) ? trim($_GET['suffix']) : '';

    $rows = buildSummaryPalletRows($conn);
    $rows = filterSummaryPalletRows($rows, $cat, $val, $statusParam, $suffixParam);

    $catLabels = ['customer' => 'Customer', 'product' => 'Product Type', 'date' => 'Date', 'width' => 'Width', 'length' => 'Length', 'suffix' => 'Pallet Suffix'];
    $filterParts = [];
    if ($val !== '') {
        $filterParts[] = isset($catLabels[$cat]) ? "{$catLabels[$cat]}: {$val}" : "Search: {$val}";
    }
    if ($statusParam !== '') {
        $statusName = ucwords(str_replace('_', ' ', $statusParam));
        $filterParts[] = "Status: {$statusName}";
    }
    if ($suffixParam !== '') {
        $suffixName = ($suffixParam === 'none') ? 'None (Standard)' : strtoupper($suffixParam);
        $filterParts[] = "Suffix: {$suffixName}";
    }
    $filterLbl = !empty($filterParts) ? implode(' | ', $filterParts) : 'All Records';

    $filename = 'Summary_Pallet_' . date('Y-m-d_His') . '.xls';

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $cols      = 10; // Pallet No, Date, Status, Stock Code, Product Type, Rolls, Customer, Ref No, Width, Length
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
            <th style="padding:8px 10px;">Date</th>
            <th style="padding:8px 10px;">Status</th>
            <th style="padding:8px 10px;">Stock Code</th>
            <th style="padding:8px 10px;">Product Type</th>
            <th style="padding:8px 10px;">Rolls</th>
            <th style="padding:8px 10px;">Customer</th>
            <th style="padding:8px 10px;">Ref No</th>
            <th style="padding:8px 10px;">Width (mm)</th>
            <th style="padding:8px 10px;">Length (m)</th>
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
            echo '<td ' . $td  . '>' . htmlspecialchars(($r['date'] ?? '') ?: '-') . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($statusLbl) . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars(($r['stock_code'] ?? '') ?: '-') . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars(($r['product'] ?? '') ?: '-') . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($rollsCell) . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($r['customer'] ?: '-') . '</td>';
            echo '<td ' . $td  . '>' . htmlspecialchars($r['ref_no']   ?: '-') . '</td>';
            echo '<td ' . $tdN . '>' . ($r['width'] !== null ? formatWidthDisplay($r['width']) : '-') . '</td>';
            echo '<td ' . $tdN . '>' . ($r['length'] !== null ? formatWidthDisplay($r['length']) : '-') . '</td>';
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
    $reason   = trim($_POST['reopen_reason'] ?? '');
    $result   = $pm->reopenApprovedOrDeliveredPallet($palletId, $reason);
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

// Helper: format width to show decimals if present (e.g. 109.5) or integer if whole number (e.g. 110)
function formatWidthDisplay($val): string {
    if ($val === null || $val === '') return '-';
    $f = (float)$val;
    return ($f == (int)$f) ? number_format($f, 0) : (string)$f;
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
    color:#adb5bd; border:0; font-size:10.5px; font-weight:600; padding:3px 4px;
    border-radius:4px; letter-spacing:.01em;
}
.pallet-tab-group .pallet-tab:hover  { color:#fff; }
.pallet-tab-group .pallet-tab.active { background:#fff; color:#212529; }
.pallet-tab-group .pallet-tab .tab-count { opacity:.65; font-weight:500; margin-left:2px; }
.pallet-tab-group .pallet-suffix-btn {
    color:#adb5bd; border:0; font-size:10.5px; font-weight:600; padding:2px 6px;
    border-radius:4px; letter-spacing:.01em; transition:all 0.15s ease;
}
.pallet-tab-group .pallet-suffix-btn:hover  { color:#fff; }
.pallet-tab-group .pallet-suffix-btn.active { background:#0d6efd; color:#fff; font-weight:700; }

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
.pallet-card.active .pallet-card-lot,
.pallet-card.active .pallet-card-date { color:rgba(255,255,255,.75) !important; }
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
.pallet-card-date       { font-size:10px; color:#6c757d; font-weight:500; margin-top:2px; white-space:nowrap; }
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
/* Active Operator Select & Name List */
#activeOperatorSelect, #activeOperatorSelect option {
    font-size: 18px !important;
}
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <h2 class="mb-0"><i class="bi bi-archive me-2"></i>Pallet Management</h2>

        <!-- Active Operator Selector -->
        <div class="d-flex align-items-center gap-2 bg-white border border-primary-subtle rounded-3 px-3 py-2 shadow-sm">
            <span class="text-secondary fw-bold text-nowrap" style="font-size:15px !important;">
                <i class="bi bi-person-badge text-primary me-1" style="font-size:17px !important;"></i>Active Operator:
            </span>
            <select id="activeOperatorSelect" class="form-select border-primary font-monospace fw-bold text-primary bg-primary-subtle"
                    style="min-width:180px; font-size:18px !important; padding:6px 38px 6px 14px !important; height:auto !important;" onchange="setActiveOperator(this.value)">
                <option value="" style="font-size:18px !important;">-- Select Operator --</option>
                <?php foreach ($operators as $op): ?>
                <option value="<?= htmlspecialchars($op['name']) ?>" style="font-size:18px !important;" <?= ($activeOperatorSession === $op['name']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($op['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-primary text-nowrap shadow-sm font-monospace fw-bold" style="font-size:14px !important; padding:6px 14px !important;"
                    data-bs-toggle="modal" data-bs-target="#addOperatorModal" title="Add New Operator">
                <i class="bi bi-plus-lg me-1"></i>Add
            </button>
        </div>
    </div>

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

                    <span class="badge bg-white text-dark ms-2 shadow-sm d-inline-flex align-items-center gap-1" style="font-size:18px !important; font-weight:700; padding:4px 12px; border-radius:6px; color:#1e293b !important;" title="Active Operator">
                        <i class="bi bi-person-fill text-primary" style="font-size:18px !important;"></i> <strong><?= htmlspecialchars($activePallet['created_by'] ?: ($activeOperatorSession ?: 'N/A')) ?></strong>
                    </span>
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
                                <td class="fw-bold text-dark"><?= formatWidthDisplay($activePallet['width']) ?> mm</td>
                            </tr>
                            <tr>
                                <td class="bg-light fw-bold text-muted">Active Operator</td>
                                <td class="fw-bold text-dark" colspan="3">
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace px-2 py-1" style="font-size:13px;">
                                        <i class="bi bi-person-badge me-1"></i><?= htmlspecialchars(!empty($activePallet['created_by']) ? $activePallet['created_by'] : ($_SESSION['active_operator'] ?? 'slitting')) ?>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Edit mode (hidden until pencil clicked) -->
                <?php
                    $PALLET_CUSTOMERS = [
                        'NAE'      => 'NICHIAS AUTOPARTS EUROPE (NAE)',
                        'NAX'      => 'NAX MFG, SA.DE C.V',
                        'NCI MFG'  => 'NCI MFG., INC.',
                        'TAIHO'    => 'TAIHO MFG OF TN. INC',
                        'NRI'      => 'PT NICHIAS ROCKWOOL IND.',
                        'ASHUKA'   => 'ASHUKA TECHNOLOGIES SDN. BHD.',
                        'NIPPON'   => 'NTC(NIPPON GASKET)',
                        'NTC'      => 'NICHIAS THAILAND',
                        'SGC'      => 'SHANGHAI XINGSHENG',
                        'STAMPING' => 'MK STAMPING',
                        'YANTAI'   => 'NICHIAS (SHANGHAI) AUTOPARTS TRADING',
                        'NIPP'     => 'NICHIAS IND.PRODUCTS PVT. LTD.',
                        'NVC'      => 'NICHIAS VIETNAM CO., LTD',
                        'NSJ'      => 'NC-PT NICHIAS SUNIJAYA',
                        'NIP'      => 'SUZHOU NICHIAS IND. PRODUCTS',
                        'YTEC'     => 'YTEC CO., LTD.',
                        'NSA'      => 'NICHIAS SOUTH EAST ASIA (UP PACKING)',
                        'NCI 2'    => 'NCI 2',
                        'STOCK'    => 'STOCK',
                        'TRIAL'    => 'TRIAL',
                    ];
                    $curCust = trim($activePallet['customer_name'] ?? '');
                    $isOtherCustomer = ($curCust !== '' && !isset($PALLET_CUSTOMERS[$curCust]) && !in_array($curCust, $PALLET_CUSTOMERS, true));
                ?>
                <div class="d-none align-items-center gap-1 flex-wrap mt-2" id="constraintEditForm">
                    <select class="form-select form-select-sm constraint-edit-input" id="constraintCustomerInput"
                            onchange="handleConstraintCustomerChange()" onkeydown="onConstraintEditKeydown(event)" style="min-width:180px;">
                        <option value="">-- Select Customer --</option>
                        <?php foreach ($PALLET_CUSTOMERS as $code => $fullName): ?>
                            <option value="<?= htmlspecialchars($code) ?>" <?= ($curCust === $code || $curCust === $fullName) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($code) ?> — <?= htmlspecialchars($fullName) ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="OTHER" <?= $isOtherCustomer ? 'selected' : '' ?>>OTHER (type below)</option>
                    </select>
                    <input type="text" class="form-control form-control-sm constraint-edit-input mt-1" id="constraintCustomCustomerInput"
                           placeholder="Enter customer name" style="display:<?= $isOtherCustomer ? 'block' : 'none' ?>;"
                           value="<?= $isOtherCustomer ? htmlspecialchars($curCust) : '' ?>" onkeydown="onConstraintEditKeydown(event)">
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
                            <td><?= formatWidthDisplay($item['width']) ?></td>
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
                    <span class="badge bg-white text-dark ms-2 shadow-sm d-inline-flex align-items-center gap-1" style="font-size:18px !important; font-weight:700; padding:4px 12px; border-radius:6px; color:#1e293b !important;" title="Active Operator">
                        <i class="bi bi-person-fill text-danger" style="font-size:18px !important;"></i> <strong><?= htmlspecialchars($activePallet['created_by'] ?: ($activeOperatorSession ?: 'N/A')) ?></strong>
                    </span>
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
                            <td class="fw-bold text-dark"><?= $activePallet['width'] ? formatWidthDisplay($activePallet['width']) . ' mm' : '-' ?></td>
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
                            <td><?= formatWidthDisplay($item['width']) ?></td>
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
                <button type="button" class="btn btn-warning fw-bold"
                        onclick="openReopenModal(<?= (int)$activePalletId ?>, '<?= htmlspecialchars($activePallet['pallet_no'] ?? '', ENT_QUOTES) ?>')">
                    <i class="bi bi-pencil-fill me-1"></i> Edit Pallet
                </button>
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
                    <span class="badge bg-white text-dark ms-2 shadow-sm d-inline-flex align-items-center gap-1" style="font-size:18px !important; font-weight:700; padding:4px 12px; border-radius:6px; color:#1e293b !important;" title="Active Operator">
                        <i class="bi bi-person-fill text-warning" style="font-size:18px !important;"></i> <strong><?= htmlspecialchars($activePallet['created_by'] ?: ($activeOperatorSession ?: 'N/A')) ?></strong>
                    </span>
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
                            <td class="fw-bold text-dark"><?= $activePallet['width'] ? formatWidthDisplay($activePallet['width']) . ' mm' : '-' ?></td>
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
                            <td><?= formatWidthDisplay($item['width']) ?></td>
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
                    <span class="badge bg-white text-dark ms-2 shadow-sm d-inline-flex align-items-center gap-1" style="font-size:18px !important; font-weight:700; padding:4px 12px; border-radius:6px; color:#1e293b !important;" title="Active Operator">
                        <i class="bi bi-person-fill text-success" style="font-size:18px !important;"></i> <strong><?= htmlspecialchars($activePallet['created_by'] ?: ($activeOperatorSession ?: 'N/A')) ?></strong>
                    </span>
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
                            <td class="fw-bold text-dark"><?= $activePallet['width'] ? formatWidthDisplay($activePallet['width']) . ' mm' : '-' ?></td>
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
                            <td><?= formatWidthDisplay($item['width']) ?></td>
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
                    <button type="button" class="btn btn-outline-warning btn-sm fw-bold"
                            onclick="openReopenModal(<?= (int)$activePalletId ?>, '<?= htmlspecialchars($activePallet['pallet_no'] ?? '', ENT_QUOTES) ?>')">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Return to Edit
                    </button>
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
                    <span class="badge bg-white text-dark ms-2 shadow-sm d-inline-flex align-items-center gap-1" style="font-size:18px !important; font-weight:700; padding:4px 12px; border-radius:6px; color:#1e293b !important;" title="Active Operator">
                        <i class="bi bi-person-fill text-success" style="font-size:18px !important;"></i> <strong><?= htmlspecialchars($activePallet['created_by'] ?: ($activeOperatorSession ?: 'N/A')) ?></strong>
                    </span>
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
                            <td class="fw-bold text-dark"><?= $activePallet['width'] ? formatWidthDisplay($activePallet['width']) . ' mm' : '-' ?></td>
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
                            <td><?= formatWidthDisplay($item['width']) ?></td>
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
                    <button type="button" class="btn btn-outline-warning btn-sm fw-bold"
                            onclick="openReopenModal(<?= (int)$activePalletId ?>, '<?= htmlspecialchars($activePallet['pallet_no'] ?? '', ENT_QUOTES) ?>')">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Return to Edit
                    </button>
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
                    <div class="d-flex align-items-center gap-1">
                        <span class="text-white-50 me-1" style="font-size:11px;">Suffix:</span>
                        <div class="btn-group btn-group-sm pallet-tab-group" id="palletSuffixGroup">
                            <button type="button" class="btn pallet-suffix-btn active" data-suffix="all">All</button>
                            <button type="button" class="btn pallet-suffix-btn" data-suffix="none">None</button>
                            <button type="button" class="btn pallet-suffix-btn" data-suffix="B">B</button>
                            <button type="button" class="btn pallet-suffix-btn" data-suffix="BN">BN</button>
                        </div>
                        <span class="badge bg-secondary ms-1" id="palletListCount">—</span>
                    </div>
                </div>
                <div class="d-flex gap-2 mb-2 align-items-center">
                    <div class="position-relative flex-grow-1">
                        <i class="bi bi-search position-absolute"
                           style="left:10px; top:50%; transform:translateY(-50%); font-size:12px; color:#adb5bd;"></i>
                        <input type="text" id="palletSearchInput"
                               class="form-control form-control-sm ps-4"
                               placeholder="Type 7 numbers (e.g. 8888888), Pallet No, or Customer…">
                    </div>
                    <div class="position-relative" style="width:130px;">
                        <input type="date" id="palletDateFilter" class="form-control form-control-sm text-white border-secondary pe-4"
                               style="background:#2b3035; font-size:11px; height:31px; color-scheme: dark;"
                               title="Filter by date"
                               onchange="loadPalletList()">
                        <button type="button" id="palletDateClearBtn" class="btn btn-sm text-white-50 position-absolute d-none p-0"
                                style="right:6px; top:50%; transform:translateY(-50%); font-size:12px; line-height:1; z-index:5;"
                                title="Clear date filter" onclick="clearPalletDateFilter()">
                            <i class="bi bi-x-circle-fill"></i>
                        </button>
                    </div>
                    <select id="palletSortSelect" class="form-select form-select-sm text-white border-secondary"
                            style="background:#2b3035; font-size:11.5px; width:auto; max-width:125px;"
                            onchange="loadPalletList()">
                        <option value="latest" selected>Latest Created</option>
                        <option value="updated">Latest Activity</option>
                        <option value="id">Pallet No (A-Z)</option>
                    </select>
                </div>
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
                        <button type="button" class="btn pallet-tab" data-group="approved">
                            Approve <span class="tab-count" data-count="approved"></span>
                        </button>
                        <button type="button" class="btn pallet-tab" data-group="rejected">
                            Reject <span class="tab-count" data-count="rejected"></span>
                        </button>
                        <button type="button" class="btn pallet-tab" data-group="delivered">
                            Delivered <span class="tab-count" data-count="delivered"></span>
                        </button>
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

<!-- ── REOPEN / RETURN TO EDIT REASON MODAL ───────────────────── -->
<div class="modal fade" id="reopenReasonModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow border-0">
      <div class="modal-header bg-warning text-dark py-2">
        <h5 class="modal-title fs-6 fw-bold">
          <i class="bi bi-arrow-counterclockwise me-2"></i>Return Pallet to Edit Mode
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="pallet.php">
        <div class="modal-body py-3">
          <input type="hidden" name="action" value="reopen_pallet">
          <input type="hidden" name="pallet_id" id="reopenModalPalletId" value="">
          
          <div class="alert alert-warning py-2 mb-3 small d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0"></i>
            <div>
              Returning pallet <strong id="reopenModalPalletNo"></strong> to edit mode will reset all attached rolls to <span class="badge bg-secondary">IN</span> status so corrections can be made.
            </div>
          </div>

          <div class="mb-2">
            <label for="reopenReasonInput" class="form-label small fw-bold text-dark mb-1">
              Reason / Remark <span class="text-danger">*</span>
            </label>
            <textarea name="reopen_reason" id="reopenReasonInput" class="form-control form-control-sm" rows="3"
                      placeholder="Enter reason for returning to edit mode (e.g. Wrong roll assigned, quantity correction, QC feedback...)" required></textarea>
          </div>
        </div>
        <div class="modal-footer py-2 px-3 bg-light">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning btn-sm fw-bold">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Confirm Return to Edit
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── ADD OPERATOR MODAL ────────────────────────────────────── -->
<div class="modal fade" id="addOperatorModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content shadow border-0">
      <div class="modal-header bg-primary text-white py-2">
        <h5 class="modal-title fs-6"><i class="bi bi-person-plus me-1"></i>Add New Operator</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-3">
        <div class="mb-2">
          <label class="form-label fw-semibold small mb-1">Operator Name <span class="text-danger">*</span></label>
          <input type="text" id="newOperatorNameInput" class="form-control form-control-sm"
                 placeholder="e.g. Ahmad / Siti" autocomplete="off"
                 onkeydown="if(event.key==='Enter') submitAddOperator()">
        </div>
        <div id="addOperatorFeedback" class="small text-danger d-none mt-2"></div>
      </div>
      <div class="modal-footer py-2 px-3 bg-light">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="submitAddOperator()">
          <i class="bi bi-check-lg me-1"></i>Save Operator
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── CREATE PALLET MODAL ───────────────────────────────────── -->
<div class="modal fade" id="createPalletModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:#0f2744;">
        <h5 class="modal-title text-white">
            <i class="bi bi-plus-circle me-2"></i>New Pallet
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pb-2">
        <!-- ── AUTO GENERATION CONTROLS CARD ── -->
        <div class="card bg-light border mb-3">
          <div class="card-header bg-white py-1 px-3 fw-bold text-primary" style="font-size:12px;">
            <i class="bi bi-magic me-1"></i>Pallet ID Auto-Generator
          </div>
          <div class="card-body p-2" style="font-size:12px;">
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label mb-1 fw-semibold text-secondary" style="font-size:11px;">
                  <i class="bi bi-calendar-event me-1"></i>Year (YY)
                </label>
                <select id="autoGenYY" class="form-select form-select-sm font-monospace">
                  <?php
                    $curYY = date('y');
                    for ($y = 20; $y <= 99; $y++) {
                        $yyVal = sprintf("%02d", $y);
                        $sel = ($yyVal === $curYY) ? 'selected' : '';
                        echo "<option value=\"{$yyVal}\" {$sel}>20{$yyVal} ({$yyVal})</option>";
                    }
                  ?>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label mb-1 fw-semibold text-secondary" style="font-size:11px;">
                  <i class="bi bi-calendar-month me-1"></i>Month (MM)
                </label>
                <select id="autoGenMM" class="form-select form-select-sm font-monospace">
                  <?php
                    $months = [
                      '01'=>'01 - Jan', '02'=>'02 - Feb', '03'=>'03 - Mar', '04'=>'04 - Apr',
                      '05'=>'05 - May', '06'=>'06 - Jun', '07'=>'07 - Jul', '08'=>'08 - Aug',
                      '09'=>'09 - Sep', '10'=>'10 - Oct', '11'=>'11 - Nov', '12'=>'12 - Dec'
                    ];
                    $curMM = date('m');
                    foreach ($months as $mCode => $mName) {
                        $sel = ($mCode === $curMM) ? 'selected' : '';
                        echo "<option value=\"{$mCode}\" {$sel}>{$mName}</option>";
                    }
                  ?>
                </select>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label mb-1 fw-bold text-primary" style="font-size:11px;">
                Step 1: Select Pallet Type / Suffix <span class="text-danger">*</span>
              </label>
              <div class="btn-group w-100 btn-group-sm" role="group" id="autoGenSuffixGroup">
                <input type="radio" class="btn-check" name="autoGenSuffix" id="sufNone" value="none" autocomplete="off">
                <label class="btn btn-outline-primary" for="sufNone">None (Std)</label>

                <input type="radio" class="btn-check" name="autoGenSuffix" id="sufB" value="b" autocomplete="off">
                <label class="btn btn-outline-primary" for="sufB">(B)</label>

                <input type="radio" class="btn-check" name="autoGenSuffix" id="sufBN" value="bn" autocomplete="off">
                <label class="btn btn-outline-primary" for="sufBN">(BN)</label>
              </div>
            </div>

            <div class="d-flex justify-content-between align-items-center p-2 bg-white border rounded">
              <div>
                <div class="text-muted" style="font-size:10px; text-transform:uppercase;">Next Auto Pallet ID</div>
                <div id="nextAutoPalletDisplay" class="font-monospace fw-bold text-success fs-6">
                  <span class="text-muted fst-italic" style="font-size:12px;">Select Pallet Type above</span>
                </div>
              </div>
              <button type="button" class="btn btn-sm btn-outline-success py-1 px-2" onclick="applyAutoPalletNo()">
                <i class="bi bi-box-arrow-in-down me-1"></i>Use Auto ID
              </button>
            </div>
          </div>
        </div>

        <!-- ── HIGHEST PALLET NUMBERS REFERENCE CARD ── -->
        <div class="p-2 mb-3 bg-light border rounded" style="font-size:12px;">
          <div class="fw-bold text-dark mb-1 border-bottom pb-1" style="font-size:11px; text-transform:uppercase; letter-spacing:.5px;">
            <i class="bi bi-clock-history me-1 text-primary"></i>Highest Pallet Numbers Created
          </div>
          <div class="d-flex justify-content-between align-items-center py-1">
            <span class="text-secondary">Standard (None):</span>
            <span id="latestPalletNone" class="font-monospace fw-bold text-dark cursor-pointer" title="Click to insert" onclick="useLatestPalletNo(this)">-</span>
          </div>
          <div class="d-flex justify-content-between align-items-center py-1 border-top border-light">
            <span class="text-secondary">(B):</span>
            <span id="latestPalletB" class="font-monospace fw-bold text-dark cursor-pointer" title="Click to insert" onclick="useLatestPalletNo(this)">-</span>
          </div>
          <div class="d-flex justify-content-between align-items-center py-1 border-top border-light">
            <span class="text-secondary">(BN):</span>
            <span id="latestPalletBN" class="font-monospace fw-bold text-dark cursor-pointer" title="Click to insert" onclick="useLatestPalletNo(this)">-</span>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-bold mb-1">
            Pallet Serial No <span class="text-danger">*</span>
          </label>
          <input type="text" id="palletNoInput" class="form-control"
                 placeholder="e.g. 2608108 or 2608287(B)"
                 autocomplete="off" spellcheck="false"
                 style="font-family:monospace;letter-spacing:.4px;">
          <div class="form-text">Format: <code>SFS-YYMM-XXX</code> or <code>SFS-YYMM-XXX (B)</code></div>
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
        <div class="row g-2 mb-3 align-items-center">
          <div class="col-md-2">
            <select id="summaryFilterCategory" class="form-select form-select-sm" onchange="onSummaryCategoryChange()">
              <option value="">All Fields</option>
              <option value="suffix">Pallet Suffix (B, BN, None)</option>
              <option value="date">Date</option>
              <option value="product">Product Type</option>
              <option value="customer">Customer</option>
              <option value="width">Width</option>
              <option value="length">Length</option>
            </select>
          </div>
          <div class="col-md-3">
            <!-- Text input — used for "All Fields" free search, and for Width/Length -->
            <input type="text" id="summaryFilterValueText" class="form-control form-control-sm"
                   placeholder="Search Pallet No, Date, Stock Code, Product, Rolls, Customer, Ref No, Width, Length..."
                   oninput="applySummaryFilter()">
            <!-- Dropdown — used for Product / Customer / Suffix, populated dynamically with distinct values -->
            <select id="summaryFilterValueSelect" class="form-select form-select-sm d-none" onchange="applySummaryFilter()">
              <option value="">All</option>
            </select>
            <!-- Calendar Date Picker — used specifically when Date is selected -->
            <input type="date" id="summaryFilterValueDate" class="form-control form-control-sm d-none" onchange="applySummaryFilter()">
          </div>
          <div class="col-md-3">
            <!-- Sub Filter by Status -->
            <div class="input-group input-group-sm">
              <span class="input-group-text bg-light text-muted fw-semibold" style="font-size:12px;">Status</span>
              <select id="summaryFilterStatus" class="form-select form-select-sm" onchange="applySummaryFilter()">
                <option value="">All Statuses</option>
                <option value="building">Building</option>
                <option value="pending_qc">Pending QC</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
                <option value="delivered">Delivered</option>
              </select>
            </div>
          </div>
          <div class="col-md-2">
            <!-- Sub Filter by Suffix -->
            <div class="input-group input-group-sm">
              <span class="input-group-text bg-light text-muted fw-semibold" style="font-size:12px;">Suffix</span>
              <select id="summaryFilterSuffix" class="form-select form-select-sm" onchange="applySummaryFilter()">
                <option value="">All</option>
                <option value="none">None (Standard)</option>
                <option value="B">B</option>
                <option value="BN">BN</option>
              </select>
            </div>
          </div>
          <div class="col-md-2 d-flex gap-2 align-items-center justify-content-end">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearSummaryFilter()" title="Clear Filters">
              <i class="bi bi-x-lg me-1"></i>Clear
            </button>
            <span class="text-muted small text-nowrap" id="summaryResultCount"></span>
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
                <th>Date</th>
                <th>Status</th>
                <th>Stock Code</th>
                <th>Product Type</th>
                <th>Rolls</th>
                <th>Customer</th>
                <th>Ref No</th>
                <th>Width</th>
                <th>Length</th>
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
                        <td class="fw-bold text-dark">${p.width !== null && p.width !== undefined ? (+p.width) : '-'} mm</td>
                    </tr>
                    <tr>
                        <td class="bg-light fw-bold text-muted">Active Operator</td>
                        <td class="fw-bold text-dark" colspan="3">
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace px-2 py-1" style="font-size:13px;">
                                <i class="bi bi-person-badge me-1"></i>${escHtml(p.created_by || document.getElementById('activeOperatorSelect')?.value || 'slitting')}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="d-none align-items-center gap-1 flex-wrap mt-2" id="constraintEditForm">
            <select class="form-select form-select-sm constraint-edit-input" id="constraintCustomerInput"
                    onchange="handleConstraintCustomerChange()" onkeydown="onConstraintEditKeydown(event)" style="min-width:180px;">
                <option value="">-- Select Customer --</option>
                ${Object.entries(PALLET_CUSTOMERS_MAP).map(([code, fullName]) => {
                    const isSel = (p.customer_name === code || p.customer_name === fullName);
                    return `<option value="${escHtml(code)}" ${isSel ? 'selected' : ''}>${escHtml(code)} &mdash; ${escHtml(fullName)}</option>`;
                }).join('')}
                <option value="OTHER">OTHER (type below)</option>
            </select>
            <input type="text" class="form-control form-control-sm constraint-edit-input mt-1" id="constraintCustomCustomerInput"
                   placeholder="Enter customer name" style="display:none;" value="" onkeydown="onConstraintEditKeydown(event)">
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
        <td>${p.width !== null && p.width !== undefined ? (+p.width) : '-'}</td>
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
// removeRoll(palletId, productId, seq, btnEl)
// Removes a single roll from the active pallet
// ─────────────────────────────────────────────────────────────
async function removeRoll(palletId, productId, seq, btnEl) {
    if (!confirm('Remove this roll from the pallet?')) return;

    if (btnEl) btnEl.disabled = true;

    const fd = new FormData();
    fd.append('action', 'remove_roll');
    fd.append('pallet_id', palletId);
    fd.append('product_id', productId);

    try {
        const res = await fetch('pallet.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.ok) {
            showFeedback(res.msg || 'Failed to remove roll.', false);
            if (btnEl) btnEl.disabled = false;
            return;
        }

        clearSlot(seq);
        resequenceSlots();
        recalcTotalWeight();
        rollCount = res.new_count;
        updateProgress(rollCount);

        if (res.new_count === 0) {
            window.location.reload();
        } else {
            showFeedback(res.msg, true);
        }
    } catch (e) {
        showFeedback('Network error while removing roll.', false);
        if (btnEl) btnEl.disabled = false;
    }
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
let currentNextAutoPalletId = '';

function fetchNextAutoPalletId(autoApply = true) {
    const yyEl = document.getElementById('autoGenYY');
    const mmEl = document.getElementById('autoGenMM');
    const sufEl = document.querySelector('input[name="autoGenSuffix"]:checked');
    const dispEl = document.getElementById('nextAutoPalletDisplay');

    if (!yyEl || !mmEl || !dispEl) return;

    if (!sufEl) {
        currentNextAutoPalletId = '';
        dispEl.innerHTML = '<span class="text-muted fst-italic" style="font-size:12px;">Select Pallet Type above</span>';
        return;
    }

    const yy = yyEl.value.trim();
    const mm = mmEl.value.trim();
    const suffix = sufEl.value;

    dispEl.innerHTML = '<span class="spinner-border spinner-border-sm text-secondary" style="width:.85rem;height:.85rem;"></span>';

    fetch(`pallet.php?ajax=get_next_pallet_no&yy=${enc(yy)}&mm=${enc(mm)}&suffix=${enc(suffix)}`)
        .then(r => r.json())
        .then(res => {
            if (res.ok && res.next_pallet_no) {
                currentNextAutoPalletId = res.next_pallet_no;
                dispEl.textContent = res.next_pallet_no;
                if (autoApply) {
                    applyAutoPalletNo();
                }
            } else {
                currentNextAutoPalletId = '';
                dispEl.textContent = 'Error';
            }
        })
        .catch(() => {
            currentNextAutoPalletId = '';
            dispEl.textContent = 'Error';
        });
}

function applyAutoPalletNo() {
    if (!currentNextAutoPalletId) return;
    const inp = document.getElementById('palletNoInput');
    if (inp) {
        inp.value = currentNextAutoPalletId;
        inp.dispatchEvent(new Event('input', { bubbles: true }));
        inp.focus();
    }
}

function useLatestPalletNo(el) {
    const text = el.textContent ? el.textContent.trim() : '';
    if (!text || text === '-' || text === 'None' || text === '—') return;
    const inp = document.getElementById('palletNoInput');
    if (inp) {
        inp.value = text;
        inp.dispatchEvent(new Event('input', { bubbles: true }));
        inp.focus();
    }
}

// Event listeners for auto-generator controls
document.getElementById('autoGenYY')?.addEventListener('change', fetchNextAutoPalletId);
document.getElementById('autoGenYY')?.addEventListener('input', fetchNextAutoPalletId);
document.getElementById('autoGenMM')?.addEventListener('change', fetchNextAutoPalletId);
document.getElementById('autoGenMM')?.addEventListener('input', fetchNextAutoPalletId);
document.querySelectorAll('input[name="autoGenSuffix"]').forEach(radio => {
    radio.addEventListener('change', fetchNextAutoPalletId);
});

document.getElementById('createPalletModal')?.addEventListener('show.bs.modal', () => {
    palletNoValid = false;
    currentNextAutoPalletId = '';
    const inp = document.getElementById('palletNoInput');
    inp.value = '';
    inp.classList.remove('is-valid', 'is-invalid');
    document.getElementById('palletNoFeedback').innerHTML = '';
    document.getElementById('createPalletBtn').disabled = true;

    // Reset Auto Gen controls to current YY/MM and uncheck suffix radios
    const now = new Date();
    const yyStr = String(now.getFullYear()).slice(-2);
    const mmStr = String(now.getMonth() + 1).padStart(2, '0');

    const yyEl = document.getElementById('autoGenYY');
    const mmEl = document.getElementById('autoGenMM');

    if (yyEl) yyEl.value = yyStr;
    if (mmEl) mmEl.value = mmStr;

    // Uncheck suffix radio options on modal open
    document.querySelectorAll('input[name="autoGenSuffix"]').forEach(radio => radio.checked = false);

    const dispEl = document.getElementById('nextAutoPalletDisplay');
    if (dispEl) dispEl.innerHTML = '<span class="text-muted fst-italic" style="font-size:12px;">Select Pallet Type above</span>';

    const sp = '<span class="spinner-border spinner-border-sm text-secondary" style="width:.75rem;height:.75rem;"></span>';
    const elNone = document.getElementById('latestPalletNone');
    const elB    = document.getElementById('latestPalletB');
    const elBN   = document.getElementById('latestPalletBN');
    if (elNone) elNone.innerHTML = sp;
    if (elB)    elB.innerHTML    = sp;
    if (elBN)   elBN.innerHTML   = sp;

    fetch('pallet.php?ajax=get_latest_pallets')
        .then(r => r.json())
        .then(res => {
            if (res.ok && res.latest) {
                if (elNone) elNone.textContent = res.latest.none || 'None';
                if (elB)    elB.textContent    = res.latest.B    || 'None';
                if (elBN)   elBN.textContent   = res.latest.BN   || 'None';
            } else {
                if (elNone) elNone.textContent = '—';
                if (elB)    elB.textContent    = '—';
                if (elBN)   elBN.textContent   = '—';
            }
        })
        .catch(() => {
            if (elNone) elNone.textContent = '—';
            if (elB)    elB.textContent    = '—';
            if (elBN)   elBN.textContent   = '—';
        });

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

function getPalletSuffix(palletNo) {
    const m = String(palletNo || '').trim().match(/\(([A-Z0-9]+)\)$/i);
    return m ? m[1].toUpperCase() : 'none';
}

async function loadSummaryPallet() {
    // Reset filter UI each time the modal is opened
    document.getElementById('summaryFilterCategory').value = '';
    document.getElementById('summaryFilterValueText').value = '';
    document.getElementById('summaryFilterValueDate').value = '';
    if (document.getElementById('summaryFilterStatus')) {
        document.getElementById('summaryFilterStatus').value = '';
    }
    if (document.getElementById('summaryFilterSuffix')) {
        document.getElementById('summaryFilterSuffix').value = '';
    }
    document.getElementById('summaryFilterValueText').classList.remove('d-none');
    document.getElementById('summaryFilterValueSelect').classList.add('d-none');
    document.getElementById('summaryFilterValueDate').classList.add('d-none');

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
        tbody.innerHTML = `<tr><td colspan="10" class="py-4 text-muted">No matching records.</td></tr>`;
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
                <td>${r.date ? escHtml(r.date) : '<span class="text-muted">&mdash;</span>'}</td>
                <td><span class="badge ${badgeClass}">${escHtml(summaryStatusLabel(r.status))}</span></td>
                <td style="font-family:monospace;font-size:12px;">${r.stock_code ? escHtml(r.stock_code) : '<span class="text-muted">&mdash;</span>'}</td>
                <td>${r.product ? escHtml(r.product) : '<span class="text-muted">&mdash;</span>'}</td>
                <td>${rollsCell}</td>
                <td>${r.customer ? escHtml(r.customer) : '<span class="text-muted">&mdash;</span>'}</td>
                <td>${r.ref_no ? escHtml(r.ref_no) : '<span class="text-muted">&mdash;</span>'}</td>
                <td>${r.width !== null ? (+r.width) + ' mm' : '<span class="text-muted">&mdash;</span>'}</td>
                <td>${r.length !== null ? (+r.length) + ' m' : '<span class="text-muted">&mdash;</span>'}</td>
            </tr>
        `;
    }).join('');

    countEl.textContent = rows.length + (rows.length === 1 ? ' row' : ' rows');
}

// When the filter category changes, swap between:
// - Calendar Date Picker (Date)
// - Dropdown of distinct values (Product / Customer / Suffix)
// - Free-text search box (All Fields / Width / Length)
function onSummaryCategoryChange() {
    const cat         = document.getElementById('summaryFilterCategory').value;
    const textInput   = document.getElementById('summaryFilterValueText');
    const selectInput = document.getElementById('summaryFilterValueSelect');
    const dateInput   = document.getElementById('summaryFilterValueDate');

    textInput.classList.add('d-none');
    selectInput.classList.add('d-none');
    dateInput.classList.add('d-none');

    if (cat === 'date') {
        dateInput.value = '';
        dateInput.classList.remove('d-none');
    } else if (cat === 'customer' || cat === 'product') {
        const distinct = [...new Set(
            summaryData
                .map(r => cat === 'customer' ? r.customer : r.product)
                .filter(v => v !== null && v !== '' && v !== '-')
        )].sort();

        selectInput.innerHTML = '<option value="">All</option>' +
            distinct.map(v => `<option value="${escHtml(v)}">${escHtml(v)}</option>`).join('');

        selectInput.classList.remove('d-none');
    } else if (cat === 'suffix') {
        const distinct = [...new Set(
            summaryData.map(r => getPalletSuffix(r.pallet_no))
        )].sort();

        selectInput.innerHTML = '<option value="">All</option>' +
            distinct.map(v => `<option value="${escHtml(v)}">${v === 'none' ? 'None (Standard)' : escHtml(v)}</option>`).join('');

        selectInput.classList.remove('d-none');
    } else {
        // "All Fields", "Width", and "Length" use free-text search
        textInput.value = '';
        textInput.placeholder = (cat === 'width')
            ? 'Type a width in mm, e.g. 309'
            : (cat === 'length')
            ? 'Type a length in m, e.g. 796'
            : 'Search Pallet No, Date, Stock Code, Product, Rolls, Customer, Ref No, Width, Length...';
        textInput.classList.remove('d-none');
    }

    applySummaryFilter();
}

function applySummaryFilter() {
    const cat          = document.getElementById('summaryFilterCategory').value;
    const statusFilter = document.getElementById('summaryFilterStatus') ? document.getElementById('summaryFilterStatus').value : '';
    const suffixFilter = document.getElementById('summaryFilterSuffix') ? document.getElementById('summaryFilterSuffix').value : '';
    let rows           = summaryData;

    // 1. Main Filter
    if (cat === 'date') {
        const rawDate = document.getElementById('summaryFilterValueDate').value;
        if (rawDate) {
            const parts = rawDate.split('-');
            if (parts.length === 3) {
                const targetDate = `${parts[2]}/${parts[1]}/${parts[0]}`; // DD/MM/YYYY
                rows = rows.filter(r => String(r.date ?? '') === targetDate);
            }
        }
    } else if (cat === 'customer' || cat === 'product') {
        const val = document.getElementById('summaryFilterValueSelect').value;
        if (val !== '') {
            rows = rows.filter(r => String(r[cat] ?? '') === val);
        }
    } else if (cat === 'suffix') {
        const val = document.getElementById('summaryFilterValueSelect').value;
        if (val !== '') {
            rows = rows.filter(r => (val === 'none') ? (getPalletSuffix(r.pallet_no) === 'none') : (getPalletSuffix(r.pallet_no) === val.toUpperCase()));
        }
    } else if (cat === 'width') {
        const val = document.getElementById('summaryFilterValueText').value.trim();
        if (val !== '') {
            rows = rows.filter(r => r.width !== null && String(r.width).includes(val));
        }
    } else if (cat === 'length') {
        const val = document.getElementById('summaryFilterValueText').value.trim();
        if (val !== '') {
            rows = rows.filter(r => r.length !== null && String(r.length).includes(val));
        }
    } else {
        // All Fields — free text across everything visible in the table
        const val = document.getElementById('summaryFilterValueText').value.trim().toLowerCase();
        if (val !== '') {
            rows = rows.filter(r => [
                r.pallet_no, r.date, r.status, r.stock_code, r.product, r.lot_coil, r.roll_no,
                r.customer, r.ref_no, r.width, r.length
            ].some(v => v !== null && String(v).toLowerCase().includes(val)));
        }
    }

    // 2. Sub Filter by Status
    if (statusFilter !== '') {
        rows = rows.filter(r => String(r.status ?? '') === statusFilter);
    }

    // 3. Sub Filter by Suffix
    if (suffixFilter !== '') {
        rows = rows.filter(r => (suffixFilter === 'none') ? (getPalletSuffix(r.pallet_no) === 'none') : (getPalletSuffix(r.pallet_no) === suffixFilter.toUpperCase()));
    }

    renderSummaryTable(rows);
}

function clearSummaryFilter() {
    document.getElementById('summaryFilterCategory').value = '';
    document.getElementById('summaryFilterValueText').value = '';
    document.getElementById('summaryFilterValueDate').value = '';
    if (document.getElementById('summaryFilterStatus')) {
        document.getElementById('summaryFilterStatus').value = '';
    }
    if (document.getElementById('summaryFilterSuffix')) {
        document.getElementById('summaryFilterSuffix').value = '';
    }
    document.getElementById('summaryFilterValueText').classList.remove('d-none');
    document.getElementById('summaryFilterValueSelect').classList.add('d-none');
    document.getElementById('summaryFilterValueDate').classList.add('d-none');
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

function openReopenModal(palletId, palletNo) {
    const pId = palletId || (typeof PALLET_ID !== 'undefined' ? PALLET_ID : 0);
    const pNo = palletNo || (typeof currentPalletNo !== 'undefined' ? currentPalletNo : '') || ('#' + pId);

    const idInput     = document.getElementById('reopenModalPalletId');
    const noEl        = document.getElementById('reopenModalPalletNo');
    const reasonInput = document.getElementById('reopenReasonInput');

    if (idInput)     idInput.value = pId;
    if (noEl)        noEl.textContent = pNo;
    if (reasonInput) reasonInput.value = '';

    const modalEl = document.getElementById('reopenReasonModal');
    if (!modalEl) return;

    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
        modal.show();
    } else if (typeof $ !== 'undefined' && $.fn && $.fn.modal) {
        $(modalEl).modal('show');
    }
}

// ─────────────────────────────────────────────────────────────
// INLINE CUSTOMER / REF NO EDIT (constraint badge header)
// Standardized Customer selection component + auto-population
// ─────────────────────────────────────────────────────────────
const PALLET_CUSTOMERS_MAP = {
    'NAE':      'NICHIAS AUTOPARTS EUROPE (NAE)',
    'NAX':      'NAX MFG, SA.DE C.V',
    'NCI MFG':  'NCI MFG., INC.',
    'TAIHO':    'TAIHO MFG OF TN. INC',
    'NRI':      'PT NICHIAS ROCKWOOL IND.',
    'ASHUKA':   'ASHUKA TECHNOLOGIES SDN. BHD.',
    'NIPPON':   'NTC(NIPPON GASKET)',
    'NTC':      'NICHIAS THAILAND',
    'SGC':      'SHANGHAI XINGSHENG',
    'STAMPING': 'MK STAMPING',
    'YANTAI':   'NICHIAS (SHANGHAI) AUTOPARTS TRADING',
    'NIPP':     'NICHIAS IND.PRODUCTS PVT. LTD.',
    'NVC':      'NICHIAS VIETNAM CO., LTD',
    'NSJ':      'NC-PT NICHIAS SUNIJAYA',
    'NIP':      'SUZHOU NICHIAS IND. PRODUCTS',
    'YTEC':     'YTEC CO., LTD.',
    'NSA':      'NICHIAS SOUTH EAST ASIA (UP PACKING)',
    'NCI 2':    'NCI 2',
    'STOCK':    'STOCK',
    'TRIAL':    'TRIAL'
};

let constraintEditSaving = false;
let currentConstraintCustomer = <?= json_encode($activePallet['customer_name'] ?? '') ?>;
let currentConstraintRefNo    = <?= json_encode($activePallet['ref_no'] ?? '') ?>;

function handleConstraintCustomerChange() {
    const sel = document.getElementById('constraintCustomerInput');
    const customEl = document.getElementById('constraintCustomCustomerInput');
    const refNoEl = document.getElementById('constraintRefNoInput');
    if (!sel) return;

    const val = sel.value;
    if (customEl) {
        customEl.style.display = (val === 'OTHER') ? 'block' : 'none';
        if (val === 'OTHER') customEl.focus();
    }

    if (refNoEl) {
        if (val === 'STOCK') {
            refNoEl.value = 'STOCK';
        } else if (refNoEl.value === 'STOCK' || !refNoEl.value.trim()) {
            refNoEl.value = 'SO-';
        }
    }
}

function startEditConstraint() {
    document.getElementById('constraintDisplayGroup').classList.add('d-none');
    const form = document.getElementById('constraintEditForm');
    form.classList.remove('d-none');
    form.classList.add('d-flex');
    document.getElementById('constraintHintText').classList.add('d-none');
    hideConstraintEditError();

    const customerSelect = document.getElementById('constraintCustomerInput');
    const customInput    = document.getElementById('constraintCustomCustomerInput');
    const refNoInput     = document.getElementById('constraintRefNoInput');

    let matchedCode = '';
    if (currentConstraintCustomer) {
        if (PALLET_CUSTOMERS_MAP[currentConstraintCustomer]) {
            matchedCode = currentConstraintCustomer;
        } else {
            for (const [code, fullName] of Object.entries(PALLET_CUSTOMERS_MAP)) {
                if (fullName === currentConstraintCustomer) {
                    matchedCode = code;
                    break;
                }
            }
        }
    }

    if (matchedCode) {
        customerSelect.value = matchedCode;
        if (customInput) customInput.style.display = 'none';
    } else if (currentConstraintCustomer) {
        customerSelect.value = 'OTHER';
        if (customInput) {
            customInput.value = currentConstraintCustomer;
            customInput.style.display = 'block';
        }
    } else {
        customerSelect.value = '';
        if (customInput) customInput.style.display = 'none';
    }

    refNoInput.value = currentConstraintRefNo;
    customerSelect.focus();
}

function cancelEditConstraint() {
    const form = document.getElementById('constraintEditForm');
    form.classList.add('d-none');
    form.classList.remove('d-flex');
    document.getElementById('constraintDisplayGroup').classList.remove('d-none');
    document.getElementById('constraintHintText').classList.remove('d-none');

    const customerSelect = document.getElementById('constraintCustomerInput');
    const customInput    = document.getElementById('constraintCustomCustomerInput');
    if (customerSelect) customerSelect.value = '';
    if (customInput) { customInput.value = ''; customInput.style.display = 'none'; }
    document.getElementById('constraintRefNoInput').value = currentConstraintRefNo;
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
    const el = document.getElementById('constraintEditError');
    if (el) el.classList.add('d-none');
}

async function saveConstraintEdit() {
    if (constraintEditSaving) return;

    const customerSelect = document.getElementById('constraintCustomerInput');
    const customInput    = document.getElementById('constraintCustomCustomerInput');
    const refNoInput     = document.getElementById('constraintRefNoInput');

    let newCustomer = customerSelect ? customerSelect.value.trim() : '';
    if (newCustomer === 'OTHER' && customInput) {
        newCustomer = customInput.value.trim();
    }
    const newRefNo = refNoInput.value.trim();

    if (newCustomer === '') {
        showConstraintEditError('Please select a Customer.');
        if (customerSelect) customerSelect.focus();
        return;
    }
    if (newRefNo === '') {
        showConstraintEditError('Ref No cannot be empty.');
        if (refNoInput) refNoInput.focus();
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
            if (customerSelect) customerSelect.focus();
            return;
        }

        // Success — update the badges in place and drop out of edit mode.
        currentConstraintCustomer = data.customer_name || newCustomer;
        currentConstraintRefNo    = data.ref_no || newRefNo;

        const custTextEl = document.getElementById('constraintCustomerText');
        if (custTextEl) custTextEl.textContent = currentConstraintCustomer;

        const refTextEl = document.getElementById('constraintRefNoText');
        if (refTextEl) refTextEl.textContent = currentConstraintRefNo;

        cancelEditConstraint();

        // Refresh the right-side Pallets list so the updated card shows
        // immediately, without a full page reload.
        if (typeof loadPalletList === 'function') loadPalletList();

    } catch (e) {
        console.error('saveConstraintEdit error:', e);
        showConstraintEditError((e && e.message) ? e.message : 'Network error — please try again.');
        if (customerSelect) customerSelect.focus();
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
const VALID_PALLET_TABS = ['all', 'open', 'qc', 'approved', 'rejected', 'delivered'];
let palletListData  = [];

// Initialize active tab from URL query parameter 'tab', falling back to localStorage
const urlParams   = new URLSearchParams(window.location.search);
const initialTab  = urlParams.get('tab') || localStorage.getItem('palletActiveTab') || 'all';
let palletActiveTab = VALID_PALLET_TABS.includes(initialTab) ? initialTab : 'all';

const initialSuffix = urlParams.get('suffix') || localStorage.getItem('palletActiveSuffix') || 'all';
let palletActiveSuffix = ['all', 'none', 'B', 'BN'].includes(initialSuffix) ? initialSuffix : 'all';

const initialSort = urlParams.get('sort') || localStorage.getItem('palletActiveSort') || 'latest';
let palletActiveSort = ['latest', 'updated', 'id'].includes(initialSort) ? initialSort : 'latest';

const initialDate = urlParams.get('date') || localStorage.getItem('palletActiveDate') || '';
let palletActiveDate = initialDate;

// Sync initial UI state for tab buttons
function syncTabButtonsUI() {
    document.querySelectorAll('#palletTabGroup .pallet-tab').forEach(btn => {
        if (btn.dataset.group === palletActiveTab) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
}
syncTabButtonsUI();

function syncSuffixButtonsUI() {
    document.querySelectorAll('#palletSuffixGroup .pallet-suffix-btn').forEach(btn => {
        if (btn.dataset.suffix === palletActiveSuffix) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
    if (document.getElementById('palletSortSelect')) {
        document.getElementById('palletSortSelect').value = palletActiveSort;
    }
    if (document.getElementById('palletDateFilter')) {
        document.getElementById('palletDateFilter').value = palletActiveDate;
        const clearBtn = document.getElementById('palletDateClearBtn');
        if (clearBtn) {
            if (palletActiveDate) clearBtn.classList.remove('d-none');
            else clearBtn.classList.add('d-none');
        }
    }
}
syncSuffixButtonsUI();

let palletSearchDebounce = null;

async function loadPalletList() {
    const q    = document.getElementById('palletSearchInput').value.trim();
    const sort = document.getElementById('palletSortSelect')?.value || palletActiveSort || 'latest';
    const date = document.getElementById('palletDateFilter')?.value || '';
    palletActiveSort = sort;
    localStorage.setItem('palletActiveSort', sort);
    palletActiveDate = date;
    localStorage.setItem('palletActiveDate', date);

    const clearBtn = document.getElementById('palletDateClearBtn');
    if (clearBtn) {
        if (date) clearBtn.classList.remove('d-none');
        else clearBtn.classList.add('d-none');
    }

    document.getElementById('palletListScroll').innerHTML =
        `<div class="pallet-list-loading"><div class="spinner-border spinner-border-sm me-2"></div>Loading pallets…</div>`;

    try {
        const params = new URLSearchParams({ ajax: 'list_pallets', group: 'all', q, sort, suffix: palletActiveSuffix, date: palletActiveDate });
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

function clearPalletDateFilter() {
    const input = document.getElementById('palletDateFilter');
    if (input) input.value = '';
    loadPalletList();
}

function renderPalletList() {
    syncTabButtonsUI();
    syncSuffixButtonsUI();
    const counts = { all: palletListData.length, open: 0, qc: 0, approved: 0, rejected: 0, delivered: 0, closed: 0 };
    palletListData.forEach(p => { if (counts[p.status_group] !== undefined) counts[p.status_group]++; });
    document.querySelectorAll('.tab-count').forEach(el => {
        el.textContent = counts[el.dataset.count] ?? 0;
    });

    const rows = palletActiveTab === 'all'
        ? [...palletListData]
        : palletListData.filter(p => p.status_group === palletActiveTab);

    document.getElementById('palletListCount').textContent =
        rows.length + (rows.length === 1 ? ' pallet' : ' pallets');

    const scroll = document.getElementById('palletListScroll');

    if (!rows.length) {
        scroll.innerHTML = `<div class="pallet-list-empty">No pallets match your filters.</div>`;
        return;
    }

    const tabParam = palletActiveTab && palletActiveTab !== 'all' ? `&tab=${encodeURIComponent(palletActiveTab)}` : '';

    scroll.innerHTML = rows.map(p => {
        const badgeClass = SUMMARY_STATUS_BADGE[p.status] || 'badge-building';
        const isActive   = PALLET_ID && Number(p.id) === Number(PALLET_ID);
        const pct        = Math.min(100, Math.round((p.roll_count / p.max_rolls) * 100));
        return `
            <a href="pallet.php?pallet_id=${p.id}${tabParam}"
               class="pallet-card border-${escHtml(p.status)} ${isActive ? 'active' : ''}">
                <div class="pallet-card-top">
                    <div>
                        <div class="pallet-card-id">
                            ${escHtml(p.pallet_no)}
                            ${isActive ? '<span class="pallet-card-open-pill"><i class="bi bi-eye-fill"></i> Open</span>' : ''}
                        </div>
                        ${p.created_by ? `<div class="pallet-card-operator" style="font-size:11px; color:#475569; font-weight:600;"><i class="bi bi-person-fill text-primary me-1"></i>${escHtml(p.created_by)}</div>` : ''}
                        ${p.lot_nos ? `<div class="pallet-card-lot">${escHtml(p.lot_nos)}</div>` : ''}
                    </div>
                    <div class="d-flex flex-column align-items-end">
                        <span class="badge ${badgeClass}">${escHtml(summaryStatusLabel(p.status))}</span>
                        ${p.created_date ? `<div class="pallet-card-date"><i class="bi bi-calendar-event me-1"></i>${escHtml(p.created_date)}</div>` : ''}
                    </div>
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

    if (PALLET_ID) {
        const activeCard = scroll.querySelector('.pallet-card.active');
        if (activeCard) {
            activeCard.scrollIntoView({ block: 'nearest', behavior: 'auto' });
        }
    }
}

document.getElementById('palletTabGroup')?.addEventListener('click', e => {
    const btn = e.target.closest('.pallet-tab');
    if (!btn) return;
    document.querySelectorAll('#palletTabGroup .pallet-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    palletActiveTab = btn.dataset.group;
    localStorage.setItem('palletActiveTab', palletActiveTab);

    // Update URL query parameter without full reload
    const url = new URL(window.location.href);
    if (palletActiveTab && palletActiveTab !== 'all') {
        url.searchParams.set('tab', palletActiveTab);
    } else {
        url.searchParams.delete('tab');
    }
    window.history.replaceState({}, '', url);

    renderPalletList();
});

document.getElementById('palletTabGroup')?.addEventListener('dblclick', e => {
    const scroll = document.getElementById('palletListScroll');
    if (scroll) {
        scroll.scrollTo({ top: 0, behavior: 'smooth' });
    }
});

document.getElementById('palletSuffixGroup')?.addEventListener('click', e => {
    const btn = e.target.closest('.pallet-suffix-btn');
    if (!btn) return;
    document.querySelectorAll('#palletSuffixGroup .pallet-suffix-btn').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    palletActiveSuffix = btn.dataset.suffix;
    localStorage.setItem('palletActiveSuffix', palletActiveSuffix);

    loadPalletList();
});

document.getElementById('palletSuffixGroup')?.addEventListener('dblclick', e => {
    const scroll = document.getElementById('palletListScroll');
    if (scroll) {
        scroll.scrollTo({ top: 0, behavior: 'smooth' });
    }
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

loadPalletList();

function exportSummaryPallet() {
    const cat    = document.getElementById('summaryFilterCategory').value;
    const status = document.getElementById('summaryFilterStatus') ? document.getElementById('summaryFilterStatus').value : '';
    const suffix = document.getElementById('summaryFilterSuffix') ? document.getElementById('summaryFilterSuffix').value : '';
    let val      = '';

    if (cat === 'date') {
        val = document.getElementById('summaryFilterValueDate').value;
    } else if (cat === 'customer' || cat === 'product' || cat === 'suffix') {
        val = document.getElementById('summaryFilterValueSelect').value;
    } else {
        val = document.getElementById('summaryFilterValueText').value.trim();
    }

    const params = new URLSearchParams({ export: 'summary_pallet' });
    if (cat)    params.set('cat', cat);
    if (val)    params.set('val', val);
    if (status) params.set('status', status);
    if (suffix) params.set('suffix', suffix);

    // Plain navigation — the server responds with Content-Disposition:
    // attachment, so this triggers a download without leaving the page.
    window.location.href = 'pallet.php?' + params.toString();
}

async function setActiveOperator(name) {
    const fd = new FormData();
    fd.append('ajax', 'set_active_operator');
    fd.append('operator_name', name);
    try {
        await fetch('pallet.php', { method: 'POST', body: fd });
    } catch(e) {
        console.error('Failed to set active operator:', e);
    }
}

async function submitAddOperator() {
    const input = document.getElementById('newOperatorNameInput');
    const name = input ? input.value.trim() : '';
    const feedback = document.getElementById('addOperatorFeedback');

    if (!name) {
        if (feedback) {
            feedback.textContent = 'Please enter an operator name.';
            feedback.classList.remove('d-none');
        }
        return;
    }
    if (feedback) feedback.classList.add('d-none');

    const fd = new FormData();
    fd.append('ajax', 'add_operator');
    fd.append('operator_name', name);

    try {
        const res = await fetch('pallet.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            const select = document.getElementById('activeOperatorSelect');
            if (select) {
                let existingOpt = Array.from(select.options).find(opt => opt.value.toLowerCase() === data.name.toLowerCase());
                if (!existingOpt) {
                    const opt = document.createElement('option');
                    opt.value = data.name;
                    opt.textContent = data.name;
                    select.appendChild(opt);
                    existingOpt = opt;
                }
                select.value = data.name;
            }

            const modalEl = document.getElementById('addOperatorModal');
            if (modalEl) {
                const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                modal.hide();
            }
            if (input) input.value = '';
        } else {
            if (feedback) {
                feedback.textContent = data.msg || 'Error adding operator.';
                feedback.classList.remove('d-none');
            }
        }
    } catch(e) {
        if (feedback) {
            feedback.textContent = e.message;
            feedback.classList.remove('d-none');
        }
    }
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
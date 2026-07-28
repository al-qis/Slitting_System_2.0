<?php
// finish_product_export.php
// ============================================================
// Real Excel export handler for the Finish Product page.
//
// This file previously was an accidental exact duplicate of
// finish_product.php (full HTML page, no export logic at all) —
// that's why "Download Excel" appeared to do nothing: clicking it
// redirected here, and this just re-rendered the same web page
// instead of generating a spreadsheet.
//
// It now reuses the EXACT same filter/query logic as
// finish_product.php (same tab conditions, same tokenized search,
// same lot/coil exact-match, same sort handling) so the exported
// spreadsheet always matches whatever was on screen — driven by the
// query string forwarded from finish_product.php's redirect.
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

// ── Read filters (mirrors finish_product.php exactly) ──────────
$month  = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year   = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$day = isset($_GET['day']) ? (int)$_GET['day'] : 0;
if ($day < 0 || $day > 31) { $day = 0; }

$filter_lot  = isset($_GET['lot_no'])  ? trim($_GET['lot_no'])  : '';
$filter_coil = isset($_GET['coil_no']) ? trim($_GET['coil_no']) : '';

$filter_card = $_GET['filter'] ?? 'in_pending';
if (!in_array($filter_card, ['in_pending', 'stock', 'palletised', 'waiting', 'deliver', 'produced_month', 'stock_month_end'], true)) {
    $filter_card = 'in_pending';
}

$daysInSelectedMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
if ($day > $daysInSelectedMonth) { $day = 0; }

if ($month < 1 || $month > 12) { $month = (int)date('m'); }
if ($year < 2000 || $year > 2100) { $year = (int)date('Y'); }

$sort_col = isset($_GET['sort_col']) ? $_GET['sort_col'] : '';
if (!in_array($sort_col, ['date_in', 'date_out'], true)) { $sort_col = ''; }
$sort_dir = (isset($_GET['sort_dir']) && strtoupper($_GET['sort_dir']) === 'ASC') ? 'ASC' : 'DESC';

// ── Card filter SQL condition + per-tab default sort column ────
$cardCondition = '';
$sortColumn    = 'sp.date_in';
if ($filter_card === 'in_pending') {
    $cardCondition = " AND sp.status = 'IN' AND sp.is_completed = 0";
    $sortColumn    = 'sp.date_in';
} elseif ($filter_card === 'stock') {
    $cardCondition = " AND sp.status = 'IN' AND sp.stock_counted = 1 AND pi.pallet_id IS NULL";
    $sortColumn    = 'sp.date_in';
} elseif ($filter_card === 'palletised') {
    $cardCondition = " AND sp.status = 'IN' AND pi.pallet_id IS NOT NULL AND p.status = 'building'";
    $sortColumn    = 'sp.date_in';
} elseif ($filter_card === 'waiting') {
    $cardCondition = " AND sp.status = 'WAITING'";
    $sortColumn    = 'sp.date_out';
} elseif ($filter_card === 'deliver') {
    $cardCondition = " AND sp.status = 'DELIVERED'";
    $sortColumn    = 'sp.delivered_at';
}

// ── Main query (identical logic to finish_product.php) ─────────
$baseTypes  = '';
$baseParams = [];

if ($filter_card === 'produced_month') {
    $baseSql = "
        SELECT sp.*,
               pi.pallet_id,
               p.pallet_no,
               p.status AS pallet_status
        FROM slitting_product sp
        LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
        LEFT JOIN pallets p       ON p.id = pi.pallet_id
        WHERE sp.is_voided = 0
          AND MONTH(sp.date_in) = ? AND YEAR(sp.date_in) = ?"
          . ($day > 0 ? " AND DAY(sp.date_in) = ?" : "");
    $sortColumn = 'sp.date_in';
    $baseTypes  = $day > 0 ? 'iii' : 'ii';
    $baseParams = $day > 0 ? [$month, $year, $day] : [$month, $year];

} elseif ($filter_card === 'stock_month_end') {
    $eom = ($day > 0)
        ? sprintf('%04d-%02d-%02d 23:59:59', $year, $month, $day)
        : date('Y-m-t 23:59:59', strtotime("$year-$month-01"));
    $baseSql = "
        SELECT sp.*,
               pi.pallet_id,
               p.pallet_no,
               p.status AS pallet_status
        FROM slitting_product sp
        LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
        LEFT JOIN pallets p       ON p.id = pi.pallet_id
        LEFT JOIN (
            SELECT entity_id, MIN(performed_at) AS recoil_date
            FROM process_log
            WHERE entity_type = 'slitting' AND action_detail = 'send_to_recoiling'
            GROUP BY entity_id
        ) rc ON rc.entity_id = sp.id
        LEFT JOIN (
            SELECT entity_id, MIN(performed_at) AS reslit_date
            FROM process_log
            WHERE entity_type = 'slitting' AND action_detail = 'send_to_reslit'
            GROUP BY entity_id
        ) rs ON rs.entity_id = sp.id
        WHERE sp.is_voided = 0
          AND sp.date_in <= ?
          AND (sp.date_out     IS NULL OR sp.date_out     > ?)
          AND (sp.delivered_at IS NULL OR sp.delivered_at > ?)
          AND (rc.recoil_date  IS NULL OR rc.recoil_date  > ?)
          AND (rs.reslit_date  IS NULL OR rs.reslit_date  > ?)";
    $sortColumn = 'sp.date_in';
    $baseTypes  = 'sssss';
    $baseParams = [$eom, $eom, $eom, $eom, $eom];

} else {
    $dayCondOut       = $day > 0 ? " AND DAY(sp.date_out) = ?"     : "";
    $dayCondDelivered = $day > 0 ? " AND DAY(sp.delivered_at) = ?" : "";
    $baseSql = "
        SELECT sp.*,
               pi.pallet_id,
               p.pallet_no,
               p.status AS pallet_status
        FROM slitting_product sp
        LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
        LEFT JOIN pallets p       ON p.id = pi.pallet_id
        WHERE sp.is_voided = 0
          AND (sp.is_recoiled = 0 OR sp.is_recoiled IS NULL)
          AND (sp.is_reslitted = 0 OR sp.is_reslitted IS NULL)
          AND (
              sp.status = 'IN'
              OR sp.status = 'WAITING'
              OR (sp.status IN ('OUT','APPROVED','REJECTED')
                  AND MONTH(sp.date_out) = ? AND YEAR(sp.date_out) = ?{$dayCondOut})
              OR (sp.status = 'DELIVERED'
                  AND MONTH(sp.delivered_at) = ? AND YEAR(sp.delivered_at) = ?{$dayCondDelivered})
          )
          {$cardCondition}";
    $sortColumn = $sortColumn ?: 'sp.date_in';
    if ($day > 0) {
        $baseTypes  = 'iiiiii';
        $baseParams = [$month, $year, $day, $month, $year, $day];
    } else {
        $baseTypes  = 'iiii';
        $baseParams = [$month, $year, $month, $year];
    }
}

// ── Tokenized search (identical logic to finish_product.php) ───
$searchTokens = ($search !== '')
    ? preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY)
    : [];

if (!empty($searchTokens)) {
    $tokenClauses = array_fill(
        0,
        count($searchTokens),
        "(sp.product LIKE ? OR sp.lot_no LIKE ? OR sp.coil_no LIKE ? OR sp.roll_no LIKE ? OR sp.id LIKE ? OR p.pallet_no LIKE ?)"
    );
    $baseSql .= " AND (" . implode(" AND ", $tokenClauses) . ")";
}

if ($filter_lot !== '')  { $baseSql .= " AND sp.lot_no = ?";  }
if ($filter_coil !== '') { $baseSql .= " AND sp.coil_no = ?"; }

if ($sort_col !== '') {
    $sortColumn = 'sp.' . $sort_col;
}
$finalSortDir = ($sort_col !== '') ? $sort_dir : 'DESC';

$baseSql .= " ORDER BY {$sortColumn} {$finalSortDir}, sp.id DESC";

$stmt = $conn->prepare($baseSql);
if (!$stmt) { die("Query prepare failed: " . htmlspecialchars($conn->error)); }

$types  = $baseTypes;
$params = $baseParams;

foreach ($searchTokens as $token) {
    $like = '%' . $token . '%';
    for ($i = 0; $i < 6; $i++) {
        $types    .= "s";
        $params[] = $like;
    }
}

if ($filter_lot !== '')  { $types .= "s"; $params[] = $filter_lot;  }
if ($filter_coil !== '') { $types .= "s"; $params[] = $filter_coil; }

$bindArgs = [$types];
foreach ($params as $key => $value) {
    $bindArgs[] = &$params[$key];
}
call_user_func_array([$stmt, 'bind_param'], $bindArgs);

$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

// ── Build & stream the Excel (.xls) file ─────────────────────────
$tabLabels = [
    'in_pending'      => 'IN (Pending)',
    'stock'           => 'Finish Good Stock',
    'palletised'      => 'Palletised',
    'waiting'         => 'Waiting QC',
    'deliver'         => 'Delivered',
    'produced_month'  => 'Produced This Month',
    'stock_month_end' => 'Stock Balance (Month End)',
];
$tabLabel  = $tabLabels[$filter_card] ?? $filter_card;
$periodLbl = $day > 0
    ? date('j F Y', mktime(0, 0, 0, $month, $day, $year))
    : date('F Y', mktime(0, 0, 0, $month, 1, $year));

$filename = 'Finish_Product_' . preg_replace('/[^A-Za-z0-9]+/', '_', $tabLabel) . '_' . date('Y-m-d') . '.xls';

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$cols      = 14; // Status, Origin, Product, Lot No, Roll No, Width, Length, Actual, NOD, Invoice Length, Customer, Ref No, Date In, Date Out
$generated = date('d M Y, H:i');
?>
<html><head><meta charset="UTF-8"></head><body>

<table>
    <tr>
        <td colspan="<?= $cols ?>" style="background:#1e3a5f;color:#fff;font-size:18px;font-weight:bold;padding:12px 16px;letter-spacing:1px;">
            FINISH PRODUCT REPORT &mdash; <?= htmlspecialchars(strtoupper($tabLabel)) ?>
        </td>
    </tr>
    <tr>
        <td colspan="<?= $cols ?>" style="background:#2c5282;color:#bee3f8;font-size:11px;padding:4px 16px;">
            Generated: <?= $generated ?> &nbsp;|&nbsp; Period: <?= htmlspecialchars($periodLbl) ?> &nbsp;|&nbsp; System: Slitting Management
        </td>
    </tr>
    <tr><td colspan="<?= $cols ?>"></td></tr><!-- spacer -->
</table>

<table border="1" style="border-collapse:collapse;">
    <thead>
        <tr style="background:#343a40;color:#fff;font-weight:bold;font-size:12px;">
            <th style="padding:8px 10px;">Status</th>
            <th style="padding:8px 10px;">Origin</th>
            <th style="padding:8px 10px;">Product</th>
            <th style="padding:8px 10px;">Lot No</th>
            <th style="padding:8px 10px;">Roll No.</th>
            <th style="padding:8px 10px;">Width (mm)</th>
            <th style="padding:8px 10px;">Length (m)</th>
            <th style="padding:8px 10px;">Actual (m)</th>
            <th style="padding:8px 10px;">NOD (m)</th>
            <th style="padding:8px 10px;">Invoice Length (m)</th>
            <th style="padding:8px 10px;">Customer</th>
            <th style="padding:8px 10px;">Ref No</th>
            <th style="padding:8px 10px;">Date In</th>
            <th style="padding:8px 10px;">Date Out</th>
        </tr>
    </thead>
    <tbody>
<?php
$td  = 'style="padding:6px 10px;"';
$tdN = 'style="padding:6px 10px;text-align:right;"';

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $originalSource = $row['original_source'] ?? $row['source'] ?? 'raw_material';
        $originLabelTxt = match (trim(strtolower($originalSource))) {
            'sfc'          => 'SFC',
            'raw_material' => 'RAW MAT',
            default        => strtoupper($originalSource),
        };

        $lotCoil = trim(($row['lot_no'] ?? '') . ' ' . ($row['coil_no'] ?? ''));

        $hasNod       = !empty($row['nod_length']) && (float)$row['nod_length'] > 0;
        $nodValue     = $hasNod ? (float)$row['nod_length'] : 0;
        $nodDisplay   = $hasNod ? number_format($nodValue, 2) : '-';
        $invoiceLength = number_format((float)($row['actual_length'] ?? 0) - $nodValue, 2);

        echo '<tr>';
        echo '<td ' . $td  . '>' . htmlspecialchars(strtoupper($row['status'] ?? '-')) . '</td>';
        echo '<td ' . $td  . '>' . htmlspecialchars($originLabelTxt) . '</td>';
        echo '<td ' . $td  . '>' . htmlspecialchars($row['product'] ?? '-') . '</td>';
        echo '<td ' . $td  . '>' . htmlspecialchars($lotCoil) . '</td>';
        echo '<td ' . $td  . '>' . htmlspecialchars($row['roll_no'] ?? '-') . '</td>';
        echo '<td ' . $tdN . '>' . number_format((float)($row['width']         ?? 0)) . '</td>';
        echo '<td ' . $tdN . '>' . number_format((float)($row['length']        ?? 0)) . '</td>';
        echo '<td ' . $tdN . '>' . number_format((float)($row['actual_length'] ?? 0)) . '</td>';
        echo '<td ' . $tdN . '>' . $nodDisplay . '</td>';
        echo '<td ' . $tdN . '>' . $invoiceLength . '</td>';
        echo '<td ' . $td  . '>' . htmlspecialchars($row['customer_name'] ?: '-') . '</td>';
        echo '<td ' . $td  . '>' . htmlspecialchars($row['ref_no']        ?: '-') . '</td>';
        echo '<td ' . $td  . '>' . htmlspecialchars($row['date_in']  ?? '-') . '</td>';
        echo '<td ' . $td  . '>' . htmlspecialchars($row['date_out'] ?? '-') . '</td>';
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
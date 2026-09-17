<?php
// recoiling_summary_ajax.php
// Returns JSON: { counts: {rewinding, normal, cut_into_2}, rows: [...] }
// Called by the Summary Report modal in recoiling.php.
//
// Params:
//   ?month=YYYY-MM        — filter by month (optional)
//   ?cut_type=rewinding   — filter by type: rewinding | normal | cut_into_2 | '' = all

session_start();
if (!isset($_SESSION['role'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

include 'config.php';
header('Content-Type: application/json');

$month    = trim($_GET['month']    ?? '');
$cut_type = trim($_GET['cut_type'] ?? '');

$valid_types = ['rewinding', 'normal', 'cut_into_2'];

// ── Base WHERE (always filter completed) ─────────────────────
$base_where  = "WHERE rp.status = 'completed'";
$base_types  = '';
$base_vals   = [];

if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
    $base_where  .= " AND DATE_FORMAT(rp.completed_at, '%Y-%m') = ?";
    $base_types  .= 's';
    $base_vals[]  = $month;
}

// ── KPI counts always show all 3 types (month filter only) ───
// So the 3 cards always reflect the full month totals regardless
// of which type filter button the user pressed.
$count_sql = "
    SELECT cut_type, COUNT(*) AS cnt
    FROM recoiling_product rp
    $base_where
    GROUP BY cut_type
";

$stmt = $conn->prepare($count_sql);
if ($base_types) {
    $stmt->bind_param($base_types, ...$base_vals);
}
$stmt->execute();
$count_res = $stmt->get_result();
$stmt->close();

$counts = ['rewinding' => 0, 'normal' => 0, 'cut_into_2' => 0];
while ($row = $count_res->fetch_assoc()) {
    $ct = $row['cut_type'] ?? '';
    if (isset($counts[$ct])) {
        $counts[$ct] = (int)$row['cnt'];
    }
}

// ── Detail rows — apply cut_type filter on top of base ───────
$detail_where  = $base_where;
$detail_types  = $base_types;
$detail_vals   = $base_vals;

if (in_array($cut_type, $valid_types, true)) {
    $detail_where  .= " AND rp.cut_type = ?";
    $detail_types  .= 's';
    $detail_vals[]  = $cut_type;
}

$detail_sql = "
    SELECT
        rp.id,
        rp.cut_type,
        rp.completed_at,
        rp.new_length,
        IFNULL(rp.product,  mc.product)  AS product,
        IFNULL(rp.lot_no,   mc.lot_no)   AS lot_no,
        IFNULL(rp.coil_no,  mc.coil_no)  AS coil_no,
        IFNULL(rp.roll_no,  '-')         AS roll_no
    FROM recoiling_product rp
    LEFT JOIN mother_coil mc ON rp.mother_id = mc.id
    $detail_where
    ORDER BY rp.completed_at DESC
    LIMIT 500
";

$stmt2 = $conn->prepare($detail_sql);
if ($detail_types) {
    $stmt2->bind_param($detail_types, ...$detail_vals);
}
$stmt2->execute();
$detail_res = $stmt2->get_result();
$stmt2->close();

$rows = [];
while ($r = $detail_res->fetch_assoc()) {
    $rows[] = [
        'id'           => $r['id'],
        'cut_type'     => $r['cut_type'] ?? '',
        'completed_at' => $r['completed_at'] ? date('d M Y', strtotime($r['completed_at'])) : null,
        'lot_no'       => $r['lot_no']   ?? '-',
        'coil_no'      => $r['coil_no']  ?? '-',
        'roll_no'      => $r['roll_no']  ?? '-',
        'product'      => $r['product']  ?? '-',
        'new_length'   => (float)($r['new_length'] ?? 0),
    ];
}

echo json_encode([
    'month'    => $month ?: 'all',
    'cut_type' => $cut_type ?: 'all',
    'counts'   => $counts,
    'rows'     => $rows,
], JSON_UNESCAPED_UNICODE);
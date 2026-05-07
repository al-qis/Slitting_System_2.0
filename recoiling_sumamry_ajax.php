<?php
// recoiling_summary_ajax.php
// Returns JSON: { counts: {rewinding, normal, cut_into_2}, rows: [...] }
// Called by the Summary Report modal in recoiling.php.

session_start();
if (!isset($_SESSION['role'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

include 'config.php';
header('Content-Type: application/json');

$month = trim($_GET['month'] ?? ''); // e.g. "2025-03"  or ""

// Build optional WHERE clause for month filter
// Filter on completed_at (or date_in as fallback) of recoiling_product
$where_sql  = "WHERE rp.status = 'completed'";
$bind_types = '';
$bind_vals  = [];

if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
    $where_sql  .= " AND DATE_FORMAT(rp.completed_at, '%Y-%m') = ?";
    $bind_types  = 's';
    $bind_vals[] = $month;
}

// ── Count per cut_type ────────────────────────────────────────
$count_sql = "
    SELECT cut_type, COUNT(*) AS cnt
    FROM recoiling_product rp
    $where_sql
    GROUP BY cut_type
";

$stmt = $conn->prepare($count_sql);
if ($bind_types) {
    $stmt->bind_param($bind_types, ...$bind_vals);
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

// ── Detail rows ───────────────────────────────────────────────
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
    $where_sql
    ORDER BY rp.completed_at DESC
    LIMIT 500
";

$stmt2 = $conn->prepare($detail_sql);
if ($bind_types) {
    $stmt2->bind_param($bind_types, ...$bind_vals);
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
    'month'  => $month ?: 'all',
    'counts' => $counts,
    'rows'   => $rows,
], JSON_UNESCAPED_UNICODE);
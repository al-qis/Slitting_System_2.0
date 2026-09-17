<?php
// reslit_summary_ajax.php
// Returns JSON: { counts: {normal, cut_into_2}, rows: [...] }

session_start();
if (!isset($_SESSION['role'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

include 'config.php';
header('Content-Type: application/json');

$month = trim($_GET['month'] ?? ''); // e.g. "2025-03" or ""

// ── Build WHERE clause ────────────────────────────────────────
// Use date_in for month filtering (reslit_product has no completed_at/updated_at)
$where_sql  = "WHERE rp.status = 'completed'";
$bind_types = '';
$bind_vals  = [];

if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
    $where_sql  .= " AND DATE_FORMAT(rp.completed_at, '%Y-%m') = ?";
    $bind_types  = 's';
    $bind_vals[] = $month;
}

// ── Check if cut_type column exists ──────────────────────────
$hasCutType = false;
$colCheck = $conn->query("SHOW COLUMNS FROM reslit_product LIKE 'cut_type'");
if ($colCheck && $colCheck->num_rows > 0) {
    $hasCutType = true;
}

// ── Count per cut_type (or total if column missing) ───────────
$counts = ['normal' => 0, 'cut_into_2' => 0];

if ($hasCutType) {
    $count_sql = "SELECT cut_type, COUNT(*) AS cnt FROM reslit_product rp $where_sql GROUP BY cut_type";
    $stmt = $conn->prepare($count_sql);
    if ($bind_types) $stmt->bind_param($bind_types, ...$bind_vals);
    $stmt->execute();
    $count_res = $stmt->get_result();
    $stmt->close();
    while ($row = $count_res->fetch_assoc()) {
        $ct = $row['cut_type'] ?? '';
        if (isset($counts[$ct])) $counts[$ct] = (int)$row['cnt'];
    }
} else {
    // No cut_type column — just count total completed
    $count_sql = "SELECT COUNT(*) AS cnt FROM reslit_product rp $where_sql";
    $stmt = $conn->prepare($count_sql);
    if ($bind_types) $stmt->bind_param($bind_types, ...$bind_vals);
    $stmt->execute();
    $count_res = $stmt->get_result();
    $stmt->close();
    $counts['normal'] = (int)($count_res->fetch_assoc()['cnt'] ?? 0);
}

// ── Detail rows ───────────────────────────────────────────────
$cut_type_select = $hasCutType ? 'rp.cut_type,' : "'normal' AS cut_type,";

$detail_sql = "
    SELECT
        rp.id,
        $cut_type_select
        rp.date_in,
        rp.actual_length,
        rp.product,
        rp.lot_no,
        rp.coil_no,
        rp.roll_no,
        rr.id          AS roll_id,
        rr.roll_no     AS child_roll_no,
        rr.cut_letter,
        rr.new_width,
        rr.actual_length AS child_actual_length
    FROM reslit_product rp
    LEFT JOIN reslit_rolls rr ON rr.parent_id = rp.id
    $where_sql
    ORDER BY rp.id DESC, rr.id ASC
    LIMIT 1000
";

$stmt2 = $conn->prepare($detail_sql);
if ($bind_types) $stmt2->bind_param($bind_types, ...$bind_vals);
$stmt2->execute();
$detail_res = $stmt2->get_result();
$stmt2->close();

// Group child rolls under each parent
$parents = [];
while ($r = $detail_res->fetch_assoc()) {
    $pid = $r['id'];
    if (!isset($parents[$pid])) {
        $parents[$pid] = [
            'id'            => $pid,
            'cut_type'      => $r['cut_type'] ?? 'normal',
            'completed_at'  => $r['date_in'] ? date('d M Y', strtotime($r['date_in'])) : null,
            'product'       => $r['product']  ?? '-',
            'lot_no'        => $r['lot_no']   ?? '-',
            'coil_no'       => $r['coil_no']  ?? '-',
            'roll_no'       => $r['roll_no']  ?? '-',
            'actual_length' => (float)($r['actual_length'] ?? 0),
            'rolls'         => [],
        ];
    }
    if ($r['roll_id']) {
        $parents[$pid]['rolls'][] = [
            'roll_no'       => $r['child_roll_no'] ?? '-',
            'cut_letter'    => $r['cut_letter']    ?? '',
            'new_width'     => (float)($r['new_width'] ?? 0),
            'actual_length' => (float)($r['child_actual_length'] ?? 0),
        ];
    }
}

echo json_encode([
    'month'  => $month ?: 'all',
    'counts' => $counts,
    'rows'   => array_values($parents),
], JSON_UNESCAPED_UNICODE);
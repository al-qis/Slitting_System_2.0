<?php
/**
 * index.php - End-of-Month Stock Scan & Export (Slitting System)
 * -----------------------------------------------------------------------
 * Temporary tool: scan physical QR codes, then export the scanned stock
 * as an Excel file (D365 ITEM NUMBER / D365 LOT NO / MTR) for the
 * supervisor to compare against D365 manually.
 * Scanned progress persists in the PHP session so a refresh doesn't lose
 * anything already scanned.
 * -----------------------------------------------------------------------
 */
require_once dirname(__DIR__) . '/config.php';
$mysqli = $conn;

// Pre-load whatever has already been scanned, from the shared DB table -
// this is what makes progress visible from ANY device (tablet or PC),
// not just the browser that did the scanning.
$existingScans = [];
$sqlScans = "SELECT raw, lot, coil, roll, width, length, product_code,
                    d365_item_number, d365_lot_no, mtr,
                    DATE_FORMAT(scanned_at, '%H:%i:%s') AS scanned_at
             FROM stock_crosscheck_scans
             ORDER BY id DESC";
if ($resScans = $mysqli->query($sqlScans)) {
    while ($row = $resScans->fetch_assoc()) {
        $existingScans[] = $row;
    }
}

// -----------------------------------------------------------------------
// Produced Date Filtering (defaults to all dates, or officer selected cutoff date)
// -----------------------------------------------------------------------
$fromDate = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$toDate   = isset($_GET['to_date'])   ? trim($_GET['to_date'])   : (isset($_GET['cutoff_date']) ? trim($_GET['cutoff_date']) : '');

if ($fromDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = '';
if ($toDate   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate))   $toDate   = '';

$whereDate = [];
if ($fromDate !== '') {
    $whereDate[] = "DATE(COALESCE(sp.date_in, sp.updated_at)) >= '" . $mysqli->real_escape_string($fromDate) . "'";
}
if ($toDate !== '') {
    $whereDate[] = "DATE(COALESCE(sp.date_in, sp.updated_at)) <= '" . $mysqli->real_escape_string($toDate) . "'";
}
$dateClause = count($whereDate) > 0 ? " AND " . implode(" AND ", $whereDate) : "";

$baselineStock = [];
$baselineRolls = [];
$sql = "SELECT COALESCE(NULLIF(sp.product, ''), cpm.product, 'Unknown') AS product_code,
               sp.width, sp.lot_no, sp.coil_no, sp.roll_no
        FROM slitting_product sp
        LEFT JOIN (
            SELECT coil_code, MIN(product) AS product
            FROM coil_product_map
            GROUP BY coil_code
        ) cpm ON cpm.coil_code = SUBSTRING_INDEX(sp.coil_no, '-', 1)
        WHERE sp.status NOT IN ('OUT','DELIVERED')
          AND (sp.is_voided IS NULL OR sp.is_voided = 0)
          AND (sp.is_reslitted = 0 OR sp.is_reslitted IS NULL)
          AND (
              (sp.is_recoiled = 0 OR sp.is_recoiled IS NULL)
              OR (sp.is_recoiled = 1 AND EXISTS (
                  SELECT 1 FROM recoiling_product rp 
                  WHERE rp.slitting_product_id = sp.id AND rp.status = 'pending'
              ))
          )
          {$dateClause}
        ORDER BY sp.lot_no ASC, sp.coil_no ASC, sp.roll_no ASC";
if ($res = $mysqli->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        $p = trim($row['product_code']);
        $w = (float) $row['width'];
        $wClean = ($w > 0) ? ((fmod($w, 1) == 0) ? (int)$w : $w) : null;
        $label = $wClean !== null ? "$p ({$wClean}mm)" : $p;

        if (!isset($baselineStock[$label])) {
            $baselineStock[$label] = 0;
            $baselineRolls[$label] = [];
        }
        $baselineStock[$label]++;
        $baselineRolls[$label][] = [
            'lot'  => $row['lot_no'],
            'coil' => $row['coil_no'],
            'roll' => $row['roll_no']
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>End-of-Month Stock Scan &amp; Export</title>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<style>
  :root {
    --navy:#0f1b2d;
    --navy-2:#16283f;
    --blue:#3b6ef6;
    --blue-dark:#2a55d6;
    --green:#137333;
    --green-bg:#e6f4ea;
    --green-border:#bfe3cb;
    --amber:#b06000;
    --amber-bg:#fef7e0;
    --amber-border:#f5e2ab;
    --red:#c5221f;
    --red-bg:#fce8e6;
    --red-border:#f5c6c3;
    --bg:#eef1f6;
    --card:#ffffff;
    --border:#e6e9f0;
    --text:#1f2937;
    --text-muted:#64748b;
    --radius:12px;
    --shadow-sm:0 1px 2px rgba(15,23,42,.04);
    --shadow-md:0 1px 2px rgba(15,23,42,.04), 0 10px 24px -8px rgba(15,23,42,.12);
  }
  * { box-sizing: border-box; }
  body {
    margin:0;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
    background:
      radial-gradient(1200px 400px at 10% -10%, rgba(59,110,246,.06), transparent),
      var(--bg);
    color:var(--text);
    line-height:1.5;
    -webkit-font-smoothing:antialiased;
  }

  header {
    background:linear-gradient(135deg, var(--navy) 0%, var(--navy-2) 100%);
    color:#fff;
    padding:22px 0;
    box-shadow:0 2px 12px rgba(15,23,42,.15);
    position: sticky;
    top: 0;
    z-index: 1030;
  }
  .header-inner { max-width:1300px; margin:0 auto; padding:0 20px; display:flex; align-items:center; gap:12px; }
  .header-badge {
    width:38px; height:38px; border-radius:10px; flex-shrink:0;
    background:linear-gradient(135deg, var(--blue), #6b8cff);
    display:flex; align-items:center; justify-content:center;
    font-size:17px; box-shadow:var(--shadow-sm);
  }
  header h1 { margin:0; font-size:19px; font-weight:650; letter-spacing:-.01em; }
  header p { margin:3px 0 0; font-size:12.5px; color:#a9b8d6; letter-spacing:.01em; }

  main { max-width:1300px; margin:28px auto; padding:0 20px 60px; }
  .layout { display:flex; gap:22px; align-items:flex-start; }
  .main-col { flex:1.4; min-width:0; }
  .side-col { flex:1; min-width:420px; flex-shrink:0; position:sticky; top:20px; }
  @media (max-width: 900px) {
    .layout { flex-direction:column; }
    .side-col { width:100%; min-width:0; position:static; }
  }

  .card {
    background:var(--card);
    border:1px solid var(--border);
    border-radius:var(--radius);
    padding:22px 24px;
    margin-bottom:22px;
    box-shadow:var(--shadow-md);
  }
  .card h2 {
    margin:0 0 16px;
    padding-bottom:14px;
    border-bottom:1px solid var(--border);
    font-size:15.5px;
    font-weight:650;
    letter-spacing:-.01em;
    display:flex;
    align-items:center;
    gap:10px;
    color:#111827;
  }
  .step-badge {
    background:linear-gradient(135deg, var(--blue), var(--blue-dark));
    color:#fff;
    width:26px; height:26px;
    border-radius:50%;
    display:inline-flex; align-items:center; justify-content:center;
    font-size:12.5px; font-weight:700;
    box-shadow:0 2px 6px rgba(59,110,246,.4);
  }

  .scanner-status-bar {
    display: flex;
    align-items: center;
    gap: 9px;
    background: #e6f4ea;
    border: 1px solid #bfe3cb;
    color: #137333;
    padding: 8px 14px;
    border-radius: 9px;
    font-size: 13px;
    margin-bottom: 12px;
    transition: all .2s ease;
  }
  .scanner-status-bar.paused {
    background: #fff8e1;
    border-color: #ffe082;
    color: #b06000;
  }
  .scanner-indicator {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #137333;
    box-shadow: 0 0 0 3px rgba(19, 115, 51, 0.25);
    animation: pulseDot 1.8s infinite ease-in-out;
  }
  .scanner-status-bar.paused .scanner-indicator {
    background: #f59e0b;
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.25);
    animation: none;
  }
  @keyframes pulseDot {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(19, 115, 51, 0.5); }
    70% { transform: scale(1.05); box-shadow: 0 0 0 8px rgba(19, 115, 51, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(19, 115, 51, 0); }
  }

  #scanInput {
    width:100%;
    padding:15px 18px;
    font-size:16px;
    font-weight:500;
    letter-spacing:.01em;
    color:var(--text);
    background:#f8faff;
    border:1.5px solid var(--border);
    border-radius:10px;
    outline:none;
    transition:border-color .15s ease, box-shadow .15s ease, background-color .15s ease;
  }
  #scanInput::placeholder { color:#9aa5b8; font-weight:400; }
  #scanInput:focus {
    border-color:var(--blue);
    background:#fff;
    box-shadow:0 0 0 4px rgba(59,110,246,.15);
  }
  .hint { font-size:12.5px; color:var(--text-muted); margin-top:8px; line-height:1.5; }

  #scanInput.flash-success {
    border-color: #10b981 !important;
    background: #ecfdf5 !important;
    box-shadow: 0 0 0 5px rgba(16, 185, 129, 0.3) !important;
  }
  #scanInput.flash-error {
    border-color: #ef4444 !important;
    background: #fef2f2 !important;
    box-shadow: 0 0 0 5px rgba(239, 68, 68, 0.3) !important;
    animation: shakeInput 0.4s ease-in-out;
  }
  @keyframes shakeInput {
    0%, 100% { transform: translateX(0); }
    20%, 60% { transform: translateX(-6px); }
    40%, 80% { transform: translateX(6px); }
  }

  #warningBanner {
    display: none;
    margin-top: 14px;
    padding: 14px 18px;
    border-radius: 10px;
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fca5a5;
    border-left: 6px solid #dc2626;
    font-weight: 700;
    font-size: 16px;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.15);
    transition: all .2s ease;
  }
  #warningBanner::before { content: "❌  "; font-size: 18px; }
  #warningBanner.ok {
    background: #dcfce7;
    color: #15803d;
    border-color: #86efac;
    border-left: 6px solid #16a34a;
    box-shadow: 0 4px 14px rgba(22, 163, 74, 0.15);
  }
  #warningBanner.ok::before { content: "✅  "; font-size: 18px; }

  .counter { font-size:13px; color:var(--text-muted); margin-top:12px; }
  .counter b { color:var(--navy); font-size:16px; font-weight:700; }

  .table-wrap { max-height:420px; overflow:auto; border:1px solid var(--border); border-radius:10px; margin-top:14px; }
  .table-wrap table { margin-top:0; border:none; }
  table { width:100%; border-collapse:collapse; margin-top:14px; font-size:13px; }
  thead th {
    position:sticky; top:0; z-index:1;
    background:#f4f6fb;
    font-weight:650;
    color:#3a4552;
    text-transform:uppercase;
    font-size:11px;
    letter-spacing:.04em;
  }
  th, td { padding:10px 12px; border-bottom:1px solid var(--border); text-align:left; white-space:nowrap; }
  tbody tr { transition:background-color .12s ease; }
  tbody tr:hover td { background:#f7f9fd; }
  tbody tr:last-child td { border-bottom:none; }

  .removeBtn {
    color:var(--red);
    cursor:pointer;
    font-size:11.5px;
    font-weight:600;
    border:none;
    background:none;
    padding:4px 8px;
    border-radius:6px;
    transition:background-color .12s ease;
  }
  .removeBtn:hover { background:var(--red-bg); }

  .btn {
    border:none;
    padding:11px 20px;
    border-radius:9px;
    font-size:14px;
    font-weight:650;
    cursor:pointer;
    transition:transform .08s ease, box-shadow .15s ease, background-color .15s ease;
  }
  .btn:active { transform:translateY(1px); }
  .btn-primary {
    background:linear-gradient(135deg, var(--blue), var(--blue-dark));
    color:#fff;
    box-shadow:0 4px 12px rgba(59,110,246,.35);
  }
  .btn-primary:hover { box-shadow:0 6px 16px rgba(59,110,246,.45); background:linear-gradient(135deg, var(--blue-dark), var(--blue-dark)); }
  .btn-ghost {
    background:#eef1f5;
    color:#374151;
    border:1px solid var(--border);
  }
  .btn-ghost:hover { background:#e5e9f0; }

  .btn-camera {
    background: linear-gradient(135deg, #0ea5e9, #0284c7);
    color: #fff;
    border: none;
    box-shadow: 0 4px 12px rgba(14, 165, 233, 0.35);
  }
  .btn-camera:hover {
    background: linear-gradient(135deg, #0284c7, #0369a1);
    box-shadow: 0 6px 16px rgba(14, 165, 233, 0.45);
  }

  /* Camera Modal Styles */
  .camera-modal {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    z-index: 2000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
  }
  .camera-modal-backdrop {
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 27, 45, 0.7);
    backdrop-filter: blur(4px);
  }
  .camera-modal-content {
    position: relative;
    z-index: 2001;
    background: #fff;
    border-radius: 14px;
    width: 100%;
    max-width: 520px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.3);
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }
  .camera-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    background: var(--navy);
    color: #fff;
  }
  .camera-modal-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .close-camera-btn {
    background: transparent;
    border: none;
    color: #94a3b8;
    font-size: 24px;
    font-weight: 700;
    cursor: pointer;
    line-height: 1;
    transition: color .15s ease;
  }
  .close-camera-btn:hover {
    color: #fff;
  }
  .camera-modal-body {
    padding: 16px;
  }
  .camera-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
  }
  .camera-select-dropdown {
    flex: 1;
    padding: 8px 12px;
    font-size: 13px;
    border: 1px solid var(--border);
    border-radius: 7px;
    outline: none;
  }
  .continuous-toggle {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12.5px;
    font-weight: 600;
    color: #475569;
    cursor: pointer;
    user-select: none;
  }
  .camera-reader-container {
    position: relative;
    min-height: 280px;
    background: #000;
    border-radius: 10px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .camera-status-notice {
    position: absolute;
    bottom: 12px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(15, 27, 45, 0.85);
    color: #fff;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    z-index: 10;
  }
  #reader video {
    width: 100% !important;
    border-radius: 10px;
    object-fit: cover;
  }
  .actions { display:flex; gap:10px; margin-top:16px; flex-wrap:wrap; }

  /* Manual Entry Form Styles */
  .manual-entry-card {
    background: #f8faff;
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    padding: 16px 18px;
    margin-top: 14px;
    box-shadow: var(--shadow-sm);
  }
  .manual-entry-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid #e2e8f0;
  }
  .manual-entry-header h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 700;
    color: var(--navy);
  }
  .close-manual-btn {
    background: transparent;
    border: none;
    font-size: 18px;
    font-weight: 700;
    color: var(--text-muted);
    cursor: pointer;
    line-height: 1;
  }
  .close-manual-btn:hover { color: var(--red); }
  .manual-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    align-items: end;
  }
  .form-group-lot {
    grid-column: span 2;
    min-width: 240px;
  }
  @media (max-width: 640px) {
    .form-group-lot {
      grid-column: 1 / -1;
      min-width: 0;
    }
  }
  .manual-form-grid .form-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }
  .manual-form-grid label {
    font-size: 12px;
    font-weight: 650;
    color: #334155;
  }
  .manual-form-grid label .opt {
    font-weight: 400;
    color: #94a3b8;
  }
  .manual-form-grid input {
    padding: 9px 12px;
    font-size: 13.5px;
    border: 1px solid var(--border);
    border-radius: 7px;
    outline: none;
    background: #fff;
    transition: border-color .15s ease;
  }
  .manual-form-grid input:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(59,110,246,.12);
  }
  .form-actions-full {
    grid-column: 1 / -1;
    display: flex;
    gap: 10px;
    margin-top: 4px;
  }

  .input-with-btn {
    display: flex;
    gap: 6px;
    align-items: center;
  }
  .input-with-btn input {
    flex: 1;
    min-width: 100px;
  }
  .btn-fetch-stock {
    background: #e2e8f0;
    color: #1e293b;
    border: 1px solid var(--border);
    padding: 9px 12px;
    font-size: 12.5px;
    font-weight: 650;
    border-radius: 7px;
    cursor: pointer;
    white-space: nowrap;
    transition: background-color .15s ease;
  }
  .btn-fetch-stock:hover {
    background: #cbd5e1;
  }

  .summary-main-row.has-unscanned {
    cursor: pointer;
  }
  .summary-main-row.has-unscanned:hover td {
    background: #f0f7ff !important;
  }
  .expand-toggle-icon {
    display: inline-block;
    width: 14px;
    font-size: 10px;
    color: var(--blue);
    transition: transform .18s ease;
    margin-right: 4px;
  }
  .summary-main-row.expanded .expand-toggle-icon {
    transform: rotate(90deg);
  }
  .summary-detail-row td {
    padding: 0 !important;
    background: #fffdf5 !important;
    border-bottom: 1px solid var(--border);
  }
  .summary-detail-box {
    padding: 10px 14px;
    border-top: 1px dashed #fcd34d;
  }
  .summary-detail-header {
    font-size: 11.5px;
    font-weight: 700;
    color: #b06000;
    margin-bottom: 7px;
  }
  .summary-detail-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    max-height: 180px;
    overflow-y: auto;
  }
  .summary-detail-chip {
    background: #fff;
    border: 1px solid #fde68a;
    color: #1e293b;
    padding: 4px 9px;
    border-radius: 6px;
    font-size: 11.5px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.03);
  }
  .summary-detail-chip b {
    color: var(--navy);
  }

  .manual-stock-notice {
    margin-top: 12px;
    padding: 8px 12px;
    border-radius: 7px;
    font-size: 12.5px;
    font-weight: 600;
  }
  .manual-stock-notice.ok {
    background: #e6f4ea;
    color: #137333;
    border: 1px solid #bfe3cb;
  }
  .manual-stock-notice.warn {
    background: #fef7e0;
    color: #b06000;
    border: 1px solid #f5e2ab;
  }
  .manual-stock-list {
    margin-top: 10px;
    max-height: 220px;
    overflow-y: auto;
    border: 1px solid #cbd5e1;
    border-radius: 9px;
    background: #fff;
    box-shadow: var(--shadow-sm);
  }
  .manual-stock-item {
    padding: 10px 14px;
    font-size: 13px;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all .12s ease;
  }
  .manual-stock-item:hover {
    background: #f0f7ff;
  }
  .manual-stock-item.selected {
    background: #e6f4ea !important;
    border-left: 4px solid var(--green);
  }
  .manual-stock-item.is-scanned {
    background: #f8fafc;
  }
  .stock-item-left {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .stock-coil-badge {
    font-weight: 700;
    color: var(--navy);
  }
  .stock-roll-badge {
    font-weight: 600;
    color: #475569;
  }
  .scanned-pill {
    font-size: 10.5px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 999px;
    background: #e2e8f0;
    color: #475569;
  }
  .stock-dimen {
    color: #64748b;
    margin-left: 6px;
  }

  .empty-note { color:#9aa5b8; font-size:13px; padding:14px 0; text-align:center; }

  .side-col .card { position:relative; }
  .side-col table { font-size:12px; }
  .side-col th, .side-col td { padding:10px 8px; white-space:normal; word-break:break-word; }
  .side-col .table-wrap { max-height:calc(100vh - 160px); }

  /* Summary table: fixed layout + defined column widths so every column
     fits without a horizontal scrollbar, no matter the content width. */
  .summary-wrap { overflow-x:hidden; }
  #summaryTable { table-layout:fixed; width:100%; }
  #summaryTable th:nth-child(1), #summaryTable td:nth-child(1) { width:26%; }
  #summaryTable th:nth-child(2), #summaryTable td:nth-child(2) { width:16%; text-align:center; }
  #summaryTable th:nth-child(3), #summaryTable td:nth-child(3) { width:16%; text-align:center; }
  #summaryTable th:nth-child(4), #summaryTable td:nth-child(4) { width:14%; text-align:center; }
  #summaryTable th:nth-child(5), #summaryTable td:nth-child(5) { width:28%; text-align:center; }

  .summary-status-complete,
  .summary-status-remaining,
  .summary-status-over {
    display:inline-block;
    padding:3px 10px;
    border-radius:999px;
    font-weight:650;
    font-size:11.5px;
    border:1px solid transparent;
    white-space:nowrap;
  }
  .summary-status-complete { background:#e6f4ea; color:#137333; border-color:#bfe3cb; }
  .summary-status-remaining { background:#fef7e0; color:#b06000; border-color:#f5e2ab; }
  .summary-status-over { background:#fce8e6; color:#c5221f; border-color:#f5c6c3; }

  .summary-row-complete td { background:#fbfdfc; }
  .summary-row-remaining td { background:#fffdf7; }

  .summary-row-complete td { background:#fbfdfc; }
  .summary-row-remaining td { background:#fffdf7; }

  /* -------------------------------------------------------------------
     Toggle Sidebar Controls & Collapsible / Drawer Styles
     ------------------------------------------------------------------- */
  .btn-summary-toggle {
    margin-left: auto;
    background: rgba(255, 255, 255, 0.14);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.22);
    padding: 7px 15px;
    font-size: 13px;
    font-weight: 650;
    border-radius: 9px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 7px;
    transition: all .18s ease;
  }
  .btn-summary-toggle:hover {
    background: rgba(255, 255, 255, 0.24);
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
  }
  .btn-summary-toggle.active {
    background: #fff;
    color: var(--navy);
    font-weight: 700;
    box-shadow: 0 2px 8px rgba(0,0,0,0.18);
  }

  .summary-total-row th,
  .summary-total-row td {
    position: sticky;
    top: 28px;
    z-index: 10;
    background: #e2e8f0 !important;
    color: var(--navy) !important;
    font-weight: 800 !important;
    font-size: 12px !important;
    border-top: 1px solid #cbd5e1 !important;
    border-bottom: 2.5px solid #94a3b8 !important;
    text-transform: none !important;
    letter-spacing: 0 !important;
    box-shadow: 0 2px 4px rgba(0,0,0,0.06);
  }

  /* Summary Sidebar Filter Box & Tally Banner Styles */
  .summary-filter-box {
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 9px;
    padding: 12px 14px;
    margin-bottom: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
  }
  .summary-filter-toggle-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    user-select: none;
  }
  .btn-toggle-filter {
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 11.5px;
    cursor: pointer;
    padding: 2px 6px;
    border-radius: 4px;
    transition: background .15s ease;
  }
  .btn-toggle-filter:hover {
    background: #e2e8f0;
    color: var(--navy);
  }
  .tally-active-badge {
    background: #2563eb;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 4px;
    margin-left: 6px;
  }
  .summary-filter-header {
    font-size: 13px;
    color: var(--navy);
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .summary-filter-sub {
    font-size: 11.5px;
    color: var(--text-muted);
    margin-top: 2px;
    margin-bottom: 10px;
    line-height: 1.35;
  }
  .summary-filter-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 10px;
  }
  .summary-filter-grid .date-input-group {
    flex: 1 1 45%;
    min-width: 130px;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 3px;
  }
  .summary-filter-grid .date-input-group label {
    font-size: 11.5px;
    color: var(--navy);
  }
  .summary-filter-grid .date-input-group input[type="date"] {
    width: 100%;
    padding: 5px 8px;
    font-size: 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
  }
  .summary-filter-actions {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .summary-filter-actions .btn-filter-submit {
    flex: 1;
    background: var(--blue);
    color: #fff;
    border: none;
    padding: 6px 12px;
    border-radius: 7px;
    font-size: 12.5px;
    font-weight: 650;
    cursor: pointer;
    transition: background .15s ease;
  }
  .summary-filter-actions .btn-filter-submit:hover {
    background: var(--blue-dark);
  }
  .summary-filter-actions .btn-filter-reset {
    background: #e2e8f0;
    color: #475569;
    text-decoration: none;
    padding: 6px 10px;
    border-radius: 7px;
    font-size: 11.5px;
    font-weight: 600;
    white-space: nowrap;
  }
  .date-presets {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
    padding-top: 8px;
    border-top: 1px dashed #cbd5e1;
    font-size: 12px;
  }
  .preset-label {
    color: var(--text-muted);
    font-weight: 600;
    margin-right: 4px;
  }
  .btn-preset {
    background: #fff;
    border: 1px solid #cbd5e1;
    color: var(--navy);
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all .15s ease;
  }
  .btn-preset:hover {
    background: #e2e8f0;
    border-color: #94a3b8;
  }
  .summary-tally-banner {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    padding: 9px 13px;
    margin-bottom: 12px;
    font-size: 12.5px;
    color: #1e40af;
  }
  .tally-banner-title {
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 700;
  }
  .tally-date-chip {
    background: #2563eb;
    color: #fff;
    padding: 2px 7px;
    border-radius: 5px;
    font-size: 11.5px;
    font-weight: 700;
  }
  .tally-banner-sub {
    font-size: 11.5px;
    color: #3b82f6;
    margin-top: 2px;
  }

  .close-sidebar-btn {
    margin-left: auto;
    background: transparent;
    border: none;
    font-size: 20px;
    font-weight: 700;
    color: var(--text-muted);
    cursor: pointer;
    padding: 2px 8px;
    border-radius: 6px;
    line-height: 1;
    transition: all .15s ease;
  }
  .close-sidebar-btn:hover {
    background: #fee2e2;
    color: var(--red);
  }

  .layout { display: flex; gap: 22px; align-items: flex-start; transition: all .25s ease; }
  .main-col { flex: 1.4; min-width: 0; transition: all .25s ease; }
  .side-col {
    flex: 1;
    min-width: 400px;
    max-width: 450px;
    flex-shrink: 0;
    position: sticky;
    top: 80px;
    transition: transform .3s cubic-bezier(0.4, 0, 0.2, 1), opacity .2s ease;
  }

  /* Collapsed Sidebar on Desktop */
  body.sidebar-closed .side-col {
    display: none !important;
  }
  body.sidebar-closed .main-col {
    flex: 1 1 100% !important;
    max-width: 100% !important;
  }

  /* Backdrop Overlay for Mobile / Tablet Slide-out Drawer */
  .sidebar-backdrop {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 27, 45, 0.45);
    backdrop-filter: blur(3px);
    z-index: 1040;
    opacity: 0;
    transition: opacity .25s ease;
  }

  @media (max-width: 960px) {
    .layout { flex-direction: column; }
    .main-col { width: 100%; }

    .sidebar-backdrop.active {
      display: block;
      opacity: 1;
    }

    .side-col {
      position: fixed !important;
      top: 0 !important;
      right: 0 !important;
      bottom: 0 !important;
      height: 100vh !important;
      width: 420px !important;
      max-width: 90vw !important;
      z-index: 1050 !important;
      background: #fff;
      padding: 24px !important;
      box-shadow: -8px 0 32px rgba(0,0,0,0.18) !important;
      overflow-y: auto !important;
      transform: translateX(100%);
      display: block !important;
      margin-top: 0 !important;
    }

    body.sidebar-open .side-col {
      transform: translateX(0);
    }
    body.sidebar-closed .side-col {
      transform: translateX(100%);
      display: block !important;
    }

    .side-col .card {
      box-shadow: none !important;
      border: none !important;
      padding: 0 !important;
      margin: 0 !important;
    }
  }
</style>
</head>
<body>

<header>
  <div class="header-inner">
    <div class="header-badge">📦</div>
    <div>
      <h1>End-of-Month Stock Scan &amp; Export</h1>
      <p>Slitting System &middot; Physical stock scanning &amp; live reconciliation</p>
    </div>
    <button id="toggleSummaryBtn" class="btn-summary-toggle" title="Toggle Live Reconciliation Summary Sidebar">
      📊 Live Summary Sidebar
    </button>
  </div>
</header>

<main>
<div class="layout">

  <div class="main-col">

  <!-- STEP 1: SCAN, DEDUPE, CONVERT -->
  <div class="card">
    <h2><span class="step-badge">1</span> Scan Physical Products</h2>
    <div class="scanner-status-bar" id="scannerStatusBar">
      <span class="scanner-indicator" id="scannerIndicator"></span>
      <span id="scannerStatusText"><b>Scanner Active &amp; Ready</b> &mdash; Point barcode gun &amp; press trigger anytime</span>
    </div>
    <input type="text" id="scanInput" placeholder="⚡ Ready for Scanning — Pull scanner trigger anytime..." autocomplete="off" autofocus>
    <div class="hint">No clicking needed &mdash; point barcode gun &amp; pull trigger. Scanning automatically submits on "Enter".</div>
    <div id="warningBanner"></div>
    <div class="counter">Scanned: <b id="scanCount">0</b> item(s)</div>

    <div class="actions">
      <button class="btn btn-ghost" id="clearAllBtn">Clear All Scans</button>
      <button class="btn btn-camera" id="cameraScanBtn">📷 Camera Scan</button>
      <button class="btn btn-ghost" id="manualEntryToggleBtn">✏️ Manual Entry</button>
      <button class="btn btn-ghost" id="toggleSummaryBtnActions">📊 Live Summary</button>
      <button class="btn btn-primary" id="exportBtn">Export to Excel</button>
    </div>

    <!-- MANUAL COIL ENTRY FORM -->
    <div id="manualEntryFormCard" class="manual-entry-card" style="display: none;">
      <div class="manual-entry-header">
        <h3>✏️ Manual Coil Entry (Retrieve from Stock)</h3>
        <button type="button" class="close-manual-btn" id="closeManualBtn">&times;</button>
      </div>
      <form id="manualEntryForm" class="manual-form-grid">
        <div class="form-group form-group-lot">
          <label for="manualLot">Lot No *</label>
          <div class="input-with-btn">
            <input type="text" id="manualLot" placeholder="e.g. 2520" required autocomplete="off">
            <button type="button" class="btn-fetch-stock" id="fetchStockBtn" title="Retrieve matching rolls from stock">🔍 Fetch Stock</button>
          </div>
        </div>
        <div class="form-group">
          <label for="manualCoil">Coil No *</label>
          <input type="text" id="manualCoil" placeholder="e.g. CH-2 or CH-02" required autocomplete="off">
        </div>
        <div class="form-group">
          <label for="manualRoll">Roll No *</label>
          <input type="text" id="manualRoll" placeholder="e.g. 01 or 1" required autocomplete="off">
        </div>
        <div class="form-group">
          <label for="manualWidth">Width (mm) <span class="opt">(Optional)</span></label>
          <input type="number" step="any" id="manualWidth" placeholder="Auto lookup" autocomplete="off">
        </div>
        <div class="form-group">
          <label for="manualLength">Length (m) <span class="opt">(Optional)</span></label>
          <input type="number" step="any" id="manualLength" placeholder="Auto lookup" autocomplete="off">
        </div>
        <div class="form-group form-actions-full">
          <button type="submit" class="btn btn-primary" id="submitManualBtn">➕ Add Manual Scan</button>
          <button type="button" class="btn btn-ghost" id="cancelManualBtn">Cancel</button>
        </div>
      </form>
      <div id="manualStockNotice" class="manual-stock-notice" style="display:none;"></div>
      <div id="manualStockList" class="manual-stock-list" style="display:none;"></div>
    </div>

    <div class="table-wrap">
    <table id="scanTable">
      <thead>
        <tr>
          <th>#</th><th>Lot</th><th>Coil</th>
          <th>Product Code</th><th>D365 Item Number</th><th>D365 Lot No</th><th>MTR</th><th>Time</th><th></th>
        </tr>
      </thead>
      <tbody id="scanTbody"></tbody>
    </table>
    </div>
    <div id="scanEmptyNote" class="empty-note">No items scanned yet.</div>
  </div>

  </div>

  <div class="side-col">
    <!-- LIVE RECONCILIATION SUMMARY -->
    <div class="card">
      <h2>
        <span>Live Reconciliation Summary</span>
        <button id="closeSummaryBtn" class="close-sidebar-btn" title="Close Sidebar">&times;</button>
      </h2>

      <!-- OFFICER SYSTEM STOCK TALLY FILTER INSIDE LIVE SUMMARY (COLLAPSIBLE) -->
      <div class="summary-filter-box" id="summaryFilterBox">
        <div class="summary-filter-toggle-header" id="toggleFilterBoxHeader">
          <div class="summary-filter-header">
            <span class="icon">📊</span>
            <b>Filter Baseline Stock by Last Date</b>
            <?php if ($fromDate !== '' || $toDate !== ''): ?>
              <span class="tally-active-badge">Active Filter</span>
            <?php endif; ?>
          </div>
          <button type="button" class="btn-toggle-filter" id="btnToggleFilter" title="Toggle Filter Box">
            <span id="filterToggleIcon">▼</span>
          </button>
        </div>

        <div id="summaryFilterContent" class="summary-filter-content">
          <div class="summary-filter-sub">
            Filter system baseline stock up to the cut-off date to tally against physical scans
          </div>

          <form method="GET" action="index.php" id="dateFilterForm" class="date-filter-form">
            <div class="summary-filter-grid">
              <div class="date-input-group">
                <label for="to_date"><b>📅 Cutoff / Last Date:</b></label>
                <input type="date" id="to_date" name="to_date" value="<?php echo htmlspecialchars($toDate); ?>">
              </div>
              <div class="date-input-group">
                <label for="from_date" style="color: var(--text-muted);">From (Optional):</label>
                <input type="date" id="from_date" name="from_date" value="<?php echo htmlspecialchars($fromDate); ?>">
              </div>
            </div>

            <div class="summary-filter-actions">
              <button type="submit" class="btn-filter-submit">🔍 Tally System Stock</button>
              <?php if ($fromDate !== '' || $toDate !== ''): ?>
                <a href="index.php" class="btn-filter-reset">🔄 Reset (All Stock)</a>
              <?php endif; ?>
            </div>

            <div class="date-presets">
              <span class="preset-label">Quick Presets:</span>
              <button type="button" class="btn-preset" onclick="setFilterDates('', '', event)">🌐 All Dates</button>
              <button type="button" class="btn-preset" onclick="setFilterDates('', '<?php echo date('Y-m-t', strtotime('-1 month')); ?>', event)">🏁 End of Last Month (<?php echo date('31/m', strtotime('-1 month')); ?>)</button>
              <button type="button" class="btn-preset" onclick="setFilterDates('', '<?php echo date('Y-m-d', strtotime('-1 day')); ?>', event)">📅 Yesterday</button>
              <button type="button" class="btn-preset" onclick="setFilterDates('', '<?php echo date('Y-m-d'); ?>', event)">📅 Today (<?php echo date('d/m'); ?>)</button>
            </div>
          </form>
        </div>
      </div>

      <div class="summary-tally-banner">
        <div class="tally-banner-title">
          <span>🏁 <b>System Tally Cutoff:</b></span>
          <span class="tally-date-chip">
            <?php echo $toDate ? "Up to " . htmlspecialchars($toDate) : ($fromDate ? "From " . htmlspecialchars($fromDate) : "All Dates"); ?>
          </span>
        </div>
        <div class="tally-banner-sub">
          Tallying <b><?php echo array_sum($baselineStock); ?></b> expected system coils against scanned data
        </div>
      </div>

      <div class="table-wrap summary-wrap">
      <table id="summaryTable">
        <thead>
          <tr><th>Product &amp; Width</th><th>System</th><th>Scanned</th><th>Left</th><th>Status</th></tr>
          <tr id="summaryTotalRow" class="summary-total-row"></tr>
        </thead>
        <tbody id="summaryTbody"></tbody>
      </table>
      </div>
      <div id="summaryEmptyNote" class="empty-note">No baseline stock data found.</div>
    </div>
  </div>

</div>
</main>
<div id="sidebarBackdrop" class="sidebar-backdrop"></div>

<!-- CAMERA SCANNER MODAL -->
<div id="cameraScanModal" class="camera-modal" style="display: none;">
  <div class="camera-modal-backdrop" id="cameraModalBackdrop"></div>
  <div class="camera-modal-content">
    <div class="camera-modal-header">
      <h3>📷 Tablet Camera Scanner</h3>
      <button type="button" class="close-camera-btn" id="closeCameraBtn">&times;</button>
    </div>
    <div class="camera-modal-body">
      <div class="camera-controls">
        <select id="cameraSelect" class="camera-select-dropdown">
          <option value="">Detecting cameras...</option>
        </select>
        <label class="continuous-toggle">
          <input type="checkbox" id="continuousScanToggle" checked>
          <span>Continuous Mode</span>
        </label>
      </div>
      <div id="cameraReaderContainer" class="camera-reader-container">
        <div id="reader" style="width: 100%;"></div>
        <div id="cameraStatusNotice" class="camera-status-notice">Initializing camera...</div>
      </div>
    </div>
  </div>
</div>

<script>
// ---------------------------------------------------------------------
// State: scanned records, seeded from the PHP session (survives refresh)
// ---------------------------------------------------------------------
let scannedRecords = <?php echo json_encode($existingScans, JSON_UNESCAPED_SLASHES); ?>;

// Baseline "should exist" stock count per product code & width, computed server-side
// on page load (see the SQL query at the top of this file).
const systemStockBaseline = <?php echo json_encode($baselineStock, JSON_UNESCAPED_SLASHES); ?>;
const systemStockRolls    = <?php echo json_encode($baselineRolls, JSON_UNESCAPED_SLASHES); ?>;

const scanInput   = document.getElementById('scanInput');
const scanTbody   = document.getElementById('scanTbody');
const scanEmptyNote = document.getElementById('scanEmptyNote');
const warningBanner = document.getElementById('warningBanner');
const scanCountEl = document.getElementById('scanCount');

function refreshScanTable() {
  scanTbody.innerHTML = '';
  scannedRecords.forEach((r, i) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${scannedRecords.length - i}</td><td>${escapeHtml(r.lot)}</td><td>${escapeHtml(r.coil)}</td>
      <td>${escapeHtml(r.product_code)}</td><td>${escapeHtml(r.d365_item_number)}</td>
      <td>${escapeHtml(r.d365_lot_no)}</td><td>${escapeHtml(r.mtr)}</td><td>${escapeHtml(r.scanned_at)}</td>
      <td><button class="removeBtn" data-raw="${encodeURIComponent(r.raw)}">remove</button></td>`;
    scanTbody.appendChild(tr);
  });
  scanCountEl.textContent = scannedRecords.length;
  scanEmptyNote.style.display = scannedRecords.length ? 'none' : 'block';
  renderSummary();
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function escapeJsString(str) {
  if (!str) return '';
  return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

// ---------------------------------------------------------------------
// Live reconciliation summary: system baseline vs scanned, per Product & Width
// ---------------------------------------------------------------------
function getProductLabel(r) {
  const code = r.product_code || '(unknown)';
  const w = parseFloat(r.width || 0);
  if (w > 0) {
    const wClean = (w % 1 === 0) ? w : w;
    return `${code} (${wClean}mm)`;
  }
  return code;
}

const expandedSummaryRows = new Set();

function toggleSummaryRow(labelKey) {
  const summaryWrap = document.querySelector('.summary-wrap');
  const sideCol = document.querySelector('.side-col');
  const savedScrollWrap = summaryWrap ? summaryWrap.scrollTop : 0;
  const savedScrollSide = sideCol ? sideCol.scrollTop : 0;
  const savedPageY = window.scrollY;

  if (expandedSummaryRows.has(labelKey)) {
    expandedSummaryRows.delete(labelKey);
  } else {
    expandedSummaryRows.add(labelKey);
  }

  renderSummary();

  if (summaryWrap) summaryWrap.scrollTop = savedScrollWrap;
  if (sideCol) sideCol.scrollTop = savedScrollSide;
  window.scrollTo(0, savedPageY);
}

function renderSummary() {
  const summaryTbody = document.getElementById('summaryTbody');
  const summaryEmptyNote = document.getElementById('summaryEmptyNote');
  const summaryWrap = document.querySelector('.summary-wrap');
  const sideCol = document.querySelector('.side-col');
  const savedScrollWrap = summaryWrap ? summaryWrap.scrollTop : 0;
  const savedScrollSide = sideCol ? sideCol.scrollTop : 0;
  const savedPageY = window.scrollY;

  // Count scans per product & width
  const scannedCounts = {};
  scannedRecords.forEach(r => {
    const label = getProductLabel(r);
    scannedCounts[label] = (scannedCounts[label] || 0) + 1;
  });

  // Union of every product & width label: baseline + anything scanned
  const allLabels = new Set([...Object.keys(systemStockBaseline), ...Object.keys(scannedCounts)]);

  if (!allLabels.size) {
    summaryTbody.innerHTML = '';
    summaryEmptyNote.style.display = 'block';
    return;
  }
  summaryEmptyNote.style.display = 'none';

  const rows = [];
  [...allLabels].sort().forEach(label => {
    const system    = systemStockBaseline[label] ?? 0;
    const scanned   = scannedCounts[label] || 0;
    const remaining = system - scanned;

    // Find unscanned rolls for this product label
    const systemRolls = systemStockRolls[label] || [];
    const unscannedRolls = systemRolls.filter(item => {
      return !scannedRecords.some(r =>
        (r.lot || '').toLowerCase()  === (item.lot || '').toLowerCase() &&
        (r.coil || '').toLowerCase() === (item.coil || '').toLowerCase() &&
        String(r.roll) === String(item.roll)
      );
    });

    // Find all physical scans for this product label
    const scannedForProduct = scannedRecords.filter(r => getProductLabel(r) === label);

    // Identify extra coils scanned (scanned coils that were NOT in system baseline stock)
    const extraScannedRolls = scannedForProduct.filter(r => {
      return !systemRolls.some(item =>
        (item.lot || '').toLowerCase()  === (r.lot || '').toLowerCase() &&
        (item.coil || '').toLowerCase() === (r.coil || '').toLowerCase() &&
        String(item.roll) === String(r.roll)
      );
    });

    const isOver = remaining < 0;
    const hasUnscanned = unscannedRolls.length > 0;
    const hasExtra = extraScannedRolls.length > 0;
    const canExpand = hasUnscanned || isOver || (scannedForProduct.length > 0);
    const isExpanded = expandedSummaryRows.has(label);

    let statusHtml, rowClass;
    if (remaining > 0) {
      statusHtml = `<span class="summary-status-remaining">\u{1F7E1} ${remaining} Left</span>`;
      rowClass = 'summary-row-remaining';
    } else if (remaining < 0) {
      statusHtml = `<span class="summary-status-over">\u{1F534} Over by ${Math.abs(remaining)}</span>`;
      rowClass = 'summary-row-remaining';
    } else {
      statusHtml = `<span class="summary-status-complete">\u{1F7E2} Complete</span>`;
      rowClass = 'summary-row-complete';
    }

    const clickAttr = canExpand ? `onclick="toggleSummaryRow('${escapeJsString(label)}')" title="Click to view/hide detail coils"` : '';
    const mainClass = `${rowClass} summary-main-row ${canExpand ? 'has-unscanned' : ''} ${isExpanded ? 'expanded' : ''}`;

    rows.push(`
      <tr class="${mainClass}" ${clickAttr}>
        <td>
          ${canExpand ? `<span class="expand-toggle-icon">${isExpanded ? '\u{25BC}' : '\u{25B6}'}</span>` : ''}
          <b>${escapeHtml(label)}</b>
        </td>
        <td>${system}</td>
        <td>${scanned}</td>
        <td>${remaining}</td>
        <td>${statusHtml}</td>
      </tr>
    `);

    if (canExpand && isExpanded) {
      if (isOver || hasExtra) {
        // OVER / EXTRA: Render scanned coils with extra highlighting
        const overChipsHtml = scannedForProduct.map(r => {
          const isExtra = extraScannedRolls.some(ex =>
            (ex.lot || '').toLowerCase() === (r.lot || '').toLowerCase() &&
            (ex.coil || '').toLowerCase() === (r.coil || '').toLowerCase() &&
            String(ex.roll) === String(r.roll)
          );
          return `
            <div class="summary-detail-chip ${isExtra ? 'chip-extra' : 'chip-matched'}">
              ${isExtra ? '🚨' : '✔'} Lot <b>${escapeHtml(r.lot)}</b> &middot; Coil <b>${escapeHtml(r.coil)}</b> &middot; Roll <b>${escapeHtml(r.roll)}</b>
              <span class="${isExtra ? 'badge-extra' : 'badge-matched'}">${isExtra ? 'EXTRA' : 'MATCHED'}</span>
            </div>
          `;
        }).join('');

        rows.push(`
          <tr class="summary-detail-row">
            <td colspan="5">
              <div class="summary-detail-box over-box">
                <div class="summary-detail-header over-header">
                  🚨 Scanned Coils List (${scannedForProduct.length} Physical Scans &middot; ${extraScannedRolls.length} Extra Coils Not In Baseline):
                </div>
                <div class="summary-detail-grid">
                  ${overChipsHtml}
                </div>
              </div>
            </td>
          </tr>
        `);
      } else if (hasUnscanned) {
        // LEFT > 0: Render unscanned baseline coils
        const chipsHtml = unscannedRolls.map(roll => `
          <div class="summary-detail-chip">
            Lot <b>${escapeHtml(roll.lot)}</b> &middot; Coil <b>${escapeHtml(roll.coil)}</b> &middot; Roll <b>${escapeHtml(roll.roll)}</b>
          </div>
        `).join('');

        rows.push(`
          <tr class="summary-detail-row">
            <td colspan="5">
              <div class="summary-detail-box">
                <div class="summary-detail-header">📦 Coils &amp; Rolls Left (${unscannedRolls.length} Unscanned):</div>
                <div class="summary-detail-grid">
                  ${chipsHtml}
                </div>
              </div>
            </td>
          </tr>
        `);
      } else if (scannedForProduct.length > 0) {
        // COMPLETE (0 Left): Render matched scanned coils
        const completeChipsHtml = scannedForProduct.map(r => `
          <div class="summary-detail-chip chip-matched">
            ✔ Lot <b>${escapeHtml(r.lot)}</b> &middot; Coil <b>${escapeHtml(r.coil)}</b> &middot; Roll <b>${escapeHtml(r.roll)}</b> <span class="badge-matched">MATCHED</span>
          </div>
        `).join('');

        rows.push(`
          <tr class="summary-detail-row">
            <td colspan="5">
              <div class="summary-detail-box" style="background:#f0fdf4; border-top: 1px dashed #86efac;">
                <div class="summary-detail-header" style="color:#15803d;">
                  🟢 Scanned Coils List (Complete &middot; ${scannedForProduct.length} Scanned):
                </div>
                <div class="summary-detail-grid">
                  ${completeChipsHtml}
                </div>
              </div>
            </td>
          </tr>
        `);
      }
    }
  });

  let totalSystem = 0;
  let totalScanned = 0;

  [...allLabels].forEach(label => {
    totalSystem += (systemStockBaseline[label] ?? 0);
    totalScanned += (scannedCounts[label] || 0);
  });

  const totalLeft = totalSystem - totalScanned;
  const totalProducts = allLabels.size;

  let totalStatusHtml;
  if (totalLeft > 0) {
    totalStatusHtml = `<span class="summary-status-remaining">\u{1F7E1} ${totalLeft} Left</span>`;
  } else if (totalLeft < 0) {
    totalStatusHtml = `<span class="summary-status-over">\u{1F534} Over by ${Math.abs(totalLeft)}</span>`;
  } else {
    totalStatusHtml = `<span class="summary-status-complete">\u{1F7E2} Complete</span>`;
  }

  const totalRowEl = document.getElementById('summaryTotalRow');
  if (totalRowEl) {
    totalRowEl.innerHTML = `
      <th><b>TOTAL</b> (${totalProducts} Lines)</th>
      <th><b>${totalSystem}</b></th>
      <th><b>${totalScanned}</b></th>
      <th><b>${totalLeft}</b></th>
      <th>${totalStatusHtml}</th>
    `;
  }

  summaryTbody.innerHTML = rows.join('');

  if (summaryWrap) summaryWrap.scrollTop = savedScrollWrap;
  if (sideCol) sideCol.scrollTop = savedScrollSide;
  window.scrollTo(0, savedPageY);
}

function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));
}

function playScanSound(ok = true) {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    if (ok) {
      // High-pitched dual success tone (587Hz -> 880Hz)
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(587.33, ctx.currentTime);
      osc.frequency.setValueAtTime(880.00, ctx.currentTime + 0.08);
      gain.gain.setValueAtTime(0.2, ctx.currentTime);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + 0.2);
    } else {
      // Low warning error buzzer (220Hz sawtooth wave)
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sawtooth';
      osc.frequency.setValueAtTime(220, ctx.currentTime);
      gain.gain.setValueAtTime(0.25, ctx.currentTime);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + 0.35);
    }
  } catch (err) { /* silent fallback */ }
}

function showWarning(msg, ok = false) {
  playScanSound(ok);

  const formattedMsg = ok 
    ? `SCANNED SUCCESSFUL! &mdash; ${escapeHtml(msg)}` 
    : `SCAN FAILED &mdash; ${escapeHtml(msg)}`;

  warningBanner.innerHTML = formattedMsg;
  warningBanner.className = ok ? 'ok' : '';
  warningBanner.style.display = 'block';

  // Flash input border
  scanInput.classList.remove('flash-success', 'flash-error');
  void scanInput.offsetWidth; // Reflow for animation restart
  scanInput.classList.add(ok ? 'flash-success' : 'flash-error');

  // Update status notice in camera modal if open
  const cameraNotice = document.getElementById('cameraStatusNotice');
  if (cameraNotice && typeof isCameraModalActive === 'function' && isCameraModalActive()) {
    cameraNotice.textContent = ok ? `✅ ${msg}` : `❌ ${msg}`;
    cameraNotice.style.background = ok ? 'rgba(22, 163, 74, 0.95)' : 'rgba(220, 38, 38, 0.95)';
    setTimeout(() => {
      if (cameraNotice && isCameraModalActive()) {
        cameraNotice.textContent = '📷 Point camera at QR code label';
        cameraNotice.style.background = 'rgba(15, 27, 45, 0.85)';
      }
    }, 2800);
  }

  clearTimeout(showWarning._t);
  showWarning._t = setTimeout(() => { 
    warningBanner.style.display = 'none';
    scanInput.classList.remove('flash-success', 'flash-error');
  }, 3500);
}

// ---------------------------------------------------------------------
// STEP 1: Scan -> parse -> dedupe -> convert (auto on Enter keypress)
// ---------------------------------------------------------------------
function parseQR(raw) {
  const data = {};
  raw.split(';').forEach(pair => {
    const idx = pair.indexOf('=');
    if (idx === -1) return;
    const key = pair.slice(0, idx).trim().toUpperCase();
    const val = pair.slice(idx + 1).trim();
    if (key) data[key] = val;
  });
  return data;
}

function isAlreadyScanned(lot, coil, roll, raw) {
  if (raw && scannedRecords.some(r => r.raw === raw)) return true;

  const cleanLot  = (lot || '').trim().toLowerCase();
  const cleanCoil = (coil || '').trim().toLowerCase();
  const cleanRoll = (roll || '').trim().toLowerCase();
  if (!cleanLot || !cleanCoil || !cleanRoll) return false;

  const formatCoilJs = (c) => {
    const m = c.match(/^(.*?)-(\d+)$/);
    return m ? m[1] + '-' + m[2].padStart(2, '0') : c;
  };
  const coilAlt = formatCoilJs(cleanCoil);

  const rollPadded   = cleanRoll.padStart(2, '0');
  const rollUnpadded = cleanRoll.replace(/^0+/, '') || '0';

  return scannedRecords.some(r => {
    const rLot  = (r.lot || '').trim().toLowerCase();
    const rCoil = (r.coil || '').trim().toLowerCase();
    const rRoll = (r.roll || '').trim().toLowerCase();
    const rRollPadded   = rRoll.padStart(2, '0');
    const rRollUnpadded = rRoll.replace(/^0+/, '') || '0';

    const lotMatch  = (rLot === cleanLot);
    const coilMatch = (rCoil === cleanCoil || rCoil === coilAlt || formatCoilJs(rCoil) === coilAlt);
    const rollMatch = (rRoll === cleanRoll || rRollPadded === rollPadded || rRollUnpadded === rollUnpadded);

    return lotMatch && coilMatch && rollMatch;
  });
}

scanInput.addEventListener('keydown', function (e) {
  if (e.key !== 'Enter') return;
  e.preventDefault();
  const raw = scanInput.value.trim();
  scanInput.value = '';
  if (!raw) return;

  const fields = parseQR(raw);
  if (!fields.LOT || !fields.COIL || !fields.ROLL) {
    showWarning('Invalid QR code: missing LOT/COIL/ROLL.');
    return;
  }

  // Client-side instant dedupe check by raw AND (lot, coil, roll)
  if (isAlreadyScanned(fields.LOT, fields.COIL, fields.ROLL, raw)) {
    showWarning('Already Scanned');
    return;
  }

  if (!/^[A-Za-z0-9]{4,7}$/.test(fields.LOT)) {
    showWarning('Invalid Lot No format.');
    return;
  }

  const body = new URLSearchParams({
    raw, lot: fields.LOT, coil: fields.COIL, roll: fields.ROLL
  });
  if (fields.WIDTH !== undefined)  body.set('width', fields.WIDTH);
  if (fields.LENGTH !== undefined) body.set('length', fields.LENGTH);

  fetch('ajax_lookup.php', { method: 'POST', body })
    .then(r => r.json())
    .then(res => {
      if (!res.success) {
        showWarning(res.error || 'Scan failed');
        return;
      }
      scannedRecords.unshift(res.record);
      refreshScanTable();
      showWarning('Scanned OK - ' + res.record.d365_lot_no, true);
    })
    .catch(() => showWarning('Network/server error while looking up scan.'));
});

// ---------------------------------------------------------------------
// Manual Entry Form Handling & Stock Retrieval (by Lot No + Coil No + Roll No)
// ---------------------------------------------------------------------
const manualEntryFormCard = document.getElementById('manualEntryFormCard');
const manualEntryToggleBtn = document.getElementById('manualEntryToggleBtn');
const closeManualBtn       = document.getElementById('closeManualBtn');
const cancelManualBtn      = document.getElementById('cancelManualBtn');
const manualEntryForm      = document.getElementById('manualEntryForm');
const manualLotInput       = document.getElementById('manualLot');
const manualCoilInput      = document.getElementById('manualCoil');
const manualRollInput      = document.getElementById('manualRoll');
const manualWidthInput     = document.getElementById('manualWidth');
const manualLengthInput    = document.getElementById('manualLength');
const fetchStockBtn        = document.getElementById('fetchStockBtn');
const manualStockNotice    = document.getElementById('manualStockNotice');
const manualStockList      = document.getElementById('manualStockList');

function resetManualForm() {
  manualEntryForm.reset();
  if (manualStockNotice) manualStockNotice.style.display = 'none';
  if (manualStockList)   manualStockList.style.display   = 'none';
}

function toggleManualEntry(show) {
  const isVisible = show !== undefined ? show : manualEntryFormCard.style.display === 'none';
  manualEntryFormCard.style.display = isVisible ? 'block' : 'none';
  if (isVisible) {
    resetManualForm();
    manualLotInput.focus();
  } else {
    scanInput.focus();
  }
}

function fetchStockData() {
  const lot  = manualLotInput.value.trim();
  const coil = manualCoilInput.value.trim();
  const roll = manualRollInput.value.trim();

  if (!lot) {
    manualStockNotice.style.display = 'none';
    manualStockList.style.display   = 'none';
    return;
  }

  const query = new URLSearchParams({ lot_no: lot });
  if (coil) query.set('coil_no', coil);

  fetch('ajax_retrieve_stock.php?' + query.toString())
    .then(r => r.json())
    .then(res => {
      if (!res.success || !res.items || res.items.length === 0) {
        manualStockNotice.className = 'manual-stock-notice warn';
        manualStockNotice.textContent = `⚠️ No matching roll found in slitting_product for Lot "${lot}".`;
        manualStockNotice.style.display = 'block';
        manualStockList.style.display   = 'none';
        return;
      }

      manualStockNotice.className = 'manual-stock-notice ok';
      manualStockNotice.textContent = `📦 Found ${res.items.length} roll(s) in stock for Lot "${lot}". Click any roll to select:`;
      manualStockNotice.style.display = 'block';

      manualStockList.innerHTML = res.items.map((item) => {
        const isScanned = scannedRecords.some(r => 
          (r.lot || '').toLowerCase() === (item.lot_no || '').toLowerCase() &&
          (r.coil || '').toLowerCase() === (item.coil_no || '').toLowerCase() &&
          String(r.roll) === String(item.roll_no)
        );
        const isSelected = (res.items.length === 1 || (coil && roll && item.coil_no === coil && item.roll_no === roll));

        return `
          <div class="manual-stock-item ${isSelected ? 'selected' : ''} ${isScanned ? 'is-scanned' : ''}" 
               data-coil="${escapeHtml(item.coil_no)}" 
               data-roll="${escapeHtml(item.roll_no)}" 
               data-width="${escapeHtml(item.width_clean)}" 
               data-length="${escapeHtml(item.length_clean)}" 
               data-prod="${escapeHtml(item.product)}">
            <div class="stock-item-left">
              <span class="stock-coil-badge">Coil ${escapeHtml(item.coil_no)}</span>
              <span class="stock-roll-badge">Roll ${escapeHtml(item.roll_no)}</span>
              ${isScanned ? '<span class="scanned-pill">✔ Scanned</span>' : ''}
            </div>
            <div>
              <span class="stock-pill">${escapeHtml(item.product)}</span>
              <small class="stock-dimen">${escapeHtml(item.width_clean)}mm &times; ${escapeHtml(item.length_clean)}m</small>
            </div>
          </div>
        `;
      }).join('');
      manualStockList.style.display = 'block';

      if (res.items.length === 1) {
        const item = res.items[0];
        manualCoilInput.value   = item.coil_no;
        manualRollInput.value   = item.roll_no;
        manualWidthInput.value  = item.width_clean;
        manualLengthInput.value = item.length_clean;
        manualStockNotice.textContent = `✅ Selected Roll: ${item.product} | Coil: ${item.coil_no} | Roll: ${item.roll_no} (${item.width_clean}mm x ${item.length_clean}m)`;
      }
    })
    .catch(() => {
      manualStockNotice.className = 'manual-stock-notice warn';
      manualStockNotice.textContent = 'Network error while retrieving stock.';
      manualStockNotice.style.display = 'block';
    });
}

fetchStockBtn?.addEventListener('click', (e) => {
  e.preventDefault();
  fetchStockData();
});

[manualLotInput, manualCoilInput, manualRollInput].forEach(inp => {
  inp?.addEventListener('change', () => {
    if (manualLotInput.value.trim().length >= 3) fetchStockData();
  });
});

manualStockList?.addEventListener('click', (e) => {
  const item = e.target.closest('.manual-stock-item');
  if (!item) return;

  manualStockList.querySelectorAll('.manual-stock-item').forEach(el => el.classList.remove('selected'));
  item.classList.add('selected');

  manualCoilInput.value   = item.dataset.coil;
  manualRollInput.value   = item.dataset.roll;
  manualWidthInput.value  = item.dataset.width;
  manualLengthInput.value = item.dataset.length;

  manualStockNotice.className = 'manual-stock-notice ok';
  manualStockNotice.textContent = `✅ Selected Roll: ${item.dataset.prod} | Coil: ${item.dataset.coil} | Roll: ${item.dataset.roll} (${item.dataset.width}mm x ${item.dataset.length}m)`;
});

manualEntryToggleBtn?.addEventListener('click', (e) => {
  e.stopPropagation();
  toggleManualEntry();
});
closeManualBtn?.addEventListener('click', (e) => {
  e.stopPropagation();
  toggleManualEntry(false);
});
cancelManualBtn?.addEventListener('click', (e) => {
  e.stopPropagation();
  toggleManualEntry(false);
});

manualEntryForm?.addEventListener('submit', function (e) {
  e.preventDefault();
  const lot    = manualLotInput.value.trim();
  const coil   = manualCoilInput.value.trim();
  const roll   = manualRollInput.value.trim();
  const width  = manualWidthInput.value.trim();
  const length = manualLengthInput.value.trim();

  if (!lot || !coil || !roll) {
    showWarning('Please enter Lot No, Coil No, and Roll No.');
    return;
  }

  const raw = `LOT=${lot};COIL=${coil};ROLL=${roll}` +
              (width ? `;WIDTH=${width}` : '') +
              (length ? `;LENGTH=${length}` : '');

  if (isAlreadyScanned(lot, coil, roll, raw)) {
    showWarning('Already Scanned');
    return;
  }

  const body = new URLSearchParams({ raw, lot, coil, roll });
  if (width)  body.set('width', width);
  if (length) body.set('length', length);

  fetch('ajax_lookup.php', { method: 'POST', body })
    .then(r => r.json())
    .then(res => {
      if (!res.success) {
        showWarning(res.error || 'Manual entry failed');
        return;
      }
      scannedRecords.unshift(res.record);
      refreshScanTable();
      showWarning('Added Manual Entry - ' + res.record.d365_lot_no, true);
      resetManualForm();
      toggleManualEntry(false);
    })
    .catch(() => showWarning('Network/server error while adding manual entry.'));
});

// ---------------------------------------------------------------------
// View Mode Switcher (Auto / Tablet / PC) & Tablet Tab Switcher
// ---------------------------------------------------------------------
let currentViewMode = localStorage.getItem('stock_crosscheck_view_mode') || 'auto';
let activeTab = 'scan';

function setViewMode(mode) {
  currentViewMode = mode;
  localStorage.setItem('stock_crosscheck_view_mode', mode);
  document.body.classList.remove('mode-auto', 'mode-tablet', 'mode-pc');
  document.body.classList.add('mode-' + mode);
  
  const btnAuto = document.getElementById('viewAutoBtn');
  const btnTablet = document.getElementById('viewTabletBtn');
  const btnPc = document.getElementById('viewPcBtn');
  if (btnAuto) btnAuto.classList.toggle('active', mode === 'auto');
  if (btnTablet) btnTablet.classList.toggle('active', mode === 'tablet');
  if (btnPc) btnPc.classList.toggle('active', mode === 'pc');

  if (mode === 'pc' || activeTab === 'scan') {
    scanInput.focus();
  }
}

function setActiveTab(tab) {
  activeTab = tab;
  document.body.setAttribute('data-active-tab', tab);
  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.tab === tab);
  });
  if (tab === 'scan') {
    scanInput.focus();
  }
}

document.getElementById('viewAutoBtn')?.addEventListener('click', () => setViewMode('auto'));
document.getElementById('viewTabletBtn')?.addEventListener('click', () => setViewMode('tablet'));
document.getElementById('viewPcBtn')?.addEventListener('click', () => setViewMode('pc'));

document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', (e) => {
    setActiveTab(e.currentTarget.dataset.tab);
  });
});

setViewMode(currentViewMode);
setActiveTab('scan');

// ---------------------------------------------------------------------
// Sidebar Toggle Logic (Desktop Collapsible & Mobile/Tablet Slide Drawer)
// ---------------------------------------------------------------------
let isSidebarOpen = localStorage.getItem('stock_summary_sidebar_open');
if (isSidebarOpen === null) {
  isSidebarOpen = window.innerWidth > 960;
} else {
  isSidebarOpen = isSidebarOpen === 'true';
}

function updateSidebarState(open) {
  isSidebarOpen = open;
  localStorage.setItem('stock_summary_sidebar_open', open);

  const toggleBtnHeader = document.getElementById('toggleSummaryBtn');
  const toggleBtnActions = document.getElementById('toggleSummaryBtnActions');
  const backdrop = document.getElementById('sidebarBackdrop');

  if (open) {
    document.body.classList.remove('sidebar-closed');
    document.body.classList.add('sidebar-open');
    if (toggleBtnHeader) toggleBtnHeader.classList.add('active');
    if (toggleBtnActions) toggleBtnActions.classList.add('active');
    if (backdrop && window.innerWidth <= 960) backdrop.classList.add('active');
  } else {
    document.body.classList.remove('sidebar-open');
    document.body.classList.add('sidebar-closed');
    if (toggleBtnHeader) toggleBtnHeader.classList.remove('active');
    if (toggleBtnActions) toggleBtnActions.classList.remove('active');
    if (backdrop) backdrop.classList.remove('active');
  }
}

function toggleSidebar() {
  updateSidebarState(!isSidebarOpen);
}

document.getElementById('toggleSummaryBtn')?.addEventListener('click', (e) => {
  e.stopPropagation();
  toggleSidebar();
});
document.getElementById('toggleSummaryBtnActions')?.addEventListener('click', (e) => {
  e.stopPropagation();
  toggleSidebar();
});
document.getElementById('closeSummaryBtn')?.addEventListener('click', (e) => {
  e.stopPropagation();
  updateSidebarState(false);
});
document.getElementById('sidebarBackdrop')?.addEventListener('click', () => {
  updateSidebarState(false);
});

updateSidebarState(isSidebarOpen);

// ---------------------------------------------------------------------
// Hands-Free Global Scanner Listener: Automatically captures barcode gun
// input anywhere on the page without requiring the user to click the input!
// ---------------------------------------------------------------------
function isCameraModalActive() {
  const modal = document.getElementById('cameraScanModal');
  return modal && modal.style.display !== 'none';
}

function isManualEntryActive() {
  const formCard = document.getElementById('manualEntryFormCard');
  return (formCard && formCard.style.display !== 'none') || isCameraModalActive();
}

function ensureScanInputFocus() {
  if (isManualEntryActive()) return;
  if (document.activeElement !== scanInput) {
    scanInput.focus();
  }
}

// Focus on page load & tab visibility change
window.addEventListener('load', ensureScanInputFocus);
document.addEventListener('visibilitychange', () => {
  if (!document.hidden) ensureScanInputFocus();
});

// Global keydown capture for barcode scanner gun
window.addEventListener('keydown', (e) => {
  if (isManualEntryActive()) return;

  // Ignore navigation & system shortcuts (Ctrl, Alt, Meta, F-keys, Tab, Escape)
  if (e.ctrlKey || e.altKey || e.metaKey || e.key === 'Tab' || e.key === 'Escape' || e.key.startsWith('F')) {
    return;
  }

  const activeEl = document.activeElement;
  const isTypingInOtherInput = activeEl && (
    activeEl.tagName === 'INPUT' || 
    activeEl.tagName === 'TEXTAREA' || 
    activeEl.tagName === 'SELECT'
  ) && activeEl !== scanInput;

  if (isTypingInOtherInput) return;

  // Shifting focus to scanInput automatically so scanner gun keystrokes stream in!
  if (document.activeElement !== scanInput) {
    scanInput.focus();
  }
}, true);

// Scanner status indicator updates on focus / blur
scanInput.addEventListener('focus', () => {
  const bar = document.getElementById('scannerStatusBar');
  const txt = document.getElementById('scannerStatusText');
  if (bar && txt) {
    bar.className = 'scanner-status-bar';
    txt.innerHTML = '<b>Scanner Active &amp; Ready</b> &mdash; Point barcode gun &amp; press trigger anytime';
  }
});

scanInput.addEventListener('blur', () => {
  setTimeout(() => {
    if (isCameraModalActive()) {
      const bar = document.getElementById('scannerStatusBar');
      const txt = document.getElementById('scannerStatusText');
      if (bar && txt) {
        bar.className = 'scanner-status-bar paused';
        txt.innerHTML = '<b>Tablet Camera Scanner Active</b> &mdash; Close camera scanner to resume barcode gun';
      }
    } else if (isManualEntryActive()) {
      const bar = document.getElementById('scannerStatusBar');
      const txt = document.getElementById('scannerStatusText');
      if (bar && txt) {
        bar.className = 'scanner-status-bar paused';
        txt.innerHTML = '<b>Manual Entry Mode Active</b> &mdash; Close manual entry form to resume barcode gun';
      }
    } else {
      ensureScanInputFocus();
    }
  }, 150);
});

// ---------------------------------------------------------------------
// Tablet Camera QR Scanner Handler (Html5Qrcode)
// ---------------------------------------------------------------------
let html5QrCodeScanner = null;

function playScanBeep() {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(1046.5, ctx.currentTime);
    gain.gain.setValueAtTime(0.15, ctx.currentTime);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + 0.12);
  } catch (err) { /* silent fallback */ }
}

function processScannedQR(decodedText) {
  const raw = decodedText.trim();
  if (!raw) return;

  const continuous = document.getElementById('continuousScanToggle')?.checked;
  if (!continuous) {
    stopCameraScanner();
  }

  scanInput.value = raw;
  const enterEvent = new KeyboardEvent('keydown', { key: 'Enter', code: 'Enter', keyCode: 13, which: 13, bubbles: true });
  scanInput.dispatchEvent(enterEvent);
}

function startCameraScanner() {
  const modal = document.getElementById('cameraScanModal');
  const statusNotice = document.getElementById('cameraStatusNotice');
  modal.style.display = 'flex';
  if (statusNotice) statusNotice.textContent = 'Starting camera viewfinder...';

  if (typeof Html5Qrcode === 'undefined') {
    if (statusNotice) statusNotice.textContent = '❌ Html5Qrcode library not loaded.';
    return;
  }

  if (!html5QrCodeScanner) {
    html5QrCodeScanner = new Html5Qrcode("reader");
  }

  Html5Qrcode.getCameras().then(devices => {
    const cameraSelect = document.getElementById('cameraSelect');
    if (cameraSelect && devices.length > 0) {
      cameraSelect.innerHTML = devices.map((dev, i) => 
        `<option value="${dev.id}">${dev.label || 'Camera ' + (i + 1)}</option>`
      ).join('');
    }

    const config = { fps: 15, qrbox: { width: 250, height: 250 } };
    const cameraParam = devices.length > 0 ? { facingMode: "environment" } : { facingMode: "user" };

    html5QrCodeScanner.start(
      cameraParam,
      config,
      (decodedText) => processScannedQR(decodedText),
      () => { /* frame decode fallback */ }
    ).then(() => {
      if (statusNotice) statusNotice.textContent = '📷 Point camera at QR code label';
    }).catch(err => {
      if (statusNotice) statusNotice.textContent = '❌ Camera error: ' + (err || 'Permission denied');
    });
  }).catch(err => {
    if (statusNotice) statusNotice.textContent = '❌ No camera device found.';
  });
}

function stopCameraScanner() {
  const modal = document.getElementById('cameraScanModal');
  modal.style.display = 'none';
  if (html5QrCodeScanner) {
    try {
      html5QrCodeScanner.stop().catch(() => {});
    } catch(e) {}
  }
  ensureScanInputFocus();
}

document.getElementById('cameraScanBtn')?.addEventListener('click', (e) => {
  e.stopPropagation();
  startCameraScanner();
});
document.getElementById('closeCameraBtn')?.addEventListener('click', stopCameraScanner);
document.getElementById('cameraModalBackdrop')?.addEventListener('click', stopCameraScanner);

document.getElementById('cameraSelect')?.addEventListener('change', (e) => {
  const deviceId = e.target.value;
  if (!deviceId || !html5QrCodeScanner) return;
  const config = { fps: 15, qrbox: { width: 250, height: 250 } };
  html5QrCodeScanner.stop().then(() => {
    html5QrCodeScanner.start(
      deviceId,
      config,
      (decodedText) => processScannedQR(decodedText)
    );
  }).catch(() => {});
});

function saveScrollPositions() {
  const summaryWrap = document.querySelector('.summary-wrap');
  const sideCol = document.querySelector('.side-col');
  sessionStorage.setItem('stock_page_scroll', window.scrollY);
  if (sideCol) sessionStorage.setItem('stock_sidebar_scroll', sideCol.scrollTop);
  if (summaryWrap) sessionStorage.setItem('stock_summary_wrap_scroll', summaryWrap.scrollTop);
}

function restoreScrollPositions() {
  const pageY = sessionStorage.getItem('stock_page_scroll');
  const sideScroll = sessionStorage.getItem('stock_sidebar_scroll');
  const wrapScroll = sessionStorage.getItem('stock_summary_wrap_scroll');

  if (pageY !== null) {
    window.scrollTo(0, parseInt(pageY, 10));
    sessionStorage.removeItem('stock_page_scroll');
  }
  if (sideScroll !== null) {
    const sideCol = document.querySelector('.side-col');
    if (sideCol) sideCol.scrollTop = parseInt(sideScroll, 10);
    sessionStorage.removeItem('stock_sidebar_scroll');
  }
  if (wrapScroll !== null) {
    const summaryWrap = document.querySelector('.summary-wrap');
    if (summaryWrap) summaryWrap.scrollTop = parseInt(wrapScroll, 10);
    sessionStorage.removeItem('stock_summary_wrap_scroll');
  }
}

document.addEventListener('DOMContentLoaded', restoreScrollPositions);

function setFilterDates(from, to, e) {
  if (e) {
    e.preventDefault();
    e.stopPropagation();
  }
  saveScrollPositions();
  const fromEl = document.getElementById('from_date');
  const toEl = document.getElementById('to_date');
  if (fromEl) fromEl.value = from;
  if (toEl) toEl.value = to;
  const form = document.getElementById('dateFilterForm');
  if (form) {
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  }
}

document.getElementById('dateFilterForm')?.addEventListener('submit', saveScrollPositions);

function toggleFilterBox(e) {
  if (e) {
    e.stopPropagation();
    e.preventDefault();
  }
  const content = document.getElementById('summaryFilterContent');
  const icon = document.getElementById('filterToggleIcon');
  if (!content) return;

  const isCurrentlyCollapsed = content.classList.contains('collapsed') || content.style.display === 'none';
  if (isCurrentlyCollapsed) {
    content.classList.remove('collapsed');
    content.style.display = 'block';
    if (icon) icon.textContent = '▼';
  } else {
    content.classList.add('collapsed');
    content.style.display = 'none';
    if (icon) icon.textContent = '▶';
  }
}

document.getElementById('toggleFilterBoxHeader')?.addEventListener('click', toggleFilterBox);
document.getElementById('btnToggleFilter')?.addEventListener('click', toggleFilterBox);

// Keep focus on the scan input on click anywhere on page
document.addEventListener('click', (e) => {
  if (e.target.id !== 'clearAllBtn' && e.target.id !== 'exportBtn' &&
      e.target.className !== 'removeBtn' &&
      !e.target.closest('#summaryFilterBox') &&
      !e.target.closest('#dateFilterForm') &&
      !e.target.closest('#cameraScanBtn') &&
      !e.target.closest('#cameraScanModal') &&
      !e.target.closest('#manualEntryToggleBtn') &&
      !e.target.closest('#manualEntryFormCard') &&
      !e.target.closest('#toggleSummaryBtn') &&
      !e.target.closest('#toggleSummaryBtnActions') &&
      !e.target.closest('#closeSummaryBtn') &&
      !e.target.closest('.side-col')) {
    ensureScanInputFocus();
  }
});
ensureScanInputFocus();

// Remove a mistaken scan
scanTbody.addEventListener('click', (e) => {
  if (!e.target.classList.contains('removeBtn')) return;
  const raw = decodeURIComponent(e.target.dataset.raw);
  fetch('ajax_reset.php', { method: 'POST', body: new URLSearchParams({ action: 'remove', raw }) })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        scannedRecords = scannedRecords.filter(r => r.raw !== raw);
        refreshScanTable();
      }
    });
});

document.getElementById('clearAllBtn').addEventListener('click', () => {
  if (!confirm('Clear all scanned items and start a new count?')) return;
  fetch('ajax_reset.php', { method: 'POST', body: new URLSearchParams({ action: 'clear_all' }) })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        scannedRecords = [];
        refreshScanTable();
      }
    });
});

refreshScanTable();

// ---------------------------------------------------------------------
// Live sync: poll ajax_scans.php so a second device (e.g. supervisor's PC)
// picks up scans made from the tablet without needing to refresh the page.
// Skips a refresh while the scan input has pending text or right after a
// local scan, to avoid visual flicker/race with the in-flight request.
// ---------------------------------------------------------------------
let syncInFlight = false;
setInterval(() => {
  if (syncInFlight) return;
  syncInFlight = true;
  fetch('ajax_scans.php')
    .then(r => r.json())
    .then(res => {
      if (res.success && res.records.length !== scannedRecords.length) {
        scannedRecords = res.records;
        refreshScanTable();
      }
    })
    .catch(() => { /* silent - next poll will retry */ })
    .finally(() => { syncInFlight = false; });
}, 4000);

// ---------------------------------------------------------------------
// STEP 2: Export scanned records to Excel (.xls, tab-separated - opens
// natively in Excel with correct columns, no server-side library needed)
// ---------------------------------------------------------------------
document.getElementById('exportBtn').addEventListener('click', () => {
  if (!scannedRecords.length) {
    alert('No scanned items to export yet.');
    return;
  }

  const header = ['D365 ITEM NUMBER', 'D365 LOT NO', 'MTR'];
  const rows = scannedRecords.map(r => [r.d365_item_number, r.d365_lot_no, r.mtr]);

  const tsv = [header, ...rows].map(row => row.join('\t')).join('\r\n');

  const blob = new Blob([tsv], { type: 'application/vnd.ms-excel' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  const stamp = new Date().toISOString().slice(0, 16).replace(/[:T]/g, '-');
  a.href = url;
  a.download = `stock_scan_export_${stamp}.xls`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
});
</script>

</body>
</html>
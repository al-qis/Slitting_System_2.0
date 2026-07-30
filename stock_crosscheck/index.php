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
session_start();
require_once __DIR__ . '/db_config.php'; // read-only: baseline stock counts per product code
if (!isset($_SESSION['scanned'])) {
    $_SESSION['scanned'] = [];
}
// Pre-load whatever has already been scanned this session into the page.
$existingScans = array_values($_SESSION['scanned']);

// -----------------------------------------------------------------------
// System stock baseline per product code - "how many rolls should exist
// per product right now". Matched the same way ajax_lookup.php derives a
// product code: the letters before the dash in coil_no (e.g. "CH-2" -> "CH")
// looked up in coil_product_map.
//
// ADJUST THE WHERE CLAUSE if "currently in stock" should mean something
// different in your system. Current definition: not yet OUT/DELIVERED and
// not voided.
// -----------------------------------------------------------------------
$baselineStock = [];
$sql = "SELECT cpm.product AS product_code, COUNT(sp.id) AS cnt
        FROM slitting_product sp
        JOIN coil_product_map cpm ON cpm.coil_code = SUBSTRING_INDEX(sp.coil_no, '-', 1)
        WHERE sp.status NOT IN ('OUT','DELIVERED')
          AND (sp.is_voided IS NULL OR sp.is_voided = 0)
        GROUP BY cpm.product";
if ($res = $mysqli->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        $baselineStock[$row['product_code']] = (int) $row['cnt'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>End-of-Month Stock Scan &amp; Export</title>
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

  #warningBanner {
    display:none;
    margin-top:12px;
    padding:11px 14px 11px 16px;
    border-radius:9px;
    background:var(--red-bg);
    color:var(--red);
    border:1px solid var(--red-border);
    border-left:3px solid var(--red);
    font-weight:600;
    font-size:13.5px;
    box-shadow:var(--shadow-sm);
    transition:background-color .2s ease, color .2s ease, border-color .2s ease;
  }
  #warningBanner::before { content:"\26A0\FE0F  "; }
  #warningBanner.ok {
    background:var(--green-bg);
    color:var(--green);
    border-color:var(--green-border);
    border-left-color:var(--green);
  }
  #warningBanner.ok::before { content:"\2705  "; }

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
  .actions { display:flex; gap:10px; margin-top:16px; }

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
</style>
</head>
<body>

<header>
  <div class="header-inner">
    <div class="header-badge">📦</div>
    <div>
      <h1>End-of-Month Stock Scan &amp; Export</h1>
      <p>Slitting System &middot; Temporary physical stock scanning tool</p>
    </div>
  </div>
</header>

<main>
<div class="layout">

  <div class="main-col">

  <!-- STEP 1: SCAN, DEDUPE, CONVERT -->
  <div class="card">
    <h2><span class="step-badge">1</span> Scan Physical Products</h2>
    <input type="text" id="scanInput" placeholder="Click here, then scan a QR code..." autocomplete="off" autofocus>
    <div class="hint">No button needed &mdash; scanning automatically submits when the scanner sends "Enter".</div>
    <div id="warningBanner"></div>
    <div class="counter">Scanned: <b id="scanCount">0</b> item(s)</div>

    <div class="actions">
      <button class="btn btn-ghost" id="clearAllBtn">Clear All Scans</button>
      <button class="btn btn-primary" id="exportBtn">Export to Excel</button>
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
      <h2>Live Reconciliation Summary</h2>
      <div class="table-wrap summary-wrap">
      <table id="summaryTable">
        <thead>
          <tr><th>Product</th><th>System</th><th>Scanned</th><th>Left</th><th>Status</th></tr>
        </thead>
        <tbody id="summaryTbody"></tbody>
      </table>
      </div>
      <div id="summaryEmptyNote" class="empty-note">No baseline stock data found.</div>
    </div>
  </div>

</div>
</main>

<script>
// ---------------------------------------------------------------------
// State: scanned records, seeded from the PHP session (survives refresh)
// ---------------------------------------------------------------------
let scannedRecords = <?php echo json_encode($existingScans, JSON_UNESCAPED_SLASHES); ?>;

// Baseline "should exist" stock count per product code, computed server-side
// on page load (see the SQL query at the top of this file).
const systemStockBaseline = <?php echo json_encode($baselineStock, JSON_UNESCAPED_SLASHES); ?>;

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
      <td>${i + 1}</td><td>${escapeHtml(r.lot)}</td><td>${escapeHtml(r.coil)}</td>
      <td>${escapeHtml(r.product_code)}</td><td>${escapeHtml(r.d365_item_number)}</td>
      <td>${escapeHtml(r.d365_lot_no)}</td><td>${escapeHtml(r.mtr)}</td><td>${escapeHtml(r.scanned_at)}</td>
      <td><button class="removeBtn" data-raw="${encodeURIComponent(r.raw)}">remove</button></td>`;
    scanTbody.appendChild(tr);
  });
  scanCountEl.textContent = scannedRecords.length;
  scanEmptyNote.style.display = scannedRecords.length ? 'none' : 'block';
  renderSummary();
}

// ---------------------------------------------------------------------
// Live reconciliation summary: system baseline vs scanned, per product code
// ---------------------------------------------------------------------
function renderSummary() {
  const summaryTbody = document.getElementById('summaryTbody');
  const summaryEmptyNote = document.getElementById('summaryEmptyNote');

  // Count scans per product code
  const scannedCounts = {};
  scannedRecords.forEach(r => {
    const code = r.product_code || '(unknown)';
    scannedCounts[code] = (scannedCounts[code] || 0) + 1;
  });

  // Union of every product code we know about: baseline + anything scanned
  // that isn't in the baseline (e.g. unexpected/extra scans).
  const allCodes = new Set([...Object.keys(systemStockBaseline), ...Object.keys(scannedCounts)]);

  if (!allCodes.size) {
    summaryTbody.innerHTML = '';
    summaryEmptyNote.style.display = 'block';
    return;
  }
  summaryEmptyNote.style.display = 'none';

  const rows = [...allCodes].sort().map(code => {
    const system   = systemStockBaseline[code] ?? 0;
    const scanned  = scannedCounts[code] || 0;
    const remaining = system - scanned;

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

    return `<tr class="${rowClass}">
      <td>${escapeHtml(code)}</td><td>${system}</td><td>${scanned}</td>
      <td>${remaining}</td><td>${statusHtml}</td></tr>`;
  });

  summaryTbody.innerHTML = rows.join('');
}

function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));
}

function showWarning(msg, ok = false) {
  warningBanner.textContent = msg;
  warningBanner.className = ok ? 'ok' : '';
  warningBanner.style.display = 'block';
  clearTimeout(showWarning._t);
  showWarning._t = setTimeout(() => { warningBanner.style.display = 'none'; }, 2500);
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

scanInput.addEventListener('keydown', function (e) {
  if (e.key !== 'Enter') return;
  e.preventDefault();
  const raw = scanInput.value.trim();
  scanInput.value = '';
  if (!raw) return;

  // Client-side instant dedupe check (server re-checks authoritatively too)
  if (scannedRecords.some(r => r.raw === raw)) {
    showWarning('Already Scanned');
    return;
  }

  const fields = parseQR(raw);
  if (!fields.LOT || !fields.COIL || !fields.ROLL) {
    showWarning('Invalid QR code: missing LOT/COIL/ROLL.');
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
      scannedRecords.push(res.record);
      refreshScanTable();
      showWarning('Scanned OK - ' + res.record.d365_lot_no, true);
    })
    .catch(() => showWarning('Network/server error while looking up scan.'));
});

// Keep focus on the scan input at all times (scanner types into it)
document.addEventListener('click', (e) => {
  if (e.target.id !== 'clearAllBtn' && e.target.id !== 'exportBtn' &&
      e.target.className !== 'removeBtn') {
    scanInput.focus();
  }
});
scanInput.focus();

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
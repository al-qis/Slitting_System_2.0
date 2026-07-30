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
    --navy:#1c2b3a; --blue:#2f6fed; --green:#1a9e5c; --red:#d64545;
    --bg:#f4f6f9; --card:#ffffff; --border:#e1e6ec; --text:#28313c;
  }
  * { box-sizing: border-box; }
  body { margin:0; font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:var(--bg); color:var(--text); }
  header { background:var(--navy); color:#fff; padding:18px 28px; }
  header h1 { margin:0; font-size:20px; font-weight:600; }
  header p { margin:4px 0 0; font-size:13px; color:#c3cede; }
  main { max-width:1300px; margin:24px auto; padding:0 20px 60px; }
  .layout { display:flex; gap:22px; align-items:flex-start; }
  .main-col { flex:1; min-width:0; }
  .side-col { width:320px; flex-shrink:0; position:sticky; top:20px; }
  @media (max-width: 900px) {
    .layout { flex-direction:column; }
    .side-col { width:100%; position:static; }
  }
  .card { background:var(--card); border:1px solid var(--border); border-radius:10px; padding:20px 22px; margin-bottom:22px; }
  .card h2 { margin-top:0; font-size:16px; display:flex; align-items:center; gap:8px; }
  .step-badge { background:var(--blue); color:#fff; width:24px; height:24px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:13px; }
  #scanInput { width:100%; padding:14px 16px; font-size:16px; border:2px solid var(--blue); border-radius:8px; outline:none; }
  #scanInput:focus { box-shadow:0 0 0 3px rgba(47,111,237,.2); }
  .hint { font-size:12.5px; color:#6b7684; margin-top:6px; }
  #warningBanner { display:none; margin-top:10px; padding:10px 14px; border-radius:6px; background:#fdeaea; color:var(--red); border:1px solid #f3c2c2; font-weight:600; }
  #warningBanner.ok { background:#e8f8ef; color:var(--green); border-color:#bfe9d1; }
  .counter { font-size:13px; color:#556; margin-top:10px; }
  .counter b { color:var(--navy); font-size:15px; }
  table { width:100%; border-collapse:collapse; margin-top:14px; font-size:13px; }
  th, td { padding:8px 10px; border-bottom:1px solid var(--border); text-align:left; }
  th { background:#f0f3f7; font-weight:600; color:#3a4552; }
  tr:hover td { background:#fafcff; }
  .removeBtn { color:var(--red); cursor:pointer; font-size:12px; border:none; background:none; text-decoration:underline; }
  .btn { border:none; padding:10px 18px; border-radius:7px; font-size:14px; font-weight:600; cursor:pointer; }
  .btn-primary { background:var(--blue); color:#fff; }
  .btn-primary:hover { background:#2359c9; }
  .btn-ghost { background:#eef1f5; color:#333; }
  .btn-ghost:hover { background:#e2e6ec; }
  .actions { display:flex; gap:10px; margin-top:14px; }
  .empty-note { color:#8a95a1; font-size:13px; padding:10px 0; }
  .side-col table { font-size:12px; }
  .side-col th, .side-col td { padding:6px 6px; }
  .summary-status-complete { color:var(--green); font-weight:600; }
  .summary-status-remaining { color:#a9760f; font-weight:600; }
  .summary-status-over { color:var(--red); font-weight:600; }
  .summary-row-complete td { background:#f6fbf8; }
  .summary-row-remaining td { background:#fffaf0; }
</style>
</head>
<body>

<header>
  <h1>End-of-Month Stock Scan &amp; Export</h1>
  <p>Slitting System &middot; Temporary physical stock scanning tool</p>
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

    <table id="scanTable">
      <thead>
        <tr>
          <th>#</th><th>Lot</th><th>Coil</th><th>Roll</th><th>Width</th><th>Length</th>
          <th>Product Code</th><th>D365 Item Number</th><th>D365 Lot No</th><th>MTR</th><th>Time</th><th></th>
        </tr>
      </thead>
      <tbody id="scanTbody"></tbody>
    </table>
    <div id="scanEmptyNote" class="empty-note">No items scanned yet.</div>

    <div class="actions">
      <button class="btn btn-ghost" id="clearAllBtn">Clear All Scans</button>
    </div>
  </div>

  <!-- STEP 2: EXPORT TO EXCEL -->
  <div class="card">
    <h2><span class="step-badge">2</span> Export Scanned Stock</h2>
    <p class="hint">Once scanning is finished, export the list below as an Excel file with columns
    <b>D365 ITEM NUMBER</b>, <b>D365 LOT NO</b>, <b>MTR</b> &mdash; ready to compare against the D365 export.</p>
    <div class="actions">
      <button class="btn btn-primary" id="exportBtn">Export to Excel</button>
    </div>
  </div>

  </div>

  <div class="side-col">
    <!-- LIVE RECONCILIATION SUMMARY -->
    <div class="card">
      <h2>Live Reconciliation Summary</h2>
      <table id="summaryTable">
        <thead>
          <tr><th>Product</th><th>System</th><th>Scanned</th><th>Left</th><th>Status</th></tr>
        </thead>
        <tbody id="summaryTbody"></tbody>
      </table>
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
      <td>${escapeHtml(r.roll)}</td><td>${escapeHtml(r.width)}</td><td>${escapeHtml(r.length)}</td>
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
<?php
// qc_manage_inspectors.php
// PLACEMENT: C:\Apache24\htdocs\slitting_system\qc_manage_inspectors.php
// Accessible only by the 'qc' role.
// Provides a simple CRUD UI for managing the qc_inspectors table.

session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'qc') {
    header("Location: login.php");
    exit;
}

// Fetch all inspectors (active + inactive) for display
$stmt = $conn->prepare(
    "SELECT id, name, is_active, created_at
     FROM qc_inspectors
     ORDER BY is_active DESC, name ASC"
);
$stmt->execute();
$inspectors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$activeCount   = count(array_filter($inspectors, fn($r) => $r['is_active']));
$inactiveCount = count($inspectors) - $activeCount;
$activeInspectorSession = $_SESSION['active_qc_inspector'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage QC Inspectors</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body        { background: #f4f7f9; font-family: 'Segoe UI', sans-serif; }
        .navbar     { background: #2c3e50; }
        .page-card  {
            max-width: 640px; margin: 40px auto;
            background: #fff; border-radius: 12px;
            box-shadow: 0 2px 16px rgba(0,0,0,.08);
            overflow: hidden;
        }
        .page-card-head {
            background: linear-gradient(135deg, #1a3d6e, #2563eb);
            color: #fff; padding: 22px 28px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .page-card-head h4 { margin: 0; font-weight: 800; font-size: 16px; }
        .page-card-head p  { margin: 3px 0 0; opacity: .75; font-size: 12px; }

        .inspector-list { list-style: none; padding: 0; margin: 0; }
        .inspector-item {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 28px; border-bottom: 1px solid #f0f0f0;
            transition: background .12s;
        }
        .inspector-item:last-child { border-bottom: none; }
        .inspector-item:hover      { background: #f8faff; }
        .inspector-item.inactive   { opacity: .45; }

        .insp-name  { font-weight: 600; font-size: 14px; color: #1a2332; }
        .insp-date  { font-size: 11px; color: #9ca3af; margin-top: 2px; }
        .insp-badge {
            font-size: 10px; font-weight: 700; padding: 2px 8px;
            border-radius: 20px; margin-left: 8px;
        }

        .btn-del {
            background: #fee2e2; color: #991b1b; border: none;
            border-radius: 7px; padding: 5px 12px; font-size: 12px;
            font-weight: 700; cursor: pointer; transition: background .15s;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .btn-del:hover { background: #fca5a5; }

        .btn-edit {
            background: #e0f2fe; color: #0369a1; border: none;
            border-radius: 7px; padding: 5px 12px; font-size: 12px;
            font-weight: 700; cursor: pointer; transition: background .15s;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .btn-edit:hover { background: #bae6fd; }

        .btn-restore {
            background: #dcfce7; color: #166534; border: none;
            border-radius: 7px; padding: 5px 12px; font-size: 12px;
            font-weight: 700; cursor: pointer; transition: background .15s;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .btn-restore:hover { background: #bbf7d0; }

        .add-section { padding: 20px 28px; background: #f8faff; border-top: 1px solid #e5e7eb; }
        .add-section label { font-size: 12px; font-weight: 700; color: #374151; margin-bottom: 5px; display: block; }

        #toast-wrap {
            position: fixed; bottom: 24px; right: 24px; z-index: 9999;
            display: flex; flex-direction: column; gap: 8px; pointer-events: none;
        }
        .toast-msg {
            padding: 11px 18px; border-radius: 10px; font-size: 13px; font-weight: 600;
            box-shadow: 0 6px 24px rgba(0,0,0,.15);
            animation: toastSlide .3s ease forwards;
            pointer-events: all;
        }
        @keyframes toastSlide {
            from { opacity:0; transform:translateX(30px); }
            to   { opacity:1; transform:translateX(0); }
        }
        .toast-ok  { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .toast-err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        .stats-row {
            display: flex; gap: 16px; padding: 16px 28px; border-bottom: 1px solid #e5e7eb;
        }
        .stat-pill {
            background: #eff6ff; color: #1d4ed8; border-radius: 8px;
            padding: 6px 14px; font-size: 12px; font-weight: 700;
        }
        .stat-pill.inactive { background: #f1f5f9; color: #64748b; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="qc_dashboard.php">
            <i class="bi bi-shield-check"></i> NICHIAS QC MANAGEMENT
        </a>
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center bg-white bg-opacity-10 border border-light border-opacity-25 rounded-3 px-3 py-1 text-light">
                <i class="bi bi-person-badge-fill text-warning me-2" style="font-size:18px !important;"></i>
                <label for="globalQcInspectorSelect" class="me-2 mb-0 fw-semibold text-white" style="font-size:15px !important;">Active QC:</label>
                <select id="globalQcInspectorSelect" class="form-select border-0 shadow font-monospace fw-bold text-dark" style="width: auto; background:#fff3cd; font-size:18px !important; color:#856404; padding:6px 36px 6px 14px !important; height:auto !important;" onchange="updateGlobalQcInspector(this.value)">
                    <option value="">-- Select Active Inspector --</option>
                    <?php foreach ($inspectors as $insp): if (!$insp['is_active']) continue; ?>
                    <option value="<?= htmlspecialchars($insp['name'], ENT_QUOTES) ?>" <?= ($activeInspectorSession === $insp['name']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($insp['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <a href="qc_dashboard.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
            </a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <div class="page-card">

        <!-- Header -->
        <div class="page-card-head">
            <div>
                <h4><i class="bi bi-people-fill me-2"></i>Inspector Management</h4>
                <p>Manage names available in the QC "Checked By" dropdown</p>
            </div>
            <i class="bi bi-gear-fill" style="font-size:28px; opacity:.3;"></i>
        </div>

        <!-- Stats row -->
        <div class="stats-row">
            <span class="stat-pill">
                <i class="bi bi-check-circle me-1"></i>
                <?= $activeCount ?> Active
            </span>
            <?php if ($inactiveCount > 0): ?>
            <span class="stat-pill inactive">
                <i class="bi bi-dash-circle me-1"></i>
                <?= $inactiveCount ?> Removed
            </span>
            <?php endif; ?>
        </div>

        <!-- Inspector list -->
        <ul class="inspector-list" id="inspectorList">
            <?php foreach ($inspectors as $insp): ?>
            <li class="inspector-item <?= $insp['is_active'] ? '' : 'inactive' ?>"
                id="inspRow<?= $insp['id'] ?>">
                <div>
                    <div class="insp-name">
                        <span class="insp-name-text"><?= htmlspecialchars($insp['name']) ?></span>
                        <?php if (!$insp['is_active']): ?>
                            <span class="insp-badge" style="background:#f1f5f9;color:#64748b;">Removed</span>
                        <?php else: ?>
                            <span class="insp-badge" style="background:#dcfce7;color:#166534;">Active</span>
                        <?php endif; ?>
                    </div>
                    <div class="insp-date">
                        Added: <?= date('d M Y', strtotime($insp['created_at'])) ?>
                    </div>
                </div>
                <div class="insp-actions d-flex gap-2">
                <?php if ($insp['is_active']): ?>
                    <button class="btn-edit"
                            onclick="editInspector(<?= $insp['id'] ?>, '<?= htmlspecialchars(addslashes($insp['name'])) ?>')">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                    <button class="btn-del"
                            onclick="deleteInspector(<?= $insp['id'] ?>, '<?= htmlspecialchars(addslashes($insp['name'])) ?>')">
                        <i class="bi bi-trash3"></i> Remove
                    </button>
                <?php else: ?>
                    <button class="btn-restore"
                            onclick="restoreInspector('<?= htmlspecialchars(addslashes($insp['name'])) ?>')">
                        <i class="bi bi-arrow-counterclockwise"></i> Restore
                    </button>
                <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>

            <?php if (empty($inspectors)): ?>
            <li class="inspector-item" id="emptyRow">
                <span class="text-muted" style="font-size:13px;">No inspectors added yet.</span>
            </li>
            <?php endif; ?>
        </ul>

        <!-- Add section -->
        <div class="add-section">
            <label for="newInspectorName">Add New Inspector</label>
            <div class="d-flex gap-2">
                <input type="text" id="newInspectorName" class="form-control form-control-sm"
                       placeholder="Enter full name…" maxlength="100"
                       onkeydown="if(event.key==='Enter') addInspector()">
                <button class="btn btn-primary btn-sm px-3 fw-bold" onclick="addInspector()">
                    <i class="bi bi-plus-lg me-1"></i> Add
                </button>
            </div>
            <div class="form-text mt-1">Name must be unique. Max 100 characters.</div>
        </div>

    </div>
</div>

<div id="toast-wrap"></div>

<script>
/* ── Toast ─────────────────────────────────────────────────── */
function toast(msg, ok = true) {
    const w = document.getElementById('toast-wrap');
    const t = document.createElement('div');
    t.className = 'toast-msg ' + (ok ? 'toast-ok' : 'toast-err');
    t.innerHTML = '<i class="bi bi-' + (ok ? 'check-circle-fill' : 'exclamation-circle-fill') + ' me-2"></i>' + esc(msg);
    w.appendChild(t);
    setTimeout(() => {
        t.style.opacity = '0'; t.style.transition = '.3s';
        setTimeout(() => t.remove(), 300);
    }, 3500);
}

function esc(s) {
    return String(s).replace(/[&<>"']/g,
        c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

async function post(data) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(data)) fd.append(k, v);
    const r = await fetch('qc_inspectors_ajax.php', { method: 'POST', body: fd });
    return r.json();
}

/* ── Add inspector ──────────────────────────────────────────── */
async function addInspector() {
    const inp  = document.getElementById('newInspectorName');
    const name = inp.value.trim();
    if (!name) { toast('Please enter a name.', false); inp.focus(); return; }

    const r = await post({ action: 'add', name });
    toast(r.msg, r.ok);

    if (r.ok) {
        inp.value = '';
        // Remove "empty" placeholder if present
        const emptyRow = document.getElementById('emptyRow');
        if (emptyRow) emptyRow.remove();

        // Check if row already exists (restore case)
        const existingRow = document.getElementById('inspRow' + r.id);
        if (existingRow) {
            existingRow.classList.remove('inactive');
            const nameTxt = existingRow.querySelector('.insp-name-text');
            if (nameTxt) nameTxt.textContent = r.name;
            const badge = existingRow.querySelector('.insp-badge');
            if (badge) { badge.style.background = '#dcfce7'; badge.style.color = '#166534'; badge.textContent = 'Active'; }

            const actions = existingRow.querySelector('.insp-actions');
            if (actions) {
                actions.innerHTML = `
                    <button class="btn-edit" onclick="editInspector(${r.id}, '${esc(r.name)}')">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                    <button class="btn-del" onclick="deleteInspector(${r.id}, '${esc(r.name)}')">
                        <i class="bi bi-trash3"></i> Remove
                    </button>`;
            }
        } else {
            // Append new row
            const li = document.createElement('li');
            li.className = 'inspector-item';
            li.id = 'inspRow' + r.id;
            li.innerHTML = `
                <div>
                    <div class="insp-name">
                        <span class="insp-name-text">${esc(r.name)}</span>
                        <span class="insp-badge" style="background:#dcfce7;color:#166534;">Active</span>
                    </div>
                    <div class="insp-date">Added: just now</div>
                </div>
                <div class="insp-actions d-flex gap-2">
                    <button class="btn-edit" onclick="editInspector(${r.id}, '${esc(r.name)}')">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                    <button class="btn-del" onclick="deleteInspector(${r.id}, '${esc(r.name)}')">
                        <i class="bi bi-trash3"></i> Remove
                    </button>
                </div>`;
            document.getElementById('inspectorList').appendChild(li);
        }
    }
}

/* ── Edit inspector ─────────────────────────────────────────── */
async function editInspector(id, currentName) {
    const newName = prompt('Edit inspector name:', currentName);
    if (newName === null) return;
    const name = newName.trim();
    if (!name) { toast('Name cannot be empty.', false); return; }
    if (name === currentName) return;

    const r = await post({ action: 'edit', id, name });
    toast(r.msg, r.ok);

    if (r.ok) {
        const row = document.getElementById('inspRow' + id);
        if (row) {
            const nameTxt = row.querySelector('.insp-name-text');
            if (nameTxt) nameTxt.textContent = r.name;

            const editBtn = row.querySelector('.btn-edit');
            if (editBtn) editBtn.setAttribute('onclick', `editInspector(${id}, '${esc(r.name)}')`);

            const delBtn = row.querySelector('.btn-del');
            if (delBtn) delBtn.setAttribute('onclick', `deleteInspector(${id}, '${esc(r.name)}')`);
        }

        // Update dropdown if option exists
        const sel = document.getElementById('globalQcInspectorSelect');
        if (sel) {
            for (let opt of sel.options) {
                if (opt.value === currentName) {
                    opt.value = r.name;
                    opt.textContent = r.name;
                    break;
                }
            }
        }
    }
}

/* ── Delete (soft) ──────────────────────────────────────────── */
async function deleteInspector(id, name) {
    if (!confirm(`Remove "${name}" from the dropdown?\n\nHistorical QC records using this name are not affected.`)) return;

    const r = await post({ action: 'delete', id });
    toast(r.msg, r.ok);

    if (r.ok) {
        const row = document.getElementById('inspRow' + id);
        if (row) {
            row.classList.add('inactive');
            const badge = row.querySelector('.insp-badge');
            if (badge) { badge.style.background = '#f1f5f9'; badge.style.color = '#64748b'; badge.textContent = 'Removed'; }

            const actions = row.querySelector('.insp-actions');
            if (actions) {
                actions.innerHTML = `
                    <button class="btn-restore" onclick="restoreInspector('${esc(name)}')">
                        <i class="bi bi-arrow-counterclockwise"></i> Restore
                    </button>`;
            }
        }
    }
}

/* ── Restore ────────────────────────────────────────────────── */
async function restoreInspector(name) {
    // Re-use the 'add' action — backend will re-activate the soft-deleted row
    const r = await post({ action: 'add', name });
    toast(r.msg, r.ok);

    if (r.ok) {
        const row = document.getElementById('inspRow' + r.id);
        if (row) {
            row.classList.remove('inactive');
            const nameTxt = row.querySelector('.insp-name-text');
            if (nameTxt) nameTxt.textContent = r.name;
            const badge = row.querySelector('.insp-badge');
            if (badge) { badge.style.background = '#dcfce7'; badge.style.color = '#166534'; badge.textContent = 'Active'; }

            const actions = row.querySelector('.insp-actions');
            if (actions) {
                actions.innerHTML = `
                    <button class="btn-edit" onclick="editInspector(${r.id}, '${esc(r.name)}')">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                    <button class="btn-del" onclick="deleteInspector(${r.id}, '${esc(r.name)}')">
                        <i class="bi bi-trash3"></i> Remove
                    </button>`;
            }
        }
    }
}

function updateGlobalQcInspector(val) {
    val = (val || '').trim();
    localStorage.setItem('active_qc_inspector', val);

    const fd = new FormData();
    fd.append('action', 'set_active_inspector');
    fd.append('name', val);
    fetch('qc_inspectors_ajax.php', { method: 'POST', body: fd }).catch(() => {});
}

document.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('globalQcInspectorSelect');
    if (sel) {
        const saved = localStorage.getItem('active_qc_inspector');
        if (saved && (!sel.value || sel.value === '')) {
            for (let opt of sel.options) {
                if (opt.value === saved) {
                    sel.value = saved;
                    updateGlobalQcInspector(saved);
                    break;
                }
            }
        }
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
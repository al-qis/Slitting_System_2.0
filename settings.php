<?php
session_start();
if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}
include 'config.php';

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'];

/* ─── Handle AJAX / POST actions ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $action = $_POST['action'];

    /* ── 1. Update Username ─────────────────────────── */
    if ($action === 'update_username') {
        $new_username = trim($_POST['new_username'] ?? '');
        if ($new_username === '') { echo json_encode(['ok'=>false,'msg'=>'Username cannot be empty.']); exit; }

        $chk = $conn->prepare("SELECT id FROM users WHERE username=? AND id!=?");
        $chk->bind_param("si", $new_username, $user_id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) { echo json_encode(['ok'=>false,'msg'=>'Username already taken.']); exit; }
        $chk->close();

        $stmt = $conn->prepare("UPDATE users SET username=? WHERE id=?");
        $stmt->bind_param("si", $new_username, $user_id);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok'=>$ok, 'msg'=> $ok ? 'Username updated successfully.' : 'Database error.']);
        exit;
    }

    /* ── 2. Change Password ─────────────────────────── */
    if ($action === 'change_password') {
        $current  = $_POST['current_password']  ?? '';
        $new_pw   = $_POST['new_password']       ?? '';
        $confirm  = $_POST['confirm_password']   ?? '';

        if ($new_pw !== $confirm) { echo json_encode(['ok'=>false,'msg'=>'New passwords do not match.']); exit; }
        if (strlen($new_pw) < 6)  { echo json_encode(['ok'=>false,'msg'=>'Password must be at least 6 characters.']); exit; }

        $stmt = $conn->prepare("SELECT password FROM users WHERE id=?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!password_verify($current, $row['password'])) {
            echo json_encode(['ok'=>false,'msg'=>'Current password is incorrect.']); exit;
        }

        $hash = password_hash($new_pw, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->bind_param("si", $hash, $user_id);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok'=>$ok, 'msg'=> $ok ? 'Password changed successfully.' : 'Database error.']);
        exit;
    }

    /* ── 3. Add Mapping ─────────────────────────────── */
    if ($action === 'add_mapping') {
        $coil_code  = strtoupper(trim($_POST['coil_code']  ?? ''));
        $product    = trim($_POST['product']    ?? '');
        $std_weight = floatval($_POST['std_weight'] ?? 0);
        $nci_code   = trim($_POST['nci_code']   ?? '');
        $nci_width  = trim($_POST['nci_width']  ?? '');
        $customer   = trim($_POST['customer']   ?? '');
        $part_no    = trim($_POST['part_no']    ?? '');

        if (!$coil_code || !$product) { echo json_encode(['ok'=>false,'msg'=>'Coil code and product are required.']); exit; }

        $chk = $conn->prepare("SELECT id FROM coil_product_map WHERE coil_code=? AND product=?");
        $chk->bind_param("ss", $coil_code, $product);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $chk->close();
            echo json_encode(['ok'=>false,'msg'=>"Mapping {$coil_code} → {$product} already exists."]);
            exit;
        }
        $chk->close();

        $conn->begin_transaction();
        try {
            $s1 = $conn->prepare("INSERT INTO coil_product_map (coil_code, product) VALUES (?,?)");
            $s1->bind_param("ss", $coil_code, $product);
            $s1->execute(); $s1->close();

            if ($std_weight > 0) {
                $s2 = $conn->prepare("INSERT INTO std_wgt (product_code, std_weight) VALUES (?,?)
                                      ON DUPLICATE KEY UPDATE std_weight=VALUES(std_weight)");
                $s2->bind_param("sd", $product, $std_weight);
                $s2->execute(); $s2->close();
            }

            if ($nci_code) {
                $s3 = $conn->prepare("INSERT INTO nci_product_mapping (internal_code, product, width, customer, part_no) VALUES (?,?,?,?,?)");
                $s3->bind_param("sssss", $nci_code, $product, $nci_width, $customer, $part_no);
                $s3->execute(); $s3->close();
            }

            $conn->commit();
            echo json_encode(['ok'=>true,'msg'=>'Mapping added successfully.']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['ok'=>false,'msg'=>'Error: '.$e->getMessage()]);
        }
        exit;
    }

    /* ── 4. Update Mapping ──────────────────────────── */
    if ($action === 'update_mapping') {
        $map_id     = intval($_POST['map_id']);
        $new_coil   = strtoupper(trim($_POST['coil_code'] ?? ''));
        $product    = trim($_POST['product']    ?? '');
        $std_weight = floatval($_POST['std_weight'] ?? 0);

        if (!$new_coil)  { echo json_encode(['ok'=>false,'msg'=>'Coil code cannot be empty.']); exit; }
        if (!$product)   { echo json_encode(['ok'=>false,'msg'=>'Product name is required.']); exit; }

        $chk = $conn->prepare("SELECT id FROM coil_product_map WHERE coil_code=? AND product=? AND id!=?");
        $chk->bind_param("ssi", $new_coil, $product, $map_id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $chk->close();
            echo json_encode(['ok'=>false,'msg'=>"Another mapping already uses {$new_coil} → {$product}."]);
            exit;
        }
        $chk->close();

        $conn->begin_transaction();
        try {
            $s1 = $conn->prepare("UPDATE coil_product_map SET coil_code=?, product=? WHERE id=?");
            $s1->bind_param("ssi", $new_coil, $product, $map_id);
            $s1->execute(); $s1->close();

            if ($std_weight > 0) {
                $s2 = $conn->prepare("INSERT INTO std_wgt (product_code, std_weight) VALUES (?,?)
                                      ON DUPLICATE KEY UPDATE std_weight=VALUES(std_weight)");
                $s2->bind_param("sd", $product, $std_weight);
                $s2->execute(); $s2->close();
            }

            $conn->commit();
            echo json_encode([
                'ok'       => true,
                'msg'      => 'Mapping updated successfully.',
                'new_coil' => $new_coil,
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['ok'=>false,'msg'=>'Error: '.$e->getMessage()]);
        }
        exit;
    }

    /* ── 5. Delete Mapping ──────────────────────────── */
    if ($action === 'delete_mapping') {
        $map_id = intval($_POST['map_id']);
        $stmt = $conn->prepare("DELETE FROM coil_product_map WHERE id=?");
        $stmt->bind_param("i", $map_id);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok'=>$ok,'msg'=> $ok ? 'Mapping deleted.' : 'Delete failed.']);
        exit;
    }

    /* ── 6. Update std_wgt directly ─────────────────── */
    if ($action === 'update_stdwgt') {
        $product    = trim($_POST['product']    ?? '');
        $std_weight = floatval($_POST['std_weight'] ?? 0);
        if (!$product || $std_weight <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid data.']); exit; }
        $stmt = $conn->prepare("UPDATE std_wgt SET std_weight=? WHERE product_code=?");
        $stmt->bind_param("ds", $std_weight, $product);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok'=>$ok,'msg'=> $ok ? 'Standard weight updated.' : 'Update failed.']);
        exit;
    }

    /* ── 7. Add NCI Mapping ─────────────────────────── */
    if ($action === 'add_nci_mapping') {
        $internal_code = strtoupper(trim($_POST['internal_code'] ?? ''));
        $product       = trim($_POST['product']       ?? '');
        $width         = trim($_POST['width']         ?? '');
        $customer      = trim($_POST['customer']      ?? '');
        $part_no       = trim($_POST['part_no']       ?? '');

        if (!$internal_code || !$product) {
            echo json_encode(['ok'=>false,'msg'=>'Internal code and product are required.']);
            exit;
        }

        $chk = $conn->prepare("SELECT id FROM nci_product_mapping WHERE internal_code=?");
        $chk->bind_param("s", $internal_code);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $chk->close();
            echo json_encode(['ok'=>false,'msg'=>"Internal code {$internal_code} already exists."]);
            exit;
        }
        $chk->close();

        $stmt = $conn->prepare("INSERT INTO nci_product_mapping (internal_code, product, width, customer, part_no) VALUES (?,?,?,?,?)");
        $stmt->bind_param("sssss", $internal_code, $product, $width, $customer, $part_no);
        $ok = $stmt->execute();
        $new_id = $conn->insert_id;
        $stmt->close();

        echo json_encode([
            'ok'  => $ok,
            'msg' => $ok ? 'NCI mapping added.' : 'Insert failed: '.$conn->error,
            'id'  => $new_id,
            'internal_code' => $internal_code,
            'product'  => $product,
            'width'    => $width,
            'customer' => $customer,
            'part_no'  => $part_no,
        ]);
        exit;
    }

    /* ── 8. Update NCI Mapping ──────────────────────── */
    if ($action === 'update_nci_mapping') {
        $nci_id        = intval($_POST['nci_id']);
        $internal_code = strtoupper(trim($_POST['internal_code'] ?? ''));
        $product       = trim($_POST['product']       ?? '');
        $width         = trim($_POST['width']         ?? '');
        $customer      = trim($_POST['customer']      ?? '');
        $part_no       = trim($_POST['part_no']       ?? '');

        if (!$internal_code || !$product) {
            echo json_encode(['ok'=>false,'msg'=>'Internal code and product are required.']);
            exit;
        }

        // Uniqueness: no other row with the same internal_code
        $chk = $conn->prepare("SELECT id FROM nci_product_mapping WHERE internal_code=? AND id!=?");
        $chk->bind_param("si", $internal_code, $nci_id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $chk->close();
            echo json_encode(['ok'=>false,'msg'=>"Another row already uses internal code {$internal_code}."]);
            exit;
        }
        $chk->close();

        $stmt = $conn->prepare("UPDATE nci_product_mapping SET internal_code=?, product=?, width=?, customer=?, part_no=? WHERE id=?");
        $stmt->bind_param("sssssi", $internal_code, $product, $width, $customer, $part_no, $nci_id);
        $ok = $stmt->execute();
        $stmt->close();

        echo json_encode([
            'ok'  => $ok,
            'msg' => $ok ? 'NCI mapping updated.' : 'Update failed: '.$conn->error,
            'internal_code' => $internal_code,
            'product'  => $product,
            'width'    => $width,
            'customer' => $customer,
            'part_no'  => $part_no,
        ]);
        exit;
    }

    /* ── 9. Delete NCI Mapping ──────────────────────── */
    if ($action === 'delete_nci_mapping') {
        $nci_id = intval($_POST['nci_id']);
        $stmt = $conn->prepare("DELETE FROM nci_product_mapping WHERE id=?");
        $stmt->bind_param("i", $nci_id);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok'=>$ok,'msg'=> $ok ? 'NCI mapping deleted.' : 'Delete failed.']);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action.']);
    exit;
}

/* ─── Load page data ────────────────────────────────────────── */
$current_user = $conn->query("SELECT username FROM users WHERE id=$user_id")->fetch_assoc();

$mappings = $conn->query("
    SELECT c.id, c.coil_code, c.product,
           COALESCE(w.std_weight, 0) AS std_weight
    FROM coil_product_map c
    LEFT JOIN std_wgt w ON w.product_code = c.product
    ORDER BY c.coil_code
");
$mapping_rows = [];
while ($r = $mappings->fetch_assoc()) $mapping_rows[] = $r;

$nci_rows = [];
$nci_res  = $conn->query("SELECT * FROM nci_product_mapping ORDER BY internal_code");
while ($r = $nci_res->fetch_assoc()) $nci_rows[] = $r;

$page_title = "Settings";
include 'header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap');

:root {
  --bg:      #F0F2F5;
  --surface: #FFFFFF;
  --border:  #E4E7EC;
  --navy:    #0D1B2A;
  --text:    #1A2332;
  --muted:   #8A96A3;
  --r:       14px;
  --r-sm:    8px;
  --shadow-s: 0 1px 4px rgba(13,27,42,.07);
  --shadow-m: 0 4px 20px rgba(13,27,42,.11);
}
body { font-family:'DM Sans',sans-serif; background:var(--bg); color:var(--text); }

.pg-head {
  display:flex; align-items:center; justify-content:space-between;
  margin-bottom:28px;
}
.pg-title { font-size:22px; font-weight:800; color:var(--navy); letter-spacing:-.4px; }
.pg-title span { display:block; font-size:12px; font-weight:500; color:var(--muted); margin-top:2px; letter-spacing:0; }

/* ── Tabs ── */
.stab-bar {
  display:flex; gap:4px;
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--r); padding:5px;
  margin-bottom:24px; width:fit-content;
  box-shadow:var(--shadow-s);
}
.stab {
  padding:9px 22px; border-radius:10px;
  font-size:13px; font-weight:700;
  cursor:pointer; border:none; background:transparent;
  color:var(--muted); transition:.15s;
  display:flex; align-items:center; gap:7px;
}
.stab:hover { color:var(--text); background:#F5F7FA; }
.stab.active { background:var(--navy); color:#fff; }

.tab-panel { display:none; }
.tab-panel.active { display:block; }

/* ── Section card ── */
.s-card {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--r); box-shadow:var(--shadow-s);
  margin-bottom:20px; overflow:hidden;
}
.s-card-head {
  padding:16px 22px; border-bottom:1px solid var(--border);
  display:flex; align-items:center; gap:10px;
}
.s-card-icon {
  width:34px; height:34px; border-radius:9px;
  display:flex; align-items:center; justify-content:center;
  font-size:15px;
}
.s-card-title { font-size:13px; font-weight:700; color:var(--navy); text-transform:uppercase; letter-spacing:.4px; }
.s-card-sub   { font-size:11px; color:var(--muted); margin-top:1px; }
.s-card-body  { padding:22px; }

/* ── Form controls ── */
.f-label { font-size:12px; font-weight:700; color:#334155; margin-bottom:5px; display:block; }
.f-ctrl {
  width:100%; border-radius:10px; border:1px solid var(--border);
  padding:10px 14px; font-size:13px; font-family:'DM Sans',sans-serif;
  background:#F8FBFF; color:var(--text); outline:none; transition:.15s;
}
.f-ctrl:focus { border-color:#9ab5ff; background:#fff; box-shadow:0 0 0 3px rgba(59,130,246,.08); }
.f-ctrl[readonly] { background:#F3F4F6; color:var(--muted); cursor:not-allowed; }

.coil-edit-hint {
  font-size:10px; color:#7C3AED; font-weight:600;
  margin-top:4px; display:flex; align-items:center; gap:4px;
}

.btn-prim {
  display:inline-flex; align-items:center; gap:7px;
  padding:10px 22px; border-radius:10px; border:none;
  background:#1d4ed8; color:#fff; font-size:13px; font-weight:700;
  cursor:pointer; transition:.15s;
}
.btn-prim:hover { background:#1e40af; transform:translateY(-1px); box-shadow:0 4px 12px rgba(29,78,216,.25); }
.btn-danger {
  display:inline-flex; align-items:center; gap:6px;
  padding:7px 14px; border-radius:8px; border:none;
  background:#fee2e2; color:#991b1b; font-size:12px; font-weight:700;
  cursor:pointer; transition:.15s;
}
.btn-danger:hover { background:#fca5a5; }
.btn-edit {
  display:inline-flex; align-items:center; gap:6px;
  padding:7px 14px; border-radius:8px; border:none;
  background:#eff6ff; color:#1d4ed8; font-size:12px; font-weight:700;
  cursor:pointer; transition:.15s;
}
.btn-edit:hover { background:#dbeafe; }
.btn-sm {
  padding:6px 12px; font-size:11px; border-radius:7px;
  border:none; cursor:pointer; font-weight:700; transition:.15s;
}

/* ── Mapping table ── */
.map-table { width:100%; border-collapse:collapse; font-size:13px; }
.map-table th {
  padding:9px 14px; background:#F8FAFC; border-bottom:2px solid var(--border);
  font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:1.5px; color:var(--muted);
  text-align:left;
}
.map-table td { padding:10px 14px; border-bottom:1px solid #F3F4F6; vertical-align:middle; }
.map-table tr:last-child td { border-bottom:none; }
.map-table tr:hover td { background:#FAFBFC; }
.code-tag {
  font-family:'DM Mono',monospace; font-size:11px; font-weight:500;
  padding:3px 8px; border-radius:5px; background:#F0F4FF; color:#3730a3;
  display:inline-block;
}
.weight-tag {
  font-family:'DM Mono',monospace; font-size:12px; font-weight:500; color:#059669;
}

@keyframes rowFlash {
  0%   { background:#EDE9FE; }
  100% { background:transparent; }
}
.row-flash td { animation: rowFlash .8s ease-out forwards; }

@keyframes rowFlashTeal {
  0%   { background:#ECFEFF; }
  100% { background:transparent; }
}
.row-flash-teal td { animation: rowFlashTeal .8s ease-out forwards; }

/* ── Search box ── */
.tbl-search {
  position:relative; margin-bottom:14px; max-width:300px;
}
.tbl-search input {
  width:100%; padding:9px 14px 9px 36px; border:1px solid var(--border);
  border-radius:9px; font-size:13px; background:#F8FBFF;
  outline:none; transition:.15s;
}
.tbl-search input:focus { border-color:#9ab5ff; background:#fff; }
.tbl-search i {
  position:absolute; left:11px; top:50%; transform:translateY(-50%);
  color:var(--muted); font-size:13px; pointer-events:none;
}

/* ── Modal ── */
.modal-overlay {
  position:fixed; inset:0; background:rgba(0,0,0,.45); backdrop-filter:blur(4px);
  z-index:9999; display:none; align-items:center; justify-content:center;
}
.modal-overlay.open { display:flex; }
.modal-box {
  background:#fff; border-radius:18px; padding:28px;
  width:520px; max-width:94vw; max-height:88vh; overflow-y:auto;
  box-shadow:0 20px 60px rgba(0,0,0,.2);
  animation:slideUp .22s cubic-bezier(.16,1,.3,1);
}
.modal-box.sm { width:380px; }
@keyframes slideUp {
  from { opacity:0; transform:translateY(20px) scale(.97); }
  to   { opacity:1; transform:translateY(0) scale(1); }
}
.modal-title { font-size:16px; font-weight:800; color:var(--navy); margin-bottom:18px; display:flex; align-items:center; gap:8px; }

/* ── Toast ── */
#toast-wrap {
  position:fixed; bottom:28px; right:28px; z-index:10000;
  display:flex; flex-direction:column; gap:10px; pointer-events:none;
}
.toast {
  display:flex; align-items:center; gap:10px;
  padding:13px 18px; border-radius:12px; max-width:340px;
  font-size:13px; font-weight:600;
  box-shadow:0 8px 30px rgba(0,0,0,.18);
  animation:toastIn .3s cubic-bezier(.16,1,.3,1) forwards;
  pointer-events:all;
}
@keyframes toastIn {
  from { opacity:0; transform:translateX(30px); }
  to   { opacity:1; transform:translateX(0); }
}
.toast-ok   { background:#ECFDF5; color:#065F46; border:1px solid #A7F3D0; }
.toast-err  { background:#FEF2F2; color:#991B1B; border:1px solid #FECACA; }

/* ── Confirm dialog ── */
.confirm-icon { font-size:40px; text-align:center; margin-bottom:10px; }
.confirm-msg  { font-size:14px; color:var(--text); text-align:center; margin-bottom:22px; }
.confirm-btns { display:flex; gap:10px; justify-content:center; }
.btn-cancel   { padding:10px 24px; border-radius:10px; border:1px solid var(--border); background:#fff; color:var(--muted); font-weight:700; font-size:13px; cursor:pointer; transition:.15s; }
.btn-cancel:hover { background:#F5F7FA; }
.btn-confirm-del { padding:10px 24px; border-radius:10px; border:none; background:#dc2626; color:#fff; font-weight:700; font-size:13px; cursor:pointer; transition:.15s; }
.btn-confirm-del:hover { background:#b91c1c; }

/* ── Divider ── */
.divider { height:1px; background:var(--border); margin:18px 0; }

/* ── Scrollable table wrapper ── */
.tbl-wrap { overflow-x:auto; border-radius:var(--r-sm); }

/* ── Dirty row highlight ── */
.wgt-dirty td { background: #FFFBEB !important; }
.wgt-dirty td:first-child { border-left: 3px solid #D97706; }
.wgt-dirty .wgt-inline { border-color:#D97706 !important; background:#FFFBEB !important; }

/* ── Part No cell — truncate long strings gracefully ── */
.part-no-cell {
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 11px;
  color: var(--muted);
}
.part-no-cell:hover { white-space: normal; word-break: break-all; color: var(--text); }
</style>

<!-- Page Header -->
<div class="pg-head">
  <div>
    <div class="pg-title">
      Settings
      <span>Account & Configuration Management</span>
    </div>
  </div>
</div>

<!-- Tab Bar -->
<div class="stab-bar">
  <button class="stab active" onclick="switchTab('security')" id="tab-security">
    <i class="bi bi-shield-lock"></i> Security
  </button>
  <button class="stab" onclick="switchTab('mapping')" id="tab-mapping">
    <i class="bi bi-diagram-3"></i> Product Mapping
  </button>
  <button class="stab" onclick="switchTab('stdwgt')" id="tab-stdwgt">
    <i class="bi bi-calculator"></i> Std Weight
  </button>
  <button class="stab" onclick="switchTab('nci')" id="tab-nci">
    <i class="bi bi-table"></i> NCI Mapping
  </button>
</div>

<!-- ══════════════════════════════════════════════════════════
     TAB 1 — SECURITY
══════════════════════════════════════════════════════════ -->
<div class="tab-panel active" id="panel-security">
  <div class="row g-3">
    <div class="col-md-6">
      <div class="s-card">
        <div class="s-card-head">
          <div class="s-card-icon" style="background:#EFF6FF;color:#2563EB;">
            <i class="bi bi-person-badge"></i>
          </div>
          <div>
            <div class="s-card-title">Username</div>
            <div class="s-card-sub">Update your login username</div>
          </div>
        </div>
        <div class="s-card-body">
          <div class="mb-3">
            <label class="f-label">Current Username</label>
            <input class="f-ctrl" type="text" value="<?= htmlspecialchars($current_user['username']) ?>" readonly>
          </div>
          <div class="mb-4">
            <label class="f-label">New Username</label>
            <input class="f-ctrl" type="text" id="inp-username" placeholder="Enter new username">
          </div>
          <button class="btn-prim" onclick="doUpdateUsername()">
            <i class="bi bi-check-lg"></i> Save Username
          </button>
        </div>
      </div>
    </div>

    <div class="col-md-6">
      <div class="s-card">
        <div class="s-card-head">
          <div class="s-card-icon" style="background:#F0FDF4;color:#059669;">
            <i class="bi bi-key"></i>
          </div>
          <div>
            <div class="s-card-title">Password</div>
            <div class="s-card-sub">Change your account password</div>
          </div>
        </div>
        <div class="s-card-body">
          <div class="mb-3">
            <label class="f-label">Current Password</label>
            <div style="position:relative">
              <input class="f-ctrl" type="password" id="inp-cur-pw" placeholder="Enter current password" style="padding-right:42px">
              <button type="button" onclick="togglePw('inp-cur-pw')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;"><i class="bi bi-eye"></i></button>
            </div>
          </div>
          <div class="mb-3">
            <label class="f-label">New Password</label>
            <div style="position:relative">
              <input class="f-ctrl" type="password" id="inp-new-pw" placeholder="Min. 6 characters" style="padding-right:42px">
              <button type="button" onclick="togglePw('inp-new-pw')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;"><i class="bi bi-eye"></i></button>
            </div>
          </div>
          <div class="mb-4">
            <label class="f-label">Confirm New Password</label>
            <input class="f-ctrl" type="password" id="inp-conf-pw" placeholder="Repeat new password">
          </div>
          <button class="btn-prim" style="background:#059669;" onclick="doChangePassword()">
            <i class="bi bi-lock"></i> Change Password
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     TAB 2 — PRODUCT MAPPING
══════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="panel-mapping">
  <div class="s-card">
    <div class="s-card-head" style="justify-content:space-between;">
      <div style="display:flex;align-items:center;gap:10px;">
        <div class="s-card-icon" style="background:#F5F3FF;color:#7C3AED;"><i class="bi bi-diagram-3"></i></div>
        <div>
          <div class="s-card-title">Coil → Product Mapping</div>
          <div class="s-card-sub"><?= count($mapping_rows) ?> entries · coil_product_map + std_wgt</div>
        </div>
      </div>
      <button class="btn-prim" style="background:#7C3AED;" onclick="openAddMapping()">
        <i class="bi bi-plus-lg"></i> Add Mapping
      </button>
    </div>
    <div class="s-card-body">
      <div class="tbl-search">
        <i class="bi bi-search"></i>
        <input type="text" id="map-search" placeholder="Search coil or product…"
               oninput="filterTable('map-search','map-table')">
      </div>
      <div class="tbl-wrap">
        <table class="map-table" id="map-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Coil Code</th>
              <th>Product</th>
              <th>Std Weight (g/m)</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($mapping_rows as $i => $m): ?>
          <tr data-id="<?= $m['id'] ?>"
              data-coil="<?= htmlspecialchars($m['coil_code']) ?>"
              data-product="<?= htmlspecialchars($m['product']) ?>"
              data-weight="<?= $m['std_weight'] ?>">
            <td style="color:var(--muted);font-size:11px;"><?= $i+1 ?></td>
            <td class="cell-coil"><span class="code-tag"><?= htmlspecialchars($m['coil_code']) ?></span></td>
            <td class="cell-product"><strong><?= htmlspecialchars($m['product']) ?></strong></td>
            <td><span class="weight-tag"><?= number_format($m['std_weight'],4) ?></span></td>
            <td>
              <button class="btn-edit btn-sm"
                      onclick="openEditMapping(
                        <?= $m['id'] ?>,
                        '<?= htmlspecialchars(addslashes($m['coil_code'])) ?>',
                        '<?= htmlspecialchars(addslashes($m['product'])) ?>',
                        <?= $m['std_weight'] ?>
                      )">
                <i class="bi bi-pencil"></i> Edit
              </button>
              <button class="btn-danger btn-sm" style="margin-left:4px;"
                      onclick="confirmDelete(
                        <?= $m['id'] ?>,
                        '<?= htmlspecialchars(addslashes($m['coil_code'].' → '.$m['product'])) ?>'
                      )">
                <i class="bi bi-trash3"></i> Delete
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     TAB 3 — STD WEIGHT
══════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="panel-stdwgt">
  <div class="s-card">
    <div class="s-card-head">
      <div class="s-card-icon" style="background:#FFF7ED;color:#D97706;"><i class="bi bi-calculator"></i></div>
      <div>
        <div class="s-card-title">Standard Weight Table</div>
        <div class="s-card-sub">Direct std_wgt table management · g/m per product code</div>
      </div>
    </div>
    <div class="s-card-body">
      <div class="tbl-search">
        <i class="bi bi-search"></i>
        <input type="text" id="wgt-search" placeholder="Search product code…"
               oninput="filterTable('wgt-search','wgt-table')">
      </div>
      <div class="tbl-wrap">
        <table class="map-table" id="wgt-table">
          <thead>
            <tr><th>#</th><th>Product Code</th><th>Std Weight (g/m)</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php
          $wgt_res = $conn->query("SELECT * FROM std_wgt ORDER BY product_code");
          $wi = 0;
          while ($w = $wgt_res->fetch_assoc()):
            $wi++;
          ?>
          <tr class="wgt-row">
            <td style="color:var(--muted);font-size:11px;"><?= $wi ?></td>
            <td><span class="code-tag"><?= htmlspecialchars($w['product_code']) ?></span></td>
            <td>
              <input type="number" step="0.0001" class="f-ctrl wgt-inline"
                     style="max-width:130px;padding:6px 10px;transition:.2s;"
                     data-code="<?= htmlspecialchars($w['product_code']) ?>"
                     data-original="<?= $w['std_weight'] ?>"
                     value="<?= $w['std_weight'] ?>"
                     oninput="markDirty(this)">
            </td>
            <td style="white-space:nowrap;">
              <button class="btn-edit btn-sm wgt-save-btn" style="display:none;"
                      onclick="saveInlineWeight(this)">
                <i class="bi bi-check-lg"></i> Save
              </button>
              <button class="btn-sm wgt-discard-btn"
                      style="display:none;background:#F3F4F6;color:var(--muted);border:1px solid var(--border);margin-left:4px;"
                      onclick="discardInlineWeight(this)">
                <i class="bi bi-x-lg"></i> Discard
              </button>
            </td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     TAB 4 — NCI MAPPING
══════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="panel-nci">
  <div class="s-card">
    <div class="s-card-head" style="justify-content:space-between;">
      <div style="display:flex;align-items:center;gap:10px;">
        <div class="s-card-icon" style="background:#ECFEFF;color:#0891B2;"><i class="bi bi-table"></i></div>
        <div>
          <div class="s-card-title">NCI Product Mapping</div>
          <div class="s-card-sub" id="nci-count-sub"><?= count($nci_rows) ?> entries · customer part number reference</div>
        </div>
      </div>
      <button class="btn-prim" style="background:#0891B2;" onclick="openAddNci()">
        <i class="bi bi-plus-lg"></i> Add NCI Mapping
      </button>
    </div>
    <div class="s-card-body">
      <div class="tbl-search">
        <i class="bi bi-search"></i>
        <input type="text" id="nci-search" placeholder="Search code, product, customer…"
               oninput="filterTable('nci-search','nci-table')">
      </div>
      <div class="tbl-wrap">
        <table class="map-table" id="nci-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Internal Code</th>
              <th>Product</th>
              <th>Width</th>
              <th>Customer</th>
              <th>Part No</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($nci_rows as $ni => $n): ?>
          <tr data-nci-id="<?= $n['id'] ?>">
            <td style="color:var(--muted);font-size:11px;" class="nci-num"><?= $ni+1 ?></td>
            <td class="nci-code"><span class="code-tag"><?= htmlspecialchars($n['internal_code']) ?></span></td>
            <td class="nci-product"><strong><?= htmlspecialchars($n['product']) ?></strong></td>
            <td class="nci-width"><span style="font-family:'DM Mono',monospace;font-size:12px;"><?= htmlspecialchars($n['width']) ?></span></td>
            <td class="nci-customer"><?= htmlspecialchars($n['customer']) ?></td>
            <td class="nci-partno part-no-cell" title="<?= htmlspecialchars($n['part_no']) ?>"><?= htmlspecialchars($n['part_no']) ?></td>
            <td style="white-space:nowrap;">
              <button class="btn-edit btn-sm"
                      onclick="openEditNci(
                        <?= $n['id'] ?>,
                        '<?= htmlspecialchars(addslashes($n['internal_code'])) ?>',
                        '<?= htmlspecialchars(addslashes($n['product'])) ?>',
                        '<?= htmlspecialchars(addslashes($n['width'])) ?>',
                        '<?= htmlspecialchars(addslashes($n['customer'])) ?>',
                        '<?= htmlspecialchars(addslashes($n['part_no'])) ?>'
                      )">
                <i class="bi bi-pencil"></i> Edit
              </button>
              <button class="btn-danger btn-sm" style="margin-left:4px;"
                      onclick="confirmDeleteNci(
                        <?= $n['id'] ?>,
                        '<?= htmlspecialchars(addslashes($n['internal_code'])) ?>'
                      )">
                <i class="bi bi-trash3"></i> Delete
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>


<!-- ══ MODALS ══════════════════════════════════════════════════ -->

<!-- Add Mapping Modal -->
<div class="modal-overlay" id="modal-add">
  <div class="modal-box">
    <div class="modal-title">
      <i class="bi bi-plus-circle" style="color:#7C3AED;"></i> Add New Mapping
    </div>
    <div class="row g-3">
      <div class="col-6">
        <label class="f-label">Coil Code <span style="color:#dc2626;">*</span></label>
        <input class="f-ctrl" type="text" id="add-coil"
               placeholder="e.g. A" style="text-transform:uppercase;">
      </div>
      <div class="col-6">
        <label class="f-label">Product <span style="color:#dc2626;">*</span></label>
        <input class="f-ctrl" type="text" id="add-product" placeholder="e.g. RS-3825">
      </div>
      <div class="col-6">
        <label class="f-label">Std Weight (g/m)</label>
        <input class="f-ctrl" type="number" step="0.0001" id="add-weight" placeholder="e.g. 2.1690">
      </div>
      <div class="col-6">
        <label class="f-label">NCI Internal Code</label>
        <input class="f-ctrl" type="text" id="add-nci-code" placeholder="e.g. A-115">
      </div>
      <div class="col-6">
        <label class="f-label">Width (NCI)</label>
        <input class="f-ctrl" type="text" id="add-nci-width" placeholder="e.g. 115 mm">
      </div>
      <div class="col-6">
        <label class="f-label">Customer</label>
        <input class="f-ctrl" type="text" id="add-customer" placeholder="e.g. DELPHI">
      </div>
      <div class="col-12">
        <label class="f-label">Part No</label>
        <input class="f-ctrl" type="text" id="add-partno" placeholder="e.g. 6572050">
      </div>
    </div>
    <div class="divider"></div>
    <div style="display:flex;gap:10px;justify-content:flex-end;">
      <button class="btn-cancel" onclick="closeModal('modal-add')">Cancel</button>
      <button class="btn-prim" style="background:#7C3AED;" onclick="doAddMapping()">
        <i class="bi bi-plus-lg"></i> Add Mapping
      </button>
    </div>
  </div>
</div>

<!-- Edit Mapping Modal -->
<div class="modal-overlay" id="modal-edit">
  <div class="modal-box sm">
    <div class="modal-title">
      <i class="bi bi-pencil-square" style="color:#2563EB;"></i> Edit Mapping
    </div>
    <input type="hidden" id="edit-id">

    <div class="mb-3">
      <label class="f-label">
        Coil Code <span style="color:#dc2626;">*</span>
        <span style="font-size:10px;color:#7C3AED;font-weight:600;margin-left:6px;">
          <i class="bi bi-pencil-fill"></i> Editable
        </span>
      </label>
      <input class="f-ctrl" type="text" id="edit-coil"
             style="font-family:'DM Mono',monospace;letter-spacing:.5px;text-transform:uppercase;"
             oninput="this.value=this.value.toUpperCase()">
      <div class="coil-edit-hint">
        <i class="bi bi-info-circle"></i>
        Changing the coil code updates the mapping lookup used during scanning.
      </div>
    </div>

    <div class="mb-3">
      <label class="f-label">Product <span style="color:#dc2626;">*</span></label>
      <input class="f-ctrl" type="text" id="edit-product">
    </div>

    <div class="mb-4">
      <label class="f-label">Std Weight (g/m)</label>
      <input class="f-ctrl" type="number" step="0.0001" id="edit-weight">
    </div>

    <div style="display:flex;gap:10px;justify-content:flex-end;">
      <button class="btn-cancel" onclick="closeModal('modal-edit')">Cancel</button>
      <button class="btn-prim" onclick="doEditMapping()">
        <i class="bi bi-check-lg"></i> Save Changes
      </button>
    </div>
  </div>
</div>

<!-- Add NCI Mapping Modal -->
<div class="modal-overlay" id="modal-nci-add">
  <div class="modal-box">
    <div class="modal-title">
      <i class="bi bi-plus-circle" style="color:#0891B2;"></i> Add NCI Mapping
    </div>
    <div class="row g-3">
      <div class="col-6">
        <label class="f-label">Internal Code <span style="color:#dc2626;">*</span></label>
        <input class="f-ctrl" type="text" id="nci-add-code"
               placeholder="e.g. KB-167" style="text-transform:uppercase;"
               oninput="this.value=this.value.toUpperCase()">
      </div>
      <div class="col-6">
        <label class="f-label">Product <span style="color:#dc2626;">*</span></label>
        <input class="f-ctrl" type="text" id="nci-add-product" placeholder="e.g. KB-6440">
      </div>
      <div class="col-6">
        <label class="f-label">Width</label>
        <input class="f-ctrl" type="text" id="nci-add-width" placeholder="e.g. 167 mm">
      </div>
      <div class="col-6">
        <label class="f-label">Customer</label>
        <input class="f-ctrl" type="text" id="nci-add-customer" placeholder="e.g. AMAK / AMBRAKE">
      </div>
      <div class="col-12">
        <label class="f-label">Part No</label>
        <input class="f-ctrl" type="text" id="nci-add-partno"
               placeholder="e.g. 51-E5112-57431 / AB-E5111-57431">
        <div style="font-size:11px;color:var(--muted);margin-top:4px;">
          <i class="bi bi-info-circle"></i>
          Separate multiple values with <code>/</code> (e.g. <em>PartA / PartB</em>)
        </div>
      </div>
    </div>
    <div class="divider"></div>
    <div style="display:flex;gap:10px;justify-content:flex-end;">
      <button class="btn-cancel" onclick="closeModal('modal-nci-add')">Cancel</button>
      <button class="btn-prim" style="background:#0891B2;" onclick="doAddNci()">
        <i class="bi bi-plus-lg"></i> Add
      </button>
    </div>
  </div>
</div>

<!-- Edit NCI Mapping Modal -->
<div class="modal-overlay" id="modal-nci-edit">
  <div class="modal-box">
    <div class="modal-title">
      <i class="bi bi-pencil-square" style="color:#0891B2;"></i> Edit NCI Mapping
    </div>
    <input type="hidden" id="nci-edit-id">
    <div class="row g-3">
      <div class="col-6">
        <label class="f-label">Internal Code <span style="color:#dc2626;">*</span></label>
        <input class="f-ctrl" type="text" id="nci-edit-code"
               style="text-transform:uppercase;"
               oninput="this.value=this.value.toUpperCase()">
      </div>
      <div class="col-6">
        <label class="f-label">Product <span style="color:#dc2626;">*</span></label>
        <input class="f-ctrl" type="text" id="nci-edit-product">
      </div>
      <div class="col-6">
        <label class="f-label">Width</label>
        <input class="f-ctrl" type="text" id="nci-edit-width">
      </div>
      <div class="col-6">
        <label class="f-label">Customer</label>
        <input class="f-ctrl" type="text" id="nci-edit-customer">
      </div>
      <div class="col-12">
        <label class="f-label">Part No</label>
        <input class="f-ctrl" type="text" id="nci-edit-partno">
        <div style="font-size:11px;color:var(--muted);margin-top:4px;">
          <i class="bi bi-info-circle"></i>
          Separate multiple values with <code>/</code> (e.g. <em>PartA / PartB</em>)
        </div>
      </div>
    </div>
    <div class="divider"></div>
    <div style="display:flex;gap:10px;justify-content:flex-end;">
      <button class="btn-cancel" onclick="closeModal('modal-nci-edit')">Cancel</button>
      <button class="btn-prim" style="background:#0891B2;" onclick="doEditNci()">
        <i class="bi bi-check-lg"></i> Save Changes
      </button>
    </div>
  </div>
</div>

<!-- Confirm Delete Modal (shared) -->
<div class="modal-overlay" id="modal-confirm">
  <div class="modal-box sm">
    <div class="confirm-icon">🗑️</div>
    <div class="modal-title" style="justify-content:center;">Confirm Delete</div>
    <div class="confirm-msg" id="confirm-msg-text"></div>
    <input type="hidden" id="confirm-del-id">
    <input type="hidden" id="confirm-del-type"> <!-- 'mapping' or 'nci' -->
    <div class="confirm-btns">
      <button class="btn-cancel" onclick="closeModal('modal-confirm')">Cancel</button>
      <button class="btn-confirm-del" onclick="doConfirmedDelete()">
        <i class="bi bi-trash3"></i> Yes, Delete
      </button>
    </div>
  </div>
</div>

<!-- Unsaved Changes Modal -->
<div class="modal-overlay" id="modal-unsaved">
  <div class="modal-box sm">
    <div class="confirm-icon">⚠️</div>
    <div class="modal-title" style="justify-content:center;">Unsaved Changes</div>
    <div class="confirm-msg">
      You have unsaved weight changes. If you leave now, your edits will be lost.
    </div>
    <div class="confirm-btns">
      <button class="btn-cancel" id="unsaved-leave">Discard &amp; Leave</button>
      <button class="btn-prim" id="unsaved-stay" style="background:#D97706;">
        <i class="bi bi-pencil"></i> Stay &amp; Save
      </button>
    </div>
  </div>
</div>

<!-- Toast Container -->
<div id="toast-wrap"></div>


<script>
/* ── Tab switching ─────────────────────────────────────────── */
function switchTab(tab) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.stab').forEach(b => b.classList.remove('active'));
  document.getElementById('panel-' + tab).classList.add('active');
  document.getElementById('tab-'   + tab).classList.add('active');
}

/* ── Toast ─────────────────────────────────────────────────── */
function toast(msg, ok = true) {
  const w = document.getElementById('toast-wrap');
  const t = document.createElement('div');
  t.className = 'toast ' + (ok ? 'toast-ok' : 'toast-err');
  t.innerHTML = `<i class="bi bi-${ok ? 'check-circle-fill' : 'exclamation-circle-fill'}"></i> ${msg}`;
  w.appendChild(t);
  setTimeout(() => {
    t.style.opacity   = '0';
    t.style.transform = 'translateX(30px)';
    t.style.transition = '.3s';
    setTimeout(() => t.remove(), 300);
  }, 3500);
}

/* ── Modals ─────────────────────────────────────────────────── */
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});

/* ── Table search ───────────────────────────────────────────── */
function filterTable(inputId, tableId) {
  const q = document.getElementById(inputId).value.toLowerCase();
  document.querySelectorAll(`#${tableId} tbody tr`).forEach(row => {
    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}

/* ── Password toggle ────────────────────────────────────────── */
function togglePw(id) {
  const el = document.getElementById(id);
  el.type = (el.type === 'password') ? 'text' : 'password';
}

/* ── API helper ─────────────────────────────────────────────── */
async function post(data) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(data)) fd.append(k, v);
  const res = await fetch('settings.php', { method: 'POST', body: fd });
  return res.json();
}

/* ── Update Username ────────────────────────────────────────── */
async function doUpdateUsername() {
  const v = document.getElementById('inp-username').value.trim();
  if (!v) { toast('Please enter a new username.', false); return; }
  const r = await post({ action: 'update_username', new_username: v });
  toast(r.msg, r.ok);
  if (r.ok) document.getElementById('inp-username').value = '';
}

/* ── Change Password ────────────────────────────────────────── */
async function doChangePassword() {
  const cur  = document.getElementById('inp-cur-pw').value;
  const np   = document.getElementById('inp-new-pw').value;
  const conf = document.getElementById('inp-conf-pw').value;
  if (!cur || !np || !conf) { toast('All password fields are required.', false); return; }
  if (np !== conf) { toast('New passwords do not match.', false); return; }
  const r = await post({ action:'change_password', current_password:cur, new_password:np, confirm_password:conf });
  toast(r.msg, r.ok);
  if (r.ok) {
    document.getElementById('inp-cur-pw').value  = '';
    document.getElementById('inp-new-pw').value  = '';
    document.getElementById('inp-conf-pw').value = '';
  }
}

/* ── Open Add Mapping ───────────────────────────────────────── */
function openAddMapping() {
  ['add-coil','add-product','add-weight','add-nci-code','add-nci-width','add-customer','add-partno']
    .forEach(id => document.getElementById(id).value = '');
  openModal('modal-add');
  setTimeout(() => document.getElementById('add-coil').focus(), 200);
}

/* ── Open Edit Mapping ──────────────────────────────────────── */
function openEditMapping(id, coil, product, weight) {
  document.getElementById('edit-id').value      = id;
  document.getElementById('edit-coil').value    = coil;
  document.getElementById('edit-product').value = product;
  document.getElementById('edit-weight').value  = weight;
  openModal('modal-edit');
  setTimeout(() => document.getElementById('edit-coil').focus(), 200);
}

/* ── Confirm Delete (shared modal) ─────────────────────────── */
function confirmDelete(id, label) {
  document.getElementById('confirm-del-id').value   = id;
  document.getElementById('confirm-del-type').value = 'mapping';
  document.getElementById('confirm-msg-text').innerHTML =
    `This will permanently remove <strong>${label}</strong> from the mapping table.`;
  openModal('modal-confirm');
}

function confirmDeleteNci(id, code) {
  document.getElementById('confirm-del-id').value   = id;
  document.getElementById('confirm-del-type').value = 'nci';
  document.getElementById('confirm-msg-text').innerHTML =
    `This will permanently remove NCI mapping <strong>${escHtml(code)}</strong>.`;
  openModal('modal-confirm');
}

async function doConfirmedDelete() {
  const id   = document.getElementById('confirm-del-id').value;
  const type = document.getElementById('confirm-del-type').value;
  closeModal('modal-confirm');

  if (type === 'nci') {
    await doDeleteNci(id);
  } else {
    await doDeleteMapping(id);
  }
}

/* ── Add Mapping (CRUD) ─────────────────────────────────────── */
async function doAddMapping() {
  const payload = {
    action:     'add_mapping',
    coil_code:  document.getElementById('add-coil').value.trim().toUpperCase(),
    product:    document.getElementById('add-product').value.trim(),
    std_weight: document.getElementById('add-weight').value,
    nci_code:   document.getElementById('add-nci-code').value.trim(),
    nci_width:  document.getElementById('add-nci-width').value.trim(),
    customer:   document.getElementById('add-customer').value.trim(),
    part_no:    document.getElementById('add-partno').value.trim(),
  };
  if (!payload.coil_code || !payload.product) {
    toast('Coil Code and Product are required.', false); return;
  }
  const r = await post(payload);
  toast(r.msg, r.ok);
  if (r.ok) { closeModal('modal-add'); setTimeout(() => location.reload(), 1200); }
}

/* ── Edit Mapping (CRUD) ────────────────────────────────────── */
async function doEditMapping() {
  const id      = document.getElementById('edit-id').value;
  const coil    = document.getElementById('edit-coil').value.trim().toUpperCase();
  const product = document.getElementById('edit-product').value.trim();
  const weight  = document.getElementById('edit-weight').value;

  if (!coil)    { toast('Coil code cannot be empty.', false); return; }
  if (!product) { toast('Product name is required.', false); return; }

  const r = await post({ action:'update_mapping', map_id:id, coil_code:coil, product, std_weight:weight });
  toast(r.msg, r.ok);

  if (r.ok) {
    closeModal('modal-edit');
    const row = document.querySelector(`#map-table tr[data-id="${id}"]`);
    if (row) {
      row.dataset.coil    = r.new_coil ?? coil;
      row.dataset.product = product;
      row.querySelector('.cell-coil').innerHTML    = `<span class="code-tag">${escHtml(r.new_coil ?? coil)}</span>`;
      row.querySelector('.cell-product').innerHTML = `<strong>${escHtml(product)}</strong>`;
      const editBtn = row.querySelector('.btn-edit');
      if (editBtn) editBtn.setAttribute('onclick',
        `openEditMapping(${id},'${(r.new_coil??coil).replace(/'/g,"\\'")}','${product.replace(/'/g,"\\'")}',${weight||0})`);
      row.classList.add('row-flash');
      row.addEventListener('animationend', () => row.classList.remove('row-flash'), { once:true });
    }
  }
}

/* ── Delete Mapping ─────────────────────────────────────────── */
async function doDeleteMapping(id) {
  const r = await post({ action:'delete_mapping', map_id:id });
  toast(r.msg, r.ok);
  if (r.ok) {
    const row = document.querySelector(`#map-table tr[data-id="${id}"]`);
    if (row) { row.style.opacity='0'; row.style.transition='.3s'; setTimeout(()=>row.remove(),300); }
  }
}

/* ═══════════════════════════════════════════════════════════════
   NCI MAPPING CRUD
═══════════════════════════════════════════════════════════════ */

/* ── Open Add NCI Modal ─────────────────────────────────────── */
function openAddNci() {
  ['nci-add-code','nci-add-product','nci-add-width','nci-add-customer','nci-add-partno']
    .forEach(id => document.getElementById(id).value = '');
  openModal('modal-nci-add');
  setTimeout(() => document.getElementById('nci-add-code').focus(), 200);
}

/* ── Open Edit NCI Modal ────────────────────────────────────── */
function openEditNci(id, code, product, width, customer, part_no) {
  document.getElementById('nci-edit-id').value       = id;
  document.getElementById('nci-edit-code').value     = code;
  document.getElementById('nci-edit-product').value  = product;
  document.getElementById('nci-edit-width').value    = width;
  document.getElementById('nci-edit-customer').value = customer;
  document.getElementById('nci-edit-partno').value   = part_no;
  openModal('modal-nci-edit');
  setTimeout(() => document.getElementById('nci-edit-code').focus(), 200);
}

/* ── Add NCI Mapping ────────────────────────────────────────── */
async function doAddNci() {
  const code     = document.getElementById('nci-add-code').value.trim().toUpperCase();
  const product  = document.getElementById('nci-add-product').value.trim();
  const width    = document.getElementById('nci-add-width').value.trim();
  const customer = document.getElementById('nci-add-customer').value.trim();
  const part_no  = document.getElementById('nci-add-partno').value.trim();

  if (!code || !product) { toast('Internal code and product are required.', false); return; }

  const r = await post({ action:'add_nci_mapping', internal_code:code, product, width, customer, part_no });
  toast(r.msg, r.ok);

  if (r.ok) {
    closeModal('modal-nci-add');

    // Append new row to the table without a full page reload
    const tbody = document.querySelector('#nci-table tbody');
    const rowCount = tbody.querySelectorAll('tr').length + 1;
    const tr = document.createElement('tr');
    tr.dataset.nciId = r.id;
    tr.innerHTML = `
      <td style="color:var(--muted);font-size:11px;" class="nci-num">${rowCount}</td>
      <td class="nci-code"><span class="code-tag">${escHtml(r.internal_code)}</span></td>
      <td class="nci-product"><strong>${escHtml(r.product)}</strong></td>
      <td class="nci-width"><span style="font-family:'DM Mono',monospace;font-size:12px;">${escHtml(r.width)}</span></td>
      <td class="nci-customer">${escHtml(r.customer)}</td>
      <td class="nci-partno part-no-cell" title="${escHtml(r.part_no)}">${escHtml(r.part_no)}</td>
      <td style="white-space:nowrap;">
        <button class="btn-edit btn-sm" onclick="openEditNci(${r.id},'${esc(r.internal_code)}','${esc(r.product)}','${esc(r.width)}','${esc(r.customer)}','${esc(r.part_no)}')">
          <i class="bi bi-pencil"></i> Edit
        </button>
        <button class="btn-danger btn-sm" style="margin-left:4px;" onclick="confirmDeleteNci(${r.id},'${esc(r.internal_code)}')">
          <i class="bi bi-trash3"></i> Delete
        </button>
      </td>`;
    tbody.appendChild(tr);
    tr.classList.add('row-flash-teal');
    tr.addEventListener('animationend', () => tr.classList.remove('row-flash-teal'), { once:true });

    // Update count in subtitle
    const sub = document.getElementById('nci-count-sub');
    if (sub) sub.textContent = `${rowCount} entries · customer part number reference`;
  }
}

/* ── Edit NCI Mapping ───────────────────────────────────────── */
async function doEditNci() {
  const id       = document.getElementById('nci-edit-id').value;
  const code     = document.getElementById('nci-edit-code').value.trim().toUpperCase();
  const product  = document.getElementById('nci-edit-product').value.trim();
  const width    = document.getElementById('nci-edit-width').value.trim();
  const customer = document.getElementById('nci-edit-customer').value.trim();
  const part_no  = document.getElementById('nci-edit-partno').value.trim();

  if (!code || !product) { toast('Internal code and product are required.', false); return; }

  const r = await post({ action:'update_nci_mapping', nci_id:id, internal_code:code, product, width, customer, part_no });
  toast(r.msg, r.ok);

  if (r.ok) {
    closeModal('modal-nci-edit');

    const row = document.querySelector(`#nci-table tr[data-nci-id="${id}"]`);
    if (row) {
      row.querySelector('.nci-code').innerHTML    = `<span class="code-tag">${escHtml(r.internal_code)}</span>`;
      row.querySelector('.nci-product').innerHTML = `<strong>${escHtml(r.product)}</strong>`;
      row.querySelector('.nci-width').innerHTML   = `<span style="font-family:'DM Mono',monospace;font-size:12px;">${escHtml(r.width)}</span>`;
      row.querySelector('.nci-customer').textContent = r.customer;
      const partCell = row.querySelector('.nci-partno');
      partCell.textContent = r.part_no;
      partCell.title       = r.part_no;

      // Refresh the Edit button's onclick with updated values
      const editBtn = row.querySelector('.btn-edit');
      if (editBtn) editBtn.setAttribute('onclick',
        `openEditNci(${id},'${esc(r.internal_code)}','${esc(r.product)}','${esc(r.width)}','${esc(r.customer)}','${esc(r.part_no)}')`);
      const delBtn = row.querySelector('.btn-danger');
      if (delBtn) delBtn.setAttribute('onclick', `confirmDeleteNci(${id},'${esc(r.internal_code)}')`);

      row.classList.add('row-flash-teal');
      row.addEventListener('animationend', () => row.classList.remove('row-flash-teal'), { once:true });
    }
  }
}

/* ── Delete NCI Mapping ─────────────────────────────────────── */
async function doDeleteNci(id) {
  const r = await post({ action:'delete_nci_mapping', nci_id:id });
  toast(r.msg, r.ok);
  if (r.ok) {
    const row = document.querySelector(`#nci-table tr[data-nci-id="${id}"]`);
    if (row) { row.style.opacity='0'; row.style.transition='.3s'; setTimeout(()=>{ row.remove(); renumberNci(); },300); }
  }
}

/* Renumber the # column after a delete */
function renumberNci() {
  document.querySelectorAll('#nci-table tbody tr').forEach((tr, i) => {
    const numCell = tr.querySelector('.nci-num');
    if (numCell) numCell.textContent = i + 1;
  });
  const sub = document.getElementById('nci-count-sub');
  if (sub) {
    const n = document.querySelectorAll('#nci-table tbody tr').length;
    sub.textContent = `${n} entries · customer part number reference`;
  }
}

/* ── Std Weight: dirty-state tracking ──────────────────────── */
function markDirty(inp) {
  const row     = inp.closest('tr');
  const changed = inp.value !== inp.dataset.original;
  row.classList.toggle('wgt-dirty', changed);
  row.querySelector('.wgt-save-btn').style.display    = changed ? '' : 'none';
  row.querySelector('.wgt-discard-btn').style.display = changed ? '' : 'none';
  inp.style.borderColor = changed ? '#D97706' : '';
  inp.style.background  = changed ? '#FFFBEB' : '';
}

function discardInlineWeight(btn) {
  const row = btn.closest('tr');
  const inp = row.querySelector('.wgt-inline');
  inp.value = inp.dataset.original;
  row.classList.remove('wgt-dirty');
  btn.style.display = 'none';
  row.querySelector('.wgt-save-btn').style.display = 'none';
  inp.style.borderColor = '';
  inp.style.background  = '';
}

function hasDirtyWeights() {
  return document.querySelectorAll('.wgt-dirty').length > 0;
}

async function saveInlineWeight(btn) {
  const row = btn.closest('tr');
  const inp = row.querySelector('.wgt-inline');
  if (!inp.value || parseFloat(inp.value) <= 0) {
    toast('Weight must be a positive number.', false); return;
  }
  const r = await post({ action:'update_stdwgt', product:inp.dataset.code, std_weight:inp.value });
  toast(r.msg, r.ok);
  if (r.ok) {
    inp.dataset.original = inp.value;
    row.classList.remove('wgt-dirty');
    btn.style.display = 'none';
    row.querySelector('.wgt-discard-btn').style.display = 'none';
    inp.style.borderColor = '';
    inp.style.background  = '#F0FDF4';
    setTimeout(() => inp.style.background = '', 1200);
  }
}

/* ── Guard: warn before switching tab if dirty ──────────────── */
const _origSwitchTab = switchTab;
window.switchTab = function (tab) {
  if (hasDirtyWeights() && document.getElementById('panel-stdwgt').classList.contains('active')) {
    openModal('modal-unsaved');
    pendingTab = tab;
    return;
  }
  _origSwitchTab(tab);
};

let pendingTab = null;
document.getElementById('unsaved-leave').addEventListener('click', () => {
  document.querySelectorAll('.wgt-dirty').forEach(row => {
    const inp = row.querySelector('.wgt-inline');
    inp.value = inp.dataset.original;
    row.classList.remove('wgt-dirty');
    row.querySelector('.wgt-save-btn').style.display    = 'none';
    row.querySelector('.wgt-discard-btn').style.display = 'none';
    inp.style.borderColor = '';
    inp.style.background  = '';
  });
  closeModal('modal-unsaved');
  if (pendingTab) { _origSwitchTab(pendingTab); pendingTab = null; }
});
document.getElementById('unsaved-stay').addEventListener('click', () => {
  closeModal('modal-unsaved'); pendingTab = null;
});

window.addEventListener('beforeunload', e => {
  if (hasDirtyWeights()) { e.preventDefault(); e.returnValue = ''; }
});

/* ── Helpers ─────────────────────────────────────────────────── */
function escHtml(s) {
  return String(s ?? '').replace(
    /[&<>"']/g,
    c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])
  );
}
// JS-string-safe escape (for onclick attributes)
function esc(s) {
  return String(s ?? '').replace(/\\/g,'\\\\').replace(/'/g,"\\'");
}
</script>

<?php include 'footer.php'; ?>
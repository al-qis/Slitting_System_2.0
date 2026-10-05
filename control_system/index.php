<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/config_store.php';

// Handle AJAX API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    
    $rawInput = file_get_contents('php://input');
    $jsonData = json_decode($rawInput, true);
    
    $postAction = $jsonData['action'] ?? $_POST['action'] ?? '';
    $config     = getSystemControlConfig();
    
    // 1. Update or Auto-Generate Supervisor PIN / Authentication Code
    if ($postAction === 'update_pin' || $postAction === 'auto_generate_pin') {
        if ($postAction === 'auto_generate_pin') {
            $newPin = generateSupervisorAuthCode(6);
        } else {
            $newPin = trim($jsonData['supervisor_pin'] ?? $_POST['supervisor_pin'] ?? '');
        }

        if ($newPin === '') {
            echo json_encode(['ok' => false, 'msg' => 'Supervisor PIN cannot be empty.']);
            exit;
        }

        $config['supervisor_pin'] = $newPin;
        $saved = saveSystemControlConfig($config);
        echo json_encode([
            'ok'   => $saved,
            'pin'  => $newPin,
            'msg'  => $saved ? ($postAction === 'auto_generate_pin' ? 'New 6-digit authentication code generated & saved: ' . $newPin : 'Supervisor PIN updated successfully.') : 'Failed to save PIN.',
            'last_updated' => date('Y-m-d H:i:s'),
            'updated_by'   => $_SESSION['username'] ?? $_SESSION['role'] ?? 'system'
        ]);
        exit;
    }

    // Live Polling API endpoint to fetch current active PIN & metadata
    if ($postAction === 'get_current_pin') {
        echo json_encode([
            'ok'           => true,
            'pin'          => $config['supervisor_pin'] ?? '1234',
            'last_updated' => $config['last_updated'] ?? 'N/A',
            'updated_by'   => $config['updated_by'] ?? 'System'
        ]);
        exit;
    }
    
    // 2. Toggle Individual Button Status (active / blocked)
    if ($postAction === 'toggle_button') {
        $btnKey  = trim($jsonData['button_key'] ?? $_POST['button_key'] ?? '');
        $newState = strtolower(trim($jsonData['status'] ?? $_POST['status'] ?? ''));
        
        if (!$btnKey || !in_array($newState, ['active', 'blocked'], true)) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid button parameters.']);
            exit;
        }
        
        $config['button_states'][$btnKey] = $newState;
        $saved = saveSystemControlConfig($config);
        echo json_encode([
            'ok'      => $saved,
            'msg'     => $saved ? "Button state updated to " . strtoupper($newState) . "." : "Failed to update button state.",
            'key'     => $btnKey,
            'status'  => $newState
        ]);
        exit;
    }
    
    // 3. Bulk Toggle (Block All / Activate All)
    if ($postAction === 'bulk_toggle') {
        $targetState = strtolower(trim($jsonData['target_state'] ?? $_POST['target_state'] ?? 'active'));
        if (!in_array($targetState, ['active', 'blocked'], true)) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid target state.']);
            exit;
        }
        
        foreach ($config['button_states'] as $k => $v) {
            $config['button_states'][$k] = $targetState;
        }
        
        $saved = saveSystemControlConfig($config);
        echo json_encode(['ok' => $saved, 'msg' => "All buttons set to " . strtoupper($targetState) . "."]);
        exit;
    }
    
    echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
    exit;
}

// Retrieve current configuration for rendering
$config       = getSystemControlConfig();
$currentPin   = $config['supervisor_pin'] ?? '1234';
$buttonStates = $config['button_states'] ?? [];

// Count active and blocked buttons
$activeCount  = 0;
$blockedCount = 0;
foreach ($buttonStates as $st) {
    if (strtolower($st) === 'active') {
        $activeCount++;
    } else {
        $blockedCount++;
    }
}

// Button metadata definitions
$buttonMeta = [
    'recoiling_recoil' => [
        'title'       => 'Recoiling - Recoil Button',
        'description' => 'Controls the Recoil button function in recoiling.php.',
        'icon'        => 'arrow-repeat'
    ],
    'reslit_reslit' => [
        'title'       => 'Reslit - Reslit Button',
        'description' => 'Controls the Reslit button function in reslit.php.',
        'icon'        => 'scissors'
    ],
    'finish_stock_edit' => [
        'title'       => 'Finish Product (Stock) - Edit Button',
        'description' => 'Controls the Edit button function under Stock section in finish_product.php.',
        'icon'        => 'pencil-square'
    ],
    'finish_produced_edit' => [
        'title'       => 'Finish Product (Produced) - Edit Button',
        'description' => 'Controls the Edit button function under Produced section in finish_product.php.',
        'icon'        => 'pencil'
    ]
];

$page_title = "Control Center";
$pathPrefix = '../';
include __DIR__ . '/../header.php';
?>

<div class="container-fluid p-0">
    <!-- Header Banner -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-2 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2 text-dark font-weight-bold">
                <i class="bi bi-sliders text-primary me-2"></i>Control Center
            </h1>
            <p class="text-muted mb-0">System Control System — Manage Supervisor PIN & Button Lockout States</p>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0 gap-2">
            <a href="../index.php" class="btn btn-outline-secondary btn-sm fw-bold">
                <i class="bi bi-arrow-left me-1"></i> Dashboard
            </a>
            <button type="button" class="btn btn-outline-success btn-sm fw-bold" id="btnActivateAll">
                <i class="bi bi-check-circle me-1"></i> Activate All Buttons
            </button>
            <button type="button" class="btn btn-outline-danger btn-sm fw-bold" id="btnBlockAll">
                <i class="bi bi-slash-circle me-1"></i> Block All Buttons
            </button>
        </div>
    </div>

    <!-- Live Toast Notification Container -->
    <div id="toastContainer" class="position-fixed bottom-0 end-0 p-3" style="z-index: 1090;"></div>

    <!-- Single Screen PC Layout: 2-Column Dashboard -->
    <div class="row g-3">
        <!-- Left Column: Passkey Card -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-dark text-white fw-bold py-2 px-3 d-flex justify-content-between align-items-center">
                    <span class="small"><i class="bi bi-shield-lock-fill text-warning me-2"></i>Supervisor Passkey</span>
                    <span class="badge bg-primary px-2 py-1" style="font-size: 11px;"><i class="bi bi-arrow-repeat me-1"></i>Auto-Rotate Active</span>
                </div>
                <div class="card-body p-3">
                    <form id="pinUpdateForm">
                        <div class="mb-0">
                            <label for="supervisorPinInput" class="form-label fw-semibold small mb-1">Supervisor Authorization Passkey / Code</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light px-2"><i class="bi bi-key-fill text-primary fs-4"></i></span>
                                <input type="text" 
                                       id="supervisorPinInput" 
                                       class="form-control text-center font-monospace fw-bolder text-primary passkey-display" 
                                       style="font-size: 36px !important; letter-spacing: 5px !important; height: 54px !important;" 
                                       value="<?= htmlspecialchars($currentPin) ?>" 
                                       placeholder="Passkey" 
                                       maxlength="20" 
                                       required>
                                <button class="btn btn-outline-secondary px-3" type="button" id="btnToggleFormPin" title="Toggle Visibility">
                                    <i class="bi bi-eye-slash fs-5"></i>
                                </button>
                            </div>
                        </div>
                    </form>

                    <div class="bg-light p-2 rounded border mt-2 d-flex justify-content-between align-items-center" style="font-size: 12px;">
                        <span class="text-muted">
                            <i class="bi bi-clock-history me-1"></i>Updated: <strong id="lastUpdatedDisplay"><?= htmlspecialchars($config['last_updated'] ?? 'N/A') ?></strong>
                        </span>
                        <span class="text-muted">
                            By: <strong id="updatedByDisplay"><?= htmlspecialchars($config['updated_by'] ?? 'System') ?></strong>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Stats Cards (Top) + System Button Control Matrix (Bottom) -->
        <div class="col-lg-7">
            <!-- Active / Blocked Buttons Summary Cards (Placed ABOVE System Button Control) -->
            <div class="row g-2 mb-2">
                <div class="col-6">
                    <div class="card border-0 shadow-sm bg-success text-white py-2 px-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-uppercase text-white-50 fw-bold" style="font-size: 11px;">Active Buttons</div>
                                <h3 class="mb-0 fw-bold fs-2" id="activeCountDisplay"><?= $activeCount ?></h3>
                            </div>
                            <div class="fs-2 text-white-50"><i class="bi bi-check-circle-fill"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="card border-0 shadow-sm bg-danger text-white py-2 px-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-uppercase text-white-50 fw-bold" style="font-size: 11px;">Blocked Buttons</div>
                                <h3 class="mb-0 fw-bold fs-2" id="blockedCountDisplay"><?= $blockedCount ?></h3>
                            </div>
                            <div class="fs-2 text-white-50"><i class="bi bi-slash-circle-fill"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- System Button Control & Lockout Matrix -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-dark text-white fw-bold py-2 px-3 d-flex justify-content-between align-items-center">
                    <span class="small"><i class="bi bi-toggle-on text-info me-2"></i>System Button Control & Lockout</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="font-size: 13px;">
                            <thead class="table-light">
                                <tr>
                                    <th class="py-2 px-3">Button / Feature</th>
                                    <th class="py-2 px-3">Description</th>
                                    <th class="text-center py-2 px-3">Status</th>
                                    <th class="text-center py-2 px-3" style="width: 150px;">Control Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($buttonMeta as $key => $meta): 
                                    $status = strtolower($buttonStates[$key] ?? 'active');
                                    $isActive = ($status === 'active');
                                ?>
                                    <tr id="row-<?= $key ?>">
                                        <td class="py-2 px-3">
                                            <div class="d-flex align-items-center">
                                                <div class="p-1 rounded bg-light text-primary me-2">
                                                    <i class="bi bi-<?= $meta['icon'] ?> fs-6"></i>
                                                </div>
                                                <strong class="text-dark small"><?= htmlspecialchars($meta['title']) ?></strong>
                                            </div>
                                        </td>
                                        <td class="text-muted py-2 px-3" style="font-size: 12px;">
                                            <?= htmlspecialchars($meta['description']) ?>
                                        </td>
                                        <td class="text-center py-2 px-3" id="status-badge-<?= $key ?>">
                                            <?php if ($isActive): ?>
                                                <span class="badge bg-success px-2 py-1" style="font-size: 11px;">
                                                    <i class="bi bi-check-circle me-1"></i> ACTIVE
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-danger px-2 py-1" style="font-size: 11px;">
                                                    <i class="bi bi-slash-circle me-1"></i> BLOCKED
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center py-2 px-3">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button type="button" 
                                                        class="btn <?= $isActive ? 'btn-success text-white fw-bold' : 'btn-outline-success' ?> btn-toggle-state py-1 px-2" 
                                                        data-key="<?= $key ?>" 
                                                        data-target-status="active" style="font-size: 11px;">
                                                    <i class="bi bi-check-lg me-1"></i>Active
                                                </button>
                                                <button type="button" 
                                                        class="btn <?= !$isActive ? 'btn-danger text-white fw-bold' : 'btn-outline-danger' ?> btn-toggle-state py-1 px-2" 
                                                        data-key="<?= $key ?>" 
                                                        data-target-status="blocked" style="font-size: 11px;">
                                                    <i class="bi bi-x-lg me-1"></i>Block
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const pinFormInput = document.getElementById('supervisorPinInput');
    const btnToggleFormPin = document.getElementById('btnToggleFormPin');
    const pinForm = document.getElementById('pinUpdateForm');

    const lastUpdatedDisplay  = document.getElementById('lastUpdatedDisplay');
    const updatedByDisplay    = document.getElementById('updatedByDisplay');

    // Toggle input visibility in form (Default: text / open)
    btnToggleFormPin.addEventListener('click', function () {
        if (pinFormInput.type === 'text') {
            pinFormInput.type = 'password';
            btnToggleFormPin.innerHTML = '<i class="bi bi-eye"></i>';
        } else {
            pinFormInput.type = 'text';
            btnToggleFormPin.innerHTML = '<i class="bi bi-eye-slash"></i>';
        }
    });

    // Helper: Toast Notifications
    function showToast(message, toastType = true) {
        const toastId = 'toast-' + Date.now();
        let bgClass = 'bg-success';
        let iconClass = 'check-circle-fill';

        if (toastType === 'blocked' || toastType === 'danger' || toastType === false) {
            bgClass = 'bg-danger';
            iconClass = 'slash-circle-fill';
        }

        const html = `
            <div id="${toastId}" class="toast align-items-center text-white ${bgClass} border-0 shadow mb-2" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body font-monospace fw-bold">
                        <i class="bi bi-${iconClass} me-2"></i>${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        `;
        const container = document.getElementById('toastContainer');
        container.insertAdjacentHTML('beforeend', html);
        const toastEl = document.getElementById(toastId);
        const bsToast = new bootstrap.Toast(toastEl, { delay: 4000 });
        bsToast.show();
        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    }

    // Submit PIN update (custom or manually entered)
    pinForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const newPin = pinFormInput.value.trim();

        if (!newPin) {
            showToast('PIN cannot be empty.', false);
            return;
        }

        fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update_pin', supervisor_pin: newPin })
        })
        .then(res => res.json())
        .then(data => {
            if (data.ok) {
                showToast(data.msg, true);
                pinRaw.textContent = newPin;
                if (lastUpdatedDisplay && data.last_updated) lastUpdatedDisplay.textContent = data.last_updated;
                if (updatedByDisplay && data.updated_by) updatedByDisplay.textContent = data.updated_by;
            } else {
                showToast(data.msg || 'Error updating PIN.', false);
            }
        })
        .catch(err => {
            console.error(err);
            showToast('Connection error while updating PIN.', false);
        });
    });

    // Helper: Send toggle AJAX request
    function sendToggleRequest(key, targetStatus) {
        const badgeCell = document.getElementById('status-badge-' + key);
        const currentIsActive = badgeCell ? badgeCell.textContent.includes('ACTIVE') : true;
        const currentStatus = currentIsActive ? 'active' : 'blocked';

        // Do not send request or show toast notification if state did not change
        if (targetStatus === currentStatus) {
            return;
        }

        fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'toggle_button', button_key: key, status: targetStatus })
        })
        .then(res => res.json())
        .then(data => {
            if (data.ok) {
                showToast(data.msg, targetStatus === 'blocked' ? 'danger' : 'success');
                updateRowUI(key, targetStatus);
            } else {
                showToast(data.msg || 'Failed to update button state.', 'danger');
            }
        })
        .catch(err => {
            console.error(err);
            showToast('Connection error.', 'danger');
        });
    }

    let toggleClickTimer = null;

    // Single Click: set target status explicitly
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-toggle-state');
        if (!btn) return;
        e.preventDefault();

        const key = btn.dataset.key;
        const targetStatus = btn.dataset.targetStatus;

        if (toggleClickTimer) {
            clearTimeout(toggleClickTimer);
            toggleClickTimer = null;
        }

        toggleClickTimer = setTimeout(function () {
            toggleClickTimer = null;
            sendToggleRequest(key, targetStatus);
        }, 220);
    });

    // Double Click: Auto switch status (ACTIVE ↔ BLOCKED)
    document.addEventListener('dblclick', function (e) {
        const target = e.target.closest('.btn-toggle-state, [id^="status-badge-"]');
        if (!target) return;
        e.preventDefault();

        let key = target.dataset ? target.dataset.key : null;
        if (!key && target.id) {
            key = target.id.replace('status-badge-', '');
        }
        if (!key) return;

        if (toggleClickTimer) {
            clearTimeout(toggleClickTimer);
            toggleClickTimer = null;
        }

        const badgeCell = document.getElementById('status-badge-' + key);
        const currentIsActive = badgeCell ? badgeCell.textContent.includes('ACTIVE') : true;
        const targetStatus = currentIsActive ? 'blocked' : 'active';

        sendToggleRequest(key, targetStatus);
    });

    // Bulk activate / block buttons
    document.getElementById('btnActivateAll').addEventListener('click', () => bulkToggle('active'));
    document.getElementById('btnBlockAll').addEventListener('click', () => bulkToggle('blocked'));

    function bulkToggle(targetState) {
        fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'bulk_toggle', target_state: targetState })
        })
        .then(res => res.json())
        .then(data => {
            if (data.ok) {
                showToast(data.msg, targetState === 'blocked' ? 'danger' : 'success');
                document.querySelectorAll('.btn-toggle-state').forEach(b => {
                    const key = b.dataset.key;
                    updateRowUI(key, targetState);
                });
            } else {
                showToast(data.msg || 'Bulk update failed.', 'danger');
            }
        })
        .catch(err => {
            console.error(err);
            showToast('Connection error.', 'danger');
        });
    }

    function updateRowUI(key, status) {
        const badgeCell = document.getElementById('status-badge-' + key);
        const isActive = (status === 'active');

        if (badgeCell) {
            badgeCell.innerHTML = isActive 
                ? '<span class="badge bg-success px-2 py-1" style="font-size: 11px;"><i class="bi bi-check-circle me-1"></i> ACTIVE</span>'
                : '<span class="badge bg-danger px-2 py-1" style="font-size: 11px;"><i class="bi bi-slash-circle me-1"></i> BLOCKED</span>';
        }

        const row = document.getElementById('row-' + key);
        if (row) {
            const activeBtn = row.querySelector('[data-target-status="active"]');
            const blockBtn  = row.querySelector('[data-target-status="blocked"]');
            if (activeBtn && blockBtn) {
                if (isActive) {
                    activeBtn.className = 'btn btn-success text-white fw-bold btn-toggle-state py-1 px-2';
                    blockBtn.className  = 'btn btn-outline-danger btn-toggle-state py-1 px-2';
                } else {
                    activeBtn.className = 'btn btn-outline-success btn-toggle-state py-1 px-2';
                    blockBtn.className  = 'btn btn-danger text-white fw-bold btn-toggle-state py-1 px-2';
                }
            }
        }

        // Recalculate Active/Blocked counter header displays
        let activeTotal = 0;
        let blockedTotal = 0;
        document.querySelectorAll('[id^="status-badge-"]').forEach(cell => {
            if (cell.textContent.includes('ACTIVE')) {
                activeTotal++;
            } else {
                blockedTotal++;
            }
        });
        document.getElementById('activeCountDisplay').textContent = activeTotal;
        document.getElementById('blockedCountDisplay').textContent = blockedTotal;
    }

    // Live Polling: Check every 3 seconds for auto-rotated passkey from SFC processing
    setInterval(function () {
        fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_current_pin' })
        })
        .then(res => res.json())
        .then(data => {
            if (data.ok && data.pin && data.pin !== pinFormInput.value.trim()) {
                if (!document.activeElement || document.activeElement !== pinFormInput) {
                    pinFormInput.value = data.pin;
                }
                if (lastUpdatedDisplay && data.last_updated) lastUpdatedDisplay.textContent = data.last_updated;
                if (updatedByDisplay && data.updated_by) updatedByDisplay.textContent = data.updated_by;
                showToast('Passkey used by operator! Auto-generated new code for next coil: ' + data.pin, true);
            }
        })
        .catch(() => {});
    }, 3000);
});
</script>

<?php include __DIR__ . '/../footer.php'; ?>

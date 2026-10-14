<?php
/**
 * Stock Movement Audit Log View
 * Location: /stock_draft_system/frontend/movement_log.php
 */
include 'includes/header.php';
?>

<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h3 class="fw-bold mb-1"><i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Stock Movement Audit Log</h3>
            <p class="text-muted mb-0">Complete historical log of coil status transitions, draft allocations, and officer actions.</p>
        </div>
        <div>
            <button id="btnRefreshLog" class="btn btn-outline-secondary">
                <i class="fa-solid fa-arrows-rotate me-1"></i> Refresh Log
            </button>
        </div>
    </div>
</div>

<div class="card panel-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-custom table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Timestamp</th>
                        <th>Coil No</th>
                        <th>Lot No</th>
                        <th>Status Transition</th>
                        <th>Action</th>
                        <th>Draft Ref</th>
                        <th>Officer</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody id="logTableBody">
                    <tr>
                        <td colspan="9" class="text-center py-4 text-muted">
                            <i class="fa-solid fa-spinner fa-spin me-2"></i>Loading movement log...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    loadMovementLog();
    document.getElementById('btnRefreshLog').addEventListener('click', loadMovementLog);
});

function loadMovementLog() {
    fetch('../backend/api/movement_log.php?limit=100')
        .then(res => res.json())
        .then(res => {
            if (!res.ok) return;
            renderLogTable(res.logs || []);
        });
}

function renderLogTable(logs) {
    const tbody = document.getElementById('logTableBody');
    if (logs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-muted">No movement logs found.</td></tr>';
        return;
    }

    let html = '';
    logs.forEach((l, idx) => {
        const transition = `<span class="badge bg-secondary">${l.previous_status}</span> &rarr; <span class="badge bg-primary">${l.new_status}</span>`;
        const draftRef = l.draft_no ? `<strong>${l.draft_no}</strong> (${l.customer_name})` : '<span class="text-muted">-</span>';

        html += `
            <tr>
                <td class="text-muted fw-semibold">${idx + 1}</td>
                <td class="text-dark fw-semibold"><small>${l.created_at}</small></td>
                <td class="fw-bold text-dark">${l.coil_no || 'N/A'}</td>
                <td><code>${l.lot_no || 'N/A'}</code></td>
                <td>${transition}</td>
                <td><span class="badge bg-light text-dark border">${l.action}</span></td>
                <td><small>${draftRef}</small></td>
                <td><small class="text-muted"><i class="fa-regular fa-user me-1"></i>${l.officer_id}</small></td>
                <td><small class="text-secondary">${l.remarks || '-'}</small></td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}
</script>

<?php include 'includes/footer.php'; ?>

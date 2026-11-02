<?php
/**
 * Stock Coils Management View
 * Location: /stock_draft_system/frontend/stock_coils.php
 */
include 'includes/header.php';
?>

<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h3 class="fw-bold mb-1"><i class="fa-solid fa-layer-group text-primary me-2"></i>Stock Coils Directory</h3>
            <p class="text-muted mb-0">Search, filter, and manage coil statuses within the isolated draft system.</p>
        </div>
        <div>
            <button id="btnRefreshCoils" class="btn btn-outline-secondary shadow-sm">
                <i class="fa-solid fa-arrows-rotate me-1"></i> Refresh
            </button>
            <a href="create_draft.php" class="btn btn-primary shadow-sm ms-2">
                <i class="fa-solid fa-plus me-1"></i> Allocate To Draft
            </a>
        </div>
    </div>
</div>

<!-- Alert Box -->
<div id="coilAlert" class="alert alert-success alert-dismissible fade show d-none mb-3" role="alert">
    <span id="coilAlertMsg">Action completed.</span>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>

<!-- Summary Badges Bar -->
<div class="row row-cols-2 row-cols-sm-3 row-cols-md-5 g-3 mb-4">
    <div class="col">
        <div class="p-3 bg-white rounded-3 shadow-sm border-start border-4 border-success">
            <small class="text-muted d-block fw-semibold">AVAILABLE</small>
            <h4 id="cntAvailable" class="fw-bold mb-0 text-success">0</h4>
        </div>
    </div>
    <div class="col">
        <div class="p-3 bg-white rounded-3 shadow-sm border-start border-4 border-warning">
            <small class="text-muted d-block fw-semibold">RESERVED</small>
            <h4 id="cntReserved" class="fw-bold mb-0 text-warning">0</h4>
        </div>
    </div>
    <div class="col">
        <div class="p-3 bg-white rounded-3 shadow-sm border-start border-4 border-info">
            <small class="text-muted d-block fw-semibold">OUTGOING</small>
            <h4 id="cntOutgoing" class="fw-bold mb-0 text-info">0</h4>
        </div>
    </div>
    <div class="col">
        <div class="p-3 bg-white rounded-3 shadow-sm border-start border-4 border-danger">
            <small class="text-muted d-block fw-semibold">CANCELLED</small>
            <h4 id="cntCancelled" class="fw-bold mb-0 text-danger">0</h4>
        </div>
    </div>
    <div class="col">
        <div class="p-3 bg-white rounded-3 shadow-sm border-start border-4 border-dark">
            <small class="text-muted d-block fw-semibold">TOTAL</small>
            <h4 id="cntTotal" class="fw-bold mb-0 text-dark">0</h4>
        </div>
    </div>
</div>

<!-- Filters Bar -->
<div class="card panel-card mb-4">
    <div class="card-body p-3">
        <div class="row g-2 align-items-center">
            <div class="col-md-3">
                <input type="text" id="filterSearch" class="form-control" placeholder="Search Product, Lot, Coil, Ref...">
            </div>
            <div class="col-md-3">
                <select id="filterCustomer" class="form-select">
                    <option value="">All Customers</option>
                </select>
            </div>
            <div class="col-md-2">
                <select id="filterStatus" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="AVAILABLE">AVAILABLE</option>
                    <option value="RESERVED">RESERVED</option>
                    <option value="OUTGOING">OUTGOING</option>
                    <option value="CANCELLED">CANCELLED</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="text" id="filterGrade" class="form-control" placeholder="Grade (e.g. STD)">
            </div>
            <div class="col-md-2 text-end">
                <button id="btnResetFilters" class="btn btn-outline-secondary w-100">Reset Filters</button>
            </div>
        </div>
    </div>
</div>

<!-- Coils Table -->
<div class="card panel-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-custom table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product Type</th>
                        <th>Lot No + Coil No</th>
                        <th>Width (mm)</th>
                        <th>Length (m)</th>
                        <th>Roll No</th>
                        <th>Customer</th>
                        <th>Ref No</th>
                        <th>State</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="coilsTableBody">
                    <tr>
                        <td colspan="11" class="text-center py-4 text-muted">
                            <i class="fa-solid fa-spinner fa-spin me-2"></i>Loading stock coils...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Status Update Modal -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2 text-primary"></i>Override Coil Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="modalCoilId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Coil Number</label>
                    <input type="text" id="modalCoilNo" class="form-control" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">New Status</label>
                    <select id="modalNewStatus" class="form-select">
                        <option value="AVAILABLE">AVAILABLE</option>
                        <option value="RESERVED">RESERVED</option>
                        <option value="OUTGOING">OUTGOING</option>
                        <option value="USED">USED</option>
                        <option value="CANCELLED">CANCELLED</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Remarks / Reason</label>
                    <textarea id="modalRemarks" class="form-control" rows="3" placeholder="Enter reason for status override..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" id="btnSaveCoilStatus" class="btn btn-primary fw-semibold">Save Status</button>
            </div>
        </div>
    </div>
</div>

<script>
let statusModalObj;

document.addEventListener('DOMContentLoaded', function() {
    statusModalObj = new bootstrap.Modal(document.getElementById('statusModal'));
    loadStockCoils();

    document.getElementById('filterSearch').addEventListener('input', loadStockCoils);
    document.getElementById('filterCustomer').addEventListener('change', loadStockCoils);
    document.getElementById('filterStatus').addEventListener('change', loadStockCoils);
    document.getElementById('filterGrade').addEventListener('input', loadStockCoils);

    document.getElementById('btnResetFilters').addEventListener('click', function() {
        document.getElementById('filterSearch').value = '';
        document.getElementById('filterCustomer').value = '';
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterGrade').value = '';
        loadStockCoils();
    });

    document.getElementById('btnRefreshCoils').addEventListener('click', loadStockCoils);

    document.getElementById('btnSaveCoilStatus').addEventListener('click', saveStatusOverride);
});

function loadStockCoils() {
    const tbody = document.getElementById('coilsTableBody');
    tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4 text-muted"><i class="fa-solid fa-spinner fa-spin me-2"></i>Loading stock coils...</td></tr>';

    const search   = document.getElementById('filterSearch').value;
    const customer = document.getElementById('filterCustomer').value;
    const status   = document.getElementById('filterStatus').value;
    const grade    = document.getElementById('filterGrade').value;

    const query = new URLSearchParams({ search, customer, status, grade, limit: 1000 }).toString();

    fetch('../backend/api/stock_coils.php?' + query)
        .then(res => res.json())
        .then(res => {
            if (!res.ok) {
                tbody.innerHTML = `<tr><td colspan="10" class="text-center py-4 text-danger">Error: ${res.error || 'Failed to fetch data'}</td></tr>`;
                return;
            }
            const stats = res.stats || {};
            document.getElementById('cntAvailable').innerText = stats.available_count || 0;
            document.getElementById('cntReserved').innerText  = stats.reserved_count || 0;
            document.getElementById('cntOutgoing').innerText  = stats.outgoing_count || 0;
            document.getElementById('cntCancelled').innerText = stats.cancelled_count || 0;
            document.getElementById('cntTotal').innerText     = stats.total_coils || 0;

            // Populate Customer Filter Dropdown if options not populated yet
            const custSelect = document.getElementById('filterCustomer');
            if (custSelect.options.length <= 1 && res.customers && res.customers.length > 0) {
                const currentVal = custSelect.value;
                custSelect.innerHTML = '<option value="">All Customers</option>';
                res.customers.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c;
                    opt.textContent = c;
                    if (c === currentVal) opt.selected = true;
                    custSelect.appendChild(opt);
                });
            }

            renderCoilsTable(res.coils || []);
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="10" class="text-center py-4 text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>Failed to load stock coils: ${err.message || err}</td></tr>`;
        });
}

function renderCoilsTable(coils) {
    const tbody = document.getElementById('coilsTableBody');
    if (!coils || coils.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" class="text-center py-4 text-muted">No stock coils found matching current filter.</td></tr>';
        return;
    }

    let html = '';
    coils.forEach((c, idx) => {
        let badgeClass = 'badge-available';
        if (c.status === 'RESERVED') badgeClass = 'badge-reserved';
        if (c.status === 'OUTGOING') badgeClass = 'badge-outgoing';
        if (c.status === 'USED')     badgeClass = 'badge-used';
        if (c.status === 'CANCELLED') badgeClass = 'badge-cancelled';

        let stateBadgeClass = 'bg-secondary';
        const coilState = (c.state || 'STOCK').toUpperCase();
        if (coilState === 'STOCK') stateBadgeClass = 'bg-dark';
        if (coilState === 'PALLETISED') stateBadgeClass = 'bg-primary';
        if (coilState === 'WAITING QC') stateBadgeClass = 'bg-warning text-dark';
        if (coilState === 'APPROVED') stateBadgeClass = 'bg-success';
        if (coilState === 'DELIVERED') stateBadgeClass = 'bg-info text-dark';

        let statusHtml = `<span class="badge-status ${badgeClass}">${c.status}</span>`;
        if (c.status === 'RESERVED' && c.reserved_at) {
            statusHtml += `<div class="text-muted small mt-1" style="font-size: 0.72rem; line-height: 1.1;">
                <i class="fa-regular fa-calendar-check me-1 text-warning"></i>${c.reserved_at}
            </div>`;
        }

        html += `
            <tr>
                <td class="text-muted fw-semibold">${idx + 1}</td>
                <td class="fw-semibold">${c.product} <span class="badge bg-light text-dark border ms-1">${c.grade}</span></td>
                <td><span class="fw-bold text-dark">${c.lot_no}</span> <span class="fw-bold text-dark">${c.coil_no}</span></td>
                <td>${parseFloat(c.width).toFixed(2)}</td>
                <td class="fw-semibold text-primary">${parseFloat(c.length).toFixed(2)}</td>
                <td><span class="badge bg-secondary">${c.roll || '1'}</span></td>
                <td><span class="badge bg-info text-dark fw-bold">${c.customer_name || 'STOCK'}</span></td>
                <td><code>${c.ref_no || '-'}</code></td>
                <td><span class="badge ${stateBadgeClass}">${coilState}</span></td>
                <td>${statusHtml}</td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-dark" onclick="openStatusModal(${c.id}, '${c.coil_no}', '${c.status}')">
                        <i class="fa-solid fa-pen"></i> Status
                    </button>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

function openStatusModal(id, coilNo, currentStatus) {
    document.getElementById('modalCoilId').value = id;
    document.getElementById('modalCoilNo').value = coilNo;
    document.getElementById('modalNewStatus').value = currentStatus;
    document.getElementById('modalRemarks').value = '';
    statusModalObj.show();
}

function saveStatusOverride() {
    const coilId    = document.getElementById('modalCoilId').value;
    const newStatus = document.getElementById('modalNewStatus').value;
    const remarks   = document.getElementById('modalRemarks').value;

    fetch('../backend/api/stock_coils.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            coil_id: coilId,
            new_status: newStatus,
            remarks: remarks
        })
    })
    .then(res => res.json())
    .then(data => {
        statusModalObj.hide();
        if (data.ok) {
            const alertBox = document.getElementById('coilAlert');
            const alertMsg = document.getElementById('coilAlertMsg');
            alertBox.classList.remove('d-none');
            alertMsg.innerText = data.msg;
            loadStockCoils();
        } else {
            alert(data.error || 'Failed to update status');
        }
    })
    .catch(err => {
        alert('Error updating status: ' + err);
    });
}
</script>

<?php include 'includes/footer.php'; ?>

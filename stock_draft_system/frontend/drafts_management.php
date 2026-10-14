<?php
/**
 * Drafts Management View
 * Location: /stock_draft_system/frontend/drafts_management.php
 */
include 'includes/header.php';
?>

<style>
.copy-col-btn {
    opacity: 0.35;
    transition: opacity 0.2s, color 0.2s, transform 0.15s;
    font-size: 0.78rem;
    cursor: pointer;
    border: none;
    background: transparent;
    padding: 2px 5px !important;
    line-height: 1;
}
th:hover .copy-col-btn, .copy-col-btn:hover {
    opacity: 1;
    color: #0d6efd !important;
    transform: scale(1.15);
}
.copyable-cell {
    cursor: copy;
    transition: background-color 0.15s ease;
}
.copyable-cell:hover {
    background-color: rgba(13, 110, 253, 0.05);
}
.copy-row-btn {
    transition: all 0.15s ease;
}
</style>

<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h3 class="fw-bold mb-1"><i class="fa-solid fa-file-signature text-primary me-2"></i>Customer Draft Orders</h3>
            <p class="text-muted mb-0">Manage active reservations, view allocated coils, confirm orders, or revoke drafts.</p>
        </div>
        <div>
            <a href="create_draft.php" class="btn btn-primary shadow-sm fw-semibold">
                <i class="fa-solid fa-plus me-1"></i> New Draft Order
            </a>
        </div>
    </div>
</div>

<!-- Alert Message -->
<div id="draftAlert" class="alert alert-success alert-dismissible fade show d-none mb-3" role="alert">
    <span id="draftAlertMsg">Action completed.</span>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>

<!-- Filter Bar -->
<div class="card panel-card mb-4">
    <div class="card-body p-3">
        <div class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" id="filterDraftSearch" class="form-control" placeholder="Search Customer Name, SOS No...">
            </div>
            <div class="col-md-3">
                <select id="filterDraftStatus" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="RESERVED">RESERVED</option>
                    <option value="CONFIRMED">CONFIRMED</option>
                    <option value="CANCELLED">CANCELLED</option>
                </select>
            </div>
            <div class="col-md-5 text-end d-flex justify-content-end gap-2">
                <button id="btnCopyDraftsTable" class="btn btn-outline-primary" onclick="copyTableExcel('draftsTable', this)" title="Copy entire table to clipboard (Excel format)">
                    <i class="fa-regular fa-copy me-1"></i> Copy Table
                </button>
                <button id="btnRefreshDrafts" class="btn btn-outline-secondary">
                    <i class="fa-solid fa-arrows-rotate me-1"></i> Refresh List
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Drafts List Table -->
<div class="card panel-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-custom table-hover mb-0 align-middle" id="draftsTable">
                <thead>
                    <tr>
                        <th>
                            <div class="d-flex align-items-center justify-content-between">
                                <span>Customer</span>
                                <button type="button" class="copy-col-btn btn btn-sm p-0 text-muted" onclick="copyColumn('draftsTable', 0, 'Customer', this)" title="Copy Customer column">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </th>
                        <th>
                            <div class="d-flex align-items-center justify-content-between">
                                <span>Product</span>
                                <button type="button" class="copy-col-btn btn btn-sm p-0 text-muted" onclick="copyColumn('draftsTable', 1, 'Product', this)" title="Copy Product column">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </th>
                        <th>
                            <div class="d-flex align-items-center justify-content-between">
                                <span>Width (mm)</span>
                                <button type="button" class="copy-col-btn btn btn-sm p-0 text-muted" onclick="copyColumn('draftsTable', 2, 'Width', this)" title="Copy Width column">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </th>
                        <th>
                            <div class="d-flex align-items-center justify-content-between">
                                <span>Sel. Length (m)</span>
                                <button type="button" class="copy-col-btn btn btn-sm p-0 text-muted" onclick="copyColumn('draftsTable', 3, 'Length', this)" title="Copy Length column">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </th>
                        <th>
                            <div class="d-flex align-items-center justify-content-between">
                                <span>Coils Count</span>
                                <button type="button" class="copy-col-btn btn btn-sm p-0 text-muted" onclick="copyColumn('draftsTable', 4, 'Coils Count', this)" title="Copy Coils Count column">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </th>
                        <th>
                            <div class="d-flex align-items-center justify-content-between">
                                <span>Status</span>
                                <button type="button" class="copy-col-btn btn btn-sm p-0 text-muted" onclick="copyColumn('draftsTable', 5, 'Status', this)" title="Copy Status column">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </th>
                        <th>
                            <div class="d-flex align-items-center justify-content-between">
                                <span>Created At</span>
                                <button type="button" class="copy-col-btn btn btn-sm p-0 text-muted" onclick="copyColumn('draftsTable', 6, 'Created At', this)" title="Copy Created At column">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="draftsTableBody">
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            <i class="fa-solid fa-spinner fa-spin me-2"></i>Loading draft orders...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Draft Detail Modal -->
<div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold" id="detailModalTitle"><i class="fa-solid fa-circle-info me-2"></i>Draft Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3 mb-3 p-3 bg-light rounded border">
                    <div class="col-md-4">
                        <small class="text-muted d-block">Customer</small>
                        <strong id="modalCustomer">-</strong>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block">SOS Number</small>
                        <strong id="modalSosNo">-</strong>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block">Status</small>
                        <span id="modalStatusBadge" class="badge bg-primary">-</span>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold mb-0">Allocated Coils</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="copyTableExcel('modalItemsTable', this)" title="Copy all coils to clipboard (Excel format)">
                        <i class="fa-regular fa-copy me-1"></i> Copy All Coils
                    </button>
                </div>
                <div class="table-responsive border rounded">
                    <table class="table table-sm table-hover mb-0 align-middle" id="modalItemsTable">
                        <thead class="table-light">
                            <tr>
                                <th>
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span>Product Type</span>
                                        <button type="button" class="copy-col-btn btn btn-sm p-0 text-muted" onclick="copyColumn('modalItemsTable', 0, 'Product Type', this)" title="Copy Product Type column">
                                            <i class="fa-regular fa-copy"></i>
                                        </button>
                                    </div>
                                </th>
                                <th>
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span>Lot No + Coil No</span>
                                        <button type="button" class="copy-col-btn btn btn-sm p-0 text-muted" onclick="copyColumn('modalItemsTable', 1, 'Lot No + Coil No', this)" title="Copy Lot No + Coil No column">
                                            <i class="fa-regular fa-copy"></i>
                                        </button>
                                    </div>
                                </th>
                                <th>
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span>Ref No</span>
                                        <button type="button" class="copy-col-btn btn btn-sm p-0 text-muted" onclick="copyColumn('modalItemsTable', 2, 'Ref No', this)" title="Copy Ref No column">
                                            <i class="fa-regular fa-copy"></i>
                                        </button>
                                    </div>
                                </th>
                                <th>
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span>Length (m)</span>
                                        <button type="button" class="copy-col-btn btn btn-sm p-0 text-muted" onclick="copyColumn('modalItemsTable', 3, 'Length', this)" title="Copy Length column">
                                            <i class="fa-regular fa-copy"></i>
                                        </button>
                                    </div>
                                </th>
                                <th>
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span>Width (mm)</span>
                                        <button type="button" class="copy-col-btn btn btn-sm p-0 text-muted" onclick="copyColumn('modalItemsTable', 4, 'Width', this)" title="Copy Width column">
                                            <i class="fa-regular fa-copy"></i>
                                        </button>
                                    </div>
                                </th>
                                <th>
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span>Roll No</span>
                                        <button type="button" class="copy-col-btn btn btn-sm p-0 text-muted" onclick="copyColumn('modalItemsTable', 5, 'Roll No', this)" title="Copy Roll No column">
                                            <i class="fa-regular fa-copy"></i>
                                        </button>
                                    </div>
                                </th>
                                <th>
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span>Coil Status</span>
                                        <button type="button" class="copy-col-btn btn btn-sm p-0 text-muted" onclick="copyColumn('modalItemsTable', 6, 'Coil Status', this)" title="Copy Coil Status column">
                                            <i class="fa-regular fa-copy"></i>
                                        </button>
                                    </div>
                                </th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>

                        <tbody id="modalItemsTbody">
                            <!-- Populated dynamically -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Copy Notification Toast -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1100;">
    <div id="copyToast" class="toast align-items-center text-white bg-dark border-0 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body d-flex align-items-center gap-2 py-2 px-3">
                <i class="fa-solid fa-circle-check text-success fs-5"></i>
                <span id="copyToastMsg" class="fw-semibold">Copied to clipboard!</span>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<script>
let detailModalObj;
let rawDraftsList = [];

document.addEventListener('DOMContentLoaded', function() {
    detailModalObj = new bootstrap.Modal(document.getElementById('detailModal'));
    loadDrafts();

    document.getElementById('filterDraftSearch').addEventListener('input', loadDrafts);
    document.getElementById('filterDraftStatus').addEventListener('change', loadDrafts);
    document.getElementById('btnRefreshDrafts').addEventListener('click', loadDrafts);
});

function showAlert(type, msg) {
    const alertEl = document.getElementById('draftAlert');
    const msgEl = document.getElementById('draftAlertMsg');
    if (!alertEl || !msgEl) {
        if (typeof showCopyToast === 'function') showCopyToast(msg);
        return;
    }
    alertEl.className = `alert alert-${type} alert-dismissible fade show mb-3`;
    msgEl.innerText = msg;
    alertEl.classList.remove('d-none');
    window.scrollTo({ top: 0, behavior: 'smooth' });
    setTimeout(() => {
        alertEl.classList.add('d-none');
    }, 4500);
}

function loadDrafts() {
    const search = document.getElementById('filterDraftSearch').value;
    const status = document.getElementById('filterDraftStatus').value;
    const query = new URLSearchParams({ search, status }).toString();

    fetch('../backend/api/drafts.php?' + query)
        .then(res => res.json())
        .then(res => {
            if (!res.ok) return;
            rawDraftsList = res.orders || [];
            renderDraftsTable(rawDraftsList);
        });
}

function renderDraftsTable(drafts) {
    const tbody = document.getElementById('draftsTableBody');
    if (drafts.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">No draft orders found.</td></tr>';
        return;
    }

    let html = '';
    drafts.forEach(d => {
        let badgeClass = 'badge-reserved';
        if (d.status === 'CONFIRMED') badgeClass = 'badge-outgoing';
        if (d.status === 'CANCELLED') badgeClass = 'badge-cancelled';

        const lenColor = 'text-dark';

        // Extract unique Products and Widths from draft allocated items
        const items = d.items || [];
        const uniqueProducts = [...new Set(items.map(it => it.product).filter(Boolean))];
        const uniqueWidths   = [...new Set(items.map(it => parseFloat(it.width || 0).toFixed(2)).filter(w => parseFloat(w) > 0))];

        let productDisplay = uniqueProducts.length > 0 
            ? `<span class="fw-semibold">${uniqueProducts.join(', ')}</span>`
            : `<span class="text-muted small">—</span>`;

        let widthDisplay = uniqueWidths.length > 0 
            ? `<span class="fw-semibold text-dark">${uniqueWidths.join(', ')}</span>`
            : `<span class="text-muted small">—</span>`;

        let actionBtns = `
            <button class="btn btn-sm btn-outline-info me-1" onclick="viewDraftDetails(${d.id})" title="View Details">
                <i class="fa-solid fa-eye"></i> Details
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary me-1 copy-row-btn" onclick="copyRow(this)" title="Copy row to clipboard (Excel-ready)">
                <i class="fa-regular fa-copy"></i>
            </button>
        `;

        if (d.status === 'RESERVED' || d.status === 'DRAFT') {
            actionBtns += `
                <a href="create_draft.php?edit_draft_id=${d.id}" class="btn btn-sm btn-outline-warning me-1" title="Edit Draft">
                    <i class="fa-solid fa-pen"></i> Edit
                </a>
                <button class="btn btn-sm btn-success me-1" onclick="confirmDraftOrder(${d.id}, '${d.draft_no}')" title="Confirm Order">
                    <i class="fa-solid fa-check"></i> Confirm
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="cancelDraftOrder(${d.id}, '${d.draft_no}')" title="Cancel Draft">
                    <i class="fa-solid fa-xmark"></i> Cancel
                </button>
            `;
        } else if (d.status === 'CANCELLED') {
            actionBtns += `
                <button class="btn btn-sm btn-outline-danger" onclick="deleteDraftOrder(${d.id}, '${d.draft_no}')" title="Delete Cancelled Draft">
                    <i class="fa-solid fa-trash"></i> Delete
                </button>
            `;
        }

        html += `
            <tr>
                <td class="copyable-cell" data-copy="${d.customer_name}\t${d.sos_no}" title="Double-click to copy">
                    <span class="fw-bold text-dark">${d.customer_name}</span>
                    <div class="mt-1"><code class="text-danger fw-semibold" style="font-size: 0.9rem;">${d.sos_no}</code></div>
                </td>
                <td class="copyable-cell" data-copy="${uniqueProducts.join(', ')}" title="Double-click to copy">${productDisplay}</td>
                <td class="copyable-cell" data-copy="${uniqueWidths.join(', ')}" title="Double-click to copy">${widthDisplay}</td>
                <td class="fw-bold ${lenColor} copyable-cell" data-copy="${parseFloat(d.selected_length).toFixed(2)}" title="Double-click to copy">${parseFloat(d.selected_length).toFixed(2)}</td>
                <td class="copyable-cell" data-copy="${items.length}" title="Double-click to copy"><span class="badge bg-secondary">${items.length} coils</span></td>
                <td class="copyable-cell" data-copy="${d.status}" title="Double-click to copy"><span class="badge-status ${badgeClass}">${d.status}</span></td>
                <td class="text-muted copyable-cell" data-copy="${d.created_at}" title="Double-click to copy"><small>${d.created_at}</small></td>
                <td class="text-end text-nowrap">${actionBtns}</td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

function viewDraftDetails(draftId) {
    const draft = rawDraftsList.find(d => d.id === draftId);
    if (!draft) return;

    document.getElementById('detailModalTitle').innerText = `Draft Details — ${draft.draft_no}`;
    document.getElementById('modalCustomer').innerText = draft.customer_name;
    document.getElementById('modalSosNo').innerText = draft.sos_no;
    let draftBadgeClass = 'badge-reserved';
    if (draft.status === 'CONFIRMED') draftBadgeClass = 'badge-outgoing';
    if (draft.status === 'CANCELLED') draftBadgeClass = 'badge-cancelled';
    const modalBadge = document.getElementById('modalStatusBadge');
    modalBadge.className = `badge-status ${draftBadgeClass}`;
    modalBadge.innerText = draft.status;

    // Show Edit Draft button inside modal if not confirmed, or Delete if cancelled
    const modalFooter = document.querySelector('#detailModal .modal-footer');
    if (draft.status === 'RESERVED' || draft.status === 'DRAFT') {
        modalFooter.innerHTML = `
            <a href="create_draft.php?edit_draft_id=${draft.id}" class="btn btn-warning fw-semibold">
                <i class="fa-solid fa-pen me-1"></i> Edit Draft Coils
            </a>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        `;
    } else if (draft.status === 'CANCELLED') {
        modalFooter.innerHTML = `
            <button type="button" class="btn btn-danger fw-semibold" onclick="deleteDraftOrder(${draft.id}, '${draft.draft_no}')">
                <i class="fa-solid fa-trash me-1"></i> Delete Draft
            </button>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        `;
    } else {
        modalFooter.innerHTML = `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>`;
    }

    const tbody = document.getElementById('modalItemsTbody');
    let html = '';
    (draft.items || []).forEach(item => {
        let coilBadgeClass = 'badge-available';
        if (item.current_coil_status === 'RESERVED') coilBadgeClass = 'badge-reserved';
        if (item.current_coil_status === 'OUTGOING') coilBadgeClass = 'badge-outgoing';
        if (item.current_coil_status === 'USED')     coilBadgeClass = 'badge-used';
        if (item.current_coil_status === 'CANCELLED') coilBadgeClass = 'badge-cancelled';

        const isOnHold = parseInt(item.is_on_hold) === 1;
        const noteEsc  = (item.hold_note || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');

        let statusDisplay = `<span class="badge-status ${coilBadgeClass}">${item.current_coil_status}</span>`;
        if (isOnHold) {
            statusDisplay += `
                <div class="mt-1">
                    <span class="badge bg-warning text-dark fw-bold" style="font-size:0.7rem;" title="${noteEsc}">
                        <i class="fa-solid fa-hand me-1"></i>ON HOLD
                    </span>
                    ${item.hold_note ? `<small class="text-danger d-block fw-semibold" style="font-size:0.7rem;">${item.hold_note}</small>` : ''}
                </div>
            `;
        }

        // Action button: Copy row + Done button for hold coil only
        let actionBtn = `
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 copy-row-btn me-1" 
                    title="Copy coil row to clipboard (Excel-ready)" 
                    onclick="copyRow(this)">
                <i class="fa-regular fa-copy me-1"></i>Copy
            </button>
        `;
        if (isOnHold) {
            actionBtn += `
                <button type="button" class="btn btn-sm btn-success py-0 px-2 shadow-sm fw-semibold" 
                        title="Sticker changed — mark hold as done" 
                        onclick="releaseDraftCoilHold(${item.draft_coil_id}, ${draft.id})">
                    <i class="fa-solid fa-circle-check me-1"></i>Done
                </button>
            `;
        }

        html += `
            <tr class="${isOnHold ? 'table-warning bg-opacity-50' : ''}">
                <td class="fw-semibold copyable-cell" data-copy="${item.product}" title="Double-click to copy">${item.product}</td>
                <td class="copyable-cell" data-copy="${item.lot_no} ${item.coil_no}" title="Double-click to copy"><span class="fw-bold text-dark">${item.lot_no}</span> <span class="text-dark">${item.coil_no}</span></td>
                <td class="copyable-cell" data-copy="${item.ref_no || '-'}" title="Double-click to copy"><code class="text-danger fw-semibold" style="font-size: 0.9rem;">${item.ref_no || '-'}</code></td>
                <td class="fw-semibold text-primary copyable-cell" data-copy="${parseFloat(item.allocated_length).toFixed(2)}" title="Double-click to copy">${parseFloat(item.allocated_length).toFixed(2)}</td>
                <td class="copyable-cell" data-copy="${parseFloat(item.width || 0).toFixed(2)}" title="Double-click to copy">${parseFloat(item.width || 0).toFixed(2)}</td>
                <td class="copyable-cell" data-copy="${item.roll || '1'}" title="Double-click to copy"><span class="badge bg-secondary">${item.roll || '1'}</span></td>
                <td class="copyable-cell" data-copy="${item.current_coil_status}${isOnHold ? ' (ON HOLD: ' + (item.hold_note || '') + ')' : ''}" title="Double-click to copy">${statusDisplay}</td>
                <td class="text-end text-nowrap">${actionBtn}</td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
    detailModalObj.show();
}

function confirmDraftOrder(draftId, draftNo) {
    if (!confirm(`Are you sure you want to CONFIRM draft ${draftNo}? This will set coil statuses to OUTGOING.`)) return;

    fetch('../backend/api/drafts.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'confirm',
            draft_order_id: draftId
        })
    })
    .then(res => res.json())
    .then(data => {
        showAlert(data.ok ? 'success' : 'danger', data.msg || data.error);
        if (data.ok) loadDrafts();
    });
}

function cancelDraftOrder(draftId, draftNo) {
    const reason = prompt(`Reason for cancelling draft ${draftNo}:`, 'Revoked by officer');
    if (reason === null) return;

    fetch('../backend/api/drafts.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'cancel',
            draft_order_id: draftId,
            reason: reason
        })
    })
    .then(res => res.json())
    .then(data => {
        showAlert(data.ok ? 'success' : 'danger', data.msg || data.error);
        if (data.ok) loadDrafts();
    });
}

function deleteDraftOrder(draftId, draftNo) {
    if (!confirm(`Adakah anda pasti mahu memadam draf ${draftNo}?`)) return;

    fetch('../backend/api/drafts.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'delete',
            draft_order_id: draftId
        })
    })
    .then(res => res.json())
    .then(data => {
        showAlert(data.ok ? 'success' : 'danger', data.msg || data.error);
        if (data.ok) {
            // If the detail modal is open, close it
            if (typeof detailModalObj !== 'undefined' && detailModalObj) {
                detailModalObj.hide();
            }
            loadDrafts();
        }
    })
    .catch(err => {
        showAlert('danger', 'Failed to delete draft: ' + err.message);
    });
}

function releaseDraftCoilHold(coilId, draftId) {
    if (!confirm('Sticker has been changed — mark this hold as done?')) return;

    fetch('../backend/api/coil_holds.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'release', coil_id: coilId })
    })
    .then(res => res.json())
    .then(data => {
        if (data.ok) {
            showAlert('success', 'Coil hold cleared successfully.');
            // Reload drafts to refresh data and update modal
            const search = document.getElementById('filterDraftSearch').value;
            const status = document.getElementById('filterDraftStatus').value;
            const query = new URLSearchParams({ search, status }).toString();

            fetch('../backend/api/drafts.php?' + query)
                .then(res => res.json())
                .then(res => {
                    if (res.ok) {
                        rawDraftsList = res.orders || [];
                        renderDraftsTable(rawDraftsList);
                        // Re-render open modal if still open
                        viewDraftDetails(draftId);
                    }
                });
        } else {
            alert('Error: ' + (data.error || 'Failed to release hold.'));
        }
    })
}

// ── Clipboard Copy Helper Functions ─────────────────────────────────

let copyToastInstance = null;
function showCopyToast(msg) {
    const toastEl = document.getElementById('copyToast');
    const msgEl   = document.getElementById('copyToastMsg');
    if (!toastEl || !msgEl) return;
    msgEl.innerText = msg;
    if (!copyToastInstance) {
        copyToastInstance = new bootstrap.Toast(toastEl, { delay: 2500 });
    }
    copyToastInstance.show();
}

function copyTextToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text).catch(() => fallbackCopyText(text));
    } else {
        return fallbackCopyText(text);
    }
}

function fallbackCopyText(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    try {
        document.execCommand('copy');
    } catch (err) {
        console.error('Fallback copy failed: ', err);
    }
    document.body.removeChild(textarea);
    return Promise.resolve();
}

function setBtnSuccessFeedback(btn, originalHtml) {
    if (!btn) return;
    btn.innerHTML = '<i class="fa-solid fa-check text-success"></i>';
    btn.classList.add('border-success');
    setTimeout(() => {
        btn.innerHTML = originalHtml;
        btn.classList.remove('border-success');
    }, 1500);
}

function copyColumn(tableId, colIndex, colName, btn) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const rows = table.querySelectorAll('tbody tr');
    const values = [];

    rows.forEach(tr => {
        const cells = tr.querySelectorAll('td');
        if (cells.length <= 1) return; // skip empty/loading rows
        const cell = cells[colIndex];
        if (cell) {
            const rawVal = cell.getAttribute('data-copy') || cell.innerText.trim();
            if (rawVal) values.push(rawVal);
        }
    });

    if (values.length === 0) {
        showCopyToast(`No data in column "${colName}" to copy.`);
        return;
    }

    const textToCopy = values.join('\n');
    copyTextToClipboard(textToCopy);

    if (btn) {
        const orig = btn.innerHTML;
        setBtnSuccessFeedback(btn, orig);
    }
    showCopyToast(`Copied ${values.length} rows from column "${colName}"!`);
}

function copyRow(btn) {
    const tr = btn.closest('tr');
    if (!tr) return;
    const cells = tr.querySelectorAll('td');
    const values = [];

    cells.forEach((cell, idx) => {
        // Exclude action button column
        if (idx === cells.length - 1 || cell.classList.contains('text-end')) return;
        const val = cell.getAttribute('data-copy') || cell.innerText.trim();
        values.push(val);
    });

    if (values.length === 0) return;

    const textToCopy = values.join('\t');
    copyTextToClipboard(textToCopy);

    const orig = btn.innerHTML;
    setBtnSuccessFeedback(btn, orig);
    showCopyToast('Row copied to clipboard (ready to paste into Excel)!');
}

function copyTableExcel(tableId, btn) {
    const table = document.getElementById(tableId);
    if (!table) return;

    const headerThs = table.querySelectorAll('thead tr th');
    const headerVals = [];
    headerThs.forEach((th, idx) => {
        if (idx === headerThs.length - 1 || th.classList.contains('text-end')) return;
        const clone = th.cloneNode(true);
        const copyBtns = clone.querySelectorAll('.copy-col-btn, button');
        copyBtns.forEach(b => b.remove());
        headerVals.push(clone.innerText.trim());
    });

    const rows = table.querySelectorAll('tbody tr');
    const rowLines = [];
    let validRowCount = 0;

    rows.forEach(tr => {
        const cells = tr.querySelectorAll('td');
        if (cells.length <= 1) return;
        const cellVals = [];
        cells.forEach((cell, idx) => {
            if (idx === cells.length - 1 || cell.classList.contains('text-end')) return;
            cellVals.push(cell.getAttribute('data-copy') || cell.innerText.trim());
        });
        if (cellVals.length > 0) {
            rowLines.push(cellVals.join('\t'));
            validRowCount++;
        }
    });

    if (validRowCount === 0) {
        showCopyToast('No table rows to copy.');
        return;
    }

    const fullTableText = headerVals.join('\t') + '\n' + rowLines.join('\n');
    copyTextToClipboard(fullTableText);

    if (btn) {
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check text-success me-1"></i> Copied!';
        setTimeout(() => { btn.innerHTML = orig; }, 1500);
    }
    showCopyToast(`Entire table (${validRowCount} rows) copied for Excel!`);
}

// Quick cell double-click to copy individual values
document.addEventListener('dblclick', function(e) {
    const td = e.target.closest('td.copyable-cell');
    if (!td || td.classList.contains('text-end')) return;
    const val = td.getAttribute('data-copy') || td.innerText.trim();
    if (!val) return;
    copyTextToClipboard(val);
    showCopyToast(`Copied: "${val.replace(/\t/g, ' - ')}"`);
});
</script>

<?php include 'includes/footer.php'; ?>

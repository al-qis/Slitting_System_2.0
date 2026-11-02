<?php
/**
 * Interactive Two-Panel Draft Creation View
 * Location: /stock_draft_system/frontend/create_draft.php
 */
include 'includes/header.php';
?>

<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h3 class="fw-bold mb-1"><i class="fa-solid fa-plus-circle text-primary me-2"></i>Create Customer Order Draft</h3>
            <p class="text-muted mb-0">Select available coils, calculate live totals, and simulate customer allocations safely.</p>
        </div>
        <div>
            <a href="drafts_management.php" class="btn btn-outline-secondary">
                <i class="fa-solid fa-list-check me-1"></i> View All Drafts
            </a>
        </div>
    </div>
</div>

<!-- Main 2-Panel Row -->
<div class="row g-4">

    <!-- LEFT PANEL: Available Coils Selection -->
    <div class="col-lg-6">
        <div class="card panel-card">
            <div class="panel-header d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0 text-success">
                    <i class="fa-solid fa-boxes-stacked me-2"></i>1. Select Available Coils
                </h5>
                <span class="badge bg-success" id="leftAvailableBadge">0 Available</span>
            </div>
            
            <div class="p-3 border-bottom bg-light">
                <div class="row g-2">
                    <div class="col-md-7">
                        <input type="text" id="leftSearch" class="form-control form-control-sm" placeholder="Search Product, Lot No, Coil No...">
                    </div>
                    <div class="col-md-5">
                        <input type="text" id="leftFilterGrade" class="form-control form-control-sm" placeholder="Filter Grade...">
                    </div>
                </div>
            </div>

            <!-- Requirement Lock Notice -->
            <div id="reqLockBanner" class="alert alert-warning border-warning m-3 py-2 px-3 small d-flex align-items-center mb-0" style="display: none;">
                <i class="fa-solid fa-lock text-warning fs-5 me-2"></i>
                <div>
                    <strong>Pemilihan Coil Dikunci:</strong> Sila lengkapkan <strong>Customer Name, SOS No, Product, dan Width</strong> pada Draft Requirement terlebih dahulu sebelum memilih coil.
                </div>
            </div>

            <div class="card-body p-0 coil-scroll-area">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th style="width: 45px;">Select</th>
                                <th>Product Type</th>
                                <th>Lot No + Coil No</th>
                                <th>Width (mm)</th>
                                <th>Length (m)</th>
                                <th>Roll No</th>
                                <th>Customer</th>
                                <th>Draft Customer / SO</th>
                            </tr>
                        </thead>
                        <tbody id="leftAvailableTbody">
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="fa-solid fa-spinner fa-spin me-2"></i>Loading available coils...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- RIGHT PANEL: Draft Workspace & Live Calculation -->
    <div class="col-lg-6">
        <div class="card panel-card">
            <div class="panel-header bg-primary bg-opacity-10 text-primary">
                <h5 class="fw-bold mb-0">
                    <i class="fa-solid fa-calculator me-2"></i>2. Draft Requirements & Live Totals
                </h5>
            </div>

            <div class="card-body p-4">
                <form id="draftForm">
                    <!-- Customer & SOS Input -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Customer Name <span class="text-danger">*</span></label>
                            <select id="reqCustomerName" class="form-select" required>
                                <option value="">-- Select Customer --</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">SOS Number / Ref <span class="text-danger">*</span></label>
                            <input type="text" id="reqSosNo" class="form-control text-uppercase" required>
                            <div id="sosFormatHelp" class="form-text text-muted" style="font-size: 0.78rem;">
                                Format: <code>SO-YY-XXXX</code>, <code>MS-XXXXXXX</code>, or <code>MS-XXXXXXX J</code>
                            </div>
                        </div>
                    </div>

                    <!-- Target Requirements Input -->
                    <div class="row g-3 mb-4 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label fw-bold mb-1">
                                Product <span class="text-danger">*</span>
                            </label>
                            <select id="reqProduct" class="form-select" required>
                                <option value="">-- Select Product --</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold mb-1">
                                Required Width (mm) <span class="text-danger">*</span>
                            </label>
                            <input type="number" step="0.01" id="reqWidth" class="form-control" placeholder="e.g. 119.00" required min="0.01">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold mb-1">
                                Required Length (m) <span class="text-danger">*</span>
                            </label>
                            <input type="number" step="0.01" id="reqLength" class="form-control" placeholder="0.00" value="0.00" required>
                        </div>
                    </div>

                    <!-- Live Calculation & Status Badge Banner -->
                    <div id="statusBanner" class="status-badge-container status-not-enough mb-4">
                        <i id="statusIcon" class="fa-solid fa-circle-xmark fs-4"></i>
                        <div>
                            <div id="statusTitle" class="fs-6 fw-bold">🔴 NOT ENOUGH STOCK</div>
                            <small id="statusSubtitle" class="fw-normal">Required: 0.00 m | Selected: 0.00 m (Shortage: 0.00 m)</small>
                        </div>
                    </div>

                    <!-- Selected Coils Table -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold mb-0">Selected Coils (<span id="selCoilCount">0</span>)</h6>
                            <button type="button" id="btnClearSelected" class="btn btn-sm btn-link text-danger text-decoration-none">
                                <i class="fa-solid fa-trash me-1"></i>Clear All
                            </button>
                        </div>

                        <div class="border rounded-3 p-2 bg-light" style="max-height: 220px; overflow-y: auto;">
                            <table class="table table-sm table-borderless mb-0 align-middle">
                                <thead>
                                    <tr class="text-muted border-bottom" style="font-size: 0.8rem;">
                                        <th>Product Type</th>
                                        <th>Lot No + Coil No</th>
                                        <th>Width (mm)</th>
                                        <th>Length (m)</th>
                                        <th>Roll No</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="rightSelectedTbody">
                                    <tr>
                                        <td colspan="6" class="text-center py-3 text-muted">No coils selected yet. Pick coils from the left panel.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Hold Coils Table (Only visible when there are coils on hold) -->
                    <div id="holdCoilsContainer" class="mb-4" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold text-warning mb-0">
                                <i class="fa-solid fa-hand me-1"></i>Hold Coils (<span id="holdCoilCount">0</span>)
                            </h6>
                            <small class="text-muted">Awaiting sticker change</small>
                        </div>

                        <div class="border border-warning border-opacity-50 rounded-3 p-2 bg-warning bg-opacity-10" style="max-height: 220px; overflow-y: auto;">
                            <table class="table table-sm table-borderless mb-0 align-middle">
                                <thead>
                                    <tr class="text-dark border-bottom border-warning border-opacity-25" style="font-size: 0.8rem;">
                                        <th>Product Type</th>
                                        <th>Lot No + Coil No</th>
                                        <th>Roll No</th>
                                        <th>Width (mm)</th>
                                        <th>Length (m)</th>
                                        <th>Customer</th>
                                        <th>Note</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="rightHoldTbody">
                                    <!-- Populated dynamically -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Summary Totals Row -->
                    <div class="p-3 bg-light rounded-3 border mb-4">
                        <div class="row text-center">
                            <div class="col-4">
                                <small class="text-muted d-block fw-semibold">Total Selected Length</small>
                                <h5 id="sumSelLength" class="fw-bold mb-0 text-primary">0.00 m</h5>
                            </div>
                            <div class="col-4">
                                <small class="text-muted d-block fw-semibold">Total Selected Weight</small>
                                <h5 id="sumSelWeight" class="fw-bold mb-0 text-warning">0.00 kg</h5>
                            </div>
                            <div class="col-4">
                                <small class="text-muted d-block fw-semibold">Total Selected Area</small>
                                <h5 id="sumSelArea" class="fw-bold mb-0 text-secondary">0.00 m²</h5>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Draft Button -->
                    <div class="d-grid">
                        <button type="submit" id="btnSubmitDraft" class="btn btn-primary btn-lg fw-bold shadow-sm" disabled>
                            <i class="fa-solid fa-check-circle me-2"></i>Save & Reserve Draft Order
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit Selected Coil Modal -->
<div class="modal fade" id="editSelectedCoilModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square text-primary me-2"></i>Edit Selected Coil Dimensions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editCoilId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Lot & Coil Number</label>
                    <input type="text" id="editCoilLabel" class="form-control" readonly>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Width (mm)</label>
                        <input type="number" step="0.01" id="editCoilWidth" class="form-control">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Length (m)</label>
                        <input type="number" step="0.01" id="editCoilLength" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="btnSaveCoilEdit" class="btn btn-primary fw-semibold">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Customer Mismatch Warning Modal -->
<div class="modal fade" id="customerMismatchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-warning shadow">
            <div class="modal-header bg-warning bg-opacity-10 border-warning">
                <h5 class="modal-title fw-bold text-dark">
                    <i class="fa-solid fa-triangle-exclamation text-warning me-2 fs-4"></i>Customer Mismatch Warning
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <p class="mb-3">
                    The selected coil belongs to a different customer than specified in the draft requirement:
                </p>
                <div class="p-3 bg-light rounded border mb-3">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Selected Coil:</span>
                        <strong id="warnCoilLabel">-</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Coil Customer:</span>
                        <span id="warnCoilCustomer" class="badge bg-info text-dark fw-bold">-</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Draft Customer:</span>
                        <span id="warnDraftCustomer" class="badge bg-primary fw-bold">-</span>
                    </div>
                </div>
                <p class="text-warning-emphasis fw-semibold mb-0 small">
                    <i class="fa-solid fa-hand me-1"></i> Place this coil on hold so the officer can change the sticker before allocation?
                </p>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="btnConfirmMismatch" class="btn btn-warning fw-bold text-dark">
                    <i class="fa-solid fa-hand me-1"></i> Hold Coil
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Customer Mismatch Submit Warning Modal -->
<div class="modal fade" id="submitMismatchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-warning shadow">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold">
                    <i class="fa-solid fa-triangle-exclamation me-2 fs-5"></i>Customer Mismatch Detected!
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <p class="mb-3">
                    The draft order customer is set to <strong id="subWarnDraftCustomer" class="text-primary">-</strong>, but the following selected coil(s) belong to a different customer:
                </p>
                <div class="table-responsive border rounded mb-3" style="max-height: 180px; overflow-y: auto;">
                    <table class="table table-sm table-striped mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Coil / Lot No</th>
                                <th>Product</th>
                                <th>Coil Customer</th>
                            </tr>
                        </thead>
                        <tbody id="subWarnTableBody">
                            <!-- Populated dynamically -->
                        </tbody>
                    </table>
                </div>
                <div class="alert alert-warning py-2 mb-0 small">
                    <i class="fa-solid fa-circle-exclamation me-1"></i> Are you sure you want to proceed and save this reservation?
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel & Review</button>
                <button type="button" id="btnConfirmSubmitMismatch" class="btn btn-warning fw-bold text-dark">
                    <i class="fa-solid fa-check me-1"></i> Confirm & Save Anyway
                </button>
            </div>
        </div>
    </div>
</div>

<!-- On Hold Modal -->
<div class="modal fade" id="holdCoilModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-warning shadow">
            <div class="modal-header bg-warning bg-opacity-25 border-warning">
                <h5 class="modal-title fw-bold text-dark">
                    <i class="fa-solid fa-hand text-warning me-2 fs-5"></i>Place Coil On Hold
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <input type="hidden" id="holdCoilId">
                <div class="p-3 bg-light rounded border mb-3">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Coil:</span>
                        <strong id="holdCoilLabel">-</strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Current Customer:</span>
                        <span id="holdCoilCustomer" class="badge bg-info text-dark fw-bold">-</span>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Note <span class="text-danger">*</span></label>
                    <input type="text" id="holdNote" class="form-control" 
                           placeholder='e.g. "Change sticker from NAE to NTC"' required>
                    <div class="form-text">Describe what sticker change is needed before this coil can be used.</div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="btnConfirmHold" class="btn btn-warning fw-bold text-dark">
                    <i class="fa-solid fa-hand me-1"></i> Place On Hold
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let allAvailableCoils = [];
let selectedCoilsMap = new Map();
let editingDraftId = null;
let editingDraftNo = null;

let customerMismatchModalObj;
let submitMismatchModalObj;
let holdModalObj;
let pendingMismatchCoil = null;

document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    editingDraftId = urlParams.get('edit_draft_id');

    customerMismatchModalObj = new bootstrap.Modal(document.getElementById('customerMismatchModal'));
    submitMismatchModalObj   = new bootstrap.Modal(document.getElementById('submitMismatchModal'));
    holdModalObj             = new bootstrap.Modal(document.getElementById('holdCoilModal'));

    loadAvailableCoils();

    // Hold modal: confirm button
    document.getElementById('btnConfirmHold').addEventListener('click', confirmPlaceOnHold);

    document.getElementById('leftSearch').addEventListener('input', filterLeftCoils);
    document.getElementById('leftFilterGrade').addEventListener('input', filterLeftCoils);

    // Requirements suggestion & calculation triggers
    document.getElementById('reqCustomerName').addEventListener('change', function() {
        handleCustomerChange();
        filterLeftCoils();
    });
    document.getElementById('reqSosNo').addEventListener('input', function() {
        this.value = this.value.toUpperCase();
        filterLeftCoils();
    });
    document.getElementById('reqSosNo').addEventListener('focus', function() {
        if (this.value === 'SO-' || this.value === 'MS-') {
            const len = this.value.length;
            this.setSelectionRange(len, len);
        }
    });
    document.getElementById('reqProduct').addEventListener('change', filterLeftCoils);
    document.getElementById('reqWidth').addEventListener('input', filterLeftCoils);
    document.getElementById('reqLength').addEventListener('input', calculateLiveTotals);

    document.getElementById('btnClearSelected').addEventListener('click', function() {
        selectedCoilsMap.clear();
        filterLeftCoils();
        renderRightSelectedCoils();
        calculateLiveTotals();
    });

    document.getElementById('draftForm').addEventListener('submit', handleDraftSubmit);
    document.getElementById('btnSaveCoilEdit').addEventListener('click', saveEditedCoilDimensions);

    document.getElementById('btnConfirmMismatch').addEventListener('click', function() {
        if (!pendingMismatchCoil) return;

        const coilToHold    = pendingMismatchCoil;
        const draftCustomer = document.getElementById('reqCustomerName').value.trim();
        const draftSosNo    = (document.getElementById('reqSosNo')?.value || '').trim();
        const coilCustomer  = (coilToHold.customer_name || 'STOCK').trim();
        const noteMsg       = `Change sticker from ${coilCustomer} to ${draftCustomer}`;

        const btn = document.getElementById('btnConfirmMismatch');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Holding...';

        fetch('../backend/api/coil_holds.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'hold', coil_id: coilToHold.id, note: noteMsg, draft_customer: draftCustomer, draft_sos: draftSosNo })
        })
        .then(res => res.json())
        .then(data => {
            if (data.ok) {
                // Update local coil state in available pool
                const targetCoil = allAvailableCoils.find(c => c.id === coilToHold.id);
                if (targetCoil) {
                    targetCoil.is_on_hold = 1;
                    targetCoil.hold_note  = noteMsg;
                    targetCoil.draft_customer = draftCustomer;
                    targetCoil.draft_sos_no   = draftSosNo;
                    targetCoil.hold_draft_customer = draftCustomer;
                    targetCoil.hold_draft_sos      = draftSosNo;
                }

                // Also include coil in selectedCoilsMap for this draft order
                coilToHold.is_on_hold = 1;
                coilToHold.hold_note  = noteMsg;
                coilToHold.draft_customer = draftCustomer;
                coilToHold.draft_sos_no   = draftSosNo;
                coilToHold.hold_draft_customer = draftCustomer;
                coilToHold.hold_draft_sos      = draftSosNo;
                selectedCoilsMap.set(coilToHold.id, coilToHold);

                pendingMismatchCoil = null;
                filterLeftCoils();
                renderRightSelectedCoils();
                calculateLiveTotals();
                if (customerMismatchModalObj) {
                    customerMismatchModalObj.hide();
                }
            } else {
                alert('Error: ' + (data.error || 'Failed to place coil on hold.'));
            }
        })
        .catch(err => alert('Request failed: ' + err))
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-hand me-1"></i> Hold Coil';
        });
    });

    document.getElementById('customerMismatchModal').addEventListener('hidden.bs.modal', function() {
        if (pendingMismatchCoil) {
            pendingMismatchCoil = null;
            filterLeftCoils();
        }
    });

    document.getElementById('btnConfirmSubmitMismatch').addEventListener('click', function() {
        if (submitMismatchModalObj) {
            submitMismatchModalObj.hide();
        }
        executeDraftSubmit();
    });
});

function loadAvailableCoils() {
    fetch('../backend/api/stock_coils.php?status=AVAILABLE,CANCELLED,RESERVED&state=STOCK&limit=1000')
        .then(res => res.json())
        .then(res => {
            if (!res.ok) return;
            // Include status === 'AVAILABLE', 'CANCELLED', or 'RESERVED', provided state is 'STOCK'
            const rawCoils = res.coils || [];
            allAvailableCoils = rawCoils.filter(c => 
                (c.status === 'AVAILABLE' || c.status === 'CANCELLED' || c.status === 'RESERVED') && 
                (!c.state || c.state.toUpperCase() === 'STOCK')
            );


            // Populate Customer Name Dropdown Options
            const custSelect = document.getElementById('reqCustomerName');
            if (res.customers && res.customers.length > 0) {
                custSelect.innerHTML = '<option value="">-- Select Customer --</option>';
                res.customers.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c;
                    opt.textContent = c;
                    custSelect.appendChild(opt);
                });
            }

            // Populate Product Dropdown Options
            const prodSelect = document.getElementById('reqProduct');
            if (res.products && res.products.length > 0) {
                const curProd = prodSelect.value;
                prodSelect.innerHTML = '<option value="">-- Select Product --</option>';
                res.products.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p;
                    opt.textContent = p;
                    if (p === curProd) opt.selected = true;
                    prodSelect.appendChild(opt);
                });
            }

            // If editing, load draft details next
            if (editingDraftId) {
                loadExistingDraftDetails(editingDraftId);
            } else {
                filterLeftCoils();
                renderRightHoldCoils();
            }
        });
}

function loadExistingDraftDetails(draftId) {
    fetch(`../backend/api/drafts.php?id=${draftId}`)
        .then(res => res.json())
        .then(res => {
            if (!res.ok || !res.order) {
                alert('Draft order not found.');
                return;
            }
            const order = res.order;
            editingDraftNo = order.draft_no || null;

            // Update UI page headers
            document.querySelector('h3.fw-bold').innerHTML = `<i class="fa-solid fa-pen-to-square text-warning me-2"></i>Edit Draft Order — ${order.draft_no}`;
            document.getElementById('btnSubmitDraft').innerHTML = `<i class="fa-solid fa-floppy-disk me-2"></i>Update Draft Order`;

            // Populate header fields
            const custSelect = document.getElementById('reqCustomerName');
            let foundCust = false;
            for (let i = 0; i < custSelect.options.length; i++) {
                if (custSelect.options[i].value === order.customer_name) {
                    custSelect.selectedIndex = i;
                    foundCust = true;
                    break;
                }
            }
            if (!foundCust && order.customer_name) {
                const opt = document.createElement('option');
                opt.value = order.customer_name;
                opt.textContent = order.customer_name;
                opt.selected = true;
                custSelect.appendChild(opt);
            }

            document.getElementById('reqSosNo').value = order.sos_no || '';
            handleCustomerChange(true);
            document.getElementById('reqLength').value = parseFloat(order.required_length || 0).toFixed(2);

            // Populate selected coils map from allocated items
            selectedCoilsMap.clear();
            let firstItemProduct = '';
            let firstItemWidth = 0;
            (order.items || []).forEach((item, idx) => {
                if (idx === 0) {
                    firstItemProduct = item.product || '';
                    firstItemWidth = parseFloat(item.width || 0);
                }
                const coilObj = {
                    id: item.draft_coil_id,
                    coil_no: item.coil_no,
                    lot_no: item.lot_no,
                    product: item.product,
                    grade: item.grade,
                    width: parseFloat(item.width),
                    length: parseFloat(item.allocated_length || item.length),
                    weight: parseFloat(item.allocated_weight || item.weight),
                    area: parseFloat(item.allocated_area || 0),
                    roll: item.roll || '1',
                    ref_no: item.ref_no || '-',
                    customer_name: item.customer_name || 'STOCK',
                    is_on_hold: parseInt(item.is_on_hold) || 0,
                    hold_note: item.hold_note || '',
                    hold_set_by: item.hold_set_by || '',
                    hold_set_at: item.hold_set_at || '',
                    status: item.current_coil_status || 'RESERVED',
                    draft_order_id: draftId,
                    reserved_draft_no: order.draft_no,
                    draft_customer: order.customer_name || '',
                    draft_sos_no: order.sos_no || ''
                };
                selectedCoilsMap.set(item.draft_coil_id, coilObj);

                // Add to available pool if not present, or update existing coil properties
                const existingIdx = allAvailableCoils.findIndex(c => c.id === item.draft_coil_id);
                if (existingIdx !== -1) {
                    allAvailableCoils[existingIdx] = Object.assign({}, allAvailableCoils[existingIdx], coilObj);
                } else {
                    allAvailableCoils.unshift(coilObj);
                }
            });

            if (firstItemProduct && !document.getElementById('reqProduct').value) {
                document.getElementById('reqProduct').value = firstItemProduct;
            }
            if (firstItemWidth > 0 && !document.getElementById('reqWidth').value) {
                document.getElementById('reqWidth').value = firstItemWidth.toFixed(2);
            }

            document.getElementById('leftAvailableBadge').innerText = allAvailableCoils.length + ' Available';
            filterLeftCoils();
            renderRightSelectedCoils();
            renderRightHoldCoils();
            calculateLiveTotals();
        });
}

function handleCustomerChange(preserveExisting = false) {
    const cust = (document.getElementById('reqCustomerName').value || '').trim();
    const custUpper = cust.toUpperCase();
    const sosInput = document.getElementById('reqSosNo');
    const helpEl = document.getElementById('sosFormatHelp');
    if (!sosInput) return;

    const currentSos = sosInput.value.trim();

    if (!preserveExisting) {
        if (custUpper === 'STAMPING') {
            if (!currentSos || currentSos === 'SO-' || currentSos.startsWith('SO-')) {
                sosInput.value = currentSos.startsWith('SO-') ? currentSos.replace(/^SO-/i, 'MS-') : 'MS-';
            }
        } else if (cust) {
            // Any customer other than STAMPING
            if (!currentSos || currentSos === 'MS-' || currentSos.startsWith('MS-')) {
                sosInput.value = currentSos.startsWith('MS-') ? currentSos.replace(/^MS-/i, 'SO-') : 'SO-';
            }
        }
    }

    if (helpEl) {
        if (custUpper === 'STAMPING') {
            helpEl.innerHTML = 'Customer: <strong>STAMPING</strong> — Format: <code>MS-XXXXXXX</code> or <code>MS-XXXXXXX J</code>';
        } else if (cust) {
            helpEl.innerHTML = `Customer: <strong>${cust}</strong> — Format: <code>SO-YY-XXXX</code>`;
        } else {
            helpEl.innerHTML = 'Format: <code>SO-YY-XXXX</code>, <code>MS-XXXXXXX</code>, or <code>MS-XXXXXXX J</code>';
        }
    }
}

function checkRequirementsComplete() {
    const cust = (document.getElementById('reqCustomerName')?.value || '').trim();
    const sos  = (document.getElementById('reqSosNo')?.value || '').trim();
    const prod = (document.getElementById('reqProduct')?.value || '').trim();
    const wVal = parseFloat(document.getElementById('reqWidth')?.value);

    // SOS cannot be empty or just the prefix 'SO-' / 'MS-'
    const hasValidSos = Boolean(sos && sos !== 'SO-' && sos !== 'MS-');

    return Boolean(cust && hasValidSos && prod && !isNaN(wVal) && wVal > 0);
}

function filterLeftCoils() {
    const isReqComplete = checkRequirementsComplete();
    const lockBanner = document.getElementById('reqLockBanner');
    if (lockBanner) {
        lockBanner.style.display = isReqComplete ? 'none' : 'flex';
    }

    const search     = document.getElementById('leftSearch').value.toLowerCase().trim();
    const grade      = document.getElementById('leftFilterGrade').value.toLowerCase().trim();
    const reqProduct = document.getElementById('reqProduct').value.toLowerCase().trim();
    const reqWidthVal = parseFloat(document.getElementById('reqWidth').value);

    const filtered = allAvailableCoils.filter(c => {
        // Always include currently selected coils so user doesn't lose checked state
        const isChecked = selectedCoilsMap.has(c.id);

        const matchesSearch = !search ||
                              c.coil_no.toLowerCase().includes(search) ||
                              c.lot_no.toLowerCase().includes(search) ||
                              c.product.toLowerCase().includes(search) ||
                              (c.ref_no && c.ref_no.toLowerCase().includes(search));

        const matchesGrade = !grade || c.grade.toLowerCase().includes(grade);

        // Product suggestion filter from form requirement
        const matchesProduct = !reqProduct || c.product.toLowerCase() === reqProduct;

        // Width suggestion filter from form requirement (match width within 0.05mm tolerance)
        const matchesWidth = isNaN(reqWidthVal) || reqWidthVal <= 0 || Math.abs(parseFloat(c.width) - reqWidthVal) <= 0.05;

        return isChecked || (matchesSearch && matchesGrade && matchesProduct && matchesWidth);
    });

    const badge = document.getElementById('leftAvailableBadge');
    if (!isReqComplete) {
        badge.className = 'badge bg-secondary';
        badge.innerText = 'Locked (Fill Requirement)';
    } else if (reqProduct || (!isNaN(reqWidthVal) && reqWidthVal > 0)) {
        badge.className = 'badge bg-primary';
        badge.innerText = `${filtered.length} Suggested`;
    } else {
        badge.className = 'badge bg-success';
        badge.innerText = `${allAvailableCoils.length} Available`;
    }

    renderLeftCoils(filtered);
}

function renderLeftCoils(coilsToRender = allAvailableCoils) {
    const tbody = document.getElementById('leftAvailableTbody');
    if (coilsToRender.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">No available coils match filters.</td></tr>';
        return;
    }

    const draftCust = document.getElementById('reqCustomerName').value.trim();
    const draftSos  = (document.getElementById('reqSosNo')?.value || '').trim();

    let html = '';
    coilsToRender.forEach(c => {
        const isChecked = selectedCoilsMap.has(c.id);
        const coilCust  = (c.customer_name || 'STOCK').trim();
        const isOnHold  = parseInt(c.is_on_hold) === 1;
        const isReserved = (c.status || '').toUpperCase() === 'RESERVED';
        const isReservedByOther = isReserved && !isChecked && (!editingDraftNo || c.reserved_draft_no !== editingDraftNo);

        let draftCustCell = `<span class="text-muted small">—</span>`;
        let isMiss = false;

        if (isOnHold) {
            // For on-hold coil, show the draft customer and SO
            const targetCust = c.draft_customer || c.hold_draft_customer || (c.hold_note && c.hold_note.match(/to\s+([A-Za-z0-9_-]+)/i) ? c.hold_note.match(/to\s+([A-Za-z0-9_-]+)/i)[1] : '') || (isChecked ? draftCust : '');
            const targetSos  = c.draft_sos_no || c.hold_draft_sos || (targetCust && draftCust && targetCust.toUpperCase() === draftCust.toUpperCase() ? draftSos : '');

            if (targetCust) {
                draftCustCell = `
                    <span class="badge bg-warning text-dark fw-bold">${targetCust}</span>
                    ${targetSos ? `<div class="mt-1"><code class="text-dark fw-semibold" style="font-size:0.85rem;"><i class="fa-solid fa-file-invoice me-1 text-muted"></i>${targetSos}</code></div>` : ''}
                `;
            }
        } else if (isReserved) {
            // For reserved coil, show the draft customer and SO assigned
            const resCust  = c.draft_customer || 'RESERVED';
            const resSos   = c.draft_sos_no || '';
            const resDraft = c.reserved_draft_no || '';

            draftCustCell = `
                <span class="badge bg-secondary text-white fw-bold" title="${resDraft ? 'Draft: ' + resDraft : 'Reserved'}">${resCust}</span>
                ${resSos ? `<div class="mt-1"><code class="text-dark fw-semibold" style="font-size:0.85rem;"><i class="fa-solid fa-file-invoice me-1 text-muted"></i>${resSos}</code></div>` : ''}
            `;
        } else if (isChecked) {
            const isMatch = draftCust && coilCust.toUpperCase() === draftCust.toUpperCase();
            if (!draftCust) {
                draftCustCell = `<span class="text-muted small">—</span>`;
            } else if (isMatch) {
                draftCustCell = `<span class="badge bg-success fw-bold">${draftCust}</span>
                                <br><small class="text-success" style="font-size:0.7rem;"><i class="fa-solid fa-circle-check me-1"></i>Match</small>`;
            } else {
                isMiss = true;
                draftCustCell = `<span class="badge bg-warning text-dark fw-bold">${draftCust}</span>
                                <br><small class="text-danger" style="font-size:0.7rem;"><i class="fa-solid fa-circle-xmark me-1"></i>Mismatch</small>`;
            }
        }

        // Row class: on-hold gets amber, checked gets blue, reserved by other gets muted grey, mismatch gets warning
        let rowClass = '';
        if (isOnHold) {
            rowClass = 'table-warning';
        } else if (isChecked) {
            rowClass = isMiss ? 'table-warning' : 'table-primary';
        } else if (isReservedByOther) {
            rowClass = 'table-secondary bg-opacity-50 text-muted';
        }

        // Customer cell: show ON HOLD badge if applicable
        let customerCellExtra = '';
        if (isOnHold) {
            const noteEsc = (c.hold_note || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            customerCellExtra = `<br><span class="badge bg-warning text-dark fw-bold" style="font-size:0.65rem;" title="${noteEsc}">
                <i class="fa-solid fa-hand me-1"></i>ON HOLD
            </span>`;
        }

        const isReqComplete = checkRequirementsComplete();

        // Determine if checkbox should be disabled
        let checkboxDisabled = '';
        if (!isReqComplete && !isChecked) {
            checkboxDisabled = 'disabled title="Sila lengkapkan Customer, SOS No, Product, dan Width terlebih dahulu"';
        } else if (isReservedByOther) {
            const resDraftMsg = c.reserved_draft_no ? `Already reserved in ${c.reserved_draft_no}` : 'Already reserved in another draft';
            checkboxDisabled = `disabled title="${resDraftMsg}"`;
        } else if (isOnHold && !isChecked) {
            checkboxDisabled = 'disabled title="On hold — sticker change needed"';
        }

        const isRowDisabled = (!isReqComplete && !isChecked) || isReservedByOther || (isOnHold && !isChecked);

        html += `
            <tr class="${rowClass}" style="${isRowDisabled ? 'opacity:0.75; cursor:not-allowed;' : ''}">
                <td>
                    <input type="checkbox" class="form-check-input coil-checkbox" 
                           data-id="${c.id}" ${isChecked ? 'checked' : ''} 
                           ${checkboxDisabled}
                           ${isRowDisabled ? 'style="cursor:not-allowed;"' : ''}
                           onchange="toggleCoilSelection(${c.id})">
                </td>
                <td class="fw-semibold">${c.product}</td>
                <td><span class="fw-bold text-dark">${c.lot_no}</span> <span class="fw-bold text-dark">${c.coil_no}</span></td>
                <td>${parseFloat(c.width).toFixed(2)}</td>
                <td class="fw-semibold text-primary">${parseFloat(c.length).toFixed(2)}</td>
                <td><span class="badge bg-secondary">${c.roll || '1'}</span></td>
                <td>
                    <span class="badge bg-info text-dark fw-bold">${coilCust}</span>
                    <div class="mt-1"><code class="text-danger fw-semibold" style="font-size: 0.9rem;">${c.ref_no || '-'}</code></div>
                    ${customerCellExtra}
                </td>
                <td>${draftCustCell}</td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

function toggleCoilSelection(id) {
    if (!checkRequirementsComplete()) {
        alert('Sila lengkapkan maklumat Draft Requirement (Customer Name, SOS No, Product, dan Width) terlebih dahulu sebelum memilih coil.');
        return;
    }
    const coil = allAvailableCoils.find(c => c.id === id);
    if (!coil) return;

    if (selectedCoilsMap.has(id)) {
        selectedCoilsMap.delete(id);
        filterLeftCoils();
        renderRightSelectedCoils();
        calculateLiveTotals();
    } else {
        // Guard: Prevent selecting a coil already reserved in another draft
        if (coil.status === 'RESERVED') {
            const isSelfDraft = (editingDraftId && parseInt(coil.draft_order_id) === parseInt(editingDraftId)) ||
                                (editingDraftNo && coil.reserved_draft_no === editingDraftNo);
            if (!isSelfDraft) {
                const draftInfo = coil.reserved_draft_no ? ` (${coil.reserved_draft_no})` : '';
                alert(`This coil is already RESERVED in another draft order${draftInfo}.`);
                filterLeftCoils();
                return;
            }
        }
        const draftCustomer = document.getElementById('reqCustomerName').value.trim();
        const coilCustomer  = (coil.customer_name || 'STOCK').trim();

        // Check customer mismatch if a draft customer is selected
        if (draftCustomer && coilCustomer && draftCustomer.toUpperCase() !== coilCustomer.toUpperCase()) {
            pendingMismatchCoil = coil;
            document.getElementById('warnCoilLabel').innerText    = `${coil.lot_no} ${coil.coil_no} (${coil.product})`;
            document.getElementById('warnCoilCustomer').innerText = coilCustomer;
            document.getElementById('warnDraftCustomer').innerText= draftCustomer;
            
            if (customerMismatchModalObj) {
                customerMismatchModalObj.show();
            }
            return;
        }

        selectedCoilsMap.set(id, coil);
        filterLeftCoils();
        renderRightSelectedCoils();
        calculateLiveTotals();
    }
}

function renderRightSelectedCoils() {
    const tbody = document.getElementById('rightSelectedTbody');
    const allSelected = Array.from(selectedCoilsMap.values());
    const activeSelectedList = allSelected.filter(c => parseInt(c.is_on_hold) !== 1);
    const holdList = allSelected.filter(c => parseInt(c.is_on_hold) === 1);
    document.getElementById('selCoilCount').innerText = activeSelectedList.length;

    if (activeSelectedList.length === 0) {
        if (holdList.length > 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center py-3 text-muted fst-italic">All selected coil(s) are on hold awaiting sticker change below.</td></tr>';
        } else {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center py-3 text-muted">No coils selected yet. Pick coils from the left panel.</td></tr>';
        }
    } else {
        let html = '';
        activeSelectedList.forEach(c => {
            html += `
                <tr>
                    <td class="fw-semibold">${c.product}</td>
                    <td><span class="fw-bold text-dark">${c.lot_no}</span> <span class="fw-bold text-dark">${c.coil_no}</span></td>
                    <td>${parseFloat(c.width).toFixed(2)}</td>
                    <td class="fw-semibold text-primary">${parseFloat(c.length).toFixed(2)}</td>
                    <td><span class="badge bg-secondary">${c.roll || '1'}</span></td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-dark py-0 px-2 me-1" title="Edit Dimensions" onclick="openEditCoilModal(${c.id})">
                            <i class="fa-solid fa-pen-to-square"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" title="Remove Coil" onclick="toggleCoilSelection(${c.id})">
                            &times;
                        </button>
                    </td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
    }

    renderRightHoldCoils();
}

function renderRightHoldCoils() {
    const container = document.getElementById('holdCoilsContainer');
    const tbody     = document.getElementById('rightHoldTbody');
    const badge     = document.getElementById('holdCoilCount');

    if (!container || !tbody) return;

    // Filter hold coils that are selected in this draft order
    const selectedList = Array.from(selectedCoilsMap.values());
    const holdList = selectedList.filter(c => parseInt(c.is_on_hold) === 1);
    if (badge) badge.innerText = holdList.length;

    if (holdList.length === 0) {
        container.style.display = 'none';
        tbody.innerHTML = '';
        return;
    }

    container.style.display = 'block';

    let html = '';
    holdList.forEach(c => {
        const noteEsc = (c.hold_note || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        const coilCust = (c.customer_name || 'STOCK').trim();
        html += `
            <tr class="border-bottom border-warning border-opacity-25" style="font-size: 0.85rem;">
                <td class="fw-semibold">${c.product}</td>
                <td><span class="fw-bold text-dark">${c.lot_no}</span> <span class="text-dark">${c.coil_no}</span></td>
                <td><span class="badge bg-secondary">${c.roll || '1'}</span></td>
                <td>${parseFloat(c.width).toFixed(2)}</td>
                <td class="fw-semibold text-primary">${parseFloat(c.length).toFixed(2)}</td>
                <td>
                    <span class="badge bg-info text-dark fw-bold" style="font-size: 0.75rem;">${coilCust}</span>
                    <div class="mt-1"><code class="text-danger fw-semibold" style="font-size: 0.85rem;">${c.ref_no || '-'}</code></div>
                </td>
                <td>
                    <span class="badge bg-warning text-dark fw-normal text-wrap text-start d-inline-block" style="max-width: 140px; font-size: 0.72rem;" title="${noteEsc}">
                        ${c.hold_note || 'Sticker change needed'}
                    </span>
                </td>
                <td class="text-end text-nowrap">
                    <button type="button" class="btn btn-sm btn-outline-dark py-0 px-2 me-1" title="Edit Dimensions" onclick="openEditCoilModal(${c.id})">
                        <i class="fa-solid fa-pen-to-square"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 me-1" title="Remove Coil" onclick="toggleCoilSelection(${c.id})">
                        &times;
                    </button>
                    <button type="button" class="btn btn-sm btn-success py-0 px-2 shadow-sm" 
                            title="Sticker changed — clear hold" 
                            onclick="releaseHold(${c.id})">
                        <i class="fa-solid fa-circle-check me-1"></i>Done
                    </button>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

let editModalObj;

function openEditCoilModal(id) {
    const coil = selectedCoilsMap.get(id);
    if (!coil) return;

    if (!editModalObj) {
        editModalObj = new bootstrap.Modal(document.getElementById('editSelectedCoilModal'));
    }

    document.getElementById('editCoilId').value = coil.id;
    document.getElementById('editCoilLabel').value = `${coil.lot_no} ${coil.coil_no} (${coil.product})`;
    document.getElementById('editCoilWidth').value = parseFloat(coil.width).toFixed(2);
    document.getElementById('editCoilLength').value = parseFloat(coil.length).toFixed(2);

    editModalObj.show();
}

function saveEditedCoilDimensions() {
    const id     = parseInt(document.getElementById('editCoilId').value);
    const width  = floatval(document.getElementById('editCoilWidth').value);
    const length = floatval(document.getElementById('editCoilLength').value);

    const coil = selectedCoilsMap.get(id);
    if (coil) {
        coil.width  = width;
        coil.length = length;
        coil.weight = ((width / 1000) * length * 7.85).toFixed(2);
        coil.area   = ((width / 1000) * length).toFixed(2);

        selectedCoilsMap.set(id, coil);
        filterLeftCoils();
        renderRightSelectedCoils();
        calculateLiveTotals();
    }

    if (editModalObj) {
        editModalObj.hide();
    }
}

function calculateLiveTotals() {
    const reqLen = floatval(document.getElementById('reqLength').value);
    const selectedList = Array.from(selectedCoilsMap.values());

    let selLen = 0, selWgt = 0, selArea = 0;
    selectedList.forEach(c => {
        selLen  += floatval(c.length);
        selWgt  += floatval(c.weight);
        selArea += floatval(c.area);
    });

    document.getElementById('sumSelLength').innerText = selLen.toFixed(2) + ' m';
    document.getElementById('sumSelWeight').innerText = selWgt.toFixed(2) + ' kg';
    document.getElementById('sumSelArea').innerText   = selArea.toFixed(2) + ' m²';

    const banner    = document.getElementById('statusBanner');
    const icon      = document.getElementById('statusIcon');
    const title     = document.getElementById('statusTitle');
    const subtitle  = document.getElementById('statusSubtitle');
    const btnSubmit = document.getElementById('btnSubmitDraft');

    const diff = selLen - reqLen;

    if (selectedList.length > 0 && selLen >= reqLen && reqLen > 0) {
        banner.className = 'status-badge-container status-enough mb-4';
        icon.className   = 'fa-solid fa-circle-check fs-4';
        title.innerText  = '🟢 ENOUGH STOCK';
        subtitle.innerText = `Required: ${reqLen.toFixed(2)} m | Selected: ${selLen.toFixed(2)} m (Excess: +${diff.toFixed(2)} m)`;
        btnSubmit.disabled = false;
    } else {
        banner.className = 'status-badge-container status-not-enough mb-4';
        icon.className   = 'fa-solid fa-circle-xmark fs-4';
        title.innerText  = '🔴 NOT ENOUGH STOCK';
        subtitle.innerText = `Required: ${reqLen.toFixed(2)} m | Selected: ${selLen.toFixed(2)} m (Shortage: ${Math.abs(diff).toFixed(2)} m)`;
        btnSubmit.disabled = selectedList.length === 0;
    }
}

function floatval(val) {
    const parsed = parseFloat(val);
    return isNaN(parsed) ? 0 : parsed;
}

function handleDraftSubmit(e) {
    e.preventDefault();

    const customerName = document.getElementById('reqCustomerName').value.trim();
    const sosNo        = document.getElementById('reqSosNo').value.trim().toUpperCase();
    const product      = document.getElementById('reqProduct').value.trim();
    const widthVal     = parseFloat(document.getElementById('reqWidth').value);
    const coilIds      = Array.from(selectedCoilsMap.keys());

    if (!customerName || !sosNo || !product || isNaN(widthVal) || widthVal <= 0) {
        alert('Please fill in all required draft requirements: Customer Name, SOS Number, Product, and Width.');
        return;
    }

    // Format validation check: SO-YY-XXXX, MS-XXXXXXX, or MS-XXXXXXX J
    const sosRegex = /^(SO-\d{2}-[A-Z0-9]+|MS-[A-Z0-9]+(\s*J)?)$/i;
    if (!sosRegex.test(sosNo)) {
        if (!confirm(`SOS Number "${sosNo}" does not match the standard format:\n- SO-YY-XXXX (e.g. SO-26-1280)\n- MS-XXXXXXX (e.g. MS-1234567)\n- MS-XXXXXXX J (e.g. MS-1234567 J)\n\nDo you want to proceed anyway?`)) {
            document.getElementById('reqSosNo').focus();
            return;
        }
    }

    if (coilIds.length === 0) {
        alert('Please select at least one coil for the draft.');
        return;
    }

    // Check if any selected coil has a different customer than the draft customer
    const selectedList = Array.from(selectedCoilsMap.values());
    const mismatchedCoils = selectedList.filter(c => {
        const coilCust = (c.customer_name || 'STOCK').trim().toUpperCase();
        return coilCust !== customerName.toUpperCase();
    });

    if (mismatchedCoils.length > 0) {
        document.getElementById('subWarnDraftCustomer').innerText = customerName;
        const tbody = document.getElementById('subWarnTableBody');
        let html = '';
        mismatchedCoils.forEach(c => {
            html += `
                <tr>
                    <td><strong>${c.lot_no}</strong> ${c.coil_no}</td>
                    <td>${c.product}</td>
                    <td><span class="badge bg-info text-dark fw-bold">${c.customer_name || 'STOCK'}</span></td>
                </tr>
            `;
        });
        tbody.innerHTML = html;

        if (submitMismatchModalObj) {
            submitMismatchModalObj.show();
        }
        return;
    }

    executeDraftSubmit();
}

function executeDraftSubmit() {
    const customerName = document.getElementById('reqCustomerName').value.trim();
    const sosNo        = document.getElementById('reqSosNo').value.trim();
    const reqLen       = floatval(document.getElementById('reqLength').value);
    const coilIds      = Array.from(selectedCoilsMap.keys());

    // Auto-calculate weight and area based on selected coils
    const selectedList = Array.from(selectedCoilsMap.values());
    let autoWgt = 0, autoArea = 0;
    selectedList.forEach(c => {
        autoWgt  += floatval(c.weight);
        autoArea += floatval(c.area);
    });

    const btn = document.getElementById('btnSubmitDraft');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i> Saving Draft...';

    const payload = {
        action: editingDraftId ? 'update' : 'create',
        customer_name: customerName,
        sos_no: sosNo,
        required_length: reqLen,
        required_weight: autoWgt,
        required_area: autoArea,
        coil_ids: coilIds
    };
    if (editingDraftId) {
        payload.draft_order_id = editingDraftId;
    }

    fetch('../backend/api/drafts.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (data.ok) {
            const msg = editingDraftId ? `Draft Order ${data.draft_no} updated successfully!` : `Draft Order ${data.draft_no} created and coils reserved successfully!`;
            alert(msg);
            window.location.href = 'drafts_management.php';
        } else {
            alert('Error saving draft: ' + data.error);
            btn.disabled = false;
            btn.innerHTML = editingDraftId ? '<i class="fa-solid fa-floppy-disk me-2"></i>Update Draft Order' : '<i class="fa-solid fa-check-circle me-2"></i>Save & Reserve Draft Order';
        }
    })
    .catch(err => {
        alert('Submission failed: ' + err);
        btn.disabled = false;
        btn.innerHTML = editingDraftId ? '<i class="fa-solid fa-floppy-disk me-2"></i>Update Draft Order' : '<i class="fa-solid fa-check-circle me-2"></i>Save & Reserve Draft Order';
    });
}

// ── On Hold Functions ──────────────────────────────────────────────

function openHoldModal(coilId) {
    const coil = allAvailableCoils.find(c => c.id === coilId);
    if (!coil) return;

    document.getElementById('holdCoilId').value = coilId;
    document.getElementById('holdCoilLabel').innerText = `${coil.lot_no} ${coil.coil_no} (${coil.product})`;
    document.getElementById('holdCoilCustomer').innerText = coil.customer_name || 'STOCK';
    document.getElementById('holdNote').value = '';

    if (holdModalObj) holdModalObj.show();
}

function confirmPlaceOnHold() {
    const coilId = parseInt(document.getElementById('holdCoilId').value);
    const note   = document.getElementById('holdNote').value.trim();

    if (!note) {
        alert('Please enter a note describing the sticker change needed.');
        return;
    }

    const btn = document.getElementById('btnConfirmHold');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Saving...';

    fetch('../backend/api/coil_holds.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'hold', coil_id: coilId, note: note })
    })
    .then(res => res.json())
    .then(data => {
        if (data.ok) {
            // Update local data
            const coil = allAvailableCoils.find(c => c.id === coilId);
            if (coil) {
                coil.is_on_hold = 1;
                coil.hold_note  = note;
            }
            if (selectedCoilsMap.has(coilId)) {
                const sel = selectedCoilsMap.get(coilId);
                sel.is_on_hold = 1;
                sel.hold_note  = note;
            }
            filterLeftCoils();
            renderRightSelectedCoils();
            if (holdModalObj) holdModalObj.hide();
        } else {
            alert('Error: ' + (data.error || 'Failed to place hold.'));
        }
    })
    .catch(err => alert('Request failed: ' + err))
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-hand me-1"></i> Place On Hold';
    });
}

function releaseHold(coilId) {
    if (!confirm('Sticker has been changed — clear this hold?')) return;

    fetch('../backend/api/coil_holds.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'release', coil_id: coilId })
    })
    .then(res => res.json())
    .then(data => {
        if (data.ok) {
            const coil = allAvailableCoils.find(c => c.id === coilId);
            if (coil) {
                coil.is_on_hold = 0;
                coil.hold_note  = null;
            }
            if (selectedCoilsMap.has(coilId)) {
                const selCoil = selectedCoilsMap.get(coilId);
                selCoil.is_on_hold = 0;
                selCoil.hold_note  = null;
            }
            filterLeftCoils();
            renderRightSelectedCoils();
        } else {
            alert('Error: ' + (data.error || 'Failed to release hold.'));
        }
    })
    .catch(err => alert('Request failed: ' + err));
}
</script>

<?php include 'includes/footer.php'; ?>

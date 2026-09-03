<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

include 'config.php';

$page_title = "Real-Time Production Monitoring";
$hide_sidebar = true;
include 'header.php';
?>

<style>
    /* Factory Floor Dashboard Display Styles */
    .monitoring-card {
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        border: none;
        transition: all 0.3s ease;
    }
    .running-hero-card {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        color: #ffffff;
        border-left: 8px solid #0d6efd;
    }
    .packing-hero-card {
        background: linear-gradient(135deg, #2d1b4e 0%, #1e1b4b 100%);
        color: #ffffff;
        border-left: 8px solid #ffc107;
    }
    .idle-hero-card {
        background: linear-gradient(135deg, #334155 0%, #1e293b 100%);
        color: #94a3b8;
        border-left: 8px solid #64748b;
    }
    .display-coil-id {
        font-size: 2.4rem;
        font-weight: 800;
        letter-spacing: 0.5px;
        color: #38bdf8;
    }
    .display-product-type {
        font-size: 1.8rem;
        font-weight: 700;
        color: #f1f5f9;
    }
    .display-customer {
        font-size: 1.4rem;
        font-weight: 600;
        color: #cbd5e1;
    }
    .elapsed-timer-box {
        background: rgba(15, 23, 42, 0.6);
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 10px;
        padding: 12px 24px;
    }
    .timer-digits {
        font-family: 'Courier New', Courier, monospace;
        font-size: 2.4rem;
        font-weight: 800;
        color: #4ade80;
    }
    .packing-countdown-digits {
        font-family: 'Courier New', Courier, monospace;
        font-size: 2.4rem;
        font-weight: 800;
        color: #fbbf24;
    }
    .status-badge-running {
        font-size: 1rem;
        padding: 8px 18px;
        border-radius: 20px;
        box-shadow: 0 0 12px rgba(13, 110, 253, 0.5);
        animation: pulse-blue 2s infinite;
    }
    .status-badge-packing {
        font-size: 1rem;
        padding: 8px 18px;
        border-radius: 20px;
        box-shadow: 0 0 12px rgba(255, 193, 7, 0.6);
        animation: pulse-amber 1.5s infinite;
    }
    @keyframes pulse-blue {
        0% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.7); }
        70% { box-shadow: 0 0 0 12px rgba(13, 110, 253, 0); }
        100% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0); }
    }
    @keyframes pulse-amber {
        0% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.7); }
        70% { box-shadow: 0 0 0 12px rgba(255, 193, 7, 0); }
        100% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0); }
    }
    .waiting-table th {
        background-color: #f1f5f9;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.85rem;
        color: #475569;
    }
    .queue-badge {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
    }
</style>

<div class="container-fluid py-3">

    <!-- Top Header -->
    <div class="row align-items-center mb-4">
        <div class="col-12">
            <h3 class="fw-bold m-0 text-dark">
                <i class="bi bi-tv text-primary me-2"></i> Slitting Production Monitoring
            </h3>
            <p class="text-muted small m-0 mt-1">Real-time active production status & mother coil queue display</p>
        </div>
    </div>

    <div class="row g-4">
        <!-- ════════════════════════════════════════════════════════════════ -->
        <!-- SECTION A: CURRENT RUNNING COIL (ACTIVE PRODUCTION)             -->
        <!-- ════════════════════════════════════════════════════════════════ -->
        <div class="col-12 col-xl-7">
            <div class="card monitoring-card h-100" id="runningCardContainer">
                <div class="card-body p-4 d-flex flex-column justify-content-between" id="runningCardBody">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary mb-3" role="status"></div>
                        <p class="text-muted fs-5">Loading Active Production Display...</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════════ -->
        <!-- SECTION B: WAITING LIST (QUEUE)                                 -->
        <!-- ════════════════════════════════════════════════════════════════ -->
        <div class="col-12 col-xl-5">
            <div class="card monitoring-card h-100">
                <div class="card-header bg-white border-bottom p-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold m-0 text-dark">
                        <i class="bi bi-list-ol text-warning me-2"></i> Waiting List (Queue)
                    </h5>
                    <span class="badge bg-primary rounded-pill fs-6" id="waitingQueueBadge">0 Coils</span>
                </div>
                <div class="card-body p-0" style="max-height: 520px; overflow-y: auto;">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle waiting-table m-0">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width: 50px;">Pos</th>
                                    <th>Coil ID / Barcode</th>
                                    <th>Product Type</th>
                                    <th>Customer Name</th>
                                    <th>Received</th>
                                </tr>
                            </thead>
                            <tbody id="waitingTableBody">
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">
                                        <div class="spinner-border spinner-border-sm text-secondary me-2"></div> Loading queue...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let liveTimerInterval = null;
let currentStartTimestamp = 0;
let packingCountdownInterval = null;
let currentPackingRemaining = 0;

document.addEventListener('DOMContentLoaded', () => {
    fetchMonitoringData();
    setInterval(fetchMonitoringData, 3000); // 3-second real-time poll
});

// Fetch live monitoring data via AJAX
function fetchMonitoringData() {
    fetch('production_monitoring_ajax.php?action=get_data')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderSectionA(data.running);
                renderSectionB(data.waiting_list);
            }
        })
        .catch(err => console.error('Error fetching monitoring data:', err));
}

// Render Section A: Current Running Coil (Read-Only Display)
function renderSectionA(running) {
    const container = document.getElementById('runningCardContainer');
    const body = document.getElementById('runningCardBody');

    if (liveTimerInterval) {
        clearInterval(liveTimerInterval);
        liveTimerInterval = null;
    }
    if (packingCountdownInterval) {
        clearInterval(packingCountdownInterval);
        packingCountdownInterval = null;
    }

    if (!running || !running.has_running) {
        // IDLE STATE
        container.className = 'card monitoring-card h-100 idle-hero-card';
        body.innerHTML = `
            <div class="text-center py-5">
                <i class="bi bi-dash-circle fs-1 mb-3 d-block text-secondary"></i>
                <h3 class="fw-bold text-white mb-2">Machine Idle</h3>
                <p class="fs-5 mb-0 text-light">No mother coil currently in active production.</p>
            </div>
        `;
        return;
    }

    if (running.is_packing) {
        // PACKING STATE (Temporary 1-minute completion state)
        container.className = 'card monitoring-card h-100 packing-hero-card';
        currentPackingRemaining = running.packing_remaining_seconds || 60;

        body.innerHTML = `
            <div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <span class="badge bg-warning text-dark fs-6 status-badge-packing">
                            <i class="bi bi-box-seam me-1"></i> PACKING
                        </span>
                        <span class="badge bg-secondary ms-2 fs-6">${running.sub_status}</span>
                    </div>
                    <div class="text-end">
                        <span class="text-warning small fw-bold">Auto-clears in 1 minute</span>
                    </div>
                </div>

                <div class="mb-4">
                    <div class="text-uppercase text-warning small fw-bold mb-1">Finalizing Finished Goods</div>
                    <div class="display-coil-id">${running.coil_id_display}</div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-6">
                        <div class="text-uppercase small text-light fw-semibold">Product Type</div>
                        <div class="display-product-type">${running.product_type}</div>
                    </div>
                    <div class="col-6 col-md-6">
                        <div class="text-uppercase small text-light fw-semibold">Customer Name</div>
                        <div class="display-customer">${running.customer_name}</div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-end mt-3 pt-3 border-top border-secondary">
                <div class="elapsed-timer-box">
                    <div class="text-uppercase small text-warning fw-bold mb-1">Packing Countdown</div>
                    <div class="packing-countdown-digits" id="packingCountdownDisplay">00:${currentPackingRemaining < 10 ? '0' : ''}${currentPackingRemaining}</div>
                </div>
                <div>
                    <span class="badge bg-success fs-6 py-2 px-3">
                        <i class="bi bi-check-all me-1"></i> Saved to Stock
                    </span>
                </div>
            </div>
        `;

        // Start Packing Countdown Ticker
        packingCountdownInterval = setInterval(() => {
            currentPackingRemaining--;
            const displayEl = document.getElementById('packingCountdownDisplay');
            if (displayEl) {
                if (currentPackingRemaining > 0) {
                    displayEl.innerText = `00:${currentPackingRemaining < 10 ? '0' : ''}${currentPackingRemaining}`;
                } else {
                    displayEl.innerText = `00:00`;
                    clearInterval(packingCountdownInterval);
                    fetchMonitoringData(); // Refresh to clear
                }
            }
        }, 1000);

    } else {
        // RUNNING STATE
        container.className = 'card monitoring-card h-100 running-hero-card';
        currentStartTimestamp = running.start_timestamp;

        body.innerHTML = `
            <div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <span class="badge bg-primary status-badge-running">
                            <i class="bi bi-gear-wide-connected me-1"></i> RUNNING
                        </span>
                        <span class="badge bg-warning text-dark ms-2 fs-6">${running.sub_status}</span>
                    </div>
                    <div class="text-end">
                        <span class="text-light small">Started: ${running.start_time_fmt}</span>
                    </div>
                </div>

                <div class="mb-4">
                    <div class="text-uppercase text-info small fw-bold mb-1">Active Mother Coil ID</div>
                    <div class="display-coil-id">${running.coil_id_display}</div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-6">
                        <div class="text-uppercase small text-light fw-semibold">Product Type</div>
                        <div class="display-product-type">${running.product_type}</div>
                    </div>
                    <div class="col-6 col-md-6">
                        <div class="text-uppercase small text-light fw-semibold">Customer Name</div>
                        <div class="display-customer">${running.customer_name}</div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-end mt-3 pt-3 border-top border-secondary">
                <div class="elapsed-timer-box">
                    <div class="text-uppercase small text-info fw-bold mb-1">Elapsed Time</div>
                    <div class="timer-digits" id="liveElapsedDisplay">${running.elapsed_formatted}</div>
                </div>
                <div>
                    <span class="badge bg-info text-dark fs-6 py-2 px-3 fw-bold">
                        <i class="bi bi-activity me-1"></i> Active Production
                    </span>
                </div>
            </div>
        `;

        // Start Live Elapsed Time Ticker
        liveTimerInterval = setInterval(() => {
            if (currentStartTimestamp > 0) {
                const nowSec = Math.floor(Date.now() / 1000);
                const diff = Math.max(0, nowSec - currentStartTimestamp);
                const hrs  = Math.floor(diff / 3600);
                const mins = Math.floor((diff % 3600) / 60);
                const secs = diff % 60;
                const formatted = `${hrs < 10 ? '0' : ''}${hrs}:${mins < 10 ? '0' : ''}${mins}:${secs < 10 ? '0' : ''}${secs}`;
                const el = document.getElementById('liveElapsedDisplay');
                if (el) el.innerText = formatted;
            }
        }, 1000);
    }
}

// Render Section B: Waiting List Queue (Read-Only Display)
function renderSectionB(waitingList) {
    const badge = document.getElementById('waitingQueueBadge');
    const tbody = document.getElementById('waitingTableBody');

    if (badge) badge.innerText = `${waitingList ? waitingList.length : 0} Coils`;

    if (!waitingList || waitingList.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" class="text-center py-4 text-muted">
                    <i class="bi bi-inbox fs-3 d-block mb-1 text-secondary"></i>
                    No mother coils currently in Waiting List queue.
                </td>
            </tr>
        `;
        return;
    }

    let html = '';
    waitingList.forEach(item => {
        html += `
            <tr>
                <td class="text-center">
                    <span class="queue-badge ${item.pos === 1 ? 'bg-primary text-white' : 'bg-light text-dark border'}">
                        ${item.pos}
                    </span>
                </td>
                <td class="fw-bold text-dark">${item.coil_id_display}</td>
                <td><span class="badge bg-secondary">${item.product_type}</span></td>
                <td class="fw-semibold text-secondary">${item.customer_name}</td>
                <td class="small text-muted">${item.received_formatted}</td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}
</script>

<?php include 'footer.php'; ?>

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
        font-size: 1.3rem;
        font-weight: 600;
        color: #cbd5e1;
        word-break: break-word;
        overflow-wrap: break-word;
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
    /* 24-Hour Production Performance Hourly Scale */
    .hourly-scale-wrapper {
        position: relative;
        width: 100%;
        height: 32px;
        margin-top: 4px;
        margin-bottom: 8px;
        user-select: none;
    }
    .hourly-scale-item {
        position: absolute;
        transform: translateX(-50%);
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    .hourly-scale-tick {
        width: 1px;
        height: 8px;
        background-color: #94a3b8;
    }
    .hourly-scale-tick.tick-major {
        height: 11px;
        width: 2px;
        background-color: #475569;
    }
    .hourly-scale-tick.tick-midnight {
        height: 12px;
        width: 2px;
        background-color: #0284c7;
    }
    .hourly-scale-label {
        font-size: 0.72rem;
        font-weight: 600;
        color: #64748b;
        margin-top: 2px;
        line-height: 1;
        white-space: nowrap;
    }
    .hourly-scale-label.label-major {
        color: #1e293b;
        font-weight: 700;
    }
    .hourly-scale-label.label-midnight {
        color: #0284c7;
        font-weight: 800;
    }
    @media (max-width: 768px) {
        .hourly-scale-label {
            font-size: 0.60rem;
        }
    }
    @media (max-width: 480px) {
        .hourly-scale-label {
            font-size: 0.50rem;
        }
    }
</style>

<div class="container-fluid py-3">

    <!-- Top Header -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div class="d-flex flex-wrap align-items-center gap-4">
            <div>
                <h3 class="fw-bold m-0 text-dark">
                    <i class="bi bi-tv text-primary me-2"></i> Slitting Production Monitoring
                </h3>
                <p class="text-muted small m-0 mt-1">Real-time active production status & mother coil queue display</p>
            </div>

            <!-- Slitting Running Coil Counter (Jumlah Coil Sedang Dipotong) -->
            <div class="card border-0 shadow-sm px-3 py-2 rounded-3 bg-white d-flex flex-row align-items-center gap-3 border-start border-4 border-primary">
                <div class="p-2 rounded-2 bg-primary bg-opacity-20 text-primary">
                    <i class="bi bi-scissors fs-4 text-primary"></i>
                </div>
                <div>
                    <div class="text-uppercase text-muted fw-bold" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                        Coil Sedang Dipotong
                    </div>
                    <div class="d-flex align-items-baseline gap-1">
                        <span class="fs-4 fw-bold text-dark" id="slittingRunningCoilCount">0</span>
                        <span class="text-muted small fw-semibold">Coil (<span id="slittingRunningCoilLen">0 m</span>)</span>
                    </div>
                </div>
            </div>

            <!-- Recoiling Coil Counter (By Coil, Follow by Day) -->
            <div class="card border-0 shadow-sm px-3 py-2 rounded-3 bg-white d-flex flex-row align-items-center gap-3 border-start border-4 border-warning">
                <div class="p-2 rounded-2 bg-warning bg-opacity-20 text-warning">
                    <i class="bi bi-arrow-repeat fs-4 text-warning"></i>
                </div>
                <div>
                    <div class="text-uppercase text-muted fw-bold" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                        Recoiling Coils (Today)
                    </div>
                    <div class="d-flex align-items-baseline gap-1">
                        <span class="fs-4 fw-bold text-dark" id="recoilDayCoilCount">0</span>
                        <span class="text-muted small fw-semibold">Coils</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-none d-md-block text-end">
            <span class="badge bg-light text-secondary border px-3 py-2 small fw-semibold">
                <i class="bi bi-calendar-event me-1"></i> Production Day: <strong id="prodDayLabel" class="text-dark"><?php echo date('d M Y'); ?></strong>
            </span>
        </div>
    </div>

    <!-- 24-HOUR PRODUCTION PERFORMANCE: 2 BAR GRAPHS WITH SCALE -->
    <div class="card shadow-sm border-0 mb-4 rounded-3" style="background: #ffffff;">
        <div class="card-body p-3 p-md-4">
            
            <!-- ════════════════════════════════════════════════════════════════ -->
            <!-- 1ST BAR: TARGET MOTHER COIL LENGTH PER HOUR (RED COLOUR)         -->
            <!-- ════════════════════════════════════════════════════════════════ -->
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="p-2 rounded-3 bg-danger bg-opacity-10 text-danger">
                        <i class="bi bi-bullseye fs-4"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <h5 class="fw-bold m-0 text-dark">Target Mother Coil Length (Per Hour)</h5>
                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle fw-bold" id="targetHourlyRateBadge">650 m / hour</span>
                        </div>
                        <p class="text-muted small m-0" id="targetHourlySubtitle">Sasaran pengeluaran mengikut jam &bull; Sasaran kumulatif mengikut garis masa</p>
                    </div>
                </div>
                <div class="text-end">
                    <span class="fs-5 fw-bold text-danger" id="targetHourProduced">0 m</span>
                    <span class="text-muted fs-6"> / </span>
                    <span class="text-muted fs-6 fw-semibold" id="targetHourMax">10,400 m</span>
                    <span class="badge bg-danger text-white fs-6 ms-2" id="targetHourBadge">0.0%</span>
                </div>
            </div>

            <!-- Target Progress Bar Track (Red) -->
            <div class="progress rounded-pill shadow-inner my-2" style="height: 22px; background: #fee2e2;">
                <div class="progress-bar progress-bar-striped progress-bar-animated rounded-pill" 
                     id="targetProgressBar" 
                     role="progressbar" 
                     style="width: 0%; background: linear-gradient(90deg, #dc2626, #ef4444); transition: width 0.8s ease-in-out;" 
                     aria-valuenow="0" 
                     aria-valuemin="0" 
                     aria-valuemax="100">
                </div>
            </div>

            <!-- 1st Scale: Hourly Scale Track for Target -->
            <div class="hourly-scale-wrapper" id="hourlyScaleTargetContainer"></div>

            <hr class="my-3 text-muted opacity-25">

            <!-- ════════════════════════════════════════════════════════════════ -->
            <!-- 2ND BAR: REAL PRODUCTION PERFORMANCE (ACTUAL)                   -->
            <!-- ════════════════════════════════════════════════════════════════ -->
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="p-2 rounded-3 bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-speedometer fs-4"></i>
                    </div>
                    <div>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <h5 class="fw-bold m-0 text-dark">Real Production Performance (Actual)</h5>
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary fs-7 d-none" id="runningCoilInfoBadge"></span>
                            <span class="badge bg-secondary bg-opacity-10 text-secondary border fs-7 d-none" id="completedCoilInfoBadge"></span>
                            <span class="badge bg-light text-dark border fs-7 d-none" id="productionVarianceBadge"></span>
                        </div>
                        <p class="text-muted small m-0" id="lenTargetSubtitle">Real-time total Mother Coil length tracking vs. 24-hour target (Default 2 Shifts: 10,400 m / 3 Shifts: 15,600 m)</p>
                    </div>
                </div>
                <div class="text-end">
                    <span class="fs-5 fw-bold text-dark" id="len24hProduced">0 m</span>
                    <span class="text-muted fs-6"> / </span>
                    <span class="text-muted fs-6 fw-semibold" id="len24hTarget">10,400 m</span>
                    <span class="badge bg-info text-dark fs-6 ms-2" id="lenProgressBadge">0.0%</span>
                </div>
            </div>

            <!-- Real Performance Progress Bar Track -->
            <div class="progress rounded-pill shadow-inner my-2" style="height: 22px; background: #e2e8f0;">
                <div class="progress-bar progress-bar-striped progress-bar-animated rounded-pill" 
                     id="lenProgressBar" 
                     role="progressbar" 
                     style="width: 0%; background: linear-gradient(90deg, #0284c7, #38bdf8); transition: width 0.8s ease-in-out;" 
                     aria-valuenow="0" 
                     aria-valuemin="0" 
                     aria-valuemax="100">
                </div>
            </div>

            <!-- 2nd Scale: Hourly Scale Track for Real Performance -->
            <div class="hourly-scale-wrapper" id="hourlyScaleContainer"></div>

            <!-- Footer Details -->
            <div class="d-flex flex-wrap align-items-center justify-content-between small text-muted pt-2 border-top">
                <div>
                    <i class="bi bi-gear me-1"></i>
                    Shift Target (8h): <strong id="lenShiftTarget" class="text-dark">5,200 m</strong> 
                    <span class="mx-1">&bull;</span> 
                    <span id="lenTargetFormulaLabel">24h Target (2 Shifts):</span> <strong id="len24hMaxFormula" class="text-dark">10,400 m</strong>
                </div>
                <div>
                    <i class="bi bi-calendar-week me-1"></i>
                    Weekly Cumulative (Mon - Sun): <strong id="lenWeeklyTotal" class="text-success">0 m</strong>
                </div>
            </div>
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
                                    <th class="text-center" style="width: 45px;">Pos</th>
                                    <th>Coil ID / Barcode</th>
                                    <th>Process Operation</th>
                                    <th>Product Type</th>
                                    <th>Length (m)</th>
                                    <th>Customer Name</th>
                                    <th>Status</th>
                                    <th>Received</th>
                                </tr>
                            </thead>
                            <tbody id="waitingTableBody">
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">
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
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP error ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                renderSectionA(data.running);
                renderSectionB(data.waiting_list);
                renderLengthTracking(data.length_tracking);
                if (data.length_tracking) {
                    const elRunCoilCount = document.getElementById('slittingRunningCoilCount');
                    const elRunCoilLen   = document.getElementById('slittingRunningCoilLen');
                    if (elRunCoilCount) {
                        elRunCoilCount.innerText = data.length_tracking.running_coil_count ?? 0;
                    }
                    if (elRunCoilLen) {
                        elRunCoilLen.innerText = Math.round(data.length_tracking.running_coil_length ?? 0).toLocaleString() + ' m';
                    }
                }
                if (data.recoil_summary) {
                    const elCoil = document.getElementById('recoilDayCoilCount');
                    if (elCoil) {
                        elCoil.innerText = data.recoil_summary.total_coils ?? 0;
                    }
                    const elDate = document.getElementById('prodDayLabel');
                    if (elDate && data.recoil_summary.prod_date_formatted) {
                        elDate.innerText = data.recoil_summary.prod_date_formatted;
                    }
                }
                if (data.shift_summary) {
                    const s1 = document.getElementById('s1Badge');
                    const s2 = document.getElementById('s2Badge');
                    const s3 = document.getElementById('s3Badge');
                    const st = document.getElementById('sTotalBadge');
                    if (s1) s1.innerText = 'Shift 1: ' + data.shift_summary.shift1;
                    if (s2) s2.innerText = 'Shift 2: ' + data.shift_summary.shift2;
                    if (s3) s3.innerText = 'Shift 3: ' + data.shift_summary.shift3;
                    if (st) st.innerText = 'Total Today: ' + data.shift_summary.total;
                }
            } else {
                if (data.message === 'Unauthorized') {
                    window.location.href = 'login.php';
                } else {
                    renderSectionA(null);
                    renderSectionB([]);
                }
            }
        })
        .catch(err => {
            console.error('Error fetching monitoring data:', err);
            // Fallback render to clear infinite loading spinner on connection error
            renderSectionA(null);
            renderSectionB([]);
        });
}

// Render 24-Hour Length Tracking: 2 Bar Graphs (Target Per Hour & Real Performance)
function renderLengthTracking(tracking) {
    if (!tracking) return;
    
    const produced = parseFloat(tracking.length_produced_24h) || 0;
    const target24h = parseFloat(tracking.target_24h_meters) || 10400;
    const shiftTarget = parseFloat(tracking.shift_target_meters) || 5200;
    const weeklyTotal = parseFloat(tracking.weekly_total_meters) || 0;
    const pct = parseFloat(tracking.progress_percentage) || 0;
    const shiftsCount = parseInt(tracking.shifts_multiplier, 10) || (target24h >= 15600 ? 3 : 2);

    // ── 1ST BAR: TARGET MOTHER COIL LENGTH PER HOUR (RED) ───────────────
    const hourlyRate = parseFloat(tracking.hourly_target_rate) || Math.round(shiftTarget / 8);
    const targetProduced = parseFloat(tracking.target_produced_meters) || 0;
    const targetPct = parseFloat(tracking.target_hour_percentage) || 0;

    const elTargetProd = document.getElementById('targetHourProduced');
    const elTargetMax  = document.getElementById('targetHourMax');
    const targetBadge  = document.getElementById('targetHourBadge');
    const targetBar    = document.getElementById('targetProgressBar');
    const targetRateBadge = document.getElementById('targetHourlyRateBadge');

    if (elTargetProd) elTargetProd.innerText = Math.round(targetProduced).toLocaleString() + ' m';
    if (elTargetMax)  elTargetMax.innerText  = Math.round(target24h).toLocaleString() + ' m';
    if (targetBadge)  targetBadge.innerText  = targetPct.toFixed(1) + '%';
    if (targetRateBadge) targetRateBadge.innerText = Math.round(hourlyRate).toLocaleString() + ' m / hour';

    if (targetBar) {
        targetBar.style.width = Math.min(100, Math.max(0, targetPct)) + '%';
        targetBar.setAttribute('aria-valuenow', targetPct);
    }

    // ── 2ND BAR: REAL PRODUCTION PERFORMANCE (ACTUAL) ───────────────────
    const elProd = document.getElementById('len24hProduced');
    const elTgt = document.getElementById('len24hTarget');
    const elShiftTgt = document.getElementById('lenShiftTarget');
    const el24hMax = document.getElementById('len24hMaxFormula');
    const elFormulaLbl = document.getElementById('lenTargetFormulaLabel');
    const elWkTotal = document.getElementById('lenWeeklyTotal');
    const badge = document.getElementById('lenProgressBadge');
    const bar = document.getElementById('lenProgressBar');
    const varianceBadge = document.getElementById('productionVarianceBadge');

    if (elProd) elProd.innerText = Math.round(produced).toLocaleString() + ' m';
    if (elTgt) elTgt.innerText = Math.round(target24h).toLocaleString() + ' m';
    if (elShiftTgt) elShiftTgt.innerText = Math.round(shiftTarget).toLocaleString() + ' m';
    if (el24hMax) el24hMax.innerText = Math.round(target24h).toLocaleString() + ' m';
    if (elFormulaLbl) elFormulaLbl.innerText = `24h Target (${shiftsCount} Shifts):`;
    if (elWkTotal) elWkTotal.innerText = Math.round(weeklyTotal).toLocaleString() + ' m';
    
    if (badge) badge.innerText = pct.toFixed(1) + '%';

    // Badges for Completed vs Sedang Dipotong
    const elCompletedBadge = document.getElementById('completedCoilInfoBadge');
    const elRunningBadge   = document.getElementById('runningCoilInfoBadge');

    if (elCompletedBadge) {
        elCompletedBadge.classList.remove('d-none');
        elCompletedBadge.innerText = 'Siap: ' + Math.round(tracking.length_completed_24h || 0).toLocaleString() + ' m';
    }
    if (elRunningBadge) {
        const rCount = parseInt(tracking.running_coil_count, 10) || 0;
        const rLen   = parseFloat(tracking.running_coil_length) || 0;
        if (rCount > 0 && rLen > 0) {
            elRunningBadge.classList.remove('d-none');
            elRunningBadge.innerHTML = `<i class="bi bi-scissors me-1"></i>Sedang dipotong: ${rCount} Coil (${Math.round(rLen).toLocaleString()} m)`;
        } else {
            elRunningBadge.classList.add('d-none');
        }
    }

    // Variance badge (Ahead / Behind target pacing)
    if (varianceBadge) {
        const diff = Math.round(produced - targetProduced);
        varianceBadge.classList.remove('d-none');
        if (diff >= 0) {
            varianceBadge.className = 'badge bg-success bg-opacity-10 text-success border border-success fs-7';
            varianceBadge.innerHTML = `<i class="bi bi-arrow-up-right me-1"></i>+${diff.toLocaleString()} m Ahead of Target`;
        } else {
            varianceBadge.className = 'badge bg-danger bg-opacity-10 text-danger border border-danger fs-7';
            varianceBadge.innerHTML = `<i class="bi bi-arrow-down-right me-1"></i>${diff.toLocaleString()} m Behind Target`;
        }
    }

    const subTitle = document.getElementById('lenTargetSubtitle');
    if (subTitle) {
        const isFri = (tracking.is_friday === true);
        subTitle.innerText = isFri 
            ? 'Real-time total Mother Coil length tracking vs. 24-hour target (Friday 2 Shifts: 10,400 m / 3 Shifts: 15,600 m after 01:00)'
            : 'Real-time total Mother Coil length tracking vs. 24-hour target (Default 2 Shifts: 10,400 m / 3 Shifts: 15,600 m after 12:00 AM)';
    }

    if (bar) {
        bar.style.width = Math.min(100, Math.max(0, pct)) + '%';
        bar.setAttribute('aria-valuenow', pct);

        if (pct >= 90) {
            bar.style.background = 'linear-gradient(90deg, #059669, #34d399)';
            if (badge) badge.className = 'badge bg-success fs-6 ms-2';
        } else if (pct >= 50) {
            bar.style.background = 'linear-gradient(90deg, #d97706, #fbbf24)';
            if (badge) badge.className = 'badge bg-warning text-dark fs-6 ms-2';
        } else {
            bar.style.background = 'linear-gradient(90deg, #0284c7, #38bdf8)';
            if (badge) badge.className = 'badge bg-info text-dark fs-6 ms-2';
        }
    }

    // Render Scales for both Target Bar and Real Performance Bar
    const hasPostMidnight = (tracking.has_post_midnight_prod === true || shiftsCount === 3 || target24h >= 15600);
    renderHourlyScale('hourlyScaleTargetContainer', hasPostMidnight);
    renderHourlyScale('hourlyScaleContainer', hasPostMidnight);
}

// Render Hourly Scale Marks along the 24-Hour Progress Track for a given container
function renderHourlyScale(containerId, hasPostMidnight) {
    const container = document.getElementById(containerId);
    if (!container) return;

    // Define hours in the production timeline
    // Production day starts at 07:00 AM.
    // Shift 1 + Shift 2 covers 07:00 AM to 23:00 (11:00 PM).
    // Default hours: 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23 (16 hours).
    // If post-12am (Shift 3) has production, scale extends through 00, 1, 2, 3, 4, 5, 6, 7 (24 hours).
    const totalHours = hasPostMidnight ? 24 : 16;
    
    // Check if current scale configuration already rendered
    const currentScaleType = container.getAttribute('data-scale-type');
    const targetScaleType = hasPostMidnight ? '24h' : '16h';
    if (currentScaleType === targetScaleType) return;
    container.setAttribute('data-scale-type', targetScaleType);

    let html = '';
    for (let step = 1; step <= totalHours; step++) {
        // Calculate clock hour
        const clockHour = (7 + step) % 24;
        const pctPosition = (step / totalHours) * 100;
        
        let labelText = clockHour.toString();
        let isMajor = (clockHour === 15 || clockHour === 23 || clockHour === 7);
        let isMidnight = (clockHour === 0);

        if (isMidnight) {
            labelText = '00:00';
        }

        const tickClass = isMidnight ? 'tick-midnight' : (isMajor ? 'tick-major' : '');
        const labelClass = isMidnight ? 'label-midnight' : (isMajor ? 'label-major' : '');

        html += `
            <div class="hourly-scale-item" style="left: ${pctPosition}%;" title="Jam ${labelText}">
                <div class="hourly-scale-tick ${tickClass}"></div>
                <div class="hourly-scale-label ${labelClass}">${labelText}</div>
            </div>
        `;
    }

    container.innerHTML = html;
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
                    <div class="col-4 col-md-4">
                        <div class="text-uppercase small text-light fw-semibold">Product Type</div>
                        <div class="display-product-type">${running.product_type}</div>
                    </div>
                    <div class="col-4 col-md-4">
                        <div class="text-uppercase small text-light fw-semibold">Mother Coil Length</div>
                        <div class="display-product-type text-warning fw-bold">${running.mother_length_formatted || '-'}</div>
                    </div>
                    <div class="col-4 col-md-4">
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

        const processBadgeClass = running.process_badge_class || 'bg-info text-dark';
        const processIcon = running.process_icon || 'bi-scissors';
        const processType = running.process_type || 'Slitting';

        body.innerHTML = `
            <div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <span class="badge bg-primary status-badge-running">
                            <i class="bi bi-gear-wide-connected me-1"></i> RUNNING
                        </span>
                        <span class="badge ${processBadgeClass} ms-2 fs-6 fw-bold">
                            <i class="bi ${processIcon} me-1"></i> ${processType}
                        </span>
                        <span class="badge bg-secondary ms-2 fs-6">${running.sub_status}</span>
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
                    <div class="col-6 col-md-3">
                        <div class="text-uppercase small text-light fw-semibold">Process</div>
                        <div class="display-product-type"><span class="badge ${processBadgeClass} fs-6"><i class="bi ${processIcon} me-1"></i>${processType}</span></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-uppercase small text-light fw-semibold">Product Type</div>
                        <div class="display-product-type">${running.product_type}</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-uppercase small text-light fw-semibold">Mother Coil Length</div>
                        <div class="display-product-type text-warning fw-bold">${running.mother_length_formatted || '-'}</div>
                    </div>
                    <div class="col-6 col-md-3">
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
                <td colspan="8" class="text-center py-4 text-muted">
                    <i class="bi bi-inbox fs-3 d-block mb-1 text-secondary"></i>
                    No mother coils currently in Waiting List queue.
                </td>
            </tr>
        `;
        return;
    }

    let html = '';
    waitingList.forEach(item => {
        const badgeClass = item.status_badge_class || 'bg-secondary text-white';
        const statusLabel = item.status_label || 'Waiting';
        const procClass = item.process_badge_class || 'bg-info text-dark';
        const procIcon  = item.process_icon || 'bi-scissors';
        const procType  = item.process_type || 'Slitting';

        html += `
            <tr>
                <td class="text-center">
                    <span class="queue-badge ${item.pos === 1 ? 'bg-primary text-white' : 'bg-light text-dark border'}">
                        ${item.pos}
                    </span>
                </td>
                <td class="fw-bold text-dark">${item.coil_id_display}</td>
                <td><span class="badge ${procClass} fs-7"><i class="bi ${procIcon} me-1"></i>${procType}</span></td>
                <td><span class="badge bg-secondary">${item.product_type}</span></td>
                <td class="fw-bold text-dark">${item.length_formatted || '-'}</td>
                <td class="fw-semibold text-secondary">${item.customer_name}</td>
                <td><span class="badge ${badgeClass} fs-7">${statusLabel}</span></td>
                <td class="small text-muted">${item.received_formatted}</td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}
</script>

<?php include 'footer.php'; ?>

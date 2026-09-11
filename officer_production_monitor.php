<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';

$page_title = "Papan Pemantauan Pengeluaran Pegawai (Officer Monitor)";
include 'header.php';

$shiftTarget = (float)getSystemSetting($conn, 'shift_target_meters', '5200');
if ($shiftTarget <= 0) {
    $shiftTarget = 5200.0;
}
$dailyTarget  = $shiftTarget * 3;
$weeklyTarget = $dailyTarget * 7;
?>

<!-- Include Chart.js for Weekly Slot Performance Chart -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    .officer-card {
        border-radius: 12px;
        border: none;
        box-shadow: 0 4px 18px rgba(0,0,0,0.06);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .officer-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 24px rgba(0,0,0,0.1);
    }
    .stat-hero {
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        color: #ffffff;
    }
    .stat-number {
        font-size: 1.8rem;
        font-weight: 800;
        letter-spacing: -0.5px;
    }
    .slot-table th {
        background-color: #0f172a !important;
        color: #f8fafc !important;
        font-weight: 600;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .slot-table td {
        vertical-align: middle;
    }
    .bg-today-highlight {
        background-color: rgba(14, 165, 233, 0.08) !important;
        border-left: 4px solid #0284c7 !important;
    }
</style>

<div class="container-fluid py-4">

    <!-- Top Header -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h3 class="fw-bold text-dark m-0">
                <i class="bi bi-display text-primary me-2"></i> Papan Pemantauan Pengeluaran Pegawai
            </h3>
            <p class="text-muted small m-0 mt-1">
                Pemantauan Prestasi Pengeluaran Mingguan Slitting (Kitaran Slot Masa 7:01 AM - 7:00 AM)
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-outline-primary fw-semibold px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#targetConfigModal">
                <i class="bi bi-sliders me-1"></i> Tetapan Sasaran Syif
            </button>
            <button type="button" class="btn btn-primary fw-semibold px-3 shadow-sm" onclick="loadOfficerData()">
                <i class="bi bi-arrow-clockwise me-1"></i> Muat Semula
            </button>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════ -->
    <!-- SECTION 1: KPI OVERVIEW CARDS                                    -->
    <!-- ════════════════════════════════════════════════════════════════ -->
    <div class="row g-3 mb-4">
        <!-- 1-Shift Target -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card officer-card h-100 bg-white p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="text-muted small fw-bold text-uppercase">Sasaran 1 Syif (8 Jam)</span>
                    <span class="p-2 rounded-circle bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-clock-history fs-5"></i>
                    </span>
                </div>
                <div class="mt-2">
                    <div class="stat-number text-dark" id="displayShiftTarget"><?php echo number_format($shiftTarget, 0); ?> m</div>
                    <div class="small text-muted mt-1">
                        <i class="bi bi-info-circle me-1"></i> Purata pengeluaran per syif
                    </div>
                </div>
            </div>
        </div>

        <!-- 24-Hour Target -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card officer-card h-100 bg-white p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="text-muted small fw-bold text-uppercase">Sasaran Harian (24 Jam)</span>
                    <span class="p-2 rounded-circle bg-info bg-opacity-10 text-info">
                        <i class="bi bi-calendar-day fs-5"></i>
                    </span>
                </div>
                <div class="mt-2">
                    <div class="stat-number text-dark" id="displayDailyTarget"><?php echo number_format($dailyTarget, 0); ?> m</div>
                    <div class="small text-muted mt-1">
                        <i class="bi bi-stack me-1"></i> 3 Syif x <?php echo number_format($shiftTarget, 0); ?> m
                    </div>
                </div>
            </div>
        </div>

        <!-- Weekly Target -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card officer-card h-100 bg-white p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="text-muted small fw-bold text-uppercase">Sasaran Mingguan (7 Hari)</span>
                    <span class="p-2 rounded-circle bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-calendar-week fs-5"></i>
                    </span>
                </div>
                <div class="mt-2">
                    <div class="stat-number text-dark" id="displayWeeklyTarget"><?php echo number_format($weeklyTarget, 0); ?> m</div>
                    <div class="small text-muted mt-1">
                        <i class="bi bi-calculator me-1"></i> 7 Hari (21 Syif Total)
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Weekly Produced Hero Card -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card officer-card stat-hero h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="text-light small fw-bold text-uppercase">Pencapaian Minggu Ini</span>
                    <span class="badge bg-success text-white px-2 py-1 fs-7" id="weeklyOverallBadge">0.0%</span>
                </div>
                <div class="mt-2">
                    <div class="stat-number text-cyan" id="displayWeeklyProduced">0 m</div>
                    <div class="progress rounded-pill my-2" style="height: 10px; background: rgba(255,255,255,0.15);">
                        <div class="progress-bar bg-success rounded-pill" id="weeklyProgressBar" style="width: 0%;"></div>
                    </div>
                    <div class="small text-slate-300" id="weeklyCycleRange">Isnin 7:01 AM &rarr; Ahad 7:00 AM</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════ -->
    <!-- SECTION 2: CHART & WEEKLY TIME SLOTS TABLE                       -->
    <!-- ════════════════════════════════════════════════════════════════ -->
    <div class="row g-4 mb-4">
        <!-- Visual Chart -->
        <div class="col-12 col-xl-5">
            <div class="card officer-card h-100">
                <div class="card-header bg-white border-bottom p-3">
                    <h5 class="fw-bold m-0 text-dark">
                        <i class="bi bi-bar-chart-line text-primary me-2"></i> Graf Pencapaian Slot Harian
                    </h5>
                </div>
                <div class="card-body p-3 d-flex align-items-center justify-content-center">
                    <canvas id="weeklyPerformanceChart" style="max-height: 380px; width: 100%;"></canvas>
                </div>
            </div>
        </div>

        <!-- Detailed Table -->
        <div class="col-12 col-xl-7">
            <div class="card officer-card h-100">
                <div class="card-header bg-white border-bottom p-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold m-0 text-dark">
                        <i class="bi bi-table text-success me-2"></i> Jadual Prestasi Slot Masa Mingguan
                    </h5>
                    <span class="badge bg-secondary rounded-pill" id="currentCycleBadge">Kitaran Aktif</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle slot-table m-0">
                            <thead>
                                <tr>
                                    <th>Hari</th>
                                    <th>Slot Masa (7:01 AM - 7:00 AM)</th>
                                    <th class="text-end">Hasil (m)</th>
                                    <th class="text-end">Sasaran (m)</th>
                                    <th class="text-end">Varian (m)</th>
                                    <th class="text-center">Pencapaian (%)</th>
                                </tr>
                            </thead>
                            <tbody id="weeklySlotsTableBody">
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        <div class="spinner-border spinner-border-sm me-2 text-primary"></div> Muat turun data slot mingguan...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════ -->
    <!-- SECTION 3: AUTOMATION & RESET CONTROL PANEL                     -->
    <!-- ════════════════════════════════════════════════════════════════ -->
    <div class="card officer-card bg-white p-4">
        <div class="row align-items-center g-3">
            <div class="col-12 col-md-8">
                <div class="d-flex align-items-start gap-3">
                    <div class="p-3 bg-primary bg-opacity-10 text-primary rounded-3">
                        <i class="bi bi-clock-history fs-3"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold text-dark mb-1">Pengurusan Automasi Auto-Delete Mingguan (Cron Job)</h5>
                        <p class="text-muted small mb-1">
                            Sistem secara automatik disetkan dengan tugasan Cron Job untuk membersihkan kitaran prestasi mingguan pada <strong>Setiap Hari Isnin jam 11:00 Malam (23:00)</strong> untuk memulakan minggu pengeluaran baharu yang bersih.
                        </p>
                        <div class="small text-secondary">
                            <i class="bi bi-check-circle-fill text-success me-1"></i> Tarikh / Masa Reset Terakhir: <strong id="lastResetTimestamp" class="text-dark">-</strong>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4 text-md-end">
                <button type="button" class="btn btn-outline-danger fw-semibold px-3 py-2" onclick="confirmManualReset()">
                    <i class="bi bi-trash3 me-1"></i> Reset Manual Kitaran Mingguan
                </button>
            </div>
        </div>
    </div>

</div>

<!-- ════════════════════════════════════════════════════════════════ -->
<!-- MODAL: SHIFT TARGET CONFIGURATION                                -->
<!-- ════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="targetConfigModal" tabindex="-1" aria-labelledby="targetConfigModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white p-3">
                <h5 class="modal-title fw-bold" id="targetConfigModalLabel">
                    <i class="bi bi-sliders me-2"></i> Tetapan Sasaran Purata Syif
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="targetConfigForm" onsubmit="saveShiftTarget(event)">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="inputShiftTarget" class="form-label fw-bold">Sasaran Purata Pengeluaran 1 Syif (Meter / 8 Jam)</label>
                        <div class="input-group">
                            <input type="number" step="100" min="100" class="form-control form-control-lg fw-bold text-primary" id="inputShiftTarget" value="<?php echo (int)$shiftTarget; ?>" required>
                            <span class="input-group-text fw-bold">meter</span>
                        </div>
                        <div class="form-text mt-2">
                            Nilai rujukan piawai: <strong>5,200 meter</strong> per syif 8 jam.
                        </div>
                    </div>

                    <div class="p-3 rounded-3 bg-light border">
                        <div class="small text-muted fw-semibold mb-2">Pengiraan Dinamik Berdasarkan Tetapan Atas:</div>
                        <div class="d-flex justify-content-between small mb-1">
                            <span>Sasaran Harian (24 Jam / 3 Syif):</span>
                            <strong id="previewDailyTarget">15,600 m</strong>
                        </div>
                        <div class="d-flex justify-content-between small">
                            <span>Sasaran Mingguan (7 Hari / 21 Syif):</span>
                            <strong id="previewWeeklyTarget" class="text-primary">109,200 m</strong>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light p-3">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold" id="saveTargetBtn">Simpan Tetapan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let perfChart = null;

document.addEventListener('DOMContentLoaded', () => {
    initChart();
    loadOfficerData();

    // Auto calculate previews on target input change
    const targetInput = document.getElementById('inputShiftTarget');
    if (targetInput) {
        targetInput.addEventListener('input', (e) => {
            const val = parseFloat(e.target.value) || 0;
            const d = Math.round(val * 3);
            const w = Math.round(val * 3 * 7);
            document.getElementById('previewDailyTarget').innerText = d.toLocaleString() + ' m';
            document.getElementById('previewWeeklyTarget').innerText = w.toLocaleString() + ' m';
        });
    }
});

// Initialize Chart.js
function initChart() {
    const ctx = document.getElementById('weeklyPerformanceChart');
    if (!ctx) return;

    perfChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Isnin', 'Selasa', 'Rabu', 'Khamis', 'Jumaat', 'Sabtu', 'Ahad'],
            datasets: [
                {
                    label: 'Hasil Pengeluaran (m)',
                    data: [0, 0, 0, 0, 0, 0, 0],
                    backgroundColor: 'rgba(2, 132, 199, 0.85)',
                    borderColor: '#0284c7',
                    borderWidth: 1,
                    borderRadius: 6
                },
                {
                    label: 'Sasaran Harian (m)',
                    data: [0, 0, 0, 0, 0, 0, 0],
                    type: 'line',
                    borderColor: '#ef4444',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    fill: false,
                    pointStyle: 'circle',
                    pointRadius: 4,
                    pointBackgroundColor: '#ef4444'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { font: { weight: 'bold' } }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + Math.round(context.raw).toLocaleString() + ' m';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) { return value.toLocaleString() + ' m'; }
                    }
                }
            }
        }
    });
}

// Load data from officer_production_ajax.php
function loadOfficerData() {
    fetch('officer_production_ajax.php?action=get_data')
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                alert(data.message || 'Gagal memuat turun data.');
                return;
            }

            // Update KPI Display Numbers
            const st = data.shift_target_meters || 5200;
            const dt = data.daily_target_meters || (st * 3);
            const wt = data.weekly_target_meters || (dt * 7);

            document.getElementById('displayShiftTarget').innerText = Math.round(st).toLocaleString() + ' m';
            document.getElementById('displayDailyTarget').innerText = Math.round(dt).toLocaleString() + ' m';
            document.getElementById('displayWeeklyTarget').innerText = Math.round(wt).toLocaleString() + ' m';
            document.getElementById('lastResetTimestamp').innerText = data.last_weekly_reset_at || '-';

            const perf = data.weekly_performance;
            if (perf) {
                const prodTotal = perf.weekly_produced_total || 0;
                const overallPct = perf.weekly_overall_pct || 0;

                document.getElementById('displayWeeklyProduced').innerText = Math.round(prodTotal).toLocaleString() + ' m';
                
                const badge = document.getElementById('weeklyOverallBadge');
                if (badge) {
                    badge.innerText = overallPct.toFixed(1) + '%';
                    badge.className = overallPct >= 90 ? 'badge bg-success text-white px-2 py-1 fs-7' : (overallPct >= 50 ? 'badge bg-warning text-dark px-2 py-1 fs-7' : 'badge bg-info text-white px-2 py-1 fs-7');
                }

                const pbar = document.getElementById('weeklyProgressBar');
                if (pbar) {
                    pbar.style.width = Math.min(100, Math.max(0, overallPct)) + '%';
                }

                renderSlotsTable(perf.slots);
                updateChartData(perf.slots);
            }
        })
        .catch(err => {
            console.error('Ralat muat turun data:', err);
        });
}

// Render Slots Table
function renderSlotsTable(slots) {
    const tbody = document.getElementById('weeklySlotsTableBody');
    if (!tbody || !slots) return;

    if (slots.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">Tiada data rekod slot mingguan.</td></tr>';
        return;
    }

    let html = '';
    slots.forEach(slot => {
        const trClass = slot.is_today ? 'bg-today-highlight' : '';
        const todayBadge = slot.is_today ? '<span class="badge bg-info text-dark ms-2">HARI INI</span>' : '';
        
        const varianceVal = slot.variance_meters;
        const varianceFormatted = (varianceVal > 0 ? '+' : '') + Math.round(varianceVal).toLocaleString() + ' m';
        const varianceClass = varianceVal >= 0 ? 'text-success fw-bold' : 'text-danger fw-bold';

        const pct = slot.percentage;
        const pctBadgeClass = pct >= 100 ? 'bg-success' : (pct >= 50 ? 'bg-warning text-dark' : 'bg-secondary');

        html += `
            <tr class="${trClass}">
                <td class="fw-bold text-dark">
                    ${slot.day_name} ${todayBadge}
                </td>
                <td class="small text-muted">
                    <i class="bi bi-clock me-1"></i> ${slot.time_slot_label}
                </td>
                <td class="text-end fw-bold text-dark">${Math.round(slot.produced_meters).toLocaleString()} m</td>
                <td class="text-end text-muted">${Math.round(slot.target_meters).toLocaleString()} m</td>
                <td class="text-end ${varianceClass}">${varianceFormatted}</td>
                <td class="text-center">
                    <span class="badge ${pctBadgeClass} px-2 py-1 fs-7">${pct.toFixed(1)}%</span>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
}

// Update Chart Data
function updateChartData(slots) {
    if (!perfChart || !slots) return;

    const producedData = slots.map(s => Math.round(s.produced_meters));
    const targetData   = slots.map(s => Math.round(s.target_meters));

    perfChart.data.datasets[0].data = producedData;
    perfChart.data.datasets[1].data = targetData;
    perfChart.update();
}

// Save Shift Target via AJAX
function saveShiftTarget(e) {
    e.preventDefault();
    const inputVal = document.getElementById('inputShiftTarget').value;
    const btn = document.getElementById('saveTargetBtn');
    btn.disabled = true;

    const formData = new FormData();
    formData.append('target_meters', inputVal);

    fetch('officer_production_ajax.php?action=update_target', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        if (data.success) {
            const modalEl = document.getElementById('targetConfigModal');
            const modalObj = bootstrap.Modal.getInstance(modalEl);
            if (modalObj) modalObj.hide();
            
            loadOfficerData();
            alert(data.message);
        } else {
            alert(data.message || 'Gagal menyimpan sasaran.');
        }
    })
    .catch(err => {
        btn.disabled = false;
        console.error('Ralat simpan sasaran:', err);
    });
}

// Confirm Manual Reset
function confirmManualReset() {
    if (confirm("Adakah anda pasti mahu menetapkan semula (reset) kitaran pengeluaran mingguan sekarang? Action ini akan merekodkan audit reset dalam sistem.")) {
        fetch('officer_production_ajax.php?action=manual_reset', { method: 'POST' })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    loadOfficerData();
                } else {
                    alert(data.message || 'Gagal melaksanakan reset.');
                }
            })
            .catch(err => console.error('Ralat reset manual:', err));
    }
}
</script>

<?php include 'footer.php'; ?>

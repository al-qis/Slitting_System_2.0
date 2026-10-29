<?php
/**
 * customer_track_order_view.php
 * View for Customer Order Tracking Portal
 * Location: /customer_track_order/views/customer_track_order_view.php
 */

// If view is accessed directly without controller, redirect to index.php or invoke controller
if (!isset($model)) {
    if (!headers_sent()) {
        $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
        header("Location: ../index.php" . $qs);
        exit;
    }
    require_once __DIR__ . '/../controllers/CustomerTrackOrderController.php';
    $controller = new CustomerTrackOrderController();
    $controller->index();
    exit;
}

// Defensive fallback initialization
$searchSo             = $searchSo ?? '';
$searchQuery          = $searchQuery ?? '';
$filterCustomer       = $filterCustomer ?? '';
$filterStatus         = $filterStatus ?? '';
$customerList         = is_array($customerList ?? null) ? $customerList : [];
$orderList            = is_array($orderList ?? null) ? $orderList : [];
$orderRolls           = is_array($orderRolls ?? null) ? $orderRolls : [];
$singleOrder          = $singleOrder ?? null;
$totalOrdersCount     = $totalOrdersCount ?? 0;
$deliveredOrdersCount = $deliveredOrdersCount ?? 0;
$inProdOrdersCount    = $inProdOrdersCount ?? 0;
$readyOrdersCount     = $readyOrdersCount ?? 0;
$pageUrl              = $pageUrl ?? 'index.php';
$pathPrefix           = $pathPrefix ?? '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page_title ?? 'Customer Order Tracking - MK Slitting') ?></title>

    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

    <style>
        :root {
            --brand-primary: #003366;
            --brand-accent: #0284c7;
            --brand-dark: #0f172a;
            --brand-bg: #f8fafc;
            --card-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        }

        body {
            background-color: var(--brand-bg);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
            color: #1e293b;
        }

        /* Top Brand Header */
        .portal-header {
            background: linear-gradient(135deg, #002244 0%, #003366 50%, #0369a1 100%);
            color: #ffffff;
            box-shadow: 0 4px 15px rgba(0, 34, 68, 0.2);
        }

        .portal-logo {
            max-height: 48px;
            background: #ffffff;
            padding: 3px;
            border-radius: 6px;
        }

        /* Glass / Card styling */
        .glass-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: var(--card-shadow);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .glass-card:hover {
            box-shadow: 0 8px 28px rgba(0, 0, 0, 0.08);
        }

        /* Search Box */
        .search-hero {
            border-radius: 16px;
            border: 2px solid #e2e8f0;
            padding: 0.5rem 0.75rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.2s;
            background: #fff;
        }
        .search-hero:focus-within {
            border-color: #0284c7;
            box-shadow: 0 0 0 4px rgba(2, 132, 199, 0.15);
        }
        .search-hero input {
            border: none;
            outline: none;
            box-shadow: none;
        }

        /* Customer Pills */
        .cust-pill {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            background: #e2e8f0;
            color: #334155;
            transition: all 0.2s;
        }
        .cust-pill:hover, .cust-pill.active {
            background: var(--brand-primary);
            color: #ffffff;
        }

        /* Step Timeline Tracker */
        .tracking-timeline {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin: 2rem 0;
            padding: 0;
            list-style: none;
        }
        .tracking-timeline::before {
            content: '';
            position: absolute;
            top: 24px;
            left: 5%;
            right: 5%;
            height: 4px;
            background: #e2e8f0;
            z-index: 1;
        }
        .timeline-progress-bar {
            position: absolute;
            top: 24px;
            left: 5%;
            height: 4px;
            background: #10b981;
            z-index: 2;
            transition: width 0.4s ease;
        }
        .timeline-step {
            position: relative;
            z-index: 3;
            text-align: center;
            width: 20%;
        }
        .step-icon-bubble {
            width: 48px;
            height: 48px;
            margin: 0 auto 8px auto;
            border-radius: 50%;
            background: #ffffff;
            border: 3px solid #cbd5e1;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        .timeline-step.completed .step-icon-bubble {
            background: #10b981;
            border-color: #10b981;
            color: #ffffff;
            box-shadow: 0 0 12px rgba(16, 185, 129, 0.4);
        }
        .timeline-step.active .step-icon-bubble {
            background: #0284c7;
            border-color: #0284c7;
            color: #ffffff;
            box-shadow: 0 0 14px rgba(2, 132, 199, 0.5);
            animation: pulse-ring 2s infinite;
        }
        .step-label {
            font-size: 0.85rem;
            font-weight: 700;
            color: #64748b;
        }
        .timeline-step.completed .step-label {
            color: #0f172a;
        }
        .timeline-step.active .step-label {
            color: #0284c7;
        }
        .step-subtext {
            font-size: 0.72rem;
            color: #94a3b8;
        }

        @keyframes pulse-ring {
            0% { transform: scale(1); }
            50% { transform: scale(1.08); }
            100% { transform: scale(1); }
        }

        /* Order Status Badges */
        .badge-delivered-lg {
            background-color: #10b981;
            color: white;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 0.95rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .badge-ready-lg {
            background-color: #0284c7;
            color: white;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 0.95rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .badge-waiting-lg {
            background-color: #f59e0b;
            color: #1e293b;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 0.95rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .badge-production-lg {
            background-color: #64748b;
            color: white;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 0.95rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* Month Section Styling */
        .month-group-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            background: #ffffff;
            transition: all 0.2s ease;
        }
        .month-header-btn {
            background-color: #ffffff;
            transition: background-color 0.2s ease;
            cursor: pointer;
            user-select: none;
        }
        .month-header-btn:hover {
            background-color: #f8fafc;
        }
        .month-header-btn:active {
            background-color: #f1f5f9;
        }

        .pallet-header-btn {
            background-color: #ffffff;
            transition: background-color 0.2s ease;
            cursor: pointer;
            user-select: none;
        }
        .pallet-header-btn:hover {
            background-color: #f8fafc;
        }
        .pallet-header-btn:active {
            background-color: #f1f5f9;
        }

        /* Table Design */
        .table-custom th {
            background-color: #0f172a;
            color: #ffffff;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            vertical-align: middle;
        }
        .table-custom td {
            vertical-align: middle;
            font-size: 0.9rem;
        }

        /* Print Media Stylesheet */
        @media print {
            .month-collapse, .pallet-collapse {
                display: block !important;
                height: auto !important;
                visibility: visible !important;
            }
            .no-print {
                display: none !important;
            }
            body {
                background: #ffffff !important;
                color: #000000 !important;
            }
            .glass-card {
                border: 1px solid #ccc !important;
                box-shadow: none !important;
            }
            .portal-header {
                background: #ffffff !important;
                color: #000000 !important;
                border-bottom: 2px solid #000000;
            }
            .portal-logo {
                filter: grayscale(100%);
            }
            .table-custom th {
                background-color: #eeeeee !important;
                color: #000000 !important;
            }
        }
    </style>
</head>
<body>

    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <!-- PORTAL HEADER                                                           -->
    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <header class="portal-header py-3 px-3 px-md-4 mb-4">
        <div class="container-xl d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <img src="<?= $pathPrefix ?>assets/nichiaslogo.jpg" alt="Nichias Logo" class="portal-logo shadow-sm">
                <div>
                    <h4 class="m-0 fw-bold tracking-tight text-white d-flex align-items-center gap-2">
                        <i class="bi bi-box-seam-fill"></i> Customer Order Tracking
                    </h4>
                    <p class="m-0 text-white-50 small">
                        Real-time Slitting, Palletizing & Delivery Status Portal
                    </p>
                </div>
            </div>

            <!-- Actions -->
            <div class="d-flex align-items-center gap-2 no-print">
                <button type="button" class="btn btn-outline-light btn-sm fw-semibold" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print
                </button>
            </div>
        </div>
    </header>

    <main class="container-xl pb-5">

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- SEARCH & FILTER TOOLBAR                                             -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <section class="card glass-card p-3 p-md-4 mb-4 no-print">
            <form method="GET" action="<?= $pageUrl ?>" class="row g-3 align-items-center">
                <!-- Search Input -->
                <div class="col-12 col-md-6 col-lg-7">
                    <div class="search-hero d-flex align-items-center">
                        <i class="bi bi-search text-muted fs-5 me-2"></i>
                        <input 
                            type="text" 
                            name="search" 
                            class="form-control" 
                            placeholder="Enter Sales Order (e.g. SO-26-1555), Customer Name, or Pallet No..." 
                            value="<?= htmlspecialchars($searchSo ?: $searchQuery) ?>"
                            autocomplete="off"
                            autofocus
                        >
                        <?php if ($searchSo !== '' || $searchQuery !== '' || $filterCustomer !== '' || $filterStatus !== ''): ?>
                            <a href="<?= $pageUrl ?>" class="btn btn-link text-muted p-0 ms-2" title="Clear Search">
                                <i class="bi bi-x-circle-fill fs-5"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Customer Filter -->
                <div class="col-6 col-md-3 col-lg-3">
                    <select name="customer" class="form-select py-2 fw-semibold" onchange="this.form.submit()">
                        <option value="">All Customers</option>
                        <?php foreach ($customerList as $cust): ?>
                            <option value="<?= htmlspecialchars($cust) ?>" <?= ($filterCustomer === $cust) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cust) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Submit Button -->
                <div class="col-6 col-md-3 col-lg-2 d-grid">
                    <button type="submit" class="btn btn-primary py-2 fw-bold shadow-sm">
                        <i class="bi bi-geo-alt me-1"></i> Track Order
                    </button>
                </div>

                <!-- Customer Quick Filter Pills -->
                <div class="col-12 pt-2 border-top">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <small class="text-muted fw-bold me-1 text-uppercase">Quick Filter:</small>
                        <a href="<?= $pageUrl ?>" class="cust-pill <?= ($filterCustomer === '' && $searchSo === '' && $searchQuery === '') ? 'active' : '' ?>">
                            All Orders
                        </a>
                        <?php 
                        // Show top 8 customers as quick pills
                        $topPills = array_slice($customerList, 0, 8);
                        foreach ($topPills as $pill): 
                        ?>
                            <a href="<?= $pageUrl ?>?customer=<?= urlencode($pill) ?>" class="cust-pill <?= ($filterCustomer === $pill) ? 'active' : '' ?>">
                                <?= htmlspecialchars($pill) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </form>
        </section>

        <?php if ($singleOrder !== null): ?>
            <!-- ════════════════════════════════════════════════════════════════ -->
            <!-- SINGLE ORDER DEEP-DIVE VIEW                                      -->
            <!-- ════════════════════════════════════════════════════════════════ -->
            <div class="mb-4 d-flex flex-wrap align-items-center justify-content-between gap-2 no-print">
                <a href="<?= $pageUrl ?><?= $filterCustomer ? '?customer='.urlencode($filterCustomer) : '' ?>" class="btn btn-outline-secondary btn-sm fw-semibold">
                    <i class="bi bi-arrow-left me-1"></i> Back to All Orders
                </a>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm fw-semibold" onclick="copyTrackingLink()">
                        <i class="bi bi-link-45deg me-1"></i> Copy Shareable Tracking Link
                    </button>
                    <button type="button" class="btn btn-primary btn-sm fw-semibold" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i> Print Order Slip
                    </button>
                </div>
            </div>

            <!-- Order Status Banner Card -->
            <div class="card glass-card p-4 mb-4">
                <div class="row align-items-center g-3">
                    <div class="col-12 col-md-8">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <span class="badge bg-dark fs-6 px-3 py-2">
                                <i class="bi bi-receipt me-1"></i> SO NUMBER: <?= htmlspecialchars($singleOrder['ref_no']) ?>
                            </span>
                            <span class="badge bg-secondary fs-6 px-3 py-2">
                                <i class="bi bi-building me-1"></i> Customer: <?= htmlspecialchars($singleOrder['customer_name']) ?>
                            </span>
                        </div>
                        <h3 class="fw-bold m-0 text-dark">
                            Product Spec: <?= htmlspecialchars($singleOrder['products']) ?>
                        </h3>
                        <p class="text-muted small m-0 mt-1">
                            Order Started: <?= $singleOrder['earliest_date'] ? date('d M Y, h:i A', strtotime($singleOrder['earliest_date'])) : '—' ?>
                            <?php if ($singleOrder['latest_delivered']): ?>
                                &bull; Dispatched: <?= date('d M Y, h:i A', strtotime($singleOrder['latest_delivered'])) ?>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="col-12 col-md-4 text-md-end">
                        <?php if ($singleOrder['overall_status'] === 'DELIVERED'): ?>
                            <div class="badge-delivered-lg">
                                <i class="bi bi-check-circle-fill fs-5"></i> DELIVERED & COMPLETED
                            </div>
                        <?php elseif ($singleOrder['overall_status'] === 'PARTIALLY_DELIVERED'): ?>
                            <div class="badge-ready-lg">
                                <i class="bi bi-truck fs-5"></i> PARTIAL DELIVERY (<?= $singleOrder['delivered_rolls'] ?> / <?= $singleOrder['target_rolls'] ?? $singleOrder['planned_rolls'] ?> Rolls)
                            </div>
                        <?php elseif ($singleOrder['overall_status'] === 'READY_FOR_DELIVERY'): ?>
                            <div class="badge-ready-lg">
                                <i class="bi bi-box-seam fs-5"></i> READY FOR DISPATCH / ON PALLET
                            </div>
                        <?php elseif ($singleOrder['overall_status'] === 'WAITING_QC'): ?>
                            <div class="badge-waiting-lg">
                                <i class="bi bi-shield-check fs-5"></i> QC INSPECTION IN PROGRESS
                            </div>
                        <?php else: ?>
                            <div class="badge-production-lg">
                                <i class="bi bi-gear-wide-connected fs-5"></i> IN SLITTING PRODUCTION
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 5-Step Order Progress Timeline -->
                <div class="mt-4 pt-3 border-top">
                    <h6 class="text-muted fw-bold text-uppercase small mb-3">Order Fulfillment Journey</h6>
                    
                    <div class="tracking-timeline">
                        <div class="timeline-progress-bar" style="width: <?= min(100, max(0, ($singleOrder['stage_index'] - 1) * 23.5)) ?>%;"></div>

                        <!-- Step 1: Order Planned -->
                        <div class="timeline-step completed">
                            <div class="step-icon-bubble">
                                <i class="bi bi-clipboard-check"></i>
                            </div>
                            <div class="step-label">Order Planned</div>
                            <div class="step-subtext">SO Confirmed</div>
                            <div class="mt-2">
                                <span class="badge bg-secondary-subtle text-secondary border px-2 py-1" style="font-size: 11px;">
                                    <i class="bi bi-calendar-check me-1"></i><strong><?= $singleOrder['planned_rolls'] ?></strong> Coils Planned
                                </span>
                            </div>
                        </div>

                        <!-- Step 2: Slitting -->
                        <div class="timeline-step <?= ($singleOrder['stage_index'] > 2) ? 'completed' : (($singleOrder['stage_index'] === 2) ? 'active' : '') ?>">
                            <div class="step-icon-bubble">
                                <i class="bi bi-scissors"></i>
                            </div>
                            <div class="step-label">Slitting</div>
                            <div class="step-subtext">Rolls Slit & Tagged</div>
                            <div class="mt-2">
                                <span class="badge <?= ($singleOrder['slit_rolls_cnt'] > 0) ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-light text-muted border' ?> px-2 py-1" style="font-size: 11px;">
                                    <i class="bi bi-scissors me-1"></i><strong><?= $singleOrder['slit_rolls_cnt'] ?></strong> / <?= $singleOrder['target_rolls'] ?? $singleOrder['planned_rolls'] ?> Coils Slit
                                </span>
                            </div>
                        </div>

                        <!-- Step 3: QC Inspection -->
                        <div class="timeline-step <?= ($singleOrder['stage_index'] > 3) ? 'completed' : (($singleOrder['stage_index'] === 3) ? 'active' : '') ?>">
                            <div class="step-icon-bubble">
                                <i class="bi bi-patch-check"></i>
                            </div>
                            <div class="step-label">Quality Check</div>
                            <div class="step-subtext">Verified by QC</div>
                            <div class="mt-2">
                                <span class="badge <?= ($singleOrder['qc_passed_cnt'] > 0) ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-light text-muted border' ?> px-2 py-1" style="font-size: 11px;">
                                    <i class="bi bi-patch-check me-1"></i><strong><?= $singleOrder['qc_passed_cnt'] ?></strong> / <?= $singleOrder['slit_rolls_cnt'] ?> Coils Verified
                                </span>
                            </div>
                        </div>

                        <!-- Step 4: Palletizing -->
                        <div class="timeline-step <?= ($singleOrder['stage_index'] > 4) ? 'completed' : (($singleOrder['stage_index'] === 4) ? 'active' : '') ?>">
                            <div class="step-icon-bubble">
                                <i class="bi bi-archive"></i>
                            </div>
                            <div class="step-label">Palletizing</div>
                            <div class="step-subtext">Packed & Staged</div>
                            <div class="mt-2">
                                <span class="badge <?= ($singleOrder['palletized_cnt'] > 0) ? 'bg-info-subtle text-info-emphasis border border-info-subtle' : 'bg-light text-muted border' ?> px-2 py-1" style="font-size: 11px;">
                                    <i class="bi bi-archive me-1"></i><strong><?= $singleOrder['palletized_cnt'] ?></strong> Coils (<?= count($singleOrder['pallets']) ?> Plt)
                                </span>
                            </div>
                        </div>

                        <!-- Step 5: Delivered -->
                        <div class="timeline-step <?= ($singleOrder['stage_index'] === 5) ? 'completed' : (($singleOrder['overall_status'] === 'PARTIALLY_DELIVERED') ? 'active' : '') ?>">
                            <div class="step-icon-bubble">
                                <i class="bi bi-truck"></i>
                            </div>
                            <div class="step-label"><?= ($singleOrder['overall_status'] === 'PARTIALLY_DELIVERED') ? 'Partial Delivery' : 'Delivered' ?></div>
                            <div class="step-subtext">
                                <?= $singleOrder['latest_delivered'] ? date('d M Y', strtotime($singleOrder['latest_delivered'])) : 'Out for Delivery' ?>
                            </div>
                            <div class="mt-2">
                                <span class="badge <?= ($singleOrder['delivered_rolls'] > 0) ? 'bg-success text-white shadow-sm' : 'bg-light text-muted border' ?> px-2 py-1" style="font-size: 11px;">
                                    <i class="bi bi-truck me-1"></i><strong><?= $singleOrder['delivered_rolls'] ?></strong> / <?= $singleOrder['target_rolls'] ?? $singleOrder['planned_rolls'] ?> Coils
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- KPI Metric Chips -->
                <div class="row g-3 mt-2">
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded bg-light border text-center h-100 d-flex flex-column justify-content-center">
                            <small class="text-muted fw-bold text-uppercase d-block mb-1">Total Quantity</small>
                            <div>
                                <span class="fs-4 fw-bold text-dark"><?= $singleOrder['total_rolls'] ?> / <?= $singleOrder['planned_rolls'] ?></span>
                                <span class="text-muted small">Rolls</span>
                            </div>
                            <?php if (!empty($singleOrder['excess_breakdown'])): ?>
                                <div class="mt-2 pt-1 border-top" style="font-size: 11px;">
                                    <span class="badge bg-warning text-dark border border-warning-subtle" title="Extra produce beyond planned cut">
                                        <i class="bi bi-plus-circle me-1"></i>+<?= $singleOrder['excess_rolls'] ?> Extra: <?= htmlspecialchars($singleOrder['excess_breakdown']) ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded bg-light border text-center">
                            <small class="text-muted fw-bold text-uppercase d-block mb-1">Total Length</small>
                            <span class="fs-4 fw-bold text-dark"><?= number_format($singleOrder['total_meters'], 1) ?></span>
                            <span class="text-muted small">Meters (m)</span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded bg-light border text-center">
                            <small class="text-muted fw-bold text-uppercase d-block mb-1">Delivered Rolls</small>
                            <span class="fs-4 fw-bold text-success"><?= $singleOrder['delivered_rolls'] ?></span>
                            <span class="text-muted small">/ <?= $singleOrder['target_rolls'] ?? $singleOrder['planned_rolls'] ?> Rolls</span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded bg-light border text-center h-100 d-flex flex-column justify-content-center"
                             <?php if (!empty($singleOrder['pallets'])): ?>
                                 role="button"
                                 style="cursor: pointer;"
                                 onclick="togglePalletCollapse()"
                                 title="Click to toggle pallets dropdown"
                             <?php endif; ?>>
                            <small class="text-muted fw-bold text-uppercase d-block mb-1">Pallet Units</small>
                            <span class="fs-4 fw-bold text-primary"><?= count($singleOrder['pallets']) ?></span>
                            <span class="text-muted small">
                                Pallets Assigned
                                <?php if (!empty($singleOrder['pallets'])): ?>
                                    <i class="bi bi-chevron-down ms-1 text-primary"></i>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pallet Summary Box (Collapsible Dropdown) -->
            <?php if (!empty($singleOrder['pallets'])): 
                $totalPltCount = count($singleOrder['pallets']);
                $delPltCount = 0;
                $appPltCount = 0;
                $packPltCount = 0;
                $totalPltRolls = 0;
                foreach ($singleOrder['pallets'] as $pCheck) {
                    $totalPltRolls += (int)($pCheck['roll_count'] ?? 0);
                    if (($pCheck['status'] ?? '') === 'delivered') {
                        $delPltCount++;
                    } elseif (($pCheck['status'] ?? '') === 'approved') {
                        $appPltCount++;
                    } else {
                        $packPltCount++;
                    }
                }
            ?>
                <div class="card glass-card mb-4 overflow-hidden border">
                    <div class="pallet-header-btn py-3 px-3 px-md-4 d-flex flex-wrap align-items-center justify-content-between gap-3"
                         data-bs-toggle="collapse"
                         data-bs-target="#assignedPalletsCollapse"
                         aria-expanded="false"
                         aria-controls="assignedPalletsCollapse">
                        
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-primary-subtle text-primary p-2 rounded-3 d-flex align-items-center justify-content-center shadow-sm" style="width: 44px; height: 44px;">
                                <i class="bi bi-boxes fs-4"></i>
                            </div>
                            <div>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <h5 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.3px;">
                                        Assigned Pallets & Dispatch Units
                                    </h5>
                                    <span class="badge bg-primary text-white px-2 py-1" style="font-size: 11px;">
                                        <?= $totalPltCount ?> Pallet<?= $totalPltCount > 1 ? 's' : '' ?>
                                    </span>
                                    <?php if ($delPltCount > 0): ?>
                                        <span class="badge bg-success text-white px-2 py-1" style="font-size: 11px;">
                                            <i class="bi bi-check-circle-fill me-1"></i><?= $delPltCount ?> Delivered
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($appPltCount > 0): ?>
                                        <span class="badge bg-info text-dark px-2 py-1" style="font-size: 11px;">
                                            <i class="bi bi-box-seam me-1"></i><?= $appPltCount ?> Ready
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted small mt-1">
                                    Total <strong><?= $totalPltRolls ?></strong> rolls loaded across <?= $totalPltCount ?> pallet units &bull; <span class="text-primary fw-semibold">Click dropdown to view details</span>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-2 no-print ms-auto">
                            <button type="button" class="btn btn-sm btn-outline-primary fw-semibold px-3 py-1 pallet-toggle-btn" tabindex="-1">
                                <span class="pallet-toggle-label">View Pallets</span>
                                <i class="bi bi-chevron-down ms-1 pallet-toggle-icon"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Collapsible Pallet Grid (Dropdown) -->
                    <div id="assignedPalletsCollapse" class="collapse pallet-collapse">
                        <div class="card-body p-3 p-md-4 border-top bg-light-subtle">
                            <div class="row g-3" id="palletCardsGrid">
                                <?php foreach ($singleOrder['pallets'] as $plt): ?>
                                    <div class="col-12 col-md-6 col-lg-4 pallet-card-item" data-pallet-no="<?= htmlspecialchars(strtolower($plt['pallet_no'])) ?>" id="pallet-item-<?= htmlspecialchars(preg_replace('/[^a-zA-Z0-9_\-]/', '_', $plt['pallet_no'])) ?>">
                                        <div class="p-3 border rounded-3 bg-white h-100 shadow-sm">
                                            <div class="d-flex align-items-center justify-content-between mb-2">
                                                <span class="fw-bold text-dark fs-6"><?= htmlspecialchars($plt['pallet_no']) ?></span>
                                                <?php if ($plt['status'] === 'delivered'): ?>
                                                    <span class="badge bg-success">Delivered</span>
                                                <?php elseif ($plt['status'] === 'approved'): ?>
                                                    <span class="badge bg-info text-dark">Approved</span>
                                                <?php elseif ($plt['status'] === 'pending_qc'): ?>
                                                    <span class="badge bg-warning text-dark">QC Check</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Packing</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="small text-muted">
                                                <div><i class="bi bi-collection me-1"></i> Rolls Loaded: <strong><?= $plt['roll_count'] ?></strong></div>
                                                <?php if ($plt['delivered_at']): ?>
                                                    <div><i class="bi bi-clock-history me-1"></i> Delivered: <?= date('d M Y, h:i A', strtotime($plt['delivered_at'])) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php
                // Pre-calculate status counts for filter buttons
                $statusCounts = [
                    'all'         => count($orderRolls),
                    'delivered'   => 0,
                    'approved'    => 0,
                    'waiting'     => 0,
                    'finish_good' => 0,
                    'in_slitting' => 0,
                    'planned'     => 0,
                ];
                foreach ($orderRolls as $r) {
                    if (!empty($r['is_planned_only'])) {
                        $statusCounts['planned']++;
                    } else {
                        $s = strtoupper($r['status'] ?? 'IN');
                        $sc = (int)($r['stock_counted'] ?? 0);
                        if ($s === 'DELIVERED') {
                            $statusCounts['delivered']++;
                        } elseif ($s === 'APPROVED') {
                            $statusCounts['approved']++;
                        } elseif ($s === 'WAITING') {
                            $statusCounts['waiting']++;
                        } elseif ($sc === 1) {
                            $statusCounts['finish_good']++;
                        } else {
                            $statusCounts['in_slitting']++;
                        }
                    }
                }
            ?>

            <!-- Roll Breakdown Table -->
            <div class="card glass-card p-3 p-md-4 mb-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <h5 class="fw-bold m-0 d-flex align-items-center gap-2">
                        <i class="bi bi-list-check text-primary"></i> Order Itemized Rolls Breakdown
                    </h5>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <?php if (!empty($singleOrder['planning_rolls_cnt'])): ?>
                            <span class="badge text-white border px-2 py-1 shadow-sm" style="background-color: #7c3aed;" title="Planned coils pending slitting">
                                <i class="bi bi-clock-history me-1"></i>Planning: <strong><?= $singleOrder['planning_rolls_cnt'] ?></strong>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($singleOrder['initial_stock_cnt'])): ?>
                            <span class="badge bg-secondary-subtle text-secondary border px-2 py-1" title="Rolls from initial ready stock">
                                <i class="bi bi-box-seam me-1"></i>Initial Stock: <strong><?= $singleOrder['initial_stock_cnt'] ?></strong>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($singleOrder['recoil_cnt'])): ?>
                            <span class="badge bg-info-subtle text-info-emphasis border px-2 py-1" title="Rolls from recoiling">
                                <i class="bi bi-arrow-repeat me-1"></i>Recoil: <strong><?= $singleOrder['recoil_cnt'] ?></strong>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($singleOrder['reslit_cnt'])): ?>
                            <span class="badge bg-warning-subtle text-warning-emphasis border px-2 py-1" title="Rolls from reslitting">
                                <i class="bi bi-scissors me-1"></i>Reslit: <strong><?= $singleOrder['reslit_cnt'] ?></strong>
                            </span>
                        <?php endif; ?>
                        <span class="text-muted small fw-semibold">Total: <?= count($orderRolls) ?> Rolls (<?= $singleOrder['total_rolls'] ?> Produced<?= !empty($singleOrder['planning_rolls_cnt']) ? ' + ' . $singleOrder['planning_rolls_cnt'] . ' Planned' : '' ?>)</span>
                    </div>
                </div>

                <!-- Status Filter Toolbar -->
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 p-2 px-3 mb-3 bg-light bg-opacity-75 rounded-3 border">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="small fw-bold text-muted text-uppercase me-1 d-flex align-items-center gap-1">
                            <i class="bi bi-funnel"></i> Status Filter:
                        </span>
                        <div class="d-flex flex-wrap align-items-center gap-1" id="rollStatusFilterGroup">
                            <button type="button" class="btn btn-sm btn-dark roll-filter-btn active shadow-sm" data-filter="all" onclick="filterRollTable('all', this)">
                                All <span class="badge bg-light text-dark ms-1"><?= count($orderRolls) ?></span>
                            </button>
                            <?php if ($statusCounts['delivered'] > 0): ?>
                                <button type="button" class="btn btn-sm btn-outline-success roll-filter-btn" data-filter="delivered" onclick="filterRollTable('delivered', this)">
                                    <i class="bi bi-check-circle me-1"></i>Delivered <span class="badge bg-success text-white ms-1"><?= $statusCounts['delivered'] ?></span>
                                </button>
                            <?php endif; ?>
                            <?php if ($statusCounts['approved'] > 0): ?>
                                <button type="button" class="btn btn-sm btn-outline-info text-dark roll-filter-btn" data-filter="approved" onclick="filterRollTable('approved', this)">
                                    <i class="bi bi-patch-check me-1"></i>Approved <span class="badge bg-info text-dark ms-1"><?= $statusCounts['approved'] ?></span>
                                </button>
                            <?php endif; ?>
                            <?php if ($statusCounts['waiting'] > 0): ?>
                                <button type="button" class="btn btn-sm btn-outline-warning text-dark roll-filter-btn" data-filter="waiting" onclick="filterRollTable('waiting', this)">
                                    <i class="bi bi-clock me-1"></i>Waiting QC <span class="badge bg-warning text-dark ms-1"><?= $statusCounts['waiting'] ?></span>
                                </button>
                            <?php endif; ?>
                            <?php if ($statusCounts['finish_good'] > 0): ?>
                                <button type="button" class="btn btn-sm btn-outline-primary roll-filter-btn" data-filter="finish_good" onclick="filterRollTable('finish_good', this)">
                                    <i class="bi bi-check2-all me-1"></i>Finish Good <span class="badge bg-primary text-white ms-1"><?= $statusCounts['finish_good'] ?></span>
                                </button>
                            <?php endif; ?>
                            <?php if ($statusCounts['in_slitting'] > 0): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary roll-filter-btn" data-filter="in_slitting" onclick="filterRollTable('in_slitting', this)">
                                    <i class="bi bi-gear me-1"></i>In Slitting <span class="badge bg-secondary text-white ms-1"><?= $statusCounts['in_slitting'] ?></span>
                                </button>
                            <?php endif; ?>
                            <?php if ($statusCounts['planned'] > 0): ?>
                                <button type="button" class="btn btn-sm roll-filter-btn btn-outline-purple" data-filter="planned" onclick="filterRollTable('planned', this)" style="border-color: #7c3aed; color: #7c3aed;">
                                    <i class="bi bi-hourglass-split me-1"></i>Planned <span class="badge text-white ms-1" style="background-color: #7c3aed;"><?= $statusCounts['planned'] ?></span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="small text-muted fw-semibold" id="rollFilterSummaryText">
                        Showing all <span class="text-dark fw-bold" id="rollVisibleCount"><?= count($orderRolls) ?></span> rolls
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover table-custom align-middle">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Roll No</th>
                                <th>Lot & Coil</th>
                                <th>Product Spec</th>
                                <th>Width</th>
                                <th>Length</th>
                                <th>Nod</th>
                                <th>Stage / Status</th>
                                <th>Pallet No</th>
                                <th>Delivery Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orderRolls as $idx => $roll): 
                                $isPlanOnly = !empty($roll['is_planned_only']);
                                $st = strtoupper($roll['status'] ?? 'IN');
                                $sc = (int)($roll['stock_counted'] ?? 0);
                                $isInit = (empty($roll['mother_id']) || ($roll['original_source'] ?? '') === 'initial_stock');
                                $isRec  = (($roll['source'] ?? '') === 'recoiling' || !empty($roll['recoiling_id']) || ($roll['cut_type'] ?? '') === 'cut_into_2');
                                $isRes  = (($roll['source'] ?? '') === 'reslit' || !empty($roll['is_reslitted']));

                                if ($isPlanOnly) {
                                    $rowStatus = 'planned';
                                } elseif ($st === 'DELIVERED') {
                                    $rowStatus = 'delivered';
                                } elseif ($st === 'APPROVED') {
                                    $rowStatus = 'approved';
                                } elseif ($st === 'WAITING') {
                                    $rowStatus = 'waiting';
                                } elseif ($sc === 1) {
                                    $rowStatus = 'finish_good';
                                } else {
                                    $rowStatus = 'in_slitting';
                                }

                                $rowStyle = '';
                                if ($isPlanOnly) {
                                    $rowStyle = 'border-left: 4px solid #7c3aed; background-color: rgba(124, 58, 237, 0.08);';
                                } elseif ($isInit) {
                                    $rowStyle = 'border-left: 4px solid #6c757d; background-color: rgba(108, 117, 125, 0.05);';
                                } elseif ($isRec) {
                                    $rowStyle = 'border-left: 4px solid #0284c7; background-color: rgba(2, 132, 199, 0.06);';
                                } elseif ($isRes) {
                                    $rowStyle = 'border-left: 4px solid #f59e0b; background-color: rgba(245, 158, 11, 0.06);';
                                }
                            ?>
                                <tr class="roll-row" data-roll-status="<?= $rowStatus ?>" style="<?= $rowStyle ?>">
                                    <td class="text-muted roll-index-cell"><?= $idx + 1 ?></td>
                                    <td class="fw-bold text-dark">
                                        <?= $model->formatRollNo($roll['roll_no']) ?>
                                        <?php if ($isPlanOnly): ?>
                                            <span class="badge text-white ms-1" style="background-color: #7c3aed; font-size: 10px;">Planned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="fw-semibold text-secondary"><?= htmlspecialchars($roll['lot_no'] ?: '—') ?></span>
                                        <small class="text-muted d-block">
                                            Coil: 
                                            <?php if ($isPlanOnly): ?>
                                                <strong style="color: #7c3aed;"><?= htmlspecialchars($roll['coil_no'] ?: '—') ?></strong>
                                            <?php elseif ($isInit): ?>
                                                <strong class="text-secondary"><?= htmlspecialchars($roll['coil_no'] ?: '—') ?></strong>
                                            <?php elseif ($isRec): ?>
                                                <strong class="text-info-emphasis"><?= htmlspecialchars($roll['coil_no'] ?: '—') ?></strong>
                                            <?php elseif ($isRes): ?>
                                                <strong class="text-warning-emphasis"><?= htmlspecialchars($roll['coil_no'] ?: '—') ?></strong>
                                            <?php else: ?>
                                                <strong><?= htmlspecialchars($roll['coil_no'] ?: '—') ?></strong>
                                            <?php endif; ?>
                                        </small>
                                        <?php if ($isPlanOnly): ?>
                                            <span class="badge text-white mt-1 shadow-sm px-2 py-1" style="background-color: #7c3aed; font-size: 10px;">
                                                <i class="bi bi-clock-history me-1"></i>Planning Coil (Mother #<?= $roll['mother_id'] ?>)
                                            </span>
                                        <?php elseif ($isInit): ?>
                                            <span class="badge bg-secondary text-white mt-1 shadow-sm px-2 py-1" style="font-size: 10px;">
                                                <i class="bi bi-box-seam me-1"></i>Initial Stock
                                            </span>
                                        <?php elseif ($isRec): ?>
                                            <span class="badge bg-info text-dark mt-1 shadow-sm px-2 py-1" style="font-size: 10px;">
                                                <i class="bi bi-arrow-repeat me-1"></i>Recoil
                                            </span>
                                        <?php elseif ($isRes): ?>
                                            <span class="badge bg-warning text-dark mt-1 shadow-sm px-2 py-1" style="font-size: 10px;">
                                                <i class="bi bi-scissors me-1"></i>Reslit
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-dark"><?= htmlspecialchars($roll['product'] ?: '—') ?></span>
                                        <?php if (!empty($roll['grade'])): ?>
                                            <small class="badge bg-light text-muted border ms-1"><?= htmlspecialchars($roll['grade']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= number_format((float)$roll['width'], 1) ?> mm</td>
                                    <td><?= number_format((float)($roll['actual_length'] ?: $roll['length']), 1) ?> m</td>
                                    <td>
                                        <?php if (!empty($roll['nod_length']) && (float)$roll['nod_length'] > 0): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1 fw-semibold" title="Notice of Defect: <?= number_format((float)$roll['nod_length'], 1) ?> m">
                                                <i class="bi bi-exclamation-triangle me-1"></i><?= number_format((float)$roll['nod_length'], 1) ?> m
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isPlanOnly): ?>
                                            <span class="badge text-white shadow-sm" style="background-color: #7c3aed;">
                                                <i class="bi bi-hourglass-split me-1"></i> Planned (Pending Cut)
                                            </span>
                                        <?php elseif ($st === 'DELIVERED'): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Delivered</span>
                                        <?php elseif ($st === 'APPROVED'): ?>
                                            <span class="badge bg-info text-dark"><i class="bi bi-patch-check me-1"></i> Approved</span>
                                        <?php elseif ($st === 'WAITING'): ?>
                                            <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i> Waiting QC</span>
                                        <?php elseif ($sc === 1): ?>
                                            <span class="badge bg-primary"><i class="bi bi-check2-all me-1"></i> Finish Good</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="bi bi-gear me-1"></i> In Slitting</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isPlanOnly): ?>
                                            <span class="text-muted small"><i class="bi bi-dash-circle me-1"></i>Not Slit Yet</span>
                                        <?php elseif (!empty($roll['pallet_no'])): ?>
                                            <span class="badge bg-light text-dark border fw-semibold">
                                                <i class="bi bi-archive me-1"></i> <?= htmlspecialchars($roll['pallet_no']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">Not Palletized</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isPlanOnly): ?>
                                            <span class="text-muted small">—</span>
                                        <?php elseif (!empty($roll['delivered_at'])): ?>
                                            <span class="text-success fw-semibold small">
                                                <i class="bi bi-check-lg me-1"></i> <?= date('d M Y', strtotime($roll['delivered_at'])) ?>
                                            </span>
                                        <?php elseif ($st === 'DELIVERED'): ?>
                                            <span class="text-success fw-semibold small">Delivered</span>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="noRollsMatchRow" style="display: none;">
                                <td colspan="10" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-3 d-block mb-1 text-secondary"></i>
                                    <span>No rolls found matching this status filter.</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php else: ?>
            <!-- ════════════════════════════════════════════════════════════════ -->
            <!-- ORDER BROWSER / SUMMARY LIST VIEW                                -->
            <!-- ════════════════════════════════════════════════════════════════ -->

            <!-- KPI Metric Summary Row -->
            <div class="row g-3 mb-4 no-print">
                <div class="col-6 col-md-3">
                    <div class="card glass-card p-3 text-center">
                        <small class="text-muted fw-bold text-uppercase d-block mb-1">Orders Found</small>
                        <span class="fs-3 fw-bold text-dark"><?= count($orderList) ?></span>
                        <span class="text-muted small">Active SOs</span>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card glass-card p-3 text-center">
                        <small class="text-muted fw-bold text-uppercase d-block mb-1">In Production</small>
                        <span class="fs-3 fw-bold text-primary"><?= $inProdOrdersCount ?></span>
                        <span class="text-muted small">Processing</span>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card glass-card p-3 text-center">
                        <small class="text-muted fw-bold text-uppercase d-block mb-1">Ready / On Pallet</small>
                        <span class="fs-3 fw-bold text-info"><?= $readyOrdersCount ?></span>
                        <span class="text-muted small">Ready to Ship</span>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card glass-card p-3 text-center">
                        <small class="text-muted fw-bold text-uppercase d-block mb-1">Delivered</small>
                        <span class="fs-3 fw-bold text-success"><?= $deliveredOrdersCount ?></span>
                        <span class="text-muted small">Completed</span>
                    </div>
                </div>
            </div>

            <!-- Orders Table Card -->
            <div class="card glass-card p-3 p-md-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                    <div>
                        <h5 class="fw-bold m-0 text-dark">
                            <i class="bi bi-card-checklist text-primary me-2"></i> Track Customer Orders
                        </h5>
                        <p class="text-muted small m-0 mt-1">
                            Showing customer sales orders, roll completion, and delivery progress. Click any order to view full tracking details.
                        </p>
                    </div>

                    <!-- Status Filter Dropdown / Buttons -->
                    <div class="no-print">
                        <div class="btn-group btn-group-sm" role="group">
                            <a href="<?= $pageUrl ?>?customer=<?= urlencode($filterCustomer) ?>&search=<?= urlencode($searchQuery) ?>" class="btn <?= ($filterStatus === '') ? 'btn-dark' : 'btn-outline-secondary' ?>">
                                All
                            </a>
                            <a href="<?= $pageUrl ?>?customer=<?= urlencode($filterCustomer) ?>&search=<?= urlencode($searchQuery) ?>&status=in_production" class="btn <?= ($filterStatus === 'in_production') ? 'btn-dark' : 'btn-outline-secondary' ?>">
                                In Prod
                            </a>
                            <a href="<?= $pageUrl ?>?customer=<?= urlencode($filterCustomer) ?>&search=<?= urlencode($searchQuery) ?>&status=approved" class="btn <?= ($filterStatus === 'approved') ? 'btn-dark' : 'btn-outline-secondary' ?>">
                                Ready
                            </a>
                            <a href="<?= $pageUrl ?>?customer=<?= urlencode($filterCustomer) ?>&search=<?= urlencode($searchQuery) ?>&status=delivered" class="btn <?= ($filterStatus === 'delivered') ? 'btn-dark' : 'btn-outline-secondary' ?>">
                                Delivered
                            </a>
                        </div>
                    </div>
                </div>

                <?php if (empty($orderList)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-search text-muted" style="font-size: 3rem;"></i>
                        <h5 class="fw-bold mt-3 text-dark">No Customer Orders Found</h5>
                        <p class="text-muted small">
                            We couldn't find any orders matching "<strong><?= htmlspecialchars($searchSo ?: $searchQuery ?: $filterCustomer) ?></strong>".
                        </p>
                        <a href="<?= $pageUrl ?>" class="btn btn-outline-primary btn-sm fw-semibold">
                            <i class="bi bi-arrow-repeat me-1"></i> Reset Filters
                        </a>
                    </div>
                <?php else: ?>
                    <?php
                    // Group orders by month (e.g. 2026-10, 2026-09, 2026-08)
                    $malayMonths = [
                        '01' => 'Januari',
                        '02' => 'Februari',
                        '03' => 'Mac',
                        '04' => 'April',
                        '05' => 'Mei',
                        '06' => 'Jun',
                        '07' => 'Julai',
                        '08' => 'Ogos',
                        '09' => 'September',
                        '10' => 'Oktober',
                        '11' => 'November',
                        '12' => 'Disember',
                    ];

                    $ordersByMonth = [];
                    foreach ($orderList as $order) {
                        $orderDate = !empty($order['first_date']) ? $order['first_date'] : $order['latest_delivered'];
                        $mKey = $orderDate ? date('Y-m', strtotime($orderDate)) : 'other';
                        $ordersByMonth[$mKey][] = $order;
                    }
                    krsort($ordersByMonth); // Latest months first (e.g. 2026-10, 2026-09, 2026-08...)

                    // Sort orders in each month so active/in-progress orders (< 100%) appear at the top,
                    // and completed orders (100%) are placed below in the list.
                    foreach ($ordersByMonth as $mKey => &$mOrders) {
                        usort($mOrders, function($a, $b) {
                            $isA100 = ($a['progress_pct'] >= 100) ? 1 : 0;
                            $isB100 = ($b['progress_pct'] >= 100) ? 1 : 0;
                            if ($isA100 !== $isB100) {
                                return $isA100 <=> $isB100; // 0 (< 100%) at top, 1 (100%) below
                            }
                            $dateA = $a['latest_delivered'] ?: $a['first_date'];
                            $dateB = $b['latest_delivered'] ?: $b['first_date'];
                            return strcmp($dateB, $dateA);
                        });
                    }
                    unset($mOrders);

                    $hasFilter = ($searchQuery !== '' || $filterCustomer !== '' || $filterStatus !== '');
                    ?>

                    <!-- Monthly View Toolbar -->
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 p-2 px-3 mb-3 bg-light rounded-3 border no-print">
                        <div class="small text-muted d-flex align-items-center gap-2">
                            <i class="bi bi-calendar-range text-primary fs-6"></i>
                            <span>Senarai Mengikut Bulan: <strong>2 Bulan Terkini Dipaparkan Automatik</strong></span>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary" onclick="expandAllMonths()" title="Buka senarai untuk semua bulan">
                                <i class="bi bi-arrows-expand me-1"></i> Kembang Semua
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="collapseOlderMonths()" title="Kekalkan 2 bulan terkini sahaja">
                                <i class="bi bi-clock-history me-1"></i> 2 Bulan Terkini Sahaja
                            </button>
                        </div>
                    </div>

                    <!-- Month Accordions -->
                    <div class="d-flex flex-column gap-3">
                        <?php 
                        $monthIndex = 0;
                        foreach ($ordersByMonth as $mKey => $mOrders): 
                            if ($mKey !== 'other' && preg_match('/^(\d{4})-(\d{2})$/', $mKey, $matches)) {
                                $year = $matches[1];
                                $monthNum = $matches[2];
                                $monthName = $malayMonths[$monthNum] ?? date('F', mktime(0, 0, 0, (int)$monthNum, 10));
                                $monthTitle = $monthName . ' ' . $year;
                            } else {
                                $monthTitle = 'Lain-lain / Tarikh Lain (Other)';
                            }

                            // 2 latest months are visible by default ($monthIndex < 2).
                            // If a filter/search is active, expand all matching months so nothing is hidden.
                            $isLatestTwo = ($monthIndex < 2);
                            $isExpanded = $isLatestTwo || $hasFilter;
                            $collapseId = 'month-collapse-' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $mKey);

                            // Month aggregated stats
                            $mDelivered = 0;
                            $mInProd = 0;
                            $mReady = 0;
                            $mTotalRolls = 0;
                            $mPlannedRolls = 0;
                            foreach ($mOrders as $mo) {
                                $mTotalRolls += (int)$mo['total_rolls'];
                                $mPlannedRolls += (int)$mo['planned_rolls'];
                                $orderSt = strtoupper($mo['order_status'] ?? ($mo['overall_status'] ?? ''));
                                if ($orderSt === 'DELIVERED') {
                                    $mDelivered++;
                                } elseif ($orderSt === 'READY' || $orderSt === 'APPROVED') {
                                    $mReady++;
                                } else {
                                    $mInProd++;
                                }
                            }
                        ?>
                            <div class="month-group-card shadow-sm" data-month-index="<?= $monthIndex ?>">
                                <div class="month-header-btn py-3 px-3 px-md-4 d-flex flex-wrap align-items-center justify-content-between gap-3"
                                     data-bs-toggle="collapse"
                                     data-bs-target="#<?= $collapseId ?>"
                                     aria-expanded="<?= $isExpanded ? 'true' : 'false' ?>"
                                     aria-controls="<?= $collapseId ?>">
                                    
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="<?= $isLatestTwo ? 'bg-primary text-white' : 'bg-light text-secondary border' ?> p-2 rounded-3 d-flex align-items-center justify-content-center shadow-sm" style="width: 44px; height: 44px;">
                                            <i class="bi bi-calendar-check fs-5"></i>
                                        </div>
                                        <div>
                                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                <h5 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.3px;">
                                                    <?= htmlspecialchars($monthTitle) ?>
                                                </h5>
                                                <?php if ($isLatestTwo): ?>
                                                    <span class="badge bg-primary text-white px-2 py-1" style="font-size: 11px;">
                                                        <i class="bi bi-stars me-1"></i> Terkini (Latest)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-secondary border px-2 py-1" style="font-size: 11px;">
                                                        <i class="bi bi-archive me-1"></i> Terdahulu (Older)
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-muted small mt-1">
                                                <span class="fw-bold text-dark"><?= count($mOrders) ?></span> Pesanan (Orders) &bull;
                                                <span class="fw-semibold text-dark"><?= $mTotalRolls ?></span> / <?= $mPlannedRolls ?> Rolls &bull;
                                                <span class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i><?= $mDelivered ?> Delivered</span>
                                                <?php if ($mInProd > 0): ?>
                                                    &bull; <span class="text-primary fw-semibold"><i class="bi bi-gear-fill me-1"></i><?= $mInProd ?> In Prod</span>
                                                <?php endif; ?>
                                                <?php if ($mReady > 0): ?>
                                                    &bull; <span class="text-info-emphasis fw-semibold"><i class="bi bi-box-seam me-1"></i><?= $mReady ?> Ready</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex align-items-center gap-2 no-print ms-auto">
                                        <button type="button" class="btn btn-sm <?= $isExpanded ? 'btn-outline-primary' : 'btn-light border text-muted' ?> fw-semibold px-3 py-1 month-toggle-btn" tabindex="-1">
                                            <span class="toggle-label"><?= $isExpanded ? 'Tutup Senarai' : 'Papar Senarai' ?></span>
                                            <i class="bi <?= $isExpanded ? 'bi-chevron-up' : 'bi-chevron-down' ?> ms-1 toggle-icon"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Collapsible Orders Table for this Month -->
                                <div id="<?= $collapseId ?>" class="collapse <?= $isExpanded ? 'show' : '' ?> month-collapse">
                                    <div class="card-body p-0 border-top">
                                        <div class="table-responsive">
                                            <table class="table table-hover table-custom align-middle mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>Sales Order (SO)</th>
                                                        <th>Customer</th>
                                                        <th>Product Model</th>
                                                        <th>Total Quantity <small class="text-muted fw-normal d-block" style="font-size: 11px;">(Dipotong / Dirancang)</small></th>
                                                        <th>Total Meters</th>
                                                        <th>Fulfillment Progress</th>
                                                        <th>Status</th>
                                                        <th class="text-end no-print">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($mOrders as $order): ?>
                                                        <tr>
                                                            <td>
                                                                <a href="<?= $pageUrl ?>?so=<?= urlencode($order['ref_no']) ?>" class="fw-bold text-decoration-none text-primary fs-6">
                                                                    <i class="bi bi-receipt me-1"></i> <?= htmlspecialchars($order['ref_no']) ?>
                                                                </a>
                                                                <small class="text-muted d-block">
                                                                    Started: <?= $order['first_date'] ? date('d M Y', strtotime($order['first_date'])) : '—' ?>
                                                                </small>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-light text-dark border fw-bold fs-6">
                                                                    <?= htmlspecialchars($order['customer_name']) ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <span class="fw-semibold text-dark"><?= htmlspecialchars($order['products'] ?: '—') ?></span>
                                                            </td>
                                                            <td>
                                                                <strong><?= $order['total_rolls'] ?> / <?= $order['planned_rolls'] ?></strong> <span class="text-muted small">Rolls</span>
                                                                <?php if (!empty($order['excess_breakdown'])): ?>
                                                                    <div class="mt-1" style="font-size: 11px;">
                                                                        <span class="badge bg-warning text-dark border border-warning-subtle py-1" title="Extra produce beyond planned cut">
                                                                            <i class="bi bi-plus-circle me-1"></i>+<?= $order['excess_rolls'] ?>: <?= htmlspecialchars($order['excess_breakdown']) ?>
                                                                        </span>
                                                                    </div>
                                                                <?php endif; ?>
                                                                <div class="small text-muted mt-1">
                                                                    Delivered: <span class="text-success fw-bold"><?= $order['delivered_rolls'] ?></span>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <strong><?= number_format((float)$order['total_meters'], 1) ?></strong> <span class="text-muted small">m</span>
                                                            </td>
                                                            <td style="min-width: 150px;">
                                                                <div class="d-flex align-items-center justify-content-between small text-muted mb-1">
                                                                    <span>Progress</span>
                                                                    <span class="fw-bold"><?= $order['progress_pct'] ?>%</span>
                                                                </div>
                                                                <div class="progress" style="height: 6px;">
                                                                    <div 
                                                                        class="progress-bar <?= ($order['progress_pct'] === 100) ? 'bg-success' : 'bg-primary' ?>" 
                                                                        role="progressbar" 
                                                                        style="width: <?= $order['progress_pct'] ?>%;" 
                                                                        aria-valuenow="<?= $order['progress_pct'] ?>" 
                                                                        aria-valuemin="0" 
                                                                        aria-valuemax="100">
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <span class="badge <?= $order['badge_class'] ?> px-2 py-1">
                                                                    <?= $order['status_text'] ?>
                                                                </span>
                                                            </td>
                                                            <td class="text-end no-print">
                                                                <a href="<?= $pageUrl ?>?so=<?= urlencode($order['ref_no']) ?>" class="btn btn-outline-primary btn-sm fw-semibold">
                                                                    Track <i class="bi bi-arrow-right ms-1"></i>
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php $monthIndex++; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </main>

    <!-- Footer -->
    <footer class="text-center py-4 text-muted border-top bg-white mt-5">
        <div class="container small">
            <div>&copy; <?= date('Y') ?> Nichias MK Slitting &bull; Customer Order Tracking System</div>
            <div class="text-muted mt-1">Industrial Slitting & Roll Packaging Traceability Portal</div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function copyTrackingLink() {
            const url = window.location.href;
            navigator.clipboard.writeText(url).then(() => {
                alert("Tracking link copied to clipboard!\n\n" + url);
            }).catch(err => {
                prompt("Copy this tracking link:", url);
            });
        }

        function expandAllMonths() {
            document.querySelectorAll('.month-collapse').forEach(function(el) {
                bootstrap.Collapse.getOrCreateInstance(el, {toggle: false}).show();
            });
        }

        function collapseOlderMonths() {
            document.querySelectorAll('.month-group-card').forEach(function(card) {
                const idx = parseInt(card.getAttribute('data-month-index') || '0', 10);
                const collapseEl = card.querySelector('.month-collapse');
                if (collapseEl) {
                    const instance = bootstrap.Collapse.getOrCreateInstance(collapseEl, {toggle: false});
                    if (idx < 2) {
                        instance.show();
                    } else {
                        instance.hide();
                    }
                }
            });
        }

        // Synchronize collapse header button text and chevron icons
        document.addEventListener('DOMContentLoaded', function() {
            const monthCollapses = document.querySelectorAll('.month-collapse');
            monthCollapses.forEach(function(el) {
                el.addEventListener('show.bs.collapse', function() {
                    const card = el.closest('.month-group-card');
                    if (card) {
                        const btn = card.querySelector('.month-toggle-btn');
                        const label = card.querySelector('.toggle-label');
                        const icon = card.querySelector('.toggle-icon');
                        if (btn) {
                            btn.classList.remove('btn-light', 'border', 'text-muted');
                            btn.classList.add('btn-outline-primary');
                        }
                        if (label) label.textContent = 'Tutup Senarai';
                        if (icon) {
                            icon.classList.remove('bi-chevron-down');
                            icon.classList.add('bi-chevron-up');
                        }
                    }
                });
                el.addEventListener('hide.bs.collapse', function() {
                    const card = el.closest('.month-group-card');
                    if (card) {
                        const btn = card.querySelector('.month-toggle-btn');
                        const label = card.querySelector('.toggle-label');
                        const icon = card.querySelector('.toggle-icon');
                        if (btn) {
                            btn.classList.remove('btn-outline-primary');
                            btn.classList.add('btn-light', 'border', 'text-muted');
                        }
                        if (label) label.textContent = 'Papar Senarai';
                        if (icon) {
                            icon.classList.remove('bi-chevron-up');
                            icon.classList.add('bi-chevron-down');
                        }
                    }
                });
            });

            // Synchronize pallet collapse toggle button
            const palletCollapseEl = document.getElementById('assignedPalletsCollapse');
            if (palletCollapseEl) {
                palletCollapseEl.addEventListener('show.bs.collapse', function() {
                    const btn = document.querySelector('.pallet-toggle-btn');
                    const label = document.querySelector('.pallet-toggle-label');
                    const icon = document.querySelector('.pallet-toggle-icon');
                    if (btn) {
                        btn.classList.remove('btn-outline-primary');
                        btn.classList.add('btn-primary', 'text-white');
                    }
                    if (label) label.textContent = 'Hide Pallets';
                    if (icon) {
                        icon.classList.remove('bi-chevron-down');
                        icon.classList.add('bi-chevron-up');
                    }
                });
                palletCollapseEl.addEventListener('hide.bs.collapse', function() {
                    const btn = document.querySelector('.pallet-toggle-btn');
                    const label = document.querySelector('.pallet-toggle-label');
                    const icon = document.querySelector('.pallet-toggle-icon');
                    if (btn) {
                        btn.classList.remove('btn-primary', 'text-white');
                        btn.classList.add('btn-outline-primary');
                    }
                    if (label) label.textContent = 'View Pallets';
                    if (icon) {
                        icon.classList.remove('bi-chevron-up');
                        icon.classList.add('bi-chevron-down');
                    }
                });
            }
        });

        function togglePalletCollapse() {
            const el = document.getElementById('assignedPalletsCollapse');
            if (el) {
                bootstrap.Collapse.getOrCreateInstance(el).toggle();
                el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }

        function filterRollTable(status, clickedBtn) {
            const filterButtons = document.querySelectorAll('.roll-filter-btn');
            filterButtons.forEach(btn => {
                const filter = btn.getAttribute('data-filter');
                const isActive = (btn === clickedBtn);
                btn.classList.toggle('active', isActive);
                btn.classList.toggle('shadow-sm', isActive);

                const badge = btn.querySelector('.badge');

                if (filter === 'all') {
                    btn.className = `btn btn-sm roll-filter-btn ${isActive ? 'btn-dark text-white active shadow-sm' : 'btn-outline-dark'}`;
                    if (badge) {
                        badge.className = `badge ms-1 ${isActive ? 'bg-light text-dark' : 'bg-dark text-white'}`;
                    }
                } else if (filter === 'delivered') {
                    btn.className = `btn btn-sm roll-filter-btn ${isActive ? 'btn-success text-white active shadow-sm' : 'btn-outline-success'}`;
                    if (badge) {
                        badge.className = `badge ms-1 ${isActive ? 'bg-white text-success' : 'bg-success text-white'}`;
                    }
                } else if (filter === 'approved') {
                    btn.className = `btn btn-sm roll-filter-btn ${isActive ? 'btn-info text-dark fw-semibold active shadow-sm' : 'btn-outline-info text-dark'}`;
                    if (badge) {
                        badge.className = `badge ms-1 ${isActive ? 'bg-white text-dark' : 'bg-info text-dark'}`;
                    }
                } else if (filter === 'waiting') {
                    btn.className = `btn btn-sm roll-filter-btn ${isActive ? 'btn-warning text-dark fw-semibold active shadow-sm' : 'btn-outline-warning text-dark'}`;
                    if (badge) {
                        badge.className = `badge ms-1 ${isActive ? 'bg-white text-dark' : 'bg-warning text-dark'}`;
                    }
                } else if (filter === 'finish_good') {
                    btn.className = `btn btn-sm roll-filter-btn ${isActive ? 'btn-primary text-white active shadow-sm' : 'btn-outline-primary'}`;
                    if (badge) {
                        badge.className = `badge ms-1 ${isActive ? 'bg-white text-primary' : 'bg-primary text-white'}`;
                    }
                } else if (filter === 'in_slitting') {
                    btn.className = `btn btn-sm roll-filter-btn ${isActive ? 'btn-secondary text-white active shadow-sm' : 'btn-outline-secondary'}`;
                    if (badge) {
                        badge.className = `badge ms-1 ${isActive ? 'bg-white text-secondary' : 'bg-secondary text-white'}`;
                    }
                } else if (filter === 'planned') {
                    btn.className = `btn btn-sm roll-filter-btn ${isActive ? 'active shadow-sm' : ''}`;
                    if (isActive) {
                        btn.style.backgroundColor = '#7c3aed';
                        btn.style.borderColor = '#7c3aed';
                        btn.style.color = '#ffffff';
                        if (badge) {
                            badge.style.backgroundColor = '#ffffff';
                            badge.style.color = '#7c3aed';
                        }
                    } else {
                        btn.style.backgroundColor = 'transparent';
                        btn.style.borderColor = '#7c3aed';
                        btn.style.color = '#7c3aed';
                        if (badge) {
                            badge.style.backgroundColor = '#7c3aed';
                            badge.style.color = '#ffffff';
                        }
                    }
                }
            });

            const rows = document.querySelectorAll('.roll-row');
            let visibleCount = 0;
            rows.forEach(row => {
                const rowStatus = row.getAttribute('data-roll-status');
                if (status === 'all' || rowStatus === status) {
                    row.style.display = '';
                    visibleCount++;
                    const idxCell = row.querySelector('.roll-index-cell');
                    if (idxCell) {
                        idxCell.textContent = visibleCount;
                    }
                } else {
                    row.style.display = 'none';
                }
            });

            const countEl = document.getElementById('rollVisibleCount');
            if (countEl) countEl.textContent = visibleCount;

            const summaryText = document.getElementById('rollFilterSummaryText');
            if (summaryText) {
                if (status === 'all') {
                    summaryText.innerHTML = `Showing all <span class="text-dark fw-bold">${visibleCount}</span> rolls`;
                } else {
                    const filterName = clickedBtn.innerText.replace(/[0-9]/g, '').trim();
                    summaryText.innerHTML = `Showing <span class="text-dark fw-bold">${visibleCount}</span> of ${rows.length} rolls (${filterName})`;
                }
            }

            const noMatchRow = document.getElementById('noRollsMatchRow');
            if (noMatchRow) {
                noMatchRow.style.display = (visibleCount === 0) ? '' : 'none';
            }
        }
    </script>
</body>
</html>

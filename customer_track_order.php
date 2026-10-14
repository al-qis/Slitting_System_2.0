<?php
/**
 * customer_track_order.php — Dedicated Customer Order Tracking Portal
 * ─────────────────────────────────────────────────────────────────────────────
 * Provides a dedicated, secure tracking portal for customers and staff to monitor
 * customer orders, production progress, QC status, palletizing, and delivery details.
 *
 * Supported Lookups:
 *   - Sales Order (SO) Number / Ref No (e.g. SO-26-1555)
 *   - Customer Name (e.g. NAE, NAX, TAIHO, NRI, etc.)
 *   - Pallet Number (e.g. SFS-2610-002)
 *   - Coil No / Lot No / Roll No
 * ─────────────────────────────────────────────────────────────────────────────
 */

require_once 'config.php';

// Base sanitization helper
function cleanInput($val) {
    return trim((string)($val ?? ''));
}

// Roll number formatter (e.g., R1 -> R-1)
function formatRollNo($roll) {
    if (!$roll) return '—';
    $roll = (string)$roll;
    if (preg_match('/^R\d+/i', $roll)) {
        return 'R-' . ltrim(substr($roll, 1), '-');
    }
    return htmlspecialchars($roll);
}

// ── Search & Filter Inputs ───────────────────────────────────────────────────
$searchSo       = cleanInput($_GET['so'] ?? ($_GET['order'] ?? ''));
$searchQuery    = cleanInput($_GET['search'] ?? '');
$filterCustomer = cleanInput($_GET['customer'] ?? '');
$filterStatus   = cleanInput($_GET['status'] ?? '');

// If user entered something in generic search that looks like an SO number, prioritize it
if ($searchSo === '' && $searchQuery !== '' && preg_match('/^SO-[\d-]+/i', $searchQuery)) {
    $searchSo = $searchQuery;
}

// ── Distinct Customers for Dropdown / Pills ──────────────────────────────────
$customerList = [];
$custRes = $conn->query("
    SELECT DISTINCT customer_name 
    FROM slitting_product 
    WHERE customer_name IS NOT NULL 
      AND customer_name != '' 
      AND customer_name != 'STOCK'
      AND is_voided = 0
    ORDER BY customer_name ASC
");
if ($custRes) {
    while ($cRow = $custRes->fetch_assoc()) {
        $customerList[] = $cRow['customer_name'];
    }
}

// ── Check if Single Order Mode is Triggered ──────────────────────────────────
$singleOrder = null;
$orderRolls  = [];
$orderPallets = [];

// Helper condition: valid un-voided, non-consumed rolls
$baseWhere = "sp.is_voided = 0 AND (sp.is_reslitted = 0 OR sp.is_reslitted IS NULL) AND (sp.is_recoiled = 0 OR sp.is_recoiled IS NULL)";

if ($searchSo !== '') {
    // 1. Fetch rolls for this specific SO / Ref No
    $stmtRolls = $conn->prepare("
        SELECT 
            sp.id,
            sp.customer_name,
            sp.ref_no,
            sp.product,
            sp.lot_no,
            sp.coil_no,
            sp.roll_no,
            sp.width,
            sp.length,
            sp.actual_length,
            sp.status,
            sp.stock_counted,
            sp.is_completed,
            sp.date_in,
            sp.date_out,
            sp.delivered_at,
            sp.delivered_by,
            sp.qc_comment,
            mc.grade,
            pi.pallet_id,
            p.pallet_no,
            p.status AS pallet_status,
            p.delivered_at AS pallet_delivered_at
        FROM slitting_product sp
        LEFT JOIN mother_coil mc ON mc.id = sp.mother_id
        LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
        LEFT JOIN pallets p ON p.id = pi.pallet_id
        WHERE {$baseWhere}
          AND sp.ref_no = ?
        ORDER BY 
            CASE sp.status WHEN 'DELIVERED' THEN 1 ELSE 0 END ASC,
            sp.roll_no ASC,
            sp.id ASC
    ");
    $stmtRolls->bind_param("s", $searchSo);
    $stmtRolls->execute();
    $rollsResult = $stmtRolls->get_result();
    while ($r = $rollsResult->fetch_assoc()) {
        $orderRolls[] = $r;
    }
    $stmtRolls->close();

    // If exact match didn't yield anything, try LIKE search for partial SO
    if (empty($orderRolls)) {
        $likeSo = '%' . $searchSo . '%';
        $stmtRollsLike = $conn->prepare("
            SELECT 
                sp.id,
                sp.customer_name,
                sp.ref_no,
                sp.product,
                sp.lot_no,
                sp.coil_no,
                sp.roll_no,
                sp.width,
                sp.length,
                sp.actual_length,
                sp.status,
                sp.stock_counted,
                sp.is_completed,
                sp.date_in,
                sp.date_out,
                sp.delivered_at,
                sp.delivered_by,
                sp.qc_comment,
                mc.grade,
                pi.pallet_id,
                p.pallet_no,
                p.status AS pallet_status,
                p.delivered_at AS pallet_delivered_at
            FROM slitting_product sp
            LEFT JOIN mother_coil mc ON mc.id = sp.mother_id
            LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
            LEFT JOIN pallets p ON p.id = pi.pallet_id
            WHERE {$baseWhere}
              AND sp.ref_no LIKE ?
            ORDER BY 
                CASE sp.status WHEN 'DELIVERED' THEN 1 ELSE 0 END ASC,
                sp.roll_no ASC,
                sp.id ASC
        ");
        $stmtRollsLike->bind_param("s", $likeSo);
        $stmtRollsLike->execute();
        $likeRes = $stmtRollsLike->get_result();
        while ($r = $likeRes->fetch_assoc()) {
            $orderRolls[] = $r;
        }
        $stmtRollsLike->close();
    }

    // Compute Single Order Summary
    if (!empty($orderRolls)) {
        $totalRolls     = count($orderRolls);
        $deliveredRolls = 0;
        $approvedRolls  = 0;
        $waitingRolls   = 0;
        $inProdRolls    = 0;
        $totalMeters    = 0;
        $customerNames  = [];
        $uniqueProducts = [];
        $uniquePallets  = [];
        $earliestDate   = null;
        $latestDelivered= null;
        $actualRefNo    = $orderRolls[0]['ref_no'];

        foreach ($orderRolls as $roll) {
            $status = strtoupper($roll['status'] ?? 'IN');
            $stockCounted = (int)($roll['stock_counted'] ?? 0);
            $meters = (float)($roll['actual_length'] ?: $roll['length'] ?: 0);
            $totalMeters += $meters;

            if ($roll['customer_name']) {
                $customerNames[$roll['customer_name']] = true;
            }
            if ($roll['product']) {
                $uniqueProducts[$roll['product']] = true;
            }
            if (!empty($roll['pallet_no'])) {
                $pNo = $roll['pallet_no'];
                if (!isset($uniquePallets[$pNo])) {
                    $uniquePallets[$pNo] = [
                        'pallet_no'     => $pNo,
                        'status'        => $roll['pallet_status'],
                        'delivered_at'  => $roll['pallet_delivered_at'],
                        'roll_count'    => 0,
                    ];
                }
                $uniquePallets[$pNo]['roll_count']++;
            }

            if ($status === 'DELIVERED') {
                $deliveredRolls++;
                if (!empty($roll['delivered_at'])) {
                    if (!$latestDelivered || strtotime($roll['delivered_at']) > strtotime($latestDelivered)) {
                        $latestDelivered = $roll['delivered_at'];
                    }
                }
            } elseif ($status === 'APPROVED') {
                $approvedRolls++;
            } elseif ($status === 'WAITING') {
                $waitingRolls++;
            } else {
                $inProdRolls++;
            }

            if (!empty($roll['date_in'])) {
                if (!$earliestDate || strtotime($roll['date_in']) < strtotime($earliestDate)) {
                    $earliestDate = $roll['date_in'];
                }
            }
        }

        // Determine Overall Progress State
        $overallStatus = 'IN_PRODUCTION';
        $progressPct   = 25;
        $stageIndex    = 1; // 1: Order/Planned, 2: Slitting, 3: QC, 4: Palletizing, 5: Delivered

        if ($deliveredRolls === $totalRolls) {
            $overallStatus = 'DELIVERED';
            $progressPct   = 100;
            $stageIndex    = 5;
        } elseif ($deliveredRolls > 0) {
            $overallStatus = 'PARTIALLY_DELIVERED';
            $progressPct   = (int)round(($deliveredRolls / $totalRolls) * 100);
            if ($progressPct < 50) $progressPct = 50;
            $stageIndex    = 4;
        } elseif (!empty($uniquePallets) || $approvedRolls === $totalRolls) {
            $overallStatus = 'READY_FOR_DELIVERY';
            $progressPct   = 85;
            $stageIndex    = 4;
        } elseif ($waitingRolls > 0 || $approvedRolls > 0) {
            $overallStatus = 'WAITING_QC';
            $progressPct   = 55;
            $stageIndex    = 3;
        } else {
            $overallStatus = 'IN_PRODUCTION';
            $progressPct   = 30;
            $stageIndex    = 2;
        }

        $singleOrder = [
            'ref_no'          => $actualRefNo,
            'customer_name'   => implode(', ', array_keys($customerNames)) ?: 'N/A',
            'products'        => implode(', ', array_keys($uniqueProducts)) ?: 'N/A',
            'total_rolls'     => $totalRolls,
            'delivered_rolls' => $deliveredRolls,
            'approved_rolls'  => $approvedRolls,
            'waiting_rolls'   => $waitingRolls,
            'in_prod_rolls'   => $inProdRolls,
            'total_meters'    => $totalMeters,
            'overall_status'  => $overallStatus,
            'progress_pct'    => $progressPct,
            'stage_index'     => $stageIndex,
            'earliest_date'   => $earliestDate,
            'latest_delivered'=> $latestDelivered,
            'pallets'         => array_values($uniquePallets),
        ];
    }
}

// ── Multi-Order List Query (Search & Browse Mode) ─────────────────────────────
$orderList = [];
$totalOrdersCount = 0;
$deliveredOrdersCount = 0;
$inProdOrdersCount = 0;
$readyOrdersCount = 0;

$listSql = "
    SELECT 
        sp.customer_name, 
        sp.ref_no, 
        COUNT(*) as total_rolls, 
        SUM(CASE WHEN sp.status = 'DELIVERED' THEN 1 ELSE 0 END) as delivered_rolls, 
        SUM(CASE WHEN sp.status = 'APPROVED' THEN 1 ELSE 0 END) as approved_rolls, 
        SUM(CASE WHEN sp.status = 'WAITING' THEN 1 ELSE 0 END) as waiting_rolls, 
        SUM(CASE WHEN sp.status = 'IN' THEN 1 ELSE 0 END) as in_prod_rolls, 
        SUM(COALESCE(sp.actual_length, sp.length, 0)) as total_meters,
        MAX(sp.delivered_at) as latest_delivered, 
        MIN(sp.date_in) as first_date,
        COUNT(DISTINCT p.pallet_no) as pallet_count,
        GROUP_CONCAT(DISTINCT p.pallet_no SEPARATOR ', ') as pallet_numbers,
        GROUP_CONCAT(DISTINCT sp.product SEPARATOR ', ') as products
    FROM slitting_product sp 
    LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id 
    LEFT JOIN pallets p ON p.id = pi.pallet_id 
    WHERE {$baseWhere}
      AND sp.customer_name IS NOT NULL AND sp.customer_name != '' 
      AND sp.ref_no IS NOT NULL AND sp.ref_no != '' 
      AND sp.ref_no != 'STOCK'
";

$listParams = [];
$listTypes  = '';

if ($filterCustomer !== '') {
    $listSql .= " AND sp.customer_name = ?";
    $listParams[] = $filterCustomer;
    $listTypes .= 's';
}

if ($searchQuery !== '') {
    $listSql .= " AND (
        sp.ref_no LIKE ? OR 
        sp.customer_name LIKE ? OR 
        sp.product LIKE ? OR 
        sp.lot_no LIKE ? OR 
        sp.coil_no LIKE ? OR
        p.pallet_no LIKE ?
    )";
    $likeQ = '%' . $searchQuery . '%';
    $listParams = array_merge($listParams, [$likeQ, $likeQ, $likeQ, $likeQ, $likeQ, $likeQ]);
    $listTypes .= 'ssssss';
}

$listSql .= " GROUP BY sp.customer_name, sp.ref_no ";

// Status filter for aggregated grouping
if ($filterStatus === 'delivered') {
    $listSql .= " HAVING delivered_rolls = total_rolls ";
} elseif ($filterStatus === 'in_production') {
    $listSql .= " HAVING delivered_rolls < total_rolls AND in_prod_rolls > 0 ";
} elseif ($filterStatus === 'waiting_qc') {
    $listSql .= " HAVING delivered_rolls < total_rolls AND waiting_rolls > 0 ";
} elseif ($filterStatus === 'approved') {
    $listSql .= " HAVING delivered_rolls < total_rolls AND approved_rolls > 0 ";
}

$listSql .= " ORDER BY COALESCE(latest_delivered, first_date) DESC LIMIT 100";

$stmtList = $conn->prepare($listSql);
if (!empty($listParams)) {
    $stmtList->bind_param($listTypes, ...$listParams);
}
$stmtList->execute();
$listResult = $stmtList->get_result();
while ($row = $listResult->fetch_assoc()) {
    $tot = (int)$row['total_rolls'];
    $del = (int)$row['delivered_rolls'];
    $app = (int)$row['approved_rolls'];
    $wai = (int)$row['waiting_rolls'];
    $palletsCount = (int)$row['pallet_count'];

    if ($del === $tot) {
        $st = 'DELIVERED';
        $pct = 100;
        $badgeClass = 'bg-success text-white';
        $statusText = 'Delivered';
        $deliveredOrdersCount++;
    } elseif ($del > 0) {
        $st = 'PARTIALLY_DELIVERED';
        $pct = (int)round(($del / $tot) * 100);
        $badgeClass = 'bg-primary text-white';
        $statusText = "Partial ({$del}/{$tot} Delivered)";
        $inProdOrdersCount++;
    } elseif ($palletsCount > 0 || $app === $tot) {
        $st = 'READY';
        $pct = 85;
        $badgeClass = 'bg-info text-dark';
        $statusText = 'Ready for Delivery';
        $readyOrdersCount++;
    } elseif ($wai > 0) {
        $st = 'WAITING_QC';
        $pct = 50;
        $badgeClass = 'bg-warning text-dark';
        $statusText = 'QC Inspection';
        $inProdOrdersCount++;
    } else {
        $st = 'IN_PRODUCTION';
        $pct = 25;
        $badgeClass = 'bg-secondary text-white';
        $statusText = 'In Production';
        $inProdOrdersCount++;
    }

    $row['order_status']   = $st;
    $row['progress_pct']   = $pct;
    $row['badge_class']    = $badgeClass;
    $row['status_text']    = $statusText;

    $orderList[] = $row;
    $totalOrdersCount++;
}
$stmtList->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Customer Order Tracking - MK Slitting</title>

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
                <img src="assets/nichiaslogo.jpg" alt="Nichias Logo" class="portal-logo shadow-sm">
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
            <form method="GET" action="customer_track_order.php" class="row g-3 align-items-center">
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
                            <a href="customer_track_order.php" class="btn btn-link text-muted p-0 ms-2" title="Clear Search">
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
                        <a href="customer_track_order.php" class="cust-pill <?= ($filterCustomer === '' && $searchSo === '' && $searchQuery === '') ? 'active' : '' ?>">
                            All Orders
                        </a>
                        <?php 
                        // Show top 8 customers as quick pills
                        $topPills = array_slice($customerList, 0, 8);
                        foreach ($topPills as $pill): 
                        ?>
                            <a href="customer_track_order.php?customer=<?= urlencode($pill) ?>" class="cust-pill <?= ($filterCustomer === $pill) ? 'active' : '' ?>">
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
                <a href="customer_track_order.php<?= $filterCustomer ? '?customer='.urlencode($filterCustomer) : '' ?>" class="btn btn-outline-secondary btn-sm fw-semibold">
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
                                <i class="bi bi-truck fs-5"></i> PARTIAL DELIVERY (<?= $singleOrder['delivered_rolls'] ?> / <?= $singleOrder['total_rolls'] ?>)
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
                        </div>

                        <!-- Step 2: Slitting -->
                        <div class="timeline-step <?= ($singleOrder['stage_index'] > 2) ? 'completed' : (($singleOrder['stage_index'] === 2) ? 'active' : '') ?>">
                            <div class="step-icon-bubble">
                                <i class="bi bi-scissors"></i>
                            </div>
                            <div class="step-label">Slitting</div>
                            <div class="step-subtext">Rolls Slit & Tagged</div>
                        </div>

                        <!-- Step 3: QC Inspection -->
                        <div class="timeline-step <?= ($singleOrder['stage_index'] > 3) ? 'completed' : (($singleOrder['stage_index'] === 3) ? 'active' : '') ?>">
                            <div class="step-icon-bubble">
                                <i class="bi bi-patch-check"></i>
                            </div>
                            <div class="step-label">Quality Check</div>
                            <div class="step-subtext">Verified by QC</div>
                        </div>

                        <!-- Step 4: Palletizing -->
                        <div class="timeline-step <?= ($singleOrder['stage_index'] > 4) ? 'completed' : (($singleOrder['stage_index'] === 4) ? 'active' : '') ?>">
                            <div class="step-icon-bubble">
                                <i class="bi bi-archive"></i>
                            </div>
                            <div class="step-label">Palletizing</div>
                            <div class="step-subtext">Packed & Staged</div>
                        </div>

                        <!-- Step 5: Delivered -->
                        <div class="timeline-step <?= ($singleOrder['stage_index'] === 5) ? 'completed' : '' ?>">
                            <div class="step-icon-bubble">
                                <i class="bi bi-truck"></i>
                            </div>
                            <div class="step-label">Delivered</div>
                            <div class="step-subtext"><?= $singleOrder['latest_delivered'] ? date('d M Y', strtotime($singleOrder['latest_delivered'])) : 'Out for Delivery' ?></div>
                        </div>
                    </div>
                </div>

                <!-- KPI Metric Chips -->
                <div class="row g-3 mt-2">
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded bg-light border text-center">
                            <small class="text-muted fw-bold text-uppercase d-block mb-1">Total Quantity</small>
                            <span class="fs-4 fw-bold text-dark"><?= $singleOrder['total_rolls'] ?></span>
                            <span class="text-muted small">Rolls</span>
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
                            <span class="text-muted small">/ <?= $singleOrder['total_rolls'] ?> Rolls</span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded bg-light border text-center">
                            <small class="text-muted fw-bold text-uppercase d-block mb-1">Pallet Units</small>
                            <span class="fs-4 fw-bold text-primary"><?= count($singleOrder['pallets']) ?></span>
                            <span class="text-muted small">Pallets Assigned</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pallet Summary Box (if pallets exist) -->
            <?php if (!empty($singleOrder['pallets'])): ?>
                <div class="card glass-card p-3 p-md-4 mb-4">
                    <h5 class="fw-bold mb-3 d-flex align-items-center gap-2">
                        <i class="bi bi-boxes text-primary"></i> Assigned Pallets & Dispatch Units
                    </h5>
                    <div class="row g-3">
                        <?php foreach ($singleOrder['pallets'] as $plt): ?>
                            <div class="col-12 col-md-6 col-lg-4">
                                <div class="p-3 border rounded-3 bg-light h-100">
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
            <?php endif; ?>

            <!-- Roll Breakdown Table -->
            <div class="card glass-card p-3 p-md-4 mb-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="fw-bold m-0 d-flex align-items-center gap-2">
                        <i class="bi bi-list-check text-primary"></i> Order Itemized Rolls Breakdown
                    </h5>
                    <span class="text-muted small">Total: <?= count($orderRolls) ?> Rolls</span>
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
                                <th>Stage / Status</th>
                                <th>Pallet No</th>
                                <th>Delivery Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orderRolls as $idx => $roll): 
                                $st = strtoupper($roll['status'] ?? 'IN');
                                $sc = (int)($roll['stock_counted'] ?? 0);
                            ?>
                                <tr>
                                    <td class="text-muted"><?= $idx + 1 ?></td>
                                    <td class="fw-bold text-dark">
                                        <?= formatRollNo($roll['roll_no']) ?>
                                    </td>
                                    <td>
                                        <span class="fw-semibold text-secondary"><?= htmlspecialchars($roll['lot_no'] ?: '—') ?></span>
                                        <small class="text-muted d-block">Coil: <?= htmlspecialchars($roll['coil_no'] ?: '—') ?></small>
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
                                        <?php if ($st === 'DELIVERED'): ?>
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
                                        <?php if (!empty($roll['pallet_no'])): ?>
                                            <span class="badge bg-light text-dark border fw-semibold">
                                                <i class="bi bi-archive me-1"></i> <?= htmlspecialchars($roll['pallet_no']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">Not Palletized</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($roll['delivered_at'])): ?>
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

                    <!-- Status Filter Dropdown -->
                    <div class="no-print">
                        <div class="btn-group btn-group-sm" role="group">
                            <a href="customer_track_order.php?customer=<?= urlencode($filterCustomer) ?>&search=<?= urlencode($searchQuery) ?>" class="btn <?= ($filterStatus === '') ? 'btn-dark' : 'btn-outline-secondary' ?>">
                                All
                            </a>
                            <a href="customer_track_order.php?customer=<?= urlencode($filterCustomer) ?>&search=<?= urlencode($searchQuery) ?>&status=in_production" class="btn <?= ($filterStatus === 'in_production') ? 'btn-dark' : 'btn-outline-secondary' ?>">
                                In Prod
                            </a>
                            <a href="customer_track_order.php?customer=<?= urlencode($filterCustomer) ?>&search=<?= urlencode($searchQuery) ?>&status=approved" class="btn <?= ($filterStatus === 'approved') ? 'btn-dark' : 'btn-outline-secondary' ?>">
                                Ready
                            </a>
                            <a href="customer_track_order.php?customer=<?= urlencode($filterCustomer) ?>&search=<?= urlencode($searchQuery) ?>&status=delivered" class="btn <?= ($filterStatus === 'delivered') ? 'btn-dark' : 'btn-outline-secondary' ?>">
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
                        <a href="customer_track_order.php" class="btn btn-outline-primary btn-sm fw-semibold">
                            <i class="bi bi-arrow-repeat me-1"></i> Reset Filters
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-custom align-middle">
                            <thead>
                                <tr>
                                    <th>Sales Order (SO)</th>
                                    <th>Customer</th>
                                    <th>Product Model</th>
                                    <th>Total Quantity</th>
                                    <th>Total Meters</th>
                                    <th>Fulfillment Progress</th>
                                    <th>Status</th>
                                    <th>Pallet Details</th>
                                    <th class="text-end no-print">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orderList as $order): ?>
                                    <tr>
                                        <td>
                                            <a href="customer_track_order.php?so=<?= urlencode($order['ref_no']) ?>" class="fw-bold text-decoration-none text-primary fs-6">
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
                                            <strong><?= $order['total_rolls'] ?></strong> <span class="text-muted small">Rolls</span>
                                            <div class="small text-muted">
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
                                        <td>
                                            <?php if (!empty($order['pallet_numbers'])): ?>
                                                <span class="small text-dark fw-semibold">
                                                    <i class="bi bi-archive me-1"></i> <?= htmlspecialchars($order['pallet_numbers']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end no-print">
                                            <a href="customer_track_order.php?so=<?= urlencode($order['ref_no']) ?>" class="btn btn-outline-primary btn-sm fw-semibold">
                                                Track <i class="bi bi-arrow-right ms-1"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
    </script>
</body>
</html>

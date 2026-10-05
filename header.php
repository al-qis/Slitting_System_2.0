<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'Slitting System'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo isset($pathPrefix) ? $pathPrefix : ''; ?>font_size_patch.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        .sidebar { background: #212529; }
        @media (min-width: 768px) {
            .sidebar {
                position: sticky;
                top: 0;
                height: 100vh;
                overflow-y: auto;
                z-index: 1020;
            }
        }
        .nav-link { transition: all 0.2s; border-radius: 4px; margin-bottom: 2px; }
        .nav-link:hover { background: rgba(255,255,255,0.1); color: #fff !important; }
        .active-nav { background: #0d6efd !important; color: #fff !important; font-weight: 600; }
        .sidebar-logo { border-bottom: 1px solid #444; padding-bottom: 1rem; margin-bottom: 1rem; }

        /* Global Warning Badges requested by user */
        /* Red Warning: Sticker SO Number = STOCK */
        .badge-warning-stock {
            background-color: #dc3545 !important;
            color: #ffffff !important;
            font-weight: 700;
            border: 1px solid #b02a37 !important;
            padding: 3px 7px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .warning-stock-input {
            border-color: #dc3545 !important;
            background-color: #fff5f5 !important;
            color: #dc3545 !important;
            font-weight: bold;
        }

        /* Light Purple Warning: Mismatched Customer Details (Name / SO Num) */
        .badge-warning-cust-mismatch {
            background-color: #f3e8ff !important;
            color: #6b21a8 !important;
            border: 1px solid #d8b4fe !important;
            font-weight: 700;
            padding: 3px 7px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .row-cust-mismatch {
            background-color: #faf5ff !important;
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
<?php if (empty($hide_sidebar)): ?>
        <div class="col-md-2 bg-dark text-white min-vh-100 p-3 shadow no-print sidebar">
            <div class="d-flex align-items-center justify-content-center sidebar-logo">
                <img src="<?php echo isset($pathPrefix) ? $pathPrefix : ''; ?>assets/nichiaslogo.jpg" alt="Logo" style="max-width: 35px;" class="me-2 rounded shadow-sm">
                <h6 class="m-0 fw-bold">MK SLITTING</h6>
            </div>

            <ul class="nav flex-column">
                <?php
                $current_page = basename($_SERVER['PHP_SELF']);
                $script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
                $is_control_center = (strpos($script_name, 'control_system') !== false);
                $role = $_SESSION['role'] ?? '';
                $prefix = isset($pathPrefix) ? $pathPrefix : '';

                // Full menu for slitting role
                $all_menu_items = [
                    'settings.php'             => ['icon' => 'gear',                   'label' => 'Settings'],
                    'control_system/index.php' => ['icon' => 'sliders',                'label' => 'Control Center'],
                    'index.php'                => ['icon' => 'speedometer2',            'label' => 'Dashboard'],
                    'mother_coil.php'          => ['icon' => 'layer-forward',           'label' => 'Mother Coil'],
                    'raw_material.php'         => ['icon' => 'box-seam',                'label' => 'Raw Material'],
                    'sfc.php'                  => ['icon' => 'box-seam-fill',           'label' => 'SFC Inventory'],
                    'slitting_product.php'     => ['icon' => 'scissors',                'label' => 'Slitting Product'],
                    'recoiling.php'            => ['icon' => 'arrow-repeat',            'label' => 'Recoiling Cut'],
                    'reslit.php'               => ['icon' => 'intersect',               'label' => 'Reslit Product'],
                    'finish_product.php'       => ['icon' => 'check-circle',            'label' => 'Finish Product'],
                    'pallet.php'               => ['icon' => 'archive',                 'label' => 'Pallet'],
                    'report.php'               => ['icon' => 'file-earmark-bar-graph',  'label' => 'Report'],
                    'tracking_product.php'     => ['icon' => 'globe2',                  'label' => 'Traceability'],
                ];

                // Restricted menu for mkl3 role — Settings & Mother Coil only
                $mkl3_menu_items = [
                    'settings.php'    => ['icon' => 'gear',           'label' => 'Settings'],
                    'mother_coil.php' => ['icon' => 'layer-forward',  'label' => 'Mother Coil'],
                ];

                $menu_items = ($role === 'mkl3') ? $mkl3_menu_items : $all_menu_items;

                foreach ($menu_items as $url => $info):
                    if ($url === 'control_system/index.php') {
                        $active = $is_control_center ? 'active-nav' : '';
                    } elseif ($url === 'index.php') {
                        $active = (!$is_control_center && $current_page === 'index.php') ? 'active-nav' : '';
                    } else {
                        $active = ($current_page === basename($url)) ? 'active-nav' : '';
                    }
                ?>
                <li class="nav-item">
                    <a class="nav-link text-white <?php echo $active; ?>" href="<?php echo $prefix . $url; ?>">
                        <i class="bi bi-<?php echo $info['icon']; ?> me-2"></i> <?php echo $info['label']; ?>
                    </a>
                </li>
                <?php endforeach; ?>

                <li class="nav-item mt-4 pt-2 border-top border-secondary">
                    <a class="nav-link text-danger" href="<?php echo $prefix; ?>logout.php">
                        <i class="bi bi-box-arrow-right me-2"></i> Logout
                    </a>
                </li>
            </ul>
        </div>

        <div class="col-md-10 p-4" style="background: #f8f9fa; min-height: 100vh;">
<?php else: ?>
        <div class="col-12 p-4" style="background: #f8f9fa; min-height: 100vh;">
<?php endif; ?>
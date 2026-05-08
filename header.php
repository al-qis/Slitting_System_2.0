<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'Slitting System'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        .sidebar { min-vh-100; background: #212529; }
        .nav-link { transition: all 0.2s; border-radius: 4px; margin-bottom: 2px; }
        .nav-link:hover { background: rgba(255,255,255,0.1); color: #fff !important; }
        .active-nav { background: #0d6efd !important; color: #fff !important; font-weight: 600; }
        .sidebar-logo { border-bottom: 1px solid #444; padding-bottom: 1rem; margin-bottom: 1rem; }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 bg-dark text-white min-vh-100 p-3 shadow no-print">
            <div class="d-flex align-items-center justify-content-center sidebar-logo">
                <img src="assets/nichiaslogo.jpg" alt="Logo" style="max-width: 35px;" class="me-2 rounded shadow-sm">
                <h6 class="m-0 fw-bold">MK SLITTING</h6>
            </div>
            
            <ul class="nav flex-column">
                <?php
                $current_page = basename($_SERVER['PHP_SELF']);
                $menu_items = [
                    'index.php'            => ['icon' => 'speedometer2', 'label' => 'Dashboard'],
                    'mother_coil.php'      => ['icon' => 'layer-forward', 'label' => 'Mother Coil'],
                    'raw_material.php'     => ['icon' => 'box-seam', 'label' => 'Raw Material'],
                    'sfc.php'              => ['icon' => 'box-seam-fill', 'label' => 'SFC Inventory'],
                    'slitting_product.php' => ['icon' => 'scissors', 'label' => 'Slitting Product'],
                    'recoiling.php'        => ['icon' => 'arrow-repeat', 'label' => 'Recoiling Cut'],
                    'reslit.php'           => ['icon' => 'intersect', 'label' => 'Reslit Product'],
                    'finish_product.php'   => ['icon' => 'check-circle', 'label' => 'Finish Product'],
                    'report.php'           => ['icon' => 'file-earmark-bar-graph', 'label' => 'Report'],
                    'tracking_product.php' => ['icon' => 'globe2', 'label' => 'Traceability'],
                ];

                foreach ($menu_items as $url => $info):
                    $active = ($current_page == $url) ? 'active-nav' : '';
                ?>
                <li class="nav-item">
                    <a class="nav-link text-white <?php echo $active; ?>" href="<?php echo $url; ?>">
                        <i class="bi bi-<?php echo $info['icon']; ?> me-2"></i> <?php echo $info['label']; ?>
                    </a>
                </li>
                <?php endforeach; ?>
                
                <li class="nav-item mt-4 pt-2 border-top border-secondary">
                    <a class="nav-link text-danger" href="logout.php">
                        <i class="bi bi-box-arrow-right me-2"></i> Logout
                    </a>
                </li>
            </ul>
        </div>

        <div class="col-md-10 p-4" style="background: #f8f9fa; min-height: 100vh;">
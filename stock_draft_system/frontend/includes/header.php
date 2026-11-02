<?php
/**
 * Layout Header
 * Location: /stock_draft_system/frontend/includes/header.php
 */
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Draft System — Production Tracking Module</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Custom Styles -->
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow-sm">
    <div class="container-fluid px-4">
        <a class="navbar-brand d-flex align-items-center gap-2" href="stock_coils.php">
            <i class="fa-solid fa-cubes-stacked text-warning fs-4"></i>
            <span>Stock Draft System <span class="badge bg-primary fs-7 fw-normal ms-1">v2.0 Submodule</span></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-3">
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage === 'stock_coils.php' || $currentPage === 'index.php') ? 'active' : '' ?>" href="stock_coils.php">
                        <i class="fa-solid fa-layer-group me-1"></i> Stock Coils
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'create_draft.php' ? 'active' : '' ?>" href="create_draft.php">
                        <i class="fa-solid fa-plus-circle me-1 text-info"></i> Create Draft
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'drafts_management.php' ? 'active' : '' ?>" href="drafts_management.php">
                        <i class="fa-solid fa-file-signature me-1"></i> Drafts Management
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'movement_log.php' ? 'active' : '' ?>" href="movement_log.php">
                        <i class="fa-solid fa-clock-rotate-left me-1"></i> Movement Log
                    </a>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-3 text-light">
                <span class="badge bg-success px-3 py-2">
                    <i class="fa-solid fa-shield-halved me-1"></i> Read-Only Mode Active
                </span>
                <small class="text-white-50"><i class="fa-regular fa-user me-1"></i> Production Officer</small>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-4">

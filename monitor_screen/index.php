<?php
/**
 * index.php - Default Entrypoint for Monitor Screen Module
 * Location: /monitor_screen/index.php
 */

require_once __DIR__ . '/controllers/ProductionMonitoringController.php';

$controller = new ProductionMonitoringController();
$controller->index();

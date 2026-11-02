<?php
/**
 * production_monitoring.php - Real-Time Production Floor Live Monitor (TV Display)
 * MVC Entrypoint
 * Location: /monitor_screen/production_monitoring.php
 */

require_once __DIR__ . '/controllers/ProductionMonitoringController.php';

$controller = new ProductionMonitoringController();
$controller->index();

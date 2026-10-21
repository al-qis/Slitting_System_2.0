<?php
/**
 * production_monitoring_ajax.php - Real-Time Production Monitor AJAX Endpoint
 * MVC Entrypoint
 * Location: /monitor_screen/production_monitoring_ajax.php
 */

require_once __DIR__ . '/controllers/ProductionMonitoringController.php';

$controller = new ProductionMonitoringController();
$controller->ajaxGetData();

<?php
/**
 * Factory Floor Real-Time Production Monitoring
 * Delegator to MonitoringController::production (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\MonitoringController();
$controller->production();

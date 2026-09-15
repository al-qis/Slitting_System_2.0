<?php
/**
 * Officer Production Shift Monitor
 * Delegator to MonitoringController::officer (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\MonitoringController();
$controller->officer();

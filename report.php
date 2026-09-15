<?php
/**
 * Monthly Production Report
 * Delegator to ReportController::monthly (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\ReportController();
$controller->monthly();
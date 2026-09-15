<?php
/**
 * Process Log Viewer
 * Delegator to ReportController::processLog (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\ReportController();
$controller->processLog();
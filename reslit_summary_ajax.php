<?php
/**
 * Reslit Summary AJAX Endpoint
 * Refactored to delegate to App\Controllers\ReslitController (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\ReslitController();
$controller->summaryAjax();
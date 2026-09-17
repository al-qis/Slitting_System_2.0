<?php
/**
 * QC Scan Lookup
 * Refactored to delegate to App\Controllers\QCController (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\QCController();
$controller->scanLookup();

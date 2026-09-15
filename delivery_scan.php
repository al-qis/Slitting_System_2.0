<?php
/**
 * Finish Product Delivery Scan
 * Refactored to delegate to App\Controllers\PalletController (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\PalletController();
$controller->deliveryScan();
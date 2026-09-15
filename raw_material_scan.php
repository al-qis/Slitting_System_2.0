<?php
/**
 * Raw Material Inventory & Scan
 * Delegator to MotherCoilController::rawMaterialScan (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\MotherCoilController();
$controller->rawMaterialScan();
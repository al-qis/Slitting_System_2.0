<?php
/**
 * Raw Material - Available Stock
 * Delegator to MotherCoilController::rawMaterial (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\MotherCoilController();
$controller->rawMaterial();
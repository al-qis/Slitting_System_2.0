<?php
/**
 * Delegator to FinishedProductController::scanProduct (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\FinishedProductController();
$controller->scanProduct();

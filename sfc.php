<?php
/**
 * SFC Inventory Management
 * Delegator to SFCController::index (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\SFCController();
$controller->index();
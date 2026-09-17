<?php
/**
 * Delegator to FinishedProductController::editSlitting (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\FinishedProductController();
$controller->editSlitting();

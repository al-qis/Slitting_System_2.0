<?php
/**
 * Delegator to FinishedProductController::deleteSlitting (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\FinishedProductController();
$controller->deleteSlitting();

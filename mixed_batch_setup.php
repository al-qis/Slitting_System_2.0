<?php
/**
 * Mixed Batch Setup Page
 * Delegator to BatchController::mixedSetup (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\BatchController();
$controller->mixedSetup();

<?php
/**
 * Batch Setup & Print
 * Delegator to BatchController::setup (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\BatchController();
$controller->setup();

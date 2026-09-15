<?php
/**
 * Batch Setup Save Action (AJAX)
 * Delegator to BatchController::saveAll (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\BatchController();
$controller->saveAll();
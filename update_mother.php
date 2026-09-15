<?php
/**
 * Update Mother Coil Action Endpoint
 * Refactored to delegate to App\Controllers\MotherCoilController (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\MotherCoilController();
$controller->update();
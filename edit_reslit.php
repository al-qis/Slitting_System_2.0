<?php
/**
 * Edit Reslit Product Entry Point
 * Refactored to delegate to App\Controllers\ReslitController (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\ReslitController();
$controller->edit();
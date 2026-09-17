<?php
/**
 * Delete Recoiling Product Entry Point
 * Refactored to delegate to App\Controllers\RecoilingController (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\RecoilingController();
$controller->delete();
<?php
/**
 * Add To Recoiling Entry Point (Legacy from finish_product)
 * Refactored to delegate to App\Controllers\RecoilingController (MVC Pattern)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

$controller = new \App\Controllers\RecoilingController();
$controller->add();
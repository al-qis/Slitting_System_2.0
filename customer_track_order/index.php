<?php
/**
 * index.php - Default Entrypoint for Customer Track Order Module
 * Location: /customer_track_order/index.php
 */

require_once __DIR__ . '/controllers/CustomerTrackOrderController.php';

$controller = new CustomerTrackOrderController();
$controller->index();

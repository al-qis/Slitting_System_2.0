<?php
/**
 * customer_track_order_ajax.php - AJAX Entrypoint for Customer Track Order Module
 * Location: /customer_track_order/customer_track_order_ajax.php
 */

require_once __DIR__ . '/controllers/CustomerTrackOrderController.php';

$controller = new CustomerTrackOrderController();
$controller->handleAjax();

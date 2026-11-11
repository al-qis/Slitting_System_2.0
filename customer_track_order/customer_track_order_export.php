<?php
/**
 * customer_track_order_export.php
 * Standalone export endpoint for customer order tracking
 * Location: /customer_track_order/customer_track_order_export.php
 */

require_once __DIR__ . '/controllers/CustomerTrackOrderController.php';

$controller = new CustomerTrackOrderController();
$so = $_GET['so'] ?? ($_GET['ref_no'] ?? ($_GET['order'] ?? ''));

$controller->exportOrderExcel($so);

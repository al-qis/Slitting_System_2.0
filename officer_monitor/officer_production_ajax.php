<?php
/**
 * officer_production_ajax.php - Officer Monitor AJAX Endpoints
 * MVC Entrypoint
 * Location: /monitor_screen/officer_production_ajax.php
 */

require_once __DIR__ . '/controllers/OfficerProductionController.php';

$controller = new OfficerProductionController();
$controller->handleAjax();

<?php
/**
 * officer_production_monitor.php - Officer Production Dashboard & Pacing Monitor
 * MVC Entrypoint
 * Location: /monitor_screen/officer_production_monitor.php
 */

require_once __DIR__ . '/controllers/OfficerProductionController.php';

$controller = new OfficerProductionController();
$controller->index();

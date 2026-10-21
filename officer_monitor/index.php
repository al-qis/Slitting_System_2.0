<?php
/**
 * index.php - Default Entrypoint for Officer Monitor Module
 * Location: /officer_monitor/index.php
 */

require_once __DIR__ . '/controllers/OfficerProductionController.php';

$controller = new OfficerProductionController();
$controller->index();

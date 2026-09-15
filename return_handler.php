<?php
/**
 * Return Delivered Product Handler Delegator
 * Maintains backward compatibility with legacy routes.
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Controllers\FinishedProductController;

(new FinishedProductController())->returnHandler();

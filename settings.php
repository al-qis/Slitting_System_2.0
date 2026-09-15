<?php
/**
 * Settings Delegator
 * Maintains backward compatibility with legacy bookmarks and routes.
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Controllers\SettingsController;

(new SettingsController())->index();
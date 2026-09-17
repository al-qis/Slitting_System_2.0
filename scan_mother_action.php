<?php
/**
 * Barcode Scan Action for Mother Coil / Raw Material Delegator
 * Maintains backward compatibility with hardware barcode scanners, camera scanners, and direct POSTs.
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Controllers\MotherCoilController;

(new MotherCoilController())->scanMotherAction();
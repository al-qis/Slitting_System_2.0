<?php
// pallet_handler.php
// ============================================================
// PLACEMENT: Root of project (same folder as pallet.php)
// PURPOSE:  Handles the "Deliver" button from pallet.php's
//           all-pallets table (approved → delivered).
//           Also handles the POST from pallet.php itself for
//           the deliver_pallet action so we keep pallet.php lean.
// ============================================================

session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}
// Both slitting and QC can deliver
if (!in_array($_SESSION['role'], ['slitting', 'qc'], true)) {
    die("Access denied");
}

include 'config.php';
require_once 'pallet_helpers.php';

$action    = $_POST['action']    ?? '';
$pallet_id = intval($_POST['pallet_id'] ?? 0);

if ($pallet_id <= 0) {
    header("Location: pallet.php?error=" . urlencode('Invalid pallet ID.'));
    exit;
}

if ($action === 'deliver_pallet') {
    $result = deliverPallet($conn, $pallet_id);
    if ($result['ok']) {
        header("Location: pallet.php?success=delivered");
    } else {
        header("Location: pallet.php?error=" . urlencode($result['msg']));
    }
    exit;
}

header("Location: pallet.php");
exit;
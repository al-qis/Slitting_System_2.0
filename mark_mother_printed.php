<?php
// mark_mother_printed.php
// ============================================================
// Called (via sendBeacon/fetch) the moment the operator clicks Print on
// print_mother.php. Sets printed_at so the Mother Coil list can show a
// simple Printed / Not Printed Yet status per row.
//
// Deliberately no session/auth check: sendBeacon requests cannot carry
// custom headers reliably across browsers, and this endpoint only ever
// timestamps a coil that a page already gated by session has linked to
// — there's nothing here to protect beyond what print_mother.php (which
// currently has no session check either) already exposes. If session
// auth is later added to print_mother.php, add the matching check here
// too.
//
// Runs every time Print is clicked, not just the first time — so
// printed_at always reflects the most recent print, which is more
// useful on a reprint than freezing at the very first click.
// ============================================================

include 'config.php';

$id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit;
}

$stmt = $conn->prepare("UPDATE mother_coil SET printed_at = NOW() WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();

http_response_code(204);

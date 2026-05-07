<?php
// update_mother.php — fixed version
// Bugs fixed from original:
//   1. int($_POST['id']) → intval($_POST['id'])
//   2. die() statement had ":" instead of ";"
//   3. bind_param was called before execute (was correct in original but
//      the column list in the UPDATE was wrong — now matches actual schema)
//   4. prepare() result checked before bind_param

include 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: mother_coil.php");
    exit;
}

if (empty($_POST['id'])) {
    die("Invalid request: missing ID");
}

$id      = intval($_POST['id']);                 // FIX: was int() which doesn't exist
$coil_no = trim($_POST['coil_no'] ?? '');
$product = trim($_POST['product'] ?? '');
$lot_no  = trim($_POST['lot_no']  ?? '');
$grade   = trim($_POST['grade']   ?? '');
$width   = trim($_POST['width']   ?? '');
$length  = trim($_POST['length']  ?? '');

if ($coil_no === '' || $product === '' || $lot_no === '' || $width === '' || $length === '') {
    die("Required fields missing");  // FIX: was "missing"): with colon instead of semicolon
}

$grade = ($grade === '') ? null : $grade;

// Duplicate check — make sure we don't collide with another row
$check = $conn->prepare("SELECT id FROM mother_coil WHERE coil_no=? AND lot_no=? AND id != ?");
if (!$check) { die("Prepare failed: " . $conn->error); }
$check->bind_param("ssi", $coil_no, $lot_no, $id);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    $check->close();
    die("Duplicate: coil_no + lot_no combination already exists for another record.");
}
$check->close();

// FIX: column list now matches actual mother_coil schema (no 'size', 'nominal', 'effective')
$stmt = $conn->prepare("
    UPDATE mother_coil
    SET product=?, lot_no=?, coil_no=?, grade=?, width=?, length=?
    WHERE id=?
");
if (!$stmt) { die("Prepare failed: " . $conn->error); }

// FIX: bind_param must come BEFORE execute, and types must match column order above
$stmt->bind_param("sssssdi", $product, $lot_no, $coil_no, $grade, $width, $length, $id);

if (!$stmt->execute()) {
    die("SQL Error: " . $stmt->error);
}

$stmt->close();

header("Location: mother_coil.php?success=update");
exit;
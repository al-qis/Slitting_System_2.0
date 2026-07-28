<?php
// Prevent error messages from breaking the image stream
error_reporting(E_ALL & ~E_DEPRECATED); 
ini_set('display_errors', 0);

require __DIR__ . '/vendor/autoload.php';
include 'config.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;

$type = $_GET['type'] ?? 'mother';
$size = 300; // Optimal size for display
$qrText = '';

// --- LOGIC BLOCK ---
if ($type === 'slitting') {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) die('Invalid ID');

    $stmt = $conn->prepare("SELECT lot_no, coil_no, roll_no, width, length, actual_length FROM slitting_product WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) die('Slitting product not found');

    // Prefer actual_length over the original/nominal length whenever it's
    // been recorded — same "measured beats planned" rule already used for
    // display elsewhere in this system (finish_product.php, slitting_product.php,
    // print_slip.php). Falls back to length only if actual_length is still
    // blank (e.g. before QC has measured the roll).
    $effectiveLength = (!empty($row['actual_length']) && $row['actual_length'] > 0)
        ? $row['actual_length']
        : $row['length'];

    // New 5-field format: LOT / COIL / ROLL / WIDTH / LENGTH. WIDTH and
    // LENGTH are only appended when actually present on the row, so a
    // legacy row with no width/length recorded still generates a plain
    // 3-field QR — scan_product_action.php treats both shapes identically.
    $qrText = "LOT={$row['lot_no']};COIL={$row['coil_no']};ROLL={$row['roll_no']}";
    if ($row['width']        !== null && $row['width']        !== '') $qrText .= ";WIDTH={$row['width']}";
    if ($effectiveLength     !== null && $effectiveLength      !== '') $qrText .= ";LENGTH={$effectiveLength}";

} else {
    $lot  = trim($_GET['lot'] ?? '');
    $coil = trim($_GET['coil'] ?? '');
    $roll = trim($_GET['roll'] ?? '');
    $width  = trim($_GET['width']  ?? '');
    $length = trim($_GET['length'] ?? '');

    if ($lot === '' || $coil === '') die('Invalid QR data');

    // "LOT=826175;COIL=FK-1" or, when a roll is supplied (e.g. from
    // print_sfc.php), "LOT=826175;COIL=FK-1;ROLL=R2" — matching the same
    // format the 'slitting' branch above and the scan-matching code in
    // sfc.php / scan_product_action.php already expect. ROLL is only
    // appended when actually provided, since some SFC entries legitimately
    // have none (e.g. "BALANCE" rolls, already blanked out upstream).
    // WIDTH/LENGTH follow the same "only if provided" rule.
    $qrText = "LOT=$lot;COIL=$coil";
    if ($roll !== '')   $qrText .= ";ROLL=$roll";
    if ($width !== '')  $qrText .= ";WIDTH=$width";
    if ($length !== '') $qrText .= ";LENGTH=$length";
}

// Ensure there is always text
if (empty($qrText)) $qrText = "NO_DATA";

// --- GENERATION BLOCK (New Syntax) ---
$writer = new PngWriter();

// Create QR Code
$qrCode = QrCode::create($qrText)
    ->setEncoding(new Encoding('UTF-8'))
    ->setErrorCorrectionLevel(ErrorCorrectionLevel::Low) // Fixed the Fatal Error here
    ->setSize($size)
    ->setMargin(10)
    ->setForegroundColor(new Color(0, 0, 0))
    ->setBackgroundColor(new Color(255, 255, 255));

try {
    $result = $writer->write($qrCode);

    // Output Header
    header('Content-Type: ' . $result->getMimeType());
    header('Cache-Control: no-cache, must-revalidate');

    // Stream image
    echo $result->getString();
} catch (Exception $e) {
    die("Error generating QR code");
}
exit;
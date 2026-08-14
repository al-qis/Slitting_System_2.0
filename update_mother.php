<?php
// update_mother.php — updates mother coil details and slitting plan
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: mother_coil.php");
    exit;
}

if (empty($_POST['id'])) {
    die("Invalid request: missing ID");
}

$id      = intval($_POST['id']);
$coil_no = trim($_POST['coil_no'] ?? '');
$product = trim($_POST['product'] ?? '');
$lot_no  = trim($_POST['lot_no']  ?? '');
$grade   = trim($_POST['grade']   ?? '');
$width   = trim($_POST['width']   ?? '');
$length  = trim($_POST['length']  ?? '');

if ($coil_no === '' || $product === '' || $lot_no === '' || $width === '' || $length === '') {
    die("Required fields missing");
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

$conn->begin_transaction();

try {
    // 1. Update mother_coil
    $stmt = $conn->prepare("
        UPDATE mother_coil
        SET product=?, lot_no=?, coil_no=?, grade=?, width=?, length=?
        WHERE id=?
    ");
    if (!$stmt) { throw new Exception("Prepare failed: " . $conn->error); }
    $stmt->bind_param("sssssdi", $product, $lot_no, $coil_no, $grade, $width, $length, $id);
    if (!$stmt->execute()) {
        throw new Exception("SQL Error: " . $stmt->error);
    }
    $stmt->close();

    // 2. Update slitting_plans (delete old and re-insert updated plan rows)
    $delPlan = $conn->prepare("DELETE FROM slitting_plans WHERE mother_coil_id = ?");
    if ($delPlan) {
        $delPlan->bind_param("i", $id);
        $delPlan->execute();
        $delPlan->close();
    }

    $knownCustomers = ['NAE','NAX','NCI MFG','TAIHO','NRI','ASHUKA','NIPPON','NTC','SGC','STAMPING','YANTAI','NIPP','NVC','NSJ','NIP','YTEC','NSA','NCI 2','STOCK','TRIAL'];

    if (!function_exists('sanitizeCustomerCode')) {
        function sanitizeCustomerCode($cust, $knownCustomers) {
            $cust = strtoupper(trim((string)$cust));
            if ($cust === '') return null;
            foreach ($knownCustomers as $k) {
                if (strtoupper($k) === $cust) {
                    return $k;
                }
            }
            return null;
        }
    }

    $planSeqs      = $_POST['plan_seq']      ?? [];
    $planWidths    = $_POST['plan_width']    ?? [];
    $planCustomers = $_POST['plan_customer'] ?? [];
    $planRefs      = $_POST['plan_ref']      ?? [];

    if (is_array($planSeqs) && is_array($planWidths)) {
        $insPlan = $conn->prepare("
            INSERT INTO slitting_plans (mother_coil_id, roll_seq, planned_width, customer_name, ref_no, sort_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if ($insPlan) {
            $order = 0;
            foreach ($planSeqs as $i => $seqRaw) {
                $seq   = trim($seqRaw);
                $wRaw  = trim($planWidths[$i] ?? '');
                $cRaw  = trim($planCustomers[$i] ?? '');
                $refNo = trim($planRefs[$i] ?? '');
                if ($seq === '' || $wRaw === '' || !is_numeric($wRaw)) continue;
                $order++;
                $widthVal = (float)$wRaw;
                $cust = sanitizeCustomerCode($cRaw, $knownCustomers);
                $insPlan->bind_param("isdssi", $id, $seq, $widthVal, $cust, $refNo, $order);
                $insPlan->execute();
            }
            $insPlan->close();
        }
    }

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    die("Update failed: " . htmlspecialchars($e->getMessage()));
}

header("Location: mother_coil.php?success=update");
exit;
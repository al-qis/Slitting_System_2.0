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

// Fetch current values before update for cascading downstream changes
$oldStmt = $conn->prepare("SELECT lot_no, coil_no, product FROM mother_coil WHERE id = ?");
if (!$oldStmt) { die("Prepare failed: " . $conn->error); }
$oldStmt->bind_param("i", $id);
$oldStmt->execute();
$oldRes = $oldStmt->get_result();
if (!$oldRes || $oldRes->num_rows === 0) {
    $oldStmt->close();
    die("Mother coil not found for ID: " . $id);
}
$oldMother = $oldRes->fetch_assoc();
$oldStmt->close();

$old_lot_no  = $oldMother['lot_no'];
$old_coil_no = $oldMother['coil_no'];
$old_product = $oldMother['product'];

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

    // 2. Cascade updates to downstream tables if coil_no, lot_no, or product changed
    $coil_changed    = ($old_coil_no !== $coil_no);
    $lot_changed     = ($old_lot_no  !== $lot_no);
    $product_changed = ($old_product !== $product);

    if ($coil_changed || $lot_changed || $product_changed) {
        // A. Update slitting_product
        $sp_sets   = [];
        $sp_types  = "";
        $sp_params = [];

        if ($coil_changed) {
            $sp_sets[]   = "coil_no = ?";
            $sp_types   .= "s";
            $sp_params[] = $coil_no;
        }
        if ($product_changed) {
            $sp_sets[]   = "product = ?";
            $sp_types   .= "s";
            $sp_params[] = $product;
        }
        if ($lot_changed) {
            $sp_sets[]   = "lot_no = CASE WHEN lot_no LIKE CONCAT(?, '%') THEN CONCAT(?, SUBSTRING(lot_no, CHAR_LENGTH(?) + 1)) ELSE ? END";
            $sp_types   .= "ssss";
            $sp_params[] = $old_lot_no;
            $sp_params[] = $lot_no;
            $sp_params[] = $old_lot_no;
            $sp_params[] = $lot_no;
        }
        // Reset print status so operators know stickers should be reprinted if label specs changed
        $sp_sets[] = "is_printed = 0";

        $sp_sql = "UPDATE slitting_product SET " . implode(", ", $sp_sets) . " WHERE mother_id = ?";
        $sp_types   .= "i";
        $sp_params[] = $id;

        $stmtSP = $conn->prepare($sp_sql);
        if ($stmtSP) {
            $stmtSP->bind_param($sp_types, ...$sp_params);
            $stmtSP->execute();
            $stmtSP->close();
        }

        // B. Update stock_raw_material
        $st_sets   = [];
        $st_types  = "";
        $st_params = [];

        if ($coil_changed) {
            $st_sets[]   = "coil_no = ?";
            $st_types   .= "s";
            $st_params[] = $coil_no;
        }
        if ($lot_changed) {
            $st_sets[]   = "lot_no = CASE WHEN lot_no LIKE CONCAT(?, '%') THEN CONCAT(?, SUBSTRING(lot_no, CHAR_LENGTH(?) + 1)) ELSE ? END";
            $st_types   .= "ssss";
            $st_params[] = $old_lot_no;
            $st_params[] = $lot_no;
            $st_params[] = $old_lot_no;
            $st_params[] = $lot_no;
        }

        if (!empty($st_sets)) {
            $st_sql = "UPDATE stock_raw_material SET " . implode(", ", $st_sets) . " WHERE source_id = ?";
            $st_types   .= "i";
            $st_params[] = $id;

            $stmtST = $conn->prepare($st_sql);
            if ($stmtST) {
                $stmtST->bind_param($st_types, ...$st_params);
                $stmtST->execute();
                $stmtST->close();
            }
        }

        // C. Update sfc
        $sfc_sets   = [];
        $sfc_types  = "";
        $sfc_params = [];

        if ($coil_changed) {
            $sfc_sets[]   = "coil_no = ?";
            $sfc_types   .= "s";
            $sfc_params[] = $coil_no;
        }
        if ($product_changed) {
            $sfc_sets[]   = "product = ?";
            $sfc_types   .= "s";
            $sfc_params[] = $product;
        }
        if ($lot_changed) {
            $sfc_sets[]   = "lot_no = CASE WHEN lot_no LIKE CONCAT(?, '%') THEN CONCAT(?, SUBSTRING(lot_no, CHAR_LENGTH(?) + 1)) ELSE ? END";
            $sfc_types   .= "ssss";
            $sfc_params[] = $old_lot_no;
            $sfc_params[] = $lot_no;
            $sfc_params[] = $old_lot_no;
            $sfc_params[] = $lot_no;
        }

        if (!empty($sfc_sets)) {
            $sfc_sql = "UPDATE sfc SET " . implode(", ", $sfc_sets) . " WHERE mother_id = ?";
            $sfc_types   .= "i";
            $sfc_params[] = $id;

            $stmtSFC = $conn->prepare($sfc_sql);
            if ($stmtSFC) {
                $stmtSFC->bind_param($sfc_types, ...$sfc_params);
                $stmtSFC->execute();
                $stmtSFC->close();
            }
        }

        // D. Update recoiling_product
        $rec_sets   = [];
        $rec_types  = "";
        $rec_params = [];

        if ($coil_changed) {
            $rec_sets[]   = "coil_no = ?";
            $rec_types   .= "s";
            $rec_params[] = $coil_no;
        }
        if ($product_changed) {
            $rec_sets[]   = "product = ?";
            $rec_types   .= "s";
            $rec_params[] = $product;
        }
        if ($lot_changed) {
            $rec_sets[]   = "lot_no = CASE WHEN lot_no LIKE CONCAT(?, '%') THEN CONCAT(?, SUBSTRING(lot_no, CHAR_LENGTH(?) + 1)) ELSE ? END";
            $rec_types   .= "ssss";
            $rec_params[] = $old_lot_no;
            $rec_params[] = $lot_no;
            $rec_params[] = $old_lot_no;
            $rec_params[] = $lot_no;
        }

        if (!empty($rec_sets)) {
            $rec_sql = "UPDATE recoiling_product SET " . implode(", ", $rec_sets) . " WHERE mother_id = ?";
            $rec_types   .= "i";
            $rec_params[] = $id;

            $stmtRec = $conn->prepare($rec_sql);
            if ($stmtRec) {
                $stmtRec->bind_param($rec_types, ...$rec_params);
                $stmtRec->execute();
                $stmtRec->close();
            }
        }

        // E. Update reslit_product
        $res_sets   = [];
        $res_types  = "";
        $res_params = [];

        if ($coil_changed) {
            $res_sets[]   = "coil_no = ?";
            $res_types   .= "s";
            $res_params[] = $coil_no;
        }
        if ($product_changed) {
            $res_sets[]   = "product = ?";
            $res_types   .= "s";
            $res_params[] = $product;
        }
        if ($lot_changed) {
            $res_sets[]   = "lot_no = CASE WHEN lot_no LIKE CONCAT(?, '%') THEN CONCAT(?, SUBSTRING(lot_no, CHAR_LENGTH(?) + 1)) ELSE ? END";
            $res_types   .= "ssss";
            $res_params[] = $old_lot_no;
            $res_params[] = $lot_no;
            $res_params[] = $old_lot_no;
            $res_params[] = $lot_no;
        }

        if (!empty($res_sets)) {
            $res_sql = "UPDATE reslit_product SET " . implode(", ", $res_sets) . " WHERE mother_id = ?";
            $res_types   .= "i";
            $res_params[] = $id;

            $stmtRes = $conn->prepare($res_sql);
            if ($stmtRes) {
                $stmtRes->bind_param($res_types, ...$res_params);
                $stmtRes->execute();
                $stmtRes->close();
            }
        }
    }

    // 3. Update slitting_plans (delete old and re-insert updated plan rows)
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
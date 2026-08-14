<?php
include 'config.php';

// ── Helper: write one process_log row (shared pattern used across the app) ──
if (!function_exists('log_process')) {
    function log_process(
        mysqli  $conn,
        string  $entity_type,
        int     $entity_id,
        ?int    $mother_id,
        ?string $from_status,
        string  $to_status,
        string  $action_detail = '',
        string  $remark = ''
    ): void {
        $performed_by = $_SESSION['role'] ?? 'system';
        $stmt = $conn->prepare("
            INSERT INTO process_log
                (entity_type, entity_id, mother_id, from_status, to_status,
                 performed_by, action_detail, remark)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "siisssss",
            $entity_type, $entity_id, $mother_id,
            $from_status, $to_status,
            $performed_by, $action_detail, $remark
        );
        $stmt->execute();
        $stmt->close();
    }
}

/* ──────────────────────────────────────────────────────────────
   Helper: return ALL products that match a coil_no prefix
   Returns an array (may be empty, 1 item, or multiple items)
────────────────────────────────────────────────────────────── */
function productsFromCoil(mysqli $conn, string $coil_no): array
{
    $coil_no = strtoupper(trim($coil_no));
    if ($coil_no === '') return [];

    preg_match('/^[A-Z0-9]+/', $coil_no, $m);
    $token = $m[0] ?? '';
    if ($token === '') return [];

    for ($len = strlen($token); $len >= 1; $len--) {
        $code = substr($token, 0, $len);

        $stmt = $conn->prepare(
            "SELECT product FROM coil_product_map WHERE coil_code = ? ORDER BY product"
        );
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $res  = $stmt->get_result();
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (count($rows) > 0) {
            return array_column($rows, 'product');   // e.g. ['RS-3825', 'RS-3825-04']
        }
    }
    return [];
}

/* ──────────────────────────────────────────────────────────────
   AJAX endpoint: mother_coil.php?ajax=validate_product&code=...
   Used by Bulk Add's auto-detected Product column (pasted from
   Excel): validates the pasted text against known products in
   coil_product_map (the same source of truth productsFromCoil()
   already uses) rather than trusting pasted text blindly. Exact,
   case-insensitive match only — no fuzzy/partial matching, since a
   wrong guess here would silently mis-assign a real product.
────────────────────────────────────────────────────────────── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'validate_product') {
    header('Content-Type: application/json; charset=utf-8');

    $code = trim($_GET['code'] ?? '');
    if ($code === '') { echo json_encode(['ok' => true, 'found' => false]); exit; }

    $stmt = $conn->prepare("SELECT DISTINCT product FROM coil_product_map WHERE product = ? LIMIT 1");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    echo json_encode(['ok' => true, 'found' => (bool)$row, 'product' => $row['product'] ?? null]);
    exit;
}

/* ──────────────────────────────────────────────────────────────
   AJAX endpoint: mother_coil.php?ajax=search_coil&q=...
   "Quick Search & Add" (Option B) for Bulk Print for Slitting Plan —
   returns matching mother_coil rows for the operator to pick from.
   Deliberately returns candidates rather than guessing, since coil_no
   alone is NOT globally unique (only the coil_no + lot_no combination
   is enforced unique on add), so a bare coil number can legitimately
   match more than one row across different lots.
────────────────────────────────────────────────────────────── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_coil') {
    header('Content-Type: application/json; charset=utf-8');

    $q = trim($_GET['q'] ?? '');
    if ($q === '') { echo json_encode(['ok' => false, 'results' => []]); exit; }

    $like = '%' . $q . '%';
    $stmt = $conn->prepare("
        SELECT id, product, lot_no, coil_no, grade, width, length
        FROM mother_coil
        WHERE coil_no LIKE ? OR lot_no LIKE ? OR product LIKE ?
        ORDER BY id DESC
        LIMIT 15
    ");
    $stmt->bind_param("sss", $like, $like, $like);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode(['ok' => true, 'results' => $rows]);
    exit;
}

/* ──────────────────────────────────────────────────────────────
   AJAX endpoint: mother_coil.php?ajax=resolve_bulk_paste (POST)
   "Text Input / Copy-Paste" (Option A) for Bulk Print for Slitting Plan.
   Body: { text: "raw pasted text" }
   Splits on commas AND newlines. Each token can be either:
     - "LOT COIL" (two words together, e.g. "826403a N-4") — matched
       exactly against lot_no + coil_no, the unambiguous case.
     - a single word — tried first as an exact coil_no match, then an
       exact lot_no match. If that single word matches more than one
       row (coil_no is not globally unique), it's reported as
       ambiguous rather than guessed, with all candidates listed so
       the operator can resolve it via Quick Search instead.
   Returns { resolved: [...rows], ambiguous: [{token, candidates}],
             not_found: [token, ...] }
────────────────────────────────────────────────────────────── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'resolve_bulk_paste' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $body = json_decode(file_get_contents('php://input'), true);
    $text = trim($body['text'] ?? '');

    $lines = preg_split('/[\r\n,]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

    $resolved  = [];
    $ambiguous = [];
    $not_found = [];
    $seenIds   = [];

    $stmtExact2 = $conn->prepare("SELECT id, product, lot_no, coil_no, grade, width, length FROM mother_coil WHERE lot_no = ? AND coil_no = ?");
    $stmtByCoil = $conn->prepare("SELECT id, product, lot_no, coil_no, grade, width, length FROM mother_coil WHERE coil_no = ?");
    $stmtByLot  = $conn->prepare("SELECT id, product, lot_no, coil_no, grade, width, length FROM mother_coil WHERE lot_no = ?");

    foreach ($lines as $rawLine) {
        $line = trim($rawLine);
        if ($line === '') continue;

        $parts = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);

        $matches = [];
        if (count($parts) >= 2) {
            // "LOT COIL" together — unambiguous, exact match on both.
            $lot  = $parts[0];
            $coil = $parts[1];
            $stmtExact2->bind_param("ss", $lot, $coil);
            $stmtExact2->execute();
            $matches = $stmtExact2->get_result()->fetch_all(MYSQLI_ASSOC);
        } else {
            // Single token — try coil_no first, then lot_no.
            $token = $parts[0];
            $stmtByCoil->bind_param("s", $token);
            $stmtByCoil->execute();
            $matches = $stmtByCoil->get_result()->fetch_all(MYSQLI_ASSOC);

            if (empty($matches)) {
                $stmtByLot->bind_param("s", $token);
                $stmtByLot->execute();
                $matches = $stmtByLot->get_result()->fetch_all(MYSQLI_ASSOC);
            }
        }

        if (count($matches) === 1) {
            $row = $matches[0];
            if (!isset($seenIds[$row['id']])) {
                $resolved[] = $row;
                $seenIds[$row['id']] = true;
            }
        } elseif (count($matches) > 1) {
            $ambiguous[] = ['token' => $line, 'candidates' => $matches];
        } else {
            $not_found[] = $line;
        }
    }
    $stmtExact2->close();
    $stmtByCoil->close();
    $stmtByLot->close();

    echo json_encode([
        'ok'        => true,
        'resolved'  => $resolved,
        'ambiguous' => $ambiguous,
        'not_found' => $not_found,
    ]);
    exit;
}

/* ──────────────────────────────────────────────────────────────
   AJAX endpoint: mother_coil.php?ajax=get_product&coil=...
   Now returns:
     { ok: true/false, products: [...], product: "single or empty" }
────────────────────────────────────────────────────────────── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_product') {
    header('Content-Type: application/json; charset=utf-8');

    $coil     = $_GET['coil'] ?? '';
    $products = productsFromCoil($conn, $coil);

    echo json_encode([
        'ok'       => count($products) > 0,
        'products' => $products,
        'product'  => count($products) === 1 ? $products[0] : '',   // backward-compat
    ]);
    exit;
}

/* ──────────────────────────────────────────────────────────────
   AJAX endpoint: mother_coil.php?ajax=get_slitting_plan&mother_id=...
   Returns existing slitting_plans for edit modal
────────────────────────────────────────────────────────────── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_slitting_plan') {
    header('Content-Type: application/json; charset=utf-8');

    $mother_id = intval($_GET['mother_id'] ?? 0);
    $plans = [];
    if ($mother_id > 0) {
        $stmt = $conn->prepare("SELECT roll_seq, planned_width, customer_name, ref_no FROM slitting_plans WHERE mother_coil_id = ? ORDER BY sort_order ASC, id ASC");
        $stmt->bind_param("i", $mother_id);
        $stmt->execute();
        $plans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    echo json_encode(['ok' => true, 'plans' => $plans]);
    exit;
}

/* ──────────────────────────────────────────────────────────────
   Session / auth guard
────────────────────────────────────────────────────────────── */
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if (!in_array($_SESSION['role'], ['slitting', 'mkl3'], true)) {
    die("Access denied");
}

// ── Alert state from URL ──────────────────────────────────────
$success = $_GET['success'] ?? null;
$error   = $_GET['error']   ?? null;

$error_messages = [
    'not_found'     => '❌ Mother coil not found. It may have already been deleted.',
    'missing_id'    => '❌ No ID was provided.',
    'invalid_id'    => '❌ Invalid ID.',
    'delete_failed' => '❌ Delete failed: ' . htmlspecialchars(urldecode($_GET['msg'] ?? 'Unknown error')),
];

$childrenUpdatedCount = isset($_GET['children_updated']) ? (int)$_GET['children_updated'] : null;

$success_messages = [
    '1'      => '✅ Mother coil saved successfully.',
    '3'      => '✅ Mother coil deleted successfully.',
    'update' => ($childrenUpdatedCount !== null)
        ? ($childrenUpdatedCount > 0
            ? "✅ Mother coil updated successfully. Lot No. change cascaded to <strong>{$childrenUpdatedCount}</strong> child roll(s) on Finished Product."
            : "✅ Mother coil updated successfully. Lot No. changed, but no child rolls were found to update.")
        : '✅ Mother coil updated successfully.',
];

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

/* ──────────────────────────────────────────────────────────────
   POST ACTIONS (ADD / UPDATE)
────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';

    /* ─────────────────────────────────────────────
       BULK ADD (Excel multi-row paste)
       Accepts rows_json = JSON array of
       { lot_no, coil_no, grade, width, length, product }
       Inserts each valid, non-duplicate row; reports
       per-row results as JSON instead of redirecting.
    ───────────────────────────────────────────── */
    if ($action === 'bulk_add') {
        header('Content-Type: application/json; charset=utf-8');

        $rows = json_decode($_POST['rows_json'] ?? '[]', true);
        if (!is_array($rows)) $rows = [];

        /* ── Shared Slitting Plan ────────────────────────────────
           Entered once by the officer, applied to EVERY mother coil
           successfully inserted from this batch. Blank/invalid rows
           are skipped, same as the single-add plan. */
        $planRowsRaw = json_decode($_POST['plan_rows_json'] ?? '[]', true);
        $sharedPlanRows = [];
        if (is_array($planRowsRaw)) {
            $order = 0;
            foreach ($planRowsRaw as $pr) {
                $seq   = trim($pr['seq']   ?? '');
                $wRaw  = trim($pr['width'] ?? '');
                $cRaw  = trim($pr['customer'] ?? '');
                $refNo = trim($pr['ref_no']   ?? '');
                if ($seq === '' || $wRaw === '' || !is_numeric($wRaw)) continue;
                $order++;
                $cust = sanitizeCustomerCode($cRaw, $knownCustomers);
                $sharedPlanRows[] = ['seq' => $seq, 'width' => (float)$wRaw, 'customer' => $cust, 'ref_no' => $refNo, 'order' => $order];
            }
        }

        $planStmt = null;
        if (!empty($sharedPlanRows)) {
            $planStmt = $conn->prepare("
                INSERT INTO slitting_plans (mother_coil_id, roll_seq, planned_width, customer_name, ref_no, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
        }

        $inserted = 0;
        $skipped  = [];

        foreach ($rows as $i => $r) {
            $r_lot     = trim($r['lot_no']  ?? '');
            $r_coil    = trim($r['coil_no'] ?? '');
            $r_grade   = trim($r['grade']   ?? '');
            $r_width   = trim($r['width']   ?? '');
            $r_length  = trim($r['length']  ?? '');
            $r_product = trim($r['product'] ?? '');

            if ($r_lot === '' || $r_coil === '' || $r_grade === '' || $r_width === '' || $r_length === '' || $r_product === '') {
                $skipped[] = ['row' => $i + 1, 'reason' => 'Missing required field'];
                continue;
            }

            $dup_stmt = $conn->prepare("SELECT id FROM mother_coil WHERE coil_no = ? AND lot_no = ?");
            $dup_stmt->bind_param("ss", $r_coil, $r_lot);
            $dup_stmt->execute();
            $dup_res = $dup_stmt->get_result();
            if ($dup_res->num_rows > 0) {
                $skipped[] = ['row' => $i + 1, 'reason' => "Duplicate Lot $r_lot / Coil $r_coil"];
                $dup_stmt->close();
                continue;
            }
            $dup_stmt->close();

            $stmt = $conn->prepare("
                INSERT INTO mother_coil (product, grade, lot_no, coil_no, width, length, date_created, status)
                VALUES (?,?,?,?,?,?,NOW(),'NEW')
            ");
            $stmt->bind_param("ssssss", $r_product, $r_grade, $r_lot, $r_coil, $r_width, $r_length);
            $stmt->execute();
            $newMotherId = $stmt->insert_id;
            $stmt->close();
            $inserted++;

            // Apply the shared plan to this mother coil, if one was provided
            if ($planStmt && !empty($sharedPlanRows)) {
                foreach ($sharedPlanRows as $pr) {
                    $planStmt->bind_param("isdssi", $newMotherId, $pr['seq'], $pr['width'], $pr['customer'], $pr['ref_no'], $pr['order']);
                    $planStmt->execute();
                }
            }
        }

        if ($planStmt) $planStmt->close();

        echo json_encode(['ok' => true, 'inserted' => $inserted, 'skipped' => $skipped]);
        exit;
    }

    $id      = intval($_POST['id'] ?? 0);
    $lot_no  = trim($_POST['lot_no']  ?? '');
    $coil_no = trim($_POST['coil_no'] ?? '');
    $grade   = trim($_POST['grade']   ?? '');
    $width   = trim($_POST['width']   ?? '');
    $length  = trim($_POST['length']  ?? '');
    $product = trim($_POST['product'] ?? '');

    // For ADD: if product not posted (shouldn't happen), fall back to lookup
    if ($action === 'add' && $product === '') {
        $found = productsFromCoil($conn, $coil_no);
        $product = count($found) === 1 ? $found[0] : '';
    }

    if ($lot_no === '' || $coil_no === '' || $grade === '' || $width === '' || $length === '' || $product === '') {
        die("Required field missing (including product).");
    }

    // Duplicate check
    $check_stmt = $conn->prepare(
        "SELECT id FROM mother_coil WHERE coil_no = ? AND lot_no = ? AND id != ?"
    );
    $check_stmt->bind_param("ssi", $coil_no, $lot_no, $id);
    $check_stmt->execute();
    $check_res = $check_stmt->get_result();

    if ($check_res->num_rows > 0) {
        die("<div style='color:red;font-family:sans-serif;padding:20px;border:1px solid red;background:#fff5f5;'>
                <h2>Registration Failed</h2>
                <p><strong>Duplicate Error:</strong> The combination of Coil: <b>$coil_no</b> and Lot: <b>$lot_no</b> already exists.</p>
                <p>Please check your data or add an alphabet suffix (e.g., {$lot_no}a) if this is a repeat entry.</p>
                <button onclick='history.back()'>Go Back and Fix</button>
             </div>");
    }
    $check_stmt->close();

    if ($action === 'add') {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("
                INSERT INTO mother_coil (product, grade, lot_no, coil_no, width, length, date_created, status)
                VALUES (?,?,?,?,?,?,NOW(),'NEW')
            ");
            if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
            $stmt->bind_param("ssssss", $product, $grade, $lot_no, $coil_no, $width, $length);
            if (!$stmt->execute()) throw new Exception("Failed to insert mother coil: " . $stmt->error);
            $newMotherId = $stmt->insert_id;
            $stmt->close();

            /* ── Optional Slitting Plan ─────────────────────────────
               plan_seq[] / plan_width[] arrive together whether the officer
               typed rows manually or pasted from Excel — both funnel into
               the same inputs client-side. Empty/absent is not an error;
               the form must succeed either way. Blank or non-numeric rows
               (e.g. a leftover empty row) are silently skipped.

               The plan only stores roll widths — the operator picks Normal
               vs. Cut Into 2 on the floor in add_slitting.php; whichever
               they pick, the plan's roll count and widths are applied. */
            $planSeqs      = $_POST['plan_seq']      ?? [];
            $planWidths    = $_POST['plan_width']    ?? [];
            $planCustomers = $_POST['plan_customer'] ?? [];
            $planRefs      = $_POST['plan_ref']      ?? [];

            if (is_array($planSeqs) && is_array($planWidths)) {
                $planStmt = $conn->prepare("
                    INSERT INTO slitting_plans (mother_coil_id, roll_seq, planned_width, customer_name, ref_no, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                if (!$planStmt) throw new Exception("Prepare failed (plan): " . $conn->error);

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
                    $planStmt->bind_param("isdssi", $newMotherId, $seq, $widthVal, $cust, $refNo, $order);
                    if (!$planStmt->execute()) {
                        throw new Exception("Failed to insert slitting plan row: " . $planStmt->error);
                    }
                }
                $planStmt->close();
            }

            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            die("Registration failed: " . htmlspecialchars($e->getMessage()));
        }

        header("Location: mother_coil.php?success=1");
        exit;
    }

    if ($action === 'update' && $id > 0) {
        // Fetch the CURRENT lot_no before overwriting it — we need the OLD
        // value to know what prefix to look for on child rolls.
        $curStmt = $conn->prepare("SELECT lot_no FROM mother_coil WHERE id = ?");
        $curStmt->bind_param("i", $id);
        $curStmt->execute();
        $curRow = $curStmt->get_result()->fetch_assoc();
        $curStmt->close();

        if (!$curRow) {
            die("Mother coil not found.");
        }
        $oldLotNo     = $curRow['lot_no'];
        $lotNoChanged = ($oldLotNo !== $lot_no);

        $conn->begin_transaction();
        try {
            // 1. Update the mother coil itself
            $stmt = $conn->prepare("
                UPDATE mother_coil
                SET product=?, grade=?, lot_no=?, coil_no=?, width=?, length=?
                WHERE id=?
            ");
            if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
            $stmt->bind_param("ssssssi", $product, $grade, $lot_no, $coil_no, $width, $length, $id);
            if (!$stmt->execute()) {
                throw new Exception("Failed to update mother coil: " . $stmt->error);
            }
            $stmt->close();

            // Update slitting_plans for this mother_coil
            $delPlan = $conn->prepare("DELETE FROM slitting_plans WHERE mother_coil_id = ?");
            if ($delPlan) {
                $delPlan->bind_param("i", $id);
                $delPlan->execute();
                $delPlan->close();
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

            $childrenUpdated = 0;

            // 2. Cascade the lot_no rename to every child slitting_product row
            //    linked to this mother — this is the "Finished Product" stage
            //    the mismatch was reported at.
            //
            //    IMPORTANT: a child's lot_no is not always identical to the
            //    mother's — reslit/recoiled children pick up a letter suffix
            //    (e.g. mother "826604" -> child "826604a"). A blind overwrite
            //    would destroy that suffix, so instead we replace only the OLD
            //    lot_no PREFIX and keep whatever comes after it intact:
            //    "826604a" -> "{new lot_no}a".
            if ($lotNoChanged) {
                $childStmt = $conn->prepare("
                    SELECT id, lot_no FROM slitting_product
                    WHERE mother_id = ? AND (is_voided = 0 OR is_voided IS NULL)
                ");
                $childStmt->bind_param("i", $id);
                $childStmt->execute();
                $childRows = $childStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $childStmt->close();

                $updChild = $conn->prepare("UPDATE slitting_product SET lot_no = ? WHERE id = ?");

                foreach ($childRows as $child) {
                    $childLot = $child['lot_no'] ?? '';

                    // Only touch rows whose lot_no actually starts with the OLD
                    // mother lot_no. This is what preserves any suffix, and it
                    // also defensively skips anything that doesn't match the
                    // expected pattern rather than guessing and risking
                    // corrupting unrelated data.
                    if (strpos($childLot, $oldLotNo) !== 0) {
                        continue;
                    }

                    $suffix      = substr($childLot, strlen($oldLotNo));
                    $newChildLot = $lot_no . $suffix;

                    if ($newChildLot === $childLot) {
                        continue; // no actual change for this row
                    }

                    $updChild->bind_param("si", $newChildLot, $child['id']);
                    if (!$updChild->execute()) {
                        // Most likely cause: renaming this child would collide
                        // with the (lot_no, coil_no, roll_no) UNIQUE KEY of some
                        // unrelated existing row. Abort the whole cascade rather
                        // than leave it half-applied.
                        throw new Exception(
                            "Could not rename child roll (old lot: {$childLot}) to " .
                            "\"{$newChildLot}\" — likely a duplicate Lot/Coil/Roll " .
                            "combination already exists. " . $updChild->error
                        );
                    }
                    $childrenUpdated++;

                    // Lightweight audit trail — logged per child roll using
                    // entity_type='slitting' (a value process_log already
                    // accepts elsewhere in the app). 'mother_coil' is not a
                    // valid entity_type, so we don't log a separate parent-
                    // level entry; each renamed child gets its own line
                    // instead, which is arguably more useful anyway.
                    if (function_exists('log_process')) {
                        log_process($conn, 'slitting', (int)$child['id'], $id,
                            $childLot, $newChildLot, 'lot_no_cascade_rename',
                            "Cascaded from mother_coil id={$id} lot_no rename " .
                            "(\"{$oldLotNo}\" -> \"{$lot_no}\")"
                        );
                    }
                }
                $updChild->close();
            }

            $conn->commit();
            $successParams = 'success=update';
            if ($lotNoChanged) { $successParams .= '&children_updated=' . $childrenUpdated; }
            header("Location: mother_coil.php?{$successParams}");
            exit;

        } catch (Throwable $e) {
            $conn->rollback();
            die("<div style='color:red;font-family:sans-serif;padding:20px;border:1px solid red;background:#fff5f5;'>
                    <h2>Update Failed — No Changes Saved</h2>
                    <p><strong>Reason:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
                    <p>The Mother Coil update was rolled back entirely (including the lot number
                       change itself) so the parent and its child rolls never end up out of sync.</p>
                    <button onclick='history.back()'>Go Back and Fix</button>
                 </div>");
        }
    }
}

/* ──────────────────────────────────────────────────────────────
   SEARCH / LIST

   Tokenize search: split on whitespace so users can type e.g.
   "826403a N-4 DS-3020" (Lot + Coil + Product together) and get
   an exact match across all three, instead of one literal string
   matched against a single column (which would find nothing).

     1 word   -> broad OR across coil_no, lot_no, product
                 (existing behavior — unchanged)
     2 words  -> token1 -> lot_no, token2 -> coil_no
     3+ words -> token1 -> lot_no, token2 -> coil_no, token3 -> product
                 (tokens beyond the 3rd are ignored)

   PRINT STATUS FILTER
   Combinable with the search above via ?print_status=printed|not_printed
   (any other/absent value means "All" — no filtering by print status).
────────────────────────────────────────────────────────────── */
$search = trim($_GET['search'] ?? '');
$tokens = $search !== '' ? preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) : [];

$printFilter = $_GET['print_status'] ?? '';
if (!in_array($printFilter, ['printed', 'not_printed'], true)) {
    $printFilter = ''; // treat anything else as "All"
}

$where  = [];
$params = [];
$types  = '';

if (count($tokens) === 1) {
    // Single keyword — original broad OR search across all columns
    $searchTerm = '%' . $tokens[0] . '%';
    $where[]  = '(coil_no LIKE ? OR lot_no LIKE ? OR product LIKE ?)';
    $params[] = $searchTerm; $params[] = $searchTerm; $params[] = $searchTerm;
    $types   .= 'sss';

} elseif (count($tokens) === 2) {
    // Two tokens — Lot No + Coil No
    $likeLot  = '%' . $tokens[0] . '%';
    $likeCoil = '%' . $tokens[1] . '%';
    $where[]  = '(lot_no LIKE ? AND coil_no LIKE ?)';
    $params[] = $likeLot; $params[] = $likeCoil;
    $types   .= 'ss';

} elseif (count($tokens) >= 3) {
    // Three or more tokens — Lot No + Coil No + Product
    // (tokens beyond the 3rd are ignored)
    $likeLot     = '%' . $tokens[0] . '%';
    $likeCoil    = '%' . $tokens[1] . '%';
    $likeProduct = '%' . $tokens[2] . '%';
    $where[]  = '(lot_no LIKE ? AND coil_no LIKE ? AND product LIKE ?)';
    $params[] = $likeLot; $params[] = $likeCoil; $params[] = $likeProduct;
    $types   .= 'sss';
}

if ($printFilter === 'printed') {
    $where[] = 'printed_at IS NOT NULL';
} elseif ($printFilter === 'not_printed') {
    $where[] = 'printed_at IS NULL';
}

$sql = 'SELECT * FROM mother_coil';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY id DESC';

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Load all distinct products for the edit modal dropdown
$all_products = [];
$prod_res = $conn->query("SELECT DISTINCT product_code FROM std_wgt ORDER BY product_code");
while ($pr = $prod_res->fetch_assoc()) $all_products[] = $pr['product_code'];

$page_title = 'Mother Coil';
include 'header.php';
?>

<style>
/* ── Product select state styles ── */
#add_product_display.state-auto     { background:#F0FDF4; color:#166534; border-color:#86efac; }
#add_product_display.state-empty    { background:#FFF; color:inherit; }
#add_product_select_wrap            { display:none; }
#add_product_select_wrap.visible    { display:block; }
.product-choice-badge {
    display:inline-flex; align-items:center; gap:6px;
    font-size:11px; font-weight:600; padding:3px 10px;
    border-radius:20px; background:#EFF6FF; color:#1D4ED8;
    margin-bottom:6px;
}
</style>

<h2 class="mb-3"><i class="bi bi-layer-forward me-2"></i>Mother Coil List</h2>

<?php if ($error && isset($error_messages[$error])): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-3">
        <?= $error_messages[$error] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success && isset($success_messages[$success])): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm mb-3">
        <?= $success_messages[$success] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row mb-3">
    <div class="col-md-6">
        <?php if (in_array($_SESSION['role'], ['mkl3', 'slitting'], true)): ?>
            <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addMotherModal">
                <i class="bi bi-plus"></i> Add Mother Coil
            </button>
            <button class="btn btn-outline-success btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#bulkAddMotherModal">
                <i class="bi bi-table"></i> Bulk Add from Excel
            </button>
            <button class="btn btn-outline-danger btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#bulkPrintSlittingModal">
                <i class="bi bi-printer-fill"></i> Bulk Print for Slitting Plan
            </button>
        <?php endif; ?>
    </div>
    <div class="col-md-6">
        <form method="GET" action="mother_coil.php" class="input-group input-group-sm">
            <input type="text" name="search" class="form-control"
                   placeholder="Search Coil No, Lot, or Product... (e.g. 826403a N-4 DS-3020)"
                   value="<?= htmlspecialchars($search) ?>">
            <select name="print_status" class="form-select" style="max-width:160px;" onchange="this.form.submit()">
                <option value="" <?= $printFilter === '' ? 'selected' : '' ?>>All Coils</option>
                <option value="printed" <?= $printFilter === 'printed' ? 'selected' : '' ?>>Printed</option>
                <option value="not_printed" <?= $printFilter === 'not_printed' ? 'selected' : '' ?>>Not Printed Yet</option>
            </select>
            <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Search</button>
            <?php if ($search !== '' || $printFilter !== ''): ?>
                <a href="mother_coil.php" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<table class="table table-bordered table-striped align-middle text-center">
    <thead class="table-dark">
        <tr>
            <th>ID</th><th>Product</th><th>Lot No.</th><th>Coil No.</th>
            <th>Grade</th><th>Width</th><th>Length (mtr)</th>
            <th>Date Created</th><th>Print Status</th><th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($result && $result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): $id = (int)$row['id']; ?>
                <tr>
                    <td><?= $id ?></td>
                    <td><?= htmlspecialchars($row['product'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['lot_no']  ?? '') ?></td>
                    <td><?= htmlspecialchars($row['coil_no'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['grade']   ?? '') ?></td>
                    <td><?= htmlspecialchars($row['width']   ?? '') ?></td>
                    <td><?= htmlspecialchars($row['length']  ?? '') ?></td>
                    <td><?= htmlspecialchars($row['date_created'] ?? '') ?></td>
                    <td>
                        <?php if (!empty($row['printed_at'])): ?>
                            <span class="badge bg-success" title="<?= htmlspecialchars($row['printed_at']) ?>">
                                <i class="bi bi-check-circle me-1"></i>Printed
                            </span>
                            <div class="small text-muted"><?= date('d M Y, H:i', strtotime($row['printed_at'])) ?></div>
                        <?php else: ?>
                            <span class="badge bg-secondary">
                                <i class="bi bi-clock me-1"></i>Not Printed Yet
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                    <?php if (in_array($_SESSION['role'], ['mkl3', 'slitting'], true)): ?>
                        <button type="button" class="btn btn-warning btn-sm me-1 editBtn"
                                data-id="<?= $id ?>"
                                data-product="<?= htmlspecialchars($row['product'] ?? '') ?>"
                                data-lot_no="<?= htmlspecialchars($row['lot_no']   ?? '') ?>"
                                data-coil_no="<?= htmlspecialchars($row['coil_no'] ?? '') ?>"
                                data-grade="<?= htmlspecialchars($row['grade']     ?? '') ?>"
                                data-width="<?= htmlspecialchars($row['width']     ?? '') ?>"
                                data-length="<?= htmlspecialchars($row['length']   ?? '') ?>"
                                data-bs-toggle="modal" data-bs-target="#editMotherModal">
                            Edit
                        </button>
                        <a href="delete_mother.php?id=<?= $id ?>" class="btn btn-danger btn-sm"
                           onclick="return confirm('⚠️ DELETE MOTHER COIL?\n\nThis will permanently delete all linked records.\n\nThis CANNOT be undone. Continue?')">
                            Delete
                        </a>
                        <a href="print_mother.php?id=<?= $id ?>" class="btn btn-info btn-sm" target="_blank">Print</a>
                        <a href="mother_coil_journal.php?id=<?= $id ?>" class="btn btn-info btn-sm">
                            <i class="bi bi-journal-text"></i> Journal
                        </a>
                    <?php else: ?>
                        <span class="text-muted">View only</span>
                    <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="11" class="text-center py-4 text-muted">No records found.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- ══════════════════════════════════════════
     ADD MODAL
══════════════════════════════════════════ -->
<div class="modal fade" id="addMotherModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <form method="post" action="mother_coil.php" id="addMotherForm">
        <input type="hidden" name="action" value="add">

        <div class="modal-header bg-light py-3 px-4">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-plus-circle-fill text-primary me-2"></i>Add Mother Coil</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body p-4">

          <!-- Excel paste shortcut -->
          <div class="p-3 bg-light rounded-3 border mb-4">
            <label class="form-label fw-semibold text-dark mb-1">
              <i class="bi bi-file-earmark-spreadsheet-fill text-success me-1"></i> Shortcut: Paste Excel Row Here to Auto-Fill
            </label>
            <textarea id="add_paste_zone" class="form-control form-control-sm border-secondary-subtle" rows="2"
                      placeholder="Copy a row from Excel (without headers) and paste it here…"></textarea>
            <div class="form-text text-muted small mt-1">
              Auto-fills Lot No, Coil No, Product, Grade, Width, and Length below.
            </div>
            <div class="alert alert-warning py-1 px-2 mt-2 mb-0 small d-none" id="add_paste_warning">
              Format mismatch. Please ensure you copied a valid row from the spreadsheet.
            </div>
          </div>

          <!-- 2-Column Grid for Mother Coil Details -->
          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Lot No <span class="text-danger">*</span></label>
              <input type="text" name="lot_no" id="add_lot_no" class="form-control" required
                     maxlength="8" pattern="^[a-zA-Z0-9]{4,8}$"
                     title="4–8 alphanumeric characters"
                     placeholder="e.g. 5001">
              <div class="form-text">4–8 characters, letters and numbers only.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Coil No <span class="text-danger">*</span></label>
              <input type="text" name="coil_no" id="add_coil_no" class="form-control" required
                     disabled placeholder="Enter coil number">
              <div class="form-text" id="add_coil_hint">Enter coil number then click elsewhere to look up product.</div>
            </div>

            <div class="col-md-6" id="add_product_wrap">
              <label class="form-label fw-semibold">Product <span class="text-danger">*</span></label>

              <div id="add_product_auto_wrap">
                <input type="text" id="add_product_display" class="form-control state-empty"
                       readonly placeholder="Will auto-fill after Coil No is entered">
              </div>

              <div id="add_product_select_wrap">
                <div class="product-choice-badge mb-1 small text-primary">
                  <i class="bi bi-info-circle me-1"></i>Multiple products found — please select one
                </div>
                <select name="product" id="add_product_select" class="form-select" required>
                  <option value="">-- Select Product --</option>
                </select>
              </div>

              <input type="hidden" name="product" id="add_product_hidden">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Grade <span class="text-danger">*</span></label>
              <input type="text" name="grade" id="add_grade" class="form-control" required
                     disabled placeholder="e.g. SS400">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Width (mm) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="width" id="add_width" class="form-control" required
                     disabled placeholder="e.g. 1250">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Length (mtr) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="length" id="add_length" class="form-control" required
                     disabled placeholder="e.g. 500">
            </div>
          </div>

          <!-- Slitting Plan Section -->
          <hr class="my-4">
          <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <label class="form-label fw-bold fs-6 text-dark mb-0">
                <i class="bi bi-list-ol text-primary me-2"></i>Slitting Plan <span class="badge bg-secondary-subtle text-secondary ms-1">Optional</span>
              </label>
            </div>
            <div class="form-text text-muted mb-3">
              Give floor operators a head start by planning cut widths, customer codes, and ref numbers now.
            </div>

            <!-- Quick Paste Area -->
            <div class="p-3 bg-light rounded-3 border mb-3">
              <label class="form-label fw-semibold small text-secondary mb-1">
                <i class="bi bi-clipboard-plus me-1"></i> Quick Excel / CSV Slitting Plan Paste
              </label>
              <textarea id="plan_paste_zone" class="form-control form-control-sm border-secondary-subtle" rows="2"
                        placeholder="Paste from Excel/CSV — Roll, Width, Customer, Ref No, e.g.&#10;R1, 125, NAE, SO-26-0110&#10;R2, 125, STAMPING, STOCK"></textarea>
              <div class="alert alert-warning py-1 px-2 small mt-2 mb-0 d-none" id="plan_paste_warning">
                Couldn't read that as Seq + Width pairs — check the format.
              </div>
            </div>

            <!-- Structured Table / Grid for Slitting Plan Rows -->
            <div class="card border shadow-sm mb-3">
              <div class="card-header bg-light py-2 px-3">
                <div class="row g-2 align-items-center text-secondary fw-bold small text-uppercase">
                  <div style="width: 85px;">Seq</div>
                  <div style="width: 125px;">Width (mm)</div>
                  <div style="width: 230px;">Customer Code</div>
                  <div class="col">Customer Ref No</div>
                  <div style="width: 40px;" class="text-end"></div>
                </div>
              </div>
              <div class="card-body p-2" id="planRowsContainer" style="max-height: 280px; overflow-y: auto;">
                <!-- Rows injected dynamically -->
              </div>
            </div>

            <button type="button" class="btn btn-outline-primary btn-sm px-3" onclick="addPlanRow()">
              <i class="bi bi-plus-lg me-1"></i>Add Row Manually
            </button>
          </div>

        </div><!-- /modal-body -->

        <div class="modal-footer bg-light px-4 py-3">
          <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success px-4" id="add_submit_btn" disabled>
            <i class="bi bi-check-circle me-1"></i> Save Mother Coil
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════
     EDIT MODAL
══════════════════════════════════════════ -->
<div class="modal fade" id="editMotherModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <form method="post" action="mother_coil.php">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" id="edit_id">

        <div class="modal-header bg-light py-3 px-4">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-pencil-square text-warning me-2"></i>Edit Mother Coil</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body p-4">
          <!-- 2-Column Grid for Mother Coil Details -->
          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Lot No</label>
              <input type="text" name="lot_no" id="edit_lot_no" class="form-control" required
                     maxlength="8" pattern="^[a-zA-Z0-9]{4,8}$">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Coil No</label>
              <input type="text" name="coil_no" id="edit_coil_no" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Product</label>
              <select name="product" id="edit_product" class="form-select" required>
                <option value="">-- Select Product --</option>
                <?php foreach ($all_products as $p): ?>
                  <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Grade</label>
              <input type="text" name="grade" id="edit_grade" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Width (mm)</label>
              <input type="number" step="0.01" name="width" id="edit_width" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Length (mtr)</label>
              <input type="number" step="0.01" name="length" id="edit_length" class="form-control" required>
            </div>
          </div>

          <!-- Slitting Plan (optional / editable) -->
          <hr class="my-4">
          <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <label class="form-label fw-bold fs-6 text-dark mb-0">
                <i class="bi bi-list-ol text-primary me-2"></i>Slitting Plan <span class="badge bg-secondary-subtle text-secondary ms-1">Optional</span>
              </label>
            </div>
            <div class="form-text text-muted mb-3">
              Modify or add planned cut widths, customer codes, and ref numbers for this mother coil.
            </div>

            <!-- Quick Paste Area -->
            <div class="p-3 bg-light rounded-3 border mb-3">
              <label class="form-label fw-semibold small text-secondary mb-1">
                <i class="bi bi-clipboard-plus me-1"></i> Quick Excel / CSV Slitting Plan Paste
              </label>
              <textarea id="edit_plan_paste_zone" class="form-control form-control-sm border-secondary-subtle" rows="2"
                        placeholder="Paste from Excel/CSV — Roll, Width, Customer, Ref No, e.g.&#10;R1, 125, NAE, SO-26-0110&#10;R2, 125, STAMPING, STOCK"></textarea>
              <div class="alert alert-warning py-1 px-2 small mt-2 mb-0 d-none" id="edit_plan_paste_warning">
                Couldn't read that as Seq + Width pairs — check the format.
              </div>
            </div>

            <!-- Structured Table / Grid for Slitting Plan Rows -->
            <div class="card border shadow-sm mb-3">
              <div class="card-header bg-light py-2 px-3">
                <div class="row g-2 align-items-center text-secondary fw-bold small text-uppercase">
                  <div style="width: 85px;">Seq</div>
                  <div style="width: 125px;">Width (mm)</div>
                  <div style="width: 230px;">Customer Code</div>
                  <div class="col">Customer Ref No</div>
                  <div style="width: 40px;" class="text-end"></div>
                </div>
              </div>
              <div class="card-body p-2" id="editPlanRowsContainer" style="max-height: 280px; overflow-y: auto;">
                <!-- Dynamic rows injected here -->
              </div>
            </div>

            <button type="button" class="btn btn-outline-primary btn-sm px-3" onclick="addEditPlanRow()">
              <i class="bi bi-plus-lg me-1"></i>Add Row Manually
            </button>
          </div>
        </div>

        <div class="modal-footer bg-light px-4 py-3">
          <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success px-4">
            <i class="bi bi-check-circle me-1"></i> Update Mother Coil
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════
     BULK ADD MODAL (multi-row Excel paste)
══════════════════════════════════════════ -->
<div class="modal fade" id="bulkAddMotherModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-table me-2"></i>Bulk Add Mother Coils from Excel</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">Paste Multiple Excel Rows Here (one coil per line, no headers)</label>
          <textarea id="bulk_paste_zone" class="form-control" rows="6"
                    placeholder="Paste several rows copied from Excel — each line becomes one mother coil."></textarea>
          <div class="form-text">Each line must be tab-separated, same column layout as the single-row paste shortcut.</div>
        </div>

        <div class="d-flex gap-2 mb-3">
          <button type="button" class="btn btn-primary btn-sm" id="bulk_parse_btn">
            <i class="bi bi-list-check"></i> Parse Rows
          </button>
          <button type="button" class="btn btn-secondary btn-sm" id="bulk_clear_btn">Clear</button>
        </div>

        <div class="alert alert-warning d-none" id="bulk_parse_warning"></div>
        <div class="alert alert-success d-none" id="bulk_result_msg"></div>

        <div class="table-responsive">
          <table class="table table-sm table-bordered align-middle" id="bulk_preview_table">
            <thead class="table-light">
              <tr>
                <th>#</th><th>Lot No</th><th>Coil No</th><th>Product</th>
                <th>Grade</th><th>Width (mm)</th><th>Length (mtr)</th><th></th>
              </tr>
            </thead>
            <tbody id="bulk_preview_tbody"></tbody>
          </table>
        </div>

        <!-- Shared Slitting Plan: entered ONCE, applied to every mother coil
             saved from this batch (same roll widths for all of them). -->
        <hr class="my-4">
        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <label class="form-label fw-bold fs-6 text-dark mb-0">
              <i class="bi bi-list-ol text-primary me-2"></i>Shared Slitting Plan <span class="badge bg-secondary-subtle text-secondary ms-1">Applies to ALL rows in batch</span>
            </label>
          </div>
          <div class="form-text text-muted mb-3">
            Enter the plan once here and every mother coil saved from this batch will share the same cut widths, customer codes, and ref numbers.
          </div>

          <!-- Quick Paste Area -->
          <div class="p-3 bg-light rounded-3 border mb-3">
            <label class="form-label fw-semibold small text-secondary mb-1">
              <i class="bi bi-clipboard-plus me-1"></i> Quick Excel / CSV Slitting Plan Paste
            </label>
            <textarea id="bulk_plan_paste_zone" class="form-control form-control-sm border-secondary-subtle" rows="2"
                      placeholder="Paste from Excel/CSV — Roll, Width, Customer, Ref No, e.g.&#10;R1, 125, NAE, SO-26-0110&#10;R2, 125, STAMPING, STOCK"></textarea>
            <div class="alert alert-warning py-1 px-2 small mt-2 mb-0 d-none" id="bulk_plan_paste_warning">
              Couldn't read that as Seq + Width pairs — check the format.
            </div>
          </div>

          <!-- Structured Table / Grid for Slitting Plan Rows -->
          <div class="card border shadow-sm mb-3">
            <div class="card-header bg-light py-2 px-3">
              <div class="row g-2 align-items-center text-secondary fw-bold small text-uppercase">
                <div style="width: 85px;">Seq</div>
                <div style="width: 125px;">Width (mm)</div>
                <div style="width: 230px;">Customer Code</div>
                <div class="col">Customer Ref No</div>
                <div style="width: 40px;" class="text-end"></div>
              </div>
            </div>
            <div class="card-body p-2" id="bulkPlanRowsContainer" style="max-height: 280px; overflow-y: auto;">
              <!-- Dynamic rows injected here -->
            </div>
          </div>

          <button type="button" class="btn btn-outline-primary btn-sm px-3" onclick="addBulkPlanRow()">
            <i class="bi bi-plus-lg me-1"></i>Add Row Manually
          </button>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-success" id="bulk_save_btn" disabled>
          <i class="bi bi-save me-1"></i> Save All
        </button>
        <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<datalist id="validCustomerList">
  <option value="NAE">NICHIAS AUTOPARTS EUROPE (NAE)</option>
  <option value="NAX">NAX MFG, SA.DE C.V</option>
  <option value="NCI MFG">NCI MFG., INC.</option>
  <option value="TAIHO">TAIHO MFG OF TN. INC</option>
  <option value="NRI">PT NICHIAS ROCKWOOL IND.</option>
  <option value="ASHUKA">ASHUKA TECHNOLOGIES SDN. BHD.</option>
  <option value="NIPPON">NTC(NIPPON GASKET)</option>
  <option value="NTC">NICHIAS THAILAND</option>
  <option value="SGC">SHANGHAI XINGSHENG</option>
  <option value="STAMPING">MK STAMPING</option>
  <option value="YANTAI">NICHIAS (SHANGHAI) AUTOPARTS TRADING</option>
  <option value="NIPP">NICHIAS IND.PRODUCTS PVT. LTD.</option>
  <option value="NVC">NICHIAS VIETNAM CO., LTD</option>
  <option value="NSJ">NC-PT NICHIAS SUNIJAYA</option>
  <option value="NIP">SUZHOU NICHIAS IND. PRODUCTS</option>
  <option value="YTEC">YTEC CO., LTD.</option>
  <option value="NSA">NICHIAS SOUTH EAST ASIA (UP PACKING)</option>
  <option value="NCI 2">NCI MFG (LINE 2)</option>
  <option value="STOCK">STOCK</option>
  <option value="TRIAL">TRIAL</option>
</datalist>

<!-- ================================================================
     BULK PRINT FOR SLITTING PLAN MODAL
     Two intake paths (paste text, or quick search-and-add), both feed
     the same ordered, drag-reorderable list, printed as one job.
================================================================ -->
<div class="modal fade" id="bulkPrintSlittingModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-printer-fill me-2"></i>Bulk Print for Slitting Plan</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <ul class="nav nav-tabs" id="bpsTabs">
          <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#bpsPasteTab" type="button">
              <i class="bi bi-clipboard-plus me-1"></i> Paste / Type List
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#bpsSearchTab" type="button">
              <i class="bi bi-search me-1"></i> Quick Search &amp; Add
            </button>
          </li>
        </ul>

        <div class="tab-content border border-top-0 p-3 mb-3">

          <!-- Option A: Paste / Type List -->
          <div class="tab-pane fade show active" id="bpsPasteTab">
            <label class="form-label fw-semibold">Coil Nos. / Lot Nos. — one per line, or comma-separated</label>
            <textarea id="bps_paste_zone" class="form-control" rows="5"
                      placeholder="e.g.&#10;N-4&#10;826403a N-4&#10;QA-1, QA-2, QA-3"></textarea>
            <div class="form-text">
                A single word is matched against Coil No first, then Lot No. For a guaranteed exact match
                (coil numbers can repeat across different lots), type <strong>Lot No then Coil No</strong> together on one line, e.g. <code>826403a N-4</code>.
            </div>
            <button type="button" class="btn btn-primary btn-sm mt-2" id="bps_resolve_btn">
                <i class="bi bi-list-check me-1"></i> Find Matching Coils
            </button>
          </div>

          <!-- Option B: Quick Search & Add -->
          <div class="tab-pane fade" id="bpsSearchTab">
            <label class="form-label fw-semibold">Type a Coil No, Lot No, or Product, then pick from the results</label>
            <input type="text" id="bps_search_input" class="form-control" placeholder="e.g. N-4" autocomplete="off">
            <div id="bps_search_results" class="list-group mt-2"></div>
          </div>

        </div>

        <div id="bps_resolve_feedback"></div>

        <hr>

        <h6 class="fw-bold"><i class="bi bi-list-ol me-1"></i> Print Order (<span id="bps_count">0</span> coils) — drag <i class="bi bi-grip-vertical"></i> to rearrange</h6>
        <div id="bpsOrderedList"></div>
        <div id="bps_empty_hint" class="text-muted small">No coils added yet — use either tab above.</div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="bps_print_all_btn" onclick="bpsPrintAll()" disabled>
          <i class="bi bi-printer-fill me-1"></i> Print All Selected Coils
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Hidden form used to POST the ordered coil IDs to the print job in a new tab -->
<form id="bpsPrintForm" method="post" action="print_mother_batch_action.php" target="_blank" style="display:none;">
    <input type="hidden" name="mother_coil_ids" id="bpsPrintIdsInput">
</form>

<script>
/* ════════════════════════════════════════════════════════════
   ADD MODAL — Product lookup logic
════════════════════════════════════════════════════════════ */

const elLot     = document.getElementById('add_lot_no');
const elCoil    = document.getElementById('add_coil_no');
const elGrade   = document.getElementById('add_grade');
const elWidth   = document.getElementById('add_width');
const elLength  = document.getElementById('add_length');
const elSubmit  = document.getElementById('add_submit_btn');

const elAutoWrap   = document.getElementById('add_product_auto_wrap');
const elSelectWrap = document.getElementById('add_product_select_wrap');
const elDisplay    = document.getElementById('add_product_display');
const elSelect     = document.getElementById('add_product_select');
const elHidden     = document.getElementById('add_product_hidden');
const elCoilHint   = document.getElementById('add_coil_hint');

const elPaste        = document.getElementById('add_paste_zone');
const elPasteWarning = document.getElementById('add_paste_warning');

function showPasteWarning(show) {
    elPasteWarning.classList.toggle('d-none', !show);
}

/* ════════════════════════════════════════════════════════════
   Excel paste shortcut — parses a tab-separated Excel row and
   auto-fills Lot No, Coil No, Grade, Width and Length.
   Manual editing of every field remains available afterwards.
════════════════════════════════════════════════════════════ */
elPaste.addEventListener('input', function () {
    handlePasteAutofill(this.value);
});

async function handlePasteAutofill(raw) {
    const text = raw.trim();
    if (!text) { showPasteWarning(false); return; }

    const cols = text.split('\t');
    // Real Excel layout is 10 tab-separated cells, including 3 blank
    // spacer columns that have no header text at all:
    //   0 Product | 1 (blank spacer) | 2 Width (mm) | 3 Lot No. (base + coil) |
    //   4 (blank spacer) | 5 Material Length | 6 Actual Length (ignored) |
    //   7 Slitting Date (ignored) | 8 (blank spacer) | 9 Grade
    if (cols.length < 10) { showPasteWarning(true); return; }

    const pastedProduct = (cols[0] || '').trim();
    const widthVal       = (cols[2] || '').trim();
    const lotCoilCell     = (cols[3] || '').trim();
    const lengthVal       = (cols[5] || '').trim();
    // cols[1], cols[4], cols[8] are blank spacer columns; cols[6] (Actual
    // Length) and cols[7] (Slitting Date) are intentionally never read —
    // present or blank, none of these shift the Grade column.
    const gradeVal        = (cols[9] || '').trim();

    // Split "826711 CH-2" -> base lot "826711" + coil "CH-2". Splitting on
    // whitespace (rather than a fixed-length/charset regex) is what makes
    // this robust to base lot numbers of any length (4-7 chars) and mixed
    // case, since we never need to know its exact shape — only that it's
    // whatever comes before the first space, with the coil being everything
    // after (rejoined, in case the coil identifier itself contains a space).
    const lotCoilParts = lotCoilCell.split(' ').filter(Boolean);
    const widthNum  = parseFloat(widthVal);
    const lengthNum = parseFloat(lengthVal);

    if (lotCoilParts.length < 2 || isNaN(widthNum) || isNaN(lengthNum) || !gradeVal) {
        showPasteWarning(true);
        return;
    }

    showPasteWarning(false);

    const lotVal  = lotCoilParts[0];
    const coilVal = lotCoilParts.slice(1).join(' ');

    /* Fill Lot No (re-validate + unlock Coil No) */
    elLot.value = lotVal;
    elLot.dispatchEvent(new Event('input'));

    /* Fill Coil No */
    elCoil.disabled = false;
    elCoil.value = coilVal;

    /* Instantly fill + unlock Grade, Width, Length — still editable by hand */
    elGrade.disabled  = false; elGrade.value  = gradeVal;
    elWidth.disabled  = false; elWidth.value  = widthVal;
    elLength.disabled = false; elLength.value = lengthVal;
    elSubmit.disabled = false;

    /* ── Auto-Detection + Exact Matching + Seamless Bypass ────────
       If Excel already gave us a product code, validate it against real
       product records first. A confirmed exact match fills the Product
       field directly and skips the coil-based lookup entirely — no
       "Multiple products found" dropdown, no manual selection needed.
       An unrecognized/blank pasted value falls through to the exact
       same coil-based lookup this form already used before this change. */
    if (pastedProduct) {
        try {
            elCoilHint.textContent = '🔍 Checking pasted product…';
            const vRes  = await fetch('mother_coil.php?ajax=validate_product&code=' + encodeURIComponent(pastedProduct));
            const vData = await vRes.json();

            if (vData.ok && vData.found) {
                elDisplay.value      = vData.product;
                elDisplay.className  = 'form-control state-auto';
                elHidden.value       = vData.product;
                elHidden.disabled    = false;
                elAutoWrap.style.display = 'block';
                elSelectWrap.classList.remove('visible');
                elSelect.required = false;
                elCoilHint.textContent = '';

                /* Re-assert pasted values, same as the coil-lookup path below */
                elGrade.disabled  = false; elGrade.value  = gradeVal;
                elWidth.disabled  = false; elWidth.value  = widthVal;
                elLength.disabled = false; elLength.value = lengthVal;
                elSubmit.disabled = false;
                return; // bypass coil-based lookup entirely
            }
        } catch (err) {
            // Validation call failed — fall through to coil-based lookup below
        }
    }

    /* Best-effort background product lookup; pasted values are preserved either way */
    try {
        elCoilHint.textContent = '🔍 Looking up product…';
        const res  = await fetch('mother_coil.php?ajax=get_product&coil=' + encodeURIComponent(coilVal));
        const data = await res.json();

        if (data.ok && data.products.length === 1) {
            elDisplay.value      = data.products[0];
            elDisplay.className  = 'form-control state-auto';
            elHidden.value       = data.products[0];
            elHidden.disabled    = false;
            elAutoWrap.style.display = 'block';
            elSelectWrap.classList.remove('visible');
            elSelect.required = false;
            elCoilHint.textContent = '';
        } else if (data.ok && data.products.length > 1) {
            elAutoWrap.style.display = 'none';
            elSelectWrap.classList.add('visible');
            elHidden.disabled = true;
            elHidden.value    = '';
            elSelect.innerHTML = '<option value="">-- Select Product --</option>';
            data.products.forEach(p => {
                const opt = document.createElement('option');
                opt.value = opt.textContent = p;
                elSelect.appendChild(opt);
            });
            elSelect.required = true;
            elCoilHint.textContent = '';
        } else {
            elCoilHint.textContent = '⚠️ Coil code not found in mapping table. Please select product manually if required.';
        }
    } catch (err) {
        elCoilHint.textContent = '⚠️ Network error during lookup.';
    }

    /* Re-assert pasted values in case the lookup branches above touched shared state */
    elGrade.disabled  = false; elGrade.value  = gradeVal;
    elWidth.disabled  = false; elWidth.value  = widthVal;
    elLength.disabled = false; elLength.value = lengthVal;
    elSubmit.disabled = false;
}

/* ════════════════════════════════════════════════════════════
   OPTIONAL SLITTING PLAN — manual rows + Excel paste both feed
   the same plan_seq[]/plan_width[] inputs, which simply submit
   as-is (or stay empty) with the rest of the Add form.
════════════════════════════════════════════════════════════ */
const elPlanPaste   = document.getElementById('plan_paste_zone');
const elPlanRows    = document.getElementById('planRowsContainer');
const elPlanWarning = document.getElementById('plan_paste_warning');

function planEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g,
        c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

const VALID_CUSTOMERS = ['NAE','NAX','NCI MFG','TAIHO','NRI','ASHUKA','NIPPON','NTC','SGC','STAMPING','YANTAI','NIPP','NVC','NSJ','NIP','YTEC','NSA','NCI 2','STOCK','TRIAL'];

function normalizeCustomerCode(input) {
    if (!input) return '';
    const clean = input.trim().toUpperCase();
    const found = VALID_CUSTOMERS.find(c => c.toUpperCase() === clean);
    return found || '';
}

function validatePlanCustomerInput(input) {
    const val = input.value.trim().toUpperCase();
    if (!val) {
        input.classList.remove('is-invalid');
        input.title = "";
        return;
    }
    const isValid = VALID_CUSTOMERS.some(c => c.toUpperCase() === val);
    if (!isValid) {
        input.classList.add('is-invalid');
        input.title = "Invalid Customer Code! Must be one of: " + VALID_CUSTOMERS.join(', ');
    } else {
        input.classList.remove('is-invalid');
        input.title = "";
    }
}

/* Shared by both the single-add plan and the bulk-add shared plan below.
   Format: "R1, 125, NAE, SO-26-0110" or "R1 \t 125 \t NAE \t SO-26-0110" — comma/tab/space separated per line. */
function parsePlanPasteText(text) {
    const lines  = text.trim().split(/\r?\n/).filter(l => l.trim() !== '');
    const parsed = [];

    for (const line of lines) {
        let cols = [];
        if (line.includes(',')) {
            cols = line.split(',').map(c => c.trim());
        } else if (line.includes('\t')) {
            cols = line.split('\t').map(c => c.trim()).filter(c => c !== '');
        } else {
            cols = line.trim().split(/\s+/);
        }

        if (cols.length < 2) continue;

        const seq   = cols[0].trim();
        const width = parseFloat(cols[1]);
        if (seq === '' || isNaN(width)) continue;

        const rawCustomer = (cols.length >= 3) ? cols[2].trim() : '';
        const customer    = normalizeCustomerCode(rawCustomer);
        const refNo       = (cols.length >= 4) ? cols[3].trim() : '';
        const rawCustInvalid = (rawCustomer !== '' && customer === '');

        parsed.push({ seq, width, customer, refNo, rawCustomer, rawCustInvalid });
    }

    return parsed;
}

function addPlanRow(seq = '', width = '', customer = '', refNo = '', rawCustomer = '', rawCustInvalid = false) {
    if (!seq) {
        let maxNum = 0;
        elPlanRows.querySelectorAll('.plan-row input[name="plan_seq[]"]').forEach(input => {
            const m = input.value.trim().match(/^R(\d+)$/i);
            if (m) {
                const num = parseInt(m[1], 10);
                if (num > maxNum) maxNum = num;
            }
        });
        if (maxNum === 0) {
            maxNum = elPlanRows.querySelectorAll('.plan-row').length;
        }
        seq = 'R' + (maxNum + 1);
    }

    const custVal = customer || rawCustomer;
    const invalidClass = rawCustInvalid ? 'is-invalid' : '';
    const invalidTitle = rawCustInvalid ? `Invalid Customer Code '${rawCustomer}'` : '';

    const row = document.createElement('div');
    row.className = 'row g-2 align-items-center mb-2 plan-row';
    row.innerHTML = `
        <div style="width: 85px;">
            <input type="text" name="plan_seq[]" class="form-control form-control-sm text-center fw-bold bg-light" placeholder="R1" value="${planEsc(seq)}">
        </div>
        <div style="width: 125px;">
            <input type="number" step="0.01" name="plan_width[]" class="form-control form-control-sm plan-width-input" placeholder="125" value="${planEsc(width)}">
        </div>
        <div style="width: 230px;">
            <input type="text" name="plan_customer[]" class="form-control form-control-sm text-uppercase ${invalidClass}" list="validCustomerList" placeholder="e.g. NAE" value="${planEsc(custVal)}" title="${invalidTitle}" oninput="validatePlanCustomerInput(this)">
        </div>
        <div class="col">
            <input type="text" name="plan_ref[]" class="form-control form-control-sm" placeholder="e.g. SO-26-0110" value="${planEsc(refNo)}">
        </div>
        <div style="width: 40px;" class="text-end">
            <button type="button" class="btn btn-sm btn-outline-danger border-0 px-2" title="Remove Roll" onclick="this.closest('.plan-row').remove()">
                <i class="bi bi-trash3-fill"></i>
            </button>
        </div>
    `;
    elPlanRows.appendChild(row);

    if (!width) {
        const widthInput = row.querySelector('.plan-width-input');
        if (widthInput) widthInput.focus();
    }
}

function clearPlanRows() {
    elPlanRows.innerHTML = '';
}

elPlanPaste.addEventListener('input', function () {
    const text = this.value.trim();
    if (!text) { elPlanWarning.classList.add('d-none'); return; }

    const parsed = parsePlanPasteText(text);
    if (parsed.length === 0) { elPlanWarning.classList.remove('d-none'); return; }

    elPlanWarning.classList.add('d-none');
    clearPlanRows();
    parsed.forEach(p => addPlanRow(p.seq, p.width, p.customer, p.refNo, p.rawCustomer, p.rawCustInvalid));
});

/* ════════════════════════════════════════════════════════════
   BULK ADD — SHARED SLITTING PLAN
   One plan entered once, applied to every mother coil saved in this
   batch. Rows aren't submitted via name="[]" attributes (the bulk
   save flow builds its own FormData in JS), so getBulkPlanRows()
   reads the current values straight out of the DOM when saving.
════════════════════════════════════════════════════════════ */
const elBulkPlanPaste   = document.getElementById('bulk_plan_paste_zone');
const elBulkPlanRows    = document.getElementById('bulkPlanRowsContainer');
const elBulkPlanWarning = document.getElementById('bulk_plan_paste_warning');

function addBulkPlanRow(seq = '', width = '', customer = '', refNo = '', rawCustomer = '', rawCustInvalid = false) {
    if (!seq) {
        let maxNum = 0;
        elBulkPlanRows.querySelectorAll('.plan-row .bulk-plan-seq').forEach(input => {
            const m = input.value.trim().match(/^R(\d+)$/i);
            if (m) {
                const num = parseInt(m[1], 10);
                if (num > maxNum) maxNum = num;
            }
        });
        if (maxNum === 0) {
            maxNum = elBulkPlanRows.querySelectorAll('.plan-row').length;
        }
        seq = 'R' + (maxNum + 1);
    }

    const custVal = customer || rawCustomer;
    const invalidClass = rawCustInvalid ? 'is-invalid' : '';
    const invalidTitle = rawCustInvalid ? `Invalid Customer Code '${rawCustomer}'` : '';

    const row = document.createElement('div');
    row.className = 'row g-2 align-items-center mb-2 plan-row';
    row.innerHTML = `
        <div style="width: 85px;">
            <input type="text" class="form-control form-control-sm text-center fw-bold bg-light bulk-plan-seq" placeholder="R1" value="${planEsc(seq)}">
        </div>
        <div style="width: 125px;">
            <input type="number" step="0.01" class="form-control form-control-sm bulk-plan-width" placeholder="125" value="${planEsc(width)}">
        </div>
        <div style="width: 230px;">
            <input type="text" class="form-control form-control-sm bulk-plan-customer text-uppercase ${invalidClass}" list="validCustomerList" placeholder="e.g. NAE" value="${planEsc(custVal)}" title="${invalidTitle}" oninput="validatePlanCustomerInput(this)">
        </div>
        <div class="col">
            <input type="text" class="form-control form-control-sm bulk-plan-ref" placeholder="e.g. SO-26-0110" value="${planEsc(refNo)}">
        </div>
        <div style="width: 40px;" class="text-end">
            <button type="button" class="btn btn-sm btn-outline-danger border-0 px-2" title="Remove Roll" onclick="this.closest('.plan-row').remove()">
                <i class="bi bi-trash3-fill"></i>
            </button>
        </div>
    `;
    elBulkPlanRows.appendChild(row);

    if (!width) {
        const widthInput = row.querySelector('.bulk-plan-width');
        if (widthInput) widthInput.focus();
    }
}

function clearBulkPlanRows() {
    elBulkPlanRows.innerHTML = '';
}

function getBulkPlanRows() {
    return Array.from(elBulkPlanRows.querySelectorAll('.plan-row'))
        .map(r => ({
            seq:      r.querySelector('.bulk-plan-seq').value.trim(),
            width:    r.querySelector('.bulk-plan-width').value.trim(),
            customer: (r.querySelector('.bulk-plan-customer')?.value || '').trim().toUpperCase(),
            ref_no:   (r.querySelector('.bulk-plan-ref')?.value || '').trim()
        }))
        .filter(r => r.seq !== '' && r.width !== '' && !isNaN(parseFloat(r.width)));
}

elBulkPlanPaste.addEventListener('input', function () {
    const text = this.value.trim();
    if (!text) { elBulkPlanWarning.classList.add('d-none'); return; }

    const parsed = parsePlanPasteText(text);
    if (parsed.length === 0) { elBulkPlanWarning.classList.remove('d-none'); return; }

    elBulkPlanWarning.classList.add('d-none');
    clearBulkPlanRows();
    parsed.forEach(p => addBulkPlanRow(p.seq, p.width, p.customer, p.refNo, p.rawCustomer, p.rawCustInvalid));
});

/* Reset the whole product area */
function resetProduct() {
    elDisplay.value = '';
    elDisplay.className = 'form-control state-empty';
    elHidden.value  = '';

    elAutoWrap.style.display   = 'block';
    elSelectWrap.classList.remove('visible');

    // Disable select so it doesn't send an empty value
    elSelect.required = false;
    elSelect.innerHTML = '<option value="">-- Select Product --</option>';

    // Also re-enable hidden (single-match path)
    elHidden.disabled = false;

    lockDownstream(true);
}

/* Lock / unlock Grade + Width + Length + Submit */
function lockDownstream(lock) {
    [elGrade, elWidth, elLength].forEach(el => {
        el.disabled = lock;
        if (lock) el.value = '';
    });
    elSubmit.disabled = lock;
}

/* Apply a resolved product value */
function applyProduct(value) {
    elHidden.value = value;
    lockDownstream(false);
    elGrade.focus();
}

/* ── Step 1: Lot No → enable Coil No ── */
function validateLotNo(input) {
    const regex = /^[a-zA-Z0-9]{4,8}$/;
    if (input.value !== '' && !regex.test(input.value)) {
        input.setCustomValidity('Lot No must be 4–8 alphanumeric characters.');
    } else {
        input.setCustomValidity('');
    }
}

elLot.addEventListener('input', function () {
    validateLotNo(this);
    const ok = this.value.trim() !== '';
    elCoil.disabled = !ok;
    if (!ok) { elCoil.value = ''; resetProduct(); }
});

/* ── Step 2: Coil No blur → lookup ── */
elCoil.addEventListener('blur', async function () {
    const coilVal = this.value.trim();
    resetProduct();

    if (!coilVal) return;

    elCoilHint.textContent = '🔍 Looking up product…';

    try {
        const res  = await fetch('mother_coil.php?ajax=get_product&coil=' + encodeURIComponent(coilVal));
        const data = await res.json();

        if (!data.ok || data.products.length === 0) {
            elCoilHint.textContent = '⚠️ Coil code not found in mapping table.';
            elDisplay.value = '';
            elDisplay.className = 'form-control';
            elDisplay.style.borderColor = '#dc3545';
            return;
        }

        elCoilHint.textContent = '';
        elDisplay.style.borderColor = '';

        if (data.products.length === 1) {
            /* ── Single match: auto-fill ── */
            elDisplay.value    = data.products[0];
            elDisplay.className = 'form-control state-auto';
            elHidden.value     = data.products[0];
            elHidden.disabled  = false;

            // Hide select, show auto display
            elAutoWrap.style.display = 'block';
            elSelectWrap.classList.remove('visible');
            elSelect.required = false;

            applyProduct(data.products[0]);

        } else {
            /* ── Multiple matches: show dropdown ── */
            elAutoWrap.style.display = 'none';
            elSelectWrap.classList.add('visible');

            // Disable hidden input (select will post instead)
            elHidden.disabled = true;
            elHidden.value    = '';

            // Populate select
            elSelect.innerHTML = '<option value="">-- Select Product --</option>';
            data.products.forEach(p => {
                const opt = document.createElement('option');
                opt.value       = p;
                opt.textContent = p;
                elSelect.appendChild(opt);
            });
            elSelect.required = true;
            elSelect.focus();
        }

    } catch (e) {
        elCoilHint.textContent = '⚠️ Network error during lookup.';
        console.error(e);
    }
});

/* ── Step 3: When dropdown changes → unlock downstream ── */
elSelect.addEventListener('change', function () {
    if (this.value) {
        applyProduct(this.value);
    } else {
        lockDownstream(true);
    }
});

/* ── Reset modal on open ── */
document.getElementById('addMotherModal').addEventListener('show.bs.modal', function () {
    document.getElementById('addMotherForm').reset();
    resetProduct();
    elCoil.disabled  = true;
    elGrade.disabled = true;
    elWidth.disabled = true;
    elLength.disabled= true;
    elSubmit.disabled= true;
    elCoilHint.textContent = 'Enter coil number then click elsewhere to look up product.';
    elDisplay.style.borderColor = '';
    elPaste.value = '';
    showPasteWarning(false);

    // Reset the optional slitting plan section too
    elPlanPaste.value = '';
    elPlanWarning.classList.add('d-none');
    clearPlanRows();
});

document.getElementById('addMotherModal').addEventListener('shown.bs.modal', function () {
    elLot.focus();
});

/* ════════════════════════════════════════════════════════════
   EDIT MODAL — populate fields & slitting plan
════════════════════════════════════════════════════════════ */
const elEditPlanPaste   = document.getElementById('edit_plan_paste_zone');
const elEditPlanRows    = document.getElementById('editPlanRowsContainer');
const elEditPlanWarning = document.getElementById('edit_plan_paste_warning');

function addEditPlanRow(seq = '', width = '', customer = '', refNo = '', rawCustomer = '', rawCustInvalid = false) {
    if (!seq) {
        let maxNum = 0;
        elEditPlanRows.querySelectorAll('.plan-row input[name="plan_seq[]"]').forEach(input => {
            const m = input.value.trim().match(/^R(\d+)$/i);
            if (m) {
                const num = parseInt(m[1], 10);
                if (num > maxNum) maxNum = num;
            }
        });
        if (maxNum === 0) {
            maxNum = elEditPlanRows.querySelectorAll('.plan-row').length;
        }
        seq = 'R' + (maxNum + 1);
    }

    const custVal = customer || rawCustomer;
    const invalidClass = rawCustInvalid ? 'is-invalid' : '';
    const invalidTitle = rawCustInvalid ? `Invalid Customer Code '${rawCustomer}'` : '';

    const row = document.createElement('div');
    row.className = 'row g-2 align-items-center mb-2 plan-row';
    row.innerHTML = `
        <div style="width: 85px;">
            <input type="text" name="plan_seq[]" class="form-control form-control-sm text-center fw-bold bg-light" placeholder="R1" value="${planEsc(seq)}">
        </div>
        <div style="width: 125px;">
            <input type="number" step="0.01" name="plan_width[]" class="form-control form-control-sm plan-width-input" placeholder="125" value="${planEsc(width)}">
        </div>
        <div style="width: 230px;">
            <input type="text" name="plan_customer[]" class="form-control form-control-sm text-uppercase ${invalidClass}" list="validCustomerList" placeholder="e.g. NAE" value="${planEsc(custVal)}" title="${invalidTitle}" oninput="validatePlanCustomerInput(this)">
        </div>
        <div class="col">
            <input type="text" name="plan_ref[]" class="form-control form-control-sm" placeholder="e.g. SO-26-0110" value="${planEsc(refNo)}">
        </div>
        <div style="width: 40px;" class="text-end">
            <button type="button" class="btn btn-sm btn-outline-danger border-0 px-2" title="Remove Roll" onclick="this.closest('.plan-row').remove()">
                <i class="bi bi-trash3-fill"></i>
            </button>
        </div>
    `;
    elEditPlanRows.appendChild(row);

    if (!width) {
        const widthInput = row.querySelector('.plan-width-input');
        if (widthInput) widthInput.focus();
    }
}

function clearEditPlanRows() {
    elEditPlanRows.innerHTML = '';
}

if (elEditPlanPaste) {
    elEditPlanPaste.addEventListener('input', function () {
        const text = this.value.trim();
        if (!text) { elEditPlanWarning.classList.add('d-none'); return; }

        const parsed = parsePlanPasteText(text);
        if (parsed.length === 0) { elEditPlanWarning.classList.remove('d-none'); return; }

        elEditPlanWarning.classList.add('d-none');
        clearEditPlanRows();
        parsed.forEach(p => addEditPlanRow(p.seq, p.width, p.customer, p.refNo, p.rawCustomer, p.rawCustInvalid));
    });
}

document.querySelectorAll('.editBtn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const motherId = btn.dataset.id;
        document.getElementById('edit_id').value      = motherId;
        document.getElementById('edit_lot_no').value  = btn.dataset.lot_no  || '';
        document.getElementById('edit_coil_no').value = btn.dataset.coil_no || '';
        document.getElementById('edit_grade').value   = btn.dataset.grade   || '';
        document.getElementById('edit_width').value   = btn.dataset.width   || '';
        document.getElementById('edit_length').value  = btn.dataset.length  || '';

        // Set selected product in dropdown
        const sel = document.getElementById('edit_product');
        sel.value = btn.dataset.product || '';
        // If not in list (legacy product), add it
        if (sel.value === '' && btn.dataset.product) {
            const opt = document.createElement('option');
            opt.value = opt.textContent = btn.dataset.product;
            sel.appendChild(opt);
            sel.value = btn.dataset.product;
        }

        // Fetch slitting plan for this mother coil
        elEditPlanPaste.value = '';
        elEditPlanWarning.classList.add('d-none');
        clearEditPlanRows();

        try {
            const res = await fetch('mother_coil.php?ajax=get_slitting_plan&mother_id=' + motherId);
            const data = await res.json();
            if (data.ok && Array.isArray(data.plans)) {
                data.plans.forEach(p => addEditPlanRow(p.roll_seq, p.planned_width, p.customer_name, p.ref_no));
            }
        } catch (e) {
            console.error('Error loading slitting plan:', e);
        }
    });
});

/* ── Edit Lot No validation ── */
document.getElementById('edit_lot_no').addEventListener('input', function () {
    validateLotNo(this);
});

/* ════════════════════════════════════════════════════════════
   BULK ADD MODAL — paste many Excel rows, preview, edit, save all
════════════════════════════════════════════════════════════ */
const bulkPaste     = document.getElementById('bulk_paste_zone');
const bulkParseBtn  = document.getElementById('bulk_parse_btn');
const bulkClearBtn  = document.getElementById('bulk_clear_btn');
const bulkWarning   = document.getElementById('bulk_parse_warning');
const bulkResultMsg = document.getElementById('bulk_result_msg');
const bulkTbody     = document.getElementById('bulk_preview_tbody');
const bulkSaveBtn   = document.getElementById('bulk_save_btn');

let bulkRows = []; // { lot_no, coil_no, grade, width, length, product, productOptions, productStatus }

function showBulkWarning(msg) {
    if (!msg) {
        bulkWarning.classList.add('d-none');
        bulkWarning.textContent = '';
        return;
    }
    bulkWarning.textContent = msg;
    bulkWarning.classList.remove('d-none');
}

function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

bulkClearBtn.addEventListener('click', () => {
    bulkPaste.value = '';
    bulkRows = [];
    renderBulkTable();
    showBulkWarning('');
    bulkResultMsg.classList.add('d-none');
});

bulkParseBtn.addEventListener('click', () => {
    parseBulkRows(bulkPaste.value);
});

function parseBulkRows(raw) {
    const lines = raw.split(/\r?\n/).map(l => l.trim()).filter(l => l !== '');
    if (lines.length === 0) {
        showBulkWarning('Please paste at least one row before parsing.');
        return;
    }

    const parsed   = [];
    const badLines = [];

    lines.forEach((line, idx) => {
        const cols = line.split('\t');
        // Same 10-column Excel layout as the single-row paste shortcut
        // (including 3 blank spacer columns with no header text):
        //   0 Product | 1 (blank spacer) | 2 Width (mm) | 3 Lot No. (base + coil) |
        //   4 (blank spacer) | 5 Material Length | 6 Actual Length (ignored) |
        //   7 Slitting Date (ignored) | 8 (blank spacer) | 9 Grade
        if (cols.length < 10) { badLines.push(idx + 1); return; }

        const pastedProduct = (cols[0] || '').trim();
        const widthVal       = (cols[2] || '').trim();
        const lotCoilCell     = (cols[3] || '').trim();
        const lengthVal       = (cols[5] || '').trim();
        // cols[1], cols[4], cols[8] are blank spacers; cols[6] (Actual
        // Length) and cols[7] (Slitting Date) are intentionally never read.
        const gradeVal        = (cols[9] || '').trim();

        const lotCoilParts = lotCoilCell.split(' ').filter(Boolean);

        if (lotCoilParts.length < 2 || isNaN(parseFloat(widthVal)) || isNaN(parseFloat(lengthVal)) || !gradeVal) {
            badLines.push(idx + 1);
            return;
        }

        parsed.push({
            lot_no:  lotCoilParts[0],
            coil_no: lotCoilParts.slice(1).join(' '),
            grade:   gradeVal,
            width:   widthVal,
            length:  lengthVal,
            product: '',
            pastedProduct: pastedProduct,   // candidate from Excel, unvalidated
            productOptions: [],
            productStatus: 'pending'   // pending | single | multiple | none
        });
    });

    if (badLines.length > 0) {
        showBulkWarning(
            `Format mismatch on line(s) ${badLines.join(', ')}. Please ensure every row was copied correctly from the spreadsheet. Valid rows were still parsed below.`
        );
    } else {
        showBulkWarning('');
    }

    bulkRows = parsed;
    renderBulkTable();
    bulkRows.forEach((row, i) => lookupBulkProduct(i));
}

function renderProductCell(row) {
    if (row.productStatus === 'pending') {
        return `<span class="text-muted small">🔍 Looking up…</span>`;
    }
    if (row.productStatus === 'none') {
        return `<span class="text-danger small">⚠ Not found</span>`;
    }
    if (row.productStatus === 'single') {
        return `<span class="text-success small">${escapeHtml(row.product)}</span>`;
    }
    if (row.productStatus === 'multiple') {
        const options = row.productOptions.map(p =>
            `<option value="${escapeHtml(p)}" ${p === row.product ? 'selected' : ''}>${escapeHtml(p)}</option>`
        ).join('');
        return `<select class="form-select form-select-sm bulk-product-select">
                    <option value="">-- Select --</option>${options}
                </select>`;
    }
    return '';
}

function renderBulkTable() {
    bulkTbody.innerHTML = '';
    bulkSaveBtn.disabled = bulkRows.length === 0;

    bulkRows.forEach((row, i) => {
        const tr = document.createElement('tr');
        tr.dataset.index = i;
        tr.innerHTML = `
            <td>${i + 1}</td>
            <td><input type="text" class="form-control form-control-sm bulk-lot" value="${escapeHtml(row.lot_no)}"></td>
            <td><input type="text" class="form-control form-control-sm bulk-coil" value="${escapeHtml(row.coil_no)}"></td>
            <td class="bulk-product-cell">${renderProductCell(row)}</td>
            <td><input type="text" class="form-control form-control-sm bulk-grade" value="${escapeHtml(row.grade)}"></td>
            <td><input type="number" step="0.01" class="form-control form-control-sm bulk-width" value="${escapeHtml(row.width)}"></td>
            <td><input type="number" step="0.01" class="form-control form-control-sm bulk-length" value="${escapeHtml(row.length)}"></td>
            <td><button type="button" class="btn btn-outline-danger btn-sm bulk-remove">&times;</button></td>
        `;
        bulkTbody.appendChild(tr);
    });

    bulkTbody.querySelectorAll('tr').forEach(tr => {
        const i = parseInt(tr.dataset.index, 10);

        tr.querySelector('.bulk-lot').addEventListener('input', e => bulkRows[i].lot_no = e.target.value);
        tr.querySelector('.bulk-coil').addEventListener('change', e => {
            bulkRows[i].coil_no       = e.target.value;
            bulkRows[i].product       = '';
            bulkRows[i].productStatus = 'pending';
            lookupBulkProduct(i);
        });
        tr.querySelector('.bulk-grade').addEventListener('input', e => bulkRows[i].grade = e.target.value);
        tr.querySelector('.bulk-width').addEventListener('input', e => bulkRows[i].width = e.target.value);
        tr.querySelector('.bulk-length').addEventListener('input', e => bulkRows[i].length = e.target.value);

        tr.querySelector('.bulk-remove').addEventListener('click', () => {
            bulkRows.splice(i, 1);
            renderBulkTable();
        });

        const sel = tr.querySelector('.bulk-product-select');
        if (sel) sel.addEventListener('change', e => { bulkRows[i].product = e.target.value; });
    });
}

async function lookupBulkProduct(i) {
    const row = bulkRows[i];
    if (!row || !row.coil_no) return;

    // ── Auto-Detection + Exact Matching ─────────────────────────
    // If Excel already gave us a product code for this row, validate it
    // against real product records first. A confirmed exact match bypasses
    // the coil-based lookup (and its ambiguous multi-match dropdown)
    // entirely — added directly, no manual selection needed. An
    // unrecognized/blank pasted value falls through to the exact same
    // coil-based lookup this page already used before this change.
    if (row.pastedProduct) {
        try {
            const vRes  = await fetch('mother_coil.php?ajax=validate_product&code=' + encodeURIComponent(row.pastedProduct));
            const vData = await vRes.json();

            if (vData.ok && vData.found) {
                row.productStatus = 'single';
                row.product = vData.product;

                const tr = bulkTbody.querySelector(`tr[data-index="${i}"]`);
                if (tr) {
                    const cell = tr.querySelector('.bulk-product-cell');
                    cell.innerHTML = renderProductCell(row);
                }
                return; // Seamless Bypass — skip coil-based lookup entirely
            }
        } catch (e) {
            // Validation call failed — fall through to coil-based lookup below
        }
    }

    try {
        const res  = await fetch('mother_coil.php?ajax=get_product&coil=' + encodeURIComponent(row.coil_no));
        const data = await res.json();

        if (!data.ok || data.products.length === 0) {
            row.productStatus = 'none';
            row.product = '';
        } else if (data.products.length === 1) {
            row.productStatus = 'single';
            row.product = data.products[0];
        } else {
            row.productStatus  = 'multiple';
            row.productOptions = data.products;
            row.product = '';
        }
    } catch (e) {
        row.productStatus = 'none';
        row.product = '';
    }

    // Re-render only this row's product cell so other in-progress edits aren't disturbed
    const tr = bulkTbody.querySelector(`tr[data-index="${i}"]`);
    if (tr) {
        const cell = tr.querySelector('.bulk-product-cell');
        cell.innerHTML = renderProductCell(row);
        const sel = cell.querySelector('.bulk-product-select');
        if (sel) sel.addEventListener('change', e => { row.product = e.target.value; });
    }
}

bulkSaveBtn.addEventListener('click', async () => {
    const validRows = bulkRows.filter(r =>
        r.lot_no && r.coil_no && r.grade && r.width && r.length && r.product
    );

    if (validRows.length === 0) {
        showBulkWarning('No complete rows to save. Make sure every row has a resolved Product before saving.');
        return;
    }

    bulkSaveBtn.disabled = true;
    bulkSaveBtn.textContent = 'Saving…';

    try {
        const formData = new FormData();
        formData.append('action', 'bulk_add');
        formData.append('rows_json', JSON.stringify(validRows));
        formData.append('plan_rows_json', JSON.stringify(getBulkPlanRows()));

        const res  = await fetch('mother_coil.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.ok) {
            let msg = `✅ ${data.inserted} mother coil(s) saved successfully.`;
            if (data.skipped && data.skipped.length > 0) {
                msg += ` ${data.skipped.length} row(s) skipped: ` +
                    data.skipped.map(s => `Row ${s.row} (${s.reason})`).join('; ');
            }
            bulkResultMsg.textContent = msg;
            bulkResultMsg.classList.remove('d-none');

            if (data.inserted > 0) {
                setTimeout(() => window.location.reload(), 1200);
            }
        } else {
            showBulkWarning('Save failed. Please try again.');
        }
    } catch (e) {
        showBulkWarning('Network error while saving.');
    } finally {
        bulkSaveBtn.disabled = false;
        bulkSaveBtn.textContent = 'Save All';
    }
});

/* Reset bulk modal on open */
document.getElementById('bulkAddMotherModal').addEventListener('show.bs.modal', function () {
    bulkPaste.value = '';
    bulkRows = [];
    renderBulkTable();
    showBulkWarning('');
    bulkResultMsg.classList.add('d-none');

    // Reset the shared batch plan too
    elBulkPlanPaste.value = '';
    elBulkPlanWarning.classList.add('d-none');
    clearBulkPlanRows();
});
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>
<script>
/* ════════════════════════════════════════════════════════════
   BULK PRINT FOR SLITTING PLAN
   Two intake paths (paste, quick search) both push into the same
   `bpsItems` ordered array, rendered as a drag-reorderable list,
   then printed as one consolidated job.
════════════════════════════════════════════════════════════ */
let bpsItems = []; // [{id, product, lot_no, coil_no, grade, width, length}, ...]

function bpsEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g,
        c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function bpsAddItem(row) {
    if (bpsItems.some(it => String(it.id) === String(row.id))) return; // no duplicates
    bpsItems.push(row);
    bpsRender();
}

function bpsRemoveItem(id) {
    bpsItems = bpsItems.filter(it => String(it.id) !== String(id));
    bpsRender();
}

function bpsRender() {
    const listEl  = document.getElementById('bpsOrderedList');
    const emptyEl = document.getElementById('bps_empty_hint');
    const countEl = document.getElementById('bps_count');
    const printBtn = document.getElementById('bps_print_all_btn');

    countEl.textContent = bpsItems.length;
    printBtn.disabled = bpsItems.length === 0;
    emptyEl.classList.toggle('d-none', bpsItems.length > 0);

    listEl.innerHTML = bpsItems.map((it, idx) => `
        <div class="d-flex align-items-center gap-2 border rounded p-2 mb-2 bg-white" data-id="${it.id}">
            <span class="text-muted" style="cursor:grab;"><i class="bi bi-grip-vertical"></i></span>
            <span class="badge bg-secondary" style="min-width:26px;">${idx + 1}</span>
            <div class="flex-grow-1">
                <div class="fw-bold">${bpsEsc(it.product)}</div>
                <div class="small text-muted">Lot ${bpsEsc(it.lot_no)} · Coil ${bpsEsc(it.coil_no)} · Grade ${bpsEsc(it.grade)} · ${bpsEsc(it.width)}mm × ${bpsEsc(it.length)}m</div>
            </div>
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="bpsRemoveItem('${it.id}')">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    `).join('');
}

new Sortable(document.getElementById('bpsOrderedList'), {
    handle: '.bi-grip-vertical',
    animation: 150,
    onEnd: function () {
        const newOrderIds = Array.from(document.querySelectorAll('#bpsOrderedList [data-id]')).map(el => el.dataset.id);
        bpsItems.sort((a, b) => newOrderIds.indexOf(String(a.id)) - newOrderIds.indexOf(String(b.id)));
        bpsRender();
    },
});

/* ── Option A: Paste / Type List ─────────────────────────────── */
document.getElementById('bps_resolve_btn').addEventListener('click', async function () {
    const text = document.getElementById('bps_paste_zone').value.trim();
    const feedback = document.getElementById('bps_resolve_feedback');
    if (!text) return;

    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Searching…';

    try {
        const resp = await fetch('mother_coil.php?ajax=resolve_bulk_paste', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text }),
        });
        const data = await resp.json();

        data.resolved.forEach(row => bpsAddItem(row));

        let html = '';
        if (data.resolved.length > 0) {
            html += `<div class="alert alert-success py-2">Added ${data.resolved.length} coil(s).</div>`;
        }
        if (data.ambiguous.length > 0) {
            html += `<div class="alert alert-warning py-2"><strong>${data.ambiguous.length} entr${data.ambiguous.length > 1 ? 'ies' : 'y'} matched more than one coil</strong> — use Quick Search &amp; Add to pick the right one, or retype as "Lot Coil":<ul class="mb-0 mt-1">`;
            data.ambiguous.forEach(a => {
                html += `<li><code>${bpsEsc(a.token)}</code> matched ${a.candidates.length} coils (e.g. ${a.candidates.map(c => bpsEsc(c.lot_no) + ' ' + bpsEsc(c.coil_no)).join(', ')})</li>`;
            });
            html += `</ul></div>`;
        }
        if (data.not_found.length > 0) {
            html += `<div class="alert alert-danger py-2"><strong>Not found:</strong> ${data.not_found.map(bpsEsc).join(', ')}</div>`;
        }
        feedback.innerHTML = html;
    } catch (e) {
        feedback.innerHTML = '<div class="alert alert-danger py-2">Network error while searching.</div>';
    }

    this.disabled = false;
    this.innerHTML = '<i class="bi bi-list-check me-1"></i> Find Matching Coils';
});

/* ── Option B: Quick Search & Add ────────────────────────────── */
let bpsSearchDebounce = null;
let bpsLastResults = []; // indexed lookup for the currently-shown result buttons

document.getElementById('bps_search_input').addEventListener('input', function () {
    const q = this.value.trim();
    clearTimeout(bpsSearchDebounce);
    const resultsEl = document.getElementById('bps_search_results');

    if (q.length < 1) { resultsEl.innerHTML = ''; bpsLastResults = []; return; }

    bpsSearchDebounce = setTimeout(async () => {
        try {
            const resp = await fetch('mother_coil.php?ajax=search_coil&q=' + encodeURIComponent(q));
            const data = await resp.json();
            bpsLastResults = data.results || [];

            resultsEl.innerHTML = bpsLastResults.length === 0
                ? '<div class="text-muted small p-2">No matches.</div>'
                : bpsLastResults.map((r, i) => `
                    <button type="button" class="list-group-item list-group-item-action bps-result-btn" data-index="${i}">
                        <strong>${bpsEsc(r.product)}</strong> —
                        Lot ${bpsEsc(r.lot_no)} · Coil ${bpsEsc(r.coil_no)} · Grade ${bpsEsc(r.grade)} ·
                        ${bpsEsc(r.width)}mm × ${bpsEsc(r.length)}m
                    </button>
                `).join('');

            resultsEl.querySelectorAll('.bps-result-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const row = bpsLastResults[parseInt(this.dataset.index, 10)];
                    if (row) bpsAddItem(row);
                    document.getElementById('bps_search_input').value = '';
                    resultsEl.innerHTML = '';
                    bpsLastResults = [];
                    document.getElementById('bps_search_input').focus();
                });
            });
        } catch (e) {
            resultsEl.innerHTML = '<div class="text-danger small p-2">Network error.</div>';
        }
    }, 250);
});
// Enter key adds the top result directly, so a scanner/fast typist
// doesn't have to click.
document.getElementById('bps_search_input').addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const first = document.querySelector('.bps-result-btn');
    if (first) first.click();
});

/* ── Reset modal state each time it's opened ─────────────────── */
document.getElementById('bulkPrintSlittingModal').addEventListener('show.bs.modal', function () {
    bpsItems = [];
    bpsRender();
    document.getElementById('bps_paste_zone').value = '';
    document.getElementById('bps_search_input').value = '';
    document.getElementById('bps_search_results').innerHTML = '';
    document.getElementById('bps_resolve_feedback').innerHTML = '';
});

/* ── Print All Selected Coils ────────────────────────────────── */
function bpsPrintAll() {
    if (bpsItems.length === 0) return;
    document.getElementById('bpsPrintIdsInput').value = JSON.stringify(bpsItems.map(it => it.id));
    document.getElementById('bpsPrintForm').submit();
}
</script>

<?php if ($_SESSION['role'] === 'slitting'): ?>
    <div><a href="index.php" class="btn btn-secondary mt-3">← Back</a></div>
<?php else: ?>
    <div><a href="logout.php" class="btn btn-secondary mt-3">Logout</a></div>
<?php endif; ?>

<?php include 'footer.php'; ?>
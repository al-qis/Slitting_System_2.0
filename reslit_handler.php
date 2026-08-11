<?php
// reslit_handler.php
// coil_no now comes directly from reslit_product.coil_no (populated by both
// sfc.php and finish_product.php when the reslit_product row is created).
// The old slitting_product_id-based lookup is kept only as a fallback for
// legacy rows that may have been inserted with a blank coil_no.

session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'slitting') {
    die("Access denied");
}

include 'config.php';

// ── Helper: write one process_log row ─────────────────────────
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

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    || ($_POST['action'] ?? '') !== 'complete_reslit_direct'
) {
    header("Location: reslit.php");
    exit;
}

$parent_id = intval($_POST['id']);
$cut_type  = $_POST['cut_type'] ?? 'normal';

$roll_numbers   = $_POST['roll_number']   ?? [];
$cut_letters    = $_POST['cut_letter']    ?? [];
$new_widths     = $_POST['new_width']     ?? [];
$lengths        = $_POST['length']        ?? [];
$actual_lengths = $_POST['actual_length'] ?? [];
$send_to_sfc    = $_POST['send_to_sfc']   ?? [];

// Cut Into 2 leftover routing (mutually exclusive: SFC XOR Finished Product)
$slit_quantity    = floatval($_POST['slit_quantity'] ?? 0);
$leftover_length  = floatval($_POST['stock']         ?? 0); // "Remaining Stock" field
$leftover_to_sfc  = ($_POST['leftover_to_sfc'] ?? '') === '1';

// 1. Fetch parent reslit_product
$stmt = $conn->prepare("SELECT * FROM reslit_product WHERE id = ?");
$stmt->bind_param("i", $parent_id);
$stmt->execute();
$parent = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$parent) { die("Parent reslit record not found."); }

$originalSource = $parent['original_source'] ?? 'raw_material';

// slitting_product_id on reslit_product = the roll that was sent here.
$parent_slit_id = $parent['slitting_product_id'] ?? null;

// ── Coil No + mother_id ────────────────────────────────────────
// PRIMARY: both reslit_product.coil_no AND reslit_product.mother_id are
// now populated directly when this row is created, whether the source
// was finish_product.php's "Send to Reslit" or sfc.php's "Reslit" action
// (sfc.php now carries mother_id forward from the sfc row — see fix).
// Only fall back to deriving via slitting_product/mother_coil for older
// rows created before mother_id/coil_no were captured directly here.
$coil_no_val   = trim($parent['coil_no'] ?? '');
$mother_id_val = !empty($parent['mother_id']) ? intval($parent['mother_id']) : null;

if ($parent_slit_id && ($coil_no_val === '' || $mother_id_val === null)) {
    $sp_row = $conn->query(
        "SELECT mother_id, coil_no FROM slitting_product WHERE id=" . intval($parent_slit_id)
    )->fetch_assoc();
    if ($sp_row) {
        if ($mother_id_val === null) {
            $mother_id_val = intval($sp_row['mother_id']) ?: null;
        }
        if ($coil_no_val === '') {
            $coil_no_val = $sp_row['coil_no'] ?? '';
        }
    }
}

// Last-resort fallback: try mother_coil if still empty
if ($coil_no_val === '' && $mother_id_val) {
    $mc_row = $conn->query(
        "SELECT coil_no FROM mother_coil WHERE id=" . intval($mother_id_val)
    )->fetch_assoc();
    $coil_no_val = $mc_row['coil_no'] ?? '';
}

$conn->begin_transaction();

try {
    // ── STEP 1: Void the parent slitting_product row if present ────────
    // Free the UNIQUE KEY (lot_no, coil_no, roll_no) on the parent roll
    // so child rolls or leftovers can reuse the same lot number, coil, and roll.
    if ($parent_slit_id) {
        $stmt_void_parent = $conn->prepare("
            UPDATE slitting_product
            SET is_voided = 1,
                voided_at = NOW(),
                voided_reason = 'reslitted_into_child_rolls'
            WHERE id = ?
        ");
        $stmt_void_parent->bind_param("i", $parent_slit_id);
        $stmt_void_parent->execute();
        $stmt_void_parent->close();
    }

    // ── Validation: check for duplicate active (non-voided) lot+coil+roll ─────────
    $exclude_id = $parent_slit_id ?? 0;

    foreach ($roll_numbers as $index => $roll_label) {
        $letter      = trim($cut_letters[$index] ?? '');
        $temp_lot_no = $parent['lot_no'] . $letter;

        // Check against OTHER active non-voided rolls only
        $check = $conn->prepare("
            SELECT id FROM slitting_product
            WHERE lot_no = ? AND coil_no = ? AND roll_no = ?
              AND id != ?
              AND (is_voided = 0 OR is_voided IS NULL)
        ");
        $check->bind_param("sssi", $temp_lot_no, $coil_no_val, $roll_label, $exclude_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            throw new Exception(
                "Duplicate: Lot [{$temp_lot_no}] Coil [{$coil_no_val}] Roll [{$roll_label}] already exists on an active roll."
            );
        }
        $check->close();
    }

    // Cut Into 2 leftover, routed to Finished Product: exclude parent & voided rolls
    $leftover_roll_no = $parent['roll_no'] ?? '';
    if ($cut_type === 'cut_into_2' && $leftover_length > 0 && !$leftover_to_sfc) {
        $check = $conn->prepare("
            SELECT id FROM slitting_product
            WHERE lot_no = ? AND coil_no = ? AND roll_no = ?
              AND id != ?
              AND (is_voided = 0 OR is_voided IS NULL)
        ");
        $check->bind_param("sssi", $parent['lot_no'], $coil_no_val, $leftover_roll_no, $exclude_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            throw new Exception(
                "Duplicate: Lot [{$parent['lot_no']}] Coil [{$coil_no_val}] Roll [{$leftover_roll_no}] already exists on an active roll."
            );
        }
        $check->close();
    }

    $total_actual = 0;

    // ── Insert pass ───────────────────────────────────────────
    foreach ($roll_numbers as $index => $roll_label) {
        $letter  = trim($cut_letters[$index]   ?? '');
        $width   = floatval($new_widths[$index]    ?? 0);
        $nom_len = floatval($lengths[$index]        ?? 0);
        $act_raw = trim($actual_lengths[$index]    ?? '');
        $act_len = ($act_raw !== '' && is_numeric($act_raw)) ? floatval($act_raw) : $nom_len;

        $new_lot_no    = $parent['lot_no'] . $letter;
        $total_actual += $act_len;

        // ── PATH A: Send to SFC (mirrors save_slitting.php's SFC path) ──
        if (in_array($roll_label, $send_to_sfc, true)) {
            $sfc_stmt = $conn->prepare("
                INSERT INTO sfc
                    (mother_id, product, lot_no, coil_no, roll_no,
                     width, length, action, date_created)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'reslit', NOW())
            ");
            $sfc_stmt->bind_param(
                "issssdd",
                $mother_id_val,
                $parent['product'],
                $new_lot_no,
                $coil_no_val,
                $roll_label,
                $width,
                $act_len
            );
            if (!$sfc_stmt->execute()) {
                throw new Exception("Failed to insert into SFC: " . $sfc_stmt->error);
            }
            $sfc_id = $conn->insert_id;
            $sfc_stmt->close();

            log_process($conn, 'sfc', $sfc_id, $mother_id_val,
                null, 'IN', 'sent_to_sfc_from_reslit',
                "Roll {$roll_label} from reslit_product id={$parent_id}, width={$width}mm length={$act_len}m product={$parent['product']}");

            $audit_stmt = $conn->prepare("
                INSERT INTO slitting_audit_log
                    (mother_id, action, roll_no, destination, created_at)
                VALUES (?, 'send_to_sfc', ?, 'sfc_stock', NOW())
            ");
            $audit_stmt->bind_param("is", $mother_id_val, $roll_label);
            $audit_stmt->execute();
            $audit_stmt->close();

            continue; // skip the slitting_product / reslit_rolls insert below for this roll
        }

        // ── PATH B: Go to Finished Products (existing behavior) ─────
        $stmt_ins = $conn->prepare("
            INSERT INTO slitting_product
                (mother_id, parent_slit_id,
                 product, lot_no, coil_no, roll_no,
                 width, length, actual_length,
                 status, is_completed, stock_counted,
                 date_in, source, original_source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'IN', 1, 1, NOW(), 'reslit', ?)
        ");
        $stmt_ins->bind_param(
            "iissssddds",
            $mother_id_val,
            $parent_slit_id,
            $parent['product'],
            $new_lot_no,
            $coil_no_val,
            $roll_label,
            $width,
            $nom_len,
            $act_len,
            $originalSource
        );
        $stmt_ins->execute();
        $new_slit_id = $conn->insert_id;
        $stmt_ins->close();

        log_process($conn, 'slitting', $new_slit_id, $mother_id_val,
            null, 'IN', 'reslit_output',
            "Roll {$roll_label} from reslit_product id={$parent_id}, parent_slit_id={$parent_slit_id}");

        // B. Insert into reslit_rolls
        $stmt_roll = $conn->prepare("
            INSERT INTO reslit_rolls
                (parent_id, roll_no, cut_letter, new_width,
                 length, actual_length, original_source)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt_roll->bind_param(
            "issddds",
            $parent_id,
            $roll_label,
            $letter,
            $width,
            $nom_len,
            $act_len,
            $originalSource
        );
        $stmt_roll->execute();
        $stmt_roll->close();
    }

    // ── Cut Into 2: route the LEFTOVER balance — mutually exclusive ────
    // Exactly one branch runs (if/else, not two separate ifs), so the
    // leftover can never be inserted into both SFC and Finished Product.
    // The leftover STAYS on its original roll_no (e.g. "R-4") — it is
    // NOT renamed to "BALANCE", since it's still physically that same roll.
    if ($cut_type === 'cut_into_2' && $leftover_length > 0) {
        $leftover_width = floatval($parent['width'] ?? 0);

        if ($leftover_to_sfc) {
            // ── Leftover → SFC ONLY ──
            $sfc_stmt = $conn->prepare("
                INSERT INTO sfc
                    (mother_id, product, lot_no, coil_no, roll_no,
                     width, length, action, date_created)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'reslit_balance', NOW())
            ");
            $sfc_stmt->bind_param(
                "issssdd",
                $mother_id_val,
                $parent['product'],
                $parent['lot_no'],
                $coil_no_val,
                $leftover_roll_no,
                $leftover_width,
                $leftover_length
            );
            if (!$sfc_stmt->execute()) {
                throw new Exception("Failed to insert leftover into SFC: " . $sfc_stmt->error);
            }
            $sfc_leftover_id = $conn->insert_id;
            $sfc_stmt->close();

            log_process($conn, 'sfc', $sfc_leftover_id, $mother_id_val,
                null, 'IN', 'reslit_leftover_to_sfc',
                "Leftover {$leftover_length}m (roll {$leftover_roll_no}) from reslit_product id={$parent_id} routed to SFC (slit_quantity={$slit_quantity}m used)");

        } else {
            // ── Leftover → Finished Product ONLY ──
            $stmt_leftover = $conn->prepare("
                INSERT INTO slitting_product
                    (mother_id, parent_slit_id,
                     product, lot_no, coil_no, roll_no,
                     width, length, actual_length,
                     status, is_completed, stock_counted,
                     date_in, source, original_source)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'IN', 1, 1, NOW(), 'reslit', ?)
            ");
            $stmt_leftover->bind_param(
                "iissssddds",
                $mother_id_val,
                $parent_slit_id,
                $parent['product'],
                $parent['lot_no'],
                $coil_no_val,
                $leftover_roll_no,
                $leftover_width,
                $leftover_length,
                $leftover_length,
                $originalSource
            );
            if (!$stmt_leftover->execute()) {
                throw new Exception("Failed to insert leftover into Finished Product: " . $stmt_leftover->error);
            }
            $leftover_slit_id = $conn->insert_id;
            $stmt_leftover->close();

            log_process($conn, 'slitting', $leftover_slit_id, $mother_id_val,
                null, 'IN', 'reslit_leftover_output',
                "Leftover {$leftover_length}m (roll {$leftover_roll_no}) from reslit_product id={$parent_id} routed to Finished Product (slit_quantity={$slit_quantity}m used)");
        }
    }

    // Mark parent as completed — do NOT touch actual_length here.
    // It must keep the value it already had (the measured length of the
    // BALANCE roll itself); the per-output-roll actual lengths live on
    // reslit_rolls / slitting_product instead.
    $stmt_upd = $conn->prepare("
        UPDATE reslit_product
        SET status='completed', completed_at=NOW()
        WHERE id=?
    ");
    $stmt_upd->bind_param("i", $parent_id);
    $stmt_upd->execute();
    $stmt_upd->close();

    log_process($conn, 'reslit', $parent_id, $mother_id_val,
        'pending', 'completed', 'reslit_complete',
        "Output rolls: " . count($roll_numbers) . ", total={$total_actual}m");

    $conn->commit();
    header("Location: reslit.php?success=completed");
    exit;

} catch (Exception $e) {
    $conn->rollback();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <title>Reslit Error</title>
    </head>
    <body class="bg-light">
        <div class="container mt-5">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card border-danger shadow">
                        <div class="card-header bg-danger text-white">
                            <h4><i class="bi bi-exclamation-triangle-fill me-2"></i>Reslit Process Failed</h4>
                        </div>
                        <div class="card-body p-4 text-center">
                            <p class="lead text-danger fw-bold"><?= htmlspecialchars($e->getMessage()) ?></p>
                            <hr>
                            <p class="text-secondary">Go back and fix the issue, or add a letter suffix to make the lot number unique.</p>
                            <div class="d-flex justify-content-center gap-3 mt-4">
                                <button onclick="history.back()" class="btn btn-warning px-4 fw-bold">← Back to Form</button>
                                <a href="reslit.php" class="btn btn-outline-secondary px-4">Cancel</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}
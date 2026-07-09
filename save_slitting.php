<?php
// save_slitting.php — UPDATED (Schema Migration v2) — FIXED
// + V-Coil Dynamic TS/RS Naming (added)
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION['role'] !== 'slitting') {
    die("Access denied");
}

include 'config.php';

// ── Helper: write one process_log row ──────────────────────────
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
    $stmt = $conn->prepare(
        "INSERT INTO process_log
             (entity_type, entity_id, mother_id, from_status, to_status,
              performed_by, action_detail, remark)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) return;
    // types: s i i s s s s s
    $stmt->bind_param(
        "siisssss",
        $entity_type, $entity_id, $mother_id,
        $from_status, $to_status,
        $performed_by, $action_detail, $remark
    );
    $stmt->execute();
    $stmt->close();
}

// ── Helper: V-Coil Dynamic TS/RS Naming ─────────────────────────
// Applies ONLY to child products generated from a slit (never to the
// mother_coil record itself, which always keeps its original RS prefix).
//
// Rule: if the mother coil's Lot/Coil No contains "V", AND the resulting
// slit width matches one of the TS trigger widths, the child product gets
// the TS- prefix (+ White sticker, handled automatically by print_product.php's
// existing $PRODUCT_COLOR map). Otherwise it gets the standard RS- prefix.
// Non-V mother coils are left completely unaffected (original code passes through).
function resolveSlitProductCode(string $motherProductCode, bool $isVCoil, $slitWidth): array
{
    // Exact TS-trigger widths (mm), compared with a small float tolerance.
    $tsWidths = [66.5, 81.0, 109.5, 118.0, 309.0];

    $width = (float)$slitWidth;
    $matchesTsWidth = false;
    foreach ($tsWidths as $tsw) {
        if (abs($width - $tsw) < 0.01) {
            $matchesTsWidth = true;
            break;
        }
    }

    // Split "RS-3020" -> suffix "3020"
    $parts  = explode('-', $motherProductCode, 2);
    $suffix = $parts[1] ?? $motherProductCode;

    if ($isVCoil && $matchesTsWidth) {
        return ['code' => 'TS-' . $suffix, 'is_ts' => true];
    }

    if ($isVCoil) {
        // V coil, but width not on the TS list -> standard RS prefix
        return ['code' => 'RS-' . $suffix, 'is_ts' => false];
    }

    // Non-V mother coil: leave product code exactly as passed in — untouched
    return ['code' => $motherProductCode, 'is_ts' => false];
}

// ── Read form data ──────────────────────────────────────────────
$source_type = $_POST['source_type'] ?? '';
$mother_id   = intval($_POST['mother_id']  ?? 0);
$stock_id    = intval($_POST['stock_id']   ?? 0);
$cut_type    = $_POST['cut_type']          ?? '';
$product     = $conn->real_escape_string($_POST['product'] ?? '');
$lot_no      = $conn->real_escape_string($_POST['lot_no']  ?? '');
$coil_no     = $conn->real_escape_string($_POST['coil_no'] ?? '');

$roll_nos    = $_POST['roll_no']     ?? [];
$cut_letters = $_POST['cut_letter']  ?? [];
$lengths     = $_POST['length']      ?? [];
$widths      = $_POST['width']       ?? [];
$send_to_sfc = $_POST['send_to_sfc'] ?? [];

$sfc_balance_width = floatval($_POST['sfc_balance_width'] ?? 0);

if (!$mother_id || !$cut_type) {
    die("Error: Missing required fields (mother_id or cut_type)");
}

// ── V-Coil detection (based on the mother's Lot/Coil No) ────────
$isVCoil = (stripos($lot_no, 'V') !== false) || (stripos($coil_no, 'V') !== false);

$conn->begin_transaction();

try {
    // ── Fetch mother coil ───────────────────────────────────────
    $mother_result = $conn->query("SELECT * FROM mother_coil WHERE id=$mother_id");
    if (!$mother_result || $mother_result->num_rows === 0) {
        throw new Exception("Mother coil not found for ID: $mother_id");
    }
    $mother = $mother_result->fetch_assoc();

    $balance_stock_id = null;

    // ── CUT INTO 2: save leftover to stock_raw_material ────────
    if ($cut_type === 'cut_into_2') {
        $slit_quantity   = floatval($_POST['slit_quantity'] ?? 0);
        $leftover_length = floatval($_POST['stock']         ?? 0); // form field still named 'stock'

        if ($leftover_length > 0) {
            $new_lot_no = $lot_no;
            $grade_val  = $mother['grade'] ?? '';
            $width_val  = floatval($mother['width']);

            $insert_stock = $conn->prepare(
                "INSERT INTO stock_raw_material
                     (lot_no, coil_no, grade, width, length,
                      status, source_type, source_id, date_in)
                 VALUES (?, ?, ?, ?, ?, 'IN', 'slitting_cut_into_2', ?, NOW())"
            );
            // types: s s s d d i
            $insert_stock->bind_param(
                "sssddi",
                $new_lot_no, $coil_no, $grade_val,
                $width_val, $leftover_length, $mother_id
            );
            if (!$insert_stock->execute()) {
                throw new Exception("Failed to save leftover stock: " . $insert_stock->error);
            }
            $balance_stock_id = $conn->insert_id;
            $insert_stock->close();

            log_process($conn, 'stock', $balance_stock_id, $mother_id,
                null, 'IN', 'cut_into_2_leftover',
                "Leftover {$leftover_length}m saved from Cut Into 2");
        }

        if ($stock_id > 0) {
            $conn->query("UPDATE stock_raw_material SET status='OUT', updated_at=NOW() WHERE id=$stock_id");
            log_process($conn, 'stock', $stock_id, $mother_id,
                'IN', 'OUT', 'consumed_by_cut_into_2', '');
        }

        $conn->query("UPDATE raw_material_log SET status='OUT', date_out=NOW() WHERE mother_id=$mother_id LIMIT 1");
    }

    // ── Process each roll ───────────────────────────────────────
    foreach ($roll_nos as $index => $roll_no) {
        $length       = floatval($lengths[$index]  ?? 0);
        $width        = floatval($widths[$index]   ?? 0);
        $cut_letter   = trim($cut_letters[$index]  ?? '');
        $roll_lot_no  = $lot_no . $cut_letter;
        $roll_no_safe = $roll_no;

        // ── Resolve this roll's product code (TS/RS dynamic naming) ──
        $resolved     = resolveSlitProductCode($product, $isVCoil, $width);
        $roll_product = $resolved['code'];

        $roll_number_str  = (string)($index + 1);
        $send_to_sfc_flag = in_array($roll_number_str, $send_to_sfc);

        // ── PATH A: Send to SFC ──────────────────────────────
        if ($send_to_sfc_flag) {
            $sfc_stmt = $conn->prepare(
                "INSERT INTO sfc
                     (mother_id, product, lot_no, coil_no, roll_no,
                      width, length, action, date_created)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'slitting', NOW())"
            );
            // types: i s s s s d d
            $sfc_stmt->bind_param(
                "issssdd",
                $mother_id, $roll_product, $roll_lot_no, $coil_no,
                $roll_no_safe, $width, $length
            );
            if (!$sfc_stmt->execute()) {
                throw new Exception("Failed to insert into SFC: " . $sfc_stmt->error);
            }
            $sfc_id = $conn->insert_id;
            $sfc_stmt->close();

            log_process($conn, 'sfc', $sfc_id, $mother_id,
                null, 'IN', 'sent_to_sfc_from_slitting',
                "Roll {$roll_no_safe} width={$width}mm length={$length}m product={$roll_product}");

            $rn_esc = $conn->real_escape_string($roll_no_safe);
            $conn->query(
                "INSERT INTO slitting_audit_log
                     (mother_id, action, roll_no, destination, created_at)
                 VALUES ($mother_id, 'send_to_sfc', '$rn_esc', 'sfc_stock', NOW())"
            );
            continue;
        }

        // ── PATH B: Go to Finished Products ─────────────────
        $slit_quantity_val = floatval($_POST['slit_quantity'] ?? 0);

        // Check if leftover_length column exists (migration may not have run yet)
        // Use a safe fallback: try leftover_length first, fall back to stock column name
        $col_check = $conn->query(
            "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'slitting_product'
               AND COLUMN_NAME  = 'leftover_length'"
        );
        $col_row        = $col_check->fetch_assoc();
        $leftover_col   = ($col_row['cnt'] > 0) ? 'leftover_length' : 'stock';

        // Build insert dynamically based on which column exists
        $insert_stmt = $conn->prepare(
            "INSERT INTO slitting_product
                 (product, lot_no, coil_no, roll_no, width, length,
                  mother_id, status, cut_type, slit_quantity,
                  {$leftover_col}, parent_slit_id, date_in, source)
             VALUES
                 (?, ?, ?, ?, ?, ?, ?, 'IN', ?, ?, NULL, NULL, NOW(), ?)"
        );
        if (!$insert_stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        // types: s s s s d d i s d s
        $insert_stmt->bind_param(
            "ssssddisds",
            $roll_product, $roll_lot_no, $coil_no, $roll_no_safe,
            $width, $length, $mother_id,
            $cut_type, $slit_quantity_val,
            $source_type
        );
        if (!$insert_stmt->execute()) {
            throw new Exception("Failed to insert slitting product: " . $insert_stmt->error);
        }
        $new_slit_id = $conn->insert_id;
        $insert_stmt->close();

        log_process($conn, 'slitting', $new_slit_id, $mother_id,
            null, 'IN',
            "slitting_{$cut_type}",
            "Roll {$roll_no_safe} width={$width}mm length={$length}m product={$roll_product}");

        $rn_esc = $conn->real_escape_string($roll_no_safe);
        $conn->query(
            "INSERT INTO slitting_audit_log
                 (mother_id, action, roll_no, destination, created_at)
             VALUES ($mother_id, 'send_to_finished', '$rn_esc', 'slitting_product', NOW())"
        );
    }

    // ── Normal slitting: mark mother OUT ────────────────────────
    if ($cut_type === 'normal') {
        $conn->query("UPDATE mother_coil SET stock=0, status='OUT', date_out=NOW() WHERE id=$mother_id");

        if ($stock_id > 0) {
            $conn->query("UPDATE stock_raw_material SET status='OUT', updated_at=NOW() WHERE id=$stock_id");
        }

        // Optional SFC balance width entry
        if ($sfc_balance_width > 0) {
            $balance_length  = floatval($lengths[0] ?? 0);
            $balance_roll_no = "BALANCE";

            // Resolve TS/RS naming for the balance-width entry too
            $balance_resolved = resolveSlitProductCode($product, $isVCoil, $sfc_balance_width);
            $balance_product  = $balance_resolved['code'];

            $sfc_bal = $conn->prepare(
                "INSERT INTO sfc
                     (mother_id, product, lot_no, coil_no, roll_no,
                      width, length, action, date_created)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'slitting_balance', NOW())"
            );
            // types: i s s s s d d
            $sfc_bal->bind_param(
                "issssdd",
                $mother_id, $balance_product, $lot_no, $coil_no,
                $balance_roll_no, $sfc_balance_width, $balance_length
            );
            if (!$sfc_bal->execute()) {
                throw new Exception("Failed to insert SFC balance: " . $sfc_bal->error);
            }
            $sfc_bal_id = $conn->insert_id;
            $sfc_bal->close();

            log_process($conn, 'sfc', $sfc_bal_id, $mother_id,
                null, 'IN', 'balance_width_to_sfc',
                "Balance width {$sfc_balance_width}mm saved to SFC product={$balance_product}");
        }

        $conn->query(
            "INSERT INTO mother_coil_audit_log
                 (mother_id, action_type, performed_at, remark)
             VALUES ($mother_id, 'OUT', NOW(), 'Normal slitting completed')"
        );
    }

    // ── Cut Into 2: mark mother OUT ─────────────────────────────
    if ($cut_type === 'cut_into_2') {
        $conn->query("UPDATE mother_coil SET stock=0, status='OUT', date_out=NOW() WHERE id=$mother_id");
    }

    $conn->commit();

    $_SESSION['success'] = "✓ Slitting products saved successfully (" . count($roll_nos) . " rolls)";
    header("Location: raw_material.php");
    exit;

} catch (Exception $e) {
    $conn->rollback();
    die("Error: " . $e->getMessage());
}

$conn->close();
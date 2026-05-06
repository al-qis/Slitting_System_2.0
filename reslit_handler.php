<?php
// reslit_handler.php — UPDATED (Schema Migration v2)
// Changes vs original:
//   • Each new slitting_product row from reslit gets parent_slit_id set
//     to the source slitting_product via reslit_product.slitting_product_id
//   • Every insert writes to process_log

session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'slitting') {
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
    $stmt = $conn->prepare("
        INSERT INTO process_log
            (entity_type, entity_id, mother_id, from_status, to_status,
             performed_by, action_detail, remark)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "siisssss",
        $entity_type, $entity_id, $mother_id,
        $from_status,  $to_status,
        $performed_by, $action_detail, $remark
    );
    $stmt->execute();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'complete_reslit_direct') {

    $parent_id = intval($_POST['id']);
    $cut_type  = $_POST['cut_type'];

    $roll_numbers   = $_POST['roll_number']   ?? [];
    $cut_letters    = $_POST['cut_letter']    ?? [];
    $new_widths     = $_POST['new_width']     ?? [];
    $lengths        = $_POST['length']        ?? [];
    $actual_lengths = $_POST['actual_length'] ?? [];

    // 1. Fetch parent reslit_product
    $stmt = $conn->prepare("SELECT * FROM reslit_product WHERE id = ?");
    $stmt->bind_param("i", $parent_id);
    $stmt->execute();
    $parent = $stmt->get_result()->fetch_assoc();

    if (!$parent) { die("Parent record not found."); }

    $originalSource = $parent['original_source'];

    // UPDATED: carry the FK chain — slitting_product_id on the reslit_product
    // is the "parent roll" that was sent here. Use it as parent_slit_id.
    $parent_slit_id = $parent['slitting_product_id'] ?? null;

    // Derive mother_id: prefer reslit_product having it via parent slitting row
    $mother_id_val = null;
    if ($parent_slit_id) {
        $sp_row = $conn->query("SELECT mother_id FROM slitting_product WHERE id=$parent_slit_id")->fetch_assoc();
        $mother_id_val = $sp_row ? intval($sp_row['mother_id']) : null;
    }

    $conn->begin_transaction();

    try {
        // === Validation loop ===
        foreach ($roll_numbers as $index => $roll_label) {
            $letter      = $cut_letters[$index];
            $temp_lot_no = $parent['lot_no'] . $letter;
            $coil_no     = $parent['coil_no'];

            $check = $conn->prepare("
                SELECT id FROM slitting_product
                WHERE lot_no = ? AND coil_no = ? AND roll_no = ?
            ");
            $check->bind_param("sss", $temp_lot_no, $coil_no, $roll_label);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                throw new Exception(
                    "Duplicate Error: [Lot: $temp_lot_no | Coil: $coil_no | Roll: $roll_label] already exists."
                );
            }
            $check->close();
        }

        $total_actual = 0;

        // === Processing loop ===
        foreach ($roll_numbers as $index => $roll_label) {
            $letter  = $cut_letters[$index];
            $width   = floatval($new_widths[$index]);
            $nom_len = floatval($lengths[$index]);
            $act_len = floatval($actual_lengths[$index]);

            $new_lot_no = $parent['lot_no'] . $letter;
            $total_actual += $act_len;

            // A. Insert into slitting_product
            // UPDATED: parent_slit_id set so the traceability chain is unbroken
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
                $parent_slit_id,       // ← NEW: link back to original slit roll
                $parent['product'],
                $new_lot_no,
                $parent['coil_no'],
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

        // 4. Update parent reslit_product status
        $stmt_upd = $conn->prepare("
            UPDATE reslit_product
            SET status = 'completed', actual_length = ?
            WHERE id = ?
        ");
        $stmt_upd->bind_param("di", $total_actual, $parent_id);
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
        // Error UI block (identical style to original)
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
                                <p class="text-secondary">Go back and add a cut letter to make the lot combination unique.</p>
                                <div class="d-flex justify-content-center gap-3 mt-4">
                                    <button onclick="history.back()" class="btn btn-warning px-4 fw-bold">
                                        ← Back to Form
                                    </button>
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

} else {
    header("Location: reslit.php");
    exit;
}
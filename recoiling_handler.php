<?php
// recoiling_handler.php

session_start();

include 'config.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'start_and_complete_recoiling'
) {

    $id = intval($_POST['id'] ?? 0);

    // AUTO DETECT TOTAL ROLLS
    $total_rolls = 0;
    if (isset($_POST['actual_length']) && is_array($_POST['actual_length'])) {
        $total_rolls = count($_POST['actual_length']);
    } elseif (isset($_POST['new_width']) && is_array($_POST['new_width'])) {
        $total_rolls = count($_POST['new_width']);
    }

    if ($id <= 0) {
        header("Location: recoiling.php?error=invalid_id");
        exit;
    }

    // 1. Get original product data (Now includes original_source)
    $stmt = $conn->prepare("SELECT * FROM recoiling_product WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $original = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$original) {
        header("Location: recoiling.php?error=not_found");
        exit;
    }

    if (($original['status'] ?? '') === 'completed') {
        header("Location: recoiling.php?error=already_completed&id=$id");
        exit;
    }

    $conn->begin_transaction();

    try {
        $total_actual_length = 0.0;
        $summary_width = 0.0;
        $all_remarks = [];

        for ($i = 0; $i < $total_rolls; $i++) {

            $new_width     = floatval($_POST['new_width'][$i] ?? 0);
            $length        = floatval($_POST['length'][$i] ?? 0);
            $defect        = floatval($_POST['defect'][$i] ?? 0);
            $actual_length = floatval($_POST['actual_length'][$i] ?? 0);
            $remark        = trim($_POST['remark'][$i] ?? '');
            $roll_number   = intval($_POST['roll_number'][$i] ?? 1);
            $letter        = trim($_POST['letter'][$i] ?? ''); 

            $new_roll_no = 'R' . $roll_number;
            $new_lot_no  = $original['lot_no'] . ($letter !== '' ? $letter : '');
            
            // Build Remark
            if (!empty($remark) || $defect > 0 || $letter !== '') {
                $r = "{$new_lot_no} {$original['coil_no']} / {$new_roll_no} : ";
                $r .= ($defect > 0) ? "Defect {$defect}m" : "";
                if (!empty($remark)) $r .= ($defect > 0 ? " - " : "") . $remark;
                $all_remarks[] = $r;
            }

            $mother_id_val = (!empty($original['mother_id']) && $original['mother_id'] != 0) ? $original['mother_id'] : NULL;

            // 2. Insert into slitting_product with original_source maintained
            $insert_stmt = $conn->prepare("
                INSERT INTO slitting_product
                (recoiling_id, mother_id, product, lot_no, coil_no, roll_no, width, length, actual_length, status, is_completed, stock_counted, original_source, source, date_in)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'IN', 1, 1, ?, 'recoiling', NOW())
            ");

            // We pass $original['original_source'] directly into the new product
            $insert_stmt->bind_param(
                "iissssddds", 
                $id,
                $mother_id_val,
                $original['product'],
                $new_lot_no,
                $original['coil_no'],
                $new_roll_no,
                $new_width,
                $length,
                $actual_length,
                $original['original_source'] // THIS IS THE FIX
            );

            if (!$insert_stmt->execute()) {
                throw new Exception("Insert failed: " . $insert_stmt->error);
            }
            $insert_stmt->close();

            $total_actual_length += $actual_length;
            if ($i === 0) $summary_width = $new_width;
        }

        $combined_remark = !empty($all_remarks) ? implode(" | ", $all_remarks) : "";

        // 3. Update recoiling_product status
        $update_stmt = $conn->prepare("
            UPDATE recoiling_product
            SET status = 'completed',
                completed_at = NOW(),
                started_at = NOW(),
                new_width = ?,
                new_length = ?,
                remark = ?
            WHERE id = ?
        ");

        $update_stmt->bind_param("ddsi", $summary_width, $total_actual_length, $combined_remark, $id);
        $update_stmt->execute();
        $update_stmt->close();

        $conn->commit();
        header("Location: recoiling.php?success=completed&id=$id");
        exit;

    } catch (Throwable $e) {
        $conn->rollback();
        header("Location: recoiling.php?error=process_failed&msg=" . urlencode($e->getMessage()));
        exit;
    }
}
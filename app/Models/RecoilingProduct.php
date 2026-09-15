<?php

namespace App\Models;

use App\Core\Model;
use Exception;
use RuntimeException;
use Throwable;

class RecoilingProduct extends Model
{
    /**
     * Helper to write process log
     */
    public function logProcess(
        string  $entity_type,
        int     $entity_id,
        ?int    $mother_id,
        ?string $from_status,
        string  $to_status,
        string  $action_detail = '',
        string  $remark = ''
    ): void {
        $performed_by = $_SESSION['role'] ?? 'system';
        $stmt = $this->mysqli->prepare("
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

    /**
     * Get main recoiling list (union of recoiling_product and raw_material_log sfc)
     */
    public function getMainList(): array
    {
        $query = "
            (SELECT
                rp.id, rp.status,
                IFNULL(rp.product, mc.product) as product,
                IFNULL(rp.lot_no, mc.lot_no)   as lot_no,
                IFNULL(rp.coil_no, mc.coil_no) as coil_no,
                rp.roll_no,
                IFNULL(rp.width, mc.width)     as width,
                IFNULL(rp.length, mc.length)   as length,
                IFNULL(rp.actual_length, IFNULL(rp.length, mc.length)) as actual_length,
                rp.new_length, rp.date_in, rp.completed_at, rp.remark, rp.mother_id,
                'recoiling_product' as source_table
            FROM recoiling_product rp
            LEFT JOIN mother_coil mc ON rp.mother_id = mc.id)
            UNION ALL
            (SELECT
                log.id, 'sfc' as status,
                mc.product, mc.lot_no, mc.coil_no, '-' as roll_no,
                mc.width, mc.length, mc.length as actual_length,
                NULL as new_length, log.date_in, NULL as completed_at,
                log.remark, log.mother_id,
                'raw_material_log' as source_table
            FROM raw_material_log log
            JOIN mother_coil mc ON log.mother_id = mc.id
            WHERE log.status = 'IN' AND log.action = 'sfc')
            ORDER BY
              CASE status
                WHEN 'sfc'         THEN 1
                WHEN 'in_progress' THEN 2
                WHEN 'pending'     THEN 3
                WHEN 'completed'   THEN 4
                ELSE 5
              END,
              date_in ASC
        ";
        return $this->queryAll($query);
    }

    /**
     * Get child rolls grouped by recoiling_id
     */
    public function getChildRolls(): array
    {
        $query = "
            SELECT recoiling_id, lot_no, coil_no, roll_no, width, length, actual_length
            FROM slitting_product
            WHERE recoiling_id IS NOT NULL
            ORDER BY recoiling_id ASC, id ASC
        ";
        $rows = $this->queryAll($query);
        $children = [];
        foreach ($rows as $c) {
            $children[(int)$c['recoiling_id']][] = $c;
        }
        return $children;
    }

    /**
     * Get KPI counts (pending vs completed)
     */
    public function getKpiCounts(): array
    {
        $resPending = $this->queryOne("SELECT COUNT(*) AS c FROM recoiling_product WHERE status IN ('pending', 'in_progress')");
        $resCompleted = $this->queryOne("SELECT COUNT(*) AS c FROM recoiling_product WHERE status='completed'");

        return [
            'pending'   => (int)($resPending['c'] ?? 0),
            'completed' => (int)($resCompleted['c'] ?? 0),
        ];
    }

    /**
     * Get all data for Excel export
     */
    public function getExportData(): array
    {
        $sql = "
            SELECT
                rp.id, rp.status, rp.roll_no, rp.date_in, rp.completed_at, rp.remark,
                rp.new_length, rp.mother_id,
                IFNULL(rp.product, mc.product)   AS product,
                IFNULL(rp.lot_no,  mc.lot_no)    AS lot_no,
                IFNULL(rp.coil_no, mc.coil_no)   AS coil_no,
                IFNULL(rp.width,   mc.width)     AS width,
                IFNULL(rp.length,  mc.length)    AS length,
                IFNULL(rp.actual_length, IFNULL(rp.length, mc.length)) AS actual_length,
                rp.cut_type
            FROM recoiling_product rp
            LEFT JOIN mother_coil mc ON rp.mother_id = mc.id
            ORDER BY rp.id ASC
        ";
        return $this->queryAll($sql);
    }

    /**
     * Find single record by ID
     */
    public function findById(int $id): ?array
    {
        return $this->queryOne("SELECT * FROM recoiling_product WHERE id = :id", ['id' => $id]);
    }

    /**
     * Update fields of a recoiling product
     */
    public function updateRecord(int $id, array $data): bool
    {
        $sql = "UPDATE recoiling_product SET 
            product = :product, 
            lot_no = :lot_no, 
            coil_no = :coil_no, 
            roll_no = :roll_no, 
            width = :width, 
            length = :length, 
            actual_length = :actual_length 
            WHERE id = :id";
        
        return $this->execute($sql, [
            'product'       => $data['product'],
            'lot_no'        => $data['lot_no'],
            'coil_no'       => $data['coil_no'],
            'roll_no'       => $data['roll_no'],
            'width'         => $data['width'],
            'length'        => $data['length'],
            'actual_length' => $data['actual_length'],
            'id'            => $id,
        ]);
    }

    /**
     * Delete recoiling product and associated QR file if exists
     */
    public function deleteRecord(int $id): bool
    {
        $row = $this->findById($id);
        if (!$row) {
            return false;
        }

        $ok = $this->execute("DELETE FROM recoiling_product WHERE id = :id", ['id' => $id]);
        if ($ok && !empty($row['qr_code']) && file_exists($row['qr_code'])) {
            unlink($row['qr_code']);
        }
        return $ok;
    }

    /**
     * Update process status (in_progress or pending)
     */
    public function updateProcessStatus(int $id, string $status): bool
    {
        if ($status === 'in_progress') {
            $stmt = $this->mysqli->prepare("UPDATE recoiling_product SET status = 'in_progress', started_at = COALESCE(started_at, NOW()) WHERE id = ? AND status != 'completed'");
        } else {
            $stmt = $this->mysqli->prepare("UPDATE recoiling_product SET status = 'pending', started_at = NULL WHERE id = ? AND status = 'in_progress'");
        }

        $stmt->bind_param("i", $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Unified intake transaction
     */
    public function processIntake(string $lot_no, string $coil_no, string $roll_no, string $source = 'unified'): array
    {
        $this->mysqli->begin_transaction();
        try {
            $stmt = $this->mysqli->prepare("
                SELECT * FROM slitting_product
                WHERE LOWER(lot_no) = LOWER(?) AND LOWER(coil_no) = LOWER(?) AND LOWER(roll_no) = LOWER(?)
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->bind_param("sss", $lot_no, $coil_no, $roll_no);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$product) {
                throw new Exception("No matching roll found for Lot {$lot_no} / Coil {$coil_no} / Roll {$roll_no}.");
            }

            $id = (int)$product['id'];

            if ((int)$product['is_voided'] === 1) {
                throw new Exception("This roll has been voided and can't be sent to recoiling.");
            }
            if ((int)$product['is_recoiled'] === 1) {
                throw new Exception("This roll has already been sent to recoiling.");
            }
            if ((int)$product['is_reslitted'] === 1) {
                throw new Exception("This roll has already been sent to reslit — it can't also go to recoiling.");
            }
            if ((int)$product['stock_counted'] !== 1) {
                throw new Exception("This roll hasn't been stock-counted yet (Actual Length not recorded) — finish that on Finished Product first.");
            }
            if (strtoupper(trim($product['status'] ?? '')) !== 'IN') {
                throw new Exception("This roll's status is \"{$product['status']}\" — only rolls currently IN stock can be sent to recoiling.");
            }

            $upd = $this->mysqli->prepare("UPDATE slitting_product SET is_recoiled = 1 WHERE id = ?");
            $upd->bind_param("i", $id);
            $upd->execute();
            $upd->close();

            $ins = $this->mysqli->prepare("
                INSERT INTO recoiling_product
                    (slitting_product_id, mother_id, product, lot_no, coil_no, roll_no,
                     width, length, actual_length, status, original_source, date_in)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
            ");
            $originalSource = $product['original_source'] ?? $product['source'] ?? 'raw_material';
            $motherId = $product['mother_id'] !== null ? (int)$product['mother_id'] : null;
            $ins->bind_param(
                "iisssssdds",
                $id,
                $motherId,
                $product['product'],
                $product['lot_no'],
                $product['coil_no'],
                $product['roll_no'],
                $product['width'],
                $product['length'],
                $product['actual_length'],
                $originalSource
            );
            if (!$ins->execute()) {
                throw new Exception("Failed to add to recoiling queue: " . $ins->error);
            }
            $recoilingId = $this->mysqli->insert_id;
            $ins->close();

            $this->logProcess('slitting', $id, $motherId, 'IN', 'IN', 'send_to_recoiling',
                "Added via Recoiling page intake ({$source}); recoiling_product id={$recoilingId}");
            $this->logProcess('recoiling', $recoilingId, $motherId, null, 'pending', 'created',
                "Created via Recoiling page intake ({$source}) from slitting_product id={$id}");

            $this->mysqli->commit();

            return [
                'ok'  => true,
                'msg' => "Added {$product['lot_no']} {$product['coil_no']} {$product['roll_no']} to the recoiling queue.",
                'roll' => [
                    'id'      => $recoilingId,
                    'product' => $product['product'],
                    'lot_no'  => $product['lot_no'],
                    'coil_no' => $product['coil_no'],
                    'roll_no' => $product['roll_no'],
                ],
            ];
        } catch (Throwable $e) {
            $this->mysqli->rollback();
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    /**
     * Send back to Finished Product (Undo intake)
     */
    public function sendBackToFinishedProduct(int $id): void
    {
        $this->mysqli->begin_transaction();
        try {
            $stmt = $this->mysqli->prepare("SELECT * FROM recoiling_product WHERE id=? FOR UPDATE");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $rp = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$rp) {
                throw new RuntimeException("not_found");
            }
            if ($rp['status'] !== 'pending') {
                throw new RuntimeException("not_pending");
            }

            $slittingId = isset($rp['slitting_product_id']) ? (int)$rp['slitting_product_id'] : 0;
            if ($slittingId <= 0) {
                throw new RuntimeException("no_source_finished_product");
            }

            $mid = isset($rp['mother_id']) ? (int)$rp['mother_id'] : null;
            $original_source = $rp['original_source'] ?? 'raw_material';

            $stmt = $this->mysqli->prepare("UPDATE slitting_product SET is_recoiled=0 WHERE id=?");
            $stmt->bind_param("i", $slittingId);
            $stmt->execute();
            $stmt->close();

            $stmt = $this->mysqli->prepare("DELETE FROM recoiling_product WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();

            $this->logProcess('recoiling', $id, $mid, 'pending', 'returned', 'sent_back_to_finished_product',
                "Sent back to Finished Product; slitting_product id={$slittingId}");
            $this->logProcess('slitting', $slittingId, $mid, 'OUT', 'IN', 'returned_from_recoiling',
                "Returned from recoiling_product id={$id}");

            $stmt = $this->mysqli->prepare("
                INSERT INTO source_tracking_log
                    (product_id, table_name, original_source, current_source, action)
                VALUES (?, 'recoiling_product', ?, 'finished_product', 'sent_back_to_finished_product')
            ");
            $stmt->bind_param("is", $slittingId, $original_source);
            $stmt->execute();
            $stmt->close();

            $this->mysqli->commit();
        } catch (Throwable $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }

    /**
     * Complete recoiling process with cuts / rewinding
     */
    public function completeProcess(int $id, array $postData): void
    {
        $total_rolls = 0;
        if (isset($postData['actual_length']) && is_array($postData['actual_length'])) {
            $total_rolls = count($postData['actual_length']);
        } elseif (isset($postData['new_width']) && is_array($postData['new_width'])) {
            $total_rolls = count($postData['new_width']);
        }

        $stmt = $this->mysqli->prepare("SELECT * FROM recoiling_product WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $original = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$original) {
            throw new Exception("Record not found.");
        }
        if (($original['status'] ?? '') === 'completed') {
            throw new Exception("already_completed");
        }

        $parent_slit_id = intval($original['slitting_product_id'] ?? 0) ?: null;
        $mother_id_val  = $original['mother_id'] ?? null;
        $cut_type       = trim($postData['cut_type'] ?? 'normal');
        $is_same_roll   = in_array($cut_type, ['rewinding', 'normal']);

        if ($cut_type === 'cut_into_2') {
            $duplicates = [];
            for ($i = 0; $i < $total_rolls; $i++) {
                $letter     = trim($postData['letter'][$i] ?? '');
                $new_lot_no = $original['lot_no'] . $letter;
                $new_roll   = $original['roll_no'];
                $exclude_id = $parent_slit_id ?? 0;

                $chk = $this->mysqli->prepare("
                    SELECT COUNT(*) AS cnt
                    FROM slitting_product
                    WHERE lot_no = ? AND coil_no = ? AND roll_no = ?
                      AND id != ?
                      AND (is_voided = 0 OR is_voided IS NULL)
                ");
                $chk->bind_param("sssi", $new_lot_no, $original['coil_no'], $new_roll, $exclude_id);
                $chk->execute();
                $cnt = (int)($chk->get_result()->fetch_assoc()['cnt'] ?? 0);
                $chk->close();

                if ($cnt > 0) {
                    $duplicates[] = "{$new_lot_no} {$original['coil_no']} {$new_roll}";
                }
            }

            if (!empty($duplicates)) {
                $dupList = implode(', ', array_unique($duplicates));
                throw new Exception("duplicate_lot:" . $dupList);
            }
        }

        $this->mysqli->begin_transaction();
        try {
            if ($parent_slit_id) {
                if ($cut_type === 'cut_into_2') {
                    $voided_lot = $original['lot_no'] . '_OLD_' . $parent_slit_id;
                    $void_stmt = $this->mysqli->prepare("
                        UPDATE slitting_product
                        SET lot_no = ?, is_recoiled = 1, is_voided = 1, voided_at = NOW(), voided_reason = ?
                        WHERE id = ?
                    ");
                    $reason = 'replaced_by_recoil_cut_into_2';
                    $void_stmt->bind_param("ssi", $voided_lot, $reason, $parent_slit_id);
                } else {
                    $voided_roll = $original['roll_no'] . '_void_' . $parent_slit_id;
                    $void_stmt = $this->mysqli->prepare("
                        UPDATE slitting_product
                        SET roll_no = ?, is_recoiled = 1, is_voided = 1, voided_at = NOW(), voided_reason = ?
                        WHERE id = ?
                    ");
                    $reason = 'replaced_by_recoil_' . $cut_type;
                    $void_stmt->bind_param("ssi", $voided_roll, $reason, $parent_slit_id);
                }

                $void_stmt->execute();
                $void_stmt->close();

                $voided_ref = ($cut_type === 'cut_into_2') ? ($voided_lot ?? '') : ($voided_roll ?? '');
                $this->logProcess('slitting', $parent_slit_id, $mother_id_val,
                    'IN', 'IN', 'voided_for_recoil',
                    "renamed to {$voided_ref}, mode={$cut_type}, recoiling_product id={$id}"
                );
            }

            $total_actual_length = 0.0;
            $summary_width       = 0.0;
            $all_remarks         = [];

            for ($i = 0; $i < $total_rolls; $i++) {
                $new_width     = floatval($postData['new_width'][$i]     ?? 0);
                $length        = floatval($postData['length'][$i]        ?? 0);
                $defect        = floatval($postData['defect'][$i]        ?? 0);
                $actual_length = floatval($postData['actual_length'][$i] ?? 0);
                $remark        = trim($postData['remark'][$i]            ?? '');
                $letter        = trim($postData['letter'][$i]            ?? '');

                if ($is_same_roll) {
                    $new_roll_no = $original['roll_no'];
                    $new_lot_no  = $original['lot_no'];
                } else {
                    $new_roll_no = $original['roll_no'];
                    $new_lot_no  = $original['lot_no'] . $letter;
                }

                if (!empty($remark) || $defect > 0) {
                    $r  = "{$new_lot_no} {$original['coil_no']} / {$new_roll_no} : ";
                    $r .= ($defect > 0) ? "Defect {$defect}m" : "";
                    if (!empty($remark)) $r .= ($defect > 0 ? " - " : "") . $remark;
                    $all_remarks[] = $r;
                }

                $insert_stmt = $this->mysqli->prepare("
                    INSERT INTO slitting_product
                        (recoiling_id, mother_id, parent_slit_id,
                         product, lot_no, coil_no, roll_no,
                         width, length, actual_length,
                         status, is_completed, stock_counted,
                         original_source, source, date_in)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'IN', 1, 1, ?, 'recoiling', NOW())
                ");

                $insert_stmt->bind_param(
                    "iiissssddds",
                    $id,
                    $mother_id_val,
                    $parent_slit_id,
                    $original['product'],
                    $new_lot_no,
                    $original['coil_no'],
                    $new_roll_no,
                    $new_width,
                    $length,
                    $actual_length,
                    $original['original_source']
                );

                if (!$insert_stmt->execute()) {
                    throw new Exception("Insert failed: " . $insert_stmt->error);
                }
                $new_slit_id = $this->mysqli->insert_id;
                $insert_stmt->close();

                $this->logProcess('slitting', $new_slit_id, $mother_id_val,
                    null, 'IN', 'recoiling_output',
                    "Mode={$cut_type} {$new_lot_no} {$original['coil_no']} {$new_roll_no} "
                    . "actual={$actual_length}m width={$new_width}mm from recoiling_product id={$id}"
                );

                $total_actual_length += $actual_length;
                if ($i === 0) $summary_width = $new_width;
            }

            $combined_remark = !empty($all_remarks) ? implode(" | ", $all_remarks) : "";

            $update_stmt = $this->mysqli->prepare("
                UPDATE recoiling_product
                SET status       = 'completed',
                    completed_at = NOW(),
                    started_at   = COALESCE(started_at, NOW()),
                    new_width    = ?,
                    new_length   = ?,
                    remark       = ?,
                    cut_type     = ?
                WHERE id = ?
            ");
            $update_stmt->bind_param("ddssi",
                $summary_width, $total_actual_length, $combined_remark, $cut_type, $id);
            $update_stmt->execute();
            $update_stmt->close();

            $this->logProcess('recoiling', $id, $mother_id_val,
                $original['status'] ?? 'in_progress', 'completed', 'recoiling_complete',
                "Mode={$cut_type}, rolls={$total_rolls}, total_length={$total_actual_length}m"
            );

            $this->mysqli->commit();
        } catch (Throwable $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }

    /**
     * Get summary report data (counts and rows)
     */
    public function getSummaryReport(string $month, string $cut_type): array
    {
        $base_where  = "WHERE rp.status = 'completed'";
        $base_types  = '';
        $base_vals   = [];

        if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $base_where  .= " AND DATE_FORMAT(rp.completed_at, '%Y-%m') = ?";
            $base_types  .= 's';
            $base_vals[]  = $month;
        }

        $count_sql = "
            SELECT cut_type, COUNT(*) AS cnt
            FROM recoiling_product rp
            $base_where
            GROUP BY cut_type
        ";

        $stmt = $this->mysqli->prepare($count_sql);
        if ($base_types) {
            $stmt->bind_param($base_types, ...$base_vals);
        }
        $stmt->execute();
        $count_res = $stmt->get_result();
        $stmt->close();

        $counts = ['rewinding' => 0, 'normal' => 0, 'cut_into_2' => 0];
        while ($row = $count_res->fetch_assoc()) {
            $ct = $row['cut_type'] ?? '';
            if (isset($counts[$ct])) {
                $counts[$ct] = (int)$row['cnt'];
            }
        }

        $detail_where  = $base_where;
        $detail_types  = $base_types;
        $detail_vals   = $base_vals;

        $valid_types = ['rewinding', 'normal', 'cut_into_2'];
        if (in_array($cut_type, $valid_types, true)) {
            $detail_where  .= " AND rp.cut_type = ?";
            $detail_types  .= 's';
            $detail_vals[]  = $cut_type;
        }

        $detail_sql = "
            SELECT
                rp.id,
                rp.cut_type,
                rp.completed_at,
                rp.new_length,
                IFNULL(rp.product,  mc.product)  AS product,
                IFNULL(rp.lot_no,   mc.lot_no)   AS lot_no,
                IFNULL(rp.coil_no,  mc.coil_no)  AS coil_no,
                IFNULL(rp.roll_no,  '-')         AS roll_no
            FROM recoiling_product rp
            LEFT JOIN mother_coil mc ON rp.mother_id = mc.id
            $detail_where
            ORDER BY rp.completed_at DESC
            LIMIT 500
        ";

        $stmt2 = $this->mysqli->prepare($detail_sql);
        if ($detail_types) {
            $stmt2->bind_param($detail_types, ...$detail_vals);
        }
        $stmt2->execute();
        $detail_res = $stmt2->get_result();
        $stmt2->close();

        $rows = [];
        while ($r = $detail_res->fetch_assoc()) {
            $rows[] = [
                'id'           => (int)$r['id'],
                'cut_type'     => $r['cut_type'] ?? '-',
                'completed_at' => $r['completed_at'] ? date('d/m/Y H:i', strtotime($r['completed_at'])) : '-',
                'new_length'   => $r['new_length'] !== null ? number_format((float)$r['new_length'], 1) : '-',
                'product'      => $r['product'] ?? '-',
                'lot_no'       => $r['lot_no'] ?? '-',
                'coil_no'      => $r['coil_no'] ?? '-',
                'roll_no'      => $r['roll_no'] ?? '-',
            ];
        }

        return ['counts' => $counts, 'rows' => $rows];
    }

    /**
     * Legacy add from finish_product
     */
    public function addFromSlitting(int $productId, ?float $actualLength): bool
    {
        $stmt = $this->mysqli->prepare("SELECT * FROM slitting_product WHERE id = ?");
        $stmt->bind_param("i", $productId);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$product) {
            return false;
        }

        $stmt = $this->mysqli->prepare("
            INSERT INTO recoiling_product
                (product, lot_no, coil_no, roll_no, width, length, actual_length, status, date_in)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");

        $stmt->bind_param(
            "ssssddd",
            $product['product'],
            $product['lot_no'],
            $product['coil_no'],
            $product['roll_no'],
            $product['width'],
            $product['length'],
            $actualLength
        );

        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

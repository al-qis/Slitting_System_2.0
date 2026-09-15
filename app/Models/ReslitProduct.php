<?php

namespace App\Models;

use App\Core\Model;
use Exception;
use RuntimeException;
use Throwable;

class ReslitProduct extends Model
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
     * Helper to write source tracking log
     */
    public function logSourceTracking(int $product_id, string $table_name, string $original_source, string $current_source, string $action): void
    {
        $stmt = $this->mysqli->prepare("
            INSERT INTO source_tracking_log
                (product_id, table_name, original_source, current_source, action)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issss", $product_id, $table_name, $original_source, $current_source, $action);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get main reslit list with effective length
     */
    public function getMainList(): array
    {
        $sql = "
            SELECT 
                r.*,
                COALESCE(NULLIF(s.actual_length, 0), r.length) AS effective_length
            FROM reslit_product r
            LEFT JOIN (
                SELECT sp1.*
                FROM slitting_product sp1
                INNER JOIN (
                    SELECT lot_no, coil_no, roll_no, MAX(id) AS max_id
                    FROM slitting_product
                    GROUP BY lot_no, coil_no, roll_no
                ) sp2
                  ON sp1.id = sp2.max_id
            ) s
              ON s.lot_no = r.lot_no
             AND s.coil_no = r.coil_no
             AND s.roll_no = r.roll_no
            ORDER BY r.id ASC
        ";
        return $this->queryAll($sql);
    }

    /**
     * Get all child rolls from reslit_rolls grouped by parent_id
     */
    public function getChildRolls(): array
    {
        $sql = "SELECT * FROM reslit_rolls ORDER BY parent_id ASC, id ASC";
        $rows = $this->queryAll($sql);
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int)$row['parent_id']][] = $row;
        }
        return $grouped;
    }

    /**
     * Get KPI counts (pending vs completed)
     */
    public function getKpiCounts(): array
    {
        $resPending   = $this->queryOne("SELECT COUNT(*) AS count FROM reslit_product WHERE status='pending'");
        $resCompleted = $this->queryOne("SELECT COUNT(*) AS count FROM reslit_product WHERE status='completed'");

        return [
            'pending'   => (int)($resPending['count'] ?? 0),
            'completed' => (int)($resCompleted['count'] ?? 0),
        ];
    }

    /**
     * Get Excel export data
     */
    public function getExportData(): array
    {
        $sql = "
            SELECT
                r.id, r.status, r.cut_type, r.product, r.lot_no, r.roll_no,
                r.width, r.length, r.actual_length, r.date_in, r.completed_at,
                COALESCE(NULLIF(s.actual_length, 0), r.length) AS effective_length
            FROM reslit_product r
            LEFT JOIN (
                SELECT sp1.*
                FROM slitting_product sp1
                INNER JOIN (
                    SELECT lot_no, roll_no, MAX(id) AS max_id
                    FROM slitting_product
                    GROUP BY lot_no, roll_no
                ) sp2 ON sp1.id = sp2.max_id
            ) s ON s.lot_no = r.lot_no AND s.roll_no = r.roll_no
            ORDER BY r.id ASC
        ";
        return $this->queryAll($sql);
    }

    /**
     * Find single record by ID
     */
    public function findById(int $id): ?array
    {
        return $this->queryOne("SELECT * FROM reslit_product WHERE id = :id", ['id' => $id]);
    }

    /**
     * Update reslit product details (from edit_reslit)
     */
    public function updateRecord(int $id, array $data): bool
    {
        $sql = "UPDATE reslit_product SET product=:product, lot_no=:lot_no, coil_no=:coil_no, roll_no=:roll_no, width=:width, length=:length WHERE id=:id";
        return $this->execute($sql, [
            'product' => $data['product'],
            'lot_no'  => $data['lot_no'],
            'coil_no' => $data['coil_no'],
            'roll_no' => $data['roll_no'],
            'width'   => $data['width'],
            'length'  => $data['length'],
            'id'      => $id,
        ]);
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
                throw new Exception("This roll has been voided and can't be sent to reslit.");
            }
            if ((int)$product['is_reslitted'] === 1) {
                throw new Exception("This roll has already been sent to reslit.");
            }
            if ((int)$product['is_recoiled'] === 1) {
                throw new Exception("This roll has already been sent to recoiling — it can't also go to reslit.");
            }
            if ((int)$product['stock_counted'] !== 1) {
                throw new Exception("This roll hasn't been stock-counted yet (Actual Length not recorded) — finish that on Finished Product first.");
            }
            if (strtoupper(trim($product['status'] ?? '')) !== 'IN') {
                throw new Exception("This roll's status is \"{$product['status']}\" — only rolls currently IN stock can be sent to reslit.");
            }

            $upd = $this->mysqli->prepare("UPDATE slitting_product SET is_reslitted = 1 WHERE id = ?");
            $upd->bind_param("i", $id);
            $upd->execute();
            $upd->close();

            $ins = $this->mysqli->prepare("
                INSERT INTO reslit_product
                    (slitting_product_id, product, lot_no, coil_no, roll_no,
                     width, length, date_in, original_source)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ");
            $originalSource = $product['original_source'] ?? $product['source'] ?? 'raw_material';
            $ins->bind_param(
                "issssdds",
                $id,
                $product['product'],
                $product['lot_no'],
                $product['coil_no'],
                $product['roll_no'],
                $product['width'],
                $product['length'],
                $originalSource
            );
            if (!$ins->execute()) {
                throw new Exception("Failed to add to reslit queue: " . $ins->error);
            }
            $reslitId = $this->mysqli->insert_id;
            $ins->close();

            $motherId = $product['mother_id'] !== null ? (int)$product['mother_id'] : null;

            $this->logProcess('slitting', $id, $motherId, 'IN', 'IN', 'send_to_reslit',
                "Added via Reslit page intake ({$source}); reslit_product id={$reslitId}");
            $this->logProcess('reslit', $reslitId, $motherId, null, 'pending', 'created',
                "Created via Reslit page intake ({$source}) from slitting_product id={$id}");

            $this->mysqli->commit();

            return [
                'ok'  => true,
                'msg' => "Added {$product['lot_no']} {$product['coil_no']} {$product['roll_no']} to the reslit queue.",
                'roll' => [
                    'id'      => $reslitId,
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
     * Send back from Reslit (undo to Finished Product or SFC)
     */
    public function sendBack(int $id): string
    {
        $this->mysqli->begin_transaction();
        try {
            $stmt = $this->mysqli->prepare("SELECT * FROM reslit_product WHERE id=? FOR UPDATE");
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

            $mid             = isset($rp['mother_id']) ? (int)$rp['mother_id'] : null;
            $original_source = $rp['original_source'] ?? 'raw_material';
            $slittingId      = isset($rp['slitting_product_id']) ? (int)$rp['slitting_product_id'] : 0;

            if ($slittingId > 0) {
                // Route 1: restore to Finished Product
                $stmt = $this->mysqli->prepare("UPDATE slitting_product SET is_reslitted=0 WHERE id=?");
                $stmt->bind_param("i", $slittingId);
                $stmt->execute();
                $stmt->close();

                $stmt = $this->mysqli->prepare("DELETE FROM reslit_product WHERE id=?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();

                $this->logProcess('reslit', $id, $mid, 'pending', 'returned', 'sent_back_to_finished_product',
                    "Sent back to Finished Product; slitting_product id={$slittingId}");
                $this->logProcess('slitting', $slittingId, $mid, 'OUT', 'IN', 'returned_from_reslit',
                    "Returned from reslit_product id={$id}");

                $this->logSourceTracking($slittingId, 'reslit_product', $original_source, 'finished_product', 'sent_back_to_finished_product');

                $this->mysqli->commit();
                return 'returned_fp';
            } else {
                // Route 2: restore to SFC Inventory
                $sfcRow = null;
                $sourceSfcId = isset($rp['source_sfc_id']) ? (int)$rp['source_sfc_id'] : 0;

                if ($sourceSfcId > 0) {
                    $stmt = $this->mysqli->prepare("SELECT * FROM sfc WHERE sfc_id=? AND is_deleted=0 FOR UPDATE");
                    $stmt->bind_param("i", $sourceSfcId);
                    $stmt->execute();
                    $sfcRow = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                }

                if (!$sfcRow) {
                    $stmt = $this->mysqli->prepare("
                        SELECT * FROM sfc
                        WHERE lot_no=? AND coil_no=? AND roll_no=?
                          AND is_deleted=0 AND date_out IS NOT NULL AND action='RESLIT'
                        ORDER BY date_out DESC
                        LIMIT 1
                        FOR UPDATE
                    ");
                    $stmt->bind_param("sss", $rp['lot_no'], $rp['coil_no'], $rp['roll_no']);
                    $stmt->execute();
                    $sfcRow = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                }

                if (!$sfcRow) {
                    throw new RuntimeException("no_source_sfc");
                }

                $sfcId = (int)$sfcRow['sfc_id'];

                $stmt = $this->mysqli->prepare("UPDATE sfc SET date_out=NULL, action=NULL WHERE sfc_id=?");
                $stmt->bind_param("i", $sfcId);
                $stmt->execute();
                $stmt->close();

                $stmt = $this->mysqli->prepare("DELETE FROM reslit_product WHERE id=?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();

                $this->logProcess('reslit', $id, $mid, 'pending', 'returned', 'sent_back_to_sfc',
                    "Sent back to SFC Inventory; sfc id={$sfcId}");
                $this->logProcess('sfc', $sfcId, $mid, 'OUT', 'IN', 'returned_from_reslit',
                    "Returned from reslit_product id={$id}");

                $this->logSourceTracking($sfcId, 'reslit_product', $original_source, 'sfc', 'sent_back_to_sfc');

                $this->mysqli->commit();
                return 'returned_sfc';
            }
        } catch (Throwable $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }

    /**
     * Complete reslit process with child rolls and leftover routing
     */
    public function completeProcess(int $parentId, array $postData): void
    {
        $cut_type  = $postData['cut_type'] ?? 'normal';
        $roll_numbers   = $postData['roll_number']   ?? [];
        $cut_letters    = $postData['cut_letter']    ?? [];
        $new_widths     = $postData['new_width']     ?? [];
        $lengths        = $postData['length']        ?? [];
        $actual_lengths = $postData['actual_length'] ?? [];
        $send_to_sfc    = $postData['send_to_sfc']   ?? [];

        $slit_quantity    = floatval($postData['slit_quantity'] ?? 0);
        $leftover_length  = floatval($postData['stock']         ?? 0);
        $leftover_to_sfc  = ($postData['leftover_to_sfc'] ?? '') === '1';

        $stmt = $this->mysqli->prepare("SELECT * FROM reslit_product WHERE id = ?");
        $stmt->bind_param("i", $parentId);
        $stmt->execute();
        $parent = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$parent) {
            throw new Exception("Parent reslit record not found.");
        }

        $originalSource = $parent['original_source'] ?? 'raw_material';
        $parent_slit_id = $parent['slitting_product_id'] ?? null;

        $coil_no_val   = trim($parent['coil_no'] ?? '');
        $mother_id_val = !empty($parent['mother_id']) ? intval($parent['mother_id']) : null;

        if ($parent_slit_id && ($coil_no_val === '' || $mother_id_val === null)) {
            $sp_row = $this->mysqli->query(
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

        if ($coil_no_val === '' && $mother_id_val) {
            $mc_row = $this->mysqli->query(
                "SELECT coil_no FROM mother_coil WHERE id=" . intval($mother_id_val)
            )->fetch_assoc();
            $coil_no_val = $mc_row['coil_no'] ?? '';
        }

        $this->mysqli->begin_transaction();
        try {
            if ($parent_slit_id) {
                $stmt_void_parent = $this->mysqli->prepare("
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

            $exclude_id = $parent_slit_id ?? 0;

            foreach ($roll_numbers as $index => $roll_label) {
                $letter      = trim($cut_letters[$index] ?? '');
                $temp_lot_no = $parent['lot_no'] . $letter;

                $check = $this->mysqli->prepare("
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

            $leftover_roll_no = $parent['roll_no'] ?? '';
            if ($cut_type === 'cut_into_2' && $leftover_length > 0 && !$leftover_to_sfc) {
                $check = $this->mysqli->prepare("
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

            foreach ($roll_numbers as $index => $roll_label) {
                $letter  = trim($cut_letters[$index]   ?? '');
                $width   = floatval($new_widths[$index]    ?? 0);
                $nom_len = floatval($lengths[$index]        ?? 0);
                $act_raw = trim($actual_lengths[$index]    ?? '');
                $act_len = ($act_raw !== '' && is_numeric($act_raw)) ? floatval($act_raw) : $nom_len;

                $new_lot_no    = $parent['lot_no'] . $letter;
                $total_actual += $act_len;

                if (in_array($roll_label, $send_to_sfc, true)) {
                    $sfc_stmt = $this->mysqli->prepare("
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
                    $sfc_id = $this->mysqli->insert_id;
                    $sfc_stmt->close();

                    $this->logProcess('sfc', $sfc_id, $mother_id_val, null, 'IN', 'sent_to_sfc_from_reslit',
                        "Roll {$roll_label} from reslit_product id={$parentId}, width={$width}mm length={$act_len}m product={$parent['product']}");

                    $audit_stmt = $this->mysqli->prepare("
                        INSERT INTO slitting_audit_log
                            (mother_id, action, roll_no, destination, created_at)
                        VALUES (?, 'send_to_sfc', ?, 'sfc_stock', NOW())
                    ");
                    $audit_stmt->bind_param("is", $mother_id_val, $roll_label);
                    $audit_stmt->execute();
                    $audit_stmt->close();

                    continue;
                }

                $stmt_ins = $this->mysqli->prepare("
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
                $new_slit_id = $this->mysqli->insert_id;
                $stmt_ins->close();

                $this->logProcess('slitting', $new_slit_id, $mother_id_val, null, 'IN', 'reslit_output',
                    "Roll {$roll_label} from reslit_product id={$parentId}, parent_slit_id={$parent_slit_id}");

                $stmt_roll = $this->mysqli->prepare("
                    INSERT INTO reslit_rolls
                        (parent_id, roll_no, cut_letter, new_width,
                         length, actual_length, original_source)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_roll->bind_param(
                    "issddds",
                    $parentId,
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

            if ($cut_type === 'cut_into_2' && $leftover_length > 0) {
                $leftover_width = floatval($parent['width'] ?? 0);

                if ($leftover_to_sfc) {
                    $sfc_stmt = $this->mysqli->prepare("
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
                    $sfc_leftover_id = $this->mysqli->insert_id;
                    $sfc_stmt->close();

                    $this->logProcess('sfc', $sfc_leftover_id, $mother_id_val, null, 'IN', 'reslit_leftover_to_sfc',
                        "Leftover {$leftover_length}m (roll {$leftover_roll_no}) from reslit_product id={$parentId} routed to SFC (slit_quantity={$slit_quantity}m used)");
                } else {
                    $stmt_leftover = $this->mysqli->prepare("
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
                    $leftover_slit_id = $this->mysqli->insert_id;
                    $stmt_leftover->close();

                    $this->logProcess('slitting', $leftover_slit_id, $mother_id_val, null, 'IN', 'reslit_leftover_output',
                        "Leftover {$leftover_length}m (roll {$leftover_roll_no}) from reslit_product id={$parentId} routed to Finished Product (slit_quantity={$slit_quantity}m used)");
                }
            }

            $stmt_upd = $this->mysqli->prepare("
                UPDATE reslit_product
                SET status='completed', completed_at=NOW()
                WHERE id=?
            ");
            $stmt_upd->bind_param("i", $parentId);
            $stmt_upd->execute();
            $stmt_upd->close();

            $this->logProcess('reslit', $parentId, $mother_id_val, 'pending', 'completed', 'reslit_complete',
                "Output rolls: " . count($roll_numbers) . ", total={$total_actual}m");

            $this->mysqli->commit();
        } catch (Throwable $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }

    /**
     * Get summary report data (counts and rows)
     */
    public function getSummaryReport(string $month): array
    {
        $where_sql  = "WHERE rp.status = 'completed'";
        $bind_types = '';
        $bind_vals  = [];

        if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $where_sql  .= " AND DATE_FORMAT(rp.completed_at, '%Y-%m') = ?";
            $bind_types  = 's';
            $bind_vals[] = $month;
        }

        $hasCutType = false;
        $colCheck = $this->mysqli->query("SHOW COLUMNS FROM reslit_product LIKE 'cut_type'");
        if ($colCheck && $colCheck->num_rows > 0) {
            $hasCutType = true;
        }

        $counts = ['normal' => 0, 'cut_into_2' => 0];

        if ($hasCutType) {
            $count_sql = "SELECT cut_type, COUNT(*) AS cnt FROM reslit_product rp $where_sql GROUP BY cut_type";
            $stmt = $this->mysqli->prepare($count_sql);
            if ($bind_types) $stmt->bind_param($bind_types, ...$bind_vals);
            $stmt->execute();
            $count_res = $stmt->get_result();
            $stmt->close();
            while ($row = $count_res->fetch_assoc()) {
                $ct = $row['cut_type'] ?? '';
                if (isset($counts[$ct])) $counts[$ct] = (int)$row['cnt'];
            }
        } else {
            $count_sql = "SELECT COUNT(*) AS cnt FROM reslit_product rp $where_sql";
            $stmt = $this->mysqli->prepare($count_sql);
            if ($bind_types) $stmt->bind_param($bind_types, ...$bind_vals);
            $stmt->execute();
            $count_res = $stmt->get_result();
            $stmt->close();
            $counts['normal'] = (int)($count_res->fetch_assoc()['cnt'] ?? 0);
        }

        $cut_type_select = $hasCutType ? 'rp.cut_type,' : "'normal' AS cut_type,";

        $detail_sql = "
            SELECT
                rp.id,
                $cut_type_select
                rp.date_in,
                rp.actual_length,
                rp.product,
                rp.lot_no,
                rp.coil_no,
                rp.roll_no,
                rr.id          AS roll_id,
                rr.roll_no     AS child_roll_no,
                rr.cut_letter,
                rr.new_width,
                rr.actual_length AS child_actual_length
            FROM reslit_product rp
            LEFT JOIN reslit_rolls rr ON rr.parent_id = rp.id
            $where_sql
            ORDER BY rp.id DESC, rr.id ASC
            LIMIT 1000
        ";

        $stmt2 = $this->mysqli->prepare($detail_sql);
        if ($bind_types) $stmt2->bind_param($bind_types, ...$bind_vals);
        $stmt2->execute();
        $detail_res = $stmt2->get_result();
        $stmt2->close();

        $parents = [];
        while ($r = $detail_res->fetch_assoc()) {
            $pid = $r['id'];
            if (!isset($parents[$pid])) {
                $parents[$pid] = [
                    'id'            => $pid,
                    'cut_type'      => $r['cut_type'] ?? 'normal',
                    'completed_at'  => $r['date_in'] ? date('d M Y', strtotime($r['date_in'])) : null,
                    'product'       => $r['product'],
                    'lot_no'        => $r['lot_no'],
                    'coil_no'       => $r['coil_no'],
                    'roll_no'       => $r['roll_no'],
                    'actual_length' => $r['actual_length'],
                    'rolls'         => [],
                ];
            }
            if (!empty($r['roll_id'])) {
                $parents[$pid]['rolls'][] = [
                    'roll_no'       => $r['child_roll_no'],
                    'cut_letter'    => $r['cut_letter'],
                    'new_width'     => $r['new_width'],
                    'actual_length' => $r['child_actual_length'],
                ];
            }
        }

        return ['counts' => $counts, 'rows' => array_values($parents)];
    }

    /**
     * Legacy add from finish_product
     */
    public function addFromFinishProduct(int $productId): bool
    {
        $stmt = $this->mysqli->prepare("SELECT * FROM finish_product WHERE id = ?");
        $stmt->bind_param("i", $productId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            return false;
        }

        $product = $result->fetch_assoc();
        $stmt->close();

        $stmt = $this->mysqli->prepare("
            INSERT INTO reslit_product
                (status, product, lot_no, coil_no, roll_no, width, length, date_in, qr_code)
            VALUES ('pending', ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "ssssddss",
            $product['product'],
            $product['lot_no'],
            $product['coil_no'],
            $product['roll_no'],
            $product['width'],
            $product['length'],
            $product['date_in'],
            $product['qr_code']
        );

        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

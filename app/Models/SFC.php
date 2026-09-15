<?php

namespace App\Models;

use App\Core\Model;
use mysqli;
use Exception;

class SFC extends Model
{
    public function __construct()
    {
        parent::__construct();
    }

    public function logSourceTracking(int $productId, string $tableName, string $originalSource, string $currentSource, string $action): void
    {
        $stmt = $this->mysqli->prepare("
            INSERT INTO source_tracking_log
                (product_id, table_name, original_source, current_source, action)
            VALUES (?, ?, ?, ?, ?)
        ");
        if (!$stmt) return;
        $stmt->bind_param("issss", $productId, $tableName, $originalSource, $currentSource, $action);
        $stmt->execute();
        $stmt->close();
    }

    public function addInitialStock(array $products, array $lots, array $coils, array $rolls, array $widths, array $lengths): int
    {
        $this->mysqli->begin_transaction();
        try {
            $insertStmt = $this->mysqli->prepare("
                INSERT INTO sfc (mother_id, product, lot_no, coil_no, roll_no, width, length, action, date_created)
                VALUES (NULL, ?, ?, ?, ?, ?, ?, NULL, NOW())
            ");
            if (!$insertStmt) {
                throw new Exception("Prepare failed: " . $this->mysqli->error);
            }

            $insertedCount = 0;

            foreach ($products as $i => $productRaw) {
                $product = trim($productRaw);
                $lot     = trim($lots[$i]    ?? '');
                $coil    = trim($coils[$i]   ?? '');
                $roll    = trim($rolls[$i]   ?? '');
                if ($roll === '') {
                    $roll = 'BALANCE';
                }
                $width   = trim($widths[$i]  ?? '');
                $length  = trim($lengths[$i] ?? '');

                if ($product === '' && $lot === '' && $coil === '' && $width === '' && $length === '') {
                    continue;
                }

                if ($product === '' || $lot === '' || $coil === '' || $width === '' || $length === '') {
                    throw new Exception("Row " . ($i + 1) . ": Product, Lot No, Coil No, Width, and Length are all required.");
                }
                if (!is_numeric($width) || !is_numeric($length)) {
                    throw new Exception("Row " . ($i + 1) . ": Width and Length must be numbers.");
                }

                $widthVal  = (float)$width;
                $lengthVal = (float)$length;

                $insertStmt->bind_param("ssssdd", $product, $lot, $coil, $roll, $widthVal, $lengthVal);
                if (!$insertStmt->execute()) {
                    throw new Exception("Row " . ($i + 1) . ": " . $insertStmt->error);
                }

                $newSfcId = $this->mysqli->insert_id;
                $this->logSourceTracking($newSfcId, 'sfc', 'manual_initial_stock', 'sfc', 'INITIAL_STOCK_ADDED');
                $insertedCount++;
            }
            $insertStmt->close();

            if ($insertedCount === 0) {
                throw new Exception("No rows to add — fill in at least one row before submitting.");
            }

            $this->mysqli->commit();
            return $insertedCount;

        } catch (Exception $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }

    public function processAction(int $sfcId, string $action, string $userRole): string
    {
        $stmt = $this->mysqli->prepare("SELECT * FROM sfc WHERE sfc_id = ? AND date_out IS NULL AND is_deleted = 0");
        $stmt->bind_param("i", $sfcId);
        $stmt->execute();
        $sfc = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$sfc) {
            throw new Exception("SFC not found, already used, or already deleted.");
        }

        $this->mysqli->begin_transaction();
        try {
            $original_source = 'sfc';

            if ($action === 'DELETE') {
                $deletedBy = $userRole ?: 'system';
                $delStmt = $this->mysqli->prepare("
                    UPDATE sfc SET is_deleted = 1, deleted_at = NOW(), deleted_by = ?
                    WHERE sfc_id = ?
                ");
                $delStmt->bind_param("si", $deletedBy, $sfcId);
                $delStmt->execute();
                $delStmt->close();

                $this->logSourceTracking($sfcId, 'sfc', $original_source, 'sfc', 'DELETED_FROM_SFC');
                $this->mysqli->commit();
                return 'deleted';
            }

            if ($action === 'RECOIL') {
                $stmt = $this->mysqli->prepare("INSERT INTO recoiling_product
                    (mother_id, product, lot_no, coil_no, roll_no, width, length, status, date_in, original_source)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), ?)");
                $stmt->bind_param("issssdds",
                    $sfc['mother_id'],
                    $sfc['product'], $sfc['lot_no'], $sfc['coil_no'],
                    $sfc['roll_no'], $sfc['width'],  $sfc['length'], $original_source);
                $stmt->execute();
                $stmt->close();
                $this->logSourceTracking(0, 'recoiling_product', $original_source, 'sfc', 'RECOIL_FROM_SFC');

            } elseif ($action === 'RESLIT') {
                $stmt = $this->mysqli->prepare("INSERT INTO reslit_product
                    (mother_id, product, lot_no, coil_no, roll_no, width, length, status, date_in, original_source, source_sfc_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), ?, ?)");
                $stmt->bind_param("issssddsi",
                    $sfc['mother_id'],
                    $sfc['product'], $sfc['lot_no'], $sfc['coil_no'],
                    $sfc['roll_no'], $sfc['width'],  $sfc['length'], $original_source, $sfcId);
                $stmt->execute();
                $stmt->close();
                $this->logSourceTracking(0, 'reslit_product', $original_source, 'sfc', 'RESLIT_FROM_SFC');

            } elseif ($action === 'SELL') {
                $isBalance = (strtoupper(trim($sfc['roll_no'] ?? '')) === 'BALANCE' || strtoupper(trim($sfc['status'] ?? '')) === 'BALANCE');
                if ($isBalance) {
                    $this->mysqli->rollback();
                    throw new Exception("Balance coils cannot be sold directly. They must be sent to Recoil or Reslit.");
                }
                $actLen = (float)($sfc['length'] ?? 0);
                $stmt = $this->mysqli->prepare("INSERT INTO slitting_product
                    (mother_id, product, lot_no, coil_no, roll_no, width, length, actual_length,
                     status, is_completed, stock_counted, date_in, date_out, cut_type, source, original_source)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'IN', 1, 1, NOW(), NULL, 'sfc_sell', 'sfc', ?)");
                $stmt->bind_param("issssddds",
                    $sfc['mother_id'],
                    $sfc['product'], $sfc['lot_no'], $sfc['coil_no'],
                    $sfc['roll_no'], $sfc['width'],  $sfc['length'], $actLen, $original_source);
                $stmt->execute();
                $stmt->close();
                $this->logSourceTracking(0, 'slitting_product', $original_source, 'sfc', 'SELL_FROM_SFC');
            }

            $updateStmt = $this->mysqli->prepare("UPDATE sfc SET date_out = NOW(), action = ? WHERE sfc_id = ?");
            $updateStmt->bind_param("si", $action, $sfcId);
            $updateStmt->execute();
            $updateStmt->close();

            $this->mysqli->commit();
            return $action === 'SELL' ? 'sfc_sold' : 'success';

        } catch (Exception $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }

    public function getSfcOutputs(array $sfc): array
    {
        $lot  = $this->mysqli->real_escape_string($sfc['lot_no']  ?? '');
        $coil = $this->mysqli->real_escape_string($sfc['coil_no'] ?? '');
        $roll = $this->mysqli->real_escape_string($sfc['roll_no'] ?? '');
        $out  = [];

        // 1. Reslit outputs
        $rp = $this->mysqli->query("
            SELECT rp.id, rp.status, rp.lot_no, rp.coil_no, rp.roll_no
            FROM reslit_product rp
            WHERE rp.lot_no='$lot' AND rp.coil_no='$coil' AND rp.roll_no='$roll'
              AND rp.original_source='sfc'
            ORDER BY rp.id DESC LIMIT 1
        ")->fetch_assoc();

        if ($rp) {
            $rpId    = intval($rp['id']);
            $rr_res  = $this->mysqli->query("
                SELECT rr.*, rp.lot_no AS parent_lot, rp.coil_no AS parent_coil
                FROM reslit_rolls rr
                JOIN reslit_product rp ON rp.id = rr.parent_id
                WHERE rr.parent_id = $rpId
                ORDER BY rr.id ASC
            ");
            $rolls = [];
            if ($rr_res) while ($r = $rr_res->fetch_assoc()) $rolls[] = $r;

            $out[] = [
                'process'    => 'RESLIT',
                'parent_id'  => $rpId,
                'status'     => $rp['status'],
                'rolls'      => $rolls,
            ];
        }

        // 2. Recoiling outputs
        $rcp = $this->mysqli->query("
            SELECT rcp.id, rcp.status, rcp.lot_no, rcp.coil_no, rcp.roll_no
            FROM recoiling_product rcp
            WHERE rcp.lot_no='$lot' AND rcp.coil_no='$coil' AND rcp.roll_no='$roll'
              AND rcp.original_source='sfc'
            ORDER BY rcp.id DESC LIMIT 1
        ")->fetch_assoc();

        if ($rcp) {
            $rcpId    = intval($rcp['id']);
            $sp_res   = $this->mysqli->query("
                SELECT id, lot_no, coil_no, roll_no, width, actual_length, length, status
                FROM slitting_product
                WHERE recoiling_id = $rcpId
                ORDER BY id ASC
            ");
            $rolls = [];
            if ($sp_res) while ($r = $sp_res->fetch_assoc()) $rolls[] = $r;

            $out[] = [
                'process'   => 'RECOIL',
                'parent_id' => $rcpId,
                'status'    => $rcp['status'],
                'rolls'     => $rolls,
            ];
        }

        // 3. Direct SELL to slitting_product
        $sp_res = $this->mysqli->query("
            SELECT id, lot_no, coil_no, roll_no, width, actual_length, length, status
            FROM slitting_product
            WHERE lot_no='$lot' AND coil_no='$coil' AND roll_no='$roll'
              AND source='sfc' AND original_source='sfc'
            ORDER BY id DESC LIMIT 1
        ")->fetch_assoc();

        if ($sp_res) {
            $out[] = [
                'process'   => 'SELL',
                'parent_id' => $sp_res['id'],
                'status'    => $sp_res['status'],
                'rolls'     => [$sp_res],
            ];
        }

        return $out;
    }

    public function runSfcTrackingQuery(string $table, int $month, int $year, string $search)
    {
        $allowedTables = ['slitting_product', 'recoiling_product', 'reslit_product'];
        if (!in_array($table, $allowedTables, true)) {
            return false;
        }

        $sql = "SELECT id, product, lot_no, coil_no, roll_no, width, length,
                       status, date_in, original_source
                FROM {$table}
                WHERE original_source = 'sfc'
                AND (
                    (MONTH(date_in) = ? AND YEAR(date_in) = ?)
                    OR date_in IS NULL
                )";

        if ($search !== '') {
            $sql .= " AND (
                        product LIKE ? OR
                        lot_no  LIKE ? OR
                        coil_no LIKE ? OR
                        id      LIKE ?
                      )";
        }

        $sql .= " ORDER BY id DESC";

        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) return false;

        if ($search !== '') {
            $likeSearch = '%' . $search . '%';
            $stmt->bind_param("iissss", $month, $year, $likeSearch, $likeSearch, $likeSearch, $likeSearch);
        } else {
            $stmt->bind_param("ii", $month, $year);
        }

        $stmt->execute();
        return $stmt->get_result();
    }
}

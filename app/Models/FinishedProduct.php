<?php

namespace App\Models;

use App\Core\Model;
use mysqli;
use Throwable;

class FinishedProduct extends Model
{
    public function __construct()
    {
        parent::__construct();
    }

    public static function originLabel(string $origin): string
    {
        return match ($origin) {
            'sfc'           => 'SFC',
            'raw_material'  => 'RM',
            'initial_stock' => 'IS',
            default         => strtoupper($origin),
        };
    }

    public static function cardUrl(string $filterVal, int $month, int $year, string $search): string
    {
        $params = ['month' => $month, 'year' => $year, 'filter' => $filterVal];
        if ($search !== '') $params['search'] = $search;
        return '?' . http_build_query($params);
    }

    public function logProcess(
        string  $entityType,
        int     $entityId,
        ?int    $motherId,
        ?string $fromStatus,
        string  $toStatus,
        string  $actionDetail = '',
        string  $remark = '',
        string  $performedBy = 'system'
    ): void {
        $stmt = $this->mysqli->prepare("
            INSERT INTO process_log
                (entity_type, entity_id, mother_id, from_status, to_status,
                 performed_by, action_detail, remark)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) return;
        $stmt->bind_param(
            "siisssss",
            $entityType, $entityId, $motherId,
            $fromStatus, $toStatus,
            $performedBy, $actionDetail, $remark
        );
        $stmt->execute();
        $stmt->close();
    }

    public function getOriginOptions(): array
    {
        $originOptions = [];
        $originOptRes = $this->mysqli->query("
            SELECT DISTINCT LOWER(TRIM(COALESCE(original_source, source, 'raw_material'))) AS origin
            FROM slitting_product
            WHERE is_voided = 0
            ORDER BY origin
        ");
        if ($originOptRes) {
            while ($optRow = $originOptRes->fetch_assoc()) {
                if (($optRow['origin'] ?? '') !== '') {
                    $originOptions[] = $optRow['origin'];
                }
            }
        }
        return $originOptions;
    }

    public function getSingleProduct(int $id): ?array
    {
        $stmt = $this->mysqli->prepare("SELECT * FROM slitting_product WHERE id = ?");
        if (!$stmt) return null;
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $res ?: null;
    }

    public function updateOk(int $id, string $actualLength, string $performedBy): bool
    {
        $stmt = $this->mysqli->prepare("
            UPDATE slitting_product
            SET actual_length=?, date_in=NOW(), stock_counted=1, is_completed=1
            WHERE id=?
        ");
        if (!$stmt) return false;
        $stmt->bind_param("si", $actualLength, $id);
        $stmt->execute();
        $stmt->close();

        $product = $this->getSingleProduct($id);
        if ($product && $product['cut_type'] === 'cut_into_2'
            && floatval($product['leftover_length'] ?? $product['stock'] ?? 0) > 0) {

            $leftover = floatval($product['leftover_length'] ?? $product['stock'] ?? 0);
            $motherId = (int)$product['mother_id'];
            $mother = $this->mysqli->query("SELECT * FROM mother_coil WHERE id={$motherId}")->fetch_assoc();
            if ($mother) {
                $stockLotNo = $product['lot_no'] . 'a';
                $check = $this->mysqli->query("
                    SELECT id, length FROM stock_raw_material
                    WHERE lot_no='{$stockLotNo}' AND coil_no='{$product['coil_no']}'
                ");
                if ($check && $check->num_rows > 0) {
                    $existing   = $check->fetch_assoc();
                    $newLength = $existing['length'] + $leftover;
                    $this->mysqli->query("UPDATE stock_raw_material SET length={$newLength}, updated_at=NOW() WHERE id={$existing['id']}");
                } else {
                    $stmtSM = $this->mysqli->prepare("
                        INSERT INTO stock_raw_material
                            (lot_no, coil_no, width, length, status, source_type, source_id, date_in)
                        VALUES (?, ?, ?, ?, 'IN', 'reslit', ?, NOW())
                    ");
                    $stmtSM->bind_param("ssddi", $stockLotNo, $product['coil_no'], $mother['width'], $leftover, $product['mother_id']);
                    $stmtSM->execute();
                    $stmtSM->close();
                }
            }
        }

        $this->logProcess('slitting', $id, intval($product['mother_id'] ?? 0) ?: null,
            'IN', 'IN', 'actual_length_saved', "actual_length={$actualLength}m, stock_counted=1", $performedBy);

        return true;
    }

    public function saveNod(int $id, ?float $nodLength, string $performedBy): bool
    {
        if ($nodLength === null) {
            $stmt = $this->mysqli->prepare("
                UPDATE slitting_product
                SET nod_length = NULL, nod_recorded_at = NULL, nod_recorded_by = NULL
                WHERE id = ?
            ");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            $this->logProcess('slitting', $id, null, '', '', 'nod_cleared', "NOD removed from roll id={$id}", $performedBy);
        } else {
            $stmt = $this->mysqli->prepare("
                UPDATE slitting_product
                SET nod_length = ?, nod_recorded_at = NOW(), nod_recorded_by = ?
                WHERE id = ?
            ");
            $stmt->bind_param("dsi", $nodLength, $performedBy, $id);
            $stmt->execute();
            $stmt->close();
            $this->logProcess('slitting', $id, null, '', '', 'nod_recorded', "NOD length={$nodLength}m recorded on roll id={$id}", $performedBy);
        }
        return true;
    }

    public function updateCustomerRef(int $id, string $customer, string $refNo, string $performedBy): bool
    {
        $stmt = $this->mysqli->prepare("UPDATE slitting_product SET customer_name = ?, ref_no = ? WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("ssi", $customer, $refNo, $id);
        $stmt->execute();
        $stmt->close();
        $this->logProcess('slitting', $id, null, '', '', 'customer_ref_updated', "customer={$customer}, ref_no={$refNo}", $performedBy);
        return true;
    }

    public function voidProduct(int $id, string $reason, string $performedBy): bool
    {
        $stmt = $this->mysqli->prepare("
            UPDATE slitting_product
            SET is_voided = 1, voided_at = NOW(), voided_by = ?, voided_reason = ?
            WHERE id = ?
        ");
        if (!$stmt) return false;
        $stmt->bind_param("ssi", $performedBy, $reason, $id);
        $stmt->execute();
        $stmt->close();
        $this->logProcess('slitting', $id, null, '', 'VOIDED', 'void_product', $reason, $performedBy);
        return true;
    }

    public function saveRemarks(int $id, string $remark, string $performedBy): bool
    {
        $stmt = $this->mysqli->prepare("UPDATE slitting_product SET remark = ? WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("si", $remark, $id);
        $stmt->execute();
        $stmt->close();
        $this->logProcess('slitting', $id, null, '', '', 'remark_updated', $remark, $performedBy);
        return true;
    }

    public function deleteRoll(int $id, string $performedBy): bool
    {
        $stmt = $this->mysqli->prepare("
            UPDATE slitting_product
            SET is_voided = 1, voided_at = NOW(), voided_by = ?, voided_reason = 'manual_delete'
            WHERE id = ?
        ");
        if (!$stmt) return false;
        $stmt->bind_param("si", $performedBy, $id);
        $stmt->execute();
        $stmt->close();

        // Remove from pallet_items if assigned
        $this->mysqli->query("DELETE FROM pallet_items WHERE slitting_product_id = {$id}");

        $this->logProcess('slitting', $id, null, '', 'DELETED', 'delete_roll', 'manual_delete', $performedBy);
        return true;
    }
}

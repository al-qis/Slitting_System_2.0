<?php

namespace App\Models;

use App\Core\Model;
use mysqli;

class Batch extends Model
{
    public function __construct()
    {
        parent::__construct();
    }

    public function logProcess(
        int $entityId,
        ?int $motherId,
        string $fromStatus,
        string $toStatus,
        string $performedBy,
        string $actionDetail,
        string $remark
    ): void {
        $entityType = 'slitting';
        $stmt = $this->mysqli->prepare("
            INSERT INTO process_log
                (entity_type, entity_id, mother_id, from_status, to_status,
                 performed_by, action_detail, remark)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) return;
        $stmt->bind_param("siisssss", $entityType, $entityId, $motherId, $fromStatus, $toStatus, $performedBy, $actionDetail, $remark);
        $stmt->execute();
        $stmt->close();
    }

    public function getRollsForBatchSetup(string $lotNo, string $coilNo, int $motherId = 0): array
    {
        if ($motherId > 0) {
            $stmt = $this->mysqli->prepare("
                SELECT sp.id, sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
                       sp.width, sp.length, sp.actual_length, sp.status,
                       sp.is_completed, sp.customer_name, sp.ref_no,
                       sp.is_printed, sp.print_count, sp.last_printed_at, sp.last_printed_by,
                       pi.pallet_id
                FROM slitting_product sp
                LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                WHERE sp.is_voided = 0
                  AND sp.lot_no  = ?
                  AND sp.coil_no = ?
                  AND sp.mother_id = ?
                  AND sp.status NOT IN ('WAITING', 'REJECTED')
                  AND NOT (sp.status = 'IN' AND (sp.is_completed = 0 OR pi.pallet_id IS NOT NULL))
                ORDER BY sp.roll_no ASC, sp.id ASC
            ");
            $stmt->bind_param("ssi", $lotNo, $coilNo, $motherId);
        } else {
            $stmt = $this->mysqli->prepare("
                SELECT sp.id, sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
                       sp.width, sp.length, sp.actual_length, sp.status,
                       sp.is_completed, sp.customer_name, sp.ref_no,
                       sp.is_printed, sp.print_count, sp.last_printed_at, sp.last_printed_by,
                       pi.pallet_id
                FROM slitting_product sp
                LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                WHERE sp.is_voided = 0
                  AND sp.lot_no  = ?
                  AND sp.coil_no = ?
                  AND sp.status NOT IN ('WAITING', 'REJECTED')
                  AND NOT (sp.status = 'IN' AND (sp.is_completed = 0 OR pi.pallet_id IS NOT NULL))
                ORDER BY sp.roll_no ASC, sp.id ASC
            ");
            $stmt->bind_param("ss", $lotNo, $coilNo);
        }
        $stmt->execute();
        $rolls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rolls;
    }

    public function getRollsForMixedBatchSetup(array $ids, string $from = ''): array
    {
        if (empty($ids)) {
            return ['rolls' => [], 'errors' => []];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->mysqli->prepare("
            SELECT sp.id, sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
                   sp.width, sp.length, sp.actual_length,
                   sp.status, sp.is_completed, sp.customer_name, sp.ref_no,
                   sp.is_printed, sp.print_count, sp.last_printed_at, sp.last_printed_by,
                   pi.pallet_id
            FROM slitting_product sp
            LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
            WHERE sp.id IN ($placeholders)
              AND (sp.is_voided = 0 OR sp.is_voided IS NULL)
        ");
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $allRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $rolls  = [];
        $errors = [];
        $foundIds = [];

        foreach ($allRows as $r) {
            $foundIds[] = (int)$r['id'];

            if ($from === 'slitting_product') {
                $isPrintable = true;
            } else {
                $isPrintable = !in_array($r['status'], ['WAITING', 'REJECTED'], true)
                    && !($r['status'] === 'IN' && ($r['is_completed'] == 0 || $r['pallet_id']));
            }

            if (!$isPrintable) {
                $reason = $r['status'] === 'IN'
                    ? ($r['pallet_id'] ? 'already palletised' : 'pending Actual Length')
                    : ('status ' . $r['status']);
                $errors[] = "Roll #{$r['id']} ({$r['product']} {$r['lot_no']} {$r['coil_no']}): skipped — {$reason}.";
                continue;
            }

            $rolls[] = $r;
        }

        $missingIds = array_diff($ids, $foundIds);
        foreach ($missingIds as $mid) {
            $errors[] = "Roll #{$mid}: not found (or voided).";
        }

        usort($rolls, function ($a, $b) {
            return [$a['product'], $a['lot_no'], $a['coil_no'], $a['roll_no']]
               <=> [$b['product'], $b['lot_no'], $b['coil_no'], $b['roll_no']];
        });

        return ['rolls' => $rolls, 'errors' => $errors];
    }

    public function saveBatchSelections(array $selections, string $performedBy): array
    {
        $stmt = $this->mysqli->prepare("UPDATE slitting_product SET customer_name = ?, ref_no = ?, actual_length = ?, date_in = NOW(), stock_counted = 1, is_completed = 1 WHERE id = ?");
        if (!$stmt) {
            return ['ok' => false, 'msg' => 'DB prepare failed: ' . $this->mysqli->error];
        }

        $saved = 0;
        $errors = [];

        foreach ($selections as $sel) {
            $id          = intval($sel['id'] ?? 0);
            $customer    = trim($sel['customer'] ?? '');
            $ref_no      = trim($sel['ref_no']   ?? '');
            $nciResolved = trim($sel['nci_resolved_customer'] ?? '');
            $length      = isset($sel['length']) ? (float)$sel['length'] : 0;

            if ($id <= 0)         { $errors[] = "Invalid roll ID."; continue; }
            if ($customer === '') { $errors[] = "Roll #{$id}: customer required."; continue; }
            if ($ref_no === '')   { $errors[] = "Roll #{$id}: Ref No required."; continue; }
            if ($length <= 0)     { $errors[] = "Roll #{$id}: length must be greater than 0."; continue; }

            $existing = null;
            $resEx = $this->mysqli->query("SELECT * FROM slitting_product WHERE id=$id");
            if ($resEx && $resEx->num_rows > 0) {
                $existing = $resEx->fetch_assoc();
            }

            $wasPending = $existing && ((int)$existing['is_completed'] === 0);

            $customerToSave = $customer;
            if (in_array($customer, ['NCI MFG', 'NCI 2'], true) && $nciResolved !== '') {
                $customerToSave = $nciResolved;
            }

            $stmt->bind_param("ssdi", $customerToSave, $ref_no, $length, $id);
            if ($stmt->execute()) {
                $saved++;

                if ($wasPending && $existing) {
                    if ($existing['cut_type'] === 'cut_into_2'
                        && floatval($existing['leftover_length'] ?? $existing['stock'] ?? 0) > 0) {

                        $leftover = floatval($existing['leftover_length'] ?? $existing['stock'] ?? 0);
                        $motherId = intval($existing['mother_id'] ?? 0);
                        if ($motherId > 0) {
                            $mRes = $this->mysqli->query("SELECT * FROM mother_coil WHERE id={$motherId}");
                            if ($mRes && $mRes->num_rows > 0) {
                                $mother = $mRes->fetch_assoc();
                                $stock_lot_no = $existing['lot_no'] . 'a';
                                $check = $this->mysqli->query("
                                    SELECT id, length FROM stock_raw_material
                                    WHERE lot_no='$stock_lot_no' AND coil_no='{$existing['coil_no']}'
                                ");
                                if ($check && $check->num_rows > 0) {
                                    $exStock = $check->fetch_assoc();
                                    $new_length = $exStock['length'] + $leftover;
                                    $this->mysqli->query("UPDATE stock_raw_material SET length=$new_length, updated_at=NOW() WHERE id={$exStock['id']}");
                                } else {
                                    $ins = $this->mysqli->prepare("
                                        INSERT INTO stock_raw_material
                                            (lot_no, coil_no, width, length, status, source_type, source_id, date_in)
                                        VALUES (?, ?, ?, ?, 'IN', 'reslit', ?, NOW())
                                    ");
                                    $ins->bind_param("ssddi",
                                        $stock_lot_no, $existing['coil_no'],
                                        $mother['width'], $leftover, $motherId);
                                    $ins->execute();
                                    $ins->close();
                                }
                            }
                        }
                    }

                    $motherId = intval($existing['mother_id'] ?? 0) ?: null;
                    $remark   = "actual_length={$length}m, customer={$customerToSave}, ref_no={$ref_no}, stock_counted=1";
                    $this->logProcess($id, $motherId, 'IN', 'IN', $performedBy, 'batch_setup_saved', $remark);
                }
            } else {
                $errors[] = "Roll #{$id}: save failed — {$this->mysqli->error}";
            }
        }
        $stmt->close();

        return [
            'ok'     => empty($errors),
            'saved'  => $saved,
            'errors' => $errors,
            'msg'    => empty($errors)
                ? "Saved {$saved} roll(s)."
                : "Saved {$saved} roll(s), " . count($errors) . ' failed: ' . implode(' | ', $errors),
        ];
    }
}

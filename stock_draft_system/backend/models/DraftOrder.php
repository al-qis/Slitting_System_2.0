<?php
/**
 * Model for Draft Orders and Item Allocation
 * Location: /stock_draft_system/backend/models/DraftOrder.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/DraftCoil.php';
require_once __DIR__ . '/StockMovementLog.php';

class DraftOrder {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getDraftConnection();
    }

    /**
     * Create a new draft order and allocate selected coils
     */
    public function createDraft(array $data): array {
        $customerName   = trim($data['customer_name'] ?? '');
        $sosNo          = trim($data['sos_no'] ?? '');
        $reqLength      = floatval($data['required_length'] ?? 0);
        $reqWeight      = floatval($data['required_weight'] ?? 0);
        $reqArea        = floatval($data['required_area'] ?? 0);
        $coilIds        = $data['coil_ids'] ?? [];
        $createdBy      = trim($data['created_by'] ?? 'Production Officer');

        if (empty($customerName) || empty($sosNo)) {
            return ['ok' => false, 'error' => 'Customer Name and SOS Number are required.'];
        }

        if (empty($coilIds) || !is_array($coilIds)) {
            return ['ok' => false, 'error' => 'At least one coil must be selected for the draft.'];
        }

        $this->pdo->beginTransaction();

        try {
            // 1. Generate unique Draft No
            $draftNo = 'DFT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

            // 2. Insert draft_orders header
            $sqlOrder = "INSERT INTO draft_orders 
                (draft_no, customer_name, sos_no, required_length, required_weight, required_area, status, created_by, created_at)
                VALUES
                (:draft_no, :customer_name, :sos_no, :required_length, :required_weight, :required_area, 'DRAFT', :created_by, NOW())";
            
            $stmtOrder = $this->pdo->prepare($sqlOrder);
            $stmtOrder->execute([
                ':draft_no'        => $draftNo,
                ':customer_name'   => $customerName,
                ':sos_no'          => $sosNo,
                ':required_length' => $reqLength,
                ':required_weight' => $reqWeight,
                ':required_area'   => $reqArea,
                ':created_by'      => $createdBy
            ]);

            $draftOrderId = (int)$this->pdo->lastInsertId();

            $selLength = 0.00;
            $selWeight = 0.00;
            $selArea   = 0.00;

            // 3. Process each coil with SELECT FOR UPDATE (Concurrency Guard)
            foreach ($coilIds as $cId) {
                $coilId = intval($cId);
                $stmtCoil = $this->pdo->prepare("SELECT id, coil_no, status, length, weight, area FROM draft_coils WHERE id = :id FOR UPDATE");
                $stmtCoil->execute([':id' => $coilId]);
                $coil = $stmtCoil->fetch(PDO::FETCH_ASSOC);

                if (!$coil) {
                    throw new Exception("Coil ID {$coilId} not found.");
                }

                if (!in_array($coil['status'], ['AVAILABLE', 'CANCELLED'])) {
                    throw new Exception("Coil {$coil['coil_no']} is already allocated or not AVAILABLE (Status: {$coil['status']}).");
                }


                $cLength = floatval($coil['length']);
                $cWeight = floatval($coil['weight']);
                $cArea   = floatval($coil['area']);

                $selLength += $cLength;
                $selWeight += $cWeight;
                $selArea   += $cArea;

                // Insert into draft_order_items
                $sqlItem = "INSERT INTO draft_order_items 
                    (draft_order_id, draft_coil_id, allocated_length, allocated_weight, allocated_area, created_at)
                    VALUES
                    (:draft_order_id, :draft_coil_id, :allocated_length, :allocated_weight, :allocated_area, NOW())";
                $stmtItem = $this->pdo->prepare($sqlItem);
                $stmtItem->execute([
                    ':draft_order_id'   => $draftOrderId,
                    ':draft_coil_id'    => $coilId,
                    ':allocated_length' => $cLength,
                    ':allocated_weight' => $cWeight,
                    ':allocated_area'   => $cArea
                ]);

                // Update coil status to RESERVED
                $updCoil = $this->pdo->prepare("UPDATE draft_coils SET status = 'RESERVED', updated_at = NOW() WHERE id = :id");
                $updCoil->execute([':id' => $coilId]);

                // Audit log entry
                StockMovementLog::log(
                    $coilId,
                    $draftOrderId,
                    'AVAILABLE',
                    'RESERVED',
                    'DRAFT_ALLOCATION',
                    "Allocated to Draft {$draftNo} for {$customerName} (SOS: {$sosNo})",
                    $createdBy
                );
            }

            // 4. Update draft order selected totals
            $updOrder = $this->pdo->prepare("UPDATE draft_orders SET 
                selected_length = :selected_length,
                selected_weight = :selected_weight,
                selected_area = :selected_area,
                status = 'RESERVED'
                WHERE id = :id");
            $updOrder->execute([
                ':selected_length' => $selLength,
                ':selected_weight' => $selWeight,
                ':selected_area'   => $selArea,
                ':id'              => $draftOrderId
            ]);

            $this->pdo->commit();

            return [
                'ok' => true,
                'draft_id' => $draftOrderId,
                'draft_no' => $draftNo,
                'totals' => [
                    'selected_length' => $selLength,
                    'selected_weight' => $selWeight,
                    'selected_area'   => $selArea,
                    'is_enough'       => ($selLength >= $reqLength)
                ]
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get all draft orders with items
     */
    public function getDraftOrders(array $filters = []): array {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(draft_no LIKE :search OR customer_name LIKE :search OR sos_no LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $sql = "SELECT * FROM draft_orders";
        if (count($where) > 0) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($orders as &$ord) {
            $ord['items'] = $this->getDraftOrderItems($ord['id']);
        }

        return $orders;
    }

    /**
     * Get a single draft order by ID with its allocated coils
     */
    public function getDraftOrderById(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM draft_orders WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) return null;

        $order['items'] = $this->getDraftOrderItems($id);
        return $order;
    }

    /**
     * Update an existing draft order (re-allocates coils safely)
     */
    public function updateDraft(array $data): array {
        $draftOrderId   = intval($data['draft_order_id'] ?? 0);
        $customerName   = trim($data['customer_name'] ?? '');
        $sosNo          = trim($data['sos_no'] ?? '');
        $reqLength      = floatval($data['required_length'] ?? 0);
        $reqWeight      = floatval($data['required_weight'] ?? 0);
        $reqArea        = floatval($data['required_area'] ?? 0);
        $coilIds        = $data['coil_ids'] ?? [];
        $createdBy      = trim($data['created_by'] ?? 'Production Officer');

        if ($draftOrderId <= 0) {
            return ['ok' => false, 'error' => 'Invalid draft order ID.'];
        }

        if (empty($customerName) || empty($sosNo)) {
            return ['ok' => false, 'error' => 'Customer Name and SOS Number are required.'];
        }

        if (empty($coilIds) || !is_array($coilIds)) {
            return ['ok' => false, 'error' => 'At least one coil must be selected for the draft.'];
        }

        $this->pdo->beginTransaction();

        try {
            // 1. Fetch current draft
            $stmtOrder = $this->pdo->prepare("SELECT * FROM draft_orders WHERE id = :id FOR UPDATE");
            $stmtOrder->execute([':id' => $draftOrderId]);
            $existingDraft = $stmtOrder->fetch(PDO::FETCH_ASSOC);

            if (!$existingDraft) {
                throw new Exception("Draft order not found.");
            }

            if ($existingDraft['status'] === 'CONFIRMED') {
                throw new Exception("Confirmed draft orders cannot be modified.");
            }

            // 2. Un-reserve existing allocated coils for this draft
            $existingItems = $this->getDraftOrderItems($draftOrderId);
            foreach ($existingItems as $eItem) {
                $cId = $eItem['draft_coil_id'];
                $updC = $this->pdo->prepare("UPDATE draft_coils SET status = 'AVAILABLE', updated_at = NOW() WHERE id = :id");
                $updC->execute([':id' => $cId]);
            }

            // Remove existing items from draft_order_items
            $delItems = $this->pdo->prepare("DELETE FROM draft_order_items WHERE draft_order_id = :id");
            $delItems->execute([':id' => $draftOrderId]);

            $selLength = 0.00;
            $selWeight = 0.00;
            $selArea   = 0.00;

            // 3. Re-allocate selected coils
            foreach ($coilIds as $cId) {
                $coilId = intval($cId);
                $stmtCoil = $this->pdo->prepare("SELECT id, coil_no, status, length, weight, area FROM draft_coils WHERE id = :id FOR UPDATE");
                $stmtCoil->execute([':id' => $coilId]);
                $coil = $stmtCoil->fetch(PDO::FETCH_ASSOC);

                if (!$coil) {
                    throw new Exception("Coil ID {$coilId} not found.");
                }

                if (!in_array($coil['status'], ['AVAILABLE', 'CANCELLED'])) {
                    throw new Exception("Coil {$coil['coil_no']} is not AVAILABLE (Status: {$coil['status']}).");
                }


                $cLength = floatval($coil['length']);
                $cWeight = floatval($coil['weight']);
                $cArea   = floatval($coil['area']);

                $selLength += $cLength;
                $selWeight += $cWeight;
                $selArea   += $cArea;

                // Insert into draft_order_items
                $sqlItem = "INSERT INTO draft_order_items 
                    (draft_order_id, draft_coil_id, allocated_length, allocated_weight, allocated_area, created_at)
                    VALUES
                    (:draft_order_id, :draft_coil_id, :allocated_length, :allocated_weight, :allocated_area, NOW())";
                $stmtItem = $this->pdo->prepare($sqlItem);
                $stmtItem->execute([
                    ':draft_order_id'   => $draftOrderId,
                    ':draft_coil_id'    => $coilId,
                    ':allocated_length' => $cLength,
                    ':allocated_weight' => $cWeight,
                    ':allocated_area'   => $cArea
                ]);

                // Update coil status to RESERVED
                $updCoil = $this->pdo->prepare("UPDATE draft_coils SET status = 'RESERVED', updated_at = NOW() WHERE id = :id");
                $updCoil->execute([':id' => $coilId]);

                // Audit log
                StockMovementLog::log(
                    $coilId,
                    $draftOrderId,
                    'AVAILABLE',
                    'RESERVED',
                    'DRAFT_UPDATE',
                    "Updated in Draft {$existingDraft['draft_no']} for {$customerName} (SOS: {$sosNo})",
                    $createdBy
                );
            }

            // 4. Update header
            $updOrder = $this->pdo->prepare("UPDATE draft_orders SET 
                customer_name = :customer_name,
                sos_no = :sos_no,
                required_length = :required_length,
                required_weight = :required_weight,
                required_area = :required_area,
                selected_length = :selected_length,
                selected_weight = :selected_weight,
                selected_area = :selected_area,
                updated_at = NOW()
                WHERE id = :id");
            $updOrder->execute([
                ':customer_name'   => $customerName,
                ':sos_no'          => $sosNo,
                ':required_length' => $reqLength,
                ':required_weight' => $reqWeight,
                ':required_area'   => $reqArea,
                ':selected_length' => $selLength,
                ':selected_weight' => $selWeight,
                ':selected_area'   => $selArea,
                ':id'              => $draftOrderId
            ]);

            $this->pdo->commit();

            return [
                'ok' => true,
                'draft_id' => $draftOrderId,
                'draft_no' => $existingDraft['draft_no'],
                'msg' => 'Draft order updated successfully.'
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get items for a draft order
     */
    public function getDraftOrderItems(int $draftOrderId): array {
        $sql = "SELECT 
                    doi.*,
                    dc.coil_no,
                    dc.lot_no,
                    dc.roll,
                    dc.ref_no,
                    dc.product,
                    dc.grade,
                    dc.width,
                    dc.length,
                    dc.weight,
                    dc.customer_name,
                    dc.is_on_hold,
                    dc.hold_note,
                    dc.hold_set_by,
                    dc.hold_set_at,
                    dc.status AS current_coil_status
                FROM draft_order_items doi
                JOIN draft_coils dc ON doi.draft_coil_id = dc.id
                WHERE doi.draft_order_id = :draft_order_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':draft_order_id' => $draftOrderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Confirm a draft order (Mark as CONFIRMED / OUTGOING)
     */
    public function confirmDraft(int $draftOrderId, string $officerId = 'Production Officer'): array {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM draft_orders WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $draftOrderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new Exception("Draft order not found.");
            }

            if (in_array($order['status'], ['CONFIRMED', 'COMPLETED', 'CANCELLED'])) {
                throw new Exception("Draft order is already {$order['status']}.");
            }

            // Update order status
            $updOrder = $this->pdo->prepare("UPDATE draft_orders SET status = 'CONFIRMED', updated_at = NOW() WHERE id = :id");
            $updOrder->execute([':id' => $draftOrderId]);

            // Update items/coils to OUTGOING
            $items = $this->getDraftOrderItems($draftOrderId);
            foreach ($items as $item) {
                $coilId = $item['draft_coil_id'];
                $updCoil = $this->pdo->prepare("UPDATE draft_coils SET status = 'OUTGOING', updated_at = NOW() WHERE id = :id");
                $updCoil->execute([':id' => $coilId]);

                StockMovementLog::log(
                    $coilId,
                    $draftOrderId,
                    'RESERVED',
                    'OUTGOING',
                    'CONFIRM_DRAFT',
                    "Draft order {$order['draft_no']} confirmed for customer {$order['customer_name']}",
                    $officerId
                );
            }

            $this->pdo->commit();
            return ['ok' => true, 'msg' => 'Draft order successfully confirmed and coils marked as OUTGOING.'];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Cancel a draft order (Revert coils to AVAILABLE)
     */
    public function cancelDraft(int $draftOrderId, string $reason = 'Cancelled by user', string $officerId = 'Production Officer'): array {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM draft_orders WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $draftOrderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new Exception("Draft order not found.");
            }

            if ($order['status'] === 'CANCELLED') {
                throw new Exception("Draft order is already CANCELLED.");
            }

            // Update order status
            $updOrder = $this->pdo->prepare("UPDATE draft_orders SET status = 'CANCELLED', updated_at = NOW() WHERE id = :id");
            $updOrder->execute([':id' => $draftOrderId]);

            // Revert coils to AVAILABLE
            $items = $this->getDraftOrderItems($draftOrderId);
            foreach ($items as $item) {
                $coilId = $item['draft_coil_id'];
                $prevStatus = $item['current_coil_status'];

                $updCoil = $this->pdo->prepare("UPDATE draft_coils SET status = 'AVAILABLE', updated_at = NOW() WHERE id = :id");
                $updCoil->execute([':id' => $coilId]);

                StockMovementLog::log(
                    $coilId,
                    $draftOrderId,
                    $prevStatus,
                    'AVAILABLE',
                    'CANCEL_DRAFT',
                    "Draft order {$order['draft_no']} cancelled. Reason: {$reason}",
                    $officerId
                );
            }

            $this->pdo->commit();
            return ['ok' => true, 'msg' => 'Draft order cancelled and coils reverted to AVAILABLE.'];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Permanently delete a cancelled draft order and its items
     */
    public function deleteDraft(int $draftOrderId, string $officerId = 'Production Officer'): array {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM draft_orders WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $draftOrderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new Exception("Draft order not found.");
            }

            if ($order['status'] !== 'CANCELLED') {
                throw new Exception("Only cancelled drafts can be deleted. Current status: {$order['status']}");
            }

            // Delete draft order items first
            $delItems = $this->pdo->prepare("DELETE FROM draft_order_items WHERE draft_order_id = :id");
            $delItems->execute([':id' => $draftOrderId]);

            // Delete draft order
            $delOrder = $this->pdo->prepare("DELETE FROM draft_orders WHERE id = :id");
            $delOrder->execute([':id' => $draftOrderId]);

            $this->pdo->commit();
            return ['ok' => true, 'msg' => "Cancelled draft {$order['draft_no']} deleted permanently."];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}


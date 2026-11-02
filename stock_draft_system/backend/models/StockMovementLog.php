<?php
/**
 * Model for Stock Movement Audit Trail
 * Location: /stock_draft_system/backend/models/StockMovementLog.php
 */

require_once __DIR__ . '/../config/database.php';

class StockMovementLog {
    /**
     * Record a status movement log
     */
    public static function log(
        int $coilId,
        ?int $draftOrderId,
        string $previousStatus,
        string $newStatus,
        string $action,
        string $remarks = '',
        string $officerId = 'Production Officer'
    ): bool {
        try {
            $pdo = Database::getDraftConnection();
            $sql = "INSERT INTO draft_movement_log 
                    (draft_coil_id, draft_order_id, previous_status, new_status, action, remarks, officer_id, created_at)
                    VALUES
                    (:draft_coil_id, :draft_order_id, :previous_status, :new_status, :action, :remarks, :officer_id, NOW())";
            $stmt = $pdo->prepare($sql);
            return $stmt->execute([
                ':draft_coil_id'   => $coilId,
                ':draft_order_id'  => $draftOrderId,
                ':previous_status' => $previousStatus,
                ':new_status'      => $newStatus,
                ':action'          => $action,
                ':remarks'         => $remarks,
                ':officer_id'      => $officerId
            ]);
        } catch (Exception $e) {
            error_log("Failed to insert movement log: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch movement history
     */
    public static function getLogs(int $limit = 100): array {
        $pdo = Database::getDraftConnection();
        $sql = "SELECT 
                    ml.*,
                    dc.coil_no,
                    dc.lot_no,
                    dc.product,
                    do.draft_no,
                    do.customer_name
                FROM draft_movement_log ml
                LEFT JOIN draft_coils dc ON ml.draft_coil_id = dc.id
                LEFT JOIN draft_orders do ON ml.draft_order_id = do.id
                ORDER BY ml.id DESC
                LIMIT :limit";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

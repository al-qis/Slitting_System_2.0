<?php
/**
 * Model for Draft Coils & Isolated Status Management
 * Location: /stock_draft_system/backend/models/DraftCoil.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../adapters/SlittingDbAdapter.php';
require_once __DIR__ . '/StockMovementLog.php';

class DraftCoil {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getDraftConnection();
    }

    /**
     * Sync coils from slitting_db into local draft_coils table
     */
    public function syncFromSlittingDb(): array {
        $adapter = new SlittingDbAdapter();
        $sourceCoils = $adapter->getMonthEndStockCoils();

        $syncedCount = 0;
        $newCount = 0;

        $validSourceCoilIds = [];

        foreach ($sourceCoils as $sc) {
            $validSourceCoilIds[] = intval($sc['source_coil_id']);

            // Check if coil exists in draft_coils by source_coil_id & source_type
            $checkSql = "SELECT id, status, product FROM draft_coils WHERE source_coil_id = :source_coil_id AND source_type = :source_type LIMIT 1";
            $stmt = $this->pdo->prepare($checkSql);
            $stmt->execute([
                ':source_coil_id' => $sc['source_coil_id'],
                ':source_type'    => $sc['source_type']
            ]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                $initialStatus = in_array(strtoupper($sc['state'] ?? ''), ['PALLETISED', 'WAITING QC', 'APPROVED', 'DELIVERED']) ? 'USED' : 'AVAILABLE';

                $insertSql = "INSERT INTO draft_coils 
                    (source_coil_id, source_type, coil_no, lot_no, product, grade, width, length, weight, area, roll, customer_name, ref_no, state, status, synced_at)
                    VALUES
                    (:source_coil_id, :source_type, :coil_no, :lot_no, :product, :grade, :width, :length, :weight, :area, :roll, :customer_name, :ref_no, :state, :status, NOW())";
                $instStmt = $this->pdo->prepare($insertSql);
                $instStmt->execute([
                    ':source_coil_id' => $sc['source_coil_id'],
                    ':source_type'    => $sc['source_type'],
                    ':coil_no'        => $sc['coil_no'],
                    ':lot_no'         => $sc['lot_no'],
                    ':product'        => $sc['product'],
                    ':grade'          => $sc['grade'],
                    ':width'          => $sc['width'],
                    ':length'         => $sc['length'],
                    ':weight'         => $sc['weight'],
                    ':area'           => $sc['area'],
                    ':roll'           => $sc['roll'],
                    ':customer_name'  => $sc['customer_name'] ?? 'STOCK',
                    ':ref_no'         => $sc['ref_no'] ?? '-',
                    ':state'          => $sc['state'] ?? 'STOCK',
                    ':status'         => $initialStatus
                ]);
                $newCoilId = $this->pdo->lastInsertId();
                $newCount++;

                // Log initial sync
                StockMovementLog::log(
                    intval($newCoilId),
                    null,
                    'UNTRACKED',
                    $initialStatus,
                    'SYSTEM_SYNC',
                    'Initial sync from slitting_db month-end stock',
                    'System'
                );

            } else {
                // Update product code, customer_name, ref_no & state
                $updStmt = $this->pdo->prepare("UPDATE draft_coils SET product = :product, grade = :grade, customer_name = :customer_name, ref_no = :ref_no, state = :state WHERE id = :id");
                $updStmt->execute([
                    ':product'       => $sc['product'],
                    ':grade'         => $sc['grade'],
                    ':customer_name' => $sc['customer_name'] ?? 'STOCK',
                    ':ref_no'        => $sc['ref_no'] ?? '-',
                    ':state'         => $sc['state'] ?? 'STOCK',
                    ':id'            => $existing['id']
                ]);
                $syncedCount++;
            }
        }

        // Clean up any coils in draft_coils that are NOT in Finish Product Stock (444 count)
        // Coils not currently in an active draft order should be removed
        if (count($validSourceCoilIds) > 0) {
            $inClause = implode(',', $validSourceCoilIds);
            $this->pdo->exec("
                DELETE dc FROM draft_coils dc
                LEFT JOIN (
                    SELECT doi.draft_coil_id 
                    FROM draft_order_items doi
                    JOIN draft_orders d ON d.id = doi.draft_order_id
                    WHERE d.status IN ('DRAFT', 'RESERVED')
                ) active_orders ON active_orders.draft_coil_id = dc.id
                WHERE (dc.source_type != 'slitting_product' OR dc.source_coil_id NOT IN ($inClause))
                  AND active_orders.draft_coil_id IS NULL
            ");
        } else {
            $this->pdo->exec("DELETE FROM draft_coils WHERE source_type != 'slitting_product'");
        }

        // Real-time State Refresh: Update state for ALL existing coils directly from slitting_db
        // AND automatically change status to 'USED' if the state has progressed to PALLETISED, WAITING QC, APPROVED, or DELIVERED.
        try {
            $allTracked = $this->pdo->query("SELECT id, source_coil_id, state, status FROM draft_coils WHERE source_type = 'slitting_product'")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($allTracked)) {
                $trackedIds = array_column($allTracked, 'source_coil_id');
                $liveStates = $adapter->getLiveStatesForCoils($trackedIds);

                $updateStateStmt = $this->pdo->prepare("UPDATE draft_coils SET state = :state, status = :status, updated_at = NOW() WHERE id = :id");
                foreach ($allTracked as $tr) {
                    $srcId = intval($tr['source_coil_id']);
                    if (isset($liveStates[$srcId])) {
                        $currentState = $liveStates[$srcId];
                        $currentStatus = $tr['status'];
                        $newStatus = $currentStatus;

                        // If state has moved to palletised, waiting QC, approved, or delivered, auto-change status to USED
                        if (in_array($currentState, ['PALLETISED', 'WAITING QC', 'APPROVED', 'DELIVERED'])) {
                            if ($currentStatus !== 'USED') {
                                $newStatus = 'USED';
                                StockMovementLog::log(
                                    intval($tr['id']),
                                    null,
                                    $currentStatus,
                                    'USED',
                                    'AUTO_STATE_PROGRESSION',
                                    "Coil state progressed to {$currentState} in production system — automatically marked as USED",
                                    'System'
                                );
                            }
                        }

                        if ($currentState !== $tr['state'] || $newStatus !== $currentStatus) {
                            $updateStateStmt->execute([
                                ':state'  => $currentState,
                                ':status' => $newStatus,
                                ':id'     => $tr['id']
                            ]);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error during real-time state sync: " . $e->getMessage());
        }

        // Auto-heal status for cancelled coils:
        // 1. Any coil with status 'CANCELLED' should automatically be 'AVAILABLE' as long as its state is 'STOCK'
        try {
            $this->pdo->exec("UPDATE draft_coils SET status = 'AVAILABLE', updated_at = NOW() WHERE status = 'CANCELLED' AND (state = 'STOCK' OR state IS NULL)");
            
            // 2. Any coil marked 'RESERVED' that is NOT currently allocated to an active draft (DRAFT / RESERVED) should automatically be 'AVAILABLE'
            $this->pdo->exec("
                UPDATE draft_coils dc
                SET dc.status = 'AVAILABLE', dc.updated_at = NOW()
                WHERE dc.status = 'RESERVED'
                  AND dc.id NOT IN (
                      SELECT doi.draft_coil_id 
                      FROM draft_order_items doi
                      JOIN draft_orders d ON d.id = doi.draft_order_id
                      WHERE d.status IN ('DRAFT', 'RESERVED')
                  )
            ");
        } catch (Exception $e) {
            error_log("Error during cancelled coil auto-heal: " . $e->getMessage());
        }

        return [
            'total_retrieved' => count($sourceCoils),
            'new_added' => $newCount,
            'existing_synced' => $syncedCount
        ];
    }



    /**
     * Get all draft coils with filtering, search & pagination (Prioritizes slitting_product finish goods)
     */
    public function getCoils(array $filters = []): array {
        // Auto-sync live data from slitting_db on every fetch
        $this->syncFromSlittingDb();

        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            if (strpos($filters['status'], ',') !== false) {
                $statusList = array_map('trim', explode(',', $filters['status']));
                $placeholders = [];
                foreach ($statusList as $k => $stVal) {
                    $paramKey = ":status_" . $k;
                    $placeholders[] = $paramKey;
                    $params[$paramKey] = $stVal;
                }
                $where[] = "status IN (" . implode(',', $placeholders) . ")";
            } else {
                $where[] = "status = :status";
                $params[':status'] = $filters['status'];
            }
        } else {
            // By default, hide coils that are already USED
            $where[] = "status != 'USED'";
        }


        if (!empty($filters['state'])) {
            $where[] = "state = :state";
            $params[':state'] = $filters['state'];
        }

        if (!empty($filters['product'])) {
            $where[] = "product LIKE :product";
            $params[':product'] = '%' . $filters['product'] . '%';
        }

        if (!empty($filters['grade'])) {
            $where[] = "grade = :grade";
            $params[':grade'] = $filters['grade'];
        }

        if (!empty($filters['customer'])) {
            $where[] = "customer_name = :customer";
            $params[':customer'] = $filters['customer'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(coil_no LIKE :search OR lot_no LIKE :search OR product LIKE :search OR customer_name LIKE :search OR ref_no LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $sql = "SELECT dc.*, 
                       active_draft.draft_no AS reserved_draft_no,
                       COALESCE(active_draft.draft_customer, dc.hold_draft_customer) AS draft_customer,
                       COALESCE(active_draft.draft_sos_no, dc.hold_draft_sos) AS draft_sos_no,
                       COALESCE(active_draft.reserved_at, dc.updated_at) AS reserved_at
                FROM draft_coils dc
                LEFT JOIN (
                    SELECT doi.draft_coil_id, doi.created_at AS reserved_at, d.draft_no, d.customer_name AS draft_customer, d.sos_no AS draft_sos_no
                    FROM draft_order_items doi
                    JOIN draft_orders d ON d.id = doi.draft_order_id
                    WHERE d.status IN ('DRAFT', 'RESERVED')
                ) active_draft ON active_draft.draft_coil_id = dc.id";
        if (count($where) > 0) {
            $sql .= " WHERE " . implode(" AND ", array_map(function($w) {
                // Prepend dc. if unqualified column name
                if (strpos($w, '.') === false && strpos($w, '(') === false) {
                    return 'dc.' . $w;
                }
                return $w;
            }, $where));
        }
        $sql .= " ORDER BY CASE WHEN dc.source_type = 'slitting_product' THEN 1 WHEN dc.source_type = 'mother_coil' THEN 2 ELSE 3 END, dc.id DESC";

        $limit = isset($filters['limit']) ? intval($filters['limit']) : 100;
        $offset = isset($filters['offset']) ? intval($filters['offset']) : 0;
        if ($limit > 0) {
            $sql .= " LIMIT " . $limit . " OFFSET " . $offset;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get summary totals across all statuses
     */
    public function getSummaryStats(): array {
        // Auto-sync if table is empty
        $cntStmt = $this->pdo->query("SELECT COUNT(*) FROM draft_coils");
        if ($cntStmt && (int)$cntStmt->fetchColumn() === 0) {
            $this->syncFromSlittingDb();
        }

        $sql = "SELECT 
                    COALESCE(SUM(CASE WHEN status != 'USED' THEN 1 ELSE 0 END), 0) as total_coils,
                    COALESCE(SUM(CASE WHEN status = 'AVAILABLE' THEN 1 ELSE 0 END), 0) as available_count,
                    COALESCE(SUM(CASE WHEN status = 'RESERVED' THEN 1 ELSE 0 END), 0) as reserved_count,
                    COALESCE(SUM(CASE WHEN status = 'USED' THEN 1 ELSE 0 END), 0) as used_count,
                    COALESCE(SUM(CASE WHEN status = 'OUTGOING' THEN 1 ELSE 0 END), 0) as outgoing_count,
                    COALESCE(SUM(CASE WHEN status = 'CANCELLED' THEN 1 ELSE 0 END), 0) as cancelled_count,
                    COALESCE(SUM(CASE WHEN status = 'AVAILABLE' THEN length ELSE 0 END), 0) as available_length,
                    COALESCE(SUM(CASE WHEN status = 'AVAILABLE' THEN weight ELSE 0 END), 0) as available_weight,
                    COALESCE(SUM(CASE WHEN status = 'RESERVED' THEN length ELSE 0 END), 0) as reserved_length,
                    COALESCE(SUM(CASE WHEN status = 'RESERVED' THEN weight ELSE 0 END), 0) as reserved_weight
                FROM draft_coils";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Update coil status safely
     */
    public function updateStatus(int $coilId, string $newStatus, ?int $draftOrderId = null, string $action = 'STATUS_UPDATE', string $remarks = '', string $officerId = 'Production Officer'): bool {
        $stmt = $this->pdo->prepare("SELECT status FROM draft_coils WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $coilId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$current) {
            return false;
        }

        $prevStatus = $current['status'];

        $update = $this->pdo->prepare("UPDATE draft_coils SET status = :status, updated_at = NOW() WHERE id = :id");
        $success = $update->execute([
            ':status' => $newStatus,
            ':id'     => $coilId
        ]);

        if ($success) {
            StockMovementLog::log($coilId, $draftOrderId, $prevStatus, $newStatus, $action, $remarks, $officerId);
        }

        return $success;
    }

    /**
     * Get distinct customer names present in draft_coils
     */
    public function getUniqueCustomers(): array {
        $stmt = $this->pdo->query("SELECT DISTINCT customer_name FROM draft_coils WHERE customer_name IS NOT NULL AND customer_name != '' ORDER BY customer_name ASC");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    }

    /**
     * Get distinct products present in draft_coils
     */
    public function getUniqueProducts(): array {
        $stmt = $this->pdo->query("SELECT DISTINCT product FROM draft_coils WHERE product IS NOT NULL AND product != '' ORDER BY product ASC");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    }

    /**
     * Place a coil on hold (notification flag — sticker change needed)
     */
    public function setHold(int $coilId, string $note, string $officer = 'Production Officer', ?string $draftCustomer = null, ?string $draftSos = null): bool {
        // If draftCustomer not explicitly passed, try to parse from note "Change sticker from ... to <CUSTOMER>"
        if (empty($draftCustomer) && preg_match('/to\s+([A-Za-z0-9_-]+)/i', $note, $m)) {
            $draftCustomer = trim($m[1]);
        }

        $stmt = $this->pdo->prepare("
            UPDATE draft_coils 
            SET is_on_hold = 1, 
                hold_note = :note, 
                hold_draft_customer = :draft_customer, 
                hold_draft_sos = :draft_sos, 
                hold_set_by = :officer, 
                hold_set_at = NOW(), 
                updated_at = NOW()
            WHERE id = :id
        ");
        $success = $stmt->execute([
            ':note'           => $note,
            ':draft_customer' => $draftCustomer,
            ':draft_sos'      => $draftSos,
            ':officer'        => $officer,
            ':id'             => $coilId
        ]);

        if ($success) {
            StockMovementLog::log($coilId, null, 'AVAILABLE', 'ON_HOLD', 'HOLD_SET', $note, $officer);
        }

        return $success;
    }

    /**
     * Release a coil hold (sticker has been changed)
     */
    public function releaseHold(int $coilId, string $officer = 'Production Officer'): bool {
        // Get current hold note for logging
        $cur = $this->pdo->prepare("SELECT hold_note FROM draft_coils WHERE id = :id");
        $cur->execute([':id' => $coilId]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);

        $stmt = $this->pdo->prepare("
            UPDATE draft_coils 
            SET is_on_hold = 0, 
                hold_note = NULL, 
                hold_draft_customer = NULL, 
                hold_draft_sos = NULL, 
                hold_set_by = NULL, 
                hold_set_at = NULL, 
                updated_at = NOW()
            WHERE id = :id
        ");
        $success = $stmt->execute([':id' => $coilId]);

        if ($success) {
            $remarks = 'Hold cleared' . ($row && $row['hold_note'] ? ' (was: ' . $row['hold_note'] . ')' : '');
            StockMovementLog::log($coilId, null, 'ON_HOLD', 'AVAILABLE', 'HOLD_RELEASED', $remarks, $officer);
        }

        return $success;
    }

    /**
     * Get all coils currently on hold
     */
    public function getOnHoldCoils(): array {
        $stmt = $this->pdo->query("SELECT * FROM draft_coils WHERE is_on_hold = 1 ORDER BY hold_set_at DESC");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
}


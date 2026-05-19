<?php
// =============================================================
// PalletManager.php
// PLACEMENT: C:\Apache24\htdocs\slitting_system\PalletManager.php
//
// Encapsulates ALL pallet business rules:
//   • Pallet No format validation (regex)
//   • Matching constraint enforcement (customer/ref/product/width)
//   • 8-roll capacity cap (transaction-safe)
//   • Bundle delivery scan-out (single JOIN UPDATE)
//   • process_log + pallet_delivery_log audit writes
//
// Usage (from pallet.php, scan_product_action.php, etc.):
//   $pm     = new PalletManager($conn, $_SESSION['role']);
//   $result = $pm->createPallet('SFS-0024-001 (A)');
//   $result = $pm->addRollToPallet($palletId, $productId);
//   $result = $pm->bundleDeliver($palletId, $triggeredByProductId);
// =============================================================

declare(strict_types=1);

class PalletManager
{
    // ---------------------------------------------------------
    // Business constants
    // ---------------------------------------------------------
    public const MAX_ROLLS  = 8;
    public const MIN_ROLLS  = 1;     // minimum to send to QC / deliver

    /**
     * Pallet No regex — enforces:
     *   SFS-         literal prefix
     *   \d{4}        exactly 4 digits  (e.g. 0024)
     *   -            separator
     *   \d{3}        exactly 3 digits  (e.g. 001)
     *   (?: …)?      optional trailing suffix:
     *       \s+          one or more spaces
     *       \(           literal open paren
     *       [A-Z]{1,3}   1–3 uppercase letters  (A, AB, ABC)
     *       \)           literal close paren
     *
     * Valid:   SFS-0024-001
     *          SFS-0024-001 (A)
     *          SFS-0024-001 (AB)
     *          SFS-0024-001 (ABC)
     * Invalid: SFS-24-001        (only 2 digits in first group)
     *          SFS-0024-01       (only 2 digits in second group)
     *          SFS-0024-001(A)   (missing space before paren)
     *          SFS-0024-001 (a)  (lowercase letter)
     *          SFS-0024-001 (ABCD) (4 letters — too many)
     */
    public const PALLET_NO_REGEX = '/^SFS-\d{4}-\d{3}(?: \([A-Z]{1,3}\))?$/';

    private mysqli $conn;
    private string $actor;   // e.g. 'slitting', 'qc'

    public function __construct(mysqli $conn, string $actor = 'slitting')
    {
        $this->conn  = $conn;
        $this->actor = $actor;
    }

    // =========================================================
    // 1. PALLET NO VALIDATION
    // =========================================================

    /**
     * Validate a pallet number string against the SFS format.
     *
     * @return array{ok: bool, msg: string}
     */
    public function validatePalletNo(string $palletNo): array
    {
        $palletNo = trim($palletNo);

        if ($palletNo === '') {
            return ['ok' => false, 'msg' => 'Pallet No cannot be empty.'];
        }

        if (!preg_match(self::PALLET_NO_REGEX, $palletNo)) {
            return [
                'ok'  => false,
                'msg' => "Invalid format. Expected: SFS-XXXX-XXX or SFS-XXXX-XXX (A). "
                       . "Got: \"{$palletNo}\".",
            ];
        }

        // Check uniqueness in DB
        $stmt = $this->conn->prepare(
            "SELECT id FROM pallets WHERE pallet_no = ? LIMIT 1"
        );
        $stmt->bind_param("s", $palletNo);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($exists) {
            return [
                'ok'  => false,
                'msg' => "Pallet No \"{$palletNo}\" already exists in the system.",
            ];
        }

        return ['ok' => true, 'msg' => 'Valid.'];
    }

    // =========================================================
    // 2. CREATE PALLET
    //    Only pallet_no is required at creation time.
    //    Constraint columns (customer_name, ref_no, product_type,
    //    width) are seeded automatically when the FIRST roll is
    //    scanned via addRollToPallet().
    // =========================================================

    /**
     * @param string $palletNo  Validated SFS-XXXX-XXX format
     * @return array{ok: bool, msg: string, pallet_id?: int, pallet_no?: string}
     */
    public function createPallet(string $palletNo): array
    {
        $v = $this->validatePalletNo($palletNo);
        if (!$v['ok']) return $v;

        $stmt = $this->conn->prepare("
            INSERT INTO pallets
                (pallet_no, customer_name, ref_no, product_type, width,
                 status, created_by)
            VALUES (?, '', '', '', 0, 'building', ?)
        ");
        $stmt->bind_param("ss", $palletNo, $this->actor);
        $stmt->execute();
        $palletId = (int)$this->conn->insert_id;
        $stmt->close();

        return [
            'ok'        => true,
            'msg'       => "Pallet {$palletNo} created. Scan the first roll to lock its constraints.",
            'pallet_id' => $palletId,
            'pallet_no' => $palletNo,
        ];
    }

    // =========================================================
    // 3. ADD ROLL TO EXISTING PALLET
    //    Enforces matching constraints + 8-roll cap atomically.
    // =========================================================

    /**
     * Add a roll to an existing building pallet.
     *
     * @return array{ok: bool, msg: string, seq?: int, roll_count?: int}
     */
    public function addRollToPallet(int $palletId, int $productId): array
    {
        $this->conn->begin_transaction();
        try {
            // Lock the pallet row so concurrent adds can't both see count=7
            $stmt = $this->conn->prepare(
                "SELECT * FROM pallets WHERE id = ? FOR UPDATE"
            );
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $pallet = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$pallet) {
                throw new RuntimeException("Pallet #{$palletId} not found.");
            }
            if ($pallet['status'] !== 'building') {
                throw new RuntimeException(
                    "Pallet {$pallet['pallet_no']} is already closed "
                    . "(status: {$pallet['status']}). Cannot add rolls."
                );
            }

            // Count existing rolls (inside the lock)
            $stmt = $this->conn->prepare(
                "SELECT COUNT(*) AS cnt FROM pallet_items WHERE pallet_id = ?"
            );
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $currentCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();

            if ($currentCount >= self::MAX_ROLLS) {
                throw new RuntimeException(
                    "Pallet {$pallet['pallet_no']} is full "
                    . "(" . self::MAX_ROLLS . "/" . self::MAX_ROLLS . " rolls)."
                );
            }

            // Load the product to add
            $product = $this->fetchProduct($productId);
            if (!$product) {
                throw new RuntimeException("Product #{$productId} not found.");
            }

            // Basic eligibility (stock counted, not voided, not already on pallet)
            $guard = $this->guardProductForPallet($product);
            if (!$guard['ok']) {
                throw new RuntimeException($guard['msg']);
            }

            // ── MATCHING CONSTRAINTS ────────────────────────────────
            // First roll: seed the pallet's constraint columns.
            // Subsequent rolls: validate against those seeded values.
            if ($currentCount === 0) {
                // Seed — lock the four constraint values from this product
                $cn = trim($product['customer_name'] ?? '');
                $rn = trim($product['ref_no']        ?? '');
                $pt = trim($product['product']       ?? '');
                $wd = (float)($product['width']       ?? 0);
                $upd = $this->conn->prepare("
                    UPDATE pallets
                    SET customer_name = ?,
                        ref_no        = ?,
                        product_type  = ?,
                        width         = ?
                    WHERE id = ?
                ");
                $upd->bind_param("sssdi", $cn, $rn, $pt, $wd, $palletId);
                $upd->execute();
                $upd->close();
                // Refresh $pallet so the new values are available if needed below
                $pallet['customer_name'] = $cn;
                $pallet['ref_no']        = $rn;
                $pallet['product_type']  = $pt;
                $pallet['width']         = $wd;
            } else {
                // Validate against the already-seeded constraints
                $mismatches = $this->checkMatchingConstraints($pallet, $product);
                if (!empty($mismatches)) {
                    throw new RuntimeException(
                        "Product does not match pallet constraints. "
                        . "Mismatched fields: " . implode(', ', $mismatches) . ". "
                        . "All rolls on a pallet must share the same Customer, "
                        . "Ref No, Product Type, and Width."
                    );
                }
            }

            // All checks passed — insert
            $seq = $currentCount + 1;
            $add = $this->_insertPalletItem($palletId, $productId, $seq);
            if (!$add['ok']) {
                throw new RuntimeException($add['msg']);
            }

            $this->conn->commit();
            return [
                'ok'         => true,
                'msg'        => "Roll #{$productId} added at position {$seq}.",
                'seq'        => $seq,
                'roll_count' => $seq,
            ];
        } catch (Throwable $e) {
            $this->conn->rollback();
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    // =========================================================
    // 4. MATCHING CONSTRAINT CHECK
    // =========================================================

    /**
     * Returns an array of field names that don't match the pallet's seed.
     * Empty array = all good.
     *
     * Fields checked (per business rules):
     *   customer_name, ref_no, product (type), width
     * Fields intentionally NOT checked:
     *   lot_no, length, roll_no  (allowed to differ)
     *
     * @param  array $pallet   Row from pallets table
     * @param  array $product  Row from slitting_product table
     * @return string[]        Mismatched field labels
     */
    public function checkMatchingConstraints(array $pallet, array $product): array
    {
        $mismatches = [];

        // Customer name — case-insensitive trim comparison
        if (
            strtolower(trim($product['customer_name'] ?? ''))
            !== strtolower(trim($pallet['customer_name']))
        ) {
            $mismatches[] = sprintf(
                'Customer (pallet: "%s", product: "%s")',
                $pallet['customer_name'],
                $product['customer_name'] ?? '—'
            );
        }

        // Ref No — exact match after trim
        if (trim($product['ref_no'] ?? '') !== trim($pallet['ref_no'])) {
            $mismatches[] = sprintf(
                'Ref No (pallet: "%s", product: "%s")',
                $pallet['ref_no'],
                $product['ref_no'] ?? '—'
            );
        }

        // Product Type — case-insensitive
        if (
            strtolower(trim($product['product'] ?? ''))
            !== strtolower(trim($pallet['product_type']))
        ) {
            $mismatches[] = sprintf(
                'Product Type (pallet: "%s", product: "%s")',
                $pallet['product_type'],
                $product['product'] ?? '—'
            );
        }

        // Width — numeric comparison with 0.01 mm tolerance
        $widthDiff = abs((float)($product['width'] ?? 0) - (float)$pallet['width']);
        if ($widthDiff > 0.01) {
            $mismatches[] = sprintf(
                'Width (pallet: %gmm, product: %gmm)',
                $pallet['width'],
                $product['width'] ?? 0
            );
        }

        return $mismatches;
    }

    // =========================================================
    // 5. BUNDLE DELIVERY — the core scan-out function
    //    Called when operator scans ANY one product on an
    //    approved pallet. Updates ALL rolls atomically.
    // =========================================================

    /**
     * Execute bundle delivery for the pallet containing $productId.
     * If $palletId is supplied directly, $productId is only used for
     * the audit log (which roll was scanned).
     *
     * @return array{
     *   ok: bool, msg: string, code: string,
     *   pallet_no?: string, pallet_id?: int, rolls_delivered?: int
     * }
     */
    public function bundleDeliver(int $palletId, int $triggeredByProductId = 0): array
    {
        $this->conn->begin_transaction();
        try {
            // Lock pallet row — prevents double-delivery from concurrent scans
            $stmt = $this->conn->prepare(
                "SELECT * FROM pallets WHERE id = ? FOR UPDATE"
            );
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $pallet = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$pallet) {
                $this->conn->rollback();
                return [
                    'ok'   => false,
                    'code' => 'NOT_FOUND',
                    'msg'  => "Pallet #{$palletId} not found.",
                ];
            }

            $palletNo = $pallet['pallet_no'];

            // ── Idempotent: already delivered ───────────────────
            if ($pallet['status'] === 'delivered') {
                $this->conn->rollback();
                return [
                    'ok'         => true,    // not an error
                    'code'       => 'ALREADY_DELIVERED',
                    'msg'        => "Pallet {$palletNo} was already delivered on {$pallet['delivered_at']}.",
                    'pallet_no'  => $palletNo,
                    'pallet_id'  => $palletId,
                    'rolls_delivered' => 0,
                ];
            }

            // ── Must be in 'approved' state ──────────────────────
            if ($pallet['status'] !== 'approved') {
                $this->conn->rollback();
                $friendly = match($pallet['status']) {
                    'building'   => 'still being built (not yet sent to QC).',
                    'pending_qc' => 'awaiting QC approval.',
                    'rejected'   => 'rejected by QC — resolve issues first.',
                    default      => "in an undeliverable state ({$pallet['status']}).",
                };
                return [
                    'ok'        => false,
                    'code'      => 'WRONG_STATE',
                    'msg'       => "Pallet {$palletNo} is {$friendly}",
                    'status'    => $pallet['status'],
                    'pallet_no' => $palletNo,
                ];
            }

            // ── Minimum roll count ───────────────────────────────
            $stmt = $this->conn->prepare(
                "SELECT COUNT(*) AS cnt FROM pallet_items WHERE pallet_id = ?"
            );
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $rollCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();

            if ($rollCount < self::MIN_ROLLS) {
                $this->conn->rollback();
                return [
                    'ok'   => false,
                    'code' => 'EMPTY_PALLET',
                    'msg'  => "Pallet {$palletNo} has no rolls assigned.",
                ];
            }

            // ── Fetch roll IDs for process_log (before UPDATE) ──
            $stmt = $this->conn->prepare("
                SELECT pi.slitting_product_id AS product_id,
                       sp.mother_id,
                       sp.status AS prev_status
                FROM pallet_items pi
                JOIN slitting_product sp ON sp.id = pi.slitting_product_id
                WHERE pi.pallet_id = ?
            ");
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $rolls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // ── UPDATE pallets (re-check status to guard race) ──
            $stmt = $this->conn->prepare("
                UPDATE pallets
                SET status       = 'delivered',
                    delivered_at = NOW()
                WHERE id     = ?
                  AND status = 'approved'
            ");
            $stmt->bind_param("i", $palletId);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                // Another request beat us to it — idempotent return
                $stmt->close();
                $this->conn->rollback();
                return [
                    'ok'         => true,
                    'code'       => 'ALREADY_DELIVERED',
                    'msg'        => "Pallet {$palletNo} was just delivered by another session.",
                    'pallet_no'  => $palletNo,
                    'pallet_id'  => $palletId,
                    'rolls_delivered' => 0,
                ];
            }
            $stmt->close();

            // ── Bulk UPDATE all rolls (single JOIN UPDATE) ───────
            // This is ONE query regardless of how many rolls are on
            // the pallet — no PHP loop, no N+1.
            $stmt = $this->conn->prepare("
                UPDATE slitting_product sp
                JOIN   pallet_items pi
                    ON pi.slitting_product_id = sp.id
                SET    sp.status       = 'DELIVERED',
                       sp.delivered_at = NOW()
                WHERE  pi.pallet_id   = ?
            ");
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $updatedRolls = (int)$stmt->affected_rows;
            $stmt->close();

            // ── process_log per roll ─────────────────────────────
            foreach ($rolls as $r) {
                $this->writeProcessLog(
                    'slitting',
                    (int)$r['product_id'],
                    $r['mother_id'] ? (int)$r['mother_id'] : null,
                    $r['prev_status'],
                    'DELIVERED',
                    "bundle_scan_out pallet_id={$palletId} pallet_no={$palletNo}",
                );
            }

            // ── pallet_delivery_log ──────────────────────────────
            $stmt = $this->conn->prepare("
                INSERT INTO pallet_delivery_log
                    (pallet_id, pallet_no, rolls_delivered,
                     triggered_by_product_id, performed_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $trigId = $triggeredByProductId ?: null;
            $stmt->bind_param(
                "issis",
                $palletId, $palletNo, $updatedRolls,
                $trigId, $this->actor
            );
            $stmt->execute();
            $stmt->close();

            $this->conn->commit();

            return [
                'ok'             => true,
                'code'           => 'DELIVERED',
                'msg'            => "Pallet {$palletNo} delivered — {$updatedRolls} roll(s) updated.",
                'pallet_no'      => $palletNo,
                'pallet_id'      => $palletId,
                'rolls_delivered' => $updatedRolls,
            ];

        } catch (Throwable $e) {
            $this->conn->rollback();
            return [
                'ok'   => false,
                'code' => 'DB_ERROR',
                'msg'  => 'Database error: ' . $e->getMessage(),
            ];
        }
    }

    // =========================================================
    // 6. HELPER: find pallet from a scanned product
    // =========================================================

    /**
     * Derive the pallet_id for a given product (for scan-out path).
     * Returns null if the product is not on any pallet.
     */
    public function getPalletIdForProduct(int $productId): ?int
    {
        $stmt = $this->conn->prepare(
            "SELECT pallet_id FROM pallet_items WHERE slitting_product_id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $productId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['pallet_id'] : null;
    }

    /**
     * Full pallet row by ID.
     */
    public function getPallet(int $palletId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM pallets WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $palletId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * All rolls on a pallet with full product detail.
     */
    public function getPalletItems(int $palletId): array
    {
        $stmt = $this->conn->prepare("
            SELECT pi.seq, pi.added_at,
                   sp.id    AS product_id,
                   sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
                   sp.width, sp.length, sp.actual_length, sp.status,
                   sp.customer_name, sp.ref_no
            FROM pallet_items pi
            JOIN slitting_product sp ON sp.id = pi.slitting_product_id
            WHERE pi.pallet_id = ?
            ORDER BY pi.seq ASC
        ");
        $stmt->bind_param("i", $palletId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    // =========================================================
    // PRIVATE HELPERS
    // =========================================================

    /** Fetch a slitting_product row by ID. */
    private function fetchProduct(int $productId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT id, product, lot_no, coil_no, roll_no,
                   width, length, actual_length,
                   status, stock_counted, is_voided,
                   mother_id, customer_name, ref_no
            FROM slitting_product
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $productId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Basic eligibility checks before adding to any pallet.
     * Does NOT check matching constraints (those require the pallet row).
     */
    private function guardProductForPallet(array $product): array
    {
        if ((int)($product['is_voided'] ?? 0) === 1) {
            return ['ok' => false, 'msg' => "Roll #{$product['id']} has been voided."];
        }
        if ((int)($product['stock_counted'] ?? 0) !== 1) {
            return [
                'ok'  => false,
                'msg' => "Roll #{$product['id']}: actual length not saved yet. "
                       . "Click Update first.",
            ];
        }
        if (!($product['customer_name'] ?? '')) {
            return [
                'ok'  => false,
                'msg' => "Roll #{$product['id']} has no Customer assigned. "
                       . "Assign customer before palletising.",
            ];
        }
        if (!($product['ref_no'] ?? '')) {
            return [
                'ok'  => false,
                'msg' => "Roll #{$product['id']} has no Ref No assigned.",
            ];
        }

        // Check not already on a pallet
        $stmt = $this->conn->prepare("
            SELECT pi.pallet_id, p.pallet_no
            FROM pallet_items pi
            JOIN pallets p ON p.id = pi.pallet_id
            WHERE pi.slitting_product_id = ?
            LIMIT 1
        ");
        $pid = (int)$product['id'];
        $stmt->bind_param("i", $pid);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            return [
                'ok'  => false,
                'msg' => "Roll #{$product['id']} is already on pallet {$existing['pallet_no']}.",
            ];
        }

        return ['ok' => true, 'msg' => 'OK'];
    }

    /** Insert a pallet_items row (called inside an open transaction). */
    private function _insertPalletItem(
        int    $palletId,
        int    $productId,
        int    $seq
    ): array {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO pallet_items (pallet_id, slitting_product_id, seq, added_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->bind_param("iiis", $palletId, $productId, $seq, $this->actor);
            $stmt->execute();
            $stmt->close();
            return ['ok' => true, 'msg' => 'Inserted.'];
        } catch (Throwable $e) {
            // Duplicate key = roll already on this (or another) pallet
            if ($this->conn->errno === 1062) {
                return [
                    'ok'  => false,
                    'msg' => "Roll #{$productId} is already assigned to a pallet.",
                ];
            }
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    /** Write a single row to process_log. */
    private function writeProcessLog(
        string  $entityType,
        int     $entityId,
        ?int    $motherId,
        ?string $fromStatus,
        string  $toStatus,
        string  $actionDetail
    ): void {
        $stmt = $this->conn->prepare("
            INSERT INTO process_log
                (entity_type, entity_id, mother_id,
                 from_status, to_status, performed_by, action_detail)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) return;
        $stmt->bind_param(
            "siissss",
            $entityType, $entityId, $motherId,
            $fromStatus, $toStatus,
            $this->actor, $actionDetail
        );
        $stmt->execute();
        $stmt->close();
    }
}
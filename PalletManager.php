<?php
// =============================================================
// PalletManager.php  —  v2 (Pallet Upgrade)
// PLACEMENT: C:\Apache24\htdocs\slitting_system\PalletManager.php
//
// NEW METHODS in this version:
//   removeRollFromPallet()   — remove one roll, resequence
//   deletePallet()           — safely delete pallet, untag rolls
//   reopenRejectedPallet()   — rejected → building (edit mode)
//   resubmitToQC()           — building → pending_qc after edit
//
// All existing methods are unchanged.
// =============================================================

declare(strict_types=1);

class PalletManager
{
    // ---------------------------------------------------------
    // Constants
    // ---------------------------------------------------------
    public const MAX_ROLLS = 8;
    public const MIN_ROLLS = 1;

    /**
     * SFS-XXXX-XXX  or  SFS-XXXX-XXX (A)  … (ABC)
     */
    public const PALLET_NO_REGEX = '/^SFS-\d{4}-\d{3}(?: \([A-Z]{1,3}\))?$/';

    private mysqli $conn;
    private string $actor;

    public function __construct(mysqli $conn, string $actor = 'slitting')
    {
        $this->conn  = $conn;
        $this->actor = $actor;
    }

    // =========================================================
    // 1. VALIDATE PALLET NO
    // =========================================================
    public function validatePalletNo(string $palletNo): array
    {
        $palletNo = trim($palletNo);
        if ($palletNo === '') {
            return ['ok' => false, 'msg' => 'Pallet No cannot be empty.'];
        }
        if (!preg_match(self::PALLET_NO_REGEX, $palletNo)) {
            return [
                'ok'  => false,
                'msg' => "Invalid format. Expected SFS-XXXX-XXX or SFS-XXXX-XXX (A). Got: \"{$palletNo}\".",
            ];
        }
        $stmt = $this->conn->prepare(
            "SELECT id FROM pallets WHERE pallet_no = ? LIMIT 1"
        );
        $stmt->bind_param("s", $palletNo);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($exists) {
            return ['ok' => false, 'msg' => "Pallet No \"{$palletNo}\" already exists."];
        }
        return ['ok' => true, 'msg' => 'Valid.'];
    }

    // =========================================================
    // 2. CREATE PALLET  (pallet_no only — constraints seeded
    //    from first scanned roll via addRollToPallet)
    // =========================================================
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
    // 3. ADD ROLL TO PALLET
    //    — first roll seeds constraints
    //    — subsequent rolls are validated against them
    //    — works on both 'building' AND 'rejected' pallets
    //      (rejected pallets must be reopened first via
    //       reopenRejectedPallet())
    // =========================================================
    public function addRollToPallet(int $palletId, int $productId): array
    {
        $this->conn->begin_transaction();
        try {
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

            // Only 'building' status accepts new rolls
            if ($pallet['status'] !== 'building') {
                $friendly = match($pallet['status']) {
                    'pending_qc' => 'waiting for QC approval',
                    'approved'   => 'already approved',
                    'delivered'  => 'already delivered',
                    'rejected'   => 'QC rejected — click "Edit Pallet" to reopen it first',
                    default      => "in state: {$pallet['status']}",
                };
                throw new RuntimeException(
                    "Pallet {$pallet['pallet_no']} is {$friendly}. Cannot add rolls."
                );
            }

            // Count existing rolls
            $stmt = $this->conn->prepare(
                "SELECT COUNT(*) AS cnt FROM pallet_items WHERE pallet_id = ?"
            );
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $currentCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();

            if ($currentCount >= self::MAX_ROLLS) {
                throw new RuntimeException(
                    "Pallet {$pallet['pallet_no']} is full ({$currentCount}/" . self::MAX_ROLLS . " rolls)."
                );
            }

            $product = $this->fetchProduct($productId);
            if (!$product) {
                throw new RuntimeException("Product #{$productId} not found.");
            }

            $guard = $this->guardProductForPallet($product);
            if (!$guard['ok']) {
                throw new RuntimeException($guard['msg']);
            }

            // ── First roll: seed constraints ─────────────────
            if ($currentCount === 0) {
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
                $pallet['customer_name'] = $cn;
                $pallet['ref_no']        = $rn;
                $pallet['product_type']  = $pt;
                $pallet['width']         = $wd;
            } else {
                // ── Subsequent rolls: validate ────────────────
                $mismatches = $this->checkMatchingConstraints($pallet, $product);
                if (!empty($mismatches)) {
                    throw new RuntimeException(
                        "Product does not match pallet constraints. "
                        . "Mismatched: " . implode(', ', $mismatches) . ". "
                        . "All rolls must share the same Customer, Ref No, Product Type, and Width."
                    );
                }
            }

            $seq = $currentCount + 1;
            $add = $this->_insertPalletItem($palletId, $productId, $seq);
            if (!$add['ok']) {
                throw new RuntimeException($add['msg']);
            }

            // Audit log for edit operations
            if (($pallet['edit_count'] ?? 0) > 0) {
                $this->writeEditLog($palletId, $pallet['pallet_no'], 'add_roll', $productId,
                    $this->makeProductRef($product));
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
    // 4. REMOVE ROLL FROM PALLET                      ← NEW
    //    Works on 'building' pallets only.
    //    After removal, remaining rolls are re-sequenced.
    //    If the removed roll was the ONLY roll AND it was the
    //    constraint seed, the pallet constraints are cleared
    //    so the next roll can re-seed them.
    // =========================================================

    /**
     * @return array{ok: bool, msg: string, new_count?: int}
     */
    public function removeRollFromPallet(int $palletId, int $productId): array
    {
        $this->conn->begin_transaction();
        try {
            // Lock the pallet
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
                    "Pallet {$pallet['pallet_no']} cannot be modified "
                    . "(status: {$pallet['status']}). "
                    . "Only 'building' pallets can be edited."
                );
            }

            // Confirm the roll is actually on this pallet
            $stmt = $this->conn->prepare(
                "SELECT id FROM pallet_items
                 WHERE pallet_id = ? AND slitting_product_id = ?
                 LIMIT 1"
            );
            $stmt->bind_param("ii", $palletId, $productId);
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$item) {
                throw new RuntimeException(
                    "Product #{$productId} is not on pallet {$pallet['pallet_no']}."
                );
            }

            // ── Delete the pallet_items row ───────────────────
            $stmt = $this->conn->prepare(
                "DELETE FROM pallet_items
                 WHERE pallet_id = ? AND slitting_product_id = ?"
            );
            $stmt->bind_param("ii", $palletId, $productId);
            $stmt->execute();
            $stmt->close();

            // ── Re-sequence remaining rows ────────────────────
            $stmt = $this->conn->prepare(
                "SELECT id FROM pallet_items WHERE pallet_id = ? ORDER BY seq ASC"
            );
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $remaining = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($remaining as $idx => $r) {
                $newSeq = $idx + 1;
                $u = $this->conn->prepare("UPDATE pallet_items SET seq = ? WHERE id = ?");
                $u->bind_param("ii", $newSeq, $r['id']);
                $u->execute();
                $u->close();
            }

            $newCount = count($remaining);

            // ── If pallet is now empty, clear constraint seed ──
            // This lets the next roll re-seed the constraints,
            // avoiding a situation where an empty pallet locks out
            // all products that don't match the removed roll.
            if ($newCount === 0) {
                $clearStmt = $this->conn->prepare("
                    UPDATE pallets
                    SET customer_name = '',
                        ref_no        = '',
                        product_type  = '',
                        width         = 0
                    WHERE id = ?
                ");
                $clearStmt->bind_param("i", $palletId);
                $clearStmt->execute();
                $clearStmt->close();
            }

            // ── Audit log ─────────────────────────────────────
            $product = $this->fetchProduct($productId);
            $this->writeEditLog(
                $palletId, $pallet['pallet_no'],
                'remove_roll', $productId,
                $product ? $this->makeProductRef($product) : "id={$productId}",
                "Removed from pallet, {$newCount} rolls remaining"
            );

            $this->conn->commit();
            return [
                'ok'        => true,
                'msg'       => "Roll #{$productId} removed. {$newCount} roll(s) remaining.",
                'new_count' => $newCount,
            ];
        } catch (Throwable $e) {
            $this->conn->rollback();
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    // =========================================================
    // 5. DELETE ENTIRE PALLET                         ← NEW
    //    Safe: only allowed on 'building' or 'rejected' pallets.
    //    Products are UNTAGGED (pallet_items rows deleted),
    //    their slitting_product status is reset to 'IN'
    //    (stock-counted, available to be palletised again).
    //    The pallets row is then hard-deleted.
    //    Delivered / approved pallets cannot be deleted.
    // =========================================================

    /**
     * @return array{ok: bool, msg: string, rolls_untagged?: int}
     */
    public function deletePallet(int $palletId): array
    {
        $this->conn->begin_transaction();
        try {
            // Lock the pallet row
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

            $palletNo = $pallet['pallet_no'];

            // Only building or rejected pallets can be deleted
            $deletableStates = ['building', 'rejected'];
            if (!in_array($pallet['status'], $deletableStates, true)) {
                $reason = match($pallet['status']) {
                    'pending_qc' => 'waiting for QC — withdraw from QC first',
                    'approved'   => 'approved by QC — cannot delete an approved pallet',
                    'delivered'  => 'already delivered — historical record cannot be removed',
                    default      => "in state '{$pallet['status']}'",
                };
                throw new RuntimeException(
                    "Pallet {$palletNo} cannot be deleted: it is {$reason}."
                );
            }

            // ── Fetch all product IDs on this pallet ──────────
            $stmt = $this->conn->prepare(
                "SELECT slitting_product_id FROM pallet_items WHERE pallet_id = ?"
            );
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $productIds  = array_column($items, 'slitting_product_id');
            $rollsCount  = count($productIds);

            // ── Reset each product's status back to 'IN' ──────
            // (stock_counted stays 1 — the actual length is still valid)
            // date_out is cleared because the product is no longer in QC
            if (!empty($productIds)) {
                $placeholders = implode(',', array_fill(0, $rollsCount, '?'));
                $types        = str_repeat('i', $rollsCount);

                $resetStmt = $this->conn->prepare("
                    UPDATE slitting_product
                    SET status       = 'IN',
                        date_out     = NULL,
                        qc_comment   = NULL
                    WHERE id IN ({$placeholders})
                      AND status IN ('IN', 'WAITING', 'REJECTED')
                ");
                $resetStmt->bind_param($types, ...$productIds);
                $resetStmt->execute();
                $resetStmt->close();
            }

            // ── Delete pallet_items (untag rolls) ─────────────
            $stmt = $this->conn->prepare(
                "DELETE FROM pallet_items WHERE pallet_id = ?"
            );
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $stmt->close();

            // ── Audit log entry ───────────────────────────────
            $this->writeEditLog(
                $palletId, $palletNo,
                'delete_pallet', null, null,
                "Pallet deleted. {$rollsCount} roll(s) untagged and reset to IN."
            );

            // ── Hard-delete the pallet row ────────────────────
            $stmt = $this->conn->prepare("DELETE FROM pallets WHERE id = ?");
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $stmt->close();

            $this->conn->commit();
            return [
                'ok'            => true,
                'msg'           => "Pallet {$palletNo} deleted. {$rollsCount} roll(s) returned to stock.",
                'rolls_untagged' => $rollsCount,
                'pallet_no'     => $palletNo,
            ];
        } catch (Throwable $e) {
            $this->conn->rollback();
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    // =========================================================
    // 6. REOPEN REJECTED PALLET                       ← NEW
    //    QC rejected → building  (operator can now edit)
    //    Rolls are kept on the pallet but their status is reset
    //    to 'IN' so they don't show as REJECTED in other views.
    //    edit_count is incremented for the audit trail.
    // =========================================================

    /**
     * @return array{ok: bool, msg: string}
     */
    public function reopenRejectedPallet(int $palletId): array
    {
        $this->conn->begin_transaction();
        try {
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
            if ($pallet['status'] !== 'rejected') {
                throw new RuntimeException(
                    "Pallet {$pallet['pallet_no']} is not in rejected state "
                    . "(current: {$pallet['status']})."
                );
            }

            $palletNo = $pallet['pallet_no'];

            // ── Reopen the pallet ─────────────────────────────
            $stmt = $this->conn->prepare("
                UPDATE pallets
                SET status      = 'building',
                    qc_comment  = NULL,
                    edit_count  = edit_count + 1
                WHERE id     = ?
                  AND status = 'rejected'
            ");
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                throw new RuntimeException("Pallet state changed concurrently — please refresh.");
            }
            $stmt->close();

            // ── Reset roll statuses to IN ─────────────────────
            // They were set to REJECTED by QC. Resetting to IN
            // makes them editable and visible again in finish_product.
            $stmt = $this->conn->prepare("
                UPDATE slitting_product sp
                JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                SET sp.status     = 'IN',
                    sp.date_out   = NULL,
                    sp.qc_comment = NULL
                WHERE pi.pallet_id = ?
            ");
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $rollsReset = (int)$stmt->affected_rows;
            $stmt->close();

            // ── Audit ─────────────────────────────────────────
            $this->writeEditLog(
                $palletId, $palletNo,
                'reopen', null, null,
                "Pallet reopened for editing after QC rejection. "
                . "{$rollsReset} roll(s) reset to IN."
            );

            $this->conn->commit();
            return [
                'ok'  => true,
                'msg' => "Pallet {$palletNo} reopened. Edit the contents, then re-submit to QC.",
            ];
        } catch (Throwable $e) {
            $this->conn->rollback();
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    // =========================================================
    // 7. RESUBMIT TO QC AFTER EDITING                 ← NEW
    //    building (edit_count > 0) → pending_qc
    //    Minimum 1 roll required. Matching constraints are
    //    re-validated against the current constraint seed.
    // =========================================================

    /**
     * @return array{ok: bool, msg: string, roll_count?: int}
     */
    public function resubmitToQC(int $palletId): array
    {
        $this->conn->begin_transaction();
        try {
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
                    "Pallet {$pallet['pallet_no']} is not in building state."
                );
            }

            $palletNo = $pallet['pallet_no'];

            // ── Count rolls ───────────────────────────────────
            $stmt = $this->conn->prepare(
                "SELECT COUNT(*) AS cnt FROM pallet_items WHERE pallet_id = ?"
            );
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $rollCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();

            if ($rollCount < self::MIN_ROLLS) {
                throw new RuntimeException(
                    "Pallet {$palletNo} has no rolls. Add at least one roll before submitting to QC."
                );
            }

            // ── Validate all rolls still match constraints ─────
            // (Edge case: somehow a mismatch crept in)
            $items = $this->getPalletItems($palletId);
            $violations = [];
            foreach ($items as $item) {
                $product = $this->fetchProduct((int)$item['product_id']);
                if (!$product) continue;
                $mm = $this->checkMatchingConstraints($pallet, $product);
                if (!empty($mm)) {
                    $violations[] = "R-{$item['roll_no']}: " . implode('; ', $mm);
                }
            }
            if (!empty($violations)) {
                throw new RuntimeException(
                    "Cannot resubmit — constraint violations found:\n"
                    . implode("\n", $violations)
                );
            }

            // ── Update pallet ─────────────────────────────────
            $stmt = $this->conn->prepare("
                UPDATE pallets
                SET status = 'pending_qc'
                WHERE id = ? AND status = 'building'
            ");
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                throw new RuntimeException("Pallet state changed — please refresh.");
            }
            $stmt->close();

            // ── Set rolls to WAITING ──────────────────────────
            $stmt = $this->conn->prepare("
                UPDATE slitting_product sp
                JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                SET sp.status   = 'WAITING',
                    sp.date_out = NOW()
                WHERE pi.pallet_id = ?
            ");
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $stmt->close();

            // ── Audit ─────────────────────────────────────────
            $this->writeEditLog(
                $palletId, $palletNo,
                'resubmit_to_qc', null, null,
                "Re-submitted to QC with {$rollCount} roll(s)."
            );

            $this->conn->commit();
            return [
                'ok'         => true,
                'msg'        => "Pallet {$palletNo} re-submitted to QC with {$rollCount} roll(s).",
                'roll_count' => $rollCount,
            ];
        } catch (Throwable $e) {
            $this->conn->rollback();
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    // =========================================================
    // 8. MATCHING CONSTRAINTS CHECK
    // =========================================================
    public function checkMatchingConstraints(array $pallet, array $product): array
    {
        $mismatches = [];

        if (
            strtolower(trim($product['customer_name'] ?? ''))
            !== strtolower(trim($pallet['customer_name']))
        ) {
            $mismatches[] = sprintf(
                'Customer (pallet: "%s", roll: "%s")',
                $pallet['customer_name'], $product['customer_name'] ?? '—'
            );
        }

        if (trim($product['ref_no'] ?? '') !== trim($pallet['ref_no'])) {
            $mismatches[] = sprintf(
                'Ref No (pallet: "%s", roll: "%s")',
                $pallet['ref_no'], $product['ref_no'] ?? '—'
            );
        }

        if (
            strtolower(trim($product['product'] ?? ''))
            !== strtolower(trim($pallet['product_type']))
        ) {
            $mismatches[] = sprintf(
                'Product Type (pallet: "%s", roll: "%s")',
                $pallet['product_type'], $product['product'] ?? '—'
            );
        }

        $widthDiff = abs((float)($product['width'] ?? 0) - (float)$pallet['width']);
        if ($widthDiff > 0.01) {
            $mismatches[] = sprintf(
                'Width (pallet: %gmm, roll: %gmm)',
                $pallet['width'], $product['width'] ?? 0
            );
        }

        return $mismatches;
    }

    // =========================================================
    // 9. BUNDLE DELIVERY
    // =========================================================
    public function bundleDeliver(int $palletId, int $triggeredByProductId = 0): array
    {
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare("SELECT * FROM pallets WHERE id = ? FOR UPDATE");
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $pallet = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$pallet) {
                $this->conn->rollback();
                return ['ok' => false, 'code' => 'NOT_FOUND',
                        'msg' => "Pallet #{$palletId} not found."];
            }

            $palletNo = $pallet['pallet_no'];

            if ($pallet['status'] === 'delivered') {
                $this->conn->rollback();
                return ['ok' => true, 'code' => 'ALREADY_DELIVERED',
                        'msg' => "Pallet {$palletNo} was already delivered on {$pallet['delivered_at']}.",
                        'pallet_no' => $palletNo, 'pallet_id' => $palletId, 'rolls_delivered' => 0];
            }

            if ($pallet['status'] !== 'approved') {
                $this->conn->rollback();
                $friendly = match($pallet['status']) {
                    'building'   => 'still being built.',
                    'pending_qc' => 'awaiting QC approval.',
                    'rejected'   => 'rejected by QC — resolve issues first.',
                    default      => "in state: {$pallet['status']}.",
                };
                return ['ok' => false, 'code' => 'WRONG_STATE',
                        'msg' => "Pallet {$palletNo} is {$friendly}",
                        'status' => $pallet['status'], 'pallet_no' => $palletNo];
            }

            $stmt = $this->conn->prepare(
                "SELECT COUNT(*) AS cnt FROM pallet_items WHERE pallet_id = ?"
            );
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $rollCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();

            if ($rollCount < self::MIN_ROLLS) {
                $this->conn->rollback();
                return ['ok' => false, 'code' => 'EMPTY_PALLET',
                        'msg' => "Pallet {$palletNo} has no rolls."];
            }

            $stmt = $this->conn->prepare("
                SELECT pi.slitting_product_id AS product_id, sp.mother_id, sp.status AS prev_status
                FROM pallet_items pi
                JOIN slitting_product sp ON sp.id = pi.slitting_product_id
                WHERE pi.pallet_id = ?
            ");
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $rolls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $stmt = $this->conn->prepare("
                UPDATE pallets SET status = 'delivered', delivered_at = NOW()
                WHERE id = ? AND status = 'approved'
            ");
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                $stmt->close();
                $this->conn->rollback();
                return ['ok' => true, 'code' => 'ALREADY_DELIVERED',
                        'msg' => "Pallet {$palletNo} was just delivered by another session.",
                        'pallet_no' => $palletNo, 'pallet_id' => $palletId, 'rolls_delivered' => 0];
            }
            $stmt->close();

            $stmt = $this->conn->prepare("
                UPDATE slitting_product sp
                JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                SET sp.status = 'DELIVERED', sp.delivered_at = NOW()
                WHERE pi.pallet_id = ?
            ");
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $updatedRolls = (int)$stmt->affected_rows;
            $stmt->close();

            foreach ($rolls as $r) {
                $this->writeProcessLog(
                    'slitting', (int)$r['product_id'],
                    $r['mother_id'] ? (int)$r['mother_id'] : null,
                    $r['prev_status'], 'DELIVERED',
                    "bundle_scan_out pallet_id={$palletId} pallet_no={$palletNo}"
                );
            }

            $stmt = $this->conn->prepare("
                INSERT INTO pallet_delivery_log
                    (pallet_id, pallet_no, rolls_delivered, triggered_by_product_id, performed_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $trigId = $triggeredByProductId ?: null;
            $stmt->bind_param("issis", $palletId, $palletNo, $updatedRolls, $trigId, $this->actor);
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
            return ['ok' => false, 'code' => 'DB_ERROR', 'msg' => 'Database error: ' . $e->getMessage()];
        }
    }

    // =========================================================
    // 10. SEND TO QC  (first submission — building → pending_qc)
    // =========================================================
    public function sendToQC(int $palletId): array
    {
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                "SELECT COUNT(*) AS cnt FROM pallet_items WHERE pallet_id = ?"
            );
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $cnt = (int)$stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();

            if ($cnt < self::MIN_ROLLS) {
                throw new RuntimeException('Pallet is empty — add at least one roll.');
            }

            $stmt = $this->conn->prepare(
                "UPDATE pallets SET status = 'pending_qc' WHERE id = ? AND status = 'building'"
            );
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                throw new RuntimeException('Pallet is not in building state.');
            }
            $stmt->close();

            $stmt = $this->conn->prepare("
                UPDATE slitting_product sp
                JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                SET sp.status = 'WAITING', sp.date_out = NOW()
                WHERE pi.pallet_id = ?
            ");
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $stmt->close();

            $this->conn->commit();
            return ['ok' => true, 'msg' => "Pallet sent to QC with {$cnt} roll(s)."];
        } catch (Throwable $e) {
            $this->conn->rollback();
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    // =========================================================
    // READ HELPERS
    // =========================================================
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

    public function getPallet(int $palletId): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM pallets WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $palletId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public function getPalletItems(int $palletId): array
    {
        $stmt = $this->conn->prepare("
            SELECT pi.seq, pi.added_at,
                   sp.id AS product_id,
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
    private function fetchProduct(int $productId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT id, product, lot_no, coil_no, roll_no,
                   width, length, actual_length,
                   status, stock_counted, is_voided,
                   mother_id, customer_name, ref_no
            FROM slitting_product
            WHERE id = ? LIMIT 1
        ");
        $stmt->bind_param("i", $productId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function guardProductForPallet(array $product): array
    {
        if ((int)($product['is_voided'] ?? 0) === 1) {
            return ['ok' => false, 'msg' => "Roll #{$product['id']} has been voided."];
        }
        if ((int)($product['stock_counted'] ?? 0) !== 1) {
            return [
                'ok'  => false,
                'msg' => "Roll #{$product['id']}: actual length not saved yet. Click Update first.",
            ];
        }
        if (!($product['customer_name'] ?? '')) {
            return [
                'ok'  => false,
                'msg' => "Roll #{$product['id']} has no Customer assigned.",
            ];
        }
        if (!($product['ref_no'] ?? '')) {
            return [
                'ok'  => false,
                'msg' => "Roll #{$product['id']} has no Ref No assigned.",
            ];
        }

        // Not already on another pallet
        $stmt = $this->conn->prepare("
            SELECT pi.pallet_id, p.pallet_no
            FROM pallet_items pi
            JOIN pallets p ON p.id = pi.pallet_id
            WHERE pi.slitting_product_id = ? LIMIT 1
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

    private function _insertPalletItem(int $palletId, int $productId, int $seq): array
    {
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
            if ($this->conn->errno === 1062) {
                return ['ok' => false, 'msg' => "Roll #{$productId} is already assigned to a pallet."];
            }
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

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
            $fromStatus, $toStatus, $this->actor, $actionDetail
        );
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Write to pallet_edit_log (created in migration).
     * Silently fails if the table doesn't exist yet.
     */
    private function writeEditLog(
        int     $palletId,
        string  $palletNo,
        string  $action,
        ?int    $productId,
        ?string $productRef,
        string  $note = ''
    ): void {
        $stmt = $this->conn->prepare("
            INSERT INTO pallet_edit_log
                (pallet_id, pallet_no, action, product_id, product_ref, performed_by, note)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) return;
        $stmt->bind_param(
            "ississs",
            $palletId, $palletNo, $action,
            $productId, $productRef, $this->actor, $note
        );
        $stmt->execute();
        $stmt->close();
    }

    private function makeProductRef(array $product): string
    {
        return trim(
            ($product['lot_no']  ?? '') . ' ' .
            ($product['coil_no'] ?? '') . ' R-' .
            ltrim($product['roll_no'] ?? '', 'R')
        );
    }
}
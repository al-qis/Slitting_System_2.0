<?php

namespace App\Models;

use App\Core\Model;
use Exception;
use Throwable;

class MotherCoil extends Model
{
    private array $knownCustomers = [
        'NAE','NAX','NCI MFG','TAIHO','NRI','ASHUKA','NIPPON','NTC','SGC',
        'STAMPING','YANTAI','NIPP','NVC','NSJ','NIP','YTEC','NSA','NCI 2','STOCK','TRIAL'
    ];

    public function getKnownCustomers(): array
    {
        return $this->knownCustomers;
    }

    public function sanitizeCustomerCode(?string $cust): ?string
    {
        $cust = strtoupper(trim((string)$cust));
        if ($cust === '') return null;
        foreach ($this->knownCustomers as $k) {
            if (strtoupper($k) === $cust) {
                return $k;
            }
        }
        return null;
    }

    /**
     * Map coil_no prefix to products from coil_product_map
     */
    public function productsFromCoil(string $coil_no): array
    {
        $coil_no = strtoupper(trim($coil_no));
        if ($coil_no === '') return [];

        preg_match('/^[A-Z0-9]+/', $coil_no, $m);
        $token = $m[0] ?? '';
        if ($token === '') return [];

        for ($len = strlen($token); $len >= 1; $len--) {
            $code = substr($token, 0, $len);
            $stmt = $this->mysqli->prepare(
                "SELECT product FROM coil_product_map WHERE coil_code = ? ORDER BY product"
            );
            $stmt->bind_param("s", $code);
            $stmt->execute();
            $res  = $stmt->get_result();
            $rows = $res->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (count($rows) > 0) {
                return array_column($rows, 'product');
            }
        }
        return [];
    }

    /**
     * Validate product text against coil_product_map
     */
    public function validateProduct(string $code): ?string
    {
        $code = trim($code);
        if ($code === '') return null;

        $stmt = $this->mysqli->prepare("SELECT DISTINCT product FROM coil_product_map WHERE product = ? LIMIT 1");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row['product'] ?? null;
    }

    /**
     * Search coils by coil_no, lot_no, or product
     */
    public function searchCandidates(string $query): array
    {
        $q = trim($query);
        if ($q === '') return [];

        $like = '%' . $q . '%';
        $stmt = $this->mysqli->prepare("
            SELECT id, product, lot_no, coil_no, grade, width, length
            FROM mother_coil
            WHERE coil_no LIKE ? OR lot_no LIKE ? OR product LIKE ?
            ORDER BY id DESC
            LIMIT 15
        ");
        $stmt->bind_param("sss", $like, $like, $like);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * Resolve bulk paste text for bulk print
     */
    public function resolveBulkPaste(string $text): array
    {
        $lines = preg_split('/[\r\n,]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $resolved  = [];
        $ambiguous = [];
        $not_found = [];
        $seenIds   = [];

        $stmtExact2 = $this->mysqli->prepare("SELECT id, product, lot_no, coil_no, grade, width, length FROM mother_coil WHERE lot_no = ? AND coil_no = ?");
        $stmtByCoil = $this->mysqli->prepare("SELECT id, product, lot_no, coil_no, grade, width, length FROM mother_coil WHERE coil_no = ?");
        $stmtByLot  = $this->mysqli->prepare("SELECT id, product, lot_no, coil_no, grade, width, length FROM mother_coil WHERE lot_no = ?");

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') continue;

            $parts = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
            $matches = [];

            if (count($parts) >= 2) {
                $lot  = $parts[0];
                $coil = $parts[1];
                $stmtExact2->bind_param("ss", $lot, $coil);
                $stmtExact2->execute();
                $matches = $stmtExact2->get_result()->fetch_all(MYSQLI_ASSOC);
            } else {
                $token = $parts[0];
                $stmtByCoil->bind_param("s", $token);
                $stmtByCoil->execute();
                $matches = $stmtByCoil->get_result()->fetch_all(MYSQLI_ASSOC);

                if (empty($matches)) {
                    $stmtByLot->bind_param("s", $token);
                    $stmtByLot->execute();
                    $matches = $stmtByLot->get_result()->fetch_all(MYSQLI_ASSOC);
                }
            }

            if (count($matches) === 1) {
                $row = $matches[0];
                if (!isset($seenIds[$row['id']])) {
                    $resolved[] = $row;
                    $seenIds[$row['id']] = true;
                }
            } elseif (count($matches) > 1) {
                $ambiguous[] = ['token' => $line, 'candidates' => $matches];
            } else {
                $not_found[] = $line;
            }
        }

        $stmtExact2->close();
        $stmtByCoil->close();
        $stmtByLot->close();

        return [
            'ok'        => true,
            'resolved'  => $resolved,
            'ambiguous' => $ambiguous,
            'not_found' => $not_found,
        ];
    }

    /**
     * Find mother coil by ID
     */
    public function findById(int $id): ?array
    {
        return $this->queryOne("SELECT * FROM mother_coil WHERE id = :id", ['id' => $id]);
    }

    /**
     * Fetch existing slitting plans for mother coil
     */
    public function findSlittingPlans(int $motherCoilId): array
    {
        $stmt = $this->mysqli->prepare("SELECT roll_seq, planned_width, customer_name, ref_no FROM slitting_plans WHERE mother_coil_id = ? ORDER BY sort_order ASC, id ASC");
        $stmt->bind_param("i", $motherCoilId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Get distinct products for dropdowns
     */
    public function getDistinctProducts(): array
    {
        $rows = $this->queryAll("SELECT DISTINCT product_code FROM std_wgt ORDER BY product_code");
        return array_column($rows, 'product_code');
    }

    /**
     * Search and list mother coils
     */
    public function search(string $search = '', string $printFilter = ''): array
    {
        $tokens = $search !== '' ? preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) : [];
        $where  = [];
        $params = [];
        $types  = '';

        if (count($tokens) === 1) {
            $searchTerm = '%' . $tokens[0] . '%';
            $where[]  = '(coil_no LIKE ? OR lot_no LIKE ? OR product LIKE ?)';
            $params[] = $searchTerm; $params[] = $searchTerm; $params[] = $searchTerm;
            $types   .= 'sss';
        } elseif (count($tokens) === 2) {
            $where[]  = '(lot_no LIKE ? AND coil_no LIKE ?)';
            $params[] = '%' . $tokens[0] . '%';
            $params[] = '%' . $tokens[1] . '%';
            $types   .= 'ss';
        } elseif (count($tokens) >= 3) {
            $where[]  = '(lot_no LIKE ? AND coil_no LIKE ? AND product LIKE ?)';
            $params[] = '%' . $tokens[0] . '%';
            $params[] = '%' . $tokens[1] . '%';
            $params[] = '%' . $tokens[2] . '%';
            $types   .= 'sss';
        }

        if ($printFilter === 'printed') {
            $where[] = 'printed_at IS NOT NULL';
        } elseif ($printFilter === 'not_printed') {
            $where[] = 'printed_at IS NULL';
        }

        $sql = 'SELECT * FROM mother_coil';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC';

        $stmt = $this->mysqli->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $res;
    }

    /**
     * Single add mother coil + slitting plans
     */
    public function create(array $data, array $planRows = []): int
    {
        $product = trim($data['product'] ?? '');
        $grade   = trim($data['grade'] ?? '');
        $lot_no  = trim($data['lot_no'] ?? '');
        $coil_no = trim($data['coil_no'] ?? '');
        $width   = trim($data['width'] ?? '');
        $length  = trim($data['length'] ?? '');

        if ($product === '' || $grade === '' || $lot_no === '' || $coil_no === '' || $width === '' || $length === '') {
            throw new Exception("All fields are required.");
        }

        // Duplicate check
        $dup = $this->mysqli->prepare("SELECT id FROM mother_coil WHERE coil_no = ? AND lot_no = ?");
        $dup->bind_param("ss", $coil_no, $lot_no);
        $dup->execute();
        if ($dup->get_result()->num_rows > 0) {
            $dup->close();
            throw new Exception("Duplicate: Coil No and Lot No combination already exists.");
        }
        $dup->close();

        $this->mysqli->begin_transaction();
        try {
            $stmt = $this->mysqli->prepare("
                INSERT INTO mother_coil (product, grade, lot_no, coil_no, width, length, date_created, status)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), 'NEW')
            ");
            $stmt->bind_param("ssssss", $product, $grade, $lot_no, $coil_no, $width, $length);
            if (!$stmt->execute()) {
                throw new Exception("SQL Error: " . $stmt->error);
            }
            $newId = $this->mysqli->insert_id;
            $stmt->close();

            if (!empty($planRows)) {
                $planStmt = $this->mysqli->prepare("
                    INSERT INTO slitting_plans (mother_coil_id, roll_seq, planned_width, customer_name, ref_no, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $order = 0;
                foreach ($planRows as $pr) {
                    $seq   = trim($pr['seq'] ?? '');
                    $wRaw  = trim($pr['width'] ?? '');
                    $cRaw  = trim($pr['customer'] ?? '');
                    $refNo = trim($pr['ref_no'] ?? '');
                    if ($seq === '' || $wRaw === '' || !is_numeric($wRaw)) continue;

                    $order++;
                    $widthVal = (float)$wRaw;
                    $cust = $this->sanitizeCustomerCode($cRaw);
                    $planStmt->bind_param("isdssi", $newId, $seq, $widthVal, $cust, $refNo, $order);
                    $planStmt->execute();
                }
                $planStmt->close();
            }

            $this->mysqli->commit();
            return $newId;
        } catch (Throwable $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }

    /**
     * Bulk Add (from Excel multi-row paste)
     */
    public function bulkAdd(array $rows, array $sharedPlanRows = []): array
    {
        $planStmt = null;
        if (!empty($sharedPlanRows)) {
            $planStmt = $this->mysqli->prepare("
                INSERT INTO slitting_plans (mother_coil_id, roll_seq, planned_width, customer_name, ref_no, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
        }

        $inserted = 0;
        $skipped  = [];

        foreach ($rows as $i => $r) {
            $r_lot     = trim($r['lot_no']  ?? '');
            $r_coil    = trim($r['coil_no'] ?? '');
            $r_grade   = trim($r['grade']   ?? '');
            $r_width   = trim($r['width']   ?? '');
            $r_length  = trim($r['length']  ?? '');
            $r_product = trim($r['product'] ?? '');

            if ($r_lot === '' || $r_coil === '' || $r_grade === '' || $r_width === '' || $r_length === '' || $r_product === '') {
                $skipped[] = ['row' => $i + 1, 'reason' => 'Missing required field'];
                continue;
            }

            $dup_stmt = $this->mysqli->prepare("SELECT id FROM mother_coil WHERE coil_no = ? AND lot_no = ?");
            $dup_stmt->bind_param("ss", $r_coil, $r_lot);
            $dup_stmt->execute();
            $dup_res = $dup_stmt->get_result();
            if ($dup_res->num_rows > 0) {
                $skipped[] = ['row' => $i + 1, 'reason' => "Duplicate Lot $r_lot / Coil $r_coil"];
                $dup_stmt->close();
                continue;
            }
            $dup_stmt->close();

            $stmt = $this->mysqli->prepare("
                INSERT INTO mother_coil (product, grade, lot_no, coil_no, width, length, date_created, status)
                VALUES (?,?,?,?,?,?,NOW(),'NEW')
            ");
            $stmt->bind_param("ssssss", $r_product, $r_grade, $r_lot, $r_coil, $r_width, $r_length);
            $stmt->execute();
            $newMotherId = $this->mysqli->insert_id;
            $stmt->close();
            $inserted++;

            if ($planStmt && !empty($sharedPlanRows)) {
                $order = 0;
                foreach ($sharedPlanRows as $pr) {
                    $order++;
                    $cust = $this->sanitizeCustomerCode($pr['customer'] ?? '');
                    $wVal = (float)$pr['width'];
                    $planStmt->bind_param("isdssi", $newMotherId, $pr['seq'], $wVal, $cust, $pr['ref_no'], $order);
                    $planStmt->execute();
                }
            }
        }

        if ($planStmt) $planStmt->close();

        return ['ok' => true, 'inserted' => $inserted, 'skipped' => $skipped];
    }

    /**
     * Update mother coil and cascade changes downstream
     */
    public function updateRecord(int $id, array $data, array $planRows = []): int
    {
        $curStmt = $this->mysqli->prepare("SELECT lot_no, coil_no, product FROM mother_coil WHERE id = ?");
        $curStmt->bind_param("i", $id);
        $curStmt->execute();
        $curRow = $curStmt->get_result()->fetch_assoc();
        $curStmt->close();

        if (!$curRow) {
            throw new Exception("Mother coil not found.");
        }

        $oldLotNo       = $curRow['lot_no'];
        $oldCoilNo      = $curRow['coil_no'];
        $oldProduct     = $curRow['product'];

        $product = trim($data['product'] ?? '');
        $grade   = trim($data['grade'] ?? '');
        $lot_no  = trim($data['lot_no'] ?? '');
        $coil_no = trim($data['coil_no'] ?? '');
        $width   = trim($data['width'] ?? '');
        $length  = trim($data['length'] ?? '');

        $lotNoChanged   = ($oldLotNo   !== $lot_no);
        $coilNoChanged  = ($oldCoilNo  !== $coil_no);
        $productChanged = ($oldProduct !== $product);

        $this->mysqli->begin_transaction();
        try {
            // 1. Update mother_coil
            $stmt = $this->mysqli->prepare("
                UPDATE mother_coil
                SET product=?, grade=?, lot_no=?, coil_no=?, width=?, length=?
                WHERE id=?
            ");
            $stmt->bind_param("ssssssi", $product, $grade, $lot_no, $coil_no, $width, $length, $id);
            if (!$stmt->execute()) {
                throw new Exception("Failed to update mother coil: " . $stmt->error);
            }
            $stmt->close();

            // 2. Update slitting_plans
            $delPlan = $this->mysqli->prepare("DELETE FROM slitting_plans WHERE mother_coil_id = ?");
            if ($delPlan) {
                $delPlan->bind_param("i", $id);
                $delPlan->execute();
                $delPlan->close();
            }

            if (!empty($planRows)) {
                $insPlan = $this->mysqli->prepare("
                    INSERT INTO slitting_plans (mother_coil_id, roll_seq, planned_width, customer_name, ref_no, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $order = 0;
                foreach ($planRows as $pr) {
                    $seq   = trim($pr['seq'] ?? '');
                    $wRaw  = trim($pr['width'] ?? '');
                    $cRaw  = trim($pr['customer'] ?? '');
                    $refNo = trim($pr['ref_no'] ?? '');
                    if ($seq === '' || $wRaw === '' || !is_numeric($wRaw)) continue;

                    $order++;
                    $widthVal = (float)$wRaw;
                    $cust = $this->sanitizeCustomerCode($cRaw);
                    $insPlan->bind_param("isdssi", $id, $seq, $widthVal, $cust, $refNo, $order);
                    $insPlan->execute();
                }
                $insPlan->close();
            }

            // 3. Cascade updates to slitting_product
            $childrenUpdated = 0;
            if ($lotNoChanged || $coilNoChanged || $productChanged) {
                $childStmt = $this->mysqli->prepare("SELECT id, lot_no, product FROM slitting_product WHERE mother_id = ?");
                $childStmt->bind_param("i", $id);
                $childStmt->execute();
                $childRows = $childStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $childStmt->close();

                $updChild = $this->mysqli->prepare("UPDATE slitting_product SET lot_no = ?, coil_no = ?, product = ? WHERE id = ?");
                foreach ($childRows as $child) {
                    $childLot    = $child['lot_no'] ?? '';
                    $newChildLot = $childLot;

                    if ($lotNoChanged && strpos($childLot, $oldLotNo) === 0) {
                        $suffix      = substr($childLot, strlen($oldLotNo));
                        $newChildLot = $lot_no . $suffix;
                    }

                    $newCoil = $coilNoChanged ? $coil_no : $curRow['coil_no'];
                    $newProd = $productChanged ? $product : $child['product'];

                    $updChild->bind_param("sssi", $newChildLot, $newCoil, $newProd, $child['id']);
                    $updChild->execute();
                    $childrenUpdated++;
                }
                $updChild->close();
            }

            // 4. Cascade updates to stock_raw_material
            if ($coilNoChanged || $lotNoChanged) {
                $stStmt = $this->mysqli->prepare("SELECT id, lot_no FROM stock_raw_material WHERE source_id = ?");
                if ($stStmt) {
                    $stStmt->bind_param("i", $id);
                    $stStmt->execute();
                    $stRows = $stStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stStmt->close();

                    $stUpd = $this->mysqli->prepare("UPDATE stock_raw_material SET coil_no = ?, lot_no = ? WHERE id = ?");
                    foreach ($stRows as $stRow) {
                        $stLot = $stRow['lot_no'] ?? '';
                        $newStLot = $stLot;
                        if ($lotNoChanged && strpos($stLot, $oldLotNo) === 0) {
                            $suffix   = substr($stLot, strlen($oldLotNo));
                            $newStLot = $lot_no . $suffix;
                        }
                        $stUpd->bind_param("ssi", $coil_no, $newStLot, $stRow['id']);
                        $stUpd->execute();
                    }
                    $stUpd->close();
                }
            }

            // 5. Cascade updates to sfc
            if ($coilNoChanged || $lotNoChanged || $productChanged) {
                $sfcStmt = $this->mysqli->prepare("SELECT sfc_id, lot_no FROM sfc WHERE mother_id = ?");
                if ($sfcStmt) {
                    $sfcStmt->bind_param("i", $id);
                    $sfcStmt->execute();
                    $sfcRows = $sfcStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $sfcStmt->close();

                    $sfcUpd = $this->mysqli->prepare("UPDATE sfc SET coil_no = ?, product = ?, lot_no = ? WHERE sfc_id = ?");
                    foreach ($sfcRows as $sfcRow) {
                        $sfcLot = $sfcRow['lot_no'] ?? '';
                        $newSfcLot = $sfcLot;
                        if ($lotNoChanged && strpos($sfcLot, $oldLotNo) === 0) {
                            $suffix    = substr($sfcLot, strlen($oldLotNo));
                            $newSfcLot = $lot_no . $suffix;
                        }
                        $sfcUpd->bind_param("sssi", $coil_no, $product, $newSfcLot, $sfcRow['sfc_id']);
                        $sfcUpd->execute();
                    }
                    $sfcUpd->close();
                }
            }

            // 6. Cascade updates to recoiling_product
            if ($coilNoChanged || $lotNoChanged || $productChanged) {
                $recStmt = $this->mysqli->prepare("SELECT id, lot_no FROM recoiling_product WHERE mother_id = ?");
                if ($recStmt) {
                    $recStmt->bind_param("i", $id);
                    $recStmt->execute();
                    $recRows = $recStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $recStmt->close();

                    $recUpd = $this->mysqli->prepare("UPDATE recoiling_product SET coil_no = ?, product = ?, lot_no = ? WHERE id = ?");
                    foreach ($recRows as $recRow) {
                        $recLot = $recRow['lot_no'] ?? '';
                        $newRecLot = $recLot;
                        if ($lotNoChanged && strpos($recLot, $oldLotNo) === 0) {
                            $suffix    = substr($recLot, strlen($oldLotNo));
                            $newRecLot = $lot_no . $suffix;
                        }
                        $recUpd->bind_param("sssi", $coil_no, $product, $newRecLot, $recRow['id']);
                        $recUpd->execute();
                    }
                    $recUpd->close();
                }
            }

            // 7. Cascade updates to reslit_product
            if ($coilNoChanged || $lotNoChanged || $productChanged) {
                $resStmt = $this->mysqli->prepare("SELECT id, lot_no FROM reslit_product WHERE mother_id = ?");
                if ($resStmt) {
                    $resStmt->bind_param("i", $id);
                    $resStmt->execute();
                    $resRows = $resStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $resStmt->close();

                    $resUpd = $this->mysqli->prepare("UPDATE reslit_product SET coil_no = ?, product = ?, lot_no = ? WHERE id = ?");
                    foreach ($resRows as $resRow) {
                        $resLot = $resRow['lot_no'] ?? '';
                        $newResLot = $resLot;
                        if ($lotNoChanged && strpos($resLot, $oldLotNo) === 0) {
                            $suffix    = substr($resLot, strlen($oldLotNo));
                            $newResLot = $lot_no . $suffix;
                        }
                        $resUpd->bind_param("sssi", $coil_no, $product, $newResLot, $resRow['id']);
                        $resUpd->execute();
                    }
                    $resUpd->close();
                }
            }

            $this->mysqli->commit();
            return $childrenUpdated;
        } catch (Throwable $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }

    /**
     * Delete mother coil and all its children across the DB
     */
    public function deleteRecord(int $id): bool
    {
        $mother = $this->findById($id);
        if (!$mother) {
            return false;
        }

        $this->mysqli->begin_transaction();
        try {
            // Find all slitting_product IDs belonging to this mother coil
            $sp_ids = [];
            $sp_res = $this->mysqli->query("SELECT id FROM slitting_product WHERE mother_id = $id");
            if ($sp_res) {
                while ($r = $sp_res->fetch_assoc()) $sp_ids[] = (int)$r['id'];
                $sp_res->free();
            }

            if (!empty($sp_ids)) {
                $in_sp = implode(',', $sp_ids);

                // recoiling output rows
                $rec_ids = [];
                $r_res = $this->mysqli->query("SELECT id FROM recoiling_product WHERE slitting_product_id IN ($in_sp)");
                if ($r_res) {
                    while ($r = $r_res->fetch_assoc()) $rec_ids[] = (int)$r['id'];
                    $r_res->free();
                }
                if (!empty($rec_ids)) {
                    $in_rec = implode(',', $rec_ids);
                    $this->mysqli->query("DELETE FROM slitting_product WHERE recoiling_id IN ($in_rec)");
                    $this->mysqli->query("DELETE FROM recoiling_product WHERE id IN ($in_rec)");
                }

                // reslit_product
                $res_ids = [];
                $res_r = $this->mysqli->query("SELECT id FROM reslit_product WHERE slitting_product_id IN ($in_sp)");
                if ($res_r) {
                    while ($r = $res_r->fetch_assoc()) $res_ids[] = (int)$r['id'];
                    $res_r->free();
                }
                if (!empty($res_ids)) {
                    $in_reslit = implode(',', $res_ids);
                    $this->mysqli->query("DELETE FROM reslit_product WHERE id IN ($in_reslit)");
                }

                $this->mysqli->query("DELETE FROM source_tracking_log WHERE product_id IN ($in_sp)");
                $this->mysqli->query("DELETE FROM slitting_product WHERE id IN ($in_sp)");
            }

            $this->mysqli->query("DELETE FROM slitting_plans WHERE mother_coil_id = $id");
            $this->mysqli->query("DELETE FROM slitting_audit_log WHERE mother_id = $id");
            $this->mysqli->query("DELETE FROM sfc WHERE mother_id = $id");
            $this->mysqli->query("DELETE FROM process_log WHERE mother_id = $id");
            $this->mysqli->query("DELETE FROM mother_coil WHERE id = $id");

            $this->mysqli->commit();
            return true;
        } catch (Throwable $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }

    /**
     * Mark printed timestamp
     */
    public function markPrinted(int $id): void
    {
        $stmt = $this->mysqli->prepare("UPDATE mother_coil SET printed_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }
}

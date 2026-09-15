<?php

namespace App\Models;

use App\Core\Model;
use mysqli;
use RuntimeException;
use Throwable;

class QC extends Model
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Ensure coil_width column exists in pallet_items table
     */
    public function ensureCoilWidthColumn(): void
    {
        $colCheck = $this->mysqli->query("SHOW COLUMNS FROM pallet_items LIKE 'coil_width'");
        if ($colCheck && $colCheck->num_rows === 0) {
            $this->mysqli->query("ALTER TABLE pallet_items ADD COLUMN coil_width TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Coil Width checkbox was ticked by QC inspector' AFTER hairy_rubber");
        }
    }

    /**
     * Fetch all active inspectors
     */
    public function getActiveInspectors(): array
    {
        $stmt = $this->mysqli->prepare("SELECT id, name FROM qc_inspectors WHERE is_active = 1 ORDER BY name ASC");
        if (!$stmt) return [];
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Fetch all inspectors (active and inactive)
     */
    public function getAllInspectors(): array
    {
        $stmt = $this->mysqli->prepare("SELECT id, name, is_active, created_at FROM qc_inspectors ORDER BY is_active DESC, name ASC");
        if (!$stmt) return [];
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Add new QC inspector
     */
    public function addInspector(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'msg' => 'Name cannot be empty.'];
        }
        if (mb_strlen($name) > 100) {
            return ['ok' => false, 'msg' => 'Name is too long (max 100 characters).'];
        }

        $chk = $this->mysqli->prepare("SELECT id, is_active FROM qc_inspectors WHERE LOWER(name) = LOWER(?)");
        $chk->bind_param("s", $name);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($existing) {
            if ((int)$existing['is_active'] === 1) {
                return ['ok' => false, 'msg' => "Inspector \"$name\" already exists."];
            } else {
                $upd = $this->mysqli->prepare("UPDATE qc_inspectors SET is_active = 1, name = ? WHERE id = ?");
                $upd->bind_param("si", $name, $existing['id']);
                $upd->execute();
                $upd->close();
                return [
                    'ok'   => true,
                    'msg'  => "Inspector \"$name\" restored.",
                    'id'   => $existing['id'],
                    'name' => $name,
                ];
            }
        }

        $ins = $this->mysqli->prepare("INSERT INTO qc_inspectors (name) VALUES (?)");
        $ins->bind_param("s", $name);
        $ok = $ins->execute();
        $newId = $this->mysqli->insert_id;
        $ins->close();

        return [
            'ok'   => $ok,
            'msg'  => $ok ? "Inspector \"$name\" added." : 'Database error: ' . $this->mysqli->error,
            'id'   => $newId,
            'name' => $name,
        ];
    }

    /**
     * Soft-delete QC inspector
     */
    public function deleteInspector(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'msg' => 'Invalid ID.'];
        }

        $stmt = $this->mysqli->prepare("UPDATE qc_inspectors SET is_active = 0 WHERE id = ? AND is_active = 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return [
            'ok'  => $affected > 0,
            'msg' => $affected > 0 ? 'Inspector removed from dropdown.' : 'Inspector not found or already removed.',
        ];
    }

    /**
     * Edit QC inspector
     */
    public function editInspector(int $id, string $name): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'msg' => 'Invalid ID.'];
        }
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'msg' => 'Name cannot be empty.'];
        }
        if (mb_strlen($name) > 100) {
            return ['ok' => false, 'msg' => 'Name is too long (max 100 characters).'];
        }

        $chk = $this->mysqli->prepare("SELECT id FROM qc_inspectors WHERE LOWER(name) = LOWER(?) AND id != ?");
        $chk->bind_param("si", $name, $id);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($existing) {
            return ['ok' => false, 'msg' => "Inspector name \"$name\" is already in use."];
        }

        $oldStmt = $this->mysqli->prepare("SELECT name FROM qc_inspectors WHERE id = ?");
        $oldStmt->bind_param("i", $id);
        $oldStmt->execute();
        $oldRow = $oldStmt->get_result()->fetch_assoc();
        $oldStmt->close();
        $oldName = $oldRow['name'] ?? '';

        $upd = $this->mysqli->prepare("UPDATE qc_inspectors SET name = ? WHERE id = ?");
        $upd->bind_param("si", $name, $id);
        $ok = $upd->execute();
        $upd->close();

        if ($ok && isset($_SESSION['active_qc_inspector']) && $_SESSION['active_qc_inspector'] === $oldName) {
            $_SESSION['active_qc_inspector'] = $name;
        }

        return [
            'ok'   => $ok,
            'msg'  => $ok ? "Inspector renamed to \"$name\"." : 'Database error: ' . $this->mysqli->error,
            'id'   => $id,
            'name' => $name,
        ];
    }

    /**
     * Get pallet items for QC dashboard
     */
    public function getPalletItems(int $palletId): array
    {
        $stmt = $this->mysqli->prepare("
            SELECT pi.seq, pi.added_at, pi.stock_code,
                   sp.id AS product_id,
                   sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
                   sp.width, sp.length, sp.actual_length, sp.status,
                   sp.nod_length,
                   COALESCE(sw.std_weight, 0) AS std_weight
            FROM pallet_items pi
            JOIN slitting_product sp ON sp.id = pi.slitting_product_id
            LEFT JOIN std_wgt sw ON sw.product_code = sp.product
            WHERE pi.pallet_id = ?
            ORDER BY pi.seq ASC
        ");
        $stmt->bind_param("i", $palletId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public static function formatWidthDisplay($val): string
    {
        if ($val === null || $val === '') return '-';
        $f = (float)$val;
        return ($f == (int)$f) ? number_format($f, 0) : (string)$f;
    }

    public static function calcEstWeight(float $l, float $w, float $s): float
    {
        if ($l <= 0 || $w <= 0 || $s <= 0) return 0.0;
        return ($l * $w / 1000) * $s;
    }

    /**
     * Get Pending QC Pallets with items, weights, and nod counts
     */
    public function getPendingQcPallets(?string $filterDate = null): array
    {
        $this->ensureCoilWidthColumn();

        $where  = "WHERE p.status = 'pending_qc'";
        $params = [];
        $types  = '';

        if ($filterDate) {
            $where .= " AND DATE(p.updated_at) = ?";
            $params[] = $filterDate;
            $types   .= 's';
        }

        $sql = "
            SELECT p.id, p.pallet_no, p.status,
                   p.customer_name, p.ref_no, p.product_type, p.width,
                   p.created_at, p.updated_at,
                   COUNT(pi.id) AS roll_count
            FROM pallets p
            JOIN pallet_items pi ON pi.pallet_id = p.id
            $where
            GROUP BY p.id
            ORDER BY p.updated_at DESC
        ";

        if (!empty($params)) {
            $stmt = $this->mysqli->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $stmt->close();
        } else {
            $result = $this->mysqli->query($sql);
        }

        $pallets = [];
        if ($result) {
            require_once __DIR__ . '/../../PalletManager.php';
            while ($row = $result->fetch_assoc()) {
                $items        = $this->getPalletItems((int)$row['id']);
                $totalWgt     = 0.0;
                $nodCoilCount = 0;
                foreach ($items as &$item) {
                    $len      = (float)($item['actual_length'] ?: $item['length']);
                    $totalWgt += self::calcEstWeight($len, (float)$item['width'], (float)$item['std_weight']);
                    $nodLen   = 0.0;
                    if (!empty($item['nod_length']) && (float)$item['nod_length'] > 0) {
                        $nodCoilCount++;
                        $nodLen = (float)$item['nod_length'];
                    }
                    $netLen = max(0.0, $len - $nodLen);
                    $item['stock_code'] = \PalletManager::formatStockCode($item['coil_no'], $item['width'], $netLen);
                }
                unset($item);
                $row['items']          = $items;
                $row['total_wgt']      = $totalWgt;
                $row['nod_coil_count'] = $nodCoilCount;
                $pallets[]             = $row;
            }
        }
        return $pallets;
    }

    /**
     * Barcode scan lookup for QC Dashboard
     */
    public function scanLookup(string $rawInput): array
    {
        $qr = preg_replace('/[[:cntrl:]]/', '', $rawInput);
        $qr = preg_replace('/^\][A-Za-z][0-9]/', '', $qr);
        $qr = preg_replace('/^[^\w]{0,6}(?=LOT=|COIL=|ROLL=)/i', '', $qr);
        $qr = preg_replace('/^[\w]{1,6}(?=LOT=|COIL=|ROLL=)/i',  '', $qr);
        $qr = trim($qr);

        if ($qr === '') {
            return ['ok' => false, 'msg' => 'Empty scan input.'];
        }

        $lot    = '';
        $coil   = '';
        $roll   = '';
        $pallet = '';

        if (strpos($qr, '=') !== false) {
            $pairs = [];
            foreach (explode(';', $qr) as $segment) {
                $segment = trim($segment);
                if (strpos($segment, '=') === false) continue;
                [$k, $v] = explode('=', $segment, 2);
                $pairs[strtoupper(trim($k))] = trim($v);
            }
            $lot  = $pairs['LOT']  ?? '';
            $coil = $pairs['COIL'] ?? '';
            $roll = $pairs['ROLL'] ?? '';
        } else {
            $tokens = preg_split('/\s+/', $qr);
            if (count($tokens) >= 2) {
                $lot  = trim($tokens[0] ?? '');
                $coil = trim($tokens[1] ?? '');
                $roll = trim($tokens[2] ?? '');
            } else {
                $pallet = $qr;
            }
        }

        if ($lot !== '' && $coil !== '') {
            $sql = "
                SELECT sp.id AS slitting_product_id, sp.lot_no, sp.coil_no, sp.roll_no, sp.product,
                       sp.status AS product_status,
                       p.id AS pallet_id, p.pallet_no, p.status AS pallet_status
                FROM slitting_product sp
                LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                LEFT JOIN pallets p       ON p.id = pi.pallet_id
                WHERE sp.lot_no  = ?
                  AND sp.coil_no = ?
            ";
            if ($roll !== '') {
                $sql .= " AND (sp.roll_no = ? OR sp.roll_no = ?)";
                $rollWithR = (strpos($roll, 'R') === false) ? 'R' . $roll : $roll;
                $rollWithoutR = ltrim($roll, 'R-');
            }
            $sql .= " ORDER BY sp.id DESC LIMIT 1";

            $stmt = $this->mysqli->prepare($sql);
            if ($roll !== '') {
                $stmt->bind_param("ssss", $lot, $coil, $rollWithR, $rollWithoutR);
            } else {
                $stmt->bind_param("ss", $lot, $coil);
            }
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($res) {
                if ($res['pallet_id'] && $res['pallet_status'] === 'pending_qc') {
                    return [
                        'ok'                  => true,
                        'match_type'          => 'product',
                        'pallet_id'           => (int)$res['pallet_id'],
                        'pallet_no'           => $res['pallet_no'],
                        'slitting_product_id' => (int)$res['slitting_product_id'],
                        'lot_no'              => $res['lot_no'],
                        'coil_no'             => $res['coil_no'],
                        'roll_no'             => $res['roll_no'],
                        'product'             => $res['product'],
                        'pallet_status'       => $res['pallet_status'],
                    ];
                } elseif ($res['pallet_id']) {
                    return [
                        'ok'            => false,
                        'reason'        => 'already_processed',
                        'msg'           => "Pallet {$res['pallet_no']} is already processed (Status: " . strtoupper($res['pallet_status']) . ").",
                        'pallet_no'     => $res['pallet_no'],
                        'pallet_status' => $res['pallet_status'],
                    ];
                } else {
                    return [
                        'ok'     => false,
                        'reason' => 'not_on_pallet',
                        'msg'    => "Coil {$res['lot_no']} {$res['coil_no']} is not assigned to any pending pallet.",
                    ];
                }
            }
        }

        // Option 2: Lookup by Pallet Number
        $searchPallet = ($pallet !== '') ? $pallet : $qr;
        $stmtP = $this->mysqli->prepare("
            SELECT id AS pallet_id, pallet_no, status AS pallet_status
            FROM pallets
            WHERE pallet_no = ?
            LIMIT 1
        ");
        $stmtP->bind_param("s", $searchPallet);
        $stmtP->execute();
        $pRes = $stmtP->get_result()->fetch_assoc();
        $stmtP->close();

        if ($pRes) {
            if ($pRes['pallet_status'] === 'pending_qc') {
                return [
                    'ok'            => true,
                    'match_type'    => 'pallet',
                    'pallet_id'     => (int)$pRes['pallet_id'],
                    'pallet_no'     => $pRes['pallet_no'],
                    'pallet_status' => $pRes['pallet_status'],
                ];
            } else {
                return [
                    'ok'            => false,
                    'reason'        => 'already_processed',
                    'msg'           => "Pallet {$pRes['pallet_no']} is already processed (Status: " . strtoupper($pRes['pallet_status']) . ").",
                    'pallet_no'     => $pRes['pallet_no'],
                    'pallet_status' => $pRes['pallet_status'],
                ];
            }
        }

        return [
            'ok'     => false,
            'reason' => 'not_found',
            'msg'    => 'No pending QC record found matching barcode: ' . htmlspecialchars($rawInput),
        ];
    }

    /**
     * Get QC Log records (pallets + rolls)
     */
    public function getQcLog(string $filterStatus = 'all', string $search = '', string $dateFrom = '', string $dateTo = ''): array
    {
        $this->ensureCoilWidthColumn();

        $where  = "WHERE p.status IN ('approved','rejected','delivered')";
        $params = [];
        $types  = '';

        if ($filterStatus === 'approved') { $where .= " AND p.status = 'approved'"; }
        elseif ($filterStatus === 'rejected') { $where .= " AND p.status = 'rejected'"; }
        elseif ($filterStatus === 'delivered') { $where .= " AND p.status = 'delivered'"; }

        if ($search !== '') {
            $where   .= " AND (p.pallet_no LIKE ? OR p.customer_name LIKE ? OR p.product_type LIKE ? OR p.checked_by LIKE ?)";
            $like     = '%' . $search . '%';
            $params   = array_merge($params, [$like,$like,$like,$like]);
            $types   .= 'ssss';
        }
        if ($dateFrom !== '') { $where .= " AND DATE(p.updated_at) >= ?"; $params[] = $dateFrom; $types .= 's'; }
        if ($dateTo   !== '') { $where .= " AND DATE(p.updated_at) <= ?"; $params[] = $dateTo;   $types .= 's'; }

        $sql = "
            SELECT p.id AS pallet_id, p.pallet_no, p.status AS pallet_status,
                   p.customer_name, p.ref_no, p.product_type, p.width AS pallet_width,
                   p.qc_comment AS rejection_reason, p.checked_by, p.updated_at AS qc_datetime,
                   COUNT(pi.id) AS total_rolls
            FROM pallets p
            LEFT JOIN pallet_items pi ON pi.pallet_id = p.id
            $where
            GROUP BY p.id
            ORDER BY p.updated_at DESC
        ";
        $stmt = $this->mysqli->prepare($sql);
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $palletRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $rollsByPallet = [];
        $palletIds = array_column($palletRows, 'pallet_id');

        if (!empty($palletIds)) {
            $ph      = implode(',', array_fill(0, count($palletIds), '?'));
            $phTypes = str_repeat('i', count($palletIds));

            $rollStmt = $this->mysqli->prepare("
                SELECT
                    pi.pallet_id,
                    pi.seq,
                    pi.winding_condition,
                    pi.hairy_rubber,
                    pi.coil_width,
                    pi.qc_checked_at,
                    sp.id           AS product_id,
                    sp.lot_no,
                    sp.coil_no,
                    sp.roll_no,
                    sp.product,
                    sp.width,
                    sp.length,
                    sp.actual_length,
                    sp.status       AS roll_status,
                    sp.qc_comment   AS roll_rejection,
                    sp.date_in
                FROM pallet_items pi
                JOIN slitting_product sp ON sp.id = pi.slitting_product_id
                WHERE pi.pallet_id IN ($ph)
                ORDER BY pi.pallet_id ASC, pi.seq ASC
            ");
            $rollStmt->bind_param($phTypes, ...$palletIds);
            $rollStmt->execute();
            $rollRows = $rollStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $rollStmt->close();

            foreach ($rollRows as $rr) {
                $rollsByPallet[$rr['pallet_id']][] = $rr;
            }
        }

        return [
            'palletRows'    => $palletRows,
            'rollsByPallet' => $rollsByPallet,
        ];
    }

    /**
     * Get QC Log rows for Excel Export
     */
    public function getQcLogExportData(string $filterStatus = 'all', string $search = '', string $dateFrom = '', string $dateTo = ''): array
    {
        $this->ensureCoilWidthColumn();

        $where  = "WHERE p.status IN ('approved','rejected','delivered')";
        $params = [];
        $types  = '';

        if ($filterStatus === 'approved') { $where .= " AND p.status = 'approved'"; }
        elseif ($filterStatus === 'rejected') { $where .= " AND p.status = 'rejected'"; }
        elseif ($filterStatus === 'delivered') { $where .= " AND p.status = 'delivered'"; }

        if ($search !== '') {
            $where .= " AND (p.pallet_no LIKE ? OR p.customer_name LIKE ? OR p.product_type LIKE ? OR p.checked_by LIKE ?)";
            $like   = '%' . $search . '%';
            $params = array_merge($params, [$like,$like,$like,$like]);
            $types .= 'ssss';
        }
        if ($dateFrom !== '') { $where .= " AND DATE(p.updated_at) >= ?"; $params[] = $dateFrom; $types .= 's'; }
        if ($dateTo   !== '') { $where .= " AND DATE(p.updated_at) <= ?"; $params[] = $dateTo;   $types .= 's'; }

        $sql = "
            SELECT
                p.id                                    AS pallet_id,
                p.pallet_no,
                p.updated_at                            AS qc_datetime,
                sp.product,
                p.customer_name,
                sp.lot_no, sp.coil_no, sp.roll_no,
                COALESCE(sp.actual_length, sp.length)   AS qty_m,
                sp.width,
                pi.winding_condition,
                pi.hairy_rubber,
                pi.coil_width,
                p.checked_by,
                p.status                                AS pallet_status,
                pi.seq,
                pallet_totals.total_rolls
            FROM pallets p
            JOIN pallet_items pi     ON pi.pallet_id          = p.id
            JOIN slitting_product sp ON sp.id                 = pi.slitting_product_id
            JOIN (
                SELECT pallet_id, COUNT(*) AS total_rolls
                FROM pallet_items
                GROUP BY pallet_id
            ) pallet_totals ON pallet_totals.pallet_id = p.id
            $where
            ORDER BY p.updated_at DESC, p.id ASC, pi.seq ASC
        ";

        $stmt = $this->mysqli->prepare($sql);
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * Get QC KPI metrics
     */
    public function getKpis(): array
    {
        $kpiStmt = $this->mysqli->prepare("SELECT status, COUNT(*) AS total FROM pallets WHERE status IN ('approved','rejected','delivered') GROUP BY status");
        if (!$kpiStmt) return ['total_approved' => 0, 'total_rejected' => 0, 'total_delivered' => 0, 'total_passed' => 0, 'total' => 0, 'pass_rate' => 0];
        $kpiStmt->execute();
        $total_approved = 0; $total_rejected = 0; $total_delivered = 0;
        foreach ($kpiStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $k) {
            if ($k['status'] === 'approved')  $total_approved  = (int)$k['total'];
            if ($k['status'] === 'rejected')  $total_rejected  = (int)$k['total'];
            if ($k['status'] === 'delivered') $total_delivered = (int)$k['total'];
        }
        $kpiStmt->close();
        $total_passed = $total_approved + $total_delivered;
        $total        = $total_passed + $total_rejected;
        $pass_rate    = $total > 0 ? round(($total_passed / $total) * 100, 1) : 0;
        return [
            'total_approved'  => $total_approved,
            'total_rejected'  => $total_rejected,
            'total_delivered' => $total_delivered,
            'total_passed'    => $total_passed,
            'total'           => $total,
            'pass_rate'       => $pass_rate,
        ];
    }

    /**
     * Legacy single roll approval / rejection
     */
    public function processRoll(int $id, string $action, string $comment = ''): bool
    {
        if ($id <= 0) return false;

        if ($action === 'approve') {
            $stmt = $this->mysqli->prepare("UPDATE slitting_product SET status='APPROVED', qc_comment=NULL WHERE id=? AND status='WAITING'");
            $stmt->bind_param("i", $id);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        } elseif ($action === 'reject') {
            $stmt = $this->mysqli->prepare("UPDATE slitting_product SET status='REJECTED', qc_comment=? WHERE id=? AND status='WAITING'");
            $stmt->bind_param("si", $comment, $id);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }
        return false;
    }
}

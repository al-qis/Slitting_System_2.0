<?php

namespace App\Models;

use App\Core\Model;
use App\Core\Database;
use mysqli;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../../PalletManager.php';

class Pallet extends Model
{
    private ?\PalletManager $palletManager = null;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get or instantiate PalletManager domain service
     */
    public function getManager(string $actor = 'slitting'): \PalletManager
    {
        if ($this->palletManager === null) {
            $this->palletManager = new \PalletManager($this->mysqli, $actor);
        }
        return $this->palletManager;
    }

    /**
     * Ensure operators table exists
     */
    public function ensureOperatorsTable(): void
    {
        $this->mysqli->query("
            CREATE TABLE IF NOT EXISTS operators (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL UNIQUE,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * Fetch active operators
     */
    public function getOperators(): array
    {
        $operators = [];
        $res = $this->mysqli->query("SELECT id, name FROM operators WHERE is_active = 1 ORDER BY name ASC");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $operators[] = $r;
            }
        }
        return $operators;
    }

    /**
     * Set active operator on building pallet
     */
    public function setActiveOperator(string $opName, int $palletId): bool
    {
        if ($palletId > 0 && $opName !== '') {
            $stmt = $this->mysqli->prepare("UPDATE pallets SET created_by = ? WHERE id = ? AND status = 'building'");
            if ($stmt) {
                $stmt->bind_param("si", $opName, $palletId);
                $stmt->execute();
                $stmt->close();
                return true;
            }
        }
        return false;
    }

    /**
     * Add new operator
     */
    public function addOperator(string $opName): array
    {
        $opName = trim($opName);
        if ($opName === '') {
            return ['ok' => false, 'msg' => 'Operator name cannot be empty.'];
        }

        $stmt = $this->mysqli->prepare("INSERT INTO operators (name, is_active) VALUES (?, 1) ON DUPLICATE KEY UPDATE is_active = 1");
        if ($stmt) {
            $stmt->bind_param("s", $opName);
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok) {
                return ['ok' => true, 'name' => $opName, 'msg' => 'Operator added successfully.'];
            }
        }
        return ['ok' => false, 'msg' => 'Failed to save operator.'];
    }

    /**
     * Product lookup for pallet scanning
     */
    public function lookupProduct(int $id, string $lot, string $coil, string $roll, float $width): ?array
    {
        if ($id <= 0 && !$roll && (!$lot || !$coil)) {
            return null;
        }

        if ($id > 0) {
            $stmt = $this->mysqli->prepare("
                SELECT sp.id, sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
                       sp.width, sp.actual_length, sp.length, sp.nod_length,
                       sp.stock_counted, sp.status, sp.is_voided,
                       sp.customer_name, sp.ref_no,
                       pi.pallet_id, p.pallet_no,
                       COALESCE(sw.std_weight, 0) AS std_weight
                FROM slitting_product sp
                LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                LEFT JOIN pallets p       ON p.id = pi.pallet_id
                LEFT JOIN std_wgt sw      ON sw.product_code = sp.product
                WHERE sp.id = ? AND (sp.is_voided = 0 OR sp.is_voided IS NULL)
                LIMIT 1
            ");
            $stmt->bind_param("i", $id);
        } elseif ($lot !== '' && $coil !== '' && $width > 0) {
            $stmt = $this->mysqli->prepare("
                SELECT sp.id, sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
                       sp.width, sp.actual_length, sp.length, sp.nod_length,
                       sp.stock_counted, sp.status, sp.is_voided,
                       sp.customer_name, sp.ref_no,
                       pi.pallet_id, p.pallet_no,
                       COALESCE(sw.std_weight, 0) AS std_weight
                FROM slitting_product sp
                LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                LEFT JOIN pallets p       ON p.id = pi.pallet_id
                LEFT JOIN std_wgt sw      ON sw.product_code = sp.product
                WHERE sp.lot_no = ? AND sp.coil_no = ? AND sp.roll_no = ?
                  AND ABS(sp.width - ?) < 0.5
                  AND (sp.is_voided = 0 OR sp.is_voided IS NULL)
                ORDER BY (pi.pallet_id IS NULL) DESC, sp.id DESC
                LIMIT 1
            ");
            $stmt->bind_param("sssd", $lot, $coil, $roll, $width);
        } elseif ($width > 0 && $roll !== '') {
            $stmt = $this->mysqli->prepare("
                SELECT sp.id, sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
                       sp.width, sp.actual_length, sp.length, sp.nod_length,
                       sp.stock_counted, sp.status, sp.is_voided,
                       sp.customer_name, sp.ref_no,
                       pi.pallet_id, p.pallet_no,
                       COALESCE(sw.std_weight, 0) AS std_weight
                FROM slitting_product sp
                LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                LEFT JOIN pallets p       ON p.id = pi.pallet_id
                LEFT JOIN std_wgt sw      ON sw.product_code = sp.product
                WHERE sp.roll_no = ?
                  AND ABS(sp.width - ?) < 0.5
                  AND (sp.is_voided = 0 OR sp.is_voided IS NULL)
                ORDER BY (pi.pallet_id IS NULL) DESC, sp.id DESC
                LIMIT 1
            ");
            $stmt->bind_param("sd", $roll, $width);
        } else {
            $stmt = $this->mysqli->prepare("
                SELECT sp.id, sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
                       sp.width, sp.actual_length, sp.length, sp.nod_length,
                       sp.stock_counted, sp.status, sp.is_voided,
                       sp.customer_name, sp.ref_no,
                       pi.pallet_id, p.pallet_no,
                       COALESCE(sw.std_weight, 0) AS std_weight
                FROM slitting_product sp
                LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                LEFT JOIN pallets p       ON p.id = pi.pallet_id
                LEFT JOIN std_wgt sw      ON sw.product_code = sp.product
                WHERE sp.lot_no = ? AND sp.coil_no = ? AND sp.roll_no = ?
                  AND (sp.is_voided = 0 OR sp.is_voided IS NULL)
                ORDER BY (pi.pallet_id IS NULL) DESC, sp.id DESC
                LIMIT 1
            ");
            $stmt->bind_param("sss", $lot, $coil, $roll);
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) return null;

        $rawLen = (!empty($row['actual_length']) && $row['actual_length'] > 0) ? (float)$row['actual_length'] : (float)$row['length'];
        $nodLen = !empty($row['nod_length']) ? (float)$row['nod_length'] : 0.0;
        $lenForCode = max(0.0, $rawLen - $nodLen);
        $row['stock_code'] = \PalletManager::formatStockCode($row['coil_no'], $row['width'], $lenForCode);
        return $row;
    }

    /**
     * Global scan-to-deliver resolution
     */
    public function deliverByScan(string $raw, int $activePalletId, string $actor = 'slitting'): array
    {
        $raw = trim(preg_replace('/[[:cntrl:]]/', '', $raw));
        if ($raw === '') {
            return ['ok' => false, 'code' => 'PARSE_ERROR', 'msg' => 'Nothing scanned.'];
        }

        $targetPalletId = 0;
        $targetPalletNo = '';
        $targetStatus   = '';

        $formattedPalletNo = \PalletManager::formatPalletNo($raw);
        $stmtPallet = $this->mysqli->prepare("
            SELECT id, pallet_no, status, delivered_at
            FROM pallets
            WHERE pallet_no = ? OR pallet_no = ?
            LIMIT 1
        ");
        $stmtPallet->bind_param("ss", $raw, $formattedPalletNo);
        $stmtPallet->execute();
        $palletRow = $stmtPallet->get_result()->fetch_assoc();
        $stmtPallet->close();

        if ($palletRow) {
            $targetPalletId = (int)$palletRow['id'];
            $targetPalletNo = $palletRow['pallet_no'];
            $targetStatus   = $palletRow['status'];
        } else {
            $lot = $coil = $roll = '';
            $width = 0.0;
            $id = 0;

            if (strpos($raw, '=') !== false) {
                foreach (explode(';', $raw) as $segment) {
                    $segment = trim($segment);
                    if (strpos($segment, '=') === false) continue;
                    [$k, $v] = explode('=', $segment, 2);
                    $k = strtoupper(trim($k));
                    $v = trim($v);
                    if ($k === 'OLL')   $k = 'ROLL';
                    if ($k === 'OT')    $k = 'LOT';
                    if ($k === 'OIL')   $k = 'COIL';
                    if ($k === 'LOT')   $lot   = $v;
                    if ($k === 'COIL')  $coil  = $v;
                    if ($k === 'ROLL')  $roll  = $v;
                    if ($k === 'WIDTH') $width = (float)$v;
                    if ($k === 'ID')    $id    = (int)$v;
                }
            } else {
                $tokens = preg_split('/\s+/', $raw, 4);
                $lot    = trim($tokens[0] ?? '');
                $coil   = trim($tokens[1] ?? '');
                $roll   = trim($tokens[2] ?? '');
                $width  = floatval($tokens[3] ?? 0);
            }

            if ($id <= 0 && ($width <= 0 || $roll === '') && ($lot === '' || $coil === '' || $roll === '')) {
                return [
                    'ok'   => false,
                    'code' => 'INCOMPLETE_IDENTIFIER',
                    'msg'  => 'Please specify full Lot No, Coil No, and Roll No (e.g. 826277 FK-1 R1) or Roll No and Width.'
                ];
            }

            $cleanRoll = ltrim(strtoupper($roll), 'R-');
            $cleanRoll = 'R' . ltrim($cleanRoll, 'R');

            if ($id > 0) {
                $stmt = $this->mysqli->prepare("
                    SELECT sp.id, sp.is_voided, pi.pallet_id, p.status AS pallet_status, p.pallet_no
                    FROM slitting_product sp
                    LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                    LEFT JOIN pallets p       ON p.id = pi.pallet_id
                    WHERE sp.id = ?
                    ORDER BY sp.id DESC
                    LIMIT 1
                ");
                $stmt->bind_param("i", $id);
            } elseif ($width > 0 && $roll !== '') {
                $stmt = $this->mysqli->prepare("
                    SELECT sp.id, sp.is_voided, pi.pallet_id, p.status AS pallet_status, p.pallet_no
                    FROM slitting_product sp
                    LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                    LEFT JOIN pallets p       ON p.id = pi.pallet_id
                    WHERE sp.roll_no = ? AND ABS(sp.width - ?) < 0.5
                    ORDER BY sp.id DESC
                    LIMIT 1
                ");
                $stmt->bind_param("sd", $roll, $width);
            } else {
                $stmt = $this->mysqli->prepare("
                    SELECT sp.id, sp.is_voided, pi.pallet_id, p.status AS pallet_status, p.pallet_no
                    FROM slitting_product sp
                    LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                    LEFT JOIN pallets p       ON p.id = pi.pallet_id
                    WHERE sp.lot_no = ? AND sp.coil_no = ? AND (sp.roll_no = ? OR sp.roll_no = ?)
                    ORDER BY sp.id DESC
                    LIMIT 1
                ");
                $stmt->bind_param("ssss", $lot, $coil, $roll, $cleanRoll);
            }

            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                return ['ok' => false, 'code' => 'NOT_FOUND', 'msg' => "No product found matching: {$lot} {$coil} {$roll}"];
            }

            if ((int)$row['is_voided'] === 1) {
                return ['ok' => false, 'code' => 'VOIDED', 'msg' => "Product {$lot} {$coil} {$roll} has been voided."];
            }

            if (!$row['pallet_id']) {
                return ['ok' => false, 'code' => 'NO_PALLET', 'msg' => "Product {$lot} {$coil} {$roll} is not assigned to any pallet."];
            }

            $targetPalletId = (int)$row['pallet_id'];
            $targetPalletNo = $row['pallet_no'];
            $targetStatus   = $row['pallet_status'];
        }

        $prevDelivered = false;
        $prevPalletNo  = '';
        $pm = $this->getManager($actor);

        if ($activePalletId > 0 && $activePalletId !== $targetPalletId) {
            $prevPallet = $pm->getPallet($activePalletId);
            if ($prevPallet && $prevPallet['status'] === 'approved') {
                $deliverRes = $this->deliverBundle($activePalletId, $actor);
                if ($deliverRes['ok']) {
                    $prevDelivered = true;
                    $prevPalletNo  = $prevPallet['pallet_no'];
                }
            }
        }

        return [
            'ok'             => true,
            'pallet_id'      => $targetPalletId,
            'pallet_no'      => $targetPalletNo,
            'status'         => $targetStatus,
            'prev_delivered' => $prevDelivered,
            'prev_pallet_no' => $prevPalletNo,
        ];
    }

    /**
     * Build flattened Summary Pallet dataset
     */
    public function buildSummaryPalletRows(): array
    {
        $rows = $this->mysqli->query("
            SELECT p.id AS pallet_id, p.pallet_no, p.status, p.created_at AS pallet_date, pi.stock_code AS pi_stock_code,
                   sp.roll_no, sp.lot_no, sp.coil_no, sp.product,
                   sp.customer_name, sp.ref_no, sp.width, sp.length, sp.actual_length
            FROM pallets p
            LEFT JOIN pallet_items pi     ON pi.pallet_id = p.id
            LEFT JOIN slitting_product sp ON sp.id = pi.slitting_product_id
            ORDER BY p.created_at DESC, pi.seq ASC
        ")->fetch_all(MYSQLI_ASSOC);

        return array_map(function ($r) {
            $rawLen = (!empty($r['actual_length']) && $r['actual_length'] > 0)
                ? (float)$r['actual_length'] : (float)$r['length'];
            $nodLen = !empty($r['nod_length']) ? (float)$r['nod_length'] : 0.0;
            $lenVal = max(0.0, $rawLen - $nodLen);
            $stockCode = !empty($r['coil_no'])
                ? \PalletManager::formatStockCode($r['coil_no'], $r['width'] ?? 0, $lenVal)
                : ($r['pi_stock_code'] ?? null);

            $formattedDate = !empty($r['pallet_date']) ? date('d/m/Y', strtotime($r['pallet_date'])) : '-';

            return [
                'pallet_id'  => $r['pallet_id'],
                'pallet_no'  => $r['pallet_no'],
                'date'       => $formattedDate,
                'status'     => $r['status'],
                'stock_code' => $stockCode,
                'roll_no'    => $r['roll_no'] ? str_replace('R', 'R-', $r['roll_no']) : null,
                'lot_coil'   => trim(($r['lot_no'] ?? '') . ' ' . ($r['coil_no'] ?? '')),
                'product'    => $r['product'],
                'customer'   => $r['customer_name'],
                'ref_no'     => $r['ref_no'],
                'width'      => $r['width'] !== null ? (float)$r['width'] : null,
                'length'     => $lenVal !== null ? (float)$lenVal : null,
            ];
        }, $rows);
    }

    public static function getPalletSuffix(string $palletNo): string
    {
        $palletNo = trim($palletNo);
        if (preg_match('/\(([A-Z0-9]+)\)$/i', $palletNo, $m)) {
            return strtoupper($m[1]);
        }
        return 'none';
    }

    /**
     * Filter Summary Pallet dataset
     */
    public function filterSummaryPalletRows(array $rows, string $cat, string $val, string $statusFilter = '', string $suffixFilter = ''): array
    {
        if ($val !== '') {
            if ($cat === 'date') {
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $val, $m)) {
                    $val = "{$m[3]}/{$m[2]}/{$m[1]}";
                }
                $rows = array_values(array_filter($rows, fn($r) => (string)($r['date'] ?? '') === $val));
            } elseif ($cat === 'customer' || $cat === 'product') {
                $rows = array_values(array_filter($rows, fn($r) => (string)($r[$cat] ?? '') === $val));
            } elseif ($cat === 'suffix') {
                $rows = array_values(array_filter($rows, function ($r) use ($val) {
                    $s = self::getPalletSuffix((string)($r['pallet_no'] ?? ''));
                    return ($val === 'none') ? ($s === 'none') : (strcasecmp($s, $val) === 0);
                }));
            } elseif ($cat === 'width') {
                $rows = array_values(array_filter($rows, function ($r) use ($val) {
                    return $r['width'] !== null && str_contains((string)$r['width'], $val);
                }));
            } elseif ($cat === 'length') {
                $rows = array_values(array_filter($rows, function ($r) use ($val) {
                    return $r['length'] !== null && str_contains((string)$r['length'], $val);
                }));
            } else {
                $needle = strtolower($val);
                $rows = array_values(array_filter($rows, function ($r) use ($needle) {
                    foreach ([$r['pallet_no'], $r['date'], $r['status'], $r['stock_code'], $r['product'], $r['lot_coil'], $r['roll_no'], $r['customer'], $r['ref_no'], $r['width'], $r['length']] as $field) {
                        if ($field !== null && str_contains(strtolower((string)$field), $needle)) return true;
                    }
                    return false;
                }));
            }
        }

        if ($statusFilter !== '') {
            $rows = array_values(array_filter($rows, fn($r) => (string)($r['status'] ?? '') === $statusFilter));
        }

        if ($suffixFilter !== '') {
            $rows = array_values(array_filter($rows, function ($r) use ($suffixFilter) {
                $s = self::getPalletSuffix((string)($r['pallet_no'] ?? ''));
                return ($suffixFilter === 'none') ? ($s === 'none') : (strcasecmp($s, $suffixFilter) === 0);
            }));
        }

        return $rows;
    }

    /**
     * Get items on a pallet including std_weight
     */
    public function getPalletItemsWithWeight(int $palletId): array
    {
        $stmt = $this->mysqli->prepare("
            SELECT pi.seq, pi.added_at, pi.stock_code,
                   sp.id AS product_id,
                   sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
                   sp.width, sp.length, sp.actual_length, sp.nod_length, sp.status,
                   sp.customer_name, sp.ref_no,
                   COALESCE(sw.std_weight, 0) AS std_weight
            FROM pallet_items pi
            JOIN slitting_product sp ON sp.id = pi.slitting_product_id
            LEFT JOIN std_wgt sw     ON sw.product_code = sp.product
            WHERE pi.pallet_id = ?
            ORDER BY pi.seq ASC
        ");
        $stmt->bind_param("i", $palletId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as &$row) {
            $rawLen = (float)($row['actual_length'] ?: $row['length']);
            $nodLen = !empty($row['nod_length']) ? (float)$row['nod_length'] : 0.0;
            $lenVal = max(0.0, $rawLen - $nodLen);
            $row['stock_code'] = \PalletManager::formatStockCode($row['coil_no'], $row['width'], $lenVal);
        }
        unset($row);
        return $rows;
    }

    public static function formatWidthDisplay($val): string
    {
        if ($val === null || $val === '') return '-';
        $f = (float)$val;
        return ($f == (int)$f) ? number_format($f, 0) : (string)$f;
    }

    public static function calcEstWeight(float $lengthM, float $widthMm, float $stdWeight): float
    {
        if ($lengthM <= 0 || $widthMm <= 0 || $stdWeight <= 0) return 0.0;
        return ($lengthM * $widthMm / 1000) * $stdWeight;
    }

    public function getRejectedPallets(): array
    {
        return $this->mysqli->query("
            SELECT p.*, COUNT(pi.id) AS item_count
            FROM pallets p LEFT JOIN pallet_items pi ON pi.pallet_id = p.id
            WHERE p.status = 'rejected'
            GROUP BY p.id ORDER BY p.rejected_at DESC, p.updated_at DESC LIMIT 20
        ")->fetch_all(MYSQLI_ASSOC);
    }

    public function getReopenedPallets(): array
    {
        return $this->mysqli->query("
            SELECT p.*, COUNT(pi.id) AS item_count
            FROM pallets p LEFT JOIN pallet_items pi ON pi.pallet_id = p.id
            WHERE p.status = 'building' AND p.edit_count > 0
            GROUP BY p.id ORDER BY p.updated_at DESC, p.id DESC LIMIT 20
        ")->fetch_all(MYSQLI_ASSOC);
    }

    public function getAllEditLogs(int $palletId): array
    {
        $stmt = $this->mysqli->prepare("
            SELECT action, product_ref, performed_by, performed_at, note
            FROM pallet_edit_log
            WHERE pallet_id = ?
            ORDER BY performed_at DESC
        ");
        if (!$stmt) return [];
        $stmt->bind_param("i", $palletId);
        $stmt->execute();
        $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $logs;
    }

    /**
     * Atomically deliver an entire pallet bundle
     */
    public function deliverBundle(int $palletId, string $performedBy): array
    {
        $this->mysqli->begin_transaction();
        try {
            $stmt = $this->mysqli->prepare("
                SELECT id, pallet_no, status, delivered_at
                FROM pallets
                WHERE id = ?
                FOR UPDATE
            ");
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $pallet = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$pallet) {
                $this->mysqli->rollback();
                return ['ok' => false, 'code' => 'NOT_FOUND', 'msg' => "Pallet #{$palletId} not found."];
            }

            $palletNo = $pallet['pallet_no'];

            if ($pallet['status'] === 'delivered') {
                $this->mysqli->rollback();
                return [
                    'ok'         => true,
                    'code'       => 'ALREADY_DELIVERED',
                    'msg'        => "Pallet {$palletNo} was already delivered on {$pallet['delivered_at']}.",
                    'pallet_no'  => $palletNo,
                    'pallet_id'  => $palletId,
                    'roll_count' => 0,
                ];
            }

            if ($pallet['status'] !== 'approved') {
                $this->mysqli->rollback();
                $friendly = match($pallet['status']) {
                    'building'   => 'still being built (not yet sent to QC).',
                    'pending_qc' => 'waiting for QC approval.',
                    'rejected'   => 'rejected by QC. Resolve rejected rolls before delivering.',
                    default      => "in an undeliverable state: {$pallet['status']}.",
                };
                return [
                    'ok'        => false,
                    'code'      => 'WRONG_STATE',
                    'msg'       => "Pallet {$palletNo} is {$friendly}",
                    'status'    => $pallet['status'],
                    'pallet_no' => $palletNo,
                ];
            }

            $stmt = $this->mysqli->prepare("SELECT COUNT(*) AS cnt FROM pallet_items WHERE pallet_id = ?");
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $rollCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();

            if ($rollCount === 0) {
                $this->mysqli->rollback();
                return ['ok' => false, 'code' => 'EMPTY_PALLET', 'msg' => "Pallet {$palletNo} has no rolls assigned to it."];
            }

            $stmt = $this->mysqli->prepare("
                SELECT pi.slitting_product_id AS product_id, sp.mother_id, sp.status AS prev_status
                FROM pallet_items pi
                JOIN slitting_product sp ON sp.id = pi.slitting_product_id
                WHERE pi.pallet_id = ?
            ");
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $rolls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $stmt = $this->mysqli->prepare("
                UPDATE pallets
                SET status = 'delivered', delivered_at = NOW()
                WHERE id = ? AND status = 'approved'
            ");
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                $stmt->close();
                $this->mysqli->rollback();
                return [
                    'ok'         => true,
                    'code'       => 'ALREADY_DELIVERED',
                    'msg'        => "Pallet {$palletNo} was just delivered by another session.",
                    'pallet_no'  => $palletNo,
                    'pallet_id'  => $palletId,
                    'roll_count' => 0,
                ];
            }
            $stmt->close();

            $stmt = $this->mysqli->prepare("
                UPDATE slitting_product sp
                JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                SET sp.status = 'DELIVERED', sp.delivered_at = NOW()
                WHERE pi.pallet_id = ?
            ");
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $updatedRolls = $stmt->affected_rows;
            $stmt->close();

            // Process log
            $stmtLog = $this->mysqli->prepare("
                INSERT INTO process_log (entity_type, entity_id, mother_id, from_status, to_status, performed_by, action_detail)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            if ($stmtLog) {
                $entityType = 'slitting';
                $toStatus = 'DELIVERED';
                $detail = "bundle_delivery pallet_id={$palletId} pallet_no={$palletNo}";
                foreach ($rolls as $r) {
                    $pid = (int)$r['product_id'];
                    $mid = $r['mother_id'] ? (int)$r['mother_id'] : null;
                    $fromStatus = $r['prev_status'];
                    $stmtLog->bind_param("siissss", $entityType, $pid, $mid, $fromStatus, $toStatus, $performedBy, $detail);
                    $stmtLog->execute();
                }
                $stmtLog->close();
            }

            $this->mysqli->commit();
            return [
                'ok'         => true,
                'code'       => 'DELIVERED',
                'msg'        => "Pallet {$palletNo} delivered — {$updatedRolls} roll(s) updated.",
                'pallet_no'  => $palletNo,
                'pallet_id'  => $palletId,
                'roll_count' => $updatedRolls,
            ];
        } catch (Throwable $e) {
            $this->mysqli->rollback();
            return ['ok' => false, 'code' => 'DB_ERROR', 'msg' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * QC: Save checklist state
     */
    public function saveChecklistState(int $palletId, int $topProductId): void
    {
        $colCheck = $this->mysqli->query("SHOW COLUMNS FROM pallet_items LIKE 'coil_width'");
        if ($colCheck && $colCheck->num_rows === 0) {
            $this->mysqli->query("ALTER TABLE pallet_items ADD COLUMN coil_width TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Coil Width checkbox was ticked by QC inspector' AFTER hairy_rubber");
        }

        $clear = $this->mysqli->prepare("
            UPDATE pallet_items
            SET winding_condition = 0, hairy_rubber = 0, coil_width = 0, qc_checked_at = NULL
            WHERE pallet_id = ?
        ");
        $clear->bind_param("i", $palletId);
        $clear->execute();
        $clear->close();

        $mark = $this->mysqli->prepare("
            UPDATE pallet_items
            SET winding_condition = 1, hairy_rubber = 1, coil_width = 1, qc_checked_at = NOW()
            WHERE pallet_id = ? AND slitting_product_id = ?
        ");
        $mark->bind_param("ii", $palletId, $topProductId);
        $mark->execute();
        $mark->close();
    }

    /**
     * QC: Approve pallet
     */
    public function approvePallet(int $palletId, string $checkedBy, int $topProductId): array
    {
        $this->mysqli->begin_transaction();
        try {
            $this->saveChecklistState($palletId, $topProductId);

            $stmt = $this->mysqli->prepare("
                UPDATE pallets
                SET status = 'approved', qc_comment = NULL, checked_by = ?
                WHERE id = ? AND status = 'pending_qc'
            ");
            $stmt->bind_param("si", $checkedBy, $palletId);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                throw new RuntimeException('Pallet is not pending QC (may have already been processed).');
            }
            $stmt->close();

            $stmt = $this->mysqli->prepare("
                UPDATE slitting_product sp
                JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                SET sp.status = 'APPROVED'
                WHERE pi.pallet_id = ?
            ");
            $stmt->bind_param("i", $palletId);
            $stmt->execute();
            $stmt->close();

            $this->mysqli->commit();
            return ['ok' => true];
        } catch (Throwable $e) {
            $this->mysqli->rollback();
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    /**
     * QC: Reject pallet
     */
    public function rejectPallet(int $palletId, string $comment, string $checkedBy, int $topProductId = 0): array
    {
        $this->mysqli->begin_transaction();
        try {
            if ($topProductId > 0) {
                $this->saveChecklistState($palletId, $topProductId);
            }

            $stmt = $this->mysqli->prepare("
                UPDATE pallets
                SET status = 'rejected', qc_comment = ?, checked_by = ?
                WHERE id = ? AND status = 'pending_qc'
            ");
            $stmt->bind_param("ssi", $comment, $checkedBy, $palletId);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                throw new RuntimeException('Pallet is not pending QC (may have already been processed).');
            }
            $stmt->close();

            $stmt = $this->mysqli->prepare("
                UPDATE slitting_product sp
                JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                SET sp.status = 'REJECTED', sp.qc_comment = ?
                WHERE pi.pallet_id = ?
            ");
            $stmt->bind_param("si", $comment, $palletId);
            $stmt->execute();
            $stmt->close();

            $this->mysqli->commit();
            return ['ok' => true];
        } catch (Throwable $e) {
            $this->mysqli->rollback();
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    /**
     * Legacy finish product delivery scan
     */
    public function deliverFinishProduct(int $slitId, string $deliveredBy): array
    {
        $stmt = $this->mysqli->prepare("
            SELECT * FROM finish_product WHERE slit_id = ? ORDER BY id DESC LIMIT 1
        ");
        $stmt->bind_param("i", $slitId);
        $stmt->execute();
        $fin = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$fin) {
            return ['ok' => false, 'code' => 'not_found'];
        }

        $stmt = $this->mysqli->prepare("
            SELECT * FROM waiting_approval WHERE finish_id = ? ORDER BY id DESC LIMIT 1
        ");
        $stmt->bind_param("i", $fin['id']);
        $stmt->execute();
        $wait = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($wait && $wait['status'] === 'APPROVED') {
            $stmt = $this->mysqli->prepare("
                UPDATE finish_product SET status = 'DELIVERED', delivered_by = ?, delivered_at = NOW() WHERE id = ?
            ");
            $stmt->bind_param("si", $deliveredBy, $fin['id']);
            $stmt->execute();
            $stmt->close();

            $stmt = $this->mysqli->prepare("
                UPDATE waiting_approval SET status = 'DELIVERED' WHERE id = ?
            ");
            $stmt->bind_param("i", $wait['id']);
            $stmt->execute();
            $stmt->close();

            return ['ok' => true];
        }

        return ['ok' => false, 'code' => 'not_approved'];
    }
}

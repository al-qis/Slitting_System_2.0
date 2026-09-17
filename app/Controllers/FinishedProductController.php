<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Models\FinishedProduct;
use mysqli;
use RuntimeException;
use Throwable;

class FinishedProductController extends Controller
{
    private FinishedProduct $productModel;
    private mysqli $conn;

    public function __construct()
    {
        parent::__construct();
        $this->productModel = new FinishedProduct();
        $this->conn = Database::getMysqli();
    }

    /**
     * Finished Product Main Dashboard (formerly finish_product.php)
     */
    public function index(): void
    {
        $this->requireRole(['slitting']);

        $conn = $this->conn;

        // Excel export redirect
        if (isset($_GET['download']) && $_GET['download'] === 'excel') {
            $this->export();
            return;
        }

        $month  = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
        $year   = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';

        $day = isset($_GET['day']) ? (int)$_GET['day'] : 0;
        if ($day < 0 || $day > 31) { $day = 0; }

        $sort_col = isset($_GET['sort_col']) ? $_GET['sort_col'] : '';
        if (!in_array($sort_col, ['date_in', 'date_out'], true)) { $sort_col = ''; }
        $sort_dir = (isset($_GET['sort_dir']) && strtoupper($_GET['sort_dir']) === 'ASC') ? 'ASC' : 'DESC';

        $filter_card = $_GET['filter'] ?? 'in_pending';
        if (!in_array($filter_card, ['in_pending', 'stock', 'palletised', 'waiting', 'deliver', 'produced_month', 'stock_month_end'], true)) {
            $filter_card = 'in_pending';
        }

        $filter_origin = trim($_GET['origin'] ?? '');
        $originOptions = $this->productModel->getOriginOptions();
        $filter_nod    = trim($_GET['nod'] ?? '');
        if (!in_array($filter_nod, ['', '1', '0'], true)) { $filter_nod = ''; }

        if ($month < 1 || $month > 12) { $month = (int)date('m'); }
        if ($year < 2000 || $year > 2100) { $year = (int)date('Y'); }

        $daysInSelectedMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
        if ($day > $daysInSelectedMonth) { $day = 0; }

        $searchTokens = ($search !== '') ? preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) : [];

        // ── POST Handlers ──────────────────────────────────────────────
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';

            if ($action === 'update_ok') {
                $id            = intval($_POST['id']);
                $actual_length = trim($_POST['actual_length']);
                $this->productModel->updateOk($id, $actual_length, $_SESSION['role'] ?? 'system');

                $redirectFilter = $_POST['filter'] ?? $filter_card;
                $redirectSearch = $_POST['search'] ?? $search;
                $redirectParams = ['month' => $month, 'year' => $year, 'success' => 'stock'];
                if ($day > 0) $redirectParams['day'] = $day;
                if ($redirectSearch !== '') $redirectParams['search'] = $redirectSearch;
                if ($redirectFilter !== '') $redirectParams['filter'] = $redirectFilter;
                $this->redirect("finish_product.php?" . http_build_query($redirectParams));
                return;
            }

            if ($action === 'save_nod') {
                $id           = intval($_POST['id']);
                $nodLengthRaw = trim($_POST['nod_length'] ?? '');
                $nodLength    = $nodLengthRaw === '' ? null : (float)$nodLengthRaw;
                $this->productModel->saveNod($id, $nodLength, $_SESSION['role'] ?? 'system');

                $redirectFilter = $_POST['filter'] ?? $filter_card;
                $redirectSearch = $_POST['search'] ?? $search;
                $redirectParams = ['month' => $month, 'year' => $year, 'success' => 'nod_saved'];
                if ($day > 0) $redirectParams['day'] = $day;
                if ($redirectSearch !== '') $redirectParams['search'] = $redirectSearch;
                if ($redirectFilter !== '') $redirectParams['filter'] = $redirectFilter;
                $this->redirect("finish_product.php?" . http_build_query($redirectParams));
                return;
            }

            if ($action === 'batch_update_actual_length') {
                $actual_length = trim($_POST['actual_length']);
                $product       = trim($_POST['product']);
                $lot_no        = trim($_POST['lot_no']);
                $id            = intval($_POST['id']);
                $new_coil_no   = trim($_POST['coil_no'] ?? '');
                $new_lot_no    = trim($_POST['new_lot_no'] ?? '');

                $selfRow = $conn->query("SELECT * FROM slitting_product WHERE id=$id")->fetch_assoc();

                if ($selfRow && $new_lot_no !== '' && $new_lot_no !== trim($selfRow['lot_no'] ?? '')) {
                    $oldLotNo = trim($selfRow['lot_no'] ?? '');
                    $stmtFixLot = $conn->prepare("UPDATE slitting_product SET lot_no=? WHERE id=?");
                    $stmtFixLot->bind_param("si", $new_lot_no, $id);
                    $stmtFixLot->execute();
                    $stmtFixLot->close();
                    $selfRow['lot_no'] = $new_lot_no;
                    $this->productModel->logProcess('slitting', $id, intval($selfRow['mother_id'] ?? 0) ?: null, 'IN', 'IN', 'lot_no_corrected', "Lot No corrected: '{$oldLotNo}' -> '{$new_lot_no}'", $_SESSION['role'] ?? 'system');
                }

                if ($selfRow && $new_coil_no !== '' && $new_coil_no !== trim($selfRow['coil_no'] ?? '')) {
                    $oldCoilNo = trim($selfRow['coil_no'] ?? '');
                    $stmtFix = $conn->prepare("UPDATE slitting_product SET coil_no=? WHERE id=?");
                    $stmtFix->bind_param("si", $new_coil_no, $id);
                    $stmtFix->execute();
                    $stmtFix->close();
                    $selfRow['coil_no'] = $new_coil_no;
                    $this->productModel->logProcess('slitting', $id, intval($selfRow['mother_id'] ?? 0) ?: null, 'IN', 'IN', 'coil_no_corrected', "Coil No corrected: '{$oldCoilNo}' -> '{$new_coil_no}'", $_SESSION['role'] ?? 'system');
                }

                if ($selfRow) {
                    $lot_no = trim($selfRow['lot_no'] ?? $lot_no);
                }

                $isFirstTimeEntry = $selfRow && (int)$selfRow['is_completed'] === 0;
                $selfMotherId     = intval($selfRow['mother_id'] ?? 0);

                if ($isFirstTimeEntry) {
                    if ($selfMotherId > 0) {
                        $stmt = $conn->prepare("SELECT * FROM slitting_product WHERE product=? AND lot_no=? AND mother_id=? AND status='IN' AND is_completed=0");
                        $stmt->bind_param("ssi", $product, $lot_no, $selfMotherId);
                    } else {
                        $stmt = $conn->prepare("SELECT * FROM slitting_product WHERE product=? AND lot_no=? AND status='IN' AND is_completed=0");
                        $stmt->bind_param("ss", $product, $lot_no);
                    }
                    $stmt->execute();
                    $group = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();

                    if (empty($group) && $selfRow) {
                        $group = [$selfRow];
                    }

                    if ($selfMotherId > 0) {
                        $stmt = $conn->prepare("UPDATE slitting_product SET actual_length=?, date_in=NOW(), stock_counted=1, is_completed=1 WHERE product=? AND lot_no=? AND mother_id=? AND status='IN' AND is_completed=0");
                        $stmt->bind_param("sssi", $actual_length, $product, $lot_no, $selfMotherId);
                    } else {
                        $stmt = $conn->prepare("UPDATE slitting_product SET actual_length=?, date_in=NOW(), stock_counted=1, is_completed=1 WHERE product=? AND lot_no=? AND status='IN' AND is_completed=0");
                        $stmt->bind_param("sss", $actual_length, $product, $lot_no);
                    }
                    $stmt->execute();
                    $stmt->close();
                } else {
                    $group = $selfRow ? [$selfRow] : [];
                    $stmt = $conn->prepare("UPDATE slitting_product SET actual_length=?, date_in=NOW(), stock_counted=1, is_completed=1 WHERE id=?");
                    $stmt->bind_param("si", $actual_length, $id);
                    $stmt->execute();
                    $stmt->close();
                }

                foreach ($group as $item) {
                    $gid = $item['id'];
                    if ($item['cut_type'] === 'cut_into_2' && floatval($item['leftover_length'] ?? $item['stock'] ?? 0) > 0) {
                        $leftover = floatval($item['leftover_length'] ?? $item['stock'] ?? 0);
                        $mother   = $conn->query("SELECT * FROM mother_coil WHERE id={$item['mother_id']}")->fetch_assoc();
                        if ($mother) {
                            $stock_lot_no = $item['lot_no'] . 'a';
                            $check = $conn->query("SELECT id, length FROM stock_raw_material WHERE lot_no='$stock_lot_no' AND coil_no='{$item['coil_no']}'");
                            if ($check && $check->num_rows > 0) {
                                $existing   = $check->fetch_assoc();
                                $new_length = $existing['length'] + $leftover;
                                $conn->query("UPDATE stock_raw_material SET length=$new_length, updated_at=NOW() WHERE id={$existing['id']}");
                            } else {
                                $ins = $conn->prepare("INSERT INTO stock_raw_material (lot_no, coil_no, width, length, status, source_type, source_id, date_in) VALUES (?, ?, ?, ?, 'IN', 'reslit', ?, NOW())");
                                $ins->bind_param("ssddi", $stock_lot_no, $item['coil_no'], $mother['width'], $leftover, $item['mother_id']);
                                $ins->execute();
                                $ins->close();
                            }
                        }
                    }

                    $this->productModel->logProcess('slitting', $gid, intval($item['mother_id'] ?? 0) ?: null, 'IN', 'IN', 'actual_length_saved', "actual_length={$actual_length}m, stock_counted=1", $_SESSION['role'] ?? 'system');
                }

                $redirectFilter = $_POST['filter'] ?? $filter_card;
                $redirectSearch = $_POST['search'] ?? $search;
                $redirectParams = ['month' => $month, 'year' => $year, 'success' => 'stock'];
                if ($day > 0) $redirectParams['day'] = $day;
                if ($redirectSearch !== '') $redirectParams['search'] = $redirectSearch;
                if ($redirectFilter !== '') $redirectParams['filter'] = $redirectFilter;
                $this->redirect("finish_product.php?" . http_build_query($redirectParams));
                return;
            }

            if ($action === 'send_to_recoiling') {
                $id = intval($_POST['product_id']);
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("SELECT * FROM slitting_product WHERE id=? FOR UPDATE");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $p = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$p) throw new RuntimeException("Roll #$id not found.");
                    if ($p['is_recoiled']) throw new RuntimeException("Roll #$id is already sent to recoiling.");

                    $mid             = intval($p['mother_id'] ?? 0) ?: null;
                    $original_source = $p['original_source'] ?? 'raw_material';
                    $prev_status     = $p['status'];

                    $conn->query("UPDATE slitting_product SET is_recoiled=1 WHERE id=$id");
                    $stmtR = $conn->prepare("INSERT INTO recoiling_product (slitting_product_id, mother_id, status, product, lot_no, coil_no, roll_no, width, length, actual_length, original_source) VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmtR->bind_param("iissssddds", $id, $mid, $p['product'], $p['lot_no'], $p['coil_no'], $p['roll_no'], $p['width'], $p['length'], $p['actual_length'], $original_source);
                    $stmtR->execute();
                    $new_recoil_id = $conn->insert_id;
                    $stmtR->close();

                    $this->productModel->logProcess('slitting', $id, $mid, $prev_status, 'OUT', 'send_to_recoiling', "Sent to recoiling_product id={$new_recoil_id}", $_SESSION['role'] ?? 'system');
                    $this->productModel->logProcess('recoiling', $new_recoil_id, $mid, null, 'pending', 'received_from_slitting', "From slitting_product id={$id}", $_SESSION['role'] ?? 'system');

                    $conn->query("INSERT INTO source_tracking_log (product_id, table_name, original_source, current_source, action) VALUES ($id, 'recoiling_product', '$original_source', 'recoiling', 'send_to_recoiling')");
                    $conn->commit();
                    $this->redirect("finish_product.php?month={$month}&year={$year}&success=recoiling");
                } catch (Throwable $e) {
                    $conn->rollback();
                    $this->redirect("finish_product.php?month={$month}&year={$year}&error=recoiling_failed&msg=" . urlencode($e->getMessage()));
                }
                return;
            }

            if ($action === 'send_to_reslit') {
                $id = intval($_POST['product_id']);
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("SELECT * FROM slitting_product WHERE id=? FOR UPDATE");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $p = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$p) throw new RuntimeException("Roll #$id not found.");
                    if ($p['is_reslitted']) throw new RuntimeException("Roll #$id is already sent to reslit.");

                    $mid             = intval($p['mother_id'] ?? 0) ?: null;
                    $original_source = $p['original_source'] ?? 'raw_material';
                    $prev_status     = $p['status'];

                    $conn->query("UPDATE slitting_product SET is_reslitted=1 WHERE id=$id");
                    $insert_length = $p['actual_length'] ?: $p['length'];

                    $stmtRes = $conn->prepare("INSERT INTO reslit_product (slitting_product_id, mother_id, status, product, lot_no, coil_no, roll_no, width, length, date_in, original_source) VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, NOW(), ?)");
                    $stmtRes->bind_param("iissssdds", $id, $mid, $p['product'], $p['lot_no'], $p['coil_no'], $p['roll_no'], $p['width'], $insert_length, $original_source);
                    $stmtRes->execute();
                    $new_reslit_id = $conn->insert_id;
                    $stmtRes->close();

                    $this->productModel->logProcess('slitting', $id, $mid, $prev_status, 'OUT', 'send_to_reslit', "Sent to reslit_product id={$new_reslit_id}", $_SESSION['role'] ?? 'system');
                    $this->productModel->logProcess('reslit', $new_reslit_id, $mid, null, 'pending', 'received_from_slitting', "From slitting_product id={$id}", $_SESSION['role'] ?? 'system');

                    $conn->query("INSERT INTO source_tracking_log (product_id, table_name, original_source, current_source, action) VALUES ($id, 'reslit_product', '$original_source', 'reslit', 'send_to_reslit')");
                    $conn->commit();
                    $this->redirect("finish_product.php?month={$month}&year={$year}&success=reslit");
                } catch (Throwable $e) {
                    $conn->rollback();
                    $this->redirect("finish_product.php?month={$month}&year={$year}&error=reslit_failed&msg=" . urlencode($e->getMessage()));
                }
                return;
            }

            if ($action === 'send_to_sfc') {
                $id = intval($_POST['product_id']);
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("SELECT * FROM slitting_product WHERE id=? FOR UPDATE");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $p = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$p) throw new RuntimeException("Roll #$id not found.");
                    if ($p['status'] === 'OUT') throw new RuntimeException("Roll #$id is already transferred or status is OUT.");

                    $mid             = intval($p['mother_id'] ?? 0) ?: null;
                    $original_source = $p['original_source'] ?? 'raw_material';
                    $prev_status     = $p['status'];
                    $insert_length   = (float)($p['actual_length'] ?: $p['length']);

                    $sfc_stmt = $conn->prepare("INSERT INTO sfc (mother_id, product, lot_no, coil_no, roll_no, width, length, action, date_created) VALUES (?, ?, ?, ?, ?, ?, ?, 'slitting', NOW())");
                    $sfc_stmt->bind_param("issssdd", $mid, $p['product'], $p['lot_no'], $p['coil_no'], $p['roll_no'], $p['width'], $insert_length);
                    $sfc_stmt->execute();
                    $sfc_id = $conn->insert_id;
                    $sfc_stmt->close();

                    $conn->query("UPDATE slitting_product SET status='OUT', date_out=NOW() WHERE id=$id");

                    $this->productModel->logProcess('slitting', $id, $mid, $prev_status, 'OUT', 'send_to_sfc', "Sent to SFC id={$sfc_id}", $_SESSION['role'] ?? 'system');
                    $this->productModel->logProcess('sfc', $sfc_id, $mid, null, 'IN', 'received_from_slitting', "From slitting_product id={$id}", $_SESSION['role'] ?? 'system');

                    $conn->query("INSERT INTO source_tracking_log (product_id, table_name, original_source, current_source, action) VALUES ($id, 'sfc', '$original_source', 'sfc', 'send_to_sfc')");
                    $conn->commit();
                    $this->redirect("finish_product.php?month={$month}&year={$year}&success=sfc");
                } catch (Throwable $e) {
                    $conn->rollback();
                    $this->redirect("finish_product.php?month={$month}&year={$year}&error=sfc_failed&msg=" . urlencode($e->getMessage()));
                }
                return;
            }

            if ($action === 'void_product') {
                $id = intval($_POST['id']);
                $reason = trim($_POST['void_reason'] ?? '');
                $this->productModel->voidProduct($id, $reason, $_SESSION['role'] ?? 'system');
                $this->redirect("finish_product.php?month={$month}&year={$year}&success=voided");
                return;
            }

            if ($action === 'save_remarks') {
                $id = intval($_POST['id']);
                $remark = trim($_POST['remark'] ?? '');
                $this->productModel->saveRemarks($id, $remark, $_SESSION['role'] ?? 'system');
                $this->redirect("finish_product.php?month={$month}&year={$year}&success=remark");
                return;
            }

            if ($action === 'update_customer_ref') {
                $id = intval($_POST['id']);
                $customer = trim($_POST['customer_name'] ?? '');
                $refNo = trim($_POST['ref_no'] ?? '');
                $this->productModel->updateCustomerRef($id, $customer, $refNo, $_SESSION['role'] ?? 'system');
                $this->redirect("finish_product.php?month={$month}&year={$year}&success=customer_ref");
                return;
            }

            if ($action === 'delete_roll') {
                $id = intval($_POST['id']);
                $this->productModel->deleteRoll($id, $_SESSION['role'] ?? 'system');
                $this->redirect("finish_product.php?month={$month}&year={$year}&success=deleted");
                return;
            }
        }

        // ── Card filter SQL condition & sorting ────────────────────────
        $cardCondition = '';
        $sortColumn    = 'sp.date_in';
        if ($filter_card === 'in_pending') {
            $cardCondition = " AND sp.status = 'IN' AND sp.is_completed = 0";
            $sortColumn    = 'sp.date_in';
        } elseif ($filter_card === 'stock') {
            $cardCondition = " AND sp.status = 'IN' AND sp.stock_counted = 1 AND pi.pallet_id IS NULL";
            $sortColumn    = 'sp.date_in';
        } elseif ($filter_card === 'palletised') {
            $cardCondition = " AND sp.status = 'IN' AND pi.pallet_id IS NOT NULL AND p.status = 'building'";
            $sortColumn    = 'sp.date_in';
        } elseif ($filter_card === 'waiting') {
            $cardCondition = " AND sp.status = 'WAITING'";
            $sortColumn    = 'sp.date_out';
        } elseif ($filter_card === 'deliver') {
            $cardCondition = " AND sp.status = 'DELIVERED'";
            $sortColumn    = 'sp.delivered_at';
        }

        $baseTypes  = '';
        $baseParams = [];

        if ($filter_card === 'produced_month') {
            $baseSql = "
                SELECT sp.*, pi.pallet_id, p.pallet_no, p.status AS pallet_status
                FROM slitting_product sp
                LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                LEFT JOIN pallets p       ON p.id = pi.pallet_id
                WHERE sp.is_voided = 0
                  AND MONTH(sp.date_in) = ? AND YEAR(sp.date_in) = ?"
                  . ($day > 0 ? " AND DAY(sp.date_in) = ?" : "");
            $sortColumn = 'sp.date_in';
            $baseTypes  = $day > 0 ? 'iii' : 'ii';
            $baseParams = $day > 0 ? [$month, $year, $day] : [$month, $year];
        } elseif ($filter_card === 'stock_month_end') {
            $eom = ($day > 0)
                ? sprintf('%04d-%02d-%02d 23:59:59', $year, $month, $day)
                : date('Y-m-t 23:59:59', strtotime("$year-$month-01"));
            $baseSql = "
                SELECT sp.*, pi.pallet_id, p.pallet_no, p.status AS pallet_status
                FROM slitting_product sp
                LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                LEFT JOIN pallets p       ON p.id = pi.pallet_id
                LEFT JOIN (
                    SELECT entity_id, MIN(performed_at) AS recoil_date
                    FROM process_log
                    WHERE entity_type = 'slitting' AND action_detail = 'send_to_recoiling'
                    GROUP BY entity_id
                ) rc ON rc.entity_id = sp.id
                LEFT JOIN (
                    SELECT entity_id, MIN(performed_at) AS reslit_date
                    FROM process_log
                    WHERE entity_type = 'slitting' AND action_detail = 'send_to_reslit'
                    GROUP BY entity_id
                ) rs ON rs.entity_id = sp.id
                WHERE sp.is_voided = 0
                  AND sp.date_in <= ?
                  AND (sp.date_out     IS NULL OR sp.date_out     > ?)
                  AND (sp.delivered_at IS NULL OR sp.delivered_at > ?)
                  AND (rc.recoil_date  IS NULL OR rc.recoil_date  > ?)
                  AND (rs.reslit_date  IS NULL OR rs.reslit_date  > ?)";
            $sortColumn = 'sp.date_in';
            $baseTypes  = 'sssss';
            $baseParams = [$eom, $eom, $eom, $eom, $eom];
        } else {
            $dayCondOut       = $day > 0 ? " AND DAY(sp.date_out) = ?"     : "";
            $dayCondDelivered = $day > 0 ? " AND DAY(sp.delivered_at) = ?" : "";
            $baseSql = "
                SELECT sp.*, pi.pallet_id, p.pallet_no, p.status AS pallet_status
                FROM slitting_product sp
                LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                LEFT JOIN pallets p       ON p.id = pi.pallet_id
                WHERE sp.is_voided = 0
                  AND (sp.is_recoiled = 0 OR sp.is_recoiled IS NULL)
                  AND (sp.is_reslitted = 0 OR sp.is_reslitted IS NULL)
                  AND (
                      sp.status = 'IN'
                      OR sp.status = 'WAITING'
                      OR (sp.status IN ('OUT','APPROVED','REJECTED')
                          AND MONTH(sp.date_out) = ? AND YEAR(sp.date_out) = ?{$dayCondOut})
                      OR (sp.status = 'DELIVERED'
                          AND MONTH(sp.delivered_at) = ? AND YEAR(sp.delivered_at) = ?{$dayCondDelivered})
                  )
                  {$cardCondition}";
            $sortColumn = $sortColumn ?: 'sp.date_in';
            if ($day > 0) {
                $baseTypes  = 'iiiiii';
                $baseParams = [$month, $year, $day, $month, $year, $day];
            } else {
                $baseTypes  = 'iiii';
                $baseParams = [$month, $year, $month, $year];
            }
        }

        if (!empty($searchTokens)) {
            $tokenClauses = array_fill(
                0,
                count($searchTokens),
                "(sp.product LIKE ? OR sp.lot_no LIKE ? OR sp.coil_no LIKE ? OR sp.roll_no LIKE ? OR sp.id LIKE ? OR p.pallet_no LIKE ? OR sp.width LIKE ?)"
            );
            $baseSql .= " AND (" . implode(" AND ", $tokenClauses) . ")";
        }

        if ($filter_origin !== '') {
            $baseSql .= " AND LOWER(TRIM(COALESCE(sp.original_source, sp.source, 'raw_material'))) = ?";
        }

        if ($filter_nod === '1') {
            $baseSql .= " AND sp.nod_length IS NOT NULL AND sp.nod_length > 0";
        } elseif ($filter_nod === '0') {
            $baseSql .= " AND (sp.nod_length IS NULL OR sp.nod_length = 0)";
        }

        if ($sort_col !== '') {
            $sortColumn = 'sp.' . $sort_col;
        }
        $finalSortDir = ($sort_col !== '') ? $sort_dir : 'DESC';

        $baseSql .= " ORDER BY {$sortColumn} {$finalSortDir}, sp.id DESC";

        $stmt = $conn->prepare($baseSql);
        if (!$stmt) { die("Query prepare failed: " . htmlspecialchars($conn->error)); }

        $types  = $baseTypes;
        $params = $baseParams;

        foreach ($searchTokens as $token) {
            $like = '%' . $token . '%';
            for ($i = 0; $i < 7; $i++) {
                $types    .= "s";
                $params[] = $like;
            }
        }

        if ($filter_origin !== '') { $types .= "s"; $params[] = $filter_origin; }

        $bindArgs = [$types];
        foreach ($params as $key => $value) {
            $bindArgs[] = &$params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindArgs);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        // ── KPIs ───────────────────────────────────────────────────────
        $in      = $conn->query("SELECT IFNULL(COUNT(*),0) AS total FROM slitting_product WHERE is_voided=0 AND status='IN' AND is_completed=0 AND (is_recoiled=0 OR is_recoiled IS NULL) AND (is_reslitted=0 OR is_reslitted IS NULL)")->fetch_assoc()['total'];
        $stock   = $conn->query("SELECT IFNULL(COUNT(*),0) AS total FROM slitting_product WHERE is_voided=0 AND status='IN' AND stock_counted=1 AND (is_recoiled=0 OR is_recoiled IS NULL) AND (is_reslitted=0 OR is_reslitted IS NULL)")->fetch_assoc()['total'];
        $waiting = $conn->query("SELECT IFNULL(COUNT(*),0) AS total FROM slitting_product WHERE is_voided=0 AND status='WAITING'")->fetch_assoc()['total'];
        $deliver = $conn->query("SELECT IFNULL(COUNT(*),0) AS total FROM slitting_product WHERE is_voided=0 AND status='DELIVERED' AND MONTH(delivered_at)=$month AND YEAR(delivered_at)=$year")->fetch_assoc()['total'];

        $palletised = $conn->query("
            SELECT IFNULL(COUNT(*),0) AS total
            FROM pallet_items pi
            JOIN pallets p ON p.id = pi.pallet_id
            JOIN slitting_product sp ON sp.id = pi.slitting_product_id
            WHERE p.status = 'building'
        ")->fetch_assoc()['total'];

        $stmtPM = $conn->prepare("SELECT IFNULL(COUNT(*),0) AS total FROM slitting_product WHERE is_voided=0 AND MONTH(date_in)=? AND YEAR(date_in)=?");
        $stmtPM->bind_param("ii", $month, $year);
        $stmtPM->execute();
        $producedMonthCount = (int)$stmtPM->get_result()->fetch_assoc()['total'];
        $stmtPM->close();

        $eomForCount = date('Y-m-t 23:59:59', strtotime("$year-$month-01"));
        $stmtSME = $conn->prepare("
            SELECT IFNULL(COUNT(*),0) AS total
            FROM slitting_product sp
            LEFT JOIN (
                SELECT entity_id, MIN(performed_at) AS recoil_date
                FROM process_log
                WHERE entity_type = 'slitting' AND action_detail = 'send_to_recoiling'
                GROUP BY entity_id
            ) rc ON rc.entity_id = sp.id
            LEFT JOIN (
                SELECT entity_id, MIN(performed_at) AS reslit_date
                FROM process_log
                WHERE entity_type = 'slitting' AND action_detail = 'send_to_reslit'
                GROUP BY entity_id
            ) rs ON rs.entity_id = sp.id
            WHERE sp.is_voided = 0
              AND sp.date_in <= ?
              AND (sp.date_out     IS NULL OR sp.date_out     > ?)
              AND (sp.delivered_at IS NULL OR sp.delivered_at > ?)
              AND (rc.recoil_date  IS NULL OR rc.recoil_date  > ?)
              AND (rs.reslit_date  IS NULL OR rs.reslit_date  > ?)
        ");
        $stmtSME->bind_param("sssss", $eomForCount, $eomForCount, $eomForCount, $eomForCount, $eomForCount);
        $stmtSME->execute();
        $stockMonthEndCount = (int)$stmtSME->get_result()->fetch_assoc()['total'];
        $stmtSME->close();

        $nodMonthCount = 0;
        $stmtNOD = $conn->prepare("
            SELECT IFNULL(COUNT(*),0) AS total
            FROM slitting_product
            WHERE is_voided = 0
              AND nod_length IS NOT NULL AND nod_length > 0
              AND MONTH(nod_recorded_at) = ? AND YEAR(nod_recorded_at) = ?
        ");
        $stmtNOD->bind_param("ii", $month, $year);
        $stmtNOD->execute();
        $nodMonthCount = (int)$stmtNOD->get_result()->fetch_assoc()['total'];
        $stmtNOD->close();

        $editData = null;
        $batchRolls = [];
        if (isset($_GET['edit'])) {
            $eid = intval($_GET['edit']);
            $res = $conn->query("SELECT * FROM slitting_product WHERE id=$eid");
            if ($res && $res->num_rows > 0) {
                $editData = $res->fetch_assoc();
                $lotNoFetch    = trim($editData['lot_no'] ?? '');
                $coilNoFetch   = trim($editData['coil_no'] ?? '');
                $motherIdFetch = intval($editData['mother_id'] ?? 0);

                if ($lotNoFetch !== '' && $coilNoFetch !== '') {
                    if ($motherIdFetch > 0) {
                        $stmtBR = $conn->prepare("
                            SELECT sp.id, sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
                                   sp.width, sp.length, sp.actual_length, sp.status,
                                   sp.is_completed, sp.customer_name, sp.ref_no,
                                   sp.is_printed, sp.print_count, sp.last_printed_at, sp.last_printed_by,
                                   pi.pallet_id
                            FROM slitting_product sp
                            LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                            WHERE sp.is_voided = 0 AND sp.lot_no = ? AND sp.coil_no = ? AND sp.mother_id = ?
                            ORDER BY sp.roll_no ASC, sp.id ASC
                        ");
                        $stmtBR->bind_param("ssi", $lotNoFetch, $coilNoFetch, $motherIdFetch);
                    } else {
                        $stmtBR = $conn->prepare("
                            SELECT sp.id, sp.product, sp.lot_no, sp.coil_no, sp.roll_no,
                                   sp.width, sp.length, sp.actual_length, sp.status,
                                   sp.is_completed, sp.customer_name, sp.ref_no,
                                   sp.is_printed, sp.print_count, sp.last_printed_at, sp.last_printed_by,
                                   pi.pallet_id
                            FROM slitting_product sp
                            LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
                            WHERE sp.is_voided = 0 AND sp.lot_no = ? AND sp.coil_no = ?
                            ORDER BY sp.roll_no ASC, sp.id ASC
                        ");
                        $stmtBR->bind_param("ss", $lotNoFetch, $coilNoFetch);
                    }
                    $stmtBR->execute();
                    $batchRolls = $stmtBR->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmtBR->close();
                }
                if (empty($batchRolls)) {
                    $batchRolls = [$editData];
                }
            }
        }

        $this->render('finish_product/index', [
            'month'               => $month,
            'year'                => $year,
            'day'                 => $day,
            'sort_col'            => $sort_col,
            'sort_dir'            => $sort_dir,
            'filter_card'         => $filter_card,
            'filter_origin'       => $filter_origin,
            'originOptions'       => $originOptions,
            'filter_nod'          => $filter_nod,
            'daysInSelectedMonth' => $daysInSelectedMonth,
            'search'              => $search,
            'searchTokens'        => $searchTokens,
            'result'              => $result,
            'in'                  => $in,
            'stock'               => $stock,
            'waiting'             => $waiting,
            'deliver'             => $deliver,
            'palletised'          => $palletised,
            'producedMonthCount'  => $producedMonthCount,
            'stockMonthEndCount'  => $stockMonthEndCount,
            'nodMonthCount'       => $nodMonthCount,
            'editData'            => $editData,
            'batchRolls'          => $batchRolls,
            'conn'                => $conn,
        ]);
    }

    /**
     * Officer Crosscheck Dashboard (formerly finish_product_crosscheck.php)
     */
    public function crosscheck(): void
    {
        $role = strtolower($_SESSION['role'] ?? '');
        $this->requireRole(['officer', 'slitting', 'mkl3', 'admin', 'qc']);

        $conn = $this->conn;
        $month = isset($_GET['month']) ? (int)$_GET['month'] : 8;
        if ($month < 1 || $month > 12) { $month = 8; }
        $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        if ($year < 2000 || $year > 2100) { $year = (int)date('Y'); }

        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $statusFilter = $_GET['status_filter'] ?? 'all';
        if (!in_array($statusFilter, ['all', 'discrepancy', 'matched'], true)) {
            $statusFilter = 'all';
        }

        $monthNames = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
        ];

        $sql = "
            SELECT 
                sp.id AS product_id,
                sp.product,
                sp.lot_no AS actual_lot_no,
                sp.coil_no AS actual_coil_no,
                sp.roll_no AS actual_roll_no,
                sp.width AS actual_width,
                COALESCE(sp.actual_length, sp.length, 0.0) AS actual_length,
                sp.status AS product_status,
                sp.date_in,
                sp.mother_id,
                sp.customer_name,
                sp.ref_no,
                mc.lot_no AS planned_lot_no,
                mc.coil_no AS planned_coil_no,
                mc.product AS mother_product,
                mc.width AS mother_width,
                mc.length AS planned_mother_length,
                spl.width AS plan_width,
                spl.length AS plan_length
            FROM slitting_product sp
            LEFT JOIN mother_coil mc ON mc.id = sp.mother_id
            LEFT JOIN slitting_plans spl ON spl.mother_id = sp.mother_id AND spl.width = sp.width
            WHERE sp.is_voided = 0
              AND MONTH(sp.date_in) = ? 
              AND YEAR(sp.date_in) = ?
        ";

        $types = 'ii';
        $params = [$month, $year];

        if ($search !== '') {
            $sql .= " AND (sp.coil_no LIKE ? OR sp.lot_no LIKE ? OR sp.roll_no LIKE ? OR sp.product LIKE ? OR mc.coil_no LIKE ? OR sp.customer_name LIKE ? OR sp.ref_no LIKE ?)";
            $st = '%' . $search . '%';
            $types .= 'sssssss';
            $params = array_merge($params, [$st, $st, $st, $st, $st, $st, $st]);
        }

        $sql .= " ORDER BY sp.id DESC";

        $stmt = $conn->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $records = [];
        $totalRolls = 0;
        $matchedRolls = 0;
        $discrepancyRolls = 0;
        $widthMismatches = 0;
        $lengthMismatches = 0;
        $lotMismatches = 0;

        while ($row = $result->fetch_assoc()) {
            $actualWidth = (float)($row['actual_width'] ?? 0.0);
            if (isset($row['plan_width']) && $row['plan_width'] !== null && (float)$row['plan_width'] > 0) {
                $plannedWidth = (float)$row['plan_width'];
            } elseif (isset($row['mother_width']) && $row['mother_width'] !== null && (float)$row['mother_width'] > 0) {
                $plannedWidth = (float)$row['mother_width'];
            } else {
                $plannedWidth = $actualWidth;
            }
            $widthDiff = round($actualWidth - $plannedWidth, 2);
            $widthMatch = abs($widthDiff) <= 0.01;

            $actualLength = (float)($row['actual_length'] ?? 0.0);
            if (isset($row['plan_length']) && $row['plan_length'] !== null && (float)$row['plan_length'] > 0) {
                $plannedLength = (float)$row['plan_length'];
            } elseif (isset($row['planned_mother_length']) && $row['planned_mother_length'] !== null && (float)$row['planned_mother_length'] > 0) {
                $plannedLength = (float)$row['planned_mother_length'];
            } else {
                $plannedLength = $actualLength;
            }
            $lengthDiff = round($actualLength - $plannedLength, 2);
            $lengthMatch = abs($lengthDiff) <= 0.5;

            $actLot = trim($row['actual_lot_no'] ?? '');
            $planLot = trim($row['planned_lot_no'] ?? '');
            $cleanActLot = rtrim($actLot, 'a..zA..Z');
            $cleanPlanLot = rtrim($planLot, 'a..zA..Z');
            $lotMatch = ($actLot === $planLot) || ($cleanActLot !== '' && $cleanActLot === $cleanPlanLot);

            $hasDiscrepancy = (!$widthMatch || !$lengthMatch || !$lotMatch);

            $totalRolls++;
            if ($hasDiscrepancy) {
                $discrepancyRolls++;
                if (!$widthMatch) $widthMismatches++;
                if (!$lengthMatch) $lengthMismatches++;
                if (!$lotMatch) $lotMismatches++;
            } else {
                $matchedRolls++;
            }

            $row['planned_width'] = $plannedWidth;
            $row['width_diff'] = $widthDiff;
            $row['width_match'] = $widthMatch;
            $row['planned_length'] = $plannedLength;
            $row['length_diff'] = $lengthDiff;
            $row['length_match'] = $lengthMatch;
            $row['lot_match'] = $lotMatch;
            $row['has_discrepancy'] = $hasDiscrepancy;

            if ($statusFilter === 'discrepancy' && !$hasDiscrepancy) continue;
            if ($statusFilter === 'matched' && $hasDiscrepancy) continue;

            $records[] = $row;
        }
        $stmt->close();

        $this->render('finish_product/crosscheck', [
            'month'            => $month,
            'year'             => $year,
            'search'           => $search,
            'statusFilter'     => $statusFilter,
            'monthNames'       => $monthNames,
            'records'          => $records,
            'totalRolls'       => $totalRolls,
            'matchedRolls'     => $matchedRolls,
            'discrepancyRolls' => $discrepancyRolls,
            'widthMismatches'  => $widthMismatches,
            'lengthMismatches' => $lengthMismatches,
            'lotMismatches'    => $lotMismatches,
        ]);
    }

    /**
     * Add manual finished product (formerly finish_product_add.php)
     */
    public function addManual(): void
    {
        $this->requireRole(['slitting']);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $coil_no = $this->conn->real_escape_string($_POST['coil_no'] ?? '');
            $product = $this->conn->real_escape_string($_POST['product'] ?? '');
            $lot_no  = $this->conn->real_escape_string($_POST['lot_no'] ?? '');
            $jumlah  = intval($_POST['jumlah'] ?? 1);
            if ($jumlah < 1) $jumlah = 1;

            for ($i = 0; $i < $jumlah; $i++) {
                $stmt = $this->conn->prepare("INSERT INTO finish_product (product, lot_no, coil_no, status, date_created) VALUES (?, ?, ?, 'IN', NOW())");
                $stmt->bind_param("sss", $product, $lot_no, $coil_no);
                $stmt->execute();
                $new_id = $stmt->insert_id;
                $stmt->close();

                $code = 'FIN' . str_pad($new_id, 6, '0', STR_PAD_LEFT);
                $stmt2 = $this->conn->prepare("UPDATE finish_product SET code=? WHERE id=?");
                $stmt2->bind_param("si", $code, $new_id);
                $stmt2->execute();
                $stmt2->close();
            }

            $this->redirect("finish_product.php?success=created");
            return;
        }

        $this->redirect("finish_product.php");
    }

    /**
     * Add Slitting Interface (formerly add_slitting.php)
     */
    public function addSlitting(): void
    {
        $this->requireRole(['slitting']);

        $conn = $this->conn;
        $from_stock = isset($_GET['stock_id']);
        $source_data = null;
        $mother_data = null;
        $source_type = '';
        $mother_id = null;
        $stock_id = null;

        if ($from_stock) {
            $stock_id = intval($_GET['stock_id']);
            $query = "SELECT * FROM stock_raw_material WHERE id=$stock_id AND status='IN'";
            $source_data = $conn->query($query)->fetch_assoc();
            $source_type = 'stock';

            if (!$source_data) {
                die("Stock item not found or already used.");
            }

            if (!empty($source_data['source_id'])) {
                $mother_id = $source_data['source_id'];
                $mother_data = $conn->query("SELECT * FROM mother_coil WHERE id=$mother_id")->fetch_assoc();
            } else {
                $mother_query = "SELECT * FROM mother_coil WHERE lot_no LIKE '" . $conn->real_escape_string($source_data['lot_no']) . "%' ORDER BY id DESC LIMIT 1";
                $mother_data = $conn->query($mother_query)->fetch_assoc();
            }
        } else {
            $mother_id = intval($_GET['mother_id'] ?? 0);
            $query = "SELECT * FROM mother_coil WHERE id=$mother_id AND status='IN'";
            $source_data = $conn->query($query)->fetch_assoc();
            $source_type = 'mother';

            if (!$source_data) {
                die("Mother coil not found or already used.");
            }
            $mother_data = $source_data;
        }

        $isLeftoverCut = $from_stock && (($source_data['source_type'] ?? '') === 'slitting_cut_into_2');
        $slittingPlan = [];
        if (!$isLeftoverCut && $mother_id) {
            $planStmt = $conn->prepare("
                SELECT roll_seq, planned_width, customer_name, ref_no
                FROM slitting_plans
                WHERE mother_coil_id = ?
                ORDER BY sort_order ASC, id ASC
            ");
            if ($planStmt) {
                $planStmt->bind_param("i", $mother_id);
                $planStmt->execute();
                $slittingPlan = $planStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $planStmt->close();
            }
        }

        $this->render('slitting/add', [
            'from_stock'   => $from_stock,
            'source_data'  => $source_data,
            'mother_data'  => $mother_data,
            'source_type'  => $source_type,
            'mother_id'    => $mother_id,
            'stock_id'     => $stock_id,
            'slittingPlan' => $slittingPlan,
            'conn'         => $conn,
        ]);
    }

    /**
     * Edit Slitting Roll (formerly edit_slitting.php)
     */
    public function editSlitting(): void
    {
        $this->requireRole(['slitting']);

        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) die("Invalid product ID");

        $stmt = $this->conn->prepare("SELECT * FROM slitting_product WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$product) die("Product not found");

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $roll_no     = trim($_POST['roll_no']);
            $width       = trim($_POST['width']);
            $length      = trim($_POST['length']);
            $length_type = $_POST['length_type'];

            $stmt = $this->conn->prepare("UPDATE slitting_product SET roll_no=?, width=?, length=?, length_type=? WHERE id=?");
            $stmt->bind_param("ssssi", $roll_no, $width, $length, $length_type, $id);
            $stmt->execute();
            $stmt->close();

            $this->redirect("finish_product.php?success=updated");
            return;
        }

        $this->render('slitting/edit', [
            'id'      => $id,
            'product' => $product,
        ]);
    }

    /**
     * Delete Slitting Roll (formerly delete_slitting.php)
     */
    public function deleteSlitting(): void
    {
        $this->requireRole(['slitting']);

        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            $this->redirect("slitting_product.php?error=missing_id");
            return;
        }

        $this->productModel->deleteRoll($id, $_SESSION['role'] ?? 'system');
        $this->redirect("slitting_product.php?success=deleted");
    }

    /**
     * Save Slitting Production (formerly save_slitting.php)
     */
    public function saveSlitting(): void
    {
        $this->requireRole(['slitting']);

        $conn = $this->conn;

        $source_type = $_POST['source_type'] ?? '';
        $mother_id   = intval($_POST['mother_id']  ?? 0);
        $stock_id    = intval($_POST['stock_id']   ?? 0);
        $cut_type    = $_POST['cut_type']          ?? '';
        $product     = $conn->real_escape_string($_POST['product'] ?? '');
        $lot_no      = $conn->real_escape_string($_POST['lot_no']  ?? '');
        $coil_no     = $conn->real_escape_string($_POST['coil_no'] ?? '');

        $roll_nos    = $_POST['roll_no']     ?? [];
        $cut_letters = $_POST['cut_letter']  ?? [];
        $lengths     = $_POST['length']      ?? [];
        $widths      = $_POST['width']       ?? [];
        $send_to_sfc = $_POST['send_to_sfc'] ?? [];

        $sfc_balance_width = floatval($_POST['sfc_balance_width'] ?? 0);

        if (!$mother_id || !$cut_type) {
            die("Error: Missing required fields (mother_id or cut_type)");
        }

        // V-Coil detection (based on the mother's Lot/Coil No)
        $isVCoil = (stripos($lot_no, 'V') !== false) || (stripos($coil_no, 'V') !== false);

        $resolveSlitProductCode = function (string $motherProductCode, bool $isVCoil, $slitWidth): array {
            $tsWidths = [66.5, 81.0, 109.5, 118.0, 309.0];
            $width = (float)$slitWidth;
            $matchesTsWidth = false;
            foreach ($tsWidths as $tsw) {
                if (abs($width - $tsw) < 0.01) {
                    $matchesTsWidth = true;
                    break;
                }
            }
            $parts  = explode('-', $motherProductCode, 2);
            $suffix = $parts[1] ?? $motherProductCode;

            if ($isVCoil && $matchesTsWidth) {
                return ['code' => 'TS-' . $suffix, 'is_ts' => true];
            }
            if ($isVCoil) {
                return ['code' => 'RS-' . $suffix, 'is_ts' => false];
            }
            return ['code' => $motherProductCode, 'is_ts' => false];
        };

        $conn->begin_transaction();

        try {
            // Fetch mother coil
            $mother_result = $conn->query("SELECT * FROM mother_coil WHERE id=$mother_id");
            if (!$mother_result || $mother_result->num_rows === 0) {
                throw new \Exception("Mother coil not found for ID: $mother_id");
            }
            $mother = $mother_result->fetch_assoc();

            $balance_stock_id = null;

            // CUT INTO 2: save leftover to stock_raw_material
            if ($cut_type === 'cut_into_2') {
                $slit_quantity   = floatval($_POST['slit_quantity'] ?? 0);
                $leftover_length = floatval($_POST['stock']         ?? 0);

                if ($leftover_length > 0) {
                    $new_lot_no = $lot_no;
                    $grade_val  = $mother['grade'] ?? '';
                    $width_val  = floatval($mother['width']);

                    $insert_stock = $conn->prepare(
                        "INSERT INTO stock_raw_material
                             (lot_no, coil_no, grade, width, length,
                              status, source_type, source_id, date_in)
                         VALUES (?, ?, ?, ?, ?, 'IN', 'slitting_cut_into_2', ?, NOW())"
                    );
                    $insert_stock->bind_param(
                        "sssddi",
                        $new_lot_no, $coil_no, $grade_val,
                        $width_val, $leftover_length, $mother_id
                    );
                    if (!$insert_stock->execute()) {
                        throw new \Exception("Failed to save leftover stock: " . $insert_stock->error);
                    }
                    $balance_stock_id = $conn->insert_id;
                    $insert_stock->close();

                    $this->productModel->logProcess('stock', $balance_stock_id, $mother_id,
                        null, 'IN', 'cut_into_2_leftover',
                        "Leftover {$leftover_length}m saved from Cut Into 2", $_SESSION['role'] ?? 'system');
                }

                if ($stock_id > 0) {
                    $conn->query("UPDATE stock_raw_material SET status='OUT', updated_at=NOW() WHERE id=$stock_id");
                    $this->productModel->logProcess('stock', $stock_id, $mother_id,
                        'IN', 'OUT', 'consumed_by_cut_into_2', '', $_SESSION['role'] ?? 'system');
                }

                $conn->query("UPDATE raw_material_log SET status='OUT', date_out=NOW() WHERE mother_id=$mother_id LIMIT 1");
            }

            // Fetch Slitting Plan (if any) to map customer & ref_no by roll sequence
            $planCustomerMap = [];
            $planRefMap      = [];
            if ($mother_id) {
                $planRes = $conn->query("SELECT roll_seq, customer_name, ref_no FROM slitting_plans WHERE mother_coil_id = " . intval($mother_id) . " ORDER BY sort_order ASC, id ASC");
                if ($planRes) {
                    $pIdx = 0;
                    while ($pRow = $planRes->fetch_assoc()) {
                        $cName = trim($pRow['customer_name'] ?? '');
                        $rNo   = trim($pRow['ref_no'] ?? '');
                        $seqKey = strtoupper(trim($pRow['roll_seq']));
                        if ($seqKey !== '') {
                            if ($cName !== '') $planCustomerMap[$seqKey] = $cName;
                            if ($rNo !== '')   $planRefMap[$seqKey]      = $rNo;
                        }
                        if ($cName !== '') $planCustomerMap['idx_' . $pIdx] = $cName;
                        if ($rNo !== '')   $planRefMap['idx_' . $pIdx]      = $rNo;
                        $pIdx++;
                    }
                }
            }

            // Process each roll
            foreach ($roll_nos as $index => $roll_no) {
                $length       = floatval($lengths[$index]  ?? 0);
                $width        = floatval($widths[$index]   ?? 0);
                $cut_letter   = trim($cut_letters[$index]  ?? '');
                $roll_lot_no  = $lot_no . $cut_letter;
                $roll_no_safe = $roll_no;

                $plannedCustomer = null;
                $plannedRefNo    = null;
                $roll_seq_key = strtoupper(trim($roll_no_safe));
                if (isset($planCustomerMap[$roll_seq_key])) {
                    $plannedCustomer = $planCustomerMap[$roll_seq_key];
                } elseif (isset($planCustomerMap['idx_' . $index])) {
                    $plannedCustomer = $planCustomerMap['idx_' . $index];
                }
                if (isset($planRefMap[$roll_seq_key])) {
                    $plannedRefNo = $planRefMap[$roll_seq_key];
                } elseif (isset($planRefMap['idx_' . $index])) {
                    $plannedRefNo = $planRefMap['idx_' . $index];
                }

                $resolved     = $resolveSlitProductCode($product, $isVCoil, $width);
                $roll_product = $resolved['code'];

                $roll_number_str  = (string)($index + 1);
                $send_to_sfc_flag = in_array($roll_number_str, $send_to_sfc);

                // PATH A: Send to SFC
                if ($send_to_sfc_flag) {
                    $sfc_stmt = $conn->prepare(
                        "INSERT INTO sfc
                             (mother_id, product, lot_no, coil_no, roll_no,
                              width, length, action, date_created)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'slitting', NOW())"
                    );
                    $sfc_stmt->bind_param(
                        "issssdd",
                        $mother_id, $roll_product, $roll_lot_no, $coil_no,
                        $roll_no_safe, $width, $length
                    );
                    if (!$sfc_stmt->execute()) {
                        throw new \Exception("Failed to insert into SFC: " . $sfc_stmt->error);
                    }
                    $sfc_id = $conn->insert_id;
                    $sfc_stmt->close();

                    $this->productModel->logProcess('sfc', $sfc_id, $mother_id,
                        null, 'IN', 'sent_to_sfc_from_slitting',
                        "Roll {$roll_no_safe} width={$width}mm length={$length}m product={$roll_product}", $_SESSION['role'] ?? 'system');

                    $rn_esc = $conn->real_escape_string($roll_no_safe);
                    $conn->query(
                        "INSERT INTO slitting_audit_log
                             (mother_id, action, roll_no, destination, created_at)
                         VALUES ($mother_id, 'send_to_sfc', '$rn_esc', 'sfc_stock', NOW())"
                    );
                    continue;
                }

                // PATH B: Go to Finished Products
                $slit_quantity_val = floatval($_POST['slit_quantity'] ?? 0);

                $display_roll_no = $roll_no_safe;
                $base_roll_key   = $roll_lot_no . $coil_no . $display_roll_no;
                $target_roll_key = $base_roll_key;

                $check_key_stmt = $conn->prepare(
                    "SELECT id FROM slitting_product 
                     WHERE roll_key = ? AND (is_voided = 0 OR is_voided IS NULL)"
                );
                $check_key_stmt->bind_param("s", $target_roll_key);
                $check_key_stmt->execute();
                $dup_res = $check_key_stmt->get_result();
                if ($dup_res && $dup_res->num_rows > 0) {
                    $suffix_num = 1;
                    while (true) {
                        $candidate_key = $base_roll_key . "_" . $suffix_num;
                        $chk = $conn->prepare(
                            "SELECT id FROM slitting_product 
                             WHERE roll_key = ? AND (is_voided = 0 OR is_voided IS NULL)"
                        );
                        $chk->bind_param("s", $candidate_key);
                        $chk->execute();
                        if ($chk->get_result()->num_rows === 0) {
                            $target_roll_key = $candidate_key;
                            $chk->close();
                            break;
                        }
                        $chk->close();
                        $suffix_num++;
                    }
                }
                $check_key_stmt->close();

                $col_check = $conn->query(
                    "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'slitting_product'
                       AND COLUMN_NAME  = 'leftover_length'"
                );
                $col_row        = $col_check->fetch_assoc();
                $leftover_col   = ($col_row['cnt'] > 0) ? 'leftover_length' : 'stock';

                $insert_stmt = $conn->prepare(
                    "INSERT INTO slitting_product
                         (product, lot_no, coil_no, roll_no, roll_key, width, length,
                          mother_id, status, cut_type, slit_quantity,
                          customer_name, ref_no, {$leftover_col}, parent_slit_id, date_in, source)
                     VALUES
                         (?, ?, ?, ?, ?, ?, ?, ?, 'IN', ?, ?, ?, ?, NULL, NULL, NOW(), ?)"
                );
                if (!$insert_stmt) {
                    throw new \Exception("Prepare failed: " . $conn->error);
                }
                $insert_stmt->bind_param(
                    "sssssddisdsss",
                    $roll_product, $roll_lot_no, $coil_no, $display_roll_no, $target_roll_key,
                    $width, $length, $mother_id,
                    $cut_type, $slit_quantity_val, $plannedCustomer, $plannedRefNo,
                    $source_type
                );
                if (!$insert_stmt->execute()) {
                    throw new \Exception("Failed to insert slitting product: " . $insert_stmt->error);
                }
                $new_slit_id = $conn->insert_id;
                $insert_stmt->close();

                $this->productModel->logProcess('slitting', $new_slit_id, $mother_id,
                    null, 'IN',
                    "slitting_{$cut_type}",
                    "Roll {$roll_no_safe} width={$width}mm length={$length}m product={$roll_product}", $_SESSION['role'] ?? 'system');

                $rn_esc = $conn->real_escape_string($roll_no_safe);
                $conn->query(
                    "INSERT INTO slitting_audit_log
                         (mother_id, action, roll_no, destination, created_at)
                     VALUES ($mother_id, 'send_to_finished', '$rn_esc', 'slitting_product', NOW())"
                );
            }

            // Normal slitting: mark mother OUT
            if ($cut_type === 'normal') {
                $conn->query("UPDATE mother_coil SET stock=0, status='OUT', date_out=NOW() WHERE id=$mother_id");

                if ($stock_id > 0) {
                    $conn->query("UPDATE stock_raw_material SET status='OUT', updated_at=NOW() WHERE id=$stock_id");
                }

                $conn->query(
                    "INSERT INTO mother_coil_audit_log
                         (mother_id, action_type, performed_at, remark)
                     VALUES ($mother_id, 'OUT', NOW(), 'Normal slitting completed')"
                );
            }

            // Cut Into 2: mark mother OUT
            if ($cut_type === 'cut_into_2') {
                $conn->query("UPDATE mother_coil SET stock=0, status='OUT', date_out=NOW() WHERE id=$mother_id");
            }

            // Optional SFC balance width entry
            if ($sfc_balance_width > 0) {
                $balance_length  = floatval($lengths[0] ?? 0);
                $balance_roll_no = "BALANCE";

                $balance_resolved = $resolveSlitProductCode($product, $isVCoil, $sfc_balance_width);
                $balance_product  = $balance_resolved['code'];

                $sfc_bal = $conn->prepare(
                    "INSERT INTO sfc
                         (mother_id, product, lot_no, coil_no, roll_no,
                          width, length, action, date_created)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'slitting_balance', NOW())"
                );
                $sfc_bal->bind_param(
                    "issssdd",
                    $mother_id, $balance_product, $lot_no, $coil_no,
                    $balance_roll_no, $sfc_balance_width, $balance_length
                );
                if (!$sfc_bal->execute()) {
                    throw new \Exception("Failed to insert SFC balance: " . $sfc_bal->error);
                }
                $sfc_bal_id = $conn->insert_id;
                $sfc_bal->close();

                $this->productModel->logProcess('sfc', $sfc_bal_id, $mother_id,
                    null, 'IN', 'balance_width_to_sfc',
                    "Balance width {$sfc_balance_width}mm saved to SFC product={$balance_product}", $_SESSION['role'] ?? 'system');
            }

            $conn->commit();

            $_SESSION['success'] = "✓ Slitting products saved successfully (" . count($roll_nos) . " rolls)";
            $this->redirect("raw_material.php");
            return;

        } catch (\Exception $e) {
            $conn->rollback();
            die("Error: " . $e->getMessage());
        }
    }

    /**
     * Slitting Product Management & Edit (formerly slitting_product.php)
     */
    public function slittingProduct(): void
    {
        $this->requireRole(['slitting', 'mkl3', 'admin']);

        $conn = $this->conn;

        // === Handle Update Product ===
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
            $id            = intval($_POST['id']);
            $coil_no       = $_POST['coil_no'] ?? '';
            $product       = $_POST['product'] ?? '';
            $lot_no        = $_POST['lot_no'] ?? '';
            $roll_no       = $_POST['roll_no'] ?? '';
            $width         = $_POST['width'] ?? '';
            $length        = $_POST['length'] ?? '';
            $actual_length = $_POST['actual_length'] ?? '';

            $beforeStmt = $conn->prepare("
                SELECT lot_no, coil_no, roll_no, width, length, actual_length
                FROM slitting_product WHERE id = ?
            ");
            $beforeStmt->bind_param("i", $id);
            $beforeStmt->execute();
            $before = $beforeStmt->get_result()->fetch_assoc();
            $beforeStmt->close();

            $stmt = $conn->prepare("UPDATE slitting_product
                SET coil_no=?, product=?, lot_no=?, roll_no=?, width=?, length=?, actual_length=?
                WHERE id=?");
            $stmt->bind_param("sssssssi", $coil_no, $product, $lot_no, $roll_no, $width, $length, $actual_length, $id);
            $stmt->execute();
            $stmt->close();

            if ($before) {
                $qrFieldsChanged =
                       (string)$before['lot_no']        !== (string)$lot_no
                    || (string)$before['coil_no']       !== (string)$coil_no
                    || (string)$before['roll_no']       !== (string)$roll_no
                    || (float)$before['width']          !== (float)$width
                    || (float)$before['length']         !== (float)$length
                    || (float)($before['actual_length'] ?? 0) !== (float)($actual_length !== '' ? $actual_length : 0);
                if ($qrFieldsChanged) {
                    $resetStmt = $conn->prepare("
                        UPDATE slitting_product SET is_printed = 0 WHERE id = ?
                    ");
                    $resetStmt->bind_param("i", $id);
                    $resetStmt->execute();
                    $resetStmt->close();
                }
            }

            $this->redirect("slitting_product.php?success=update");
            return;
        }

        // === Soft-delete (void) Product ===
        if (isset($_GET['delete'])) {
            $id   = intval($_GET['delete']);
            $stmt = $conn->prepare("
                UPDATE slitting_product
                SET is_voided=1, voided_at=NOW(), voided_reason='manual_delete'
                WHERE id=?
            ");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            $this->redirect("slitting_product.php?success=delete");
            return;
        }

        // === Search Logic ===
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $tokens = $search !== '' ? preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) : [];

        $printFilter = $_GET['print_status'] ?? '';
        if (!in_array($printFilter, ['printed', 'not_printed'], true)) {
            $printFilter = '';
        }

        $print_where = '';
        if ($printFilter === 'printed') {
            $print_where = " AND (is_printed = 1)";
        } elseif ($printFilter === 'not_printed') {
            $print_where = " AND (is_printed = 0 OR is_printed IS NULL)";
        }

        $base_where = "
            (is_voided    = 0 OR is_voided    IS NULL)
            AND (is_recoiled  = 0 OR is_recoiled  IS NULL)
            AND (is_reslitted = 0 OR is_reslitted IS NULL)
        ";

        if (count($tokens) === 1) {
            $stmt = $conn->prepare("
                SELECT * FROM slitting_product
                WHERE ($base_where)
                  AND (coil_no LIKE ? OR product LIKE ? OR lot_no LIKE ? OR roll_no LIKE ?)
                  $print_where
                ORDER BY id DESC
            ");
            if (!$stmt) { die("Query preparation failed: " . htmlspecialchars($conn->error)); }
            $like = '%' . $tokens[0] . '%';
            $stmt->bind_param("ssss", $like, $like, $like, $like);
            $stmt->execute();
            $slitting = $stmt->get_result();
            $stmt->close();
        } elseif (count($tokens) === 2) {
            $stmt = $conn->prepare("
                SELECT * FROM slitting_product
                WHERE ($base_where)
                  AND lot_no LIKE ? AND coil_no LIKE ?
                  $print_where
                ORDER BY id DESC
            ");
            if (!$stmt) { die("Query preparation failed: " . htmlspecialchars($conn->error)); }
            $likeLot  = '%' . $tokens[0] . '%';
            $likeCoil = '%' . $tokens[1] . '%';
            $stmt->bind_param("ss", $likeLot, $likeCoil);
            $stmt->execute();
            $slitting = $stmt->get_result();
            $stmt->close();
        } elseif (count($tokens) >= 3) {
            $stmt = $conn->prepare("
                SELECT * FROM slitting_product
                WHERE ($base_where)
                  AND lot_no LIKE ? AND coil_no LIKE ? AND roll_no LIKE ?
                  $print_where
                ORDER BY id DESC
            ");
            if (!$stmt) { die("Query preparation failed: " . htmlspecialchars($conn->error)); }
            $likeLot  = '%' . $tokens[0] . '%';
            $likeCoil = '%' . $tokens[1] . '%';
            $likeRoll = '%' . $tokens[2] . '%';
            $stmt->bind_param("sss", $likeLot, $likeCoil, $likeRoll);
            $stmt->execute();
            $slitting = $stmt->get_result();
            $stmt->close();
        } else {
            $slitting = $conn->query("
                SELECT * FROM slitting_product
                WHERE $base_where
                $print_where
                ORDER BY id DESC
            ");
        }

        $success = $_GET['success'] ?? null;

        // === Fetch Single Product for Edit ===
        $editData = null;
        if (isset($_GET['edit'])) {
            $id  = intval($_GET['edit']);
            $res = $conn->query("SELECT * FROM slitting_product WHERE id=$id");
            if ($res && $res->num_rows > 0) {
                $editData = $res->fetch_assoc();
            } else {
                die("Product not found!");
            }
        }

        $this->render('slitting/list', [
            'conn'        => $conn,
            'slitting'    => $slitting,
            'search'      => $search,
            'printFilter' => $printFilter,
            'success'     => $success,
            'editData'    => $editData,
        ]);
    }

    /**
     * Single Product Update (formerly update_product.php)
     */
    public function updateProduct(): void
    {
        $this->requireRole(['slitting']);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $product_id = intval($_POST['product_id'] ?? 0);
            $roll_no    = trim($_POST['roll_no'] ?? '');
            $width      = trim($_POST['width'] ?? '');
            $length     = trim($_POST['length'] ?? '');

            if ($product_id <= 0) die("Error: Invalid Product ID.");

            $stmt = $this->conn->prepare("UPDATE slitting_product SET roll_no = ?, width = ?, length = ? WHERE id = ?");
            $stmt->bind_param("sssi", $roll_no, $width, $length, $product_id);
            $stmt->execute();
            $stmt->close();

            $this->redirect("finish_product.php?success=updated");
            return;
        }

        $this->redirect("finish_product.php");
    }

    /**
     * Update Mother Coil Status (formerly update_status.php)
     */
    public function updateStatus(): void
    {
        $this->requireRole(['slitting', 'mkl3', 'admin']);

        $id     = $_GET['id'] ?? null;
        $action = $_GET['action'] ?? null;

        if ($id && $action) {
            if ($action === "in") {
                $stmt = $this->conn->prepare("UPDATE mother_coil SET status='IN', date_in=NOW() WHERE id=?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                echo "Mother Coil $id status updated to IN";
            } elseif ($action === "out") {
                $stmt = $this->conn->prepare("UPDATE mother_coil SET status='OUT', date_out=NOW() WHERE id=?");
                $stmt->bind_param("i", $id);
                $stmt->execute();

                $this->conn->query("INSERT INTO finish_product (mother_id, coil_no, product, date_in, date_created, status) SELECT id, coil_no, product, date_in, NOW(), 'IN' FROM mother_coil WHERE id=$id");
                echo "Mother Coil $id status updated to OUT & moved to Finish Product";
            }
        } else {
            echo "Invalid request!";
        }
    }

    /**
     * Barcode scan action from finish product (formerly scan_product_action.php)
     */
    public function scanProduct(): void
    {
        require_once __DIR__ . '/../../config.php';
        require_once __DIR__ . '/../../PalletManager.php';

        $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host      = $_SERVER['HTTP_HOST'];
        $scriptDir = rtrim(dirname($_SERVER['PHP_SELF']), '/');
        $BASE_URL  = $protocol . '://' . $host . $scriptDir;

        $qr = trim($_POST['qr'] ?? '');
        $qr = preg_replace('/[[:cntrl:]]/', '', $qr);
        $qr = preg_replace('/^\][A-Za-z][0-9]/', '', $qr);
        $qr = preg_replace('/^[^\w]{0,6}(?=LOT=|COIL=|ROLL=)/i', '', $qr);
        $qr = preg_replace('/^[\w]{1,6}(?=LOT=|COIL=|ROLL=)/i',  '', $qr);
        $qr = trim($qr);

        if ($qr === '') {
            $this->redirect("finish_product.php?scan=empty");
            return;
        }

        // Format checks
        $lot = ''; $coil = ''; $roll = '';
        if (strpos($qr, '=') !== false) {
            foreach (explode(';', $qr) as $segment) {
                if (strpos($segment, '=') === false) continue;
                [$k, $v] = explode('=', $segment, 2);
                $k = strtoupper(trim($k));
                if ($k === 'LOT') $lot = trim($v);
                if ($k === 'COIL') $coil = trim($v);
                if ($k === 'ROLL') $roll = trim($v);
            }
        } else {
            $tokens = preg_split('/\s+/', $qr);
            $lot  = trim($tokens[0] ?? '');
            $coil = trim($tokens[1] ?? '');
            $roll = trim($tokens[2] ?? '');
        }

        $stmt = $this->conn->prepare("
            SELECT sp.*, pi.pallet_id, p.status AS pallet_status, p.pallet_no
            FROM slitting_product sp
            LEFT JOIN pallet_items pi ON pi.slitting_product_id = sp.id
            LEFT JOIN pallets p ON p.id = pi.pallet_id
            WHERE sp.lot_no = ? AND sp.coil_no = ? AND sp.roll_no = ? AND sp.is_voided = 0
            LIMIT 1
        ");
        $stmt->bind_param("sss", $lot, $coil, $roll);
        $stmt->execute();
        $p = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$p) {
            $this->redirect("finish_product.php?scan=not_found&lot=" . urlencode($lot) . "&coil=" . urlencode($coil) . "&roll=" . urlencode($roll));
            return;
        }

        if ($p['pallet_id']) {
            if ($p['pallet_status'] === 'approved') {
                $this->redirect("pallet_deliver.php?pallet_id=" . $p['pallet_id']);
            } else {
                $this->redirect("pallet.php?pallet_id=" . $p['pallet_id']);
            }
        } else {
            $this->redirect("pallet.php?scan_roll=" . urlencode($lot . ' ' . $coil . ' ' . $roll));
        }
    }

    /**
     * Global Traceability Report (formerly tracking_product.php)
     */
    public function tracking(): void
    {
        $this->requireRole(['slitting', 'mkl3', 'admin', 'qc', 'officer']);

        $conn = $this->conn;
        $search          = trim($_GET['search']   ?? '');
        $filter_status   = trim($_GET['status']   ?? '');
        $filter_month    = intval($_GET['month']  ?? 0);
        $filter_year     = intval($_GET['year']   ?? date('Y'));
        $filter_customer = trim($_GET['customer'] ?? '');
        $filter_source   = trim($_GET['source']   ?? '');

        $consumedSql = "(sp.is_reslitted = 1 OR sp.is_recoiled = 1)";

        $sql = "
            SELECT
                sp.id,
                sp.product,
                sp.lot_no,
                sp.coil_no,
                sp.roll_no,
                sp.width,
                sp.actual_length,
                sp.length,
                sp.status,
                sp.stock_counted,
                sp.is_reslitted,
                sp.is_recoiled,
                sp.date_in,
                sp.date_out,
                sp.delivered_at,
                sp.original_source,
                sp.customer_name,
                sp.ref_no,
                mc.grade
            FROM slitting_product sp
            LEFT JOIN mother_coil mc ON mc.id = sp.mother_id
            WHERE sp.is_voided = 0
              AND NOT {$consumedSql}
        ";

        $params = [];
        $types  = '';

        $tokens = ($search !== '') ? preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) : [];

        if (count($tokens) === 1) {
            $sql   .= " AND (
                            sp.lot_no        LIKE ? OR
                            sp.coil_no       LIKE ? OR
                            sp.roll_no       LIKE ? OR
                            sp.product       LIKE ? OR
                            sp.customer_name LIKE ?
                        )";
            $like   = '%' . $tokens[0] . '%';
            $params = array_merge($params, [$like, $like, $like, $like, $like]);
            $types .= 'sssss';
        } elseif (count($tokens) === 2) {
            $sql      .= " AND sp.lot_no LIKE ? AND sp.coil_no LIKE ?";
            $likeLot   = '%' . $tokens[0] . '%';
            $likeCoil  = '%' . $tokens[1] . '%';
            $params    = array_merge($params, [$likeLot, $likeCoil]);
            $types    .= 'ss';
        } elseif (count($tokens) >= 3) {
            $sql      .= " AND sp.lot_no LIKE ? AND sp.coil_no LIKE ? AND sp.roll_no LIKE ?";
            $likeLot   = '%' . $tokens[0] . '%';
            $likeCoil  = '%' . $tokens[1] . '%';
            $likeRoll  = '%' . $tokens[2] . '%';
            $params    = array_merge($params, [$likeLot, $likeCoil, $likeRoll]);
            $types    .= 'sss';
        }

        if ($filter_status !== '') {
            $sql    .= " AND sp.status = ?";
            $params[] = $filter_status;
            $types  .= 's';
        }

        if ($filter_customer !== '') {
            $sql    .= " AND sp.customer_name LIKE ?";
            $params[] = '%' . $filter_customer . '%';
            $types  .= 's';
        }

        if ($filter_source === 'sfc') {
            $sql .= " AND LOWER(sp.original_source) = 'sfc'";
        } elseif ($filter_source === 'initial_stock') {
            $sql .= " AND LOWER(sp.original_source) LIKE '%initial%'";
        } elseif ($filter_source === 'raw_material') {
            $sql .= " AND (sp.original_source IS NULL OR sp.original_source = ''
                        OR (LOWER(sp.original_source) != 'sfc' AND LOWER(sp.original_source) NOT LIKE '%initial%'))";
        }

        if ($filter_month > 0) {
            $sql .= " AND (
                (sp.status = 'DELIVERED' AND MONTH(sp.delivered_at) = ? AND YEAR(sp.delivered_at) = ?)
                OR
                (sp.status != 'DELIVERED' AND MONTH(sp.date_in) = ? AND YEAR(sp.date_in) = ?)
            )";
            $params = array_merge($params, [$filter_month, $filter_year, $filter_month, $filter_year]);
            $types .= 'iiii';
        }

        $sql .= " ORDER BY
            CASE sp.status WHEN 'DELIVERED' THEN 0 ELSE 1 END ASC,
            COALESCE(sp.delivered_at, sp.date_in) DESC";

        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Summary KPI counts (all records, not filtered)
        $counts_stmt = $conn->prepare("
            SELECT status, COUNT(*) AS total
            FROM slitting_product sp
            WHERE sp.is_voided = 0
              AND NOT {$consumedSql}
            GROUP BY status
        ");
        $counts_stmt->execute();
        $counts_raw  = $counts_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $counts_stmt->close();

        $counts = ['DELIVERED' => 0, 'IN' => 0, 'WAITING' => 0, 'APPROVED' => 0, 'REJECTED' => 0];
        foreach ($counts_raw as $c) {
            if (isset($counts[$c['status']])) {
                $counts[$c['status']] = (int)$c['total'];
            }
        }

        // Finish Good = IN + stock_counted
        $fg_stmt = $conn->prepare("
            SELECT COUNT(*) AS total FROM slitting_product sp
            WHERE sp.is_voided = 0 AND sp.status = 'IN' AND sp.stock_counted = 1
              AND NOT {$consumedSql}
        ");
        $fg_stmt->execute();
        $counts['FINISH_GOOD'] = (int)($fg_stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $fg_stmt->close();

        // Customer dropdown list
        $cust_stmt = $conn->prepare("
            SELECT DISTINCT customer_name
            FROM slitting_product
            WHERE customer_name IS NOT NULL AND customer_name != ''
            ORDER BY customer_name ASC
        ");
        $cust_stmt->execute();
        $customers = array_column($cust_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'customer_name');
        $cust_stmt->close();

        $this->render('tracking/index', [
            'conn'            => $conn,
            'rows'            => $rows,
            'counts'          => $counts,
            'customers'       => $customers,
            'search'          => $search,
            'filter_status'   => $filter_status,
            'filter_month'    => $filter_month,
            'filter_year'     => $filter_year,
            'filter_customer' => $filter_customer,
            'filter_source'   => $filter_source,
        ]);
    }

    /**
     * Traceability Excel Export (formerly tracking_product_export.php)
     */
    public function trackingExport(): void
    {
        $this->requireRole(['slitting', 'mkl3', 'admin', 'qc', 'officer']);

        require 'c:/laragon/www/Slitting_System_2.0/tracking_product_export.php';
    }

    /**
     * Finish Product Excel Export (formerly finish_product_export.php)
     */
    public function export(): void
    {
        $this->requireRole(['slitting']);

        require 'c:/laragon/www/Slitting_System_2.0/finish_product_export.php';
    }

    /**
     * Return Delivered Product back to Stock (formerly return_handler.php)
     */
    public function returnHandler(): void
    {
        $this->requireRole(['slitting']);

        if (!isset($_GET['id'])) {
            $this->redirect("finish_product.php?error=missing_id");
            return;
        }

        $id = intval($_GET['id']);
        $conn = $this->conn;

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT status FROM slitting_product WHERE id=? FOR UPDATE");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                throw new \Exception("Product not found.");
            }

            $product = $result->fetch_assoc();
            if ($product['status'] !== 'DELIVERED') {
                throw new \Exception("Product is not delivered.");
            }
            $stmt->close();

            $stmt = $conn->prepare("UPDATE slitting_product SET status='IN', date_out=NULL, delivered_at=NULL WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $this->redirect("finish_product.php?success=returned_to_stock");
            return;
        } catch (Throwable $e) {
            $conn->rollback();
            error_log("Return to stock failed: " . $e->getMessage());
            $this->redirect("finish_product.php?error=return_failed");
            return;
        }
    }
}

<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use mysqli;
use Exception;

class SettingsController extends Controller
{
    private mysqli $conn;

    public function __construct()
    {
        parent::__construct();
        $this->conn = Database::getConnection();
    }

    /**
     * Settings Dashboard and AJAX management (formerly settings.php)
     */
    public function index(): void
    {
        $this->requireRole(['slitting', 'mkl3', 'admin', 'officer', 'qc']);

        $conn = $this->conn;
        $user_id = $_SESSION['user_id'] ?? 0;
        $role    = $_SESSION['role'] ?? '';

        /* ─── Handle AJAX / POST actions ───────────────────────────── */
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            $action = $_POST['action'];

            /* ── 1. Update Username ─────────────────────────── */
            if ($action === 'update_username') {
                $new_username = trim($_POST['new_username'] ?? '');
                if ($new_username === '') { $this->json(['ok'=>false,'msg'=>'Username cannot be empty.']); return; }

                $chk = $conn->prepare("SELECT id FROM users WHERE username=? AND id!=?");
                $chk->bind_param("si", $new_username, $user_id);
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows > 0) { $chk->close(); $this->json(['ok'=>false,'msg'=>'Username already taken.']); return; }
                $chk->close();

                $stmt = $conn->prepare("UPDATE users SET username=? WHERE id=?");
                $stmt->bind_param("si", $new_username, $user_id);
                $ok = $stmt->execute();
                $stmt->close();
                $this->json(['ok'=>$ok, 'msg'=> $ok ? 'Username updated successfully.' : 'Database error.']);
                return;
            }

            /* ── 2. Change Password ─────────────────────────── */
            if ($action === 'change_password') {
                $current  = $_POST['current_password']  ?? '';
                $new_pw   = $_POST['new_password']       ?? '';
                $confirm  = $_POST['confirm_password']   ?? '';

                if ($new_pw !== $confirm) { $this->json(['ok'=>false,'msg'=>'New passwords do not match.']); return; }
                if (strlen($new_pw) < 6)  { $this->json(['ok'=>false,'msg'=>'Password must be at least 6 characters.']); return; }

                $stmt = $conn->prepare("SELECT password FROM users WHERE id=?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$row || !password_verify($current, $row['password'])) {
                    $this->json(['ok'=>false,'msg'=>'Current password is incorrect.']);
                    return;
                }

                $hash = password_hash($new_pw, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
                $stmt->bind_param("si", $hash, $user_id);
                $ok = $stmt->execute();
                $stmt->close();
                $this->json(['ok'=>$ok, 'msg'=> $ok ? 'Password changed successfully.' : 'Database error.']);
                return;
            }

            /* ── 3. Add Mapping ─────────────────────────────── */
            if ($action === 'add_mapping') {
                $coil_code  = strtoupper(trim($_POST['coil_code']  ?? ''));
                $product    = trim($_POST['product']    ?? '');
                $std_weight = floatval($_POST['std_weight'] ?? 0);
                $nci_code   = trim($_POST['nci_code']   ?? '');
                $nci_width  = trim($_POST['nci_width']  ?? '');
                $customer   = trim($_POST['customer']   ?? '');
                $part_no    = trim($_POST['part_no']    ?? '');

                if (!$coil_code || !$product) { $this->json(['ok'=>false,'msg'=>'Coil code and product are required.']); return; }

                $chk = $conn->prepare("SELECT id FROM coil_product_map WHERE coil_code=? AND product=?");
                $chk->bind_param("ss", $coil_code, $product);
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows > 0) {
                    $chk->close();
                    $this->json(['ok'=>false,'msg'=>"Mapping {$coil_code} → {$product} already exists."]);
                    return;
                }
                $chk->close();

                $conn->begin_transaction();
                try {
                    $s1 = $conn->prepare("INSERT INTO coil_product_map (coil_code, product) VALUES (?,?)");
                    $s1->bind_param("ss", $coil_code, $product);
                    $s1->execute(); $s1->close();

                    if ($std_weight > 0) {
                        $s2 = $conn->prepare("INSERT INTO std_wgt (product_code, std_weight) VALUES (?,?)
                                              ON DUPLICATE KEY UPDATE std_weight=VALUES(std_weight)");
                        $s2->bind_param("sd", $product, $std_weight);
                        $s2->execute(); $s2->close();
                    }

                    if ($nci_code) {
                        $s3 = $conn->prepare("INSERT INTO nci_product_mapping (internal_code, product, width, customer, part_no) VALUES (?,?,?,?,?)");
                        $s3->bind_param("sssss", $nci_code, $product, $nci_width, $customer, $part_no);
                        $s3->execute(); $s3->close();
                    }

                    $conn->commit();
                    $this->json(['ok'=>true,'msg'=>'Mapping added successfully.']);
                } catch (Exception $e) {
                    $conn->rollback();
                    $this->json(['ok'=>false,'msg'=>'Error: '.$e->getMessage()]);
                }
                return;
            }

            /* ── 4. Update Mapping ──────────────────────────── */
            if ($action === 'update_mapping') {
                $map_id     = intval($_POST['map_id']);
                $new_coil   = strtoupper(trim($_POST['coil_code'] ?? ''));
                $product    = trim($_POST['product']    ?? '');
                $std_weight = floatval($_POST['std_weight'] ?? 0);

                if (!$new_coil)  { $this->json(['ok'=>false,'msg'=>'Coil code cannot be empty.']); return; }
                if (!$product)   { $this->json(['ok'=>false,'msg'=>'Product name is required.']); return; }

                $chk = $conn->prepare("SELECT id FROM coil_product_map WHERE coil_code=? AND product=? AND id!=?");
                $chk->bind_param("ssi", $new_coil, $product, $map_id);
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows > 0) {
                    $chk->close();
                    $this->json(['ok'=>false,'msg'=>"Another mapping already uses {$new_coil} → {$product}."]);
                    return;
                }
                $chk->close();

                $conn->begin_transaction();
                try {
                    $s1 = $conn->prepare("UPDATE coil_product_map SET coil_code=?, product=? WHERE id=?");
                    $s1->bind_param("ssi", $new_coil, $product, $map_id);
                    $s1->execute(); $s1->close();

                    if ($std_weight > 0) {
                        $s2 = $conn->prepare("INSERT INTO std_wgt (product_code, std_weight) VALUES (?,?)
                                              ON DUPLICATE KEY UPDATE std_weight=VALUES(std_weight)");
                        $s2->bind_param("sd", $product, $std_weight);
                        $s2->execute(); $s2->close();
                    }

                    $conn->commit();
                    $this->json([
                        'ok'       => true,
                        'msg'      => 'Mapping updated successfully.',
                        'new_coil' => $new_coil,
                    ]);
                } catch (Exception $e) {
                    $conn->rollback();
                    $this->json(['ok'=>false,'msg'=>'Error: '.$e->getMessage()]);
                }
                return;
            }

            /* ── 5. Delete Mapping ──────────────────────────── */
            if ($action === 'delete_mapping') {
                $map_id = intval($_POST['map_id']);
                $stmt = $conn->prepare("DELETE FROM coil_product_map WHERE id=?");
                $stmt->bind_param("i", $map_id);
                $ok = $stmt->execute();
                $stmt->close();
                $this->json(['ok'=>$ok,'msg'=> $ok ? 'Mapping deleted.' : 'Delete failed.']);
                return;
            }

            /* ── 6. Update std_wgt directly ─────────────────── */
            if ($action === 'update_stdwgt') {
                $product    = trim($_POST['product']    ?? '');
                $std_weight = floatval($_POST['std_weight'] ?? 0);
                if (!$product || $std_weight <= 0) { $this->json(['ok'=>false,'msg'=>'Invalid data.']); return; }
                $stmt = $conn->prepare("UPDATE std_wgt SET std_weight=? WHERE product_code=?");
                $stmt->bind_param("ds", $std_weight, $product);
                $ok = $stmt->execute();
                $stmt->close();
                $this->json(['ok'=>$ok,'msg'=> $ok ? 'Standard weight updated.' : 'Update failed.']);
                return;
            }

            /* ── 7. Add NCI Mapping ─────────────────────────── */
            if ($action === 'add_nci_mapping') {
                $internal_code = strtoupper(trim($_POST['internal_code'] ?? ''));
                $product       = trim($_POST['product']       ?? '');
                $width         = trim($_POST['width']         ?? '');
                $customer      = trim($_POST['customer']      ?? '');
                $part_no       = trim($_POST['part_no']       ?? '');

                if (!$internal_code || !$product) {
                    $this->json(['ok'=>false,'msg'=>'Internal code and product are required.']);
                    return;
                }

                $chk = $conn->prepare("SELECT id FROM nci_product_mapping WHERE internal_code=?");
                $chk->bind_param("s", $internal_code);
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows > 0) {
                    $chk->close();
                    $this->json(['ok'=>false,'msg'=>"Internal code {$internal_code} already exists."]);
                    return;
                }
                $chk->close();

                $stmt = $conn->prepare("INSERT INTO nci_product_mapping (internal_code, product, width, customer, part_no) VALUES (?,?,?,?,?)");
                $stmt->bind_param("sssss", $internal_code, $product, $width, $customer, $part_no);
                $ok = $stmt->execute();
                $new_id = $conn->insert_id;
                $stmt->close();

                $this->json([
                    'ok'  => $ok,
                    'msg' => $ok ? 'NCI mapping added.' : 'Insert failed: '.$conn->error,
                    'id'  => $new_id,
                    'internal_code' => $internal_code,
                    'product'  => $product,
                    'width'    => $width,
                    'customer' => $customer,
                    'part_no'  => $part_no,
                ]);
                return;
            }

            /* ── 8. Update NCI Mapping ──────────────────────── */
            if ($action === 'update_nci_mapping') {
                $nci_id        = intval($_POST['nci_id']);
                $internal_code = strtoupper(trim($_POST['internal_code'] ?? ''));
                $product       = trim($_POST['product']       ?? '');
                $width         = trim($_POST['width']         ?? '');
                $customer      = trim($_POST['customer']      ?? '');
                $part_no       = trim($_POST['part_no']       ?? '');

                if (!$internal_code || !$product) {
                    $this->json(['ok'=>false,'msg'=>'Internal code and product are required.']);
                    return;
                }

                $chk = $conn->prepare("SELECT id FROM nci_product_mapping WHERE internal_code=? AND id!=?");
                $chk->bind_param("si", $internal_code, $nci_id);
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows > 0) {
                    $chk->close();
                    $this->json(['ok'=>false,'msg'=>"Another row already uses internal code {$internal_code}."]);
                    return;
                }
                $chk->close();

                $stmt = $conn->prepare("UPDATE nci_product_mapping SET internal_code=?, product=?, width=?, customer=?, part_no=? WHERE id=?");
                $stmt->bind_param("sssssi", $internal_code, $product, $width, $customer, $part_no, $nci_id);
                $ok = $stmt->execute();
                $stmt->close();

                $this->json([
                    'ok'  => $ok,
                    'msg' => $ok ? 'NCI mapping updated.' : 'Update failed: '.$conn->error,
                    'internal_code' => $internal_code,
                    'product'  => $product,
                    'width'    => $width,
                    'customer' => $customer,
                    'part_no'  => $part_no,
                ]);
                return;
            }

            /* ── 9. Delete NCI Mapping ──────────────────────── */
            if ($action === 'delete_nci_mapping') {
                $nci_id = intval($_POST['nci_id']);
                $stmt = $conn->prepare("DELETE FROM nci_product_mapping WHERE id=?");
                $stmt->bind_param("i", $nci_id);
                $ok = $stmt->execute();
                $stmt->close();
                $this->json(['ok'=>$ok,'msg'=> $ok ? 'NCI mapping deleted.' : 'Delete failed.']);
                return;
            }

            $this->json(['ok'=>false,'msg'=>'Unknown action.']);
            return;
        }

        /* ─── Load page data ────────────────────────────────────────── */
        $current_user = $conn->query("SELECT username FROM users WHERE id=$user_id")->fetch_assoc();

        $mappings = $conn->query("
            SELECT c.id, c.coil_code, c.product,
                   COALESCE(w.std_weight, 0) AS std_weight
            FROM coil_product_map c
            LEFT JOIN std_wgt w ON w.product_code = c.product
            ORDER BY c.coil_code
        ");
        $mapping_rows = [];
        while ($r = $mappings->fetch_assoc()) $mapping_rows[] = $r;

        $nci_rows = [];
        $nci_res  = $conn->query("SELECT * FROM nci_product_mapping ORDER BY internal_code");
        while ($r = $nci_res->fetch_assoc()) $nci_rows[] = $r;

        $this->render('settings/index', [
            'conn'          => $conn,
            'user_id'       => $user_id,
            'role'          => $role,
            'current_user'  => $current_user,
            'mapping_rows'  => $mapping_rows,
            'nci_rows'      => $nci_rows,
        ]);
    }
}

<?php
session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if (!in_array($_SESSION['role'], ['slitting', 'mkl3'], true)) {
    header("Location: login.php");
    exit;
}

include 'config.php';

if (!isset($_GET['id'])) {
    header("Location: mother_coil.php?error=missing_id");
    exit;
}

$id = intval($_GET['id']);

if ($id <= 0) {
    header("Location: mother_coil.php?error=invalid_id");
    exit;
}

// ── Verify mother coil exists ─────────────────────────────────
$stmt = $conn->prepare("SELECT id, coil_no, lot_no FROM mother_coil WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$mother = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$mother) {
    header("Location: mother_coil.php?error=not_found");
    exit;
}

// ── Delete everything in FK-safe order inside one transaction ─
//
// Dependency chain (deepest children first):
//
//  mother_coil
//  ├── mother_coil_audit_log      (FK → mother_coil, CASCADE — handled by DB)
//  ├── raw_material_log           (FK → mother_coil, CASCADE — handled by DB)
//  ├── stock_raw_material         (FK → mother_coil, CASCADE — handled by DB)
//  ├── slitting_audit_log         (FK → mother_coil — delete manually)
//  ├── sfc                        (mother_id column — delete manually)
//  ├── process_log                (mother_id column — delete manually)
//  └── slitting_product           (FK → mother_coil, SET NULL — delete manually)
//      ├── waiting_approval       (FK → slitting_product, CASCADE — handled by DB)
//      ├── source_tracking_log    (product_id column — delete manually)
//      ├── recoiling_product      (slitting_product_id column — delete manually)
//      │   └── slitting_product   (recoiling output rows, recoiling_id FK)
//      └── reslit_product         (slitting_product_id column — delete manually)
//          └── reslit_rolls       (FK → reslit_product, CASCADE — handled by DB)

$conn->begin_transaction();

try {

    // ── STEP 1: Collect all slitting_product IDs for this mother ─
    $stmt = $conn->prepare("SELECT id FROM slitting_product WHERE mother_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $sp_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $sp_ids = array_column($sp_rows, 'id');

    if (!empty($sp_ids)) {
        $sp_placeholders = implode(',', array_fill(0, count($sp_ids), '?'));
        $sp_types        = str_repeat('i', count($sp_ids));

        // ── STEP 2: Collect recoiling_product IDs linked to these slit rows
        $stmt = $conn->prepare(
            "SELECT id FROM recoiling_product WHERE slitting_product_id IN ($sp_placeholders)"
        );
        $stmt->bind_param($sp_types, ...$sp_ids);
        $stmt->execute();
        $rp_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $rp_ids = array_column($rp_rows, 'id');

        // Also collect recoiling_product linked via mother_id directly
        $stmt = $conn->prepare("SELECT id FROM recoiling_product WHERE mother_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $rp_rows2 = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $rp_ids = array_unique(array_merge($rp_ids, array_column($rp_rows2, 'id')));

        // ── STEP 3: Collect reslit_product IDs linked to these slit rows
        $stmt = $conn->prepare(
            "SELECT id FROM reslit_product WHERE slitting_product_id IN ($sp_placeholders)"
        );
        $stmt->bind_param($sp_types, ...$sp_ids);
        $stmt->execute();
        $reslit_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $reslit_ids = array_column($reslit_rows, 'id');

        // ── STEP 4: Delete recoil OUTPUT slitting_product rows
        //    (rows where recoiling_id points to one of our recoiling_product rows)
        if (!empty($rp_ids)) {
            $rp_placeholders = implode(',', array_fill(0, count($rp_ids), '?'));
            $rp_types        = str_repeat('i', count($rp_ids));

            // source_tracking_log for recoil outputs
            $stmt = $conn->prepare(
                "DELETE FROM source_tracking_log
                 WHERE table_name = 'recoiling_product'
                   AND product_id IN ($rp_placeholders)"
            );
            $stmt->bind_param($rp_types, ...$rp_ids);
            $stmt->execute();
            $stmt->close();

            // slitting_product rows that are recoil outputs
            $stmt = $conn->prepare(
                "DELETE FROM slitting_product WHERE recoiling_id IN ($rp_placeholders)"
            );
            $stmt->bind_param($rp_types, ...$rp_ids);
            $stmt->execute();
            $stmt->close();

            // recoiling_product rows themselves
            $stmt = $conn->prepare(
                "DELETE FROM recoiling_product WHERE id IN ($rp_placeholders)"
            );
            $stmt->bind_param($rp_types, ...$rp_ids);
            $stmt->execute();
            $stmt->close();
        }

        // ── STEP 5: Delete reslit_rolls then reslit_product
        if (!empty($reslit_ids)) {
            $rs_placeholders = implode(',', array_fill(0, count($reslit_ids), '?'));
            $rs_types        = str_repeat('i', count($reslit_ids));

            // reslit_rolls cascade from reslit_product, but delete explicitly to be safe
            $stmt = $conn->prepare(
                "DELETE FROM reslit_rolls WHERE parent_id IN ($rs_placeholders)"
            );
            $stmt->bind_param($rs_types, ...$reslit_ids);
            $stmt->execute();
            $stmt->close();

            // source_tracking_log for reslit
            $stmt = $conn->prepare(
                "DELETE FROM source_tracking_log
                 WHERE table_name = 'reslit_product'
                   AND product_id IN ($rs_placeholders)"
            );
            $stmt->bind_param($rs_types, ...$reslit_ids);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "DELETE FROM reslit_product WHERE id IN ($rs_placeholders)"
            );
            $stmt->bind_param($rs_types, ...$reslit_ids);
            $stmt->execute();
            $stmt->close();
        }

        // ── STEP 6: source_tracking_log for slitting_product rows
        $stmt = $conn->prepare(
            "DELETE FROM source_tracking_log
             WHERE table_name = 'slitting_product'
               AND product_id IN ($sp_placeholders)"
        );
        $stmt->bind_param($sp_types, ...$sp_ids);
        $stmt->execute();
        $stmt->close();

        // ── STEP 7: waiting_approval — CASCADE from slitting_product,
        //    but delete explicitly to guarantee order
        $stmt = $conn->prepare(
            "DELETE FROM waiting_approval WHERE finish_id IN ($sp_placeholders)"
        );
        $stmt->bind_param($sp_types, ...$sp_ids);
        $stmt->execute();
        $stmt->close();

        // ── STEP 8: Delete the slitting_product rows themselves
        $stmt = $conn->prepare(
            "DELETE FROM slitting_product WHERE id IN ($sp_placeholders)"
        );
        $stmt->bind_param($sp_types, ...$sp_ids);
        $stmt->execute();
        $stmt->close();
    }

    // ── STEP 9: slitting_audit_log ────────────────────────────
    $stmt = $conn->prepare("DELETE FROM slitting_audit_log WHERE mother_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // ── STEP 10: sfc ──────────────────────────────────────────
    $stmt = $conn->prepare("DELETE FROM sfc WHERE mother_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // ── STEP 11: process_log ──────────────────────────────────
    $stmt = $conn->prepare("DELETE FROM process_log WHERE mother_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // ── STEP 12: raw_material_log ────────────────────────────
    //    (CASCADE from mother_coil, but explicit is safer)
    $stmt = $conn->prepare("DELETE FROM raw_material_log WHERE mother_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // ── STEP 13: stock_raw_material ───────────────────────────
    //    (CASCADE from mother_coil, but explicit is safer)
    $stmt = $conn->prepare("DELETE FROM stock_raw_material WHERE source_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // ── STEP 14: mother_coil_audit_log ────────────────────────
    //    (CASCADE from mother_coil, but explicit is safer)
    $stmt = $conn->prepare("DELETE FROM mother_coil_audit_log WHERE mother_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // ── STEP 15: Finally delete the mother coil itself ─────────
    $stmt = $conn->prepare("DELETE FROM mother_coil WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        throw new Exception("Mother coil row was not deleted.");
    }
    $stmt->close();

    $conn->commit();
    header("Location: mother_coil.php?success=3");
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    header("Location: mother_coil.php?error=delete_failed&msg=" . urlencode($e->getMessage()));
    exit;
}
?>
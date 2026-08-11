<?php
// delete_slitting.php — fixed version
// Uses prepared statements (no SQL injection).
// Soft-deletes the slitting_product row instead of hard-deleting,
// then hard-deletes from finish_product if it exists there.

session_start();
include 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'slitting') {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['id'])) {
    header("Location: slitting_product.php?error=missing_id");
    exit;
}

$id = intval($_GET['id']);

// 1. Verify the row exists before touching it
$stmt = $conn->prepare("SELECT id, status, mother_id FROM slitting_product WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    header("Location: slitting_product.php?error=not_found");
    exit;
}

$conn->begin_transaction();
try {
    // 2. Remove from finish_product if referenced (hard delete OK — it's a join table)
    $stmt = $conn->prepare("DELETE FROM finish_product WHERE slit_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // 3. Soft-delete the slitting_product row — preserves history and satisfies FKs
    //    Hard-deleting would break any recoiling_product / reslit_product rows
    //    that reference this roll via slitting_product_id.
    $stmt = $conn->prepare("
        UPDATE slitting_product
        SET is_voided=1, voided_at=NOW(), voided_reason='manual_delete'
        WHERE id=?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // 3.5. Remove from pallet_items if assigned to a pallet, and clean up pallet if empty
    $stmt = $conn->prepare("SELECT pallet_id FROM pallet_items WHERE slitting_product_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $pRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($pRow) {
        $affectedPalletId = (int)$pRow['pallet_id'];
        $stmt = $conn->prepare("DELETE FROM pallet_items WHERE slitting_product_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        // Check remaining items on that pallet
        $stmt = $conn->prepare("SELECT id FROM pallet_items WHERE pallet_id = ? ORDER BY seq ASC");
        $stmt->bind_param("i", $affectedPalletId);
        $stmt->execute();
        $remItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($remItems)) {
            $stmt = $conn->prepare("DELETE FROM pallet_edit_log WHERE pallet_id = ?");
            $stmt->bind_param("i", $affectedPalletId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM pallet_delivery_log WHERE pallet_id = ?");
            $stmt->bind_param("i", $affectedPalletId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM pallets WHERE id = ?");
            $stmt->bind_param("i", $affectedPalletId);
            $stmt->execute();
            $stmt->close();
        } else {
            foreach ($remItems as $idx => $rItem) {
                $newSeq = $idx + 1;
                $u = $conn->prepare("UPDATE pallet_items SET seq = ? WHERE id = ?");
                $u->bind_param("ii", $newSeq, $rItem['id']);
                $u->execute();
                $u->close();
            }
        }
    }

    // 4. Write to process_log
    $performed_by = $_SESSION['role'] ?? 'system';
    $entity_type  = 'slitting';
    $stmt = $conn->prepare("
        INSERT INTO process_log
            (entity_type, entity_id, mother_id, from_status, to_status, performed_by, action_detail)
        VALUES (?, ?, ?, 'IN', 'VOIDED', ?, 'manual_delete')
    ");
    $stmt->bind_param("siis",
        $entity_type, $id,
        $row['mother_id'],
        $performed_by
    );
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    header("Location: slitting_product.php?success=1");
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    header("Location: slitting_product.php?error=delete_failed");
    exit;
}
<?php
// pallet_qc_action.php — PLACEMENT: C:\Apache24\htdocs\slitting_system\pallet_qc_action.php
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'qc') {
    die("Access denied");
}

// ============================================================
// INLINED PALLET HELPERS
// ============================================================
function getPallet(mysqli $conn, int $pallet_id): ?array {
    $stmt = $conn->prepare("SELECT * FROM pallets WHERE id = ?");
    $stmt->bind_param("i", $pallet_id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}
function approvePallet(mysqli $conn, int $pallet_id): array {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE pallets SET status = 'approved', qc_comment = NULL WHERE id = ? AND status = 'pending_qc'");
        $stmt->bind_param("i", $pallet_id); $stmt->execute();
        if ($stmt->affected_rows === 0) throw new RuntimeException('Pallet is not pending QC.');
        $stmt->close();
        $stmt = $conn->prepare("UPDATE slitting_product sp JOIN pallet_items pi ON pi.slitting_product_id = sp.id SET sp.status = 'APPROVED' WHERE pi.pallet_id = ?");
        $stmt->bind_param("i", $pallet_id); $stmt->execute(); $stmt->close();
        $conn->commit(); return ['ok' => true];
    } catch (Throwable $e) { $conn->rollback(); return ['ok' => false, 'msg' => $e->getMessage()]; }
}
function rejectPallet(mysqli $conn, int $pallet_id, string $comment): array {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE pallets SET status = 'rejected', qc_comment = ? WHERE id = ? AND status = 'pending_qc'");
        $stmt->bind_param("si", $comment, $pallet_id); $stmt->execute();
        if ($stmt->affected_rows === 0) throw new RuntimeException('Pallet is not pending QC.');
        $stmt->close();
        $stmt = $conn->prepare("UPDATE slitting_product sp JOIN pallet_items pi ON pi.slitting_product_id = sp.id SET sp.status = 'REJECTED', sp.qc_comment = ? WHERE pi.pallet_id = ?");
        $stmt->bind_param("si", $comment, $pallet_id); $stmt->execute(); $stmt->close();
        $conn->commit(); return ['ok' => true];
    } catch (Throwable $e) { $conn->rollback(); return ['ok' => false, 'msg' => $e->getMessage()]; }
}
// ============================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: qc_dashboard.php"); exit; }

$action    = $_POST['action']    ?? '';
$pallet_id = intval($_POST['pallet_id'] ?? 0);

if ($pallet_id <= 0) { header("Location: qc_dashboard.php?error=invalid_id"); exit; }

if ($action === 'approve') {
    $result = approvePallet($conn, $pallet_id);
    header($result['ok'] ? "Location: qc_dashboard.php?approved=1" : "Location: qc_dashboard.php?error=" . urlencode($result['msg'] ?? 'approve_failed'));
    exit;
}

if ($action === 'reject') {
    $comment = trim($_POST['comment'] ?? '');
    if ($comment === '') { header("Location: qc_dashboard.php?error=comment_required"); exit; }
    $result = rejectPallet($conn, $pallet_id, $comment);
    header($result['ok'] ? "Location: qc_dashboard.php?rejected=1" : "Location: qc_dashboard.php?error=" . urlencode($result['msg'] ?? 'reject_failed'));
    exit;
}

header("Location: qc_dashboard.php");
exit;
<?php
// qc_inspectors_ajax.php
// PLACEMENT: C:\Apache24\htdocs\slitting_system\qc_inspectors_ajax.php
//
// Handles AJAX requests for the Inspector Management panel.
// Called by qc_manage_inspectors.php and qc_dashboard.php
// Allowed roles: qc only.

session_start();
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'qc') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Access denied.']);
    exit;
}

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

// ── GET: list active inspectors ───────────────────────────────
if ($action === 'list') {
    $stmt = $conn->prepare(
        "SELECT id, name FROM qc_inspectors WHERE is_active = 1 ORDER BY name ASC"
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['ok' => true, 'inspectors' => $rows]);
    exit;
}

// ── POST: set active QC inspector session ─────────────────────
if ($action === 'set_active_inspector') {
    $name = trim($_POST['name'] ?? '');
    $_SESSION['active_qc_inspector'] = $name;
    echo json_encode(['ok' => true, 'active_qc_inspector' => $name]);
    exit;
}

// ── POST: add inspector ───────────────────────────────────────
if ($action === 'add') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        echo json_encode(['ok' => false, 'msg' => 'Name cannot be empty.']);
        exit;
    }
    if (mb_strlen($name) > 100) {
        echo json_encode(['ok' => false, 'msg' => 'Name is too long (max 100 characters).']);
        exit;
    }

    // Duplicate check (case-insensitive)
    $chk = $conn->prepare(
        "SELECT id, is_active FROM qc_inspectors WHERE LOWER(name) = LOWER(?)"
    );
    $chk->bind_param("s", $name);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($existing) {
        if ((int)$existing['is_active'] === 1) {
            echo json_encode(['ok' => false, 'msg' => "Inspector \"$name\" already exists."]);
        } else {
            // Re-activate a previously soft-deleted entry
            $upd = $conn->prepare(
                "UPDATE qc_inspectors SET is_active = 1, name = ? WHERE id = ?"
            );
            $upd->bind_param("si", $name, $existing['id']);
            $upd->execute();
            $upd->close();
            echo json_encode([
                'ok'  => true,
                'msg' => "Inspector \"$name\" restored.",
                'id'  => $existing['id'],
                'name' => $name,
            ]);
        }
        exit;
    }

    $ins = $conn->prepare("INSERT INTO qc_inspectors (name) VALUES (?)");
    $ins->bind_param("s", $name);
    $ok = $ins->execute();
    $newId = $conn->insert_id;
    $ins->close();

    echo json_encode([
        'ok'   => $ok,
        'msg'  => $ok ? "Inspector \"$name\" added." : 'Database error: ' . $conn->error,
        'id'   => $newId,
        'name' => $name,
    ]);
    exit;
}

// ── POST: soft-delete inspector ───────────────────────────────
if ($action === 'delete') {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid ID.']);
        exit;
    }

    $stmt = $conn->prepare(
        "UPDATE qc_inspectors SET is_active = 0 WHERE id = ? AND is_active = 1"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    echo json_encode([
        'ok'  => $affected > 0,
        'msg' => $affected > 0
            ? 'Inspector removed from dropdown.'
            : 'Inspector not found or already removed.',
    ]);
    exit;
}

// ── POST: edit inspector ───────────────────────────────────────
if ($action === 'edit') {
    $id   = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');

    if ($id <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid ID.']);
        exit;
    }
    if ($name === '') {
        echo json_encode(['ok' => false, 'msg' => 'Name cannot be empty.']);
        exit;
    }
    if (mb_strlen($name) > 100) {
        echo json_encode(['ok' => false, 'msg' => 'Name is too long (max 100 characters).']);
        exit;
    }

    // Check duplicate name on OTHER inspectors
    $chk = $conn->prepare(
        "SELECT id FROM qc_inspectors WHERE LOWER(name) = LOWER(?) AND id != ?"
    );
    $chk->bind_param("si", $name, $id);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($existing) {
        echo json_encode(['ok' => false, 'msg' => "Inspector name \"$name\" is already in use."]);
        exit;
    }

    // Fetch old name to update session if active
    $oldStmt = $conn->prepare("SELECT name FROM qc_inspectors WHERE id = ?");
    $oldStmt->bind_param("i", $id);
    $oldStmt->execute();
    $oldRow = $oldStmt->get_result()->fetch_assoc();
    $oldStmt->close();
    $oldName = $oldRow['name'] ?? '';

    $upd = $conn->prepare("UPDATE qc_inspectors SET name = ? WHERE id = ?");
    $upd->bind_param("si", $name, $id);
    $ok = $upd->execute();
    $upd->close();

    if ($ok && isset($_SESSION['active_qc_inspector']) && $_SESSION['active_qc_inspector'] === $oldName) {
        $_SESSION['active_qc_inspector'] = $name;
    }

    echo json_encode([
        'ok'   => $ok,
        'msg'  => $ok ? "Inspector renamed to \"$name\"." : 'Database error: ' . $conn->error,
        'id'   => $id,
        'name' => $name,
    ]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
<?php
/**
 * Weekly Production Data Auto-Delete Cron Script
 * -----------------------------------------------------------------------
 * Executed every Monday at 11:00 PM (23:00) to clear weekly length tracking
 * data and reset the weekly production performance cycle.
 * -----------------------------------------------------------------------
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// Optional API token check for external cron triggers
$cronToken = $_GET['token'] ?? $_POST['token'] ?? '';
$validToken = 'slitting_weekly_reset_key'; // Default key

if (!empty($cronToken) && $cronToken !== $validToken) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized cron token']);
    exit;
}

try {
    // 1. Delete records from weekly_production_log
    $conn->query("TRUNCATE TABLE weekly_production_log");
    $affected_log = $conn->affected_rows;

    // 2. Log process event
    $performed_by = $_SESSION['role'] ?? 'system_cron';
    $remark = "Weekly production length tracking data reset on Monday 23:00 cycle.";
    
    $stmtLog = $conn->prepare("
        INSERT INTO process_log
            (entity_type, entity_id, mother_id, from_status, to_status,
             performed_by, action_detail, remark)
        VALUES ('stock', 0, NULL, 'ACTIVE', 'RESET', ?, 'weekly_data_auto_delete', ?)
    ");
    if ($stmtLog) {
        $stmtLog->bind_param("ss", $performed_by, $remark);
        $stmtLog->execute();
        $stmtLog->close();
    }

    // 3. Update last reset timestamp in system_settings
    setSystemSetting($conn, 'last_weekly_reset_at', date('Y-m-d H:i:s'));

    echo json_encode([
        'success'           => true,
        'message'           => 'Weekly production length tracking data reset successfully.',
        'timestamp'         => date('Y-m-d H:i:s'),
        'performed_by'      => $performed_by,
        'affected_records'  => $affected_log
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
?>

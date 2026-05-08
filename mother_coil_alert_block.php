<?php
// ── PASTE THIS BLOCK right after:  $success = $_GET['success'] ?? null;
// ── AND right after:  include 'header.php';
// ── (inside the HTML body, just below the <h2> heading)
//
// Replace the existing:
//     $success = $_GET['success'] ?? null;
// with this entire block:

$success = $_GET['success'] ?? null;
$error   = $_GET['error']   ?? null;

// Map error codes to user-friendly messages
$error_messages = [
    'not_found'     => '❌ Mother coil not found. It may have already been deleted.',
    'missing_id'    => '❌ No ID was provided.',
    'invalid_id'    => '❌ Invalid ID.',
    'delete_failed' => '❌ Delete failed: ' . htmlspecialchars(urldecode($_GET['msg'] ?? 'Unknown error')),
    'has_children'  => '⚠️ Cannot delete — this mother coil has <strong>' . intval($_GET['count'] ?? 0) . ' linked slitting record(s)</strong>. 
                        Delete or void the slitting records first before removing the mother coil.',
];

// Map success codes to messages
$success_messages = [
    '1'  => '✅ Mother coil saved successfully.',
    '3'  => '✅ Mother coil deleted successfully.',
    'update' => '✅ Mother coil updated successfully.',
];
?>

<!-- ── PASTE THIS HTML right below your <h2> heading in the page body ── -->

<?php if ($error && isset($error_messages[$error])): ?>
    <div class="alert alert-<?= $error === 'has_children' ? 'warning' : 'danger' ?> alert-dismissible fade show shadow-sm mb-3">
        <?= $error_messages[$error] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success && isset($success_messages[$success])): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm mb-3">
        <?= $success_messages[$success] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
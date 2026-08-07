<?php
session_start();
include 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'slitting') {
    die("Access denied");
}

$msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = intval($_POST['user_id']);
    $new     = $_POST['new_password'];

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
    $stmt->bind_param("si", $hash, $user_id);
    $stmt->execute();

    $msg = "✅ Password reset successfully";
}

$users = $conn->query("SELECT id, username, role FROM users ORDER BY role");
?>
<!doctype html>
<html>
<head>
    <title>Reset Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
<div class="container" style="max-width:500px;">
    <h4>Reset User Password</h4>

    <?php if($msg): ?>
        <div class="alert alert-success"><?= $msg ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="mb-2">
            <label>User</label>
            <select name="user_id" class="form-control" required>
                <?php while($u = $users->fetch_assoc()): ?>
                    <option value="<?= $u['id'] ?>">
                        <?= $u['username'] ?> (<?= $u['role'] ?>)
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="mb-3">
            <label>New Password</label>
            <input type="text" name="new_password" class="form-control" required>
        </div>

        <button class="btn btn-danger w-100">Reset Password</button>
        <a href="index.php" class="btn btn-secondary w-100 mt-2">Back</a>
    </form>
</div>
</body>
</html>
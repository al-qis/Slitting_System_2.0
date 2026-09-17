<?php
session_start();
include 'config.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role     = strtolower(trim($_POST['role'] ?? ''));
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // whitelist roles (ikut pilihan awak)
    $allowed_roles = ['mkl3', 'slitting', 'qc'];
    if (!in_array($role, $allowed_roles, true)) {
        $role = 'slitting'; // default safe
    }

    $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username=? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Verify login + verify role selected matches DB role
    if ($user && password_verify($password, $user['password']) && strtolower($user['role']) === $role) {

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['role']    = strtolower($user['role']);

        // Redirect ikut role
        if ($_SESSION['role'] === 'mkl3') {
            header("Location: mother_coil.php");
            exit;
        } elseif ($_SESSION['role'] === 'qc') {
            // tukar ke page QC awak (contoh)
            header("Location: qc_dashboard.php");
            exit;
        } else { // slitting
            header("Location: index.php");
            exit;
        }

    } else {
        $error = "Invalid username / password / role.";
    }
}

// default role selected
$defaultRole = 'slitting';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Login - Slitting System</title>

  <!-- Bootstrap (optional, but nice) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body{
      min-height:100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      background: linear-gradient(135deg, #f6f9ff, #eef3ff);
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    }

    .card-login{
      width: 420px;
      max-width: 92vw;
      border: 0;
      border-radius: 18px;
      box-shadow: 0 10px 30px rgba(0,0,0,.08);
      overflow: hidden;
      background:#fff;
    }

    .login-header{
      padding: 22px 22px 10px 22px;
      border-bottom: 1px solid #eef1f6;
      display:flex;
      gap:12px;
      align-items:center;
    }

    .logo-wrap{
      width: 44px;
      height: 44px;
      border-radius: 12px;
      display:flex;
      align-items:center;
      justify-content:center;
      background:#f2f6ff;
      overflow:hidden;
      flex-shrink:0;
    }
    .logo-wrap img{
      width: 85%;
      height: 85%;
      object-fit: contain;
    }

    .login-title{
      margin:0;
      font-weight: 800;
      font-size: 18px;
      line-height: 1.1;
    }
    .login-sub{
      margin:2px 0 0 0;
      color:#6c757d;
      font-size: 13px;
    }

    .login-body{
      padding: 18px 22px 22px 22px;
    }

    .role-switch{
      display:flex;
      gap:10px;
      padding: 10px;
      border-radius: 14px;
      background: #f6f8fc;
      border: 1px solid #eef1f6;
      margin-bottom: 16px;
      justify-content: space-between;
    }

    .role-item{
      flex:1;
      position:relative;
    }
    .role-item input{
      position:absolute;
      opacity:0;
      pointer-events:none;
    }
    .role-label{
      width:100%;
      display:flex;
      align-items:center;
      justify-content:center;
      padding: 10px 8px;
      border-radius: 12px;
      font-weight: 700;
      font-size: 14px;
      color:#334155;
      cursor:pointer;
      user-select:none;
      transition: .15s ease;
      border: 1px solid transparent;
    }
    .role-item input:checked + .role-label{
      background:#ffffff;
      border-color:#d7e3ff;
      box-shadow: 0 6px 14px rgba(0,0,0,.06);
      color:#1d4ed8;
    }

    .form-label{
      font-weight:700;
      font-size: 13px;
      color:#334155;
    }

    .form-control{
      border-radius: 12px;
      padding: 12px 12px;
      background:#f8fbff;
      border: 1px solid #e7eefc;
    }
    .form-control:focus{
      box-shadow: none;
      border-color:#9ab5ff;
      background:#ffffff;
    }

    .pw-wrap{ position:relative; }
    .pw-toggle{
      position:absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      border:0;
      background: transparent;
      font-weight:700;
      color:#64748b;
      padding: 6px 8px;
      border-radius: 10px;
      cursor:pointer;
    }
    .pw-toggle:hover{ background:#eef2ff; }

    .btn-login{
      width:100%;
      border-radius: 14px;
      padding: 12px 14px;
      font-weight: 800;
      letter-spacing: .3px;
      background: #3b82f6;
      border: 0;
    }
    .btn-login:hover{
      background:#2563eb;
    }

    .mini-links{
      display:flex;
      justify-content: center;
      align-items:center;
      margin-top: 10px;
      font-size: 13px;
      color:#6c757d;
    }
    .mini-links a{
      text-decoration:none;
      font-weight:700;
      color:#3b82f6;
    }
    .mini-links a:hover{ text-decoration:underline; }

    .alert{
      border-radius: 12px;
      font-size: 13px;
      margin-bottom: 12px;
    }
  </style>
</head>

<body>
  <div class="card card-login">
    <div class="login-header">
      <div class="logo-wrap">
        <img src="assets/nichiaslogo.jpg" alt="Logo">
      </div>
      <div>
        <p class="login-title">Slitting System</p>
        <p class="login-sub">Choose role and log in</p>
      </div>
    </div>

    <div class="login-body">

      <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" autocomplete="off">
        <!-- Role -->
        <div class="role-switch">
          <div class="role-item">
            <input type="radio" id="role_mkl3" name="role" value="mkl3" <?= ($defaultRole==='mkl3')?'checked':'' ?>>
            <label class="role-label" for="role_mkl3">MKL3</label>
          </div>
          <div class="role-item">
            <input type="radio" id="role_slitting" name="role" value="slitting" checked>
            <label class="role-label" for="role_slitting">Slitting</label>
          </div>
          <div class="role-item">
            <input type="radio" id="role_qc" name="role" value="qc">
            <label class="role-label" for="role_qc">QC</label>
          </div>
        </div>

        <!-- Username -->
        <div class="mb-3">
          <label class="form-label">Username</label>
          <input class="form-control" type="text" name="username" placeholder="Enter username" required>
        </div>

        <!-- Password -->
        <div class="mb-2">
          <label class="form-label d-flex justify-content-between">
            <span>Password</span>
            <a href="#" onclick="alert('Please contact admin to reset password.'); return false;">Forgot password?</a>
          </label>
          <div class="pw-wrap">
            <input class="form-control" type="password" name="password" id="password" placeholder="Enter password" required>
            <button type="button" class="pw-toggle" onclick="togglePw()">👁</button>
          </div>
        </div>

        <button class="btn btn-primary btn-login mt-3" type="submit">LOG IN</button>

        <div class="mini-links">
            <span>© <?= date('Y') ?> MK Slitting</span>
        </div>
      </form>
    </div>
  </div>

  <script>
    function togglePw(){
      const el = document.getElementById('password');
      el.type = (el.type === 'password') ? 'text' : 'password';
    }
  </script>
</body>
</html>

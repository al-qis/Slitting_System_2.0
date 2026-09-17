<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\User;

class AuthController extends Controller
{
    private User $userModel;

    public function __construct()
    {
        parent::__construct();
        $this->userModel = new User();
    }

    /**
     * Handle display and submission of the login page
     */
    public function login(): void
    {
        $error = "";
        $defaultRole = 'slitting';
        $username = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $role     = strtolower(trim($_POST['role'] ?? ''));
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            // Whitelist allowed roles
            $allowedRoles = ['mkl3', 'slitting', 'qc'];
            if (!in_array($role, $allowedRoles, true)) {
                $role = 'slitting';
            }
            $defaultRole = $role;

            $user = $this->userModel->verifyCredentials($username, $password, $role);

            if ($user) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['role']    = strtolower($user['role']);

                $this->redirectByRole($_SESSION['role']);
                return;
            } else {
                $error = "Invalid username / password / role.";
            }
        }

        // Render login view
        $this->render('auth/login', [
            'error'       => $error,
            'defaultRole' => $defaultRole,
            'username'    => $username,
        ]);
    }

    /**
     * Handle user logout
     */
    public function logout(): void
    {
        $_SESSION = [];
        if (session_id() !== '' || isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        session_destroy();

        $this->redirect('login.php');
    }

    /**
     * Helper to route users to their dashboard based on role
     */
    private function redirectByRole(string $role): void
    {
        switch ($role) {
            case 'mkl3':
                $this->redirect('mother_coil.php');
                break;
            case 'qc':
                $this->redirect('qc_dashboard.php');
                break;
            case 'slitting':
            default:
                $this->redirect('index.php');
                break;
        }
    }
}

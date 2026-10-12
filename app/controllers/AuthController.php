<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/functions.php';

class AuthController {

    public function login(): void {
        startSession();

        if (isLoggedIn()) {
            redirect('/dashboard.php');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email    = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            if (empty($email) || empty($password)) {
                $this->loginView('Email and password are required.');
                return;
            }

            $db   = getDB();
            $stmt = $db->prepare("
                SELECT u.*, c.name AS company_name, c.is_active AS company_active
                FROM users u
                JOIN companies c ON c.id = u.company_id
                WHERE u.email = ? AND u.is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password'])) {
                $this->loginView('Invalid email or password.');
                return;
            }

            if (!$user['company_active']) {
                $this->loginView('Your company account has been deactivated.');
                return;
            }

            // Set session
            $_SESSION['user_id']      = $user['id'];
            $_SESSION['user_name']    = $user['name'];
            $_SESSION['user_email']   = $user['email'];
            $_SESSION['user_role']    = $user['role'];
            $_SESSION['company_id']   = $user['company_id'];
            $_SESSION['company_name'] = $user['company_name'];

            // Update last login
            $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
               ->execute([$user['id']]);

            redirect('/dashboard.php');
        }

        $this->loginView();
    }

    public function logout(): void {
        startSession();
        session_destroy();
        redirect('/login.php');
    }

    private function loginView(string $error = ''): void {
        include __DIR__ . '/../../public/views/login.php';
        exit;
    }
}

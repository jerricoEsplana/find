<?php
declare(strict_types=1);

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool {
    return !empty($_SESSION['user']);
}

function is_admin(): bool {
    return is_logged_in() && ($_SESSION['user']['role'] ?? '') === 'admin';
}

function require_login(): void {
    if (!is_logged_in()) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? 'index.php';
        flash('info', 'Please log in to continue.');
        redirect('login.php');
    }
}

function require_admin(): void {
    if (!is_admin()) {
        http_response_code(403);
        require __DIR__ . '/header.php';
        echo '<div class="container narrow"><div class="empty-state"><h1>Access denied</h1><p>You do not have permission to access this page.</p><a class="btn primary" href="index.php">Return Home</a></div></div>';
        require __DIR__ . '/footer.php';
        exit;
    }
}

function login_user(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'university_id' => $user['university_id'],
    ];
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

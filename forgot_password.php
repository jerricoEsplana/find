<?php
require 'config.php';
$pageTitle = 'Forgot Password';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $userId = $stmt->fetchColumn();
    if ($userId) {
        $token = bin2hex(random_bytes(24));
        $expires = date('Y-m-d H:i:s', time() + 1800);
        $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)")->execute([(int)$userId, $token, $expires]);
        $message = 'For local development, use this reset link: reset_password.php?token=' . $token;
    } else {
        $message = 'If the email exists, a reset request has been created.';
    }
}
require 'includes/header.php';
?>
<div class="auth-shell"><div class="auth-card"><span class="eyebrow">Account recovery</span><h1>Forgot password?</h1><p>Enter your university email. Email delivery can be connected later for production.</p>
<?php if ($message): ?><div class="flash info"><?= h($message) ?></div><?php endif; ?>
<form method="post" class="form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><label>University Email<input type="email" name="email" required></label><button class="btn primary full">Create Reset Request</button></form>
</div></div>
<?php require 'includes/footer.php'; ?>

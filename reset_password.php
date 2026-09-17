<?php
require 'config.php';
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$pageTitle = 'Reset Password';
$error = '';
$stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token = ? AND used = 0 AND expires_at >= ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$token, now()]);
$reset = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if (!$reset) $error = 'This reset link is invalid or expired.';
    elseif (strlen($password) < 8) $error = 'Password must be at least 8 characters.';
    elseif ($password !== $confirm) $error = 'Passwords do not match.';
    else {
        $pdo->prepare("UPDATE users SET password_hash=?, updated_at=? WHERE id=?")->execute([password_hash($password, PASSWORD_DEFAULT), now(), $reset['user_id']]);
        $pdo->prepare("UPDATE password_resets SET used=1 WHERE id=?")->execute([$reset['id']]);
        flash('success', 'Password updated. You can now sign in.');
        redirect('login.php');
    }
}
require 'includes/header.php';
?>
<div class="auth-shell"><div class="auth-card"><span class="eyebrow">Account recovery</span><h1>Reset password</h1>
<?php if ($error): ?><div class="flash error"><?= h($error) ?></div><?php endif; ?>
<?php if ($reset): ?><form method="post" class="form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="token" value="<?= h($token) ?>"><label>New Password<input type="password" name="password" minlength="8" required></label><label>Confirm Password<input type="password" name="confirm_password" minlength="8" required></label><button class="btn primary full">Update Password</button></form><?php else: ?><div class="empty-state"><p>Invalid or expired reset link.</p><a class="btn primary" href="forgot_password.php">Request Again</a></div><?php endif; ?>
</div></div>
<?php require 'includes/footer.php'; ?>

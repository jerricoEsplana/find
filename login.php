<?php
require 'config.php';
if (is_logged_in()) redirect('index.php');
$pageTitle = 'Login';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash']) || $user['account_status'] !== 'active') {
        $error = 'Invalid email/password or inactive account.';
    } else {
        login_user($user);
        $destination = $_SESSION['after_login'] ?? ($user['role'] === 'admin' ? 'admin.php' : 'index.php');
        unset($_SESSION['after_login']);
        redirect($destination);
    }
}
require 'includes/header.php';
?>
<style>
.auth-shell{
    display:flex;
    align-items:center;
    justify-content:center;
    min-height:calc(100vh - 90px);
    padding:42px 24px;
    box-sizing:border-box;
    background:#f6f7f9;
}

.auth-layout{
    width:min(100%, 940px);
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:22px;
    align-items:stretch;
}

.auth-about-panel{
    position:relative;
    overflow:hidden;
    display:flex;
    flex-direction:column;
    justify-content:center;
    padding:38px 34px;
    border-radius:18px;
    background:#9d1c2e;
    border:1px solid #8d1829;
    box-shadow:0 18px 40px rgba(80,20,30,.12);
    color:#fff;
}

.auth-about-panel::before{
    content:"";
    position:absolute;
    width:260px;
    height:260px;
    right:-105px;
    top:-120px;
    border:1px solid rgba(255,255,255,.12);
    border-radius:50%;
}

.auth-about-panel::after{
    content:"";
    position:absolute;
    width:210px;
    height:210px;
    left:-120px;
    bottom:-120px;
    border:1px solid rgba(255,255,255,.10);
    border-radius:50%;
}

.auth-about-content{
    position:relative;
    z-index:1;
}

.auth-about-badge{
    display:inline-flex;
    width:max-content;
    margin-bottom:14px;
    padding:6px 10px;
    border:1px solid rgba(255,255,255,.28);
    border-radius:5px;
    color:#fff;
    font-size:9px;
    font-weight:900;
    letter-spacing:.12em;
    text-transform:uppercase;
}

.auth-about-panel h2{
    margin:0 0 12px;
    color:#fff;
    font-size:32px;
    line-height:1.1;
    letter-spacing:-.02em;
}

.auth-about-panel > .auth-about-content > p{
    max-width:500px;
    margin:0;
    color:rgba(255,255,255,.88);
    font-size:12px;
    line-height:1.75;
}

.auth-about-list{
    display:grid;
    gap:10px;
    margin-top:20px;
}

.auth-about-item{
    display:flex;
    gap:13px;
    align-items:flex-start;
    padding:13px 14px;
    border-radius:10px;
    background:rgba(255,255,255,.10);
    border:1px solid rgba(255,255,255,.14);
}

.auth-about-number{
    width:28px;
    height:28px;
    min-width:28px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:7px;
    background:#fff;
    color:#9d1c2e;
    font-size:10px;
    font-weight:900;
}

.auth-about-item strong{
    display:block;
    margin-bottom:3px;
    color:#fff;
    font-size:11px;
}

.auth-about-item span{
    display:block;
    color:rgba(255,255,255,.76);
    font-size:9.5px;
    line-height:1.5;
}

.auth-about-footer{
    position:relative;
    z-index:1;
    margin-top:25px;
    padding-top:16px;
    border-top:1px solid rgba(255,255,255,.16);
    color:rgba(255,255,255,.66);
    font-size:9px;
    letter-spacing:.02em;
}

.auth-card{
    width:100%;
    box-sizing:border-box;
    background:#fff;
    border:1px solid #e0e4ea;
    border-radius:18px;
    box-shadow:0 14px 32px rgba(30,42,65,.07);
}

.auth-card .eyebrow{
    color:#9d1c2e;
}

.auth-card .btn.primary{
    background:#9d1c2e;
    border-color:#9d1c2e;
}

.auth-card .btn.primary:hover{
    background:#871727;
    border-color:#871727;
}

.auth-card .auth-links a{
    color:#9d1c2e;
}

@media (max-width:780px){
    .auth-layout{
        grid-template-columns:1fr;
        max-width:590px;
    }

    .auth-about-panel{
        padding:34px 28px;
    }

    .auth-about-panel h2{
        font-size:30px;
    }
}

@media (max-width:520px){
    .auth-shell{
        padding:25px 15px;
    }

    .auth-about-panel{
        padding:30px 23px;
    }
}
</style>

<div class="auth-shell">
    <div class="auth-layout">

        <section class="auth-about-panel">
            <div class="auth-about-content">

                <span class="auth-about-badge">Mapúa University</span>

                <h2>Find IT</h2>

                <p>
                    A digital Lost &amp; Found platform designed to help
                    Mapúa students report, find, claim, and recover lost
                    belongings on campus.
                </p>

                <div class="auth-about-list">
                    <div class="auth-about-item">
                        <span class="auth-about-number">01</span>
                        <div>
                            <strong>Find Verified Items</strong>
                            <span>Browse found items reviewed and verified by the campus administrator.</span>
                        </div>
                    </div>

                    <div class="auth-about-item">
                        <span class="auth-about-number">02</span>
                        <div>
                            <strong>Report Lost or Found</strong>
                            <span>Submit item details and evidence to help connect belongings with their owners.</span>
                        </div>
                    </div>

                    <div class="auth-about-item">
                        <span class="auth-about-number">03</span>
                        <div>
                            <strong>Claim &amp; Recover</strong>
                            <span>Provide ownership evidence and complete the administrator's verification process.</span>
                        </div>
                    </div>
                </div>

                <div class="auth-about-footer">
                    Campus Lost &amp; Found Services · Mapúa University
                </div>
            </div>
        </section>

        <div class="auth-card">
            <span class="eyebrow">Find IT Account</span>
            <h1>Welcome back</h1>
            <p>Sign in to report items, submit claims, and track your activity.</p>

<?php if ($error): ?><div class="flash error"><?= h($error) ?></div><?php endif; ?>
<form method="post" class="form">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<label>University Email<input type="email" name="email" required autocomplete="email"></label>
<label>Password<input type="password" name="password" required autocomplete="current-password"></label>
<button class="btn primary full">Sign In</button>
</form>
<div class="auth-links"><a href="register.php">Create an account</a><a href="forgot_password.php">Forgot password?</a></div>
<div class="dev-box"><strong>Local test accounts</strong><br>Admin: admin@findit.local / admin123<br>Student: student@findit.local / student123</div>
        </div>
    </div>
</div>
<?php require 'includes/footer.php'; ?>

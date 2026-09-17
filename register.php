<?php 
require 'config.php'; 
if (is_logged_in()) redirect('index.php'); 
$pageTitle = 'Register'; 
$error = ''; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') { 
    verify_csrf(); 
    $name = trim($_POST['full_name'] ?? ''); 
    $uid = trim($_POST['university_id'] ?? ''); 
    $email = trim($_POST['email'] ?? ''); 
    $password = $_POST['password'] ?? ''; 
    $confirm = $_POST['confirm_password'] ?? ''; 

    if ($name === '' || $uid === '' || $email === '' || $password === '') $error = 'Please complete all required fields.'; 
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Please enter a valid email address.'; 
    elseif (strlen($password) < 8) $error = 'Password must be at least 8 characters.'; 
    elseif ($password !== $confirm) $error = 'Passwords do not match.'; 
    else { 
        try { 
            $stmt = $pdo->prepare("INSERT INTO users (full_name, university_id, email, password_hash, role, account_status, created_at, updated_at) VALUES (?, ?, ?, ?, 'user', 'active', ?, ?)"); 
            $stmt->execute([$name, $uid, $email, password_hash($password, PASSWORD_DEFAULT), now(), now()]); 
            flash('success', 'Account created successfully. You can now sign in.'); 
            redirect('login.php'); 
        } catch (PDOException $e) { 
            $error = str_contains(strtolower($e->getMessage()), 'unique') ? 'That university ID or email is already registered.' : 'Unable to create the account.'; 
        } 
    } 
} 
require 'includes/header.php'; 
?> 

<style> 
.register-auth-shell{ 
    width:min(1040px, calc(100% - 48px)); 
    margin:30px auto 42px; 
    display:grid; 
    grid-template-columns:1fr 1fr; 
    gap:28px; 
    align-items:stretch; 
} 

.register-about-panel{ 
    position:relative; 
    overflow:hidden; 
    min-height:690px; 
    box-sizing:border-box; 
    display:flex; 
    align-items:center; 
    padding:52px 46px; 
    background:#a91d32; 
    color:#fff; 
    border:1px solid #94182b; 
    border-radius:22px; 
    box-shadow:0 18px 40px rgba(30,42,65,.08); 
} 

.register-about-panel::before{ 
    content:""; 
    position:absolute; 
    width:285px; 
    height:285px; 
    right:-110px; 
    top:-180px; 
    border:1px solid rgba(255,255,255,.13); 
    border-radius:50%; 
} 

.register-about-panel::after{ 
    content:""; 
    position:absolute; 
    width:235px; 
    height:235px; 
    left:-125px; 
    bottom:-170px; 
    border:1px solid rgba(255,255,255,.12); 
    border-radius:50%; 
} 

.register-about-content{ 
    position:relative; 
    z-index:1; 
    width:100%; 
} 

.register-about-badge{ 
    display:inline-flex; 
    width:max-content; 
    margin-bottom:15px; 
    padding:8px 13px; 
    border:1px solid rgba(255,255,255,.35); 
    border-radius:6px; 
    color:#fff; 
    font-size:10px; 
    font-weight:900; 
    letter-spacing:.10em; 
    text-transform:uppercase; 
} 

.register-about-content h2{ 
    margin:0 0 16px; 
    color:#fff; 
    font-size:46px; 
    line-height:1.05; 
    letter-spacing:-.025em; 
} 

.register-about-content > p{ 
    max-width:500px; 
    margin:0; 
    color:rgba(255,255,255,.91); 
    font-size:13px; 
    line-height:1.75; 
} 

.register-about-list{ 
    display:grid; 
    gap:13px; 
    margin-top:27px; 
} 

.register-about-item{ 
    display:grid; 
    grid-template-columns:42px 1fr; 
    gap:13px; 
    align-items:center; 
    padding:14px 16px; 
    border-radius:11px; 
    background:rgba(255,255,255,.10); 
    border:1px solid rgba(255,255,255,.18); 
} 

.register-about-number{ 
    width:38px; 
    height:38px; 
    display:flex; 
    align-items:center; 
    justify-content:center; 
    border-radius:10px; 
    background:#fff; 
    color:#a91d32; 
    font-size:11px; 
    font-weight:900; 
} 

.register-about-item strong{ 
    display:block; 
    margin-bottom:3px; 
    color:#fff; 
    font-size:12px; 
} 

.register-about-item span{ 
    display:block; 
    color:rgba(255,255,255,.78); 
    font-size:9.5px; 
    line-height:1.45; 
} 

.register-about-footer{ 
    margin-top:27px; 
    padding-top:18px; 
    border-top:1px solid rgba(255,255,255,.20); 
    color:rgba(255,255,255,.72); 
    font-size:9.5px; 
} 

.register-form-card{ 
    min-height:690px; 
    box-sizing:border-box; 
    padding:48px 44px; 
    background:#fff; 
    border:1px solid #e0e3e8; 
    border-radius:22px; 
    box-shadow:0 18px 40px rgba(30,42,65,.08); 
} 

.register-form-card .eyebrow{ 
    color:#a91d32; 
} 

.register-form-card h1{ 
    margin:0 0 14px; 
    font-size:36px; 
    line-height:1.15; 
    letter-spacing:-.025em; 
} 

.register-form-card > p{ 
    margin:0 0 28px; 
    color:#607089; 
    font-size:13px; 
    line-height:1.7; 
} 

.register-form-card .form.two-col{ 
    display:grid; 
    grid-template-columns:repeat(2,minmax(0,1fr)); 
    column-gap:20px; 
    row-gap:19px; 
} 

.register-form-card .form.two-col label{ 
    min-width:0; 
} 

.register-form-card .form.two-col .form-actions{ 
    grid-column:1 / -1; 
    display:flex; 
    align-items:center; 
    gap:12px; 
    margin-top:2px; 
} 

.register-form-card .form.two-col .form-actions .btn{ 
    min-width:165px; 
} 

.register-form-card .btn.primary{ 
    background:#a91d32; 
    border-color:#a91d32; 
} 

.register-form-card .btn.primary:hover{ 
    background:#94182b; 
    border-color:#94182b; 
} 

@media (max-width:900px){ 
    .register-auth-shell{ 
        width:min(680px, calc(100% - 32px)); 
        grid-template-columns:1fr; 
        margin:28px auto 36px; 
    } 

    .register-about-panel{ 
        min-height:auto; 
        padding:40px 34px; 
    } 

    .register-form-card{ 
        min-height:auto; 
        padding:40px 34px; 
    } 
} 

@media (max-width:620px){ 
    .register-form-card .form.two-col{ 
        grid-template-columns:1fr; 
    } 

    .register-form-card .form.two-col .form-actions{ 
        grid-column:auto; 
        flex-wrap:wrap; 
    } 

    .register-form-card .form.two-col .form-actions .btn{ 
        width:100%; 
    } 

    .register-about-panel, 
    .register-form-card{ 
        padding:32px 24px; 
    } 

    .register-form-card h1{ 
        font-size:30px; 
    } 
} 
</style> 

<div class="register-auth-shell"> 

    <section class="register-about-panel"> 
        <div class="register-about-content"> 
            <span class="register-about-badge">Mapúa University</span> 

            <h2>Find IT</h2> 

            <p> 
                A digital Lost &amp; Found platform designed to help Mapúa students 
                report, find, claim, and recover lost belongings on campus. 
            </p> 

            <div class="register-about-list"> 
                <div class="register-about-item"> 
                    <span class="register-about-number">01</span> 
                    <div> 
                        <strong>Find Verified Items</strong> 
                        <span>Browse found items reviewed and verified by the campus administrator.</span> 
                    </div> 
                </div> 

                <div class="register-about-item"> 
                    <span class="register-about-number">02</span> 
                    <div> 
                        <strong>Report Lost or Found</strong> 
                        <span>Submit item details and evidence to help connect belongings with their owners.</span> 
                    </div> 
                </div> 

                <div class="register-about-item"> 
                    <span class="register-about-number">03</span> 
                    <div> 
                        <strong>Claim &amp; Recover</strong> 
                        <span>Provide ownership evidence and complete the administrator's verification process.</span> 
                    </div> 
                </div> 
            </div> 

            <div class="register-about-footer"> 
                Campus Lost &amp; Found Services · Mapúa University 
            </div> 
        </div> 
    </section> 

    <div class="auth-card wide register-form-card"> 
        <span class="eyebrow">Get started</span> 
        <h1>Create your Find IT account</h1> 
        <p>Registration is required for reporting, claiming, and personal tracking.</p> 

        <?php if ($error): ?> 
            <div class="flash error"><?= h($error) ?></div> 
        <?php endif; ?> 

        <form method="post" class="form two-col"> 
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"> 

            <label> 
                Full Name 
                <input name="full_name" required> 
            </label> 

            <label> 
                University ID 
                <input name="university_id" required> 
            </label> 

            <label> 
                University Email 
                <input type="email" name="email" required> 
            </label> 

            <label> 
                Password 
                <input type="password" name="password" minlength="8" required> 
            </label> 

            <label> 
                Confirm Password 
                <input type="password" name="confirm_password" minlength="8" required> 
            </label> 

            <div class="form-actions"> 
                <button class="btn primary">Create Account</button> 
                <a class="btn ghost" href="login.php">Back to Login</a> 
            </div> 
        </form> 
    </div> 

</div> 

<?php require 'includes/footer.php'; ?>

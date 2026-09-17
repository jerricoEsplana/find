<?php
require 'config.php'; require_login();
$id=(int)($_GET['id']??0);
$stmt=$pdo->prepare("SELECT c.*,i.item_name FROM claims c JOIN items i ON i.id=c.item_id WHERE c.id=? AND c.claimant_user_id=?");
$stmt->execute([$id,current_user()['id']]);$c=$stmt->fetch();
if(!$c){http_response_code(404);exit('Claim not found.');}
$pageTitle='Claim Submitted';require 'includes/header.php';
?>
<div class="container narrow"><div class="success-card"><div class="success-icon">✓</div><span class="eyebrow">Claim submitted</span><h1>Your claim is pending verification</h1><p>An administrator will review your ownership information. If approved, you will receive instructions about in-person verification and release.</p><div class="detail-grid"><span>Claim ID</span><strong>CLM-<?= str_pad((string)$c['id'],4,'0',STR_PAD_LEFT) ?></strong><span>Item</span><strong><?= h($c['item_name']) ?></strong><span>Status</span><strong><span class="status pending"><?= status_label($c['claim_status']) ?></span></strong></div><div class="form-actions"><a class="btn primary" href="my_claims.php">View My Claims</a><a class="btn ghost" href="notifications.php">View Notifications</a></div></div></div>
<?php require 'includes/footer.php'; ?>

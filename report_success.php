<?php
require 'config.php';
require_login();
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT r.*, i.item_name FROM reports r JOIN items i ON i.id=r.item_id WHERE r.id=? AND r.user_id=?");
$stmt->execute([$id,current_user()['id']]);
$r = $stmt->fetch();
if (!$r) { http_response_code(404); exit('Report not found.'); }
$pageTitle='Report Submitted';
require 'includes/header.php';
?>
<div class="container narrow"><div class="success-card"><div class="success-icon">✓</div><span class="eyebrow">Submission complete</span><h1>Report submitted successfully</h1><p>Your report is now waiting for administrator review.</p><div class="detail-grid"><span>Report ID</span><strong>RPT-<?= str_pad((string)$r['id'],4,'0',STR_PAD_LEFT) ?></strong><span>Item</span><strong><?= h($r['item_name']) ?></strong><span>Report Type</span><strong><?= ucfirst($r['report_type']) ?></strong><span>Report Status</span><strong><span class="status pending_review"><?= status_label($r['report_status']) ?></span></strong></div><div class="form-actions"><a class="btn primary" href="my_reports.php">View My Reports</a><a class="btn ghost" href="search.php">Browse Items</a></div></div></div>
<?php require 'includes/footer.php'; ?>

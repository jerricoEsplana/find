<?php
require 'config.php';
$id=(int)($_GET['id']??0);
$stmt=$pdo->prepare("SELECT r.*,i.* ,u.full_name AS reporter_name FROM reports r JOIN items i ON i.id=r.item_id JOIN users u ON u.id=r.user_id WHERE r.id=? AND r.report_status='approved'");
$stmt->execute([$id]);$r=$stmt->fetch();
if(!$r){http_response_code(404);exit('Item not found.');}
$pageTitle=$r['item_name'];

// Use the report's uploaded photo first. If there is no uploaded photo,
// look for the owner's custom image in /uploads/items/ using the item name.
$displayPhoto = trim((string)($r['photo_path'] ?? ''));

if ($displayPhoto === '') {
    $imageSlug = strtolower(trim((string)$r['item_name']));
    $imageSlug = preg_replace('/[^a-z0-9]+/i', '-', $imageSlug);
    $imageSlug = trim($imageSlug, '-');

    $customImageDir = __DIR__ . '/uploads/items/';
    $customImageUrl = 'uploads/items/';
    $imageExtensions = ['jpg', 'jpeg', 'png', 'webp'];

    foreach ($imageExtensions as $extension) {
        $candidateFile = $customImageDir . $imageSlug . '.' . $extension;

        if (is_file($candidateFile)) {
            $displayPhoto = $customImageUrl . $imageSlug . '.' . $extension;
            break;
        }
    }
}

$canClaim=$r['report_type']==='found' && in_array($r['item_status'],['open','claim_pending'],true);
$existingClaim=false;
if(current_user()){ $s=$pdo->prepare("SELECT id FROM claims WHERE item_id=? AND claimant_user_id=? AND claim_status='pending'");$s->execute([$r['item_id'],current_user()['id']]);$existingClaim=(bool)$s->fetchColumn(); }
require 'includes/header.php';
?>
<div class="container page-head"><a class="back" href="search.php">← Back to Search</a></div>
<div class="container detail-layout">
<div class="detail-card" style="display:flex;flex-direction:column;overflow:hidden;background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.06);"><div class="detail-photo" style="position:relative;width:100%;height:520px;min-height:320px;overflow:hidden;background:#eef1f5;display:flex;align-items:center;justify-content:center;"><?php if($displayPhoto): ?><img src="<?= h($displayPhoto) ?>" alt="<?= h($r['item_name']) ?>" style="display:block;width:100%;height:100%;object-fit:contain;background:#eef1f5;"><?php else: ?><span style="font-size:72px;font-weight:800;color:#9aa6b8;"><?= h(strtoupper(substr($r['item_name'],0,1))) ?></span><?php endif; ?></div>
<div class="detail-body" style="position:relative!important;inset:auto!important;display:block!important;width:100%;padding:34px 38px 40px;background:#fff;box-sizing:border-box;z-index:2;"><div class="tag-row"><span class="badge <?= h($r['report_type']) ?>"><?= ucfirst($r['report_type']) ?></span><span class="status <?= h(status_class($r['item_status'])) ?>"><?= status_label($r['item_status']) ?></span></div><h1><?= h($r['item_name']) ?></h1><p class="lead-small"><?= h($r['description']) ?></p>
<div class="detail-grid"><span>Category</span><strong><?= h($r['category']) ?></strong><span>Date</span><strong><?= h($r['event_date']) ?></strong><span>Location</span><strong><?= h($r['campus_location']) ?><?= $r['building']?' · '.h($r['building']):'' ?></strong><?php if($r['floor']): ?><span>Floor</span><strong><?= h($r['floor']) ?></strong><?php endif; ?><?php if($r['specific_area']): ?><span>Specific Area</span><strong><?= h($r['specific_area']) ?></strong><?php endif; ?><?php if($r['color']): ?><span>Color</span><strong><?= h($r['color']) ?></strong><?php endif; ?><?php if($r['brand']): ?><span>Brand</span><strong><?= h($r['brand']) ?></strong><?php endif; ?><span>Report ID</span><strong>RPT-<?= str_pad((string)$r['id'],4,'0',STR_PAD_LEFT) ?></strong></div>
<?php if($r['report_type']==='found' && $r['storage_location']): ?><div class="notice info"><strong>Release location:</strong> <?= h($r['storage_location']) ?>. Ownership must be verified before release.</div><?php endif; ?>
<?php if($canClaim): ?>
<?php if($existingClaim): ?>
<div class="notice info">You already have a pending claim for this item. Other students may also have claims under review.</div>
<?php elseif(current_user()): ?>
<a class="btn primary" href="claim.php?item_id=<?= (int)$r['item_id'] ?>">Claim This Item</a>
<?php else: ?>
<a class="btn primary" href="login.php?redirect=claim.php%3Fitem_id%3D<?= (int)$r['item_id'] ?>">Login to Claim This Item</a>
<?php endif; ?>
<?php elseif($r['report_type']==='found' && $r['item_status']==='matched'): ?>
<div class="notice info">This item already has an approved claim and a release schedule.</div>
<?php endif; ?>
</div></div>
<aside class="info-card"><h3>Ownership verification</h3><p>Do not rely only on the public description. Claimants must provide identifying information that helps an administrator verify ownership.</p><p><strong>Never share sensitive card numbers, passwords, or financial information.</strong></p></aside>
</div>
<?php require 'includes/footer.php'; ?>

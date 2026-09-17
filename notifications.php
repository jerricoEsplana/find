<?php
require 'config.php';
require_login();
$pageTitle='Notifications';
$currentUser=current_user();

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();

    if(($_POST['action']??'')==='read_all'){
        $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")
            ->execute([$currentUser['id']]);

        flash('success','All notifications marked as read.');

    } elseif(($_POST['action']??'')==='read'){
        $id=(int)($_POST['id']??0);

        $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")
            ->execute([$id,$currentUser['id']]);
    }

    redirect('notifications.php');
}

$rowsStmt=$pdo->prepare("
    SELECT *
    FROM notifications
    WHERE user_id=?
    ORDER BY created_at DESC
");
$rowsStmt->execute([$currentUser['id']]);
$rows=$rowsStmt->fetchAll();

require 'includes/header.php';
?>

<div class="container page-head notifications-head">
  <div>
    <span class="eyebrow">Stay informed</span>
    <h1>Notifications</h1>
    <p>Updates about your reports, claims, potential matches, item turnover, checking schedules, and item recovery.</p>
  </div>

  <form method="post" class="notifications-read-all">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="read_all">
    <button class="btn ghost" type="submit">Mark all as read</button>
  </form>
</div>

<div class="container notification-list notification-inbox">
<?php if($rows): ?>

<?php foreach($rows as $n): ?>

<?php
$type=strtolower((string)$n['notification_type']);
$title=strtolower((string)$n['title']);

/*
 * Keep notification categories aligned with the current Find IT workflow.
 * Reports are automatically recorded. Students do not confirm potential
 * matches online. Administrator-approved matches proceed to physical
 * turnover, in-person ownership verification, and final resolution.
 */
$typeLabel=match($type){
    'claim'=>'Claim',
    'match'=>(
        str_contains($title,'turnover')
            || str_contains($title,'turn over')
            || str_contains($title,'handover')
            ? 'Item Turnover'
            : (
                str_contains($title,'schedule')
                    || str_contains($title,'verification')
                    ? 'Checking Schedule'
                    : 'Potential Match'
            )
    ),
    'status'=>'Item Status',
    'guidance'=>'Campus Guidance',
    'report'=>'Report Update',
    default=>'Find IT Update'
};
?>

<article class="notification-card <?= $n['is_read']?'is-read':'is-unread' ?>">
  <div class="notification-card-top">
    <div class="notification-type">
      <span class="notification-dot"></span>
      <span><?= h($typeLabel) ?></span>
    </div>

    <div class="notification-time">
      <?= h(date('M j, Y • g:i A',strtotime($n['created_at']))) ?>
    </div>
  </div>

  <div class="notification-card-main">
    <div class="notification-card-content">
      <h2><?= h($n['title']) ?></h2>
      <p class="notification-message"><?= nl2br(h($n['message'])) ?></p>
    </div>

    <div class="notification-actions">
      <?php if(!$n['is_read']): ?>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="read">
        <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
        <button class="notification-read-btn" type="submit">Mark read</button>
      </form>

      <?php else: ?>

      <span class="notification-read-label">Read</span>

      <?php endif; ?>
    </div>
  </div>
</article>

<?php endforeach; ?>

<?php else: ?>

<div class="empty-state notification-empty">
  <div class="notification-empty-icon">✓</div>
  <h3>You're all caught up</h3>
  <p>Updates about your reports, claims, potential matches, item turnover, checking schedules, and recovery instructions will appear here.</p>
</div>

<?php endif; ?>
</div>

<?php require 'includes/footer.php'; ?>

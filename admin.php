<?php
require 'config.php'; require_admin();
$tab=$_GET['tab']??'overview';
// Users remains available through Statistics; Matches keeps its own review workspace.
if($tab==='users')$tab='statistics';
if($tab==='claims')$tab='reports';
if(!in_array($tab,['overview','reports','matches','user_history','items','statistics','guidance','messages'],true))$tab='overview';
$error='';

/* Optional ownership-proof photo column for claims submitted by students. */
try {
    $claimColumns = $pdo->query("PRAGMA table_info(claims)")->fetchAll();
    $hasProofPhotoColumn = false;
    foreach ($claimColumns as $claimColumn) {
        if (($claimColumn['name'] ?? '') === 'proof_photo_path') {
            $hasProofPhotoColumn = true;
            break;
        }
    }
    if (!$hasProofPhotoColumn) {
        $pdo->exec("ALTER TABLE claims ADD COLUMN proof_photo_path TEXT");
    }
} catch (Throwable $e) {
    /* Keep the admin page usable on databases already migrated. */
}

/* Handover audit fields. These let the system record when an approved item
 * was physically released and which administrator completed the handover. */
try {
    $claimColumns = $pdo->query("PRAGMA table_info(claims)")->fetchAll();
    $claimColumnNames = [];
    foreach ($claimColumns as $claimColumn) {
        $claimColumnNames[] = (string)($claimColumn['name'] ?? '');
    }
    if (!in_array('handover_at', $claimColumnNames, true)) {
        $pdo->exec("ALTER TABLE claims ADD COLUMN handover_at TEXT");
    }
    if (!in_array('handover_admin_id', $claimColumnNames, true)) {
        $pdo->exec("ALTER TABLE claims ADD COLUMN handover_admin_id INTEGER");
    }
    if (!in_array('handover_notes', $claimColumnNames, true)) {
        $pdo->exec("ALTER TABLE claims ADD COLUMN handover_notes TEXT");
    }
} catch (Throwable $e) {
    /* Keep the admin page usable if the database cannot be migrated. */
}

/* Verified-match scheduling fields. These belong to the Lost ↔ Found match
 * itself so the administrator can approve the automated match and schedule
 * one verification appointment for both users. */
try{$pdo->exec("ALTER TABLE potential_matches ADD COLUMN verification_date TEXT");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE potential_matches ADD COLUMN verification_time TEXT");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE potential_matches ADD COLUMN verification_deadline TEXT");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE potential_matches ADD COLUMN verification_location TEXT");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE potential_matches ADD COLUMN user_confirmed_at TEXT");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE potential_matches ADD COLUMN user_confirmed_by INTEGER");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE potential_matches ADD COLUMN handover_at TEXT");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE potential_matches ADD COLUMN handover_admin_id INTEGER");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE potential_matches ADD COLUMN handover_evidence TEXT");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE potential_matches ADD COLUMN handover_notes TEXT");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE potential_matches ADD COLUMN verification_outcome TEXT");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE potential_matches ADD COLUMN verification_outcome_reason TEXT");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE potential_matches ADD COLUMN found_turnover_start_date TEXT");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE potential_matches ADD COLUMN found_turnover_deadline TEXT");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE potential_matches ADD COLUMN found_turnover_location TEXT");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE potential_matches ADD COLUMN found_handover_at TEXT");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE potential_matches ADD COLUMN found_handover_admin_id INTEGER");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE potential_matches ADD COLUMN found_handover_notes TEXT");}catch(Throwable $e){}
try{$pdo->exec("ALTER TABLE reports ADD COLUMN admin_hidden INTEGER NOT NULL DEFAULT 0");}catch(Throwable $e){}


// Direct user ↔ administrator messages use the same conversation tables as messages.php.
// notification_messages is kept below for backward compatibility with older records,
// but it is no longer used for the live Messages inbox.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS message_threads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        subject TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','closed')),
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        last_message_at TEXT NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    );
    CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        thread_id INTEGER NOT NULL,
        sender_user_id INTEGER NOT NULL,
        message TEXT NOT NULL,
        is_read INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        FOREIGN KEY(thread_id) REFERENCES message_threads(id) ON DELETE CASCADE,
        FOREIGN KEY(sender_user_id) REFERENCES users(id) ON DELETE CASCADE
    );
    CREATE INDEX IF NOT EXISTS idx_message_threads_user ON message_threads(user_id,last_message_at);
    CREATE INDEX IF NOT EXISTS idx_messages_thread ON messages(thread_id,created_at);
    CREATE INDEX IF NOT EXISTS idx_messages_unread ON messages(sender_user_id,is_read);
    CREATE TABLE IF NOT EXISTS message_migrations (
        notification_message_id INTEGER PRIMARY KEY,
        message_id INTEGER NOT NULL,
        migrated_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS notification_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        thread_key TEXT NOT NULL,
        notification_id INTEGER,
        sender_user_id INTEGER NOT NULL,
        recipient_user_id INTEGER NOT NULL,
        message TEXT NOT NULL,
        created_at TEXT NOT NULL,
        is_read INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY(notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
        FOREIGN KEY(sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
    );
    CREATE INDEX IF NOT EXISTS idx_notification_messages_recipient
        ON notification_messages(recipient_user_id, is_read);
    CREATE INDEX IF NOT EXISTS idx_notification_messages_thread
        ON notification_messages(thread_key, created_at);
");

// One-time compatibility migration: older builds stored user/admin replies in
// notification_messages. Move those records into the real two-way conversation
// tables so the original message text remains available to both sides.
try {
    $legacyRows=$pdo->query("SELECT nm.*, n.title AS notification_title,
            su.role AS sender_role, ru.role AS recipient_role
        FROM notification_messages nm
        LEFT JOIN notifications n ON n.id=nm.notification_id
        JOIN users su ON su.id=nm.sender_user_id
        JOIN users ru ON ru.id=nm.recipient_user_id
        LEFT JOIN message_migrations mm ON mm.notification_message_id=nm.id
        WHERE mm.notification_message_id IS NULL
        ORDER BY nm.created_at ASC, nm.id ASC")->fetchAll();

    if($legacyRows){
        $findThread=$pdo->prepare("SELECT id FROM message_threads WHERE user_id=? AND subject=? ORDER BY id LIMIT 1");
        $createThread=$pdo->prepare("INSERT INTO message_threads(user_id,subject,status,created_at,updated_at,last_message_at) VALUES(?,?,?,?,?,?)");
        $createMessage=$pdo->prepare("INSERT INTO messages(thread_id,sender_user_id,message,is_read,created_at) VALUES(?,?,?,?,?)");
        $mapMessage=$pdo->prepare("INSERT INTO message_migrations(notification_message_id,message_id,migrated_at) VALUES(?,?,?)");

        foreach($legacyRows as $legacy){
            $userId=$legacy['sender_role']==='user' ? (int)$legacy['sender_user_id'] : (int)$legacy['recipient_user_id'];
            $subject=trim((string)($legacy['notification_title']??''));
            if($subject==='')$subject='Message to Find IT Administrator';
            $createdAt=(string)$legacy['created_at'];

            $findThread->execute([$userId,$subject]);
            $threadId=(int)$findThread->fetchColumn();
            if(!$threadId){
                $createThread->execute([$userId,$subject,'open',$createdAt,$createdAt,$createdAt]);
                $threadId=(int)$pdo->lastInsertId();
            }

            $createMessage->execute([
                $threadId,
                (int)$legacy['sender_user_id'],
                (string)$legacy['message'],
                (int)$legacy['is_read'],
                $createdAt
            ]);
            $messageId=(int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE message_threads SET updated_at=CASE WHEN updated_at<? THEN ? ELSE updated_at END, last_message_at=CASE WHEN last_message_at<? THEN ? ELSE last_message_at END WHERE id=?")
                ->execute([$createdAt,$createdAt,$createdAt,$createdAt,$threadId]);
            $mapMessage->execute([(int)$legacy['id'],$messageId,now()]);
        }
    }
} catch(Throwable $migrationError) {
    // Never prevent the admin page from loading because of an optional legacy migration.
}


/* ================================================================
 * ADMIN ACTIVITY NOTIFICATIONS
 * ---------------------------------------------------------------
 * The regular notifications table is user-scoped. That means a student
 * submitting a report/claim only creates a notification for that student,
 * so the administrator's bell has nothing to count.  Before the admin
 * header is rendered, mirror new user activity into notifications for the
 * administrator(s).  The related record IDs make the operation idempotent,
 * so refreshing this page never creates duplicate activity notifications.
 * ================================================================ */
try {
    $adminUsers = $pdo->query("SELECT id FROM users WHERE role='admin' AND account_status='active'")->fetchAll(PDO::FETCH_COLUMN);

    if ($adminUsers) {
        $notifyReport = $pdo->prepare("SELECT 1 FROM notifications
            WHERE user_id=? AND notification_type='admin_activity'
              AND related_report_id=? AND related_claim_id IS NULL
              AND title=? LIMIT 1");
        $insertReportNotification = $pdo->prepare("INSERT INTO notifications
            (user_id,title,message,notification_type,related_report_id,related_claim_id,created_at)
            VALUES (?,?,?,?,?,?,?)");

        $newReports = $pdo->query("SELECT r.id,r.report_type,r.item_id,r.created_at,
                i.item_name,u.full_name
            FROM reports r
            JOIN items i ON i.id=r.item_id
            JOIN users u ON u.id=r.user_id
            ORDER BY r.created_at ASC,r.id ASC")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($newReports as $report) {
            $typeLabel = $report['report_type']==='lost' ? 'Lost' : 'Found';
            $title = 'New '.$typeLabel.' Report Submitted';
            $reportCode = 'RPT-'.str_pad((string)$report['id'],4,'0',STR_PAD_LEFT);
            $message = $report['full_name'].' submitted a '.$typeLabel.' item report '.$reportCode.
                ' for "'.$report['item_name'].'". The report was automatically added to the system. Review the record and monitor any potential match that may be generated.';

            foreach ($adminUsers as $adminId) {
                $notifyReport->execute([(int)$adminId,(int)$report['id'],$title]);
                if (!$notifyReport->fetchColumn()) {
                    $insertReportNotification->execute([
                        (int)$adminId,
                        $title,
                        $message,
                        'admin_activity',
                        (int)$report['id'],
                        null,
                        (string)$report['created_at']
                    ]);
                }
            }
        }

        $notifyClaim = $pdo->prepare("SELECT 1 FROM notifications
            WHERE user_id=? AND notification_type='admin_activity'
              AND related_claim_id=? AND related_report_id IS NULL
              AND title='New Claim Submitted' LIMIT 1");
        $insertClaimNotification = $pdo->prepare("INSERT INTO notifications
            (user_id,title,message,notification_type,related_report_id,related_claim_id,created_at)
            VALUES (?,?,?,?,?,?,?)");

        $newClaims = $pdo->query("SELECT c.id,c.item_id,c.created_at,
                i.item_name,u.full_name
            FROM claims c
            JOIN items i ON i.id=c.item_id
            JOIN users u ON u.id=c.claimant_user_id
            ORDER BY c.created_at ASC,c.id ASC")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($newClaims as $claim) {
            $claimCode = 'CLM-'.str_pad((string)$claim['id'],3,'0',STR_PAD_LEFT);
            $message = $claim['full_name'].' submitted claim '.$claimCode.
                ' for "'.$claim['item_name'].'". Review the claimant\'s ownership information and decide whether to approve or reject the claim.';

            foreach ($adminUsers as $adminId) {
                $notifyClaim->execute([(int)$adminId,(int)$claim['id']]);
                if (!$notifyClaim->fetchColumn()) {
                    $insertClaimNotification->execute([
                        (int)$adminId,
                        'New Claim Submitted',
                        $message,
                        'admin_activity',
                        null,
                        (int)$claim['id'],
                        (string)$claim['created_at']
                    ]);
                }
            }
        }
    }
} catch (Throwable $activityNotificationError) {
    // Notification sync must never prevent the administrator dashboard from loading.
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=$_POST['action']??'';
    try{
        if($action==='delete_report'){
            $reportId=(int)($_POST['report_id']??0);
            $reason=trim((string)($_POST['reason']??''));
            if($reportId<=0)throw new RuntimeException('Invalid report selected.');

            $s=$pdo->prepare("SELECT r.*,i.item_name,u.full_name FROM reports r JOIN items i ON i.id=r.item_id JOIN users u ON u.id=r.user_id WHERE r.id=? LIMIT 1");
            $s->execute([$reportId]);
            $r=$s->fetch();
            if(!$r)throw new RuntimeException('Report not found.');

            /* Do not silently break an active approved match or approved claim. */
            $activeMatch=$pdo->prepare("SELECT COUNT(*) FROM potential_matches WHERE (lost_report_id=? OR found_report_id=?) AND match_status='confirmed' AND verification_outcome IS NULL");
            $activeMatch->execute([$reportId,$reportId]);
            if((int)$activeMatch->fetchColumn()>0){
                throw new RuntimeException('This report is part of an active approved match. Complete or close the match before removing the report.');
            }

            $approvedClaim=$pdo->prepare("SELECT COUNT(*) FROM claims c JOIN reports fr ON fr.item_id=c.item_id AND fr.report_type='found' WHERE fr.id=? AND c.claim_status='approved'");
            $approvedClaim->execute([$reportId]);
            if((int)$approvedClaim->fetchColumn()>0){
                throw new RuntimeException('This report has an approved ownership claim and cannot be removed from the public workflow.');
            }

            $pdo->beginTransaction();
            $now=now();
            $hideReason=$reason!=='' ? $reason : 'Removed by administrator from public browsing.';
            $pdo->prepare("UPDATE reports SET admin_hidden=1, report_status='rejected', rejection_reason=?, item_status='closed', updated_at=? WHERE id=?")
                ->execute([$hideReason,$now,$reportId]);

            /* A removed report must no longer participate in pending matching. */
            $pdo->prepare("UPDATE potential_matches SET match_status='rejected', admin_remarks=CASE WHEN COALESCE(admin_remarks,'')='' THEN ? ELSE admin_remarks END, updated_at=? WHERE (lost_report_id=? OR found_report_id=?) AND match_status='pending'")
                ->execute([$hideReason,$now,$reportId,$reportId]);

            add_status_history($pdo,$reportId,(string)$r['item_status'],'closed',current_user()['id'],'Report removed from public browsing by administrator. Reason: '.$hideReason);
            notify_user($pdo,(int)$r['user_id'],'Report Removed by Administrator',
                'Your '.$r['report_type'].' report for '.$r['item_name'].' was removed from public browsing by the administrator. Reason: '.$hideReason,
                'report',$reportId);
            audit($pdo,current_user()['id'],'Removed report from public browsing','report',$reportId,$hideReason);
            $pdo->commit();
            flash('success','Report removed from public browsing.');
        } elseif($action==='match_decision'){
            $matchId=(int)$_POST['match_id'];
            $decision=$_POST['decision']??'';
            $remarks=trim($_POST['remarks']??'');
            if(!in_array($decision,['confirm','reject'],true))throw new RuntimeException('Invalid match decision.');

            $s=$pdo->prepare("SELECT pm.*,
                    fr.user_id AS found_user_id,
                    fi.item_name AS found_item_name,
                    lr.user_id AS lost_user_id,
                    li.item_name AS lost_item_name
                FROM potential_matches pm
                JOIN reports fr ON fr.id=pm.found_report_id
                JOIN items fi ON fi.id=fr.item_id
                JOIN reports lr ON lr.id=pm.lost_report_id
                JOIN items li ON li.id=lr.item_id
                WHERE pm.id=?");
            $s->execute([$matchId]);
            $m=$s->fetch();

            if(!$m)throw new RuntimeException('Potential match not found.');
            if($m['match_status']!=='pending')throw new RuntimeException('This potential match has already been reviewed.');

            if($decision==='confirm'){
                /*
                 * Approving a potential match identifies a candidate for in-person
                 * verification. The student does NOT need to confirm online.
                 * The administrator releases the checking schedule next, and the
                 * final match/return decision is recorded after the in-person check.
                 */
                $pdo->beginTransaction();
                $now=now();

                $pdo->prepare("UPDATE potential_matches
                    SET match_status='confirmed',
                        admin_remarks=?,
                        user_confirmed_at=NULL,
                        user_confirmed_by=NULL,
                        verification_outcome=NULL,
                        verification_outcome_reason=NULL,
                        verification_date=NULL,
                        verification_time=NULL,
                        verification_deadline=NULL,
                        verification_location=NULL,
                        handover_at=NULL,
                        handover_admin_id=NULL,
                        handover_evidence=NULL,
                        handover_notes=NULL,
                        found_turnover_deadline=NULL,
                        found_handover_at=NULL,
                        found_handover_admin_id=NULL,
                        found_handover_notes=NULL,
                        verification_outcome=NULL,
                        verification_outcome_reason=NULL,
                        updated_at=?
                    WHERE id=?")
                    ->execute([
                        $remarks?:null,
                        $now,
                        $matchId
                    ]);

                /* The reports are matched/reserved, but NOT completed. */
                $pdo->prepare("UPDATE reports
                    SET item_status='matched',
                        verification_date=NULL,
                        verification_time=NULL,
                        verification_deadline=NULL,
                        verification_location=NULL,
                        updated_at=?
                    WHERE id IN (?,?)")
                    ->execute([
                        $now,
                        (int)$m['lost_report_id'],
                        (int)$m['found_report_id']
                    ]);

                notify_user(
                    $pdo,
                    (int)$m['lost_user_id'],
                    'Potential Match Approved — Waiting for Found Item Turnover',
                    'The administrator approved a potential match for your lost '.$m['lost_item_name'].'. No online confirmation is required. The Found Reporter must first turn the physical item over to the administrator. After the item is received, the administrator will release your in-person ownership verification schedule.',
                    'match',
                    (int)$m['lost_report_id']
                );

                if((int)$m['found_user_id']!==(int)$m['lost_user_id']){
                    notify_user(
                        $pdo,
                        (int)$m['found_user_id'],
                        'Potential Match Approved — Turnover Required',
                        'An administrator approved a potential match involving your found '.$m['found_item_name'].'. Please wait for the administrator to provide a turnover deadline. You must first turn the physical item over to the administrator. You do not need to attend the lost owner’s later verification appointment.',
                        'match',
                        (int)$m['found_report_id']
                    );
                }

                audit(
                    $pdo,
                    current_user()['id'],
                    'Approved potential Lost/Found match — awaiting in-person verification',
                    'match',
                    $matchId,
                    'Found RPT-'.str_pad((string)$m['found_report_id'],4,'0',STR_PAD_LEFT).
                    ' ↔ Lost RPT-'.str_pad((string)$m['lost_report_id'],4,'0',STR_PAD_LEFT).
                    ' | Schedule not yet released; no student confirmation required.'.
                    ($remarks?' | '.$remarks:'')
                );

                $pdo->commit();
            } else {
                $rejectionReason=$remarks?:'Not a match.';
                $rejectedAt=now();
                $pdo->prepare("UPDATE potential_matches
                    SET match_status='rejected',
                        admin_remarks=?,
                        user_confirmed_at=NULL,
                        user_confirmed_by=NULL,
                        verification_date=NULL,
                        verification_time=NULL,
                        verification_deadline=NULL,
                        verification_location=NULL,
                        updated_at=?
                    WHERE id=?")
                    ->execute([$rejectionReason,$rejectedAt,$matchId]);

                notify_user($pdo,(int)$m['lost_user_id'],'Potential Match Rejected',
                    'The administrator reviewed the potential match for your lost '.$m['lost_item_name'].' and rejected it. Reason: '.$rejectionReason,
                    'match',(int)$m['lost_report_id']);
                if((int)$m['found_user_id']!==(int)$m['lost_user_id']){
                    notify_user($pdo,(int)$m['found_user_id'],'Potential Lost/Found Match Rejected',
                        'The administrator reviewed the potential Lost/Found match involving your found '.$m['found_item_name'].' and rejected it. Reason: '.$rejectionReason,
                        'match',(int)$m['found_report_id']);
                }

                audit(
                    $pdo,
                    current_user()['id'],
                    'Rejected potential Lost/Found match',
                    'match',
                    $matchId,
                    $remarks?:'Not a match.'
                );
            }
        } elseif($action==='match_turnover_schedule'){
            $matchId=(int)($_POST['match_id']??0);
            $turnoverStartDate=trim((string)($_POST['found_turnover_start_date']??''));
            $turnoverDeadline=trim((string)($_POST['found_turnover_deadline']??''));
            $turnoverLocation=trim((string)($_POST['found_turnover_location']??''));
            $remarks=trim((string)($_POST['remarks']??''));

            if($matchId<=0)throw new RuntimeException('Invalid match selected.');
            if($turnoverStartDate===''||$turnoverDeadline===''||$turnoverLocation==='')throw new RuntimeException('Set the Found Reporter start date, deadline, and turnover location before notifying the finder.');
            $startTs=strtotime($turnoverStartDate.' 00:00:00');
            $deadlineTs=strtotime($turnoverDeadline.' 23:59:59');
            if($startTs===false||$deadlineTs===false)throw new RuntimeException('The Found Reporter turnover schedule contains an invalid date.');
            if($startTs>$deadlineTs)throw new RuntimeException('The turnover start date must be on or before the turnover deadline.');

            $s=$pdo->prepare("SELECT pm.*,fr.user_id AS found_user_id,fi.item_name AS found_item_name,
                    lr.user_id AS lost_user_id,li.item_name AS lost_item_name
                FROM potential_matches pm
                JOIN reports fr ON fr.id=pm.found_report_id
                JOIN items fi ON fi.id=fr.item_id
                JOIN reports lr ON lr.id=pm.lost_report_id
                JOIN items li ON li.id=lr.item_id
                WHERE pm.id=? LIMIT 1");
            $s->execute([$matchId]);
            $m=$s->fetch();
            if(!$m)throw new RuntimeException('Potential match not found.');
            if($m['match_status']!=='confirmed')throw new RuntimeException('The match must be approved before a Found Reporter turnover deadline can be released.');
            if(($m['verification_outcome']??'')==='completed')throw new RuntimeException('This potential match has already been completed.');
            if(($m['verification_outcome']??'')==='not_match')throw new RuntimeException('This potential match was closed as not a match.');
            if(!empty($m['found_handover_at']))throw new RuntimeException('The Found Reporter has already turned the item over to the administrator.');

            $pdo->beginTransaction();
            $now=now();
            $pdo->prepare("UPDATE potential_matches
                SET found_turnover_start_date=?,
                    found_turnover_deadline=?,
                    found_turnover_location=?,
                    admin_remarks=CASE WHEN ?<>'' THEN ? ELSE admin_remarks END,
                    updated_at=? WHERE id=?")
                ->execute([$turnoverStartDate,$turnoverDeadline,$turnoverLocation,$remarks,$remarks,$now,$matchId]);

            $startText=date('F j, Y',$startTs);
            $deadlineText=date('F j, Y',$deadlineTs);
            if((int)$m['found_user_id']!==(int)$m['lost_user_id']){
                notify_user($pdo,(int)$m['found_user_id'],'Found Item Turnover Required',
                    'The administrator approved a potential Lost/Found match involving your found '.$m['found_item_name'].'. Please turn the physical item over to the administrator from '.$startText.' through '.$deadlineText.'. Turnover location: '.$turnoverLocation.'. The lost-item owner will only receive a checking schedule after the item has been physically received by the administrator. You do not need to attend the owner verification appointment.',
                    'match',(int)$m['found_report_id']);
                notify_user($pdo,(int)$m['lost_user_id'],'Match Approved — Waiting for Found Item',
                    'The potential match for your lost '.$m['lost_item_name'].' has been approved by the administrator. We are waiting for the student who found the item to turn it over to the administrator from '.$startText.' through '.$deadlineText.' at '.$turnoverLocation.'. You will receive your own in-person release/verification schedule only after the item has been physically received by the administrator.',
                    'match',(int)$m['lost_report_id']);
            } else {
                notify_user($pdo,(int)$m['lost_user_id'],'Match Approved — Waiting for Found Item',
                    'Your potential match for '.$m['lost_item_name'].' has been approved. The item must be turned over to the administrator from '.$startText.' through '.$deadlineText.' at '.$turnoverLocation.'. Your in-person verification schedule will be released only after the item is physically received.',
                    'match',(int)$m['lost_report_id']);
            }

            audit($pdo,current_user()['id'],'Set Found Reporter turnover deadline','match',$matchId,
                'Found Reporter may turn over item from '.$startText.' through '.$deadlineText.' at '.$turnoverLocation.($remarks?' | '.$remarks:''));
            $pdo->commit();
        } elseif($action==='found_match_handover'){
            $matchId=(int)($_POST['match_id']??0);
            $notes=trim((string)($_POST['found_handover_notes']??''));
            if($matchId<=0)throw new RuntimeException('Invalid match selected.');

            $s=$pdo->prepare("SELECT pm.*,fr.user_id AS found_user_id,fr.item_status AS found_item_status,
                    lr.user_id AS lost_user_id,fr.id AS found_report_id,lr.id AS lost_report_id,
                    fi.item_name AS found_item_name
                FROM potential_matches pm
                JOIN reports fr ON fr.id=pm.found_report_id
                JOIN reports lr ON lr.id=pm.lost_report_id
                JOIN items fi ON fi.id=fr.item_id
                WHERE pm.id=? LIMIT 1");
            $s->execute([$matchId]);
            $m=$s->fetch();
            if(!$m)throw new RuntimeException('Potential match not found.');
            if(($m['match_status']??'')!=='confirmed')throw new RuntimeException('Only an administrator-approved match can receive the Found Reporter turnover.');
            if(($m['verification_outcome']??'')==='completed')throw new RuntimeException('This potential match has already been completed.');
            if(($m['verification_outcome']??'')==='not_match')throw new RuntimeException('This potential match was closed as not a match.');
            if(empty($m['found_turnover_deadline']))throw new RuntimeException('Set the Found Reporter turnover deadline first.');
            if(!empty($m['found_handover_at']))throw new RuntimeException('The Found Reporter turnover has already been recorded.');

            $pdo->beginTransaction();
            $t=now();
            $pdo->prepare("UPDATE potential_matches SET found_handover_at=?,found_handover_admin_id=?,found_handover_notes=?,updated_at=? WHERE id=?")
                ->execute([$t,(int)current_user()['id'],$notes,$t,$matchId]);

            /* The Found Reporter's responsibility ends once the administrator
             * physically receives the item. Mark that report completed/returned,
             * while keeping the Lost ↔ Found match active for the owner's
             * later verification. */
            $pdo->prepare("UPDATE reports SET item_status='returned',updated_at=? WHERE id=?")
                ->execute([$t,(int)$m['found_report_id']]);

            add_status_history(
                $pdo,
                (int)$m['found_report_id'],
                (string)$m['found_item_status'],
                'returned',
                current_user()['id'],
                'Physical item received by administrator from Found Reporter; Found Reporter workflow completed.'
            );

            notify_user($pdo,(int)$m['found_user_id'],'Found Item Received by Administrator',
                'Thank you for turning over the '.$m['found_item_name'].' to the administrator. The item has been physically received. You do not need to attend the lost owner’s verification appointment.',
                'match',(int)$m['found_report_id']);
            if((int)$m['found_user_id']!==(int)$m['lost_user_id']){
                notify_user($pdo,(int)$m['lost_user_id'],'Found Item Received — Verification Can Be Scheduled',
                    'The found item related to your lost '.$m['found_item_name'].' has been physically turned over to the administrator. The administrator can now schedule your in-person ownership verification.',
                    'match',(int)$m['lost_report_id']);
            }

            audit($pdo,current_user()['id'],'Recorded Found Reporter physical turnover','match',$matchId,
                'Found item received by administrator on '.date('F j, Y g:i A',strtotime($t)).($notes?' | '.$notes:''));
            $pdo->commit();
        } elseif($action==='match_schedule'){
            $matchId=(int)$_POST['match_id'];
            $verificationDate=trim($_POST['verification_date']??'');
            $verificationTime=trim($_POST['verification_time']??'');
            $verificationDeadline=trim($_POST['verification_deadline']??'');
            $verificationLocation=trim($_POST['verification_location']??'');
            $remarks=trim($_POST['remarks']??'');

            if($matchId<=0)throw new RuntimeException('Invalid match selected.');
            if($verificationDate===''||$verificationTime===''||$verificationDeadline===''||$verificationLocation===''){
                throw new RuntimeException('Set the owner verification date, time, deadline, and location before releasing the schedule.');
            }

            $scheduledTs=strtotime($verificationDate.' '.$verificationTime);
            $deadlineTs=strtotime($verificationDeadline);
            if($scheduledTs===false||$deadlineTs===false)throw new RuntimeException('The selected owner verification schedule contains an invalid date or time.');
            if($deadlineTs<$scheduledTs)throw new RuntimeException('The verification deadline must be on or after the scheduled date and time.');

            $s=$pdo->prepare("SELECT pm.*,fr.user_id AS found_user_id,fi.item_name AS found_item_name,
                    lr.user_id AS lost_user_id,li.item_name AS lost_item_name
                FROM potential_matches pm
                JOIN reports fr ON fr.id=pm.found_report_id
                JOIN items fi ON fi.id=fr.item_id
                JOIN reports lr ON lr.id=pm.lost_report_id
                JOIN items li ON li.id=lr.item_id
                WHERE pm.id=? LIMIT 1");
            $s->execute([$matchId]);
            $m=$s->fetch();
            if(!$m)throw new RuntimeException('Potential match not found.');
            if($m['match_status']!=='confirmed')throw new RuntimeException('The match must be approved before an owner verification schedule can be released.');
            if(empty($m['found_handover_at']))throw new RuntimeException('The Found Reporter must physically turn the item over to the administrator before the lost-item owner can be scheduled.');
            $handoverTs=strtotime((string)$m['found_handover_at']);
            if($handoverTs!==false && $scheduledTs<$handoverTs)throw new RuntimeException('The lost-item owner schedule must be on or after the actual date and time the Found Reporter turned the item over to the administrator.');
            if(($m['verification_outcome']??'')==='completed')throw new RuntimeException('This potential match has already been completed.');
            if(($m['verification_outcome']??'')==='not_match')throw new RuntimeException('This potential match was closed as not a match.');

            $pdo->beginTransaction();
            $now=now();
            $pdo->prepare("UPDATE potential_matches
                SET verification_date=?,verification_time=?,verification_deadline=?,verification_location=?,
                    admin_remarks=CASE WHEN ?<>'' THEN ? ELSE admin_remarks END,
                    updated_at=? WHERE id=?")
                ->execute([$verificationDate,$verificationTime,$verificationDeadline,$verificationLocation,$remarks,$remarks,$now,$matchId]);

            // Only the lost-item owner's report receives the owner verification schedule.
            $pdo->prepare("UPDATE reports SET item_status='matched',verification_date=?,verification_time=?,verification_deadline=?,verification_location=?,updated_at=? WHERE id=?")
                ->execute([$verificationDate,$verificationTime,$verificationDeadline,$verificationLocation,$now,(int)$m['lost_report_id']]);

            $visitDate=date('F j, Y',$scheduledTs);
            $visitTime=date('g:i A',$scheduledTs);
            $deadlineText=date('F j, Y g:i A',$deadlineTs);

            notify_user($pdo,(int)$m['lost_user_id'],'Owner Verification Schedule Released',
                'The found item for your lost '.$m['lost_item_name'].' has already been physically received by the administrator. Your in-person ownership verification is scheduled at '.$verificationLocation.' on '.$visitDate.' at '.$visitTime.'. Verification deadline: '.$deadlineText.'. Bring your valid Mapúa/university ID and proof of ownership or identifying information. The Found Reporter does not need to attend this appointment.',
                'match',(int)$m['lost_report_id']);

            audit($pdo,current_user()['id'],'Released Lost Owner verification schedule','match',$matchId,
                'Found item already received | '.$visitDate.' '.$visitTime.' @ '.$verificationLocation.' | Deadline: '.$deadlineText.($remarks?' | '.$remarks:''));
            $pdo->commit();
        } elseif($action==='claim_decision'){
            $claimId=(int)$_POST['claim_id'];
            $decision=$_POST['decision'];
            $reason=trim($_POST['reason']??'');

            $s=$pdo->prepare("SELECT c.*,i.item_name,r.id report_id,r.user_id reporter_id,r.event_date,r.campus_location,r.building FROM claims c JOIN items i ON i.id=c.item_id JOIN reports r ON r.item_id=c.item_id AND r.report_type='found' WHERE c.id=? ORDER BY r.id DESC LIMIT 1");
            $s->execute([$claimId]);
            $c=$s->fetch();

            if(!$c)throw new RuntimeException('Claim not found.');
            if(!in_array($decision,['approve','reject'],true))throw new RuntimeException('Invalid claim decision.');
            if($decision==='reject'&&$reason==='')throw new RuntimeException('A rejection reason is required.');

            if($decision==='approve'){
                $verificationDate=trim($_POST['verification_date']??'');
                $verificationTime=trim($_POST['verification_time']??'');
                $verificationDeadline=trim($_POST['verification_deadline']??'');
                $verificationLocation=trim($_POST['verification_location']??'Office of Mapúa, 1st Floor');

                if($verificationDate===''||$verificationTime===''||$verificationDeadline===''){
                    throw new RuntimeException('Set the verification date, time, and deadline before approving the claim.');
                }
                if(strtotime($verificationDeadline) < strtotime($verificationDate.' '.$verificationTime)){
                    throw new RuntimeException('The deadline must be on or after the scheduled verification date and time.');
                }

                $pdo->beginTransaction();

                $now=now();

                // Approve the selected claimant.
                $pdo->prepare("UPDATE claims SET claim_status='approved',rejection_reason=NULL,updated_at=? WHERE id=?")
                    ->execute([$now,$claimId]);

                $old=$pdo->prepare("SELECT item_status FROM reports WHERE id=?");
                $old->execute([(int)$c['report_id']]);
                $oldStatus=(string)$old->fetchColumn();

                // The item is now reserved for verified release.
                $pdo->prepare("UPDATE reports SET item_status='matched',verification_date=?,verification_time=?,verification_deadline=?,verification_location=?,updated_at=? WHERE item_id=? AND report_type='found'")
                    ->execute([$verificationDate,$verificationTime,$verificationDeadline,$verificationLocation,$now,$c['item_id']]);

                add_status_history(
                    $pdo,
                    (int)$c['report_id'],
                    $oldStatus,
                    'matched',
                    current_user()['id'],
                    'Ownership claim approved; release verification scheduled.'
                );

                /*
                 * Multiple-claim resolution:
                 * once one claimant is approved, every other pending claim
                 * for the same item is automatically rejected so there can
                 * be only one approved owner for the item.
                 */
                $otherClaims=$pdo->prepare("
                    SELECT id,claimant_user_id
                    FROM claims
                    WHERE item_id=?
                      AND id<>?
                      AND claim_status='pending'
                ");
                $otherClaims->execute([(int)$c['item_id'],$claimId]);
                $losingClaims=$otherClaims->fetchAll();

                $rejectOthers=$pdo->prepare("
                    UPDATE claims
                    SET claim_status='rejected',
                        rejection_reason=?,
                        updated_at=?
                    WHERE item_id=?
                      AND id<>?
                      AND claim_status='pending'
                ");
                $rejectOthers->execute([
                    'Another claimant was approved for this item after ownership verification.',
                    $now,
                    (int)$c['item_id'],
                    $claimId
                ]);

                $visitDate=date('F j, Y',strtotime($verificationDate));
                $visitTime=date('g:i A',strtotime($verificationTime));
                $deadline=date('F j, Y',strtotime($verificationDeadline));

                notify_user(
                    $pdo,
                    (int)$c['claimant_user_id'],
                    'Claim Approved — Release Schedule',
                    'Your claim for '.$c['item_name'].' has been approved. Please go to '.$verificationLocation.' for in-person Lost-and-Found verification and release. Scheduled visit: '.$visitDate.' at '.$visitTime.'. Verification deadline: '.$deadline.'. Please bring your valid Mapúa/university ID or authorized identification, plus proof of ownership and identifying details. The item will be released only after successful in-person verification.',
                    'claim',
                    (int)$c['report_id'],
                    $claimId
                );

                // Notify and audit each losing claimant.
                foreach($losingClaims as $loser){
                    notify_user(
                        $pdo,
                        (int)$loser['claimant_user_id'],
                        'Claim Closed — Another Claim Approved',
                        'Your claim for '.$c['item_name'].' was closed because another ownership claim was approved after administrator verification.',
                        'claim',
                        (int)$c['report_id'],
                        (int)$loser['id']
                    );

                    audit(
                        $pdo,
                        current_user()['id'],
                        'Competing claim automatically rejected after approval',
                        'claim',
                        (int)$loser['id'],
                        'Item #'.(int)$c['item_id'].' | Approved claim #'.$claimId
                    );
                }

                audit(
                    $pdo,
                    current_user()['id'],
                    'Claim approved and release scheduled',
                    'claim',
                    $claimId,
                    $verificationLocation.' | '.$verificationDate.' '.$verificationTime.' | Deadline: '.$verificationDeadline.' | Competing claims resolved: '.count($losingClaims)
                );

                $pdo->commit();

            } else {
                $pdo->beginTransaction();
                $now=now();

                $pdo->prepare("UPDATE claims SET claim_status='rejected',rejection_reason=?,updated_at=? WHERE id=?")
                    ->execute([$reason,$now,$claimId]);

                // Only reopen the item when there is NO approved claim and
                // NO other pending claim remaining for this item.
                $remaining=$pdo->prepare("
                    SELECT
                        SUM(CASE WHEN claim_status='pending' THEN 1 ELSE 0 END) AS pending_count,
                        SUM(CASE WHEN claim_status='approved' THEN 1 ELSE 0 END) AS approved_count
                    FROM claims
                    WHERE item_id=?
                ");
                $remaining->execute([(int)$c['item_id']]);
                $claimState=$remaining->fetch() ?: ['pending_count'=>0,'approved_count'=>0];

                if((int)$claimState['pending_count']===0 && (int)$claimState['approved_count']===0){
                    $old=$pdo->prepare("SELECT item_status FROM reports WHERE item_id=? AND report_type='found' ORDER BY id DESC LIMIT 1");
                    $old->execute([(int)$c['item_id']]);
                    $oldStatus=(string)$old->fetchColumn();

                    if($oldStatus==='claim_pending'){
                        $pdo->prepare("UPDATE reports SET item_status='open',updated_at=? WHERE item_id=? AND report_type='found' AND item_status='claim_pending'")
                            ->execute([$now,(int)$c['item_id']]);

                        $historyReport=$pdo->prepare("SELECT id FROM reports WHERE item_id=? AND report_type='found' ORDER BY id DESC LIMIT 1");
                        $historyReport->execute([(int)$c['item_id']]);
                        $historyReportId=(int)$historyReport->fetchColumn();

                        if($historyReportId){
                            add_status_history(
                                $pdo,
                                $historyReportId,
                                'claim_pending',
                                'open',
                                current_user()['id'],
                                'All ownership claims were rejected; item is available for new claims.'
                            );
                        }
                    }
                }

                notify_user(
                    $pdo,
                    (int)$c['claimant_user_id'],
                    'Claim Rejected',
                    'Your claim for '.$c['item_name'].' was rejected. Reason: '.$reason,
                    'claim',
                    (int)$c['report_id'],
                    $claimId
                );

                audit(
                    $pdo,
                    current_user()['id'],
                    'Claim rejected',
                    'claim',
                    $claimId,
                    $reason
                );

                $pdo->commit();
            }
        } elseif($action==='handover_item'){
            $claimId=(int)($_POST['claim_id']??0);
            $handoverNotes=trim((string)($_POST['handover_notes']??''));
            if($claimId<=0)throw new RuntimeException('Invalid claim selected for handover.');

            $s=$pdo->prepare("
                SELECT
                    c.*,
                    i.item_name,
                    r.id AS found_report_id,
                    r.user_id AS found_reporter_user_id,
                    r.item_status,
                    r.verification_date,
                    r.verification_time,
                    r.verification_deadline,
                    r.verification_location
                FROM claims c
                JOIN items i ON i.id=c.item_id
                JOIN reports r
                    ON r.item_id=c.item_id
                   AND r.report_type='found'
                WHERE c.id=?
                ORDER BY r.id DESC
                LIMIT 1
            ");
            $s->execute([$claimId]);
            $handoverClaim=$s->fetch();

            if(!$handoverClaim)throw new RuntimeException('Approved claim or found item could not be found.');
            if(($handoverClaim['claim_status']??'')!=='approved'){
                throw new RuntimeException('Only an approved claim can be handed over.');
            }
            if(!empty($handoverClaim['handover_at'])){
                throw new RuntimeException('This item has already been handed over.');
            }
            if(empty($handoverClaim['verification_date']) || empty($handoverClaim['verification_time']) || empty($handoverClaim['verification_deadline'])){
                throw new RuntimeException('The release verification schedule is incomplete.');
            }

            $pdo->beginTransaction();
            $t=now();

            // Mark the found item as physically returned/released.
            $pdo->prepare("
                UPDATE reports
                SET item_status='returned', updated_at=?
                WHERE id=?
            ")->execute([$t,(int)$handoverClaim['found_report_id']]);

            $pdo->prepare("
                UPDATE claims
                SET handover_at=?, handover_admin_id=?, handover_notes=?, updated_at=?
                WHERE id=?
            ")->execute([$t,(int)current_user()['id'],$handoverNotes,$t,$claimId]);

            /*
             * The item may also have an automated Lost ↔ Found potential-match
             * record. Once the administrator physically hands the item over
             * through an approved claim, that item is no longer in an active
             * match workflow. Stamp the linked match records with the same
             * handover time so the original FOUND reporter sees the final
             * Completed state instead of an old Potential Match status.
             */
            $linkedMatches=$pdo->prepare("
                UPDATE potential_matches
                SET handover_at=?, handover_admin_id=?,
                    handover_notes=CASE
                        WHEN COALESCE(handover_notes,'')='' THEN ?
                        ELSE handover_notes
                    END,
                    updated_at=?
                WHERE found_report_id=? AND handover_at IS NULL
            ");
            $linkedMatchNote='Item was physically handed over to the approved claimant through the ownership-claim release process.'
                .($handoverNotes?' '.$handoverNotes:'');
            $linkedMatches->execute([
                $t,
                (int)current_user()['id'],
                $linkedMatchNote,
                $t,
                (int)$handoverClaim['found_report_id']
            ]);

            add_status_history(
                $pdo,
                (int)$handoverClaim['found_report_id'],
                (string)$handoverClaim['item_status'],
                'returned',
                current_user()['id'],
                'Item handed over to approved claimant after release verification.'
            );

            notify_user(
                $pdo,
                (int)$handoverClaim['claimant_user_id'],
                'Item Handed Over — Claim Completed',
                'Your claim for '.$handoverClaim['item_name'].' has been completed. The item was handed over after release verification at '.$handoverClaim['verification_location'].'.',
                'claim',
                (int)$handoverClaim['found_report_id'],
                $claimId
            );

            /* Notify the student who originally reported the FOUND item. */
            if ((int)$handoverClaim['found_reporter_user_id'] > 0
                && (int)$handoverClaim['found_reporter_user_id'] !== (int)$handoverClaim['claimant_user_id']) {
                notify_user(
                    $pdo,
                    (int)$handoverClaim['found_reporter_user_id'],
                    'Found Item Successfully Returned — Thank You',
                    'The item you reported as found was successfully handed over to its rightful owner after in-person verification on '.date('F j, Y g:i A',strtotime($t)).'. Thank you for your honesty and for helping return a lost item to its owner!',
                    'report',
                    (int)$handoverClaim['found_report_id']
                );
            }

            audit(
                $pdo,
                current_user()['id'],
                'Item handed over to approved claimant',
                'claim',
                $claimId,
                'Item #'.(int)$handoverClaim['item_id'].' | Found report #'.(int)$handoverClaim['found_report_id'].' | Claimant user #'.(int)$handoverClaim['claimant_user_id']
            );

            $pdo->commit();
            flash('success','Item successfully handed over. The item is now marked as returned and the claimant has been notified.');
        } elseif($action==='match_verification_outcome'){
            $matchId=(int)($_POST['match_id']??0);
            $outcome=trim((string)($_POST['verification_outcome']??''));
            $reason=trim((string)($_POST['verification_outcome_reason']??''));
            $evidence=trim((string)($_POST['handover_evidence']??''));
            $notes=trim((string)($_POST['handover_notes']??''));

            if($matchId<=0)throw new RuntimeException('Invalid potential match selected.');
            if(!in_array($outcome,['completed','not_match','reschedule'],true))throw new RuntimeException('Select a valid in-person verification outcome.');
            if($reason==='')throw new RuntimeException('Record the reason for the selected in-person verification outcome.');
            if($outcome==='completed' && $evidence===''){
                throw new RuntimeException('Record the ownership evidence verified in person before marking the item as completed.');
            }

            $s=$pdo->prepare("
                SELECT
                    pm.*,
                    lr.id AS lost_report_id,
                    lr.user_id AS lost_user_id,
                    lr.item_status AS lost_item_status,
                    fr.id AS found_report_id,
                    fr.user_id AS found_user_id,
                    fr.item_status AS found_item_status,
                    li.item_name AS lost_item_name,
                    fi.item_name AS found_item_name
                FROM potential_matches pm
                JOIN reports lr ON lr.id=pm.lost_report_id
                JOIN items li ON li.id=lr.item_id
                JOIN reports fr ON fr.id=pm.found_report_id
                JOIN items fi ON fi.id=fr.item_id
                WHERE pm.id=?
                LIMIT 1
            ");
            $s->execute([$matchId]);
            $verificationMatch=$s->fetch();

            if(!$verificationMatch)throw new RuntimeException('Potential match not found.');
            if(($verificationMatch['match_status']??'')!=='confirmed')throw new RuntimeException('Only an administrator-approved match can be finalized.');
            if(empty($verificationMatch['found_handover_at'])){
                throw new RuntimeException('The Found Reporter must physically turn the item over before the owner verification can be finalized.');
            }
            if(empty($verificationMatch['verification_date']) || empty($verificationMatch['verification_time']) || empty($verificationMatch['verification_deadline']) || empty($verificationMatch['verification_location'])){
                throw new RuntimeException('Release the owner verification schedule before recording the in-person verification result.');
            }
            if(($verificationMatch['verification_outcome']??'')==='completed' || !empty($verificationMatch['handover_at'])){
                throw new RuntimeException('This potential match has already been completed.');
            }

            $pdo->beginTransaction();
            $t=now();

            if($outcome==='completed'){
                /* Physical verification confirmed ownership and the item was released. */
                $pdo->prepare("UPDATE reports SET item_status='returned',updated_at=? WHERE id IN (?,?)")
                    ->execute([$t,(int)$verificationMatch['lost_report_id'],(int)$verificationMatch['found_report_id']]);

                $pdo->prepare("
                    UPDATE potential_matches
                    SET handover_at=?,
                        handover_admin_id=?,
                        handover_evidence=?,
                        handover_notes=?,
                        verification_outcome='completed',
                        verification_outcome_reason=?,
                        updated_at=?
                    WHERE id=? AND handover_at IS NULL
                ")->execute([
                    $t,
                    (int)current_user()['id'],
                    $evidence,
                    $notes,
                    $reason,
                    $t,
                    $matchId
                ]);

                add_status_history(
                    $pdo,
                    (int)$verificationMatch['lost_report_id'],
                    (string)$verificationMatch['lost_item_status'],
                    'returned',
                    current_user()['id'],
                    'In-person verification confirmed the potential match and the item was released to the lost-item owner. Reason: '.$reason.' | Evidence verified: '.$evidence.($notes?' | '.$notes:'')
                );
                add_status_history(
                    $pdo,
                    (int)$verificationMatch['found_report_id'],
                    (string)$verificationMatch['found_item_status'],
                    'returned',
                    current_user()['id'],
                    'In-person verification confirmed the Lost/Found match and the found item was released to its verified owner. Reason: '.$reason
                );

                notify_user(
                    $pdo,
                    (int)$verificationMatch['lost_user_id'],
                    'In-Person Verification Completed — Item Returned',
                    'Your potential match for “'.$verificationMatch['lost_item_name'].'” was verified in person and the item was released to you at '.$verificationMatch['verification_location'].'. Completed on '.date('F j, Y g:i A',strtotime($t)).'. Reason recorded by the administrator: '.$reason,
                    'report',
                    (int)$verificationMatch['lost_report_id']
                );

                if((int)$verificationMatch['found_user_id']!==(int)$verificationMatch['lost_user_id']){
                    notify_user(
                        $pdo,
                        (int)$verificationMatch['found_user_id'],
                        'In-Person Verification Completed — Item Returned',
                        'The potential Lost/Found match involving your found '.$verificationMatch['found_item_name'].' was verified in person and the item was released to the verified owner. The case was completed on '.date('F j, Y g:i A',strtotime($t)).'.',
                        'report',
                        (int)$verificationMatch['found_report_id']
                    );
                }

                audit(
                    $pdo,
                    current_user()['id'],
                    'Completed Lost/Found match after in-person verification',
                    'match',
                    $matchId,
                    'Lost report #'.(int)$verificationMatch['lost_report_id'].' | Found report #'.(int)$verificationMatch['found_report_id'].' | Reason: '.$reason.' | Evidence verified: '.$evidence.($notes?' | '.$notes:'')
                );

                $successMessage='In-person verification completed. The item was confirmed as the student’s item and marked as returned.';
            } elseif($outcome==='not_match'){
                /*
                 * The automated match was only a recommendation. If the physical
                 * inspection shows the item is not the student's item, reopen the
                 * found item so other students can see/claim it again.
                 */
                $pdo->prepare("UPDATE reports SET item_status='open',verification_date=NULL,verification_time=NULL,verification_deadline=NULL,verification_location=NULL,updated_at=? WHERE id IN (?,?)")
                    ->execute([$t,(int)$verificationMatch['lost_report_id'],(int)$verificationMatch['found_report_id']]);

                $pdo->prepare("
                    UPDATE potential_matches
                    SET match_status='rejected',
                        verification_outcome='not_match',
                        verification_outcome_reason=?,
                        verification_date=NULL,
                        verification_time=NULL,
                        verification_deadline=NULL,
                        verification_location=NULL,
                        handover_at=NULL,
                        handover_admin_id=NULL,
                        handover_evidence=NULL,
                        handover_notes=?,
                        updated_at=?
                    WHERE id=?
                ")->execute([$reason,$notes,$t,$matchId]);

                add_status_history(
                    $pdo,
                    (int)$verificationMatch['lost_report_id'],
                    (string)$verificationMatch['lost_item_status'],
                    'open',
                    current_user()['id'],
                    'In-person verification found that the potential match was not the student’s item. Lost report remains active. Reason: '.$reason.($notes?' | '.$notes:'')
                );
                add_status_history(
                    $pdo,
                    (int)$verificationMatch['found_report_id'],
                    (string)$verificationMatch['found_item_status'],
                    'open',
                    current_user()['id'],
                    'In-person verification found that the potential match was not valid. Found item reopened in Search & Browse. Reason: '.$reason.($notes?' | '.$notes:'')
                );

                notify_user(
                    $pdo,
                    (int)$verificationMatch['lost_user_id'],
                    'Potential Match Not Confirmed',
                    'The item checked during the in-person verification was not confirmed as your lost '.$verificationMatch['lost_item_name'].'. Your lost report remains active so another potential match can be identified. Administrator reason: '.$reason,
                    'match',
                    (int)$verificationMatch['lost_report_id']
                );

                if((int)$verificationMatch['found_user_id']!==(int)$verificationMatch['lost_user_id']){
                    notify_user(
                        $pdo,
                        (int)$verificationMatch['found_user_id'],
                        'Found Item Reopened — Not a Match',
                        'The potential Lost/Found match involving your found '.$verificationMatch['found_item_name'].' was not confirmed during the in-person verification. The found item has been reopened and is available again in Search & Browse.',
                        'report',
                        (int)$verificationMatch['found_report_id']
                    );
                }

                audit(
                    $pdo,
                    current_user()['id'],
                    'Closed potential Lost/Found match as not a match',
                    'match',
                    $matchId,
                    'Found report #'.(int)$verificationMatch['found_report_id'].' reopened | Lost report #'.(int)$verificationMatch['lost_report_id'].' remains active | Reason: '.$reason.($notes?' | '.$notes:'')
                );

                $successMessage='Verification recorded as Not a Match. The found item has been reopened in Search & Browse.';
            } else {
                /*
                 * The student appeared, but the administrator could not complete
                 * a reliable determination. Keep the match active and clear the
                 * old appointment so a new schedule can be released.
                 */
                $pdo->prepare("UPDATE reports SET item_status='matched',verification_date=NULL,verification_time=NULL,verification_deadline=NULL,verification_location=NULL,updated_at=? WHERE id IN (?,?)")
                    ->execute([$t,(int)$verificationMatch['lost_report_id'],(int)$verificationMatch['found_report_id']]);

                $pdo->prepare("
                    UPDATE potential_matches
                    SET verification_outcome='reschedule',
                        verification_outcome_reason=?,
                        verification_date=NULL,
                        verification_time=NULL,
                        verification_deadline=NULL,
                        verification_location=NULL,
                        handover_at=NULL,
                        handover_admin_id=NULL,
                        handover_evidence=NULL,
                        handover_notes=?,
                        updated_at=?
                    WHERE id=?
                ")->execute([$reason,$notes,$t,$matchId]);

                add_status_history(
                    $pdo,
                    (int)$verificationMatch['lost_report_id'],
                    (string)$verificationMatch['lost_item_status'],
                    'matched',
                    current_user()['id'],
                    'In-person verification could not be completed; another checking schedule is required. Reason: '.$reason.($notes?' | '.$notes:'')
                );
                add_status_history(
                    $pdo,
                    (int)$verificationMatch['found_report_id'],
                    (string)$verificationMatch['found_item_status'],
                    'matched',
                    current_user()['id'],
                    'In-person verification could not be completed; another checking schedule is required. Reason: '.$reason
                );

                notify_user(
                    $pdo,
                    (int)$verificationMatch['lost_user_id'],
                    'Verification Needs to Be Rescheduled',
                    'The in-person checking for your potential match could not be completed. Your case remains active and the administrator can release a new schedule. Reason: '.$reason,
                    'match',
                    (int)$verificationMatch['lost_report_id']
                );

                if((int)$verificationMatch['found_user_id']!==(int)$verificationMatch['lost_user_id']){
                    notify_user(
                        $pdo,
                        (int)$verificationMatch['found_user_id'],
                        'Verification Needs to Be Rescheduled',
                        'The in-person checking for the potential match involving your found '.$verificationMatch['found_item_name'].' could not be completed. The match remains active and a new schedule can be released.',
                        'match',
                        (int)$verificationMatch['found_report_id']
                    );
                }

                audit(
                    $pdo,
                    current_user()['id'],
                    'Rescheduled Lost/Found in-person verification',
                    'match',
                    $matchId,
                    'Reason: '.$reason.($notes?' | '.$notes:'')
                );

                $successMessage='Verification recorded as Unable to Verify. The match remains active and a new schedule can be released.';
            }

            $pdo->commit();
            flash('success',$successMessage);
        } elseif($action==='item_status'){
            $reportId=(int)$_POST['report_id'];$new=trim($_POST['new_status']);$remarks=trim($_POST['remarks']??'');
            if(!in_array($new,['open','matched','claim_pending','returned','closed'],true))throw new RuntimeException('Invalid status.');
            $s=$pdo->prepare("SELECT * FROM reports WHERE id=?");$s->execute([$reportId]);$r=$s->fetch();if(!$r)throw new RuntimeException('Report not found.');
            $pdo->prepare("UPDATE reports SET item_status=?,updated_at=? WHERE id=?")->execute([$new,now(),$reportId]);
            add_status_history($pdo,$reportId,$r['item_status'],$new,current_user()['id'],$remarks);
            notify_user($pdo,(int)$r['user_id'],'Item status updated','Your report RPT-'.str_pad((string)$reportId,4,'0',STR_PAD_LEFT).' is now '.status_label($new).'.','status',$reportId);
            audit($pdo,current_user()['id'],'Item status changed','report',$reportId,$r['item_status'].' → '.$new.($remarks?' | '.$remarks:''));
        } elseif($action==='send_guidance'){
            $title=trim($_POST['title']??'');$message=trim($_POST['message']??'');
            if($title===''||$message==='')throw new RuntimeException('Title and message are required.');
            $users=$pdo->query("SELECT id FROM users WHERE role='user' AND account_status='active'")->fetchAll(PDO::FETCH_COLUMN);
            $ins=$pdo->prepare("INSERT INTO notifications(user_id,title,message,notification_type,created_at) VALUES(?,?,?,?,?)");
            foreach($users as $uid)$ins->execute([(int)$uid,$title,$message,'guidance',now()]);
            audit($pdo,current_user()['id'],'Sent guidance notification to registered users',null,null,'Recipients: '.count($users));
            flash('success','Guidance notification sent to '.count($users).' registered user(s).');
        } elseif($action==='message_reply'){
            $threadId=(int)($_POST['thread_id']??0);
            $message=trim($_POST['message']??'');
            if($threadId<=0||$message==='')throw new RuntimeException('Please enter a message before sending.');
            if(mb_strlen($message)>3000)throw new RuntimeException('Message must be 3000 characters or fewer.');

            // Only reply to a real user-owned conversation. Never trust a posted user ID.
            $s=$pdo->prepare("SELECT t.*,u.full_name AS user_name,u.email AS user_email
                FROM message_threads t
                JOIN users u ON u.id=t.user_id
                WHERE t.id=? AND u.role='user'
                LIMIT 1");
            $s->execute([$threadId]);
            $thread=$s->fetch();
            if(!$thread)throw new RuntimeException('Conversation not found.');

            $t=now();
            $pdo->beginTransaction();
            // Admin replies are unread for the user. The exact text is stored unchanged.
            $pdo->prepare("INSERT INTO messages(thread_id,sender_user_id,message,is_read,created_at) VALUES(?,?,?,?,?)")
                ->execute([$threadId,current_user()['id'],$message,0,$t]);
            $pdo->prepare("UPDATE message_threads SET status='open',updated_at=?,last_message_at=? WHERE id=?")
                ->execute([$t,$t,$threadId]);
            audit(
                $pdo,
                current_user()['id'],
                'Replied to user message',
                'message_thread',
                $threadId,
                'Recipient user ID: '.(int)$thread['user_id']
            );
            $pdo->commit();
            flash('success','Reply sent to the user.');
        } elseif($action==='user_status'){
            $userId=(int)$_POST['user_id'];$new=$_POST['account_status']==='inactive'?'inactive':'active';
            if($userId===current_user()['id'])throw new RuntimeException('You cannot deactivate your own account.');
            $pdo->prepare("UPDATE users SET account_status=?,updated_at=? WHERE id=?")->execute([$new,now(),$userId]);
            audit($pdo,current_user()['id'],'User account '.ucfirst($new),'user',$userId);
        }
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}

$stats=[
'total'=>(int)$pdo->query("SELECT COUNT(*) FROM reports")->fetchColumn(),
'lost'=>(int)$pdo->query("SELECT COUNT(*) FROM reports WHERE report_type='lost'")->fetchColumn(),
'found'=>(int)$pdo->query("SELECT COUNT(*) FROM reports WHERE report_type='found'")->fetchColumn(),
'pending_reports'=>(int)$pdo->query("SELECT COUNT(*) FROM reports WHERE report_status='pending_review'")->fetchColumn(),
'pending_claims'=>(int)$pdo->query("SELECT COUNT(*) FROM claims WHERE claim_status='pending'")->fetchColumn(),
'returned'=>(int)$pdo->query("SELECT COUNT(*) FROM reports WHERE item_status='returned'")->fetchColumn(),
'closed'=>(int)$pdo->query("SELECT COUNT(*) FROM reports WHERE item_status='closed'")->fetchColumn(),
];
$pendingReports=$pdo->query("SELECT r.*,i.item_name,u.full_name FROM reports r JOIN items i ON i.id=r.item_id JOIN users u ON u.id=r.user_id WHERE r.report_status='pending_review' ORDER BY r.created_at ASC")->fetchAll();
$claims=$pdo->query("
    SELECT
        c.*,
        c.proof_photo_path,
        i.item_name,
        i.category,
        i.description AS item_description,
        i.color,
        i.brand,
        i.identifying_features,
        i.photo_path,
        u.full_name,
        u.email,
        u.university_id,
        r.id AS found_report_id,
        r.item_status,
        r.event_date AS found_event_date,
        r.event_time AS found_event_time,
        r.campus_location AS found_campus_location,
        r.building AS found_building,
        r.floor AS found_floor,
        r.specific_area AS found_specific_area,
        r.storage_location,
        r.verification_date,
        r.verification_time,
        r.verification_deadline,
        r.verification_location,

        lr.id AS lost_report_id,
        lr.user_id AS lost_reporter_user_id,
        lost_u.full_name AS lost_reporter_name,
        lost_u.email AS lost_reporter_email,
        lr.event_date AS lost_event_date,
        lr.event_time AS lost_event_time,
        lr.campus_location AS lost_campus_location,
        lr.building AS lost_building,
        lr.floor AS lost_floor,
        lr.specific_area AS lost_specific_area,
        lr.additional_notes AS lost_additional_notes
    FROM claims c
    JOIN items i ON i.id=c.item_id
    JOIN users u ON u.id=c.claimant_user_id
    LEFT JOIN reports r
        ON r.id=(
            SELECT r2.id
            FROM reports r2
            WHERE r2.item_id=c.item_id
              AND r2.report_type='found'
            ORDER BY r2.id DESC
            LIMIT 1
        )
    LEFT JOIN reports lr
        ON lr.id=(
            SELECT lr2.id
            FROM reports lr2
            WHERE lr2.item_id=c.item_id
              AND lr2.report_type='lost'
            ORDER BY
                CASE lr2.report_status
                    WHEN 'approved' THEN 0
                    WHEN 'pending_review' THEN 1
                    ELSE 2
                END,
                lr2.id DESC
            LIMIT 1
        )
    LEFT JOIN users lost_u ON lost_u.id=lr.user_id
    ORDER BY c.created_at DESC
")->fetchAll();
$allReports=$pdo->query("SELECT r.*,i.item_name,i.category,i.description,i.color,i.brand,i.identifying_features,i.photo_path,u.full_name,u.email FROM reports r JOIN items i ON i.id=r.item_id JOIN users u ON u.id=r.user_id ORDER BY r.created_at DESC")->fetchAll();

/*
 * Reports are automatically recorded. Refresh Lost ↔ Found matching before
 * loading the match list so newly submitted records can be detected without
 * a separate report-approval step.
 */
try {
    sync_all_potential_matches($pdo);
} catch (Throwable $matchSyncError) {
    /* Matching is supplemental; keep the admin page usable if it fails. */
}

$potentialMatches=$pdo->query("
    SELECT
        pm.*,

        fr.id AS found_report_id,
        fr.event_date AS found_event_date,
        fr.event_time AS found_event_time,
        fr.campus_location AS found_campus_location,
        fr.building AS found_building,
        fr.floor AS found_floor,
        fr.specific_area AS found_specific_area,
        fr.storage_location AS found_storage_location,
        fr.additional_notes AS found_notes,
        fr.item_status AS found_item_status,

        fi.item_name AS found_item_name,
        fi.category AS found_category,
        fi.description AS found_description,
        fi.color AS found_color,
        fi.brand AS found_brand,
        fi.identifying_features AS found_identifying_features,
        fi.photo_path AS found_photo_path,

        lr.id AS lost_report_id,
        lr.event_date AS lost_event_date,
        lr.event_time AS lost_event_time,
        lr.campus_location AS lost_campus_location,
        lr.building AS lost_building,
        lr.floor AS lost_floor,
        lr.specific_area AS lost_specific_area,
        lr.additional_notes AS lost_notes,

        li.item_name AS lost_item_name,
        li.category AS lost_category,
        li.description AS lost_description,
        li.color AS lost_color,
        li.brand AS lost_brand,
        li.identifying_features AS lost_identifying_features,
        li.photo_path AS lost_photo_path,

        u.full_name AS lost_owner,
        u.email AS lost_owner_email,
        fu.full_name AS found_reporter,
        fu.email AS found_reporter_email

    FROM potential_matches pm

    JOIN reports fr
        ON fr.id=pm.found_report_id

    JOIN items fi
        ON fi.id=fr.item_id

    JOIN reports lr
        ON lr.id=pm.lost_report_id

    JOIN items li
        ON li.id=lr.item_id

    JOIN users u
        ON u.id=lr.user_id

    JOIN users fu
        ON fu.id=fr.user_id

    ORDER BY
        CASE pm.match_status
            WHEN 'pending' THEN 0
            WHEN 'confirmed' THEN 1
            ELSE 2
        END,
        pm.match_score DESC,
        pm.created_at DESC
")->fetchAll();

$matchFilter=$_GET['match_filter']??'all';

if(!in_array($matchFilter,['all','pending','confirmed','rejected','completed','not_match'],true)){
    $matchFilter='all';
}

$matchCounts=[
    'all'=>count($potentialMatches),
    'pending'=>count(array_filter(
        $potentialMatches,
        static fn($m)=>$m['match_status']==='pending'
    )),
    'confirmed'=>count(array_filter(
        $potentialMatches,
        static fn($m)=>$m['match_status']==='confirmed' && ($m['verification_outcome']??'')!=='completed'
    )),
    'rejected'=>count(array_filter(
        $potentialMatches,
        static fn($m)=>$m['match_status']==='rejected'
    )),
    'completed'=>count(array_filter(
        $potentialMatches,
        static fn($m)=>($m['verification_outcome']??'')==='completed'
    )),
    'not_match'=>count(array_filter(
        $potentialMatches,
        static fn($m)=>($m['verification_outcome']??'')==='not_match'
    )),
];

$visibleMatches=$matchFilter==='all'
    ? $potentialMatches
    : array_values(array_filter(
        $potentialMatches,
        static function($m) use ($matchFilter){
            if($matchFilter==='completed')return ($m['verification_outcome']??'')==='completed';
            if($matchFilter==='not_match')return ($m['verification_outcome']??'')==='not_match';
            if($matchFilter==='confirmed')return $m['match_status']==='confirmed' && ($m['verification_outcome']??'')!=='completed';
            return $m['match_status']===$matchFilter;
        }
    ));

$allUsers=$pdo->query("SELECT u.*, (SELECT COUNT(*) FROM reports r WHERE r.user_id=u.id) report_count, (SELECT COUNT(*) FROM claims c WHERE c.claimant_user_id=u.id) claim_count FROM users u ORDER BY u.created_at DESC")->fetchAll();
$history=$pdo->query("SELECT sh.*,u.full_name,r.id report_id,i.item_name FROM status_history sh JOIN users u ON u.id=sh.changed_by JOIN reports r ON r.id=sh.report_id JOIN items i ON i.id=r.item_id ORDER BY sh.created_at DESC LIMIT 100")->fetchAll();
$auditRows=$pdo->query("SELECT a.*,u.full_name FROM audit_logs a JOIN users u ON u.id=a.admin_user_id ORDER BY a.created_at DESC LIMIT 100")->fetchAll();
$inboxMessages=$pdo->prepare("
    SELECT t.*, u.full_name AS user_name, u.email AS user_email,
           (SELECT m.message FROM messages m WHERE m.thread_id=t.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_message,
           (SELECT m.created_at FROM messages m WHERE m.thread_id=t.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_message_created_at,
           (SELECT COUNT(*) FROM messages m WHERE m.thread_id=t.id AND m.is_read=0 AND m.sender_user_id<>?) AS unread_count
    FROM message_threads t
    JOIN users u ON u.id=t.user_id
    WHERE u.role='user'
    ORDER BY unread_count DESC, t.last_message_at DESC, t.id DESC
    LIMIT 100
");
$inboxMessages->execute([current_user()['id']]);
$inboxMessages=$inboxMessages->fetchAll();

/* =========================================================
   OVERVIEW DASHBOARD DATA
========================================================= */

$pendingClaims = array_values(
    array_filter(
        $claims,
        static fn($c) => ($c['claim_status'] ?? '') === 'pending'
    )
);

/*
 * Group pending claims by item so the overview can highlight
 * items that have multiple competing ownership claims.
 */
$claimsByItem = [];

foreach ($pendingClaims as $claim) {
    $itemId = (int)$claim['item_id'];

    if (!isset($claimsByItem[$itemId])) {
        $claimsByItem[$itemId] = [];
    }

    $claimsByItem[$itemId][] = $claim;
}

/*
 * Group ALL claims by item for the Claims & Handover workspace.
 * This lets administrators compare competing claimants side-by-side.
 */
$allClaimsByItem = [];

foreach ($claims as $claim) {
    $itemId = (int)$claim['item_id'];

    if (!isset($allClaimsByItem[$itemId])) {
        $allClaimsByItem[$itemId] = [];
    }

    $allClaimsByItem[$itemId][] = $claim;
}

$claimItemGroups = array_values($allClaimsByItem);

$claimsPendingCount = count($pendingClaims);
$claimsApprovedCount = count(array_filter(
    $claims,
    static fn($c) => ($c['claim_status'] ?? '') === 'approved'
));
$claimsRejectedCount = count(array_filter(
    $claims,
    static fn($c) => ($c['claim_status'] ?? '') === 'rejected'
));

$multipleClaimGroups = array_values(
    array_filter(
        $claimsByItem,
        static fn($group) => count($group) > 1
    )
);

$multipleClaimCount = count($multipleClaimGroups);
$featuredMultipleClaims = $multipleClaimGroups[0] ?? [];
$featuredClaimItem = $featuredMultipleClaims[0] ?? null;

$adminUnreadTotal = array_sum(
    array_map(
        static fn($m) => (int)($m['unread_count'] ?? 0),
        $inboxMessages
    )
);

$inquiryCount = count($inboxMessages);

$recoveryRate = $stats['found'] > 0
    ? round(($stats['returned'] / $stats['found']) * 100)
    : 0;

$systemMatchCount = count(
    array_filter(
        $potentialMatches,
        static fn($m) => ($m['match_status'] ?? '') === 'pending'
    )
);

$recentAudit = array_slice($auditRows, 0, 5);

/* Reports are automatically active. Convert any legacy pending-review records so the
 * current workflow never waits for administrator report approval. */
try {
    $pdo->exec("UPDATE reports SET report_status='approved', rejection_reason=NULL, updated_at=updated_at WHERE report_status='pending_review'");
} catch (Throwable $e) {}

/* Report verification page filters/counts. */
$reportFilter = $_GET['report_filter'] ?? 'approved';
if (!in_array($reportFilter, ['approved','rejected','all'], true)) {
    $reportFilter = 'approved';
}

$reportCounts = [
    'pending_review' => 0,
    'approved' => 0,
    'rejected' => 0,
    'all' => count($allReports),
];

foreach ($allReports as $reportRow) {
    $statusKey = (string)($reportRow['report_status'] ?? '');
    if (isset($reportCounts[$statusKey])) {
        $reportCounts[$statusKey]++;
    }
}

$visibleReports = $reportFilter === 'all'
    ? $allReports
    : array_values(array_filter(
        $allReports,
        static fn($reportRow) => ($reportRow['report_status'] ?? '') === $reportFilter
    ));

/*
 * Attach the latest Lost ↔ Found workflow record to each report.  The Reports
 * tab is intentionally a complete lifecycle view, so an approved report must
 * remain visible with its exact item details, release schedule, in-person
 * verification result, and final handover state.
 */
$reportMatchByReportId = [];
try {
    $reportMatches = $pdo->query("
        SELECT
            pm.*,
            fr.item_id AS found_item_id,
            fi.item_name AS found_item_name,
            lr.item_id AS lost_item_id,
            li.item_name AS lost_item_name
        FROM potential_matches pm
        JOIN reports fr ON fr.id=pm.found_report_id
        JOIN items fi ON fi.id=fr.item_id
        JOIN reports lr ON lr.id=pm.lost_report_id
        JOIN items li ON li.id=lr.item_id
        ORDER BY
            CASE pm.match_status
                WHEN 'confirmed' THEN 0
                WHEN 'pending' THEN 1
                ELSE 2
            END,
            CASE WHEN pm.handover_at IS NOT NULL AND pm.handover_at<>'' THEN 0 ELSE 1 END,
            pm.updated_at DESC,
            pm.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($reportMatches as $reportMatch) {
        $lostId=(int)$reportMatch['lost_report_id'];
        $foundId=(int)$reportMatch['found_report_id'];
        if (!isset($reportMatchByReportId[$lostId])) {
            $reportMatchByReportId[$lostId]=$reportMatch;
        }
        if (!isset($reportMatchByReportId[$foundId])) {
            $reportMatchByReportId[$foundId]=$reportMatch;
        }
    }
} catch (Throwable $reportMatchError) {
    /* The Reports page remains usable if an older database has no match rows. */
}

/*
 * Approved Lost ↔ Found matches are displayed as one case only after the
 * administrator has approved the match (match_status = confirmed).
 * Pending/rejected matches continue to appear as separate reports.
 */
$visibleReportById=[];
foreach($visibleReports as $visibleReport){
    $visibleReportById[(int)$visibleReport['id']]=$visibleReport;
}
$combinedMatchCases=[];
$combinedReportIds=[];
if(!empty($reportMatches)){
    foreach($reportMatches as $approvedMatch){
        if(($approvedMatch['match_status']??'')!=='confirmed'){
            continue;
        }
        $lostId=(int)($approvedMatch['lost_report_id']??0);
        $foundId=(int)($approvedMatch['found_report_id']??0);
        if(!$lostId || !$foundId || !isset($visibleReportById[$lostId],$visibleReportById[$foundId])){
            continue;
        }
        if(($visibleReportById[$lostId]['report_status']??'')!=='approved' || ($visibleReportById[$foundId]['report_status']??'')!=='approved'){
            continue;
        }
        $caseId=(int)$approvedMatch['id'];
        if(isset($combinedMatchCases[$caseId])){
            continue;
        }
        $combinedMatchCases[$caseId]=[
            'match'=>$approvedMatch,
            'lost'=>$visibleReportById[$lostId],
            'found'=>$visibleReportById[$foundId],
        ];
        $combinedReportIds[$lostId]=$caseId;
        $combinedReportIds[$foundId]=$caseId;
    }
}



$selectedThreadId=(int)($_GET['thread']??0);
if($tab==='messages' && !$selectedThreadId && $inboxMessages){
    $selectedThreadId=(int)$inboxMessages[0]['id'];
}
$selectedThread=null;
$selectedThreadMessages=[];
if($tab==='messages' && $selectedThreadId){
    $s=$pdo->prepare("SELECT t.*,u.full_name AS user_name,u.email AS user_email
        FROM message_threads t
        JOIN users u ON u.id=t.user_id
        WHERE t.id=? AND u.role='user'
        LIMIT 1");
    $s->execute([$selectedThreadId]);
    $selectedThread=$s->fetch();
    if($selectedThread){
        $m=$pdo->prepare("SELECT m.*,u.full_name,u.email,u.role
            FROM messages m
            JOIN users u ON u.id=m.sender_user_id
            WHERE m.thread_id=?
            ORDER BY m.created_at ASC, m.id ASC");
        $m->execute([$selectedThreadId]);
        $selectedThreadMessages=$m->fetchAll();

        // Opening a conversation marks only messages sent by the user as read.
        $pdo->prepare("UPDATE messages SET is_read=1 WHERE thread_id=? AND sender_user_id<>?")
            ->execute([$selectedThreadId,current_user()['id']]);

        // Keep the inbox list's unread badges accurate after marking this thread read.
        foreach($inboxMessages as &$inboxMessage){
            if((int)$inboxMessage['id']===$selectedThreadId)$inboxMessage['unread_count']=0;
        }
        unset($inboxMessage);
    }
}


$pageTitle='Admin';require 'includes/header.php';
?>

<!-- Users is managed through Statistics; remove the standalone Users navigation tab. -->
<style>
    a[href*='tab=users']{display:none !important;}

.match-reporter-contact{font-size:12px;color:#20242a;line-height:1.5}
.match-reporter-contact strong{color:#35404a;font-weight:700}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('nav a').forEach(function (link) {
        const label = (link.textContent || '').replace(/\s+/g, ' ').trim();
        const href = link.getAttribute('href') || '';

        // Rename the existing Overview navigation tab to Dashboard.
        if (label === 'Overview') {
            link.textContent = 'Dashboard';
        }

        // Remove the standalone Audit Logs navigation tab.
        if (label === 'Audit Logs' || /[?&]tab=audit(?:&|$)/.test(href)) {
            link.remove();
            return;
        }

        // Users is managed through Statistics.
        if (label === 'Users') {
            link.remove();
            return;
        }

        // Ownership claims are now reviewed directly from Found reports.
        if (label === 'Claims' || /[?&]tab=claims(?:&|$)/.test(href)) {
            link.remove();
            return;
        }
    });
});
</script>

<?php if($tab==='overview'): ?>

<div class="container command-center">

    <!-- =====================================================
         COMMAND CENTER HERO
    ====================================================== -->

    <section class="command-hero">

        <div class="command-hero-badge">
            🛡 Custodian Administration Console
        </div>

        <h1>Lost &amp; Found Command Center</h1>

        <p>
            Monitor incoming reports, verify automated algorithmic
            matches, adjudicate ownership claims, and coordinate
            scheduled item pickups.
        </p>

    </section>


    <!-- =====================================================
         MULTIPLE CLAIMS ALERT
    ====================================================== -->

    <?php if($multipleClaimCount>0 && $featuredClaimItem): ?>

        <section class="command-alert command-alert-purple">

            <div class="command-alert-icon">👥</div>

            <div class="command-alert-content">

                <div class="command-alert-heading">

                    <strong>
                        MULTIPLE CLAIMS TRIAGE ACTIVE
                    </strong>

                    <span class="priority-badge">
                        PRIORITY ATTENTION
                    </span>

                </div>

                <h3>
                    Item #ITM-<?= str_pad(
                        (string)$featuredClaimItem['item_id'],
                        3,
                        '0',
                        STR_PAD_LEFT
                    ) ?> has received multiple competing claims!
                </h3>

                <p>
                    Review all claimants' distinguishing proofs
                    side-by-side. Approving the rightful owner will
                    automatically reject other competing claims with
                    notifications.
                </p>

            </div>

            <a
                class="command-alert-button purple"
                href="admin.php?tab=reports&amp;report_filter=approved"
            >
                Review Found Reports →
            </a>

        </section>

    <?php endif; ?>


    <!-- =====================================================
         UNREAD INQUIRY ALERT
    ====================================================== -->

    <?php if($adminUnreadTotal>0): ?>

        <section class="command-alert command-alert-red">

            <div class="command-alert-icon">💬</div>

            <div class="command-alert-content">

                <h3>
                    <?= $adminUnreadTotal ?>
                    Unread Student
                    <?= $adminUnreadTotal===1
                        ? 'Inquiry Message'
                        : 'Inquiry Messages'
                    ?>
                </h3>

                <p>
                    Students have sent inquiries regarding lost
                    belongings and claim verification status.
                </p>

            </div>

            <a
                class="command-alert-button red"
                href="admin.php?tab=messages"
            >
                Open Messages Inbox →
            </a>

        </section>

    <?php endif; ?>


    <!-- =====================================================
         KPI CARDS
    ====================================================== -->

    <div class="command-kpi-grid">

        <a class="command-kpi" href="admin.php?tab=reports&amp;report_filter=pending_review">

            <div class="command-kpi-top">
                <span class="command-kpi-label">
                    REPORTS TO REVIEW
                </span>

                <span class="command-kpi-icon amber">
                    ▤
                </span>
            </div>

            <strong><?= $stats['pending_reports'] ?></strong>

            <small class="command-kpi-note amber-text">
                ◷ Awaiting approval
            </small>

        </a>


        <a class="command-kpi" href="admin.php?tab=matches&amp;match_filter=all">

            <div class="command-kpi-top">
                <span class="command-kpi-label">
                    SYSTEM MATCHES
                </span>

                <span class="command-kpi-icon blue">
                    ✨
                </span>
            </div>

            <strong><?= $systemMatchCount ?></strong>

            <small class="command-kpi-note blue-text">
                Algorithmic pairs detected
            </small>

        </a>


        <a class="command-kpi" href="admin.php?tab=reports&amp;report_filter=approved">

            <div class="command-kpi-top">
                <span class="command-kpi-label">
                    CLAIMS
                </span>

                <span class="command-kpi-icon red">
                    ♢
                </span>
            </div>

            <strong><?= $stats['pending_claims'] ?></strong>

            <small class="command-kpi-note red-text">
                Verification required
            </small>

        </a>


        <a class="command-kpi" href="admin.php?tab=messages">

            <div class="command-kpi-top">
                <span class="command-kpi-label">
                    INQUIRIES
                </span>

                <span class="command-kpi-icon purple">
                    ▢
                </span>
            </div>

            <strong><?= $inquiryCount ?></strong>

            <small class="command-kpi-note purple-text">
                <?= $adminUnreadTotal ?>
                unread message<?= $adminUnreadTotal===1?'':'s' ?>
            </small>

        </a>


        <a class="command-kpi" href="admin.php?tab=statistics">

            <div class="command-kpi-top">
                <span class="command-kpi-label">
                    RECOVERY RATE
                </span>

                <span class="command-kpi-icon green">
                    ↗
                </span>
            </div>

            <strong class="recovery-number">
                <?= $recoveryRate ?>%
            </strong>

            <small class="command-kpi-note green-text">
                <?= $stats['returned'] ?>
                item<?= $stats['returned']===1?'':'s' ?> returned
            </small>

        </a>

    </div>


    <!-- =====================================================
         TRIAGE DESKS
    ====================================================== -->

    <div class="command-desk-grid">

        <!-- PENDING REPORTS -->

        <section class="command-panel">

            <div class="command-panel-header">

                <div>

                    <span class="command-panel-icon amber">
                        ▤
                    </span>

                    <h2>
                        Pending Reports Triage

                        <span class="command-count amber-count">
                            <?= $stats['pending_reports'] ?>
                        </span>
                    </h2>

                </div>

                <a href="admin.php?tab=reports&amp;report_filter=pending_review">
                    View Pending Reports →
                </a>

            </div>


            <?php if($pendingReports): ?>

                <div class="command-report-list">

                    <?php foreach(array_slice($pendingReports,0,5) as $r): ?>

                        <div class="command-report-row">

                            <div class="command-report-info">

                                <strong>
                                    <?= h($r['item_name']) ?>
                                </strong>

                                <small>
                                    <?= ucfirst($r['report_type']) ?>
                                    ·
                                    <?= h($r['full_name']) ?>
                                </small>

                            </div>

                            <span class="command-status pending">
                                Pending Review
                            </span>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="command-empty">

                    <span>✓</span>

                    <strong>
                        No reports currently awaiting review.
                    </strong>

                    <small>
                        All clear!
                    </small>

                </div>

            <?php endif; ?>

        </section>


        <!-- PENDING CLAIMS -->

        <section class="command-panel">

            <div class="command-panel-header">

                <div>

                    <span class="command-panel-icon red">
                        ♢
                    </span>

                    <h2>
                        Pending Claims Desk

                        <span class="command-count red-count">
                            <?= $stats['pending_claims'] ?>
                        </span>
                    </h2>

                </div>

                <a href="admin.php?tab=reports&amp;report_filter=approved">
                    Review Found Reports →
                </a>

            </div>


            <?php if($pendingClaims): ?>

                <div class="command-claim-list">

                    <?php foreach(array_slice($pendingClaims,0,4) as $c): ?>

                        <div class="command-claim-card">

                            <div class="command-claim-top">

                                <span class="command-claim-id">
                                    CLM-<?= str_pad(
                                        (string)$c['id'],
                                        3,
                                        '0',
                                        STR_PAD_LEFT
                                    ) ?>
                                </span>

                                <span class="command-claimant">
                                    Claimant:
                                    <?= h($c['full_name']) ?>
                                </span>

                            </div>

                            <strong class="command-claim-item">
                                <?= h($c['item_name']) ?>
                            </strong>

                            <p>
                                <?php
                                $proof=trim(
                                    (string)(
                                        $c['ownership_description'] ?? ''
                                    )
                                );

                                if($proof===''){
                                    $proof='Ownership proof submitted for verification.';
                                }

                                echo h(
                                    mb_strimwidth(
                                        $proof,
                                        0,
                                        105,
                                        '...'
                                    )
                                );
                                ?>
                            </p>

                            <a
                                class="command-adjudicate"
                                href="admin.php?tab=reports&amp;report_filter=approved"
                            >
                                Review Found Report →
                            </a>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="command-empty">

                    <span>✓</span>

                    <strong>
                        No claims currently awaiting review.
                    </strong>

                </div>

            <?php endif; ?>

        </section>

    </div>




</div>


<?php else: ?>

<div class="container admin-head">

    <div>

        <span class="eyebrow">
            Objective 3 &amp; 4
        </span>

        <h1>
            Administration
        </h1>

        <p>
            Verify reports and claims, manage item lifecycle,
            send guidance, and monitor records.
        </p>

    </div>

</div>


<div class="container admin-layout">

<section class="admin-content">

<?php if($error): ?>
    <div class="flash error">
        <?= h($error) ?>
    </div>
<?php endif; ?>


<?php if($tab==='reports'): ?>

<style>
/* =========================================================
   ADMIN REPORTS — CARD / TRIAGE VIEW
========================================================= */
.admin-reports-page{padding-bottom:58px}
.admin-reports-head{display:flex;justify-content:space-between;align-items:flex-start;gap:28px;margin-bottom:20px}
.admin-reports-title h1{margin:0;font-size:30px;line-height:1.12;color:#35404a;letter-spacing:-.035em}
.admin-reports-title p{margin:6px 0 0;color:#20242a;font-size:12px}
.admin-report-tabs{display:flex;align-items:center;gap:2px;background:#fff;border:1px solid #e2e8f0;border-radius:13px;padding:4px;box-shadow:0 3px 12px rgba(25,39,61,.04);white-space:nowrap}
.admin-report-tabs a{padding:9px 14px;border-radius:9px;color:#20242a;font-size:11px;font-weight:800;text-decoration:none}
.admin-report-tabs a:hover{background:#f5f7fb}
.admin-report-tabs a.active{background:#d8f7e9;color:#087a58}
.admin-report-tabs .pending.active{background:#fff3d2;color:#a46700}
.admin-report-tabs .rejected.active{background:#ffe8ed;color:#c51e43}
.admin-report-tabs .all.active{background:#eeeaff;color:#20242a}
.admin-report-count{display:inline-flex;min-width:18px;height:18px;align-items:center;justify-content:center;margin-left:4px;border-radius:99px;background:rgba(255,255,255,.7);font-size:9px}
.admin-report-list{display:grid;gap:16px}
.admin-report-card{position:relative;display:grid;grid-template-columns:96px minmax(0,1fr) auto;gap:16px;padding:20px;background:#fff;border:1px solid #e0e6ee;border-radius:16px;box-shadow:0 4px 15px rgba(28,42,62,.045);transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease}
.admin-report-card:hover{transform:translateY(-1px);box-shadow:0 8px 22px rgba(28,42,62,.07);border-color:#d6deea}
.admin-report-photo{width:96px;height:96px;border-radius:11px;overflow:hidden;background:#f1f3f5;display:grid;place-items:center;border:1px solid #e4e8ed}
.admin-report-photo img{width:100%;height:100%;object-fit:cover;display:block}
.admin-report-photo-placeholder{font-size:28px;color:#a5afbc}
.admin-report-main{min-width:0}
.admin-report-topline{display:flex;align-items:center;flex-wrap:wrap;gap:7px;margin-bottom:7px}
.admin-report-type{display:inline-flex;align-items:center;padding:4px 8px;border-radius:5px;font-size:9px;font-weight:900;letter-spacing:.03em;text-transform:uppercase}
.admin-report-type.lost{background:#fff0c5;color:#aa6900}
.admin-report-type.found{background:#e8eaff;color:#20242a}
.admin-report-id{color:#20242a;font-size:10px;font-weight:800;letter-spacing:.03em}
.admin-report-category{color:#20242a;font-size:10px}
.admin-report-main h2{margin:0;color:#35404a;font-size:16px;line-height:1.3;letter-spacing:-.01em}
.admin-report-description{margin:5px 0 11px;color:#20242a;font-size:11px;line-height:1.5}
.admin-report-meta{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,.8fr);gap:6px 22px;margin-bottom:10px}
.admin-report-meta span{display:flex;align-items:flex-start;gap:6px;color:#20242a;font-size:10px;line-height:1.45}
.admin-report-meta b{color:#9aa8ba;font-weight:700;flex:0 0 auto}
.admin-report-identifiers{padding:9px 11px;border:1px solid #e4e9ef;border-radius:9px;background:#f8fafc;color:#20242a;font-size:10px;line-height:1.5}
.admin-report-identifiers strong{color:#35404a}
.admin-report-status{align-self:start;display:inline-flex;align-items:center;gap:5px;padding:6px 11px;border-radius:999px;border:1px solid;font-size:10px;font-weight:900;white-space:nowrap}
.admin-report-status.approved{background:#dff9ed;border-color:#63dfb0;color:#087a58}
.admin-report-status.pending_review{background:#fff4d7;border-color:#f0ca67;color:#a46600}
.admin-report-status.rejected{background:#ffe9ee;border-color:#f3a0b2;color:#c31f42}
.admin-report-status::before{content:'✓';font-weight:900}
.admin-report-status.pending_review::before{content:'◷'}
.admin-report-status.rejected::before{content:'×'}
.admin-report-actions{grid-column:2 / -1;display:flex;align-items:center;justify-content:flex-end;gap:7px;padding-top:4px;border-top:1px solid #edf0f4}
.admin-report-actions .btn{min-width:88px}.admin-report-delete{background:#fff0f2!important;color:#b4233c!important;border:1px solid #f1c0c9!important}
.admin-report-rejection{margin-top:8px;padding:8px 10px;border-radius:8px;background:#fff5f6;color:#a93b50;font-size:10px}
.admin-report-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px 18px;margin:10px 0}
.admin-report-fact{color:#20242a;font-size:10px;line-height:1.45}
.admin-report-fact strong{color:#35404a}
.admin-report-workflow{margin-top:11px;padding:12px 13px;border:1px solid #e8c1c8;border-left:4px solid #9b1c2c;border-radius:11px;background:#fff5f6}
.admin-report-workflow-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:7px}
.admin-report-workflow-title{font-size:11px;font-weight:900;color:#9b1c2c}
.admin-report-workflow-state{font-size:9px;font-weight:900;padding:4px 8px;border-radius:999px;background:#eeeaff;color:#20242a}
.admin-report-workflow-state.success{background:#dff9ed;color:#087a58}
.admin-report-workflow-state.waiting{background:#fff4d7;color:#a46600}
.admin-report-workflow-state.complete{background:#dff9ed;color:#087a58}
.admin-report-schedule{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px 18px;margin-top:8px}
.admin-report-schedule span,.admin-report-completion span{display:block;color:#20242a;font-size:10px;line-height:1.45}
.admin-report-schedule strong,.admin-report-completion strong{color:#35404a}
.admin-report-short-message{margin-top:8px;padding:8px 10px;border-radius:8px;background:#eef7ff;color:#49647e;font-size:10px;line-height:1.45}
.admin-report-completion{margin-top:8px;padding:9px 10px;border:1px solid #bcefdc;border-radius:9px;background:#effcf5}
.admin-report-completion span{color:#39705a}
.admin-report-action-form{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.admin-report-action-form .btn{min-width:118px}
.admin-report-mini-form{display:grid;grid-template-columns:1fr 1fr;gap:8px;width:100%}
.admin-report-mini-form label{display:block;color:#20242a;font-size:9px;font-weight:800}
.admin-report-mini-form input,.admin-report-mini-form textarea{width:100%;margin-top:4px;border:1px solid #d9e1ea;border-radius:8px;padding:8px 9px;font:inherit;font-size:10px;background:#fff}
.admin-report-mini-form textarea{min-height:55px;resize:vertical;grid-column:1 / -1}
.admin-report-mini-form .wide{grid-column:1 / -1}
.admin-report-actions .full-width{width:100%}
.admin-report-claims{margin-top:11px;padding:12px 13px;border:1px solid #e2e7ef;border-radius:11px;background:#fbfcfe}
.admin-report-claims-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:9px}
.admin-report-claims-title{font-size:11px;font-weight:900;color:#35404a}
.admin-report-claims-count{font-size:9px;font-weight:900;padding:4px 8px;border-radius:999px;background:#eeeaff;color:#20242a}
.admin-report-claim{padding:11px;border:1px solid #e3e8ef;border-radius:9px;background:#fff}
.admin-report-claim+.admin-report-claim{margin-top:8px}
.admin-report-claim-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.admin-report-claim-person strong{display:block;color:#35404a;font-size:10px}
.admin-report-claim-person span{display:block;margin-top:2px;color:#8290a2;font-size:9px}
.admin-report-claim-status{flex:0 0 auto;padding:4px 8px;border-radius:999px;font-size:8px;font-weight:900;background:#fff4d7;color:#a46600}
.admin-report-claim-status.approved{background:#dff9ed;color:#087a58}
.admin-report-claim-status.rejected{background:#ffe9ee;color:#c31f42}
.admin-report-claim-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px 14px;margin-top:9px}
.admin-report-claim-detail{min-width:0}
.admin-report-claim-detail small{display:block;margin-bottom:2px;color:#8a96a6;font-size:7.5px;font-weight:900;text-transform:uppercase;letter-spacing:.25px}
.admin-report-claim-detail span{display:block;color:#20242a;font-size:9px;line-height:1.5;white-space:pre-wrap;overflow-wrap:anywhere}
.admin-report-claim-proof{margin-top:8px}
.admin-report-claim-proof-label{display:block;margin-bottom:5px;color:#20242a;font-size:8px;font-weight:900}
.admin-report-claim-proof img{display:block;width:120px;height:88px;object-fit:cover;border:1px solid #dfe5ed;border-radius:8px;background:#f4f6f8}
.admin-report-claim-actions{display:flex;align-items:flex-start;gap:7px;flex-wrap:wrap;margin-top:10px}
.admin-report-claim-actions details{flex:1 1 260px}
.admin-report-claim-actions details summary{cursor:pointer}
.admin-report-claim-actions .mini-form{margin-top:7px}
.admin-report-claim-schedule{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:9px}
.admin-report-claim-schedule label{display:block;color:#20242a;font-size:9px;font-weight:800}
.admin-report-claim-schedule input{width:100%;box-sizing:border-box;margin-top:4px;border:1px solid #d9e1ea;border-radius:8px;padding:8px 9px;font:inherit;font-size:10px;background:#fff}
.admin-report-claim-schedule .wide{grid-column:1 / -1}
.admin-report-claim-release{margin-top:9px;padding:9px 10px;border:1px solid #bcefdc;border-radius:8px;background:#effcf5;color:#39705a;font-size:9px;line-height:1.5}
.admin-report-claim-release strong{color:#145a46}
.admin-report-claim-handover{margin-top:8px}
.admin-report-claim-handover textarea{width:100%;box-sizing:border-box;min-height:52px;margin-top:6px;border:1px solid #d9e1ea;border-radius:8px;padding:8px 9px;font:inherit;font-size:9px;resize:vertical}
.admin-report-claim-empty{padding:8px 10px;border-radius:8px;background:#eef7ff;color:#49647e;font-size:9px;line-height:1.45}
@media(max-width:650px){
    .admin-report-claim-details,.admin-report-claim-schedule{grid-template-columns:1fr}
    .admin-report-claim-schedule .wide{grid-column:auto}
    .admin-report-claim-top{flex-direction:column}
}


.admin-match-case{margin:0 0 16px;background:#fff;border:1px solid #d9dfe7;border-radius:16px;box-shadow:0 4px 15px rgba(28,42,62,.045);overflow:hidden}
.admin-match-case-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:15px 18px;background:#f8e9ec;border-bottom:1px solid #efd2d8}
.admin-match-case-title{display:flex;align-items:center;gap:9px;color:#9b1c2c;font-size:13px;font-weight:900}
.admin-match-case-title .case-icon{display:grid;place-items:center;width:28px;height:28px;border-radius:8px;background:#9b1c2c;color:#fff;font-size:13px}
.admin-match-case-status{padding:6px 10px;border-radius:999px;background:#e8f5ed;border:1px solid #bcefdc;color:#087a58;font-size:9px;font-weight:900;white-space:nowrap}
.admin-match-case-subtitle{padding:9px 18px;background:#fff7f8;color:#66707a;font-size:9px;line-height:1.45}
.admin-match-case-reports{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:14px 18px 4px}
.admin-match-report{border:1px solid #e1e6ed;border-radius:12px;padding:12px;background:#fafbfc}
.admin-match-report.lost{border-top:3px solid #d4a72c}
.admin-match-report.found{border-top:3px solid #9b1c2c}
.admin-match-report-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}
.admin-match-report-label{font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.04em}
.admin-match-report.lost .admin-match-report-label{color:#9a6500}
.admin-match-report.found .admin-match-report-label{color:#9b1c2c}
.admin-match-report-id{font-size:8px;color:#7b8796;font-weight:700}
.admin-match-report-body{display:grid;grid-template-columns:58px 1fr;gap:10px}
.admin-match-report-photo{width:58px;height:58px;border-radius:9px;overflow:hidden;background:#eef1f5;border:1px solid #e1e6ed;display:grid;place-items:center;color:#9aa8ba;font-size:17px}
.admin-match-report-photo img{width:100%;height:100%;object-fit:cover}
.admin-match-report-body strong{display:block;color:#20242a;font-size:11px;margin-bottom:3px}
.admin-match-report-body p{margin:0;color:#66707a;font-size:8.5px;line-height:1.45}
.admin-match-report-meta{margin-top:8px;padding-top:7px;border-top:1px solid #edf0f4;color:#61728a;font-size:8.5px;line-height:1.55}
.admin-match-report-meta b{color:#35404a}
.admin-match-case-next{margin:10px 18px 14px;padding:12px;border:1px solid #efcbd2;border-left:4px solid #9b1c2c;border-radius:10px;background:#fff5f6}
.admin-match-case-next-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:7px}
.admin-match-case-next-title{color:#9b1c2c;font-size:11px;font-weight:900}
.admin-match-case-next-message{padding:8px 10px;border-radius:8px;background:#eef7ff;color:#49647e;font-size:9px;line-height:1.5}
.admin-match-case-schedule{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 16px;margin:8px 0;color:#61728a;font-size:9px}
.admin-match-case-schedule strong{color:#35404a}
.admin-match-case .admin-report-mini-form{margin-top:9px}
.admin-match-case-claims{margin:0 18px 16px;border-top:1px solid #edf0f4;padding-top:12px}
.admin-match-case-claims-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.admin-match-case-claims-title{font-size:10px;font-weight:900;color:#35404a}
.admin-match-case-claims-count{padding:4px 8px;border-radius:999px;background:#f0edff;color:#5141cf;font-size:8px;font-weight:900}
.admin-match-case-claim{border:1px solid #e1e6ed;border-radius:9px;padding:9px 10px;margin-top:7px;background:#fff}
.admin-match-case-claim-top{display:flex;justify-content:space-between;gap:10px;align-items:start}
.admin-match-case-claim strong{font-size:9px;color:#26344a}
.admin-match-case-claim small{display:block;color:#7b8796;font-size:7.5px;margin-top:2px}
.admin-match-case-claim-status{font-size:7.5px;font-weight:900;padding:4px 7px;border-radius:999px;background:#fff4d7;color:#a46600;white-space:nowrap}
.admin-match-case-claim-status.approved{background:#e8f5ed;color:#087a58}
.admin-match-case-claim-status.rejected{background:#ffe9ee;color:#c31f42}
.admin-match-case-claim-details{display:grid;grid-template-columns:1fr 1fr;gap:6px 14px;margin-top:8px}
.admin-match-case-claim-details div{font-size:8px;color:#61728a;line-height:1.45}
.admin-match-case-claim-details b{color:#35404a}
@media(max-width:800px){.admin-match-case-reports,.admin-match-case-claim-details{grid-template-columns:1fr}.admin-match-case-schedule{grid-template-columns:1fr}}

.admin-report-guide{margin:0 0 16px;padding:14px 16px;background:#fff;border:1px solid #e0e6ee;border-radius:14px;box-shadow:0 3px 12px rgba(25,39,61,.035)}
.admin-report-guide-title{font-size:11px;font-weight:900;color:#35404a;text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px}
.admin-report-guide-steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.admin-report-guide-steps>div{display:grid;grid-template-columns:25px 1fr;column-gap:8px;align-items:start;padding:9px 10px;border:1px solid #edf0f4;border-radius:10px;background:#fafbfc}
.admin-report-guide-steps span{grid-row:span 2;display:grid;place-items:center;width:25px;height:25px;border-radius:50%;background:#f8e9ec;color:#9b1c2c;font-size:10px;font-weight:900}
.admin-report-guide-steps strong{color:#35404a;font-size:10px;line-height:1.3}
.admin-report-guide-steps small{color:#66707a;font-size:8.5px;line-height:1.45;margin-top:2px}
@media(max-width:800px){.admin-report-guide-steps{grid-template-columns:1fr}.admin-report-guide-steps>div{grid-template-columns:25px 1fr}}

.admin-reports-empty{background:#fff;border:1px solid #e0e6ee;border-radius:16px;padding:55px 25px;text-align:center;color:#20242a;box-shadow:0 4px 15px rgba(28,42,62,.04)}
.admin-reports-empty strong{display:block;color:#35404a;font-size:13px;margin-bottom:4px}
.admin-reports-empty span{font-size:11px}
@media(max-width:850px){
 .admin-reports-head{flex-direction:column}
 .admin-report-tabs{width:100%;overflow-x:auto}
 .admin-report-tabs a{flex:1;text-align:center}
 .admin-report-card{grid-template-columns:76px minmax(0,1fr)}
 .admin-report-photo{width:76px;height:76px}
 .admin-report-status{grid-column:2;justify-self:start}
 .admin-report-meta{grid-template-columns:1fr}
 .admin-report-actions{grid-column:1 / -1;justify-content:flex-start}
}
@media(max-width:560px){
 .admin-report-card{grid-template-columns:1fr}
 .admin-report-photo{width:88px;height:88px}
 .admin-report-status{grid-column:auto}
 .admin-report-actions{grid-column:auto;flex-wrap:wrap}
}
</style>

<div class="container admin-reports-page">

    <div class="admin-reports-head">

        <div class="admin-reports-title">
            <h1>Manage Reports</h1>
            <p>Reports are automatically added to the Find IT system. Use this page to review submitted records and monitor their progress; potential Lost ↔ Found matches are reviewed separately.</p>
        </div>

        <nav class="admin-report-tabs" aria-label="Report filters">
            <a class="<?= $reportFilter==='approved'?'active':'' ?>" href="admin.php?tab=reports&amp;report_filter=approved">
                Recorded / Active <span class="admin-report-count"><?= $reportCounts['approved'] ?></span>
            </a>
            <a class="rejected <?= $reportFilter==='rejected'?'active':'' ?>" href="admin.php?tab=reports&amp;report_filter=rejected">
                Rejected <span class="admin-report-count"><?= $reportCounts['rejected'] ?></span>
            </a>
            <a class="all <?= $reportFilter==='all'?'active':'' ?>" href="admin.php?tab=reports&amp;report_filter=all">
                All <span class="admin-report-count"><?= $reportCounts['all'] ?></span>
            </a>
        </nav>

    </div>

    <div class="admin-report-guide">
        <div class="admin-report-guide-title">How Find IT processes a report</div>
        <div class="admin-report-guide-steps">
            <div><span>1</span><strong>Report is recorded</strong><small>Lost and Found reports are automatically added to the system after submission.</small></div>
            <div><span>2</span><strong>System checks for matches</strong><small>The system compares eligible Lost and Found records and generates a potential match with a match percentage.</small></div>
            <div><span>3</span><strong>Admin reviews the match</strong><small>Potential matches are reviewed in Manage Potential Matches before any turnover or owner verification schedule is released.</small></div>
        </div>
    </div>

    <?php if($visibleReports): ?>

        <div class="admin-report-list">

            <?php $renderedCombinedCases=[]; ?>
            <?php foreach($visibleReports as $r): ?>

                <?php
                $currentReportId=(int)$r['id'];
                if(isset($combinedReportIds[$currentReportId])){
                    $caseId=$combinedReportIds[$currentReportId];
                    if(!isset($renderedCombinedCases[$caseId])){
                        $renderedCombinedCases[$caseId]=true;
                        $case=$combinedMatchCases[$caseId];
                        $match=$case['match'];
                        $lost=$case['lost'];
                        $found=$case['found'];
                        $caseScheduleReady=!empty($match['found_handover_at']) && !empty($match['verification_date']) && !empty($match['verification_time']) && !empty($match['verification_deadline']) && !empty($match['verification_location']);
                        $caseCompleted=!empty($match['handover_at']);
                        $caseOutcome=(string)($match['verification_outcome']??'');
                        $foundClaims=[];
                        foreach($claims as $caseClaim){
                            if((int)($caseClaim['found_report_id']??0)===(int)$found['id']){
                                $foundClaims[]=$caseClaim;
                            }
                        }
                        $casePendingClaims=array_values(array_filter($foundClaims,static fn($c)=>(($c['claim_status']??'')==='pending')));
                        $caseApprovedClaim=null;
                        foreach($foundClaims as $caseClaim){
                            if(($caseClaim['claim_status']??'')==='approved'){$caseApprovedClaim=$caseClaim;break;}
                        }
                        $caseClaimCompleted=$caseApprovedClaim && !empty($caseApprovedClaim['handover_at']);
                        $casePhoto=function($report){
                            $photo=trim((string)($report['photo_path']??''));
                            if($photo===''){
                                $slug=strtolower(trim((string)$report['item_name']));
                                $slug=preg_replace('/[^a-z0-9]+/i','-',$slug);
                                $slug=trim($slug,'-');
                                $dir=__DIR__.'/uploads/Items/'; $url='uploads/Items/';
                                if(!is_dir($dir)){$dir=__DIR__.'/uploads/items/';$url='uploads/items/';}
                                foreach(['jpg','jpeg','png','webp'] as $ext){
                                    if(is_file($dir.$slug.'.'.$ext)){ $photo=$url.$slug.'.'.$ext; break; }
                                }
                            }
                            return $photo;
                        };
                        $lostPhoto=$casePhoto($lost); $foundPhoto=$casePhoto($found);
                        ?>
                        <section class="admin-match-case">
                            <div class="admin-match-case-head">
                                <div class="admin-match-case-title"><span class="case-icon">↔</span> Approved Match Case #<?= str_pad((string)$match['id'],3,'0',STR_PAD_LEFT) ?></div>
                                <span class="admin-match-case-status">✓ Match Approved</span>
                            </div>
                            <div class="admin-match-case-subtitle">These Lost and Found reports are one case because the administrator approved their potential match. The Found Reporter turns over the item first; only after receipt will the lost-item owner receive a verification schedule.</div>

                            <div class="admin-match-case-reports">
                                <article class="admin-match-report lost">
                                    <div class="admin-match-report-head"><span class="admin-match-report-label">Lost Report</span><span class="admin-match-report-id">RPT-<?= date('Y',strtotime($lost['created_at'])) ?>-<?= str_pad((string)$lost['id'],3,'0',STR_PAD_LEFT) ?></span></div>
                                    <div class="admin-match-report-body">
                                        <div class="admin-match-report-photo"><?php if($lostPhoto): ?><img src="<?= h($lostPhoto) ?>" alt="<?= h($lost['item_name']) ?>"><?php else: ?>▧<?php endif; ?></div>
                                        <div><strong><?= h($lost['item_name']) ?></strong><p>Reported by: <?= h($lost['full_name']) ?><br><?= h($lost['email']??'') ?></p></div>
                                    </div>
                                    <div class="admin-match-report-meta"><b>Category:</b> <?= h($lost['category']?:'Not specified') ?><br><b>Color:</b> <?= h($lost['color']?:'Not specified') ?><br><b>Occurred:</b> <?= h($lost['event_date']?:'Not specified') ?><?= !empty($lost['event_time'])?' at '.h($lost['event_time']):'' ?><br><b>Identifiers:</b> <?= h($lost['identifying_features']?:'None provided') ?></div>
                                </article>

                                <article class="admin-match-report found">
                                    <div class="admin-match-report-head"><span class="admin-match-report-label">Found Report</span><span class="admin-match-report-id">RPT-<?= date('Y',strtotime($found['created_at'])) ?>-<?= str_pad((string)$found['id'],3,'0',STR_PAD_LEFT) ?></span></div>
                                    <div class="admin-match-report-body">
                                        <div class="admin-match-report-photo"><?php if($foundPhoto): ?><img src="<?= h($foundPhoto) ?>" alt="<?= h($found['item_name']) ?>"><?php else: ?>▧<?php endif; ?></div>
                                        <div><strong><?= h($found['item_name']) ?></strong><p>Found by: <?= h($found['full_name']) ?><br><?= h($found['email']??'') ?></p></div>
                                    </div>
                                    <div class="admin-match-report-meta"><b>Category:</b> <?= h($found['category']?:'Not specified') ?><br><b>Color:</b> <?= h($found['color']?:'Not specified') ?><br><b>Occurred:</b> <?= h($found['event_date']?:'Not specified') ?><?= !empty($found['event_time'])?' at '.h($found['event_time']):'' ?><br><b>Identifiers:</b> <?= h($found['identifying_features']?:'None provided') ?></div>
                                </article>
                            </div>

                            <div class="admin-match-case-next">
                                <div class="admin-match-case-next-head"><span class="admin-match-case-next-title">Case Progress</span>
                                    <?php if($caseCompleted): ?><span class="admin-report-workflow-state complete">Completed — Item Returned</span>
                                    <?php elseif($caseOutcome==='reschedule'): ?><span class="admin-report-workflow-state waiting">Owner Verification Needs Rescheduling</span>
                                    <?php elseif($caseScheduleReady): ?><span class="admin-report-workflow-state success">Owner Verification Scheduled</span>
                                    <?php elseif(!empty($match['found_handover_at'])): ?><span class="admin-report-workflow-state success">Found Item Received — Schedule Owner</span>
                                    <?php elseif(!empty($match['found_turnover_deadline'])): ?><span class="admin-report-workflow-state waiting">Waiting for Found Item Turnover</span>
                                    <?php else: ?><span class="admin-report-workflow-state waiting">Set Found Turnover Deadline</span><?php endif; ?>
                                </div>

                                <?php if($caseCompleted): ?>
                                    <div class="admin-match-case-next-message"><strong>✓ Case completed.</strong> The item was verified in person and returned to the lost-item owner.</div>
                                    <div class="admin-match-case-schedule"><span>Completed: <strong><?= h(date('F j, Y g:i A',strtotime($match['handover_at']))) ?></strong></span><span>Location: <strong><?= h($match['verification_location']?:'—') ?></strong></span></div>

                                <?php elseif($caseOutcome==='reschedule'): ?>
                                    <div class="admin-match-case-next-message"><strong>Owner verification could not be completed.</strong> The Found Reporter has already turned the item over. Release a new schedule for the lost-item owner.</div>
                                    <?php if(!empty($match['verification_outcome_reason'])): ?><div class="admin-match-case-next-message">Reason: <?= h($match['verification_outcome_reason']) ?></div><?php endif; ?>
                                    <form method="post" class="admin-report-mini-form" onsubmit="return confirm('Release a new owner verification schedule?');">
                                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="match_schedule"><input type="hidden" name="match_id" value="<?= (int)$match['id'] ?>">
                                        <label>Lost owner release / verification date<input type="date" name="verification_date" required></label><label>Lost owner release / verification time<input type="time" name="verification_time" required></label><label>Verification deadline<input type="datetime-local" name="verification_deadline" required></label><label>Location<input type="text" name="verification_location" value="<?= h($match['verification_location']?:'Office of Mapúa, 1st Floor') ?>" required></label><label class="wide">Schedule note (optional)<textarea name="remarks" placeholder="Short note for the owner's verification appointment"></textarea></label><div class="wide"><button class="btn small success" type="submit">Schedule Owner Verification</button></div>
                                    </form>

                                <?php elseif($caseScheduleReady): ?>
                                    <div class="admin-match-case-next-message"><strong>Found item already received.</strong> The Found Reporter has completed the physical turnover. The schedule below is only for the lost-item owner’s in-person verification.</div>
                                    <div class="admin-match-case-schedule"><span>Owner visit date: <strong><?= h(date('F j, Y',strtotime($match['verification_date']))) ?></strong></span><span>Owner visit time: <strong><?= h(date('g:i A',strtotime($match['verification_date'].' '.$match['verification_time']))) ?></strong></span><span>Deadline: <strong><?= h(date('F j, Y g:i A',strtotime($match['verification_deadline']))) ?></strong></span><span>Location: <strong><?= h($match['verification_location']) ?></strong></span></div>
                                    <div class="admin-match-case-next-message">After the appointment, record the final in-person verification result in the <strong>Matches</strong> tab. <strong>The Found Reporter does not need to attend.</strong></div>

                                <?php elseif(!empty($match['found_handover_at'])): ?>
                                    <div class="admin-match-case-next-message"><strong>✓ Found item received by the administrator.</strong> Turnover was recorded on <?= h(date('F j, Y g:i A',strtotime($match['found_handover_at']))) ?>. You can now schedule the lost-item owner. The visit date and time must be on or after the actual turnover date and time.</div>
                                    <form method="post" class="admin-report-mini-form" onsubmit="return confirm('Release the owner verification schedule?');">
                                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="match_schedule"><input type="hidden" name="match_id" value="<?= (int)$match['id'] ?>">
                                        <label>Lost owner release / verification date<input type="date" name="verification_date" min="<?= h(date('Y-m-d',strtotime($match['found_handover_at']))) ?>" required></label><label>Lost owner release / verification time<input type="time" name="verification_time" required></label><label>Verification deadline<input type="datetime-local" name="verification_deadline" required></label><label>Location<input type="text" name="verification_location" value="Office of Mapúa, 1st Floor" required></label><label class="wide">Schedule note (optional)<textarea name="remarks" placeholder="Short note for the owner's verification appointment"></textarea></label><div class="wide"><button class="btn small success" type="submit">Schedule Owner Verification</button></div>
                                    </form>

                                <?php elseif(!empty($match['found_turnover_deadline'])): ?>
                                    <div class="admin-match-case-next-message"><strong>Waiting for the Found Reporter.</strong> The physical item may be turned over to the administrator from <strong><?= h(date('F j, Y',strtotime($match['found_turnover_start_date']))) ?></strong> through <strong><?= h(date('F j, Y',strtotime($match['found_turnover_deadline']))) ?></strong>. The lost-item owner will be scheduled only after the item is physically received.</div>
                                    <div class="admin-match-case-schedule"><span>Found item may be turned over from: <strong><?= h(date('F j, Y',strtotime($match['found_turnover_start_date']))) ?></strong></span><span>Turnover deadline: <strong><?= h(date('F j, Y',strtotime($match['found_turnover_deadline']))) ?></strong></span><span>Turnover location: <strong><?= h($match['found_turnover_location']?:'—') ?></strong></span><span>Lost owner schedule: <strong>Not yet scheduled</strong></span></div>
                                    <form method="post" class="admin-report-mini-form" onsubmit="return confirm('Record that the Found Reporter has physically turned the item over to the administrator?');">
                                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="found_match_handover"><input type="hidden" name="match_id" value="<?= (int)$match['id'] ?>">
                                        <label class="wide">Turnover notes (optional)<textarea name="found_handover_notes" placeholder="Record how/when the item was received or any custody notes"></textarea></label><div class="wide"><button class="btn small success" type="submit">✓ Confirm Found Item Received</button></div>
                                    </form>

                                <?php else: ?>
                                    <div class="admin-match-case-next-message"><strong>Step 1 — Set when and where the Found Reporter can turn over the item.</strong> The finder must physically turn the item over to the administrator within this date range and at the stated location. Only after the item is received will the lost-item owner receive a release/verification schedule.</div>
                                    <form method="post" class="admin-report-mini-form" onsubmit="return confirm('Notify the Found Reporter about the turnover deadline?');">
                                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="match_turnover_schedule"><input type="hidden" name="match_id" value="<?= (int)$match['id'] ?>">
                                        <label>Allowed start date<input type="date" name="found_turnover_start_date" value="<?= h(date('Y-m-d')) ?>" min="<?= h(date('Y-m-d')) ?>" required></label><label>Turnover deadline<input type="date" name="found_turnover_deadline" value="<?= h(date('Y-m-d',strtotime('+1 day'))) ?>" min="<?= h(date('Y-m-d')) ?>" required></label><label>Where to bring the item<input type="text" name="found_turnover_location" value="Office of Mapúa, 1st Floor" required></label><label class="wide">Instruction / note (optional)<textarea name="remarks" placeholder="Example: Please bring the item to the Lost &amp; Found Office between the start date and deadline."></textarea></label><div class="wide"><button class="btn small success" type="submit">Set Turnover Schedule &amp; Notify Found Reporter</button></div>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <?php if($foundClaims): ?>
                            <div class="admin-match-case-claims">
                                <div class="admin-match-case-claims-head"><span class="admin-match-case-claims-title">Ownership Claims for Found Item</span><span class="admin-match-case-claims-count"><?= count($foundClaims) ?> claim<?= count($foundClaims)===1?'':'s' ?></span></div>
                                <?php foreach($foundClaims as $caseClaim): $cs=(string)($caseClaim['claim_status']??'pending'); ?>
                                    <article class="admin-match-case-claim">
                                        <div class="admin-match-case-claim-top"><div><strong>CLM-<?= str_pad((string)$caseClaim['id'],3,'0',STR_PAD_LEFT) ?> · <?= h($caseClaim['full_name']??'Unknown claimant') ?></strong><small><?= h($caseClaim['university_id']??'No university ID') ?><?= !empty($caseClaim['email'])?' · '.h($caseClaim['email']):'' ?></small></div><span class="admin-match-case-claim-status <?= $cs==='approved'?'approved':($cs==='rejected'?'rejected':'') ?>"><?= $cs==='pending'?'Pending Admin Review':($cs==='approved'?'Approved':'Rejected') ?></span></div>
                                        <div class="admin-match-case-claim-details"><div><b>Ownership:</b> <?= h($caseClaim['ownership_description']??'Not provided') ?></div><div><b>Identifying info:</b> <?= h($caseClaim['identifying_information']??'Not provided') ?></div><div><b>Evidence:</b> <?= h($caseClaim['supporting_evidence']??'Not provided') ?></div><div><b>Submitted:</b> <?= !empty($caseClaim['created_at'])?h(date('F j, Y g:i A',strtotime($caseClaim['created_at']))):'—' ?></div></div>
                                        <?php if($cs==='pending'): ?>
                                            <div class="admin-report-claim-actions" style="margin-top:8px">
                                                <details>
                                                    <summary class="btn small danger">Reject Claim</summary>
                                                    <form method="post" class="mini-form">
                                                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="claim_decision"><input type="hidden" name="claim_id" value="<?= (int)$caseClaim['id'] ?>"><input type="hidden" name="decision" value="reject">
                                                        <textarea name="reason" required placeholder="Reason required"></textarea><button class="btn small danger" type="submit">Confirm Reject</button>
                                                    </form>
                                                </details>
                                                <details>
                                                    <summary class="btn small success">✓ Approve &amp; Set Release</summary>
                                                    <form method="post" class="admin-report-claim-schedule">
                                                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="claim_decision"><input type="hidden" name="claim_id" value="<?= (int)$caseClaim['id'] ?>"><input type="hidden" name="decision" value="approve">
                                                        <label>Visit date<input type="date" name="verification_date" value="<?= date('Y-m-d') ?>" required></label><label>Visit time<input type="time" name="verification_time" value="10:00" required></label><label>Verification deadline<input type="date" name="verification_deadline" value="<?= date('Y-m-d',strtotime('+7 days')) ?>" required></label><label>Pickup / verification location<input type="text" name="verification_location" value="Office of Mapúa, 1st Floor" required></label><div class="wide"><button class="btn small success" type="submit">Confirm Approval &amp; Schedule</button></div>
                                                    </form>
                                                </details>
                                            </div>
                                        <?php elseif($cs==='approved'): ?>
                                            <div class="admin-report-claim-release" style="margin-top:8px">
                                                <strong>Release verification scheduled.</strong><br>
                                                Visit: <?= !empty($caseClaim['verification_date'])?h(date('F j, Y',strtotime($caseClaim['verification_date']))):'Date not set' ?><?php if(!empty($caseClaim['verification_time'])): ?> at <?= h(date('g:i A',strtotime($caseClaim['verification_time']))) ?><?php endif; ?>
                                                <?php if(!empty($caseClaim['verification_location'])): ?> at <?= h($caseClaim['verification_location']) ?><?php endif; ?>
                                                <?php if(empty($caseClaim['handover_at'])): ?>
                                                    <form method="post" class="admin-report-claim-handover" onsubmit="return confirm('Confirm physical handover and mark this claimed item as Returned?');">
                                                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="handover_item"><input type="hidden" name="claim_id" value="<?= (int)$caseClaim['id'] ?>">
                                                        <textarea name="handover_notes" placeholder="Handover confirmation notes / officer signature"></textarea><button class="btn small success" type="submit">✓ Confirm Physical Handover — Mark Returned</button>
                                                    </form>
                                                <?php else: ?>
                                                    <br><strong>✓ Claim completed and item returned.</strong>
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif($cs==='rejected'): ?>
                                            <div class="admin-report-claim-release" style="margin-top:8px;background:#fff5f6;border-color:#f3d0d7;color:#8f3d50"><strong>Rejection reason:</strong> <?= h($caseClaim['rejection_reason'] ?: 'No reason recorded.') ?></div>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </section>
                        <?php
                    }
                    continue;
                }

                ?>

                <?php
                $reportStatus=(string)($r['report_status']??'');
                $type=(string)($r['report_type']??'lost');
                $photo=trim((string)($r['photo_path']??''));

                if($photo===''){
                    $imageSlug=strtolower(trim((string)$r['item_name']));
                    $imageSlug=preg_replace('/[^a-z0-9]+/i','-',$imageSlug);
                    $imageSlug=trim($imageSlug,'-');
                    $customDir=__DIR__.'/uploads/Items/';
                    $customUrl='uploads/Items/';
                    if(!is_dir($customDir)){
                        $customDir=__DIR__.'/uploads/items/';
                        $customUrl='uploads/items/';
                    }
                    foreach(['jpg','jpeg','png','webp'] as $extension){
                        $candidate=$customDir.$imageSlug.'.'.$extension;
                        if(is_file($candidate)){
                            $photo=$customUrl.$imageSlug.'.'.$extension;
                            break;
                        }
                    }
                }

                $description=trim((string)($r['description']??''));
                if($description===''){
                    $description=trim((string)($r['additional_notes']??''));
                }
                if($description===''){
                    $description='No additional description was provided for this report.';
                }

                $identifiers=trim((string)($r['identifying_features']??''));
                if($identifiers===''){
                    $identifiers='No distinguishing identifiers were provided.';
                }
                ?>

                <article class="admin-report-card">

                    <div class="admin-report-photo">
                        <?php if($photo): ?>
                            <img src="<?= h($photo) ?>" alt="<?= h($r['item_name']) ?>">
                        <?php else: ?>
                            <div class="admin-report-photo-placeholder">▧</div>
                        <?php endif; ?>
                    </div>

                    <div class="admin-report-main">

                        <div class="admin-report-topline">
                            <span class="admin-report-type <?= $type==='found'?'found':'lost' ?>">
                                <?= $type==='found'?'Found Submission':'Lost Submission' ?>
                            </span>
                            <span class="admin-report-id">
                                RPT-<?= date('Y',strtotime($r['created_at'])) ?>-<?= str_pad((string)$r['id'],3,'0',STR_PAD_LEFT) ?>
                            </span>
                            <span class="admin-report-category">
                                • <?= h($r['category']) ?>
                            </span>
                        </div>

                        <h2><?= h($r['item_name']) ?></h2>

                        <?php
                        $linkedMatch=$reportMatchByReportId[(int)$r['id']]??null;
                        $scheduleReady=$linkedMatch
                            && !empty($linkedMatch['found_handover_at'])
                            && !empty($linkedMatch['verification_date'])
                            && !empty($linkedMatch['verification_time'])
                            && !empty($linkedMatch['verification_deadline'])
                            && !empty($linkedMatch['verification_location']);
                                                $handoverCompleted=$linkedMatch && !empty($linkedMatch['handover_at']);

                        // Direct ownership claims for this specific FOUND report.
                        $reportClaims=[];
                        if($type==='found'){
                            foreach($claims as $reportClaim){
                                if((int)($reportClaim['found_report_id']??0)===(int)$r['id']){
                                    $reportClaims[]=$reportClaim;
                                }
                            }
                        }
                        $pendingReportClaims=array_values(array_filter(
                            $reportClaims,
                            static fn($claim)=>(($claim['claim_status']??'')==='pending')
                        ));
                        $approvedReportClaim=null;
                        foreach($reportClaims as $reportClaim){
                            if(($reportClaim['claim_status']??'')==='approved'){
                                $approvedReportClaim=$reportClaim;
                                break;
                            }
                        }
                        $reportClaimCompleted=$approvedReportClaim && !empty($approvedReportClaim['handover_at']);
                        ?>

                        <p class="admin-report-description">
                            <?= h($description) ?>
                        </p>

                        <div class="admin-report-facts">
                            <div class="admin-report-fact"><strong>Category:</strong> <?= h($r['category'] ?: 'Not specified') ?></div>
                            <div class="admin-report-fact"><strong>Brand:</strong> <?= h($r['brand'] ?: 'Not specified') ?></div>
                            <div class="admin-report-fact"><strong>Color:</strong> <?= h($r['color'] ?: 'Not specified') ?></div>
                            <div class="admin-report-fact"><strong>Report submitted:</strong> <?= h(date('F j, Y g:i A',strtotime($r['created_at']))) ?></div>
                        </div>

                        <div class="admin-report-meta">
                            <span><b>⌖</b> Location: <?= h(trim(($r['campus_location']??'').($r['building']?' · '.$r['building']:'').($r['floor']?' · '.$r['floor']:'').($r['specific_area']?' · '.$r['specific_area']:'')) ?: 'Not specified') ?></span>
                            <span><b>▣</b> Occurred: <?= h($r['event_date'] ?: date('Y-m-d',strtotime($r['created_at']))) ?><?= $r['event_time']?' at '.h($r['event_time']):'' ?></span>
                            <span><b>♙</b> Reporter: <?= h($r['full_name']) ?><?= $r['email']?' ('.h($r['email']).')':'' ?></span>
                            <?php if($r['storage_location']): ?>
                                <span><b>◈</b> Custody: <?= h($r['storage_location']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="admin-report-identifiers">
                            <strong>Distinctive identifiers:</strong>
                            <?= h($identifiers) ?>
                        </div>

                        <?php if(!empty($r['additional_notes'])): ?>
                            <div class="admin-report-short-message"><strong>Additional notes:</strong> <?= h($r['additional_notes']) ?></div>
                        <?php endif; ?>

                        <?php if($reportStatus==='rejected' && !empty($r['rejection_reason'])): ?>
                            <div class="admin-report-rejection">
                                <strong>Rejection reason:</strong>
                                <?= h($r['rejection_reason']) ?>
                            </div>
                        <?php endif; ?>

                        <?php if($reportStatus==='approved'): ?>
                            <div class="admin-report-workflow">
                                <div class="admin-report-workflow-head">
                                    <span class="admin-report-workflow-title">Next Action</span>
                                    <?php if($handoverCompleted): ?>
                                        <span class="admin-report-workflow-state complete">Completed</span>
                                    <?php elseif($linkedMatch && ($linkedMatch['verification_outcome']??'')==='not_match'): ?>
                                        <span class="admin-report-workflow-state waiting">Not a Match — Found Item Reopened</span>
                                    <?php elseif($linkedMatch && ($linkedMatch['verification_outcome']??'')==='reschedule'): ?>
                                        <span class="admin-report-workflow-state waiting">Verification Needs Rescheduling</span>
                                    <?php elseif($scheduleReady): ?>
                                        <span class="admin-report-workflow-state success">Checking Scheduled</span>
                                    <?php elseif($linkedMatch && $linkedMatch['match_status']==='confirmed'): ?>
                                        <span class="admin-report-workflow-state waiting">Match Approved — Schedule Pending</span>
                                    <?php elseif($linkedMatch && $linkedMatch['match_status']==='pending'): ?>
                                        <span class="admin-report-workflow-state waiting">Match Under Review</span>
                                    <?php elseif($reportClaimCompleted): ?>
                                        <span class="admin-report-workflow-state complete">Claim Completed — Item Returned</span>
                                    <?php elseif($approvedReportClaim): ?>
                                        <span class="admin-report-workflow-state success">Claim Approved — Verification Scheduled</span>
                                    <?php elseif($pendingReportClaims): ?>
                                        <span class="admin-report-workflow-state waiting">Claim Pending</span>
                                    <?php else: ?>
                                        <span class="admin-report-workflow-state"><?= $type==='found' ? 'Waiting for Match or Claim' : 'Waiting for Match' ?></span>
                                    <?php endif; ?>
                                </div>

                                <?php if(!$linkedMatch): ?>
                                    <div class="admin-report-short-message">
                                        <?php if($reportClaimCompleted): ?>
                                            <strong>Claim completed — item returned.</strong> The ownership claim was verified and the item has been released.
                                        <?php elseif($approvedReportClaim): ?>
                                            <strong>Claim approved — verification scheduled.</strong> The claimant must attend the scheduled in-person verification before the item can be released.
                                        <?php elseif($pendingReportClaims): ?>
                                            <strong>Action needed: review the ownership claim below.</strong> Compare the claimant's evidence with the found item before approving or rejecting the claim.
                                        <?php elseif($type==='lost'): ?>
                                            <strong>Next step: wait for a potential match.</strong> An approved found-item report must be matched before a checking schedule can be released.
                                        <?php else: ?>
                                            <strong>Waiting for a match or claim.</strong> This approved found item is available for potential Lost ↔ Found matching and ownership claims.
                                        <?php endif; ?>
                                    </div>
                                <?php elseif($linkedMatch['match_status']==='pending'): ?>
                                    <div class="admin-report-short-message">
                                        Potential Lost ↔ Found match detected. Review and approve/reject it from the <strong>Matches</strong> tab before releasing the schedule.
                                    </div>
                                <?php elseif($linkedMatch['match_status']==='rejected' && ($linkedMatch['verification_outcome']??'')==='not_match'): ?>
                                    <div class="admin-report-short-message">
                                        <strong>Not a Match — Found Item Reopened.</strong>
                                        The in-person verification showed that the potential match was not the student's item. The found item is open again in Search &amp; Browse.
                                        <?php if(!empty($linkedMatch['verification_outcome_reason'])): ?>
                                            <br><strong>Reason:</strong> <?= h($linkedMatch['verification_outcome_reason']) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif($linkedMatch['match_status']==='rejected'): ?>
                                    <div class="admin-report-short-message">
                                        The potential match was dismissed before in-person verification. <?= h($linkedMatch['admin_remarks'] ?: 'The report remains visible with its original item details.') ?>
                                    </div>
                                <?php else: ?>
                                    <?php if($handoverCompleted): ?>
                                        <div class="admin-report-completion">
                                            <span><strong>✓ Successfully completed / returned</strong></span>
                                            <span>Completed on: <strong><?= h(date('F j, Y g:i A',strtotime($linkedMatch['handover_at']))) ?></strong></span>
                                            <span>Release location: <strong><?= h($linkedMatch['verification_location']) ?></strong></span>
                                            <span>Verification result: <strong><?= h($linkedMatch['verification_outcome_reason'] ?: 'Ownership verified in person and item released.') ?></strong></span>
                                        </div>
                                    <?php elseif(($linkedMatch['verification_outcome']??'')==='reschedule'): ?>
                                        <div class="admin-report-short-message">
                                            <strong>Verification needs rescheduling.</strong>
                                            <?php if(!empty($linkedMatch['verification_outcome_reason'])): ?>
                                                Reason: <?= h($linkedMatch['verification_outcome_reason']) ?>
                                            <?php endif; ?>
                                        </div>
                                        <form method="post" class="admin-report-mini-form" onsubmit="return confirm('Release a new checking schedule to the users?');">
                                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="match_schedule">
                                            <input type="hidden" name="match_id" value="<?= (int)$linkedMatch['id'] ?>">
                                            <label>Date
                                                <input type="date" name="verification_date" required>
                                            </label>
                                            <label>Time
                                                <input type="time" name="verification_time" required>
                                            </label>
                                            <label>Deadline
                                                <input type="datetime-local" name="verification_deadline" required>
                                            </label>
                                            <label>Location
                                                <input type="text" name="verification_location" placeholder="Lost &amp; Found Office / designated area" required>
                                            </label>
                                            <label class="wide">Schedule note (optional)
                                                <textarea name="remarks" placeholder="Short note for the new checking appointment"></textarea>
                                            </label>
                                            <div class="wide"><button class="btn small success" type="submit">Release New Schedule &amp; Notify Users</button></div>
                                        </form>
                                    <?php elseif($linkedMatch['match_status']==='confirmed'): ?>
                                        <div class="admin-report-short-message">
                                            Match approved. No student confirmation is required. The administrator can release the in-person checking schedule.
                                        </div>

                                        <?php if($scheduleReady): ?>
                                            <div class="admin-report-schedule">
                                                <span>Date: <strong><?= h(date('F j, Y',strtotime($linkedMatch['verification_date']))) ?></strong></span>
                                                <span>Time: <strong><?= h(date('g:i A',strtotime($linkedMatch['verification_date'].' '.$linkedMatch['verification_time']))) ?></strong></span>
                                                <span>Deadline: <strong><?= h(date('F j, Y g:i A',strtotime($linkedMatch['verification_deadline']))) ?></strong></span>
                                                <span>Location: <strong><?= h($linkedMatch['verification_location']) ?></strong></span>
                                            </div>
                                            <div class="admin-report-short-message">
                                                Checking schedule has been recorded. Both users are notified automatically. Record the final in-person verification result from the <strong>Matches</strong> tab.
                                            </div>
                                        <?php else: ?>
                                            <form method="post" class="admin-report-mini-form" onsubmit="return confirm('Release this in-person checking schedule to the users?');">
                                                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="match_schedule">
                                                <input type="hidden" name="match_id" value="<?= (int)$linkedMatch['id'] ?>">
                                                <label>Date
                                                    <input type="date" name="verification_date" required>
                                                </label>
                                                <label>Time
                                                    <input type="time" name="verification_time" required>
                                                </label>
                                                <label>Deadline
                                                    <input type="datetime-local" name="verification_deadline" required>
                                                </label>
                                                <label>Location
                                                    <input type="text" name="verification_location" placeholder="Lost &amp; Found Office / designated area" required>
                                                </label>
                                                <label class="wide">Schedule note (optional)
                                                    <textarea name="remarks" placeholder="Short note for the checking appointment"></textarea>
                                                </label>
                                                <div class="wide"><button class="btn small success" type="submit">Release Schedule &amp; Notify Users</button></div>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if($type==='found' && $reportStatus==='approved'): ?>
                            <div class="admin-report-claims">
                                <div class="admin-report-claims-head">
                                    <span class="admin-report-claims-title">Ownership Claims</span>
                                    <span class="admin-report-claims-count">
                                        <?= count($reportClaims) ?> claim<?= count($reportClaims)===1?'':'s' ?>
                                    </span>
                                </div>

                                <?php if($reportClaims): ?>
                                    <?php foreach($reportClaims as $reportClaim): ?>
                                        <?php
                                        $claimStatus=(string)($reportClaim['claim_status']??'pending');
                                        $claimantName=(string)($reportClaim['full_name']??'Unknown claimant');
                                        $claimantId=(string)($reportClaim['university_id']??'');
                                        $claimEmail=(string)($reportClaim['email']??'');
                                        $ownership=trim((string)($reportClaim['ownership_description']??''));
                                        $identifying=trim((string)($reportClaim['identifying_information']??''));
                                        $supporting=trim((string)($reportClaim['supporting_evidence']??''));
                                        $proofPhoto=trim((string)($reportClaim['proof_photo_path']??''));
                                        ?>
                                        <article class="admin-report-claim">
                                            <div class="admin-report-claim-top">
                                                <div class="admin-report-claim-person">
                                                    <strong>CLM-<?= str_pad((string)$reportClaim['id'],3,'0',STR_PAD_LEFT) ?> · <?= h($claimantName) ?></strong>
                                                    <span><?= h($claimantId ?: 'No university ID') ?><?= $claimEmail ? ' · '.h($claimEmail) : '' ?></span>
                                                </div>
                                                <span class="admin-report-claim-status <?= h($claimStatus) ?>">
                                                    <?= $claimStatus==='pending' ? 'Pending Admin Review' : ($claimStatus==='approved' ? 'Approved' : 'Rejected') ?>
                                                </span>
                                            </div>

                                            <div class="admin-report-claim-details">
                                                <div class="admin-report-claim-detail">
                                                    <small>Ownership description</small>
                                                    <span><?= h($ownership ?: 'Not provided.') ?></span>
                                                </div>
                                                <div class="admin-report-claim-detail">
                                                    <small>Identifying information</small>
                                                    <span><?= h($identifying ?: 'Not provided.') ?></span>
                                                </div>
                                                <div class="admin-report-claim-detail">
                                                    <small>Supporting evidence</small>
                                                    <span><?= h($supporting ?: 'Not provided.') ?></span>
                                                </div>
                                                <div class="admin-report-claim-detail">
                                                    <small>Claim submitted</small>
                                                    <span><?= h($reportClaim['created_at'] ? date('F j, Y g:i A',strtotime($reportClaim['created_at'])) : '—') ?></span>
                                                </div>
                                            </div>

                                            <?php if($proofPhoto): ?>
                                                <div class="admin-report-claim-proof">
                                                    <span class="admin-report-claim-proof-label">Proof photo</span>
                                                    <img src="<?= h($proofPhoto) ?>" alt="Ownership proof photo">
                                                </div>
                                            <?php endif; ?>

                                            <?php if($claimStatus==='pending'): ?>
                                                <div class="admin-report-claim-actions">
                                                    <details>
                                                        <summary class="btn small danger">Reject Claim</summary>
                                                        <form method="post" class="mini-form">
                                                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="claim_decision">
                                                            <input type="hidden" name="claim_id" value="<?= (int)$reportClaim['id'] ?>">
                                                            <input type="hidden" name="decision" value="reject">
                                                            <textarea name="reason" required placeholder="Reason required"></textarea>
                                                            <button class="btn small danger" type="submit">Confirm Reject</button>
                                                        </form>
                                                    </details>

                                                    <details>
                                                        <summary class="btn small success">✓ Approve &amp; Set Release</summary>
                                                        <form method="post" class="admin-report-claim-schedule">
                                                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="claim_decision">
                                                            <input type="hidden" name="claim_id" value="<?= (int)$reportClaim['id'] ?>">
                                                            <input type="hidden" name="decision" value="approve">

                                                            <label>Visit date
                                                                <input type="date" name="verification_date" value="<?= date('Y-m-d') ?>" required>
                                                            </label>
                                                            <label>Visit time
                                                                <input type="time" name="verification_time" value="10:00" required>
                                                            </label>
                                                            <label>Verification deadline
                                                                <input type="date" name="verification_deadline" value="<?= date('Y-m-d',strtotime('+7 days')) ?>" required>
                                                            </label>
                                                            <label>Pickup / verification location
                                                                <input type="text" name="verification_location" value="Office of Mapúa, 1st Floor" required>
                                                            </label>
                                                            <div class="wide">
                                                                <button class="btn small success" type="submit">Confirm Approval &amp; Schedule</button>
                                                            </div>
                                                        </form>
                                                    </details>
                                                </div>
                                            <?php elseif($claimStatus==='approved'): ?>
                                                <div class="admin-report-claim-release">
                                                    <strong>Release verification scheduled.</strong><br>
                                                    Visit: <?= $reportClaim['verification_date'] ? h(date('F j, Y',strtotime($reportClaim['verification_date']))) : 'Date not set' ?>
                                                    <?php if(!empty($reportClaim['verification_time'])): ?> at <?= h(date('g:i A',strtotime($reportClaim['verification_time']))) ?><?php endif; ?>
                                                    <?php if(!empty($reportClaim['verification_location'])): ?> at <?= h($reportClaim['verification_location']) ?><?php endif; ?>
                                                    <br>
                                                    Verification deadline: <?= $reportClaim['verification_deadline'] ? h(date('F j, Y',strtotime($reportClaim['verification_deadline']))) : '—' ?>
                                                </div>

                                                <?php if(empty($reportClaim['handover_at'])): ?>
                                                    <form method="post" class="admin-report-claim-handover" onsubmit="return confirm('Confirm physical handover and mark this claimed item as Returned?');">
                                                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="handover_item">
                                                        <input type="hidden" name="claim_id" value="<?= (int)$reportClaim['id'] ?>">
                                                        <textarea name="handover_notes" placeholder="Handover confirmation notes / officer signature"></textarea>
                                                        <button class="btn small success" type="submit">✓ Confirm Physical Handover — Mark Returned</button>
                                                    </form>
                                                <?php else: ?>
                                                    <div class="admin-report-claim-release">
                                                        <strong>✓ Claim completed and item returned.</strong><br>
                                                        Completed on <?= h(date('F j, Y g:i A',strtotime($reportClaim['handover_at']))) ?>.
                                                    </div>
                                                <?php endif; ?>
                                            <?php elseif($claimStatus==='rejected'): ?>
                                                <div class="admin-report-claim-release" style="background:#fff5f6;border-color:#f3d0d7;color:#8f3d50">
                                                    <strong>Rejection reason:</strong> <?= h($reportClaim['rejection_reason'] ?: 'No reason recorded.') ?>
                                                </div>
                                            <?php endif; ?>
                                        </article>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="admin-report-claim-empty">
                                        No ownership claims have been submitted for this found item yet. The item remains available for both potential Lost ↔ Found matching and future ownership claims.
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                    </div>

                    <span class="admin-report-status <?= h($reportStatus) ?>">
                        <?= h(status_label($reportStatus)) ?>
                    </span>

                    <?php if($reportStatus==='pending_review'): ?>
                        <div class="admin-report-actions">
                            <span class="admin-report-side-note">
                                Reports are automatically recorded. Admin approval is handled at the Potential Matches or Claims stage.
                            </span>
                            <?php if(empty($r['admin_hidden'])): ?>
                                <form method="post" onsubmit="return confirm('Remove this report from public browsing? This should only be used for inappropriate, sensitive, or unsuitable school content.');">
                                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_report">
                                    <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="reason" value="Removed by administrator from public browsing.">
                                    <button class="btn small admin-report-delete" type="submit">Remove from Public Browse</button>
                                </form>
                            <?php else: ?>
                                <span class="admin-report-side-note">Removed from public browsing.</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                </article>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="admin-reports-empty">
            <strong>
                <?php if($reportFilter==='approved'): ?>
                    No recorded active reports yet.
                <?php elseif($reportFilter==='rejected'): ?>
                    No rejected reports yet.
                <?php else: ?>
                    No submitted reports found.
                <?php endif; ?>
            </strong>
            <span>
                New Lost and Found reports are recorded automatically; administrator approval is only required for potential matches and claims.
            </span>
        </div>

    <?php endif; ?>

</div>


<?php elseif($tab==='matches'): ?>

<div class="matches-page">

<style>
.matches-page .match-card{
    overflow:visible;
}
.matches-page .match-card-head{
    align-items:flex-start;
    gap:20px;
}
.matches-page .match-status{
    white-space:nowrap;
}
.matches-page .returned-note{background:#e7faf2;border-color:#bcefdc;color:#087b59}
.matches-page .returned-note strong{color:#087b59}
.matches-page .match-scheduled-badge{
    margin-left:auto;
    margin-top:2px;
    display:flex;
    flex-direction:column;
    gap:3px;
    padding:10px 14px;
    border:1px solid #b7ead2;
    border-radius:12px;
    background:#effcf5;
    color:#137a4b;
    font-size:13px;
    line-height:1.35;
}
.matches-page .match-scheduled-badge strong{
    font-size:13px;
}
.matches-page .match-scheduled-badge span{
    color:#39705a;
}
.matches-page .match-actions{
    align-items:flex-end;
}
.matches-page .match-approve-details{
    flex:1 1 520px;
}
.matches-page .match-approve-details > summary{
    list-style:none;
    cursor:pointer;
    display:inline-flex;
    justify-content:center;
    align-items:center;
    min-height:46px;
    padding:0 20px;
    border-radius:10px;
    font-weight:800;
}
.matches-page .match-approve-details > summary::-webkit-details-marker{
    display:none;
}
.matches-page .match-approve-details[open] > summary{
    border-radius:10px 10px 0 0;
}
.matches-page .match-schedule-form{
    margin-top:10px;
    padding:20px;
    border:1px solid #d8d3ff;
    border-radius:14px;
    background:linear-gradient(180deg,#fbfaff,#ffffff);
}
.matches-page .match-schedule-heading{
    display:flex;
    flex-direction:column;
    gap:4px;
    margin-bottom:16px;
}
.matches-page .match-schedule-heading strong{
    color:#35404a;
    font-size:16px;
}
.matches-page .match-schedule-heading span{
    color:#20242a;
    font-size:13px;
    line-height:1.5;
}
.matches-page .match-schedule-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:12px;
}
.matches-page .match-schedule-grid label,
.matches-page .match-remarks-field{
    display:flex;
    flex-direction:column;
    gap:7px;
    color:#35404a;
    font-size:12px;
    font-weight:800;
}
.matches-page .match-schedule-grid .match-schedule-location{
    grid-column:1 / -1;
}
.matches-page .match-schedule-grid input,
.matches-page .match-remarks-field textarea{
    width:100%;
    box-sizing:border-box;
    border:1px solid #d8deea;
    border-radius:9px;
    background:#fff;
    padding:10px 11px;
    color:#35404a;
    font:inherit;
}
.matches-page .match-schedule-grid input:focus,
.matches-page .match-remarks-field textarea:focus{
    outline:none;
    border-color:#f3d8dd;
    box-shadow:0 0 0 3px rgba(119,103,255,.12);
}
.matches-page .match-remarks-field{
    margin-top:12px;
}
.matches-page .match-remarks-field textarea{
    min-height:78px;
    resize:vertical;
}
.matches-page .match-confirm-schedule-button{
    width:100%;
    margin-top:14px;
    min-height:46px;
    border:0;
    border-radius:10px;
    background:#137a4b;
    color:#fff;
    font-weight:800;
    cursor:pointer;
}
.matches-page .match-confirm-schedule-button:hover{
    filter:brightness(.96);
}
@media(max-width:900px){
    .matches-page .match-card-head{
        flex-direction:column;
    }
    .matches-page .match-scheduled-badge{
        margin-left:0;
    }
    .matches-page .match-schedule-grid{
        grid-template-columns:1fr;
    }
    .matches-page .match-schedule-grid .match-schedule-location{
        grid-column:auto;
    }
}
</style>


    <!-- =====================================================
         MATCHES PAGE HEADER
    ====================================================== -->

    <div class="matches-page-head">

        <div>

            <h1>
                Manage Potential Matches
            </h1>

            <p>
                Flowchart Step:
                System detects a potential match
                → Admin checks the calculated match percentage and details
                → Admin approves or dismisses the potential match
                → Found Reporter turns over the physical item
                → Admin releases owner verification schedule
                → Admin records the final in-person result
            </p>

        </div>


        <!-- MATCH FILTERS -->

        <div class="matches-filter">

            <a
                class="<?= $matchFilter==='all'?'active':'' ?>"
                href="admin.php?tab=matches&amp;match_filter=all"
            >
                All
                <span>
                    <?= $matchCounts['all'] ?>
                </span>
            </a>

            <a
                class="<?= $matchFilter==='pending'?'active':'' ?>"
                href="admin.php?tab=matches&amp;match_filter=pending"
            >
                Awaiting Verification

                <?php if($matchCounts['pending']>0): ?>

                    <span>
                        <?= $matchCounts['pending'] ?>
                    </span>

                <?php endif; ?>

            </a>

            <a
                class="<?= $matchFilter==='confirmed'?'active':'' ?>"
                href="admin.php?tab=matches&amp;match_filter=confirmed"
            >
                Scheduled / Active

                <?php if($matchCounts['confirmed']>0): ?>

                    <span>
                        <?= $matchCounts['confirmed'] ?>
                    </span>

                <?php endif; ?>

            </a>

            <a
                class="<?= $matchFilter==='completed'?'active':'' ?>"
                href="admin.php?tab=matches&amp;match_filter=completed"
            >
                Completed

                <?php if($matchCounts['completed']>0): ?>

                    <span>
                        <?= $matchCounts['completed'] ?>
                    </span>

                <?php endif; ?>

            </a>

            <a
                class="<?= $matchFilter==='not_match'?'active':'' ?>"
                href="admin.php?tab=matches&amp;match_filter=not_match"
            >
                Not a Match

                <?php if($matchCounts['not_match']>0): ?>

                    <span>
                        <?= $matchCounts['not_match'] ?>
                    </span>

                <?php endif; ?>

            </a>

            <a
                class="<?= $matchFilter==='rejected'?'active':'' ?>"
                href="admin.php?tab=matches&amp;match_filter=rejected"
            >
                Dismissed

                <?php if($matchCounts['rejected']>0): ?>

                    <span>
                        <?= $matchCounts['rejected'] ?>
                    </span>

                <?php endif; ?>

            </a>

        </div>

    </div>


    <!-- =====================================================
         MATCH CARDS
    ====================================================== -->

    <div class="matches-list">

    <?php foreach($visibleMatches as $m): ?>

        <?php
        $foundLocation=trim(
            implode(', ',array_filter([
                $m['found_campus_location']??'',
                $m['found_building']??'',
                $m['found_floor'] ? 'Floor '.$m['found_floor'] : '',
                $m['found_specific_area']??''
            ]))
        );

        $lostLocation=trim(
            implode(', ',array_filter([
                $m['lost_campus_location']??'',
                $m['lost_building']??'',
                $m['lost_floor'] ? 'Floor '.$m['lost_floor'] : '',
                $m['lost_specific_area']??''
            ]))
        );

        $foundDate=trim(
            ($m['found_event_date']??'')
            .' '
            .($m['found_event_time']??'')
        );

        $lostDate=trim(
            ($m['lost_event_date']??'')
            .' '
            .($m['lost_event_time']??'')
        );

        $foundImage=trim((string)($m['found_photo_path']??''));
        $lostImage=trim((string)($m['lost_photo_path']??''));

        $status=$m['match_status']??'pending';

        $verificationOutcome=$m['verification_outcome']??'';

        $statusText=match(true){
            $verificationOutcome==='completed' || !empty($m['handover_at'])=>'Completed — Item Returned',
            $verificationOutcome==='not_match'=>'Not a Match — Found Item Reopened',
            $verificationOutcome==='reschedule'=>'Owner Verification Needs Rescheduling',
            $status==='pending'=>'Awaiting Admin Verification',
            $status==='confirmed' && !empty($m['found_handover_at']) && !empty($m['verification_date'])=>'Owner Verification Scheduled',
            $status==='confirmed' && !empty($m['found_handover_at'])=>'Found Item Received — Schedule Owner',
            $status==='confirmed' && !empty($m['found_turnover_deadline'])=>'Waiting for Found Item Turnover',
            $status==='confirmed'=>'Match Approved — Set Found Turnover Deadline',
            $status==='rejected'=>'Dismissed',
            default=>ucfirst($status)
        };

        $statusClass=match(true){
            $verificationOutcome==='completed' || !empty($m['handover_at'])=>'returned',
            $verificationOutcome==='not_match'=>'dismissed',
            $verificationOutcome==='reschedule'=>'awaiting',
            $status==='pending'=>'awaiting',
            $status==='confirmed'=>'verified',
            $status==='rejected'=>'dismissed',
            default=>'awaiting'
        };

        $categoryMatch=
            strtolower((string)$m['found_category'])
            ===
            strtolower((string)$m['lost_category']);

        $locationMatch=
            $foundLocation!=='' &&
            $lostLocation!=='' &&
            (
                stripos($foundLocation,$m['lost_campus_location']??'')!==false
                ||
                stripos($lostLocation,$m['found_campus_location']??'')!==false
            );

        $dateDifferenceText='Date information available';

        if(!empty($m['lost_event_date']) && !empty($m['found_event_date'])){
            $lostTs=strtotime(
                $m['lost_event_date'].' '.($m['lost_event_time']??'00:00')
            );

            $foundTs=strtotime(
                $m['found_event_date'].' '.($m['found_event_time']??'00:00')
            );

            if($lostTs!==false && $foundTs!==false){
                $hours=abs($foundTs-$lostTs)/3600;
                $days=$hours/24;

                if($hours<24){
                    $dateDifferenceText=
                        'Close temporal window (same day)';
                }else{
                    $dateDifferenceText=
                        'Close temporal window ('.round($days,1).' day(s) difference)';
                }
            }
        }

        $sharedTerms=[];

        $combinedLost=strtolower(
            implode(' ',[
                $m['lost_item_name']??'',
                $m['lost_description']??'',
                $m['lost_color']??'',
                $m['lost_brand']??'',
                $m['lost_identifying_features']??''
            ])
        );

        $combinedFound=strtolower(
            implode(' ',[
                $m['found_item_name']??'',
                $m['found_description']??'',
                $m['found_color']??'',
                $m['found_brand']??'',
                $m['found_identifying_features']??''
            ])
        );

        $tokens=preg_split(
            '/[^a-z0-9]+/i',
            $combinedLost
        )?:[];

        foreach($tokens as $token){
            if(
                strlen($token)>=4 &&
                stripos($combinedFound,$token)!==false &&
                !in_array($token,$sharedTerms,true)
            ){
                $sharedTerms[]=$token;
            }
        }

        $sharedTerms=array_slice($sharedTerms,0,5);

        $termsText=$sharedTerms
            ? implode(', ',array_map(
                static fn($term)=>'"'.ucfirst($term).'"',
                $sharedTerms
            ))
            : 'Shared descriptive attributes';

        /*
         * Admin decision guidance.
         * The score is a system recommendation only; it does NOT notify users
         * until the administrator explicitly verifies the match.
         */
        $score=(int)($m['match_score']??0);

        if($score>=80){
            $recommendation='Strong Match Candidate';
            $recommendationClass='strong';
            $recommendationText='The records share several strong identifiers. Review the evidence below, then verify only if the found item is consistent with the lost report.';
        }elseif($score>=60){
            $recommendation='Good Match Candidate';
            $recommendationClass='good';
            $recommendationText='The records have multiple matching signals. The system recommends a careful admin review before notifying the lost-item owner.';
        }else{
            $recommendation='Possible Match — Review Carefully';
            $recommendationClass='possible';
            $recommendationText='The match reached the system threshold, but the evidence is weaker. Compare the item details and distinguishing marks carefully before verifying.';
        }

        $notificationState=$status==='confirmed'
            ? 'User notification sent'
            : ($status==='pending'
                ? 'No user notification sent yet'
                : 'No user notification sent');

        /*
         * Detailed score explanation.
         * These points mirror the actual potential_match_score() logic:
         * item name (up to 35), category (20), brand (15), color (10),
         * and description/identifying-feature token overlap (up to 20).
         *
         * Date and location are shown as supporting context, but they do
         * not contribute points to the numeric score in the current algorithm.
         */
        $lostNameNorm=strtolower(trim((string)($m['lost_item_name']??'')));
        $foundNameNorm=strtolower(trim((string)($m['found_item_name']??'')));

        $namePoints=0;
        $nameReason='No usable item-name information';
        $lostNameTokens=preg_split('/[^a-z0-9]+/i',$lostNameNorm)?:[];
        $foundNameTokens=preg_split('/[^a-z0-9]+/i',$foundNameNorm)?:[];
        $lostNameTokens=array_values(array_filter($lostNameTokens,static fn($t)=>strlen($t)>=3));
        $foundNameTokens=array_values(array_filter($foundNameTokens,static fn($t)=>strlen($t)>=3));

        if($lostNameNorm!=='' && $foundNameNorm!==''){
            if($lostNameNorm===$foundNameNorm){
                $namePoints=35;
                $nameReason='Exact item-name match (+35)';
            }elseif($lostNameTokens && $foundNameTokens){
                $nameOverlap=count(array_intersect($lostNameTokens,$foundNameTokens));
                $namePoints=min(25,$nameOverlap*8);
                $nameReason=$namePoints>0
                    ? 'Partial item-name overlap: '.$nameOverlap.' shared term(s) (+'.$namePoints.')'
                    : 'No shared item-name terms (+0)';
            }
        }

        $categoryPoints=$categoryMatch?20:0;
        $categoryReason=$categoryMatch
            ? 'Same category: '.(string)$m['found_category'].' (+20)'
            : 'Different categories (+0)';

        $brandPoints=0;
        $brandReason='Brand not available or different (+0)';
        if(trim((string)($m['lost_brand']??''))!=='' && trim((string)($m['found_brand']??''))!==''){
            if(strtolower(trim((string)$m['lost_brand']))===strtolower(trim((string)$m['found_brand']))){
                $brandPoints=15;
                $brandReason='Same brand: '.(string)$m['found_brand'].' (+15)';
            }else{
                $brandReason='Different brands (+0)';
            }
        }

        $colorPoints=0;
        $colorReason='Color not available or different (+0)';
        if(trim((string)($m['lost_color']??''))!=='' && trim((string)($m['found_color']??''))!==''){
            if(strtolower(trim((string)$m['lost_color']))===strtolower(trim((string)$m['found_color']))){
                $colorPoints=10;
                $colorReason='Same color: '.(string)$m['found_color'].' (+10)';
            }else{
                $colorReason='Different colors (+0)';
            }
        }

        $detailPoints=0;
        $detailReason='No shared description/feature terms (+0)';
        $lostDetailText=strtolower(
            trim(
                implode(' ',[
                    $m['lost_description']??'',
                    $m['lost_identifying_features']??''
                ])
            )
        );
        $foundDetailText=strtolower(
            trim(
                implode(' ',[
                    $m['found_description']??'',
                    $m['found_identifying_features']??''
                ])
            )
        );

        $detailTokensLost=preg_split('/[^a-z0-9]+/i',$lostDetailText)?:[];
        $detailTokensFound=preg_split('/[^a-z0-9]+/i',$foundDetailText)?:[];
        $detailTokensLost=array_values(array_unique(array_filter(
            $detailTokensLost,
            static fn($t)=>strlen($t)>=3 && !in_array($t,['the','and','with','for','item','this','that','near','found','lost'],true)
        )));
        $detailTokensFound=array_values(array_unique(array_filter(
            $detailTokensFound,
            static fn($t)=>strlen($t)>=3 && !in_array($t,['the','and','with','for','item','this','that','near','found','lost'],true)
        )));
        $detailOverlap=array_values(array_intersect($detailTokensLost,$detailTokensFound));
        if($detailOverlap){
            $detailPoints=min(20,count($detailOverlap)*4);
            $detailReason='Shared description/feature terms: '.implode(', ',array_slice($detailOverlap,0,6)).' (+'.$detailPoints.')';
        }

        $calculatedScore=$namePoints+$categoryPoints+$brandPoints+$colorPoints+$detailPoints;

        $contextChecks=[
            [
                'label'=>'Date proximity',
                'value'=>$dateDifferenceText,
                'note'=>'Supporting context only; not included in the numeric score.'
            ],
            [
                'label'=>'Location proximity',
                'value'=>$locationMatch
                    ? 'Location details are consistent / nearby'
                    : 'Location details were compared but are not clearly the same',
                'note'=>'Supporting context only; not included in the numeric score.'
            ],
        ];

        $matchIdLabel='MTH-'.(
            $m['id']??date('YmdHis')
        );
        ?>


        <article class="match-card">

            <!-- MATCH CARD HEADER -->

            <div class="match-card-head">

                <div class="match-title-wrap">

                    <div class="match-sparkle">
                        ✨
                    </div>

                    <div>

                        <h2>
                            Match #<?= h($matchIdLabel) ?>

                            <span class="match-score">
                                <?= (int)$m['match_score'] ?>% Match Score
                            </span>
                        </h2>

                        <p>
                            Category:
                            <?= h($m['found_category']) ?>

                            ·

                            Detected on
                            <?= h(
                                date(
                                    'n/j/Y, g:i:s A',
                                    strtotime($m['created_at'])
                                )
                            ) ?>
                        </p>

                    </div>

                </div>


                <span class="match-status <?= $statusClass ?>">

                    <?php if($status==='confirmed'): ?>
                        ✓
                    <?php elseif($status==='pending'): ?>
                        ◷
                    <?php else: ?>
                        ×
                    <?php endif; ?>

                    <?= h($statusText) ?>

                </span>

            </div>


            <!-- LOST / FOUND COMPARISON -->

            <div class="match-comparison">


                <!-- LOST REPORT -->

                <section class="match-side lost-side">

                    <div class="match-side-label-row">

                        <span class="match-side-label lost">
                            LOST REPORT ITEM
                        </span>

                        <span class="match-item-id">
                            ITM-<?= str_pad(
                                (string)$m['lost_report_id'],
                                3,
                                '0',
                                STR_PAD_LEFT
                            ) ?>
                        </span>

                    </div>


                    <div class="match-item-main">

                        <?php if($lostImage): ?>

                            <img
                                src="<?= h($lostImage) ?>"
                                alt="<?= h($m['lost_item_name']) ?>"
                            >

                        <?php else: ?>

                            <div class="match-image-placeholder">
                                📦
                            </div>

                        <?php endif; ?>


                        <div>

                            <h3>
                                <?= h($m['lost_item_name']) ?>
                            </h3>

                            <?php if($m['lost_description']): ?>

                                <p>
                                    <?= h(
                                        mb_strimwidth(
                                            $m['lost_description'],
                                            0,
                                            150,
                                            '...'
                                        )
                                    ) ?>
                                </p>

                            <?php endif; ?>

                            <?php if($lostLocation): ?>

                                <div class="match-meta">
                                    📍 <?= h($lostLocation) ?>
                                </div>

                            <?php endif; ?>

                            <?php if($lostDate): ?>

                                <div class="match-meta">
                                    ▣ <?= h($lostDate) ?>
                                </div>

                            <?php endif; ?>

                            <div class="match-meta match-reporter-contact">
                                👤 Lost Reported By:
                                <strong><?= h($m['lost_owner'] ?: 'Not provided') ?></strong>
                                <?php if(!empty($m['lost_owner_email'])): ?>
                                    · <?= h($m['lost_owner_email']) ?>
                                <?php else: ?>
                                    · Email not provided
                                <?php endif; ?>
                            </div>

                        </div>

                    </div>


                    <div class="match-detail-box lost-detail">

                        <strong>
                            Marks:
                        </strong>

                        <?= h(
                            $m['lost_identifying_features']
                            ?: 'No distinguishing marks provided.'
                        ) ?>

                    </div>

                </section>


                <!-- FOUND ITEM -->

                <section class="match-side found-side">

                    <div class="match-side-label-row">

                        <span class="match-side-label found">
                            FOUND CATALOG ITEM
                        </span>

                        <span class="match-item-id">
                            ITM-<?= str_pad(
                                (string)$m['found_report_id'],
                                3,
                                '0',
                                STR_PAD_LEFT
                            ) ?>
                        </span>

                    </div>


                    <div class="match-item-main">

                        <?php if($foundImage): ?>

                            <img
                                src="<?= h($foundImage) ?>"
                                alt="<?= h($m['found_item_name']) ?>"
                            >

                        <?php else: ?>

                            <div class="match-image-placeholder">
                                📦
                            </div>

                        <?php endif; ?>


                        <div>

                            <h3>
                                <?= h($m['found_item_name']) ?>
                            </h3>

                            <?php if($m['found_description']): ?>

                                <p>
                                    <?= h(
                                        mb_strimwidth(
                                            $m['found_description'],
                                            0,
                                            150,
                                            '...'
                                        )
                                    ) ?>
                                </p>

                            <?php endif; ?>

                            <?php if($foundLocation): ?>

                                <div class="match-meta">
                                    📍 <?= h($foundLocation) ?>
                                </div>

                            <?php endif; ?>

                            <?php if($foundDate): ?>

                                <div class="match-meta">
                                    ▣ <?= h($foundDate) ?>
                                </div>

                            <?php endif; ?>

                            <div class="match-meta match-reporter-contact">
                                👤 Found Reported By:
                                <strong><?= h($m['found_reporter'] ?: 'Not provided') ?></strong>
                                <?php if(!empty($m['found_reporter_email'])): ?>
                                    · <?= h($m['found_reporter_email']) ?>
                                <?php else: ?>
                                    · Email not provided
                                <?php endif; ?>
                            </div>

                        </div>

                    </div>


                    <div class="match-detail-box found-detail">

                        <strong>
                            Custody:
                        </strong>

                        <?= h(
                            $m['found_storage_location']
                            ?: 'Custody location recorded in report.'
                        ) ?>

                    </div>

                </section>

            </div>


            <!-- SYSTEM DECISION GUIDANCE -->

            <div class="match-decision-guide <?= h($recommendationClass) ?>">

                <div class="decision-guide-icon">
                    <?= $score>=80 ? '✓' : ($score>=60 ? '!' : '?') ?>
                </div>

                <div class="decision-guide-main">

                    <div class="decision-guide-top">

                        <div>
                            <span class="decision-guide-label">
                                SYSTEM RECOMMENDATION
                            </span>

                            <strong>
                                <?= h($recommendation) ?>
                            </strong>
                        </div>

                        <div class="decision-score">
                            <?= $score ?>%
                        </div>

                    </div>

                    <p>
                        <?= h($recommendationText) ?>
                    </p>

                    <div class="decision-guide-steps">

                        <div>
                            <span class="decision-step-number">1</span>
                            <div>
                                <strong>Review the evidence</strong>
                                <small>Compare name, category, brand/color, location, date, and distinguishing details.</small>
                            </div>
                        </div>

                        <div>
                            <span class="decision-step-number">2</span>
                            <div>
                                <strong>Make the admin decision</strong>
                                <small>Verify only when the Lost and Found records are genuinely consistent.</small>
                            </div>
                        </div>

                        <div>
                            <span class="decision-step-number">3</span>
                            <div>
                                <strong>Notification consequence</strong>
                                <small><?= h($notificationState) ?><?= $status==='pending' ? ' — once approved, both users are notified and the administrator can release the in-person checking schedule.' : ($status==='confirmed' ? ' — both users can be sent the checking schedule; the final result is recorded after the in-person verification.' : (($m['verification_outcome']??'')==='not_match' ? ' — the found item was reopened after the in-person check showed it was not the lost item.' : ' — the potential match was dismissed and no checking schedule was released.')) ?></small>
                            </div>
                        </div>

                    </div>

                </div>

            </div>


            <!-- DETAILED MATCH EXPLANATION -->

            <div class="match-breakdown detailed-breakdown">

                <div class="breakdown-heading">

                    <div>
                        <h3>Why did the system suggest this match?</h3>
                        <p class="breakdown-intro">
                            The system compares the Lost Report and Found Catalog Item using
                            item identity and descriptive attributes. The numeric score is built
                            from item name, category, brand, color, and shared description/feature terms.
                        </p>
                    </div>

                    <div class="breakdown-score-box">
                        <span>CALCULATED SCORE</span>
                        <strong><?= (int)$calculatedScore ?>%</strong>
                        <small>Displayed match score: <?= (int)$m['match_score'] ?>%</small>
                    </div>

                </div>


                <div class="score-explanation-grid">

                    <div class="score-factor">

                        <div class="factor-icon">1</div>

                        <div class="factor-body">

                            <div class="factor-title-row">
                                <strong>Item name</strong>
                                <b><?= $namePoints > 0 ? '+'.$namePoints : '+0' ?></b>
                            </div>

                            <p>
                                <?= h($nameReason) ?>
                            </p>

                            <small>
                                Maximum contribution: 35 points for an exact name match;
                                partial name overlap can contribute up to 25 points.
                            </small>

                        </div>

                    </div>


                    <div class="score-factor">

                        <div class="factor-icon">2</div>

                        <div class="factor-body">

                            <div class="factor-title-row">
                                <strong>Category</strong>
                                <b><?= $categoryPoints > 0 ? '+'.$categoryPoints : '+0' ?></b>
                            </div>

                            <p><?= h($categoryReason) ?></p>

                            <small>
                                Maximum contribution: 20 points when both records use the same category.
                            </small>

                        </div>

                    </div>


                    <div class="score-factor">

                        <div class="factor-icon">3</div>

                        <div class="factor-body">

                            <div class="factor-title-row">
                                <strong>Brand</strong>
                                <b><?= $brandPoints > 0 ? '+'.$brandPoints : '+0' ?></b>
                            </div>

                            <p><?= h($brandReason) ?></p>

                            <small>
                                Maximum contribution: 15 points when the recorded brands are the same.
                            </small>

                        </div>

                    </div>


                    <div class="score-factor">

                        <div class="factor-icon">4</div>

                        <div class="factor-body">

                            <div class="factor-title-row">
                                <strong>Color</strong>
                                <b><?= $colorPoints > 0 ? '+'.$colorPoints : '+0' ?></b>
                            </div>

                            <p><?= h($colorReason) ?></p>

                            <small>
                                Maximum contribution: 10 points when the recorded colors are the same.
                            </small>

                        </div>

                    </div>


                    <div class="score-factor score-factor-wide">

                        <div class="factor-icon">5</div>

                        <div class="factor-body">

                            <div class="factor-title-row">
                                <strong>Description &amp; distinguishing features</strong>
                                <b><?= $detailPoints > 0 ? '+'.$detailPoints : '+0' ?></b>
                            </div>

                            <p><?= h($detailReason) ?></p>

                            <small>
                                Shared descriptive terms can contribute up to 20 points
                                (4 points per shared term, capped at 20).
                            </small>

                        </div>

                    </div>

                </div>


                <div class="score-total-row">

                    <div>
                        <span>Score composition</span>
                        <strong>
                            <?= $namePoints ?> + <?= $categoryPoints ?> + <?= $brandPoints ?> + <?= $colorPoints ?> + <?= $detailPoints ?>
                            = <?= $calculatedScore ?>%
                        </strong>
                    </div>

                    <div class="score-threshold">
                        <span>System threshold</span>
                        <strong>45%</strong>
                        <small>
                            Scores at or above the threshold become potential matches for admin review.
                        </small>
                    </div>

                </div>


                <div class="context-checks">

                    <div class="context-checks-head">
                        <div>
                            <strong>Additional evidence for the administrator</strong>
                            <span>These checks help your decision but do not add points to the current score.</span>
                        </div>
                    </div>

                    <div class="context-check-grid">

                        <?php foreach($contextChecks as $check): ?>

                            <div class="context-check">

                                <span class="<?= ($check['label']==='Location proximity' && $locationMatch) || ($check['label']==='Date proximity' && !empty($m['lost_event_date']) && !empty($m['found_event_date'])) ? 'positive' : 'neutral' ?>">
                                    <?= ($check['label']==='Location proximity' && $locationMatch) || ($check['label']==='Date proximity' && !empty($m['lost_event_date']) && !empty($m['found_event_date'])) ? '✓' : '•' ?>
                                </span>

                                <div>
                                    <strong><?= h($check['label']) ?></strong>
                                    <p><?= h($check['value']) ?></p>
                                    <small><?= h($check['note']) ?></small>
                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>


                <div class="admin-decision-tip">

                    <span>ADMIN TIP</span>

                    <p>
                        A high percentage does not automatically prove ownership.
                        Before verifying, make sure the Found item is consistent with the
                        Lost Report and that the distinguishing details make sense.
                        If the records do not appear to describe the same physical item,
                        dismiss the match even if the score passes the threshold.
                    </p>

                </div>

            </div>


            <!-- ACTIONS -->

            <?php if($status==='pending'): ?>

                <div class="match-actions">

                    <details class="match-dismiss">

                        <summary>
                            Dismiss (Invalid Match)
                        </summary>

                        <form method="post">

                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="match_decision">
                            <input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>">
                            <input type="hidden" name="decision" value="reject">

                            <textarea
                                name="remarks"
                                placeholder="Optional reason for dismissing this automated potential match"
                            ></textarea>

                            <button class="btn small danger" type="submit">
                                Confirm Dismissal
                            </button>

                        </form>

                    </details>

                    <details class="match-approve-details">

                        <summary class="match-verify-button">
                            ✓ Approve Potential Match
                        </summary>

                        <form method="post" class="match-schedule-form">

                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="match_decision">
                            <input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>">
                            <input type="hidden" name="decision" value="confirm">

                            <div class="match-schedule-heading">
                                <strong>Approve the potential Lost ↔ Found match</strong>
                                <span>
                                    This does not mean ownership is confirmed yet. It tells both users that a potential match was found. No student confirmation is required; release the in-person checking schedule next.
                                </span>
                            </div>

                            <label class="match-remarks-field">
                                Admin remarks
                                <textarea name="remarks" placeholder="Optional notes about why this potential match was approved."></textarea>
                            </label>

                            <button class="match-confirm-schedule-button" type="submit" onclick="return confirm('Approve this potential Lost &amp; Found match? The users will be notified and you can release the in-person checking schedule next.');">
                                ✓ Approve Potential Match &amp; Notify Users
                            </button>

                        </form>

                    </details>

                </div>

            <?php elseif($status==='confirmed' && ($m['verification_outcome']??'')!=='completed' && empty($m['handover_at'])): ?>

                <?php if(!empty($m['verification_outcome']) && $m['verification_outcome']==='reschedule'): ?>
                    <div class="match-reviewed-note verified-note">
                        ↻ <strong>Verification Needs Rescheduling</strong>
                        <br>The previous in-person check could not be completed. Release a new checking schedule below.
                        <?php if(!empty($m['verification_outcome_reason'])): ?>
                            <br><strong>Reason:</strong> <?= h($m['verification_outcome_reason']) ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if(empty($m['found_turnover_deadline'])): ?>

                    <div class="match-reviewed-note verified-note">
                        ✓ <strong>Potential match approved.</strong> The Found Reporter must turn the physical item over to the administrator first.
                        <br>The lost-item owner will receive a verification schedule only after the item is physically received.
                    </div>

                    <details class="match-approve-details" open>
                        <summary class="match-verify-button">📦 Set Found Item Turnover Schedule</summary>
                        <form method="post" class="match-schedule-form">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="match_turnover_schedule">
                            <input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>">

                            <div class="match-schedule-heading">
                                <strong>Tell the Found Reporter when to turn over the item</strong>
                                <span>The finder may physically submit the item to the administrator during the allowed date range below. The finder does not need to attend the lost owner’s verification appointment.</span>
                            </div>

                            <div class="match-schedule-grid">
                                <label>
                                    Start date
                                    <input type="date" name="found_turnover_start_date" value="<?= h(date('Y-m-d')) ?>" min="<?= h(date('Y-m-d')) ?>" required>
                                </label>
                                <label>
                                    Deadline
                                    <input type="date" name="found_turnover_deadline" value="<?= h(date('Y-m-d',strtotime('+1 day'))) ?>" min="<?= h(date('Y-m-d')) ?>" required>
                                </label>
                                <label>
                                    Turnover location
                                    <input type="text" name="found_turnover_location" value="Office of Mapúa, 1st Floor" required>
                                </label>
                            </div>

                            <label class="match-remarks-field">
                                Admin remarks / turnover instructions
                                <textarea name="remarks" placeholder="Example: Please bring the item to the Lost &amp; Found Office between the start date and deadline."></textarea>
                            </label>

                            <button class="match-confirm-schedule-button" type="submit">
                                ✓ Set Turnover Schedule &amp; Notify Found Reporter
                            </button>
                        </form>
                    </details>

                <?php elseif(empty($m['found_handover_at'])): ?>

                    <div class="match-reviewed-note verified-note">
                        ⏳ <strong>Waiting for Found Item Turnover</strong>
                        <br>The Found Reporter may turn over the physical item from <strong><?= h(date('F j, Y',strtotime($m['found_turnover_start_date']))) ?></strong> through <strong><?= h(date('F j, Y',strtotime($m['found_turnover_deadline']))) ?></strong>.
                        <br><strong>Turnover location:</strong> <?= h($m['found_turnover_location']?:'Not specified') ?>
                        <br>Do not schedule the lost-item owner yet. Record the turnover below when the item is physically received.
                    </div>

                    <details class="match-approve-details" open>
                        <summary class="match-verify-button">✓ Confirm Found Item Received</summary>
                        <form method="post" class="match-schedule-form">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="found_match_handover">
                            <input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>">
                            <div class="match-schedule-heading">
                                <strong>Record physical turnover</strong>
                                <span>Use this only after the Found Reporter has actually handed the item to the administrator.</span>
                            </div>
                            <label class="match-remarks-field">
                                Turnover notes (optional)
                                <textarea name="found_handover_notes" placeholder="Record when/how the item was received or any custody notes."></textarea>
                            </label>
                            <button class="match-confirm-schedule-button" type="submit" onclick="return confirm('Confirm that the Found Reporter physically turned over the item to the administrator?');">
                                ✓ Confirm Item Received
                            </button>
                        </form>
                    </details>

                <?php elseif(empty($m['verification_date'])): ?>

                    <div class="match-reviewed-note verified-note">
                        ✓ <strong>Found Item Received</strong> on <?= h(date('F j, Y g:i A',strtotime($m['found_handover_at']))) ?>.
                        <br>Now schedule the <strong>lost-item owner</strong> for in-person ownership verification. The Found Reporter does not need to attend.
                    </div>

                    <details class="match-approve-details" open>
                        <summary class="match-verify-button">📅 Schedule Lost Owner Verification</summary>
                        <form method="post" class="match-schedule-form">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="match_schedule">
                            <input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>">

                            <div class="match-schedule-heading">
                                <strong>Set the lost-item owner’s verification appointment</strong>
                                <span>This appointment is for the lost-item owner only. The available date/time should be based on when the found item was physically received.</span>
                            </div>

                            <div class="match-schedule-grid">
                                <label>
                                    Owner visit date
                                    <input type="date" name="verification_date" min="<?= h(date('Y-m-d',strtotime($m['found_handover_at']))) ?>" value="<?= h(date('Y-m-d',strtotime('+1 day'))) ?>" required>
                                </label>
                                <label>
                                    Owner visit time
                                    <input type="time" name="verification_time" value="10:00" required>
                                </label>
                                <label>
                                    Verification deadline
                                    <input type="datetime-local" name="verification_deadline" value="<?= h(date('Y-m-d\TH:i',strtotime('+1 day 17:00'))) ?>" required>
                                </label>
                                <label class="match-schedule-location">
                                    Verification location
                                    <input type="text" name="verification_location" value="Office of Mapúa, 1st Floor" placeholder="e.g. Office of Mapúa, 1st Floor" required>
                                </label>
                            </div>

                            <label class="match-remarks-field">
                                Admin remarks
                                <textarea name="remarks" placeholder="Optional notes for the owner’s verification appointment."></textarea>
                            </label>

                            <button class="match-confirm-schedule-button" type="submit">
                                ✓ Schedule Owner Verification &amp; Notify Lost Owner
                            </button>
                        </form>
                    </details>

                <?php else: ?>

                    <div class="match-reviewed-note verified-note">
                        ✓ <strong>Owner Verification Scheduled</strong>
                        <br><strong><?= h(date('F j, Y',strtotime($m['verification_date']))) ?> at <?= h(date('g:i A',strtotime($m['verification_time'] ?? ''))) ?></strong>
                        <?php if(!empty($m['verification_location'])): ?> • <?= h($m['verification_location']) ?><?php endif; ?>
                        <br>Found item was received first. The Found Reporter does not need to attend. Record the final result after the lost-item owner’s in-person verification.
                    </div>

                    <?php if(!empty($m['found_handover_at']) && !empty($m['verification_date'])): ?>

                    <details class="match-approve-details" style="margin-top:12px">
                        <summary class="match-verify-button">✓ Record In-Person Verification Result</summary>

                        <form method="post" class="match-schedule-form">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="match_verification_outcome">
                            <input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>">

                            <div class="match-schedule-heading">
                                <strong>What happened during the physical verification?</strong>
                                <span>Select the final result after checking the item, the student's proof, and the relevant identifying details in person.</span>
                            </div>

                            <label>
                                Verification outcome <span style="color:#bd1e40">*</span>
                                <select name="verification_outcome" required>
                                    <option value="">Select outcome</option>
                                    <option value="completed">✓ Completed — Item Confirmed &amp; Returned</option>
                                    <option value="not_match">× Not a Match — Reopen Found Item</option>
                                    <option value="reschedule">↻ Unable to Verify — Reschedule</option>
                                </select>
                            </label>

                            <label class="match-remarks-field">
                                Reason / verification result <span style="color:#bd1e40">*</span>
                                <textarea name="verification_outcome_reason" required placeholder="Explain what was verified in person and why this outcome was selected. Example: Student presented a valid university ID and ownership proof; unique identifying marks matched the item."></textarea>
                            </label>

                            <label class="match-remarks-field">
                                Evidence verified in person
                                <textarea name="handover_evidence" placeholder="Required only for Completed. Example: Valid Mapúa/university ID verified; ownership proof reviewed; identifying marks matched. Do not record the full ID number."></textarea>
                            </label>

                            <label class="match-remarks-field">
                                Additional admin notes (optional)
                                <textarea name="handover_notes" placeholder="Optional notes about the physical checking, item condition, or why a reschedule was needed."></textarea>
                            </label>

                            <button class="match-confirm-schedule-button" type="submit" onclick="return confirm('Record this in-person verification result? If you select Not a Match, the found item will be reopened in Search &amp; Browse. If you select Completed, both reports will be marked as returned.');">
                                ✓ Save Verification Result
                            </button>
                        </form>
                    </details>

                    <?php endif; ?>

                <?php endif; ?>

            <?php elseif($status==='rejected' && ($m['verification_outcome']??'')==='not_match'): ?>

                <div class="match-reviewed-note dismissed-note">
                    × <strong>Not a Match — Found Item Reopened</strong>
                    <br>The physical verification showed that the found item was not the lost student's item. The found item is open again in Search &amp; Browse.
                    <?php if(!empty($m['verification_outcome_reason'])): ?>
                        <br><strong>Reason:</strong> <?= h($m['verification_outcome_reason']) ?>
                    <?php endif; ?>
                </div>

            <?php elseif(($m['verification_outcome']??'')==='completed' || !empty($m['handover_at'])): ?>

                <div class="match-reviewed-note returned-note">
                    ✓ <strong>Item Handed Over / Returned — Match Completed</strong>
                    <br>Successfully verified in person and released to the lost-item owner.
                    <br><strong>Completed:</strong> <?= h(date('F j, Y g:i A',strtotime($m['handover_at']))) ?>
                    <?php if(!empty($m['verification_location'])): ?><br><strong>Release location:</strong> <?= h($m['verification_location']) ?><?php endif; ?>
                    <?php if(!empty($m['verification_outcome_reason'])): ?><br><strong>Verification result:</strong> <?= h($m['verification_outcome_reason']) ?><?php endif; ?>
                    <?php if(!empty($m['handover_evidence'])): ?><br><strong>Evidence verified in person:</strong> <?= h($m['handover_evidence']) ?><?php endif; ?>
                    <?php if(!empty($m['handover_notes'])): ?><br><strong>Admin notes:</strong> <?= h($m['handover_notes']) ?><?php endif; ?>
                </div>

            <?php else: ?>

                <div class="match-reviewed-note dismissed-note">
                    × This potential match was dismissed as invalid.
                    <?php if(!empty($m['admin_remarks'])): ?>
                        <br><strong>Reason:</strong> <?= h($m['admin_remarks']) ?>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

        </article>

    <?php endforeach; ?>


    <?php if(!$visibleMatches): ?>

        <div class="matches-empty">

            <div>
                ✨
            </div>

            <h2>
                No matches in this view
            </h2>

            <p>
                <?php if($matchFilter==='pending'): ?>

                    There are no potential matches currently awaiting admin verification.

                <?php elseif($matchFilter==='confirmed'): ?>

                    No approved matches are currently waiting for checking or a new schedule.

                <?php elseif($matchFilter==='completed'): ?>

                    No completed Lost/Found matches have been recorded yet.

                <?php elseif($matchFilter==='not_match'): ?>

                    No potential matches have been closed as Not a Match yet.

                <?php elseif($matchFilter==='rejected'): ?>

                    No dismissed potential matches have been recorded yet.

                <?php else: ?>

                    No potential matches have been generated yet.
                    Potential matches are created automatically when
                    eligible Lost and Found reports are available.

                <?php endif; ?>
            </p>

        </div>

    <?php endif; ?>

    </div>

</div>


<?php elseif($tab==='user_history'): ?>

<?php
/* =========================================================
   USER PROPERTY HISTORY
   Opened from the Messages user name / View All Reports & Claims.
   Shows every report submitted by the selected student plus every
   ownership claim they registered, including approved/returned items.
========================================================= */
$userHistoryId=(int)($_GET['user_id']??0);
$userHistoryUser=null;
$userHistoryReports=[];
$userHistoryClaims=[];

if($userHistoryId>0){
    $u=$pdo->prepare("SELECT id,full_name,email,university_id FROM users WHERE id=? AND role='user' LIMIT 1");
    $u->execute([$userHistoryId]);
    $userHistoryUser=$u->fetch() ?: null;

    if($userHistoryUser){
        $r=$pdo->prepare("
            SELECT
                r.*,
                i.item_name,i.category,i.description,i.color,i.brand,i.identifying_features,i.photo_path
            FROM reports r
            JOIN items i ON i.id=r.item_id
            WHERE r.user_id=?
            ORDER BY r.created_at DESC,r.id DESC
        ");
        $r->execute([$userHistoryId]);
        $userHistoryReports=$r->fetchAll();

        $c=$pdo->prepare("
            SELECT
                c.*,
                i.item_name,i.category,i.description,i.color,i.brand,i.identifying_features,i.photo_path,
                r.id AS found_report_id,
                r.item_status,
                r.report_status AS found_report_status,
                r.campus_location AS found_campus_location,
                r.building AS found_building,
                r.floor AS found_floor,
                r.specific_area AS found_specific_area,
                r.storage_location,
                r.verification_date,
                r.verification_time,
                r.verification_deadline,
                r.verification_location
            FROM claims c
            JOIN items i ON i.id=c.item_id
            LEFT JOIN reports r ON r.id=(
                SELECT r2.id FROM reports r2
                WHERE r2.item_id=c.item_id AND r2.report_type='found'
                ORDER BY r2.id DESC LIMIT 1
            )
            WHERE c.claimant_user_id=?
            ORDER BY c.created_at DESC,c.id DESC
        ");
        $c->execute([$userHistoryId]);
        $userHistoryClaims=$c->fetchAll();
    }
}
?>

<?php if($userHistoryId>0): ?>
<style>
.user-history-page{padding-bottom:70px}
.user-history-head{display:flex;justify-content:space-between;align-items:flex-end;gap:24px;margin-bottom:20px}
.user-history-kicker{display:inline-flex;padding:5px 9px;border-radius:999px;background:#eeeaff;color:#20242a;font-size:9px;font-weight:900;letter-spacing:.7px;text-transform:uppercase}
.user-history-head h1{margin:8px 0 4px;color:#35404a;font-size:29px;line-height:1.15;letter-spacing:-.03em}
.user-history-head p{margin:0;color:#20242a;font-size:11px}
.user-history-back{padding:9px 12px;border:1px solid #dce2eb;border-radius:9px;background:#fff;color:#20242a;text-decoration:none;font-size:10px;font-weight:900}
.user-history-back:hover{background:#f7f8fa}
.user-history-identity{display:flex;align-items:center;gap:12px;margin-bottom:18px;padding:14px 16px;background:#fff;border:1px solid #e2e7ee;border-radius:13px;box-shadow:0 5px 18px rgba(27,42,61,.04)}
.user-history-avatar{width:42px;height:42px;border-radius:50%;display:grid;place-items:center;background:#eeeaff;color:#20242a;font-size:13px;font-weight:900}
.user-history-identity strong{display:block;color:#35404a;font-size:13px}
.user-history-identity span{color:#8190a2;font-size:9px}
.user-history-grid{display:grid;gap:18px}
.user-history-section{background:#fff;border:1px solid #e1e7ee;border-radius:16px;overflow:hidden;box-shadow:0 5px 18px rgba(27,42,61,.04)}
.user-history-section-head{display:flex;align-items:center;justify-content:space-between;padding:14px 17px;background:#fafbfd;border-bottom:1px solid #e7ebf0}
.user-history-section-head h2{margin:0;color:#35404a;font-size:13px}
.user-history-count{padding:4px 8px;border-radius:999px;background:#eef1ff;color:#20242a;font-size:9px;font-weight:900}
.user-history-card-list{display:grid;gap:10px;padding:13px}
.user-history-card{display:grid;grid-template-columns:76px minmax(0,1fr) auto;gap:13px;align-items:start;padding:12px;border:1px solid #e3e8ef;border-radius:12px;background:#fff}
.user-history-photo{width:76px;height:76px;overflow:hidden;border-radius:10px;background:#f0f3f6;border:1px solid #e0e5eb;display:grid;place-items:center;color:#9ba7b5;font-size:20px}
.user-history-photo img{width:100%;height:100%;object-fit:cover;display:block}
.user-history-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:5px}
.user-history-id{padding:4px 7px;border-radius:5px;background:#f1f3ff;color:#20242a;font-size:8px;font-weight:900}
.user-history-type{padding:4px 7px;border-radius:5px;font-size:8px;font-weight:900}
.user-history-type.lost{background:#fff0e8;color:#d35b16}.user-history-type.found{background:#e8f7ef;color:#0b805b}
.user-history-card h3{margin:0;color:#35404a;font-size:14px;line-height:1.35}
.user-history-card p{margin:4px 0 0;color:#20242a;font-size:10px;line-height:1.55}
.user-history-location{margin-top:7px;color:#20242a;font-size:9px}
.user-history-status{align-self:start;padding:6px 9px;border-radius:999px;background:#f1f3f5;color:#20242a;font-size:8px;font-weight:900;white-space:nowrap}
.user-history-status.approved,.user-history-status.returned{background:#e3f8ef;color:#087b59}
.user-history-status.pending{background:#fff3cf;color:#9b6500}
.user-history-status.rejected{background:#ffe9ee;color:#bd1e40}
.user-history-claim-card{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;padding:13px;border:1px solid #dfe6ee;border-radius:12px;background:#fff}
.user-history-claim-main h3{margin:0;color:#35404a;font-size:14px}
.user-history-claim-main p{margin:4px 0 0;color:#20242a;font-size:10px;line-height:1.55}
.user-history-proof{margin-top:9px;padding:9px 10px;border-radius:9px;background:#f7f9fc;border:1px solid #e2e7ee;color:#20242a;font-size:10px;line-height:1.55}
.user-history-proof strong{display:block;color:#35404a;margin-bottom:3px;font-size:9px}
.user-history-release{margin-top:9px;padding:10px;border-radius:9px;background:#e7faf2;border:1px solid #bcefdc;color:#087b59;font-size:10px;line-height:1.55}
.user-history-proof-photo{display:inline-flex;align-items:center;gap:5px;margin-top:8px;padding:5px 8px;border-radius:7px;background:#eef1ff;color:#20242a;text-decoration:none;font-size:8px;font-weight:900}
.user-history-empty{padding:28px 18px;text-align:center;color:#8794a4;font-size:10px}
.user-history-empty strong{display:block;color:#35404a;font-size:11px;margin-bottom:4px}
@media(max-width:700px){.user-history-head{align-items:flex-start;flex-direction:column}.user-history-card{grid-template-columns:58px minmax(0,1fr)}.user-history-photo{width:58px;height:58px}.user-history-status{grid-column:2;justify-self:start}.user-history-claim-card{grid-template-columns:1fr}}
</style>

<div class="container user-history-page">
    <?php if($userHistoryUser): ?>
        <?php
        $historyInitials='';
        foreach(preg_split('/\s+/',trim((string)$userHistoryUser['full_name'])) as $part){
            if($part!=='')$historyInitials.=strtoupper(substr($part,0,1));
        }
        $historyInitials=substr($historyInitials?:'U',0,2);
        ?>
        <div class="user-history-head">
            <div>
                <span class="user-history-kicker">STUDENT PROPERTY HISTORY</span>
                <h1><?= h($userHistoryUser['full_name']) ?></h1>
                <p>All reports, found items claimed, approved releases, and returned items associated with this student.</p>
            </div>
            <a class="user-history-back" href="admin.php?tab=messages">← Back to Messages</a>
        </div>

        <div class="user-history-identity">
            <div class="user-history-avatar"><?= h($historyInitials) ?></div>
            <div>
                <strong><?= h($userHistoryUser['full_name']) ?></strong>
                <span><?= h($userHistoryUser['university_id'] ?: 'No university ID') ?> • <?= h($userHistoryUser['email']) ?></span>
            </div>
        </div>

        <div class="user-history-grid">
            <section class="user-history-section">
                <header class="user-history-section-head">
                    <h2>Reports Submitted by This Student</h2>
                    <span class="user-history-count"><?= count($userHistoryReports) ?></span>
                </header>
                <?php if($userHistoryReports): ?>
                    <div class="user-history-card-list">
                        <?php foreach($userHistoryReports as $r): ?>
                            <?php
                            $reportType=(string)($r['report_type']??'');
                            $status=(string)($r['item_status']??'open');
                            $reportStatus=(string)($r['report_status']??'');
                            $photo=trim((string)($r['photo_path']??''));
                            $location=trim(implode(', ',array_filter([
                                $r['campus_location']??'',
                                $r['building']??'',
                                !empty($r['floor'])?'Floor '.$r['floor']:'',
                                $r['specific_area']??''
                            ])));
                            ?>
                            <article class="user-history-card">
                                <div class="user-history-photo">
                                    <?php if($photo): ?><img src="<?= h($photo) ?>" alt="<?= h($r['item_name']) ?>"><?php else: ?>▧<?php endif; ?>
                                </div>
                                <div>
                                    <div class="user-history-meta">
                                        <span class="user-history-id">RPT-<?= str_pad((string)$r['id'],3,'0',STR_PAD_LEFT) ?></span>
                                        <span class="user-history-type <?= $reportType==='lost'?'lost':'found' ?>"><?= strtoupper($reportType) ?></span>
                                    </div>
                                    <h3><?= h($r['item_name']) ?></h3>
                                    <p><?= h($r['description'] ?: 'No description provided.') ?></p>
                                    <?php if($location): ?><div class="user-history-location">⌖ <?= h($location) ?></div><?php endif; ?>
                                </div>
                                <span class="user-history-status <?= h($status) ?>"><?= h(status_label($status)) ?></span>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="user-history-empty"><strong>No reports submitted</strong>No lost or found reports are associated with this student.</div>
                <?php endif; ?>
            </section>

            <section class="user-history-section">
                <header class="user-history-section-head">
                    <h2>Ownership Claims Registered by This Student</h2>
                    <span class="user-history-count"><?= count($userHistoryClaims) ?></span>
                </header>
                <?php if($userHistoryClaims): ?>
                    <div class="user-history-card-list">
                        <?php foreach($userHistoryClaims as $c): ?>
                            <?php
                            $claimStatus=(string)($c['claim_status']??'pending');
                            $claimItemStatus=(string)($c['item_status']??'open');
                            $claimLocation=trim(implode(', ',array_filter([
                                $c['found_campus_location']??'',
                                $c['found_building']??'',
                                !empty($c['found_floor'])?'Floor '.$c['found_floor']:'',
                                $c['found_specific_area']??''
                            ])));
                            $proofPhoto=trim((string)($c['proof_photo_path']??''));
                            ?>
                            <article class="user-history-claim-card">
                                <div class="user-history-claim-main">
                                    <div class="user-history-meta">
                                        <span class="user-history-id">CLM-<?= str_pad((string)$c['id'],3,'0',STR_PAD_LEFT) ?></span>
                                    </div>
                                    <h3><?= h($c['item_name']) ?></h3>
                                    <p>Item ID: ITM-<?= str_pad((string)$c['item_id'],3,'0',STR_PAD_LEFT) ?><?php if($claimLocation): ?> • Found: <?= h($claimLocation) ?><?php endif; ?></p>
                                    <div class="user-history-proof">
                                        <strong>Ownership Proof</strong>
                                        <?= h($c['ownership_proof'] ?? $c['proof'] ?? 'No proof description provided.') ?>
                                        <?php if(!empty($c['identifying_information'])): ?><br><br><strong>Identifying Information</strong><?= h($c['identifying_information']) ?><?php endif; ?>
                                        <?php if($proofPhoto): ?><a class="user-history-proof-photo" href="<?= h($proofPhoto) ?>" target="_blank" rel="noopener">▧ View Uploaded Proof Photo</a><?php endif; ?>
                                    </div>
                                    <?php if($claimStatus==='approved' && (!empty($c['verification_date']) || !empty($c['verification_time']) || !empty($c['verification_location']))): ?>
                                        <div class="user-history-release">
                                            ✓ Release scheduled<?php if($c['verification_date']): ?> • <?= h($c['verification_date']) ?><?php endif; ?><?php if($c['verification_time']): ?> at <?= h($c['verification_time']) ?><?php endif; ?><?php if($c['verification_location']): ?> • <?= h($c['verification_location']) ?><?php endif; ?>
                                            <?php if(!empty($c['handover_at']) || $claimItemStatus==='returned'): ?><br><strong>Item Handed Over — Returned</strong><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <span class="user-history-status <?= h($claimStatus==='approved' && ($claimItemStatus==='returned'||!empty($c['handover_at']))?'returned':$claimStatus) ?>">
                                    <?= $claimStatus==='approved' && ($claimItemStatus==='returned'||!empty($c['handover_at'])) ? 'RETURNED / COMPLETED' : h(ucwords(str_replace('_',' ',$claimStatus))) ?>
                                </span>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="user-history-empty"><strong>No ownership claims</strong>This student has not registered an ownership claim.</div>
                <?php endif; ?>
            </section>
        </div>
    <?php else: ?>
        <div class="user-history-empty" style="margin-top:30px;background:#fff;border:1px solid #e1e7ee;border-radius:16px">
            <strong>Student not found</strong>The selected student account could not be found.
        </div>
    <?php endif; ?>
</div>

<?php else: ?>

<style>
/* =========================================================
   ADMIN CLAIMS — OWNERSHIP CLAIMS & HANDOVER WORKSPACE
   Inspired by the supplied Claims / Handover reference UI.
   ========================================================= */

.admin-claims-page{
    padding-bottom:70px;
}

.admin-claims-head{
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:28px;
    margin-bottom:22px;
}

.admin-claims-title h1{
    margin:0;
    color:#35404a;
    font-size:31px;
    line-height:1.12;
    letter-spacing:-.035em;
}

.admin-claims-title p{
    margin:7px 0 0;
    color:#20242a;
    font-size:12px;
    line-height:1.5;
}

.admin-claims-summary{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    justify-content:flex-end;
}

.admin-claims-stat{
    min-width:104px;
    padding:10px 13px;
    background:#fff;
    border:1px solid #e1e7ef;
    border-radius:11px;
    box-shadow:0 3px 12px rgba(25,39,61,.035);
}

.admin-claims-stat small{
    display:block;
    margin-bottom:3px;
    color:#20242a;
    font-size:8px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
}

.admin-claims-stat strong{
    color:#35404a;
    font-size:18px;
    line-height:1;
}

.admin-claims-stat.pending strong{color:#a66a00}
.admin-claims-stat.approved strong{color:#07815c}
.admin-claims-stat.rejected strong{color:#c21e43}

.admin-claims-alert{
    display:flex;
    align-items:center;
    gap:13px;
    margin-bottom:18px;
    padding:13px 16px;
    border:1px solid #dfc9ff;
    border-radius:13px;
    background:linear-gradient(90deg,#fbf7ff,#fff);
    box-shadow:0 4px 15px rgba(89,53,160,.04);
}

.admin-claims-alert-icon{
    width:34px;
    height:34px;
    flex:0 0 34px;
    display:grid;
    place-items:center;
    border-radius:9px;
    background:#eee3ff;
    color:#20242a;
    font-size:16px;
}

.admin-claims-alert strong{
    display:block;
    color:#20242a;
    font-size:11px;
    letter-spacing:.04em;
}

.admin-claims-alert span{
    display:block;
    margin-top:2px;
    color:#20242a;
    font-size:10px;
}

.admin-claim-groups{
    display:grid;
    gap:18px;
}

.admin-claim-item{
    overflow:hidden;
    background:#fff;
    border:1px solid #dfe6ee;
    border-radius:18px;
    box-shadow:0 4px 17px rgba(27,42,61,.045);
}

.admin-claim-item.multiple{
    border-color:#f8e9ec;
    box-shadow:0 5px 20px rgba(113,57,220,.055);
}

.admin-claim-item-header{
    display:grid;
    grid-template-columns:64px minmax(0,1fr) auto;
    align-items:center;
    gap:15px;
    padding:18px 24px;
    border-bottom:1px solid #e7ebf0;
    background:#fbfcfe;
}

.admin-claim-item-photo{
    width:64px;
    height:64px;
    overflow:hidden;
    display:grid;
    place-items:center;
    border:1px solid #e0e5eb;
    border-radius:11px;
    background:#f0f3f6;
}

.admin-claim-item-photo img{
    width:100%;
    height:100%;
    display:block;
    object-fit:cover;
}

.admin-claim-item-photo span{
    color:#9ba7b5;
    font-size:23px;
}

.admin-claim-item-main{
    min-width:0;
}

.admin-claim-item-meta{
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:6px;
    margin-bottom:5px;
}

.admin-claim-item-id{
    display:inline-flex;
    padding:4px 8px;
    border:1px solid #dce3eb;
    border-radius:5px;
    background:#fff;
    color:#20242a;
    font-size:9px;
    font-weight:900;
    letter-spacing:.04em;
}

.admin-claim-item-category{
    color:#20242a;
    font-size:10px;
}

.admin-claim-item-category::before{
    content:'•';
    margin-right:6px;
}

.admin-claim-item-main h2{
    margin:0;
    color:#35404a;
    font-size:16px;
    line-height:1.3;
    letter-spacing:-.01em;
}

.admin-claim-item-custody{
    margin-top:4px;
    color:#20242a;
    font-size:10px;
    line-height:1.45;
}

.admin-claim-item-custody strong{
    color:#35404a;
}

.admin-claim-item-actions{
    display:flex;
    align-items:center;
    gap:7px;
    flex-wrap:wrap;
    justify-content:flex-end;
}

.admin-release-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:6px 12px;
    border:1px solid #cfd9ff;
    border-radius:999px;
    background:#f1f4ff;
    color:#20242a;
    font-size:9px;
    font-weight:900;
    white-space:nowrap;
}

.admin-handover-btn{
    display:inline-flex;
    align-items:center;
    gap:5px;
    min-height:29px;
    padding:6px 13px;
    border:0;
    border-radius:999px;
    background:#07996c;
    color:#fff;
    font-size:10px;
    font-weight:900;
    cursor:pointer;
}

.admin-release-completed{
    background:#d9f7e9!important;
    border-color:#b9ead4!important;
    color:#087957!important;
}

.admin-handover-btn:hover{
    background:#067e5a;
}

.admin-handover-form{
    margin:0;
    padding:0;
}

.admin-handover-done{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:29px;
    padding:6px 13px;
    border-radius:999px;
    background:#d8f8e9;
    color:#087a55;
    font-size:10px;
    font-weight:900;
    white-space:nowrap;
}

.admin-claim-item-body{
    padding:22px 24px 24px;
}

.admin-claim-section-label{
    margin:0 0 12px;
    color:#20242a;
    font-size:10px;
    font-weight:900;
    letter-spacing:.05em;
    text-transform:uppercase;
}

.admin-claim-cards{
    display:grid;
    grid-template-columns:1fr;
    gap:18px;
}

/* Keep each claimant readable: the comparison belongs to the same
   claimant card, so competing claims are stacked instead of squeezed
   into narrow side-by-side columns. */
.admin-claim-item.multiple .admin-claim-cards{
    grid-template-columns:1fr;
}

.admin-claim-card{
    width:100%;
}

.admin-claim-card{
    min-width:0;
    padding:16px;
    border:1px solid #dfe6ee;
    border-radius:15px;
    background:#fff;
    box-shadow:0 2px 9px rgba(31,45,63,.025);
}

.admin-claim-card.pending{
    background:#fff;
}

.admin-claim-card.approved{
    border-color:#54dfb1;
    background:#f5fffb;
}

.admin-claim-card.rejected{
    background:#fffafb;
    border-color:#f1cbd3;
}

.admin-claim-card-top{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:10px;
    margin-bottom:11px;
}

.admin-claim-person{
    min-width:0;
}

.admin-claim-id-name{
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:8px;
}

.admin-claim-id{
    color:#20242a;
    font-size:9px;
    font-weight:900;
    letter-spacing:.04em;
}

.admin-claim-person strong{
    color:#35404a;
    font-size:12px;
}

.admin-claim-contact{
    margin-top:5px;
    color:#20242a;
    font-size:9px;
    line-height:1.55;
}

.admin-claim-status{
    display:inline-flex;
    flex:0 0 auto;
    align-items:center;
    padding:5px 9px;
    border-radius:999px;
    font-size:9px;
    font-weight:900;
    white-space:nowrap;
}

.admin-claim-status.pending{
    background:#fff0c7;
    color:#a26700;
}

.admin-claim-status.approved{
    background:#d7f8e9;
    color:#087b58;
}

.admin-claim-status.rejected{
    background:#ffe7ed;
    color:#c21f43;
}

.admin-claim-date{
    margin-bottom:10px;
    color:#20242a;
    font-size:9px;
}

.admin-claim-evidence{
    margin-top:10px;
    padding:12px;
    border:1px solid #dfe6ed;
    border-radius:11px;
    background:#f8fafc;
}

.admin-claim-proof-photo{
    margin-top:12px;
    padding-top:12px;
    border-top:1px solid #e4e9f0;
}
.admin-claim-proof-photo-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:9px;
}
.admin-claim-proof-photo-head strong{
    color:#35404a;
    font-size:11px;
}
.admin-claim-proof-photo-head a{
    color:#20242a;
    font-size:10px;
    font-weight:800;
    text-decoration:none;
}
.admin-claim-proof-photo-link{
    display:block;
    width:100%;
    max-width:320px;
    height:190px;
    overflow:hidden;
    border:1px solid #dce3ec;
    border-radius:12px;
    background:#f4f6f9;
}
.admin-claim-proof-photo-link img{
    display:block;
    width:100%;
    height:100%;
    object-fit:cover;
}
.admin-claim-proof-missing{
    margin-top:12px;
    padding:10px 12px;
    border-radius:9px;
    background:#fff6e8;
    color:#986400;
    font-size:10px;
    font-weight:700;
}

.admin-claim-evidence strong{
    display:block;
    margin-bottom:5px;
    color:#35404a;
    font-size:10px;
}

.admin-claim-evidence p{
    margin:0;
    color:#20242a;
    font-size:10px;
    line-height:1.6;
    white-space:pre-wrap;
    overflow-wrap:anywhere;
}

.admin-claim-detail{
    margin-top:9px;
    padding-top:9px;
    border-top:1px solid #e5eaf0;
}

.admin-claim-detail strong{
    display:block;
    margin-bottom:3px;
    color:#35404a;
    font-size:9px;
}

.admin-claim-detail span{
    display:block;
    color:#20242a;
    font-size:9px;
    line-height:1.55;
    white-space:pre-wrap;
    overflow-wrap:anywhere;
}

.admin-release-box{
    margin-top:12px;
    padding:12px;
    border-radius:11px;
    background:#d5f9e8;
    border:1px solid #b6efd6;
}

.admin-release-box strong{
    display:block;
    color:#145a46;
    font-size:10px;
    line-height:1.5;
}

.admin-release-box strong::before{
    content:'✓ ';
}

.admin-release-box span{
    display:block;
    margin-top:4px;
    color:#326c5b;
    font-size:9px;
    line-height:1.55;
}

.admin-release-code{
    display:inline-flex;
    margin-top:7px;
    padding:3px 6px;
    border-radius:5px;
    background:#fff;
    color:#157457;
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    font-size:9px;
    font-weight:900;
}

.admin-claim-rejection{
    margin-top:11px;
    padding:10px;
    border-radius:9px;
    background:#fff1f4;
    color:#9e4052;
    font-size:9px;
    line-height:1.5;
}

.admin-claim-rejection strong{
    color:#842c40;
}

.admin-claim-actions{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:7px;
    margin-top:14px;
    padding-top:12px;
    border-top:1px solid #e8edf2;
}

.admin-claim-actions .btn{
    min-width:94px;
}

.admin-claim-approve details,
.admin-claim-reject details{
    position:relative;
}

.admin-claim-schedule{
    width:min(390px,calc(100vw - 60px));
    margin-top:8px;
    padding:13px;
    border:1px solid #dce4eb;
    border-radius:11px;
    background:#fff;
    box-shadow:0 10px 25px rgba(24,39,57,.08);
}

.admin-claim-schedule-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:9px;
}

.admin-claim-schedule label{
    display:block;
    color:#20242a;
    font-size:9px;
    font-weight:800;
}

.admin-claim-schedule label:last-of-type{
    grid-column:1 / -1;
}

.admin-claim-schedule input{
    width:100%;
    margin-top:5px;
    padding:8px 9px;
    border:1px solid #dbe2e9;
    border-radius:7px;
    background:#fff;
    color:#35404a;
    font:inherit;
    font-size:10px;
    box-sizing:border-box;
}

.admin-claim-schedule button{
    margin-top:10px;
}



/* =========================================================
   RESOLVED MULTIPLE-CLAIM STATE
   ========================================================= */
.admin-claim-item.resolved{
    border-color:#9de5c9;
    box-shadow:0 6px 22px rgba(10,132,91,.055);
}

.admin-claim-item.resolved .admin-claim-item-header{
    background:#fbfffd;
}

.admin-claim-resolved-badge{
    background:#d8f7e9!important;
    color:#087957!important;
    border:1px solid #b6ead4!important;
}

.admin-claim-resolution-note{
    display:flex;
    align-items:flex-start;
    gap:9px;
    margin:0 0 15px;
    padding:11px 13px;
    border:1px solid #bfead8;
    border-radius:11px;
    background:#f3fff9;
    color:#477363;
    font-size:9px;
    line-height:1.55;
}

.admin-claim-resolution-note strong{
    display:block;
    color:#147355;
    font-size:10px;
}

.admin-claim-resolution-icon{
    width:24px;
    height:24px;
    flex:0 0 24px;
    display:grid;
    place-items:center;
    border-radius:7px;
    background:#d7f6e8;
    color:#087957;
    font-weight:900;
}

.admin-claim-card.rejected .admin-claim-actions{
    display:none;
}

.admin-claim-empty{
    padding:58px 25px;
    border:1px solid #dfe6ee;
    border-radius:17px;
    background:#fff;
    text-align:center;
    box-shadow:0 4px 15px rgba(28,42,62,.035);
}

.admin-claim-empty-icon{
    width:48px;
    height:48px;
    display:grid;
    place-items:center;
    margin:0 auto 10px;
    border-radius:50%;
    background:#e7f8ef;
    color:#07805b;
    font-size:22px;
}

.admin-claim-empty strong{
    display:block;
    color:#35404a;
    font-size:13px;
}

.admin-claim-empty span{
    display:block;
    margin-top:4px;
    color:#8492a4;
    font-size:10px;
}

.admin-claim-note{
    margin-top:12px;
    color:#8491a2;
    font-size:9px;
    line-height:1.5;
}

/* =========================================================
   CLAIM vs LOST-REPORT COMPARISON
   ========================================================= */
.admin-claim-comparison{
    margin:20px 0 4px;
    padding:16px;
    border:1px solid #e2e7ef;
    border-radius:13px;
    background:#fafbfd;
}
.admin-claim-comparison-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:14px;
    margin-bottom:12px;
}
.admin-claim-comparison-head h3{margin:0;color:#35404a;font-size:13px;font-weight:900}
.admin-claim-comparison-head p{margin:4px 0 0;color:#7d8b9d;font-size:10px;line-height:1.55}
.admin-claim-comparison-head p strong{color:#35404a}
.admin-claim-comparison-count{display:inline-flex;align-items:center;justify-content:center;min-width:72px;padding:6px 9px;border-radius:999px;background:#eeeaff;color:#20242a;font-size:9px;font-weight:900;white-space:nowrap}
.admin-claim-lost-reference{margin-bottom:13px;padding:12px;border:1px solid #dfe5ed;border-radius:11px;background:#fff}
.admin-claim-lost-reference-title{display:flex;justify-content:space-between;gap:10px;margin-bottom:9px;color:#20242a;font-size:9px;font-weight:900;letter-spacing:.4px}
.admin-claim-lost-reference-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px}
.admin-claim-lost-reference-grid>div{min-width:0;padding:8px;border:1px solid #edf0f4;border-radius:8px;background:#fafbfd}
.admin-claim-lost-reference-grid>div.wide{grid-column:span 2}
.admin-claim-lost-reference-grid small{display:block;margin-bottom:3px;color:#8995a5;font-size:8px;font-weight:900;text-transform:uppercase;letter-spacing:.3px}
.admin-claim-lost-reference-grid strong{display:block;color:#35404a;font-size:8px;line-height:1.45;overflow-wrap:anywhere}
.admin-claim-vs-lost-card{margin-top:10px;padding:12px;border:1px solid #dfe5ed;border-radius:11px;background:#fff}
.admin-claim-vs-lost-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:11px}
.admin-claim-vs-lost-kicker{display:block;color:#7f8c9d;font-size:8px;font-weight:900;letter-spacing:.4px}
.admin-claim-vs-lost-head h4{margin:2px 0 1px;color:#35404a;font-size:13px;font-weight:900}
.admin-claim-vs-lost-head small{color:#7c899a;font-size:9px}
.admin-claim-vs-lost-score{display:flex;flex-direction:column;align-items:flex-end;padding:7px 10px;border-radius:10px;background:#f2f4f8;min-width:94px}
.admin-claim-vs-lost-score strong{font-size:22px;line-height:1;color:#20242a}
.admin-claim-vs-lost-score span{margin-top:3px;font-size:8px;font-weight:900;text-transform:uppercase;letter-spacing:.3px}
.admin-claim-vs-lost-score.strong{background:#e7f8ef;color:#087957}.admin-claim-vs-lost-score.strong strong{color:#087957}
.admin-claim-vs-lost-score.good{background:#edf7fb;color:#14728d}.admin-claim-vs-lost-score.good strong{color:#14728d}
.admin-claim-vs-lost-score.possible{background:#fff7e6;color:#a66a00}.admin-claim-vs-lost-score.possible strong{color:#a66a00}
.admin-claim-vs-lost-score.weak{background:#f8eef1;color:#a83b52}.admin-claim-vs-lost-score.weak strong{color:#a83b52}
.admin-claim-vs-lost-breakdown{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.admin-claim-match-detail{min-width:0;padding:10px;border:1px solid #e5e9ef;border-radius:9px;background:#fbfcfd}
.admin-claim-match-detail.strong{border-color:#bce8d5;background:#f5fcf8}.admin-claim-match-detail.partial{border-color:#cce5ef;background:#f7fcfe}.admin-claim-match-detail.weak{border-color:#f0dfb4;background:#fffdf7}.admin-claim-match-detail.none{border-color:#eadde1;background:#fffafb}
.admin-claim-match-detail-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px}
.admin-claim-match-detail-head strong{color:#35404a;font-size:8px}
.admin-claim-match-detail-head span{color:#087957;font-size:12px;font-weight:900}
.admin-claim-match-detail.partial .admin-claim-match-detail-head span{color:#14728d}.admin-claim-match-detail.weak .admin-claim-match-detail-head span{color:#a66a00}.admin-claim-match-detail.none .admin-claim-match-detail-head span{color:#a83b52}
.admin-claim-match-bar{height:5px;overflow:hidden;border-radius:999px;background:#e8edf2}
.admin-claim-match-bar span{display:block;height:100%;border-radius:inherit;background:#4b9b7b}
.admin-claim-match-detail.partial .admin-claim-match-bar span{background:#4f9ab0}.admin-claim-match-detail.weak .admin-claim-match-bar span{background:#c4943d}.admin-claim-match-detail.none .admin-claim-match-bar span{background:#b96a7a}
.admin-claim-match-detail p{margin:7px 0;color:#20242a;font-size:9px;line-height:1.5}
.admin-claim-match-reason strong{color:#20242a}
.admin-claim-match-explanation{margin-top:7px;padding:7px 8px;border:1px solid #edf0f4;border-radius:7px;background:#fff}
.admin-claim-match-explanation-row{display:grid;grid-template-columns:108px minmax(0,1fr);gap:6px;padding:4px 0;color:#20242a;font-size:8.5px;line-height:1.5;overflow-wrap:anywhere}
.admin-claim-match-explanation-row+.admin-claim-match-explanation-row{border-top:1px solid #f0f2f5}
.explanation-label{font-weight:900}
.match-label{color:#087957}.extra-label{color:#a66a00}.missing-label{color:#a83b52}
.admin-claim-shared-terms{margin-top:5px;color:#20242a;font-size:8.5px;line-height:1.5}
.admin-claim-shared-terms span{font-weight:900;color:#35404a}
.admin-claim-match-evidence-grid{display:grid;grid-template-columns:1fr;gap:5px;margin-top:7px}
.admin-claim-match-evidence-grid>div{padding:6px 7px;border-radius:7px;background:#fff;border:1px solid #edf0f4}
.admin-claim-match-evidence-grid small{display:block;margin-bottom:2px;color:#8a96a6;font-size:7.5px;font-weight:900;text-transform:uppercase;letter-spacing:.25px}
.admin-claim-match-evidence-grid span{display:block;color:#20242a;font-size:8.5px;line-height:1.5;overflow-wrap:anywhere}
.admin-claim-vs-lost-footer{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:9px;padding-top:8px;border-top:1px solid #edf0f4;color:#8491a2;font-size:7.5px}
.admin-claim-vs-lost-footer strong{color:#35404a;font-size:9px}
.admin-claim-compare-note{margin-top:10px;padding:9px 10px;border-radius:8px;background:#f2f5f8;color:#20242a;font-size:7.5px;line-height:1.5}
.admin-claim-compare-note strong{color:#20242a}
@media(max-width:900px){
    .admin-claim-lost-reference-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .admin-claim-lost-reference-grid>div.wide{grid-column:span 2}
    .admin-claim-vs-lost-breakdown{grid-template-columns:1fr}
}
@media(max-width:700px){
    .admin-claim-comparison-head,.admin-claim-vs-lost-head{flex-direction:column;align-items:flex-start}
    .admin-claim-lost-reference-grid{grid-template-columns:1fr}
    .admin-claim-lost-reference-grid>div.wide{grid-column:auto}
    .admin-claim-vs-lost-score{align-items:flex-start}
}

@media(max-width:850px){
    .admin-claims-head{
        align-items:flex-start;
        flex-direction:column;
    }

    .admin-claims-summary{
        justify-content:flex-start;
    }

    .admin-claim-item-header{
        grid-template-columns:58px minmax(0,1fr);
    }

    .admin-claim-item-photo{
        width:58px;
        height:58px;
    }

    .admin-claim-item-actions{
        grid-column:1 / -1;
        justify-content:flex-start;
    }

    .admin-claim-cards{
        grid-template-columns:1fr!important;
    }
}

@media(max-width:560px){
    .admin-claims-title h1{
        font-size:25px;
    }

    .admin-claim-item-header,
    .admin-claim-item-body{
        padding-left:16px;
        padding-right:16px;
    }

    .admin-claim-item-header{
        grid-template-columns:52px minmax(0,1fr);
        gap:11px;
    }

    .admin-claim-item-photo{
        width:52px;
        height:52px;
    }

    .admin-claim-card-top{
        flex-direction:column;
    }

    .admin-claim-actions{
        justify-content:flex-start;
        flex-wrap:wrap;
    }

    .admin-claim-schedule-grid{
        grid-template-columns:1fr;
    }

    .admin-claim-schedule label:last-of-type{
        grid-column:auto;
    }
}
</style>

<div class="container admin-claims-page">

    <div class="admin-claims-head">

        <div class="admin-claims-title">
            <h1>Manage Ownership Claims &amp; Handover</h1>
            <p>
                Adjudicate claims, resolve multiple claimant disputes,
                schedule verified pickups, and prepare approved items for handover.
            </p>
        </div>

        <div class="admin-claims-summary">

            <div class="admin-claims-stat pending">
                <small>Pending Claims</small>
                <strong><?= $claimsPendingCount ?></strong>
            </div>

            <div class="admin-claims-stat approved">
                <small>Approved</small>
                <strong><?= $claimsApprovedCount ?></strong>
            </div>

            <div class="admin-claims-stat rejected">
                <small>Rejected</small>
                <strong><?= $claimsRejectedCount ?></strong>
            </div>

            <div class="admin-claims-stat">
                <small>Items With Claims</small>
                <strong><?= count($claimItemGroups) ?></strong>
            </div>

        </div>

    </div>


    <?php if($multipleClaimCount>0): ?>

        <div class="admin-claims-alert">

            <div class="admin-claims-alert-icon">♢</div>

            <div>
                <strong>
                    <?= $multipleClaimCount ?>
                    item<?= $multipleClaimCount===1?'':'s' ?>
                    require multiple-claim review
                </strong>

                <span>
                    Compare each claimant's ownership information and identifying proof
                    before approving a release.
                </span>
            </div>

        </div>

    <?php endif; ?>


    <?php if($claimItemGroups): ?>

        <div class="admin-claim-groups">

        <?php foreach($claimItemGroups as $claimGroup): ?>

            <?php
                $firstClaim=$claimGroup[0];
                $itemId=(int)$firstClaim['item_id'];
                $claimCount=count($claimGroup);
                $hasMultiple=$claimCount>1;
                $hasApprovedClaim=false;
                $pendingCount=0;
                $rejectedCount=0;
                foreach($claimGroup as $statusClaim){
                    $status=(string)($statusClaim['claim_status']??'');
                    if($status==='approved') $hasApprovedClaim=true;
                    if($status==='pending') $pendingCount++;
                    if($status==='rejected') $rejectedCount++;
                }
                $isResolved=$hasApprovedClaim;
                $isHandedOver=false;
                foreach($claimGroup as $statusClaim){
                    if(!empty($statusClaim['handover_at']) || (($statusClaim['item_status']??'')==='returned')){
                        $isHandedOver=true;
                        break;
                    }
                }

                $photo=trim((string)($firstClaim['photo_path']??''));

                if($photo===''){
                    $imageSlug=strtolower(trim((string)$firstClaim['item_name']));
                    $imageSlug=preg_replace('/[^a-z0-9]+/i','-',$imageSlug);
                    $imageSlug=trim($imageSlug,'-');

                    $customDir=__DIR__.'/uploads/Items/';
                    $customUrl='uploads/Items/';
                    if(!is_dir($customDir)){
                        $customDir=__DIR__.'/uploads/items/';
                        $customUrl='uploads/items/';
                    }

                    foreach(['jpg','jpeg','png','webp'] as $extension){
                        $candidate=$customDir.$imageSlug.'.'.$extension;

                        if(is_file($candidate)){
                            $photo=$customUrl.$imageSlug.'.'.$extension;
                            break;
                        }
                    }
                }

                $foundLocation=trim(
                    implode(', ',array_filter([
                        $firstClaim['found_campus_location']??'',
                        $firstClaim['found_building']??'',
                        $firstClaim['found_floor'] ? 'Floor '.$firstClaim['found_floor'] : '',
                        $firstClaim['found_specific_area']??''
                    ]))
                );

                $custody=$firstClaim['storage_location']??'';

                $scheduledClaim=null;

                foreach($claimGroup as $groupClaim){
                    if(($groupClaim['claim_status']??'')==='approved'){
                        $scheduledClaim=$groupClaim;
                        break;
                    }
                }
            ?>

            <article class="admin-claim-item <?= $hasMultiple?'multiple ':'' ?><?= $isResolved?'resolved ':'' ?><?= $isHandedOver?'handed-over':'' ?>">

                <header class="admin-claim-item-header">

                    <div class="admin-claim-item-photo">

                        <?php if($photo): ?>

                            <img
                                src="<?= h($photo) ?>"
                                alt="<?= h($firstClaim['item_name']) ?>"
                            >

                        <?php else: ?>

                            <span>▧</span>

                        <?php endif; ?>

                    </div>


                    <div class="admin-claim-item-main">

                        <div class="admin-claim-item-meta">

                            <span class="admin-claim-item-id">
                                ITM-<?= str_pad((string)$itemId,3,'0',STR_PAD_LEFT) ?>
                            </span>

                            <?php if($hasMultiple): ?>

                                <span class="admin-claim-status"
                                      style="background:#eee2ff;color:#20242a;">
                                    ♢ Multiple Claims (<?= $claimCount ?>)
                                </span>

                            <?php endif; ?>

                            <?php if($isHandedOver): ?>

                                <span class="admin-claim-status approved admin-claim-resolved-badge">
                                    ✓ Item Handed Over
                                </span>

                            <?php elseif($isResolved): ?>

                                <span class="admin-claim-status approved admin-claim-resolved-badge">
                                    ✓ Claim Resolved
                                </span>

                            <?php endif; ?>

                            <span class="admin-claim-item-category">
                                <?= h($firstClaim['category']??'Uncategorized') ?>
                            </span>

                        </div>

                        <h2><?= h($firstClaim['item_name']) ?></h2>

                        <div class="admin-claim-item-custody">

                            Physical Custody:

                            <strong>
                                <?= h($custody ?: 'Not assigned') ?>
                            </strong>

                            <?php if($foundLocation): ?>

                                <span> • Found: <?= h($foundLocation) ?></span>

                            <?php endif; ?>

                        </div>

                    </div>


                    <div class="admin-claim-item-actions">

                        <?php if($scheduledClaim): ?>

                            <?php $handoverAlreadyDone=!empty($scheduledClaim['handover_at']) || (($scheduledClaim['item_status']??'')==='returned'); ?>

                            <?php if($handoverAlreadyDone): ?>

                                <span class="admin-release-badge admin-release-completed">
                                    ✓ Handover Completed
                                </span>

                            <?php else: ?>

                                <span class="admin-release-badge">
                                    Release Scheduled
                                </span>

                                <button
                                    type="button"
                                    class="admin-handover-btn admin-handover-open"
                                    data-claim-id="<?= (int)$scheduledClaim['id'] ?>"
                                    data-item-name="<?= h($firstClaim['item_name'] ?? 'Item') ?>"
                                    data-claimant="<?= h($scheduledClaim['full_name'] ?? 'Approved Claimant') ?>"
                                    data-release-code="<?= h('REL-'.str_pad((string)$scheduledClaim['id'],4,'0',STR_PAD_LEFT).'-'.str_pad((string)$itemId,3,'0',STR_PAD_LEFT)) ?>"
                                >
                                    ✓ Hand Over Item
                                </button>

                            <?php endif; ?>

                        <?php endif; ?>

                    </div>

                </header>


                <div class="admin-claim-item-body">

                    <p class="admin-claim-section-label">
                        Registered Claims for This Item (<?= $claimCount ?>):
                    </p>


                    <div
                        class="admin-claim-cards"
                        style="--claim-columns:<?= $claimCount>1?2:1 ?>"
                    >

                    <?php
                        /*
                         * Compare EACH CLAIMANT against the LOST REPORT for this item.
                             * This is intentionally not claimant-vs-claimant comparison.
                             * The lost reporter's item details are the reference record.
                             *
                             * Each evidence area contributes up to 33.3 percentage points:
                             *   1) Ownership description  -> lost item description/notes
                             *   2) Identifying information -> lost identifying features/color/brand
                             *   3) Supporting evidence     -> lost description/features/notes
                             *
                             * We use meaningful-term overlap instead of requiring exact sentences,
                             * because claim text and lost-report text are normally written differently.
                             */
                            $lostReference=[
                                'reporter_name'=>trim((string)($firstClaim['lost_reporter_name']??'')),
                                'item_name'=>trim((string)($firstClaim['item_name']??'')),
                                'category'=>trim((string)($firstClaim['category']??'')),
                                'description'=>trim((string)($firstClaim['item_description']??'')),
                                'color'=>trim((string)($firstClaim['color']??'')),
                                'brand'=>trim((string)($firstClaim['brand']??'')),
                                'identifying_features'=>trim((string)($firstClaim['identifying_features']??'')),
                                'additional_notes'=>trim((string)($firstClaim['lost_additional_notes']??'')),
                            ];

                            $normalizeCompareText=static function($value){
                                $value=mb_strtolower(trim((string)$value));
                                $value=preg_replace('/[^\p{L}\p{N}]+/u',' ',$value);
                                return trim(preg_replace('/\s+/u',' ',$value));
                            };

                            $meaningfulTokens=static function($value) use ($normalizeCompareText){
                                $normalized=$normalizeCompareText($value);
                                if($normalized==='') return [];
                                $tokens=preg_split('/\s+/u',$normalized)?:[];
                                $stopWords=[
                                    'the','and','with','for','this','that','from','item','lost','report',
                                    'my','is','was','are','has','have','had','of','to','in','on','at','a',
                                    'an','or','it','its','i','me','mine','also','very','some','there','near'
                                ];
                                $tokens=array_values(array_unique(array_filter($tokens,static function($token) use ($stopWords){
                                    return mb_strlen($token)>=3 && !in_array($token,$stopWords,true);
                                })));
                                return $tokens;
                            };

                            $overlapDetails=static function($claimText,$referenceText) use ($meaningfulTokens){
                                $claimTokens=$meaningfulTokens($claimText);
                                $referenceTokens=$meaningfulTokens($referenceText);
                                if(!$claimTokens || !$referenceTokens){
                                    return ['percent'=>0,'shared'=>[],'claim_count'=>count($claimTokens),'reference_count'=>count($referenceTokens)];
                                }
                                $shared=array_values(array_intersect($claimTokens,$referenceTokens));
                                $claimOnly=array_values(array_diff($claimTokens,$referenceTokens));
                                $referenceOnly=array_values(array_diff($referenceTokens,$claimTokens));
                                /*
                                 * Score the field by how much of the claimant's meaningful
                                 * information is supported by the lost report, capped at 100%.
                                 * The extra token lists are kept so the admin can see exactly
                                 * what caused the score instead of seeing only a percentage.
                                 */
                                $percent=min(100,round((count($shared)/count($claimTokens))*100,1));
                                return [
                                    'percent'=>$percent,
                                    'shared'=>$shared,
                                    'claim_only'=>$claimOnly,
                                    'reference_only'=>$referenceOnly,
                                    'claim_count'=>count($claimTokens),
                                    'reference_count'=>count($referenceTokens)
                                ];
                            };

                            $buildEvidenceMatch=static function($claimText,$referenceText,$label) use ($overlapDetails,$normalizeCompareText){
                                $claimText=trim((string)$claimText);
                                $referenceText=trim((string)$referenceText);
                                $result=$overlapDetails($claimText,$referenceText);

                                if($claimText==='' || $referenceText===''){
                                    $result['percent']=0;
                                    $result['status']='Not enough information';
                                    $result['reason']=$claimText===''
                                        ? 'No comparison could be supported because the claimant did not provide this field.'
                                        : 'No comparison could be supported because the lost report does not contain reference information for this field.';
                                }elseif($normalizeCompareText($claimText)===$normalizeCompareText($referenceText)){
                                    $result['percent']=100;
                                    $result['status']='Strong match';
                                    $result['reason']='The claimant and lost report contain the same meaningful information for this field.';
                                }elseif($result['percent']>=70){
                                    $result['status']='Strong match';
                                    $result['reason']="Most of the claimant's meaningful terms are also present in the lost report, so the claimant description is strongly supported by the reported item details.";
                                }elseif($result['percent']>=40){
                                    $result['status']='Partial match';
                                    $result['reason']='Several meaningful terms overlap with the lost report, but the claimant also supplied additional terms that are not found in the report.';
                                }elseif($result['percent']>0){
                                    $result['status']='Weak match';
                                    $result['reason']="Only a small portion of the claimant's meaningful terms appears in the lost report, so the evidence provides limited support.";
                                }else{
                                    $result['status']='No match';
                                    $result['reason']="None of the claimant's meaningful terms appear in the lost report reference for this field.";
                                }
                                $result['label']=$label;
                                $result['claim_text']=$claimText;
                                $result['reference_text']=$referenceText;
                                return $result;
                            };

                            $lostDescriptionReference=trim(implode(' ',array_filter([
                                $lostReference['description'],
                                $lostReference['additional_notes']
                            ])));

                            $lostIdentifierReference=trim(implode(' ',array_filter([
                                $lostReference['identifying_features'],
                                $lostReference['color'],
                                $lostReference['brand'],
                                $lostReference['item_name'],
                                $lostReference['category']
                            ])));

                            $lostEvidenceReference=trim(implode(' ',array_filter([
                                $lostReference['description'],
                                $lostReference['identifying_features'],
                                $lostReference['color'],
                                $lostReference['brand'],
                                $lostReference['additional_notes'],
                                $lostReference['item_name'],
                                $lostReference['category']
                            ])));
                        ?>

                    <?php foreach($claimGroup as $c): ?>

                        <?php
                            $claimStatus=(string)($c['claim_status']??'pending');

                            $ownership=trim((string)($c['ownership_description']??''));
                            if($ownership===''){
                                $ownership='No ownership description was provided.';
                            }

                            $identifying=trim((string)($c['identifying_information']??''));

                            $supporting=trim((string)($c['supporting_evidence']??''));

                            $createdDate=$c['created_at']
                                ? date('Y-m-d',strtotime($c['created_at']))
                                : '—';

                            $releaseCode='REL-'.str_pad(
                                (string)$c['id'],
                                4,
                                '0',
                                STR_PAD_LEFT
                            ).'-'.str_pad(
                                (string)$itemId,
                                3,
                                '0',
                                STR_PAD_LEFT
                            );
                        ?>

                        <article class="admin-claim-card <?= h($claimStatus) ?>">

                            <div class="admin-claim-card-top">

                                <div class="admin-claim-person">

                                    <div class="admin-claim-id-name">

                                        <span class="admin-claim-id">
                                            CLM-<?= str_pad(
                                                (string)$c['id'],
                                                3,
                                                '0',
                                                STR_PAD_LEFT
                                            ) ?>
                                        </span>

                                        <strong>
                                            <?= h($c['full_name']) ?>
                                        </strong>

                                    </div>

                                    <div class="admin-claim-contact">

                                        ID:
                                        <?= h($c['university_id']??'Not provided') ?>

                                        <?php if(!empty($c['email'])): ?>

                                            • <?= h($c['email']) ?>

                                        <?php endif; ?>

                                    </div>

                                </div>


                                <span class="admin-claim-status <?= h($claimStatus) ?>">
                                    <?= h(status_label($claimStatus)) ?>
                                </span>

                            </div>


                            <div class="admin-claim-date">

                                Approx. Lost Date:
                                <?= h($c['found_event_date'] ?: $createdDate) ?>

                            </div>


                            <div class="admin-claim-comparison">
                            <div class="admin-claim-comparison-head">
                                <div>
                                    <h3>Claimant vs. Lost Item Match</h3>
                                    <p>
                                        Each claimant is compared directly with the original lost-item report submitted by
                                        <strong><?= h($lostReference['reporter_name'] ?: 'the lost-item reporter') ?></strong>.
                                    </p>
                                </div>
                            </div>

                            <div class="admin-claim-lost-reference">
                                <div class="admin-claim-lost-reference-title">
                                    <span>LOST ITEM REFERENCE</span>
                                    <?php if(!empty($firstClaim['lost_report_id'])): ?>
                                        RPT-<?= str_pad((string)$firstClaim['lost_report_id'],3,'0',STR_PAD_LEFT) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="admin-claim-lost-reference-grid">
                                    <div><small>Reported Item</small><strong><?= h($lostReference['item_name'] ?: 'Not provided') ?></strong></div>
                                    <div><small>Category</small><strong><?= h($lostReference['category'] ?: 'Not provided') ?></strong></div>
                                    <div><small>Color</small><strong><?= h($lostReference['color'] ?: 'Not provided') ?></strong></div>
                                    <div><small>Brand</small><strong><?= h($lostReference['brand'] ?: 'Not provided') ?></strong></div>
                                    <div class="wide"><small>Description / Notes</small><strong><?= h($lostDescriptionReference ?: 'Not provided') ?></strong></div>
                                    <div class="wide"><small>Identifying Features</small><strong><?= h($lostReference['identifying_features'] ?: 'Not provided') ?></strong></div>
                                </div>
                            </div>


                                <?php
                                    $evidenceMatches=[
                                        $buildEvidenceMatch(
                                            $c['ownership_description']??'',
                                            $lostDescriptionReference,
                                            'Ownership Description'
                                        ),
                                        $buildEvidenceMatch(
                                            $c['identifying_information']??'',
                                            $lostIdentifierReference,
                                            'Identifying Information'
                                        ),
                                        $buildEvidenceMatch(
                                            $c['supporting_evidence']??'',
                                            $lostEvidenceReference,
                                            'Supporting Evidence'
                                        )
                                    ];

                                    $totalPercent=round(array_sum(array_column($evidenceMatches,'percent'))/count($evidenceMatches),1);
                                    if($totalPercent>=80){
                                        $overallClass='strong';
                                        $overallLabel='Strong Match';
                                    }elseif($totalPercent>=60){
                                        $overallClass='good';
                                        $overallLabel='Good Match';
                                    }elseif($totalPercent>=30){
                                        $overallClass='possible';
                                        $overallLabel='Possible Match';
                                    }else{
                                        $overallClass='weak';
                                        $overallLabel='Low Match';
                                    }
                                ?>

                                <article class="admin-claim-vs-lost-card">
                                    <div class="admin-claim-vs-lost-head">
                                        <div>
                                            <span class="admin-claim-vs-lost-kicker">CLAIM CLM-<?= str_pad((string)$c['id'],3,'0',STR_PAD_LEFT) ?></span>
                                            <h4><?= h($c['full_name']) ?></h4>
                                            <small>
                                                <?= h($c['university_id'] ?: 'No university ID') ?>
                                                <?php if(!empty($c['email'])): ?> • <?= h($c['email']) ?><?php endif; ?>
                                            </small>
                                        </div>
                                        <div class="admin-claim-vs-lost-score <?= h($overallClass) ?>">
                                            <strong><?= number_format($totalPercent,1) ?>%</strong>
                                            <span><?= h($overallLabel) ?></span>
                                        </div>
                                    </div>

                                    <div class="admin-claim-vs-lost-breakdown">
                                        <?php foreach($evidenceMatches as $match): ?>
                                            <?php
                                                $barWidth=max(0,min(100,(float)$match['percent']));
                                                $matchClass=$match['percent']>=70?'strong':($match['percent']>=40?'partial':($match['percent']>0?'weak':'none'));
                                            ?>
                                            <div class="admin-claim-match-detail <?= h($matchClass) ?>">
                                                <div class="admin-claim-match-detail-head">
                                                    <strong><?= h($match['label']) ?></strong>
                                                    <span><?= number_format((float)$match['percent'],1) ?>%</span>
                                                </div>
                                                <div class="admin-claim-match-bar"><span style="width:<?= h((string)$barWidth) ?>%"></span></div>
                                                <p class="admin-claim-match-reason">
                                                    <strong>Why this score:</strong> <?= h($match['reason']) ?>
                                                </p>
                                                <div class="admin-claim-match-explanation">
                                                    <div class="admin-claim-match-explanation-row">
                                                        <span class="explanation-label match-label">✓ Matched details</span>
                                                        <span><?= $match['shared'] ? h(implode(', ',array_slice($match['shared'],0,14))) : 'None found' ?></span>
                                                    </div>
                                                    <div class="admin-claim-match-explanation-row">
                                                        <span class="explanation-label extra-label">+ Claimant-only details</span>
                                                        <span><?= $match['claim_only'] ? h(implode(', ',array_slice($match['claim_only'],0,14))) : 'None' ?></span>
                                                    </div>
                                                    <div class="admin-claim-match-explanation-row">
                                                        <span class="explanation-label missing-label">− Lost-report details not mentioned</span>
                                                        <span><?= $match['reference_only'] ? h(implode(', ',array_slice($match['reference_only'],0,14))) : 'None' ?></span>
                                                    </div>
                                                </div>
                                                <div class="admin-claim-match-evidence-grid">
                                                    <div>
                                                        <small>Claimant provided</small>
                                                        <span><?= h($match['claim_text'] ?: 'Not provided') ?></span>
                                                    </div>
                                                    <div>
                                                        <small>Lost report reference</small>
                                                        <span><?= h($match['reference_text'] ?: 'Not provided') ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="admin-claim-vs-lost-footer">
                                        <span>Overall match = average of the 3 evidence areas.</span>
                                        <strong><?= number_format($totalPercent,1) ?>% Match</strong>
                                    </div>
                                </article>

                            </div>


                            <?php if($claimStatus==='approved'): ?>

                                <div class="admin-release-box">

                                    <strong>
                                        Release verification scheduled
                                    </strong>

                                    <span>

                                        Pickup on
                                        <?= $c['verification_date']
                                            ? h(date('F j, Y',strtotime($c['verification_date'])))
                                            : 'date not set'
                                        ?>

                                        <?php if($c['verification_time']): ?>

                                            at
                                            <?= h(date('g:i A',strtotime($c['verification_time']))) ?>

                                        <?php endif; ?>

                                        <?php if($c['verification_location']): ?>

                                            at
                                            <?= h($c['verification_location']) ?>

                                        <?php endif; ?>

                                    </span>

                                    <span>

                                        Verification deadline:
                                        <?= $c['verification_deadline']
                                            ? h(date('F j, Y',strtotime($c['verification_deadline'])))
                                            : '—'
                                        ?>

                                    </span>

                                    <span class="admin-release-code">
                                        <?= h($releaseCode) ?>
                                    </span>

                                </div>


                            <?php elseif($claimStatus==='rejected' && !empty($c['rejection_reason'])): ?>

                                <div class="admin-claim-rejection">

                                    <strong>Rejection Reason:</strong>

                                    <?= h($c['rejection_reason']) ?>

                                </div>

                            <?php endif; ?>


                            <?php if($claimStatus==='pending'): ?>

                                <div class="admin-claim-actions">

                                    <div class="admin-claim-reject">

                                        <details>

                                            <summary class="btn small danger">
                                                Reject Claim
                                            </summary>

                                            <form
                                                method="post"
                                                class="mini-form"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf"
                                                    value="<?= h(csrf_token()) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="claim_decision"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="claim_id"
                                                    value="<?= (int)$c['id'] ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="decision"
                                                    value="reject"
                                                >

                                                <textarea
                                                    name="reason"
                                                    required
                                                    placeholder="Reason required"
                                                ></textarea>

                                                <button
                                                    class="btn small danger"
                                                    type="submit"
                                                >
                                                    Confirm Reject
                                                </button>

                                            </form>

                                        </details>

                                    </div>


                                    <div class="admin-claim-approve">

                                        <details>

                                            <summary class="btn small success">
                                                ✓ Approve &amp; Set Release
                                            </summary>

                                            <form
                                                method="post"
                                                class="admin-claim-schedule"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf"
                                                    value="<?= h(csrf_token()) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="claim_decision"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="claim_id"
                                                    value="<?= (int)$c['id'] ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="decision"
                                                    value="approve"
                                                >

                                                <div class="admin-claim-schedule-grid">

                                                    <label>
                                                        Visit date

                                                        <input
                                                            type="date"
                                                            name="verification_date"
                                                            value="<?= date('Y-m-d') ?>"
                                                            required
                                                        >

                                                    </label>


                                                    <label>
                                                        Visit time

                                                        <input
                                                            type="time"
                                                            name="verification_time"
                                                            value="10:00"
                                                            required
                                                        >

                                                    </label>


                                                    <label>
                                                        Verification deadline

                                                        <input
                                                            type="date"
                                                            name="verification_deadline"
                                                            value="<?= date(
                                                                'Y-m-d',
                                                                strtotime('+7 days')
                                                            ) ?>"
                                                            required
                                                        >

                                                    </label>


                                                    <label>
                                                        Pickup / verification location

                                                        <input
                                                            type="text"
                                                            name="verification_location"
                                                            value="Office of Mapúa, 1st Floor"
                                                            required
                                                        >

                                                    </label>

                                                </div>


                                                <button
                                                    class="btn small success"
                                                    type="submit"
                                                >
                                                    Confirm Approval &amp; Schedule
                                                </button>

                                            </form>

                                        </details>

                                    </div>

                                </div>

                            <?php endif; ?>

                        </article>

                    <?php endforeach; ?>

                    </div>




                </div>

            </article>

        <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="admin-claim-empty">

            <div class="admin-claim-empty-icon">✓</div>

            <strong>No ownership claims have been submitted yet.</strong>

            <span>
                New student claims will appear here after they are submitted for verification.
            </span>

        </div>

    <?php endif; ?>

</div>


<?php endif; ?>

<?php elseif($tab==='items'): ?>

<div class="panel">

    <div class="panel-head">
        <h2>Item Lifecycle Management</h2>
        <span>Open → Matched → Returned → Closed</span>
    </div>

    <div class="table-scroll">

        <table>

            <thead>
                <tr>
                    <th>Report</th>
                    <th>Item</th>
                    <th>Current Status</th>
                    <th>Change Status</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach($allReports as $r): ?>

                <tr>

                    <td>
                        RPT-<?= str_pad(
                            (string)$r['id'],
                            4,
                            '0',
                            STR_PAD_LEFT
                        ) ?>
                    </td>

                    <td>

                        <strong>
                            <?= h($r['item_name']) ?>
                        </strong>

                        <small>
                            <?= ucfirst($r['report_type']) ?>
                        </small>

                    </td>

                    <td>

                        <span class="status <?= h(status_class($r['item_status'])) ?>">
                            <?= status_label($r['item_status']) ?>
                        </span>

                    </td>

                    <td>

                        <form
                            method="post"
                            class="inline-form"
                        >

                            <input
                                type="hidden"
                                name="csrf"
                                value="<?= h(csrf_token()) ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="item_status"
                            >

                            <input
                                type="hidden"
                                name="report_id"
                                value="<?= (int)$r['id'] ?>"
                            >

                            <select name="new_status">

                                <?php foreach(
                                    ['open','matched','claim_pending','returned','closed']
                                    as $s
                                ): ?>

                                    <option
                                        value="<?= $s ?>"
                                        <?= $r['item_status']===$s
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= status_label($s) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                            <input
                                name="remarks"
                                placeholder="Remarks"
                            >

                            <button class="btn small primary">
                                Update
                            </button>

                        </form>

                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>


<?php elseif($tab==='statistics'): ?>

<div class="stat-grid">

    <?php foreach(
        [
            ['Total Reports',$stats['total']],
            ['Lost',$stats['lost']],
            ['Found',$stats['found']],
            ['Pending Reports',$stats['pending_reports']],
            ['Pending Claims',$stats['pending_claims']],
            ['Returned',$stats['returned']],
            ['Closed',$stats['closed']]
        ] as $s
    ): ?>

        <div class="stat-card">

            <small>
                <?= h($s[0]) ?>
            </small>

            <strong>
                <?= $s[1] ?>
            </strong>

        </div>

    <?php endforeach; ?>

</div>


<div class="panel">

    <h2>
        Activity by Category
    </h2>

    <div class="bar-list">

        <?php
        $rows=$pdo->query(
            "SELECT i.category, COUNT(*) total
             FROM reports r
             JOIN items i ON i.id=r.item_id
             GROUP BY i.category
             ORDER BY total DESC"
        )->fetchAll();

        $max=$rows
            ? max(array_column($rows,'total'))
            : 1;

        foreach($rows as $row):
        ?>

            <div class="bar-row">

                <span>
                    <?= h($row['category']) ?>
                </span>

                <div>
                    <i style="width:<?= round(
                        $row['total']/$max*100
                    ) ?>%"></i>
                </div>

                <b>
                    <?= $row['total'] ?>
                </b>

            </div>

        <?php endforeach; ?>

    </div>

</div>


<div class="panel export-panel">

    <div class="panel-head">

        <div>
            <h2>
                Export Reports
            </h2>

            <p class="muted">
                Preview the current report records below or download them as a CSV file.
            </p>
        </div>

        <a
            class="btn small primary"
            href="export.php"
        >
            Export CSV
        </a>

    </div>

    <?php
    $exportPreview = $pdo->query("
        SELECT
            r.id,
            r.report_type,
            i.item_name,
            i.category,
            u.full_name AS reporter,
            r.event_date,
            r.campus_location,
            r.report_status,
            r.item_status,
            r.created_at
        FROM reports r
        JOIN items i ON i.id = r.item_id
        LEFT JOIN users u ON u.id = r.user_id
        ORDER BY r.created_at DESC, r.id DESC
    ")->fetchAll();
    ?>

    <div class="table-scroll export-preview-table">
        <table>
            <thead>
                <tr>
                    <th>Report ID</th>
                    <th>Type</th>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Reporter</th>
                    <th>Date</th>
                    <th>Location</th>
                    <th>Report Status</th>
                    <th>Item Status</th>
                    <th>Created At</th>
                </tr>
            </thead>
            <tbody>
            <?php if($exportPreview): ?>
                <?php foreach($exportPreview as $exportRow): ?>
                    <tr>
                        <td>
                            RPT-<?= str_pad(
                                (string)$exportRow['id'],
                                4,
                                '0',
                                STR_PAD_LEFT
                            ) ?>
                        </td>
                        <td><?= h(ucfirst(strtolower((string)$exportRow['report_type']))) ?></td>
                        <td><?= h($exportRow['item_name']) ?></td>
                        <td><?= h($exportRow['category']) ?></td>
                        <td><?= h($exportRow['reporter'] ?? 'Unknown') ?></td>
                        <td>
                            <?= !empty($exportRow['event_date'])
                                ? h(date('n/j/Y', strtotime($exportRow['event_date'])))
                                : '—' ?>
                        </td>
                        <td><?= h($exportRow['campus_location'] ?? '—') ?></td>
                        <td><?= h(ucwords(str_replace('_', ' ', (string)$exportRow['report_status']))) ?></td>
                        <td><?= h(ucwords(str_replace('_', ' ', (string)$exportRow['item_status']))) ?></td>
                        <td>
                            <?= !empty($exportRow['created_at'])
                                ? h(date('n/j/Y H:i', strtotime($exportRow['created_at'])))
                                : '—' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="10" class="muted">
                        No report records available.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>


<div class="panel">

    <div class="panel-head">
        <h2>User Management</h2>
        <span><?= count($allUsers) ?> accounts</span>
    </div>

    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    <th>University ID</th>
                    <th>Role</th>
                    <th>Reports</th>
                    <th>Claims</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($allUsers as $u): ?>
                <tr>
                    <td>
                        <strong><?= h($u['full_name']) ?></strong>
                        <small><?= h($u['email']) ?></small>
                    </td>
                    <td><?= h($u['university_id']) ?></td>
                    <td><?= ucfirst($u['role']) ?></td>
                    <td><?= $u['report_count'] ?></td>
                    <td><?= $u['claim_count'] ?></td>
                    <td><?= h(ucfirst($u['account_status'])) ?></td>
                    <td>
                    <?php if($u['id']!==current_user()['id']): ?>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="user_status">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <input type="hidden" name="account_status" value="<?= $u['account_status']==='active'?'inactive':'active' ?>">
                            <button class="btn small <?= $u['account_status']==='active'?'danger':'success' ?>">
                                <?= $u['account_status']==='active'?'Deactivate':'Activate' ?>
                            </button>
                        </form>
                    <?php else: ?>
                        Current account
                    <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>


<?php elseif($tab==='guidance'): ?>

<div class="panel">

    <h2>
        Send Guidance Notification to Registered Users
    </h2>

    <p class="muted">
        Use this for instructions about in-person submission/claim
        procedures, required identification, release locations,
        and what users should prepare.
    </p>

    <form method="post" class="form">

        <input
            type="hidden"
            name="csrf"
            value="<?= h(csrf_token()) ?>"
        >

        <input
            type="hidden"
            name="action"
            value="send_guidance"
        >

        <label>
            Notification Title

            <input
                name="title"
                value="In-person Lost-and-Found Verification Instructions"
                required
            >

        </label>

        <label>
            Message

            <textarea
                name="message"
                rows="8"
                required
            >For in-person verification or submission of a found item, please bring your valid university ID or another authorized identification document. Prepare the item details, date and location information, and any identifying information needed to verify ownership. Please follow the designated campus Lost-and-Found release or drop-off instructions.</textarea>

        </label>

        <button class="btn primary">
            Send to Registered Users
        </button>

    </form>

</div>


<?php elseif($tab==='messages'): ?>

<?php
/* =========================================================
   MESSAGES WORKSPACE — MODERN CUSTODIAN INBOX
========================================================= */

$selectedThreadItem = null;

if ($selectedThread) {
    $subjectText = strtolower((string)($selectedThread['subject'] ?? ''));
    $isPropertyConversation = (bool)preg_match('/claim|release|pickup|ownership|item|lost|found/', $subjectText);

    /* Only attach an item when the conversation is clearly property/claim related. */
    $itemLookup = null;

    if ($isPropertyConversation) {
        $itemLookup = $pdo->prepare("
        SELECT
            i.id AS item_id,
            i.item_name,
            i.category,
            i.photo_path,
            TRIM(COALESCE(r.campus_location, '') ||
                CASE WHEN COALESCE(r.building, '') <> '' THEN ', ' || r.building ELSE '' END ||
                CASE WHEN COALESCE(r.floor, '') <> '' THEN ', ' || r.floor ELSE '' END ||
                CASE WHEN COALESCE(r.specific_area, '') <> '' THEN ', ' || r.specific_area ELSE '' END
            ) AS location_text,
            r.item_status,
            c.claim_status
        FROM claims c
        JOIN items i ON i.id = c.item_id
        LEFT JOIN reports r
            ON r.item_id = i.id
           AND r.report_type = 'found'
        WHERE c.claimant_user_id = ?
          AND (
              LOWER(c.claim_status) IN ('pending','approved')
              OR LOWER(?) LIKE '%claim%'
          )
        ORDER BY
            CASE WHEN LOWER(c.claim_status) = 'approved' THEN 0 ELSE 1 END,
            c.updated_at DESC,
            c.id DESC
        LIMIT 1");
    }

    if ($itemLookup) {
        $itemLookup->execute([
            (int)$selectedThread['user_id'],
            (string)$selectedThread['subject']
        ]);
        $selectedThreadItem = $itemLookup->fetch() ?: null;
    }

    /* If this is a property conversation but no claim matched, use the user's latest found item. */
    if ($isPropertyConversation && !$selectedThreadItem) {
        $fallbackItem = $pdo->prepare("
            SELECT
                i.id AS item_id,
                i.item_name,
                i.category,
                i.photo_path,
                TRIM(COALESCE(r.campus_location, '') ||
                    CASE WHEN COALESCE(r.building, '') <> '' THEN ', ' || r.building ELSE '' END ||
                    CASE WHEN COALESCE(r.floor, '') <> '' THEN ', ' || r.floor ELSE '' END ||
                    CASE WHEN COALESCE(r.specific_area, '') <> '' THEN ', ' || r.specific_area ELSE '' END
                ) AS location_text,
                r.item_status,
                NULL AS claim_status
            FROM reports r
            JOIN items i ON i.id = r.item_id
            WHERE r.user_id = ?
            ORDER BY r.created_at DESC, r.id DESC
            LIMIT 1");
        $fallbackItem->execute([(int)$selectedThread['user_id']]);
        $selectedThreadItem = $fallbackItem->fetch() ?: null;
    }
}

$adminUnreadTotal = array_sum(
    array_map(
        static fn($m) => (int)($m['unread_count'] ?? 0),
        $inboxMessages
    )
);
?>

<div class="admin-message-workspace">

    <div class="admin-message-titlebar">
        <div>
            <span class="admin-section-kicker">CUSTODIAN DESK</span>
            <div class="admin-message-staff">Staff: <?= h(current_user()['full_name'] ?? 'Find IT Administrator') ?></div>
            <h1>Student Inquiries &amp; Property Messages</h1>
            <p>Communicate directly with students regarding reported items, ownership claims, and pickup scheduling.</p>
        </div>
    </div>

    <div class="admin-message-shell">

        <!-- =====================================================
             LEFT: INBOX
        ====================================================== -->
        <aside class="admin-message-sidebar">

            <div class="admin-inbox-tools">
                <div class="admin-message-search">
                    <span>⌕</span>
                    <input
                        type="search"
                        id="adminMessageSearch"
                        placeholder="Search student or message..."
                        autocomplete="off"
                    >
                </div>

                <div class="admin-message-tabs">
                    <button type="button" class="admin-message-filter active" data-filter="all">
                        All Threads <span><?= count($inboxMessages) ?></span>
                    </button>
                    <button type="button" class="admin-message-filter" data-filter="unread">
                        Unread <span><?= $adminUnreadTotal ?></span>
                    </button>
                </div>
            </div>

            <div class="admin-message-list modern-message-list" id="adminMessageList">

                <?php if($inboxMessages): ?>

                    <?php foreach($inboxMessages as $m): ?>
                        <?php
                        $isUnread = (int)($m['unread_count'] ?? 0) > 0;
                        $initials = '';
                        foreach (preg_split('/\s+/', trim((string)$m['user_name'])) as $part) {
                            if ($part !== '') $initials .= strtoupper(substr($part, 0, 1));
                        }
                        $initials = substr($initials ?: 'U', 0, 2);
                        ?>

                        <a
                            class="admin-message-row modern-message-row <?= $selectedThreadId === (int)$m['id'] ? 'active' : '' ?> <?= $isUnread ? 'is-unread' : '' ?>"
                            href="admin.php?tab=messages&amp;thread=<?= (int)$m['id'] ?>"
                            data-search="<?= h(strtolower(($m['user_name'] ?? '').' '.($m['subject'] ?? '').' '.($m['last_message'] ?? ''))) ?>"
                            data-unread="<?= $isUnread ? '1' : '0' ?>"
                        >
                            <div class="modern-message-avatar">
                                <?= h($initials) ?>
                            </div>

                            <div class="modern-message-main">
                                <div class="modern-message-topline">
                                    <strong><?= h($m['user_name']) ?></strong>
                                    <time><?= h(date('g:i A', strtotime($m['last_message_created_at'] ?? $m['last_message_at']))) ?></time>
                                </div>

                                <small><?= h($m['subject']) ?></small>

                                <p>
                                    <?= h(mb_strimwidth($m['last_message'] ?? '', 0, 78, '…')) ?>
                                </p>

                                <?php if($isUnread): ?>
                                    <span class="modern-unread-dot"></span>
                                <?php endif; ?>
                            </div>
                        </a>

                    <?php endforeach; ?>

                    <div class="admin-message-no-results" id="adminMessageNoResults" hidden>
                        No matching conversations found.
                    </div>

                <?php else: ?>

                    <div class="admin-message-empty">
                        <div class="admin-message-empty-icon">✉</div>
                        <strong>No student messages yet</strong>
                        <p>Messages from students will appear here.</p>
                    </div>

                <?php endif; ?>

            </div>

        </aside>


        <!-- =====================================================
             RIGHT: CONVERSATION
        ====================================================== -->
        <main class="admin-message-conversation modern-message-conversation">

            <?php if($selectedThread): ?>

                <div class="modern-conversation-head">

                    <div class="modern-conversation-person">
                        <?php
                        $selectedInitials = '';
                        foreach (preg_split('/\s+/', trim((string)$selectedThread['user_name'])) as $part) {
                            if ($part !== '') $selectedInitials .= strtoupper(substr($part, 0, 1));
                        }
                        $selectedInitials = substr($selectedInitials ?: 'U', 0, 2);
                        ?>

                        <div class="modern-conversation-avatar">
                            <?= h($selectedInitials) ?>
                        </div>

                        <div>
                            <div class="modern-conversation-name-line">
                                <h2>
                                    <a class="message-user-history-link" href="admin.php?tab=user_history&amp;user_id=<?= (int)$selectedThread['user_id'] ?>">
                                        <?= h($selectedThread['user_name']) ?>
                                    </a>
                                </h2>
                                <span class="student-chip">Student</span>
                            </div>
                            <p>
                                <?= h($selectedThread['user_email']) ?>
                                <span>•</span>
                                <?= h($selectedThread['subject']) ?>
                            </p>
                        </div>
                    </div>

                    <div class="modern-conversation-actions">
                        <a
                            class="message-claims-button"
                            href="admin.php?tab=user_history&amp;user_id=<?= (int)$selectedThread['user_id'] ?>"
                        >
                            ◈ View All Reports &amp; Claims for <?= h($selectedThread['user_name']) ?>
                        </a>

                        <span class="message-status modern-status <?= h($selectedThread['status']) ?>">
                            <?= strtoupper($selectedThread['status']) ?>
                        </span>
                    </div>

                </div>


                <?php if($selectedThreadItem): ?>
                    <div class="modern-referenced-item">
                        <span class="referenced-label">REFERENCED ITEM</span>
                        <strong><?= h($selectedThreadItem['item_name']) ?></strong>
                        <?php if(!empty($selectedThreadItem['location_text'])): ?>
                            <span class="referenced-meta">(<?= h($selectedThreadItem['location_text']) ?>)</span>
                        <?php endif; ?>
                        <span class="referenced-status">
                            Status: <?= h(status_label($selectedThreadItem['item_status'] ?? 'open')) ?>
                        </span>
                    </div>
                <?php endif; ?>


                <div class="modern-chat-scroll">

                    <?php if($selectedThreadMessages): ?>
                        <?php foreach($selectedThreadMessages as $m): ?>
                            <?php $fromAdmin = (int)$m['sender_user_id'] === (int)current_user()['id']; ?>

                            <div class="modern-chat-message <?= $fromAdmin ? 'from-admin' : 'from-student' ?>">

                                <div class="modern-chat-meta">
                                    <strong>
                                        <?= $fromAdmin ? 'You (Security Custodian)' : h($m['full_name']) ?>
                                    </strong>
                                    <time>
                                        <?= h(date('M j, Y • g:i A', strtotime($m['created_at']))) ?>
                                    </time>
                                </div>

                                <?php if($selectedThreadItem): ?>
                                    <div class="message-item-reference">
                                        Item Ref: <?= h($selectedThreadItem['item_name']) ?>
                                    </div>
                                <?php endif; ?>

                                <div class="modern-chat-bubble">
                                    <?= nl2br(h($m['message'])) ?>
                                </div>

                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="modern-chat-empty">
                            No messages in this conversation yet.
                        </div>
                    <?php endif; ?>

                </div>


                <!-- QUICK REPLIES + COMPOSER -->
                <div class="modern-compose-area">

                    <div class="modern-quick-replies">
                        <span>QUICK REPLY:</span>
                        <button type="button" data-reply="Under review. I am checking the submitted ownership information and will update you shortly.">Under Review</button>
                        <button type="button" data-reply="Your claim has been verified. Please follow the scheduled pickup instructions in your My Claims page.">Ready for Pickup</button>
                        <button type="button" data-reply="We could not verify the item at this time. Please provide additional identifying information if available.">Not Found Yet</button>
                    </div>

                    <form method="post" class="modern-message-compose">

                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= h(csrf_token()) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="message_reply"
                        >

                        <input
                            type="hidden"
                            name="thread_id"
                            value="<?= (int)$selectedThread['id'] ?>"
                        >

                        <textarea
                            id="adminReplyMessage"
                            name="message"
                            rows="2"
                            maxlength="3000"
                            placeholder="Reply to <?= h($selectedThread['user_name']) ?>..."
                            required
                        ></textarea>

                        <button type="submit" class="modern-send-button">
                            Send Reply <span>➤</span>
                        </button>

                    </form>

                </div>

            <?php else: ?>

                <div class="message-placeholder modern-message-placeholder">
                    <div class="message-placeholder-icon">✉</div>
                    <h2>Select a conversation</h2>
                    <p>Choose a student message from the inbox to view the complete conversation and reply directly.</p>
                </div>

            <?php endif; ?>

        </main>

    </div>

</div>

<style>
/* =========================================================
   MODERN ADMIN MESSAGES — INSPIRED BY CUSTODIAN DESK UI
========================================================= */
/* The Messages workspace replaces the legacy Administration heading/sidebar. */
.admin-head{display:none!important}
.admin-layout{display:block!important;padding-top:0!important}
.admin-layout>.admin-content{width:100%!important}

.admin-message-workspace{max-width:1230px;margin:0 auto;padding:30px 0 58px}
.admin-message-titlebar{margin-bottom:18px}
.admin-section-kicker{display:inline-flex;align-items:center;padding:5px 10px;border-radius:999px;background:#ffe9ed;color:#a3162c;font-size:10px;font-weight:900;letter-spacing:.7px;text-transform:uppercase}
.admin-message-staff{display:inline-block;margin-left:9px;color:#8490a0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px}
.admin-message-titlebar h1{margin:8px 0 4px;font-size:27px;line-height:1.15;color:#35404a;letter-spacing:-.35px}
.admin-message-titlebar p{margin:0;color:#20242a;font-size:12px}
.admin-message-shell{display:grid;grid-template-columns:410px minmax(0,1fr);min-height:575px;background:#fff;border:1px solid #e3e8ee;border-radius:20px;box-shadow:0 10px 35px rgba(30,45,65,.08);overflow:hidden}
.admin-message-sidebar{border-right:1px solid #e7ebf0;background:#fff;min-width:0}
.admin-inbox-tools{padding:15px 14px;border-bottom:1px solid #e7ebf0}
.admin-message-search{height:36px;border:1px solid #dfe5ec;border-radius:10px;background:#f7f9fb;display:flex;align-items:center;gap:8px;padding:0 11px}
.admin-message-search span{font-size:20px;line-height:1;color:#9aa7b7}
.admin-message-search input{width:100%;border:0;outline:0;background:transparent;font-size:11px;color:#35404a}
.admin-message-search input::placeholder{color:#99a4b3}
.admin-message-tabs{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:10px}
.admin-message-filter{border:0;background:#fff;color:#20242a;border-radius:8px;padding:8px 7px;font-size:10px;font-weight:800;cursor:pointer}
.admin-message-filter span{margin-left:4px}
.admin-message-filter.active{background:#fff0f3;color:#b31832}
.admin-message-list{height:486px;overflow:auto}
.modern-message-row{position:relative;display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border-top:1px solid #edf0f3;text-decoration:none;color:#35404a;background:#fff;transition:.15s ease}
.modern-message-row:hover{background:#fafbfc}
.modern-message-row.active{background:#fff7f8;box-shadow:inset 4px 0 0 #d00036}
.modern-message-row.is-unread{font-weight:700}
.modern-message-avatar,.modern-conversation-avatar{flex:0 0 auto;width:40px;height:40px;border-radius:50%;display:grid;place-items:center;background:#eef1ff;color:#20242a;font-size:13px;font-weight:900}
.modern-message-main{min-width:0;flex:1}
.modern-message-topline{display:flex;justify-content:space-between;gap:8px;align-items:center}
.modern-message-topline strong{font-size:12px;color:#35404a}
.modern-message-topline time{font-size:9px;color:#9aa5b3;white-space:nowrap}
.modern-message-main small{display:block;margin-top:3px;color:#20242a;font-size:9px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.modern-message-main p{margin:6px 0 0;color:#8190a0;font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.modern-unread-dot{position:absolute;right:12px;bottom:14px;width:7px;height:7px;border-radius:50%;background:#e4003b}
.admin-message-no-results{padding:35px 15px;text-align:center;color:#8a95a3;font-size:11px}
.admin-message-empty{padding:55px 20px;text-align:center;color:#7e8997}
.admin-message-empty-icon{font-size:27px;margin-bottom:8px}
.admin-message-empty strong{display:block;color:#35404a;font-size:12px}
.admin-message-empty p{font-size:10px}
.modern-message-conversation{min-width:0;display:flex;flex-direction:column;background:#fff}
.modern-conversation-head{display:flex;justify-content:space-between;gap:18px;align-items:center;padding:15px 17px;border-bottom:1px solid #e7ebf0}
.modern-conversation-person{display:flex;align-items:center;gap:11px;min-width:0}
.modern-conversation-avatar{width:42px;height:42px;background:#eeeaff;color:#20242a}
.modern-conversation-name-line{display:flex;align-items:center;gap:7px}
.modern-conversation-name-line h2{margin:0;font-size:17px;color:#35404a}
.message-user-history-link{color:inherit;text-decoration:none}
.message-user-history-link:hover{color:#20242a;text-decoration:underline;text-underline-offset:3px}
.student-chip{padding:3px 7px;border-radius:999px;background:#e7ebff;color:#20242a;font-size:8px;font-weight:900}
.modern-conversation-person p{margin:4px 0 0;color:#8090a2;font-size:10px}
.modern-conversation-person p span{margin:0 5px}
.modern-conversation-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.message-claims-button{padding:8px 11px;border:1px solid #cfd5ff;background:#f4f3ff;color:#20242a;border-radius:9px;font-size:9px;font-weight:900;text-decoration:none}
.modern-status{padding:8px 11px;border-radius:9px;font-size:9px;font-weight:900}
.modern-status.open{background:#edf8f0;color:#168052}
.modern-status.closed{background:#f1f3f5;color:#6d7784}
.modern-referenced-item{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:8px 16px;background:#f8f9ff;border-bottom:1px solid #e5e8f5;color:#20242a;font-size:9px}
.referenced-label{padding:4px 7px;background:#dfe4ff;border-radius:4px;font-weight:900;letter-spacing:.3px}
.referenced-meta{color:#20242a}
.referenced-status{margin-left:auto;padding:4px 8px;background:#fff;border:1px solid #d8dcf5;border-radius:5px;color:#20242a}
.modern-chat-scroll{flex:1;min-height:350px;max-height:400px;overflow:auto;padding:18px 17px 24px;background:#fbfcfd}
.modern-chat-message{max-width:65%;margin-bottom:20px}
.modern-chat-message.from-student{margin-right:auto}
.modern-chat-message.from-admin{margin-left:auto}
.modern-chat-meta{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:5px;font-size:9px;padding:0 2px}
.from-admin .modern-chat-meta{justify-content:flex-end}
.modern-chat-meta strong{color:#35404a}
.modern-chat-meta time{color:#9aa5b3}
.message-item-reference{display:inline-block;margin:0 0 5px;padding:4px 7px;border:1px solid #d4dcff;background:#f1f3ff;border-radius:5px;color:#20242a;font-size:8px;font-weight:700}
.modern-chat-bubble{padding:11px 14px;border:1px solid #e0e6ec;border-radius:15px;background:#fff;color:#35404a;font-size:11px;line-height:1.6;box-shadow:0 2px 8px rgba(30,45,65,.03)}
.from-admin .modern-chat-bubble{background:#c90039;border-color:#c90039;color:#fff;border-top-right-radius:5px}
.from-student .modern-chat-bubble{border-top-left-radius:5px}
.modern-chat-empty{text-align:center;padding:70px 20px;color:#8b96a4;font-size:11px}
.modern-compose-area{border-top:1px solid #e7ebf0;background:#f8fafc}
.modern-quick-replies{display:flex;align-items:center;gap:7px;padding:9px 15px;border-bottom:1px solid #e7ebf0;flex-wrap:wrap}
.modern-quick-replies>span{font-size:8px;font-weight:900;color:#8793a2;letter-spacing:.4px}
.modern-quick-replies button{border:1px solid #dfe5eb;background:#fff;border-radius:7px;padding:6px 9px;color:#20242a;font-size:9px;cursor:pointer}
.modern-quick-replies button:hover{border-color:#cbd2ff;color:#20242a}
.modern-message-compose{display:flex;gap:9px;align-items:flex-end;padding:10px 15px 11px}
.modern-message-compose textarea{flex:1;min-height:42px;max-height:110px;resize:vertical;border:1px solid #dfe5ec;border-radius:10px;background:#fff;padding:10px 11px;outline:0;font:inherit;font-size:10px;color:#35404a}
.modern-message-compose textarea:focus{border-color:#f8e9ec;box-shadow:0 0 0 3px rgba(91,74,235,.08)}
.modern-send-button{height:40px;border:0;border-radius:10px;background:#dfe4ec;color:#98a2b3;padding:0 15px;font-size:10px;font-weight:900;cursor:not-allowed;box-shadow:none;transition:background .18s ease,color .18s ease,box-shadow .18s ease,transform .12s ease}
.modern-send-button.is-ready{background:#9b1c2c;color:#fff;cursor:pointer;box-shadow:0 5px 12px rgba(91,75,235,.22)}
.modern-send-button.is-ready:hover{filter:brightness(.96);transform:translateY(-1px)}
.modern-send-button:disabled{pointer-events:none}

.modern-message-placeholder{min-height:620px;display:grid;place-content:center;text-align:center;padding:30px}
@media(max-width:900px){.admin-message-workspace{padding:20px 0}.admin-message-shell{grid-template-columns:1fr}.admin-message-sidebar{border-right:0;border-bottom:1px solid #e7ebf0}.admin-message-list{height:330px}.modern-chat-scroll{max-height:none}.modern-conversation-head{align-items:flex-start;flex-direction:column}.modern-conversation-actions{justify-content:flex-start}.admin-message-staff{display:block;margin:7px 0 0}}

/* FINAL MESSAGE INBOX ALIGNMENT — match the reference layout */
.admin-message-sidebar,
.admin-message-sidebar *{box-sizing:border-box}
.modern-message-row{
    text-align:left!important;
    align-items:flex-start!important;
    min-height:96px!important;
    padding:14px 14px!important;
}
.modern-message-row .modern-message-main{
    text-align:left!important;
    align-self:stretch!important;
}
.modern-message-row .modern-message-topline{
    width:100%!important;
    display:flex!important;
    align-items:center!important;
    justify-content:space-between!important;
    text-align:left!important;
}
.modern-message-row .modern-message-topline strong{
    display:block!important;
    text-align:left!important;
    font-size:12px!important;
    line-height:1.25!important;
}
.modern-message-row .modern-message-topline time{
    display:block!important;
    margin-left:auto!important;
    text-align:right!important;
    font-size:9px!important;
}
.modern-message-row .modern-message-main small{
    text-align:left!important;
    margin-top:5px!important;
    line-height:1.25!important;
}
.modern-message-row .modern-message-main p{
    text-align:left!important;
    margin-top:7px!important;
    line-height:1.35!important;
}
.modern-message-row .modern-message-avatar{
    margin-top:1px!important;
}
.admin-message-list{
    height:486px!important;
}



/* =========================================================
   MESSAGES UI — INSPIRED BY THE PROVIDED REFERENCE
   Visual/layout refinement only. Existing PHP workflow,
   queries, actions, filters, and message sending remain intact.
========================================================= */

.admin-message-workspace{
    max-width:1360px!important;
    margin:0 auto!important;
    padding:24px 0 46px!important;
}

.admin-message-titlebar{
    margin-bottom:20px!important;
}

.admin-message-titlebar h1{
    margin:8px 0 5px!important;
    font-size:30px!important;
    line-height:1.15!important;
    letter-spacing:-.55px!important;
}

.admin-message-titlebar p{
    font-size:13px!important;
    color:#20242a!important;
}

.admin-message-shell{
    display:grid!important;
    grid-template-columns:500px minmax(0,1fr)!important;
    min-height:650px!important;
    background:#fff!important;
    border:1px solid #e1e6ed!important;
    border-radius:22px!important;
    box-shadow:0 8px 28px rgba(30,45,65,.07)!important;
}

/* ---------- LEFT INBOX ---------- */
.admin-message-sidebar{
    border-right:1px solid #e1e6ed!important;
    background:#fff!important;
}

.admin-inbox-tools{
    padding:20px 20px 18px!important;
}

.admin-message-search{
    height:50px!important;
    border:1px solid #dfe5ec!important;
    border-radius:25px!important;
    background:#f8fafc!important;
    padding:0 16px!important;
    gap:9px!important;
}

.admin-message-search span{
    font-size:24px!important;
    color:#8b9aaf!important;
}

.admin-message-search input{
    font-size:13px!important;
    color:#35404a!important;
}

.admin-message-tabs{
    grid-template-columns:1fr 1fr!important;
    gap:10px!important;
    margin-top:13px!important;
}

.admin-message-filter{
    padding:9px 10px!important;
    border:1px solid transparent!important;
    border-radius:10px!important;
    background:#fff!important;
    color:#20242a!important;
    font-size:12px!important;
}

.admin-message-filter.active{
    background:#fff0f3!important;
    border-color:#ffc9d4!important;
    color:#c90039!important;
}

.admin-message-filter span{
    margin-left:4px!important;
}

.admin-message-list{
    height:548px!important;
    overflow:auto!important;
}

.modern-message-row{
    min-height:110px!important;
    padding:16px 20px!important;
    gap:13px!important;
    border-top:1px solid #edf0f3!important;
    background:#fff!important;
    transition:background .15s ease!important;
}

.modern-message-row:hover{
    background:#fafbfc!important;
}

.modern-message-row.active{
    background:#fff5f7!important;
    box-shadow:inset 5px 0 0 #e4003b!important;
}

.modern-message-row.is-unread{
    background:#fff!important;
}

.modern-message-row.is-unread.active{
    background:#fff5f7!important;
}

.modern-message-avatar{
    width:42px!important;
    height:42px!important;
    margin-top:1px!important;
    background:#eef1ff!important;
    color:#20242a!important;
    font-size:13px!important;
    flex-basis:42px!important;
}

.modern-message-main{
    min-width:0!important;
}

.modern-message-topline strong{
    font-size:13px!important;
    color:#35404a!important;
}

.modern-message-topline time{
    font-size:10px!important;
    color:#8b98a9!important;
}

.modern-message-main small{
    display:block!important;
    margin-top:5px!important;
    color:#20242a!important;
    font-size:10px!important;
    font-weight:700!important;
}

.modern-message-main p{
    margin:6px 0 0!important;
    color:#20242a!important;
    font-size:11px!important;
    line-height:1.45!important;
}

.modern-unread-dot{
    position:absolute!important;
    right:17px!important;
    top:50%!important;
    width:9px!important;
    height:9px!important;
    border-radius:50%!important;
    background:#e4003b!important;
    transform:translateY(-50%)!important;
}

/* ---------- RIGHT HEADER ---------- */
.modern-conversation-head{
    min-height:108px!important;
    padding:18px 24px!important;
    border-bottom:1px solid #e5e9ef!important;
    background:#fff!important;
}

.modern-conversation-person{
    gap:13px!important;
}

.modern-conversation-avatar{
    width:48px!important;
    height:48px!important;
    flex-basis:48px!important;
    background:#eef1ff!important;
    color:#20242a!important;
    font-size:15px!important;
}

.modern-conversation-name-line{
    gap:8px!important;
}

.modern-conversation-name-line h2{
    font-size:20px!important;
    letter-spacing:-.2px!important;
}

.student-chip{
    padding:4px 9px!important;
    background:#ecebff!important;
    color:#20242a!important;
    font-size:9px!important;
}

.modern-conversation-person p{
    margin-top:6px!important;
    font-size:12px!important;
    color:#20242a!important;
}

.message-claims-button{
    padding:10px 14px!important;
    border:1px solid #cfd5ff!important;
    background:#faf9ff!important;
    color:#20242a!important;
    border-radius:22px!important;
    font-size:11px!important;
}

.modern-status{
    padding:9px 12px!important;
    border-radius:9px!important;
    font-size:10px!important;
}

/* ---------- REFERENCED ITEM ---------- */
.modern-referenced-item{
    min-height:56px!important;
    padding:10px 24px!important;
    background:#f8f9ff!important;
    border-bottom:1px solid #e4e8f4!important;
    gap:9px!important;
    font-size:11px!important;
}

.referenced-label{
    padding:5px 9px!important;
    border-radius:5px!important;
    background:#dfe5ff!important;
    color:#20242a!important;
    font-size:10px!important;
}

.referenced-meta{
    color:#20242a!important;
}

.referenced-status{
    margin-left:auto!important;
    padding:7px 10px!important;
    border:1px solid #cfd6ef!important;
    border-radius:7px!important;
    background:#fff!important;
    color:#20242a!important;
}

/* ---------- CONVERSATION / BUBBLES ---------- */
.modern-chat-scroll{
    flex:1!important;
    min-height:390px!important;
    max-height:455px!important;
    padding:18px 28px 28px!important;
    background:#fff!important;
    overflow:auto!important;
}

.modern-chat-message{
    width:auto!important;
    max-width:72%!important;
    margin-bottom:25px!important;
}

.modern-chat-message.from-student{
    margin-right:auto!important;
    margin-left:0!important;
}

.modern-chat-message.from-admin{
    margin-left:auto!important;
    margin-right:0!important;
}

.modern-chat-meta{
    display:flex!important;
    align-items:center!important;
    gap:9px!important;
    margin-bottom:8px!important;
    padding:0 4px!important;
    font-size:10px!important;
}

.from-admin .modern-chat-meta{
    justify-content:flex-end!important;
}

.modern-chat-meta strong{
    color:#35404a!important;
    font-size:11px!important;
    font-weight:800!important;
}

.modern-chat-meta time{
    color:#94a0af!important;
    font-size:10px!important;
}

.message-item-reference{
    display:inline-block!important;
    margin:0 0 7px 0!important;
    padding:5px 9px!important;
    border:1px solid #cdd5ff!important;
    border-radius:6px!important;
    background:#f8f8ff!important;
    color:#20242a!important;
    font-size:9px!important;
    font-weight:700!important;
}

.from-admin .message-item-reference{
    float:right!important;
    clear:both!important;
    margin-right:0!important;
}

.from-admin .modern-chat-bubble{
    clear:both!important;
}

.modern-chat-bubble{
    display:block!important;
    padding:14px 17px!important;
    border:1px solid #dfe5ec!important;
    border-radius:18px!important;
    background:#fff!important;
    color:#35404a!important;
    font-size:13px!important;
    line-height:1.65!important;
    box-shadow:0 2px 7px rgba(30,45,65,.045)!important;
}

.from-student .modern-chat-bubble{
    border-top-left-radius:5px!important;
}

.from-admin .modern-chat-bubble{
    border:1px solid #c90039!important;
    border-top-right-radius:5px!important;
    background:#c90039!important;
    color:#fff!important;
    box-shadow:0 3px 9px rgba(201,0,57,.10)!important;
}

.modern-chat-empty{
    padding:75px 20px!important;
}

/* ---------- COMPOSER ---------- */
.modern-compose-area{
    border-top:1px solid #e5e9ef!important;
    background:#fafbfc!important;
}

.modern-quick-replies{
    display:flex!important;
    align-items:center!important;
    flex-wrap:wrap!important;
    gap:7px!important;
    padding:10px 18px!important;
    border-bottom:1px solid #edf0f3!important;
    background:#fff!important;
}

.modern-quick-replies span{
    margin-right:3px!important;
    font-size:9px!important;
    color:#7b8798!important;
    font-weight:800!important;
}

.modern-quick-replies button{
    padding:6px 10px!important;
    border:1px solid #dfe4ea!important;
    border-radius:16px!important;
    background:#fff!important;
    color:#20242a!important;
    font-size:9px!important;
}

.modern-message-compose{
    padding:12px 18px 15px!important;
    gap:10px!important;
    background:#fff!important;
}

.modern-message-compose textarea{
    min-height:48px!important;
    border:1px solid #d9dfe7!important;
    border-radius:12px!important;
    background:#fff!important;
    padding:12px 13px!important;
    font-size:11px!important;
    line-height:1.5!important;
}

.modern-send-button{
    min-width:110px!important;
    height:43px!important;
    border-radius:22px!important;
    padding:0 16px!important;
    font-size:10px!important;
}

/* ---------- SMALLER SCREENS ---------- */
@media(max-width:1100px){
    .admin-message-shell{
        grid-template-columns:390px minmax(0,1fr)!important;
    }
    .modern-chat-message{
        max-width:80%!important;
    }
}

@media(max-width:900px){
    .admin-message-shell{
        grid-template-columns:1fr!important;
    }
    .admin-message-sidebar{
        border-right:0!important;
        border-bottom:1px solid #e1e6ed!important;
    }
    .admin-message-list{
        height:330px!important;
    }
    .modern-conversation-head{
        align-items:flex-start!important;
        flex-direction:column!important;
    }
    .modern-conversation-actions{
        justify-content:flex-start!important;
    }
    .modern-chat-message{
        max-width:88%!important;
    }
}


</style>

<script>
(function(){
    const search = document.getElementById('adminMessageSearch');
    const rows = Array.from(document.querySelectorAll('.modern-message-row'));
    const filters = Array.from(document.querySelectorAll('.admin-message-filter'));
    const noResults = document.getElementById('adminMessageNoResults');

    let activeFilter = 'all';

    function renderRows(){
        const query = (search?.value || '').trim().toLowerCase();
        let visible = 0;

        rows.forEach(function(row){
            const matchesSearch = !query || (row.dataset.search || '').includes(query);
            const matchesFilter = activeFilter === 'all' || row.dataset.unread === '1';
            const show = matchesSearch && matchesFilter;
            row.style.display = show ? 'flex' : 'none';
            if(show) visible++;
        });

        if(noResults){
            noResults.hidden = visible !== 0;
        }
    }

    if(search){
        search.addEventListener('input', renderRows);
    }

    filters.forEach(function(button){
        button.addEventListener('click', function(){
            activeFilter = this.dataset.filter || 'all';
            filters.forEach(function(item){ item.classList.remove('active'); });
            this.classList.add('active');
            renderRows();
        });
    });

    function updateAdminSendState(textarea, button){
        if(!textarea || !button) return;
        const hasMessage = textarea.value.trim().length > 0;
        button.disabled = !hasMessage;
        button.classList.toggle('is-ready', hasMessage);
    }

    document.querySelectorAll('.modern-message-compose').forEach(function(form){
        const textarea = form.querySelector('textarea[name="message"]');
        const button = form.querySelector('.modern-send-button');
        if(!textarea || !button) return;

        updateAdminSendState(textarea, button);
        textarea.addEventListener('input', function(){
            updateAdminSendState(textarea, button);
        });

        form.addEventListener('submit', function(event){
            if(!textarea.value.trim()){
                event.preventDefault();
                updateAdminSendState(textarea, button);
                textarea.focus();
            }
        });
    });

    document.querySelectorAll('.modern-quick-replies button').forEach(function(button){
        button.addEventListener('click', function(){
            const textarea = document.getElementById('adminReplyMessage');
            if(textarea){
                textarea.value = this.dataset.reply || '';
                textarea.dispatchEvent(new Event('input', {bubbles:true}));
                textarea.focus();
            }
        });
    });
})();
</script>

<?php elseif($tab==='audit'): ?>


<div class="panel">

    <div class="panel-head">

        <h2>
            Audit Logs
        </h2>

        <span>
            Latest 100 actions
        </span>

    </div>

    <div class="table-scroll">

        <table>

            <thead>

                <tr>
                    <th>Date/Time</th>
                    <th>Administrator</th>
                    <th>Action</th>
                    <th>Record</th>
                    <th>Remarks</th>
                </tr>

            </thead>

            <tbody>

            <?php foreach($auditRows as $a): ?>

                <tr>

                    <td>
                        <?= h($a['created_at']) ?>
                    </td>

                    <td>
                        <?= h($a['full_name']) ?>
                    </td>

                    <td>
                        <?= h($a['action']) ?>
                    </td>

                    <td>
                        <?= h($a['related_record_type']??'') ?>

                        <?= $a['related_record_id']
                            ? '#'.(int)$a['related_record_id']
                            : '' ?>
                    </td>

                    <td>
                        <?= h($a['remarks']??'') ?>
                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>

<?php endif; ?>

</section>

</div>

<?php endif; ?>

<?php require 'includes/footer.php'; ?>

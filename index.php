<?php
require 'config.php';

/*
 * The student portal is private. Visitors must authenticate before they
 * can see the dashboard. Administrators are sent to the admin workspace.
 */
if (!is_logged_in()) {
    $_SESSION['after_login'] = 'index.php';
    redirect('login.php');
}

if (is_admin()) {
    redirect('admin.php');
}

$user = current_user();
$userId = (int)$user['id'];
$pageTitle = 'Dashboard';

/* Legacy match fields retained for database compatibility. Students no longer confirm matches online. */
try {
    $pdo->exec("ALTER TABLE potential_matches ADD COLUMN user_confirmed_at TEXT");
} catch (Throwable $e) {}
try {
    $pdo->exec("ALTER TABLE potential_matches ADD COLUMN user_confirmed_by INTEGER");
} catch (Throwable $e) {}
try {
    $pdo->exec("ALTER TABLE potential_matches ADD COLUMN handover_at TEXT");
} catch (Throwable $e) {}
try {
    $pdo->exec("ALTER TABLE potential_matches ADD COLUMN handover_admin_id INTEGER");
} catch (Throwable $e) {}
try {
    $pdo->exec("ALTER TABLE potential_matches ADD COLUMN handover_evidence TEXT");
} catch (Throwable $e) {}
try {
    $pdo->exec("ALTER TABLE potential_matches ADD COLUMN handover_notes TEXT");
} catch (Throwable $e) {}

/* Student-specific dashboard data. */
$reportStmt = $pdo->prepare("
    SELECT r.*, i.item_name, i.category
    FROM reports r
    JOIN items i ON i.id = r.item_id
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC
    LIMIT 5
");
$reportStmt->execute([$userId]);
$myReports = $reportStmt->fetchAll();

/*
 * Current workflow status for the student's Lost Reports.
 * A report is recorded automatically. Once an administrator approves a
 * potential match, the Lost Reporter first waits for the Found Reporter
 * turnover. Only after physical receipt does the administrator release
 * the Lost Reporter's in-person checking schedule.
 */
$matchByLostReport = [];
try {
    $lostMatchStmt = $pdo->prepare("
        SELECT
            pm.lost_report_id,
            pm.match_score,
            pm.match_status,
            pm.handover_at,
            pm.verification_date,
            pm.verification_time,
            pm.verification_deadline,
            pm.verification_location,
            pm.verification_outcome,
            pm.verification_outcome_reason
        FROM potential_matches pm
        JOIN reports lr ON lr.id = pm.lost_report_id
        WHERE lr.user_id = ?
          AND lr.report_type = 'lost'
          AND lr.report_status = 'approved'
          AND pm.match_status = 'confirmed'
        ORDER BY pm.match_score DESC, pm.created_at DESC
    ");
    $lostMatchStmt->execute([$userId]);
    foreach ($lostMatchStmt->fetchAll() as $lm) {
        $lostReportId = (int)$lm['lost_report_id'];
        if (!isset($matchByLostReport[$lostReportId])) {
            $matchByLostReport[$lostReportId] = $lm;
        }
    }
} catch (PDOException $e) {
    $matchByLostReport = [];
}

$myReportsCountStmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE user_id = ?");
$myReportsCountStmt->execute([$userId]);
$myReportsCount = (int)$myReportsCountStmt->fetchColumn();

$claimsCountStmt = $pdo->prepare("SELECT COUNT(*) FROM claims WHERE claimant_user_id = ? AND claim_status IN ('pending','approved')");
$claimsCountStmt->execute([$userId]);
$activeClaimsCount = (int)$claimsCountStmt->fetchColumn();

$catalogCount = (int)$pdo->query("
    SELECT COUNT(*)
    FROM reports
    WHERE report_status = 'approved'
      AND item_status NOT IN ('returned','closed')
")->fetchColumn();

$inquiryCountStmt = $pdo->prepare("SELECT COUNT(*) FROM message_threads WHERE user_id = ? AND status = 'open'");
$inquiryCountStmt->execute([$userId]);
$inquiryCount = (int)$inquiryCountStmt->fetchColumn();

$foundStmt = $pdo->query("
    SELECT r.*, i.item_name, i.category, i.photo_path
    FROM reports r
    JOIN items i ON i.id = r.item_id
    WHERE r.report_type = 'found'
      AND r.report_status = 'approved'
      AND r.item_status NOT IN ('returned','closed')
    ORDER BY r.created_at DESC
    LIMIT 3
");
$recentFound = $foundStmt->fetchAll();

/*
 * Pull the strongest potential match for one of the student's lost reports.
 * The table is created by the application's matching/admin workflow on
 * existing installations, so check for it before querying.
 */
$match = null;
try {
    $matchStmt = $pdo->prepare("
        SELECT
            pm.id AS potential_match_id,
            pm.match_score,
            pm.match_status,
            pm.user_confirmed_at,
            pm.verification_date,
            pm.verification_time,
            pm.verification_deadline,
            pm.verification_location,
            pm.admin_remarks,
            pm.handover_at,
            pm.handover_evidence,
            pm.handover_notes,
            pm.verification_outcome,
            pm.verification_outcome_reason,
            lr.id AS lost_report_id,
            li.item_name AS lost_item_name,
            fi.item_name AS found_item_name,
            fi.category AS found_category,
            fr.campus_location AS found_location,
            fr.building AS found_building,
            fr.floor AS found_floor,
            fr.specific_area AS found_area
        FROM potential_matches pm
        JOIN reports lr ON lr.id = pm.lost_report_id
        JOIN items li ON li.id = lr.item_id
        JOIN reports fr ON fr.id = pm.found_report_id
        JOIN items fi ON fi.id = fr.item_id
        WHERE lr.user_id = ?
          AND lr.report_type = 'lost'
          AND lr.report_status = 'approved'
          AND pm.match_status = 'confirmed'
        ORDER BY pm.match_score DESC, pm.created_at DESC
        LIMIT 1
    ");
    $matchStmt->execute([$userId]);
    $match = $matchStmt->fetch() ?: null;
} catch (PDOException $e) {
    $match = null;
}

function dashboard_report_status(array $row, array $matchByLostReport): array {
    if (($row['item_status'] ?? '') === 'returned') {
        return ['returned', 'Returned'];
    }

    if (($row['item_status'] ?? '') === 'closed' || ($row['report_status'] ?? '') === 'rejected') {
        return ['closed', 'Closed'];
    }

    if (($row['report_type'] ?? '') === 'found') {
        return ['open', 'Open — Available for Matching'];
    }

    $pm = $matchByLostReport[(int)($row['id'] ?? 0)] ?? null;
    if ($pm) {
        $outcome = (string)($pm['verification_outcome'] ?? '');

        if ($outcome === 'completed') {
            return ['returned', 'Returned'];
        }
        if ($outcome === 'not_match') {
            return ['open', 'Not a Match — Waiting for New Match'];
        }
        if ($outcome === 'reschedule') {
            return ['open', 'Verification Needs Rescheduling'];
        }
        if (!empty($pm['verification_date'])) {
            return ['open', 'Checking Schedule Released'];
        }
        if (!empty($pm['handover_at'])) {
            return ['open', 'Waiting for Checking Schedule'];
        }

        return ['open', 'Waiting for Item to Be Received'];
    }

    return ['open', 'Waiting for Match'];
}

function dashboard_image(array $row): string {
    if (!empty($row['photo_path'])) {
        return (string)$row['photo_path'];
    }

    $slug = strtolower(trim((string)($row['item_name'] ?? '')));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
    $slug = trim($slug, '-');

    if ($slug === '') {
        return '';
    }

    $dir = __DIR__ . '/uploads/items/';
    foreach (['jpg','jpeg','png','webp'] as $ext) {
        $file = $dir . $slug . '.' . $ext;
        if (is_file($file)) {
            return 'uploads/items/' . $slug . '.' . $ext;
        }
    }

    return '';
}

require 'includes/header.php';
?>

<div class="student-dashboard">
    <section class="student-welcome">
        <div class="student-welcome-inner">
            <div class="welcome-badge">✦ Campus Lost &amp; Found Portal</div>
            <h1>Welcome, <?= h($user['name']) ?>!</h1>
            <p>Report lost items, return found belongings to the community, track verification claims, and receive automated algorithmic match notifications.</p>
        </div>
    </section>

    <?php if ($match): ?>
        <section class="match-alert">
            <div class="match-alert-icon">✣</div>
            <div class="match-alert-copy">
                <div class="match-alert-title">
                    <?= (($match['verification_outcome'] ?? '') === 'completed') ? 'ITEM RETURNED — MATCH COMPLETED' : (($match['verification_outcome'] ?? '') === 'not_match' ? 'NOT A MATCH — FOUND ITEM REOPENED' : (($match['verification_outcome'] ?? '') === 'reschedule' ? 'VERIFICATION NEEDS RESCHEDULING' : (!empty($match['verification_date']) ? 'CHECKING SCHEDULE RELEASED' : (!empty($match['handover_at']) ? 'ITEM RECEIVED — AWAITING CHECKING SCHEDULE' : 'MATCH APPROVED — AWAITING ITEM TURNOVER'))) ) ?>
                    <span><?= (int)$match['match_score'] ?>% Match Score</span>
                </div>
                <strong>Potential match found for: “<?= h($match['lost_item_name']) ?>”</strong>
                <p>Counterpart item: “<?= h($match['found_item_name']) ?>”</p>
                <div class="match-tags">
                    <span>• Identical category: <?= h($match['found_category']) ?></span>
                    <span>• High location proximity: <?= h($match['found_location']) ?><?= $match['found_building'] ? ', ' . h($match['found_building']) : '' ?><?= $match['found_floor'] ? ', ' . h($match['found_floor']) : '' ?></span>
                </div>
                <?php if (($match['verification_outcome'] ?? '') === 'completed'): ?>
                    <div class="student-match-next-step student-match-completed">✓ Your item was verified and returned to you. The administrator has completed this match.</div>
                <?php elseif (($match['verification_outcome'] ?? '') === 'not_match'): ?>
                    <div class="student-match-next-step">× The physical verification did not confirm the item as yours. The found item has been reopened in Search &amp; Browse.</div>
                <?php elseif (($match['verification_outcome'] ?? '') === 'reschedule'): ?>
                    <div class="student-match-next-step">↻ Verification needs rescheduling. Please wait for the administrator to release a new checking schedule.</div>
                <?php elseif (!empty($match['verification_date'])): ?>
                    <div class="student-match-next-step">◆ Checking schedule released: <?= h(date('F j, Y', strtotime($match['verification_date']))) ?> at <?= h(date('g:i A', strtotime($match['verification_time'] ?? ''))) ?> — <?= h($match['verification_location'] ?? '') ?></div>
                <?php elseif (!empty($match['handover_at'])): ?>
                    <div class="student-match-next-step">✓ The administrator has received the found item from the matched student. Please wait for your in-person checking schedule.</div>
                <?php else: ?>
                    <div class="student-match-next-step">✓ Your potential match was approved by the administrator. The matched student must first turn over the physical item to the administrator. Please wait while the item is received before a checking schedule is released.</div>
                <?php endif; ?>
            </div>
            <button class="match-inspect" type="button" id="dashboard-match-inspect">Inspect Match Details</button>
        </section>

        <div class="student-match-modal" id="dashboard-match-modal" aria-hidden="true">
            <div class="student-match-dialog" role="dialog" aria-modal="true" aria-labelledby="dashboard-match-title">
                <button type="button" class="student-match-close" id="dashboard-match-close" aria-label="Close">×</button>
                <span class="student-match-eyebrow">ADMINISTRATOR-APPROVED MATCH</span>
                <h2 id="dashboard-match-title">Match Details</h2>
                <p class="student-match-lead">Your reported lost item has an administrator-approved potential match. Review the match details and follow the turnover and in-person checking instructions shown here.</p>
                <div class="student-match-detail-grid">
                    <div><span>Your item</span><strong><?= h($match['lost_item_name']) ?></strong></div>
                    <div><span>Counterpart item</span><strong><?= h($match['found_item_name']) ?></strong></div>
                    <div><span>Match score</span><strong><?= (int)$match['match_score'] ?>%</strong></div>
                    <div><span>Found location</span><strong><?= h($match['found_location']) ?><?= $match['found_building'] ? ', ' . h($match['found_building']) : '' ?><?= $match['found_floor'] ? ', ' . h($match['found_floor']) : '' ?></strong></div>
                </div>
                <?php if (($match['verification_outcome'] ?? '') === 'completed'): ?>
                    <div class="student-match-completion">
                        <strong>✓ Item Verified &amp; Returned — Match Completed</strong>
                        <span>The administrator completed the in-person ownership verification and returned the item to you.</span>
                        <?php if (!empty($match['verification_location'])): ?><span>Release location: <?= h($match['verification_location']) ?></span><?php endif; ?>
                    </div>
                <?php elseif (!empty($match['verification_date'])): ?>
                    <div class="student-match-schedule"><strong>Checking Schedule</strong><span><?= h(date('F j, Y', strtotime($match['verification_date']))) ?> at <?= h(date('g:i A', strtotime($match['verification_time'] ?? ''))) ?></span><span><?= h($match['verification_location'] ?? '') ?></span><?php if (!empty($match['verification_deadline'])): ?><small>Deadline: <?= h(date('F j, Y g:i A', strtotime($match['verification_deadline']))) ?></small><?php endif; ?></div>
                <?php elseif (($match['verification_outcome'] ?? '') === 'not_match'): ?>
                    <div class="student-match-awaiting">× The physical verification did not confirm the item as yours. The found item has been reopened in Search &amp; Browse.</div>
                <?php elseif (($match['verification_outcome'] ?? '') === 'reschedule'): ?>
                    <div class="student-match-awaiting">↻ Verification needs rescheduling. Please wait for the administrator to release a new checking schedule.</div>
                <?php elseif (!empty($match['handover_at'])): ?>
                    <div class="student-match-awaiting">✓ The administrator has received the found item. Please wait for your in-person checking schedule.</div>
                <?php else: ?>
                    <div class="student-match-awaiting">✓ Your potential match was approved by the administrator. No online confirmation is required. The matched student must first turn over the physical item before your checking schedule can be released.</div>
                <?php endif; ?>
                <button type="button" class="student-match-close-text" id="dashboard-match-close-text">Close</button>
            </div>
        </div>
    <?php endif; ?>

    <section class="dashboard-stats">
        <a class="dashboard-stat-card" href="my_reports.php">
            <div class="stat-label">MY REPORTS <span>▧</span></div>
            <strong><?= $myReportsCount ?></strong>
            <small>Reports are automatically recorded</small>
        </a>
        <a class="dashboard-stat-card" href="my_claims.php">
            <div class="stat-label">ACTIVE CLAIMS <span>♢</span></div>
            <strong><?= $activeClaimsCount ?></strong>
            <small>Ownership verification in progress</small>
        </a>
        <a class="dashboard-stat-card" href="search.php">
            <div class="stat-label">CATALOG ITEMS <span>⌕</span></div>
            <strong><?= $catalogCount ?></strong>
            <small>Active campus records</small>
        </a>
        <a class="dashboard-stat-card" href="messages.php">
            <div class="stat-label">INQUIRIES <span>▢</span></div>
            <strong><?= $inquiryCount > 0 ? $inquiryCount : 'Support' ?></strong>
            <small><?= $inquiryCount > 0 ? 'Open support conversation' . ($inquiryCount > 1 ? 's' : '') : 'Contact Security Custodian' ?></small>
        </a>
    </section>

    <section class="dashboard-columns">
        <div class="dashboard-panel">
            <div class="dashboard-panel-head">
                <h2>▧ &nbsp; My Reports Status</h2>
                <a href="my_reports.php">View All →</a>
            </div>
            <?php if ($myReports): ?>
                <?php foreach (array_slice($myReports, 0, 3) as $r): ?>
                    <a class="dashboard-report-row" href="my_reports.php">
                        <div class="report-main">
                            <div class="report-meta">
                                <span class="report-type-pill <?= h($r['report_type']) ?>"><?= strtoupper(h($r['report_type'])) ?></span>
                                <span>RPT-<?= date('Y', strtotime($r['created_at'])) ?>-<?= str_pad((string)$r['id'], 3, '0', STR_PAD_LEFT) ?></span>
                            </div>
                            <strong><?= h($r['item_name']) ?></strong>
                            <small><?= h($r['campus_location']) ?><?= $r['building'] ? ', ' . h($r['building']) : '' ?><?= $r['floor'] ? ', ' . h($r['floor']) : '' ?><?= $r['specific_area'] ? ' ' . h($r['specific_area']) : '' ?></small>
                        </div>
                        <?php [$reportStatusClass, $reportStatusLabel] = dashboard_report_status($r, $matchByLostReport); ?>
                        <span class="status <?= h($reportStatusClass) ?>"><?= h($reportStatusLabel) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="dashboard-empty">
                    <strong>No reports yet</strong>
                    <p>Your submitted lost or found reports will appear here.</p>
                    <a class="dashboard-link-btn" href="report.php?type=lost">Report an Item →</a>
                </div>
            <?php endif; ?>
        </div>

        <div class="dashboard-panel">
            <div class="dashboard-panel-head">
                <h2>⌕ &nbsp; Recently Turned-In Found Items</h2>
                <a href="search.php?type=found">Explore All →</a>
            </div>
            <?php foreach ($recentFound as $r): ?>
                <?php $photo = dashboard_image($r); ?>
                <a class="found-item-row" href="item.php?id=<?= (int)$r['id'] ?>">
                    <div class="found-thumb">
                        <?php if ($photo): ?>
                            <img src="<?= h($photo) ?>" alt="<?= h($r['item_name']) ?>">
                        <?php else: ?>
                            <?= h(strtoupper(substr($r['item_name'], 0, 1))) ?>
                        <?php endif; ?>
                    </div>
                    <div class="found-item-copy">
                        <span><?= h($r['category']) ?></span>
                        <strong><?= h($r['item_name']) ?></strong>
                        <small><?= h($r['campus_location']) ?><?= $r['building'] ? ', ' . h($r['building']) : '' ?><?= $r['specific_area'] ? ', ' . h($r['specific_area']) : '' ?></small>
                    </div>
                    <b>View →</b>
                </a>
            <?php endforeach; ?>
            <?php if (!$recentFound): ?>
                <div class="dashboard-empty compact"><strong>No found items yet</strong><p>Newly reported found items will appear here.</p></div>
            <?php endif; ?>
        </div>
    </section>

    <!-- =========================================================
         FIND IT PROCESS — DASHBOARD GUIDE
         ========================================================= -->
    <section class="dashboard-procedure" aria-labelledby="findit-process-title">
        <div class="dashboard-procedure-head">
            <span class="dashboard-procedure-eyebrow">FIND IT PROCESS</span>
            <h2 id="findit-process-title">How Find IT Works</h2>
            <p>Follow the steps below based on whether you lost an item, found one on campus, or want to claim a Found Item.</p>
        </div>

        <div class="dashboard-procedure-grid">
            <article class="dashboard-procedure-card dashboard-procedure-lost">
                <div class="dashboard-procedure-card-head">
                    <span class="dashboard-procedure-icon">◆</span>
                    <div>
                        <span class="dashboard-procedure-kicker">IF YOU LOST AN ITEM</span>
                        <h3>Recover Your Lost Belonging</h3>
                    </div>
                </div>

                <ol class="dashboard-procedure-steps">
                    <li><strong>Report the item as Lost</strong><span>Provide accurate details about the missing item, including its last known location, date, and distinguishing features that can help verify ownership. Your Lost Report is automatically added to Find IT.</span></li>
                    <li><strong>Wait for a potential match</strong><span>Find IT automatically checks your Lost Report against eligible Found Reports. Your report remains active while the system looks for a possible match.</span></li>
                    <li><strong>Receive a potential match notification</strong><span>If Find IT identifies a possible match, the administrator reviews the automatic match percentage and details, then approves or rejects the Potential Match. You do not need to confirm the match online.</span></li>
                    <li><strong>Wait for the found item to be received</strong><span>If the Potential Match is approved, the student who reported the Found Item must first turn over the physical item to the administrator. Your status will show that you are waiting for the item to be received. No checking schedule is released yet.</span></li>
                    <li><strong>Receive your in-person checking schedule</strong><span>Once the administrator receives the physical found item, you will receive the date, time, location, and deadline for your in-person ownership checking.</span></li>
                    <li><strong>Attend the scheduled in-person checking</strong><span>Go to the designated location and bring your University/Mapúa ID and appropriate ownership evidence. The administrator will verify the item and your proof of ownership.</span></li>
                    <li><strong>Administrator records the final result</strong><span>If the item is verified as yours, the administrator completes the match and records the return. If it is not a match, the administrator records the reason, closes the match, and the Found Item can be reopened in Search &amp; Browse.</span></li>
                </ol>

                <a class="dashboard-procedure-btn" href="report.php?type=lost">Report Lost Item →</a>
            </article>

            <article class="dashboard-procedure-card dashboard-procedure-found">
                <div class="dashboard-procedure-card-head">
                    <span class="dashboard-procedure-icon">◆</span>
                    <div>
                        <span class="dashboard-procedure-kicker">IF YOU FOUND AN ITEM</span>
                        <h3>Help Return It to Its Owner</h3>
                    </div>
                </div>

                <ol class="dashboard-procedure-steps">
                    <li><strong>Report the item as Found</strong><span>Submit the found item's details, where you found it, and useful identifying information. Your Found Report is automatically added to Find IT. Keep the physical item safe while waiting for further instructions.</span></li>
                    <li><strong>Wait for a potential match notification</strong><span>Find IT automatically checks your Found Report against eligible Lost Reports. If a Potential Match is detected, the administrator reviews the automatic match percentage and details.</span></li>
                    <li><strong>Receive a turn-in schedule</strong><span>If the administrator approves the Potential Match, you will receive the date, time, location, and deadline for turning over the physical item.</span></li>
                    <li><strong>Turn over the found item to the administrator</strong><span>Bring the physical item to the designated administrator or campus Lost &amp; Found office at the scheduled time. The administrator records that the item has been received and secured.</span></li>
                    <li><strong>Found Report completed — thank you</strong><span>After the administrator receives the physical item, your Found Report is marked completed and you receive a thank-you notification. Your part is complete, and you do not need to attend the Lost Reporter's later checking schedule.</span></li>
                </ol>

                <a class="dashboard-procedure-btn" href="report.php?type=found">Report Found Item →</a>
            </article>

            <article class="dashboard-procedure-card dashboard-procedure-claim">
                <div class="dashboard-procedure-card-head">
                    <span class="dashboard-procedure-icon">◆</span>
                    <div>
                        <span class="dashboard-procedure-kicker">IF YOU WANT TO CLAIM A FOUND ITEM</span>
                        <h3>Claim an Item You Believe Is Yours</h3>
                    </div>
                </div>

                <ol class="dashboard-procedure-steps">
                    <li><strong>Find the item in Search &amp; Browse</strong><span>Browse the Found Items available in the public catalog and select the item you believe belongs to you.</span></li>
                    <li><strong>Submit an ownership claim</strong><span>Complete the claim form and provide details that can help prove the item is yours, such as distinguishing features, serial number, or other ownership evidence. Once submitted, the item is tracked under My Claims.</span></li>
                    <li><strong>Wait for administrator review</strong><span>Your claim is sent to the administrator for review. You may still submit a claim even if the Found Item has a Potential Match that has not yet been approved, and multiple students may submit claims while they are pending.</span></li>
                    <li><strong>Claim approved — receive a verification schedule</strong><span>If your claim is approved, the administrator will provide the date, time, location, and deadline for your in-person ownership verification.</span></li>
                    <li><strong>Claim rejected — review the reason</strong><span>If rejected, Find IT will show the administrator's reason. If another claimant is approved for the same item, the other pending claims for that item are automatically rejected with a clear reason.</span></li>
                    <li><strong>Attend the scheduled in-person verification</strong><span>If approved, go to the designated location with your University/Mapúa ID and appropriate ownership evidence. The administrator will verify your ownership before releasing the item.</span></li>
                    <li><strong>Item released — claim completed</strong><span>Once the administrator confirms your ownership and hands over the item, your claim is marked Completed. For questions or concerns, contact the administrator through HelpDesk/Inquiries.</span></li>
                </ol>

                <a class="dashboard-procedure-btn" href="search.php?type=found">Browse Found Items →</a>
            </article>

        </div>

        <div class="dashboard-procedure-help">
            <strong>Need help with your report or claim?</strong>
            <span>Contact the administrator through HelpDesk/Inquiries if you have questions about your item's status or next step.</span>
            <a href="messages.php">Open HelpDesk/Inquiries →</a>
        </div>
    </section>


v>


<style>
    /* =========================================================
       FIND IT PROCESS — DASHBOARD GUIDE
       ========================================================= */
    .dashboard-procedure{
        margin-top:28px;padding:26px;border:1px solid #e5e7eb;
        border-radius:22px;background:#fff;
        box-shadow:0 8px 28px rgba(15,23,42,.05)
    }
    .dashboard-procedure-head{margin-bottom:20px}
    .dashboard-procedure-eyebrow{display:block;margin-bottom:6px;font-size:11px;font-weight:900;letter-spacing:.09em;color:#8f1830}
    .dashboard-procedure-head h2{margin:0;color:#20242a;font-size:24px}
    .dashboard-procedure-head p{margin:7px 0 0;color:#5f6872;line-height:1.55}
    .dashboard-procedure-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
    .dashboard-procedure-card{border:1px solid #e5e7eb;border-radius:18px;padding:20px;background:#fcfafb}
    .dashboard-procedure-lost{border-top:4px solid #9b1c2c}
    .dashboard-procedure-found{border-top:4px solid #8f1830}
    .dashboard-procedure-claim{border-top:4px solid #8f1830}
    .dashboard-procedure-card-head{display:flex;align-items:flex-start;gap:12px;margin-bottom:17px}
    .dashboard-procedure-icon{width:34px;height:34px;min-width:34px;display:grid;place-items:center;border-radius:10px;background:#f4f5f7}
    .dashboard-procedure-kicker{display:block;margin-bottom:3px;font-size:10px;font-weight:900;letter-spacing:.07em;color:#5f6872}
    .dashboard-procedure-card h3{margin:0;font-size:17px;color:#20242a}
    .dashboard-procedure-steps{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:10px;counter-reset:dashboard-step}
    .dashboard-procedure-steps li{counter-increment:dashboard-step;position:relative;padding:12px 12px 12px 38px;border:1px solid #e2e4e7;border-radius:12px;background:#fff}
    .dashboard-procedure-steps li::before{content:counter(dashboard-step);position:absolute;left:11px;top:12px;width:21px;height:21px;display:grid;place-items:center;border-radius:50%;background:#f7e8eb;color:#9b1c2c;font-size:10px;font-weight:900}
    .dashboard-procedure-steps strong{display:block;margin-bottom:3px;font-size:12px;color:#252a30}
    .dashboard-procedure-steps span{display:block;font-size:11px;line-height:1.5;color:#5f6872}
    .dashboard-procedure-btn{display:inline-flex;align-items:center;justify-content:center;margin-top:16px;padding:10px 14px;border-radius:10px;background:#9b1c2c;color:#fff;text-decoration:none;font-size:11px;font-weight:800}
    .dashboard-procedure-btn:hover{background:#741321}
    .dashboard-procedure-help{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:18px;padding:15px 16px;border-radius:14px;background:#f4f5f7;color:#5f6872;font-size:11px}
    .dashboard-procedure-help strong{color:#252a30}
    .dashboard-procedure-help a{margin-left:auto;color:#9b1c2c;font-weight:800;text-decoration:none}
    @media(max-width:1050px){.dashboard-procedure-grid{grid-template-columns:1fr 1fr}}
    @media(max-width:850px){.dashboard-procedure-grid{grid-template-columns:1fr}.dashboard-procedure{padding:20px}}
    @media(max-width:640px){.dashboard-procedure-help a{margin-left:0;width:100%}}

.student-match-next-step{margin-top:12px;font-weight:700;color:#741321}
.student-match-modal{position:fixed;inset:0;background:rgba(15,23,42,.62);display:none;align-items:center;justify-content:center;padding:24px;z-index:9999}
.student-match-modal.open{display:flex}
.student-match-dialog{position:relative;width:min(680px,100%);background:#fff;border-radius:24px;padding:30px;box-shadow:0 24px 80px rgba(15,23,42,.28)}
.student-match-close{position:absolute;right:18px;top:14px;border:0;background:transparent;font-size:30px;cursor:pointer;color:#5f6872}
.student-match-eyebrow{font-size:12px;font-weight:800;letter-spacing:.08em;color:#8a6518}
.student-match-dialog h2{margin:8px 0 8px;font-size:28px;color:#20242a}
.student-match-lead{margin:0 0 22px;color:#5f6872;line-height:1.6}
.student-match-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.student-match-detail-grid>div{border:1px solid #e5e7eb;border-radius:14px;padding:14px}
.student-match-detail-grid span{display:block;font-size:12px;color:#5f6872;margin-bottom:5px}.student-match-detail-grid strong{color:#20242a}
.student-match-confirm-form,.student-match-schedule,.student-match-awaiting{margin-top:18px;border-radius:16px;padding:18px;background:#fffaf0;border:1px solid #d7b85a}
.student-match-confirm-form p{margin:0 0 14px;color:#5c4d27;line-height:1.55}.student-match-confirm-form button{border:0;border-radius:11px;padding:12px 18px;background:#9b1c2c;color:#fff;font-weight:800;cursor:pointer}
.student-match-schedule{display:flex;flex-direction:column;gap:5px;background:#f7e8eb;border-color:#e4c3ca;color:#741321}.student-match-schedule strong{font-size:15px}.student-match-schedule small{margin-top:4px;color:#7b5961}
.student-match-completed{margin-top:18px;border-radius:16px;padding:18px;background:#f1f7f3;border:1px solid #d6e5dc;color:#38624f;display:flex;flex-direction:column;gap:5px}.student-match-completed strong{font-size:15px}.student-match-completion{margin-top:18px;border-radius:16px;padding:18px;background:#f1f7f3;border:1px solid #d6e5dc;color:#38624f;display:flex;flex-direction:column;gap:6px}.student-match-completion strong{font-size:15px}.student-match-completion small{margin-top:4px;color:#5b756a}
.student-match-awaiting{background:#f7e8eb;border-color:#e4c3ca;color:#741321;font-weight:700}
.student-match-close-text{margin-top:18px;border:0;background:transparent;color:#5f6872;font-weight:700;cursor:pointer}
@media(max-width:640px){.student-match-detail-grid{grid-template-columns:1fr}.student-match-dialog{padding:24px}}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
    const modal=document.getElementById('dashboard-match-modal');
    const open=document.getElementById('dashboard-match-inspect');
    const close=document.getElementById('dashboard-match-close');
    const closeText=document.getElementById('dashboard-match-close-text');
    if(!modal||!open)return;
    function show(){modal.classList.add('open');modal.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';}
    function hide(){modal.classList.remove('open');modal.setAttribute('aria-hidden','true');document.body.style.overflow='';}
    open.addEventListener('click',show); if(close)close.addEventListener('click',hide); if(closeText)closeText.addEventListener('click',hide);
    modal.addEventListener('click',function(e){if(e.target===modal)hide();});
    document.addEventListener('keydown',function(e){if(e.key==='Escape'&&modal.classList.contains('open'))hide();});
});
</script>

<?php require 'includes/footer.php'; ?>

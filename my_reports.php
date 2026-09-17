<?php

require 'config.php';
require_login();

/* Student confirmation fields for administrator-approved matches. */
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
try {
    $pdo->exec("ALTER TABLE potential_matches ADD COLUMN verification_outcome TEXT");
} catch (Throwable $e) {}
try {
    $pdo->exec("ALTER TABLE potential_matches ADD COLUMN verification_outcome_reason TEXT");
} catch (Throwable $e) {}
try {
    $pdo->exec("ALTER TABLE potential_matches ADD COLUMN found_turnover_start_date TEXT");
} catch (Throwable $e) {}
try {
    $pdo->exec("ALTER TABLE potential_matches ADD COLUMN found_turnover_deadline TEXT");
} catch (Throwable $e) {}
try {
    $pdo->exec("ALTER TABLE potential_matches ADD COLUMN found_turnover_location TEXT");
} catch (Throwable $e) {}
try {
    $pdo->exec("ALTER TABLE potential_matches ADD COLUMN found_handover_at TEXT");
} catch (Throwable $e) {}

$pageTitle = 'My Reports';

$stmt = $pdo->prepare("    SELECT
        r.*,
        i.item_name,
        i.category,
        i.description,
        i.color,
        i.brand,
        i.identifying_features,
        i.photo_path,
        (
            SELECT u.full_name
            FROM status_history sh
            JOIN users u ON u.id = sh.changed_by
            WHERE sh.report_id = r.id
            ORDER BY sh.created_at ASC
            LIMIT 1
        ) AS approved_by_name,
        (
            SELECT sh.created_at
            FROM status_history sh
            WHERE sh.report_id = r.id
            ORDER BY sh.created_at ASC
            LIMIT 1
        ) AS approved_at,
        (
            SELECT pm.id FROM potential_matches pm
            WHERE pm.lost_report_id=r.id AND pm.match_status IN ('confirmed','rejected')
            ORDER BY CASE WHEN pm.match_status='confirmed' THEN 0 ELSE 1 END, pm.match_score DESC, pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS potential_match_id,
        (
            SELECT pm.match_status FROM potential_matches pm
            WHERE pm.lost_report_id=r.id AND pm.match_status IN ('confirmed','rejected')
            ORDER BY CASE WHEN pm.match_status='confirmed' THEN 0 ELSE 1 END, pm.match_score DESC, pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS potential_match_status,
        (
            SELECT pm.match_score FROM potential_matches pm
            WHERE pm.lost_report_id=r.id AND pm.match_status IN ('confirmed','rejected')
            ORDER BY CASE WHEN pm.match_status='confirmed' THEN 0 ELSE 1 END, pm.match_score DESC, pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS match_score,
        (
            SELECT pm.user_confirmed_at FROM potential_matches pm
            WHERE pm.lost_report_id=r.id AND pm.match_status IN ('confirmed','rejected')
            ORDER BY CASE WHEN pm.match_status='confirmed' THEN 0 ELSE 1 END, pm.match_score DESC, pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS match_user_confirmed_at,
        (
            SELECT pm.verification_date FROM potential_matches pm
            WHERE pm.lost_report_id=r.id AND pm.match_status IN ('confirmed','rejected')
            ORDER BY CASE WHEN pm.match_status='confirmed' THEN 0 ELSE 1 END, pm.match_score DESC, pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS match_verification_date,
        (
            SELECT pm.verification_time FROM potential_matches pm
            WHERE pm.lost_report_id=r.id AND pm.match_status IN ('confirmed','rejected')
            ORDER BY CASE WHEN pm.match_status='confirmed' THEN 0 ELSE 1 END, pm.match_score DESC, pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS match_verification_time,
        (
            SELECT pm.verification_deadline FROM potential_matches pm
            WHERE pm.lost_report_id=r.id AND pm.match_status IN ('confirmed','rejected')
            ORDER BY CASE WHEN pm.match_status='confirmed' THEN 0 ELSE 1 END, pm.match_score DESC, pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS match_verification_deadline,
        (
            SELECT pm.verification_location FROM potential_matches pm
            WHERE pm.lost_report_id=r.id AND pm.match_status IN ('confirmed','rejected')
            ORDER BY CASE WHEN pm.match_status='confirmed' THEN 0 ELSE 1 END, pm.match_score DESC, pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS match_verification_location,
        (
            SELECT pm.admin_remarks FROM potential_matches pm
            WHERE pm.lost_report_id=r.id AND pm.match_status IN ('confirmed','rejected')
            ORDER BY CASE WHEN pm.match_status='confirmed' THEN 0 ELSE 1 END, pm.match_score DESC, pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS match_admin_remarks,
        (
            SELECT pm.verification_outcome FROM potential_matches pm
            WHERE pm.lost_report_id=r.id AND pm.match_status IN ('confirmed','rejected')
            ORDER BY CASE WHEN pm.match_status='confirmed' THEN 0 ELSE 1 END, pm.match_score DESC, pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS match_verification_outcome,
        (
            SELECT pm.verification_outcome_reason FROM potential_matches pm
            WHERE pm.lost_report_id=r.id AND pm.match_status IN ('confirmed','rejected')
            ORDER BY CASE WHEN pm.match_status='confirmed' THEN 0 ELSE 1 END, pm.match_score DESC, pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS match_verification_outcome_reason,
        (
            SELECT pm.handover_at FROM potential_matches pm
            WHERE pm.lost_report_id=r.id AND pm.match_status IN ('confirmed','rejected')
            ORDER BY CASE WHEN pm.match_status='confirmed' THEN 0 ELSE 1 END, pm.match_score DESC, pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS match_handover_at,
        (
            SELECT pm.handover_evidence FROM potential_matches pm
            WHERE pm.lost_report_id=r.id AND pm.match_status IN ('confirmed','rejected')
            ORDER BY CASE WHEN pm.match_status='confirmed' THEN 0 ELSE 1 END, pm.match_score DESC, pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS match_handover_evidence,
        (
            SELECT pm.handover_notes FROM potential_matches pm
            WHERE pm.lost_report_id=r.id AND pm.match_status IN ('confirmed','rejected')
            ORDER BY CASE WHEN pm.match_status='confirmed' THEN 0 ELSE 1 END, pm.match_score DESC, pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS match_handover_notes,
        (
            SELECT fi.item_name
            FROM potential_matches pm
            JOIN reports fr ON fr.id=pm.found_report_id
            JOIN items fi ON fi.id=fr.item_id
            WHERE pm.lost_report_id=r.id AND pm.match_status IN ('confirmed','rejected')
            ORDER BY CASE WHEN pm.match_status='confirmed' THEN 0 ELSE 1 END, pm.match_score DESC, pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS matched_found_item_name,
        (
            SELECT pm.match_status FROM potential_matches pm
            WHERE pm.found_report_id=r.id AND pm.match_status IN ('confirmed','rejected')
            ORDER BY CASE WHEN pm.match_status='confirmed' THEN 0 ELSE 1 END, pm.match_score DESC, pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS found_match_status,
        (
            SELECT pm.found_turnover_start_date FROM potential_matches pm
            WHERE pm.found_report_id=r.id AND pm.match_status='confirmed'
            ORDER BY pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS found_turnover_start_date,
        (
            SELECT pm.found_turnover_deadline FROM potential_matches pm
            WHERE pm.found_report_id=r.id AND pm.match_status='confirmed'
            ORDER BY pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS found_turnover_deadline,
        (
            SELECT pm.found_turnover_location FROM potential_matches pm
            WHERE pm.found_report_id=r.id AND pm.match_status='confirmed'
            ORDER BY pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS found_turnover_location,
        (
            SELECT pm.found_handover_at FROM potential_matches pm
            WHERE pm.found_report_id=r.id AND pm.match_status='confirmed'
            ORDER BY pm.updated_at DESC, pm.id DESC LIMIT 1
        ) AS found_handover_at
    FROM reports r
    JOIN items i ON i.id = r.item_id
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC
");

$stmt->execute([current_user()['id']]);
$rows = $stmt->fetchAll();

require 'includes/header.php';


/* =========================================================
   HELPERS
========================================================= */

function my_report_location(array $r): string
{
    $parts = [];

    if (!empty($r['campus_location'])) {
        $parts[] = $r['campus_location'];
    }

    if (!empty($r['building'])) {
        $parts[] = $r['building'];
    }

    if (!empty($r['specific_area'])) {
        $parts[] = $r['specific_area'];
    }

    return implode(', ', $parts);
}


function my_report_image(array $r): string
{
    $photo = trim((string)($r['photo_path'] ?? ''));

    if ($photo !== '') {
        return $photo;
    }

    /*
     * Fallback to existing custom item image.
     */
    $imageSlug = strtolower(trim((string)$r['item_name']));
    $imageSlug = preg_replace('/[^a-z0-9]+/i', '-', $imageSlug);
    $imageSlug = trim($imageSlug, '-');

    $extensions = ['jpg', 'jpeg', 'png', 'webp'];

    $directory = __DIR__ . '/uploads/Items/';
    $urlDirectory = 'uploads/Items/';

    foreach ($extensions as $extension) {

        $candidate = $directory . $imageSlug . '.' . $extension;

        if (is_file($candidate)) {
            return $urlDirectory . $imageSlug . '.' . $extension;
        }
    }

    /*
     * Try lowercase directory as well.
     */
    $directory = __DIR__ . '/uploads/items/';
    $urlDirectory = 'uploads/items/';

    foreach ($extensions as $extension) {

        $candidate = $directory . $imageSlug . '.' . $extension;

        if (is_file($candidate)) {
            return $urlDirectory . $imageSlug . '.' . $extension;
        }
    }

    return '';
}


function my_report_status(array $r): array
{
    $reportStatus = strtolower((string)$r['report_status']);
    $itemStatus = strtolower((string)$r['item_status']);
    $type = strtolower((string)$r['report_type']);

    /* A physically returned/closed item takes priority over legacy match workflow fields. */
    if ($itemStatus === 'returned') {
        return ['Returned', 'returned'];
    }

    if ($itemStatus === 'closed' || $reportStatus === 'rejected') {
        return [$reportStatus === 'rejected' ? 'Rejected' : 'Closed', 'closed'];
    }

    /* Found reporters follow a separate turnover stage before owner release. */
    if ($type === 'found' && ($r['found_match_status'] ?? '') === 'confirmed') {
        if (!empty($r['found_handover_at'])) {
            return ['Item Received by Administrator', 'received'];
        }
        if (!empty($r['found_turnover_start_date']) || !empty($r['found_turnover_deadline'])) {
            return ['Turnover Scheduled', 'turnover'];
        }
        return ['Match Approved — Awaiting Turnover', 'match'];
    }

    /* Administrator match decisions lead to in-person verification; no online student confirmation is required. */
    if ($type === 'lost' && ($r['match_verification_outcome'] ?? '') === 'completed') {
        return ['Completed — Item Returned', 'returned'];
    }

    if ($type === 'lost' && ($r['match_verification_outcome'] ?? '') === 'not_match') {
        return ['Not a Match — Found Item Reopened', 'closed'];
    }

    if ($type === 'lost' && ($r['match_verification_outcome'] ?? '') === 'reschedule') {
        return ['Verification Needs Rescheduling', 'match'];
    }

    if ($type === 'lost' && ($r['potential_match_status'] ?? '') === 'rejected') {
        return ['Match Dismissed', 'closed'];
    }

    if ($type === 'lost' && ($r['potential_match_status'] ?? '') === 'confirmed') {
        if (!empty($r['match_verification_date'])) {
            return ['Checking Scheduled', 'scheduled'];
        }
        return ['Potential Match Found — Awaiting Schedule', 'match'];
    }

    /*
     * Reports are automatically added to the system.
     * Lost reports remain active while waiting for a match.
     */
    if (
        $type === 'lost' &&
        $itemStatus === 'open'
    ) {
        return ['Waiting for Match', 'open'];
    }

    if (
        $type === 'found' &&
        $itemStatus === 'open'
    ) {
        return ['Open — Available for Matching', 'open'];
    }

    if ($itemStatus === 'matched') {
        return ['Potential Match Detected', 'match'];
    }

    if ($itemStatus === 'claim_pending') {
        return ['Claim Pending', 'scheduled'];
    }

    return ['Open & Available', 'open'];
}

?>

<div class="my-reports-page">

    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div class="my-reports-head">

        <div>

            <span class="my-reports-eyebrow">
                MY ACTIVITY
            </span>

            <h1>
                My Submitted Reports
            </h1>

            <p>
                Track your submitted items and follow potential matches, in-person checking schedules, and return updates
            </p>

        </div>


        <a
            class="my-reports-submit-btn"
            href="report.php?type=lost"
        >
            <span>＋</span>
            Submit New Report
        </a>

    </div>


    <!-- =====================================================
         REPORT LIST
    ====================================================== -->

    <div class="my-reports-list">

        <?php if (!$rows): ?>

            <div class="my-reports-empty">

                <h2>
                    No reports yet
                </h2>

                <p>
                    You haven't submitted any lost or found item reports.
                </p>

                <a
                    class="btn primary"
                    href="report.php?type=lost"
                >
                    Submit Your First Report
                </a>

            </div>

        <?php endif; ?>


        <?php foreach ($rows as $r): ?>

            <?php

            $type = strtolower((string)$r['report_type']);

            $image = my_report_image($r);

            [$modalStatus, $modalStatusClass] =
                my_report_status($r);

            $location = my_report_location($r);

            $itemCode =
                'ITM-' .
                str_pad(
                    (string)$r['item_id'],
                    3,
                    '0',
                    STR_PAD_LEFT
                );

            /*
             * Displayed report status on the card.
             */
            $reportStatus =
                strtolower((string)$r['report_status']);

            $itemStatus =
                strtolower((string)$r['item_status']);

            if ($itemStatus === 'returned') {
                $cardStatus = 'Returned';
                $cardStatusClass = 'returned';
            }
            elseif ($itemStatus === 'closed' || $reportStatus === 'rejected') {
                $cardStatus = ($reportStatus === 'rejected') ? 'Rejected' : 'Closed';
                $cardStatusClass = 'rejected';
            }
            elseif ($type === 'found' && ($r['found_match_status'] ?? '') === 'confirmed' && !empty($r['found_handover_at'])) {
                $cardStatus = 'Item Received by Administrator';
                $cardStatusClass = 'received';
            }
            elseif ($type === 'found' && ($r['found_match_status'] ?? '') === 'confirmed' && (!empty($r['found_turnover_start_date']) || !empty($r['found_turnover_deadline']))) {
                $cardStatus = 'Turnover Scheduled';
                $cardStatusClass = 'turnover';
            }
            elseif ($type === 'found' && ($r['found_match_status'] ?? '') === 'confirmed') {
                $cardStatus = 'Match Approved — Awaiting Turnover';
                $cardStatusClass = 'match-required';
            }
            elseif ($type === 'lost' && ($r['match_verification_outcome'] ?? '') === 'completed') {
                $cardStatus = 'Completed — Item Returned';
                $cardStatusClass = 'returned';
            }
            elseif ($type === 'lost' && ($r['match_verification_outcome'] ?? '') === 'not_match') {
                $cardStatus = 'Not a Match — Found Item Reopened';
                $cardStatusClass = 'rejected';
            }
            elseif ($type === 'lost' && ($r['match_verification_outcome'] ?? '') === 'reschedule') {
                $cardStatus = 'Verification Needs Rescheduling';
                $cardStatusClass = 'match-required';
            }
            elseif ($type === 'lost' && ($r['potential_match_status'] ?? '') === 'rejected') {
                $cardStatus = 'Match Dismissed';
                $cardStatusClass = 'rejected';
            }
            elseif ($type === 'lost' && ($r['potential_match_status'] ?? '') === 'confirmed' && !empty($r['match_verification_date'])) {
                $cardStatus = 'Checking Scheduled';
                $cardStatusClass = 'scheduled';
            }
            elseif ($type === 'lost' && ($r['potential_match_status'] ?? '') === 'confirmed') {
                $cardStatus = 'Potential Match Found — Awaiting Schedule';
                $cardStatusClass = 'match-required';
            }
            elseif ($type === 'lost' && $itemStatus === 'open') {
                $cardStatus = 'Waiting for Match';
                $cardStatusClass = 'approved-active';
            }
            elseif ($type === 'found' && $itemStatus === 'open') {
                $cardStatus = 'Open — Available for Matching';
                $cardStatusClass = 'approved-active';
            }
            elseif ($reportStatus === 'rejected') {
                $cardStatus = 'Rejected';
                $cardStatusClass = 'rejected';
            }
            else {
                $cardStatus = 'Pending Review';
                $cardStatusClass = 'pending';
            }


            /*
             * Data passed to the modal.
             */
            $modalData = [

                'reportId' =>
                    (int)$r['id'],

                'type' =>
                    $type === 'lost'
                        ? 'LOST ITEM'
                        : 'FOUND ITEM',

                'id' => $itemCode,

                'image' => $image,

                'status' => $modalStatus,

                'statusClass' => $modalStatusClass,

                'category' =>
                    $r['category'] ?? '',

                'name' =>
                    $r['item_name'] ?? '',

                'description' =>
                    $r['description']
                    ?: 'No description provided.',

                'features' =>
                    $r['identifying_features']
                    ?: '',

                'location' =>
                    $location,

                'date' =>
                    $r['event_date'] ?? '',

                'time' =>
                    $r['event_time'] ?? '',

                'storageLocation' =>
                    $r['storage_location'] ?? '',

                'approvedBy' => '',

                'approvedAt' => '',

                'reportType' =>
                    $type,

                'potentialMatchId' =>
                    (int)($r['potential_match_id'] ?? 0),

                'matchStatus' =>
                    $r['potential_match_status'] ?? '',

                'matchScore' =>
                    (int)($r['match_score'] ?? 0),

                'matchedFoundItemName' =>
                    $r['matched_found_item_name'] ?? '',

                'foundMatchStatus' =>
                    $r['found_match_status'] ?? '',

                'foundTurnoverStartDate' =>
                    $r['found_turnover_start_date'] ?? '',

                'foundTurnoverDeadline' =>
                    $r['found_turnover_deadline'] ?? '',

                'foundTurnoverLocation' =>
                    $r['found_turnover_location'] ?? '',

                'foundHandoverAt' =>
                    $r['found_handover_at'] ?? '',

                'matchVerificationDate' =>
                    $r['match_verification_date'] ?? '',

                'matchVerificationTime' =>
                    $r['match_verification_time'] ?? '',

                'matchVerificationDeadline' =>
                    $r['match_verification_deadline'] ?? '',

                'matchVerificationLocation' =>
                    $r['match_verification_location'] ?? '',

                'matchAdminRemarks' =>
                    $r['match_admin_remarks'] ?? '',

                'matchVerificationOutcome' =>
                    $r['match_verification_outcome'] ?? '',

                'matchVerificationOutcomeReason' =>
                    $r['match_verification_outcome_reason'] ?? '',

                'matchHandoverAt' =>
                    $r['match_handover_at'] ?? '',

                'matchHandoverEvidence' =>
                    $r['match_handover_evidence'] ?? '',

                'matchHandoverNotes' =>
                    $r['match_handover_notes'] ?? '',

                'itemId' =>
                    (int)$r['item_id']

            ];

            ?>

            <article id="my-report-<?= (int)$r['id'] ?>" class="my-report-card" data-report-id="<?= (int)$r['id'] ?>">

                <!-- IMAGE -->

                <div class="my-report-image">

                    <?php if ($image): ?>

                        <img
                            src="<?= h($image) ?>"
                            alt="<?= h($r['item_name']) ?>"
                        >

                    <?php else: ?>

                        <span>
                            <?= h(
                                strtoupper(
                                    substr(
                                        $r['item_name'],
                                        0,
                                        1
                                    )
                                )
                            ) ?>
                        </span>

                    <?php endif; ?>

                </div>


                <!-- CONTENT -->

                <div class="my-report-content">

                    <div class="my-report-meta">

                        <span
                            class="my-report-type <?= h($type) ?>"
                        >
                            <?= strtoupper($type) ?> REPORT
                        </span>

                        <span class="my-report-id">
                            RPT-<?= str_pad(
                                (string)$r['id'],
                                4,
                                '0',
                                STR_PAD_LEFT
                            ) ?>
                        </span>

                        <span class="my-report-separator">
                            •
                        </span>

                        <span class="my-report-category">
                            <?= h($r['category']) ?>
                        </span>

                    </div>


                    <h2>
                        <?= h($r['item_name']) ?>
                    </h2>


                    <p class="my-report-description">
                        <?= h($r['description']) ?>
                    </p>


                    <div class="my-report-details">

                        <?php if ($location): ?>

                            <span class="my-report-detail">

                                <span class="detail-icon">
                                    ♧
                                </span>

                                <?= h($location) ?>

                            </span>

                        <?php endif; ?>


                        <span class="my-report-detail">

                            <span class="detail-icon">
                                ▣
                            </span>

                            <?= h($r['event_date']) ?>

                            <?php if (!empty($r['event_time'])): ?>

                                at <?= h($r['event_time']) ?>

                            <?php endif; ?>

                        </span>

                    </div>


                    <?php if (!empty($r['identifying_features'])): ?>

                        <div class="my-report-features">

                            <strong>
                                Distinguishing marks:
                            </strong>

                            <span>
                                <?= h(
                                    $r['identifying_features']
                                ) ?>
                            </span>

                        </div>

                    <?php endif; ?>

                </div>


                <!-- RIGHT SIDE -->

                <div class="my-report-side">

                    <span
                        class="my-report-status <?= h($cardStatusClass) ?>"
                    >

                        <?php if ($cardStatusClass === 'approved-active'): ?>

                            <span class="status-check">
                                ✓
                            </span>

                        <?php elseif ($cardStatusClass === 'rejected'): ?>

                            <span class="status-x">
                                ×
                            </span>

                        <?php else: ?>

                            <span class="status-dot"></span>

                        <?php endif; ?>

                        <?= h($cardStatus) ?>

                    </span>


                    <?php if (
                        $reportStatus === 'approved'
                    ): ?>

                        <button
                            type="button"
                            class="my-report-view"
                            data-item='<?= h(
                                json_encode(
                                    $modalData,
                                    JSON_UNESCAPED_SLASHES |
                                    JSON_UNESCAPED_UNICODE
                                )
                            ) ?>'
                        >
                            <?= $itemStatus === 'returned' ? 'View Return Status' : (($type === 'lost' && ($r['potential_match_status'] ?? '') === 'confirmed') ? 'View Match & Status' : 'View Active Item') ?>
                            <span>↗</span>
                        </button>

                    <?php elseif (
                        $reportStatus === 'rejected'
                    ): ?>

                        <span class="my-report-side-note">
                            See rejection reason
                        </span>

                    <?php else: ?>

                        <span class="my-report-side-note">
                            Active in system
                        </span>

                    <?php endif; ?>

                </div>

            </article>

        <?php endforeach; ?>

    </div>

</div>


<!-- =========================================================
     ITEM DETAILS MODAL
========================================================= -->

<div
    id="my-report-item-modal"
    class="my-report-modal"
    aria-hidden="true"
>

    <div
        class="my-report-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="my-report-modal-title"
    >

        <!-- HEADER -->

        <div class="my-report-dialog-head">

            <div class="my-report-dialog-tags">

                <span
                    id="my-report-modal-type"
                    class="my-report-dialog-type"
                >
                    LOST ITEM
                </span>

                <span
                    id="my-report-modal-id"
                    class="my-report-dialog-id"
                >
                    ID: ITM-000
                </span>

            </div>


            <button
                id="my-report-modal-close"
                type="button"
                class="my-report-dialog-close"
                aria-label="Close"
            >
                ×
            </button>

        </div>


        <!-- MAIN -->

        <div class="my-report-dialog-main">

            <div class="my-report-dialog-image">

                <img
                    id="my-report-modal-image"
                    src=""
                    alt=""
                >

                <div
                    id="my-report-modal-placeholder"
                    class="my-report-placeholder"
                >
                    I
                </div>

            </div>


            <div>

                <span
                    id="my-report-modal-status"
                    class="my-report-dialog-status"
                >
                    Potential Match Detected
                </span>


                <span
                    id="my-report-modal-category"
                    class="my-report-dialog-category"
                >
                    Electronics
                </span>


                <h2 id="my-report-modal-title">
                    Item Name
                </h2>


                <p id="my-report-modal-description">
                    Item description.
                </p>


                <div class="my-report-dialog-meta">

                    <div>

                        <strong>
                            <svg
                                width="13"
                                height="13"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path
                                    d="M12 21s-7-7.05-7-11.5A7 7 0 0 1 19 9.5C19 13.95 12 21 12 21z"
                                />
                                <circle
                                    cx="12"
                                    cy="9.5"
                                    r="2.2"
                                />
                            </svg>
                        </strong>

                        <span id="my-report-modal-location">
                            Campus
                        </span>

                    </div>


                    <div>

                        <strong>
                            <svg
                                width="13"
                                height="13"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <rect
                                    x="3"
                                    y="5"
                                    width="18"
                                    height="16"
                                    rx="2"
                                />
                                <line
                                    x1="16"
                                    y1="3"
                                    x2="16"
                                    y2="7"
                                />
                                <line
                                    x1="8"
                                    y1="3"
                                    x2="8"
                                    y2="7"
                                />
                                <line
                                    x1="3"
                                    y1="10"
                                    x2="21"
                                    y2="10"
                                />
                            </svg>
                        </strong>

                        <span id="my-report-modal-date">
                            Date
                        </span>

                    </div>

                </div>

            </div>

        </div>


        <!-- STORAGE -->

        <div class="my-report-dialog-info">

            <strong>

                <span class="my-report-dialog-icon">
                    <svg
                        width="14"
                        height="14"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                    >
                        <path
                            d="M21 8l-9-5-9 5 9 5 9-5z"
                        />

                        <path
                            d="M3 8v8l9 5 9-5V8"
                        />

                        <path
                            d="M12 13v8"
                        />
                    </svg>
                </span>

                Physical Custody Storage Location

            </strong>


            <p id="my-report-modal-storage">
                Items requiring verification are secured by the
                campus lost &amp; found administrator before release.
            </p>


            <span
                id="my-report-modal-approved"
                class="my-report-dialog-approved"
            ></span>

        </div>


        <!-- FEATURES -->

        <div class="my-report-dialog-feature">

            <strong>

                <span class="my-report-dialog-blue-icon">
                    ⓘ
                </span>

                Distinctive Features Logged in System

            </strong>


            <p id="my-report-modal-feature">
                Refer to the item description and identifying
                details during ownership verification.
            </p>

        </div>


        <!-- FOUND REPORTER TURNOVER STATUS -->
        <div id="my-report-found-turnover" class="my-report-found-turnover" style="display:none">
            <strong id="my-report-found-turnover-title">Found Item Turnover</strong>
            <p id="my-report-found-turnover-message"></p>
            <div class="my-report-found-turnover-details">
                <span id="my-report-found-turnover-start"></span>
                <span id="my-report-found-turnover-deadline"></span>
                <span id="my-report-found-turnover-location"></span>
                <span id="my-report-found-turnover-received"></span>
            </div>
        </div>

        <!-- MATCH VERIFICATION / SCHEDULE -->
        <div id="my-report-match-action" class="my-report-match-action" style="display:none">
            <div id="my-report-match-completed" class="my-report-match-completed" style="display:none">
                <strong>✓ In-Person Verification Completed — Item Successfully Returned</strong>
                <span id="my-report-match-completed-at"></span>
                <span id="my-report-match-completed-location"></span>
                <small id="my-report-match-completed-note"></small>
            </div>

            <div id="my-report-match-awaiting" class="my-report-match-awaiting" style="display:none"></div>

            <div id="my-report-match-schedule" class="my-report-match-schedule" style="display:none">
                <strong>In-Person Checking Schedule Released</strong>
                <span id="my-report-match-schedule-date"></span>
                <span id="my-report-match-schedule-location"></span>
                <small id="my-report-match-schedule-deadline"></small>
            </div>

            <div id="my-report-match-outcome" class="my-report-match-outcome" style="display:none">
                <strong id="my-report-match-outcome-title"></strong>
                <span id="my-report-match-outcome-reason"></span>
            </div>
        </div>

        <!-- ACTIONS -->

        <div class="my-report-dialog-actions">

            <button
                id="my-report-modal-ask"
                type="button"
                class="my-report-dialog-ask"
            >
                <svg
                    width="13"
                    height="13"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                >
                    <path
                        d="M21 11.5a8.38 8.38 0 0 1-.9 3.8
                        8.5 8.5 0 0 1-7.6 4.7
                        8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7
                        a8.38 8.38 0 0 1-.9-3.8
                        8.5 8.5 0 0 1 4.7-7.6
                        8.38 8.38 0 0 1 3.8-.9h.5
                        a8.48 8.48 0 0 1 8 8v.5z"
                    />
                </svg>

                Ask Administrator About This Item

            </button>


            <div class="my-report-dialog-actions-right">

                <button
                    id="my-report-modal-close-text"
                    type="button"
                    class="my-report-dialog-close-text"
                >
                    Close
                </button>

            </div>

        </div>

    </div>

</div>


<style>
.my-report-found-turnover{margin:0 28px 14px;border:1px solid #d9e0e7;border-radius:15px;padding:16px;background:#f7f9fb;color:#26313d}.my-report-found-turnover strong{display:block;font-size:15px;margin-bottom:5px}.my-report-found-turnover p{margin:0 0 10px;color:#5f6c78;font-size:12px;line-height:1.5}.my-report-found-turnover-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;font-size:11px}.my-report-found-turnover-details span{padding:9px 10px;border:1px solid #e3e8ed;border-radius:9px;background:#fff;color:#53606c}.my-report-found-turnover-details span:empty{display:none}.my-report-found-turnover.turnover{border-color:#e4c55a;background:#fffaf0}.my-report-found-turnover.received{border-color:#bcefdc;background:#effbf5}.my-report-match-action{margin:0 28px 20px}
.my-report-match-awaiting,.my-report-match-schedule,.my-report-match-outcome{border:1px solid #e4c55a;border-radius:15px;padding:16px;background:#fff9df;color:#5f4b10}
.my-report-match-awaiting{font-weight:700}
.rejected-match-note{background:#fff1f2;border-color:#fecdd3;color:#be123c}
.my-report-match-schedule{display:flex;flex-direction:column;gap:5px;background:#eef2ff;border-color:#c7c3fa;color:#3730a3}
.my-report-match-completed{display:flex;flex-direction:column;gap:6px;margin-bottom:10px;background:#e7faf2;border-color:#bcefdc;color:#087b59}.my-report-match-completed strong{font-size:15px}.my-report-match-completed small{color:#4d806e;margin-top:3px}.my-report-match-outcome{display:flex;flex-direction:column;gap:6px}.my-report-match-outcome strong{font-size:15px}.my-report-match-outcome span{line-height:1.5}
.my-report-match-schedule strong{font-size:15px}.my-report-match-schedule small{color:#5b5aa0;margin-top:4px}.my-report-status.match-required{background:#fff9df;border-color:#e4c55a;color:#916900}
.my-report-status.scheduled{background:#eef2ff;border-color:#c7c3fa;color:#4338ca}
</style>

<script>

(function () {

    const modal =
        document.getElementById(
            'my-report-item-modal'
        );

    const closeButton =
        document.getElementById(
            'my-report-modal-close'
        );

    const closeText =
        document.getElementById(
            'my-report-modal-close-text'
        );

    const askButton =
        document.getElementById(
            'my-report-modal-ask'
        );

    const modalType =
        document.getElementById(
            'my-report-modal-type'
        );

    const modalId =
        document.getElementById(
            'my-report-modal-id'
        );

    const modalImage =
        document.getElementById(
            'my-report-modal-image'
        );

    const modalPlaceholder =
        document.getElementById(
            'my-report-modal-placeholder'
        );

    const modalStatus =
        document.getElementById(
            'my-report-modal-status'
        );

    const modalCategory =
        document.getElementById(
            'my-report-modal-category'
        );

    const modalTitle =
        document.getElementById(
            'my-report-modal-title'
        );

    const modalDescription =
        document.getElementById(
            'my-report-modal-description'
        );

    const modalLocation =
        document.getElementById(
            'my-report-modal-location'
        );

    const modalDate =
        document.getElementById(
            'my-report-modal-date'
        );

    const modalStorage =
        document.getElementById(
            'my-report-modal-storage'
        );

    const modalApproved =
        document.getElementById(
            'my-report-modal-approved'
        );

    const modalFeature =
        document.getElementById(
            'my-report-modal-feature'
        );

    const matchAction = document.getElementById('my-report-match-action');
    const matchCompleted = document.getElementById('my-report-match-completed');
    const matchCompletedAt = document.getElementById('my-report-match-completed-at');
    const matchCompletedLocation = document.getElementById('my-report-match-completed-location');
    const matchCompletedNote = document.getElementById('my-report-match-completed-note');
    const matchAwaiting = document.getElementById('my-report-match-awaiting');
    const matchSchedule = document.getElementById('my-report-match-schedule');
    const matchScheduleDate = document.getElementById('my-report-match-schedule-date');
    const matchScheduleLocation = document.getElementById('my-report-match-schedule-location');
    const matchScheduleDeadline = document.getElementById('my-report-match-schedule-deadline');
    const matchOutcome = document.getElementById('my-report-match-outcome');
    const matchOutcomeTitle = document.getElementById('my-report-match-outcome-title');
    const matchOutcomeReason = document.getElementById('my-report-match-outcome-reason');

    const foundTurnover = document.getElementById('my-report-found-turnover');
    const foundTurnoverTitle = document.getElementById('my-report-found-turnover-title');
    const foundTurnoverMessage = document.getElementById('my-report-found-turnover-message');
    const foundTurnoverStart = document.getElementById('my-report-found-turnover-start');
    const foundTurnoverDeadline = document.getElementById('my-report-found-turnover-deadline');
    const foundTurnoverLocation = document.getElementById('my-report-found-turnover-location');
    const foundTurnoverReceived = document.getElementById('my-report-found-turnover-received');


    const STATUS_STYLES = {

        open: {
            background: '#effbf5',
            borderColor: '#a2ddc3',
            color: '#167956'
        },

        pending: {
            background: '#edf5ff',
            borderColor: '#a5caff',
            color: '#2167c6'
        },

        match: {
            background: '#fff9df',
            borderColor: '#e4c55a',
            color: '#916900'
        },

        scheduled: {
            background: '#eef2ff',
            borderColor: '#c7c3fa',
            color: '#4338ca'
        },

        turnover: {
            background: '#fff9df',
            borderColor: '#e4c55a',
            color: '#916900'
        },

        received: {
            background: '#effbf5',
            borderColor: '#a2ddc3',
            color: '#167956'
        },

        returned: {
            background: '#f5eaff',
            borderColor: '#d7b6f0',
            color: '#6c3daf'
        },

        closed: {
            background: '#eef1f4',
            borderColor: '#d3d9df',
            color: '#5a6672'
        }

    };


    function openModal(data) {

        modalType.textContent =
            data.type || 'ITEM';

        modalId.textContent =
            'ID: ' + (data.id || 'N/A');


        modalTitle.textContent =
            data.name || 'Item';

        modalCategory.textContent =
            data.category || '';

        modalDescription.textContent =
            data.description ||
            'No description provided.';


        modalLocation.textContent =
            data.location ||
            'Campus location not specified.';


        modalDate.textContent =
            (data.date || 'Date not specified')
            +
            (
                data.time
                    ? ' at ' + data.time
                    : ''
            );


        modalStatus.textContent =
            data.status || 'Open';


        const statusStyle =
            STATUS_STYLES[
                data.statusClass
            ]
            ||
            STATUS_STYLES.open;


        modalStatus.style.background =
            statusStyle.background;

        modalStatus.style.borderColor =
            statusStyle.borderColor;

        modalStatus.style.color =
            statusStyle.color;


        /* IMAGE */

        if (data.image) {

            modalImage.src =
                data.image;

            modalImage.alt =
                data.name || 'Item image';

            modalImage.style.display =
                'block';

            modalPlaceholder.style.display =
                'none';

        } else {

            modalImage.style.display =
                'none';

            modalPlaceholder.style.display =
                'grid';

            modalPlaceholder.textContent =
                (
                    data.name || 'I'
                )
                .charAt(0)
                .toUpperCase();

        }


        /* FEATURES */

        modalFeature.textContent =
            data.features
            ||
            data.description
            ||
            'Refer to the item description and identifying details during ownership verification.';


        /* STORAGE */

        if (data.storageLocation) {

            modalStorage.textContent =
                data.storageLocation;

        } else {

            modalStorage.textContent =
                'Items requiring verification are secured by the campus lost & found administrator before release.';

        }


        /* APPROVAL */

        if (data.approvedBy) {

            modalApproved.textContent =
                'Approved by '
                +
                data.approvedBy
                +
                (
                    data.approvedAt
                        ? ' on ' + data.approvedAt
                        : ''
                );

            modalApproved.style.display =
                'block';

        } else {

            modalApproved.style.display =
                'none';

        }

        /* MATCH VERIFICATION / SCHEDULE */
        if (matchAction) {
            matchAction.style.display = 'none';
            if (matchCompleted) matchCompleted.style.display = 'none';
            if (matchAwaiting) matchAwaiting.style.display = 'none';
            if (matchSchedule) matchSchedule.style.display = 'none';
            if (matchOutcome) matchOutcome.style.display = 'none';
            if (foundTurnover) {
                foundTurnover.style.display = 'none';
                foundTurnover.className = 'my-report-found-turnover';
            }

            if (data.reportType === 'found' && data.foundMatchStatus === 'confirmed') {
                foundTurnover.style.display = 'block';
                if (data.foundHandoverAt) {
                    foundTurnover.className = 'my-report-found-turnover received';
                    foundTurnoverTitle.textContent = '✓ Found Item Received by Administrator';
                    foundTurnoverMessage.textContent = 'Thank you. The item has been physically turned over to the administrator. You do not need to attend the lost owner’s verification appointment.';
                    foundTurnoverStart.textContent = '';
                    foundTurnoverDeadline.textContent = '';
                    foundTurnoverLocation.textContent = '';
                    foundTurnoverReceived.textContent = 'Received by administrator: ' + data.foundHandoverAt;
                } else if (data.foundTurnoverStartDate || data.foundTurnoverDeadline) {
                    foundTurnover.className = 'my-report-found-turnover turnover';
                    foundTurnoverTitle.textContent = '📦 Found Item Turnover Required';
                    foundTurnoverMessage.textContent = 'Please bring the physical item to the administrator within the allowed turnover period below. The lost owner will only receive a release/verification schedule after the item is received.';
                    foundTurnoverStart.textContent = data.foundTurnoverStartDate ? 'Allowed start: ' + data.foundTurnoverStartDate : '';
                    foundTurnoverDeadline.textContent = data.foundTurnoverDeadline ? 'Turnover deadline: ' + data.foundTurnoverDeadline : '';
                    foundTurnoverLocation.textContent = data.foundTurnoverLocation ? 'Bring item to: ' + data.foundTurnoverLocation : '';
                    foundTurnoverReceived.textContent = '';
                } else {
                    foundTurnoverTitle.textContent = 'Match Approved — Turnover Schedule Pending';
                    foundTurnoverMessage.textContent = 'The administrator approved the potential match. Please wait for the turnover instructions and deadline before bringing the item.';
                    foundTurnoverStart.textContent = '';
                    foundTurnoverDeadline.textContent = '';
                    foundTurnoverLocation.textContent = '';
                    foundTurnoverReceived.textContent = '';
                }
            }

            if (data.reportType === 'lost' && data.matchStatus === 'rejected' && data.matchVerificationOutcome === 'not_match') {
                matchAction.style.display = 'block';
                matchOutcome.style.display = 'flex';
                matchOutcome.className = 'my-report-match-outcome rejected-match-note';
                matchOutcomeTitle.textContent = '× Not a Match — Found Item Reopened';
                matchOutcomeReason.textContent = data.matchVerificationOutcomeReason
                    ? 'Administrator reason: ' + data.matchVerificationOutcomeReason + '. The found item has been reopened in Search & Browse.'
                    : 'The physical verification did not confirm the item as yours. The found item has been reopened in Search & Browse.';
            } else if (data.reportType === 'lost' && data.matchStatus === 'rejected') {
                matchAction.style.display = 'block';
                matchOutcome.style.display = 'flex';
                matchOutcome.className = 'my-report-match-outcome rejected-match-note';
                matchOutcomeTitle.textContent = '× Potential Match Dismissed';
                matchOutcomeReason.textContent = data.matchAdminRemarks
                    ? 'Administrator reason: ' + data.matchAdminRemarks
                    : 'The administrator dismissed this potential match.';
            } else if (data.reportType === 'lost' && data.matchStatus === 'confirmed') {
                matchAction.style.display = 'block';
                if (data.matchVerificationOutcome === 'completed' || data.matchHandoverAt) {
                    matchCompleted.style.display = 'flex';
                    matchCompletedAt.textContent = '✓ Item successfully returned to you on ' + data.matchHandoverAt + '.';
                    matchCompletedLocation.textContent = data.matchVerificationLocation ? 'Release location: ' + data.matchVerificationLocation : '';
                    matchCompletedNote.textContent = data.matchVerificationOutcomeReason
                        ? 'Verification result: ' + data.matchVerificationOutcomeReason
                        : 'The item was verified and handed over in person. This Lost & Found case is complete.';
                } else if (data.matchVerificationOutcome === 'reschedule') {
                    matchOutcome.style.display = 'flex';
                    matchOutcome.className = 'my-report-match-outcome';
                    matchOutcomeTitle.textContent = '↻ Verification Needs Rescheduling';
                    matchOutcomeReason.textContent = data.matchVerificationOutcomeReason
                        ? 'Reason: ' + data.matchVerificationOutcomeReason + '. Please wait for the administrator to release a new checking schedule.'
                        : 'Please wait for the administrator to release a new checking schedule.';
                } else if (data.matchVerificationDate) {
                    matchSchedule.style.display = 'flex';
                    matchScheduleDate.textContent = (data.matchVerificationDate || '') + (data.matchVerificationTime ? ' at ' + data.matchVerificationTime : '');
                    matchScheduleLocation.textContent = (data.matchVerificationLocation ? 'Checking location: ' + data.matchVerificationLocation : 'Checking location not specified.');
                    matchScheduleDeadline.textContent = data.matchVerificationDeadline ? 'Checking deadline: ' + data.matchVerificationDeadline : '';
                } else {
                    matchAwaiting.style.display = 'block';
                    matchAwaiting.textContent = '✓ Potential match found. No online confirmation is required. The administrator will release your in-person checking schedule after the match is approved and the found item has been physically received.';
                }
            }
        }


        modal.classList.add('open');

        modal.setAttribute(
            'aria-hidden',
            'false'
        );

        document.body.style.overflow =
            'hidden';

    }


    function closeModal() {

        modal.classList.remove(
            'open'
        );

        modal.setAttribute(
            'aria-hidden',
            'true'
        );

        document.body.style.overflow =
            '';

    }


    /* OPEN */

    document
        .querySelectorAll(
            '.my-report-view[data-item]'
        )
        .forEach(function (button) {

            button.addEventListener(
                'click',
                function () {

                    try {

                        const data =
                            JSON.parse(
                                this.getAttribute(
                                    'data-item'
                                )
                            );

                        openModal(data);

                    }
                    catch (error) {

                        console.error(
                            'Unable to open item modal:',
                            error
                        );

                    }

                }
            );

        });


    /* OPEN AN EXACT REPORT FROM A MY CLAIMS MATCH SHORTCUT */
    const openParams = new URLSearchParams(window.location.search);
    const openReportId = openParams.get('open_report');

    if (openReportId) {
        const exactReportButton = document.querySelector(
            '.my-report-view[data-item]'
        );

        document.querySelectorAll('.my-report-view[data-item]').forEach(function (button) {
            try {
                const data = JSON.parse(button.getAttribute('data-item'));
                if (String(data.reportId || '') === String(openReportId)) {
                    button.click();
                }
            } catch (error) {
                console.error('Unable to open requested report:', error);
            }
        });
    }


    /* CLOSE */

    closeButton.addEventListener(
        'click',
        closeModal
    );


    closeText.addEventListener(
        'click',
        closeModal
    );


    /* ASK ADMIN */

    askButton.addEventListener(
        'click',
        function () {

            window.location.href =
                'messages.php';

        }
    );


    /* CLICK OUTSIDE */

    modal.addEventListener(
        'click',
        function (event) {

            if (
                event.target === modal
            ) {

                closeModal();

            }

        }
    );


    /* ESC */

    document.addEventListener(
        'keydown',
        function (event) {

            if (
                event.key === 'Escape'
                &&
                modal.classList.contains(
                    'open'
                )
            ) {

                closeModal();

            }

        }
    );

})();

</script>


<?php require 'includes/footer.php'; ?>
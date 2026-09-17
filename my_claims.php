<?php

require 'config.php';
require_login();

$pageTitle = 'My Claims';

/*
 * Get the current user's claims together with
 * the related item and report information.
 */
$stmt = $pdo->prepare("
    SELECT
        c.*,

        i.item_name,
        i.category,
        i.description AS item_description,
        i.identifying_features AS item_identifying_features,
        i.photo_path,

        r.id AS report_id,
        r.item_status,
        r.campus_location,
        r.building,
        r.floor,
        r.specific_area,
        r.storage_location,
        r.report_type,
        r.event_date,
        r.event_time,
        r.verification_date,
        r.verification_time,
        r.verification_deadline,
        r.verification_location

    FROM claims c

    JOIN items i
        ON i.id = c.item_id

    LEFT JOIN reports r
        ON r.item_id = c.item_id

    WHERE c.claimant_user_id = ?

    ORDER BY c.created_at DESC
");

$stmt->execute([
    current_user()['id']
]);

$rows = $stmt->fetchAll();

require 'includes/header.php';


/* =========================================================
   HELPERS
========================================================= */

function claim_location(array $claim): string
{
    $parts = [];

    if (!empty($claim['campus_location'])) {
        $parts[] = $claim['campus_location'];
    }

    if (!empty($claim['building'])) {
        $parts[] = $claim['building'];
    }

    if (!empty($claim['specific_area'])) {
        $parts[] = $claim['specific_area'];
    }

    return implode(', ', $parts);
}


function claim_date(array $claim): string
{
    if (empty($claim['created_at'])) {
        return '';
    }

    $timestamp = strtotime($claim['created_at']);

    if ($timestamp === false) {
        return h(substr($claim['created_at'], 0, 10));
    }

    return date('n/j/Y', $timestamp);
}


function claim_status_text(string $status): string
{
    return match ($status) {

        'pending' =>
            'Pending Admin Review',

        'approved' =>
            'Approved',

        'rejected' =>
            'Rejected',

        default =>
            status_label($status)
    };
}


function claim_status_class(string $status): string
{
    return match ($status) {

        'pending' =>
            'pending',

        'approved' =>
            'approved',

        'rejected' =>
            'rejected',

        default =>
            status_class($status)
    };
}


function claim_is_completed(array $claim): bool
{
    $itemStatus = strtolower(trim((string)($claim['item_status'] ?? '')));
    return $itemStatus === 'returned';
}

function claim_display_status(array $claim, string $claimStatus): string
{
    if (claim_is_completed($claim)) {
        return 'Completed — Item Released';
    }

    if ($claimStatus === 'approved' && empty($claim['verification_date'])) {
        return 'Claim Approved';
    }

    if ($claimStatus === 'approved' && !empty($claim['verification_date'])) {
        return 'Claim Approved';
    }

    return claim_status_text($claimStatus);
}

function claim_display_status_class(array $claim, string $claimStatus): string
{
    if (claim_is_completed($claim)) {
        return 'completed';
    }

    return claim_status_class($claimStatus);
}

function claim_item_status_text(?string $status): string
{
    if (!$status) {
        return 'Open';
    }

    return status_label($status);
}


function claim_item_status_class(?string $status): string
{
    if (!$status) {
        return 'open';
    }

    return status_class($status);
}


/* =========================================================
   IMAGE HELPER
========================================================= */
function claim_proof_photo(array $claim): string
{
    $evidence = (string)($claim['supporting_evidence'] ?? '');

    if ($evidence === '') {
        return '';
    }

    /*
     * claim.php stores the optional uploaded photo as:
     * Proof photo: uploads/claims/claim-....jpg
     */
    if (preg_match('/(?:^|\R)\s*Proof photo:\s*(.+?)\s*$/mi', $evidence, $matches)) {
        $stored = trim($matches[1]);

        if ($stored !== '') {
            $cleanStored = ltrim(str_replace('\\', '/', $stored), '/');

            if (is_file(__DIR__ . '/' . $cleanStored)) {
                return $cleanStored;
            }

            $base = basename(parse_url($stored, PHP_URL_PATH) ?: $stored);

            if ($base !== '') {
                $relative = 'uploads/claims/' . $base;

                if (is_file(__DIR__ . '/' . $relative)) {
                    return $relative;
                }
            }
        }
    }

    return '';
}


function claim_image(array $claim): string
{
    $stored = trim((string)($claim['photo_path'] ?? ''));

    /*
     * Use the database path when it points to a real file.
     * This also handles paths stored with a leading slash.
     */
    if ($stored !== '') {
        $cleanStored = ltrim(str_replace('\\', '/', $stored), '/');

        if (is_file(__DIR__ . '/' . $cleanStored)) {
            return $cleanStored;
        }

        /* If the database contains an absolute/local path, use its filename. */
        $storedBase = basename(parse_url($stored, PHP_URL_PATH) ?: $stored);
        if ($storedBase !== '') {
            $itemsFile = __DIR__ . '/uploads/Items/' . $storedBase;
            if (is_file($itemsFile)) {
                return 'uploads/Items/' . $storedBase;
            }
        }
    }

    /*
     * Fallback: the project's actual folder is /uploads/Items/
     * with a capital I. Linux/Apache environments can be case-sensitive.
     */
    $name = trim((string)($claim['item_name'] ?? ''));
    if ($name === '') {
        return '';
    }

    $slug = trim(
        (string)preg_replace(
            '/[^a-z0-9]+/i',
            '-',
            strtolower($name)
        ),
        '-'
    );

    foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
        $relative = 'uploads/Items/' . $slug . '.' . $extension;

        if (is_file(__DIR__ . '/' . $relative)) {
            return $relative;
        }
    }

    return '';
}

?>

<div class="my-claims-page">

    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div class="my-claims-head">

        <div>

            <span class="my-claims-eyebrow">
                MY ACTIVITY
            </span>

            <h1>
                My Claims Tracking
            </h1>

            <p>
                Monitor your ownership claims, verification schedules, and item releases.
            </p>

        </div>


        <a
            class="my-claims-browse-btn"
            href="search.php?reset=1"
        >
            <span class="my-claims-search-icon">⌕</span>
            Browse More Items
        </a>

    </div>


    <!-- =====================================================
         CLAIM LIST
    ====================================================== -->

    <section class="my-claims-panel my-claims-panel-claims">

    <div class="my-claims-list">

        <?php if (!$rows): ?>

            <div class="my-claims-empty">

                <div class="my-claims-empty-icon">
                    ⌕
                </div>

                <h2>
                    No claims yet
                </h2>

                <p>
                    You have not submitted any ownership claims.
                </p>

                <a
                    class="btn primary"
                    href="search.php?reset=1"
                >
                    Browse Items

                </a>

            </div>

        <?php endif; ?>


        <?php foreach ($rows as $c): ?>

            <?php

            $claimStatus =
                strtolower(
                    trim(
                        (string)$c['claim_status']
                    )
                );

            $itemStatus =
                strtolower(
                    trim(
                        (string)($c['item_status'] ?? 'open')
                    )
                );

            $location =
                claim_location($c);

            /*
             * Claim ID
             */
            $claimCode =
                'CLM-' .
                str_pad(
                    (string)$c['id'],
                    3,
                    '0',
                    STR_PAD_LEFT
                );

            /*
             * Item ID
             */
            $itemCode =
                'ITM-' .
                str_pad(
                    (string)$c['item_id'],
                    3,
                    '0',
                    STR_PAD_LEFT
                );

            /*
             * Date
             */
            $submittedDate =
                claim_date($c);

            /*
             * Status
             */
            $isCompleted = claim_is_completed($c);

            $claimStatusText =
                claim_display_status(
                    $c,
                    $claimStatus
                );

            $claimStatusClass =
                claim_display_status_class(
                    $c,
                    $claimStatus
                );

            $handoverAt = trim((string)($c['handover_at'] ?? ''));

            /*
             * Item status
             */
            $itemStatusText =
                claim_item_status_text(
                    $c['item_status'] ?? null
                );

            $itemStatusClass =
                claim_item_status_class(
                    $c['item_status'] ?? null
                );


            /*
             * Determine the next step.
             */
            if ($isCompleted) {

                $nextStep =
                    'Your ownership was verified in person and the item has been released to you.';

            }
            elseif ($claimStatus === 'approved') {

                $nextStep =
                    'Your claim was approved. Go to the released pickup schedule and bring your University/Mapúa ID and ownership evidence for the final in-person ownership check.';

            }
            elseif ($claimStatus === 'rejected') {

                $nextStep =
                    'Review the administrator\'s rejection reason. If you need clarification, contact the administrator through HelpDesk.';

            }
            else {

                $nextStep =
                    'Waiting for the administrator to review your ownership evidence.';

            }


            /*
             * Submitted proof.
             *
             * We combine the ownership explanation and
             * supporting evidence for display.
             */
            $submittedProof =
                trim(
                    (string)(
                        $c['ownership_description']
                        ?? ''
                    )
                );

            if ($submittedProof === '') {

                $submittedProof =
                    'Ownership information submitted for verification.';

            }


            /*
             * Serial / identifying information.
             */
            $identifying =
                trim(
                    (string)(
                        $c['identifying_information']
                        ?? ''
                    )
                );

            /*
             * Optional uploaded proof photo.
             */
            $proofPhoto =
                claim_proof_photo($c);

            ?>

            <article class="my-claim-card">

                <!-- =================================================
                     TOP ROW
                ================================================== -->

                <div class="my-claim-top">

                    <div class="my-claim-identifiers">

                        <span class="my-claim-id">
                            <?= h($claimCode) ?>
                        </span>

                        <span class="my-claim-item-id">
                            Item ID: <?= h($itemCode) ?>
                        </span>

                        <span class="my-claim-dot">
                            •
                        </span>

                        <span class="my-claim-submitted">
                            Submitted on
                            <?= h($submittedDate) ?>
                        </span>

                    </div>


                    <span
                        class="my-claim-status <?= h($claimStatusClass) ?>"
                    >

                        <?php if ($isCompleted): ?>

                            <span class="claim-status-icon">
                                ✓
                            </span>

                        <?php elseif ($claimStatus === 'pending'): ?>

                            <span class="claim-status-icon">
                                ◷
                            </span>

                        <?php elseif ($claimStatus === 'approved'): ?>

                            <span class="claim-status-icon">
                                ✓
                            </span>

                        <?php else: ?>

                            <span class="claim-status-icon">
                                ×
                            </span>

                        <?php endif; ?>

                        <?= h($claimStatusText) ?>

                    </span>

                </div>


                <!-- =================================================
                     ITEM NAME
                ================================================== -->

                <h2 class="my-claim-item-name">
                    <?= h($c['item_name']) ?>
                </h2>


                <!-- =================================================
                     SUBMITTED PROOF
                ================================================== -->

                <div class="my-claim-proof">

                    <strong>
                        Submitted Proof:
                    </strong>

                    <span>
                        <?= h($submittedProof) ?>
                    </span>

                </div>

                <?php if ($claimStatus === 'rejected' && !empty($c['rejection_reason'])): ?>
                    <div class="my-claim-rejection-detail">
                        <div class="my-claim-rejection-detail-head">
                            <span class="my-claim-rejection-detail-icon">×</span>
                            <strong>REASON FOR REJECTION</strong>
                        </div>
                        <p><?= nl2br(h($c['rejection_reason'])) ?></p>
                    </div>
                <?php endif; ?>


                <?php if ($proofPhoto !== ''): ?>

                    <div class="my-claim-proof-photo">

                        <div class="my-claim-proof-photo-head">
                            <strong>Proof Photo:</strong>
                            <span>Optional evidence attached to this claim</span>
                        </div>

                        <a
                            href="<?= h($proofPhoto) ?>"
                            target="_blank"
                            rel="noopener"
                            class="my-claim-proof-photo-link"
                        >
                            <img
                                src="<?= h($proofPhoto) ?>"
                                alt="Uploaded proof photo for <?= h($c['item_name']) ?>"
                            >

                            <span>
                                View full-size photo
                                <b>↗</b>
                            </span>
                        </a>

                    </div>

                <?php endif; ?>


                <?php if ($claimStatus === 'approved' && !$isCompleted && !empty($c['verification_date'])): ?>

                    <!-- =================================================
                         OFFICIAL VERIFICATION SCHEDULE
                    ================================================== -->
                    <section class="my-claim-release-slip">

                        <div class="my-claim-release-head">
                            <div class="my-claim-release-title">
                                <span class="my-claim-release-key">⌕</span>
                                <strong>OFFICIAL PICKUP SCHEDULE</strong>
                            </div>

                            <span class="my-claim-release-authorized">
                                Authorized by: Find IT Administrator
                            </span>
                        </div>

                        <div class="my-claim-release-divider"></div>

                        <div class="my-claim-release-grid">

                            <div class="my-claim-release-box">
                                <span class="my-claim-release-label">PICKUP DATE &amp; TIME</span>
                                <strong>
                                    <?= !empty($c['verification_date'])
                                        ? h(date('Y-m-d', strtotime($c['verification_date'])))
                                        : 'To be scheduled' ?>
                                </strong>
                                <?php if (!empty($c['verification_time'])): ?>
                                    <small><?= h($c['verification_time']) ?></small>
                                <?php endif; ?>
                            </div>

                            <div class="my-claim-release-box">
                                <span class="my-claim-release-label">PICKUP DEADLINE</span>
                                <strong>
                                    <?= !empty($c['verification_deadline'])
                                        ? h(date('Y-m-d', strtotime($c['verification_deadline'])))
                                        : 'No deadline specified' ?>
                                </strong>
                                <small>Please complete your pickup by this date.</small>
                            </div>

                            <div class="my-claim-release-box">
                                <span class="my-claim-release-label">PICKUP LOCATION</span>
                                <strong>
                                    <?= h($c['verification_location'] ?? 'Office of Mapúa, 1st Floor') ?>
                                </strong>
                            </div>

                        </div>

                        <div class="my-claim-release-instruction">
                            <strong>Instructions:</strong> Please go to the scheduled location and bring your official University/Mapúa ID and supporting ownership evidence for the final ownership check before the item is released.
                        </div>

                    </section>

                <?php endif; ?>


                <?php if ($isCompleted): ?>

                    <section class="my-claim-completed">
                        <div class="my-claim-completed-icon">✓</div>
                        <div class="my-claim-completed-copy">
                            <strong>Ownership Verification Completed</strong>
                            <p>Your ownership was verified in person and the item was released to you. This claim is now complete.</p>
                            <?php if ($handoverAt !== ''): ?>
                                <small>Handover recorded: <?= h(date('F j, Y \a\t g:i A', strtotime($handoverAt))) ?></small>
                            <?php endif; ?>
                        </div>
                        <span class="my-claim-completed-badge">RETURNED</span>
                    </section>

                <?php endif; ?>

                <!-- =================================================
                     BOTTOM DIVIDER
                ================================================== -->

                <div class="my-claim-divider"></div>


                <!-- =================================================
                     BOTTOM ROW
                ================================================== -->

                <div class="my-claim-bottom">

                    <div class="my-claim-item-status">

                        <span>
                            Item Status in Catalog:
                        </span>

                        <strong>
                            <?= h($isCompleted ? 'Returned — Claim Completed' : ($claimStatus === 'approved' && !empty($c['verification_date']) ? 'Ready for Pickup' : ($claimStatus === 'approved' ? 'Claim Approved' : $itemStatusText))) ?>
                        </strong>

                    </div>


                    <div class="my-claim-bottom-right">

                       <button
    type="button"
    class="my-claim-inspect"
    data-claim-item='<?= h(
        json_encode(
            [
                'type' =>
                    strtolower((string)$c['report_type']) === 'found'
                        ? 'FOUND ITEM'
                        : 'LOST ITEM',

                'id' =>
                    'ITM-' .
                    str_pad(
                        (string)$c['item_id'],
                        3,
                        '0',
                        STR_PAD_LEFT
                    ),

                'name' =>
                    $c['item_name'] ?? '',

                'category' =>
                    $c['category'] ?? '',

                'description' =>
                    $c['item_description'] ?? '',
                'image' =>
                    claim_image($c),

                'proofPhoto' =>
                    $proofPhoto,

                                'location' =>
                    claim_location($c),

                'date' =>
                    $c['event_date'] ?? '',

                'time' =>
                    $c['event_time'] ?? '',

                'features' =>
                    $c['item_identifying_features'] ?? '',

                'storage' =>
                    $c['storage_location'] ?? '',

                'claimStatus' =>
                    $claimStatus,

                'rejectionReason' =>
                    $c['rejection_reason'] ?? '',

                'itemStatus' =>
                    $itemStatus,

                'completed' =>
                    $isCompleted,

                'handoverAt' =>
                    $handoverAt,

                'verificationDate' =>
                    $c['verification_date'] ?? '',

                'verificationTime' =>
                    $c['verification_time'] ?? '',

                'verificationDeadline' =>
                    $c['verification_deadline'] ?? '',

                'verificationLocation' =>
                    $c['verification_location'] ?? '',


            ],
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        )
    ) ?>'
>
    Inspect Item
    <span>→</span>
</button>

                    </div>

                </div>

            </article>

        <?php endforeach; ?>

    </div>
    </section>

</div>

<!-- =========================================================
     MY CLAIMS - INSPECT ITEM MODAL
========================================================= -->


<style>
.my-claim-release-slip{
    margin-top:18px;
    padding:16px 18px 18px;
    background:linear-gradient(135deg,#effdf7 0%,#f6fffb 100%);
    border:1px solid #55dfb1;
    border-radius:15px;
    color:#126b50;
}
.my-claim-release-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
}
.my-claim-release-title{
    display:flex;
    align-items:center;
    gap:9px;
    font-size:11px;
    letter-spacing:.35px;
}
.my-claim-release-key{
    width:22px;
    height:22px;
    display:grid;
    place-items:center;
    font-size:14px;
    font-weight:800;
}
.my-claim-release-authorized{
    color:#176b4f;
    font-size:10px;
    font-weight:700;
}
.my-claim-release-divider{
    height:1px;
    margin:10px 0 12px;
    background:#bdeedc;
}
.my-claim-release-grid{
    display:grid;
    grid-template-columns:1fr 1fr 1.12fr;
    gap:11px;
}
.my-claim-release-box{
    min-height:70px;
    display:flex;
    flex-direction:column;
    justify-content:center;
    padding:12px;
    background:rgba(255,255,255,.78);
    border:1px solid #bcebdc;
    border-radius:11px;
}
.my-claim-release-label{
    margin-bottom:5px;
    color:#527264;
    font-size:8px;
    font-weight:800;
    letter-spacing:.35px;
}
.my-claim-release-box strong{
    color:#183b31;
    font-size:11px;
    line-height:1.35;
}
.my-claim-release-box small{
    margin-top:2px;
    color:#648276;
    font-size:9px;
}
.my-claim-release-instruction{
    margin-top:11px;
    padding:9px 11px;
    background:#dcfaed;
    border:1px solid #bcebdc;
    border-radius:9px;
    color:#176b50;
    font-size:9px;
    line-height:1.5;
}
.my-claim-release-instruction strong{
    color:#0d6248;
}
.my-claim-rejection-detail{
    margin-top:12px;
    padding:12px 14px;
    background:#fff6f6;
    border:1px solid #f1c8cc;
    border-radius:11px;
}
.my-claim-rejection-detail-head{
    display:flex;
    align-items:center;
    gap:8px;
    color:#9b1c2c;
    font-size:10px;
    font-weight:900;
    letter-spacing:.3px;
}
.my-claim-rejection-detail-icon{
    width:20px;
    height:20px;
    display:grid;
    place-items:center;
    border-radius:50%;
    background:#f9dfe2;
    color:#9b1c2c;
    font-size:13px;
    font-weight:900;
}
.my-claim-rejection-detail p{
    margin:7px 0 0;
    color:#6f3b42;
    font-size:10px;
    line-height:1.55;
    white-space:normal;
}
.claim-modal-rejection{
    margin-top:14px;
    padding:14px 15px;
    background:#fff5f6;
    border:1px solid #efc4c9;
    border-radius:12px;
}
.claim-modal-rejection-head{
    display:flex;
    align-items:center;
    gap:9px;
}
.claim-modal-rejection-icon{
    width:24px;
    height:24px;
    display:grid;
    place-items:center;
    border-radius:50%;
    background:#f8dce0;
    color:#9b1c2c;
    font-size:15px;
    font-weight:900;
}
.claim-modal-rejection-head div{
    display:flex;
    flex-direction:column;
    gap:2px;
}
.claim-modal-rejection-head strong{
    color:#8f1828;
    font-size:11px;
    font-weight:900;
}
.claim-modal-rejection-head span{
    color:#9b6269;
    font-size:9px;
    font-weight:700;
}
.claim-modal-rejection-text{
    margin-top:10px;
    padding:10px 11px;
    background:#fff;
    border:1px solid #f0d7da;
    border-radius:9px;
    color:#59363b;
    font-size:10px;
    line-height:1.6;
}
.my-claim-proof strong{color:#25344d;}
.my-claim-item-status strong{color:#9b1c2c;}
.my-claim-release-slip{box-shadow:0 4px 14px rgba(15,23,42,.04);}
@media (max-width:900px){
    .my-claim-release-grid{grid-template-columns:1fr;}
    .my-claim-release-head{align-items:flex-start;flex-direction:column;}
}

.my-claim-proof-photo{
    margin-top:12px;
    padding:12px 14px;
    background:#f8f9fc;
    border:1px solid #e2e6ee;
    border-radius:11px;
}

.my-claim-proof-photo-head{
    display:flex;
    align-items:baseline;
    gap:8px;
    margin-bottom:9px;
}

.my-claim-proof-photo-head strong{
    color:#273247;
    font-size:10px;
    font-weight:800;
}

.my-claim-proof-photo-head span{
    color:#8a95a6;
    font-size:9px;
}

.my-claim-proof-photo-link{
    display:inline-flex;
    align-items:center;
    gap:9px;
    text-decoration:none;
}

.my-claim-proof-photo-link img{
    width:58px;
    height:58px;
    object-fit:cover;
    border-radius:8px;
    border:1px solid #dfe4ec;
    background:#edf0f5;
}

.my-claim-proof-photo-link span{
    color:#9b1c2c;
    font-size:9px;
    font-weight:800;
}

.my-claim-proof-photo-link b{
    font-size:11px;
}

.claim-modal-proof-photo{
    margin-top:14px;
    padding:13px 15px;
    background:#f8f9fc;
    border:1px solid #e0e5ee;
    border-radius:11px;
}

.claim-modal-proof-photo-head{
    display:flex;
    align-items:baseline;
    gap:8px;
    margin-bottom:9px;
}

.claim-modal-proof-photo-head strong{
    color:#273247;
    font-size:11px;
    font-weight:800;
}

.claim-modal-proof-photo-head span{
    color:#8a95a6;
    font-size:9px;
}

.claim-modal-proof-photo a{
    display:flex;
    align-items:center;
    gap:10px;
    text-decoration:none;
}

.claim-modal-proof-photo img{
    width:72px;
    height:72px;
    object-fit:cover;
    border-radius:9px;
    border:1px solid #dfe4ec;
    background:#edf0f5;
}

.claim-modal-proof-photo a span{
    color:#9b1c2c;
    font-size:9px;
    font-weight:800;
}

.claim-modal-release{
    margin-top:16px;
    padding:14px 16px;
    background:#e9fbf3;
    border:1px solid #b8ead3;
    border-radius:12px;
    color:#176b4d;
}
.claim-modal-release-title{
    display:flex;
    align-items:center;
    gap:7px;
    margin-bottom:7px;
    font-size:12px;
}
.claim-modal-release-title span{
    font-size:15px;
}
.claim-modal-release-details{
    color:#426b5b;
    font-size:10px;
    line-height:1.6;
}
.my-claim-completed{
    margin-top:18px;
    display:flex;
    align-items:center;
    gap:13px;
    padding:14px 16px;
    background:linear-gradient(135deg,#ecfff6 0%,#f8fffc 100%);
    border:1px solid #83e3bd;
    border-radius:13px;
    color:#176b4d;
}
.my-claim-completed-icon{
    width:34px;
    height:34px;
    flex:0 0 34px;
    display:grid;
    place-items:center;
    border-radius:50%;
    background:#c9f7e2;
    color:#08794f;
    font-size:17px;
    font-weight:900;
}
.my-claim-completed-copy{flex:1;min-width:0;}
.my-claim-completed-copy strong{display:block;color:#155b45;font-size:11px;font-weight:900;}
.my-claim-completed-copy p{margin:3px 0 0;color:#527668;font-size:9px;line-height:1.45;}
.my-claim-completed-copy small{display:block;margin-top:5px;color:#6a8b7e;font-size:8px;font-weight:700;}
.my-claim-completed-badge{padding:6px 9px;border-radius:999px;background:#c9f7e2;color:#08794f;font-size:8px;font-weight:900;letter-spacing:.4px;}
@media(max-width:650px){.my-claim-completed{align-items:flex-start;flex-wrap:wrap}.my-claim-completed-badge{margin-left:47px;}}
</style>

<div
    id="my-claim-item-modal"
    class="my-claim-item-modal"
    aria-hidden="true"
>

    <div
        class="my-claim-item-dialog"
        role="dialog"
        aria-modal="true"
    >

        <!-- HEADER -->

        <div class="my-claim-item-header">

            <div class="my-claim-item-header-left">

                <span
                    id="claim-modal-type"
                    class="claim-modal-type"
                >
                    FOUND ITEM
                </span>

                <span
                    id="claim-modal-id"
                    class="claim-modal-id"
                >
                    ID: ITM-102
                </span>

            </div>


            <button
                type="button"
                id="claim-modal-x"
                class="claim-modal-x"
                aria-label="Close"
            >
                ×
            </button>

        </div>


        <!-- MAIN ITEM INFORMATION -->

        <div class="my-claim-item-main">

            <!-- IMAGE -->

            <div class="claim-modal-image">

                <img
                    id="claim-modal-image"
                    src=""
                    alt=""
                >

                <div
                    id="claim-modal-placeholder"
                    class="claim-modal-placeholder"
                >
                    I
                </div>

            </div>


            <!-- DETAILS -->

            <div class="claim-modal-details">

                <div class="claim-modal-status-row">

                    <span
                        id="claim-modal-status"
                        class="claim-modal-status"
                    >
                        Pending Admin Review
                    </span>

                    <span
                        id="claim-modal-category"
                        class="claim-modal-category"
                    >
                        Water Bottles &amp; Tumblers
                    </span>

                </div>


                <h2 id="claim-modal-title">
                    Blue Insulated Metal Flask with Outdoor Stickers
                </h2>


                <p id="claim-modal-description">
                    Hydro Flask brand tumbler found abandoned at desk 24.
                </p>


                <div class="claim-modal-location-date">

                    <div>

                        <span class="claim-modal-small-icon">
                            <svg
                                width="15"
                                height="15"
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
                        </span>

                        <span id="claim-modal-location">
                            Main University Library, 2nd Floor Quiet Area
                        </span>

                    </div>


                    <div>

                        <span class="claim-modal-small-icon">

                            <svg
                                width="15"
                                height="15"
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

                        </span>

                        <span id="claim-modal-date">
                            2026-09-10 at 17:00
                        </span>

                    </div>

                </div>

            </div>

        </div>


        <!-- STORAGE LOCATION -->

        <div class="claim-modal-storage">

            <div class="claim-modal-storage-icon">

                <svg
                    width="17"
                    height="17"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                >
                    <path d="M21 8l-9-5-9 5 9 5 9-5z"/>
                    <path d="M3 8v8l9 5 9-5V8"/>
                    <path d="M12 13v8"/>
                </svg>

            </div>


            <div>

                <strong>
                    Physical Custody Storage Location
                </strong>

                <p id="claim-modal-storage-text">
                    Campus Security Station - Room 102 Locker B-3
                </p>

                <span id="claim-modal-approved">
                    Approved by Campus Security
                </span>

            </div>

        </div>


        <!-- DISTINCTIVE FEATURES -->

        <div class="claim-modal-features">

            <strong>

                <span>
                    ⓘ
                </span>

                Distinctive Features Logged in System

            </strong>

            <p id="claim-modal-features-text">
                Navy finish with mountain park sticker on side.
            </p>

        </div>



        <!-- OPTIONAL PROOF PHOTO -->
        <!-- REJECTION REASON -->
        <div
            id="claim-modal-rejection"
            class="claim-modal-rejection"
            style="display:none;"
        >
            <div class="claim-modal-rejection-head">
                <span class="claim-modal-rejection-icon">×</span>
                <div>
                    <strong>Claim Rejected</strong>
                    <span>Administrator's reason</span>
                </div>
            </div>
            <div
                id="claim-modal-rejection-text"
                class="claim-modal-rejection-text"
            ></div>
        </div>


        <div
            id="claim-modal-proof-photo"
            class="claim-modal-proof-photo"
            style="display:none;"
        >
            <div class="claim-modal-proof-photo-head">
                <strong>Proof Photo Submitted</strong>
                <span>Optional ownership evidence</span>
            </div>

            <a
                id="claim-modal-proof-photo-link"
                href="#"
                target="_blank"
                rel="noopener"
            >
                <img
                    id="claim-modal-proof-photo-image"
                    src=""
                    alt="Uploaded proof photo"
                >

                <span>View full-size photo ↗</span>
            </a>
        </div>


        <!-- VERIFICATION SCHEDULE -->
        <div
            id="claim-modal-release"
            class="claim-modal-release"
            style="display:none;"
        >
            <div class="claim-modal-release-title">
                <span>✓</span>
                <strong>Official Pickup Schedule</strong>
            </div>

            <div
                id="claim-modal-release-details"
                class="claim-modal-release-details"
            ></div>
        </div>

        <!-- FOOTER -->

        <div class="claim-modal-footer">

            <button
                type="button"
                class="claim-modal-ask"
                id="claim-modal-ask"
            >

                <svg
                    width="14"
                    height="14"
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


            <div class="claim-modal-footer-right">

                <button
                    type="button"
                    id="claim-modal-close"
                    class="claim-modal-close"
                >
                    Close
                </button>



            </div>

        </div>

    </div>

</div>

<script>

(function () {

    const modal =
        document.getElementById(
            'my-claim-item-modal'
        );

    const closeX =
        document.getElementById(
            'claim-modal-x'
        );

    const closeButton =
        document.getElementById(
            'claim-modal-close'
        );

    const askButton =
        document.getElementById(
            'claim-modal-ask'
        );


    const modalType =
        document.getElementById(
            'claim-modal-type'
        );

    const modalId =
        document.getElementById(
            'claim-modal-id'
        );

    const modalImage =
        document.getElementById(
            'claim-modal-image'
        );

    const placeholder =
        document.getElementById(
            'claim-modal-placeholder'
        );

    const modalStatus =
        document.getElementById(
            'claim-modal-status'
        );

    const modalCategory =
        document.getElementById(
            'claim-modal-category'
        );

    const modalTitle =
        document.getElementById(
            'claim-modal-title'
        );

    const modalDescription =
        document.getElementById(
            'claim-modal-description'
        );

    const modalLocation =
        document.getElementById(
            'claim-modal-location'
        );

    const modalDate =
        document.getElementById(
            'claim-modal-date'
        );

    const modalStorage =
        document.getElementById(
            'claim-modal-storage-text'
        );

    const modalApproved =
        document.getElementById(
            'claim-modal-approved'
        );

    const modalFeatures =
        document.getElementById(
            'claim-modal-features-text'
        );

    const modalRelease =
        document.getElementById(
            'claim-modal-release'
        );

    const modalReleaseDetails =
        document.getElementById(
            'claim-modal-release-details'
        );

    const modalRejection =
        document.getElementById(
            'claim-modal-rejection'
        );

    const modalRejectionText =
        document.getElementById(
            'claim-modal-rejection-text'
        );

    const modalProofPhoto =
        document.getElementById(
            'claim-modal-proof-photo'
        );

    const modalProofPhotoImage =
        document.getElementById(
            'claim-modal-proof-photo-image'
        );

    const modalProofPhotoLink =
        document.getElementById(
            'claim-modal-proof-photo-link'
        );


    function openClaimModal(data) {

        /* HEADER */

        modalType.textContent =
            data.type || 'ITEM';

        modalId.textContent =
            'ID: ' + (data.id || 'N/A');


        /* ITEM */

        modalTitle.textContent =
            data.name || 'Item';

        modalCategory.textContent =
            data.category || '';

        modalDescription.textContent =
            data.description ||
            'No description provided.';


        /* LOCATION */

        modalLocation.textContent =
            data.location ||
            'Location not specified.';


        /* DATE */

        let dateText =
            data.date ||
            'Date not specified';

        if (data.time) {

            dateText +=
                ' at ' +
                data.time;

        }

        modalDate.textContent =
            dateText;


        /* STATUS */

        const claimStatus =
            (data.claimStatus || 'pending').toLowerCase();

        modalStatus.classList.remove(
            'claim-status-approved',
            'claim-status-rejected',
            'claim-status-pending'
        );

        const isCompleted = !!data.completed || (data.itemStatus || '').toLowerCase() === 'returned';

        if (isCompleted) {

            modalStatus.textContent =
                'Completed — Item Released';

            modalStatus.classList.add(
                'claim-status-approved'
            );

        } else if (claimStatus === 'approved') {

            modalStatus.textContent =
                'Claim Approved';

            modalStatus.classList.add(
                'claim-status-approved'
            );

        } else if (claimStatus === 'rejected') {

            modalStatus.textContent =
                'Claim Rejected';

            modalStatus.classList.add(
                'claim-status-rejected'
            );

        } else {

            modalStatus.textContent =
                'Pending Admin Review';

            modalStatus.classList.add(
                'claim-status-pending'
            );
        }


        /* REJECTION REASON */

        if (
            claimStatus === 'rejected' &&
            data.rejectionReason
        ) {
            modalRejectionText.textContent =
                data.rejectionReason;

            modalRejection.style.display =
                'block';
        } else {
            modalRejectionText.textContent =
                '';

            modalRejection.style.display =
                'none';
        }


        /* IMAGE */

        if (data.image) {

            modalImage.src =
                data.image;

            modalImage.alt =
                data.name || 'Item image';

            modalImage.style.display =
                'block';

            placeholder.style.display =
                'none';

        }
        else {

            modalImage.style.display =
                'none';

            placeholder.style.display =
                'grid';

            placeholder.textContent =
                (
                    data.name || 'I'
                )
                .charAt(0)
                .toUpperCase();

        }


        /* OPTIONAL PROOF PHOTO */

        if (data.proofPhoto) {

            modalProofPhotoImage.src =
                data.proofPhoto;

            modalProofPhotoLink.href =
                data.proofPhoto;

            modalProofPhoto.style.display =
                'block';

        } else {

            modalProofPhotoImage.removeAttribute('src');

            modalProofPhotoLink.href =
                '#';

            modalProofPhoto.style.display =
                'none';

        }


        /* STORAGE */

        modalStorage.textContent =
            data.storage ||
            'Item is secured by the campus lost & found administrator pending verification.';


        /* APPROVAL + RELEASE */

        if (isCompleted) {

            modalApproved.textContent =
                'Item successfully released — your claim is now complete.';

            modalRelease.style.display =
                'none';

            modalReleaseDetails.textContent =
                '';

        } else if (claimStatus === 'approved' && data.verificationDate) {

            modalApproved.textContent =
                'Claim approved — your pickup schedule is ready.';

            const visitDate =
                data.verificationDate
                    ? new Date(data.verificationDate + 'T00:00:00')
                    : null;

            const formattedDate =
                visitDate && !Number.isNaN(visitDate.getTime())
                    ? visitDate.toLocaleDateString(
                        undefined,
                        {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric'
                        }
                    )
                    : (data.verificationDate || 'Date not specified');

            let visitText =
                '<strong>When:</strong> ' +
                formattedDate;

            if (data.verificationTime) {
                visitText +=
                    ' at ' +
                    data.verificationTime;
            }

            if (data.verificationDeadline) {

                const deadlineDate =
                    new Date(
                        data.verificationDeadline + 'T00:00:00'
                    );

                const formattedDeadline =
                    !Number.isNaN(deadlineDate.getTime())
                        ? deadlineDate.toLocaleDateString(
                            undefined,
                            {
                                year: 'numeric',
                                month: 'long',
                                day: 'numeric'
                            }
                        )
                        : data.verificationDeadline;

                visitText +=
                    '<br><strong>Until:</strong> ' +
                    formattedDeadline;
            }

            if (data.verificationLocation) {
                visitText +=
                    '<br><strong>Where:</strong> ' +
                    data.verificationLocation;
            }

            modalReleaseDetails.innerHTML =
                visitText;

            modalRelease.style.display =
                'block';

        } else {

            modalApproved.textContent =
                claimStatus === 'rejected'
                    ? 'This ownership claim was not approved.'
                    : 'Claim approved — awaiting pickup schedule. The administrator will release your pickup details.';

            modalRelease.style.display =
                'none';

            modalReleaseDetails.textContent =
                '';

        }


        /* FEATURES */

        modalFeatures.textContent =
            data.features ||
            data.description ||
            'Refer to the identifying details submitted with your claim.';


        /* SHOW */

        modal.classList.add(
            'open'
        );

        modal.setAttribute(
            'aria-hidden',
            'false'
        );

        document.body.style.overflow =
            'hidden';

    }


    function closeClaimModal() {

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


    /* INSPECT BUTTONS */

    document
        .querySelectorAll(
            '.my-claim-inspect[data-claim-item]'
        )
        .forEach(function (button) {

            button.addEventListener(
                'click',
                function () {

                    try {

                        const data =
                            JSON.parse(
                                this.getAttribute(
                                    'data-claim-item'
                                )
                            );

                        openClaimModal(data);

                    }
                    catch (error) {

                        console.error(
                            'Unable to open claim item:',
                            error
                        );

                    }

                }
            );

        });


    /* CLOSE */

    closeX.addEventListener(
        'click',
        closeClaimModal
    );

    closeButton.addEventListener(
        'click',
        closeClaimModal
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

                closeClaimModal();

            }

        }
    );


    /* ESC */

    document.addEventListener(
        'keydown',
        function (event) {

            if (
                event.key === 'Escape' &&
                modal.classList.contains('open')
            ) {

                closeClaimModal();

            }

        }
    );

})();

</script>

<?php require 'includes/footer.php'; ?>
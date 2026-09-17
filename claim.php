<?php

require 'config.php';
require_login();

/* Add the optional claim proof-photo field to older SQLite databases. */
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
    /* The insert below will surface a normal error if the schema cannot be migrated. */
}

$itemId = (int)($_GET['item_id'] ?? $_POST['item_id'] ?? 0);

/*
 * Multiple students may submit claims for the same found item.
 *
 * Claimable statuses:
 *   open          = no claim submitted yet
 *   claim_pending = one or more students already submitted claims
 *
 * The same student cannot submit another pending claim for the same item.
 */

$stmt = $pdo->prepare("
    SELECT
        r.*,
        i.item_name,
        i.category,
        i.photo_path
    FROM reports r
    JOIN items i ON i.id = r.item_id
    WHERE r.item_id = ?
      AND LOWER(TRIM(r.report_type)) = 'found'
      AND LOWER(TRIM(r.report_status)) = 'approved'
    ORDER BY
        CASE r.item_status
            WHEN 'open' THEN 1
            WHEN 'claim_pending' THEN 2
            WHEN 'matched' THEN 3
            ELSE 4
        END,
        r.id DESC
    LIMIT 1
");

$stmt->execute([$itemId]);
$item = $stmt->fetch();

if (!$item) {
    http_response_code(404);
    exit('This found item could not be found or has not been approved yet.');
}

/*
 * Multiple claims are allowed.
 *
 * open           = claimable
 * claim_pending  = still claimable; other students may compete
 * matched        = claimable only if it is merely a potential match
 *                  (no verification/release schedule yet)
 * returned/closed = not claimable
 */
$itemStatus = strtolower(trim((string)($item['item_status'] ?? '')));
$hasVerificationSchedule = !empty($item['verification_date']);
$canClaim = in_array($itemStatus, ['open', 'claim_pending'], true);

/* A potential match is not the same as a completed claim approval. */
if ($itemStatus === 'matched' && !$hasVerificationSchedule) {
    $canClaim = true;
}

if (!$canClaim && $itemStatus === 'matched' && $hasVerificationSchedule) {
    $itemUnavailableMessage = 'This item already has an approved claim and a scheduled verification/release.';
} elseif (in_array($itemStatus, ['returned', 'closed'], true)) {
    $itemUnavailableMessage = 'This item has already been returned or closed and is no longer available for new claims.';
} else {
    $itemUnavailableMessage = 'This item is not available for a new claim.';
}

$error = '';

$currentUserId = (int)current_user()['id'];


/*
|--------------------------------------------------------------------------
| CHECK EXISTING PENDING CLAIM
|--------------------------------------------------------------------------
*/

$dupe = $pdo->prepare("
    SELECT id
    FROM claims
    WHERE item_id = ?
      AND claimant_user_id = ?
      AND claim_status = 'pending'
    LIMIT 1
");

$dupe->execute([
    $itemId,
    $currentUserId
]);

$existingClaim = $dupe->fetchColumn();


/*
|--------------------------------------------------------------------------
| SUBMIT CLAIM
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $ownership = trim(
        $_POST['ownership_description'] ?? ''
    );

    $identifying = trim(
        $_POST['identifying_information'] ?? ''
    );

    $evidence = trim(
        $_POST['supporting_evidence'] ?? ''
    );

    /* Optional proof photo upload. */
    $proofPhotoPath = '';
    $uploadedProofAbsolute = '';
    $uploadError = '';

    if (isset($_FILES['proof_photo']) && (int)($_FILES['proof_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['proof_photo'];

        if ((int)$file['error'] !== UPLOAD_ERR_OK) {
            $uploadError = 'The proof photo could not be uploaded.';
        } elseif ((int)$file['size'] > 5 * 1024 * 1024) {
            $uploadError = 'The proof photo must be 5 MB or smaller.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $allowedMimes = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
            ];

            if (!isset($allowedMimes[$mime])) {
                $uploadError = 'Only JPG, PNG, or WEBP proof photos are allowed.';
            } else {
                $uploadDir = __DIR__ . '/uploads/claim_proofs';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }

                if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                    $uploadError = 'The proof photo upload folder is not writable.';
                } else {
                    $safeName = 'claim_' . $currentUserId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $allowedMimes[$mime];
                    $uploadedProofAbsolute = $uploadDir . '/' . $safeName;
                    $proofPhotoPath = 'uploads/claim_proofs/' . $safeName;

                    if (!move_uploaded_file($file['tmp_name'], $uploadedProofAbsolute)) {
                        $uploadError = 'The proof photo could not be saved.';
                        $proofPhotoPath = '';
                        $uploadedProofAbsolute = '';
                    }
                }
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($uploadError !== '') {

        $error = $uploadError;

    } elseif ($ownership === '' || $identifying === '') {

        $error =
            'Please provide both an ownership explanation and identifying information.';

    } else {

        /*
        |--------------------------------------------------------------------------
        | CHECK DUPLICATE CLAIM AGAIN
        |--------------------------------------------------------------------------
        | Different students are allowed to claim the same item.
        | Only the same student is prevented from creating another
        | pending claim for that item.
        */

        $dupe = $pdo->prepare("
            SELECT id
            FROM claims
            WHERE item_id = ?
              AND claimant_user_id = ?
              AND claim_status = 'pending'
            LIMIT 1
        ");

        $dupe->execute([
            $itemId,
            $currentUserId
        ]);

        if ($dupe->fetchColumn()) {

            $error =
                'You already have a pending claim for this item.';

        } else {

            if (!$canClaim) {
                $error = $itemUnavailableMessage;
            } else {

            /*
            |--------------------------------------------------------------------------
            | RE-CHECK ITEM STATUS
            |--------------------------------------------------------------------------
            | The item may have changed while the student had the page open.
            |--------------------------------------------------------------------------
            */

            $check = $pdo->prepare("
                SELECT
                    r.id,
                    r.item_id,
                    r.item_status,
                    r.report_type,
                    r.report_status,
                    i.item_name
                FROM reports r
                JOIN items i
                    ON i.id = r.item_id
                WHERE r.item_id = ?
                  AND LOWER(TRIM(r.report_type)) = 'found'
                  AND LOWER(TRIM(r.report_status)) = 'approved'
                  AND LOWER(TRIM(r.item_status)) IN ('open', 'claim_pending', 'matched')
                LIMIT 1
            ");

            $check->execute([$itemId]);

            $currentItem = $check->fetch();

            if (!$currentItem) {

                $error =
                    'This item is no longer available for claim requests.';

            } elseif (
                strtolower(trim((string)($currentItem['item_status'] ?? ''))) === 'matched'
                && $hasVerificationSchedule
            ) {

                $error =
                    'This item already has an approved match and a scheduled verification. New claims are no longer accepted.';

            } else {

                try {

                    $pdo->beginTransaction();

                    /*
                    |--------------------------------------------------------------------------
                    | INSERT CLAIM
                    |--------------------------------------------------------------------------
                    */

                    $now = now();

                    $insert = $pdo->prepare("
                        INSERT INTO claims (
                            item_id,
                            claimant_user_id,
                            ownership_description,
                            identifying_information,
                            supporting_evidence,
                            proof_photo_path,
                            claim_status,
                            created_at,
                            updated_at
                        )
                        VALUES (
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            'pending',
                            ?,
                            ?
                        )
                    ");

                    $insert->execute([
                        $itemId,
                        $currentUserId,
                        $ownership,
                        $identifying,
                        $evidence,
                        $proofPhotoPath,
                        $now,
                        $now
                    ]);

                    $claimId = (int)$pdo->lastInsertId();


                    /*
                    |--------------------------------------------------------------------------
                    | FIRST CLAIM
                    |--------------------------------------------------------------------------
                    | If this was the first claim, change:
                    |
                    | open → claim_pending
                    |
                    | If another student already claimed the item, it is
                    | already claim_pending, so leave the status as-is.
                    |--------------------------------------------------------------------------
                    */

                    if ($currentItem['item_status'] === 'open') {

                        $update = $pdo->prepare("
                            UPDATE reports
                            SET
                                item_status = 'claim_pending',
                                updated_at = ?
                            WHERE item_id = ?
                              AND report_type = 'found'
                              AND report_status = 'approved'
                              AND item_status = 'open'
                        ");

                        $update->execute([
                            $now,
                            $itemId
                        ]);

                        add_status_history(
                            $pdo,
                            (int)$currentItem['id'],
                            'open',
                            'claim_pending',
                            $currentUserId,
                            'A claim was submitted.'
                        );

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | ADDITIONAL CLAIM
                        |--------------------------------------------------------------------------
                        | Another student already has a pending claim.
                        | Keep the item in claim_pending.
                        |--------------------------------------------------------------------------
                        */

                        add_status_history(
                            $pdo,
                            (int)$currentItem['id'],
                            'claim_pending',
                            'claim_pending',
                            $currentUserId,
                            'Additional ownership claim submitted by another claimant.'
                        );
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | NOTIFICATION
                    |--------------------------------------------------------------------------
                    */

                    notify_user(
                        $pdo,
                        $currentUserId,
                        'Claim submitted',
                        'Your claim for ' .
                        $currentItem['item_name'] .
                        ' is awaiting administrator verification.',
                        'claim',
                        (int)$currentItem['id'],
                        $claimId
                    );


                    $pdo->commit();


                    /*
                    |--------------------------------------------------------------------------
                    | SUCCESS
                    |--------------------------------------------------------------------------
                    */

                    redirect(
                        'claim_success.php?id=' . $claimId
                    );

                } catch (Throwable $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    if ($uploadedProofAbsolute !== '' && is_file($uploadedProofAbsolute)) {
                        @unlink($uploadedProofAbsolute);
                    }

                    $error =
                        'Unable to submit your claim right now. Please try again.';
                }
            }
            }
        }
    }
}


$pageTitle = 'Claim Item';

require 'includes/header.php';

?>

<div class="container page-head">

    <span class="eyebrow">
        Ownership verification
    </span>

    <h1>
        Claim <?= h($item['item_name']) ?>
    </h1>

    <p>
        Provide information that only the legitimate owner would reasonably know.
    </p>

    <?php if ($itemStatus === 'claim_pending'): ?>
        <div class="notice info">
            <strong>Multiple claims are allowed.</strong> Other students may also submit a claim for this item while ownership is under administrator review. A potential match does not prevent you from submitting your own ownership claim.
        </div>
    <?php endif; ?>

</div>


<div class="container form-layout">

    <div class="form-card">

        <?php if ($error): ?>

            <div class="flash error">
                <?= h($error) ?>
            </div>

        <?php endif; ?>


        <?php if ($existingClaim && !$error): ?>

            <div class="notice info">
                <strong>You already have a pending claim for this item.</strong>
                You cannot submit another pending claim for the same item using this account.
            </div>

        <?php elseif (!$canClaim): ?>

            <div class="notice info">
                <strong><?= h($itemUnavailableMessage) ?></strong>
            </div>

            <div class="form-actions">
                <a class="btn ghost" href="item.php?id=<?= (int)$item['id'] ?>">Back to Item</a>
            </div>

        <?php else: ?>

            <form
                method="post"
                enctype="multipart/form-data"
                class="form"
            >

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= h(csrf_token()) ?>"
                >

                <input
                    type="hidden"
                    name="item_id"
                    value="<?= (int)$itemId ?>"
                >


                <label>

                    Why do you believe this item belongs to you? *

                    <textarea
                        name="ownership_description"
                        rows="4"
                        required
                    ></textarea>

                </label>


                <label>

                    Identifying information *

                    <textarea
                        name="identifying_information"
                        rows="4"
                        placeholder="Private details, markings, contents, or characteristics not publicly visible."
                        required
                    ></textarea>

                </label>


                <label>

                    Supporting evidence

                    <textarea
                        name="supporting_evidence"
                        rows="4"
                        placeholder="Describe any evidence you can present in person."
                    ></textarea>

                </label>


                <div class="notice info">

                    <strong>
                        For in-person verification:
                    </strong>

                    bring your valid university ID or another authorized
                    identification document and be ready to provide
                    supporting details.

                    Do not upload passwords or financial information.

                </div>


                <label class="check">

                    <input
                        type="checkbox"
                        required
                    >

                    I confirm that the information is truthful and that
                    I understand the item will be released only after
                    verification.

                </label>


                <div class="form-actions">

                    <button
                        type="submit"
                        class="btn primary"
                    >
                        Submit Claim
                    </button>

                    <a
                        class="btn ghost"
                        href="item.php?id=<?= (int)$item['id'] ?>"
                    >
                        Cancel
                    </a>

                </div>

            </form>

        <?php endif; ?>

    </div>

</div>


<?php require 'includes/footer.php'; ?>
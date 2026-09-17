<?php

require 'config.php';
require_login();

/*
|--------------------------------------------------------------------------
| Determine Report Type
|--------------------------------------------------------------------------
|
| The page can be opened as:
| report_item.php?type=lost
| report_item.php?type=found
|
| After submitting, the hidden report_type keeps the selected tab.
|
*/

$type = $_GET['type'] ?? $_POST['report_type'] ?? 'lost';

if (!in_array($type, ['lost', 'found'], true)) {
    $type = 'lost';
}

$pageTitle = 'Report an Item';
$error = '';

/*
|--------------------------------------------------------------------------
| Handle Form Submission
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    /*
    |--------------------------------------------------------------------------
    | Selected report type
    |--------------------------------------------------------------------------
    */

    $type = $_POST['report_type'] ?? 'lost';

    if (!in_array($type, ['lost', 'found'], true)) {
        $type = 'lost';
    }


    /*
    |--------------------------------------------------------------------------
    | Basic item information
    |--------------------------------------------------------------------------
    */

    $name = trim($_POST['item_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');

    $color = trim($_POST['color'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $features = trim($_POST['identifying_features'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | Date / Time / Location
    |--------------------------------------------------------------------------
    */

    $date = trim($_POST['event_date'] ?? '');
    $time = trim($_POST['event_time'] ?? '');

    $location = trim($_POST['campus_location'] ?? '');
    $building = trim($_POST['building'] ?? '');
    $floor = trim($_POST['floor'] ?? '');
    $area = trim($_POST['specific_area'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | Found-item custody location
    |--------------------------------------------------------------------------
    */

    $storage = trim($_POST['storage_location'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | Additional information
    |--------------------------------------------------------------------------
    */

    $notes = trim($_POST['additional_notes'] ?? '');


    /*
    |--------------------------------------------------------------------------
    | Validate Required Fields
    |--------------------------------------------------------------------------
    */

    if (
        $name === '' ||
        $category === '' ||
        $description === '' ||
        $date === '' ||
        $location === ''
    ) {

        $error = 'Please complete all required fields.';

    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | Save Uploaded Photo
            |--------------------------------------------------------------------------
            */

            $photo = save_uploaded_photo(
                $_FILES['photo'] ?? [],
                $uploadPath
            );


            /*
            |--------------------------------------------------------------------------
            | Start Database Transaction
            |--------------------------------------------------------------------------
            */

            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Create Item
            |--------------------------------------------------------------------------
            */

            $pdo->prepare("
                INSERT INTO items
                (
                    item_name,
                    category,
                    description,
                    color,
                    brand,
                    identifying_features,
                    photo_path,
                    created_at,
                    updated_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $name,
                $category,
                $description,
                $color,
                $brand,
                $features,
                $photo,
                now(),
                now()
            ]);


            $itemId = (int)$pdo->lastInsertId();


            /*
            |--------------------------------------------------------------------------
            | Create Report
            |--------------------------------------------------------------------------
            */

            $pdo->prepare("
                INSERT INTO reports
                (
                    user_id,
                    item_id,
                    report_type,
                    event_date,
                    event_time,
                    campus_location,
                    building,
                    floor,
                    specific_area,
                    storage_location,
                    additional_notes,
                    report_status,
                    item_status,
                    created_at,
                    updated_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                current_user()['id'],
                $itemId,
                $type,
                $date,
                $time,
                $location,
                $building,
                $floor,
                $area,
                $storage !== '' ? $storage : null,
                $notes,
                'approved',
                'open',
                now(),
                now()
            ]);


            $reportId = (int)$pdo->lastInsertId();


            /*
            |--------------------------------------------------------------------------
            | Status History
            |--------------------------------------------------------------------------
            */

            add_status_history(
                $pdo,
                $reportId,
                null,
                'open',
                current_user()['id'],
                'Report submitted and automatically added to the system.'
            );


            /*
            |--------------------------------------------------------------------------
            | Notification
            |--------------------------------------------------------------------------
            */

            notify_user(
                $pdo,
                current_user()['id'],
                'Report submitted',
                'Your ' .
                $type .
                ' item report RPT-' .
                str_pad(
                    (string)$reportId,
                    4,
                    '0',
                    STR_PAD_LEFT
                ) .
                ' has been added to the Find IT system and is now active. The system will check for potential matches.',
                'report',
                $reportId
            );


            /*
            |--------------------------------------------------------------------------
            | Complete Transaction
            |--------------------------------------------------------------------------
            */

            $pdo->commit();


            redirect(
                'report_success.php?id=' .
                $reportId
            );


        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}


require 'includes/header.php';

?>


<style>

/* =========================================================
   REPORT ITEM PAGE
========================================================= */

.report-item-page {
    padding-bottom: 50px;
}


/* =========================================================
   PAGE HEADER
========================================================= */

.report-item-head {
    margin-bottom: 25px;
}

.report-item-head h1 {
    margin-bottom: 7px;
}

.report-item-head p {
    max-width: 850px;
    color: #68788e;
}


/* =========================================================
   REPORT TYPE TABS
========================================================= */

.report-type-tabs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 5px;

    background: #f0f3f8;
    border-radius: 11px;

    padding: 5px;

    margin-bottom: 24px;
}


.report-type-tab {
    display: flex;
    align-items: center;
    justify-content: center;

    min-height: 42px;

    padding: 10px 16px;

    border-radius: 9px;

    border: 1px solid transparent;

    background: transparent;

    color: #52627a;

    font-size: 12px;
    font-weight: 700;

    text-decoration: none;

    transition:
        background .15s ease,
        color .15s ease,
        box-shadow .15s ease,
        border-color .15s ease;
}


.report-type-tab:hover {
    color: #9b1c2c;
}


.report-type-tab.active {
    background: #ffffff;

    color: #9b1c2c;

    border-color: #e2e7ef;

    box-shadow: 0 2px 7px rgba(30, 45, 70, .08);
}


.report-type-tab.found.active {
    color: #16865b;
}


/* =========================================================
   FORM CARD
========================================================= */

.report-form-card {
    background: #ffffff;

    border: 1px solid #e1e7f0;

    border-radius: 15px;

    padding: 24px;

    box-shadow:
        0 6px 18px rgba(30, 45, 70, .05);
}


/* =========================================================
   SECTION
========================================================= */

.report-form-section {
    padding-bottom: 23px;
    margin-bottom: 23px;

    border-bottom: 1px solid #e8edf3;
}


.report-form-section:last-of-type {
    border-bottom: none;

    margin-bottom: 0;
}


.report-form-section h2 {
    margin: 0 0 17px;

    color: #1c2a40;

    font-size: 16px;
}


/* =========================================================
   FORM GRID
========================================================= */

.report-form-grid {
    display: grid;

    grid-template-columns:
        repeat(2, minmax(0, 1fr));

    gap: 15px;
}


.report-form-grid .full-width {
    grid-column: 1 / -1;
}


/* =========================================================
   LABELS
========================================================= */

.report-form-card label {
    display: block;

    color: #33445c;

    font-size: 11px;
    font-weight: 700;
}


.report-form-card input,
.report-form-card select,
.report-form-card textarea {
    width: 100%;

    margin-top: 7px;

    padding: 11px 12px;

    box-sizing: border-box;

    border: 1px solid #d6dfeb;

    border-radius: 8px;

    background: #ffffff;

    color: #1d2940;

    font-family: inherit;

    font-size: 12px;

    outline: none;

    transition:
        border-color .15s ease,
        box-shadow .15s ease;
}


.report-form-card input:focus,
.report-form-card select:focus,
.report-form-card textarea:focus {
    border-color: #9b1c2c;

    box-shadow:
        0 0 0 3px rgba(155, 28, 44, .08);
}


.report-form-card textarea {
    resize: vertical;

    min-height: 90px;
}


/* =========================================================
   FILE INPUT
========================================================= */

.report-form-card input[type="file"] {
    padding: 8px;
}


/* =========================================================
   HELPER TEXT
========================================================= */

.field-help {
    display: block;

    margin-top: 5px;

    color: #8190a5;

    font-size: 9px;

    font-weight: 500;

    line-height: 1.45;
}


/* =========================================================
   MATCHING BADGE
========================================================= */

.matching-badge {
    display: inline-flex;

    margin-left: 5px;

    padding: 3px 7px;

    border-radius: 5px;

    background: #f8e9ec;

    color: #9b1c2c;

    font-size: 9px;

    font-weight: 700;

    vertical-align: middle;
}


/* =========================================================
   FOUND CUSTODY NOTICE
========================================================= */

.custody-notice {
    padding: 11px 12px;

    margin-bottom: 15px;

    border: 1px solid #b8ead4;

    border-radius: 9px;

    background: #f2fcf7;

    color: #267557;

    font-size: 10px;

    line-height: 1.5;
}


/* =========================================================
   ERROR
========================================================= */

.report-error {
    margin-bottom: 20px;

    padding: 11px 13px;

    border: 1px solid #e3b2ba;

    border-radius: 9px;

    background: #fdf1f3;

    color: #9b1c2c;

    font-size: 11px;

    font-weight: 600;
}


/* =========================================================
   ACTIONS
========================================================= */

.report-form-actions {
    display: flex;

    justify-content: flex-end;

    align-items: center;

    gap: 10px;

    padding-top: 5px;
}


.report-form-actions .btn {
    min-width: 125px;
}


/* =========================================================
   SIDE INFORMATION
========================================================= */

.report-info-card {
    background: #ffffff;

    border: 1px solid #e1e7f0;

    border-radius: 15px;

    padding: 20px;

    box-shadow:
        0 6px 18px rgba(30, 45, 70, .04);
}


.report-info-card h3 {
    margin: 0 0 13px;

    color: #1c2a40;

    font-size: 16px;
}


.report-info-card ol {
    margin: 0;

    padding-left: 19px;
}


.report-info-card li {
    margin-bottom: 10px;

    color: #66778e;

    font-size: 11px;

    line-height: 1.5;
}


.report-info-card li:last-child {
    margin-bottom: 0;
}


/* =========================================================
   FOUND SIDE CARD
========================================================= */

.found-info-card {
    border-color: #b6e9d3;

    background: #f7fdf9;
}


.found-info-card h3 {
    color: #176d4b;
}


/* =========================================================
   FORM LAYOUT
========================================================= */

.report-content-layout {
    display: grid;

    grid-template-columns:
        minmax(0, 1fr) 300px;

    gap: 20px;

    align-items: start;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 900px) {

    .report-content-layout {
        grid-template-columns: 1fr;
    }

    .report-info-card {
        order: 2;
    }

}


@media (max-width: 650px) {

    .report-type-tabs {
        grid-template-columns: 1fr;
    }

    .report-form-grid {
        grid-template-columns: 1fr;
    }

    .report-form-grid .full-width {
        grid-column: auto;
    }

    .report-form-card {
        padding: 16px;
    }

    .report-form-actions {
        flex-direction: column-reverse;
        align-items: stretch;
    }

    .report-form-actions .btn {
        width: 100%;
    }

}

</style>


<div class="container report-item-page">


    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div class="page-head report-item-head">

        <span class="eyebrow">
            Objective 1
        </span>

        <h1>
            Report an Item
        </h1>

        <p>
            Help return lost belongings or report items you
            have found on campus. Choose the appropriate
            report type below and provide as much accurate
            information as possible.
        </p>

    </div>


    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <div class="report-content-layout">


        <!-- =================================================
             FORM
        ================================================== -->

        <div class="report-form-card">


            <!-- =============================================
                 REPORT TYPE TABS
            ============================================== -->

            <div class="report-type-tabs">


             <a
                href="report.php?type=lost"
                class="report-type-tab <?= $type === 'lost' ? 'active' : '' ?>"
            >
                I Lost an Item (Report Lost)
            </a>

            <a
                href="report.php?type=found"
                class="report-type-tab found <?= $type === 'found' ? 'active' : '' ?>"
            >
                I Found an Item (Report Found)
            </a>

            </div>


            <!-- =================================================
                 ERROR
            ================================================== -->

            <?php if ($error): ?>

                <div class="report-error">
                    <?= h($error) ?>
                </div>

            <?php endif; ?>


            <!-- =================================================
                 REPORT FORM
            ================================================== -->

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
                    name="report_type"
                    value="<?= h($type) ?>"
                >


                <!-- =============================================
                     ITEM INFORMATION
                ============================================== -->

                <div class="report-form-section">

                    <h2>
                        Item information
                    </h2>


                    <div class="report-form-grid">


                        <!-- ITEM NAME -->

                        <label>

                            Item Name *

                            <input
                                type="text"
                                name="item_name"
                                placeholder="e.g. Navy Blue Hydro Flask 32oz"
                                value="<?= h($_POST['item_name'] ?? '') ?>"
                                required
                            >

                        </label>


                        <!-- CATEGORY -->

                        <label>

                            Category *

                            <select
                                name="category"
                                required
                            >

                                <option value="">
                                    Select category
                                </option>

                                <?php

                                $categories = [
                                    'Electronics',
                                    'ID & Cards',
                                    'Wallets & Bags',
                                    'Books & Notes',
                                    'Keys',
                                    'Clothing & Accessories',
                                    'Sports Equipment',
                                    'Jewelry',
                                    'Glasses & Sunglasses',
                                    'Headphones',
                                    'Water Bottles',
                                    'Other'
                                ];

                                $selectedCategory =
                                    $_POST['category'] ?? '';

                                ?>

                                <?php foreach ($categories as $c): ?>

                                    <option
                                        value="<?= h($c) ?>"
                                        <?= $selectedCategory === $c ? 'selected' : '' ?>
                                    >
                                        <?= h($c) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </label>


                        <!-- COLOR -->

                        <label>

                            Color

                            <input
                                type="text"
                                name="color"
                                placeholder="e.g. Navy blue"
                                value="<?= h($_POST['color'] ?? '') ?>"
                            >

                        </label>


                        <!-- BRAND -->

                        <label>

                            Brand

                            <input
                                type="text"
                                name="brand"
                                placeholder="e.g. Hydro Flask"
                                value="<?= h($_POST['brand'] ?? '') ?>"
                            >

                        </label>


                        <!-- DESCRIPTION -->

                        <label class="full-width">

                            Description *

                            <textarea
                                name="description"
                                rows="4"
                                placeholder="e.g. 32oz bottle, stainless steel body, screw-on lid, and small dent near the base..."
                                required
                            ><?= h($_POST['description'] ?? '') ?></textarea>

                        </label>


                        <!-- IDENTIFYING FEATURES -->

                        <label class="full-width">

                            Identifying Features

                            <span class="matching-badge">
                                Key for Matching &amp; Claim Proof
                            </span>

                            <textarea
                                name="identifying_features"
                                rows="3"
                                placeholder="e.g. Stickers, scratches, serial digits, keychain charm, initials..."
                            ><?= h($_POST['identifying_features'] ?? '') ?></textarea>

                            <span class="field-help">
                                Provide details that can help administrators
                                verify ownership without exposing sensitive
                                information publicly.
                            </span>

                        </label>


                        <!-- PHOTO -->

                        <label class="full-width">

                            Item Photo

                            <span class="field-help">
                                JPG, PNG, or WEBP. Maximum file size: 10MB.
                            </span>

                            <input
                                type="file"
                                name="photo"
                                accept="image/jpeg,image/png,image/webp"
                            >

                        </label>


                    </div>

                </div>


                <!-- =============================================
                     LOCATION / TIME
                ============================================== -->

                <div class="report-form-section">

                    <h2>

                        <?= $type === 'lost'
                            ? 'Where and when it was lost'
                            : 'Where and when it was found'
                        ?>

                    </h2>


                    <div class="report-form-grid">


                        <!-- DATE -->

                        <label>

                            Date *

                            <input
                                type="date"
                                name="event_date"
                                value="<?= h($_POST['event_date'] ?? '') ?>"
                                required
                            >

                        </label>


                        <!-- TIME -->

                        <label>

                            Approx. Time

                            <input
                                type="time"
                                name="event_time"
                                value="<?= h($_POST['event_time'] ?? '') ?>"
                            >

                        </label>


                        <!-- CAMPUS LOCATION -->

                        <label>

                            <?= $type === 'lost'
                                ? 'Lost Location *'
                                : 'Found Location *'
                            ?>

                            <input
                                type="text"
                                name="campus_location"
                                placeholder="e.g. Main University Library, 2nd Floor"
                                value="<?= h($_POST['campus_location'] ?? '') ?>"
                                required
                            >

                        </label>


                        <!-- BUILDING -->

                        <label>

                            Building

                            <input
                                type="text"
                                name="building"
                                placeholder="e.g. Main Library"
                                value="<?= h($_POST['building'] ?? '') ?>"
                            >

                        </label>


                        <!-- FLOOR -->

                        <label>

                            Floor

                            <input
                                type="text"
                                name="floor"
                                placeholder="e.g. 2nd Floor"
                                value="<?= h($_POST['floor'] ?? '') ?>"
                            >

                        </label>


                        <!-- SPECIFIC AREA -->

                        <label>

                            Specific Area

                            <input
                                type="text"
                                name="specific_area"
                                placeholder="e.g. Study Area A"
                                value="<?= h($_POST['specific_area'] ?? '') ?>"
                            >

                        </label>


                        <!-- ADDITIONAL NOTES -->

                        <label class="full-width">

                            Additional Notes

                            <textarea
                                name="additional_notes"
                                rows="3"
                                placeholder="Add any other information that may help the administrator..."
                            ><?= h($_POST['additional_notes'] ?? '') ?></textarea>

                        </label>


                    </div>

                </div>


                <!-- =============================================
                     ACTIONS
                ============================================== -->

                <div class="report-form-actions">

                    <a
                        class="btn ghost"
                        href="index.php"
                    >
                        Cancel
                    </a>


                    <button
                        type="submit"
                        class="btn primary"
                    >

                        Submit
                        <?= $type === 'lost' ? 'Lost' : 'Found' ?>
                        Report

                    </button>

                </div>


            </form>

        </div>


        <!-- =================================================
             INFORMATION CARD
        ================================================== -->

        <aside
            class="report-info-card <?= $type === 'found' ? 'found-info-card' : '' ?>"
        >

            <?php if ($type === 'lost'): ?>

                <h3>
                    What happens next?
                </h3>


                <ol>

                    <li>
                        Your lost-item report is automatically added
                        to the Find IT system.
                    </li>

                    <li>
                        The system keeps your report active and
                        checks for a possible potential match.
                    </li>

                    <li>
                        If a potential match is detected, an
                        administrator reviews the match and its
                        automatically generated match percentage.
                    </li>

                    <li>
                        You receive a notification when an administrator approves
                        a potential match or releases a verification schedule.
                    </li>

                </ol>


            <?php else: ?>

                <h3>
                    What happens next?
                </h3>


                <ol>

                    <li>
                        Your found-item report is automatically added
                        to the Find IT system.
                    </li>

                    <li>
                        The system keeps the found item available
                        while it checks for a possible match.
                    </li>

                    <li>
                        If a potential match is detected, the system
                        creates a potential match with an automatic
                        match percentage for administrator review.
                    </li>

                    <li>
                        If the administrator approves the match,
                        you will receive a turnover schedule for
                        personally giving the item to the administrator.
                    </li>

                </ol>

            <?php endif; ?>


        </aside>


    </div>


</div>


<?php

require 'includes/footer.php';

?>
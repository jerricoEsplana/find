<?php

require 'config.php';
require_login();

if (is_admin()) {
    redirect('admin.php');
}

$pageTitle = 'About';

require 'includes/header.php';

?>

<style>

/* =========================================================
   ABOUT / STUDENT GUIDE
========================================================= */

.about-page {
    padding-bottom: 50px;
}


/* =========================================================
   PAGE HEADER
========================================================= */

.about-hero {
    margin-bottom: 28px;
}

.about-hero .eyebrow {
    color: #b51d34;
}

.about-hero h1 {
    margin-bottom: 7px;
}

.about-hero p {
    max-width: 760px;
    color: #68788e;
}


/* =========================================================
   SECTION TITLE
========================================================= */

.about-section-title {
    margin: 28px 0 14px;
}

.about-section-title h2 {
    margin: 0;
    font-size: 20px;
    color: #17233a;
}

.about-section-title p {
    margin: 5px 0 0;
    color: #718198;
    font-size: 13px;
}


/* =========================================================
   HOW FIND IT WORKS
========================================================= */

.about-process {
    background: #ffffff;
    border: 1px solid #e1e7f0;
    border-radius: 16px;
    padding: 22px;
    box-shadow: 0 5px 16px rgba(30, 45, 70, .05);
}


.about-process-grid {
    display: grid;
    grid-template-columns:
        repeat(5, 1fr);
    gap: 12px;
}


.about-process-step {
    position: relative;
    min-height: 135px;
    padding: 17px 14px;
    border: 1px solid #e2e7ef;
    border-radius: 12px;
    background: #fbfcfe;
}


.about-process-step::after {
    content: "→";
    position: absolute;
    right: -17px;
    top: 50%;
    transform: translateY(-50%);
    color: #9ba9bd;
    font-size: 18px;
    font-weight: 700;
    z-index: 2;
}


.about-process-step:last-child::after {
    display: none;
}


.about-process-number {
    width: 27px;
    height: 27px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: #eeeaff;
    color: #5638f5;
    font-size: 11px;
    font-weight: 800;
    margin-bottom: 10px;
}


.about-process-step strong {
    display: block;
    margin-bottom: 6px;
    color: #1d2940;
    font-size: 13px;
}


.about-process-step span {
    display: block;
    color: #718097;
    font-size: 11px;
    line-height: 1.55;
}


.about-process-step.final {
    border-color: #a8e9ce;
    background: #f0fcf7;
}


.about-process-step.final .about-process-number {
    background: #d9f8e9;
    color: #138356;
}


/* =========================================================
   TWO COLUMN FEATURE SECTION
========================================================= */

.about-two-column {
    display: grid;
    grid-template-columns:
        1fr 1fr;
    gap: 20px;
    margin-top: 22px;
}


.about-card {
    background: #ffffff;
    border: 1px solid #e1e7f0;
    border-radius: 15px;
    padding: 20px;
    box-shadow: 0 5px 16px rgba(30, 45, 70, .04);
}


.about-card.purple {
    border-color: #ded2ff;
    background: #fcf9ff;
}


.about-card.green {
    border-color: #a8ebd0;
    background: #f3fcf8;
}


.about-card-heading {
    display: flex;
    align-items: center;
    gap: 9px;
    margin-bottom: 10px;
}


.about-card-icon {
    width: 29px;
    height: 29px;
    display: grid;
    place-items: center;
    border-radius: 8px;
    background: #eee8ff;
    color: #6138f5;
    font-weight: 800;
}


.about-card.green .about-card-icon {
    background: #d9f8e9;
    color: #16875c;
}


.about-card h3 {
    margin: 0;
    color: #1b2940;
    font-size: 14px;
}


.about-card-intro {
    margin: 0 0 14px;
    color: #67788f;
    font-size: 11px;
    line-height: 1.6;
}


/* =========================================================
   CLAIM HANDLING
========================================================= */

.about-rule-list {
    display: grid;
    gap: 8px;
}


.about-rule {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    padding: 10px;
    border: 1px solid #eadfff;
    border-radius: 9px;
    background: #ffffff;
}


.about-rule-number {
    width: 20px;
    height: 20px;
    flex: 0 0 20px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: #f0e8ff;
    color: #7437ec;
    font-size: 9px;
    font-weight: 800;
}


.about-rule-text {
    color: #35455d;
    font-size: 11px;
    line-height: 1.5;
}


.about-result {
    margin-top: 9px;
    padding: 10px;
    border-radius: 9px;
    background: #e8fbf2;
    border: 1px solid #9ee6c7;
    color: #106d4a;
    font-size: 11px;
    font-weight: 700;
}


.about-result.reject {
    background: #fff0f2;
    border-color: #ffc1ca;
    color: #b71939;
}


/* =========================================================
   ITEM LIFECYCLE
========================================================= */

.lifecycle-grid {
    display: grid;
    grid-template-columns:
        repeat(3, 1fr);
    gap: 9px;
    margin-top: 5px;
}


.lifecycle-step {
    min-height: 62px;
    padding: 9px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    text-align: center;
    border: 1px solid #dfe6ef;
    border-radius: 10px;
    background: #ffffff;
}


.lifecycle-step strong {
    color: #1d2940;
    font-size: 11px;
}


.lifecycle-step span {
    margin-top: 4px;
    color: #7a899e;
    font-size: 9px;
}


.lifecycle-step.open {
    border-color: #9de7c7;
}


.lifecycle-step.match {
    border-color: #ffdd81;
    background: #fffdf4;
}


.lifecycle-step.pending {
    border-color: #a9d2ff;
    background: #f7fbff;
}


.lifecycle-step.release {
    border-color: #aebfff;
    background: #f8f9ff;
}


.lifecycle-step.returned {
    border-color: #9de7c7;
    background: #f3fcf8;
}


.lifecycle-step.closed {
    border-color: #d8dfe8;
}


/* =========================================================
   RETURN TO OPEN NOTICE
========================================================= */

.about-lifecycle-note {
    margin-top: 11px;
    padding: 11px;
    border: 1px solid #ffdd83;
    border-radius: 9px;
    background: #fffaf0;
    color: #81510b;
    font-size: 11px;
    line-height: 1.55;
}


/* =========================================================
   STATUS GUIDE
========================================================= */

.status-grid {
    display: grid;
    grid-template-columns:
        repeat(3, 1fr);
    gap: 16px;
}


.status-card {
    background: #ffffff;
    border: 1px solid #e1e7f0;
    border-radius: 14px;
    padding: 17px;
}


.status-card h3 {
    margin: 0 0 11px;
    color: #64758d;
    font-size: 11px;
    letter-spacing: .06em;
    text-transform: uppercase;
}


.status-item {
    padding: 9px;
    margin-bottom: 7px;
    border-radius: 8px;
    background: #fbfcfe;
    color: #4d5e76;
    font-size: 10px;
    line-height: 1.45;
}


.status-item:last-child {
    margin-bottom: 0;
}


.status-item strong {
    color: #1d2b42;
}


/* =========================================================
   NOTIFICATIONS
========================================================= */

.notification-card {
    background: #ffffff;
    border: 1px solid #e1e7f0;
    border-radius: 15px;
    padding: 20px;
    box-shadow: 0 5px 16px rgba(30, 45, 70, .04);
}


.notification-grid {
    display: grid;
    grid-template-columns:
        repeat(4, 1fr);
    gap: 9px;
}


.notification-item {
    padding: 10px 11px;
    border-radius: 8px;
    background: #f8f9fc;
    color: #55667d;
    font-size: 10px;
}


.notification-item::before {
    content: "•";
    margin-right: 6px;
    color: #6040f5;
    font-weight: 800;
}


/* =========================================================
   STUDENT TIPS
========================================================= */

.about-tips {
    margin-top: 22px;
    padding: 18px 20px;
    border-radius: 14px;
    border: 1px solid #d7e0ed;
    background: #f8faff;
}


.about-tips h3 {
    margin: 0 0 9px;
    color: #1d2a41;
    font-size: 14px;
}


.about-tips ul {
    margin: 0;
    padding-left: 18px;
}


.about-tips li {
    margin: 5px 0;
    color: #63748b;
    font-size: 11px;
    line-height: 1.5;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 900px) {

    .about-process-grid {
        grid-template-columns:
            repeat(2, 1fr);
    }


    .about-process-step::after {
        display: none;
    }


    .about-two-column {
        grid-template-columns: 1fr;
    }


    .status-grid {
        grid-template-columns: 1fr;
    }


    .notification-grid {
        grid-template-columns:
            repeat(2, 1fr);
    }
}


@media (max-width: 600px) {

    .about-process-grid {
        grid-template-columns: 1fr;
    }


    .lifecycle-grid {
        grid-template-columns: 1fr 1fr;
    }


    .notification-grid {
        grid-template-columns: 1fr;
    }


    .about-card,
    .about-process,
    .notification-card {
        padding: 15px;
    }
}

</style>


<div class="container about-page">


    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div class="about-hero">

        <span class="eyebrow">
            ABOUT FIND IT
        </span>

        <h1>
            Find IT Student Guide
        </h1>

        <p>
            Learn how the Campus Lost &amp; Found system
            handles reports, verification, potential matches,
            ownership claims, and item returns.
        </p>

    </div>


    <!-- =====================================================
         HOW FIND IT WORKS
    ====================================================== -->

    <div class="about-section-title">

        <h2>
            How Find IT Works
        </h2>

        <p>
            Follow the journey of a lost or found item
            from reporting to its successful return.
        </p>

    </div>


    <div class="about-process">

        <div class="about-process-grid">


            <div class="about-process-step">

                <div class="about-process-number">
                    1
                </div>

                <strong>
                    Report an Item
                </strong>

                <span>
                    Student submits a lost or found report
                    with item and location details.
                </span>

            </div>


            <div class="about-process-step">

                <div class="about-process-number">
                    2
                </div>

                <strong>
                    Security Verification
                </strong>

                <span>
                    Campus Security reviews the report
                    before it becomes a verified record.
                </span>

            </div>


            <div class="about-process-step">

                <div class="about-process-number">
                    3
                </div>

                <strong>
                    Algorithmic Matching
                </strong>

                <span>
                    The system compares approved lost and
                    found records for possible matches.
                </span>

            </div>


            <div class="about-process-step">

                <div class="about-process-number">
                    4
                </div>

                <strong>
                    Ownership Claim
                </strong>

                <span>
                    The possible owner submits evidence and
                    waits for administrator verification.
                </span>

            </div>


            <div class="about-process-step final">

                <div class="about-process-number">
                    5
                </div>

                <strong>
                    Verified Return
                </strong>

                <span>
                    Security coordinates the release and
                    updates the item status to returned.
                </span>

            </div>


        </div>

    </div>


    <!-- =====================================================
         MULTIPLE CLAIMS + LIFECYCLE
    ====================================================== -->

    <div class="about-two-column">


        <!-- =================================================
             MULTIPLE CLAIMS
        ================================================== -->

        <section class="about-card purple">

            <div class="about-card-heading">

                <div class="about-card-icon">
                    ♧
                </div>

                <h3>
                    Multiple Claims Handling Protocol
                </h3>

            </div>


            <p class="about-card-intro">
                A single lost or found item can receive
                multiple claims from different students.
                The system ensures a fair and verifiable
                resolution.
            </p>


            <div class="about-rule-list">


                <div class="about-rule">

                    <div class="about-rule-number">
                        1
                    </div>

                    <div class="about-rule-text">
                        An item with a potential match can
                        receive multiple claims from different
                        students.
                    </div>

                </div>


                <div class="about-rule">

                    <div class="about-rule-number">
                        2
                    </div>

                    <div class="about-rule-text">
                        All claims appear grouped in the
                        administrator panel with comparative
                        proof.
                    </div>

                </div>


                <div class="about-rule">

                    <div class="about-rule-number">
                        3
                    </div>

                    <div class="about-rule-text">
                        The administrator examines serial
                        numbers, photos, purchase receipts,
                        and secret markings.
                    </div>

                </div>


            </div>


            <div class="about-result">

                ✓ Approves the rightful owner →
                Release scheduled with verification code

            </div>


            <div class="about-result reject">

                × Other competing claims are automatically
                rejected with an explanation notification

            </div>

        </section>


        <!-- =================================================
             ITEM LIFECYCLE
        ================================================== -->

        <section class="about-card green">

            <div class="about-card-heading">

                <div class="about-card-icon">
                    ⎇
                </div>

                <h3>
                    Item Lifecycle Flow
                </h3>

            </div>


            <p class="about-card-intro">
                Each item moves through defined states as
                reports, matches, claims, verification, and
                handover activities are completed.
            </p>


            <div class="lifecycle-grid">


                <div class="lifecycle-step open">

                    <strong>
                        1. Open
                    </strong>

                    <span>
                        Approved report
                    </span>

                </div>


                <div class="lifecycle-step match">

                    <strong>
                        2. Potential Match
                    </strong>

                    <span>
                        System detected
                    </span>

                </div>


                <div class="lifecycle-step pending">

                    <strong>
                        3. Claim Pending
                    </strong>

                    <span>
                        User claim submitted
                    </span>

                </div>


                <div class="lifecycle-step release">

                    <strong>
                        4. Release Sched.
                    </strong>

                    <span>
                        Admin approved
                    </span>

                </div>


                <div class="lifecycle-step returned">

                    <strong>
                        5. Returned
                    </strong>

                    <span>
                        Handed over to owner
                    </span>

                </div>


                <div class="lifecycle-step closed">

                    <strong>
                        6. Closed
                    </strong>

                    <span>
                        Process completed
                    </span>

                </div>


            </div>


            <div class="about-lifecycle-note">

                <strong>
                    • Can return to Open:
                </strong>

                If a claim is rejected and no other pending
                claims exist, the item returns to Open status
                so others can search and claim.

            </div>

        </section>

    </div>


    <!-- =====================================================
         STATUS GUIDE
    ====================================================== -->

    <div class="about-section-title">

        <h2>
            Understanding Statuses
        </h2>

        <p>
            Status badges help you understand where your
            report, claim, or item currently is in the process.
        </p>

    </div>


    <div class="status-grid">


        <!-- REPORT STATUSES -->

        <section class="status-card">

            <h3>
                Report Statuses
            </h3>


            <div class="status-item">

                <strong>
                    Pending Review:
                </strong>

                Submitted, awaiting admin validation.

            </div>


            <div class="status-item">

                <strong>
                    Approved:
                </strong>

                Activated and added to catalog.

            </div>


            <div class="status-item">

                <strong>
                    Rejected:
                </strong>

                Dismissed with explanatory reason.

            </div>

        </section>


        <!-- CLAIM STATUSES -->

        <section class="status-card">

            <h3>
                Claim Statuses
            </h3>


            <div class="status-item">

                <strong>
                    Pending:
                </strong>

                Queued for admin proof verification.

            </div>


            <div class="status-item">

                <strong>
                    Approved:
                </strong>

                Verified; release schedule provided.

            </div>


            <div class="status-item">

                <strong>
                    Rejected:
                </strong>

                Denied with documented reason.

            </div>

        </section>


        <!-- ITEM STATUSES -->

        <section class="status-card">

            <h3>
                Item Statuses
            </h3>


            <div class="status-item">

                <strong>
                    Open:
                </strong>

                Available &amp; searchable.

            </div>


            <div class="status-item">

                <strong>
                    Potential Match:
                </strong>

                System detected match.

            </div>


            <div class="status-item">

                <strong>
                    Claim Pending:
                </strong>

                User submitted claim.

            </div>


            <div class="status-item">

                <strong>
                    Release Scheduled:
                </strong>

                Approved pickup.

            </div>


            <div class="status-item">

                <strong>
                    Returned:
                </strong>

                Physically handed over.

            </div>


            <div class="status-item">

                <strong>
                    Closed:
                </strong>

                Finalized in archive.

            </div>

        </section>


    </div>


    <!-- =====================================================
         NOTIFICATIONS
    ====================================================== -->

    <div class="about-section-title">

        <h2>
            Notifications You May Receive
        </h2>

        <p>
            Find IT keeps you informed when an important
            event occurs involving your report, claim, or item.
        </p>

    </div>


    <section class="notification-card">

        <div class="notification-grid">


            <div class="notification-item">
                Report submitted
            </div>


            <div class="notification-item">
                Report approved / rejected
            </div>


            <div class="notification-item">
                Potential match found
            </div>


            <div class="notification-item">
                Claim submitted
            </div>


            <div class="notification-item">
                Claim approved (with schedule)
            </div>


            <div class="notification-item">
                Claim rejected
            </div>


            <div class="notification-item">
                Item returned
            </div>


            <div class="notification-item">
                Messages from administrator
            </div>


        </div>

    </section>


    <!-- =====================================================
         STUDENT TIPS
    ====================================================== -->

    <section class="about-tips">

        <h3>
            Helpful Tips for Students
        </h3>


        <ul>

            <li>
                Provide accurate item descriptions and
                locations when submitting a report.
            </li>

            <li>
                Include identifying details that can help
                administrators verify ownership.
            </li>

            <li>
                If your item receives a potential match,
                review the item information carefully before
                submitting a claim.
            </li>

            <li>
                For claims, provide proof that can help the
                administrator distinguish your item from
                competing claims.
            </li>

            <li>
                Check your notifications and HelpDesk
                messages for updates from the administrator.
            </li>

        </ul>

    </section>


</div>


<?php

require 'includes/footer.php';

?>
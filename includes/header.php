<?php
declare(strict_types=1);

$user = current_user();
$unreadCount = 0;
$unreadMessageCount = 0;

if ($user) {

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM notifications
        WHERE user_id = ?
        AND is_read = 0
    ");

    $stmt->execute([$user['id']]);

    $unreadCount = (int)$stmt->fetchColumn();


    $messageStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM messages m
        JOIN message_threads t
            ON t.id = m.thread_id
        WHERE m.is_read = 0
        AND m.sender_user_id <> ?
        AND t.user_id = ?
    ");

    $messageStmt->execute([
        $user['id'],
        $user['id']
    ]);

    $unreadMessageCount = (int)$messageStmt->fetchColumn();
}


$current = basename($_SERVER['PHP_SELF']);

$isStudentPortal = $user && ($user['role'] ?? '') === 'user';
$isAdminPortal = $user && ($user['role'] ?? '') === 'admin';
?>

<!doctype html>

<html lang="en">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>
    <?= h($pageTitle ?? 'Find IT') ?> · Find IT
</title>

<link
    rel="stylesheet"
    href="style.css"
>


<style>

/* =========================================================
   STUDENT ACCOUNT DROPDOWN
========================================================= */

.student-account {
    position: relative;
    margin-left: 2px;
}


/* Account button */

.student-profile {
    display: flex;

    align-items: center;

    gap: 9px;

    padding: 6px 9px;

    border: 0;

    border-radius: 10px;

    background: transparent;

    color: inherit;

    text-decoration: none;

    cursor: pointer;

    font-family: inherit;

    transition:
        background .15s ease,
        box-shadow .15s ease;
}


.student-profile:hover,
.student-profile.account-open {
    background: #f8e9ec;
}


/* Avatar */

.profile-avatar {
    width: 34px;

    height: 34px;

    min-width: 34px;

    display: flex;

    align-items: center;

    justify-content: center;

    border-radius: 50%;

    background: linear-gradient(
        135deg,
        #9b1c2c,
        #741321
    );

    color: white;

    font-size: 14px;

    font-weight: 800;
}


/* Name */

.student-profile-text {
    display: flex;

    flex-direction: column;

    align-items: flex-start;

    line-height: 1.1;
}


.student-profile-text strong {
    color: #1d2940;

    font-size: 12px;

    font-weight: 800;

    white-space: nowrap;
}


.student-profile-text small {
    margin-top: 3px;

    color: #7d8ba0;

    font-size: 9px;

    font-weight: 600;
}


/* Arrow */

.account-arrow {
    margin-left: 2px;

    color: #77859a;

    font-size: 11px;

    transition: transform .15s ease;
}


.student-profile.account-open .account-arrow {
    transform: rotate(180deg);
}


/* =========================================================
   DROPDOWN
========================================================= */

.student-account-dropdown {
    position: absolute;

    top: calc(100% + 9px);

    right: 0;

    width: 215px;

    background: #ffffff;

    border: 1px solid #e2e7ef;

    border-radius: 12px;

    padding: 7px;

    box-shadow:
        0 15px 35px rgba(30, 42, 65, .15);

    z-index: 9999;

    display: none;

    animation: accountDropdownIn .14s ease;
}


.student-account-dropdown.show {
    display: block;
}


@keyframes accountDropdownIn {

    from {
        opacity: 0;

        transform: translateY(-5px);
    }

    to {
        opacity: 1;

        transform: translateY(0);
    }

}


/* Dropdown user info */

.account-dropdown-user {
    display: flex;

    align-items: center;

    gap: 10px;

    padding: 9px 9px 11px;

    margin-bottom: 5px;

    border-bottom: 1px solid #edf0f5;
}


.account-dropdown-user .profile-avatar {
    width: 36px;

    height: 36px;

    min-width: 36px;
}


.account-dropdown-user-text {
    display: flex;

    flex-direction: column;
}


.account-dropdown-user-text strong {
    color: #1c2a40;

    font-size: 12px;
}


.account-dropdown-user-text small {
    margin-top: 3px;

    color: #8491a5;

    font-size: 9px;
}


/* Dropdown links */

.account-dropdown-link {
    display: flex;

    align-items: center;

    gap: 10px;

    width: 100%;

    box-sizing: border-box;

    padding: 10px;

    border-radius: 8px;

    color: #34445b;

    text-decoration: none;

    font-size: 11px;

    font-weight: 700;

    transition:
        background .15s ease,
        color .15s ease;
}


.account-dropdown-link:hover {
    background: #f8e9ec;

    color: #9b1c2c;
}


/* Logout */

.account-dropdown-link.logout {
    color: #b51e3b;
}


.account-dropdown-link.logout:hover {
    background: #fff1f3;

    color: #b51e3b;
}


/* Icons */

.account-dropdown-icon {
    width: 18px;

    height: 18px;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 14px;
}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 900px) {

    .student-account-dropdown {
        right: 0;

        width: 205px;
    }

}


/* =========================================================
   GUEST / LOGGED-OUT HEADER
========================================================= */

.guest-main-header{
    width:100%;
    background:#ffffff;
    border-bottom:3px solid #9d1c2e;
}

.guest-header-inner{
    min-height:76px;
    display:flex;
    align-items:center;
    padding:0 58px;
    box-sizing:border-box;
}

.guest-brand{
    display:inline-flex;
    align-items:center;
    gap:12px;
    color:#1d2940;
    text-decoration:none;
}

.guest-brand-mark{
    width:44px;
    height:44px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:10px;
    background:#9d1c2e;
    color:#ffffff;
    font-size:20px;
    font-weight:900;
}

.guest-brand-name{
    color:#9d1c2e;
    font-size:22px;
    font-weight:900;
    letter-spacing:-.02em;
}

@media (max-width:700px){
    .guest-header-inner{
        min-height:68px;
        padding:0 22px;
    }

    .guest-brand-mark{
        width:40px;
        height:40px;
        font-size:18px;
    }

    .guest-brand-name{
        font-size:20px;
    }
}

</style>

</head>


<body class="<?= $isStudentPortal ? 'student-portal-page' : '' ?>">


<?php if ($isStudentPortal): ?>


<header class="student-main-header">

    <div class="student-header-inner">


        <!-- =================================================
             BRAND
        ================================================== -->

        <a
            class="student-brand"
            href="index.php"
        >

            <span class="student-brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="25" height="25" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="10.5" cy="10.5" r="6.2" stroke="currentColor" stroke-width="2.4"/>
                    <path d="M15.2 15.2L20.5 20.5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>
                </svg>
            </span>

            <span class="student-brand-text">

                <strong>
                    Find IT <em>Mapúa</em>
                </strong>

                <small>
                    Student Lost &amp; Found Portal
                </small>

            </span>

        </a>


        <!-- =================================================
             MOBILE MENU
        ================================================== -->

        <button
            class="mobile-menu-btn student-mobile-menu"
            type="button"
            aria-label="Open navigation"
            onclick="document.body.classList.toggle('menu-open')"
        >
            ☰
        </button>


        <!-- =================================================
             STUDENT NAVIGATION
        ================================================== -->

        <nav class="student-nav">


            <a
                class="<?= $current === 'index.php' ? 'active' : '' ?>"
                href="index.php"
            >
                Dashboard
            </a>


            <a
                class="<?= $current === 'search.php' ? 'active' : '' ?>"
                href="search.php?reset=1"
            >
                Search &amp; Browse
            </a>


            <a
                class="<?= $current === 'my_reports.php' ? 'active' : '' ?>"
                href="my_reports.php"
            >
                My Reports
            </a>


            <a
                class="<?= $current === 'my_claims.php' ? 'active' : '' ?>"
                href="my_claims.php"
            >
                My Claims
            </a>


            <a
                class="<?= $current === 'messages.php' ? 'active' : '' ?>"
                href="messages.php"
            >
                HelpDesk/Inquiries
            </a>



            <!-- REPORT ITEM -->

            <a
                class="report-nav-btn"
                href="report.php?type=lost"
            >
                Report Item
            </a>


            <!-- =================================================
                 NOTIFICATION BELL
            ================================================== -->

            <a
                class="notification-nav"
                href="notifications.php"
                aria-label="Notifications"
                title="Notifications"
            >

                <svg
                    class="notification-bell"
                    viewBox="0 0 24 24"
                    fill="none"
                    xmlns="http://www.w3.org/2000/svg"
                    aria-hidden="true"
                >

                    <path
                        d="M18 9.5C18 6.46 15.76 4 13 4C10.24 4 8 6.46 8 9.5C8 14 6 15.5 5 17H19C18 15.5 18 14 18 9.5Z"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />

                    <path
                        d="M10.5 20C11.08 20.63 11.98 21 13 21C14.02 21 14.92 20.63 15.5 20"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                    />

                </svg>


                <?php if ($unreadCount): ?>

                    <span>
                        <?= $unreadCount ?>
                    </span>

                <?php endif; ?>

            </a>


            <!-- =================================================
                 STUDENT ACCOUNT
            ================================================== -->

            <div class="student-account">


                <!-- ACCOUNT BUTTON -->

                <button
                    type="button"
                    class="student-profile"
                    id="studentAccountButton"
                    aria-expanded="false"
                    aria-haspopup="true"
                >

                    <span class="profile-avatar">
                        <?= h(strtoupper(substr($user['name'], 0, 1))) ?>
                    </span>


                    <span class="student-profile-text">

                        <strong>
                            <?= h($user['name']) ?>
                        </strong>

                        <small>
                            Student
                        </small>

                    </span>


                    <span class="account-arrow">
                        ▾
                    </span>

                </button>


                <!-- =================================================
                     ACCOUNT DROPDOWN
                ================================================== -->

                <div
                    class="student-account-dropdown"
                    id="studentAccountDropdown"
                >


                    <!-- USER INFORMATION -->

                    <div class="account-dropdown-user">

                        <span class="profile-avatar">

                            <?= h(
                                strtoupper(
                                    substr(
                                        $user['name'],
                                        0,
                                        1
                                    )
                                )
                            ) ?>

                        </span>


                        <span class="account-dropdown-user-text">

                            <strong>
                                <?= h($user['name']) ?>
                            </strong>

                            <small>
                                Student Account
                            </small>

                        </span>

                    </div>


                    <!-- PROFILE -->

                    <a
                        class="account-dropdown-link"
                        href="profile.php"
                    >

                        <span class="account-dropdown-icon">
                            👤
                        </span>

                        My Profile

                    </a>


                    <!-- LOGOUT -->

                    <a
                        class="account-dropdown-link logout"
                        href="logout.php"
                    >

                        <span class="account-dropdown-icon">
                            🚪
                        </span>

                        Logout

                    </a>


                </div>


            </div>


        </nav>

    </div>

</header>


<?php elseif ($isAdminPortal): ?>

<!-- =========================================================
     ADMIN PORTAL HEADER
========================================================= -->

<header class="admin-main-header">

    <div class="admin-header-inner">

        <!-- =================================================
             BRAND
        ================================================== -->

        <a
            class="admin-brand"
            href="admin.php"
        >

            <span class="admin-brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="25" height="25" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="10.5" cy="10.5" r="6.2" stroke="currentColor" stroke-width="2.4"/>
                    <path d="M15.2 15.2L20.5 20.5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>
                </svg>
            </span>

            <span class="admin-brand-text">

                <strong>
                    Find IT <em>Mapúa</em>
                </strong>

                <small>
                    Administration Portal
                </small>

            </span>

        </a>


        <!-- =================================================
             MOBILE MENU
        ================================================== -->

        <button
            class="mobile-menu-btn admin-mobile-menu"
            type="button"
            aria-label="Open navigation"
            onclick="document.body.classList.toggle('menu-open')"
        >
            ☰
        </button>


        <!-- =================================================
             ADMIN NAVIGATION
        ================================================== -->

      <nav class="admin-nav">

    <a
        class="<?= $current === 'admin.php' && !isset($_GET['tab']) ? 'active' : '' ?>"
        href="admin.php"
    >
        Overview
    </a>


    <a
        class="<?= ($current === 'admin.php' && ($_GET['tab'] ?? '') === 'reports') ? 'active' : '' ?>"
        href="admin.php?tab=reports"
    >
        Reports
    </a>


    <a
        class="<?= ($current === 'admin.php' && ($_GET['tab'] ?? '') === 'matches') ? 'active' : '' ?>"
        href="admin.php?tab=matches"
    >
        Matches
    </a>


    <a
        class="<?= ($current === 'admin.php' && ($_GET['tab'] ?? '') === 'claims') ? 'active' : '' ?>"
        href="admin.php?tab=claims"
    >
        Claims
    </a>



    <a
        class="<?= ($current === 'admin.php' && ($_GET['tab'] ?? '') === 'users') ? 'active' : '' ?>"
        href="admin.php?tab=users"
    >
        Users
    </a>


    <a
        class="<?= ($current === 'admin.php' && ($_GET['tab'] ?? '') === 'statistics') ? 'active' : '' ?>"
        href="admin.php?tab=statistics"
    >
        Statistics
    </a>

    <a
        class="<?= ($current === 'admin.php' && ($_GET['tab'] ?? '') === 'messages') ? 'active' : '' ?>"
        href="admin.php?tab=messages"
    >
        Messages

        <?php if ($unreadMessageCount > 0): ?>
            <span class="admin-nav-count">
                <?= $unreadMessageCount ?>
            </span>
        <?php endif; ?>

    </a>


    <a
        class="<?= ($current === 'admin.php' && ($_GET['tab'] ?? '') === 'audit') ? 'active' : '' ?>"
        href="admin.php?tab=audit"
    >
        Audit Logs
    </a>


    <!-- NOTIFICATIONS -->

    <a
        class="admin-notification-nav"
        href="notifications.php"
        aria-label="Notifications"
        title="Notifications"
    >

        <svg
            class="admin-notification-bell"
            viewBox="0 0 24 24"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
        >

            <path
                d="M18 9.5C18 6.46 15.76 4 13 4C10.24 4 8 6.46 8 9.5C8 14 6 15.5 5 17H19C18 15.5 18 14 18 9.5Z"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
                stroke-linejoin="round"
            />

            <path
                d="M10.5 20C11.08 20.63 11.98 21 13 21C14.02 21 14.92 20.63 15.5 20"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
            />

        </svg>


        <?php if ($unreadCount > 0): ?>

            <span class="admin-notification-count">
                <?= $unreadCount ?>
            </span>

        <?php endif; ?>

    </a>


    <!-- ADMIN ACCOUNT -->

    <div class="admin-account">

        <button
            type="button"
            class="admin-profile"
            id="adminAccountButton"
            aria-expanded="false"
            aria-haspopup="true"
        >

            <span class="admin-profile-avatar">
                <?= h(
                    strtoupper(
                        substr(
                            $user['name'] ?? 'A',
                            0,
                            1
                        )
                    )
                ) ?>
            </span>


            <span class="admin-profile-text">

                <strong>
                    <?= h($user['name'] ?? 'Administrator') ?>
                </strong>

                <small>
                    Administrator
                </small>

            </span>


            <span class="admin-account-arrow">
                ▾
            </span>

        </button>


        <div
            class="admin-account-dropdown"
            id="adminAccountDropdown"
        >

            <div class="admin-dropdown-user">

                <span class="admin-profile-avatar">
                    <?= h(
                        strtoupper(
                            substr(
                                $user['name'] ?? 'A',
                                0,
                                1
                            )
                        )
                    ) ?>
                </span>

                <span class="admin-dropdown-user-text">

                    <strong>
                        <?= h($user['name'] ?? 'Administrator') ?>
                    </strong>

                    <small>
                        Administrator Account
                    </small>

                </span>

            </div>


            <a
                class="admin-dropdown-link"
                href="profile.php"
            >
                <span class="admin-dropdown-icon">👤</span>
                My Profile
            </a>


            <a
                class="admin-dropdown-link"
                href="admin.php"
            >
                <span class="admin-dropdown-icon">▦</span>
                Admin Dashboard
            </a>


            <a
                class="admin-dropdown-link logout"
                href="logout.php"
            >
                <span class="admin-dropdown-icon">🚪</span>
                Logout
            </a>

        </div>

    </div>

</nav>

    </div>

</header>

<?php else: ?>

<!-- =========================================================
     GUEST HEADER
========================================================= -->
<header class="guest-main-header">
    <div class="guest-header-inner">
        <a class="guest-brand" href="login.php" aria-label="Find IT">
            <span class="guest-brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="25" height="25" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="10.5" cy="10.5" r="6.2" stroke="currentColor" stroke-width="2.4"/>
                    <path d="M15.2 15.2L20.5 20.5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>
                </svg>
            </span>
            <span class="guest-brand-name">Find IT</span>
        </a>
    </div>
</header>

<?php endif; ?>


<main>


<?php foreach (get_flashes() as $f): ?>

    <div class="container">

        <div class="flash <?= h($f['type']) ?>">

            <?= h($f['message']) ?>

        </div>

    </div>

<?php endforeach; ?>


<script>

/* =========================================================
   STUDENT ACCOUNT DROPDOWN
========================================================= */

(function () {

    const button =
        document.getElementById('studentAccountButton');

    const dropdown =
        document.getElementById('studentAccountDropdown');


    if (!button || !dropdown) {
        return;
    }


    button.addEventListener('click', function (event) {

        event.stopPropagation();

        const isOpen =
            dropdown.classList.toggle('show');

        button.classList.toggle(
            'account-open',
            isOpen
        );

        button.setAttribute(
            'aria-expanded',
            isOpen ? 'true' : 'false'
        );

    });


    /*
    |--------------------------------------------------------------------------
    | Close when clicking outside
    |--------------------------------------------------------------------------
    */

    document.addEventListener('click', function (event) {

        if (
            !dropdown.contains(event.target) &&
            !button.contains(event.target)
        ) {

            dropdown.classList.remove('show');

            button.classList.remove(
                'account-open'
            );

            button.setAttribute(
                'aria-expanded',
                'false'
            );

        }

    });


    /*
    |--------------------------------------------------------------------------
    | Close with ESC
    |--------------------------------------------------------------------------
    */

    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape') {

            dropdown.classList.remove('show');

            button.classList.remove(
                'account-open'
            );

            button.setAttribute(
                'aria-expanded',
                'false'
            );

        }

    });

})();

/* =========================================================
   ADMIN ACCOUNT DROPDOWN
========================================================= */

(function () {

    const button =
        document.getElementById('adminAccountButton');

    const dropdown =
        document.getElementById('adminAccountDropdown');


    if (!button || !dropdown) {
        return;
    }


    button.addEventListener('click', function (event) {

        event.stopPropagation();

        const isOpen =
            dropdown.classList.toggle('show');

        button.classList.toggle(
            'account-open',
            isOpen
        );

        button.setAttribute(
            'aria-expanded',
            isOpen ? 'true' : 'false'
        );

    });


    /*
     |--------------------------------------------------------------------------
     | Close when clicking outside
     |--------------------------------------------------------------------------
    */

    document.addEventListener('click', function (event) {

        if (
            !dropdown.contains(event.target) &&
            !button.contains(event.target)
        ) {

            dropdown.classList.remove('show');

            button.classList.remove(
                'account-open'
            );

            button.setAttribute(
                'aria-expanded',
                'false'
            );

        }

    });


    /*
     |--------------------------------------------------------------------------
     | Close with ESC
     |--------------------------------------------------------------------------
    */

    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape') {

            dropdown.classList.remove('show');

            button.classList.remove(
                'account-open'
            );

            button.setAttribute(
                'aria-expanded',
                'false'
            );

        }

    });

})();

</script>
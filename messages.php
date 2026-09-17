<?php

require 'config.php';
require_login();

$user = current_user();

/*
 * Admin users are redirected to the admin messaging page.
 */
if ($user['role'] === 'admin') {
    redirect('admin.php?tab=messages');
}


/* =========================================================
   MESSAGE TABLES
========================================================= */

function ensure_message_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS message_threads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            subject TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'open'
                CHECK(status IN ('open','closed')),
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            last_message_at TEXT NOT NULL,
            FOREIGN KEY(user_id)
                REFERENCES users(id)
                ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER NOT NULL,
            sender_user_id INTEGER NOT NULL,
            message TEXT NOT NULL,
            is_read INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,

            FOREIGN KEY(thread_id)
                REFERENCES message_threads(id)
                ON DELETE CASCADE,

            FOREIGN KEY(sender_user_id)
                REFERENCES users(id)
                ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS
            idx_message_threads_user
            ON message_threads(user_id,last_message_at);

        CREATE INDEX IF NOT EXISTS
            idx_messages_thread
            ON messages(thread_id,created_at);
    ");
}

ensure_message_tables($pdo);


/* =========================================================
   POST ACTIONS
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    try {

        $action = $_POST['action'] ?? '';


        /* =================================================
           NEW THREAD
        ================================================== */

        if ($action === 'new_thread') {

            $subject =
                trim(
                    $_POST['subject'] ?? ''
                );

            $message =
                trim(
                    $_POST['message'] ?? ''
                );


            if (
                $subject === ''
                ||
                $message === ''
            ) {
                throw new RuntimeException(
                    'Please enter a subject and message.'
                );
            }


            $pdo->beginTransaction();

            $t = now();


            $pdo
                ->prepare("
                    INSERT INTO message_threads
                    (
                        user_id,
                        subject,
                        status,
                        created_at,
                        updated_at,
                        last_message_at
                    )
                    VALUES (?,?,?,?,?,?)
                ")
                ->execute(
                    [
                        $user['id'],
                        $subject,
                        'open',
                        $t,
                        $t,
                        $t
                    ]
                );


            $threadId =
                (int)$pdo->lastInsertId();


            $pdo
                ->prepare("
                    INSERT INTO messages
                    (
                        thread_id,
                        sender_user_id,
                        message,
                        is_read,
                        created_at
                    )
                    VALUES (?,?,?,?,?)
                ")
                ->execute(
                    [
                        $threadId,
                        $user['id'],
                        $message,
                        0,
                        $t
                    ]
                );


            $pdo->commit();


            flash(
                'success',
                'Your message has been sent to the Find IT administrator.'
            );
        }


        /* =================================================
           REPLY
        ================================================== */

        elseif ($action === 'reply') {

            $threadId =
                (int)(
                    $_POST['thread_id'] ?? 0
                );


            $message =
                trim(
                    $_POST['message'] ?? ''
                );


            $s =
                $pdo->prepare("
                    SELECT *
                    FROM message_threads
                    WHERE id = ?
                    AND user_id = ?
                ");

            $s->execute(
                [
                    $threadId,
                    $user['id']
                ]
            );


            $thread =
                $s->fetch();


            if (!$thread) {

                throw new RuntimeException(
                    'Conversation not found.'
                );
            }


            if ($message === '') {

                throw new RuntimeException(
                    'Please enter a message.'
                );
            }


            $t = now();


            $pdo->beginTransaction();


            $pdo
                ->prepare("
                    INSERT INTO messages
                    (
                        thread_id,
                        sender_user_id,
                        message,
                        is_read,
                        created_at
                    )
                    VALUES (?,?,?,?,?)
                ")
                ->execute(
                    [
                        $threadId,
                        $user['id'],
                        $message,
                        0,
                        $t
                    ]
                );


            $pdo
                ->prepare("
                    UPDATE message_threads
                    SET
                        status = 'open',
                        updated_at = ?,
                        last_message_at = ?
                    WHERE id = ?
                ")
                ->execute(
                    [
                        $t,
                        $t,
                        $threadId
                    ]
                );


            $pdo->commit();


            flash(
                'success',
                'Your reply has been sent.'
            );
        }


        /* =================================================
           CLOSE THREAD
        ================================================== */

        elseif ($action === 'close') {

            $threadId =
                (int)(
                    $_POST['thread_id'] ?? 0
                );


            $pdo
                ->prepare("
                    UPDATE message_threads
                    SET
                        status = 'closed',
                        updated_at = ?
                    WHERE id = ?
                    AND user_id = ?
                ")
                ->execute(
                    [
                        now(),
                        $threadId,
                        $user['id']
                    ]
                );


            flash(
                'success',
                'Conversation marked as resolved.'
            );
        }

    }

    catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }


        flash(
            'error',
            $e->getMessage()
        );
    }


    redirect(
        'messages.php'
        .
        (
            !empty($_POST['thread_id'])
                ? '?thread=' . (int)$_POST['thread_id']
                : ''
        )
    );
}


/* =========================================================
   LOAD CONVERSATIONS
========================================================= */

$threadsStmt =
    $pdo->prepare("
        SELECT
            t.*,

            (
                SELECT COUNT(*)
                FROM messages m
                WHERE m.thread_id = t.id
                AND m.is_read = 0
                AND m.sender_user_id <> ?
            ) AS unread_count,

            (
                SELECT message
                FROM messages m
                WHERE m.thread_id = t.id
                ORDER BY m.created_at DESC
                LIMIT 1
            ) AS last_message

        FROM message_threads t

        WHERE t.user_id = ?

        ORDER BY t.last_message_at DESC
    ");


$threadsStmt->execute(
    [
        $user['id'],
        $user['id']
    ]
);


$threads =
    $threadsStmt->fetchAll();


$selectedId =
    (int)(
        $_GET['thread']
        ??
        (
            array_values(
                array_filter(
                    $threads,
                    static fn($t) =>
                        trim((string)$t['subject']) === 'Question about claim'
                )
            )[0]['id']
            ?? ($threads[0]['id'] ?? 0)
        )
    );


$selected = null;

$threadMessages = [];


if ($selectedId) {

    $s =
        $pdo->prepare("
            SELECT *
            FROM message_threads
            WHERE id = ?
            AND user_id = ?
        ");


    $s->execute(
        [
            $selectedId,
            $user['id']
        ]
    );


    $selected =
        $s->fetch();


    if ($selected) {

        $m =
            $pdo->prepare("
                SELECT
                    m.*,
                    u.full_name,
                    u.role

                FROM messages m

                JOIN users u
                    ON u.id = m.sender_user_id

                WHERE m.thread_id = ?

                ORDER BY m.created_at ASC
            ");


        $m->execute(
            [
                $selectedId
            ]
        );


        $threadMessages =
            $m->fetchAll();


        /*
         * Mark administrator messages as read.
         */
        $pdo
            ->prepare("
                UPDATE messages
                SET is_read = 1

                WHERE thread_id = ?

                AND sender_user_id <> ?
            ")
            ->execute(
                [
                    $selectedId,
                    $user['id']
                ]
            );
    }
}


/* =========================================================
   LOAD FOUND ITEMS FOR LINK DROPDOWN
========================================================= */

$helpdeskItems = [];

try {

    $itemStmt =
        $pdo->query("
            SELECT
                i.id,
                i.item_name

            FROM items i

            ORDER BY i.item_name ASC
        ");

    $helpdeskItems =
        $itemStmt->fetchAll();

}
catch (Throwable $e) {

    /*
     * Keep the HelpDesk working even if the
     * item table cannot be queried.
     */
    $helpdeskItems = [];
}


$pageTitle = 'HelpDesk';

require 'includes/header.php';

?>


<style>

/* =========================================================
   HELP DESK PAGE
========================================================= */

.helpdesk-page {

    max-width: 850px;

    margin: 0 auto;

    padding:
        32px
        0
        45px;

    color: #20242a;
}


/* =========================================================
   HEADER
========================================================= */

.helpdesk-page-head {

    margin-bottom: 22px;
}


.helpdesk-eyebrow {

    display: block;

    font-size: 12px;

    font-weight: 800;

    letter-spacing: .08em;

    text-transform: uppercase;

    color: #9b1c2c;

    margin-bottom: 5px;
}


.helpdesk-page-head h1 {

    margin: 0;

    font-size: 29px;

    line-height: 1.15;

    font-weight: 800;
}


.helpdesk-page-head p {

    margin:
        7px
        0
        0;

    color: #66707a;

    font-size: 13px;
}


/* =========================================================
   DESKTOP HELP DESK PANEL
========================================================= */

.helpdesk-panel {

    background: #ffffff;

    border:
        1px solid
        #dfe3e7;

    border-radius: 15px;

    overflow: hidden;

    box-shadow:
        0 4px 14px
        rgba(30, 45, 70, .05);
}


/* =========================================================
   CUSTODIAN HEADER
========================================================= */

.helpdesk-custodian {

    min-height: 72px;

    padding:
        15px
        20px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    border-bottom:
        1px solid
        #dfe3e7;

    background: #ffffff;
}


.helpdesk-custodian-left {

    display: flex;

    align-items: center;

    gap: 12px;
}


.helpdesk-shield {

    width: 40px;

    height: 40px;

    border-radius: 50%;

    background: #f8e9ec;

    color: #9b1c2c;

    display: grid;

    place-items: center;

    font-size: 19px;

    flex-shrink: 0;
}


.helpdesk-officer-info strong {

    display: block;

    font-size: 13px;

    font-weight: 800;
}


.helpdesk-officer-line {

    display: flex;

    align-items: center;

    gap: 7px;

    margin-top: 3px;
}


.helpdesk-role {

    display: inline-flex;

    align-items: center;

    padding:
        3px
        8px;

    border-radius: 999px;

    background: #f8e9ec;

    color: #741321;

    font-size: 10px;

    font-weight: 700;
}


.helpdesk-duty {

    display: flex;

    align-items: center;

    gap: 4px;

    color: #177245;

    font-size: 10px;

    font-weight: 700;
}


.helpdesk-duty::before {

    content: "";

    width: 6px;

    height: 6px;

    border-radius: 50%;

    background: #177245;
}


.helpdesk-location {

    color: #7a858f;

    font-size: 11px;

    white-space: nowrap;
}


/* =========================================================
   CONVERSATION AREA
========================================================= */

.helpdesk-conversation {

    min-height: 350px;

    background: #f7f8fa;

    padding:
        17px
        20px;

    display: flex;

    flex-direction: column;

    gap: 14px;
}


.helpdesk-empty {

    min-height: 350px;

    display: grid;

    place-items: center;

    text-align: center;

    padding: 30px;
}


.helpdesk-empty h2 {

    margin:
        10px
        0
        5px;

    font-size: 20px;
}


.helpdesk-empty p {

    max-width: 430px;

    margin: 0 auto;

    color: #7a8799;

    font-size: 13px;
}


/* =========================================================
   MESSAGE
========================================================= */

.helpdesk-message {

    display: flex;

    flex-direction: column;

    max-width: 70%;
}


.helpdesk-message.from-user {

    align-self: flex-end;

    align-items: flex-end;
}


.helpdesk-message.from-admin {

    align-self: flex-start;

    align-items: flex-start;
}


.helpdesk-meta {

    width: 100%;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

    margin-bottom: 4px;

    color: #7b858f;

    font-size: 9px;
}


.helpdesk-message.from-user
.helpdesk-meta {

    flex-direction: row-reverse;
}


.helpdesk-meta strong {

    color: #5f6973;

    font-size: 9px;
}


.helpdesk-message-ref {

    display: inline-flex;

    align-items: center;

    padding:
        3px
        7px;

    border:
        1px solid
        #e3b2ba;

    border-radius: 5px;

    background: #f8e9ec;

    color: #741321;

    font-size: 9px;

    font-weight: 700;

    margin-bottom: 5px;
}


.helpdesk-message-bubble {

    padding:
        11px
        13px;

    border-radius: 15px;

    font-size: 12px;

    line-height: 1.65;
}


/* USER */

.helpdesk-message.from-user
.helpdesk-message-bubble {

    background: #9b1c2c;

    color: #ffffff;

    border-bottom-right-radius: 5px;

    box-shadow:
        0 4px 9px
        rgba(81,55,245,.15);
}


/* ADMIN */

.helpdesk-message.from-admin
.helpdesk-message-bubble {

    background: #ffffff;

    color: #20242a;

    border:
        1px solid
        #dfe3e7;

    border-bottom-left-radius: 5px;

    box-shadow:
        0 2px 6px
        rgba(30,45,70,.04);
}


/* =========================================================
   COMPOSER
========================================================= */

.helpdesk-composer {

    padding:
        12px
        14px;

    border-top:
        1px solid
        #dfe3e7;

    background: #ffffff;
}


.helpdesk-link-row {

    display: flex;

    align-items: center;

    gap: 8px;

    margin-bottom: 8px;
}


.helpdesk-link-icon {

    color: #7a858f;

    font-size: 14px;
}


.helpdesk-link-label {

    color: #66707a;

    font-size: 11px;

    white-space: nowrap;
}


.helpdesk-link-select {

    width: 345px;

    max-width: 100%;

    height: 30px;

    border:
        1px solid
        #dbe2ec;

    border-radius: 6px;

    background: #ffffff;

    padding:
        0
        9px;

    color: #5f6973;

    font-size: 11px;

    outline: none;
}


.helpdesk-compose-row {

    display: flex;

    align-items: center;

    gap: 8px;
}


.helpdesk-compose-row textarea {

    flex: 1;

    min-height: 38px;

    max-height: 110px;

    resize: vertical;

    border:
        1px solid
        #dfe3e7;

    border-radius: 9px;

    background: #ffffff;

    padding:
        10px
        12px;

    font-family: inherit;

    font-size: 11px;

    color: #20242a;

    outline: none;
}


.helpdesk-compose-row textarea:focus {

    border-color: #9b1c2c;

    box-shadow:
        0 0 0 3px
        rgba(81,55,245,.08);
}


.helpdesk-send {

    height: 38px;

    padding:
        0
        17px;

    border: 0;

    border-radius: 9px;

    background: #d9aeb6;

    color: #ffffff;

    font-size: 11px;

    font-weight: 800;

    cursor: not-allowed;

    white-space: nowrap;

    transition:
        background .18s ease,
        box-shadow .18s ease,
        transform .18s ease;

    box-shadow: none;

    opacity: .9;
}


.helpdesk-send.is-active {

    background: #9b1c2c;

    cursor: pointer;

    opacity: 1;

    box-shadow:
        0 5px 12px
        rgba(81,55,245,.18);
}


.helpdesk-send.is-active:hover {

    background: #741321;

    transform: translateY(-1px);

    box-shadow:
        0 7px 15px
        rgba(81,55,245,.22);
}


/* =========================================================
   NEW MESSAGE
========================================================= */

.helpdesk-new-message {

    margin-top: 18px;

    padding: 18px;

    border:
        1px solid
        #dfe3e7;

    border-radius: 13px;

    background: #ffffff;
}


.helpdesk-new-message h2 {

    margin:
        4px
        0
        3px;

    font-size: 18px;
}


.helpdesk-new-message p {

    margin:
        0
        0
        15px;

    color: #7a8799;

    font-size: 12px;
}


.helpdesk-new-message label {

    display: block;

    margin-bottom: 12px;

    color: #4c5d73;

    font-size: 11px;

    font-weight: 700;
}


.helpdesk-new-message input,
.helpdesk-new-message textarea {

    width: 100%;

    box-sizing: border-box;

    margin-top: 5px;

    padding:
        9px
        10px;

    border:
        1px solid
        #dfe3e7;

    border-radius: 7px;

    font-family: inherit;

    font-size: 11px;

    outline: none;
}


.helpdesk-new-message textarea {

    resize: vertical;
}


.helpdesk-new-message input:focus,
.helpdesk-new-message textarea:focus {

    border-color: #9b1c2c;

    box-shadow:
        0 0 0 3px
        rgba(81,55,245,.08);
}


/* =========================================================
   THREAD LIST
========================================================= */

.helpdesk-thread-list {

    margin-top: 18px;

    display: grid;

    grid-template-columns:
        repeat(
            auto-fit,
            minmax(230px, 1fr)
        );

    gap: 10px;
}


.helpdesk-thread {

    display: block;

    padding: 12px;

    border:
        1px solid
        #dfe3e7;

    border-radius: 10px;

    background: #ffffff;

    text-decoration: none;

    color: inherit;
}


.helpdesk-thread.active {

    border-color: #d9aeb6;

    background: #fbf4f5;
}


.helpdesk-thread strong {

    font-size: 12px;
}


.helpdesk-thread small {

    display: block;

    margin-top: 4px;

    color: #7b858f;

    font-size: 9px;
}


.helpdesk-thread p {

    margin:
        6px
        0;

    color: #66707a;

    font-size: 10px;

    line-height: 1.4;
}


.helpdesk-thread-status {

    display: inline-flex;

    padding:
        3px
        7px;

    border-radius: 999px;

    background: #e8f5ed;

    color: #177245;

    font-size: 9px;

    font-weight: 800;
}


.helpdesk-thread-status.closed {

    background: #eef1f4;

    color: #66707a;
}


/* =========================================================
   CLOSE BUTTON
========================================================= */

.helpdesk-resolve {

    margin-top: 10px;

    padding: 0 20px 15px;

    background: #f7f8fa;
}


.helpdesk-resolve button {

    border:
        1px solid
        #dfe3e7;

    background: #ffffff;

    border-radius: 7px;

    padding:
        7px
        11px;

    color: #65758b;

    font-size: 10px;

    font-weight: 700;

    cursor: pointer;
}


.helpdesk-new-send {
    transition:
        opacity .18s ease,
        filter .18s ease,
        transform .18s ease;
}

.helpdesk-new-send:disabled {
    opacity: .45;
    cursor: not-allowed;
    filter: grayscale(.15);
}

.helpdesk-new-send:not(:disabled) {
    opacity: 1;
    cursor: pointer;
}

.helpdesk-new-send:not(:disabled):hover {
    transform: translateY(-1px);
}

/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 700px) {

    .helpdesk-page {

        padding:
            22px
            15px
            35px;
    }


    .helpdesk-custodian {

        align-items: flex-start;

        gap: 10px;
    }


    .helpdesk-location {

        white-space: normal;

        text-align: right;
    }


    .helpdesk-message {

        max-width: 86%;
    }


    .helpdesk-compose-row {

        align-items: stretch;

        flex-direction: column;
    }


    .helpdesk-send {

        width: 100%;
    }


    .helpdesk-link-row {

        align-items: flex-start;

        flex-direction: column;
    }


    .helpdesk-link-select {

        width: 100%;
    }

}

</style>


<!-- =========================================================
     HELP DESK PAGE
========================================================= -->

<div class="helpdesk-page">


    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div class="helpdesk-page-head">

        <span class="helpdesk-eyebrow">
            CAMPUS SUPPORT
        </span>

        <h1>
            Campus Security Inquiry Desk
        </h1>

        <p>
            Direct messaging channel with Campus Security &amp;
            Lost Property Custodians
        </p>

    </div>


    <!-- =====================================================
         MAIN HELP DESK PANEL
    ====================================================== -->

    <div class="helpdesk-panel">


        <!-- =================================================
             CUSTODIAN HEADER
        ================================================== -->

        <div class="helpdesk-custodian">

            <div class="helpdesk-custodian-left">

                <div class="helpdesk-shield">
                    ♢
                </div>


                <div class="helpdesk-officer-info">

                    <strong>
                        Admin
                    </strong>


                    <div class="helpdesk-officer-line">

                        <span class="helpdesk-role">
                            Administrator / Custodian
                        </span>


                        <span class="helpdesk-duty">
                            Active On Campus Duty
                        </span>

                    </div>

                </div>

            </div>


            <div class="helpdesk-location">
                Room 102&nbsp; Main Tower
            </div>

        </div>


        <!-- =================================================
             CONVERSATION
        ================================================== -->

        <div class="helpdesk-conversation">


            <?php if ($selected): ?>


                <?php foreach ($threadMessages as $m): ?>

                    <?php
                    $isUser =
                        (int)$m['sender_user_id']
                        ===
                        (int)$user['id'];

                    $messageTime =
                        strtotime(
                            $m['created_at']
                        );
                    ?>


                    <div
                        class="
                            helpdesk-message
                            <?= $isUser
                                ? 'from-user'
                                : 'from-admin'
                            ?>
                        "
                    >


                        <!-- REFERENCE -->

                        <div class="helpdesk-message-ref">

                            Re:
                            <?= h(
                                $selected['subject']
                            ) ?>

                        </div>


                        <!-- META -->

                        <div class="helpdesk-meta">

                            <strong>
                                <?= $isUser
                                    ? 'You'
                                    : 'Administrator'
                                ?>
                            </strong>


                            <time>

                                <?= h(
                                    date(
                                        'M/d/Y • g:i A',
                                        $messageTime
                                    )
                                ) ?>

                            </time>

                        </div>


                        <!-- BUBBLE -->

                        <div class="helpdesk-message-bubble">

                            <?= nl2br(
                                h(
                                    $m['message']
                                )
                            ) ?>

                        </div>

                    </div>

                <?php endforeach; ?>


            <?php else: ?>


                <div class="helpdesk-empty">

                    <div>

                        <div
                            class="helpdesk-shield"
                            style="margin:0 auto;"
                        >
                            ?
                        </div>


                        <h2>
                            Need help?
                        </h2>


                        <p>
                            Start a conversation with Campus
                            Security about a lost item, found
                            item, ownership claim, report, or
                            other campus concern.
                        </p>

                    </div>

                </div>


            <?php endif; ?>

        </div>


        <!-- =================================================
             COMPOSER
        ================================================== -->

        <?php if ($selected): ?>

            <form
                method="post"
                class="helpdesk-composer"
            >

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= h(
                        csrf_token()
                    ) ?>"
                >


                <input
                    type="hidden"
                    name="action"
                    value="reply"
                >


                <input
                    type="hidden"
                    name="thread_id"
                    value="<?= (int)$selected['id'] ?>"
                >


                <!-- LINK ITEM -->

                <div class="helpdesk-link-row">

                    <span class="helpdesk-link-icon">
                        ◇
                    </span>


                    <span class="helpdesk-link-label">
                        Link specific item:
                    </span>


                    <select
                        class="helpdesk-link-select"
                        name="linked_item_display"
                    >

                        <option value="">
                            -- General Campus Inquiry --
                        </option>


                        <?php foreach (
                            $helpdeskItems
                            as $item
                        ): ?>

                            <option
                                value="<?= (int)$item['id'] ?>"
                            >
                                <?= h(
                                    $item['item_name']
                                ) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- MESSAGE -->

                <div class="helpdesk-compose-row">

                    <textarea
                        name="message"
                        rows="2"
                        maxlength="3000"
                        placeholder="Type your inquiry or question to the campus custodian..."
                        required
                    ></textarea>


                    <button
                        type="submit"
                        class="helpdesk-send"
                    >
                        Send&nbsp; ➤
                    </button>

                </div>

            </form>


            <?php if (
                $selected['status']
                ===
                'open'
            ): ?>

                <div class="helpdesk-resolve">

                    <form
                        method="post"
                    >

                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= h(
                                csrf_token()
                            ) ?>"
                        >


                        <input
                            type="hidden"
                            name="action"
                            value="close"
                        >


                        <input
                            type="hidden"
                            name="thread_id"
                            value="<?= (int)$selected['id'] ?>"
                        >


                        <button
                            type="submit"
                        >
                            Mark Conversation as Resolved
                        </button>

                    </form>

                </div>

            <?php endif; ?>


        <?php endif; ?>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    function hasMessage(textarea) {
        return !!textarea && textarea.value.trim().length > 0;
    }

    /* Existing conversation reply */
    const replyForm = document.querySelector('.helpdesk-composer');
    if (replyForm) {
        const textarea = replyForm.querySelector('textarea[name="message"]');
        const button = replyForm.querySelector('.helpdesk-send');

        if (textarea && button) {
            function updateReplyButton() {
                const active = hasMessage(textarea);

                button.classList.toggle('is-active', active);
                button.disabled = !active;
            }

            textarea.addEventListener('input', updateReplyButton);
            textarea.addEventListener('change', updateReplyButton);
            updateReplyButton();
        }
    }

    /* New HelpDesk Inquiry */
    const newForm = document.querySelector('.helpdesk-new-message form');
    if (newForm) {
        const textarea = newForm.querySelector('textarea[name="message"]');
        const button = newForm.querySelector('.helpdesk-new-send');

        if (textarea && button) {
            function updateNewMessageButton() {
                button.disabled = !hasMessage(textarea);
            }

            textarea.addEventListener('input', updateNewMessageButton);
            textarea.addEventListener('change', updateNewMessageButton);
            updateNewMessageButton();
        }
    }

});
</script>

<?php

require 'includes/footer.php';

?>
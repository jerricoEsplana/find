<?php
require 'config.php';

$claimUser = function_exists('current_user') ? current_user() : null;
$currentUserId = (int)($claimUser['id'] ?? 0);

$pageTitle = 'Search & Browse';

try { $pdo->exec("ALTER TABLE potential_matches ADD COLUMN found_turnover_start_date TEXT"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE potential_matches ADD COLUMN found_turnover_deadline TEXT"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE potential_matches ADD COLUMN found_turnover_location TEXT"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE potential_matches ADD COLUMN found_handover_at TEXT"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE reports ADD COLUMN admin_hidden INTEGER NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

$reset = isset($_GET['reset']) && $_GET['reset'] === '1';

$q = $reset ? '' : trim($_GET['q'] ?? '');
$type = $reset ? 'all' : ($_GET['type'] ?? 'all');
$category = $reset ? '' : trim($_GET['category'] ?? '');
$view = $reset ? '' : ($_GET['view'] ?? '');

if (!in_array($type, ['all', 'lost', 'found'], true)) {
    $type = 'all';
}

/* Recently Returned is an administrator-only view; students only browse active records. */
$view = '';

$where = ["r.report_status='approved'"];
$params = [];

/*
 * Active records shown in the catalog.
 * Returned items are available through the Recently Returned tab.
 */
/*
 * Public Search & Browse visibility:
 * - A student's own Lost/Found reports are shown only in My Reports.
 * - A Found Item that the current student has already claimed is shown only
 *   in My Claims.
 * - Other students may still see the item while claims are pending.
 * - Approved Potential Matches are hidden from the public catalog.
 * - Items removed by an administrator are hidden from the public catalog.
 */
$where[] = "(
    r.user_id <> ?
    AND NOT EXISTS (
        SELECT 1
        FROM claims c_private
        WHERE c_private.item_id=r.item_id
          AND c_private.claimant_user_id=?
    )
    AND r.item_status IN ('open','claim_pending')
    AND COALESCE(r.admin_hidden,0)=0
    AND NOT EXISTS (
        SELECT 1
        FROM potential_matches pm_public
        WHERE (pm_public.lost_report_id=r.id
               OR pm_public.found_report_id=r.id)
          AND pm_public.match_status='confirmed'
    )
)";
$params[] = $currentUserId;
$params[] = $currentUserId;

if ($type !== 'all') {
    $where[] = "r.report_type=?";
    $params[] = $type;
}

if ($q !== '') {
    $where[] = "(
        i.item_name LIKE ?
        OR i.description LIKE ?
        OR i.brand LIKE ?
        OR i.color LIKE ?
        OR r.campus_location LIKE ?
        OR r.building LIKE ?
        OR r.specific_area LIKE ?
    )";

    for ($i = 0; $i < 7; $i++) {
        $params[] = "%{$q}%";
    }
}

if ($category !== '') {
    $where[] = "i.category=?";
    $params[] = $category;
}

$sql = "SELECT
            r.*,
            i.item_name,
            i.category,
            i.description,
            i.photo_path,
            i.color,
            i.brand,
            i.identifying_features,
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
                SELECT pm.match_status
                FROM potential_matches pm
                WHERE pm.found_report_id=r.id
                  AND pm.match_status IN ('confirmed','rejected')
                ORDER BY CASE WHEN pm.match_status='confirmed' THEN 0 ELSE 1 END,
                         pm.match_score DESC, pm.updated_at DESC, pm.id DESC
                LIMIT 1
            ) AS found_match_status,
            (
                SELECT pm.found_turnover_start_date
                FROM potential_matches pm
                WHERE pm.found_report_id=r.id AND pm.match_status='confirmed'
                ORDER BY pm.updated_at DESC, pm.id DESC LIMIT 1
            ) AS found_turnover_start_date,
            (
                SELECT pm.found_turnover_deadline
                FROM potential_matches pm
                WHERE pm.found_report_id=r.id AND pm.match_status='confirmed'
                ORDER BY pm.updated_at DESC, pm.id DESC LIMIT 1
            ) AS found_turnover_deadline,
            (
                SELECT pm.found_turnover_location
                FROM potential_matches pm
                WHERE pm.found_report_id=r.id AND pm.match_status='confirmed'
                ORDER BY pm.updated_at DESC, pm.id DESC LIMIT 1
            ) AS found_turnover_location,
            (
                SELECT pm.found_handover_at
                FROM potential_matches pm
                WHERE pm.found_report_id=r.id AND pm.match_status='confirmed'
                ORDER BY pm.updated_at DESC, pm.id DESC LIMIT 1
            ) AS found_handover_at,
            (
                 SELECT pm.id FROM potential_matches pm
                 WHERE pm.lost_report_id=r.id AND pm.match_status='confirmed'
                 ORDER BY pm.updated_at DESC, pm.id DESC LIMIT 1
            ) AS lost_match_id,
            (
                 SELECT pm.found_report_id FROM potential_matches pm
                 WHERE pm.lost_report_id=r.id AND pm.match_status='confirmed'
                 ORDER BY pm.updated_at DESC, pm.id DESC LIMIT 1
            ) AS matched_found_report_id,
            (
                 SELECT fi.item_name
                 FROM potential_matches pm
                 JOIN reports fr ON fr.id=pm.found_report_id
                 JOIN items fi ON fi.id=fr.item_id
                 WHERE pm.lost_report_id=r.id AND pm.match_status='confirmed'
                 ORDER BY pm.updated_at DESC, pm.id DESC LIMIT 1
            ) AS matched_found_item_name,
            (
                 SELECT pm.verification_date FROM potential_matches pm
                 WHERE pm.lost_report_id=r.id AND pm.match_status='confirmed'
                 ORDER BY pm.updated_at DESC, pm.id DESC LIMIT 1
            ) AS lost_match_verification_date,
            (
                 SELECT pm.verification_time FROM potential_matches pm
                 WHERE pm.lost_report_id=r.id AND pm.match_status='confirmed'
                 ORDER BY pm.updated_at DESC, pm.id DESC LIMIT 1
            ) AS lost_match_verification_time,
            (
                 SELECT pm.verification_deadline FROM potential_matches pm
                 WHERE pm.lost_report_id=r.id AND pm.match_status='confirmed'
                 ORDER BY pm.updated_at DESC, pm.id DESC LIMIT 1
            ) AS lost_match_verification_deadline,
            (
                 SELECT pm.verification_location FROM potential_matches pm
                 WHERE pm.lost_report_id=r.id AND pm.match_status='confirmed'
                 ORDER BY pm.updated_at DESC, pm.id DESC LIMIT 1
            ) AS lost_match_verification_location,
            (
                 SELECT pm.found_handover_at FROM potential_matches pm
                 WHERE pm.lost_report_id=r.id AND pm.match_status='confirmed'
                 ORDER BY pm.updated_at DESC, pm.id DESC LIMIT 1
            ) AS lost_match_handover_at,
            (
                 SELECT pm.lost_report_id FROM potential_matches pm
                 WHERE pm.found_report_id=r.id AND pm.match_status='confirmed'
                 ORDER BY pm.updated_at DESC, pm.id DESC LIMIT 1
            ) AS found_match_lost_report_id
            ,
            (
                SELECT c.id
                FROM claims c
                WHERE c.item_id = r.item_id
                  AND c.claimant_user_id = {$currentUserId}
                ORDER BY c.created_at DESC, c.id DESC
                LIMIT 1
            ) AS my_claim_id
        FROM reports r
        JOIN items i ON i.id = r.item_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY r.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$categories = $pdo
    ->query("SELECT DISTINCT category
             FROM items
             WHERE category <> ''
             ORDER BY category")
    ->fetchAll(PDO::FETCH_COLUMN);

function browse_link(array $changes = []): string
{
    $query = [
        'q' => $_GET['q'] ?? '',
        'type' => $_GET['type'] ?? 'all',
        'category' => $_GET['category'] ?? '',
        'view' => $_GET['view'] ?? '',
    ];

    foreach ($changes as $key => $value) {
        $query[$key] = $value;
    }

    $query = array_filter(
        $query,
        static fn($value) => $value !== '' && $value !== null
    );

    return 'search.php' . ($query ? '?' . http_build_query($query) : '');
}

function browse_image(array $row): string
{
    $stored = trim((string)($row['photo_path'] ?? ''));

    if ($stored !== '') {
        return $stored;
    }

    $name = trim((string)($row['item_name'] ?? ''));

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
        $relative = "uploads/Items/{$slug}.{$extension}";

        if (is_file(__DIR__ . '/' . $relative)) {
            return $relative;
        }
    }

    return '';
}

function browse_status(array $row): array
{
    $status = (string)($row['item_status'] ?? 'open');

    switch ($status) {
        case 'claim_pending':
            return ['Claim Pending', 'pending'];

        case 'matched':
            /*
             * Found reports follow the turnover-first workflow. The turnover
             * details are private to the student who submitted the Found report.
             */
            if ($row['report_type'] === 'found') {
                $isOwner = (int)($row['user_id'] ?? 0) === (int)($claimUser['id'] ?? 0);
                if ($isOwner && !empty($row['found_handover_at'])) {
                    return ['Item Received by Administrator', 'open'];
                }
                if ($isOwner && (!empty($row['found_turnover_start_date']) || !empty($row['found_turnover_deadline']))) {
                    return ['Turnover Scheduled', 'scheduled'];
                }
                return ['Match Approved', 'match'];
            }

            return ['Potential Match', 'match'];

        case 'returned':
            return ['Returned', 'returned'];

        case 'closed':
            return ['Closed', 'closed'];

        default:
            return ['Open', 'open'];
    }
}

/**
 * Slightly friendlier phrasing used inside the details modal,
 * where there's room for a bit more context than a card badge.
 */
function browse_modal_status_label(string $text, string $class): string
{
    return match ($class) {
        'open' => 'Open & Available',
        default => $text,
    };
}

require 'includes/header.php';
?>

<style>
/* =========================================================
   SEARCH & BROWSE ONLY
   Matches the requested reference layout.
========================================================= */

:root {
    --browse-red: #9b1c2c;
    --browse-red-dark: #741321;
    --browse-red-soft: #f8e9ec;
    --browse-gold: #c7962a;
    --browse-orange: #ea580c;
    --browse-orange-soft: #fff1e8;
    --browse-text: #17191d;
    --browse-muted: #70808e;
    --browse-border: #dde3e8;
    --browse-bg: #f6f8fa;
}

.mapua-browse {
    min-height: calc(100vh - 74px);
    padding: 28px 0 65px;
    background: var(--browse-bg);
}

.mapua-browse * {
    box-sizing: border-box;
}

.mapua-browse .browse-container {
    width: min(1185px, calc(100% - 44px));
    margin: 0 auto;
}

/* ---------------------------------------------------------
   HEADER
--------------------------------------------------------- */

.mapua-browse .browse-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 20px;
    margin-bottom: 20px;
}

.mapua-browse .browse-heading h1 {
    margin: 0 0 6px;
    color: var(--browse-text);
    font-size: 25px;
    line-height: 1.12;
    font-weight: 900;
    letter-spacing: -0.02em;
}

.mapua-browse .browse-heading p {
    margin: 0;
    color: #7a8792;
    font-size: 13px;
}

.mapua-browse .browse-count {
    padding: 8px 13px;
    border: 1px solid var(--browse-border);
    border-radius: 999px;
    background: #fff;
    color: #5f6d78;
    font-size: 12px;
    white-space: nowrap;
    box-shadow: 0 2px 8px rgba(0, 0, 0, .035);
}

/* ---------------------------------------------------------
   SEARCH / CATALOG CONTROLS
--------------------------------------------------------- */

.mapua-browse .browse-control {
    padding: 15px;
    border: 1px solid var(--browse-border);
    border-radius: 13px;
    background: #fff;
    box-shadow: 0 3px 12px rgba(0, 0, 0, .03);
}

.mapua-browse .browse-search {
    height: 38px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 11px;
    border: 1px solid #dce3e9;
    border-radius: 8px;
    background: #f8fafb;
}

.mapua-browse .browse-search-icon {
    color: #8d9aa6;
    font-size: 16px;
}

.mapua-browse .browse-search input {
    width: 100%;
    border: 0;
    outline: 0;
    background: transparent;
    color: var(--browse-text);
    font-size: 13px;
}

.mapua-browse .browse-search input::placeholder {
    color: #9aa6b0;
}

.mapua-browse .browse-tab-row {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: 9px;
}

.mapua-browse .browse-main-tab {
    min-height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 8px 14px;
    border-radius: 7px;
    color: #66737e;
    text-decoration: none;
    font-size: 12px;
    font-weight: 800;
    white-space: nowrap;
}

.mapua-browse .browse-main-tab:hover {
    color: var(--browse-red);
    background: var(--browse-red-soft);
}

.mapua-browse .browse-main-tab.active {
    background: var(--browse-red);
    color: #fff;
}

.mapua-browse .browse-category-row {
    display: flex;
    gap: 6px;
    margin-top: 9px;
    overflow-x: auto;
    padding-bottom: 1px;
    scrollbar-width: thin;
}

.mapua-browse .browse-category-row::-webkit-scrollbar {
    height: 5px;
}

.mapua-browse .browse-category {
    min-height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    padding: 7px 13px;
    border-radius: 7px;
    background: #eef2f5;
    color: #5f6c78;
    text-decoration: none;
    font-size: 11px;
    font-weight: 800;
}

.mapua-browse .browse-category:hover {
    color: var(--browse-red);
    background: var(--browse-red-soft);
}

.mapua-browse .browse-category.active {
    color: #fff;
    background: var(--browse-red);
}

.mapua-browse .browse-category-select-row {
    display: flex;
    align-items: center;
    gap: 9px;
    margin-top: 10px;
}

.mapua-browse .browse-category-select-row label {
    color: #596671;
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
}

.mapua-browse .browse-category-select-row select {
    min-width: 230px;
    height: 34px;
    padding: 0 34px 0 11px;
    border: 1px solid #dce3e9;
    border-radius: 8px;
    background: #f8fafb;
    color: #3f4b56;
    font-size: 11px;
    font-weight: 700;
    outline: none;
    cursor: pointer;
}

.mapua-browse .browse-category-select-row select:focus {
    border-color: var(--browse-red);
    box-shadow: 0 0 0 3px rgba(155, 28, 44, .08);
}

/* ---------------------------------------------------------
   RESULTS AREA
--------------------------------------------------------- */

.mapua-browse .browse-results {
    margin-top: 22px;
}

.mapua-browse .browse-results-head {
    display: flex;
    justify-content: space-between;
    align-items: end;
    margin-bottom: 12px;
}

.mapua-browse .browse-eyebrow {
    display: block;
    margin-bottom: 5px;
    color: var(--browse-red);
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: .09em;
    font-weight: 900;
}

.mapua-browse .browse-results-head h2 {
    margin: 0;
    color: var(--browse-text);
    font-size: 20px;
    line-height: 1.1;
    font-weight: 900;
}

.mapua-browse .browse-result-count {
    color: #82909b;
    font-size: 11px;
}

/* ---------------------------------------------------------
   3 COLUMN CARDS
--------------------------------------------------------- */

.mapua-browse .browse-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
}

.mapua-browse .browse-card {
    min-width: 0;
    overflow: hidden;
    border: 1px solid var(--browse-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 3px 12px rgba(0, 0, 0, .035);
    transition: transform .15s ease, box-shadow .15s ease;
}

.mapua-browse .browse-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 9px 22px rgba(0, 0, 0, .075);
}

.mapua-browse .browse-photo {
    position: relative;
    height: 177px;
    overflow: hidden;
    background: #edf0f3;
}

.mapua-browse .browse-photo img {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: cover;
}

.mapua-browse .browse-placeholder {
    width: 100%;
    height: 100%;
    display: grid;
    place-items: center;
    color: #a8b0b8;
    font-size: 48px;
    font-weight: 900;
}

.mapua-browse .browse-badges {
    position: absolute;
    left: 9px;
    top: 9px;
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
}

.mapua-browse .browse-badge {
    padding: 5px 9px;
    border-radius: 5px;
    background: #fff;
    box-shadow: 0 2px 5px rgba(0, 0, 0, .08);
    font-size: 10px;
    line-height: 1;
    font-weight: 900;
}

/* Report-type badges (FOUND / LOST) are solid pills */
.mapua-browse .browse-badge.found {
    color: #fff;
    background: var(--browse-red);
    box-shadow: none;
}

.mapua-browse .browse-badge.lost {
    color: #fff;
    background: var(--browse-orange);
    box-shadow: none;
}

/* Status badges are soft outlined pills */
.mapua-browse .browse-badge.match {
    color: #916900;
    border: 1px solid #e4c55a;
    background: #fff9df;
}

.mapua-browse .browse-badge.pending {
    color: #2167c6;
    border: 1px solid #a5caff;
    background: #edf5ff;
}

.mapua-browse .browse-badge.open {
    color: #167956;
    border: 1px solid #a2ddc3;
    background: #effbf5;
}

.mapua-browse .browse-badge.scheduled {
    color: var(--browse-red-dark);
    border: 1px solid #c7c3fa;
    background: var(--browse-red-soft);
}

.mapua-browse .browse-badge.returned {
    color: #6c3daf;
    border: 1px solid #d7b6f0;
    background: #f5eaff;
}

.mapua-browse .browse-badge.closed {
    color: #5a6672;
    border: 1px solid #d3d9df;
    background: #eef1f4;
}

.mapua-browse .browse-body {
    padding: 15px 16px 16px;
}

.mapua-browse .browse-topline {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 8px;
}

.mapua-browse .browse-category {
    color: var(--browse-red);
    font-size: 10px;
    text-transform: uppercase;
    font-weight: 900;
}

.mapua-browse .browse-id {
    color: #a1aab2;
    font-size: 9px;
    white-space: nowrap;
}

.mapua-browse .browse-body h3 {
    margin: 7px 0 6px;
    color: var(--browse-text);
    font-size: 16px;
    line-height: 1.25;
    font-weight: 800;
}

.mapua-browse .browse-description {
    min-height: 33px;
    margin: 0;
    color: #6e7a84;
    font-size: 12px;
    line-height: 1.5;
}

.mapua-browse .browse-location {
    margin: 12px 0 0;
    padding-top: 10px;
    border-top: 1px solid #eceff2;
    color: #67747f;
    font-size: 11px;
    line-height: 1.5;
}

.mapua-browse .browse-date {
    margin-top: 6px;
    color: #929ca5;
    font-size: 10px;
}

.mapua-browse .browse-card-buttons {
    display: flex;
    gap: 8px;
    margin-top: 12px;
}

.mapua-browse .browse-card-action {
    flex: 1 1 0;
    min-width: 0;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    border: 0;
    border-radius: 8px;
    background: #eef1f4;
    color: #4b5763;
    text-decoration: none;
    font-size: 11px;
    font-weight: 800;
    cursor: pointer;
}

.mapua-browse .browse-card-action:hover {
    background: #e3e8ec;
}

.mapua-browse .browse-card-action.claim {
    background: var(--browse-red);
    color: #fff;
}

.mapua-browse .browse-card-action.claim:hover {
    background: var(--browse-red-dark);
}

/* ---------------------------------------------------------
   EMPTY
--------------------------------------------------------- */

.mapua-browse .browse-empty {
    grid-column: 1 / -1;
    padding: 45px 20px;
    border: 1px dashed #cfd7de;
    border-radius: 12px;
    background: #fff;
    text-align: center;
}

.mapua-browse .browse-empty h3 {
    margin: 0 0 6px;
    color: var(--browse-text);
    font-size: 16px;
}

.mapua-browse .browse-empty p {
    margin: 0;
    color: var(--browse-muted);
    font-size: 12px;
}

/* ---------------------------------------------------------
   DETAILS MODAL
--------------------------------------------------------- */

.browse-modal {
    position: fixed;
    inset: 0;
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(20, 24, 29, .68);
    backdrop-filter: blur(4px);
}

.browse-modal.open {
    display: flex;
}

.browse-dialog {
    width: min(675px, 100%);
    max-height: calc(100vh - 40px);
    overflow: auto;
    border-radius: 17px;
    background: #fff;
    box-shadow: 0 24px 70px rgba(0, 0, 0, .25);
}

.browse-dialog-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 21px;
    border-bottom: 1px solid #e7eaed;
}

.browse-dialog-tags {
    display: flex;
    align-items: center;
    gap: 8px;
}

.browse-dialog-type {
    padding: 5px 9px;
    border-radius: 999px;
    background: var(--browse-red-soft);
    color: var(--browse-red);
    font-size: 8px;
    font-weight: 900;
}

.browse-dialog-id {
    color: #9ba4ad;
    font-size: 9px;
}

.browse-dialog-close {
    width: 28px;
    height: 28px;
    border: 0;
    background: transparent;
    color: #99a3ac;
    font-size: 23px;
    cursor: pointer;
}

.browse-dialog-main {
    display: grid;
    grid-template-columns: 190px 1fr;
    gap: 21px;
    padding: 22px;
}

.browse-dialog-image {
    width: 190px;
    height: 190px;
    overflow: hidden;
    border-radius: 11px;
    background: #edf0f3;
}

.browse-dialog-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.browse-dialog-status {
    display: none !important;
    display: inline-flex;
    padding: 5px 8px;
    border-radius: 999px;
    color: #2167c6;
    background: #edf5ff;
    border: 1px solid #a5caff;
    font-size: 8px;
    font-weight: 900;
}

.browse-dialog-category {
    margin-left: 7px;
    color: #7e8992;
    font-size: 9px;
}

.browse-dialog-main h2 {
    margin: 10px 0 8px;
    color: var(--browse-text);
    font-size: 21px;
    line-height: 1.15;
}

.browse-dialog-main p {
    margin: 0;
    color: #69747e;
    font-size: 10px;
    line-height: 1.5;
}

.browse-dialog-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 14px;
    margin-top: 15px;
}

.browse-dialog-meta div {
    display: flex;
    align-items: center;
    gap: 5px;
}

.browse-dialog-meta strong {
    font-size: 10px;
}

.browse-dialog-meta span {
    color: #65717c;
    font-size: 9px;
}

.browse-dialog-info,
.browse-dialog-feature {
    margin: 0 22px 14px;
    padding: 13px 14px;
    border-radius: 11px;
}

.browse-dialog-info {
    border: 1px solid #e2e7ec;
    background: #f8fafc;
}

.browse-dialog-feature {
    border: 1px solid #ead0d5;
    background: #fff7f8;
}

.browse-dialog-info strong,
.browse-dialog-feature strong {
    display: block;
    margin-bottom: 3px;
    color: #38404a;
    font-size: 9px;
}

.browse-dialog-info p,
.browse-dialog-feature p {
    margin: 0;
    color: #73808b;
    font-size: 8px;
}

.browse-dialog-approved {
    display: block;
    margin-top: 4px;
    color: #6b8b7a;
    font-size: 8px;
    font-weight: 600;
}

.browse-dialog-turnover{
    border-color:#e4c55a;
    background:#fffaf0;
}
.browse-dialog-turnover.received{
    border-color:#bcefdc;
    background:#effbf5;
}
.browse-dialog-turnover strong{font-size:14px;color:#26323d}
.browse-dialog-turnover p{margin:7px 0 0;color:#667684;line-height:1.55}
.browse-dialog-turnover:not(.received){box-shadow:inset 4px 0 0 #e4c55a}
.browse-dialog-turnover.received{box-shadow:inset 4px 0 0 #16a36f}

/* Match status shown only to the student connected to the Lost Report. */
.browse-dialog-my-match{
    border:1px solid #ead0d5;
    background:#fff7f8;
    box-shadow:inset 4px 0 0 #9b1c2c;
}
.browse-dialog-my-match.waiting{
    border-color:#e4c55a;
    background:#fffaf0;
    box-shadow:inset 4px 0 0 #c7962a;
}
.browse-dialog-my-match.received{
    border-color:#bcefdc;
    background:#effbf5;
    box-shadow:inset 4px 0 0 #16a36f;
}
.browse-dialog-my-match.scheduled{
    border-color:#ead0d5;
    background:#fff7f8;
    box-shadow:inset 4px 0 0 #9b1c2c;
}
.browse-dialog-my-match strong{font-size:14px;color:#26323d}
.browse-dialog-my-match p{margin:7px 0 0;color:#667684;line-height:1.55}
.browse-match-details{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:7px;
    margin-top:9px;
}
.browse-match-details span{
    display:block;
    padding:8px 9px;
    border:1px solid #e3e8ed;
    border-radius:8px;
    background:#fff;
    color:#53606c;
    font-size:8px;
    line-height:1.4;
}
.browse-match-details span:empty{display:none}

.browse-turnover-details{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:7px;
    margin-top:9px;
}
.browse-turnover-details span{
    display:block;
    padding:8px 9px;
    border:1px solid #e3e8ed;
    border-radius:8px;
    background:#fff;
    color:#53606c;
    font-size:8px;
    line-height:1.4;
}
.browse-turnover-details span:empty{display:none}

.browse-dialog-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 9px;
    padding: 14px 22px 18px;
    border-top: 1px solid #e7eaed;
}

.browse-dialog-actions-right {
    display: flex;
    align-items: center;
    gap: 14px;
}

.browse-dialog-actions button,
.browse-dialog-actions a {
    min-height: 35px;
    padding: 0 15px;
    border: 0;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    text-decoration: none;
    font-size: 9px;
    font-weight: 900;
    cursor: pointer;
    white-space: nowrap;
}

.browse-dialog-actions .ask {
    background: #eef2f5;
    color: #47525c;
}

.browse-dialog-actions .close {
    background: transparent;
    color: #596671;
    padding: 0;
}

.browse-dialog-actions .claim {
    background: var(--browse-red);
    color: #fff;
}

.browse-dialog-claim-note {
    color: #8994a0;
    font-size: 8px;
    font-weight: 700;
    white-space: nowrap;
}

@media (max-width: 900px) {
    .mapua-browse .browse-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 620px) {
    .mapua-browse .browse-container {
        width: calc(100% - 24px);
    }

    .mapua-browse .browse-heading {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }

    .mapua-browse .browse-grid {
        grid-template-columns: 1fr;
    }

    .mapua-browse .browse-category-select-row {
        align-items: stretch;
        flex-direction: column;
    }

    .mapua-browse .browse-category-select-row select {
        width: 100%;
        min-width: 0;
    }

    .browse-dialog-main {
        grid-template-columns: 1fr;
    }

    .browse-dialog-image {
        width: 100%;
        height: 240px;
    }

    .browse-dialog-actions {
        flex-wrap: wrap;
        row-gap: 10px;
    }

    .browse-dialog-actions-right {
        width: 100%;
        justify-content: flex-end;
    }
}
.browse-icon {
    display: inline-block;
    vertical-align: -2px;
    margin-right: 4px;
    flex-shrink: 0;
}
</style>

<div class="mapua-browse">

    <div class="browse-container">

        <div class="browse-heading">
            <div>
                <h1>Browse Campus Lost &amp; Found Catalog</h1>
                <p>Search verified active items logged by campus security and student reporters</p>
            </div>

            <span class="browse-count">
                Showing <?= count($items) ?> active records
            </span>
        </div>


        <div class="browse-control">

            <form id="browse-search-form" method="get" action="search.php">

                <div class="browse-search">
                    <span class="browse-search-icon">⌕</span>

                    <input
                        type="search"
                        name="q"
                        value="<?= h($q) ?>"
                        placeholder="Search by keywords, location (e.g. Library), item brand, color..."
                        autocomplete="off"
                    >

                    <?php if ($type !== 'all' && $view !== 'returned'): ?>
                        <input type="hidden" name="type" value="<?= h($type) ?>">
                    <?php endif; ?>

                </div>

            </form>


            <div class="browse-tab-row">

                <a
                    class="browse-main-tab <?= $view !== 'returned' && $type === 'all' ? 'active' : '' ?>"
                    href="<?= h(browse_link([
                        'q' => '',
                        'type' => 'all',
                        'category' => '',
                        'view' => ''
                    ])) ?>"
                >
                    All Items
                </a>

                <a
                    class="browse-main-tab <?= $view !== 'returned' && $type === 'found' ? 'active' : '' ?>"
                    href="<?= h(browse_link([
                        'type' => 'found',
                        'view' => ''
                    ])) ?>"
                >
                    Found Items
                </a>

                <a
                    class="browse-main-tab <?= $view !== 'returned' && $type === 'lost' ? 'active' : '' ?>"
                    href="<?= h(browse_link([
                        'type' => 'lost',
                        'view' => ''
                    ])) ?>"
                >
                    Lost Reports
                </a>

            </div>


            <?php if ($view !== 'returned' && $type === 'all'): ?>
                <div class="browse-category-select-row">
                    <label for="browse-category-select">Category</label>
                    <select
                        id="browse-category-select"
                        name="category"
                        form="browse-search-form"
                        onchange="document.getElementById('browse-search-form').submit()"
                    >
                        <option value="">All categories</option>
                        <?php
                        $preferredCategories = [
                            'Books & Notes',
                            'Clothing & Accessories',
                            'Electronics',
                            'Glasses & Sunglasses',
                            'Headphones',
                            'ID & Cards',
                            'Jewelry',
                            'Keys',
                            'Other',
                            'Sports Equipment',
                            'Wallets & Bags',
                            'Water Bottles'
                        ];

                        foreach ($preferredCategories as $c):
                        ?>
                            <option value="<?= h($c) ?>" <?= $category === $c ? 'selected' : '' ?>>
                                <?= h($c) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

        </div>


        <div class="browse-results">

            <div class="browse-results-head">

                <div>
                    <span class="browse-eyebrow">
                        Verified Campus Records
                    </span>

                    <h2>All Active Items</h2>
                </div>

                <span class="browse-result-count">
                    <?= count($items) ?> result(s) found
                </span>

            </div>


            <div class="browse-grid">

                <?php foreach ($items as $r): ?>

                    <?php
                    $image = browse_image($r);
                    [$statusText, $statusClass] = browse_status($r);

                    $typeLabel = $r['report_type'] === 'lost'
                        ? 'REPORTED LOST'
                        : 'FOUND ITEM';

                    $itemCode = $r['item_code']
                        ?? ('ITM-' . str_pad(
                            (string)$r['item_id'],
                            3,
                            '0',
                            STR_PAD_LEFT
                        ));

                    $modalData = [
                        'type' => $typeLabel,
                        'id' => $itemCode,
                        'image' => $image,
                        'status' => browse_modal_status_label($statusText, $statusClass),
                        'statusClass' => $statusClass,
                        'category' => $r['category'],
                        'name' => $r['item_name'],
                        'description' => $r['description'] ?: 'No description provided.',
                        'features' => $r['identifying_features'] ?: '',
                        'location' => trim(
                            (string)$r['campus_location']
                            . (!empty($r['building']) ? ', ' . $r['building'] : '')
                            . (!empty($r['specific_area']) ? ', ' . $r['specific_area'] : '')
                        ),
                        'date' => $r['event_date'],
                        'time' => $r['event_time'] ?? '',
                        'storageLocation' => $r['storage_location'] ?? '',
                        'foundMatchStatus' => $r['found_match_status'] ?? '',
                        'foundTurnoverStartDate' => $r['found_turnover_start_date'] ?? '',
                        'foundTurnoverDeadline' => $r['found_turnover_deadline'] ?? '',
                        'foundTurnoverLocation' => $r['found_turnover_location'] ?? '',
                        'foundHandoverAt' => $r['found_handover_at'] ?? '',
                        'lostMatchId' => !empty($r['lost_match_id']) ? (int)$r['lost_match_id'] : 0,
                        'matchedFoundReportId' => !empty($r['matched_found_report_id']) ? (int)$r['matched_found_report_id'] : 0,
                        'matchedFoundItemName' => $r['matched_found_item_name'] ?? '',
                        'lostMatchVerificationDate' => $r['lost_match_verification_date'] ?? '',
                        'lostMatchVerificationTime' => $r['lost_match_verification_time'] ?? '',
                        'lostMatchVerificationDeadline' => $r['lost_match_verification_deadline'] ?? '',
                        'lostMatchVerificationLocation' => $r['lost_match_verification_location'] ?? '',
                        'lostMatchHandoverAt' => $r['lost_match_handover_at'] ?? '',
                        'foundMatchLostReportId' => !empty($r['found_match_lost_report_id']) ? (int)$r['found_match_lost_report_id'] : 0,
                        'approvedBy' => $r['approved_by_name'] ?? '',
                        'approvedAt' => !empty($r['approved_at'])
                            ? date('n/j/Y', strtotime($r['approved_at']))
                            : '',
                        'reportId' => (int)$r['id'],
                        'reportType' => $r['report_type'],
                        'reportOwnerId' => (int)($r['user_id'] ?? 0),
                        'myClaimId' => !empty($r['my_claim_id']) ? (int)$r['my_claim_id'] : 0,
                        'currentUserId' => (int)($claimUser['id'] ?? 0),
                        'itemId' => (int)$r['item_id'],
                        'claimable' => $r['report_type'] === 'found'
                            && (int)($r['user_id'] ?? 0) !== (int)($claimUser['id'] ?? 0)
                            && empty($r['my_claim_id'])
                            && in_array((string)$r['item_status'], ['open', 'claim_pending'], true)
                            && empty($r['found_match_status'])
                    ];
                    ?>

                    <article class="browse-card">

                        <div class="browse-photo">

                            <?php if ($image !== ''): ?>

                                <img
                                    src="<?= h($image) ?>"
                                    alt="<?= h($r['item_name']) ?>"
                                    loading="lazy"
                                >

                            <?php else: ?>

                                <div class="browse-placeholder">
                                    <?= h(
                                        strtoupper(
                                            substr(
                                                (string)$r['item_name'],
                                                0,
                                                1
                                            )
                                        )
                                    ) ?>
                                </div>

                            <?php endif; ?>


                            <div class="browse-badges">

                                <span class="browse-badge <?= $r['report_type'] === 'lost' ? 'lost' : 'found' ?>">
                                    <?= h($typeLabel) ?>
                                </span>


                            </div>

                        </div>


                        <div class="browse-body">

                            <div class="browse-topline">

                                <span class="browse-category">
                                    <?= h($r['category']) ?>
                                </span>

                                <span class="browse-id">
                                    <?= h($itemCode) ?>
                                </span>

                            </div>


                            <h3>
                                <?= h($r['item_name']) ?>
                            </h3>


                            <p class="browse-description">
                                <?= h(
                                    $r['description']
                                    ?: 'No description provided.'
                                ) ?>
                            </p>


                            <p class="browse-location">
                                <svg class="browse-icon" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-7.05-7-11.5A7 7 0 0 1 19 9.5C19 13.95 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.2"/></svg>
                                <?= h($r['campus_location']) ?>

                                <?php if (!empty($r['building'])): ?>
                                    , <?= h($r['building']) ?>
                                <?php endif; ?>

                                <?php if (!empty($r['specific_area'])): ?>
                                    , <?= h($r['specific_area']) ?>
                                <?php endif; ?>
                            </p>


                            <div class="browse-date">
                                <?= h($r['event_date']) ?>
                            </div>


                            <div class="browse-card-buttons">

                                <button
                                    type="button"
                                    class="browse-card-action"
                                    data-item="<?= h(
                                        json_encode(
                                            $modalData,
                                            JSON_HEX_TAG
                                            | JSON_HEX_APOS
                                            | JSON_HEX_AMP
                                            | JSON_HEX_QUOT
                                        )
                                    ) ?>"
                                >
                                    Details
                                </button>

                                <?php if ($r['report_type'] === 'found' && !empty($modalData['claimable'])): ?>

                                    <button
                                        type="button"
                                        class="browse-card-action claim browse-claim-trigger"
                                        data-claim-item="<?= h(json_encode(
                                            $modalData,
                                            JSON_HEX_TAG
                                            | JSON_HEX_APOS
                                            | JSON_HEX_AMP
                                            | JSON_HEX_QUOT
                                        )) ?>"
                                    >
                                        <svg class="browse-icon" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>
                                        Claim
                                    </button>

                                <?php endif; ?>

                            </div>

                        </div>

                    </article>

                <?php endforeach; ?>


                <?php if (!$items): ?>

                    <div class="browse-empty">

                        <h3>
                            No items found
                        </h3>

                        <p>
                            Try another keyword or category.
                        </p>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>

</div>


<!-- =====================================================
     ITEM DETAILS MODAL
===================================================== -->

<div
    id="browse-item-modal"
    class="browse-modal"
    aria-hidden="true"
>

    <div
        class="browse-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="browse-modal-title"
    >

        <div class="browse-dialog-head">

            <div class="browse-dialog-tags">

                <span
                    id="browse-modal-type"
                    class="browse-dialog-type"
                >
                    FOUND ITEM
                </span>

                <span
                    id="browse-modal-id"
                    class="browse-dialog-id"
                >
                    ID: ITM-000
                </span>

            </div>


            <button
                id="browse-modal-close"
                type="button"
                class="browse-dialog-close"
                aria-label="Close"
            >
                ×
            </button>

        </div>


        <div class="browse-dialog-main">

            <div class="browse-dialog-image">

                <img
                    id="browse-modal-image"
                    src=""
                    alt=""
                    style="display:none"
                >

                <div
                    id="browse-modal-placeholder"
                    class="browse-placeholder"
                >
                    I
                </div>

            </div>


            <div>

                <span
                    id="browse-modal-status"
                    class="browse-dialog-status"
                >
                    Open
                </span>

                <span
                    id="browse-modal-category"
                    class="browse-dialog-category"
                >
                    Electronics
                </span>


                <h2 id="browse-modal-title">
                    Item Name
                </h2>


                <p id="browse-modal-description">
                    Item description.
                </p>


                <div class="browse-dialog-meta">

                    <div>
                        <strong><svg class="browse-icon" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-7.05-7-11.5A7 7 0 0 1 19 9.5C19 13.95 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.2"/></svg></strong>
                        <span id="browse-modal-location">
                            Campus
                        </span>
                    </div>

                    <div>
                        <strong><svg class="browse-icon" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><line x1="16" y1="3" x2="16" y2="7"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="3" y1="10" x2="21" y2="10"/></svg></strong>
                        <span id="browse-modal-date">
                            Date
                        </span>
                    </div>

                </div>

            </div>

        </div>


        <div
            id="browse-modal-storage-box"
            class="browse-dialog-info"
        >

            <strong>
                <span class="browse-icon" style="color:var(--browse-red);"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg></span>
                Physical Custody Storage Location
            </strong>

            <p id="browse-modal-storage">
                Items requiring verification are secured by the
                campus lost &amp; found administrator before release.
            </p>

            <span
                id="browse-modal-approved"
                class="browse-dialog-approved"
                style="display:none"
            ></span>

        </div>


        <div
            id="browse-modal-turnover-box"
            class="browse-dialog-info browse-dialog-turnover"
            style="display:none"
        >
            <strong>
                <span class="browse-icon" style="color:var(--browse-red);">⌂</span>
                <span id="browse-modal-turnover-title">Found Item Turnover</span>
            </strong>
            <p id="browse-modal-turnover-message"></p>
            <div id="browse-modal-turnover-details" class="browse-turnover-details">
                <span id="browse-modal-turnover-start"></span>
                <span id="browse-modal-turnover-deadline"></span>
                <span id="browse-modal-turnover-location"></span>
                <span id="browse-modal-turnover-received"></span>
            </div>
        </div>

        <div
            id="browse-modal-match-box"
            class="browse-dialog-info browse-dialog-my-match"
            style="display:none"
        >
            <strong>
                <span class="browse-icon" style="color:var(--browse-red);">✓</span>
                <span id="browse-modal-match-title">Lost Item Match Status</span>
            </strong>
            <p id="browse-modal-match-message"></p>
            <div class="browse-match-details">
                <span id="browse-modal-match-found-item"></span>
                <span id="browse-modal-match-schedule-date"></span>
                <span id="browse-modal-match-schedule-time"></span>
                <span id="browse-modal-match-schedule-location"></span>
                <span id="browse-modal-match-schedule-deadline"></span>
            </div>
        </div>


        <div class="browse-dialog-feature">

            <strong>
                <span class="browse-icon" style="color:var(--browse-red);"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16"/><circle cx="12" cy="8" r="0.9" fill="currentColor" stroke="none"/></svg></span>
                Distinctive Features Logged in System
            </strong>

            <p id="browse-modal-feature">
                Refer to the item description and identifying
                details during ownership verification.
            </p>

        </div>


        <div class="browse-dialog-actions">

            <button
                id="browse-modal-ask"
                type="button"
                class="ask"
                onclick="window.location.href='messages.php'"
            >
                <svg class="browse-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                Ask Administrator About This Item
            </button>

            <div class="browse-dialog-actions-right">

                <button
                    id="browse-modal-close-text"
                    type="button"
                    class="close"
                >
                    Close
                </button>


                <a
                    id="browse-modal-claim"
                    href="#"
                    class="claim"
                >
                    <svg class="browse-icon" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>
                    Is it yours? Claim Item
                </a>

                <span
                    id="browse-modal-claim-note"
                    class="browse-dialog-claim-note"
                    style="display:none"
                ></span>

            </div>

        </div>

    </div>

</div>


<script>
(function () {
    const modal = document.getElementById('browse-item-modal');
    const modalClose = document.getElementById('browse-modal-close');
    const modalCloseText = document.getElementById('browse-modal-close-text');

    const modalType = document.getElementById('browse-modal-type');
    const modalId = document.getElementById('browse-modal-id');
    const modalImage = document.getElementById('browse-modal-image');
    const modalPlaceholder = document.getElementById('browse-modal-placeholder');
    const modalStatus = document.getElementById('browse-modal-status');
    const modalCategory = document.getElementById('browse-modal-category');
    const modalTitle = document.getElementById('browse-modal-title');
    const modalDescription = document.getElementById('browse-modal-description');
    const modalLocation = document.getElementById('browse-modal-location');
    const modalDate = document.getElementById('browse-modal-date');
    const modalFeature = document.getElementById('browse-modal-feature');
    const modalStorageBox = document.getElementById('browse-modal-storage-box');
    const modalStorage = document.getElementById('browse-modal-storage');
    const modalApproved = document.getElementById('browse-modal-approved');
    const modalClaim = document.getElementById('browse-modal-claim');
    const modalClaimNote = document.getElementById('browse-modal-claim-note');
    const modalTurnoverBox = document.getElementById('browse-modal-turnover-box');
    const modalTurnoverTitle = document.getElementById('browse-modal-turnover-title');
    const modalTurnoverMessage = document.getElementById('browse-modal-turnover-message');
    const modalTurnoverStart = document.getElementById('browse-modal-turnover-start');
    const modalTurnoverDeadline = document.getElementById('browse-modal-turnover-deadline');
    const modalTurnoverLocation = document.getElementById('browse-modal-turnover-location');
    const modalTurnoverReceived = document.getElementById('browse-modal-turnover-received');
    const modalMatchBox = document.getElementById('browse-modal-match-box');
    const modalMatchTitle = document.getElementById('browse-modal-match-title');
    const modalMatchMessage = document.getElementById('browse-modal-match-message');
    const modalMatchFoundItem = document.getElementById('browse-modal-match-found-item');
    const modalMatchScheduleDate = document.getElementById('browse-modal-match-schedule-date');
    const modalMatchScheduleTime = document.getElementById('browse-modal-match-schedule-time');
    const modalMatchScheduleLocation = document.getElementById('browse-modal-match-schedule-location');
    const modalMatchScheduleDeadline = document.getElementById('browse-modal-match-schedule-deadline');

    const CLAIM_NOTES = {
        pending: 'Claim under review',
        scheduled: 'Already claimed — pickup scheduled',
        returned: 'Already returned to owner',
        closed: 'Case closed'
    };

    const STATUS_STYLES = {
        open: { background: '#effbf5', borderColor: '#a2ddc3', color: '#167956' },
        pending: { background: '#fff5f6', borderColor: '#e2b8c0', color: '#8a4a55' },
        match: { background: '#fff9df', borderColor: '#e4c55a', color: '#916900' },
        scheduled: { background: '#f8e9ec', borderColor: '#e6c4ca', color: '#741321' },
        returned: { background: '#effbf5', borderColor: '#bcefdc', color: '#08794f' },
        closed: { background: '#eef1f4', borderColor: '#d3d9df', color: '#5a6672' }
    };

    function openItemModal(data) {
        /* Personal records should open in the dedicated workflow page. */
        const isMyReport = Number(data.reportOwnerId || 0) === Number(data.currentUserId || 0);
        const isFoundReporter = data.reportType === 'found' && isMyReport;
        const isMyClaim = data.reportType === 'found' && Number(data.myClaimId || 0) > 0;
        const isMyMatchedFoundItem = data.reportType === 'found'
            && Number(data.foundMatchLostReportId || 0) > 0;

        if (isMyReport) {
            window.location.href = 'my_reports.php?open_report=' + encodeURIComponent(data.reportId);
            return;
        }

        if (isMyMatchedFoundItem) {
            // This Found Item is already matched to the current student's Lost Report.
            // Open the student's exact Lost Report instead of showing a claim action.
            window.location.href = 'my_reports.php?open_report=' + encodeURIComponent(data.foundMatchLostReportId);
            return;
        }

        if (isMyClaim) {
            window.location.href = 'my_claims.php?open_claim=' + encodeURIComponent(data.myClaimId);
            return;
        }

        modalType.textContent = data.type || 'ITEM';
        modalId.textContent = 'ID: ' + (data.id || 'N/A');

        modalTitle.textContent = data.name || 'Item';
        modalCategory.textContent = data.category || '';
        modalDescription.textContent =
            data.description || 'No description provided.';

        modalLocation.textContent =
            data.location || 'Campus location not specified.';

        modalDate.textContent =
            (data.date || 'Date not specified')
            + (data.time ? ' at ' + data.time : '');

        modalStatus.textContent = data.status || 'Open';

        const style = STATUS_STYLES[data.statusClass] || STATUS_STYLES.open;
        modalStatus.style.background = style.background;
        modalStatus.style.borderColor = style.borderColor;
        modalStatus.style.color = style.color;

        if (modalMatchBox) {
            modalMatchBox.style.display = 'none';
            modalMatchBox.className = 'browse-dialog-info browse-dialog-my-match';
            if (modalMatchTitle) modalMatchTitle.textContent = 'Lost Item Match Status';
            if (modalMatchMessage) modalMatchMessage.textContent = '';
            if (modalMatchFoundItem) modalMatchFoundItem.textContent = '';
            if (modalMatchScheduleDate) modalMatchScheduleDate.textContent = '';
            if (modalMatchScheduleTime) modalMatchScheduleTime.textContent = '';
            if (modalMatchScheduleLocation) modalMatchScheduleLocation.textContent = '';
            if (modalMatchScheduleDeadline) modalMatchScheduleDeadline.textContent = '';

            const isMyLostReport = data.reportType === 'lost'
                && Number(data.reportOwnerId || 0) === Number(data.currentUserId || 0);

            if (isMyLostReport && Number(data.lostMatchId || 0) > 0) {
                modalMatchBox.style.display = '';
                modalMatchFoundItem.textContent = data.matchedFoundItemName
                    ? 'Matched found item: ' + data.matchedFoundItemName
                    : '';

                if (data.lostMatchVerificationDate) {
                    modalMatchBox.classList.add('scheduled');
                    modalMatchTitle.textContent = 'Release / Verification Schedule';
                    modalMatchMessage.textContent =
                        'The found item has been received by the administrator. Please attend the scheduled in-person verification to claim the item. Bring your University/Mapúa ID and ownership evidence.';
                    modalMatchScheduleDate.textContent =
                        'Release date: ' + data.lostMatchVerificationDate;
                    modalMatchScheduleTime.textContent =
                        data.lostMatchVerificationTime ? 'Time: ' + data.lostMatchVerificationTime : '';
                    modalMatchScheduleLocation.textContent =
                        data.lostMatchVerificationLocation ? 'Location: ' + data.lostMatchVerificationLocation : '';
                    modalMatchScheduleDeadline.textContent =
                        data.lostMatchVerificationDeadline ? 'Deadline: ' + data.lostMatchVerificationDeadline : '';
                } else if (data.lostMatchHandoverAt) {
                    modalMatchBox.classList.add('received');
                    modalMatchTitle.textContent = 'Item Received by Administrator';
                    modalMatchMessage.textContent =
                        'The student who found the item has already turned it over to the administrator. The administrator will provide your release / verification schedule next.';
                } else {
                    modalMatchBox.classList.add('waiting');
                    modalMatchTitle.textContent = 'Potential Match — Waiting for Item Turnover';
                    modalMatchMessage.textContent =
                        'We are waiting for the item to be received by the administrator from the student who found it. You will receive a release / verification schedule only after the item is physically received.';
                }
            }
        }

        if (modalTurnoverBox) {
            modalTurnoverBox.style.display = 'none';
            modalTurnoverBox.className = 'browse-dialog-info browse-dialog-turnover';
            modalTurnoverStart.textContent = '';
            modalTurnoverDeadline.textContent = '';
            modalTurnoverLocation.textContent = '';
            modalTurnoverReceived.textContent = '';

            const isFoundReporter = data.reportType === 'found'
                && Number(data.reportOwnerId || 0) === Number(data.currentUserId || 0);

            if (isFoundReporter && data.foundMatchStatus === 'confirmed') {
                modalTurnoverBox.style.display = '';
                if (data.foundHandoverAt) {
                    modalStatus.textContent = 'Item Received by Administrator';
                    modalStatus.className = 'browse-dialog-status';
                    const receivedStyle = STATUS_STYLES.open;
                    modalStatus.style.background = receivedStyle.background;
                    modalStatus.style.borderColor = receivedStyle.borderColor;
                    modalStatus.style.color = receivedStyle.color;
                    modalTurnoverBox.className = 'browse-dialog-info browse-dialog-turnover received';
                    modalTurnoverTitle.textContent = 'Found Item Received by Administrator';
                    modalTurnoverMessage.textContent = 'The item has already been physically turned over to the administrator. No further action is required from the Found Reporter.';
                    modalTurnoverReceived.textContent = 'Received by administrator: ' + data.foundHandoverAt;
                } else if (data.foundTurnoverStartDate || data.foundTurnoverDeadline) {
                    modalStatus.textContent = 'Turnover Scheduled';
                    modalStatus.className = 'browse-dialog-status';
                    const turnoverStyle = STATUS_STYLES.scheduled;
                    modalStatus.style.background = turnoverStyle.background;
                    modalStatus.style.borderColor = turnoverStyle.borderColor;
                    modalStatus.style.color = turnoverStyle.color;
                    modalTurnoverTitle.textContent = 'Found Item Turnover Schedule';
                    modalTurnoverMessage.textContent = 'Please bring the physical item to the administrator within the scheduled period below. The lost-item owner will receive a release/verification schedule only after the item is received.';
                    modalTurnoverStart.textContent = data.foundTurnoverStartDate ? 'Allowed start: ' + data.foundTurnoverStartDate : '';
                    modalTurnoverDeadline.textContent = data.foundTurnoverDeadline ? 'Deadline: ' + data.foundTurnoverDeadline : '';
                    modalTurnoverLocation.textContent = data.foundTurnoverLocation ? 'Bring item to: ' + data.foundTurnoverLocation : '';
                } else {
                    modalStatus.textContent = 'Match Approved — Turnover Pending';
                    modalStatus.className = 'browse-dialog-status';
                    const matchStyle = STATUS_STYLES.match;
                    modalStatus.style.background = matchStyle.background;
                    modalStatus.style.borderColor = matchStyle.borderColor;
                    modalStatus.style.color = matchStyle.color;
                    modalTurnoverTitle.textContent = 'Found Item Turnover — Pending Schedule';
                    modalTurnoverMessage.textContent = 'The administrator approved the potential match. Please wait for the administrator to set the allowed dates and location for turning over the found item.';
                }
            }
        }

        if (data.image) {
            modalImage.src = data.image;
            modalImage.alt = data.name || 'Item image';
            modalImage.style.display = 'block';
            modalPlaceholder.style.display = 'none';
        } else {
            modalImage.style.display = 'none';
            modalPlaceholder.style.display = 'grid';
            modalPlaceholder.textContent =
                (data.name || 'I').charAt(0).toUpperCase();
        }

        modalFeature.textContent =
            data.features
            || data.description
            || 'Refer to the item description and identifying details during ownership verification.';

        if (data.storageLocation) {
            modalStorage.textContent = data.storageLocation;
            modalStorageBox.style.display = '';
        } else {
            modalStorage.textContent =
                'Items requiring verification are secured by the campus lost & found administrator before release.';
        }

        if (data.approvedBy) {
            modalApproved.textContent =
                'Approved by ' + data.approvedBy
                + (data.approvedAt ? ' on ' + data.approvedAt : '');
            modalApproved.style.display = 'block';
        } else {
            modalApproved.style.display = 'none';
        }

        if (
            data.reportType === 'found'
            && data.itemId
            && data.claimable !== false
            && (data.statusClass === 'open' || data.statusClass === 'match')
        ) {
            modalClaim.href =
                'claim.php?item_id=' + encodeURIComponent(data.itemId);

            modalClaim.style.display = 'inline-flex';
            modalClaimNote.style.display = 'none';
        } else {
            modalClaim.style.display = 'none';

            if (data.reportType === 'found' && CLAIM_NOTES[data.statusClass]) {
                modalClaimNote.textContent = CLAIM_NOTES[data.statusClass];
                modalClaimNote.style.display = 'inline';
            } else {
                modalClaimNote.style.display = 'none';
            }
        }

        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeItemModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('.browse-card-action[data-item]').forEach(function (button) {

        button.addEventListener('click', function () {

            try {
                const data =
                    JSON.parse(this.getAttribute('data-item'));

                openItemModal(data);

            } catch (error) {

                console.error(
                    'Unable to open item details:',
                    error
                );

            }

        });

    });

    modalClose.addEventListener('click', closeItemModal);
    modalCloseText.addEventListener('click', closeItemModal);

    modal.addEventListener('click', function (event) {

        if (event.target === modal) {
            closeItemModal();
        }

    });

    document.addEventListener('keydown', function (event) {

        if (
            event.key === 'Escape'
            && modal.classList.contains('open')
        ) {
            closeItemModal();
        }

    });

})();
</script>



<!-- =========================================================
     CLAIM OWNERSHIP MODAL
     Opens directly from the Claim button on a found-item card.
     The form still submits to claim.php, so the existing claim
     validation/database workflow remains the source of truth.
========================================================= -->
<div
    id="browse-claim-modal"
    class="browse-claim-modal"
    aria-hidden="true"
>
    <div
        class="browse-claim-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="browse-claim-title"
    >
        <div class="browse-claim-head">
            <div>
                <div class="browse-claim-heading-row">
                    <span class="browse-claim-shield">♢</span>
                    <div>
                        <h2 id="browse-claim-title">Submit Ownership Claim</h2>
                        <p>Item Verification &amp; Release Protocol</p>
                    </div>
                </div>
            </div>

            <button
                type="button"
                class="browse-claim-close"
                id="browse-claim-close"
                aria-label="Close claim form"
            >×</button>
        </div>

        <form id="browse-claim-form" method="post" action="claim.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="item_id" id="browse-claim-item-id" value="">

            <div class="browse-claim-body">

                <!-- ITEM SUMMARY -->
                <div class="browse-claim-item-summary">
                    <div class="browse-claim-item-image">
                        <img id="browse-claim-image" src="" alt="">
                        <span id="browse-claim-placeholder">I</span>
                    </div>

                    <div class="browse-claim-item-copy">
                        <div class="browse-claim-category" id="browse-claim-category">CATEGORY</div>
                        <h3 id="browse-claim-item-name">Item Name</h3>
                        <p>
                            Found: <span id="browse-claim-found-location">Campus location</span>
                            <span class="browse-claim-dot">•</span>
                            Custody: <span id="browse-claim-storage">Campus Security Station</span>
                        </p>
                    </div>
                </div>

                <!-- CLAIMANT IDENTITY -->
                <div class="browse-claim-section browse-claim-identity">
                    <div class="browse-claim-section-title">
                        <span class="browse-claim-section-icon">♙</span>
                        <strong>Claimant Verification Identity</strong>
                    </div>

                    <div class="browse-claim-identity-grid">
                        <div>
                            <span>Name:</span>
                            <strong><?= h($claimUser['full_name'] ?? 'Signed-in student') ?></strong>
                        </div>
                        <div>
                            <span>Student ID:</span>
                            <strong><?= h($claimUser['university_id'] ?? '—') ?></strong>
                        </div>
                        <div>
                            <span>Email:</span>
                            <strong><?= h($claimUser['email'] ?? '—') ?></strong>
                        </div>
                        <?php if (!empty($claimUser['phone'])): ?>
                            <div>
                                <span>Phone:</span>
                                <strong><?= h($claimUser['phone']) ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- OWNERSHIP PROOF -->
                <div class="browse-claim-field">
                    <label for="browse-claim-ownership">
                        Detailed Ownership Proof &amp; Distinguishing Features <em>*</em>
                    </label>
                    <p class="browse-claim-help">
                        Describe details not fully visible to the public: internal contents, specific scratches,
                        secret stickers, engravings, markings, or purchase history.
                    </p>
                    <textarea
                        id="browse-claim-ownership"
                        name="ownership_description"
                        rows="4"
                        required
                        placeholder="e.g. Inside the wallet is my student ID STU-2024-8891 and approximately $15. The right corner has a faint coffee stain..."
                    ></textarea>
                </div>

                <!-- IDENTIFYING INFORMATION -->
                <div class="browse-claim-field">
                    <label for="browse-claim-identifying">
                        Serial Number / Hardware ID / Engraving <span>(if applicable)</span>
                    </label>
                    <input
                        id="browse-claim-identifying"
                        type="text"
                        name="identifying_information"
                        placeholder="e.g. SN: 2280A-9014-TI or IMEI / Model Number"
                    >
                </div>

                <!-- DATE + EVIDENCE -->
                <div class="browse-claim-two-col">
                    <div class="browse-claim-field">
                        <label for="browse-claim-date">When did you lose it? <span>(Approximate Date)</span></label>
                        <input
                            id="browse-claim-date"
                            name="loss_date"
                            type="date"
                            value=""
                        >
                    </div>

                    <div class="browse-claim-field">
                        <label for="browse-claim-evidence">Receipt / Proof Link <span>(Optional)</span></label>
                        <input
                            id="browse-claim-evidence"
                            type="url"
                            placeholder="Paste URL of receipt or proof"
                        >
                    </div>
                </div>

                <!-- OPTIONAL PHOTO PROOF -->
                <div class="browse-claim-field browse-claim-photo-field">
                    <label for="browse-claim-photo">
                        Add Photo Proof <span>(Recommended)</span>
                    </label>

                    <label class="browse-claim-upload" for="browse-claim-photo">
                        <span class="browse-claim-upload-icon">＋</span>
                        <span class="browse-claim-upload-copy">
                            <strong>Choose a photo</strong>
                            <small>JPG, PNG, or WEBP · up to 5 MB</small>
                        </span>
                        <span class="browse-claim-upload-button">Browse</span>
                    </label>

                    <input
                        id="browse-claim-photo"
                        name="proof_photo"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        hidden
                    >

                    <div id="browse-claim-photo-preview" class="browse-claim-photo-preview" hidden>
                        <img id="browse-claim-photo-preview-image" src="" alt="Selected proof photo preview">
                        <div class="browse-claim-photo-preview-copy">
                            <strong id="browse-claim-photo-name"></strong>
                            <span id="browse-claim-photo-size"></span>
                        </div>
                        <button type="button" id="browse-claim-photo-remove" class="browse-claim-photo-remove" aria-label="Remove selected photo">×</button>
                    </div>
                </div>

                <!-- Existing claims backend stores supporting_evidence as one field.
                     We combine the optional date, proof link, and uploaded photo path
                     into that field so the current database schema can be preserved. -->
                <input type="hidden" name="supporting_evidence" id="browse-claim-supporting-evidence" value="">

                <div class="browse-claim-note">
                    <strong>For in-person verification:</strong>
                    bring your valid University Student ID and be ready to provide supporting details.
                    Do not upload passwords, financial information, or sensitive account credentials.
                </div>

                <label class="browse-claim-confirm">
                    <input type="checkbox" id="browse-claim-confirm" required>
                    <span>I confirm that the information is truthful and understand that the item will only be released after verification.</span>
                </label>

                <div id="browse-claim-unavailable" class="browse-claim-unavailable" hidden>
                    This item is currently unavailable for a new ownership claim.
                </div>
            </div>

            <div class="browse-claim-footer">
                <button type="button" class="browse-claim-cancel" id="browse-claim-cancel">Cancel</button>
                <button type="submit" class="browse-claim-submit" id="browse-claim-submit">
                    <span>✓</span> Submit Claim for Verification
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* =========================================================
   CLAIM MODAL — SEARCH/BROWSE PORTAL
   Designed to match the supplied ownership-claim reference.
========================================================= */
.browse-claim-modal{
    position:fixed;
    inset:0;
    z-index:9999;
    display:none;
    align-items:center;
    justify-content:center;
    padding:22px;
    background:rgba(22,28,42,.58);
    backdrop-filter:blur(5px);
}
.browse-claim-modal.open{display:flex}
.browse-claim-dialog{
    width:min(575px,100%);
    height:min(760px,calc(100vh - 44px));
    max-height:calc(100vh - 44px);
    background:#fff;
    border:1px solid #e0e5ee;
    border-radius:17px;
    box-shadow:0 28px 80px rgba(20,28,45,.25);
    overflow:hidden;
    display:flex;
    flex-direction:column;
    min-height:0;
}
.browse-claim-dialog > form#browse-claim-form{
    display:flex;
    flex:1 1 0;
    flex-direction:column;
    min-height:0;
    overflow:hidden;
}

.browse-claim-dialog > form#browse-claim-form .browse-claim-body{
    flex:1 1 0;
    min-height:0;
    overflow-y:auto;
    overflow-x:hidden;
}

.browse-claim-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    padding:17px 25px 15px;
    border-bottom:1px solid #e8edf4;
    background:#fff;
}
.browse-claim-heading-row{display:flex;align-items:center;gap:11px}
.browse-claim-shield{
    width:31px;height:31px;border-radius:9px;
    display:grid;place-items:center;
    color:#9b1c2c;background:#eef0ff;
    font-weight:900;font-size:17px;
}
.browse-claim-head h2{margin:0;color:#20283a;font-size:17px;line-height:1.2;font-weight:800}
.browse-claim-head p{margin:3px 0 0;color:#8491a5;font-size:11px}
.browse-claim-close{
    width:31px;height:31px;border:0;background:transparent;
    color:#91a0b3;font-size:27px;line-height:1;cursor:pointer;
    border-radius:8px;
}
.browse-claim-close:hover{background:#f2f4f8;color:#4b5565}
.browse-claim-body{
    overflow-y:auto;
    overflow-x:hidden;
    padding:18px 25px 20px;
    flex:1 1 0;
    min-height:0;
    scrollbar-width:thin;
}
.browse-claim-item-summary{
    display:flex;gap:13px;align-items:center;
    padding:13px;border:1px solid #dfe5ef;border-radius:12px;
    background:#f8fafc;margin-bottom:14px;
}
.browse-claim-item-image{
    width:55px;height:55px;flex:0 0 55px;border-radius:8px;
    overflow:hidden;background:#e9edf3;display:grid;place-items:center;
}
.browse-claim-item-image img{width:100%;height:100%;object-fit:cover;display:none}
.browse-claim-item-image span{font-size:21px;font-weight:800;color:#8a96a8}
.browse-claim-item-copy{min-width:0}
.browse-claim-category{
    display:inline-block;padding:4px 7px;border-radius:5px;
    background:#eef0ff;color:#9b1c2c;font-size:9px;font-weight:800;
    text-transform:uppercase;letter-spacing:.03em;margin-bottom:4px;
}
.browse-claim-item-copy h3{margin:0 0 3px;color:#1d2739;font-size:13px;font-weight:800}
.browse-claim-item-copy p{margin:0;color:#738198;font-size:10px;line-height:1.45;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.browse-claim-dot{padding:0 3px;color:#a2adbc}
.browse-claim-section{border-radius:11px;margin-bottom:16px}
.browse-claim-identity{padding:12px 13px;background:#fff7f8;border:1px solid #ead0d5}
.browse-claim-section-title{display:flex;align-items:center;gap:7px;color:#741321;font-size:12px;margin-bottom:10px}
.browse-claim-section-icon{font-size:15px;color:#9b1c2c}
.browse-claim-identity-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px 20px}
.browse-claim-identity-grid div{display:flex;gap:4px;min-width:0;font-size:10px;color:#64738b}
.browse-claim-identity-grid strong{color:#42516a;font-weight:700;overflow:hidden;text-overflow:ellipsis}
.browse-claim-field{margin-bottom:14px}
.browse-claim-field label{display:block;margin-bottom:6px;color:#263248;font-size:11px;font-weight:800}
.browse-claim-field label em{color:#9b1c2c;font-style:normal}
.browse-claim-field label span{font-weight:600;color:#718096}
.browse-claim-help{margin:-1px 0 7px;color:#77859a;font-size:10px;line-height:1.45}
.browse-claim-field textarea,
.browse-claim-field input{
    width:100%;box-sizing:border-box;border:1px solid #ccd6e4;border-radius:10px;
    background:#fff;color:#293449;font:inherit;font-size:11px;outline:none;
    transition:border-color .15s,box-shadow .15s;
}
.browse-claim-field textarea{min-height:94px;resize:vertical;padding:10px 11px;line-height:1.45}
.browse-claim-field input{height:36px;padding:0 11px}
.browse-claim-field textarea::placeholder,.browse-claim-field input::placeholder{color:#a1acbb}
.browse-claim-field textarea:focus,.browse-claim-field input:focus{border-color:#9b1c2c;box-shadow:0 0 0 3px rgba(79,70,229,.09)}
.browse-claim-two-col{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.browse-claim-photo-field{margin-top:2px}
.browse-claim-upload{
    min-height:58px;
    box-sizing:border-box;
    display:flex;
    align-items:center;
    gap:10px;
    padding:10px 11px;
    border:1px dashed #c8d3e2;
    border-radius:10px;
    background:#fafbfe;
    cursor:pointer;
    transition:border-color .15s,background .15s,box-shadow .15s;
}
.browse-claim-upload:hover{
    border-color:#9b1c2c;
    background:#f7f7ff;
    box-shadow:0 0 0 3px rgba(79,70,229,.06);
}
.browse-claim-upload-icon{
    width:31px;height:31px;flex:0 0 31px;
    display:grid;place-items:center;
    border-radius:8px;
    background:#eef0ff;color:#9b1c2c;
    font-size:20px;font-weight:500;
}
.browse-claim-upload-copy{min-width:0;display:flex;flex-direction:column;gap:2px;flex:1}
.browse-claim-upload-copy strong{font-size:10px;color:#334056;font-weight:800}
.browse-claim-upload-copy small{font-size:9px;color:#8a97a9}
.browse-claim-upload-button{
    flex:0 0 auto;
    padding:7px 11px;
    border-radius:7px;
    background:#9b1c2c;
    color:#fff;
    font-size:9px;
    font-weight:800;
    line-height:1;
    pointer-events:none;
}
.browse-claim-photo-preview[hidden]{
    display:none !important;
}
.browse-claim-photo-preview{
    display:flex;
    align-items:center;
    gap:10px;
    margin-top:8px;
    padding:8px;
    border:1px solid #dfe5ef;
    border-radius:9px;
    background:#f8fafc;
}
.browse-claim-photo-preview img{
    width:45px;height:45px;flex:0 0 45px;
    border-radius:7px;object-fit:cover;
    background:#e9edf3;
}
.browse-claim-photo-preview-copy{
    min-width:0;display:flex;flex-direction:column;gap:3px;flex:1;
}
.browse-claim-photo-preview-copy strong{
    color:#334056;font-size:10px;font-weight:800;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.browse-claim-photo-preview-copy span{color:#8793a5;font-size:9px}
.browse-claim-photo-remove{
    width:26px;height:26px;border:0;border-radius:7px;
    background:#eef1f5;color:#697689;font-size:18px;
    cursor:pointer;line-height:1;
}
.browse-claim-photo-remove:hover{background:#ffeaea;color:#b33a3a}
.browse-claim-file-error{
    margin-top:6px;color:#a43a3a;font-size:9px;font-weight:700;
}
.browse-claim-note{
    padding:10px 11px;margin-top:2px;margin-bottom:13px;
    border:1px solid #cfe8dc;background:#effaf5;color:#557267;
    border-radius:9px;font-size:10px;line-height:1.5;
}
.browse-claim-note strong{color:#26755a}
.browse-claim-confirm{display:flex;align-items:flex-start;gap:8px;color:#59677b;font-size:10px;line-height:1.5;cursor:pointer;margin-bottom:2px}
.browse-claim-confirm input{margin-top:2px;accent-color:#9b1c2c}
.browse-claim-unavailable{padding:10px;border-radius:9px;background:#fff4f4;border:1px solid #f1c7c7;color:#9b3030;font-size:10px;margin-top:10px}
.browse-claim-footer{
    display:flex;
    justify-content:flex-end;
    align-items:center;
    gap:9px;
    flex:0 0 auto;
    min-height:65px;
    box-sizing:border-box;
    padding:12px 25px 15px;
    border-top:1px solid #e7ebf1;
    background:#fff;
    position:relative;
    z-index:2;
}
.browse-claim-cancel,.browse-claim-submit{
    height:38px;border-radius:10px;padding:0 16px;border:0;font-size:11px;font-weight:800;cursor:pointer;
}
.browse-claim-cancel{background:#f1f3f6;color:#59677b}
.browse-claim-submit{background:#9b1c2c;color:#fff;box-shadow:0 7px 18px rgba(79,70,229,.19)}
.browse-claim-submit:hover{background:#741321}
.browse-claim-submit:disabled{opacity:.5;cursor:not-allowed;box-shadow:none}
.browse-claim-submit span{margin-right:5px}
@media(max-width:620px){
    .browse-claim-modal{padding:10px}
    .browse-claim-dialog{
        height:calc(100vh - 20px);
        max-height:calc(100vh - 20px);
        border-radius:14px;
    }
    .browse-claim-head,.browse-claim-body{padding-left:16px;padding-right:16px}
    .browse-claim-footer{padding-left:16px;padding-right:16px}
    .browse-claim-identity-grid,.browse-claim-two-col{grid-template-columns:1fr}
    .browse-claim-footer{flex-direction:column-reverse;align-items:stretch}
    .browse-claim-cancel,.browse-claim-submit{width:100%}
}
</style>

<script>
(function(){
    const modal = document.getElementById('browse-claim-modal');
    const closeBtn = document.getElementById('browse-claim-close');
    const cancelBtn = document.getElementById('browse-claim-cancel');
    const form = document.getElementById('browse-claim-form');
    const itemId = document.getElementById('browse-claim-item-id');
    const image = document.getElementById('browse-claim-image');
    const placeholder = document.getElementById('browse-claim-placeholder');
    const category = document.getElementById('browse-claim-category');
    const itemName = document.getElementById('browse-claim-item-name');
    const location = document.getElementById('browse-claim-found-location');
    const storage = document.getElementById('browse-claim-storage');
    const dateInput = document.getElementById('browse-claim-date');
    const evidenceInput = document.getElementById('browse-claim-evidence');
    const photoInput = document.getElementById('browse-claim-photo');
    const photoPreview = document.getElementById('browse-claim-photo-preview');
    const photoPreviewImage = document.getElementById('browse-claim-photo-preview-image');
    const photoName = document.getElementById('browse-claim-photo-name');
    const photoSize = document.getElementById('browse-claim-photo-size');
    const photoRemove = document.getElementById('browse-claim-photo-remove');
    const supporting = document.getElementById('browse-claim-supporting-evidence');
    const confirm = document.getElementById('browse-claim-confirm');
    const submit = document.getElementById('browse-claim-submit');
    const unavailable = document.getElementById('browse-claim-unavailable');

    function clearPhoto(){
        if(photoInput) photoInput.value = '';
        if(photoPreview){
            photoPreview.hidden = true;
        }
        if(photoPreviewImage){
            photoPreviewImage.removeAttribute('src');
        }
        if(photoName) photoName.textContent = '';
        if(photoSize) photoSize.textContent = '';
    }

    function formatBytes(bytes){
        if(bytes < 1024) return bytes + ' B';
        if(bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    if(photoInput){
        photoInput.addEventListener('change', function(){
            const file = this.files && this.files[0];
            if(!file){
                clearPhoto();
                return;
            }

            const allowed = ['image/jpeg','image/png','image/webp'];
            if(!allowed.includes(file.type)){
                alert('Please choose a JPG, PNG, or WEBP image.');
                clearPhoto();
                return;
            }

            if(file.size > 5 * 1024 * 1024){
                alert('The proof photo must be 5 MB or smaller.');
                clearPhoto();
                return;
            }

            if(photoPreviewImage){
                photoPreviewImage.src = URL.createObjectURL(file);
            }
            if(photoName) photoName.textContent = file.name;
            if(photoSize) photoSize.textContent = formatBytes(file.size);
            if(photoPreview) photoPreview.hidden = false;
        });
    }

    if(photoRemove){
        photoRemove.addEventListener('click', clearPhoto);
    }

    function openClaim(data){
        itemId.value = data.itemId || '';
        category.textContent = data.category || 'FOUND ITEM';
        itemName.textContent = data.name || 'Found Item';
        location.textContent = data.location || 'Campus location not specified';
        storage.textContent = data.storageLocation || 'Campus Security / Lost & Found';

        if(data.image){
            image.src = data.image;
            image.alt = data.name || 'Item image';
            image.style.display = 'block';
            placeholder.style.display = 'none';
        }else{
            image.removeAttribute('src');
            image.style.display = 'none';
            placeholder.style.display = 'grid';
            placeholder.textContent = (data.name || 'I').charAt(0).toUpperCase();
        }

        const canClaim = data.claimable !== false;
        submit.disabled = !canClaim;
        unavailable.hidden = canClaim;

        form.reset();
        itemId.value = data.itemId || '';
        confirm.checked = false;
        clearPhoto();

        modal.classList.add('open');
        modal.setAttribute('aria-hidden','false');
        document.body.style.overflow = 'hidden';
        setTimeout(function(){
            const first = document.getElementById('browse-claim-ownership');
            if(canClaim && first) first.focus();
        },50);
    }

    function closeClaim(){
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden','true');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('.browse-claim-trigger[data-claim-item]').forEach(function(button){
        button.addEventListener('click',function(){
            try{
                openClaim(JSON.parse(this.getAttribute('data-claim-item')));
            }catch(err){
                console.error('Unable to open ownership claim form:',err);
            }
        });
    });

    closeBtn.addEventListener('click',closeClaim);
    cancelBtn.addEventListener('click',closeClaim);
    modal.addEventListener('click',function(e){ if(e.target === modal) closeClaim(); });
    document.addEventListener('keydown',function(e){ if(e.key === 'Escape' && modal.classList.contains('open')) closeClaim(); });

    form.addEventListener('submit',function(e){
        if(submit.disabled){
            e.preventDefault();
            return;
        }

        const parts = [];
        if(dateInput.value){ parts.push('Approximate date lost: ' + dateInput.value); }
        if(evidenceInput.value.trim()){ parts.push('Receipt / proof link: ' + evidenceInput.value.trim()); }
        supporting.value = parts.join('\n');
    });
})();
</script>

<?php require 'includes/footer.php'; ?>exr
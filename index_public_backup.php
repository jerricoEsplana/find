<?php
require 'config.php';

$pageTitle = 'Home';

/*
|--------------------------------------------------------------------------
| LIVE DATABASE COUNTS
|--------------------------------------------------------------------------
*/
$active = (int)$pdo->query("
    SELECT COUNT(*)
    FROM reports
    WHERE report_status = 'approved'
      AND item_status NOT IN ('returned', 'closed')
")->fetchColumn();

$found = (int)$pdo->query("
    SELECT COUNT(*)
    FROM reports
    WHERE report_type = 'found'
      AND report_status = 'approved'
      AND item_status NOT IN ('returned', 'closed')
")->fetchColumn();

$returned = (int)$pdo->query("
    SELECT COUNT(*)
    FROM reports
    WHERE item_status = 'returned'
")->fetchColumn();

/*
|--------------------------------------------------------------------------
| RECENT PUBLIC ITEMS
|--------------------------------------------------------------------------
*/
$q = trim($_GET['q'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$locationFilter = trim($_GET['location'] ?? '');
$dateFilter = trim($_GET['date_from'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = [
    "r.report_status = 'approved'"
];
$params = [];

if ($statusFilter === 'returned') {
    $where[] = "r.item_status = 'returned'";
}

if ($typeFilter === 'lost' || $typeFilter === 'found') {
    $where[] = "r.report_type = ?";
    $params[] = $typeFilter;
}

if ($categoryFilter !== '') {
    $where[] = "i.category = ?";
    $params[] = $categoryFilter;
}

if ($locationFilter !== '') {
    $where[] = "(
        r.campus_location LIKE ?
        OR r.building LIKE ?
        OR r.specific_area LIKE ?
    )";
    $locationLike = '%' . $locationFilter . '%';
    $params[] = $locationLike;
    $params[] = $locationLike;
    $params[] = $locationLike;
}

if ($dateFilter !== '') {
    $where[] = "DATE(r.event_date) = ?";
    $params[] = $dateFilter;
}

if ($q !== '') {
    $where[] = "(
        i.item_name LIKE ?
        OR i.category LIKE ?
        OR i.description LIKE ?
        OR i.brand LIKE ?
        OR r.campus_location LIKE ?
        OR r.building LIKE ?
        OR r.specific_area LIKE ?
    )";
    $searchLike = '%' . $q . '%';
    for ($i = 0; $i < 7; $i++) {
        $params[] = $searchLike;
    }
}

/* Show 10 items per page. */
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$countSql = "
    SELECT COUNT(*)
    FROM reports r
    INNER JOIN items i ON i.id = r.item_id
    WHERE " . implode(" AND ", $where);

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalItems = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalItems / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "
    SELECT
        r.*,
        i.item_name,
        i.category,
        i.photo_path,
        i.description,
        i.brand
    FROM reports r
    INNER JOIN items i ON i.id = r.item_id
    WHERE " . implode(" AND ", $where) . "
    ORDER BY r.created_at DESC
    LIMIT $perPage OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$recent = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| CATEGORIES
|--------------------------------------------------------------------------
*/
$categories = $pdo->query("
    SELECT DISTINCT category
    FROM items
    WHERE category IS NOT NULL
      AND category != ''
    ORDER BY category
")->fetchAll(PDO::FETCH_COLUMN);

require 'includes/header.php';
?>

<!-- =========================================================
     HERO / SEARCH
========================================================= -->

<section class="home-hero">

    <div class="container">

        <div class="home-hero-content">

            <div class="hero-copy">

                <span class="eyebrow hero-eyebrow">
                    SMART CAMPUS LOST-AND-FOUND
                </span>

                <h1>
                    Find it.
                    <span>Return it.</span>
                </h1>

                <p>
                    Find IT is a centralized campus lost-and-found platform
                    where students, faculty, and staff can browse reported
                    items, search records, report belongings, and submit
                    ownership claims.
                </p>





            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<section class="home-content">

    <div class="container">

        <div class="home-layout">

            <!-- =================================================
                 LEFT / MAIN AREA
            ================================================== -->

            <div class="home-main">

                <!-- BROWSE TABS + SEARCH -->

                <div class="browse-toolbar">

                    <div class="browse-tabs">

                        <a
                            class="browse-tab <?= ($typeFilter === '' && $statusFilter === '') ? 'active' : '' ?>"
                            href="index.php"
                        >
                            All Items
                        </a>

                        <a
                            class="browse-tab <?= $typeFilter === 'lost' ? 'active' : '' ?>"
                            href="index.php?type=lost"
                        >
                            Reported Lost
                        </a>

                        <a
                            class="browse-tab <?= $typeFilter === 'found' ? 'active' : '' ?>"
                            href="index.php?type=found"
                        >
                            Found Items
                        </a>

                        <a
                            class="browse-tab <?= $statusFilter === 'returned' ? 'active' : '' ?>"
                            href="index.php?status=returned"
                        >
                            Recently Returned
                        </a>

                    </div>

                    <form class="home-search-inline" action="index.php" method="get">

                        <div class="search-input-wrap">
                            <span class="search-icon">⌕</span>

                            <input
                                type="text"
                                name="q"
                                value="<?= h($q) ?>"
                                placeholder="Search item, brand, serial number, location..."
                                aria-label="Search lost and found items"
                            >

                            <?php if ($typeFilter !== ''): ?>
                                <input type="hidden" name="type" value="<?= h($typeFilter) ?>">
                            <?php endif; ?>

                            <?php if ($statusFilter !== ''): ?>
                                <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
                            <?php endif; ?>

                            <?php if ($categoryFilter !== ''): ?>
                                <input type="hidden" name="category" value="<?= h($categoryFilter) ?>">
                            <?php endif; ?>

                            <?php if ($locationFilter !== ''): ?>
                                <input type="hidden" name="location" value="<?= h($locationFilter) ?>">
                            <?php endif; ?>

                            <?php if ($dateFilter !== ''): ?>
                                <input type="hidden" name="date_from" value="<?= h($dateFilter) ?>">
                            <?php endif; ?>
                        </div>

                    </form>

                </div>


                <!-- FILTER BAR -->

                <div class="home-filter-card">

                    <form
                        class="home-filters"
                        id="homeFilters"
                        action="index.php"
                        method="get"
                    >
                        <?php if ($q !== ''): ?>
                            <input type="hidden" name="q" value="<?= h($q) ?>">
                        <?php endif; ?>

                        <?php if ($typeFilter !== ''): ?>
                            <input type="hidden" name="type" value="<?= h($typeFilter) ?>">
                        <?php endif; ?>

                        <?php if ($statusFilter !== ''): ?>
                            <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
                        <?php endif; ?>

                        <div class="filter-group">

                            <label>Category</label>

                            <select name="category" onchange="this.form.submit()">

                                <option value="">
                                    All Categories
                                </option>

                                <?php foreach ($categories as $category): ?>

                                    <option value="<?= h($category) ?>" <?= $categoryFilter === $category ? "selected" : "" ?>>
                                        <?= h($category) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div class="filter-group">

                            <label>Campus Zone</label>

                            <input
                                type="text"
                                name="location"
                                value="<?= h($locationFilter) ?>"
                                placeholder="All Campus Zones"
                                onchange="this.form.submit()"
                            >

                        </div>


                        <div class="filter-group">

                            <label>Date</label>

                            <input
                                type="date"
                                name="date_from"
                                onchange="this.form.submit()"
                            >

                        </div>


                        <button
                            class="filter-reset"
                            type="button"
                            onclick="window.location.href='index.php'"
                        >
                            Reset
                        </button>

                    </form>

                </div>


                <!-- SECTION HEADER -->

                <div class="items-heading">

                    <div>

                        <span class="eyebrow">
                            PUBLIC RECORDS
                        </span>

                        <h2>
                            <?php if ($q !== ''): ?>
                                Search Results for “<?= h($q) ?>”
                            <?php elseif ($typeFilter === 'lost'): ?>
                                Reported Lost Items
                            <?php elseif ($typeFilter === 'found'): ?>
                                Found Items
                            <?php elseif ($statusFilter === 'returned'): ?>
                                Recently Returned Items
                            <?php elseif ($categoryFilter !== '' || $locationFilter !== '' || $dateFilter !== ''): ?>
                                Filtered Items
                            <?php else: ?>
                                All Reported Items
                            <?php endif; ?>
                        </h2>

                    </div>

                    <a
                        class="view-all"
                        href="search.php"
                    >
                        View all →
                    </a>

                </div>


                <!-- ITEM GRID -->

                <div class="home-item-grid">

                    <?php foreach ($recent as $r): ?>

                        <?php
                        /*
                         * IMAGE RESOLUTION
                         *
                         * Existing uploaded photos always have priority.
                         * For older records without a photo, place your own
                         * image in /uploads/items/ using the generated filename
                         * based on the item name.
                         *
                         * Example:
                         *   Black Backpack      -> black-backpack.jpg
                         *   Blue iPhone 13      -> blue-iphone-13.jpg
                         *   University ID Card  -> university-id-card.jpg
                         *   Brown Leather Wallet-> brown-leather-wallet.jpg
                         */
                        $imageSlug = strtolower(trim((string)$r['item_name']));
                        $imageSlug = preg_replace('/[^a-z0-9]+/i', '-', $imageSlug);
                        $imageSlug = trim($imageSlug, '-');

                        $customImageDir = __DIR__ . '/uploads/items/';
                        $customImageUrl = 'uploads/items/';
                        $imageExtensions = ['jpg', 'jpeg', 'png', 'webp'];

                        $displayPhoto = !empty($r['photo_path'])
                            ? $r['photo_path']
                            : '';

                        if ($displayPhoto === '' && $imageSlug !== '') {
                            foreach ($imageExtensions as $extension) {
                                $candidateFile = $customImageDir . $imageSlug . '.' . $extension;

                                if (is_file($candidateFile)) {
                                    $displayPhoto = $customImageUrl . $imageSlug . '.' . $extension;
                                    break;
                                }
                            }
                        }
                        ?>

                        <article class="home-item-card">

                            <a
                                href="item.php?id=<?= (int)$r['item_id'] ?>"
                                class="home-item-photo"
                            >

                                <?php if ($displayPhoto !== ''): ?>

                                    <img
                                        src="<?= h($displayPhoto) ?>"
                                        alt="<?= h($r['item_name']) ?>"
                                    >

                                <?php else: ?>

                                    <div class="photo-placeholder">
                                        <?= h(strtoupper(substr($r['item_name'], 0, 1))) ?>
                                    </div>

                                <?php endif; ?>


                                <span
                                    class="item-type <?= h($r['report_type']) ?>"
                                >
                                    <?= $r['report_type'] === 'found'
                                        ? 'FOUND ITEM'
                                        : 'LOST ITEM'
                                    ?>
                                </span>

                            </a>


                            <div class="home-item-body">

                                <div class="item-category">
                                    <?= h($r['category']) ?>
                                </div>

                                <h3>
                                    <a href="item.php?id=<?= (int)$r['item_id'] ?>">
                                        <?= h($r['item_name']) ?>
                                    </a>
                                </h3>

                                <?php if (!empty($r['description'])): ?>

                                    <p class="item-description">
                                        <?= h(mb_strimwidth(
                                            $r['description'],
                                            0,
                                            105,
                                            '...'
                                        )) ?>
                                    </p>

                                <?php endif; ?>

                                <div class="item-location">
                                    <span>⌖</span>

                                    <?= h($r['campus_location']) ?>

                                </div>


                                <div class="item-card-footer">

                                    <span class="item-date">
                                        <?= h($r['event_date']) ?>
                                    </span>

                                    <span class="status <?= h(status_class($r['item_status'])) ?>">
                                        <?= h(status_label($r['item_status'])) ?>
                                    </span>

                                    <a
                                        class="card-action <?= $r['report_type'] === 'found' && $r['item_status'] === 'open' ? 'primary-action' : '' ?>"
                                        href="item.php?id=<?= (int)$r['item_id'] ?>"
                                    >
                                        View Details
                                    </a>

                                </div>

                            </div>

                        </article>

                    <?php endforeach; ?>


                    <?php if (!$recent): ?>

                        <div class="empty-state home-empty">

                            <div class="empty-icon">⌕</div>

                            <h3>
                                No public records yet
                            </h3>

                            <p>
                                Approved lost-and-found reports will
                                appear here.
                            </p>

                        </div>

                    <?php endif; ?>

                </div>

                <?php if ($totalPages > 1): ?>
                    <?php
                    $paginationQuery = $_GET;
                    unset($paginationQuery['page']);

                    $pageUrl = static function (int $targetPage) use ($paginationQuery): string {
                        $query = $paginationQuery;
                        $query['page'] = $targetPage;
                        return 'index.php?' . http_build_query($query);
                    };

                    $pageNumbers = [];

                    if ($totalPages <= 7) {
                        for ($n = 1; $n <= $totalPages; $n++) {
                            $pageNumbers[] = $n;
                        }
                    } else {
                        $pageNumbers[] = 1;

                        $start = max(2, $page - 1);
                        $end = min($totalPages - 1, $page + 1);

                        if ($start > 2) {
                            $pageNumbers[] = '...';
                        }

                        for ($n = $start; $n <= $end; $n++) {
                            $pageNumbers[] = $n;
                        }

                        if ($end < $totalPages - 1) {
                            $pageNumbers[] = '...';
                        }

                        $pageNumbers[] = $totalPages;
                    }
                    ?>

                    <nav class="home-pagination" aria-label="Item pages">

                        <?php if ($page > 1): ?>
                            <a class="pagination-prev" href="<?= h($pageUrl($page - 1)) ?>">
                                ← Previous
                            </a>
                        <?php else: ?>
                            <span class="pagination-prev disabled">← Previous</span>
                        <?php endif; ?>

                        <div class="pagination-pages">
                            <?php foreach ($pageNumbers as $number): ?>
                                <?php if ($number === '...'): ?>
                                    <span class="pagination-ellipsis">...</span>
                                <?php elseif ((int)$number === $page): ?>
                                    <span class="pagination-number active"><?= (int)$number ?></span>
                                <?php else: ?>
                                    <a class="pagination-number" href="<?= h($pageUrl((int)$number)) ?>">
                                        <?= (int)$number ?>
                                    </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($page < $totalPages): ?>
                            <a class="pagination-next" href="<?= h($pageUrl($page + 1)) ?>">
                                Next →
                            </a>
                        <?php else: ?>
                            <span class="pagination-next disabled">Next →</span>
                        <?php endif; ?>

                    </nav>
                <?php endif; ?>

            </div>


            <!-- =================================================
                 RIGHT SIDEBAR
            ================================================== -->

            <aside class="home-sidebar">

                <!-- LIVE STATISTICS -->

                <div class="sidebar-card">

                    <div class="sidebar-card-header">

                        <div>
                            <span class="eyebrow">
                                CAMPUS ACTIVITY
                            </span>

                            <h3>
                                Live Records
                            </h3>
                        </div>

                        <span class="live-indicator">
                            ● LIVE
                        </span>

                    </div>


                    <div class="activity-stat">

                        <strong>
                            <?= $active ?>
                        </strong>

                        <span>
                            Active Reports
                        </span>

                    </div>


                    <div class="activity-stat">

                        <strong>
                            <?= $found ?>
                        </strong>

                        <span>
                            Found Items
                        </span>

                    </div>


                    <div class="activity-stat">

                        <strong>
                            <?= $returned ?>
                        </strong>

                        <span>
                            Items Returned
                        </span>

                    </div>

                </div>


                <!-- HOW TO CLAIM -->

                <div class="sidebar-card claim-guide">

                    <div class="guide-title">

                        <span class="guide-icon">
                            ✓
                        </span>

                        <div>

                            <h3>
                                How to Claim an Item
                            </h3>

                            <span>
                                3 EASY STEPS
                            </span>

                        </div>

                    </div>


                    <div class="guide-step">

                        <b>1</b>

                        <div>

                            <strong>
                                Verify Ownership
                            </strong>

                            <p>
                                Provide identifying details,
                                proof of ownership, or other
                                information that only the owner
                                should know.
                            </p>

                        </div>

                    </div>


                    <div class="guide-step">

                        <b>2</b>

                        <div>

                            <strong>
                                Submit Your Claim
                            </strong>

                            <p>
                                Log in and submit an ownership
                                claim for the selected found item.
                            </p>

                        </div>

                    </div>


                    <div class="guide-step">

                        <b>3</b>

                        <div>

                            <strong>
                                Complete Verification
                            </strong>

                            <p>
                                Wait for administrator verification
                                and follow the instructions sent
                                through your notification.
                            </p>

                        </div>

                    </div>


                    <div class="guide-note">

                        <strong>
                            Important
                        </strong>

                        <p>
                            For approved claims, users may be
                            required to present a valid school ID
                            and complete in-person verification
                            before the item is released.
                        </p>

                    </div>

                </div>


                <!-- REPORT CTA -->

                <div class="sidebar-card report-help">

                    <span class="eyebrow">
                        HAVE YOU FOUND SOMETHING?
                    </span>

                    <h3>
                        Help return an item to its owner.
                    </h3>

                    <p>
                        Report a found item and provide its
                        location and identifying details.
                    </p>

                    <a
                        href="report.php?type=found"
                        class="btn sidebar-btn"
                    >
                        Report Found Item
                    </a>

                </div>

            </aside>

        </div>

    </div>

</section>


<!-- =========================================================
     BOTTOM CTA
========================================================= -->

<section class="home-bottom-cta">

    <div class="container">

        <div>

            <span class="eyebrow">
                FIND IT · CAMPUS LOST & FOUND
            </span>

            <h2>
                Lost something? Start searching.
            </h2>

            <p>
                Browse public records first. You only need an
                account when you report an item or submit a claim.
            </p>

        </div>

        <div class="cta-buttons">

            <a
                href="index.php"
                class="btn light"
            >
                Search Items
            </a>

            <a
                href="report.php?type=lost"
                class="btn outline-light"
            >
                Report Lost Item
            </a>

        </div>

    </div>

</section>


<?php require 'includes/footer.php'; ?>
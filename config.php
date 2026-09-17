<?php
declare(strict_types=1);

session_start();

$dbPath = getenv('SQLITE_DB_PATH') ?: (__DIR__ . '/storage/findit.sqlite');
$uploadPath = getenv('UPLOAD_PATH') ?: (__DIR__ . '/uploads');

if (!is_dir(dirname($dbPath))) {
    @mkdir(dirname($dbPath), 0775, true);
}
if (!is_dir($uploadPath)) {
    @mkdir($uploadPath, 0775, true);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

initialize_database($pdo);
ensure_seed_data($pdo);

/*
 * Find IT workflow compatibility migrations.
 * These fields support the finalized Potential Match and admin verification
 * workflow without changing the existing database initialization structure.
 */
try {
    $pdo->exec("ALTER TABLE reports ADD COLUMN admin_hidden INTEGER NOT NULL DEFAULT 0");
} catch (Throwable $e) {
    // Column already exists.
}

try {
    $pdo->exec("ALTER TABLE potential_matches ADD COLUMN verification_outcome TEXT");
} catch (Throwable $e) {
    // Column already exists.
}

try {
    $pdo->exec("ALTER TABLE potential_matches ADD COLUMN verification_outcome_reason TEXT");
} catch (Throwable $e) {
    // Column already exists.
}

/*
 * Reports are automatically recorded in the finalized workflow.
 * Keep the legacy database value for compatibility with older matching code,
 * while preventing old pending_review records from remaining stuck.
 */
try {
    $pdo->exec("UPDATE reports
                SET report_status='approved', rejection_reason=NULL
                WHERE report_status='pending_review'");
} catch (Throwable $e) {
    // Preserve application startup if an older schema does not contain these fields.
}

function h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

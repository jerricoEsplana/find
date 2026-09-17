<?php
declare(strict_types=1);

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Invalid request token. Please go back and try again.');
    }
}

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array {
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function now(): string {
    return date('Y-m-d H:i:s');
}

function status_label(string $value): string {
    return match ($value) {
        'pending_review' => 'Pending Review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'open' => 'Open',
        'matched' => 'Matched',
        'claim_pending' => 'Claim Pending',
        'returned' => 'Returned',
        'closed' => 'Closed',
        'pending' => 'Pending',
        default => ucwords(str_replace('_', ' ', $value)),
    };
}

function status_class(string $value): string {
    return preg_replace('/[^a-z0-9_-]/', '', strtolower($value));
}

function require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method Not Allowed');
    }
}

function save_uploaded_photo(array $file, string $uploadPath): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The image upload failed.');
    }
    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        throw new RuntimeException('Each image must be 10MB or smaller.');
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG, or WEBP images are allowed.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $destination = rtrim($uploadPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('The server could not save the uploaded image.');
    }

    return 'uploads/' . $filename;
}


function normalized_match_text(?string $value): string {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9\s]/', ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim($value);
}

function match_tokens(?string $value): array {
    $text = normalized_match_text($value);
    if ($text === '') return [];
    $stop = ['the','and','with','for','item','this','that','near','found','lost'];
    $tokens = preg_split('/\s+/', $text);
    $tokens = array_filter($tokens, static fn($token) => strlen($token) >= 3 && !in_array($token, $stop, true));
    return array_values(array_unique($tokens));
}

function potential_match_breakdown(array $lost, array $found): array {
    $score = 0;
    $details = [];

    // Item name — strongest signal.
    $lostName = normalized_match_text($lost['item_name'] ?? '');
    $foundName = normalized_match_text($found['item_name'] ?? '');

    if ($lostName !== '' && $foundName !== '') {
        if ($lostName === $foundName) {
            $points = 35;
            $score += $points;
            $details[] = [
                'field' => 'Item Name',
                'result' => 'Exact match',
                'lost' => $lost['item_name'] ?? '',
                'found' => $found['item_name'] ?? '',
                'points' => $points,
                'kind' => 'match'
            ];
        } else {
            $lostTokens = match_tokens($lostName);
            $foundTokens = match_tokens($foundName);
            $overlap = ($lostTokens && $foundTokens)
                ? count(array_intersect($lostTokens, $foundTokens))
                : 0;
            $points = min(25, $overlap * 8);
            $score += $points;
            $details[] = [
                'field' => 'Item Name',
                'result' => $points > 0 ? 'Partial match' : 'Different',
                'lost' => $lost['item_name'] ?? '',
                'found' => $found['item_name'] ?? '',
                'points' => $points,
                'kind' => $points > 0 ? 'partial' : 'different'
            ];
        }
    } else {
        $details[] = [
            'field' => 'Item Name',
            'result' => 'Missing information',
            'lost' => $lost['item_name'] ?? '',
            'found' => $found['item_name'] ?? '',
            'points' => 0,
            'kind' => 'missing'
        ];
    }

    // Category.
    $lostCategory = normalized_match_text($lost['category'] ?? '');
    $foundCategory = normalized_match_text($found['category'] ?? '');
    if ($lostCategory !== '' && $foundCategory !== '' && $lostCategory === $foundCategory) {
        $points = 20;
        $score += $points;
        $details[] = [
            'field' => 'Category',
            'result' => 'Same category',
            'lost' => $lost['category'] ?? '',
            'found' => $found['category'] ?? '',
            'points' => $points,
            'kind' => 'match'
        ];
    } else {
        $details[] = [
            'field' => 'Category',
            'result' => ($lostCategory === '' || $foundCategory === '') ? 'Missing information' : 'Different',
            'lost' => $lost['category'] ?? '',
            'found' => $found['category'] ?? '',
            'points' => 0,
            'kind' => ($lostCategory === '' || $foundCategory === '') ? 'missing' : 'different'
        ];
    }

    // Brand and color.
    foreach (['brand' => 15, 'color' => 10] as $field => $maxPoints) {
        $a = normalized_match_text($lost[$field] ?? '');
        $b = normalized_match_text($found[$field] ?? '');

        if ($a !== '' && $b !== '' && $a === $b) {
            $score += $maxPoints;
            $details[] = [
                'field' => ucwords(str_replace('_', ' ', $field)),
                'result' => 'Same value',
                'lost' => $lost[$field] ?? '',
                'found' => $found[$field] ?? '',
                'points' => $maxPoints,
                'kind' => 'match'
            ];
        } else {
            $details[] = [
                'field' => ucwords(str_replace('_', ' ', $field)),
                'result' => ($a === '' || $b === '') ? 'Missing information' : 'Different',
                'lost' => $lost[$field] ?? '',
                'found' => $found[$field] ?? '',
                'points' => 0,
                'kind' => ($a === '' || $b === '') ? 'missing' : 'different'
            ];
        }
    }

    // Description + identifying features.
    $lostDetails = match_tokens(
        ($lost['description'] ?? '') . ' ' . ($lost['identifying_features'] ?? '')
    );
    $foundDetails = match_tokens(
        ($found['description'] ?? '') . ' ' . ($found['identifying_features'] ?? '')
    );

    $overlap = ($lostDetails && $foundDetails)
        ? count(array_intersect($lostDetails, $foundDetails))
        : 0;
    $points = min(20, $overlap * 4);
    $score += $points;

    $details[] = [
        'field' => 'Description / Identifying Details',
        'result' => $points > 0 ? ($overlap . ' shared detail' . ($overlap === 1 ? '' : 's')) : 'No shared details detected',
        'lost' => trim(($lost['description'] ?? '') . ' ' . ($lost['identifying_features'] ?? '')),
        'found' => trim(($found['description'] ?? '') . ' ' . ($found['identifying_features'] ?? '')),
        'points' => $points,
        'kind' => $points > 0 ? 'partial' : 'different'
    ];

    return [
        'score' => min(100, $score),
        'details' => $details
    ];
}

function potential_match_score(array $lost, array $found): int {
    $breakdown = potential_match_breakdown($lost, $found);
    return (int)$breakdown['score'];
}

function find_potential_lost_matches(PDO $pdo, array $foundReport, array $foundItem): array {
    $stmt = $pdo->prepare("
        SELECT
            r.*,
            i.item_name,
            i.category,
            i.description,
            i.color,
            i.brand,
            i.identifying_features
        FROM reports r
        JOIN items i ON i.id = r.item_id
        WHERE r.report_type = 'lost'
          AND r.report_status = 'approved'
          AND r.item_status = 'open'
        ORDER BY r.created_at DESC
    ");

    $stmt->execute();
    $matches = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $lost) {
        $score = potential_match_score($lost, $foundItem);

        // Same item name + same category already reaches 55%.
        if ($score >= 45) {
            $lost['match_score'] = $score;
            $matches[] = $lost;
        }
    }

    usort($matches, static function (array $a, array $b): int {
        return $b['match_score'] <=> $a['match_score'];
    });

    return $matches;
}

function sync_all_potential_matches(PDO $pdo): int {
    // This makes matching retroactive too: records that were already approved
    // before the matching feature was installed will still be detected.
    $foundReports = $pdo->query("
        SELECT
            r.*,
            i.item_name,
            i.category,
            i.description,
            i.color,
            i.brand,
            i.identifying_features
        FROM reports r
        JOIN items i ON i.id = r.item_id
        WHERE r.report_type = 'found'
          AND r.report_status = 'approved'
          AND r.item_status = 'open'
        ORDER BY r.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $insert = $pdo->prepare("
        INSERT OR IGNORE INTO potential_matches
        (
            found_report_id,
            lost_report_id,
            match_score,
            match_status,
            created_at,
            updated_at
        )
        VALUES (?, ?, ?, 'pending', ?, ?)
    ");

    $created = 0;

    foreach ($foundReports as $found) {
        $matches = find_potential_lost_matches(
            $pdo,
            $found,
            $found
        );

        foreach ($matches as $lost) {
            $insert->execute([
                (int)$found['id'],
                (int)$lost['id'],
                (int)$lost['match_score'],
                now(),
                now()
            ]);

            if ($insert->rowCount() > 0) {
                $created++;
            }
        }
    }

    return $created;
}

function notify_user(PDO $pdo, int $userId, string $title, string $message, string $type, ?int $reportId = null, ?int $claimId = null): void {
    $pdo->prepare("INSERT INTO notifications
        (user_id, title, message, notification_type, related_report_id, related_claim_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$userId, $title, $message, $type, $reportId, $claimId, now()]);
}

function audit(PDO $pdo, int $adminId, string $action, ?string $recordType = null, ?int $recordId = null, ?string $remarks = null): void {
    $pdo->prepare("INSERT INTO audit_logs
        (admin_user_id, action, related_record_type, related_record_id, remarks, created_at)
        VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$adminId, $action, $recordType, $recordId, $remarks, now()]);
}

function add_status_history(PDO $pdo, int $reportId, ?string $previous, string $new, int $changedBy, ?string $remarks = null): void {
    $pdo->prepare("INSERT INTO status_history
        (report_id, previous_status, new_status, changed_by, remarks, created_at)
        VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$reportId, $previous, $new, $changedBy, $remarks, now()]);
}

<?php
declare(strict_types=1);

function initialize_database(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            university_id TEXT NOT NULL UNIQUE,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user' CHECK(role IN ('user','admin')),
            account_status TEXT NOT NULL DEFAULT 'active' CHECK(account_status IN ('active','inactive')),
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_name TEXT NOT NULL,
            category TEXT NOT NULL,
            description TEXT NOT NULL,
            color TEXT,
            brand TEXT,
            identifying_features TEXT,
            photo_path TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            item_id INTEGER NOT NULL,
            report_type TEXT NOT NULL CHECK(report_type IN ('lost','found')),
            event_date TEXT NOT NULL,
            event_time TEXT,
            campus_location TEXT NOT NULL,
            building TEXT,
            floor TEXT,
            specific_area TEXT,
            storage_location TEXT,
            additional_notes TEXT,
            report_status TEXT NOT NULL DEFAULT 'pending_review' CHECK(report_status IN ('pending_review','approved','rejected')),
            item_status TEXT NOT NULL DEFAULT 'open' CHECK(item_status IN ('open','matched','claim_pending','returned','closed')),
            rejection_reason TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id),
            FOREIGN KEY(item_id) REFERENCES items(id)
        );

        CREATE TABLE IF NOT EXISTS claims (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL,
            claimant_user_id INTEGER NOT NULL,
            ownership_description TEXT NOT NULL,
            identifying_information TEXT NOT NULL,
            supporting_evidence TEXT,
            claim_status TEXT NOT NULL DEFAULT 'pending' CHECK(claim_status IN ('pending','approved','rejected')),
            rejection_reason TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY(item_id) REFERENCES items(id),
            FOREIGN KEY(claimant_user_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS status_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL,
            previous_status TEXT,
            new_status TEXT NOT NULL,
            changed_by INTEGER NOT NULL,
            remarks TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY(report_id) REFERENCES reports(id),
            FOREIGN KEY(changed_by) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            notification_type TEXT NOT NULL,
            related_report_id INTEGER,
            related_claim_id INTEGER,
            is_read INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id),
            FOREIGN KEY(related_report_id) REFERENCES reports(id),
            FOREIGN KEY(related_claim_id) REFERENCES claims(id)
        );

        CREATE TABLE IF NOT EXISTS message_threads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            subject TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','closed')),
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            last_message_at TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER NOT NULL,
            sender_user_id INTEGER NOT NULL,
            message TEXT NOT NULL,
            is_read INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            FOREIGN KEY(thread_id) REFERENCES message_threads(id) ON DELETE CASCADE,
            FOREIGN KEY(sender_user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_message_threads_user ON message_threads(user_id, last_message_at);
        CREATE INDEX IF NOT EXISTS idx_messages_thread ON messages(thread_id, created_at);
        CREATE INDEX IF NOT EXISTS idx_messages_unread ON messages(sender_user_id, is_read);

        CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_user_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            related_record_type TEXT,
            related_record_id INTEGER,
            remarks TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY(admin_user_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL,
            used INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY(user_id) REFERENCES users(id)
        );

        CREATE INDEX IF NOT EXISTS idx_reports_type_status ON reports(report_type, report_status, item_status);
        CREATE INDEX IF NOT EXISTS idx_reports_location ON reports(campus_location, building);
        CREATE INDEX IF NOT EXISTS idx_claims_status ON claims(claim_status);
        CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, is_read);
    ");
}


function ensure_legacy_item_images(PDO $pdo): void {
    $seedImages = [
        'Black Backpack' => 'uploads/seed/backpack.svg',
        'Blue iPhone 13' => 'uploads/seed/iphone13.svg',
        'iPhone 13' => 'uploads/seed/iphone13.svg',
        'University ID Card' => 'uploads/seed/id-card.svg',
        'Brown Leather Wallet' => 'uploads/seed/wallet.svg',
    ];

    $stmt = $pdo->prepare("UPDATE items SET photo_path = ?, updated_at = ? WHERE id = ? AND (photo_path IS NULL OR photo_path = '')");
    $now = date('Y-m-d H:i:s');

    foreach ($seedImages as $itemName => $photoPath) {
        $rows = $pdo->prepare("SELECT id FROM items WHERE item_name = ? AND (photo_path IS NULL OR photo_path = '')");
        $rows->execute([$itemName]);

        foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $itemId) {
            $stmt->execute([$photoPath, $now, (int)$itemId]);
        }
    }

    // Give older records without a photo a clean category-based illustration.
    $categoryImages = [
        'Electronics' => 'uploads/seed/iphone13.svg',
        'Wallets & Bags' => 'uploads/seed/wallet.svg',
        'ID & Cards' => 'uploads/seed/id-card.svg',
    ];

    foreach ($categoryImages as $category => $photoPath) {
        $rows = $pdo->prepare("SELECT id FROM items WHERE category = ? AND (photo_path IS NULL OR photo_path = '')");
        $rows->execute([$category]);

        foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $itemId) {
            $stmt->execute([$photoPath, $now, (int)$itemId]);
        }
    }
}

function ensure_seed_data(PDO $pdo): void {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count > 0) return;

    $now = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("INSERT INTO users
        (full_name, university_id, email, password_hash, role, account_status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 'active', ?, ?)");

    $stmt->execute(['System Administrator', 'ADMIN-001', 'admin@findit.local', password_hash('admin123', PASSWORD_DEFAULT), 'admin', $now, $now]);
    $adminId = (int)$pdo->lastInsertId();

    $stmt->execute(['Test Student', '2026-00001', 'student@findit.local', password_hash('student123', PASSWORD_DEFAULT), 'user', $now, $now]);
    $studentId = (int)$pdo->lastInsertId();

    $items = [
        ['Black Backpack', 'Wallets & Bags', 'Black backpack with a laptop compartment and a small mountain sticker.', 'Black', 'Jansport', 'Mountain sticker on the front pocket.', null],
        ['Blue iPhone 13', 'Electronics', 'Blue iPhone with a cracked screen protector. Do not disclose private unlock information publicly.', 'Blue', 'Apple', 'Cracked screen protector; blue case.', null],
        ['University ID Card', 'ID & Cards', 'University identification card found near the main gate.', null, null, 'Plastic card with university branding.', null],
        ['Brown Leather Wallet', 'Wallets & Bags', 'Brown bifold wallet with several cards inside.', 'Brown', null, 'Small scratch near the fold.', null],
    ];

    $itemStmt = $pdo->prepare("INSERT INTO items
        (item_name, category, description, color, brand, identifying_features, photo_path, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $reportStmt = $pdo->prepare("INSERT INTO reports
        (user_id, item_id, report_type, event_date, event_time, campus_location, building, floor, specific_area,
         storage_location, additional_notes, report_status, item_status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($items as $idx => $item) {
        $itemStmt->execute([...$item, $now, $now]);
        $itemId = (int)$pdo->lastInsertId();

        $type = $idx % 2 === 0 ? 'found' : 'lost';
        $reportStmt->execute([
            $idx === 1 ? $studentId : $adminId,
            $itemId,
            $type,
            date('Y-m-d', strtotime("-{$idx} days")),
            $idx === 1 ? '13:20' : '10:15',
            $idx === 0 ? 'Library' : ($idx === 1 ? 'Student Center' : 'Main Gate'),
            $idx === 0 ? 'Engineering Building' : 'Main Campus',
            $idx === 0 ? '2' : '1',
            $idx === 0 ? 'Study Area A' : 'Lobby',
            $type === 'found' ? 'Security Office - Lost and Found Desk' : null,
            'Seed record for local development.',
            'approved',
            'open',
            $now,
            $now
        ]);
        $reportId = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO status_history (report_id, previous_status, new_status, changed_by, remarks, created_at)
                       VALUES (?, NULL, 'open', ?, 'Initial seeded record.', ?)")
            ->execute([$reportId, $adminId, $now]);
    }
}

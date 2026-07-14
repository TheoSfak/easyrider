<?php
/**
 * EasyRide - Inventory Migration v3.0.0
 * Run this script ONCE in the browser to create all inventory tables.
 * Delete this file after running.
 */

require_once __DIR__ . '/../../bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Migration v3.0.0 - Inventory';
$results = [];
$errors = [];

if (isPost()) {
    verifyCsrf();
    
    $pdo = getDb();
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // --- 1. ALTER departments ---
    $alters = [
        "ALTER TABLE `departments` ADD COLUMN `has_inventory` TINYINT(1) DEFAULT 0",
        "ALTER TABLE `departments` ADD COLUMN `inventory_settings` JSON NULL",
        "ALTER TABLE `users` ADD COLUMN `warehouse_id` INT UNSIGNED NULL AFTER `department_id`",
        "ALTER TABLE `users` ADD INDEX `idx_users_warehouse` (`warehouse_id`)",
    ];
    
    foreach ($alters as $sql) {
        try {
            $pdo->exec($sql);
            $results[] = "OK: " . substr($sql, 0, 80);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false || strpos($e->getMessage(), 'Duplicate key') !== false) {
                $results[] = "SKIP (already exists): " . substr($sql, 0, 80);
            } else {
                $errors[] = "ERROR: " . $e->getMessage();
            }
        }
    }
    
    // --- 2. CREATE TABLES ---
    $tables = [];
    
    $tables['inventory_categories'] = "CREATE TABLE IF NOT EXISTS `inventory_categories` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(255) NOT NULL,
        `description` TEXT NULL,
        `icon` VARCHAR(10) DEFAULT '📦',
        `color` VARCHAR(7) DEFAULT '#6c757d',
        `sort_order` INT DEFAULT 0,
        `is_active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_name` (`name`),
        INDEX `idx_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $tables['inventory_locations'] = "CREATE TABLE IF NOT EXISTS `inventory_locations` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(255) NOT NULL,
        `department_id` INT UNSIGNED NULL,
        `location_type` ENUM('warehouse','vehicle','room','other') DEFAULT 'warehouse',
        `address` TEXT NULL,
        `capacity` INT NULL,
        `current_items_count` INT DEFAULT 0,
        `notes` TEXT NULL,
        `is_active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
        INDEX `idx_department` (`department_id`),
        INDEX `idx_type` (`location_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $tables['inventory_items'] = "CREATE TABLE IF NOT EXISTS `inventory_items` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `barcode` VARCHAR(50) NOT NULL,
        `name` VARCHAR(255) NOT NULL,
        `description` TEXT NULL,
        `category_id` INT UNSIGNED NULL,
        `department_id` INT UNSIGNED NULL,
        `location_id` INT UNSIGNED NULL,
        `location_notes` TEXT NULL,
        `status` ENUM('available','booked','maintenance','damaged') DEFAULT 'available',
        `condition_notes` TEXT NULL,
        `booked_by_user_id` INT UNSIGNED NULL,
        `booked_by_name` VARCHAR(255) NULL,
        `booking_date` DATETIME NULL,
        `expected_return_date` DATETIME NULL,
        `quantity` INT DEFAULT 1,
        `image_url` VARCHAR(500) NULL,
        `is_active` TINYINT(1) DEFAULT 1,
        `created_by` INT UNSIGNED NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (`category_id`) REFERENCES `inventory_categories`(`id`) ON DELETE SET NULL,
        FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
        FOREIGN KEY (`location_id`) REFERENCES `inventory_locations`(`id`) ON DELETE SET NULL,
        FOREIGN KEY (`booked_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
        FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
        UNIQUE KEY `unique_barcode` (`barcode`),
        INDEX `idx_status` (`status`),
        INDEX `idx_department` (`department_id`),
        INDEX `idx_category` (`category_id`),
        INDEX `idx_location` (`location_id`),
        INDEX `idx_active` (`is_active`),
        INDEX `idx_dept_status` (`department_id`, `status`),
        FULLTEXT INDEX `idx_search` (`name`, `description`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $tables['inventory_bookings'] = "CREATE TABLE IF NOT EXISTS `inventory_bookings` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `item_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `member_name` VARCHAR(255) NULL,
        `member_phone` VARCHAR(20) NULL,
        `member_email` VARCHAR(255) NULL,
        `mission_location` VARCHAR(500) NULL,
        `booking_type` ENUM('single','bulk') DEFAULT 'single',
        `expected_return_date` DATE NULL,
        `notes` TEXT NULL,
        `status` ENUM('active','overdue','returned','lost') DEFAULT 'active',
        `return_date` DATETIME NULL,
        `returned_by_user_id` INT UNSIGNED NULL,
        `return_notes` TEXT NULL,
        `actual_hours` DECIMAL(8,2) NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`returned_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
        INDEX `idx_status` (`status`),
        INDEX `idx_item` (`item_id`),
        INDEX `idx_user` (`user_id`),
        INDEX `idx_dates` (`created_at`, `return_date`),
        INDEX `idx_status_dates` (`status`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $tables['inventory_notes'] = "CREATE TABLE IF NOT EXISTS `inventory_notes` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `item_id` INT UNSIGNED NOT NULL,
        `item_name` VARCHAR(255) NULL,
        `note_type` ENUM('booking','return','maintenance','damage','general') DEFAULT 'general',
        `content` TEXT NOT NULL,
        `priority` ENUM('low','medium','high','urgent') DEFAULT 'medium',
        `status` ENUM('pending','acknowledged','in_progress','resolved','archived') DEFAULT 'pending',
        `status_history` JSON NULL,
        `related_booking_id` INT UNSIGNED NULL,
        `assigned_to_user_id` INT UNSIGNED NULL,
        `created_by_user_id` INT UNSIGNED NULL,
        `created_by_name` VARCHAR(255) NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `resolved_at` DATETIME NULL,
        `resolved_by_user_id` INT UNSIGNED NULL,
        `resolution_notes` TEXT NULL,
        FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`related_booking_id`) REFERENCES `inventory_bookings`(`id`) ON DELETE SET NULL,
        FOREIGN KEY (`created_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
        FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
        FOREIGN KEY (`resolved_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
        INDEX `idx_status` (`status`),
        INDEX `idx_priority` (`priority`),
        INDEX `idx_item` (`item_id`),
        INDEX `idx_type` (`note_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $tables['inventory_fixed_assets'] = "CREATE TABLE IF NOT EXISTS `inventory_fixed_assets` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(255) NOT NULL,
        `barcode` VARCHAR(50) NULL,
        `description` TEXT NULL,
        `location` VARCHAR(255) NULL,
        `department_id` INT UNSIGNED NULL,
        `status` ENUM('available','checked_out','retired') DEFAULT 'available',
        `checked_out_to_user_id` INT UNSIGNED NULL,
        `checked_out_to_name` VARCHAR(255) NULL,
        `checked_out_phone` VARCHAR(20) NULL,
        `checked_out_at` DATETIME NULL,
        `checkout_notes` TEXT NULL,
        `purchase_date` DATE NULL,
        `purchase_cost` DECIMAL(10,2) NULL,
        `serial_number` VARCHAR(100) NULL,
        `condition_notes` TEXT NULL,
        `is_active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_barcode` (`barcode`),
        FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
        FOREIGN KEY (`checked_out_to_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
        INDEX `idx_status` (`status`),
        INDEX `idx_department` (`department_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $tables['inventory_department_access'] = "CREATE TABLE IF NOT EXISTS `inventory_department_access` (
        `user_id` INT UNSIGNED NOT NULL,
        `department_id` INT UNSIGNED NOT NULL,
        `access_level` ENUM('viewer','manager','admin') DEFAULT 'viewer',
        `can_book` TINYINT(1) DEFAULT 1,
        `can_manage_items` TINYINT(1) DEFAULT 0,
        `can_approve_bookings` TINYINT(1) DEFAULT 0,
        `granted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `granted_by_user_id` INT UNSIGNED NULL,
        PRIMARY KEY (`user_id`, `department_id`),
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`granted_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
        INDEX `idx_access_level` (`access_level`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    foreach ($tables as $name => $sql) {
        try {
            $pdo->exec($sql);
            $results[] = "OK: Table {$name} created";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') !== false) {
                $results[] = "SKIP: Table {$name} already exists";
            } else {
                $errors[] = "ERROR creating {$name}: " . $e->getMessage();
            }
        }
    }
    
    // --- 3. TRIGGERS ---
    $triggers = [
        'trg_booking_insert' => "CREATE TRIGGER `trg_booking_insert` AFTER INSERT ON `inventory_bookings`
FOR EACH ROW
BEGIN
    IF NEW.status = 'active' THEN
        UPDATE `inventory_items`
        SET `status` = 'booked',
            `booked_by_user_id` = NEW.user_id,
            `booked_by_name` = NEW.member_name,
            `booking_date` = NEW.created_at,
            `expected_return_date` = NEW.expected_return_date
        WHERE `id` = NEW.item_id;
    END IF;
END",
        'trg_booking_return' => "CREATE TRIGGER `trg_booking_return` AFTER UPDATE ON `inventory_bookings`
FOR EACH ROW
BEGIN
    IF OLD.status = 'active' AND NEW.status = 'returned' THEN
        UPDATE `inventory_items`
        SET `status` = 'available',
            `booked_by_user_id` = NULL,
            `booked_by_name` = NULL,
            `booking_date` = NULL,
            `expected_return_date` = NULL
        WHERE `id` = NEW.item_id;
    END IF;
END",
    ];
    
    foreach ($triggers as $name => $sql) {
        try {
            $pdo->exec("DROP TRIGGER IF EXISTS `{$name}`");
            $pdo->exec($sql);
            $results[] = "OK: Trigger {$name} created";
        } catch (PDOException $e) {
            $errors[] = "ERROR trigger {$name}: " . $e->getMessage();
        }
    }
    
    // --- 4. SEED DATA ---
    try {
        $pdo->exec("INSERT INTO `inventory_categories` (`name`, `icon`, `color`, `sort_order`) VALUES
            ('Φαρμακεία', '💊', '#dc3545', 1),
            ('Ιατρικός Εξοπλισμός', '🏥', '#28a745', 2),
            ('Επικοινωνία', '📢', '#17a2b8', 3),
            ('Σκηνές & Εξοπλισμός', '⛺', '#ffc107', 4),
            ('Εκπαίδευση', '📚', '#6c757d', 5),
            ('Ασύρματοι', '📻', '#007bff', 6),
            ('Οχήματα', '🚑', '#e83e8c', 7),
            ('Γενικά', '📦', '#6c757d', 8)
            ON DUPLICATE KEY UPDATE `sort_order` = VALUES(`sort_order`)");
        $results[] = "OK: Seed categories";
    } catch (PDOException $e) {
        $errors[] = "Seed categories: " . $e->getMessage();
    }
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    // Verify
    $allTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $inventoryTables = array_filter($allTables, function($t) { return strpos($t, 'inventory_') === 0; });
    $results[] = "---";
    $results[] = "Inventory tables found: " . implode(', ', $inventoryTables);
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <h1 class="h3 mb-4"><i class="bi bi-database-gear me-2"></i>Migration v3.0.0 - Inventory System</h1>
    
    <?php if (empty($results) && empty($errors)): ?>
        <div class="alert alert-warning">
            <h5><i class="bi bi-exclamation-triangle me-2"></i>Αυτό το script θα δημιουργήσει:</h5>
            <ul>
                <li>7 πίνακες inventory (categories, locations, items, bookings, notes, fixed_assets, department_access)</li>
                <li>2 triggers (booking insert/return)</li>
                <li>2 ALTER TABLE (departments + users)</li>
                <li>Seed data (8 κατηγορίες)</li>
            </ul>
            <p class="mb-0"><strong>Ασφαλές για επανεκτέλεση</strong> — χρησιμοποιεί IF NOT EXISTS παντού.</p>
        </div>
        <form method="post">
            <?= csrfField() ?>
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-play-fill me-2"></i>Εκτέλεση Migration
            </button>
        </form>
    <?php else: ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <h5><i class="bi bi-x-circle me-2"></i>Errors:</h5>
                <?php foreach ($errors as $e): ?>
                    <div><?= h($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <div class="alert alert-<?= empty($errors) ? 'success' : 'info' ?>">
            <h5><i class="bi bi-check-circle me-2"></i>Results:</h5>
            <?php foreach ($results as $r): ?>
                <div class="<?= strpos($r, 'OK:') === 0 ? 'text-success' : (strpos($r, 'SKIP') === 0 ? 'text-muted' : '') ?>">
                    <?= h($r) ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i><strong>Διαγράψτε αυτό το αρχείο</strong> μετά την εκτέλεση: <code>migrate_v3.php</code>
        </div>
        
        <a href="inventory.php" class="btn btn-success"><i class="bi bi-box-seam me-2"></i>Μετάβαση στα Υλικά</a>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

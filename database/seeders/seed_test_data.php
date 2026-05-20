<?php
/**
 * Seeder Script - Seed Unified Test User and Admin
 */

require_once __DIR__ . '/../../bootstrap/app.php';

use Core\Database;

try {
    $db = \Core\Container::getInstance()->make(Database::class);
    echo "Connecting to database...\n";

    $passwordHash = password_hash('123456', PASSWORD_DEFAULT);

    // 1. Seed Unified User (testuser@chortke.ir)
    $emailUser = 'testuser@chortke.ir';
    $usernameUser = 'testuser';

    $userExists = $db->fetch("SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1", [$emailUser, $usernameUser]);

    if (!$userExists) {
        $sqlInsertUser = "INSERT INTO users (username, email, password, role, status, email_verified_at, created_at, updated_at) 
                          VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())";
        $db->query($sqlInsertUser, [$usernameUser, $emailUser, $passwordHash, 'user', 'active']);
        echo "Created unified test user: testuser@chortke.ir (Password: 123456)\n";
    } else {
        $db->query("UPDATE users SET email = ?, username = ?, password = ?, status = 'active', role = 'user', email_verified_at = NOW() WHERE id = ?", [$emailUser, $usernameUser, $passwordHash, $userExists->id]);
        echo "Updated unified test user credentials: testuser@chortke.ir (Password: 123456)\n";
    }

    // 2. Seed Admin User (admin@chortke.ir)
    $emailAdmin = 'admin@chortke.ir';
    $usernameAdmin = 'admin';
    $adminExists = $db->fetch("SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1", [$emailAdmin, $usernameAdmin]);

    if (!$adminExists) {
        $sqlInsertAdmin = "INSERT INTO users (username, email, password, role, status, email_verified_at, created_at, updated_at) 
                           VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())";
        $db->query($sqlInsertAdmin, [$usernameAdmin, $emailAdmin, $passwordHash, 'admin', 'active']);
        echo "Created admin test user: admin@chortke.ir (Password: 123456)\n";
    } else {
        $db->query("UPDATE users SET email = ?, username = ?, password = ?, status = 'active', role = 'admin', email_verified_at = NOW() WHERE id = ?", [$emailAdmin, $usernameAdmin, $passwordHash, $adminExists->id]);
        echo "Updated admin test user credentials: admin@chortke.ir (Password: 123456)\n";
    }

    // 3. Create a custom task if custom_tasks table exists and is empty
    try {
        $tasksCount = $db->fetch("SELECT COUNT(*) as count FROM custom_tasks");
        if ($tasksCount && $tasksCount->count == 0) {
            $userObj = $db->fetch("SELECT id FROM users WHERE email = ? LIMIT 1", [$emailUser]);
            if ($userObj) {
                $sqlInsertTask = "INSERT INTO custom_tasks (user_id, title, description, budget, reward, status, created_at, updated_at) 
                                  VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $db->query($sqlInsertTask, [
                    $userObj->id, 
                    'تسک تستی: عضویت در کانال تلگرام چرتکه', 
                    'لطفاً برای انجام این کار به آیدی تلگرام @Chortke مراجعه کرده و عضو کانال شوید و یک شات تصویر آپلود کنید.', 
                    100000, 
                    1000, 
                    'approved'
                ]);
                echo "Pre-seeded an approved Custom Task for testing!\n";
            }
        }
    } catch (\Exception $e) {
        // Table might not exist yet, ignore
    }

    echo "\n✅ Seeding completed successfully!\n";

} catch (\Exception $e) {
    echo "❌ Seeding Error: " . $e->getMessage() . "\n";
    exit(1);
}

<?php
/**
 * Professional Database Migration Runner
 * Uses the App\Services\MigrationManager to track and execute SQL patches.
 */

declare(strict_types=1);

// Bootstrap the application environment
require_once __DIR__ . '/bootstrap/app.php';

use Core\Container;
use App\Commands\MigrationManager;

echo "\n=== Chortke Migration Runner ===\n\n";

try {
    $container = Container::getInstance();
    
    // Dynamically build/get MigrationManager using current Database connection
    $manager = new MigrationManager($container->make(\Core\Database::class));
    
    echo "Scanning for pending migrations...\n";
    
    // Check if we should show report or run migrations
    if (in_array('--report', $argv)) {
        echo $manager->report();
        exit(0);
    }

    // Execute the runner
    $result = $manager->run();
    
    if ($result['executed'] > 0) {
        echo "✅ Success: {$result['message']}\n";
    } else {
        echo "ℹ️ Notice: {$result['message']}\n";
    }

    if (!empty($result['errors'])) {
        echo "\n❌ ERRORS ENCOUNTERED:\n";
        foreach ($result['errors'] as $err) {
            echo "  - {$err}\n";
        }
        exit(1);
    }

    echo "\nDone.\n";

} catch (\Throwable $e) {
    echo "\n❌ FATAL SYSTEM CRASH:\n";
    echo $e->getMessage() . "\n";
    echo $e->getFile() . " line " . $e->getLine() . "\n";
    exit(1);
}

<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\AntiFraud\TorListUpdater;

/**
 * Command: UpdateTorExitNodesCommand
 * 
 * استفاده: php cli.php tor:update-exit-nodes
 */
class UpdateTorExitNodesCommand
{
    private TorListUpdater $updater;

    public function __construct(TorListUpdater $updater)
    {
        $this->updater = $updater;
    }

    public function run(array $argv): void
    {
        echo "Updating Tor exit nodes list...\n";
        
        $result = $this->updater->update();
        
        if (!$result['success']) {
            echo "ERROR: " . $result['message'] . "\n";
            return;
        }
        
        echo "SUCCESS: " . $result['message'] . "\n";
        echo "Last update: " . ($this->updater->getLastUpdate() ?? 'N/A') . "\n";
    }
}

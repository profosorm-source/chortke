<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';

use Core\Container;
use Core\Database;

try {
    $container = Container::getInstance();
    /** @var Database $db */
    $db = $container->make(Database::class);

    $settingKey = 'investment_default_profit_loss_percent';
    $defaultValue = '0.0';
    $defaultType = 'float';
    $defaultGroup = 'wallet';
    $defaultDescription = 'درصد پیش‌فرض سود یا ضرر سرمایه‌گذاری که برای اجرای خودکار توزیع استفاده می‌شود.';

    $columns = $db->fetchAll("SHOW COLUMNS FROM system_settings");
    $availableFields = array_map(static fn($row) => (string)($row->Field ?? ''), $columns);

    $insertFields = ['`key`', '`value`'];
    $insertValues = [$settingKey, $defaultValue];
    $updatePairs = ['`value` = ?'];
    $updateValues = [$defaultValue];

    if (in_array('type', $availableFields, true)) {
        $insertFields[] = '`type`';
        $insertValues[] = $defaultType;
        $updatePairs[] = '`type` = ?';
        $updateValues[] = $defaultType;
    }
    if (in_array('group', $availableFields, true)) {
        $insertFields[] = '`group`';
        $insertValues[] = $defaultGroup;
        $updatePairs[] = '`group` = ?';
        $updateValues[] = $defaultGroup;
    }
    if (in_array('description', $availableFields, true)) {
        $insertFields[] = '`description`';
        $insertValues[] = $defaultDescription;
        $updatePairs[] = '`description` = ?';
        $updateValues[] = $defaultDescription;
    }
    if (in_array('created_at', $availableFields, true) && in_array('updated_at', $availableFields, true)) {
        $insertFields[] = '`created_at`';
        $insertFields[] = '`updated_at`';
    }

    $row = $db->fetch("SELECT id FROM system_settings WHERE `key` = ? LIMIT 1", [$settingKey]);
    if ($row) {
        echo "Setting '{$settingKey}' already exists. Updating default values if needed...\n";

        $sql = "UPDATE system_settings SET " . implode(', ', $updatePairs);
        if (in_array('updated_at', $availableFields, true)) {
            $sql .= ", `updated_at` = NOW()";
        }
        $sql .= " WHERE id = ?";

        $db->query($sql, [...$updateValues, $row->id]);
    } else {
        echo "Creating default setting '{$settingKey}'...\n";
        $placeholders = implode(', ', array_fill(0, count($insertFields), '?'));
        $sql = "INSERT INTO system_settings (" . implode(', ', $insertFields) . ") VALUES ($placeholders";
        if (in_array('created_at', $availableFields, true) && in_array('updated_at', $availableFields, true)) {
            $sql .= ", NOW(), NOW()";
        }
        $sql .= ")";

        $db->query($sql, $insertValues);
    }

    echo "✅ Default investment setting registered successfully.\n";
    echo "Key: {$settingKey}\n";
    echo "Group: {$defaultGroup}\n";
    echo "Type: {$defaultType}\n";
    echo "Value: {$defaultValue}\n";
    echo "Description: {$defaultDescription}\n";
} catch (Throwable $e) {
    echo "❌ Failed to register the setting: " . $e->getMessage() . "\n";
    exit(1);
}

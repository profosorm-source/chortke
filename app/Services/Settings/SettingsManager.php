<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\Setting;
use App\Contracts\LoggerInterface;
use Core\Database;
use App\Events\SettingsUpdated;

class SettingsManager
{
    private Setting $model;

    private \Core\TransactionWrapper $transactionWrapper;
    private \Core\EventDispatcher $eventDispatcher;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\TransactionWrapper $transactionWrapper,
        \Core\EventDispatcher $eventDispatcher,
        \App\Contracts\LoggerInterface $logger,
        Setting $model
    ) {        $this->transactionWrapper = $transactionWrapper;
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;

                $this->model = $model;
        }

    public function set(string $key, string $value): bool
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 255) {
            throw new \InvalidArgumentException('Invalid setting key');
        }

        if (!is_string($value) || strlen($value) > 10000) {
            throw new \InvalidArgumentException('Invalid setting value');
        }

        try {
            return $this->getTransactionWrapper()->runWithRetry(function($db) use ($key, $value) {
                $db->query("SELECT id FROM system_settings WHERE `key` = ? FOR UPDATE", [$key]);
                
                $ok = $this->model->set($key, $value);
                
                if ($ok) {
                    if ($this->eventDispatcher) {
                        $this->eventDispatcher->dispatch(new SettingsUpdated([$key]));
                    }
                    return true;
                }
                
                return false;
            });

        } catch (\Throwable $e) {
            $this->logger->error('settings.set_failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function setMany(array $settings): bool
    {
        if (empty($settings)) return true;

        foreach ($settings as $key => $value) {
            if (!is_string($key) || trim($key) === '' || strlen($key) > 255) {
                throw new \InvalidArgumentException('Invalid setting key in batch');
            }

            if (!is_string($value) || strlen($value) > 10000) {
                throw new \InvalidArgumentException('Invalid setting value in batch');
            }
        }

        try {
            return $this->getTransactionWrapper()->runWithRetry(function($db) use ($settings) {
                $keys = array_keys($settings);
                $placeholders = implode(',', array_fill(0, count($keys), '?'));
                $db->query("SELECT id FROM system_settings WHERE `key` IN ($placeholders) FOR UPDATE", $keys);

                $ok = $this->model->setMany($settings);
                
                if ($ok) {
                    if ($this->eventDispatcher) {
                        $this->eventDispatcher->dispatch(new SettingsUpdated($keys));
                    }
                    return true;
                }

                return false;
            });

        } catch (\Throwable $e) {
            $this->logger->error('settings.set_many_failed', ['keys' => array_keys($settings), 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function updateById(int $id, string $key, string $value): bool
    {
        $record = $this->model->find($id);
        
        if (!$record || (string)($record->key ?? '') !== $key) {
            return false;
        }

        if (!is_string($value) || strlen($value) > 10000) {
            throw new \InvalidArgumentException('Invalid setting value');
        }

        return $this->updateValueById($id, $value, $key);
    }

    public function updateValueById(int $id, string $value, ?string $key = null): bool
    {
        $ok = $this->model->updateValueById($id, $value);
        if ($ok && $this->eventDispatcher) {
            $keys = $key ? [$key] : [];
            $this->eventDispatcher->dispatch(new SettingsUpdated($keys));
        }
        return $ok;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Contracts\LoggerInterface;

/**
 * WalletLockManager - Centralized wallet locking to prevent deadlocks
 * 
 * PROBLEM: Multiple services lock wallets simultaneously using FOR UPDATE
 * - FinancialEscrowService, WalletServiceInterface, InvestmentService, CryptoDepositService
 * - Payment callbacks + withdrawal requests on same user = deadlock risk
 * 
 * SOLUTION: Centralized lock manager with ordered locking strategy
 * - Lock ordering: Always lock wallets in ID order (prevents circular deadlocks)
 * - Single lock point: All wallet locks go through this service
 * - Timeout handling: Graceful degradation if lock not acquired
 * - Audit: Centralized logging of all wallet lock operations
 */
class WalletLockManager
{
    // Lock timeout in seconds (MySQL default)
    private const LOCK_TIMEOUT = 30;
    
    // Lock hold time (should be < LOCK_TIMEOUT)
    private const LOCK_HOLD_TIME = 10;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger
    ) {        $this->db = $db;
        $this->logger = $logger;

        
    }

    /**
     * Acquire lock on a single wallet
     * 
     * Uses FOR UPDATE with ordered locking strategy to prevent deadlocks
     * 
     * @param int $userId
     * @return bool
     * @throws \Exception If lock timeout or DB error
     */
    public function lockWallet(int $userId): bool
    {
        try {
            // Set statement timeout to prevent indefinite locks
            $this->db->query("SET SESSION innodb_lock_wait_timeout = ?", [self::LOCK_TIMEOUT]);
            
            // Lock wallet row for this user - ordered by wallet ID
            $wallet = $this->db->selectOne(
                "SELECT id FROM wallets WHERE user_id = ? ORDER BY id LIMIT 1 FOR UPDATE",
                [$userId]
            );

            if (!$wallet) {
                $this->logger->warning('wallet.lock_failed_not_found', ['user_id' => $userId]);
                return false;
            }

            $this->logger->debug('wallet.lock_acquired', [
                'user_id' => $userId,
                'wallet_id' => $wallet->id
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('wallet.lock_timeout_or_error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            throw new \Exception("Failed to acquire wallet lock for user {$userId}: " . $e->getMessage());
        }
    }

    /**
     * Acquire locks on multiple wallets in ordered fashion
     * 
     * CRITICAL: Locks are acquired in ascending ID order to prevent deadlocks
     * Multiple requests for different users won't deadlock if they both use this method
     * 
     * @param array $userIds
     * @return bool
     * @throws \Exception If any lock fails
     */
    public function lockWallets(array $userIds): bool
    {
        if (empty($userIds)) {
            return true;
        }

        // Remove duplicates and sort by user_id (creates consistent lock order)
        $userIds = array_unique(array_map('intval', $userIds));
        sort($userIds);

        try {
            $this->db->query("SET SESSION innodb_lock_wait_timeout = ?", [self::LOCK_TIMEOUT]);

            // Lock all wallets in ID order (prevents circular deadlocks)
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $wallets = $this->db->fetchAll(
                "SELECT id, user_id FROM wallets WHERE user_id IN ({$placeholders}) ORDER BY id FOR UPDATE",
                $userIds
            );

            if (count($wallets) !== count($userIds)) {
                $foundIds = array_column((array)$wallets, 'user_id');
                $missingIds = array_diff($userIds, $foundIds);
                $this->logger->warning('wallet.missing_for_users', ['missing_user_ids' => $missingIds]);
            }

            $this->logger->debug('wallet.locks_acquired', [
                'user_count' => count($userIds),
                'wallet_count' => count($wallets)
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('wallet.locks_timeout_or_error', [
                'user_count' => count($userIds),
                'error' => $e->getMessage()
            ]);
            throw new \Exception("Failed to acquire wallet locks: " . $e->getMessage());
        }
    }

    /**
     * Execute callback within wallet lock
     * 
     * Handles lock acquisition, timeout, and cleanup
     * 
     * @param int $userId
     * @param callable $callback
     * @return mixed
     */
    public function executeUnderLock(int $userId, callable $callback): mixed
    {
        try {
            $this->lockWallet($userId);
            return $callback();
        } finally {
            // Lock is automatically released when transaction ends
            // but we reset timeout just in case
            $this->db->query("SET SESSION innodb_lock_wait_timeout = 50");
        }
    }

    /**
     * Execute callback within multiple wallet locks
     * 
     * @param array $userIds
     * @param callable $callback
     * @return mixed
     */
    public function executeUnderMultipleLocks(array $userIds, callable $callback): mixed
    {
        try {
            $this->lockWallets($userIds);
            return $callback();
        } finally {
            $this->db->query("SET SESSION innodb_lock_wait_timeout = 50");
        }
    }

    /**
     * Release all locks
     * 
     * Note: In InnoDB, locks are held until transaction ends
     * This is primarily for resetting timeout and logging
     */
    public function releaseLocks(): void
    {
        try {
            $this->db->query("SET SESSION innodb_lock_wait_timeout = 50");
            $this->logger->debug('wallet.locks_released');
        } catch (\Throwable $e) {
            $this->logger->error('wallet.release_error', ['error' => $e->getMessage()]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Ads;
use App\Models\SeoExecution;
use App\Models\User;
use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use Core\TransactionWrapper;
use Core\Database;

class AdsSeoService
{
    private TransactionWrapper $transactionWrapper;
    private WalletServiceInterface $walletService;
    private Ads $adModel;
    private SeoExecution $executionModel;
    private User $userModel;
    private Database $db;
    private LoggerInterface $logger;
    private \Core\EventDispatcher $eventDispatcher;
    public function __construct(
        TransactionWrapper $transactionWrapper,
        WalletServiceInterface $walletService,
        Ads $adModel,
        SeoExecution $executionModel,
        User $userModel,
        Database $db,
        LoggerInterface $logger,
        \Core\EventDispatcher $eventDispatcher
    ) {        $this->transactionWrapper = $transactionWrapper;
        $this->walletService = $walletService;
        $this->adModel = $adModel;
        $this->executionModel = $executionModel;
        $this->userModel = $userModel;
        $this->db = $db;
        $this->logger = $logger;
        $this->eventDispatcher = $eventDispatcher;
}

    public function createAd(int $userId, array $data, float $budget, float $minPayout, float $maxPayout): array
    {
        try {
            return $this->transactionWrapper->runWithRetry(function() use ($userId, $data, $budget, $minPayout, $maxPayout) {
                $debit = $this->walletService->pay(
                    $userId,
                    (string)$budget,
                    'irt',
                    [
                        'type' => 'seo_ad',
                        'description' => 'SEO Ad: ' . $data['keyword'],
                        'ref_type' => 'seo_ad',
                    ]
                );
                
                if (empty($debit['success'])) {
                    throw new \RuntimeException($debit['message'] ?? '?????? ???? ????.');
                }
        
                $adId = $this->adModel->create([
                    'user_id' => $userId,
                    'type' => 'seo',
                    'site_url' => $data['site_url'],
                    'title' => $data['title'] ?? $data['keyword'],
                    'keyword' => $data['keyword'],
                    'description' => $data['description'] ?? null,
                    'budget' => $budget,
                    'remaining_budget' => $budget,
                    'min_payout' => $minPayout,
                    'max_payout' => $maxPayout,
                    'target_duration' => (int)($data['target_duration'] ?? 60),
                    'min_score' => (int)($data['min_score'] ?? 40),
                    'max_per_day' => (int)($data['max_per_day'] ?? 10),
                    'deadline' => !empty($data['deadline']) ? $data['deadline'] : null,
                    'status' => 'pending',
                ]);
        
                if (!$adId) {
                    throw new \RuntimeException('??? ?? ??? ???? ?? ???????.');
                }
                
                return ['success' => true, 'ad_id' => $adId];
            });
        } catch (\Exception $e) {
            $this->logger->error('seo_ad.create_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // Data access helpers (replacing the old SeoRepository)
    public function getAd(int $adId): ?object
    {
        return $this->adModel->find($adId);
    }

    public function getAdForUpdate(int $adId): ?object
    {
        return $this->adModel->findByIdForUpdate($adId);
    }

    public function createExecution(array $data): int|false
    {
        return $this->executionModel->create($data);
    }

    public function executionExistsToday(int $adId, int $userId): bool
    {
        return $this->executionModel->existsByAdAndUserToday($adId, $userId);
    }

    public function countUserExecutionsToday(int $userId): int
    {
        return $this->executionModel->countByUserToday($userId);
    }

    public function countUserExecutionsLastHour(int $userId): int
    {
        return $this->executionModel->countByUserLastHour($userId);
    }

    public function countIpExecutionsLastHour(string $ip): int
    {
        return $this->executionModel->countByIPLastHour($ip);
    }

    public function updateExecutionStatus(int $executionId, string $status): bool
    {
        return (bool)$this->db->query('UPDATE seo_executions SET status = ?, updated_at = ? WHERE id = ?', [$status, date('Y-m-d H:i:s'), $executionId]);
    }

    public function rejectExecution(int $executionId, string $reason): bool
    {
        return (bool)$this->db->query('UPDATE seo_executions SET status = ?, rejection_reason = ?, updated_at = ? WHERE id = ?', ['rejected', $reason, date('Y-m-d H:i:s'), $executionId]);
    }

    public function completeExecution(int $executionId, array $scores, float $payout): bool
    {
        return $this->executionModel->complete($executionId, $scores, $payout);
    }

    public function markExecutionAsFraud(int $executionId, array $flags): bool
    {
        return $this->executionModel->markAsFraud($executionId, $flags);
    }

    public function getUser(int $userId): ?object
    {
        return $this->userModel->findById($userId);
    }

    public function approveAd(int $adId): bool
    {
        $ad = $this->repository->getAd($adId);
        if (!$ad) return false;

        $ok = $this->repository->updateAd($adId, [
            'status' => 'active',
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if ($ok) {
            $this->logger->activity('seo_ad.approved', "???? SEO #{$adId} ????? ??", user_id(), ['ad_id' => $adId]);
            try {
                if ($this->eventDispatcher) {
                    $this->eventDispatcher->dispatchAsync('seo_ad.approved', [
                        'ad_id' => $adId,
                        'module' => 'seo_ad',
                        'type' => 'seo_ad'
                    ]);
                }
            } catch (\Throwable $evtErr) {
                $this->logger->warning('seo_ad.approved.event_failed', [
                    'ad_id' => $adId,
                    'error' => $evtErr->getMessage()
                ]);
            }
        }
        return $ok;
    }

    public function rejectAd(int $adId, string $reason): bool
    {
        $ad = $this->repository->getAd($adId);
        if (!$ad) return false;

        $ok = $this->repository->updateAd($adId, [
            'status' => 'rejected',
            'rejection_reason' => $reason ?: '???? ?? ???',
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if ($ok) {
            $this->logger->activity('seo_ad.rejected', "???? SEO #{$adId} ?? ??", user_id(), ['ad_id' => $adId, 'reason' => $reason]);
            try {
                if ($this->eventDispatcher) {
                    $this->eventDispatcher->dispatchAsync('seo_ad.rejected', [
                        'ad_id' => $adId,
                        'module' => 'seo_ad',
                        'type' => 'seo_ad'
                    ]);
                }
            } catch (\Throwable $evtErr) {
                $this->logger->warning('seo_ad.rejected.event_failed', [
                    'ad_id' => $adId,
                    'error' => $evtErr->getMessage()
                ]);
            }
        }
        return $ok;
    }

    public function pauseAd(int $adId): bool
    {
        $ad = $this->repository->getAd($adId);
        if (!$ad) return false;

        $ok = $this->repository->updateAd($adId, [
            'status' => 'paused',
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if ($ok) {
            $this->logger->activity('seo_ad.paused', "???? SEO #{$adId} ????? ??", user_id(), ['ad_id' => $adId]);
            try {
                if ($this->eventDispatcher) {
                    $this->eventDispatcher->dispatchAsync('seo_ad.paused', [
                        'ad_id' => $adId,
                        'module' => 'seo_ad',
                        'type' => 'seo_ad'
                    ]);
                }
            } catch (\Throwable $evtErr) {
                $this->logger->warning('seo_ad.paused.event_failed', [
                    'ad_id' => $adId,
                    'error' => $evtErr->getMessage()
                ]);
            }
        }
        return $ok;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Services\User\UserService;
use App\Services\WalletService;
use App\Services\TicketService;
use App\Services\WithdrawalService;
use App\Services\ManualDepositService;
use App\Services\CryptoDeposit\CryptoDepositService;
use App\Services\CustomTaskService;
use App\Services\BannerService;
use App\Services\ContentService;
use App\Services\ApiTokenService;
use App\Services\EmailService;
use App\Services\InvestmentService;
use App\Services\InfluencerService;

/**
 * 🚀 UPG-01: AdminSearchProvider - تأمین‌کننده اختصاصی جستجوی ادمین و پنل مدیریت
 */
class AdminSearchProvider extends BaseSearchProvider
{
    /**
     * جستجوی سراسری ادمین در کل جداول سیستم
     */
    public function searchAdmin(string $query, int $limit = 5): array
    {
        $this->logSearch('admin', $query, null);

        $cacheKey = "global_search_admin:" . md5($query . $limit);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($query);
        if (mb_strlen($q, 'UTF-8') < 2) {
            return $this->emptyGlobalResult();
        }

        $results = [
            'users' => $this->searchUsers($q, $limit),
            'transactions' => $this->searchTransactions($q, $limit),
            'tickets' => $this->searchTicketsGlobal($q, $limit),
            'withdrawals' => $this->searchWithdrawals($q, $limit),
            'deposits' => $this->searchDeposits($q, $limit),
            'ads' => $this->searchAds($q, $limit),
        ];

        $total = array_sum(array_map('count', $results));
        $results['total'] = $total;

        $this->cache->set($cacheKey, $results, self::CACHE_TTL_SECONDS);

        return $results;
    }

    public function searchBanners(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('banners', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('banners', array_merge(['q' => $q], $filters), $limit, $offset);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        $q = $this->sanitize($q);
        $result = $this->getService(BannerService::class)->searchBanners($q, $filters, $limit, $offset);

        $this->cache->set($cacheKey, $result, self::CACHE_TTL_SECONDS);
        return $result;
    }

    public function searchContent(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('content', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('content', array_merge(['q' => $q], $filters), $limit, $offset);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        $q = $this->sanitize($q);
        $result = $this->getService(ContentService::class)->searchContent($q, $filters, $limit, $offset);

        $this->cache->set($cacheKey, $result, self::CACHE_TTL_SECONDS);
        return $result;
    }

    public function searchTokens(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('tokens', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('tokens', array_merge(['q' => $q], $filters), $limit, $offset);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        $q = $this->sanitize($q);
        $result = $this->getService(ApiTokenService::class)->searchTokens($q, $filters, $limit, $offset);

        $this->cache->set($cacheKey, $result, self::CACHE_TTL_SECONDS);
        return $result;
    }

    public function searchEmails(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('emails', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('emails', array_merge(['q' => $q], $filters), $limit, $offset);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        $q = $this->sanitize($q);
        $result = $this->getService(EmailService::class)->searchEmails($q, $filters, $limit, $offset);

        $this->cache->set($cacheKey, $result, self::CACHE_TTL_SECONDS);
        return $result;
    }

    public function searchAdTasks(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('ad_tasks', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('ad_tasks', array_merge(['q' => $q], $filters), $limit, $offset);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        $q = $this->sanitize($q);
        $result = $this->getService(CustomTaskService::class)->searchAdTasks($q, $filters, $limit, $offset);

        $this->cache->set($cacheKey, $result, self::CACHE_TTL_SECONDS);
        return $result;
    }

    public function searchInvestments(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('investments', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('investments', array_merge(['q' => $q], $filters), $limit, $offset);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        $q = $this->sanitize($q);
        $result = $this->getService(InvestmentService::class)->searchInvestments($q, $filters, $limit, $offset);

        $this->cache->set($cacheKey, $result, self::CACHE_TTL_SECONDS);
        return $result;
    }

    public function searchTickets(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('tickets', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('tickets', array_merge(['q' => $q], $filters), $limit, $offset);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        $q = $this->sanitize($q);
        $result = $this->getService(TicketService::class)->searchTicketsAdmin($q, $filters, $limit, $offset);

        $this->cache->set($cacheKey, $result, self::CACHE_TTL_SECONDS);
        return $result;
    }

    public function searchInfluencers(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('influencers', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('influencers', array_merge(['q' => $q], $filters), $limit, $offset);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        $q = $this->sanitize($q);
        $result = $this->getService(InfluencerService::class)->searchInfluencersAdmin($q, $filters, $limit, $offset);

        $this->cache->set($cacheKey, $result, self::CACHE_TTL_SECONDS);
        return $result;
    }

    // Internal delegators

    private function searchUsers(string $q, int $limit): array
    {
        return $this->getService(UserService::class)->quickSearch($q, $limit);
    }

    private function searchTransactions(string $q, int $limit): array
    {
        return $this->getService(WalletService::class)->quickSearchTransactions($q, null, $limit);
    }

    private function searchTicketsGlobal(string $q, int $limit): array
    {
        return $this->getService(TicketService::class)->quickSearchTickets($q, null, $limit);
    }

    private function searchWithdrawals(string $q, int $limit): array
    {
        return $this->getService(WithdrawalService::class)->quickSearchWithdrawals($q, $limit);
    }

    private function searchDeposits(string $q, int $limit): array
    {
        $manual = $this->getService(ManualDepositService::class)->quickSearchManualDeposits($q, $limit);
        $crypto = $this->getService(CryptoDepositService::class)->quickSearchCryptoDeposits($q, $limit);
        
        $results = array_merge($manual, $crypto);
        usort($results, function($a, $b) {
            $dateA = is_object($a) ? ($a->created_at ?? '') : ($a['created_at'] ?? '');
            $dateB = is_object($b) ? ($b->created_at ?? '') : ($b['created_at'] ?? '');
            return strtotime((string)$dateB) <=> strtotime((string)$dateA);
        });
        
        return array_slice($results, 0, $limit);
    }

    private function searchAds(string $q, int $limit): array
    {
        return $this->getService(CustomTaskService::class)->quickSearchAds($q, null, $limit);
    }
}

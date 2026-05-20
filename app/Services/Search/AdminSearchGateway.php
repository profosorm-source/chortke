<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Services\ApiTokenService;
use App\Services\BannerService;
use App\Services\ContentService;
use App\Services\CryptoDeposit\CryptoDepositService;
use App\Services\CustomTaskService;
use App\Services\EmailService;
use App\Services\InfluencerService;
use App\Services\InvestmentService;
use App\Services\ManualDepositService;
use App\Services\TicketService;
use App\Services\User\UserService;
use App\Services\WalletService;
use App\Services\WithdrawalService;

/**
 * Explicit adapter for admin search read operations.
 *
 * This replaces the previous Service Locator usage inside AdminSearchProvider.
 * The provider now depends on a typed gateway, while this adapter makes every
 * domain dependency visible to the DI container and to tests.
 */
final class AdminSearchGateway
{
    public function __construct(
        private UserService $userService,
        private WalletService $walletService,
        private TicketService $ticketService,
        private WithdrawalService $withdrawalService,
        private ManualDepositService $manualDepositService,
        private CryptoDepositService $cryptoDepositService,
        private CustomTaskService $customTaskService,
        private BannerService $bannerService,
        private ContentService $contentService,
        private ApiTokenService $apiTokenService,
        private EmailService $emailService,
        private InvestmentService $investmentService,
        private InfluencerService $influencerService
    ) {}

    public function quickSearchUsers(string $q, int $limit): array
    {
        return $this->userService->quickSearch($q, $limit);
    }

    public function quickSearchTransactions(string $q, ?int $userId, int $limit): array
    {
        return $this->walletService->quickSearchTransactions($q, $userId, $limit);
    }

    public function quickSearchTickets(string $q, ?int $userId, int $limit): array
    {
        return $this->ticketService->quickSearchTickets($q, $userId, $limit);
    }

    public function quickSearchWithdrawals(string $q, int $limit): array
    {
        return $this->withdrawalService->quickSearchWithdrawals($q, $limit);
    }

    public function quickSearchDeposits(string $q, int $limit): array
    {
        $manual = $this->manualDepositService->quickSearchManualDeposits($q, $limit);
        $crypto = $this->cryptoDepositService->quickSearchCryptoDeposits($q, $limit);

        $results = array_merge($manual, $crypto);
        usort($results, function ($a, $b): int {
            $dateA = is_object($a) ? ($a->created_at ?? '') : ($a['created_at'] ?? '');
            $dateB = is_object($b) ? ($b->created_at ?? '') : ($b['created_at'] ?? '');
            return strtotime((string)$dateB) <=> strtotime((string)$dateA);
        });

        return array_slice($results, 0, $limit);
    }

    public function quickSearchAds(string $q, ?int $userId, int $limit): array
    {
        return $this->customTaskService->quickSearchAds($q, $userId, $limit);
    }

    public function searchBanners(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->bannerService->searchBanners($q, $filters, $limit, $offset);
    }

    public function searchContent(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->contentService->searchContent($q, $filters, $limit, $offset);
    }

    public function searchTokens(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->apiTokenService->searchTokens($q, $filters, $limit, $offset);
    }

    public function searchEmails(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->emailService->searchEmails($q, $filters, $limit, $offset);
    }

    public function searchAdTasks(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->customTaskService->searchAdTasks($q, $filters, $limit, $offset);
    }

    public function searchInvestments(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->investmentService->searchInvestments($q, $filters, $limit, $offset);
    }

    public function searchTicketsAdmin(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->ticketService->searchTicketsAdmin($q, $filters, $limit, $offset);
    }

    public function searchInfluencersAdmin(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->influencerService->searchInfluencersAdmin($q, $filters, $limit, $offset);
    }
}

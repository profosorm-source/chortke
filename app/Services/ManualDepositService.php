<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use Core\Database;
use App\Models\BankCard;
use App\Models\ManualDeposit;
use App\Services\OutboxService;

class ManualDepositService
{
    private Database $db;
    private LoggerInterface $logger;
    private ?OutboxService $outbox;

    public function __construct(Database $db, LoggerInterface $logger, ?OutboxService $outbox = null)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->outbox = $outbox;
    }

    public function listByStatus(string $status, int $limit, int $offset): array
    {
        return [];
    }

    public function listPending(int $limit, int $offset): array
    {
        return [];
    }

    public function getDeposit(int $depositId): ?object
    {
        return null;
    }

    public function getCard(int $cardId): ?BankCard
    {
        return null;
    }

    public function approve(int $adminId, int $depositId, string $note): bool
    {
        return false;
    }

    public function reject(int $adminId, int $depositId, string $reason): bool
    {
        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EmailQueue;
use App\Contracts\LoggerInterface;

class EmailQueueService extends \App\Services\BaseService
{
    private EmailQueue $emailQueueModel;

    public function __construct(EmailQueue $emailQueueModel, LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->emailQueueModel = $emailQueueModel;
    }

    public function getEmailsForAdmin(
        int $page = 1,
        int $perPage = 30,
        ?string $status = null,
        ?string $search = null
    ): array {
        $offset = ($page - 1) * $perPage;

        $emails = $this->emailQueueModel->findAllPaginated($perPage, $offset, $status, $search);
        $total = $this->emailQueueModel->countAll($status, $search);
        $stats = $this->emailQueueModel->getStats();

        return [
            'emails' => $emails,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'stats' => $stats,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }

    public function retryAllFailed(): int
    {
        return $this->emailQueueModel->retryAllFailed();
    }

    public function retryEmail(int $id): bool
    {
        return $this->emailQueueModel->retryById($id);
    }
}

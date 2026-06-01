<?php

declare(strict_types=1);

namespace App\Contracts;

interface WalletServiceInterface
{
    public function getOrCreateWallet(int $userId): ?object;
    
    public function deposit(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array;
    
    public function depositInTransaction(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array;
    
    public function withdraw(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array;

    public function withdrawInTransaction(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array;
    
    public function pay(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array;

    public function hasBalance(int $userId, string $amount, string $currency = 'irt'): bool;
    
    public function completeWithdrawal(int $userId, string $amount, string $currency, ?string $transactionId): bool;
    
    public function cancelWithdrawal(int $userId, string $amount, string $currency, ?string $transactionId): bool;

    public function reverseTransaction(string $transactionId, ?int $adminId = null, string $reason = ''): bool;
    
    public function canWithdraw(int $userId, string $amount, string $currency = 'irt'): array;
    
    public function getWalletSummary(int $userId): object;
    
    public function transfer(int $fromUserId, int $toUserId, string $amount, string $currency = 'irt', string $description = ''): ?object;
    
    public function getBalance(int $userId, string $currency = 'irt'): string;

    public function getBalanceForUpdate(int $userId, string $currency = 'irt'): string;

    public function isWalletFrozen(int $userId): bool;
}

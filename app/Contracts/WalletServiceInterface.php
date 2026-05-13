<?php

declare(strict_types=1);

namespace App\Contracts;

interface WalletServiceInterface
{
    public function getOrCreateWallet(int $userId): ?object;
    
    public function deposit(int $userId, float $amount, string $currency = 'irt', array $metadata = []): array;
    
    public function withdraw(int $userId, float $amount, string $currency = 'irt', array $metadata = []): array;
    
    public function hasBalance(int $userId, float $amount, string $currency = 'irt'): bool;
    
    public function completeWithdrawal(int $userId, float $amount, string $currency, ?string $transactionId): bool;
    
    public function cancelWithdrawal(int $userId, float $amount, string $currency, ?string $transactionId): bool;
    
    public function canWithdraw(int $userId, float $amount, string $currency = 'irt'): array;
    
    public function getWalletSummary(int $userId): object;
    
    public function transfer(int $fromUserId, int $toUserId, float $amount, string $currency = 'irt', string $description = ''): ?object;
    
    public function getBalance(int $userId, string $currency = 'irt'): float;
}

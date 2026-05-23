<?php

namespace App\Adapters;

interface CryptoVerificationAdapter
{
    /**
     * Verify a crypto transaction
     *
     * @param string $network The blockchain network (TRC20, BNB20, ERC20, etc.)
     * @param string $txHash The transaction hash
     * @param string $fromWallet Sender wallet address
     * @param string $toWallet Receiver wallet address
     * @param float $expectedAmount Expected amount in the transaction
     * @return array Verification result with 'status' and optional 'reason'
     */
    public function verify(string $network, string $txHash, string $fromWallet, string $toWallet, float $expectedAmount): array;
}


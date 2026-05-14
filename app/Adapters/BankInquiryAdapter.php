<?php

declare(strict_types=1);

namespace App\Adapters;

/**
 * Interface BankInquiryAdapter
 * Supports standardized interaction for Bank/IBAN inquires in Iran.
 */
interface BankInquiryAdapter
{
    /**
     * Inquire owner name by IBAN
     *
     * @param string $iban
     * @return array ['success' => bool, 'owner_name' => ?string, 'message' => ?string]
     */
    public function inquireIban(string $iban): array;

    /**
     * Checks if this adapter has sufficient settings/API keys to function.
     * Useful for automatic runtime fallbacks.
     *
     * @return bool
     */
    public function isConfigured(): bool;
}



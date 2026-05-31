<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\LoggerInterface;

/**
 * VandarInquiryAdapter
 * یک آداپتور جایگزین و صوری برای استعلام بانکی (به عنوان Fallback دوم)
 */
class VandarInquiryAdapter implements BankInquiryAdapter
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function inquiry(string $cardNumber): array
    {
        $this->logger->info('vandar.inquiry', ['card_number' => $cardNumber]);

        // در این نسخه صوری فرض می‌کنیم موقتاً سرویس در دسترس نیست تا زنجیره را نشان دهیم
        // در واقعیت در اینجا درخواست واقعی به API وندار ارسال می‌شود
        return [
            'success' => false,
            'message' => 'سرویس وندار در حال حاضر در دسترس نیست (تستی)'
        ];
    }
}

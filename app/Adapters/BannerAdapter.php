<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\AdSystemContract;
use App\Contracts\LoggerInterface;
use App\Contracts\ValidatorFactoryInterface;
use App\Models\Ads;
use App\Contracts\WalletServiceInterface;
use App\Services\Shared\IdempotencyService;
use Core\Database;
use App\Services\Settings\AppSettings;
use Core\Exceptions\ValidationException;

/**
 * BannerAdapter - Modernized Strategy for Centralized Ads ecosystem.
 * 
 * Supports:
 * - Multiple Placements (Slider, Sidebar, Footer, etc)
 * - Specific Dimensions & Mobile readiness
 * - Dynamic Startup Pricing Policies
 * - Budget-driven centralized consumption.
 */
class BannerAdapter extends AdapterBase implements AdSystemContract
{
    private Ads $bannerModel;
    private WalletServiceInterface $walletService;
    private Database $db;
    private IdempotencyService $idempotencyService;
    public function __construct(
        Ads $bannerModel,
        WalletServiceInterface $walletService,
        Database $db,
        LoggerInterface $logger,
        AppSettings $appSettings,
        ValidatorFactoryInterface $validatorFactory,
        IdempotencyService $idempotencyService
    ) {        $this->bannerModel = $bannerModel;
        $this->walletService = $walletService;
        $this->db = $db;
        $this->idempotencyService = $idempotencyService;

        parent::__construct($logger, $settingService, $validatorFactory);
    }

    public function getType(): string { return 'banner'; }

    public function create(int $userId, array $data): array
    {
        try {
            $this->validateData($data, [
                'title'     => 'required|string|min:3|max:100',
                'placement' => 'required|string',
                'link'      => 'required|url',
                'budget'    => 'required|numeric|min:100'
            ]);
        } catch (ValidationException $e) {
            throw new \Core\Exceptions\BusinessException('ورودی‌های بنر معتبر نیستند', $e->getErrors());
        }

        $budget = (float) ($data['budget'] ?? 0);
        $placement = $data['placement'] ?? 'unknown';
        $isStartup = filter_var($data['is_startup'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // 1. Dynamic Business Pricing Calculation
        // Homepage slider gets dynamic configurable discounts for startups as requested by User.
        $feePercent = $this->calculateDynamicFeePercent($placement, $isStartup);
        
        $totalWithFee = $budget + ($budget * $feePercent / 100);

        try {
            $this->db->beginTransaction();

            // 2. Atomic Wallet Withdrawal using centralized Strategy pattern
            $payload = [
                'user_id' => $userId,
                'amount' => $totalWithFee,
                'currency' => 'irt',
                'metadata' => [
                    'type' => 'banner_budget',
                    'placement' => $placement,
                    'amount' => $totalWithFee,
                ],
            ];

            $txId = $this->idempotencyService->executeWithTransaction(
                'banner_budget',
                $userId,
                $payload,
                function () use ($userId, $totalWithFee) {
                    return $this->walletService->withdraw($userId, $totalWithFee, 'irt', ['type' => 'banner_budget']);
                }
            );

            if (!$txId) {
                $this->db->rollBack();
                throw new \Core\Exceptions\BusinessException('موجودی کیف پول برای پرداخت هزینه بنر کافی نیست.');
            }

            // 3. Creation in Central Ads ecosystem (100% Unified)
            $bannerId = $this->bannerModel->create([
                'user_id'            => $userId,
                'type'               => 'banner',
                'title'              => $data['title'],
                'description'        => $data['description'] ?? null,
                'image_path'         => $data['image_path'] ?? null,
                'link'               => $data['link'] ?? null,
                'placement'          => $placement,
                'budget'             => $budget,
                'remaining_budget'   => $budget,
                'site_commission_percent' => $feePercent,
                'status'             => 'pending',
                'is_active'          => 0,
                'restrictions'       => json_encode([
                    'is_startup' => $isStartup,
                    'target_devices' => $data['target_devices'] ?? ['web', 'mobile'],
                    'dimensions' => $this->inferDimensions($placement),
                ])
            ]);

            $this->db->commit();
            $this->logInfo('banner_created', ['id' => $bannerId, 'user' => $userId]);

            return $this->successResponse('تبلیغ بنری با موفقیت ثبت شد و منتظر تایید است.', ['id' => $bannerId]);

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logError('banner_creation_fail', $e->getMessage());
            throw new \Core\Exceptions\BusinessException('سیستم در حال حاضر امکان ثبت بنر ندارد: ' . $e->getMessage());
        }
    }

    /**
     * Validates the ad configuration before persistence.
     */
    public function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];
        if (empty($data['title'])) $errors[] = 'عنوان تبلیغ الزامی است.';
        if (empty($data['placement'])) $errors[] = 'انتخاب جایگاه تبلیغ الزامی است.';
        if (!$isUpdate && empty($data['image_path'])) $errors[] = 'فایل تصویر بنر الزامی است.';
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    public function isExpired(int $adId): bool
    {
        $banner = $this->bannerModel->find($adId);
        if (!$banner) return true;
        
        // A budget-based banner expires when remaining_budget is exhausted.
        return (float)$banner->remaining_budget <= 0 || !$banner->is_active;
    }

    public function calculateCost(float $amount, array $context = []): float
    {
        $placement = $context['placement'] ?? 'general';
        $isStartup = filter_var($context['is_startup'] ?? false, FILTER_VALIDATE_BOOLEAN);
        
        $fee = $this->calculateDynamicFeePercent($placement, $isStartup);
        return $amount * ($fee / 100);
    }

    public function processPayment(int $adId, int $userId, float $amount, string $currency): array
    {
        // For budget based banners, general consumption handles it.
        return $this->successResponse('پرداخت خودکار با بودجه مدیریت می‌شود.');
    }

    public function track(int $adId, string $eventType, ?int $userId = null): array
    {
        // Incremental stat aggregation is best done async or through analytics pipeline,
        // but for basic tracking we update central Ads stats.
        $column = ($eventType === 'click') ? 'clicks' : 'impressions';
        
        $this->db->query("UPDATE ads SET {$column} = {$column} + 1 WHERE id = ?", [$adId]);
        
        return $this->successResponse('آمار بنر با موفقیت ثبت شد.');
    }

    public function getStatus(int $adId): ?array
    {
        $banner = $this->bannerModel->find($adId);
        if (!$banner) return null;
        
        return [
            'id' => $banner->id,
            'type' => 'banner',
            'status' => $banner->status,
            'budget_left' => $banner->remaining_budget
        ];
    }

    /**
     * Intelligently calculates site fee based on Admin Config and Startup status.
     */
    private function calculateDynamicFeePercent(string $placement, bool $isStartup): float
    {
        // Default standard fee
        $standardFee = (float) $this->appSettings->get('banner_fee_percent', 12.0);
        
        if ($placement === 'homepage_slider' && $isStartup) {
            // SPECIAL BUSINESS RULE: Ultra-cheap or Free for startups in homepage slider.
            // Fallback to 2.0% if not explicitly set in admin settings.
            return (float) $this->appSettings->get('banner_startup_slider_fee_percent', 2.0);
        }

        return $standardFee;
    }

    /**
     * Injects recommended dimensions based on target placement (ready for mobile/web scaling)
     */
    private function inferDimensions(string $placement): array
    {
        return match($placement) {
            'homepage_slider' => ['width' => 1920, 'height' => 600],
            'sidebar'        => ['width' => 300, 'height' => 600],
            'footer'         => ['width' => 728, 'height' => 90],
            default          => ['width' => 800, 'height' => 400],
        };
    }
}



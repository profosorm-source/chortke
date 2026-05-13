<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FeatureFlag;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\SettingService;

/**
 * VitrineSettingsService
 * مدیریت تنظیمات سیستم و Feature Flags برای ویترین
 */
class VitrineSettingsService extends \App\Services\BaseService
{
    private Database $db;
    private FeatureFlag $featureFlag;
    private SettingService $settingService;

    public function __construct(LoggerInterface $logger, Database $db, FeatureFlag $featureFlag, SettingService $settingService)
    {
        parent::__construct($logger);
        $this->db = $db;
        $this->featureFlag = $featureFlag;
        $this->settingService = $settingService;
    }

    /**
     * ذخیره تنظیمات ویترین
     */
    public function saveSettings(array $data): array
    {
        $fields = [
            'vitrine_commission_percent',
            'vitrine_escrow_days',
            'vitrine_kyc_required',
            'vitrine_min_price_usdt',
            'vitrine_max_price_usdt',
            'vitrine_max_active_per_user',
        ];

        try {
            $this->db->beginTransaction();

            $settingsToUpdate = [];
            foreach ($fields as $key) {
                if (array_key_exists($key, $data) && $data[$key] !== null) {
                    $settingsToUpdate[$key] = (string)$data[$key];
                }
            }

            if (!empty($settingsToUpdate)) {
                $this->settingService->setMany($settingsToUpdate);
            }

            // Feature Flag ویترین
            if (array_key_exists('vitrine_enabled', $data)) {
                $enabled = $data['vitrine_enabled'] === '1' || $data['vitrine_enabled'] === 1 ? 1 : 0;
                $this->db->prepare(
                    "UPDATE feature_flags SET enabled = ? WHERE name = 'vitrine_enabled'"
                )->execute([$enabled]);
            }

            $this->db->commit();
            return ['success' => true, 'message' => 'تنظیمات ذخیره شد.'];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا در ذخیره تنظیمات: ' . $e->getMessage()];
        }
    }

    /**
     * دریافت تمام تنظیمات ویترین
     */
    public function getSettings(): array
    {
        return [
            'commission'      => $this->settingService->get('vitrine_commission_percent', '5'),
            'escrowDays'      => $this->settingService->get('vitrine_escrow_days', '3'),
            'kycRequired'     => $this->settingService->get('vitrine_kyc_required', '1'),
            'minPrice'        => $this->settingService->get('vitrine_min_price_usdt', '1'),
            'maxPrice'        => $this->settingService->get('vitrine_max_price_usdt', '100000'),
            'maxPerUser'      => $this->settingService->get('vitrine_max_active_per_user', '5'),
            'vitrineEnabled'  => $this->featureFlag->isEnabled('vitrine_enabled'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FeatureFlag;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\Settings\AppSettings;

/**
 * VitrineSettingsService
 * مدیریت تنظیمات سیستم و Feature Flags برای ویترین
 */
class VitrineSettingsService
{

    private FeatureFlag $featureFlag;
    private AppSettings $appSettings;

    private \Core\Database $db;
    public function __construct(
        \Core\Database $db,
        FeatureFlag $featureFlag,
        AppSettings $appSettings
    )
    {        $this->db = $db;

        
        $this->featureFlag = $featureFlag;
        $this->appSettings = $appSettings;
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
                $this->appSettings->setMany($settingsToUpdate);
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
            'commission'      => $this->appSettings->get('vitrine_commission_percent', '5'),
            'escrowDays'      => $this->appSettings->get('vitrine_escrow_days', '3'),
            'kycRequired'     => $this->appSettings->get('vitrine_kyc_required', '1'),
            'minPrice'        => $this->appSettings->get('vitrine_min_price_usdt', '1'),
            'maxPrice'        => $this->appSettings->get('vitrine_max_price_usdt', '100000'),
            'maxPerUser'      => $this->appSettings->get('vitrine_max_active_per_user', '5'),
            'vitrineEnabled'  => $this->featureFlag->isEnabled('vitrine_enabled'),
        ];
    }
}

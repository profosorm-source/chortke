<?php

namespace App\Controllers\Admin;
use Core\Database;
use App\Services\User\UserService;

use App\Models\ReferralCommission;
use App\Services\Shared\ReferralService;
use App\Controllers\Admin\BaseAdminController;
use App\Contracts\WalletServiceInterface;

class ReferralController extends BaseAdminController
{
    private WalletServiceInterface $walletService;
    private ReferralService $referralService;
    private \App\Models\ReferralCommission $referralCommissionModel;
    private UserService $userService;
    private Database $db;
    public function __construct(Database $db,
        \App\Models\ReferralCommission $referralCommissionModel,
        UserService $userService,
        ReferralService $referralService,
        WalletServiceInterface $walletService, ?\App\Contracts\LoggerInterface $logger = null){
        parent::__construct(null, null, null, null, $logger);
        $this->db = $db;
        $this->referralCommissionModel = $referralCommissionModel;
        $this->userService = $userService;
        $this->referralService = $referralService;
        $this->walletService = $walletService;
    }

    /**
     * لیست کمیسیون‌ها
     */
    public function index()
    {
        $filters = [
            'status'      => $this->request->get('status'),
            'source_type' => $this->request->get('source_type'),
            'currency'    => $this->request->get('currency'),
            'search'      => $this->request->get('search'),
        ];

        $page = \max(1, (int) $this->request->get('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        $result = $this->referralService->adminList($filters, $limit, $offset);
        $commissions = $result['commissions'] ?? [];
        $total = $result['total'] ?? 0;
        
        $stats = $this->referralService->globalStats();
        $topReferrers = $this->referralService->topReferrers('irt', 5);

        $this->logger->activity('referrals.view', 'مشاهده لیست کمیسیون‌ها', user_id(), []);

        return view('admin.referral.index', [
            'commissions'   => $commissions,
            'total'         => $total,
            'page'          => $page,
            'pages'         => \ceil($total / $limit),
            'filters'       => $filters,
            'stats'         => $stats,
            'topReferrers'  => $topReferrers,
            'sourceTypes'   => $this->referralService->getSourceTypes(),
        ]);
    }

    /**
     * تنظیمات کمیسیون
     */
    public function settings()
    {

        return view('admin.referral.settings', [
            'sourceTypes' => $this->referralService->getSourceTypes(),
        ]);
    }

    /**
     * ذخیره تنظیمات کمیسیون
     */
    public function saveSettings()
    {

                        


        $settingsKeys = [
            'referral_commission_task_percent',
            'referral_commission_investment_percent',
            'referral_commission_vip_percent',
            'referral_commission_story_percent',
            'referral_commission_enabled',
            'referral_commission_min_payout',
            'referral_commission_min_payout_usdt',
            'referral_commission_auto_pay',
            'referral_max_daily_signups',
            'referral_farming_threshold',
            'referral_farming_action',
            'referral_signup_bonus',
            'referral_signup_bonus_usdt',
        ];

        $updated = [];
        $settings = [];

        foreach ($settingsKeys as $key) {
            $value = $this->request->post($key);
            if ($value !== null) {
                $updated[$key] = $value;
                $settings[$key] = (string)$value;
            }
        }

        $this->referralService->saveSettings($settings);
        $this->logger->activity('referrals.settings_updated', 'بروزرسانی تنظیمات کمیسیون', $updated, []);

        $this->session->setFlash('success', 'تنظیمات کمیسیون با موفقیت ذخیره شد.');
        return redirect(url('/admin/referral/settings'));
    }

    /**
     * جزئیات کمیسیون‌های یک کاربر
     */
    public function userDetail()
    {
        $userId = (int) $this->request->param('id');

        $user = $this->userService->find($userId);

        if (!$user) {
            \http_response_code(404);
            include __DIR__ . '/../../../views/errors/404.php';
            exit;
        }

        $stats = $this->referralService->getReferrerStats($userId);
        $referredUsers = $this->referralService->getReferredUsers($userId, 50, 0);
        $commissions = $this->referralService->getByReferrer($userId, [], 50, 0);
        $referredCount = $this->referralService->countReferredUsers($userId);

        return view('admin.referral.user-detail', [
            'user'          => $user,
            'stats'         => $stats,
            'referredUsers' => $referredUsers,
            'commissions'   => $commissions,
            'referredCount' => $referredCount,
        ]);
    }

    /**
     * لغو کمیسیون (Ajax)
     */
    public function cancel()
    {

                
        $id = (int) $this->request->param('id');
        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $reason = $body['reason'] ?? 'لغو توسط مدیر';

        $service = $this->referralService;
        $cancelled = $service->cancelCommission($id, $reason);

        if (!$cancelled) {
            $this->response->json([
                'success' => false,
                'message' => 'امکان لغو این کمیسیون وجود ندارد. فقط کمیسیون‌های در انتظار قابل لغو هستند.',
            ], 422);
            return;
        }

        $this->logger->activity('referrals.commission_cancelled', 'لغو کمیسیون', user_id(), [
            'commission_id' => $id,
            'reason'        => $reason,
        ]);

        $this->response->json([
            'success' => true,
            'message' => 'کمیسیون با موفقیت لغو شد.',
        ]);
    }

    /**
     * پرداخت دسته‌ای (Ajax)
     */
    public function batchPay()
    {
        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $currency = $body['currency'] ?? 'irt';

        if (!\in_array($currency, ['irt', 'usdt'])) {
            $this->response->json(['success' => false, 'message' => 'ارز نامعتبر'], 422);
            return;
        }

        $service = $this->referralService;
        $results = $service->batchPay($currency);

        if (!empty($results['locked'])) {
            $this->response->json([
                'success' => false,
                'message' => 'پرداخت دسته‌ای برای این ارز در حال حاضر در حال اجراست. لطفا چند لحظه دیگر دوباره تلاش کنید.'
            ], 409);
            return;
        }

        $this->logger->activity('referrals.batch_pay', 'پرداخت دسته‌ای کمیسیون', $results, []);

        $this->response->json([
            'success' => true,
            'message' => "پرداخت دسته‌ای انجام شد: {$results['success']} موفق، {$results['failed']} ناموفق، {$results['skipped']} رد شده",
            'results' => $results,
        ]);
    }
}
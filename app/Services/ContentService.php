<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContentSubmission;
use App\Models\ContentRevenue;
use App\Models\ContentAgreement;
use App\Services\WalletService;
use App\Services\Notification\NotificationService;
use Core\Cache;
use Core\TransactionWrapper;
use Core\EventDispatcher;
use App\Contracts\LoggerInterface;
use Core\Exceptions\BusinessException;
use App\Services\SettingService;

/**
 * سرویس مدیریت محتوا
 * 
 * @package App\Services
 */
class ContentService extends \App\Services\BaseService
{
    // Constants برای business rules
    private const MAX_PENDING_SUBMISSIONS = 1;
    private const CACHE_TTL_STATS = 300; // 5 minutes
    private const PROFESSIONAL_TIER_MONTHS = 12;
    private const PROFESSIONAL_TIER_SUBMISSIONS = 10;
    private const PROFESSIONAL_BONUS_PERCENT = 10;
    private const PROFESSIONAL_MAX_PERCENT = 80;
    private const ACTIVE_TIER_MONTHS = 6;
    private const ACTIVE_TIER_SUBMISSIONS = 5;
    private const ACTIVE_BONUS_PERCENT = 5;
    private const ACTIVE_MAX_PERCENT = 75;

    private WalletService $walletService;
    private NotificationService $notificationService;
    private Cache $cache;
    private ContentSubmission $submissionModel;
    private ContentRevenue $revenueModel;
    private ContentAgreement $agreementModel;
    private TransactionWrapper $transactionWrapper;
    private EventDispatcher $eventDispatcher;
    private SettingService $settingService;
    // متن تعهدنامه
    private const AGREEMENT_TEXT = <<<EOT
تعهدنامه همکاری محتوایی با مجموعه چرتکه

اینجانب با آگاهی کامل از شرایط زیر، محتوای خود را برای انتشار در کانال‌های مجموعه ارسال می‌نمایم:

۱. تمامی محتوای ارسالی متعلق به مجموعه چرتکه خواهد بود و حق انتشار، ویرایش و حذف آن با مجموعه است.
۲. حتی در صورت خروج، بن شدن یا عدم فعالیت در سایت، حق شکایت از مجموعه بابت محتوای منتشرشده را ندارم.
۳. حق حذف، گزارش یا شکایت از محتوای منتشرشده در یوتیوب، آپارات یا سایر شبکه‌ها را ندارم.
۴. درآمد حاصل از محتوا بر اساس نسبت تعیین‌شده بین من و مجموعه تقسیم خواهد شد.
۵. دو ماه اول پس از تأیید، هیچ سودی تعلق نمی‌گیرد.
۶. محتوای ارسالی باید اصل و متعلق به خودم باشد. در صورت کپی بودن، مسئولیت قانونی با اینجانب است.
۷. در صورت تخلف، مجموعه حق تعلیق یا مسدودسازی حساب و توقف پرداخت‌ها را دارد.

با تأیید این تعهدنامه، تمام شرایط فوق را می‌پذیرم.
EOT;

    public function __construct(
        WalletService $walletService,
        NotificationService $notificationService,
        ContentSubmission $submissionModel,
        ContentRevenue $revenueModel,
        ContentAgreement $agreementModel,
        TransactionWrapper $transactionWrapper,
        EventDispatcher $eventDispatcher,
        LoggerInterface $logger,
        Cache $cache,
        SettingService $settingService
    ) {
        parent::__construct($logger);
        $this->submissionModel = $submissionModel;
        $this->revenueModel = $revenueModel;
        $this->agreementModel = $agreementModel;
        $this->walletService = $walletService;
        $this->notificationService = $notificationService;
        $this->transactionWrapper = $transactionWrapper;
        $this->eventDispatcher = $eventDispatcher;
        $this->cache = $cache;
        $this->settingService = $settingService;
    }

    /**
     * ارسال محتوای جدید
     * 
     * @param int $userId
     * @param array $data
     * @return array
     * @throws BusinessException
     */
    public function submitContent(int $userId, array $data): array
    {
        try {
            // بررسی محدودیت محتوای در انتظار
            if ($this->hasMaxPendingSubmissions($userId)) {
                return $this->errorResponse(
                    'شما حداکثر تعداد مجاز محتوای در انتظار را دارید. لطفاً تا تعیین وضعیت آن‌ها صبر کنید.'
                );
            }

            // Validate platform
            if (!$this->isValidPlatform($data['platform'])) {
                return $this->errorResponse('پلتفرم انتخابی نامعتبر است.');
            }

            // Validate & sanitize URL
            $videoUrl = $this->sanitizeUrl($data['video_url']);
            if (!$this->validateVideoUrl($videoUrl, $data['platform'])) {
                return $this->errorResponse(
                    sprintf(
                        'لینک ویدیو نامعتبر است. لطفاً لینک صحیح از %s وارد کنید.',
                        $data['platform']
                    )
                );
            }

            // Check duplicate URL
            if ($this->submissionModel->isUrlExists($videoUrl)) {
                return $this->errorResponse('این لینک ویدیو قبلاً ثبت شده است.');
            }

            // Validate agreement
            if (empty($data['agreement_accepted'])) {
                return $this->errorResponse('لطفاً تعهدنامه همکاری را بخوانید و تأیید کنید.');
            }

            // Create submission and agreement in transaction
            $result = $this->transactionWrapper->run(function() use ($userId, $videoUrl, $data) {
                // Create submission
                $submissionId = $this->createSubmission($userId, $videoUrl, $data);

                if (!$submissionId) {
                    throw new BusinessException('خطا در ثبت محتوا.');
                }

                // Create agreement record
                $this->createAgreement($userId, $submissionId);

                return $submissionId;
            });

            $submissionId = $result;

            // Dispatch async event for content submission
            $this->eventDispatcher->dispatchAsync('content.submitted', [
                'submission_id' => $submissionId,
                'user_id' => $userId,
                'platform' => $data['platform'],
            ]);

            // Log activity
            $this->logInfo('content_submission', ['message' => "User {$userId} submitted content #{$submissionId}"]);

            // Clear cache
            $this->clearUserCache($userId);

            return $this->successResponse(
                'محتوای شما با موفقیت ثبت شد و در صف بررسی قرار گرفت.',
                ['submission_id' => $submissionId]
            );
            
        } catch (BusinessException $e) {
            $this->logError('content.submission.business_failed', [
                'user_id'   => $userId,
                'error'     => $e->getMessage(),
                'exception' => \get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            $this->logError('content.submission.unexpected_failed', [
                'user_id'   => $userId,
                'error'     => $e->getMessage(),
                'exception' => \get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
            return $this->errorResponse('خطا در ثبت محتوا. لطفاً دوباره تلاش کنید.');
        }
    }

    /**
     * تأیید محتوا (ادمین)
     * 
     * @param int $submissionId
     * @param int $adminId
     * @return array
     */
    public function approveSubmission(int $submissionId, int $adminId): array
    {
        try {
            $submission = $this->submissionModel->find($submissionId);
            
            if (!$submission) {
                return $this->errorResponse('محتوا یافت نشد.');
            }

            if (!$this->canBeApproved($submission->status)) {
                return $this->errorResponse('وضعیت محتوا اجازه تأیید را نمی‌دهد.');
            }

            $now = date('Y-m-d H:i:s');
            
            $this->submissionModel->update($submissionId, [
                'status' => ContentSubmission::STATUS_APPROVED,
                'approved_at' => $now,
                'approved_by' => $adminId,
            ]);

            // Send notification
            $this->sendNotification(
                $submission->user_id,
                'محتوای شما تأیید شد',
                sprintf(
                    'محتوای «%s» تأیید شد. پس از انتشار در کانال‌های مجموعه، درآمد شما محاسبه خواهد شد.',
                    $this->escapeText($submission->title)
                ),
                'content_approved'
            );

            $this->eventDispatcher->dispatchAsync('content.approved', [
                'submission_id' => $submissionId,
                'user_id' => $submission->user_id,
                'approved_by' => $adminId,
            ]);

            $this->logInfo('content_approval', ['message' => "Admin {$adminId} approved content #{$submissionId}"]);
            $this->clearUserCache($submission->user_id);

            return $this->successResponse('محتوا با موفقیت تأیید شد.');
            
        } catch (\Throwable $e) {
            $this->logError('content.approval.failed', [
                'submission_id' => $submissionId,
                'admin_id'      => $adminId,
                'error'         => $e->getMessage(),
                'exception'     => \get_class($e),
                'file'          => $e->getFile(),
                'line'          => $e->getLine(),
            ]);
            return $this->errorResponse('خطا در تأیید محتوا.');
        }
    }

    /**
     * رد محتوا (ادمین)
     * 
     * @param int $submissionId
     * @param int $adminId
     * @param string $reason
     * @return array
     */
    public function rejectSubmission(int $submissionId, int $adminId, string $reason): array
    {
        try {
            $submission = $this->submissionModel->find($submissionId);
            
            if (!$submission) {
                return $this->errorResponse('محتوا یافت نشد.');
            }

            if (!$this->canBeRejected($submission->status)) {
                return $this->errorResponse('وضعیت محتوا اجازه رد را نمی‌دهد.');
            }

            // Sanitize reason
            $reason = $this->sanitizeText($reason);

            $this->submissionModel->update($submissionId, [
                'status' => ContentSubmission::STATUS_REJECTED,
                'rejection_reason' => $reason,
                'rejected_by' => $adminId,
                'rejected_at' => date('Y-m-d H:i:s'),
            ]);

            // Send notification
            $this->sendNotification(
                $submission->user_id,
                'محتوای شما رد شد',
                sprintf(
                    "محتوای «%s» رد شد.\nدلیل: %s",
                    $this->escapeText($submission->title),
                    $this->escapeText($reason)
                ),
                'content_rejected'
            );

            $this->logInfo('content_rejection', ['message' => "Admin {$adminId} rejected content #{$submissionId}: {$reason}"]);
            $this->clearUserCache($submission->user_id);

            return $this->successResponse('محتوا رد شد.');
            
        } catch (\Throwable $e) {
            $this->logError('content.rejection.failed', [
                'submission_id' => $submissionId,
                'admin_id'      => $adminId,
                'error'         => $e->getMessage(),
                'exception'     => \get_class($e),
                'file'          => $e->getFile(),
                'line'          => $e->getLine(),
            ]);
            return $this->errorResponse('خطا در رد محتوا.');
        }
    }

    /**
     * انتشار محتوا (ادمین)
     * 
     * @param int $submissionId
     * @param int $adminId
     * @param string $publishedUrl
     * @return array
     */
    public function publishSubmission(int $submissionId, int $adminId, string $publishedUrl): array
    {
        try {
            $submission = $this->submissionModel->find($submissionId);
            
            if (!$submission) {
                return $this->errorResponse('محتوا یافت نشد.');
            }

            if ($submission->status !== ContentSubmission::STATUS_APPROVED) {
                return $this->errorResponse('فقط محتوای تأیید شده قابل انتشار است.');
            }

            // Validate URL
            $publishedUrl = filter_var($publishedUrl, FILTER_SANITIZE_URL);
            if (!filter_var($publishedUrl, FILTER_VALIDATE_URL)) {
                return $this->errorResponse('لینک انتشار نامعتبر است.');
            }

            $now = date('Y-m-d H:i:s');
            
            $this->submissionModel->update($submissionId, [
                'status' => ContentSubmission::STATUS_PUBLISHED,
                'published_at' => $now,
                'published_url' => $publishedUrl,
                'published_by' => $adminId,
            ]);

            // Send notification
            $this->sendNotification(
                $submission->user_id,
                'محتوای شما منتشر شد',
                sprintf(
                    'محتوای «%s» در کانال مجموعه منتشر شد. از ماه سوم درآمد شما محاسبه خواهد شد.',
                    $this->escapeText($submission->title)
                ),
                'content_published'
            );

            $this->logInfo('content_publish', ['message' => "Admin {$adminId} published content #{$submissionId}"]);
            $this->clearUserCache($submission->user_id);

            return $this->successResponse('محتوا با موفقیت منتشر شد.');
            
        } catch (\Throwable $e) {
            $this->logError('content.publish.failed', [
                'submission_id' => $submissionId,
                'admin_id'      => $adminId,
                'error'         => $e->getMessage(),
                'exception'     => \get_class($e),
                'file'          => $e->getFile(),
                'line'          => $e->getLine(),
            ]);
            return $this->errorResponse('خطا در انتشار محتوا.');
        }
    }

    /**
     * ثبت درآمد محتوا (ادمین)
     * 
     * @param int $submissionId
     * @param int $adminId
     * @param array $data
     * @return array
     */
    public function recordRevenue(int $submissionId, int $adminId, array $data): array
    {
        try {
            $submission = $this->submissionModel->find($submissionId);
            
            if (!$submission) {
                return $this->errorResponse('محتوا یافت نشد.');
            }

            if ($submission->status !== ContentSubmission::STATUS_PUBLISHED) {
                return $this->errorResponse('فقط برای محتوای منتشرشده می‌توان درآمد ثبت کرد.');
            }

            // Check minimum active months
            $activeMonths = $this->submissionModel->getActiveMonths($submission->user_id);
            if ($activeMonths < ContentSubmission::MIN_MONTHS_FOR_REVENUE) {
                $remaining = ContentSubmission::MIN_MONTHS_FOR_REVENUE - $activeMonths;
                return $this->errorResponse(
                    sprintf('کاربر هنوز به حداقل زمان فعالیت نرسیده. %d ماه دیگر باقی مانده.', $remaining)
                );
            }

            // Validate period format (YYYY-MM)
            $period = $this->validatePeriod($data['period']);
            if (!$period) {
                return $this->errorResponse('فرمت دوره نامعتبر است. (مثال: 1404-01)');
            }

            // Check duplicate period
            if ($this->revenueModel->existsForPeriod($submissionId, $period)) {
                return $this->errorResponse("درآمد برای دوره {$period} قبلاً ثبت شده است.");
            }

            // Calculate shares
            $revenueData = $this->calculateRevenue($submission->user_id, $data);

            // Create revenue record
            $revenueId = $this->revenueModel->create(array_merge($revenueData, [
                'submission_id' => $submissionId,
                'user_id' => $submission->user_id,
                'period' => $period,
                'views' => (int)($data['views'] ?? 0),
                'status' => ContentRevenue::STATUS_PENDING,
                'created_by' => $adminId,
            ]));

            if (!$revenueId) {
                throw new BusinessException('خطا در ثبت درآمد.');
            }

            // Send notification
            $this->sendRevenueNotification($submission, $revenueData, $period);

            $this->logInfo('content_revenue', ['message' => "Admin {$adminId} added revenue #{$revenueId} for content #{$submissionId}"]);
            $this->clearUserCache($submission->user_id);

            return $this->successResponse('درآمد با موفقیت ثبت شد.', ['revenue_id' => $revenueId]);
            
        } catch (BusinessException $e) {
            $this->logError('content.revenue.business_failed', [
                'submission_id' => $submissionId,
                'admin_id'      => $adminId,
                'error'         => $e->getMessage(),
                'exception'     => \get_class($e),
                'file'          => $e->getFile(),
                'line'          => $e->getLine(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            $this->logError('content.revenue.unexpected_failed', [
                'submission_id' => $submissionId,
                'admin_id'      => $adminId,
                'error'         => $e->getMessage(),
                'exception'     => \get_class($e),
                'file'          => $e->getFile(),
                'line'          => $e->getLine(),
            ]);
            return $this->errorResponse('خطا در ثبت درآمد.');
        }
    }

    /**
     * پرداخت درآمد به کیف پول کاربر (ادمین)
     * 
     * @param int $revenueId
     * @param int $adminId
     * @return array
     */
    public function payRevenue(int $revenueId, int $adminId): array
    {
        try {
            $this->db->beginTransaction();
            $revenue = $this->db->query("SELECT * FROM content_revenues WHERE id = ? FOR UPDATE", [$revenueId])->fetch(\PDO::FETCH_OBJ);
            
            if (!$revenue) {
                $this->db->rollBack();
                return $this->errorResponse('رکورد درآمد یافت نشد.');
            }

            if ($revenue->status !== \App\Models\ContentRevenue::STATUS_APPROVED) {
                $this->db->rollBack();
                return $this->errorResponse('فقط درآمدهای تأیید شده قابل پرداخت هستند.');
            }

            $currency = $revenue->currency === 'usdt' ? 'usdt' : 'irt';

            $depositResult = $this->walletService->deposit(
                $revenue->user_id,
                $revenue->net_user_amount,
                $currency,
                [
                    'type' => 'content_revenue',
                    'revenue_id' => $revenueId,
                    'submission_id' => $revenue->submission_id,
                    'period' => $revenue->period,
                    'description' => sprintf(
                        'درآمد محتوا - دوره %s - %s',
                        $revenue->period,
                        $this->escapeText($revenue->video_title ?? '')
                    )
                ]
            );

            if (empty($depositResult['success'])) {
                $this->db->rollBack();
                return $this->errorResponse(
                    'خطا در واریز به کیف پول: ' . ($depositResult['message'] ?? '')
                );
            }

            $this->revenueModel->update($revenueId, [
                'status'         => \App\Models\ContentRevenue::STATUS_PAID,
                'paid_at'        => date('Y-m-d H:i:s'),
                'transaction_id' => $depositResult['transaction_id'] ?? null,
                'paid_by_admin'  => $adminId,
            ]);
            
            // پورسانت ریفرال تولید محتوا
            $userRecord = \App\Core\Container::getInstance()->get(\App\Models\User::class)->findById($revenue->user_id);
            if ($userRecord && !empty($userRecord->referred_by)) {
                $referralService = \App\Core\Container::getInstance()->get(\App\Services\Shared\ReferralService::class);
                if ($referralService) {
                    $referralService->processCommission((int)$userRecord->referred_by, (float)$revenue->net_user_amount, $currency, [
                        'action' => 'content_revenue_reward',
                        'creator_id' => $revenue->user_id,
                        'revenue_id' => $revenueId
                    ]);
                }
            }
            
            $this->db->commit();

            $this->clearUserCache((int)$revenue->user_id);

            $this->notificationService->send(
                (int)$revenue->user_id,
                \App\Models\Notification::TYPE_SUCCESS,
                'پرداخت درآمد محتوا',
                sprintf(
                    'درآمد شما به مبلغ %s %s بابت دوره %s به کیف پول واریز شد.',
                    number_format((float)$revenue->net_user_amount, $currency === 'usdt' ? 2 : 0),
                    $currency === 'usdt' ? 'USDT' : 'تومان',
                    $revenue->period
                ),
                ['action_url' => url("/user/content/revenues")]
            );

            return $this->successResponse('درآمد با موفقیت پرداخت شد.');
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logError('content.revenue.pay_failed', [
                'revenue_id' => $revenueId,
                'error'      => $e->getMessage()
            ]);
            return $this->errorResponse('خطای سیستمی.');
        }
    }
    public function suspendSubmission(int $submissionId, int $adminId, string $reason): array
    {
        try {
            $submission = $this->submissionModel->find($submissionId);
            
            if (!$submission) {
                return $this->errorResponse('محتوا یافت نشد.');
            }

            $reason = $this->sanitizeText($reason);

            $this->submissionModel->update($submissionId, [
                'status' => ContentSubmission::STATUS_SUSPENDED,
                'rejection_reason' => $reason,
                'suspended_by' => $adminId,
                'suspended_at' => date('Y-m-d H:i:s'),
            ]);

            $this->sendNotification(
                $submission->user_id,
                'محتوای شما تعلیق شد',
                sprintf(
                    "محتوای «%s» تعلیق شد.\nدلیل: %s",
                    $this->escapeText($submission->title),
                    $this->escapeText($reason)
                ),
                'content_suspended'
            );

            $this->logInfo('content_suspended', ['message' => "Admin {$adminId} suspended content #{$submissionId}: {$reason}"]);
            $this->clearUserCache($submission->user_id);

            return $this->successResponse('محتوا تعلیق شد.');
            
        } catch (\Throwable $e) {
            $this->logError('content.suspension.failed', [
                'submission_id' => $submissionId,
                'admin_id'      => $adminId,
                'error'         => $e->getMessage(),
                'exception'     => \get_class($e),
                'file'          => $e->getFile(),
                'line'          => $e->getLine(),
            ]);
            return $this->errorResponse('خطا در تعلیق محتوا.');
        }
    }

    /**
     * دریافت متن تعهدنامه
     * 
     * @return string
     */
    public function getAgreementText(): string
    {
        return self::AGREEMENT_TEXT;
    }

    /**
     * دریافت تنظیمات محتوا
     * 
     * @return array
     */
    public function getSettings(): array
    {
        return [
            'site_share_percent' => (float)$this->settingService->get('content_site_share_percent', 40),
            'tax_percent' => (float)$this->settingService->get('content_tax_percent', 9),
            'min_months' => ContentSubmission::MIN_MONTHS_FOR_REVENUE,
            'allowed_platforms' => ContentSubmission::ALLOWED_PLATFORMS,
            'max_pending' => (int)$this->settingService->get('content_max_pending_submissions', 1),
        ];
    }

    // ============ Private Helper Methods ============

    /**
     * بررسی تعداد محتوای در انتظار
     * 
     * @param int $userId
     * @return bool
     */
    private function hasMaxPendingSubmissions(int $userId): bool
    {
        $limit = (int)$this->settingService->get('content_max_pending_submissions', 1);
        return $this->submissionModel->countByUser(
            $userId,
            ContentSubmission::STATUS_PENDING
        ) >= $limit;
    }

    /**
     * بررسی اعتبار پلتفرم
     * 
     * @param string $platform
     * @return bool
     */
    private function isValidPlatform(string $platform): bool
    {
        return in_array($platform, ContentSubmission::ALLOWED_PLATFORMS, true);
    }

    /**
     * Sanitize URL
     * 
     * @param string $url
     * @return string
     */
    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }

    /**
     * Sanitize text
     * 
     * @param string $text
     * @return string
     */
    private function sanitizeText(string $text): string
    {
        return e(trim($text), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Escape text for display
     * 
     * @param string $text
     * @return string
     */
    private function escapeText(string $text): string
    {
        return e($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * بررسی اعتبار URL ویدیو
     * 
     * @param string $url
     * @param string $platform
     * @return bool
     */
    private function validateVideoUrl(string $url, string $platform): bool
    {
        if (empty($url)) {
            return false;
        }

        // اگر آپلودسنتر باشد، هر لینک معتبری قابل قبول است
        if ($platform === ContentSubmission::PLATFORM_UPLOAD_CENTER) {
            return (bool)filter_var($url, FILTER_VALIDATE_URL);
        }

        if ($platform === ContentSubmission::PLATFORM_APARAT) {
            return (bool)preg_match('/^https?:\/\/(www\.)?aparat\.com\/v\//i', $url);
        }

        if ($platform === ContentSubmission::PLATFORM_YOUTUBE) {
            return (bool)preg_match(
                '/^https?:\/\/(www\.)?(youtube\.com\/watch\?v=|youtu\.be\/)/i',
                $url
            );
        }

        return false;
    }

    /**
     * Validate period format
     * 
     * @param string $period
     * @return string|false
     */
    private function validatePeriod(string $period)
    {
        if (preg_match('/^\d{4}-\d{2}$/', $period)) {
            return $period;
        }
        return false;
    }

    /**
     * ایجاد رکورد submission
     * 
     * @param int $userId
     * @param string $videoUrl
     * @param array $data
     * @return int|null
     */
    private function createSubmission(int $userId, string $videoUrl, array $data): ?int
    {
        return $this->submissionModel->create([
            'user_id' => $userId,
            'platform' => $data['platform'],
            'video_url' => $videoUrl,
            'title' => $this->sanitizeText($data['title']),
            'description' => $this->sanitizeText($data['description'] ?? ''),
            'category' => $this->sanitizeText($data['category'] ?? ''),
            'agreement_accepted' => 1,
            'agreement_accepted_at' => date('Y-m-d H:i:s'),
            'agreement_ip' => get_client_ip(),
            'agreement_fingerprint' => generate_device_fingerprint(),
        ]);
    }

    /**
     * ایجاد رکورد agreement
     * 
     * @param int $userId
     * @param int $submissionId
     * @return void
     */
    private function createAgreement(int $userId, int $submissionId): void
    {
        $this->agreementModel->create([
            'user_id' => $userId,
            'submission_id' => $submissionId,
            'agreement_text' => self::AGREEMENT_TEXT,
            'ip_address' => get_client_ip(),
            'user_agent' => get_user_agent(),
            'device_fingerprint' => generate_device_fingerprint(),
        ]);
    }

    /**
     * محاسبه درآمد
     * 
     * @param int $userId
     * @param array $data
     * @return array
     */
    private function calculateRevenue(int $userId, array $data): array
    {
        $totalRevenue = (float)($data['total_revenue'] ?? 0);

        // Get settings
        $siteSharePercent = (float)$this->settingService->get('content_site_share_percent', 40);
        $taxPercent = (float)$this->settingService->get('content_tax_percent', 9);

        // Calculate user share percent based on tier
        $userSharePercent = $this->calculateUserSharePercent($userId, $siteSharePercent);

        // Calculate amounts
        $siteShareAmount = round($totalRevenue * ($siteSharePercent / 100), 2);
        $userShareAmount = round($totalRevenue * ($userSharePercent / 100), 2);
        $taxAmount = round($userShareAmount * ($taxPercent / 100), 2);
        $netUserAmount = round($userShareAmount - $taxAmount, 2);

        // Determine currency
        $currency = $this->settingService->get('currency_mode', 'irt') === 'usdt' ? 'usdt' : 'irt';

        return [
            'total_revenue' => $totalRevenue,
            'site_share_percent' => $siteSharePercent,
            'site_share_amount' => $siteShareAmount,
            'user_share_percent' => $userSharePercent,
            'user_share_amount' => $userShareAmount,
            'tax_percent' => $taxPercent,
            'tax_amount' => $taxAmount,
            'net_user_amount' => $netUserAmount,
            'currency' => $currency,
        ];
    }

    /**
     * محاسبه درصد سهم کاربر بر اساس سطح فعالیت
     * 
     * @param int $userId
     * @param float $siteSharePercent
     * @return float
     */
    private function calculateUserSharePercent(int $userId, float $siteSharePercent): float
    {
        $activeMonths = $this->submissionModel->getActiveMonths($userId);
        $totalSubmissions = $this->submissionModel->countByUser(
            $userId,
            ContentSubmission::STATUS_PUBLISHED
        );

        $baseUserPercent = 100 - $siteSharePercent;

        // Professional tier
        $profBonus = (float)$this->settingService->get('content_professional_bonus_percent', 10);
        $profMax   = (float)$this->settingService->get('content_professional_max_percent', 80);
        if ($activeMonths >= self::PROFESSIONAL_TIER_MONTHS && 
            $totalSubmissions >= self::PROFESSIONAL_TIER_SUBMISSIONS) {
            return min(
                $baseUserPercent + $profBonus,
                $profMax
            );
        }

        // Active tier
        $actBonus = (float)$this->settingService->get('content_active_bonus_percent', 5);
        $actMax   = (float)$this->settingService->get('content_active_max_percent', 75);
        if ($activeMonths >= self::ACTIVE_TIER_MONTHS && 
            $totalSubmissions >= self::ACTIVE_TIER_SUBMISSIONS) {
            return min(
                $baseUserPercent + $actBonus,
                $actMax
            );
        }

        // Normal tier
        return $baseUserPercent;
    }

    /**
     * بررسی امکان تأیید
     * 
     * @param string $status
     * @return bool
     */
    private function canBeApproved(string $status): bool
    {
        return in_array($status, [
            ContentSubmission::STATUS_PENDING,
            ContentSubmission::STATUS_UNDER_REVIEW
        ], true);
    }

    /**
     * بررسی امکان رد
     * 
     * @param string $status
     * @return bool
     */
    private function canBeRejected(string $status): bool
    {
        return in_array($status, [
            ContentSubmission::STATUS_PENDING,
            ContentSubmission::STATUS_UNDER_REVIEW
        ], true);
    }

    /**
     * ارسال نوتیفیکیشن درآمد
     * 
     * @param object $submission
     * @param array $revenueData
     * @param string $period
     * @return void
     */
    private function sendRevenueNotification($submission, array $revenueData, string $period): void
    {
        $amount = number_format($revenueData['net_user_amount']);
        $currencyLabel = $revenueData['currency'] === 'usdt' ? 'تتر' : 'تومان';
        
        $this->sendNotification(
            $submission->user_id,
            'درآمد جدید ثبت شد',
            sprintf(
                'درآمد دوره %s برای محتوای «%s»: %s %s',
                $period,
                $this->escapeText($submission->title),
                $amount,
                $currencyLabel
            ),
            'content_revenue'
        );
    }

    /**
     * ارسال نوتیفیکیشن
     * 
     * @param int $userId
     * @param string $title
     * @param string $message
     * @param string $type
     * @return void
     */
    private function sendNotification(int $userId, string $title, string $message, string $type): void
    {
        try {
            $this->notificationService->send($userId, $type, $title, $message);
        } catch (\Throwable $e) {
            $this->logError('content.notification.failed', [
                'user_id'   => $userId,
                'title'     => $title,
                'error'     => $e->getMessage(),
                'exception' => \get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
        }
    }

    /**
     * پاک کردن کش کاربر
     * 
     * @param int $userId
     * @return void
     */
    private function clearUserCache(int $userId): void
    {
        try {
            $this->cache->forget("user_content_stats_{$userId}");
            $this->cache->forget("user_revenue_{$userId}");
        } catch (\Throwable $e) {
            $this->logError('content.cache_clear.failed', [
                'user_id'   => $userId,
                'error'     => $e->getMessage(),
                'exception' => \get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
        }
    }

    public function searchContent(string $q, array $filters, int $limit, int $offset): array
    {
        $query = $this->submissionModel->query()
            ->select('content_submissions.*', 'u.full_name', 'u.email')
            ->leftJoin('users as u', 'u.id', '=', 'content_submissions.user_id');

        if (!empty($q)) {
            $like = "%{$q}%";
            $query->where(function($sub) use ($like) {
                $sub->where('content_submissions.title', 'LIKE', $like)
                    ->orWhere('content_submissions.description', 'LIKE', $like);
            });
        }

        if (!empty($filters['status'])) {
            $query->where('content_submissions.status', '=', e($filters['status'], ENT_QUOTES, 'UTF-8'));
        }
        if (!empty($filters['category'])) {
            $query->where('content_submissions.category', '=', e($filters['category'], ENT_QUOTES, 'UTF-8'));
        }

        return [
            'total' => $query->count(),
            'items' => (clone $query)->orderBy('content_submissions.created_at', 'DESC')
                                     ->limit($limit)->offset($offset)->get() ?? []
        ];
    }

    // successResponse/errorResponse دریافت شده‌اند از BaseService
}


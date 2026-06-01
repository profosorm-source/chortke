<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContentSubmission;
use App\Models\ContentRevenue;
use App\Models\ContentAgreement;
use Core\Cache;
use Core\TransactionWrapper;
use Core\EventDispatcher;
use App\Contracts\LoggerInterface;
use Core\Exceptions\BusinessException;
use App\Services\Settings\AppSettings;

/**
 * سرویس مدیریت محتوا
 * 
 * @package App\Services
 */
class ContentService
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

    private ContentSubmission $submissionModel;
    private ContentRevenue $revenueModel;
    private ContentAgreement $agreementModel;
    private AppSettings $appSettings;
    private \App\Services\Shared\IdempotencyService $idempotencyService;
    private ?\App\Contracts\OutboxServiceInterface $outboxService = null;
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

    private \App\Contracts\LoggerInterface $logger;
    private \Core\EventDispatcher $eventDispatcher;
    private \Core\Database $db;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        \Core\EventDispatcher $eventDispatcher,
        \Core\Database $db,
        ContentSubmission $submissionModel,
        ContentRevenue $revenueModel,
        ContentAgreement $agreementModel,
        TransactionWrapper $transactionWrapper,
        AppSettings $appSettings,
        ?\App\Contracts\OutboxServiceInterface $outboxService = null
    ) {        $this->logger = $logger;
        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;

                $this->submissionModel = $submissionModel;
        $this->revenueModel = $revenueModel;
        $this->agreementModel = $agreementModel;
        $this->transactionWrapper = $transactionWrapper;
        $this->appSettings = $appSettings;
        $this->outboxService = $outboxService;
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
                throw new \Core\Exceptions\BusinessException('شما حداکثر تعداد مجاز محتوای در انتظار را دارید. لطفاً تا تعیین وضعیت آن‌ها صبر کنید.');
            }

            // Validate platform
            if (!$this->isValidPlatform($data['platform'])) {
                throw new \Core\Exceptions\BusinessException('پلتفرم انتخابی نامعتبر است.');
            }

            // Validate & sanitize URL
            $videoUrl = $this->sanitizeUrl($data['video_url']);
            if (!$this->validateVideoUrl($videoUrl, $data['platform'])) {
                throw new \Core\Exceptions\BusinessException(sprintf(
                        'لینک ویدیو نامعتبر است. لطفاً لینک صحیح از %s وارد کنید.',
                        $data['platform']
                    ));
            }

            // Check duplicate URL
            if ($this->submissionModel->isUrlExists($videoUrl)) {
                throw new \Core\Exceptions\BusinessException('این لینک ویدیو قبلاً ثبت شده است.');
            }

            // Validate agreement
            if (empty($data['agreement_accepted'])) {
                throw new \Core\Exceptions\BusinessException('لطفاً تعهدنامه همکاری را بخوانید و تأیید کنید.');
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
            $this->logger->info('content_submission', ['message' => "User {$userId} submitted content #{$submissionId}"]);

            // Clear cache
            return ['success' => true, 'message' => 'محتوای شما با موفقیت ثبت شد و در صف بررسی قرار گرفت.', 'data' => ['submission_id' => $submissionId]];
            
        } catch (BusinessException $e) {
            $this->logger->error('content.submission.business_failed', [
                'user_id'   => $userId,
                'error'     => $e->getMessage(),
                'exception' => \get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('content.submission.unexpected_failed', [
                'user_id'   => $userId,
                'error'     => $e->getMessage(),
                'exception' => \get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
            throw new \Core\Exceptions\BusinessException('خطا در ثبت محتوا. لطفاً دوباره تلاش کنید.');
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
                throw new \Core\Exceptions\BusinessException('محتوا یافت نشد.');
            }

            if (!$this->canBeApproved($submission->status)) {
                throw new \Core\Exceptions\BusinessException('وضعیت محتوا اجازه تأیید را نمی‌دهد.');
            }

            $now = date('Y-m-d H:i:s');
            
            $this->submissionModel->update($submissionId, [
                'status' => ContentSubmission::STATUS_APPROVED,
                'approved_at' => $now,
                'approved_by' => $adminId,
            ]);

            $this->eventDispatcher->dispatchAsync('content.approved', [
                'submission_id' => $submissionId,
                'user_id' => $submission->user_id,
                'approved_by' => $adminId,
            ]);

            $this->logger->info('content_approval', ['message' => "Admin {$adminId} approved content #{$submissionId}"]);
            return ['success' => true, 'message' => 'محتوا با موفقیت تأیید شد.', 'data' => []];
            
        } catch (\Throwable $e) {
            $this->logger->error('content.approval.failed', [
                'submission_id' => $submissionId,
                'admin_id'      => $adminId,
                'error'         => $e->getMessage(),
                'exception'     => \get_class($e),
                'file'          => $e->getFile(),
                'line'          => $e->getLine(),
            ]);
            throw new \Core\Exceptions\BusinessException('خطا در تأیید محتوا.');
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
                throw new \Core\Exceptions\BusinessException('محتوا یافت نشد.');
            }

            if (!$this->canBeRejected($submission->status)) {
                throw new \Core\Exceptions\BusinessException('وضعیت محتوا اجازه رد را نمی‌دهد.');
            }

            // Sanitize reason
            $reason = $this->sanitizeText($reason);

            $this->submissionModel->update($submissionId, [
                'status' => ContentSubmission::STATUS_REJECTED,
                'rejection_reason' => $reason,
                'rejected_by' => $adminId,
                'rejected_at' => date('Y-m-d H:i:s'),
            ]);

                        $this->eventDispatcher->dispatchAsync('content.rejected', [
                'submission_id' => $submissionId,
                'user_id' => $submission->user_id,
                'rejected_by' => $adminId,
                'reason' => $reason
            ]);

$this->logger->info('content_rejection', ['message' => "Admin {$adminId} rejected content #{$submissionId}: {$reason}"]);
            return ['success' => true, 'message' => 'محتوا رد شد.', 'data' => []];
            
        } catch (\Throwable $e) {
            $this->logger->error('content.rejection.failed', [
                'submission_id' => $submissionId,
                'admin_id'      => $adminId,
                'error'         => $e->getMessage(),
                'exception'     => \get_class($e),
                'file'          => $e->getFile(),
                'line'          => $e->getLine(),
            ]);
            throw new \Core\Exceptions\BusinessException('خطا در رد محتوا.');
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
                throw new \Core\Exceptions\BusinessException('محتوا یافت نشد.');
            }

            if ($submission->status !== ContentSubmission::STATUS_APPROVED) {
                throw new \Core\Exceptions\BusinessException('فقط محتوای تأیید شده قابل انتشار است.');
            }

            // Validate URL
            $publishedUrl = filter_var($publishedUrl, FILTER_SANITIZE_URL);
            if (!filter_var($publishedUrl, FILTER_VALIDATE_URL)) {
                throw new \Core\Exceptions\BusinessException('لینک انتشار نامعتبر است.');
            }

            $now = date('Y-m-d H:i:s');
            
            $this->submissionModel->update($submissionId, [
                'status' => ContentSubmission::STATUS_PUBLISHED,
                'published_at' => $now,
                'published_url' => $publishedUrl,
                'published_by' => $adminId,
            ]);

            $this->eventDispatcher->dispatchAsync('content.published', [
                'submission_id' => $submissionId,
                'user_id' => $submission->user_id,
                'published_by' => $adminId,
                'published_url' => $publishedUrl
            ]);

$this->logger->info('content_publish', ['message' => "Admin {$adminId} published content #{$submissionId}"]);
            return ['success' => true, 'message' => 'محتوا با موفقیت منتشر شد.', 'data' => []];
            
        } catch (\Throwable $e) {
            $this->logger->error('content.publish.failed', [
                'submission_id' => $submissionId,
                'admin_id'      => $adminId,
                'error'         => $e->getMessage(),
                'exception'     => \get_class($e),
                'file'          => $e->getFile(),
                'line'          => $e->getLine(),
            ]);
            throw new \Core\Exceptions\BusinessException('خطا در انتشار محتوا.');
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
                throw new \Core\Exceptions\BusinessException('محتوا یافت نشد.');
            }

            if ($submission->status !== ContentSubmission::STATUS_PUBLISHED) {
                throw new \Core\Exceptions\BusinessException('فقط برای محتوای منتشرشده می‌توان درآمد ثبت کرد.');
            }

            // Check minimum active months
            $activeMonths = $this->submissionModel->getActiveMonths($submission->user_id);
            if ($activeMonths < ContentSubmission::MIN_MONTHS_FOR_REVENUE) {
                $remaining = ContentSubmission::MIN_MONTHS_FOR_REVENUE - $activeMonths;
                throw new \Core\Exceptions\BusinessException(sprintf('کاربر هنوز به حداقل زمان فعالیت نرسیده. %d ماه دیگر باقی مانده.', $remaining));
            }

            // Validate period format (YYYY-MM)
            $period = $this->validatePeriod($data['period']);
            if (!$period) {
                throw new \Core\Exceptions\BusinessException('فرمت دوره نامعتبر است. (مثال: 1404-01)');
            }

            // Check duplicate period
            if ($this->revenueModel->existsForPeriod($submissionId, $period)) {
                throw new \Core\Exceptions\BusinessException("درآمد برای دوره {$period} قبلاً ثبت شده است.");
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

                        $this->eventDispatcher->dispatchAsync('content.revenue_recorded', [
                'submission_id' => $submissionId,
                'user_id' => $submission->user_id,
                'revenue_id' => $revenueId,
                'period' => $period
            ]);

$this->logger->info('content_revenue', ['message' => "Admin {$adminId} added revenue #{$revenueId} for content #{$submissionId}"]);
            return ['success' => true, 'message' => 'درآمد با موفقیت ثبت شد.', 'data' => ['revenue_id' => $revenueId]];
            
        } catch (BusinessException $e) {
            $this->logger->error('content.revenue.business_failed', [
                'submission_id' => $submissionId,
                'admin_id'      => $adminId,
                'error'         => $e->getMessage(),
                'exception'     => \get_class($e),
                'file'          => $e->getFile(),
                'line'          => $e->getLine(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('content.revenue.unexpected_failed', [
                'submission_id' => $submissionId,
                'admin_id'      => $adminId,
                'error'         => $e->getMessage(),
                'exception'     => \get_class($e),
                'file'          => $e->getFile(),
                'line'          => $e->getLine(),
            ]);
            throw new \Core\Exceptions\BusinessException('خطا در ثبت درآمد.');
        }
    }

    /**
     * ایجاد درآمد محتوا (ادمین) - wrapper with idempotency support
     * 
     * @param array $data
     * @param int $adminId
     * @param string|null $idempotencyKey
     * @return array
     */
    public function createRevenue(array $data, int $adminId, ?string $idempotencyKey = null): array
    {
        try {
            $submissionId = (int)($data['submission_id'] ?? 0);
            if ($submissionId <= 0) {
                throw new \Core\Exceptions\BusinessException('ID محتوا نامعتبر است.');
            }

            $totalRevenue = (float)($data['total_revenue'] ?? 0);
            if ($totalRevenue <= 0) {
                throw new \Core\Exceptions\BusinessException('مبلغ درآمد باید بیشتر از صفر باشد.');
            }

            $period = trim((string)($data['period'] ?? ''));
            if (empty($period)) {
                throw new \Core\Exceptions\BusinessException('دوره درآمد الزامی است.');
            }

            $payload = [
                'submission_id' => $submissionId,
                'admin_id' => $adminId,
                'period' => $period,
                'total_revenue' => $totalRevenue,
            ];

            $explicitKey = $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : null;

            return $this->idempotencyService->execute('content.createRevenue', $adminId, $payload, function () use (
                $submissionId,
                $adminId,
                $data
            ) {
                return $this->recordRevenue($submissionId, $adminId, $data);
            }, $explicitKey);

        } catch (\Throwable $e) {
            $this->logger->error('content.createRevenue.failed', [
                'submission_id' => (int)($data['submission_id'] ?? 0),
                'admin_id' => $adminId,
                'error' => $e->getMessage(),
            ]);
            throw new \Core\Exceptions\BusinessException('خطا در ثبت درآمد.');
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
                throw new \Core\Exceptions\BusinessException('رکورد درآمد یافت نشد.');
            }

            if ($revenue->status !== \App\Models\ContentRevenue::STATUS_APPROVED) {
                $this->db->rollBack();
                throw new \Core\Exceptions\BusinessException('فقط درآمدهای تأیید شده قابل پرداخت هستند.');
            }

            $currency = $revenue->currency === 'usdt' ? 'usdt' : 'irt';

            $payload = [
                'user_id' => $revenue->user_id,
                'amount' => $revenue->net_user_amount,
                'currency' => $currency,
                'metadata' => [
                    'type' => 'content_revenue',
                    'revenue_id' => $revenueId,
                    'submission_id' => $revenue->submission_id,
                    'period' => $revenue->period,
                    'description' => sprintf(
                        'درآمد محتوا - دوره %s - %s',
                        $revenue->period,
                        $this->escapeText($revenue->video_title ?? '')
                    ),
                    'idempotency_key' => "content_revenue_payment_{$revenueId}",
                ],
            ];

            if ($this->outboxService) {
                $ok = $this->outboxService->record('content_revenue', (int)$revenueId, \App\Events\Registry\EventRegistry::CONTENT_REVENUE_GENERATED, $payload);
                if (!$ok) {
                    $this->db->rollBack();
                    throw new \Core\Exceptions\BusinessException('خطا در ثبت رکورد خروجی برای پرداخت درآمد.');
                }

                $this->revenueModel->update($revenueId, [
                    'status'         => \App\Models\ContentRevenue::STATUS_PAID,
                    'paid_at'        => date('Y-m-d H:i:s'),
                    'transaction_id' => null,
                    'paid_by_admin'  => $adminId,
                ]);
            } else {
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
                        ),
                        'idempotency_key' => "content_revenue_payment_{$revenueId}",
                    ]
                );

                if (empty($depositResult['success'])) {
                    $this->db->rollBack();
                    throw new \Core\Exceptions\BusinessException('خطا در واریز به کیف پول: ' . ($depositResult['message'] ?? ''));
                }

                $this->revenueModel->update($revenueId, [
                    'status'         => \App\Models\ContentRevenue::STATUS_PAID,
                    'paid_at'        => date('Y-m-d H:i:s'),
                    'transaction_id' => $depositResult['transaction_id'] ?? null,
                    'paid_by_admin'  => $adminId,
                ]);
            }
            
                        $this->eventDispatcher->dispatchAsync('content.revenue_paid', [
                'revenue_id' => $revenueId,
                'user_id' => $revenue->user_id,
                'submission_id' => $revenue->submission_id,
                'amount' => $revenue->net_user_amount,
                'currency' => $currency
            ]);
return ['success' => true, 'message' => 'درآمد با موفقیت پرداخت شد.', 'data' => []];
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('content.revenue.pay_failed', [
                'revenue_id' => $revenueId,
                'error'      => $e->getMessage()
            ]);
            throw new \Core\Exceptions\BusinessException('خطای سیستمی.');
        }
    }
    public function suspendSubmission(int $submissionId, int $adminId, string $reason): array
    {
        try {
            $submission = $this->submissionModel->find($submissionId);
            
            if (!$submission) {
                throw new \Core\Exceptions\BusinessException('محتوا یافت نشد.');
            }

            $reason = $this->sanitizeText($reason);

            $this->submissionModel->update($submissionId, [
                'status' => ContentSubmission::STATUS_SUSPENDED,
                'rejection_reason' => $reason,
                'suspended_by' => $adminId,
                'suspended_at' => date('Y-m-d H:i:s'),
            ]);

                        $this->eventDispatcher->dispatchAsync('content.suspended', [
                'submission_id' => $submissionId,
                'user_id' => $submission->user_id,
                'suspended_by' => $adminId,
                'reason' => $reason
            ]);

$this->logger->info('content_suspended', ['message' => "Admin {$adminId} suspended content #{$submissionId}: {$reason}"]);
            return ['success' => true, 'message' => 'محتوا تعلیق شد.', 'data' => []];
            
        } catch (\Throwable $e) {
            $this->logger->error('content.suspension.failed', [
                'submission_id' => $submissionId,
                'admin_id'      => $adminId,
                'error'         => $e->getMessage(),
                'exception'     => \get_class($e),
                'file'          => $e->getFile(),
                'line'          => $e->getLine(),
            ]);
            throw new \Core\Exceptions\BusinessException('خطا در تعلیق محتوا.');
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
            'site_share_percent' => (float)$this->appSettings->get('content_site_share_percent', 40),
            'tax_percent' => (float)$this->appSettings->get('content_tax_percent', 9),
            'min_months' => ContentSubmission::MIN_MONTHS_FOR_REVENUE,
            'allowed_platforms' => ContentSubmission::ALLOWED_PLATFORMS,
            'max_pending' => (int)$this->appSettings->get('content_max_pending_submissions', 1),
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
        $limit = (int)$this->appSettings->get('content_max_pending_submissions', 1);
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
        $url = filter_var($url, FILTER_SANITIZE_URL) ?: '';
        if (empty($url)) {
            throw new \InvalidArgumentException('Empty URL');
        }

        $parsed = parse_url($url);
        if (!$parsed || !in_array(strtolower($parsed['scheme'] ?? ''), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Invalid URL scheme');
        }
        
        $host = strtolower($parsed['host'] ?? '');
        $blockedHosts = ['localhost', '127.0.0.1', '0.0.0.0', '::1'];
        if (in_array($host, $blockedHosts, true) || empty($host)) {
            throw new \InvalidArgumentException('Blocked host');
        }
        
        return $url;
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
        $siteSharePercent = (float)$this->appSettings->get('content_site_share_percent', 40);
        $taxPercent = (float)$this->appSettings->get('content_tax_percent', 9);

        // Calculate user share percent based on tier
        $userSharePercent = $this->calculateUserSharePercent($userId, $siteSharePercent);

        // Calculate amounts
        $siteShareAmount = round($totalRevenue * ($siteSharePercent / 100), 2);
        $userShareAmount = round($totalRevenue * ($userSharePercent / 100), 2);
        $taxAmount = round($userShareAmount * ($taxPercent / 100), 2);
        $netUserAmount = round($userShareAmount - $taxAmount, 2);

        // Determine currency
        $currency = $this->appSettings->get('currency_mode', 'irt') === 'usdt' ? 'usdt' : 'irt';

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
        $profBonus = (float)$this->appSettings->get('content_professional_bonus_percent', 10);
        $profMax   = (float)$this->appSettings->get('content_professional_max_percent', 80);
        if ($activeMonths >= self::PROFESSIONAL_TIER_MONTHS && 
            $totalSubmissions >= self::PROFESSIONAL_TIER_SUBMISSIONS) {
            return min(
                $baseUserPercent + $profBonus,
                $profMax
            );
        }

        // Active tier
        $actBonus = (float)$this->appSettings->get('content_active_bonus_percent', 5);
        $actMax   = (float)$this->appSettings->get('content_active_max_percent', 75);
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
}


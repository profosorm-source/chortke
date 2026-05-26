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

    private ContentSubmission $submissionModel;
    private ContentRevenue $revenueModel;
    private ContentAgreement $agreementModel;
    private SettingService $settingService;
    private ?\App\Services\OutboxService $outboxService = null;
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
        ContentSubmission $submissionModel,
        ContentRevenue $revenueModel,
        ContentAgreement $agreementModel,
        TransactionWrapper $transactionWrapper,
        EventDispatcher $eventDispatcher,
        LoggerInterface $logger,
        SettingService $settingService,
    ) {
        parent::__construct($logger);
        $this->submissionModel = $submissionModel;
        $this->revenueModel = $revenueModel;
        $this->agreementModel = $agreementModel;
        $this->transactionWrapper = $transactionWrapper;
        $this->eventDispatcher = $eventDispatcher;
        $this->settingService = $settingService;
        try {
            $this->outboxService = container()->get(\App\Services\OutboxService::class);
        } catch (\Throwable $e) {
            $this->outboxService = null;
        }
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

            $this->eventDispatcher->dispatchAsync('content.approved', [
                'submission_id' => $submissionId,
                'user_id' => $submission->user_id,
                'approved_by' => $adminId,
            ]);

            $this->logInfo('content_approval', ['message' => "Admin {$adminId} approved content #{$submissionId}"]);
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
     * ثبت نظر و امتیاز برای محتوای منتشرشده (از طرف کاربران)
     * 
     * @param int $userId ID کاربر داری‌الرتبه
     * @param int $submissionId ID محتوا
     * @param int $rating امتیاز (1-5)
     * @param string|null $review متن نظر
     * @return array
     */
    public function rateContent(int $userId, int $submissionId, int $rating, ?string $review = null): array
    {
        try {
            // Validation
            if ($rating < 1 || $rating > 5) {
                return $this->errorResponse('امتیاز باید بین ۱ تا ۵ باشد.');
            }

            $submission = $this->submissionModel->find($submissionId);
            if (!$submission) {
                return $this->errorResponse('محتوا یافت نشد.');
            }

            // Prevent self-rating
            if ((int)$submission->user_id === $userId) {
                return $this->errorResponse('نمی‌توانید برای محتوای خود نظر دهید.');
            }

            // Sanitize review
            if ($review !== null) {
                $review = $this->sanitizeText($review);
            }

            // Record rating through shared rating service
            $ratingResult = $this->ratingService->rate(
                raterId: $userId,
                ratedId: (int)$submission->user_id,
                refType: 'content_submission',
                refId: $submissionId,
                rating: $rating,
                review: $review,
                ratedType: 'user'
            );

            if (!$ratingResult) {
                return $this->errorResponse('امتیاز ثبت نشد. شاید قبلاً نظر دادید.');
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

                        $this->eventDispatcher->dispatchAsync('content.rejected', [
                'submission_id' => $submissionId,
                'user_id' => $submission->user_id,
                'rejected_by' => $adminId,
                'reason' => $reason
            ]);

$this->logInfo('content_rejection', ['message' => "Admin {$adminId} rejected content #{$submissionId}: {$reason}"]);
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

                        $this->eventDispatcher->dispatchAsync('content.rejected', [
                'submission_id' => $submissionId,
                'user_id' => $submission->user_id,
                'rejected_by' => $adminId,
                'reason' => $reason
            ]);

$this->logInfo('content_publish', ['message' => "Admin {$adminId} published content #{$submissionId}"]);
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

                        $this->eventDispatcher->dispatchAsync('content.revenue_recorded', [
                'submission_id' => $submissionId,
                'user_id' => $submission->user_id,
                'revenue_id' => $revenueId,
                'period' => $period
            ]);

$this->logInfo('content_revenue', ['message' => "Admin {$adminId} added revenue #{$revenueId} for content #{$submissionId}"]);
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
                return $this->errorResponse('ID محتوا نامعتبر است.');
            }

            $totalRevenue = (float)($data['total_revenue'] ?? 0);
            if ($totalRevenue <= 0) {
                return $this->errorResponse('مبلغ درآمد باید بیشتر از صفر باشد.');
            }

            $period = trim((string)($data['period'] ?? ''));
            if (empty($period)) {
                return $this->errorResponse('دوره درآمد الزامی است.');
            }

            $payload = [
                'submission_id' => $submissionId,
                'admin_id' => $adminId,
                'period' => $period,
                'total_revenue' => $totalRevenue,
            ];

            $explicitKey = $idempotencyKey !== null && $idempotencyKey !== ''
                ? $idempotencyKey
                : \Core\IdempotencyKey::generateFromPayload('content_revenue_creation', $payload);

            return $this->idempotent('content.createRevenue', $adminId, $payload, function () use (
                $submissionId,
                $adminId,
                $data
            ) {
                return $this->recordRevenue($submissionId, $adminId, $data);
            }, $explicitKey);

        } catch (\Throwable $e) {
            $this->logError('content.createRevenue.failed', [
                'submission_id' => (int)($data['submission_id'] ?? 0),
                'admin_id' => $adminId,
                'error' => $e->getMessage(),
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
                $ok = $this->outboxService->record('content_revenue', (int)$revenueId, 'wallet.deposit.requested', $payload);
                if (!$ok) {
                    $this->db->rollBack();
                    return $this->errorResponse('خطا در ثبت رکورد خروجی برای پرداخت درآمد.');
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
            }
            
                        $this->eventDispatcher->dispatchAsync('content.revenue_paid', [
                'revenue_id' => $revenueId,
                'user_id' => $revenue->user_id,
                'submission_id' => $revenue->submission_id,
                'amount' => $revenue->net_user_amount,
                'currency' => $currency
            ]);
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

                        $this->eventDispatcher->dispatchAsync('content.suspended', [
                'submission_id' => $submissionId,
                'user_id' => $submission->user_id,
                'suspended_by' => $adminId,
                'reason' => $reason
            ]);

$this->logInfo('content_suspended', ['message' => "Admin {$adminId} suspended content #{$submissionId}: {$reason}"]);
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


<?php

declare(strict_types=1);

namespace App\Services\CustomTask;

use App\Services\BaseService;
use App\Models\Ads;
use App\Models\CustomTaskSubmissionModel;
use App\Services\Wallet\WalletService;
use App\Services\SettingService;
use App\Traits\ValidationTrait;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Exceptions\BusinessException;
use App\Validators\Requests\CreateCustomTaskRequest;

/**
 * CustomTaskService - Core custom tasks management
 */
class CustomTaskService extends BaseService
{
    use ValidationTrait;
    private Ads $taskModel;
    private CustomTaskSubmissionModel $submissionModel;
    protected ?Database $db;
    private WalletService $walletService;
    private SettingService $settingService;
    private \Core\RateLimiter $rateLimiter;
    private \App\Models\CustomTaskAnalyticsModel $analyticsModel;
    private \App\Contracts\SearchServiceInterface $searchOrchestrator;
    private \App\Services\EscrowService $escrowService;
    private \App\Services\Interaction\RatingService $ratingService;
    private \Core\EventDispatcher $eventDispatcher;

    public function __construct(
        LoggerInterface $logger,
        Database $db,
        WalletService $walletService,
        SettingService $settingService,
        Ads $taskModel,
        CustomTaskSubmissionModel $submissionModel,
        \Core\RateLimiter $rateLimiter,
        \App\Models\CustomTaskAnalyticsModel $analyticsModel,
        \App\Contracts\SearchServiceInterface $searchOrchestrator,
        ?\App\Services\EscrowService $escrowService = null,
        ?\App\Services\Interaction\RatingService $ratingService = null,
        ?\Core\EventDispatcher $eventDispatcher = null
    ) {
        parent::__construct($logger);
        $this->db = $db;
        $this->walletService = $walletService;
        $this->settingService = $settingService;
        $this->taskModel = $taskModel;
        $this->submissionModel = $submissionModel;
        $this->rateLimiter = $rateLimiter;
        $this->analyticsModel = $analyticsModel;
        $this->searchOrchestrator = $searchOrchestrator;
        
        $container = function_exists('container') ? container() : null;
        $this->escrowService = $escrowService ?? ($container ? $container->get(\App\Services\EscrowService::class) : null);
        $this->ratingService = $ratingService ?? ($container ? $container->get(\App\Services\Interaction\RatingService::class) : null);
        $this->eventDispatcher = $eventDispatcher ?? \Core\EventDispatcher::getInstance();
    }

    public function guardCanCreateTask(int $creatorId, array $data): void
    {
        // Use centralized validation pipeline instead of inline checks
        try {
            $validated = $this->validateWith(
                $data,
                [
                    'title'              => 'required|string|min:5|max:200',
                    'description'        => 'required|string|min:20|max:2000',
                    'price_per_task'     => 'required|numeric|min:1000',
                    'total_quantity'     => 'required|integer|min:1|max:10000',
                    'currency'           => 'required|in:IRT,USDT',
                    'task_type'          => 'required|in:signup,install,review,vote,follow,join,custom',
                    'proof_type'         => 'required|in:screenshot,text,video,code,file',
                    'deadline_hours'     => 'required|integer|min:1|max:168',
                    'daily_limit_per_user' => 'nullable|integer|min:1|max:50',
                    'device_restriction' => 'nullable|in:all,mobile,desktop',
                    'link'               => 'nullable|url',
                    'idempotency_key'    => 'required|string|min:10|max:128',
                ],
                null, // No special authorization check needed
                [
                    'creator_id' => [
                        'callback' => fn() => $creatorId > 0,
                        'message' => 'کاربر سازنده نامعتبر است'
                    ],
                    'price_per_task' => [
                        'callback' => function() use ($data) {
                            $currency = strtoupper($data['currency'] ?? 'IRT');
                            $price = (float)($data['price_per_task'] ?? 0);
                            $minKey = $currency === 'IRT' 
                                ? 'custom_task_min_price_irt' 
                                : 'custom_task_min_price_usdt';
                            $minPrice = (float)$this->settingService->get($minKey, $currency === 'IRT' ? 5000 : 0.5);
                            return $price >= $minPrice;
                        },
                        'message' => 'قیمت پیشنهادی کمتر از حداقل مجاز است'
                    ],
                    'budget_available' => [
                        'callback' => function() use ($creatorId, $data) {
                            $totalCost = (float)$data['price_per_task'] * (int)$data['total_quantity'];
                            $userBalance = $this->walletService->getBalance($creatorId, $data['currency'] ?? 'IRT');
                            return $userBalance >= $totalCost;
                        },
                        'message' => 'موجودی کافی برای ایجاد این تسک نیست'
                    ]
                ]
            );

            $this->logger->info('custom_task.guard.create.passed', [
                'creator_id' => $creatorId,
                'title' => $validated['title']
            ]);
        } catch (BusinessException $e) {
            throw $e;
        }
    }

    public function createTask(int $creatorId, array $data): array
    {
        try {
            $this->guardCanCreateTask($creatorId, $data);
        } catch (BusinessException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if (!$this->rateLimiter->attempt('custom_task:create:' . $creatorId, 5, 60)) {
            $wait = ceil($this->rateLimiter->availableIn('custom_task:create:' . $creatorId) / 60);
            return ['success' => false, 'message' => "تعداد درخواست‌های ساخت تسک شما بیش از حد مجاز است. لطفاً {$wait} دقیقه دیگر تلاش کنید."];
        }

        if (!$this->settingService->get('custom_task_enabled', 1)) {
            return ['success' => false, 'message' => 'سیستم وظایف سفارشی غیرفعال است.'];
        }

        $currency = $data['currency'] ?? 'irt';
        $pricePerTask = (float) ($data['price_per_task'] ?? 0);
        $quantity = (int) ($data['total_quantity'] ?? 1);

        $minPrice = $currency === 'usdt'
            ? (float) $this->settingService->get('custom_task_min_price_usdt', 0.50)
            : (float) $this->settingService->get('custom_task_min_price_irt', 5000);

        if ($pricePerTask < $minPrice) {
            $label = $currency === 'usdt' 
                ? number_format($minPrice, 2) . ' USDT' 
                : number_format($minPrice) . ' تومان';
            return ['success' => false, 'message' => "حداقل قیمت هر تسک {$label} است."];
        }

        $feePercent = (float) $this->settingService->get('custom_task_site_fee_percent', 10);
        $totalBudget = $pricePerTask * $quantity;
        $feeAmount = round($totalBudget * ($feePercent / 100), 2);
        $totalWithFee = $totalBudget + $feeAmount;

        try {
            $this->db->beginTransaction();

            $this->db->query("SELECT id FROM users WHERE id = ? FOR UPDATE", [$creatorId])->fetch();

            $idempotencyKey = \Core\IdempotencyKey::generateFromPayload('task_budget_allocation', [
                'creator_id' => $creatorId,
                'title' => $data['title'] ?? 'untitled',
                'amount' => $totalWithFee,
                'currency' => $currency
            ]);

            $status = $this->settingService->get('custom_task_auto_approve', 0) ? 'active' : 'pending_review';

            $task = $this->taskModel->create([
                'type' => 'custom_task',
                'user_id' => $creatorId,
                'title' => $data['title'],
                'description' => $data['description'],
                'link' => $data['link'] ?? null,
                'task_type' => $data['task_type'] ?? 'custom',
                'proof_type' => $data['proof_type'] ?? 'screenshot',
                'proof_description' => $data['proof_description'] ?? null,
                'sample_image' => $data['sample_image'] ?? null,
                'price_per_task' => $pricePerTask,
                'currency' => $currency,
                'total_budget' => $totalBudget,
                'remaining_budget' => $totalBudget,
                'total_count' => $quantity,
                'remaining_count' => $quantity,
                'deadline_hours' => $data['deadline_hours'] ?? 24,
                'country_restriction' => $data['country_restriction'] ?? null,
                'device_restriction' => $data['device_restriction'] ?? 'all',
                'os_restriction' => $data['os_restriction'] ?? null,
                'status' => ($status === 'pending_review') ? 'pending' : $status,
                'site_commission_percent' => $feePercent,
                'restrictions' => json_encode([
                    'daily_limit_per_user' => $data['daily_limit_per_user'] ?? 1,
                    'site_fee_amount' => $feeAmount,
                ]),
            ]);

            if (!$task) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در ایجاد وظیفه.'];
            }
            
            // Delegate financial hold to EscrowService to enforce boundary
            $escrowResult = $this->escrowService->holdFunds(
                (int)$task->id,
                'custom_task_budget',
                $creatorId, // buyer
                0, // System/Escrow holds it, no specific seller initially
                (string)$totalWithFee,
                $currency
            );

            if (empty($escrowResult['ok'])) {
                $this->db->rollBack();
                return ['success' => false, 'message' => $escrowResult['error'] ?? 'خطا در مسدودسازی بودجه وظیفه.'];
            }

            $this->db->commit();

            $this->logger->info('Custom task created and budget escrowed', [
                'task_id' => $task->id,
                'creator_id' => $creatorId,
                'budget' => $totalWithFee,
                'escrow_id' => $escrowResult['escrow_id'] ?? null
            ]);

            try {
                // Event-Driven integration
                $this->eventDispatcher->dispatchAsync('custom_task.created', [
                    'task_id' => $task->id,
                    'module' => 'custom_task',
                    'type' => 'custom_task'
                ]);
            } catch (\Throwable $evtErr) {
                $this->logger->warning('custom_task.create.event_failed', [
                    'task_id' => $task->id,
                    'error' => $evtErr->getMessage()
                ]);
            }

            return [
                'success' => true,
                'message' => 'وظیفه با موفقیت ثبت شد.',
                'task' => $task,
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('task.create.failed', [
                'channel' => 'task',
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطا در ثبت وظیفه: ' . $e->getMessage()];
        }
    }

    public function find(int $id): ?object
    {
        return $this->taskModel->find($id);
    }

    public function getAvailableTasks(int $workerId, array $filters, int $limit, int $offset): array
    {
        return $this->taskModel->getAvailableCustomTasks($workerId, $filters, $limit, $offset);
    }

    public function getMyTasks(int $creatorId, ?string $status, int $limit, int $offset): array
    {
        return $this->taskModel->getByAdvertiser($creatorId, $limit, $offset, 'custom_task', $status);
    }

    public function getMySubmissions(int $workerId, ?string $status, int $limit, int $offset): array
    {
        return $this->submissionModel->submission_getByWorker($workerId, $status, $limit, $offset);
    }

    public function toggleFavorite(int $taskId, int $userId): array
    {
        try {
            $isCurrentlyFavorite = $this->taskModel->isTaskFavorited($taskId, $userId);

            $this->db->beginTransaction();

            if ($isCurrentlyFavorite) {
                $success = $this->taskModel->removeFromFavorites($taskId, $userId);
                $message = 'از علاقه‌مندی‌ها حذف شد.';
                $isFavorite = false;
            } else {
                $success = $this->taskModel->addToFavorites($taskId, $userId);
                $message = 'به علاقه‌مندی‌ها اضافه شد.';
                $isFavorite = true;
            }

            if ($success) {
                $this->db->commit();
                return [
                    'success' => true,
                    'message' => $message,
                    'is_favorite' => $isFavorite
                ];
            } else {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در عملیات.'];
            }

        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا در عملیات.'];
        }
    }

    public function quickSearchAds(string $term, ?int $userId = null, int $limit = 5): array
    {
        return $this->searchOrchestrator->quickSearchAds($term, $userId, $limit);
    }

    public function quickSearchSubmissions(string $term, ?int $userId = null, int $limit = 5): array
    {
        return $this->searchOrchestrator->quickSearchSubmissions($term, $userId, $limit);
    }

    public function searchAdTasks(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchOrchestrator->searchAdTasks($q, $filters, $limit, $offset);
    }

    public function getSubmissionsByTask(int $taskId, ?string $status = null, int $limit = 30, int $offset = 0): array
    {
        return $this->submissionModel->submission_getByTask($taskId, $status, $limit, $offset);
    }

    public function getTaskAnalytics(int $taskId, int $days = 30): array
    {
        $analytics = $this->analyticsModel->getTaskAnalytics($taskId);

        $ratings = [];
        if ($this->ratingService) {
            $ratingsRaw = $this->ratingService->getRatingsByRef('custom_task', $taskId, 100);
            foreach ($ratingsRaw as $r) {
                $ratings[] = [
                    'rating' => $r['stars'] ?? $r['value'] ?? 0,
                    'created_at' => $r['created_at'],
                    'rater_name' => $r['rater_name'] ?? 'کاربر',
                ];
            }
        }

        return [
            'overall' => $analytics['overall'],
            'daily' => $analytics['daily'],
            'ratings' => $ratings,
        ];
    }
}

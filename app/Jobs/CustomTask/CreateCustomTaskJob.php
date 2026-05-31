<?php
declare(strict_types=1);

namespace App\Jobs\CustomTask;

use Core\Database;
use Core\Logger;
use Core\EventDispatcher;
use Core\RateLimiter;
use App\Models\User;
use App\Models\Ads;
use App\Services\Settings\AppSettings;
use App\Services\EscrowService;

class CreateCustomTaskJob
{
    public function __construct(
        private RateLimiter $rateLimiter,
        private AppSettings $appSettings,
        private Database $db,
        private User $userModel,
        private Ads $taskModel,
        private EscrowService $escrowService,
        private Logger $logger,
        private EventDispatcher $eventDispatcher
    ) {}

    public function handle(array $payload): array
    {
        $creatorId = (int)($payload["creator_id"] ?? 0);
        $data = $payload["data"] ?? [];

        if ($creatorId <= 0) {
            return ["success" => false, "message" => "شناسه کاربر نامعتبر است"];
        }

        if (!$this->rateLimiter->attempt("custom_task:create:" . $creatorId, 5, 60)) {
            $wait = ceil($this->rateLimiter->availableIn("custom_task:create:" . $creatorId) / 60);
            return ["success" => false, "message" => "تعداد درخواست‌های شما بیش از حد مجاز است. لطفا {$wait} دقیقه دیگر امتحان کنید."];
        }

        if (!$this->appSettings->get("custom_task_enabled", 1)) {
            return ["success" => false, "message" => "سیستم وظایف سفارشی غیرفعال است."];
        }

        $currency = $data["currency"] ?? "irt";
        $pricePerTask = (float) ($data["price_per_task"] ?? 0);
        $quantity = (int) ($data["total_quantity"] ?? 1);

        $minPrice = $currency === "usdt"
            ? (float) $this->appSettings->get("custom_task_min_price_usdt", 0.50)
            : (float) $this->appSettings->get("custom_task_min_price_irt", 5000);

        if ($pricePerTask < $minPrice) {
            $label = $currency === "usdt" 
                ? number_format($minPrice, 2) . " USDT" 
                : number_format($minPrice) . " تومان";
            return ["success" => false, "message" => "حداقل قیمت برای هر وظیفه {$label} است."];
        }

        $feePercent = (float) $this->appSettings->get("custom_task_site_fee_percent", 10);
        $totalBudget = $pricePerTask * $quantity;
        $feeAmount = round($totalBudget * ($feePercent / 100), 2);
        $totalWithFee = $totalBudget + $feeAmount;

        try {
            $this->db->beginTransaction();

            $this->userModel->findByIdForUpdate($creatorId);

            $idempotencyKey = \Core\IdempotencyKey::generateFromPayload("task_budget_allocation", [
                "creator_id" => $creatorId,
                "title" => $data["title"] ?? "untitled",
                "amount" => $totalWithFee,
                "currency" => $currency
            ]);

            $status = $this->appSettings->get("custom_task_auto_approve", 0) ? "active" : "pending_review";
            
            $task = $this->taskModel->create([
                "type" => "custom_task",
                "user_id" => $creatorId,
                "title" => $data["title"],
                "description" => $data["description"],
                "link" => $data["link"] ?? null,
                "task_type" => $data["task_type"] ?? "custom",
                "proof_type" => $data["proof_type"] ?? "screenshot",
                "proof_description" => $data["proof_description"] ?? null,
                "sample_image" => $data["sample_image"] ?? null,
                "price_per_task" => $pricePerTask,
                "currency" => $currency,
                "total_budget" => $totalBudget,
                "remaining_budget" => $totalBudget,
                "total_count" => $quantity,
                "remaining_count" => $quantity,
                "deadline_hours" => $data["deadline_hours"] ?? 24,
                "country_restriction" => $data["country_restriction"] ?? null,
                "device_restriction" => $data["device_restriction"] ?? "all",
                "os_restriction" => $data["os_restriction"] ?? null,
                "status" => ($status === "pending_review") ? "pending" : $status,
                "site_commission_percent" => $feePercent,
                "restrictions" => json_encode([
                    "daily_limit_per_user" => $data["daily_limit_per_user"] ?? 1,
                    "site_fee_amount" => $feeAmount,
                ]),
            ]);

            if (!$task) {
                throw new \Exception("خطا در ذخیره وظیفه.");
            }
            
            $escrowResult = $this->escrowService->holdFunds(
                (int)$task->id,
                "custom_task_budget",
                $creatorId, 
                0, 
                (string)$totalWithFee,
                $currency
            );

            if (empty($escrowResult["ok"])) {
                throw new \Exception($escrowResult["error"] ?? "خطا در مسدودسازی مبلغ بودجه وظیفه.");
            }

            $this->db->commit();

            $this->logger->info("Custom task created and budget escrowed", [
                "task_id" => $task->id,
                "creator_id" => $creatorId,
                "budget" => $totalWithFee,
                "escrow_id" => $escrowResult["escrow_id"] ?? null
            ]);

            try {
                $this->eventDispatcher->dispatchAsync("custom_task.created", [
                    "task_id" => $task->id,
                    "module" => "custom_task",
                    "type" => "custom_task"
                ]);
            } catch (\Throwable $evtErr) {
                $this->logger->warning("custom_task.create.event_failed", [
                    "task_id" => $task->id,
                    "error" => $evtErr->getMessage()
                ]);
            }

            return [
                "success" => true,
                "message" => "وظیفه با موفقیت ثبت شد.",
                "task" => $task,
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error("task.create.failed", [
                "channel" => "task",
                "error" => $e->getMessage(),
            ]);
            return ["success" => false, "message" => "خطا در ثبت وظیفه: " . $e->getMessage()];
        }
    }
}

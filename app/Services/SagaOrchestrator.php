<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Contracts\LoggerInterface;

/**
 * Saga Orchestrator
 * الگوی هماهنگ‌کننده ساگا برای مدیریت تراکنش‌های توزیع‌شده (Distributed Transactions)
 * به جای Rollback دستی، این الگو مراحل را یک‌به‌یک انجام داده و در صورت شکست هر مرحله، 
 * مراحل جبرانی (Compensation) را به صورت خودکار به ترتیب عکس (LIFO) اجرا می‌کند.
 */
class SagaOrchestrator
{
    private array $steps = [];
    private array $executedSteps = [];
    private Database $db;
    private LoggerInterface $logger;

    public function __construct(Database $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * افزودن یک مرحله (Step) به تراکنش ساگا
     *
     * @param string $name نام مرحله برای لاگ‌گیری
     * @param callable $execute منطق اجرایی
     * @param callable $compensate منطق جبرانی در صورت خطا
     */
    public function addStep(string $name, callable $execute, callable $compensate): self
    {
        $this->steps[] = [
            'name' => $name,
            'execute' => $execute,
            'compensate' => $compensate
        ];
        return $this;
    }

    /**
     * اجرای کامل Saga Orchestrator
     *
     * @return mixed خروجی مرحله آخر در صورت موفقیت
     * @throws \Throwable
     */
    public function execute()
    {
        $this->executedSteps = [];
        $result = null;

        try {
            foreach ($this->steps as $step) {
                $this->logger->info("saga.execute_step", ['step' => $step['name']]);
                
                // اجرای مرحله
                $result = call_user_func($step['execute'], $result);
                
                // ثبت به عنوان مرحله اجرا شده برای کامپنسیشن در صورت خطا
                $this->executedSteps[] = $step;
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("saga.execution_failed", [
                'failed_step' => end($this->executedSteps)['name'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            // اجرای استراتژی جبرانی (Compensation) به صورت LIFO (عقب‌گرد)
            $this->compensate($e);

            throw new \RuntimeException("Saga transaction failed and compensated: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * اجرای مراحل جبران‌ساز (Compensation Transactions)
     */
    private function compensate(\Throwable $originalError): void
    {
        $reversedSteps = array_reverse($this->executedSteps);

        foreach ($reversedSteps as $step) {
            try {
                $this->logger->warning("saga.compensating_step", ['step' => $step['name']]);
                
                // اجرای مرحله جبرانی
                call_user_func($step['compensate'], $originalError);
                
                $this->logger->info("saga.compensated_successfully", ['step' => $step['name']]);
            } catch (\Throwable $e) {
                // اگر مرحله جبران‌ساز خودش خطا بخورد، فاجعه است (باید حتماً هشدار Critical بدهیم و در دیتابیس لاگ کنیم)
                $this->logger->critical("saga.compensation_failed_CRITICAL", [
                    'step' => $step['name'],
                    'error' => $e->getMessage(),
                    'original_error' => $originalError->getMessage()
                ]);
            }
        }
    }
}

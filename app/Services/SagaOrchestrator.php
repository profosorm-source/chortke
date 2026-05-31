<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Contracts\LoggerInterface;
use App\Contracts\SagaStepInterface;

/**
 * Saga Orchestrator
 * مدیریت تراکنش‌های توزیع‌شده (Distributed Transactions) با پشتیبانی از Recovery
 */
class SagaOrchestrator
{
    private array $steps = [];
    private array $executedSteps = [];
private LoggerInterface $logger;
    private ?string $sagaName = null;
    private array $sagaPayload = [];
    private ?string $sagaExecutionId = null;
    private ?int $sagaDeadline = null;
    private int $defaultTimeoutSeconds = 60;

    public function __construct(Database $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * تنظیم مشخصات Saga برای لاگ و Recovery
     */
    public function setSaga(string $sagaName, array $payload = []): self
    {
        $this->sagaName = $sagaName;
        $this->sagaPayload = $payload;
        $this->sagaExecutionId = $this->generateUuid();
        
        // ثبت در دیتابیس
        $this->db->prepare(
            "INSERT INTO saga_executions (id, saga_name, status, payload, executed_steps, created_at, updated_at) VALUES (?, ?, 'started', ?, '[]', NOW(), NOW())"
        )->execute([
            $this->sagaExecutionId,
            $this->sagaName,
            json_encode($this->sagaPayload)
        ]);

        return $this;
    }

    /**
     * تنظیم مهلت زمانی (Timeout) اختصاصی برای این Saga
     */
    public function setSagaTimeout(int $seconds): self
    {
        $this->sagaDeadline = time() + $seconds;
        return $this;
    }

    /**
     * افزودن یک مرحله (Step) به تراکنش ساگا
     *
     * @param string|SagaStepInterface $nameOrStep نام مرحله یا آبجکت کلاس Step
     * @param callable|null $execute منطق اجرایی (در صورت استفاده از Closure)
     * @param callable|null $compensate منطق جبرانی (در صورت استفاده از Closure)
     */
    public function addStep($nameOrStep, ?callable $execute = null, ?callable $compensate = null): self
    {
        if ($nameOrStep instanceof SagaStepInterface) {
            $this->steps[] = [
                'type' => 'class',
                'name' => $nameOrStep->getName(),
                'instance' => $nameOrStep
            ];
        } else {
            // پشتیبانی از نسخه قبلی (Closure)
            $this->steps[] = [
                'type' => 'closure',
                'name' => (string)$nameOrStep,
                'execute' => $execute,
                'compensate' => $compensate
            ];
        }
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

        if ($this->sagaDeadline === null) {
            $this->sagaDeadline = time() + $this->defaultTimeoutSeconds;
        }

        try {
            foreach ($this->steps as $step) {
                if (time() > $this->sagaDeadline) {
                    throw new \RuntimeException("Saga timeout exceeded ({$this->defaultTimeoutSeconds}s). Step '{$step['name']}' aborted.");
                }

                $this->logger->info("saga.execute_step", ['step' => $step['name']]);
                
                // اجرای مرحله
                if ($step['type'] === 'class') {
                    $result = $step['instance']->execute($result ?? $this->sagaPayload);
                } else {
                    $result = call_user_func($step['execute'], $result);
                }
                
                // ذخیره نتیجه موقت برای استفاده در جبران
                $step['result'] = $result;
                $this->executedSteps[] = $step;

                // ثبت قدم اجرا شده در دیتابیس برای Recovery
                $this->updateSagaState();
            }

            // موفقیت کامل Saga
            if ($this->sagaExecutionId) {
                $this->db->prepare("UPDATE saga_executions SET status = 'completed', updated_at = NOW() WHERE id = ?")
                         ->execute([$this->sagaExecutionId]);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("saga.execution_failed", [
                'failed_step' => end($this->executedSteps)['name'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            // اجرای استراتژی جبرانی (Compensation)
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
        $compensationSuccess = true;

        foreach ($reversedSteps as $step) {
            try {
                $this->logger->warning("saga.compensating_step", ['step' => $step['name']]);
                
                // اجرای مرحله جبرانی
                if ($step['type'] === 'class') {
                    $step['instance']->compensate($this->sagaPayload, $step['result'] ?? null, $originalError);
                } else {
                    call_user_func($step['compensate'], $originalError);
                }
                
                $this->logger->info("saga.compensated_successfully", ['step' => $step['name']]);
            } catch (\Throwable $e) {
                $compensationSuccess = false;
                $this->logger->critical("saga.compensation_failed_CRITICAL", [
                    'step' => $step['name'],
                    'error' => $e->getMessage(),
                    'original_error' => $originalError->getMessage()
                ]);
            }
        }

        if ($this->sagaExecutionId) {
            $status = $compensationSuccess ? 'compensated' : 'failed_compensation';
            $this->db->prepare("UPDATE saga_executions SET status = ?, updated_at = NOW() WHERE id = ?")
                     ->execute([$status, $this->sagaExecutionId]);
        }
    }

    private function updateSagaState(): void
    {
        if (!$this->sagaExecutionId) return;

        // فقط نام کلاس‌های اجرا شده را ذخیره می‌کنیم تا سریالایز شوند
        $executed = array_map(function($s) {
            return [
                'name' => $s['name'],
                'type' => $s['type'],
                'class' => $s['type'] === 'class' ? get_class($s['instance']) : null
            ];
        }, $this->executedSteps);

        $this->db->prepare("UPDATE saga_executions SET executed_steps = ?, updated_at = NOW() WHERE id = ?")
                 ->execute([json_encode($executed), $this->sagaExecutionId]);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}


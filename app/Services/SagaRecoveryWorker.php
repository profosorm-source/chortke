<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Contracts\LoggerInterface;
use Core\Container;

class SagaRecoveryWorker
{
private LoggerInterface $logger;
    private Container $container;

    public function __construct(Database $db, LoggerInterface $logger, Container $container)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->container = $container;
    }

    /**
     * اجرای ریکاوری برای تراکنش‌های گیر کرده (Stalled Sagas)
     */
    public function run(int $stalledMinutes = 5, int $limit = 10): int
    {
        // پیدا کردن Saga هایی که بیشتر از زمان مشخص گیر کرده‌اند
        $stmt = $this->db->prepare(
            "SELECT * FROM saga_executions 
             WHERE status = 'started' 
             AND updated_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
             ORDER BY updated_at ASC LIMIT ?"
        );
        $stmt->execute([$stalledMinutes, $limit]);
        $stalledSagas = $stmt->fetchAll();

        if (empty($stalledSagas)) {
            return 0;
        }

        $recoveredCount = 0;

        foreach ($stalledSagas as $saga) {
            $this->logger->warning("saga.recovery.starting", ['saga_id' => $saga->id, 'name' => $saga->saga_name]);
            
            try {
                $this->recoverSaga($saga);
                $recoveredCount++;
            } catch (\Throwable $e) {
                $this->logger->critical("saga.recovery.failed", [
                    'saga_id' => $saga->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        return $recoveredCount;
    }

    private function recoverSaga($sagaData): void
    {
        $payload = json_decode($sagaData->payload, true) ?: [];
        $executedSteps = json_decode($sagaData->executed_steps, true) ?: [];

        if (empty($executedSteps)) {
            // هیچ قدمی اجرا نشده، پس چیزی برای جبران نیست. وضعیت را آپدیت می‌کنیم.
            $this->db->prepare("UPDATE saga_executions SET status = 'compensated', updated_at = NOW() WHERE id = ?")
                     ->execute([$sagaData->id]);
            return;
        }

        // مراحل را به ترتیب برعکس اجرا می‌کنیم (LIFO)
        $reversedSteps = array_reverse($executedSteps);
        $compensationSuccess = true;

        $originalError = new \RuntimeException("Saga stalled and recovered by worker.");

        foreach ($reversedSteps as $step) {
            if ($step['type'] === 'class' && !empty($step['class'])) {
                $className = $step['class'];
                if (!class_exists($className)) {
                    $this->logger->error("saga.recovery.class_not_found", ['class' => $className]);
                    $compensationSuccess = false;
                    continue;
                }

                try {
                    // گرفتن نمونه کلاس از Container (برای پشتیبانی از Dependency Injection)
                    $stepInstance = $this->container->make($className);
                    $stepInstance->compensate($payload, $step['result'] ?? null, $originalError);
                    $this->logger->info("saga.recovery.compensated_step", ['saga_id' => $sagaData->id, 'step' => $step['name']]);
                } catch (\Throwable $e) {
                    $compensationSuccess = false;
                    $this->logger->critical("saga.recovery.compensation_error", [
                        'saga_id' => $sagaData->id,
                        'step' => $step['name'],
                        'error' => $e->getMessage()
                    ]);
                }
            } else {
                // اگر Closure بوده، امکان ریکاور از روی دیتابیس وجود ندارد!
                $this->logger->error("saga.recovery.cannot_recover_closure", [
                    'saga_id' => $sagaData->id, 
                    'step' => $step['name']
                ]);
                $compensationSuccess = false;
            }
        }

        $newStatus = $compensationSuccess ? 'compensated' : 'failed_compensation';
        $this->db->prepare("UPDATE saga_executions SET status = ?, updated_at = NOW() WHERE id = ?")
                 ->execute([$newStatus, $sagaData->id]);

        $this->logger->info("saga.recovery.finished", ['saga_id' => $sagaData->id, 'final_status' => $newStatus]);
    }
}


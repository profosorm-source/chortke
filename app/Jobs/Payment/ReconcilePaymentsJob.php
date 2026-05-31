<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

class ReconcilePaymentsJob
{
    public function __construct(
        private \Core\Database $db,
        private \App\Contracts\LoggerInterface $logger,
        private \App\Models\PaymentLog $log,
        private \Core\EventDispatcher $eventDispatcher
    ) {}

    public function handle(): array
    {
        $results = ['total' => 0, 'completed' => 0, 'failed' => 0, 'skipped' => 0];

        try {
            $stuckPayments = $this->db->query(
                "SELECT * FROM payment_logs 
                 WHERE status = 'pending' 
                   AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                 ORDER BY created_at ASC LIMIT 50"
            )->fetchAll(\PDO::FETCH_OBJ) ?: [];

            foreach ($stuckPayments as $pay) {
                $results['total']++;
                
                $responseData = @json_decode($pay->response_data ?? '{}', true) ?: [];
                $retryCount = (int)($responseData['retry_count'] ?? 0);

                if ($retryCount >= 5) {
                    $responseData['error_message'] = 'Max retry attempts reached (skipped)';
                    $this->log->update((int)$pay->id, [
                        'status' => 'failed',
                        'response_data' => json_encode($responseData, JSON_UNESCAPED_UNICODE)
                    ]);
                    $results['skipped']++;
                    continue;
                }

                $responseData['retry_count'] = $retryCount + 1;
                $responseData['last_retry_at'] = date('Y-m-d H:i:s');

                $this->log->update((int)$pay->id, [
                    'response_data' => json_encode($responseData, JSON_UNESCAPED_UNICODE)
                ]);

                try {
                    $storedRequestData = @json_decode($pay->request_data ?? '', true) ?: [];
                    $storedNonce = (string)($storedRequestData['callback_nonce'] ?? '');

                    // Reuse the fully secured and locked callback logic to ensure complete atomicity and safety
                    $processJob = \Core\Container::getInstance()->make(\App\Jobs\Payment\ProcessPaymentCallbackJob::class);
                    $res = $processJob->handle((string)$pay->gateway, [
                        'authority' => (string)$pay->authority,
                        'nonce' => $storedNonce,
                        'status' => 'OK'
                    ], (int)$pay->user_id);

                    if (!empty($res['success'])) {
                        $results['completed']++;
                    } else {
                        $results['failed']++;
                        
                        // If it has now been retried 5 times, mark as failed strictly and alert admin
                        if ($retryCount >= 4) {
                            $responseData['error_message'] = 'Max retry attempts reached';
                            $this->log->update((int)$pay->id, [
                                'status' => 'failed',
                                'response_data' => json_encode($responseData, JSON_UNESCAPED_UNICODE)
                            ]);
                            
                            $this->eventDispatcher->dispatchAsync('admin_notification.requested', [
                                'type' => 'payment_failed_max_retries',
                                'title' => 'خطای بحرانی پرداخت',
                                'body' => "پرداخت شماره {$pay->id} پس از ۵ بار تلاش ناموفق بود. کاربر: {$pay->user_id}، مبلغ: {$pay->amount}",
                                'data' => ['payment_id' => $pay->id, 'user_id' => $pay->user_id, 'amount' => $pay->amount],
                                'priority' => 'high'
                            ]);
                        }
                    }
                } catch (\Throwable $innerEx) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    $results['failed']++;
                    $this->logger->error('payment.reconciliation.inner_failed', [
                        'payment_id' => $pay->id,
                        'error' => $innerEx->getMessage()
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('payment.reconciliation.failed', [
                'error' => $e->getMessage()
            ]);
        }

        return $results;
    }
}

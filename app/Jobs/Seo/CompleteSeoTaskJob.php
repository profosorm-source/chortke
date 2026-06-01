<?php

declare(strict_types=1);

namespace App\Jobs\Seo;

class CompleteSeoTaskJob
{
    private \App\Services\Seo\AdsSeoService $adsService;
    private \Core\EventDispatcher $eventDispatcher;
    public function __construct(
        \App\Services\Seo\AdsSeoService $adsService,
        \Core\EventDispatcher $eventDispatcher
    ) {        $this->adsService = $adsService;
        $this->eventDispatcher = $eventDispatcher;
}

public function handle(int $executionId, int $userId, array $engagementData): array
    {

        
                if ($execution->status !== 'started') {
                    return ['success' => false, 'message' => 'این تسک در حال پردازش یا تکمیل شده است'];
                }
        
                // Mark as processing
                $this->adsService->updateExecutionStatus($executionId, 'processing');

                // 🚀 Send heavy work to background queue
                if ($this->eventDispatcher) {
                    $this->eventDispatcher->dispatchAsync('seo_task.process_requested', [
                        'execution_id' => $executionId,
                        'user_id' => $userId,
                        'ad_id' => $execution->ad_id,
                        'engagement_data' => $engagementData
                    ]);
                } else {
                    // Fallback to synchronous if no dispatcher available
                    return $this->processTaskAsync($executionId, $userId, $execution->ad_id, $engagementData);
                }

                return [
                    'success' => true,
                    'message' => 'تسک شما دریافت شد و پاداش در حال محاسبه است.',
                    'status' => 'processing'
                ];
            }
}

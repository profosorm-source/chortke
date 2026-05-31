<?php

return [
    'driver' => env('QUEUE_DRIVER', 'database'),
    'visibility_timeout' => (int)env('QUEUE_VISIBILITY_TIMEOUT', 90),

    'allowed_jobs' => [
        \App\Jobs\ProcessNotificationJob::class,
        \App\Jobs\ApplyWeeklyProfitLossJob::class,
        \App\Jobs\LogPerformanceJob::class,
        \App\Jobs\PersistBulkInAppNotificationJob::class,
        \App\Jobs\SendEmailJob::class,
        \App\Jobs\UpdateFraudScoreJob::class,
        \App\Jobs\InvestmentProfitDistributionJob::class,
        \App\Jobs\NotificationCleanupJob::class,
        \App\Jobs\EscrowTimeoutJob::class,
        \App\Jobs\CacheWarmupJob::class,
        \App\Jobs\ScoreRecalculationJob::class,
        \App\Jobs\PredictionGameSettlementJob::class,
        \App\Jobs\VitrineListingExpiryJob::class,
        \App\Jobs\InfluencerOrderTimeoutJob::class,
        \App\Jobs\SocialTaskApprovalReminderJob::class,
        \App\Jobs\AggregateAnalyticsJob::class,
    ],
];

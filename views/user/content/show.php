<?php $title = 'جزئیات محتوا'; $layout = 'user'; ob_start(); 

$statusLabels = [
    'pending' => ['در انتظار بررسی', 'badge-warning', 'hourglass_empty'],
    'under_review' => ['در حال بررسی', 'badge-info', 'rate_review'],
    'approved' => ['تأیید شده', 'badge-success', 'check_circle'],
    'published' => ['منتشر شده', 'badge-primary', 'public'],
    'rejected' => ['رد شده', 'badge-danger', 'cancel'],
    'suspended' => ['تعلیق شده', 'badge-dark', 'block'],
];
$sl = $statusLabels[$submission->status] ?? ['نامشخص', 'badge-secondary', 'help'];
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-content.css') ?>">
<div class="content-header">
    <h4><i class="material-icons">movie</i> <?= e($submission->title) ?></h4>
    <a href="<?= url('/content') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="material-icons">arrow_back</i> بازگشت
    </a>
</div>
<div class="card">
    <div class="card-header">
        <h5>اطلاعات محتوا</h5>
        <span class="badge <?= e($sl[1]) ?>" style="font-size: 13px;">
            <i class="material-icons" style="font-size: 14px; vertical-align: middle;"><?= e($sl[2]) ?></i>
            <?= e($sl[0]) ?>
        </span>
    </div>
    <div class="card-body">
        <div class="detail-grid">
            <div class="detail-item">
                <span class="detail-label">پلتفرم</span>
                <span class="detail-value">
                    <?= $submission->platform === 'aparat' ? 'آپارات' : 'یوتیوب' ?>
                </span>
            </div>
            <div class="detail-item">
                <span class="detail-label">لینک ویدیو</span>
                <span class="detail-value">
                    <a href="<?= e($submission->video_url) ?>" target="_blank" dir="ltr">
                        <?= e(\mb_substr($submission->video_url, 0, 60)) ?>...
                    </a>
                </span>
            </div>
            <div class="detail-item">
                <span class="detail-label">دسته‌بندی</span>
                <span class="detail-value"><?= e($submission->category ?? 'تعیین نشده') ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">تاریخ ارسال</span>
                <span class="detail-value"><?= e(to_jalali($submission->created_at ?? '')) ?></span>
            </div>
            <?php if ($submission->approved_at): ?>
            <div class="detail-item">
                <span class="detail-label">تاریخ تأیید</span>
                <span class="detail-value"><?= e(to_jalali($submission->approved_at)) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($submission->published_at): ?>
            <div class="detail-item">
                <span class="detail-label">تاریخ انتشار</span>
                <span class="detail-value"><?= e(to_jalali($submission->published_at)) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($submission->published_url): ?>
            <div class="detail-item">
                <span class="detail-label">لینک انتشار</span>
                <span class="detail-value">
                    <a href="<?= e($submission->published_url) ?>" target="_blank">مشاهده در کانال مجموعه</a>
                </span>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($submission->description): ?>
        <div class="mt-3">
            <strong>توضیحات:</strong>
            <p style="margin-top: 5px; color: #666;"><?= \nl2br(e($submission->description)) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($submission->status === 'rejected' && $submission->rejection_reason): ?>
        <div class="alert alert-danger mt-3">
            <i class="material-icons">error</i>
            <div>
                <strong>دلیل رد:</strong>
                <p style="margin: 5px 0 0;"><?= \nl2br(e($submission->rejection_reason)) ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- لیست درآمدها -->
<?php if (!empty($revenues)): ?>
<div class="card mt-4">
    <div class="card-header">
        <h5><i class="material-icons">monetization_on</i> تاریخچه درآمد</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>دوره</th>
                        <th>بازدید</th>
                        <th>درآمد کل</th>
                        <th>سهم شما</th>
                        <th>مالیات</th>
                        <th>خالص دریافتی</th>
                        <th>وضعیت</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($revenues as $rev): ?>
                    <tr>
                        <td><?= e($rev->period) ?></td>
                        <td><?= number_format($rev->views) ?></td>
                        <td><?= number_format($rev->total_revenue) ?></td>
                        <td><?= number_format($rev->user_share_amount) ?> (<?= e($rev->user_share_percent) ?>%)</td>
                        <td><?= number_format($rev->tax_amount) ?></td>
                        <td><strong><?= number_format($rev->net_user_amount) ?></strong></td>
                        <td>
                            <?php
                            $revStatusLabels = [
                                'pending' => ['در انتظار', 'badge-warning'],
                                'approved' => ['تأیید شده', 'badge-info'],
                                'paid' => ['پرداخت شده', 'badge-success'],
                                'cancelled' => ['لغو شده', 'badge-danger'],
                            ];
                            $rsl = $revStatusLabels[$rev->status] ?? ['نامشخص', 'badge-secondary'];
                            ?>
                            <span class="badge <?= e($rsl[1]) ?>"><?= e($rsl[0]) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php elseif ($submission->status === 'published'): ?>
<div class="alert alert-info mt-4">
    <i class="material-icons">schedule</i>
    <span>درآمد شما پس از گذشت ۲ ماه از تاریخ تأیید محاسبه و ثبت خواهد شد.</span>
</div>
<?php endif; ?>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>
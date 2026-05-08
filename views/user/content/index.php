<?php
/**
 * صفحه لیست محتواهای کاربر
 * 
 * @var object $user
 * @var array $submissions
 * @var array $stats
 * @var float $totalRevenue
 * @var float $pendingRevenue
 * @var int $total
 * @var int $totalPages
 * @var int $currentPage
 * @var string|null $currentStatus
 */

$title = 'محتواهای من';
$layout = 'user';
ob_start();

// Helper function برای escape کردن امن
function safe_escape($value) {
    return e((string)$value, ENT_QUOTES, 'UTF-8');
}

// Helper function برای badge status
function getStatusBadge($status) {
    $statusMap = [
        'pending' => ['label' => 'در انتظار', 'class' => 'badge-warning', 'icon' => 'hourglass_empty'],
        'under_review' => ['label' => 'در حال بررسی', 'class' => 'badge-info', 'icon' => 'rate_review'],
        'approved' => ['label' => 'تأیید شده', 'class' => 'badge-success', 'icon' => 'check_circle'],
        'published' => ['label' => 'منتشر شده', 'class' => 'badge-primary', 'icon' => 'public'],
        'rejected' => ['label' => 'رد شده', 'class' => 'badge-danger', 'icon' => 'cancel'],
        'suspended' => ['label' => 'تعلیق', 'class' => 'badge-dark', 'icon' => 'block'],
    ];
    
    return $statusMap[$status] ?? ['label' => 'نامشخص', 'class' => 'badge-secondary', 'icon' => 'help'];
}
?>

<div class="content-header">
    <h4>
        <i class="material-icons">video_library</i> 
        کسب درآمد از استعداد
    </h4>
    <a href="<?= url('/content/create') ?>" class="btn btn-primary btn-sm">
        <i class="material-icons">add</i> 
        ارسال محتوای جدید
    </a>
</div>

<!-- آمار کلی -->
<div class="stats-grid" role="region" aria-label="آمار محتوا">
    <div class="stat-card stat-blue">
        <div class="stat-icon" aria-hidden="true">
            <i class="material-icons">folder</i>
        </div>
        <div class="stat-info">
            <span class="stat-label">کل محتواها</span>
            <span class="stat-value"><?= safe_escape($stats['total'] ?? 0) ?></span>
        </div>
    </div>
    
    <div class="stat-card stat-orange">
        <div class="stat-icon" aria-hidden="true">
            <i class="material-icons">hourglass_empty</i>
        </div>
        <div class="stat-info">
            <span class="stat-label">در انتظار</span>
            <span class="stat-value"><?= safe_escape($stats['pending'] ?? 0) ?></span>
        </div>
    </div>
    
    <div class="stat-card stat-green">
        <div class="stat-icon" aria-hidden="true">
            <i class="material-icons">check_circle</i>
        </div>
        <div class="stat-info">
            <span class="stat-label">منتشر شده</span>
            <span class="stat-value"><?= safe_escape($stats['published'] ?? 0) ?></span>
        </div>
    </div>
    
    <div class="stat-card stat-purple">
        <div class="stat-icon" aria-hidden="true">
            <i class="material-icons">account_balance_wallet</i>
        </div>
        <div class="stat-info">
            <span class="stat-label">درآمد دریافتی</span>
            <span class="stat-value"><?= number_format($totalRevenue ?? 0) ?></span>
        </div>
    </div>
</div>

<?php if (($pendingRevenue ?? 0) > 0): ?>
<div class="alert alert-info" role="alert">
    <i class="material-icons" aria-hidden="true">info</i>
    <span>
        مبلغ <strong><?= number_format($pendingRevenue) ?></strong> تومان 
        در انتظار پرداخت است.
    </span>
</div>
<?php endif; ?>

<!-- فیلتر -->
<div class="card">
    <div class="card-header">
        <h5>لیست محتواها</h5>
        <div class="filter-tabs" role="tablist" aria-label="فیلتر وضعیت">
            <?php
            $filters = [
                '' => 'همه',
                'pending' => 'در انتظار',
                'approved' => 'تأیید شده',
                'published' => 'منتشر شده',
                'rejected' => 'رد شده'
            ];
            
            foreach ($filters as $value => $label):
                $isActive = ($value === ($currentStatus ?? ''));
                $url = url('/content' . ($value ? '?status=' . $value : ''));
            ?>
            <a href="<?= safe_escape($url) ?>" 
               class="tab <?= $isActive ? 'active' : '' ?>"
               role="tab"
               aria-selected="<?= $isActive ? 'true' : 'false' ?>">
                <?= safe_escape($label) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div class="card-body">
        <?php if (empty($submissions)): ?>
            <div class="empty-state" role="status">
                <i class="material-icons" aria-hidden="true">movie_creation</i>
                <p>هنوز محتوایی ارسال نکرده‌اید.</p>
                <a href="<?= url('/content/create') ?>" class="btn btn-primary">
                    ارسال اولین محتوا
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table" role="table" aria-label="لیست محتواها">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">عنوان</th>
                            <th scope="col">پلتفرم</th>
                            <th scope="col">وضعیت</th>
                            <th scope="col">تاریخ ارسال</th>
                            <th scope="col">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $item): 
                            $badge = getStatusBadge($item->status ?? 'pending');
                        ?>
                        <tr>
                            <td><?= safe_escape($item->id ?? '') ?></td>
                            <td>
                                <a href="<?= url('/content/' . ($item->id ?? '')) ?>"
                                   aria-label="مشاهده جزئیات <?= safe_escape($item->title ?? '') ?>">
                                    <?= safe_escape($item->title ?? '') ?>
                                </a>
                            </td>
                            <td>
                                <?php if (($item->platform ?? '') === 'aparat'): ?>
                                    <span class="badge badge-info">آپارات</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">یوتیوب</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= safe_escape($badge['class']) ?>" 
                                      role="status">
                                    <?= safe_escape($badge['label']) ?>
                                </span>
                            </td>
                            <td>
                                <time datetime="<?= safe_escape($item->created_at ?? '') ?>">
                                    <?= safe_escape(to_jalali($item->created_at ?? '')) ?>
                                </time>
                            </td>
                            <td>
                                <a href="<?= url('/content/' . ($item->id ?? '')) ?>" 
                                   class="btn btn-sm btn-outline-primary"
                                   aria-label="مشاهده محتوای <?= safe_escape($item->title ?? '') ?>">
                                    <i class="material-icons" aria-hidden="true">visibility</i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- صفحه‌بندی -->
            <?php if (($totalPages ?? 1) > 1): ?>
            <nav class="pagination-wrapper" aria-label="صفحه‌بندی">
                <div class="pagination" role="navigation">
                    <?php for ($i = 1; $i <= $totalPages; $i++): 
                        $statusParam = $currentStatus ? '&status=' . urlencode($currentStatus) : '';
                        $pageUrl = url('/content?page=' . $i . $statusParam);
                        $isActive = $i === ($currentPage ?? 1);
                    ?>
                    <a href="<?= safe_escape($pageUrl) ?>"
                       class="page-link <?= $isActive ? 'active' : '' ?>"
                       aria-label="صفحه <?= $i ?>"
                       <?= $isActive ? 'aria-current="page"' : '' ?>>
                        <?= safe_escape($i) ?>
                    </a>
                    <?php endfor; ?>
                </div>
            </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>
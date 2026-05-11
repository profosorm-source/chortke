<?php
$title = 'آگهی‌های من';
$layout = 'user';
ob_start();

// Quick badge helper
function getStatusBadge($status) {
    return match($status) {
        'active'    => '<span class="badge bg-soft-success text-success rounded-pill px-3">فعال</span>',
        'pending'   => '<span class="badge bg-soft-warning text-warning rounded-pill px-3">در انتظار بررسی</span>',
        'completed' => '<span class="badge bg-soft-secondary text-secondary rounded-pill px-3">پایان یافته</span>',
        'rejected'  => '<span class="badge bg-soft-danger text-danger rounded-pill px-3">رد شده</span>',
        default     => '<span class="badge bg-light text-dark">نامعلوم</span>'
    };
}
?>

<style>
    .stat-card {
        background: white;
        border-radius: 16px;
        border: 1px solid rgba(0,0,0,0.05);
        padding: 20px;
        box-shadow: 0 4px 20px -5px rgba(0,0,0,0.03);
        transition: transform 0.2s;
    }
    .stat-card:hover { transform: translateY(-3px); }
    .stat-icon {
        width: 48px; height: 48px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center; font-size: 24px;
    }
    .bg-soft-success { background: #d1fae5; color: #065f46; }
    .bg-soft-warning { background: #fef3c7; color: #92400e; }
    .bg-soft-danger  { background: #fee2e2; color: #991b1b; }
    .bg-soft-secondary { background: #f3f4f6; color: #374151; }

    .data-table {
        border-radius: 16px;
        overflow: hidden;
        border-collapse: separate;
        border-spacing: 0 10px;
        background: transparent !important;
    }
    .data-table thead th {
        border: none; color: #6b7280; font-size: 13px; padding: 12px 20px;
    }
    .data-table tbody tr {
        background: white;
        box-shadow: 0 2px 8px rgba(0,0,0,0.02);
        transition: all 0.2s;
    }
    .data-table tbody tr:hover {
        background: #fafafa; transform: scale(1.005);
    }
    .data-table tbody td {
        border: none; vertical-align: middle; padding: 16px 20px;
    }
    .data-table tbody td:first-child { border-radius: 0 12px 12px 0; }
    .data-table tbody td:last-child  { border-radius: 12px 0 0 12px; }

    /* Switch component override */
    .form-switch .form-check-input { width: 2.5em; cursor: pointer; }
</style>

<div class="container py-4">
    
    <!-- HEADER & CALL TO ACTION -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold"><i class="material-icons align-middle text-primary me-1">campaign</i> آگهی‌های من</h3>
            <p class="text-muted small mb-0">مدیریت جامع و رصد هوشمند تمامی کمپین‌های فعال شما.</p>
        </div>
        <a href="<?= url('/ads/create') ?>" class="btn btn-primary btn-lg rounded-pill px-4 shadow-lg">
            <i class="material-icons align-middle me-1">add_circle</i> ثبت آگهی جدید
        </a>
    </div>

    <!-- KEY METRICS -->
    <div class="row g-3 mb-5">
        <div class="col-md-3 col-6">
            <div class="stat-card d-flex align-items-center">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3"><i class="material-icons">summarize</i></div>
                <div>
                    <small class="text-muted d-block">کل تبلیغات</small>
                    <h4 class="fw-bold mb-0"><?= number_format($summary['total_count'] ?? 0) ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card d-flex align-items-center">
                <div class="stat-icon bg-success bg-opacity-10 text-success me-3"><i class="material-icons">payments</i></div>
                <div>
                    <small class="text-muted d-block">مجموع بودجه</small>
                    <h5 class="fw-bold mb-0"><?= number_format((float)($summary['total_invested'] ?? 0)) ?> <small class="small fw-normal">تومان</small></h5>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card d-flex align-items-center">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3"><i class="material-icons">visibility</i></div>
                <div>
                    <small class="text-muted d-block">کل نمایش‌ها</small>
                    <h4 class="fw-bold mb-0"><?= number_format((int)($summary['total_impressions'] ?? 0)) ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card d-flex align-items-center">
                <div class="stat-icon bg-danger bg-opacity-10 text-danger me-3"><i class="material-icons">ads_click</i></div>
                <div>
                    <small class="text-muted d-block">کل کلیک‌ها</small>
                    <h4 class="fw-bold mb-0"><?= number_format((int)($summary['total_clicks'] ?? 0)) ?></h4>
                </div>
            </div>
        </div>
    </div>

    <!-- UNIFIED HISTORY TABLE -->
    <div class="table-responsive">
        <table class="table data-table align-middle">
            <thead>
                <tr>
                    <th>عنوان و نوع</th>
                    <th>وضعیت</th>
                    <th>بودجه باقی‌مانده</th>
                    <th>نمایش / کلیک</th>
                    <th>تاریخ ثبت</th>
                    <th>عملیات و مدیریت</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($ads)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="material-icons display-5 d-block mb-2 opacity-50">history</i>
                            هنوز هیچ آگهی یا کمپینی ثبت نکرده‌اید.
                        </td>
                    </tr>
                <?php else: foreach($ads as $ad): 
                    // Construct accurate Deep-Link mapping for detailed statistics/reviews
                    $detailUrl = url("/ads/{$ad['id']}");
                ?>
                    <tr id="row-<?= $ad['id'] ?>">
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="bg-light p-2 rounded-3 me-3 text-secondary">
                                    <i class="material-icons"><?= match($ad['type']){ 'banner'=>'dashboard','notification'=>'notifications','seo'=>'language','social_task'=>'group',default=>'ads_click' } ?></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold"><?= e($ad['title']) ?></h6>
                                    <small class="text-muted opacity-75"><?= match($ad['type']){ 'banner'=>'بنری','notification'=>'پوش نوتیفیکیشن','seo'=>'سئو','social_task'=>'سوشیال',default=>'سایر' } ?></small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?= getStatusBadge($ad['status']) ?>
                        </td>
                        <td class="fw-bold text-indigo">
                            <?= number_format((float)$ad['remaining_budget']) ?>
                            <small class="fw-normal opacity-75">ت</small>
                        </td>
                        <td>
                            <span class="text-muted small"><i class="material-icons small align-middle text-warning">visibility</i> <?= number_format((int)$ad['impressions']) ?></span>
                            <br>
                            <span class="text-muted small"><i class="material-icons small align-middle text-danger">touch_app</i> <?= number_format((int)$ad['clicks']) ?></span>
                        </td>
                        <td class="small text-muted">
                            <?= date('Y/m/d', strtotime($ad['created_at'])) ?>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="form-check form-switch mb-0" title="روشن/خاموش">
                                    <input class="form-check-input" type="checkbox" 
                                           onchange="toggleAdStatus(<?= $ad['id'] ?>)"
                                           <?= (int)$ad['is_active'] === 1 ? 'checked' : '' ?>
                                           <?= in_array($ad['status'], ['completed','rejected']) ? 'disabled' : '' ?>>
                                </div>
                                
                                <?php if($detailUrl !== '#'): ?>
                                    <a href="<?= $detailUrl ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3" title="جزئیات و مدیریت بررسی">
                                        <i class="material-icons align-middle fs-6">analytics</i> بررسی
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

</div>

<script>
async function toggleAdStatus(id) {
    try {
        const formData = new FormData();
        formData.append('ad_id', id);

        const res = await fetch('<?= url("/ads/toggle-status") ?>', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '<?= csrf_token() ?>'
            },
            body: formData
        });
        const result = await res.json();
        if(result.success) {
             // Instant feedback toast could be called here
             console.log(result.message);
        } else {
            alert(result.message);
        }
    } catch (e) {
        alert('خطا در انجام عملیات.');
    }
}
</script>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>

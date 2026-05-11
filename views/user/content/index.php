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

<!-- ماشین حساب شبیه‌ساز درآمد (Glassmorphism UI) -->
<div class="card border-0 shadow-sm mb-4" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; border-radius: 16px; overflow: hidden;">
    <div class="card-body p-4 position-relative">
        <div style="position: absolute; top: -20px; right: -20px; opacity: 0.1;">
            <i class="material-icons" style="font-size: 150px;">calculate</i>
        </div>
        
        <div class="row align-items-center position-relative">
            <div class="col-lg-7">
                <h5 class="fw-bold mb-1 d-flex align-items-center gap-2">
                    <span class="material-icons">equalizer</span>
                    شبیه‌ساز درآمد احتمالی شما
                </h5>
                <p class="opacity-75 mb-4" style="font-size: 13px;">با حرکت دادن اهرم‌ها، پتانسیل درآمد ماهیانه خود را تخمین بزنید.</p>
                
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1 small">
                        <span>بازدید تخمینی ماهیانه:</span>
                        <span id="viewVal" class="fw-bold text-warning">۵۰,۰۰۰</span>
                    </div>
                    <input type="range" class="form-range custom-slider" id="rangeViews" min="1000" max="1000000" step="1000" value="50000">
                </div>
                
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1 small">
                        <span>CPM تخمینی (درآمد در ۱۰۰۰ بازدید):</span>
                        <span id="cpmVal" class="fw-bold text-warning">۵ دلار</span>
                    </div>
                    <input type="range" class="form-range custom-slider" id="rangeCpm" min="0.5" max="15" step="0.5" value="5">
                </div>
            </div>
            
            <div class="col-lg-5 text-center">
                <div class="glass-output p-3" style="background: rgba(255,255,255,0.15); border-radius: 12px; backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.2);">
                    <p class="mb-0 small opacity-75">سهم خالص شما (با کسر ۶۰٪ سهم کاربر)</p>
                    <div class="h1 fw-bold mt-1 mb-1" id="finalTotal">۱۵۰.۰۰</div>
                    <span class="h6">دلار (USDT)</span>
                    <hr style="border-color: rgba(255,255,255,0.2);">
                    <div class="small text-white-50">این رقم تقریبی بوده و بسته به تعامل مخاطبان متغیر است.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.custom-slider::-webkit-slider-thumb { background: #fff; box-shadow: 0 0 10px rgba(0,0,0,0.2); }
.glass-output { animation: pulseGlow 3s infinite alternate; }
@keyframes pulseGlow { from { box-shadow: 0 0 5px rgba(255,255,255,0.1); } to { box-shadow: 0 0 20px rgba(255,255,255,0.3); } }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const rView = document.getElementById('rangeViews');
    const rCpm = document.getElementById('rangeCpm');
    const vView = document.getElementById('viewVal');
    const vCpm = document.getElementById('cpmVal');
    const out = document.getElementById('finalTotal');
    
    // درصد سهم پیش‌فرض
    const userSharePct = 0.60; 

    function updateCalc() {
        const v = parseInt(rView.value);
        const c = parseFloat(rCpm.value);
        
        vView.textContent = v.toLocaleString('fa-IR');
        vCpm.textContent = c.toLocaleString('fa-IR') + ' دلار';
        
        // (بازدید / ۱۰۰۰) * CPM * سهم کاربر
        const total = (v / 1000) * c * userSharePct;
        
        // انیمیشن تغییر عدد
        animateValue(out, parseFloat(out.innerText.replace(/,/g,'')) || 0, total, 300);
    }

    function animateValue(obj, start, end, duration) {
        let startTimestamp = null;
        const step = (timestamp) => {
            if (!startTimestamp) startTimestamp = timestamp;
            const progress = Math.min((timestamp - startTimestamp) / duration, 1);
            const val = (progress * (end - start) + start).toFixed(2);
            obj.innerHTML = Number(val).toLocaleString('fa-IR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            if (progress < 1) {
                window.requestAnimationFrame(step);
            }
        };
        window.requestAnimationFrame(step);
    }

    rView.addEventListener('input', updateCalc);
    rCpm.addEventListener('input', updateCalc);
    updateCalc(); // Initial load
});
</script>

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
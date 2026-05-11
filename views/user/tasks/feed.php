<?php $title = 'ویترین تسک‌های درآمدزا'; ?>
<?php include_once dirname(__DIR__, 2) . '/partials/user/header.php'; ?>

<link rel="stylesheet" href="<?= asset('css/glassmorphism.css') ?>">
<style>
    :root {
        --accent-gradient: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
        --card-bg: rgba(255, 255, 255, 0.7);
        --glass-border: rgba(255, 255, 255, 0.3);
    }
    [data-theme="dark"] {
        --card-bg: rgba(30, 41, 59, 0.7);
        --glass-border: rgba(255, 255, 255, 0.1);
    }

    .tasks-hero {
        background: var(--accent-gradient);
        border-radius: 1.5rem;
        padding: 3rem 2rem;
        color: white;
        position: relative;
        overflow: hidden;
        box-shadow: 0 20px 40px rgba(99, 102, 241, 0.2);
        margin-bottom: 2rem;
    }
    .hero-pattern {
        position: absolute;
        top: -50%; right: -20%;
        width: 500px; height: 500px;
        background: rgba(255,255,255,0.1);
        border-radius: 50%;
        filter: blur(60px);
    }

    .filter-card {
        background: var(--card-bg);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid var(--glass-border);
        border-radius: 1.25rem;
        padding: 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 8px 32px rgba(0,0,0,0.05);
    }

    .task-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 1.5rem;
    }

    .task-card {
        background: var(--card-bg);
        backdrop-filter: blur(10px);
        border: 1px solid var(--glass-border);
        border-radius: 1.25rem;
        padding: 1.5rem;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        position: relative;
        overflow: hidden;
    }
    .task-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        border-color: #6366f1;
    }

    .platform-badge {
        padding: 0.4rem 0.8rem;
        border-radius: 2rem;
        font-size: 0.75rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    .badge-social { background: rgba(168, 85, 247, 0.1); color: #a855f7; }
    .badge-seo { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
    .badge-custom_task { background: rgba(16, 185, 129, 0.1); color: #10b981; }

    .payout-pill {
        background: var(--accent-gradient);
        color: white;
        font-weight: 700;
        padding: 0.5rem 1rem;
        border-radius: 0.75rem;
        font-size: 1.1rem;
    }

    .form-glass {
        background: rgba(255,255,255,0.2);
        border: 1px solid rgba(255,255,255,0.3);
        border-radius: 0.75rem;
        color: inherit;
    }
    [data-theme="dark"] .form-glass {
        background: rgba(0,0,0,0.2);
        border: 1px solid rgba(255,255,255,0.1);
    }
</style>

<div class="container-fluid py-4" dir="rtl">
    <!-- Hero Section -->
    <div class="tasks-hero">
        <div class="hero-pattern"></div>
        <div class="row align-items-center position-relative" style="z-index:2;">
            <div class="col-md-8">
                <h1 class="display-5 fw-bold mb-3 text-white">بازار کسب درآمد هوشمند</h1>
                <p class="lead opacity-75">همین حالا از بین هزاران تسک معتبر، کسب درآمد خود را شروع کنید. فیلتر کنید، کلیک کنید و پول درآورید.</p>
                <div class="d-flex gap-3 mt-4">
                    <div class="text-center bg-white bg-opacity-10 px-3 py-2 rounded-3">
                        <div class="h4 mb-0 text-white fw-bold"><?= number_format($totalTasks) ?></div>
                        <div class="small opacity-75 text-white">تسک‌های قابل انجام</div>
                    </div>
                    <div class="text-center bg-white bg-opacity-10 px-3 py-2 rounded-3">
                        <div class="h4 mb-0 text-white fw-bold"><?= number_format($totalDone) ?></div>
                        <div class="small opacity-75 text-white">تسک‌های موفق شما</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-center d-none d-md-block">
                <span class="material-icons" style="font-size: 120px; opacity: 0.5;">rocket_launch</span>
            </div>
        </div>
    </div>

    <!-- Smart Filters -->
    <div class="filter-card">
        <form action="" method="GET" id="filterForm">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-bold small">جستجوی عنوان</label>
                    <div class="input-group">
                        <span class="input-group-text bg-transparent border-end-0"><i class="material-icons small">search</i></span>
                        <input type="text" name="q" class="form-control border-start-0 form-glass" placeholder="کلمه کلیدی..." value="<?= e($filters['q'] ?? '') ?>">
                    </div>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label fw-bold small">نوع درآمد</label>
                    <select name="type" class="form-select form-glass">
                        <option value="">همه موارد</option>
                        <option value="social" <?= ($filters['type']??'')==='social'?'selected':'' ?>>شبکه‌های اجتماعی</option>
                        <option value="seo" <?= ($filters['type']??'')==='seo'?'selected':'' ?>>بازدید و سئو</option>
                        <option value="custom_task" <?= ($filters['type']??'')==='custom_task'?'selected':'' ?>>تسک‌های اختصاصی</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-bold small">پلتفرم</label>
                    <select name="platform" class="form-select form-glass">
                        <option value="">همه پلتفرم‌ها</option>
                        <?php foreach($platforms as $p): if(empty($p->platform)) continue; ?>
                            <option value="<?= e($p->platform) ?>" <?= ($filters['platform']??'')===$p->platform?'selected':'' ?>><?= ucfirst(e($p->platform)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-bold small">محدوده پاداش (تومان)</label>
                    <div class="d-flex gap-2">
                        <input type="number" name="min_price" class="form-control form-glass" placeholder="از" value="<?= e($filters['min_price'] ?? '') ?>">
                        <input type="number" name="max_price" class="form-control form-glass" placeholder="تا" value="<?= e($filters['max_price'] ?? '') ?>">
                    </div>
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100 rounded-3 shadow-sm py-2"><i class="material-icons align-middle me-1">tune</i> فیلتر کن</button>
                    <?php if(!empty(array_filter($filters))): ?>
                        <a href="?" class="btn btn-light border rounded-3 py-2"><i class="material-icons">close</i></a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <!-- Results -->
    <?php if(empty($tasks)): ?>
        <div class="text-center py-5 bg-light rounded-4 border border-dashed">
            <span class="material-icons text-muted mb-3" style="font-size:60px;">sentiment_neutral</span>
            <h4 class="fw-bold">تسکی یافت نشد</h4>
            <p class="text-muted">متاسفانه تسک جدیدی مطابق با فیلترهای شما موجود نیست.</p>
            <a href="?" class="btn btn-outline-primary btn-sm mt-2">پاک کردن فیلترها</a>
        </div>
    <?php else: ?>
        <div class="task-grid">
            <?php foreach($tasks as $task): 
                $typeLabel = match($task->type) {
                    'social' => 'شبکه‌های اجتماعی',
                    'seo' => 'بازدید و گوگل',
                    'custom_task' => 'اختصاصی',
                    default => 'تسک عمومی'
                };
                $typeClass = 'badge-' . $task->type;
                $icon = match($task->platform) {
                    'instagram' => 'photo_camera',
                    'telegram' => 'telegram',
                    'twitter', 'x' => 'tag',
                    'google' => 'search',
                    default => 'assignment'
                };
                
                // Generate specific action URL
                $actionUrl = match($task->type) {
                    'social' => url("/social-tasks/view/{$task->id}"),
                    'seo' => url("/seo-tasks/perform/{$task->id}"),
                    'custom_task' => url("/custom-tasks/{$task->id}"),
                    default => '#'
                };
            ?>
                <div class="task-card">
                    <div>
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="platform-badge <?= $typeClass ?>">
                                <i class="material-icons small"><?= $icon ?></i>
                                <?= $typeLabel ?>
                            </span>
                            <div class="small text-muted"><i class="material-icons small align-middle">person</i> <?= e($task->advertiser_name ?? 'کارفرما') ?></div>
                        </div>
                        
                        <h5 class="fw-bold mb-2 lh-base"><?= e($task->title) ?></h5>
                        <p class="text-muted small mb-3 line-clamp-2" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                            <?= e(strip_tags($task->description)) ?>
                        </p>
                    </div>

                    <div class="border-top pt-3 mt-3 d-flex justify-content-between align-items-center">
                        <div>
                            <span class="small text-muted d-block mb-1">درآمد این تسک:</span>
                            <div class="payout-pill"><?= number_format((float)$task->price_per_task) ?> <span class="small" style="font-size:0.65rem">تومان</span></div>
                        </div>
                        <a href="<?= $actionUrl ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3 py-2 fw-bold">
                            شروع اجرا <i class="material-icons small align-middle ms-1">arrow_back</i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center pagination-pill">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?= ($i === $currentPage) ? 'active' : '' ?>">
                            <?php 
                                $qParams = $_GET;
                                $qParams['page'] = $i;
                            ?>
                            <a class="page-link" href="?<?= http_build_query($qParams) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

</div>

<?php include_once dirname(__DIR__, 2) . '/partials/user/footer.php'; ?>

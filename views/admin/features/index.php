<?php
$pageTitle = 'مدیریت پیشرفته فیچرها';
ob_start();
?>

<style>
.feature-card {
    transition: all 0.3s ease;
    border-right: 4px solid transparent;
}

.feature-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    border-right-color: #007bff;
}

.feature-card.enabled {
    border-right-color: #28a745;
}

.feature-card.disabled {
    border-right-color: #dc3545;
}

.badge-pill {
    padding: 0.4em 0.8em;
}

.percentage-slider {
    width: 100%;
}

.percentage-display {
    font-size: 1.2em;
    font-weight: bold;
    color: #007bff;
}

.tag-input {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    padding: 0.5rem;
    border: 1px solid #ced4da;
    border-radius: 0.25rem;
    min-height: 45px;
}

.tag {
    background: #007bff;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 3px;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.tag .remove {
    cursor: pointer;
    font-weight: bold;
}

.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 10px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
}

.stat-item {
    text-align: center;
}

.stat-value {
    font-size: 2em;
    font-weight: bold;
}

.stat-label {
    opacity: 0.9;
    font-size: 0.9em;
}

.targeting-section {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
}

.targeting-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.targeting-header i {
    color: #007bff;
}

.advanced-features {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    color: white;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.metric-chart {
    height: 200px;
    background: #f8f9fa;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6c757d;
}

.history-timeline {
    max-height: 300px;
    overflow-y: auto;
}

.timeline-item {
    border-left: 3px solid #007bff;
    padding-left: 1rem;
    margin-bottom: 1rem;
    position: relative;
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: -7px;
    top: 0;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #007bff;
}

.timeline {
    position: relative;
}

.timeline-item {
    border-left: 3px solid #007bff;
    padding-left: 1rem;
    margin-bottom: 1rem;
    position: relative;
}

.timeline-item .timeline-marker {
    position: absolute;
    left: -7px;
    top: 0;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #007bff;
}

.timeline-item .timeline-content {
    background: white;
    padding: 1rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.chart-placeholder {
    height: 200px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.tag-input input {
    border: none;
    outline: none;
    flex: 1;
    min-width: 120px;
}

.tag-input .tag {
    background: #007bff;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 3px;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    margin: 0.125rem;
}

.tag-input .tag .tag-remove {
    cursor: pointer;
    font-weight: bold;
    color: rgba(255,255,255,0.8);
}

.tag-input .tag .tag-remove:hover {
    color: white;
}

.percentage-slider {
    width: 100%;
}

.percentage-display {
    font-size: 1.2em;
    font-weight: bold;
    color: #007bff;
    text-align: center;
    padding: 0.5rem;
    background: #f8f9fa;
    border-radius: 4px;
    margin: 0.5rem 0;
}

.tag-input input {
    border: none;
    outline: none;
    flex: 1;
    min-width: 120px;
}

.tag-input .tag {
    background: #007bff;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 3px;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    margin: 0.125rem;
}

.tag-input .tag .tag-remove {
    cursor: pointer;
    font-weight: bold;
    color: rgba(255,255,255,0.8);
}

.tag-input .tag .tag-remove:hover {
    color: white;
}

.diff-view {
    background: #f8f9fa;
    border-radius: 4px;
    padding: 0.5rem;
    font-family: monospace;
    font-size: 0.875em;
}

.diff-line {
    margin-bottom: 0.25rem;
}

.diff-line code {
    background: #e9ecef;
    padding: 0.125rem 0.25rem;
    border-radius: 3px;
    font-weight: bold;
}</style>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>
            <i class="material-icons">flag</i>
            مدیریت فوق پیشرفته فیچرها (Feature Flags Ultimate)
        </h4>
        <div>
            <button class="btn btn-success" onclick="showCreateModal()">
                <i class="material-icons">add</i>
                افزودن فیچر جدید
            </button>
            <button class="btn btn-info" onclick="refreshStats()">
                <i class="material-icons">refresh</i>
                بروزرسانی آمار
            </button>
        </div>
    </div>

    <!-- Advanced Features Banner -->
    <div class="advanced-features mb-4">
        <h5>
            <i class="material-icons">stars</i>
            قابلیت‌های فوق پیشرفته فعال شده
        </h5>
        <div class="row">
            <div class="col-md-3">
                <i class="material-icons">location_on</i>
                <small>Targeting جغرافیایی</small>
            </div>
            <div class="col-md-3">
                <i class="material-icons">devices</i>
                <small>Targeting دستگاهی</small>
            </div>
            <div class="col-md-3">
                <i class="material-icons">schedule</i>
                <small>زمان‌بندی هوشمند</small>
            </div>
            <div class="col-md-3">
                <i class="material-icons">analytics</i>
                <small>متریک‌های پیشرفته</small>
            </div>
        </div>
    </div>

    <div class="stats-card">
        <h5 class="mb-3">
            <i class="material-icons">analytics</i>
            آمار فیچرها
        </h5>
        <div class="row">
            <div class="col-md-3">
                <div class="stat-item">
                    <div class="stat-value" id="totalFeatures"><?= count($features) ?></div>
                    <div class="stat-label">کل فیچرها</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-item">
                    <div class="stat-value text-success" id="activeFeatures">
                        <?= count(array_filter($features, fn($f) => $f->enabled)) ?>
                    </div>
                    <div class="stat-label">فعال</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-item">
                    <div class="stat-value text-warning" id="abTestingFeatures">
                        <?= count(array_filter($features, fn($f) => $f->enabled_percentage < 100)) ?>
                    </div>
                    <div class="stat-label">A/B Testing</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-item">
                    <div class="stat-value text-info" id="targetedFeatures">
                        <?= count(array_filter($features, fn($f) => 
                            ($f->enabled_for_roles || $f->enabled_for_users || 
                             $f->enabled_for_countries || $f->enabled_for_devices)
                        )) ?>
                    </div>
                    <div class="stat-label">Targeted</div>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info">
        <i class="material-icons">info</i>
        <strong>راهنما:</strong> 
        با Feature Flags می‌توانید بخش‌های مختلف را بدون تغییر کد فعال/غیرفعال کنید، 
        فیچرها را محدود به نقش‌ها یا کاربران خاص کنید، و A/B Testing انجام دهید.
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <input type="text" id="searchFeature" class="form-control" 
                           placeholder="جستجوی نام یا توضیحات...">
                </div>
                <div class="col-md-2">
                    <select id="filterStatus" class="form-control">
                        <option value="">همه وضعیت‌ها</option>
                        <option value="enabled">فعال</option>
                        <option value="disabled">غیرفعال</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select id="filterTargeting" class="form-control">
                        <option value="">همه انواع</option>
                        <option value="public">عمومی</option>
                        <option value="role">نقش</option>
                        <option value="user">کاربر</option>
                        <option value="country">کشور</option>
                        <option value="device">دستگاه</option>
                        <option value="route">مسیر</option>
                        <option value="age">سن</option>
                        <option value="time">زمان</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select id="filterEnvironment" class="form-control">
                        <option value="">همه محیط‌ها</option>
                        <option value="production">Production</option>
                        <option value="staging">Staging</option>
                        <option value="development">Development</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-outline-secondary w-100" onclick="clearFilters()">
                        پاک کردن فیلترها
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="featuresContainer">
        <?php foreach ($features as $feature): ?>
            <?php
            $rolesArray = $feature->enabled_for_roles ? json_decode($feature->enabled_for_roles, true) : [];
            $usersArray = $feature->enabled_for_users ? json_decode($feature->enabled_for_users, true) : [];
            $countriesArray = $feature->enabled_for_countries ? json_decode($feature->enabled_for_countries, true) : [];
            $devicesArray = $feature->enabled_for_devices ? json_decode($feature->enabled_for_devices, true) : [];
            $routesArray = $feature->enabled_for_routes ? json_decode($feature->enabled_for_routes, true) : [];
            $dependsOnArray = $feature->depends_on ? json_decode($feature->depends_on, true) : [];
            $environmentsArray = $feature->environments ? json_decode($feature->environments, true) : [];
            $tagsArray = $feature->tags ? json_decode($feature->tags, true) : [];
            ?>
            <div class="card feature-card mb-3" 
                 data-name="<?= e($feature->name) ?>"
                 data-status="<?= e($feature->enabled ? 'enabled' : 'disabled') ?>"
                 data-percentage="<?= e((int)$feature->enabled_percentage) ?>"
                 data-targeting="<?= e(
        (!empty($rolesArray) ? 'role ' : '') .
        (!empty($usersArray) ? 'user ' : '') .
        (!empty($countriesArray) ? 'country ' : '') .
        (!empty($devicesArray) ? 'device ' : '') .
        (!empty($routesArray) ? 'route ' : '') .
        (($feature->min_age || $feature->max_age) ? 'age ' : '') .
        (($feature->enabled_from || $feature->enabled_until) ? 'time ' : '')) ?>"
                 data-environment="<?= e(implode(' ', $environmentsArray)) ?>">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">
                            <code class="text-primary"><?= e($feature->name) ?></code>
                            <span class="badge ml-2 <?= $feature->enabled ? 'badge-success' : 'badge-secondary' ?>">
                                <i class="material-icons" style="font-size: 14px;">
                                    <?= $feature->enabled ? 'toggle_on' : 'toggle_off' ?>
                                </i>
                                <?= $feature->enabled ? 'فعال' : 'غیرفعال' ?>
                            </span>
                        </h5>
                        <small class="text-muted"><?= e($feature->description) ?></small>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-primary" onclick="showMetrics('<?= e($feature->name) ?>')">
                            <i class="material-icons">analytics</i>
                        </button>
                        <button class="btn btn-sm btn-outline-info" onclick="showHistory('<?= e($feature->name) ?>')">
                            <i class="material-icons">history</i>
                        </button>
                        <button class="btn btn-sm btn-outline-warning" onclick="editFeature('<?= e($feature->name) ?>')">
                            <i class="material-icons">edit</i>
                        </button>
                        <button class="btn btn-sm <?= $feature->enabled ? 'btn-outline-danger' : 'btn-outline-success' ?>" 
                                onclick="toggleFeature('<?= e($feature->name) ?>')">
                            <i class="material-icons">power_settings_new</i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="targeting-section">
                                <div class="targeting-header">
                                    <i class="material-icons">percent</i>
                                    <strong>درصد فعال‌سازی</strong>
                                </div>
                                <div class="percentage-display text-center">
                                    <?= e($feature->enabled_percentage) ?>%
                                </div>
                                <?php if ($feature->enabled_percentage < 100): ?>
                                    <small class="text-info d-block text-center">
                                        <i class="material-icons" style="font-size: 14px;">science</i>
                                        A/B Testing فعال
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="targeting-section">
                                <div class="targeting-header">
                                    <i class="material-icons">group</i>
                                    <strong>نقش‌ها</strong>
                                </div>
                                <?php if (!empty($rolesArray)): ?>
                                    <div class="tag-input">
                                        <?php foreach ($rolesArray as $role): ?>
                                            <span class="tag">
                                                <?= e($role) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <small class="text-muted">همه نقش‌ها</small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="targeting-section">
                                <div class="targeting-header">
                                    <i class="material-icons">location_on</i>
                                    <strong>کشورها</strong>
                                </div>
                                <?php if (!empty($countriesArray)): ?>
                                    <div class="tag-input">
                                        <?php foreach ($countriesArray as $country): ?>
                                            <span class="tag">
                                                <?= e($country) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <small class="text-muted">همه کشورها</small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="targeting-section">
                                <div class="targeting-header">
                                    <i class="material-icons">devices</i>
                                    <strong>دستگاه‌ها</strong>
                                </div>
                                <?php if (!empty($devicesArray)): ?>
                                    <div class="tag-input">
                                        <?php foreach ($devicesArray as $device): ?>
                                            <span class="tag">
                                                <?= e($device) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <small class="text-muted">همه دستگاه‌ها</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Advanced Targeting Row -->
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <div class="targeting-section">
                                <div class="targeting-header">
                                    <i class="material-icons">schedule</i>
                                    <strong>زمان‌بندی</strong>
                                </div>
                                <?php if ($feature->enabled_from || $feature->enabled_until): ?>
                                    <small class="d-block">
                                        از: <?= $feature->enabled_from ? e(date('Y/m/d H:i', strtotime($feature->enabled_from))) : 'همیشه' ?>
                                    </small>
                                    <small class="d-block">
                                        تا: <?= $feature->enabled_until ? e(date('Y/m/d H:i', strtotime($feature->enabled_until))) : 'همیشه' ?>
                                    </small>
                                <?php else: ?>
                                    <small class="text-muted">همیشه فعال</small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="targeting-section">
                                <div class="targeting-header">
                                    <i class="material-icons">person</i>
                                    <strong>سن</strong>
                                </div>
                                <?php if ($feature->min_age || $feature->max_age): ?>
                                    <small class="d-block">
                                        حداقل: <?= $feature->min_age ?? 'بدون محدودیت' ?>
                                    </small>
                                    <small class="d-block">
                                        حداکثر: <?= $feature->max_age ?? 'بدون محدودیت' ?>
                                    </small>
                                <?php else: ?>
                                    <small class="text-muted">همه سنین</small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="targeting-section">
                                <div class="targeting-header">
                                    <i class="material-icons">settings</i>
                                    <strong>متادیتا</strong>
                                </div>
                                <small class="text-muted">
                                    اولویت: <?= $feature->priority ?? 0 ?> |
                                    محیط: <?= implode(', ', $environmentsArray) ?: 'همه' ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="editFeatureModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="material-icons">tune</i>
                    ویرایش پیشرفته فیچر: <code id="modalFeatureName"></code>
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="editFeatureForm">
                    <input type="hidden" id="editFeatureName">
                    
                    <div class="form-group">
                        <label>
                            <i class="material-icons">description</i>
                            توضیحات
                        </label>
                        <textarea id="editDescription" class="form-control" rows="2"></textarea>
                    </div>

                    <div class="form-group">
                        <label>
                            <i class="material-icons">percent</i>
                            درصد فعال‌سازی (A/B Testing)
                        </label>
                        <div class="row align-items-center">
                            <div class="col-9">
                                <input type="range" id="editPercentage" class="percentage-slider" 
                                       min="0" max="100" value="100" step="5">
                            </div>
                            <div class="col-3">
                                <input type="number" id="editPercentageValue" class="form-control" 
                                       min="0" max="100" value="100">
                            </div>
                        </div>
                        <small class="text-muted">
                            با مقدار کمتر از 100، فقط درصدی از کاربران دسترسی خواهند داشت (برای تست تدریجی)
                        </small>
                    </div>

                    <!-- Advanced Targeting Fields -->
                    <div class="card border-info">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0">
                                <i class="material-icons">tune</i>
                                تنظیمات پیشرفته هدف‌گیری
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>
                                            <i class="material-icons">group</i>
                                            نقش‌ها (Roles)
                                        </label>
                                        <div class="tag-input" id="rolesTagInput">
                                            <input type="text" id="roleInput" class="border-0 flex-grow-1" 
                                                   placeholder="نقش را تایپ کرده و Enter بزنید..." 
                                                   style="outline: none; min-width: 200px;">
                                        </div>
                                        <small class="text-muted">مثال: admin, moderator, premium_user</small>
                                    </div>

                                    <div class="form-group">
                                        <label>
                                            <i class="material-icons">person</i>
                                            کاربران خاص (User IDs)
                                        </label>
                                        <div class="tag-input" id="usersTagInput">
                                            <input type="text" id="userInput" class="border-0 flex-grow-1" 
                                                   placeholder="User ID را تایپ کرده و Enter بزنید..." 
                                                   style="outline: none; min-width: 200px;">
                                        </div>
                                        <small class="text-muted">مثال: 123, 456, 789</small>
                                    </div>

                                    <div class="form-group">
                                        <label>
                                            <i class="material-icons">location_on</i>
                                            کشورها (Countries)
                                        </label>
                                        <div class="tag-input" id="countriesTagInput">
                                            <input type="text" id="countryInput" class="border-0 flex-grow-1" 
                                                   placeholder="کد کشور را تایپ کرده و Enter بزنید..." 
                                                   style="outline: none; min-width: 200px;">
                                        </div>
                                        <small class="text-muted">مثال: IR, US, GB, DE</small>
                                    </div>

                                    <div class="form-group">
                                        <label>
                                            <i class="material-icons">devices</i>
                                            دستگاه‌ها (Devices)
                                        </label>
                                        <div class="tag-input" id="devicesTagInput">
                                            <input type="text" id="deviceInput" class="border-0 flex-grow-1" 
                                                   placeholder="نوع دستگاه را تایپ کرده و Enter بزنید..." 
                                                   style="outline: none; min-width: 200px;">
                                        </div>
                                        <small class="text-muted">مثال: mobile, desktop, tablet</small>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>
                                            <i class="material-icons">route</i>
                                            مسیرها (Routes)
                                        </label>
                                        <div class="tag-input" id="routesTagInput">
                                            <input type="text" id="routeInput" class="border-0 flex-grow-1" 
                                                   placeholder="مسیر را تایپ کرده و Enter بزنید..." 
                                                   style="outline: none; min-width: 200px;">
                                        </div>
                                        <small class="text-muted">مثال: /admin/*, /api/v1/*</small>
                                    </div>

                                    <div class="form-group">
                                        <label>
                                            <i class="material-icons">person</i>
                                            محدوده سنی (Age Range)
                                        </label>
                                        <div class="row">
                                            <div class="col-6">
                                                <input type="number" id="editMinAge" class="form-control" 
                                                       placeholder="حداقل سن" min="0" max="120">
                                            </div>
                                            <div class="col-6">
                                                <input type="number" id="editMaxAge" class="form-control" 
                                                       placeholder="حداکثر سن" min="0" max="120">
                                            </div>
                                        </div>
                                        <small class="text-muted">خالی بگذارید برای بدون محدودیت</small>
                                    </div>

                                    <div class="form-group">
                                        <label>
                                            <i class="material-icons">schedule</i>
                                            زمان‌بندی (Scheduling)
                                        </label>
                                        <div class="row">
                                            <div class="col-6">
                                                <input type="datetime-local" id="editEnabledFrom" class="form-control">
                                                <small class="text-muted">از تاریخ</small>
                                            </div>
                                            <div class="col-6">
                                                <input type="datetime-local" id="editEnabledUntil" class="form-control">
                                                <small class="text-muted">تا تاریخ</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>
                                            <i class="material-icons">settings</i>
                                            تنظیمات پیشرفته
                                        </label>
                                        <div class="row">
                                            <div class="col-6">
                                                <input type="number" id="editPriority" class="form-control" 
                                                       placeholder="اولویت" min="0" max="100" value="0">
                                            </div>
                                            <div class="col-6">
                                                <div class="tag-input" id="environmentsTagInput">
                                                    <input type="text" id="environmentInput" class="border-0 flex-grow-1" 
                                                           placeholder="محیط..." 
                                                           style="outline: none; min-width: 100px;">
                                                </div>
                                                <small class="text-muted">محیط‌ها: production, staging</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-light">
                        <strong>
                            <i class="material-icons">preview</i>
                            پیش‌نمایش تنظیمات:
                        </strong>
                        <div id="settingsPreview" class="mt-2"></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">بستن</button>
                <button type="button" class="btn btn-primary" onclick="saveFeature()">
                    <i class="material-icons">save</i>
                    ذخیره تنظیمات
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Metrics Modal -->
<div class="modal fade" id="metricsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="material-icons">analytics</i>
                    آمار و تحلیل: <code id="metricsFeatureName"></code>
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="metricsContent">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="sr-only">در حال بارگذاری...</span>
                        </div>
                        <p class="mt-2">در حال دریافت آمار...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- History Modal -->
<div class="modal fade" id="historyModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="material-icons">history</i>
                    تاریخچه تغییرات: <code id="historyFeatureName"></code>
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="historyContent">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="sr-only">در حال بارگذاری...</span>
                        </div>
                        <p class="mt-2">در حال دریافت تاریخچه...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
class TagInput {
    constructor(containerId, inputId) {
        this.container = document.getElementById(containerId);
        this.input = document.getElementById(inputId);
        this.tags = [];
        
        this.input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && this.input.value.trim()) {
                e.preventDefault();
                this.addTag(this.input.value.trim());
                this.input.value = '';
            }
        });
    }
    
    addTag(value) {
        if (!this.tags.includes(value)) {
            this.tags.push(value);
            this.render();
            updatePreview();
        }
    }
    
    removeTag(value) {
        this.tags = this.tags.filter(t => t !== value);
        this.render();
        updatePreview();
    }
    
    setTags(tags) {
        this.tags = tags || [];
        this.render();
    }
    
    getTags() {
        return this.tags;
    }
    
    render() {
        const existingTags = this.container.querySelectorAll('.tag');
        existingTags.forEach(tag => tag.remove());
        
        this.tags.forEach(tag => {
            const tagEl = document.createElement('div');
            tagEl.className = 'tag';
            tagEl.innerHTML = `
                ${tag}
                <span class="remove" onclick="removeTagFromInput('${this.container.id}', '${tag}')">&times;</span>
            `;
            this.container.insertBefore(tagEl, this.input);
        });
    }
}

// Initialize all tag inputs
const rolesTagInput = new TagInput('rolesTagInput', 'roleInput');
const usersTagInput = new TagInput('usersTagInput', 'userInput');
const countriesTagInput = new TagInput('countriesTagInput', 'countryInput');
const devicesTagInput = new TagInput('devicesTagInput', 'deviceInput');
const routesTagInput = new TagInput('routesTagInput', 'routeInput');
const environmentsTagInput = new TagInput('environmentsTagInput', 'environmentInput');

function removeTagFromInput(containerId, value) {
    const inputs = {
        'rolesTagInput': rolesTagInput,
        'usersTagInput': usersTagInput,
        'countriesTagInput': countriesTagInput,
        'devicesTagInput': devicesTagInput,
        'routesTagInput': routesTagInput,
        'environmentsTagInput': environmentsTagInput
    };
    
    if (inputs[containerId]) {
        inputs[containerId].removeTag(value);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // Link percentage slider and input
    const slider = document.getElementById('editPercentage');
    const numberInput = document.getElementById('editPercentageValue');
    
    slider?.addEventListener('input', () => {
        numberInput.value = slider.value;
        updatePreview();
    });
    
    numberInput?.addEventListener('input', () => {
        slider.value = numberInput.value;
        updatePreview();
    });
    
    // Initialize filters
    const searchInput = document.getElementById('searchFeature');
    const statusFilter = document.getElementById('filterStatus');
    const typeFilter = document.getElementById('filterType');
    
    function filterFeatures() {
        const searchTerm = searchInput.value.toLowerCase();
        const status = statusFilter.value;
        const type = typeFilter.value;
        
        document.querySelectorAll('.feature-card').forEach(card => {
            const name = card.dataset.name.toLowerCase();
            const cardStatus = card.dataset.status;
            const targeting = card.dataset.targeting || '';
            const percentage = parseInt(card.dataset.percentage);
            
            let show = true;
            
            if (searchTerm && !name.includes(searchTerm)) {
                show = false;
            }
            
            if (status && cardStatus !== status) {
                show = false;
            }
            
            if (type && !targeting.includes(type)) {
                show = false;
            }
            
            card.style.display = show ? 'block' : 'none';
        });
    }
    
    searchInput?.addEventListener('input', filterFeatures);
    statusFilter?.addEventListener('change', filterFeatures);
    typeFilter?.addEventListener('change', filterFeatures);
});

function updatePreview() {
    const percentage = document.getElementById('editPercentageValue')?.value || 100;
    const roles = rolesTagInput.getTags();
    const users = usersTagInput.getTags();
    const countries = countriesTagInput.getTags();
    const devices = devicesTagInput.getTags();
    const routes = routesTagInput.getTags();
    const minAge = document.getElementById('editMinAge')?.value;
    const maxAge = document.getElementById('editMaxAge')?.value;
    const enabledFrom = document.getElementById('editEnabledFrom')?.value;
    const enabledUntil = document.getElementById('editEnabledUntil')?.value;
    const environments = environmentsTagInput.getTags();
    
    let preview = '<div class="d-flex flex-wrap gap-1">';
    
    if (percentage < 100) {
        preview += `<span class="badge badge-info">A/B: ${percentage}%</span>`;
    }
    
    if (roles.length > 0) {
        preview += `<span class="badge badge-primary">${roles.length} نقش</span>`;
    }
    
    if (users.length > 0) {
        preview += `<span class="badge badge-warning">${users.length} کاربر</span>`;
    }
    
    if (countries.length > 0) {
        preview += `<span class="badge badge-success">${countries.length} کشور</span>`;
    }
    
    if (devices.length > 0) {
        preview += `<span class="badge badge-secondary">${devices.length} دستگاه</span>`;
    }
    
    if (routes.length > 0) {
        preview += `<span class="badge badge-dark">${routes.length} مسیر</span>`;
    }
    
    if (minAge || maxAge) {
        preview += `<span class="badge badge-info">سن: ${minAge || 0}-${maxAge || '∞'}</span>`;
    }
    
    if (enabledFrom || enabledUntil) {
        preview += `<span class="badge badge-warning">زمان‌بندی</span>`;
    }
    
    if (environments.length > 0) {
        preview += `<span class="badge badge-light text-dark">${environments.join(', ')}</span>`;
    }
    
    if (preview === '<div class="d-flex flex-wrap gap-1">') {
        preview += '<small class="text-muted">هیچ محدودیتی اعمال نشده (عمومی)</small>';
    }
    
    preview += '</div>';
    
    document.getElementById('settingsPreview').innerHTML = preview;
}

function showEditModal(feature) {
    document.getElementById('modalFeatureName').textContent = feature.name;
    document.getElementById('editFeatureName').value = feature.name;
    document.getElementById('editDescription').value = feature.description || '';
    
    const percentage = feature.enabled_percentage || 100;
    document.getElementById('editPercentage').value = percentage;
    document.getElementById('editPercentageValue').value = percentage;
    
    // Set advanced fields
    document.getElementById('editMinAge').value = feature.min_age || '';
    document.getElementById('editMaxAge').value = feature.max_age || '';
    document.getElementById('editEnabledFrom').value = feature.enabled_from ? 
        new Date(feature.enabled_from).toISOString().slice(0, 16) : '';
    document.getElementById('editEnabledUntil').value = feature.enabled_until ? 
        new Date(feature.enabled_until).toISOString().slice(0, 16) : '';
    document.getElementById('editPriority').value = feature.priority || 0;
    
    // Set tags
    const roles = feature.enabled_for_roles ? JSON.parse(feature.enabled_for_roles) : [];
    rolesTagInput.setTags(roles);
    
    const users = feature.enabled_for_users ? JSON.parse(feature.enabled_for_users) : [];
    usersTagInput.setTags(users.map(String));
    
    const countries = feature.enabled_for_countries ? JSON.parse(feature.enabled_for_countries) : [];
    countriesTagInput.setTags(countries);
    
    const devices = feature.enabled_for_devices ? JSON.parse(feature.enabled_for_devices) : [];
    devicesTagInput.setTags(devices);
    
    const routes = feature.enabled_for_routes ? JSON.parse(feature.enabled_for_routes) : [];
    routesTagInput.setTags(routes);
    
    const environments = feature.environments ? JSON.parse(feature.environments) : [];
    environmentsTagInput.setTags(environments);
    
    updatePreview();
    $('#editFeatureModal').modal('show');
}

function saveFeature() {
    const name = document.getElementById('editFeatureName').value;
    const description = document.getElementById('editDescription').value;
    const percentage = parseInt(document.getElementById('editPercentageValue').value);
    const minAge = document.getElementById('editMinAge').value ? parseInt(document.getElementById('editMinAge').value) : null;
    const maxAge = document.getElementById('editMaxAge').value ? parseInt(document.getElementById('editMaxAge').value) : null;
    const enabledFrom = document.getElementById('editEnabledFrom').value;
    const enabledUntil = document.getElementById('editEnabledUntil').value;
    const priority = parseInt(document.getElementById('editPriority').value) || 0;
    
    const data = {
        name: name,
        description: description,
        enabled_percentage: percentage,
        enabled_for_roles: rolesTagInput.getTags(),
        enabled_for_users: usersTagInput.getTags().map(id => parseInt(id)),
        enabled_for_countries: countriesTagInput.getTags(),
        enabled_for_devices: devicesTagInput.getTags(),
        enabled_for_routes: routesTagInput.getTags(),
        min_age: minAge,
        max_age: maxAge,
        enabled_from: enabledFrom,
        enabled_until: enabledUntil,
        priority: priority,
        environments: environmentsTagInput.getTags()
    };
    
    fetch('<?= url('/admin/features/advanced-update') ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '<?= csrf_token() ?>'
        },
        body: JSON.stringify(data)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            notyf.success('تنظیمات پیشرفته با موفقیت ذخیره شد');
            $('#editFeatureModal').modal('hide');
            setTimeout(() => location.reload(), 1000);
        } else {
            notyf.error(data.message || 'خطا در ذخیره تنظیمات');
        }
    })
    .catch(err => {
        notyf.error('خطا در ارتباط با سرور');
        console.error(err);
    });
}

function showMetrics(featureName) {
    document.getElementById('metricsFeatureName').textContent = featureName;
    
    fetch(`<?= url('/admin/features/metrics') ?>/${encodeURIComponent(featureName)}`)
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            document.getElementById('metricsContent').innerHTML = generateMetricsHTML(data.metrics);
        } else {
            document.getElementById('metricsContent').innerHTML = 
                '<div class="alert alert-danger">خطا در دریافت آمار</div>';
        }
    })
    .catch(err => {
        document.getElementById('metricsContent').innerHTML = 
            '<div class="alert alert-danger">خطا در ارتباط با سرور</div>';
        console.error(err);
    });
    
    $('#metricsModal').modal('show');
}

function showHistory(featureName) {
    document.getElementById('historyFeatureName').textContent = featureName;
    
    fetch(`<?= url('/admin/features/history') ?>/${encodeURIComponent(featureName)}`)
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            document.getElementById('historyContent').innerHTML = generateHistoryHTML(data.history);
        } else {
            document.getElementById('historyContent').innerHTML = 
                '<div class="alert alert-danger">خطا در دریافت تاریخچه</div>';
        }
    })
    .catch(err => {
        document.getElementById('historyContent').innerHTML = 
            '<div class="alert alert-danger">خطا در ارتباط با سرور</div>';
        console.error(err);
    });
    
    $('#historyModal').modal('show');
}

function generateMetricsHTML(metrics) {
    let html = `
        <div class="row">
            <div class="col-md-3">
                <div class="card text-center border-primary">
                    <div class="card-body">
                        <h3 class="text-primary">${metrics.total_checks || 0}</h3>
                        <p class="text-muted mb-0">کل بررسی‌ها</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-success">
                    <div class="card-body">
                        <h3 class="text-success">${metrics.enabled_count || 0}</h3>
                        <p class="text-muted mb-0">فعال</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h3 class="text-danger">${metrics.disabled_count || 0}</h3>
                        <p class="text-muted mb-0">غیرفعال</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-info">
                    <div class="card-body">
                        <h3 class="text-info">${metrics.success_rate || 0}%</h3>
                        <p class="text-muted mb-0">نرخ موفقیت</p>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    if (metrics.avg_response_time > 0) {
        html += `
            <div class="row mt-3">
                <div class="col-md-6">
                    <div class="card text-center border-warning">
                        <div class="card-body">
                            <h5 class="text-warning">${Math.round(metrics.avg_response_time)}ms</h5>
                            <p class="text-muted mb-0">میانگین زمان پاسخ</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card text-center border-secondary">
                        <div class="card-body">
                            <h5 class="text-secondary">${Math.round(metrics.max_response_time)}ms</h5>
                            <p class="text-muted mb-0">حداکثر زمان پاسخ</p>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    if (metrics.reasons && metrics.reasons.length > 0) {
        html += `
            <div class="mt-4">
                <h6><i class="material-icons">pie_chart</i> دلایل بررسی</h6>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>دلیل</th>
                                <th>تعداد</th>
                                <th>درصد</th>
                            </tr>
                        </thead>
                        <tbody>
        `;
        
        metrics.reasons.forEach(reason => {
            html += `
                <tr>
                    <td>${reason.reason || 'نامشخص'}</td>
                    <td>${reason.count || 0}</td>
                    <td>${reason.percentage || 0}%</td>
                </tr>
            `;
        });
        
        html += `
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    }
    
    return html;
}

function generateHistoryHTML(history) {
    if (!history || history.length === 0) {
        return '<div class="alert alert-info"><i class="material-icons">info</i> هیچ تاریخچه‌ای یافت نشد</div>';
    }
    
    let html = '<div class="timeline">';
    history.forEach(item => {
        const date = new Date(item.changed_at || item.created_at);
        const formattedDate = date.toLocaleDateString('fa-IR') + ' ' + date.toLocaleTimeString('fa-IR');
        const actionIcon = getActionIcon(item.change_type || item.action || 'updated');
        
        html += `
            <div class="timeline-item">
                <div class="timeline-marker bg-primary"></div>
                <div class="timeline-content">
                    <div class="d-flex justify-content-between align-items-start">
                        <h6 class="mb-1">
                            <i class="material-icons" style="font-size: 16px;">${actionIcon}</i>
                            ${item.change_type || item.action || 'به‌روزرسانی'}
                        </h6>
                        <small class="text-muted">${formattedDate}</small>
                    </div>
                    <p class="mb-1">${item.details || item.description || 'بدون جزئیات'}</p>
                    ${item.changed_by ? `<small class="text-info">توسط: ${item.changed_by}</small>` : ''}
                    ${item.old_values || item.new_values ? `
                        <div class="mt-2">
                            <small class="text-muted">تغییرات:</small>
                            <div class="diff-view mt-1">
                                ${generateDiffHTML(item.old_values, item.new_values)}
                            </div>
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    });
    html += '</div>';
    return html;
}

function generateDiffHTML(oldValues, newValues) {
    if (!oldValues && !newValues) return '';
    
    let html = '';
    
    if (typeof oldValues === 'string') {
        try {
            oldValues = JSON.parse(oldValues);
        } catch (e) {
            oldValues = null;
        }
    }
    
    if (typeof newValues === 'string') {
        try {
            newValues = JSON.parse(newValues);
        } catch (e) {
            newValues = null;
        }
    }
    
    if (oldValues && typeof oldValues === 'object') {
        Object.keys(oldValues).forEach(key => {
            const oldVal = oldValues[key];
            const newVal = newValues && newValues[key] !== undefined ? newValues[key] : null;
            
            if (oldVal !== newVal) {
                html += `<div class="diff-line">
                    <code>${key}</code>: 
                    <span class="text-danger">${JSON.stringify(oldVal)}</span> → 
                    <span class="text-success">${JSON.stringify(newVal)}</span>
                </div>`;
            }
        });
    }
    
    return html || '<small class="text-muted">تغییرات جزئی</small>';
}

function getActionIcon(action) {
    const icons = {
        'created': 'add_circle',
        'updated': 'edit',
        'enabled': 'toggle_on',
        'disabled': 'toggle_off',
        'deleted': 'delete'
    };
    return icons[action.toLowerCase()] || 'info';
}

function toggleFeature(name) {
    Swal.fire({
        title: 'تغییر وضعیت فیچر',
        text: `آیا مطمئن هستید که می‌خواهید وضعیت "${name}" را تغییر دهید؟`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'بله، تغییر بده',
        cancelButtonText: 'لغو',
        customClass: {
            confirmButton: 'btn btn-primary',
            cancelButton: 'btn btn-secondary'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('<?= url('/admin/features/toggle') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                },
                body: JSON.stringify({ name: name })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    notyf.success(data.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    notyf.error(data.message);
                }
            });
        }
    });
}

function editFeature(name) {
    // Find the feature data from the current features
    const features = <?= json_encode($features) ?>;
    const feature = features.find(f => f.name === name);
    
    if (feature) {
        showEditModal(feature);
    } else {
        notyf.error('فیچر مورد نظر یافت نشد');
    }
}
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.admin');
?>

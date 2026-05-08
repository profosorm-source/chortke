<?php
$title = 'جزئیات خطا';
$layout = 'admin';
ob_start();
?>

<div class="container-fluid">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="/admin/logs/errors" class="btn btn-sm btn-outline-secondary mb-2">
                <i class="material-icons align-middle" style="font-size: 18px;">arrow_back</i>
                بازگشت
            </a>
            <h2 class="mb-0">🔍 جزئیات خطا #<?= $error->id ?></h2>
        </div>
        <div>
            <?php if (!$error->is_resolved): ?>
                <button class="btn btn-success" onclick="document.getElementById('resolveForm').submit()">
                    <i class="material-icons align-middle">check_circle</i>
                    علامت به عنوان حل شده
                </button>
            <?php else: ?>
                <span class="badge bg-success fs-6">
                    <i class="material-icons align-middle">check_circle</i>
                    حل شده
                </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <!-- اطلاعات اصلی -->
        <div class="col-md-8">
            <!-- پیام خطا -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0">
                        <i class="material-icons align-middle">error</i>
                        پیام خطا
                    </h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-<?= e(
    $error->level === 'CRITICAL' ? 'danger' :
    ($error->level === 'ERROR' ? 'warning' : 'secondary')
) ?>">
                        <h5><?= e($error->message) ?></h5>
                    </div>
                </div>
            </div>

            <!-- پیشنهادات حل مشکل -->
            <?php if (!empty($suggestions)): ?>
                <div class="card border-0 shadow-sm mb-4 border-start border-success border-4">
                    <div class="card-header bg-success bg-opacity-10">
                        <h5 class="mb-0">
                            <i class="material-icons align-middle">lightbulb</i>
                            💡 پیشنهادات حل مشکل
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($suggestions as $index => $suggestion): ?>
                            <div class="alert alert-success mb-2">
                                <strong><?= $index + 1 ?>.</strong> <?= e($suggestion) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Stack Trace -->
            <?php if ($error->trace): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">
                            <i class="material-icons align-middle">list</i>
                            Stack Trace
                        </h5>
                    </div>
                    <div class="card-body">
                        <pre class="bg-dark text-light p-3 rounded" style="max-height: 400px; overflow-y: auto;"><code><?= e($error->trace) ?></code></pre>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Context -->
            <?php if ($error->context): ?>
                <?php $context = json_decode($error->context, true); ?>
                <?php if (!empty($context)): ?>
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white">
                            <h5 class="mb-0">
                                <i class="material-icons align-middle">info</i>
                                اطلاعات اضافی
                            </h5>
                        </div>
                        <div class="card-body">
                            <pre class="bg-light p-3 rounded mb-0"><code><?= json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?></code></pre>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- خطاهای مشابه -->
            <?php if (!empty($similarErrors) && count($similarErrors) > 1): ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">
                            <i class="material-icons align-middle">content_copy</i>
                            خطاهای مشابه (<?= count($similarErrors) - 1 ?> مورد)
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>زمان</th>
                                        <th>کاربر</th>
                                        <th>IP</th>
                                        <th>URL</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($similarErrors, 0, 10) as $similar): ?>
                                        <?php if ($similar->id != $error->id): ?>
                                            <tr>
                                                <td>
                                                    <small><?= date('Y-m-d H:i:s', strtotime($similar->created_at)) ?></small>
                                                </td>
                                                <td>
                                                    <?= $similar->user_id ? "کاربر #{$similar->user_id}" : '-' ?>
                                                </td>
                                                <td>
                                                    <code><?= e($similar->ip_address) ?></code>
                                                </td>
                                                <td>
                                                    <small class="text-muted"><?= e($similar->url ?? '-') ?></small>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="col-md-4">
            <!-- آمار -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="material-icons align-middle">assessment</i>
                        آمار
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">سطح:</span>
                            <span class="badge bg-<?= e(
    $error->level === 'CRITICAL' ? 'danger' :
    ($error->level === 'ERROR' ? 'warning' : 'secondary')
) ?>">
                                 <?= e($error->level) ?>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">تعداد تکرار:</span>
                            <strong class="text-danger">×<?= number_format($error->occurrence_count) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">اولین بار:</span>
                            <small><?= date('Y-m-d H:i', strtotime($error->first_occurred_at)) ?></small>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">آخرین بار:</span>
                            <small><?= date('Y-m-d H:i', strtotime($error->last_occurred_at)) ?></small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- مکان خطا -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="material-icons align-middle">location_on</i>
                        مکان خطا
                    </h5>
                </div>
                <div class="card-body">
                    <?php if ($error->file_path): ?>
                        <div class="mb-3">
                            <label class="text-muted small">فایل:</label>
                            <div class="bg-light p-2 rounded">
                                <code><?= e($error->file_path) ?></code>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="text-muted small">خط:</label>
                            <div>
                                <span class="badge bg-secondary"><?= $error->line_number ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($error->exception_type): ?>
                        <div class="mb-3">
                            <label class="text-muted small">نوع Exception:</label>
                            <div>
                                <code><?= e($error->exception_type) ?></code>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- اطلاعات درخواست -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="material-icons align-middle">web</i>
                        اطلاعات درخواست
                    </h5>
                </div>
                <div class="card-body">
                    <?php if ($error->url): ?>
                        <div class="mb-2">
                            <label class="text-muted small">URL:</label>
                            <div class="text-break small">
                                <code><?= e($error->url) ?></code>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($error->method): ?>
                        <div class="mb-2">
                            <label class="text-muted small">Method:</label>
                            <div>
                                <span class="badge bg-primary"><?= e($error->method) ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($error->user_id): ?>
                        <div class="mb-2">
                            <label class="text-muted small">کاربر:</label>
                            <div>
                                <a href="/admin/users/view/<?= $error->user_id ?>">
                                    کاربر #<?= $error->user_id ?>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($error->ip_address): ?>
                        <div class="mb-2">
                            <label class="text-muted small">IP:</label>
                            <div>
                                <code><?= e($error->ip_address) ?></code>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($error->user_agent): ?>
                        <div class="mb-2">
                            <label class="text-muted small">User Agent:</label>
                            <div class="small text-muted">
                                <?= e(mb_substr($error->user_agent, 0, 100)) ?>...
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- وضعیت حل -->
            <?php if ($error->is_resolved): ?>
                <div class="card border-0 shadow-sm border-start border-success border-4">
                    <div class="card-header bg-success bg-opacity-10">
                        <h5 class="mb-0 text-success">
                            <i class="material-icons align-middle">check_circle</i>
                            حل شده
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($error->resolved_by): ?>
                            <p class="mb-1">
                                <strong>توسط:</strong> کاربر #<?= $error->resolved_by ?>
                            </p>
                        <?php endif; ?>
                        <?php if ($error->resolved_at): ?>
                            <p class="mb-1">
                                <strong>زمان:</strong> 
                                <?= date('Y-m-d H:i', strtotime($error->resolved_at)) ?>
                            </p>
                        <?php endif; ?>
                        <?php if ($error->resolution_note): ?>
                            <p class="mb-0 mt-2">
                                <strong>یادداشت:</strong><br>
                                <?= e($error->resolution_note) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- فرم حل خطا -->
<form id="resolveForm" method="POST" action="/admin/logs/resolve-error" style="display: none;">
    <input type="hidden" name="error_id" value="<?= $error->id ?>">
    <input type="hidden" name="note" value="حل شد از طریق پنل">
</form>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>

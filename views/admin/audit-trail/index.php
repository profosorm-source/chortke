<?php
// views/admin/audit-trail/index.php
/** @var array $result ['rows'=>[], 'total'=>0, 'page'=>1, 'totalPages'=>1] */
/** @var array $eventTypes */
$title = 'Audit Trail - ردپای حسابرسی';
// مقادیر filter — از Controller inject می‌شوند؛ fallback امن از superglobal
$filterSearch  = $filterSearch  ?? e($_GET['search']  ?? '', ENT_QUOTES, 'UTF-8');
$filterEvent   = $filterEvent   ?? e($_GET['event']   ?? '', ENT_QUOTES, 'UTF-8');
$filterUserId  = $filterUserId  ?? e($_GET['user_id'] ?? '', ENT_QUOTES, 'UTF-8');
$filterFrom    = $filterFrom    ?? e($_GET['from']    ?? '', ENT_QUOTES, 'UTF-8');
$filterTo      = $filterTo      ?? e($_GET['to']      ?? '', ENT_QUOTES, 'UTF-8');
include BASE_PATH . '/views/layouts/admin.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">🔍 Audit Trail</h4>
            <p class="text-muted mb-0">ردپای کامل همه رویدادهای مهم سیستم · مجموع: <?= number_format($result['total']) ?> رویداد</p>
        </div>
        <a href="<?= url('/admin/audit-trail/export?') . http_build_query($_GET) ?>"
           class="btn btn-outline-success btn-sm">
            <span class="material-icons align-middle" style="font-size:18px">download</span>
            خروجی CSV
        </a>
    </div>

    <!-- فیلتر -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="جستجو رویداد، ایمیل..." value="<?= e($filterSearch) ?>"
                       style="width:220px">
                <select name="event" class="form-select form-select-sm" style="width:220px">
                    <option value="">همه رویدادها</option>
                    <?php foreach ($eventTypes as $et): ?>
                    <option value="<?= e($et['event']) ?>" <?= $filterEvent === $et['event'] ? 'selected' : '' ?>>
                        <?= e($et['event']) ?> (<?= number_format($et['count']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="user_id" class="form-control form-control-sm"
                       placeholder="User ID" value="<?= e($filterUserId) ?>"
                       style="width:100px">
                <input type="date" name="from" class="form-control form-control-sm"
                       value="<?= e($filterFrom) ?>" style="width:140px">
                <input type="date" name="to" class="form-control form-control-sm"
                       value="<?= e($filterTo) ?>" style="width:140px">
                <button class="btn btn-secondary btn-sm">فیلتر</button>
                <a href="<?= url('/admin/audit-trail') ?>" class="btn btn-outline-secondary btn-sm">پاک</a>
            </form>
        </div>
    </div>

    <!-- جدول -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>رویداد</th>
                            <th>کاربر تأثیرپذیر</th>
                            <th>انجام‌دهنده</th>
                            <th>جزئیات</th>
                            <th>IP</th>
                            <th>زمان</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($result['rows'])): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">موردی یافت نشد</td></tr>
                        <?php else: ?>
                        <?php foreach ($result['rows'] as $row): $row = (object)$row; ?>
                        <tr>
                            <td class="text-muted"><?= (int)$row->id ?></td>
                            <td>
                                <?php
                                // رنگ‌بندی رویداد
                                $eventColors = [
                                    'auth.'        => 'info',
                                    'user.kyc'     => 'primary',
                                    'wallet.'      => 'success',
                                    'withdrawal.'  => 'warning',
                                    'deposit.'     => 'success',
                                    'admin.'       => 'danger',
                                    'task.'        => 'secondary',
                                ];
                                $color = 'light';
                                foreach ($eventColors as $prefix => $c) {
                                    if (str_starts_with($row->event, $prefix)) {
                                        $color = $c;
                                        break;
                                    }
                                }
                                ?>
                                <span class="badge bg-<?= e($color) ?> text-<?= $color === 'light' ? 'dark' : '' ?>">
                                    <?= e($row->event) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($row->user_id): ?>
                                <a href="<?= url('/admin/users/' . (int)$row->user_id . '/edit') ?>" class="text-decoration-none small">
                                    <?= e($row->user_name ?? 'کاربر#' . $row->user_id) ?>
                                    <?php if ($row->user_email): ?>
                                    <div class="text-muted"><?= e($row->user_email) ?></div>
                                    <?php endif; ?>
                                </a>
                                <?php else: ?>
                                    <span class="text-muted">سیستم</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row->actor_id && $row->actor_id !== $row->user_id): ?>
                                <span class="badge bg-danger">
                                    <?= e($row->actor_name ?? 'ادمین#' . $row->actor_id) ?>
                                </span>
                                <?php elseif (!$row->actor_id): ?>
                                    <span class="text-muted">—</span>
                                <?php else: ?>
                                    <span class="text-muted small">کاربر خودش</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $ctx = @json_decode($row->context, true);
                                if ($ctx):
                                ?>
                                <button class="btn btn-xs btn-outline-secondary"
                                        data-bs-toggle="popover"
                                        data-bs-trigger="click"
                                        data-bs-html="true"
                                        data-bs-content="<pre class='mb-0 small' style='max-width:300px'><?= e(json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></pre>">
                                    <span class="material-icons" style="font-size:14px">info</span>
                                </button>
                                <?php if (isset($ctx['changes'])): ?>
                                <span class="text-muted small"><?= count($ctx['changes']) ?> تغییر</span>
                                <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted font-monospace" style="font-size:11px">
                                <?= e($row->ip_address ?? '—') ?>
                            </td>
                            <td class="text-muted" style="white-space:nowrap">
                                <?= to_jalali($row->created_at) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($result['totalPages'] > 1): ?>
        <div class="card-footer bg-white">
            <nav>
                <ul class="pagination pagination-sm justify-content-center mb-0">
                    <?php if ($result['page'] > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $result['page'] - 1])) ?>">‹</a>
                    </li>
                    <?php endif; ?>

                    <?php for ($p = max(1, $result['page'] - 3); $p <= min($result['totalPages'], $result['page'] + 3); $p++): ?>
                    <li class="page-item <?= $p === $result['page'] ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= e($p) ?></a>
                    </li>
                    <?php endfor; ?>

                    <?php if ($result['page'] < $result['totalPages']): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $result['page'] + 1])) ?>">›</a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>

</div>
</div>

<script>
// فعال‌سازی Popover های Bootstrap
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => {
        new bootstrap.Popover(el, { placement: 'left' });
    });
});
</script>

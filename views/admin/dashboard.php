<?php
ob_start();

$gradients   = ['linear-gradient(135deg,#5b8af5,#7c3aed)','linear-gradient(135deg,#10b981,#06b6d4)','linear-gradient(135deg,#f59e0b,#ef4444)','linear-gradient(135deg,#a855f7,#ec4899)','linear-gradient(135deg,#06b6d4,#3b82f6)','linear-gradient(135deg,#ef4444,#f59e0b)'];
$roleColors  = ['admin'=>'badge-danger','support'=>'badge-warning','user'=>'badge-muted','advertiser'=>'badge-purple'];
$roleNames   = ['admin'=>'مدیر','support'=>'پشتیبان','user'=>'کاربر','advertiser'=>'تبلیغ‌دهنده'];
$statusColors= ['active'=>'badge-success','inactive'=>'badge-muted','suspended'=>'badge-warning','banned'=>'badge-danger'];
$statusNames = ['active'=>'فعال','inactive'=>'غیرفعال','suspended'=>'تعلیق','banned'=>'مسدود'];

function actDotColor(string $s): string {
    if (str_contains($s,'withdraw')||str_contains($s,'برداشت')) return 'var(--orange)';
    if (str_contains($s,'kyc')||str_contains($s,'احراز'))      return 'var(--green)';
    if (str_contains($s,'ticket')||str_contains($s,'تیکت'))    return 'var(--accent)';
    if (str_contains($s,'ban')||str_contains($s,'مسدود'))      return 'var(--red)';
    if (str_contains($s,'task')||str_contains($s,'تسک'))       return 'var(--purple)';
    if (str_contains($s,'login')||str_contains($s,'ورود'))     return 'var(--cyan)';
    return 'var(--accent)';
}
?>
<form method="POST" style="display:none;"><?= csrf_field() ?></form>

<!-- ══ Welcome Banner ══ -->
<div class="welcome-banner">
    <div class="welcome-text">
        <h2>خوش آمدید، <?= e($fullName ?? 'مدیر') ?> 👋</h2>
        <p>آخرین ورود: <?= e(jdate('Y/m/d H:i', time())) ?> &nbsp;·&nbsp; پنل مدیریت <?= e(setting('site_name','چورتکه')) ?></p>
    </div>
    <div class="welcome-time">
        <div class="time-big" id="dash-clock">--:--</div>
        <div class="date-small"><?= e(jdate('Y/m/d', time())) ?></div>
    </div>
</div>

<!-- ══ Alert ══ -->
<?php
$alerts = [];
if (($stats['pending_kyc'] ?? 0) > 0)
    $alerts[] = '<a href="'.url('/admin/kyc').'" style="color:inherit;font-weight:700">'.fa_number($stats['pending_kyc']).' درخواست KYC</a>';
if (($stats['pending_withdrawals'] ?? 0) > 0)
    $alerts[] = '<a href="'.url('/admin/withdrawals').'" style="color:inherit;font-weight:700">'.fa_number($stats['pending_withdrawals']).' برداشت در انتظار</a>';
if (($stats['open_tickets'] ?? 0) > 0)
    $alerts[] = '<a href="'.url('/admin/tickets').'" style="color:inherit;font-weight:700">'.fa_number($stats['open_tickets']).' تیکت باز</a>';
if (!empty($alerts)):
?>
<div class="alert alert-warning">
    <span class="material-icons">warning_amber</span>
    <span>نیاز به بررسی: <?= implode(' &nbsp;·&nbsp; ', $alerts) ?></span>
</div>
<?php endif; ?>

<!-- ══ Stats Row 1 ══ -->
<div class="stats-grid stats-4">

    <!-- کاربران -->
    <div class="stat-card" style="--card-accent:var(--gold);--icon-bg:var(--gold-subtle)">
        <div class="stat-card-glow"></div>
        <div class="stat-card-inner">
            <div class="stat-card-icon"><span class="material-icons">group</span></div>
            <div class="stat-card-body">
                <div class="stat-label">کل کاربران</div>
                <div class="stat-value"><?= e(fa_number(number_format($stats['total_users'] ?? 0))) ?></div>
                <span class="stat-change up"><span class="material-icons">arrow_upward</span>+<?= e(fa_number($stats['today_users'] ?? 0)) ?> امروز</span>
            </div>
        </div>
        <div class="stat-footer">
            <span>این ماه: <strong><?= e(fa_number($stats['month_users'] ?? 0) )?></strong></span>
            <a href="<?= url('/admin/users') ?>" class="stat-footer-link">جزئیات <span class="material-icons">chevron_left</span></a>
        </div>
    </div>

    <!-- درآمد -->
    <div class="stat-card" style="--card-accent:var(--up);--icon-bg:var(--up-bg)">
        <div class="stat-card-glow"></div>
        <div class="stat-card-inner">
            <div class="stat-card-icon"><span class="material-icons">payments</span></div>
            <div class="stat-card-body">
                <div class="stat-label">درآمد این ماه (تومان)</div>
                <div class="stat-value smaller"><?= e(fa_number(number_format((int)($stats['monthly_revenue'] ?? 0)))) ?></div>
                <span class="stat-change up"><span class="material-icons">trending_up</span>کل: <?= e(fa_number(number_format((int)($stats['total_revenue'] ?? 0)))) ?></span>
            </div>
        </div>
        <div class="stat-footer">
            <span>واریز امروز: <strong><?= e(fa_number(number_format((int)($stats['today_deposits'] ?? 0)))) ?></strong></span>
            <a href="<?= url('/admin/transactions') ?>" class="stat-footer-link">گزارش <span class="material-icons">chevron_left</span></a>
        </div>
    </div>

    <!-- تسک‌ها -->
    <div class="stat-card" style="--card-accent:var(--warn);--icon-bg:var(--warn-bg)">
        <div class="stat-card-glow"></div>
        <div class="stat-card-inner">
            <div class="stat-card-icon"><span class="material-icons">assignment</span></div>
            <div class="stat-card-body">
                <div class="stat-label">تسک‌های فعال</div>
                <div class="stat-value"><?= e(fa_number(number_format($stats['active_tasks'] ?? 0))) ?></div>
                <span class="stat-change warn"><span class="material-icons">hourglass_empty</span>کل: <?= e(fa_number($stats['total_tasks'] ?? 0)) ?></span>
            </div>
        </div>
        <?php $taskPct = $stats['total_tasks'] ? round(($stats['active_tasks']/$stats['total_tasks'])*100) : 0; ?>
        <div class="stat-progress">
            <div class="stat-progress-bar"><div class="stat-progress-fill" style="width:<?= $taskPct ?>%"></div></div>
            <div class="stat-progress-labels"><span>فعال: <?= $taskPct ?>٪</span><span>کل: <?= e(fa_number($stats['total_tasks'] ?? 0)) ?></span></div>
        </div>
    </div>

    <!-- کیف‌پول -->
    <div class="stat-card" style="--card-accent:var(--purple);--icon-bg:var(--purple-bg)">
        <div class="stat-card-glow"></div>
        <div class="stat-card-inner">
            <div class="stat-card-icon"><span class="material-icons">account_balance_wallet</span></div>
            <div class="stat-card-body">
                <div class="stat-label">موجودی کل کیف‌پول‌ها</div>
                <div class="stat-value smaller"><?= e(fa_number(number_format((int)($stats['total_wallet_balance'] ?? 0)))) ?></div>
                <span class="stat-change neutral"><span class="material-icons">info</span>مجموع کاربران</span>
            </div>
        </div>
        <div class="stat-footer">
            <span>میانگین هر کاربر: <strong><?= e(fa_number(number_format((int)(($stats['total_wallet_balance'] ?? 0) / max(1, $stats['total_users'] ?? 1)))) )?></strong></span>
            <a href="<?= url('/admin/transactions') ?>" class="stat-footer-link">کیف‌پول‌ها <span class="material-icons">chevron_left</span></a>
        </div>
    </div>

</div>

<!-- ══ Stats Row 2 ══ -->
<div class="stats-grid stats-4 mt-2">

    <!-- تیکت‌ها -->
    <div class="stat-card stat-card-compact" style="--card-accent:var(--down);--icon-bg:var(--down-bg)">
        <div class="stat-card-glow"></div>
        <div class="stat-card-inner">
            <div class="stat-card-icon"><span class="material-icons">support_agent</span></div>
            <div class="stat-card-body">
                <div class="stat-label">تیکت‌های باز</div>
                <div class="stat-value"><?= e(fa_number($stats['open_tickets'] ?? 0)) ?></div>
                <div class="stat-desc">
                    <?php if (($stats['urgent_tickets'] ?? 0) > 0): ?><span class="pulse-dot"></span><?php endif; ?>
                    <?= e(fa_number($stats['urgent_tickets'] ?? 0)) ?> تیکت اورژانسی
                </div>
            </div>
        </div>
        <div class="stat-footer">
            <span>بدون پاسخ</span>
            <a href="<?= url('/admin/tickets') ?>" class="stat-footer-link">بررسی <span class="material-icons">chevron_left</span></a>
        </div>
    </div>

    <!-- KYC -->
    <div class="stat-card stat-card-compact" style="--card-accent:var(--info);--icon-bg:var(--info-bg)">
        <div class="stat-card-glow"></div>
        <div class="stat-card-inner">
            <div class="stat-card-icon"><span class="material-icons">how_to_reg</span></div>
            <div class="stat-card-body">
                <div class="stat-label">KYC در انتظار</div>
                <div class="stat-value"><?= e(fa_number($stats['pending_kyc'] ?? 0)) ?></div>
                <div class="stat-desc"><span class="material-icons">schedule</span>نیاز به تأیید مدیر</div>
            </div>
        </div>
        <div class="stat-footer">
            <span>تأیید شده: <strong><?= e(fa_number($stats['approved_kyc'] ?? 0)) ?></strong></span>
            <a href="<?= url('/admin/kyc') ?>" class="stat-footer-link">بررسی <span class="material-icons">chevron_left</span></a>
        </div>
    </div>

    <!-- برداشت -->
    <div class="stat-card stat-card-compact" style="--card-accent:var(--warn);--icon-bg:var(--warn-bg)">
        <div class="stat-card-glow"></div>
        <div class="stat-card-inner">
            <div class="stat-card-icon"><span class="material-icons">account_balance</span></div>
            <div class="stat-card-body">
                <div class="stat-label">برداشت در انتظار</div>
                <div class="stat-value"><?= e(fa_number($stats['pending_withdrawals'] ?? 0)) ?></div>
                <div class="stat-desc"><span class="material-icons">payments</span><?= e(fa_number(number_format((int)($stats['pending_withdrawal_amount'] ?? 0)))) ?> تومان</div>
            </div>
        </div>
        <div class="stat-footer">
            <span>میانگین: <strong><?= e(fa_number(number_format((int)(($stats['pending_withdrawal_amount'] ?? 0) / max(1, $stats['pending_withdrawals'] ?? 1)))) )?></strong></span>
            <a href="<?= url('/admin/withdrawals') ?>" class="stat-footer-link">تأیید <span class="material-icons">chevron_left</span></a>
        </div>
    </div>

    <!-- کاربران فعال -->
    <div class="stat-card stat-card-compact" style="--card-accent:var(--up);--icon-bg:var(--up-bg)">
        <div class="stat-card-glow"></div>
        <div class="stat-card-inner">
            <div class="stat-card-icon"><span class="material-icons">check_circle</span></div>
            <div class="stat-card-body">
                <div class="stat-label">کاربران فعال</div>
                <div class="stat-value"><?= e(fa_number($stats['active_users'] ?? 0)) ?></div>
                <div class="stat-desc"><span class="material-icons">block</span>مسدود: <?= e(fa_number($stats['banned_users'] ?? 0)) ?></div>
            </div>
        </div>
        <?php $activePct = $stats['total_users'] ? round(($stats['active_users'] / $stats['total_users']) * 100) : 0; ?>
        <div class="stat-progress">
            <div class="stat-progress-bar"><div class="stat-progress-fill" style="width:<?= $activePct ?>%"></div></div>
            <div class="stat-progress-labels"><span>فعال: <?= $activePct ?>٪</span><span>کل: <?= e(fa_number($stats['total_users'] ?? 0)) ?></span></div>
        </div>
    </div>

</div>

<!-- ══ Main Grid (2:1) ══ -->
<div class="dashboard-grid mt-3">

    <!-- ─── ستون اصلی ─── -->
    <div>

        <!-- نمودار ثبت‌نام -->
        <div class="card">
            <div class="card-header">
                <h3><span class="material-icons">show_chart</span>ثبت‌نام‌های ۳۰ روز اخیر</h3>
                <a href="<?= url('/admin/kpi') ?>" class="btn btn-sm btn-secondary">گزارش کامل</a>
            </div>
            <div class="card-body">
                <canvas id="usersChart" height="70"></canvas>
            </div>
        </div>

        <!-- فعالیت‌های اخیر کاربران -->
        <div class="card mt-2">
            <div class="card-header">
                <h3><span class="material-icons">timeline</span>فعالیت‌های اخیر کاربران</h3>
                <div style="display:flex;gap:8px;align-items:center">
                    <select id="activityTypeFilter" class="form-control" style="width:auto;padding:6px 12px;font-size:13px">
                        <option value="all">همه فعالیت‌ها</option>
                        <option value="register">ثبت‌نام</option>
                        <option value="login">ورود</option>
                        <option value="kyc">احراز هویت</option>
                        <option value="task">انجام تسک</option>
                        <option value="withdraw">برداشت</option>
                        <option value="deposit">واریز</option>
                        <option value="card">افزودن کارت</option>
                        <option value="ad">ثبت تبلیغ</option>
                    </select>
                    <a href="<?= url('/admin/audit-trail') ?>" class="btn btn-sm btn-secondary">مشاهده همه</a>
                </div>
            </div>
            <div class="card-body" style="padding:0">
                <div id="userActivitiesContainer" style="max-height:600px;overflow-y:auto">
                    <!-- محتوا از طریق AJAX بارگذاری می‌شود -->
                    <div style="padding:40px 20px;text-align:center">
                        <div class="spinner"></div>
                        <p style="margin-top:16px;color:var(--text-muted);font-size:13px">در حال بارگذاری...</p>
                    </div>
                </div>
                <div id="loadMoreContainer" style="padding:16px;text-align:center;border-top:1px solid var(--border);display:none">
                    <button id="loadMoreBtn" class="btn btn-sm btn-secondary">بارگذاری بیشتر</button>
                </div>
            </div>
        </div>

        <!-- برداشت‌های در انتظار -->
        <div class="card mt-2">
            <div class="card-header">
                <h3><span class="material-icons">payments</span>برداشت‌های در انتظار بررسی</h3>
                <a href="<?= url('/admin/withdrawals') ?>" class="btn btn-sm btn-primary">مشاهده همه</a>
            </div>
            <?php if (empty($pendingWithdrawalsList)): ?>
                <div class="empty-state" style="padding:40px 20px">
                    <span class="material-icons">check_circle_outline</span>
                    <p>هیچ برداشتی در انتظار نیست</p>
                </div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>کاربر</th>
                            <th>مبلغ (تومان)</th>
                            <th>بانک</th>
                            <th>زمان</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                   <tbody>
<?php foreach ($pendingWithdrawalsList as $w):
    $userId = (int)($w['user_id'] ?? 0);
    $fullName = (string)($w['full_name'] ?? '-');
    $email = (string)($w['email'] ?? '');
    $amount = (int)($w['amount'] ?? 0);
    $bankName = (string)($w['bank_name'] ?? '-');
    $createdAt = (string)($w['created_at'] ?? 'now');

    $wg = $gradients[$userId % count($gradients)];
    $wi = mb_substr($fullName !== '' ? $fullName : 'ک', 0, 1, 'UTF-8');
?>
    <tr>
        <td>
            <div class="user-cell">
                <div class="user-avatar-sm" style="background:<?= $wg ?>"><?= $wi ?></div>
                <div class="user-cell-info">
                    <strong><?= e($fullName) ?></strong>
                    <small><?= e($email) ?></small>
                </div>
            </div>
        </td>
        <td><span class="amount-cell positive"><?= fa_number(number_format($amount)) ?></span></td>
        <td style="font-size:12px;color:var(--text-muted)"><?= e($bankName) ?></td>
        <td style="font-size:11px;color:var(--text-muted)"><?= jdate('Y/m/d H:i', strtotime($createdAt)) ?></td>
        <td>
            <div class="action-btns">
                <a href="<?= url('/admin/withdrawals') ?>" class="icon-btn approve" title="تأیید"><span class="material-icons">check</span></a>
                <a href="<?= url('/admin/withdrawals') ?>" class="icon-btn reject"  title="رد"><span class="material-icons">close</span></a>
                <a href="<?= url('/admin/withdrawals') ?>" class="icon-btn view"    title="جزئیات"><span class="material-icons">visibility</span></a>
            </div>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- کاربران جدید -->
        <div class="card mt-2">
            <div class="card-header">
                <h3><span class="material-icons">person_add</span>آخرین کاربران ثبت‌نام شده</h3>
                <a href="<?= url('/admin/users') ?>" class="btn btn-sm btn-secondary">مشاهده همه</a>
            </div>
            <?php if (empty($recentUsers)): ?>
                <div class="empty-state" style="padding:40px 20px">
                    <span class="material-icons">group_off</span>
                    <p>کاربری ثبت نشده</p>
                </div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>کاربر</th>
                            <th>موبایل</th>
                            <th>نقش</th>
                            <th>وضعیت</th>
                            <th>تاریخ عضویت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                <tbody>
<?php foreach ($recentUsers as $u):
    $uid = (int)($u['id'] ?? 0);
    $fullName = (string)($u['full_name'] ?? '-');
    $email = (string)($u['email'] ?? '');
    $mobile = (string)($u['mobile'] ?? '-');
    $role = (string)($u['role'] ?? '');
    $status = (string)($u['status'] ?? '');
    $createdAt = (string)($u['created_at'] ?? 'now');

    $ug = $gradients[$uid % count($gradients)];
    $ui = mb_substr($fullName !== '' ? $fullName : 'ک', 0, 1, 'UTF-8');
?>
    <tr>
        <td>
            <div class="user-cell">
                <div class="user-avatar-sm" style="background:<?= $ug ?>"><?= $ui ?></div>
                <div class="user-cell-info">
                    <strong><?= e($fullName) ?></strong>
                    <small><?= e($email) ?></small>
                </div>
            </div>
        </td>
        <td style="font-size:12px;direction:ltr"><?= e($mobile) ?></td>
        <td><span class="badge <?= e($roleColors[$role] ?? 'badge-muted' ) ?>"><?= e($roleNames[$role] ?? 'کاربر' ) ?></span></td>
        <td><span class="badge <?= e($statusColors[$status] ?? 'badge-muted' ) ?>"><?= e($statusNames[$status] ?? '-' ) ?></span></td>
        <td style="font-size:11px;color:var(--text-muted)"><?= jdate('Y/m/d', strtotime($createdAt)) ?></td>
        <td>
            <div class="action-btns">
                <a href="<?= url('/admin/users/' . $uid) ?>"      class="icon-btn view"   title="مشاهده"><span class="material-icons">visibility</span></a>
                <a href="<?= url('/admin/users/edit/' . $uid) ?>" class="icon-btn edit"   title="ویرایش"><span class="material-icons">edit</span></a>
                <a href="<?= url('/admin/users') ?>"              class="icon-btn delete" title="مسدود"><span class="material-icons">block</span></a>
            </div>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /ستون اصلی -->

    <!-- ─── ستون راست ─── -->
    <div style="display:flex;flex-direction:column;gap:16px">

        <!-- اقدامات سریع -->
        <div class="card">
            <div class="card-header">
                <h3><span class="material-icons">flash_on</span>اقدامات سریع</h3>
            </div>
            <div class="card-body">
                <div class="quick-action-grid">
                    <a class="quick-action" href="<?= url('/admin/kyc') ?>"                style="color:var(--orange)">
                        <span class="material-icons">how_to_reg</span><span>بررسی KYC</span>
                    </a>
                    <a class="quick-action" href="<?= url('/admin/withdrawals') ?>"        style="color:var(--green)">
                        <span class="material-icons">payments</span><span>تأیید برداشت</span>
                    </a>
                    <a class="quick-action" href="<?= url('/admin/tickets') ?>"            style="color:var(--accent)">
                        <span class="material-icons">support_agent</span><span>تیکت‌ها</span>
                    </a>
                    <a class="quick-action" href="<?= url('/admin/notifications/send') ?>" style="color:var(--purple)">
                        <span class="material-icons">notification_add</span><span>ارسال اعلان</span>
                    </a>
                    <a class="quick-action" href="<?= url('/admin/users') ?>"              style="color:var(--cyan)">
                        <span class="material-icons">group</span><span>کاربران</span>
                    </a>
                    <a class="quick-action" href="<?= url('/admin/settings') ?>"           style="color:var(--text-muted)">
                        <span class="material-icons">settings</span><span>تنظیمات</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- وضعیت سیستم -->
        <div class="w-card" style="--accent-color:var(--up);--icon-bg:var(--up-bg)">
            <div class="w-header">
                <div class="w-header-left">
                    <div class="w-header-icon"><span class="material-icons">monitor_heart</span></div>
                    <h3>وضعیت سیستم</h3>
                </div>
                <div style="display:flex;align-items:center;gap:7px">
                    <span class="w-badge" id="sysStatusBadge">سرویس‌ها</span>
                    <button id="refreshSystemStatus" class="w-refresh-btn" title="بروزرسانی"><span class="material-icons">refresh</span></button>
                </div>
            </div>
            <div id="systemStatusContainer" class="w-body"><div class="w-loader"><div class="spinner"></div></div></div>
        </div>

        <!-- Cron Jobs -->
        <div class="w-card" style="--accent-color:var(--purple);--icon-bg:var(--purple-bg)">
            <div class="w-header">
                <div class="w-header-left">
                    <div class="w-header-icon"><span class="material-icons">schedule</span></div>
                    <h3>Cron Jobs</h3>
                </div>
                <span class="w-badge" id="cronBadge" style="--w-badge-color:var(--purple);--w-badge-bg:var(--purple-bg)">بارگذاری...</span>
            </div>
            <div id="cronJobsContainer" class="w-body"><div class="w-loader"><div class="spinner"></div></div></div>
        </div>

        <!-- درگاه‌های پرداخت -->
        <div class="w-card" style="--accent-color:var(--gold);--icon-bg:var(--gold-subtle)">
            <div class="w-header">
                <div class="w-header-left">
                    <div class="w-header-icon"><span class="material-icons">payment</span></div>
                    <h3>درگاه‌های پرداخت</h3>
                </div>
                <span class="w-badge" id="gatesBadge">درگاه‌ها</span>
            </div>
            <div id="paymentGatesContainer" class="w-body"><div class="w-loader"><div class="spinner"></div></div></div>
        </div>

        <!-- صف ایمیل -->
        <div class="w-card" style="--accent-color:var(--cyan);--icon-bg:rgba(6,182,212,.10)">
            <div class="w-header">
                <div class="w-header-left">
                    <div class="w-header-icon"><span class="material-icons">email</span></div>
                    <h3>صف ایمیل</h3>
                </div>
                <span class="w-badge" id="emailBadge" style="--w-badge-color:var(--warn);--w-badge-bg:var(--warn-bg)">...</span>
            </div>
            <div id="emailQueueContainer" class="w-body"><div class="w-loader"><div class="spinner"></div></div></div>
        </div>

        <!-- منابع سرور -->
        <div class="w-card" style="--accent-color:var(--info);--icon-bg:var(--info-bg)">
            <div class="w-header">
                <div class="w-header-left">
                    <div class="w-header-icon"><span class="material-icons">memory</span></div>
                    <h3>منابع سرور</h3>
                </div>
            </div>
            <div id="serverResourcesContainer" class="w-body"><div class="w-loader"><div class="spinner"></div></div></div>
        </div>

        <!-- آمار خلاصه -->
        <?php
        $activeRate = ($stats['total_users'] ?? 0) > 0
            ? min(round(($stats['active_users'] / $stats['total_users']) * 100), 100) : 0;
        $rateColor  = $activeRate >= 70 ? 'var(--up)' : ($activeRate >= 40 ? 'var(--warn)' : 'var(--down)');
        ?>
        <div class="w-card" style="--accent-color:var(--gold);--icon-bg:var(--gold-subtle)">
            <div class="w-header">
                <div class="w-header-left">
                    <div class="w-header-icon"><span class="material-icons">bar_chart</span></div>
                    <h3>خلاصه آمار</h3>
                </div>
                <a href="<?= url('/admin/kpi') ?>" class="w-kpi-link">
                    <span class="material-icons">insights</span>KPI
                </a>
            </div>
            <div class="w-body">
                <div class="w-mini-grid">
                    <div class="w-mini-stat" style="--ms-accent:var(--up)">
                        <div class="w-mini-label">کاربران فعال</div>
                        <div class="w-mini-val"><?= e(fa_number($stats['active_users'] ?? 0)) ?></div>
                        <div class="w-mini-sub" style="color:var(--up)">+<?= e(fa_number($stats['today_users'] ?? 0)) ?> امروز</div>
                    </div>
                    <div class="w-mini-stat" style="--ms-accent:var(--down)">
                        <div class="w-mini-label">کاربران مسدود</div>
                        <div class="w-mini-val"><?= e(fa_number($stats['banned_users'] ?? 0)) ?></div>
                        <div class="w-mini-sub" style="color:var(--down)"><?= (100 - $activeRate) ?>٪ کل</div>
                    </div>
                    <div class="w-mini-stat" style="--ms-accent:var(--warn)">
                        <div class="w-mini-label">تسک‌های فعال</div>
                        <div class="w-mini-val"><?= e(fa_number($stats['active_tasks'] ?? 0)) ?></div>
                        <div class="w-mini-sub">از <?= e(fa_number($stats['total_tasks'] ?? 0)) ?> کل</div>
                    </div>
                    <div class="w-mini-stat" style="--ms-accent:var(--down)">
                        <div class="w-mini-label">تیکت اورژانسی</div>
                        <div class="w-mini-val"><?= e(fa_number($stats['urgent_tickets'] ?? 0)) ?></div>
                        <div class="w-mini-sub" style="color:var(--down)">بی‌پاسخ</div>
                    </div>
                    <div class="w-mini-stat w-mini-full" style="--ms-accent:var(--info)">
                        <div style="display:flex;justify-content:space-between;align-items:center">
                            <div>
                                <div class="w-mini-label">KYC در انتظار</div>
                                <div class="w-mini-val"><?= e(fa_number($stats['pending_kyc'] ?? 0)) ?></div>
                            </div>
                            <a href="<?= url('/admin/kyc') ?>" class="w-inline-link">
                                بررسی<span class="material-icons">chevron_left</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="w-rate-card">
                    <div class="w-rate-header">
                        <span class="w-rate-title">نرخ فعال‌سازی کاربران</span>
                        <span class="w-rate-pct" style="color:<?= $rateColor ?>"><?= $activeRate ?>٪</span>
                    </div>
                    <div class="w-prog-bar">
                        <div class="w-prog-fill" style="width:<?= $activeRate ?>%;background:<?= $rateColor ?>"></div>
                    </div>
                    <div class="w-rate-sub">فعال: <?= fa_number($stats['active_users'] ?? 0) ?> از <?= fa_number($stats['total_users'] ?? 0) ?> کاربر</div>
                </div>
            </div>
        </div>

        <!-- ورود/خروج مدیران -->
        <div class="card mt-2">
            <div class="card-header">
                <h3><span class="material-icons">admin_panel_settings</span>دسترسی مدیران</h3>
            </div>
            <div class="card-body" style="padding:4px 20px 12px">
                <?php
                $adminAccessLog = $adminAccessLog ?? [];
                $actionLabels = [
                    'login'         => ['label' => 'ورود',         'icon' => 'login',        'color' => 'var(--green)'],
                    'login_success' => ['label' => 'ورود',         'icon' => 'login',        'color' => 'var(--green)'],
                    'admin_login'   => ['label' => 'ورود ادمین',   'icon' => 'admin_panel_settings', 'color' => 'var(--green)'],
                    'logout'        => ['label' => 'خروج',         'icon' => 'logout',       'color' => 'var(--orange)'],
                    'admin_logout'  => ['label' => 'خروج ادمین',   'icon' => 'logout',       'color' => 'var(--orange)'],
                    'login_failed'  => ['label' => 'ورود ناموفق',  'icon' => 'lock',         'color' => 'var(--red)'],
                ];
                if (!empty($adminAccessLog)): ?>
                    <?php foreach (array_slice($adminAccessLog, 0, 10) as $log):
    $log = is_array($log) ? $log : [];
    $action    = $log['type'] ?? $log['action'] ?? 'login';
                        $meta      = $actionLabels[$action] ?? ['label' => $action, 'icon' => 'history', 'color' => 'var(--text-muted)'];
                        $roleColor = match($log['role'] ?? '') {
                            'admin'   => '#ef4444',
                            'support' => '#f97316',
                            default   => '#64748b'
                        };
                        $roleName = match($log['role'] ?? '') {
                            'admin'   => 'مدیر',
                            'support' => 'پشتیبان',
                            default   => 'کاربر'
                        };
                    ?>
                        <div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--border)">
                            <span class="material-icons" style="font-size:20px;color:<?= $meta['color'] ?>;flex-shrink:0"><?= $meta['icon'] ?></span>
                            <div style="flex:1;min-width:0">
                                <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px">
                                    <span style="font-size:13px;font-weight:600;color:var(--text-primary)"><?= e($log['full_name'] ?? 'نامشخص') ?></span>
                                    <span style="font-size:10px;padding:1px 6px;border-radius:4px;background:<?= $roleColor ?>;color:#fff"><?= $roleName ?></span>
                                    <span style="font-size:11px;color:<?= $meta['color'] ?>;margin-right:2px"><?= $meta['label'] ?></span>
                                </div>
                                <div style="display:flex;gap:8px;font-size:11px;color:var(--text-muted)">
                                    <span><?= e($log['time_ago'] ?? '') ?></span>
                                    <?php if (!empty($log['ip_address'])): ?>
                                        <span>•</span><span dir="ltr"><?= e($log['ip_address']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="padding:24px 0;text-align:center;color:var(--text-muted)">
                        <span class="material-icons" style="font-size:36px;opacity:0.2;display:block;margin-bottom:8px">manage_accounts</span>
                        <div style="font-size:12px">هنوز ورود یا خروجی ثبت نشده</div>
                        <div style="font-size:11px;margin-top:4px;opacity:.7">پس از اولین ورود ادمین اینجا نمایش داده می‌شود</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /ستون راست -->

</div><!-- /dashboard-grid -->
<?php
// بعد از header یا در جای مناسب
$sentryWidgetPath = BASE_PATH . '/views/partials/sentry-widget.php';
if (is_file($sentryWidgetPath)) {
    include $sentryWidgetPath;
}
?>
<?php
$content = ob_get_clean();

$chartJson  = json_encode(array_values($chartData ?? array_fill(0, 30, 0)));
$labelsArr  = [];
for ($i = 29; $i >= 0; $i--) {
    $labelsArr[] = jdate('m/d', strtotime("-{$i} days"));
}
$labelsJson = json_encode($labelsArr);

?>

<script src="<?= asset('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<script>
(function tick(){
    var n=new Date();
    var el=document.getElementById("dash-clock");
    if(el) el.textContent=String(n.getHours()).padStart(2,"0")+":"+String(n.getMinutes()).padStart(2,"0");
    setTimeout(tick,1000);
})();

document.addEventListener("DOMContentLoaded", function(){
    var ctx = document.getElementById("usersChart");
    if(ctx) {
    var dark = !document.documentElement.classList.contains("light");
    var gc   = dark ? "rgba(255,255,255,0.04)" : "rgba(0,0,0,0.05)";
    var tc   = dark ? "#475569" : "#94a3b8";
    new Chart(ctx, {
        type:"line",
        data:{
            labels: <?= $labelsJson ?>,
            datasets:[{
                label:"کاربران جدید",
                data: <?= $chartJson ?>,
                borderColor:"#5b8af5",
                backgroundColor:"rgba(91,138,245,0.08)",
                tension:0.4, fill:true,
                pointRadius:3, pointBackgroundColor:"#5b8af5", pointHoverRadius:5
            }]
        },
        options:{
            responsive:true,
            plugins:{legend:{display:false}},
            scales:{
                x:{grid:{color:gc},ticks:{color:tc,font:{size:10,family:"Vazirmatn"}}},
                y:{grid:{color:gc},ticks:{color:tc,font:{size:10,family:"Vazirmatn"}},beginAtZero:true}
            }
        }
    });
    } // end if(ctx)
    
    // ═══════════════════════════════════════════════════════════════
    // بارگذاری فعالیت‌های اخیر کاربران
    // ═══════════════════════════════════════════════════════════════
    
    let currentPage = 1;
    let currentType = "all";
    let isLoading = false;
    
    const activityTypeMap = {
        "register": { icon: "person_add", color: "#10b981", label: "ثبت‌نام کرد" },
        "login": { icon: "login", color: "#06b6d4", label: "وارد شد" },
        "kyc": { icon: "verified_user", color: "#8b5cf6", label: "درخواست احراز هویت" },
        "task": { icon: "task_alt", color: "#f59e0b", label: "تسک انجام داد" },
        "withdraw": { icon: "payments", color: "#ef4444", label: "درخواست برداشت" },
        "deposit": { icon: "account_balance", color: "#10b981", label: "واریز کرد" },
        "card": { icon: "credit_card", color: "#3b82f6", label: "کارت بانکی اضافه کرد" },
        "ad": { icon: "campaign", color: "#ec4899", label: "تبلیغ ثبت کرد" }
    };
    
    function loadActivities(append = false) {
        if (isLoading) return;
        isLoading = true;
        
        if (!append) {
            currentPage = 1;
            document.getElementById("userActivitiesContainer").innerHTML = `
                <div style="padding:40px 20px;text-align:center">
                    <div class="spinner"></div>
                    <p style="margin-top:16px;color:var(--text-muted);font-size:13px">در حال بارگذاری...</p>
                </div>
            `;
        }
        
        fetch("<?= url('/admin/dashboard/recent-activity') ?>?type=" + currentType + "&limit=20&page=" + currentPage)
            .then(res => res.json())
            .then(data => {
                if (!data.success || !data.data || data.data.length === 0) {
                    if (!append) {
                        document.getElementById("userActivitiesContainer").innerHTML = `
                            <div style="padding:40px 20px;text-align:center;color:var(--text-muted)">
                                <span class="material-icons" style="font-size:48px;opacity:0.2">history_toggle_off</span>
                                <p style="margin-top:16px;font-size:14px">فعالیتی یافت نشد</p>
                            </div>
                        `;
                    }
                    document.getElementById("loadMoreContainer").style.display = "none";
                    return;
                }
                
                let html = "";
                data.data.forEach(activity => {
                    const typeInfo = activityTypeMap[activity.type] || { icon: "info", color: "#64748b", label: activity.description };
                    const avatar = activity.avatar_url || "<?= asset('assets/images/default-avatar.png') ?>";
                    const fullName = activity.full_name || "کاربر ناشناس";
                    const email = activity.email || "";
                    
                    html += `
                        <div class="user-activity-item" style="display:flex;gap:16px;padding:16px 20px;border-bottom:1px solid var(--border);transition:background 0.2s">
                            <div style="flex-shrink:0">
                                <img src="${avatar}" alt="${fullName}" style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid var(--border)">
                            </div>
                            <div style="flex:1;min-width:0">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                                    <span style="font-weight:600;font-size:14px;color:var(--text-primary)">${fullName}</span>
                                    ${activity.summary && activity.summary.length > 0 ? 
                                        activity.summary.map(s => `<span class="badge badge-${s.color || "default"}" style="font-size:10px;padding:2px 6px">${s.label}</span>`).join("") 
                                        : ""}
                                </div>
								
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                                    <span class="material-icons" style="font-size:16px;color:${typeInfo.color}">${typeInfo.icon}</span>
                                    <span style="font-size:13px;color:var(--text-secondary)">${activity.description || typeInfo.label}</span>
                                </div>
                                <div style="display:flex;align-items:center;gap:12px;font-size:12px;color:var(--text-muted)">
                                    <div style="display:flex;align-items:center;gap:4px">
                                        <span class="material-icons" style="font-size:14px">schedule</span>
                                        <span>${activity.time_ago || ""}</span>
                                    </div>
                                    ${email ? `<div style="display:flex;align-items:center;gap:4px">
                                        <span class="material-icons" style="font-size:14px">email</span>
                                        <span>${email}</span>
                                    </div>` : ""}
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                if (append) {
                    document.getElementById("userActivitiesContainer").innerHTML += html;
                } else {
                    document.getElementById("userActivitiesContainer").innerHTML = html;
                }
                
                // نمایش دکمه بارگذاری بیشتر
                if (data.data.length >= 20) {
                    document.getElementById("loadMoreContainer").style.display = "block";
                } else {
                    document.getElementById("loadMoreContainer").style.display = "none";
                }
                
                isLoading = false;
            })
            .catch(err => {
                console.error("خطا در بارگذاری فعالیت‌ها:", err);
                if (!append) {
                    document.getElementById("userActivitiesContainer").innerHTML = `
                        <div style="padding:40px 20px;text-align:center;color:var(--red)">
                            <span class="material-icons" style="font-size:48px;opacity:0.5">error_outline</span>
                            <p style="margin-top:16px;font-size:14px">خطا در بارگذاری فعالیت‌ها</p>
                        </div>
                    `;
                }
                isLoading = false;
            });
    }
    
    // بارگذاری اولیه
    loadActivities();
    
    // فیلتر نوع فعالیت
    const filterEl = document.getElementById("activityTypeFilter");
if (filterEl) {
    filterEl.addEventListener("change", function(e) {
        currentType = e.target.value;
        loadActivities(false);
    });
}
    
    // بارگذاری بیشتر
    const loadMoreBtn = document.getElementById("loadMoreBtn");
if (loadMoreBtn) {
    loadMoreBtn.addEventListener("click", function() {
        currentPage++;
        loadActivities(true);
    });
}
    
    // ═══════════════════════════════════════════════════════════════
    // بارگذاری وضعیت سیستم
    // ═══════════════════════════════════════════════════════════════
    
    function loadSystemStatus() {
        fetch("<?= url('/admin/dashboard/system-status') ?>")
            .then(res => res.json())
            .then(data => {
                if (!data.success || !data.data) {
                    throw new Error("خطا در دریافت داده");
                }
                
                // وضعیت سیستم
                renderSystemStatus(data.data.services || []);
                
                // Cron Jobs
                renderCronJobs(data.data.cron_jobs || []);
                
                // درگاه‌های پرداخت
                renderPaymentGates(data.data.payment_gates || []);
                
                // صف ایمیل
                renderEmailQueue(data.data.email_queue || {});
                renderServerResources(data.data.resources || {});
            })
            .catch(err => {
                console.error("خطا در بارگذاری وضعیت سیستم:", err);
                document.getElementById("systemStatusContainer").innerHTML = `
                    <div style="padding:20px 0;text-align:center;color:var(--red)">
                        <span class="material-icons" style="font-size:36px;opacity:0.5">error_outline</span>
                        <p style="margin-top:12px;font-size:13px">خطا در بارگذاری</p>
                    </div>
                `;
            });
    }
    
    function renderSystemStatus(services) {
        const SM = {
            online:  { cls:"online",  icon:"check_circle",  label:"آنلاین"   },
            warning: { cls:"warn",    icon:"warning",       label:"هشدار"    },
            error:   { cls:"error",   icon:"error",         label:"خطا"      },
            info:    { cls:"online",  icon:"info",          label:"اطلاعات"  },
            unknown: { cls:"muted",   icon:"help_outline",  label:"نامشخص"   }
        };
        let online = 0, warn = 0, err = 0;
        let html = "";
        services.forEach(s => {
            const m = SM[s.status] || SM.unknown;
            if (s.status==="online"||s.status==="info") online++;
            else if (s.status==="warning") warn++;
            else if (s.status==="error") err++;
            html += `
            <div class="ws-row">
                <div class="ws-row-left">
                    <span class="ws-dot ${m.cls}"></span>
                    <div>
                        <div class="ws-name">${s.name}</div>
                        ${s.hint ? `<div class="ws-hint">${s.hint}</div>` : ""}
                    </div>
                </div>
                <span class="ws-pill ${m.cls}">
                    <span class="material-icons">${m.icon}</span>${s.label}
                </span>
            </div>`;
        });
        if (!html) html = `<div class="ws-empty">اطلاعاتی موجود نیست</div>`;
        document.getElementById("systemStatusContainer").innerHTML = html;
        const badge = document.getElementById("sysStatusBadge");
        if (badge) {
            const t = services.length;
            badge.textContent = `${online}/${t} آنلاین`;
            badge.style.setProperty("--w-badge-color", err > 0 ? "var(--down)" : warn > 0 ? "var(--warn)" : "var(--up)");
            badge.style.setProperty("--w-badge-bg",    err > 0 ? "var(--down-bg)" : warn > 0 ? "var(--warn-bg)" : "var(--up-bg)");
        }
    }
    
    function renderCronJobs(cronJobs) {
        const SM = {
            online:  { cls:"online", icon:"check_circle", label:"موفق"   },
            warning: { cls:"warn",   icon:"warning",      label:"هشدار"  },
            error:   { cls:"error",  icon:"error",        label:"خطا"    },
            unknown: { cls:"muted",  icon:"help_outline", label:"نامشخص" }
        };
        let ok=0, fail=0;
        let html = "";
        cronJobs.forEach(job => {
            const s = SM[job.status] || SM.unknown;
            if (job.status==="online") ok++; else if (job.status==="error") fail++;
            html += `
            <div class="ws-row ws-cron-row">
                <div class="ws-row-left" style="flex-direction:column;align-items:flex-start;gap:3px">
                    <div style="display:flex;align-items:center;gap:7px">
                        <span class="ws-dot ${s.cls}"></span>
                        <span class="ws-name">${job.name}</span>
                        <span class="ws-cron-tag">${job.schedule}</span>
                    </div>
                    <div class="ws-hint" style="padding-right:15px">
                        آخرین: ${job.last_run_ago || "نامشخص"}
                        ${job.execution_time ? ` · ${job.execution_time}s` : ""}
                        ${job.items_processed ? ` · ${job.items_processed} آیتم` : ""}
                    </div>
                </div>
                <span class="ws-pill ${s.cls}">
                    <span class="material-icons">${s.icon}</span>${s.label}
                </span>
            </div>`;
        });
        if (!html) html = `<div class="ws-empty">اطلاعاتی موجود نیست</div>`;
        document.getElementById("cronJobsContainer").innerHTML = html;
        const badge = document.getElementById("cronBadge");
        if (badge) {
            badge.textContent = `${cronJobs.length} job`;
            if (fail > 0) { badge.style.setProperty("--w-badge-color","var(--down)"); badge.style.setProperty("--w-badge-bg","var(--down-bg)"); }
        }
    }
    
    function renderPaymentGates(gates) {
        let online=0, warn=0, err=0;
        let html = "";
        gates.forEach(gate => {
            const sc = gate.status === "online" ? "online" : gate.status === "warning" ? "warn" : gate.status === "error" ? "error" : "muted";
            const slabel = gate.status === "online" ? "متصل" : gate.status === "warning" ? "کند" : gate.status === "error" ? "قطع" : "نامشخص";
            const sicon  = gate.status === "online" ? "wifi" : gate.status === "warning" ? "warning" : "wifi_off";
            if (sc==="online") online++; else if (sc==="error") err++; else warn++;
            const sr = gate.success_rate || 0;
            const srCls = sr>=90?"good":sr>=70?"warn":"bad";
            html += `
            <div class="wg-block">
                <div class="wg-header">
                    <div class="wg-name-row">
                        <span class="ws-dot ${sc}"></span>
                        <span class="ws-name">${gate.name}</span>
                        ${gate.ping_ms !== null ? `<span class="wg-ping">${gate.ping_ms}ms</span>` : ""}
                    </div>
                    <span class="ws-pill ${sc}"><span class="material-icons">${sicon}</span>${slabel}</span>
                </div>
                <div class="wg-cells">
                    <div class="wg-cell"><div class="wg-cell-lbl">تراکنش امروز</div><div class="wg-cell-val">${(gate.txn_today||0).toLocaleString()}</div></div>
                    <div class="wg-cell bad"><div class="wg-cell-lbl">شکست امروز</div><div class="wg-cell-val">${gate.failed_today||0}</div></div>
                    <div class="wg-cell ${srCls}"><div class="wg-cell-lbl">نرخ موفق</div><div class="wg-cell-val">${sr}٪</div></div>
                    <div class="wg-cell good"><div class="wg-cell-lbl">مبلغ امروز</div><div class="wg-cell-val">${(gate.amount_today||0).toLocaleString()} ت</div></div>
                </div>
                <div class="wg-last">آخرین تراکنش: ${gate.last_success}</div>
            </div>`;
        });
        if (!html) html = `<div class="ws-empty">درگاهی فعال نیست</div>`;
        document.getElementById("paymentGatesContainer").innerHTML = html;
        const badge = document.getElementById("gatesBadge");
        if (badge) {
            badge.textContent = `${gates.length} درگاه`;
            badge.style.setProperty("--w-badge-color", err>0?"var(--down)":warn>0?"var(--warn)":"var(--up)");
            badge.style.setProperty("--w-badge-bg",    err>0?"var(--down-bg)":warn>0?"var(--warn-bg)":"var(--up-bg)");
        }
    }
    
    function renderEmailQueue(queue) {
        const sr = queue.success_rate || 100;
        const cp = queue.capacity_pct || 0;
        const srColor = sr>=90?"var(--up)":sr>=70?"var(--warn)":"var(--down)";
        const cpColor = cp>=80?"var(--down)":cp>=60?"var(--warn)":"var(--up)";
        const badge = document.getElementById("emailBadge");
        if (badge) {
            const q = queue.queued||0;
            badge.textContent = q > 0 ? `${q} در صف` : "خالی";
            badge.style.setProperty("--w-badge-color", q > 50 ? "var(--down)" : q > 10 ? "var(--warn)" : "var(--up)");
            badge.style.setProperty("--w-badge-bg",    q > 50 ? "var(--down-bg)" : q > 10 ? "var(--warn-bg)" : "var(--up-bg)");
        }
        let html = `
        <div class="wm-grid">
            <div class="wm-stat" style="--ms-a:var(--warn)">
                <div class="wm-label">در صف</div>
                <div class="wm-val">${queue.queued||0}</div>
                <div class="wm-sub">منتظر ارسال</div>
            </div>
            <div class="wm-stat" style="--ms-a:var(--up)">
                <div class="wm-label">ارسال امروز</div>
                <div class="wm-val">${queue.sent_today||0}</div>
                <div class="wm-sub" style="color:var(--up)">موفق</div>
            </div>
            <div class="wm-stat" style="--ms-a:var(--down)">
                <div class="wm-label">شکست امروز</div>
                <div class="wm-val">${queue.failed_today||0}</div>
                <div class="wm-sub">تلاش مجدد</div>
            </div>
            <div class="wm-stat" style="--ms-a:var(--info)">
                <div class="wm-label">در پردازش</div>
                <div class="wm-val">${queue.processing||0}</div>
                <div class="wm-sub">فعال</div>
            </div>
        </div>
        ${(queue.stuck||0)>0 ? `<div class="wm-alert"><span class="material-icons">warning</span>معلق +۳۰ دقیقه: ${queue.stuck}</div>` : ""}
        <div class="wm-prog">
            <div class="wm-prog-hd">
                <span class="wm-prog-lbl">نرخ موفقیت ۷ روز</span>
                <span class="wm-prog-val" style="color:${srColor}">${sr}٪</span>
            </div>
            <div class="wm-bar"><div class="wm-fill" style="width:${sr}%;background:${srColor}"></div></div>
        </div>
        <div class="wm-prog">
            <div class="wm-prog-hd">
                <span class="wm-prog-lbl">ظرفیت صف</span>
                <span class="wm-prog-val" style="color:${cpColor}">${cp}٪</span>
            </div>
            <div class="wm-bar"><div class="wm-fill" style="width:${cp}%;background:${cpColor}"></div></div>
        </div>`;
        if (queue.recent_failed && queue.recent_failed.length > 0) {
            html += `<div class="wm-fails-title">ایمیل‌های شکست‌خورده:</div>`;
            queue.recent_failed.forEach(em => {
                html += `<div class="wm-fail-row">
                    <div class="wm-fail-to">${em.recipient||"نامشخص"}</div>
                    <div class="wm-fail-sub">${em.subject||"بدون موضوع"}</div>
                    <div class="wm-fail-err">${em.error_message||"خطای نامشخص"}</div>
                    <div class="wm-fail-meta">${em.time_ago||""} · ${em.attempts||0} تلاش</div>
                </div>`;
            });
        }
        document.getElementById("emailQueueContainer").innerHTML = html;
    }
    
    function renderServerResources(res) {
        if (!res || !res.cpu) {
            document.getElementById("serverResourcesContainer").innerHTML =
                `<div class="ws-empty">اطلاعاتی موجود نیست</div>`;
            return;
        }
        function resColor(pct) {
            if (pct == null) return "var(--fg-muted)";
            return pct >= 85 ? "var(--down)" : pct >= 60 ? "var(--warn)" : "var(--up)";
        }
        function resItem(icon, name, pct, detail) {
            const c = resColor(pct);
            const w = pct != null ? pct : 0;
            return `<div class="wr-block">
                <div class="wr-top">
                    <div class="wr-left" style="color:${c}">
                        <span class="material-icons">${icon}</span>
                        <span class="wr-name">${name}</span>
                    </div>
                    <span class="wr-pct" style="color:${c}">${pct != null ? pct+"%" : "—"}</span>
                </div>
                ${detail ? `<div class="wr-detail">${detail}</div>` : ""}
                <div class="wr-bar"><div class="wr-fill" style="width:${w}%;background:${c}"></div></div>
            </div>`;
        }
        const cpu=res.cpu||{}, ram=res.ram||{}, disk=res.disk||{}, gpu=res.gpu||{};
        let cpuD="", ramD="", diskD="";
        if (cpu.cores) cpuD += cpu.cores + " هسته";
        if (cpu.freq)  cpuD += (cpuD?" · ":"") + cpu.freq;
        if (ram.used_gb !== undefined)  ramD  = ram.used_gb  + " / " + ram.total_gb  + " GB";
        if (disk.used_gb !== undefined) diskD = disk.used_gb + " / " + disk.total_gb + " GB";
        if (disk.type) diskD += (diskD?" · ":"") + disk.type;
        let html = resItem("developer_board","CPU",   cpu.pct  != null ? cpu.pct  : null, cpuD)
                 + resItem("storage",        "RAM",   ram.pct  != null ? ram.pct  : null, ramD)
                 + resItem("hard_drive",     "دیسک", disk.pct != null ? disk.pct : null, diskD);
        if (gpu.available) {
            let gpuD = gpu.vram_gb ? gpu.vram_gb+" GB VRAM" : "";
            if (gpu.model) gpuD += (gpuD?" · ":"") + gpu.model;
            html += resItem("videocam","GPU", gpu.pct != null ? gpu.pct : null, gpuD);
        }
        document.getElementById("serverResourcesContainer").innerHTML = html;
    }
    // بارگذاری اولیه
    loadSystemStatus();
    
    // دکمه رفرش
    const refreshSystemStatusBtn = document.getElementById("refreshSystemStatus");
if (refreshSystemStatusBtn) {
    refreshSystemStatusBtn.addEventListener("click", function() {
        loadSystemStatus();
    });
}
    
    // بارگذاری خودکار هر 60 ثانیه
    setInterval(loadSystemStatus, 60000);
});
</script>

<?php include view_path('layouts.admin'); ?>
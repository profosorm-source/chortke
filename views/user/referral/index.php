<?php
$title  = 'زیرمجموعه‌گیری و کمیسیون';
$layout = 'user';
ob_start();
?>

<div class="ref-wrap">

    <!-- ── HERO ────────────────────────────────────────────── -->
    <div class="ref-hero">
        <div class="ref-hero__left">
            <div class="ref-hero__icon">
                <i class="material-icons">diversity_3</i>
            </div>
            <div>
                <h1 class="ref-hero__title">زیرمجموعه‌گیری</h1>
                <p class="ref-hero__sub">با دعوت دوستان، از درآمد آن‌ها کمیسیون دریافت کنید</p>
            </div>
        </div>
    </div>

    <!-- ── INVITE CARD ─────────────────────────────────────── -->
    <div class="ref-invite-card">
        <div class="ref-invite-card__body">
            <div class="ref-invite-card__info">
                <div class="ref-invite-card__label">لینک دعوت اختصاصی شما</div>
                <div class="ref-invite-link-row">
                    <input type="text" id="referralLink" class="ref-invite-link-input"
                           value="<?= e($referralLink) ?>" readonly dir="ltr">
                    <button class="ref-copy-btn" id="btnCopy" onclick="copyLink()">
                        <i class="material-icons">content_copy</i>
                        کپی لینک
                    </button>
                </div>
                <div class="ref-invite-code">
                    کد دعوت: <strong dir="ltr"><?= e($user->referral_code ?? '—') ?></strong>
                </div>
            </div>

            <!-- Share Buttons -->
            <div class="ref-share-btns">
                <a href="https://t.me/share/url?url=<?= urlencode($referralLink) ?>&text=<?= urlencode('با این لینک ثبت‌نام کن و کسب درآمد کن!') ?>"
                   target="_blank" class="ref-share-btn ref-share-btn--telegram">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.562 8.248l-2.04 9.61c-.15.672-.543.836-1.1.52l-3.04-2.24-1.467 1.41c-.162.163-.298.298-.61.298l.218-3.087 5.622-5.08c.245-.217-.053-.338-.38-.12L7.36 14.12l-3.02-.943c-.657-.205-.67-.657.137-.973l11.8-4.55c.547-.2 1.025.12.845.595z"/>
                    </svg>
                    تلگرام
                </a>
                <a href="whatsapp://send?text=<?= urlencode('با این لینک ثبت‌نام کن: ' . $referralLink) ?>"
                   class="ref-share-btn ref-share-btn--whatsapp">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                    </svg>
                    واتساپ
                </a>
            </div>
        </div>
    </div>

    <!-- ── STATS ────────────────────────────────────────────── -->
    <div class="ref-stats">
        <div class="ref-stat ref-stat--green">
            <div class="ref-stat__icon"><i class="material-icons">group</i></div>
            <div class="ref-stat__body">
                <span class="ref-stat__val"><?= number_format($referredCount) ?></span>
                <span class="ref-stat__lbl">زیرمجموعه فعال</span>
            </div>
        </div>
        <div class="ref-stat ref-stat--gold">
            <div class="ref-stat__icon"><i class="material-icons">payments</i></div>
            <div class="ref-stat__body">
                <span class="ref-stat__val"><?= number_format($stats->total_earned_irt ?? 0) ?></span>
                <span class="ref-stat__lbl">کل درآمد (تومان)</span>
            </div>
        </div>
        <div class="ref-stat ref-stat--blue">
            <div class="ref-stat__icon"><i class="material-icons">currency_bitcoin</i></div>
            <div class="ref-stat__body">
                <span class="ref-stat__val" dir="ltr"><?= number_format($stats->total_earned_usdt ?? 0, 2) ?></span>
                <span class="ref-stat__lbl">کل درآمد (USDT)</span>
            </div>
        </div>
        <div class="ref-stat ref-stat--orange">
            <div class="ref-stat__icon"><i class="material-icons">schedule</i></div>
            <div class="ref-stat__body">
                <span class="ref-stat__val"><?= number_format($stats->pending_irt ?? 0) ?></span>
                <span class="ref-stat__lbl">در انتظار پرداخت (تومان)</span>
            </div>
        </div>
        <?php if (($stats->pending_usdt ?? 0) > 0): ?>
        <div class="ref-stat ref-stat--purple">
            <div class="ref-stat__icon"><i class="material-icons">pending</i></div>
            <div class="ref-stat__body">
                <span class="ref-stat__val" dir="ltr"><?= number_format($stats->pending_usdt ?? 0, 2) ?></span>
                <span class="ref-stat__lbl">در انتظار (USDT)</span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── COMMISSION RATES ─────────────────────────────────── -->
    <div class="ref-section">
        <div class="ref-section__header">
            <i class="material-icons">percent</i>
            <h2>درصد کمیسیون شما</h2>
        </div>
        <div class="ref-rates">
            <?php
            $rateIcons = [
                'task_reward'  => 'task_alt',
                'investment'   => 'trending_up',
                'vip_purchase' => 'workspace_premium',
                'story_order'  => 'auto_stories',
            ];
            foreach ($percents as $type => $percent):
            ?>
            <div class="ref-rate-card">
                <div class="ref-rate-card__icon">
                    <i class="material-icons"><?= $rateIcons[$type] ?? 'percent' ?></i>
                </div>
                <div class="ref-rate-card__pct"><?= e($percent) ?>%</div>
                <div class="ref-rate-card__lbl"><?= e($sourceTypes[$type] ?? $type) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="ref-rate-note">
            <i class="material-icons">info_outline</i>
            با هر فعالیت درآمدزای زیرمجموعه مستقیم شما، درصد مشخصی به کیف پول شما واریز می‌شود.
        </div>
    </div>

    <!-- ── REFERRED USERS ───────────────────────────────────── -->
    <div class="ref-section">
        <div class="ref-section__header">
            <i class="material-icons">group</i>
            <h2>زیرمجموعه‌های شما</h2>
            <span class="ref-section__count"><?= number_format($referredCount) ?> نفر</span>
        </div>

        <?php if (empty($referredUsers)): ?>
        <div class="ref-empty">
            <div class="ref-empty__icon"><i class="material-icons">person_add</i></div>
            <h3>هنوز زیرمجموعه‌ای ندارید</h3>
            <p>لینک دعوت خود را به اشتراک بگذارید تا کمیسیون دریافت کنید.</p>
        </div>
        <?php else: ?>
        <div class="ref-table-wrap">
            <table class="ref-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>نام</th>
                        <th>تاریخ عضویت</th>
                        <th>درآمد شما (تومان)</th>
                        <th>درآمد شما (USDT)</th>
                        <th>تعداد کمیسیون</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($referredUsers as $idx => $ru): ?>
                    <tr>
                        <td class="ref-td-num"><?= $idx + 1 ?></td>
                        <td>
                            <div class="ref-user-cell">
                                <div class="ref-user-avatar">
                                    <?= mb_substr($ru->full_name ?? 'ک', 0, 1, 'UTF-8') ?>
                                </div>
                                <span><?= e($ru->full_name ?? '—') ?></span>
                            </div>
                        </td>
                        <td class="ref-td-date"><?= to_jalali($ru->joined_at ?? '') ?></td>
                        <td class="ref-td-earn ref-text-irt"><?= number_format($ru->earned_irt ?? 0) ?></td>
                        <td class="ref-td-earn ref-text-usdt" dir="ltr"><?= number_format($ru->earned_usdt ?? 0, 2) ?></td>
                        <td>
                            <span class="ref-count-chip"><?= number_format($ru->commission_count ?? 0) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($referredCount > 10): ?>
        <div class="ref-load-more">
            <button class="ref-load-btn" onclick="loadMoreUsers(this)">
                <i class="material-icons">expand_more</i>
                نمایش بیشتر
            </button>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- ── RECENT COMMISSIONS ───────────────────────────────── -->
    <div class="ref-section">
        <div class="ref-section__header">
            <i class="material-icons">receipt_long</i>
            <h2>آخرین کمیسیون‌ها</h2>
            <span class="ref-section__count"><?= number_format($stats->total_count ?? 0) ?> تراکنش</span>
        </div>

        <?php if (empty($recentCommissions)): ?>
        <div class="ref-empty">
            <div class="ref-empty__icon"><i class="material-icons">hourglass_empty</i></div>
            <h3>هنوز کمیسیونی ثبت نشده</h3>
            <p>پس از اولین فعالیت زیرمجموعه، کمیسیون شما اینجا نمایش داده می‌شود.</p>
        </div>
        <?php else: ?>
        <div class="ref-table-wrap">
            <table class="ref-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>زیرمجموعه</th>
                        <th>منبع</th>
                        <th>مبلغ اصلی</th>
                        <th>درصد</th>
                        <th>کمیسیون</th>
                        <th>ارز</th>
                        <th>وضعیت</th>
                        <th>تاریخ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentCommissions as $idx => $c): ?>
                    <tr>
                        <td class="ref-td-num"><?= $idx + 1 ?></td>
                        <td class="ref-td-name"><?= e($c->referred_name ?? '—') ?></td>
                        <td>
                            <span class="ref-source-chip"><?= e($c->source_label ?? $c->source_type) ?></span>
                        </td>
                        <td dir="ltr" class="ref-td-amount">
                            <?= $c->currency === 'usdt'
                                ? number_format((float)$c->source_amount, 2)
                                : number_format((float)$c->source_amount) ?>
                        </td>
                        <td class="ref-td-pct"><?= e($c->commission_percent) ?>%</td>
                        <td dir="ltr" class="ref-td-comm">
                            <strong class="ref-text-earn">
                                <?= $c->currency === 'usdt'
                                    ? number_format((float)$c->commission_amount, 2)
                                    : number_format((float)$c->commission_amount) ?>
                            </strong>
                        </td>
                        <td>
                            <span class="ref-currency-chip ref-currency-chip--<?= $c->currency ?>">
                                <?= $c->currency === 'usdt' ? 'USDT' : 'تومان' ?>
                            </span>
                        </td>
                        <td>
                            <span class="ref-badge <?= $c->status_class ?>">
                                <?= e($c->status_label) ?>
                            </span>
                        </td>
                        <td class="ref-td-date"><?= to_jalali($c->created_at ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (($stats->total_count ?? 0) > 10): ?>
        <div class="ref-load-more">
            <button class="ref-load-btn" onclick="loadMoreCommissions(this)">
                <i class="material-icons">expand_more</i>
                نمایش بیشتر
            </button>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

</div>

<script>
// ── Copy Link ──────────────────────────────────────────────
function copyLink() {
    const input = document.getElementById('referralLink');
    const btn   = document.getElementById('btnCopy');

    navigator.clipboard.writeText(input.value).then(() => {
        btn.innerHTML = '<i class="material-icons">check</i> کپی شد!';
        btn.classList.add('ref-copy-btn--success');
        setTimeout(() => {
            btn.innerHTML = '<i class="material-icons">content_copy</i> کپی لینک';
            btn.classList.remove('ref-copy-btn--success');
        }, 2500);
        notyf.success('لینک دعوت کپی شد!');
    }).catch(() => {
        // fallback برای مرورگرهای قدیمی
        input.select();
        document.execCommand('copy');
        notyf.success('لینک دعوت کپی شد!');
    });
}

// ── Load More Users ────────────────────────────────────────
let usersPage = 1;
function loadMoreUsers(btn) {
    usersPage++;
    btn.disabled = true;
    btn.innerHTML = '<i class="material-icons" style="animation:spin .8s linear infinite">refresh</i> در حال بارگذاری...';

    fetch(`<?= url('/referral/referred-users') ?>?page=${usersPage}`, {
        headers: { 'X-CSRF-TOKEN': '<?= csrf_token() ?>' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success || !data.users.length) {
            btn.closest('.ref-load-more').remove();
            return;
        }
        const tbody = btn.closest('.ref-section').querySelector('tbody');
        const offset = (usersPage - 1) * 15;
        data.users.forEach((u, i) => {
            const tr = document.createElement('tr');
            const initial = u.full_name ? u.full_name.charAt(0) : 'ک';
            tr.innerHTML = `
                <td class="ref-td-num">${offset + i + 1}</td>
                <td><div class="ref-user-cell"><div class="ref-user-avatar">${initial}</div><span>${u.full_name || '—'}</span></div></td>
                <td class="ref-td-date">${u.joined_at_jalali || '—'}</td>
                <td class="ref-td-earn ref-text-irt">${Number(u.earned_irt||0).toLocaleString()}</td>
                <td class="ref-td-earn ref-text-usdt">${Number(u.earned_usdt||0).toFixed(2)}</td>
                <td><span class="ref-count-chip">${Number(u.commission_count||0).toLocaleString()}</span></td>
            `;
            tbody.appendChild(tr);
        });
        if (usersPage >= data.pages) btn.closest('.ref-load-more').remove();
        else { btn.disabled = false; btn.innerHTML = '<i class="material-icons">expand_more</i> نمایش بیشتر'; }
    })
    .catch(() => { btn.disabled = false; btn.innerHTML = '<i class="material-icons">expand_more</i> نمایش بیشتر'; });
}

// ── Load More Commissions ──────────────────────────────────
let commPage = 1;
function loadMoreCommissions(btn) {
    commPage++;
    btn.disabled = true;
    btn.innerHTML = '<i class="material-icons" style="animation:spin .8s linear infinite">refresh</i> در حال بارگذاری...';

    fetch(`<?= url('/referral/commissions') ?>?page=${commPage}`, {
        headers: { 'X-CSRF-TOKEN': '<?= csrf_token() ?>' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success || !data.commissions.length) {
            btn.closest('.ref-load-more').remove();
            return;
        }
        const tbody = btn.closest('.ref-section').querySelector('tbody');
        const offset = (commPage - 1) * 15;
        data.commissions.forEach((c, i) => {
            const isPaid = c.status === 'paid';
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="ref-td-num">${offset + i + 1}</td>
                <td class="ref-td-name">${c.referred_name || '—'}</td>
                <td><span class="ref-source-chip">${c.source_label || c.source_type}</span></td>
                <td dir="ltr" class="ref-td-amount">${c.currency==='usdt' ? Number(c.source_amount).toFixed(2) : Number(c.source_amount).toLocaleString()}</td>
                <td class="ref-td-pct">${c.commission_percent}%</td>
                <td dir="ltr" class="ref-td-comm"><strong class="ref-text-earn">${c.currency==='usdt' ? Number(c.commission_amount).toFixed(2) : Number(c.commission_amount).toLocaleString()}</strong></td>
                <td><span class="ref-currency-chip ref-currency-chip--${c.currency}">${c.currency==='usdt'?'USDT':'تومان'}</span></td>
                <td><span class="ref-badge ${c.status_class}">${c.status_label}</span></td>
                <td class="ref-td-date">${c.created_at_jalali || '—'}</td>
            `;
            tbody.appendChild(tr);
        });
        if (commPage >= data.pages) btn.closest('.ref-load-more').remove();
        else { btn.disabled = false; btn.innerHTML = '<i class="material-icons">expand_more</i> نمایش بیشتر'; }
    })
    .catch(() => { btn.disabled = false; btn.innerHTML = '<i class="material-icons">expand_more</i> نمایش بیشتر'; });
}

// spin animation
const style = document.createElement('style');
style.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
document.head.appendChild(style);
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>

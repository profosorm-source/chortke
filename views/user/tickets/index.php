<?php
$pageTitle = 'تیکت‌های پشتیبانی';
ob_start();

use App\Enums\TicketStatus;
use App\Enums\TicketPriority;
?>

<div class="tkt-wrap">

    <!-- HEADER -->
    <div class="tkt-hero">
        <div class="tkt-hero__left">
            <div class="tkt-hero__icon">
                <i class="material-icons">support_agent</i>
            </div>
            <div>
                <h1 class="tkt-hero__title">تیکت‌های پشتیبانی</h1>
                <p class="tkt-hero__sub">مجموع <strong><?= number_format($total ?? 0) ?></strong> تیکت</p>
            </div>
        </div>
        <a href="<?= url('/tickets/create') ?>" class="tkt-btn-new">
            <i class="material-icons">add</i>
            تیکت جدید
        </a>
    </div>

    <?php if (($unreadCount ?? 0) > 0): ?>
    <div class="tkt-alert">
        <i class="material-icons">mark_chat_unread</i>
        <span>شما <strong><?= $unreadCount ?></strong> پاسخ خوانده‌نشده دارید.</span>
    </div>
    <?php endif; ?>

    <!-- FILTER -->
    <form method="GET" action="<?= url('/tickets') ?>" class="tkt-filter">
        <select name="status" class="tkt-filter__select">
            <option value="">همه وضعیت‌ها</option>
            <?php foreach (TicketStatus::all() as $s): ?>
                <option value="<?= e($s) ?>" <?= ($status ?? '') === $s ? 'selected' : '' ?>>
                    <?= TicketStatus::label($s) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="tkt-filter__btn">
            <i class="material-icons">filter_list</i>فیلتر
        </button>
        <?php if (!empty($status)): ?>
        <a href="<?= url('/tickets') ?>" class="tkt-filter__clear"><i class="material-icons">close</i></a>
        <?php endif; ?>
    </form>

    <!-- LIST -->
    <?php if (empty($tickets)): ?>
    <div class="tkt-empty">
        <div class="tkt-empty__icon"><i class="material-icons">confirmation_number</i></div>
        <h3>هنوز تیکتی ثبت نکرده‌اید</h3>
        <p>برای ارتباط با تیم پشتیبانی، اولین تیکت خود را ثبت کنید.</p>
        <a href="<?= url('/tickets/create') ?>" class="tkt-btn-new" style="margin-top:16px">
            <i class="material-icons">add</i>ایجاد اولین تیکت
        </a>
    </div>
    <?php else: ?>
    <div class="tkt-list">
        <?php foreach ($tickets as $ticket):
            $isNew   = $ticket->last_reply_by === 'admin';
            $prClass = 'tkt-priority--' . ($ticket->priority ?? 'normal');
            $stClass = 'tkt-status--'   . str_replace('_','-', $ticket->status ?? 'open');
        ?>
        <a href="<?= url('/tickets/show/' . $ticket->id) ?>" class="tkt-card<?= $isNew ? ' tkt-card--new' : '' ?>">
            <div class="tkt-card__head">
                <div class="tkt-card__cat">
                    <i class="material-icons"><?= e($ticket->category_icon) ?></i>
                    <?= e($ticket->category_name) ?>
                </div>
                <div class="tkt-card__badges">
                    <span class="tkt-status <?= $stClass ?>"><?= TicketStatus::label($ticket->status) ?></span>
                    <span class="tkt-priority <?= $prClass ?>"><?= TicketPriority::label($ticket->priority) ?></span>
                    <?php if ($isNew): ?><span class="tkt-new-badge">پاسخ جدید</span><?php endif; ?>
                </div>
            </div>
            <div class="tkt-card__body">
                <p class="tkt-card__subject"><?= e($ticket->subject) ?></p>
                <span class="tkt-card__id">#<?= e($ticket->id) ?></span>
            </div>
            <div class="tkt-card__foot">
                <span class="tkt-card__date">
                    <i class="material-icons">schedule</i>
                    <?= to_jalali($ticket->updated_at) ?>
                </span>
                <span class="tkt-card__arrow"><i class="material-icons">chevron_left</i></span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if (($totalPages ?? 1) > 1): ?>
    <div class="tkt-pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a class="tkt-page<?= $i == ($page ?? 1) ? ' active' : '' ?>"
           href="<?= url('/tickets?status=' . urlencode($status ?? '') . '&page=' . $i) ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

</div>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>

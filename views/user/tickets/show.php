<?php
$pageTitle = 'تیکت #' . $ticket->id;
ob_start();

use App\Enums\TicketStatus;
use App\Enums\TicketPriority;
?>

<div class="tkt-show-wrap">

    <!-- BACK + TITLE -->
    <div class="tkt-show-header">
        <a href="<?= url('/tickets') ?>" class="tkt-back-btn">
            <i class="material-icons">arrow_forward</i>
            بازگشت
        </a>
        <div class="tkt-show-title">
            <h1><?= e($ticket->subject) ?></h1>
            <span class="tkt-show-id">#<?= e($ticket->id) ?></span>
        </div>
    </div>

    <div class="tkt-show-layout">

        <!-- SIDEBAR: ticket info -->
        <aside class="tkt-show-side">
            <div class="tkt-info-card">
                <div class="tkt-info-card__header">
                    <i class="material-icons">info_outline</i>
                    جزئیات تیکت
                </div>
                <div class="tkt-info-card__body">
                    <div class="tkt-info-row">
                        <span class="tkt-info-row__lbl">شماره</span>
                        <code class="tkt-info-row__val">#<?= e($ticket->id) ?></code>
                    </div>
                    <div class="tkt-info-row">
                        <span class="tkt-info-row__lbl">وضعیت</span>
                        <span class="tkt-status tkt-status--<?= str_replace('_','-',$ticket->status) ?>">
                            <?= TicketStatus::label($ticket->status) ?>
                        </span>
                    </div>
                    <div class="tkt-info-row">
                        <span class="tkt-info-row__lbl">اولویت</span>
                        <span class="tkt-priority tkt-priority--<?= $ticket->priority ?>">
                            <?= TicketPriority::label($ticket->priority) ?>
                        </span>
                    </div>
                    <div class="tkt-info-row">
                        <span class="tkt-info-row__lbl">دسته</span>
                        <span class="tkt-info-row__val">
                            <i class="material-icons" style="font-size:13px;vertical-align:middle"><?= e($ticket->category_icon) ?></i>
                            <?= e($ticket->category_name) ?>
                        </span>
                    </div>
                    <div class="tkt-info-row">
                        <span class="tkt-info-row__lbl">تاریخ ثبت</span>
                        <span class="tkt-info-row__val"><?= to_jalali($ticket->created_at) ?></span>
                    </div>
                    <div class="tkt-info-row">
                        <span class="tkt-info-row__lbl">آخرین به‌روزرسانی</span>
                        <span class="tkt-info-row__val"><?= to_jalali($ticket->updated_at) ?></span>
                    </div>
                    <?php if ($ticket->closed_at): ?>
                    <div class="tkt-info-row">
                        <span class="tkt-info-row__lbl">تاریخ بسته‌شدن</span>
                        <span class="tkt-info-row__val"><?= to_jalali($ticket->closed_at) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($ticket->status !== 'closed'): ?>
            <button class="tkt-close-btn" onclick="closeTicket(<?= e($ticket->id) ?>)">
                <i class="material-icons">lock</i>
                بستن تیکت
            </button>
            <?php endif; ?>
        </aside>

        <!-- MAIN: chat thread -->
        <main class="tkt-show-main">
            <div class="tkt-chat-card">
                <div class="tkt-chat-card__header">
                    <i class="material-icons">chat_bubble_outline</i>
                    گفتگو
                    <span class="tkt-msg-count"><?= count($messages ?? []) ?> پیام</span>
                </div>

                <!-- Messages -->
                <div class="tkt-thread" id="messagesContainer">
                    <?php foreach ($messages as $message): ?>
                    <div class="tkt-msg tkt-msg--<?= $message->is_admin ? 'admin' : 'user' ?>">
                        <div class="tkt-msg__avatar tkt-msg__avatar--<?= $message->is_admin ? 'support' : 'user' ?>">
                            <?php if ($message->is_admin): ?>
                            <i class="material-icons">support_agent</i>
                            <?php else: ?>
                            <?= mb_substr($message->full_name ?? 'ک', 0, 1, 'UTF-8') ?>
                            <?php endif; ?>
                        </div>
                        <div class="tkt-msg__content">
                            <div class="tkt-msg__meta">
                                <strong><?= $message->is_admin ? 'تیم پشتیبانی' : e($message->full_name) ?></strong>
                                <time><?= to_jalali($message->created_at) ?></time>
                            </div>
                            <div class="tkt-msg__bubble">
                                <?= nl2br(e($message->message)) ?>
                                <?php if ($message->attachments): ?>
                                    <?php $attachments = json_decode($message->attachments, true); ?>
                                    <?php if (!empty($attachments)): ?>
                                    <div class="tkt-attachments">
                                        <?php foreach ($attachments as $file): ?>
                                        <a href="<?= url($file['path']) ?>" target="_blank" class="tkt-attach-link">
                                            <i class="material-icons">attach_file</i>
                                            <?= e($file['name']) ?>
                                        </a>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Reply Form -->
                <?php if ($ticket->status !== 'closed'): ?>
                <div class="tkt-reply-area">
                    <div class="tkt-reply-area__label">
                        <i class="material-icons">reply</i>
                        پاسخ شما
                    </div>
                    <form id="replyForm" enctype="multipart/form-data">
                        <input type="hidden" name="ticket_id" value="<?= e($ticket->id) ?>">
                        <textarea name="message" class="tkt-textarea" rows="4"
                                  placeholder="پیام خود را بنویسید..." required></textarea>
                        <div class="tkt-reply-footer">
                            <label class="tkt-attach-btn" for="replyFiles">
                                <i class="material-icons">attach_file</i>
                                پیوست
                                <input type="file" id="replyFiles" name="attachments[]"
                                       multiple accept="image/*" class="tkt-file-input">
                            </label>
                            <span class="tkt-file-names" id="fileNames"></span>
                            <button type="submit" class="tkt-send-btn" id="sendBtn">
                                <i class="material-icons">send</i>
                                ارسال پاسخ
                            </button>
                        </div>
                    </form>
                </div>
                <?php else: ?>
                <div class="tkt-closed-notice">
                    <i class="material-icons">lock</i>
                    این تیکت بسته شده است و امکان ارسال پاسخ وجود ندارد.
                </div>
                <?php endif; ?>
            </div>
        </main>

    </div>
</div>

<script>
// نمایش نام فایل‌های انتخاب‌شده
document.getElementById('replyFiles')?.addEventListener('change', function() {
    const names = Array.from(this.files).map(f => f.name);
    const el = document.getElementById('fileNames');
    el.textContent = names.length ? names.join('، ') : '';
});

// ارسال پاسخ با پشتیبانی از فایل پیوست
document.getElementById('replyForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('sendBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="material-icons" style="animation:spin .8s linear infinite">refresh</i> در حال ارسال...';

    const formData = new FormData(this);

    fetch('<?= url('/tickets/reply') ?>', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '<?= csrf_token() ?>' },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            notyf.success(data.message);
            setTimeout(() => location.reload(), 900);
        } else {
            notyf.error(data.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="material-icons">send</i> ارسال پاسخ';
        }
    })
    .catch(() => {
        notyf.error('خطا در ارسال پاسخ');
        btn.disabled = false;
        btn.innerHTML = '<i class="material-icons">send</i> ارسال پاسخ';
    });
});

// بستن تیکت
function closeTicket(id) {
    Swal.fire({
        title: 'بستن تیکت',
        text: 'آیا مطمئن هستید؟ پس از بستن امکان ارسال پاسخ وجود نخواهد داشت.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'بله، ببند',
        cancelButtonText: 'انصراف',
        confirmButtonColor: '#ef4444'
    }).then(result => {
        if (result.isConfirmed) {
            fetch('<?= url('/tickets/close') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= csrf_token() ?>' },
                body: JSON.stringify({ id })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('موفق', data.message, 'success').then(() => location.reload());
                } else {
                    Swal.fire('خطا', data.message, 'error');
                }
            });
        }
    });
}

// اسکرول به آخرین پیام
window.addEventListener('load', () => {
    const c = document.getElementById('messagesContainer');
    if (c) c.scrollTop = c.scrollHeight;
});
</script>

<style>
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>

<?php
$pageTitle = 'تیکت #' . $ticket->id;
$title = $pageTitle;
$layout = 'admin';
ob_start();
use App\Enums\TicketStatus;
use App\Enums\TicketPriority;
$priorityMap = ['low'=>'badge-muted','normal'=>'badge-info','high'=>'badge-warning','urgent'=>'badge-danger'];
$priorityLabel = ['low'=>'کم','normal'=>'معمولی','high'=>'زیاد','urgent'=>'فوری'];
?>

<!-- PAGE HEADER -->
<div class="bx-page-header">
  <div class="bx-page-header__left">
    <div class="bx-page-header__icon bx-page-header__icon--blue"><i class="material-icons">support_agent</i></div>
    <div>
      <h1 class="bx-page-header__title"><?= e($ticket->subject ?? 'تیکت') ?></h1>
      <p class="bx-page-header__sub">
        تیکت <strong>#<?= e($ticket->id) ?></strong>
        &nbsp;·&nbsp; <?= e($ticket->category_name ?? '—') ?>
        &nbsp;·&nbsp; <?= to_jalali($ticket->created_at ?? '') ?>
      </p>
    </div>
  </div>
  <a href="<?= url('/admin/tickets') ?>" class="btn btn-secondary btn-sm">
    <i class="material-icons">arrow_forward</i>بازگشت
  </a>
</div>

<div class="bx-review-layout">

  <!-- SIDEBAR -->
  <div class="bx-review-layout__side">

    <!-- Ticket Info -->
    <div class="bx-info-card">
      <div class="bx-info-card__header">
        <i class="material-icons">info</i><h6>اطلاعات تیکت</h6>
      </div>
      <div class="bx-info-card__body bx-info-card__body--p0">
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">شماره</span>
          <code>#<?= e($ticket->id) ?></code>
        </div>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">وضعیت</span>
          <span class="bx-badge badge-info" id="ticketStatusBadge"><?= TicketStatus::label($ticket->status ?? '') ?></span>
        </div>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">اولویت</span>
          <span class="bx-badge <?= $priorityMap[$ticket->priority ?? 'normal'] ?? 'badge-muted' ?>">
            <?= $priorityLabel[$ticket->priority ?? 'normal'] ?? '—' ?>
          </span>
        </div>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">دسته</span>
          <span><?= e($ticket->category_name ?? '—') ?></span>
        </div>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">تاریخ ایجاد</span>
          <span><?= to_jalali($ticket->created_at ?? '') ?></span>
        </div>
        <div class="bx-info-row bx-info-row--padded">
          <span class="bx-info-row__label">آخرین پاسخ</span>
          <span><?= to_jalali($ticket->updated_at ?? '') ?></span>
        </div>
      </div>
    </div>

    <!-- User Info -->
    <div class="bx-info-card">
      <div class="bx-info-card__header">
        <i class="material-icons">person</i><h6>کاربر</h6>
      </div>
      <div class="bx-info-card__body">
        <div class="bx-user-profile">
          <div class="bx-user-profile__avatar" style="background:linear-gradient(135deg,#5b8af5,#7c3aed)">
            <?= mb_substr($ticket->user_name ?? 'ک', 0, 1, 'UTF-8') ?>
          </div>
          <div class="bx-user-profile__info">
            <strong><?= e($ticket->user_name ?? '—') ?></strong>
            <small><?= e($ticket->user_email ?? '—') ?></small>
          </div>
        </div>
      </div>
    </div>

    <!-- Change Status -->
    <div class="bx-info-card">
      <div class="bx-info-card__header">
        <i class="material-icons">settings</i><h6>مدیریت وضعیت</h6>
      </div>
      <div class="bx-info-card__body">
        <div class="bx-field-group">
          <label>تغییر وضعیت تیکت</label>
          <select class="bx-input" id="statusSelect">
            <?php foreach (TicketStatus::all() as $sv): ?>
            <option value="<?= e($sv) ?>" <?= $ticket->status === $sv ? 'selected' : '' ?>>
              <?= TicketStatus::label($sv) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-primary" style="width:100%" onclick="changeStatus(<?= e($ticket->id) ?>)">
          <i class="material-icons">save</i>ذخیره وضعیت
        </button>
      </div>
    </div>

  </div><!-- /side -->

  <!-- MAIN: Messages + Reply -->
  <div class="bx-review-layout__main">

    <div class="bx-info-card">
      <div class="bx-info-card__header">
        <i class="material-icons">chat</i>
        <h6>گفتگو</h6>
        <span class="bx-badge badge-muted" style="margin-right:auto"><?= count($messages ?? []) ?> پیام</span>
      </div>
      <div class="bx-info-card__body">

        <!-- Messages -->
        <div class="bx-chat-thread" id="messagesContainer">
          <?php foreach ($messages ?? [] as $message): ?>
          <div class="bx-chat-msg bx-chat-msg--<?= $message->is_admin ? 'admin' : 'user' ?>">
            <div class="bx-chat-msg__meta">
              <div class="bx-chat-msg__avatar bx-chat-msg__avatar--<?= $message->is_admin ? 'gold' : 'blue' ?>">
                <?= $message->is_admin ? 'A' : mb_substr($message->full_name ?? 'ک', 0, 1, 'UTF-8') ?>
              </div>
              <div>
                <strong><?= $message->is_admin ? 'پشتیبانی' : e($message->full_name) ?></strong>
                <time><?= to_jalali($message->created_at) ?></time>
              </div>
            </div>
            <div class="bx-chat-msg__bubble">
              <?= nl2br(e($message->message)) ?>
              <?php if ($message->attachments): $atts = json_decode($message->attachments, true); if (!empty($atts)): ?>
              <div class="bx-chat-attachments">
                <?php foreach ($atts as $file): ?>
                <a href="<?= url($file['path']) ?>" target="_blank" class="bx-attach-link">
                  <i class="material-icons">attach_file</i><?= e($file['name']) ?>
                </a>
                <?php endforeach; ?>
              </div>
              <?php endif; endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Reply Form -->
        <div class="bx-reply-form">
          <div class="bx-reply-form__label"><i class="material-icons">reply</i>ارسال پاسخ</div>
          <form id="replyForm">
            <input type="hidden" name="ticket_id" value="<?= e($ticket->id) ?>">
            <textarea name="message" class="bx-input" rows="4" placeholder="پاسخ خود را بنویسید..." required></textarea>
            <div style="margin-top:10px">
              <button type="submit" class="btn btn-primary">
                <i class="material-icons">send</i>ارسال پاسخ
              </button>
            </div>
          </form>
        </div>

      </div>
    </div>

  </div><!-- /main -->

</div>

<script>
document.getElementById('replyForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const fd = new FormData(this);
  fetch('<?= url('/admin/tickets/reply') ?>', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?= csrf_token() ?>'}, body:JSON.stringify({ticket_id:fd.get('ticket_id'),message:fd.get('message')}) })
  .then(r=>r.json()).then(data => { if(data.success){notyf.success(data.message);setTimeout(()=>location.reload(),1000);}else{notyf.error(data.message);} });
});

function changeStatus(id) {
  const status = document.getElementById('statusSelect').value;
  fetch('<?= url('/admin/tickets/change-status') ?>', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'<?= csrf_token() ?>'}, body:JSON.stringify({id,status}) })
  .then(r=>r.json()).then(data => { if(data.success){notyf.success(data.message);setTimeout(()=>location.reload(),1000);}else{notyf.error(data.message);} });
}
</script>

<?php $content = ob_get_clean(); require_once view_path('layouts.admin'); ?>

<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-primary">assignment_turned_in</span> اجراهای Adtask من</h4>
    <p class="text-muted mb-0" style="font-size:12px;">تسک‌هایی که انجام داده‌اید</p>
  </div>
  <a href="<?= url('/custom-tasks/available') ?>" class="btn btn-outline-primary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">work_outline</span> تسک‌های موجود
  </a>
</div>

<?php if(empty($submissions)): ?>
<div class="card mt-4">
  <div class="card-body text-center py-5">
    <span class="material-icons text-muted" style="font-size:64px;">assignment</span>
    <h5 class="mt-3 text-muted">هنوز تسکی انجام نداده‌اید</h5>
    <a href="<?= url('/custom-tasks/available') ?>" class="btn btn-primary mt-2">دیدن تسک‌های موجود</a>
  </div>
</div>
<?php else: ?>
<div class="card mt-3">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>تسک</th>
            <th>پاداش</th>
            <th>وضعیت</th>
            <th>تاریخ ارسال</th>
            <th>توضیح</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($submissions as $sub): ?>
          <tr>
            <td>
              <div class="fw-bold"><?= e(mb_substr($sub->task_title ?? '—', 0, 50)) ?></div>
              <small class="text-muted">#<?= $sub->task_id ?? '' ?></small>
            </td>
            <td class="text-success fw-bold"><?= number_format($sub->reward_amount ?? 0) ?> تومان</td>
            <td>
              <?php
                $sc = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','in_progress'=>'info'];
                $sl = ['pending'=>'در انتظار','approved'=>'تایید شد ✓','rejected'=>'رد شد','in_progress'=>'در حال بررسی'];
                $st = $sub->status ?? 'pending';
              ?>
              <span class="badge bg-<?= $sc[$st] ?? 'secondary' ?>"><?= $sl[$st] ?? $st ?></span>
            </td>
            <td style="font-size:12px;"><?= e(substr($sub->created_at ?? '', 0, 16)) ?></td>
            <td>
              <?php if(!empty($sub->rejection_reason)): ?>
              <small class="text-danger"><?= e(mb_substr($sub->rejection_reason, 0, 50)) ?></small>
              <?php elseif(!empty($sub->proof_text)): ?>
              <small class="text-muted"><?= e(mb_substr($sub->proof_text, 0, 50)) ?></small>
              <?php else: ?>—<?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if(($page ?? 1) > 1 || count($submissions) >= 20): ?>
<div class="d-flex justify-content-center mt-3">
  <nav><ul class="pagination pagination-sm">
    <?php if($page > 1): ?>
    <li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>">قبلی</a></li>
    <?php endif; ?>
    <li class="page-item active"><a class="page-link" href="#"><?= $page ?></a></li>
    <?php if(count($submissions) >= 20): ?>
    <li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>">بعدی</a></li>
    <?php endif; ?>
  </ul></nav>
</div>
<?php endif; ?>
<?php endif; ?>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>
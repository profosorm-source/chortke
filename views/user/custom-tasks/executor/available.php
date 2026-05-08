<?php $layout='user'; ob_start(); ?>
<div class="content-header d-flex justify-content-between align-items-center">
  <div>
    <h4 class="page-title mb-1"><span class="material-icons text-primary">work_outline</span> تسک سفارشی — تسک‌های موجود</h4>
    <p class="text-muted mb-0" style="font-size:12px;">تسک‌های سفارشی را انجام دهید و درآمد کسب کنید</p>
  </div>
  <a href="<?= url('/custom-tasks/my-submissions') ?>" class="btn btn-outline-secondary btn-sm">
    <span class="material-icons" style="font-size:16px;vertical-align:middle;">assignment_turned_in</span> اجراهای من
  </a>
</div>

<?php if(empty($tasks)): ?>
<div class="card mt-4">
  <div class="card-body text-center py-5">
    <span class="material-icons text-muted" style="font-size:64px;">work_outline</span>
    <h5 class="mt-3 text-muted">در حال حاضر تسکی موجود نیست</h5>
    <p class="text-muted small">بعداً مراجعه کنید. تسک‌های جدید به صورت مرتب اضافه می‌شوند.</p>
  </div>
</div>
<?php else: ?>
<div class="row mt-3">
<?php foreach($tasks as $task): ?>
<div class="col-md-6 col-lg-4 mb-3">
  <div class="card h-100">
    <?php if(!empty($task->sample_image)): ?>
    <img src="<?= e($task->sample_image) ?>" class="card-img-top" style="height:120px;object-fit:cover;">
    <?php endif; ?>
    <div class="card-body d-flex flex-column">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <span class="badge bg-info"><?= e($task->task_type ?? 'custom') ?></span>
        <span class="fw-bold text-success"><?= number_format($task->reward_per_user ?? 0) ?> تومان</span>
      </div>
      <h6 class="fw-bold mb-1"><?= e(mb_substr($task->title, 0, 60)) ?><?= mb_strlen($task->title) > 60 ? '...' : '' ?></h6>
      <p class="text-muted small flex-grow-1"><?= e(mb_substr($task->description ?? '', 0, 100)) ?><?= mb_strlen($task->description ?? '') > 100 ? '...' : '' ?></p>
      <div class="d-flex justify-content-between small text-muted mb-3">
        <span><span class="material-icons" style="font-size:14px;vertical-align:middle;">people</span> <?= number_format($task->slots_remaining ?? 0) ?> جای خالی</span>
        <span><span class="material-icons" style="font-size:14px;vertical-align:middle;">timer</span>
          <?php if($task->deadline ?? null): ?>
            تا <?= e(substr($task->deadline, 0, 10)) ?>
          <?php else: ?>بدون محدودیت<?php endif; ?>
        </span>
      </div>
      <a href="<?= url("/custom-tasks/{$task->id}") ?>" class="btn btn-primary btn-sm mt-auto">
        <span class="material-icons" style="font-size:16px;vertical-align:middle;">launch</span> مشاهده و شروع
      </a>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>

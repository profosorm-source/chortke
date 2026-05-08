<?php $layout='user'; ob_start(); ?>

<div class="content-header d-flex justify-content-between align-items-center">
  <h4 class="page-title mb-0">
    <i class="material-icons text-primary">search</i> انتخاب اینفلوئنسر
  </h4>
  <a href="<?= url('/influencer/advertise/my-orders') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="material-icons" style="font-size:15px;vertical-align:middle;">list_alt</i> سفارش‌های من
  </a>
</div>

<!-- فیلترها -->
<div class="card mt-3">
  <div class="card-body py-2">
    <form method="GET" action="<?= url('/influencer/advertise') ?>">
      <div class="row g-2 align-items-end">
        <div class="col-md-3 col-6">
          <label class="form-label small mb-1">جستجو</label>
          <input type="text" name="search" class="form-control form-control-sm"
                 placeholder="نام کاربری..." value="<?= e($filters['search'] ?? '') ?>">
        </div>
        <div class="col-md-2 col-6">
          <label class="form-label small mb-1">پلتفرم</label>
          <select name="platform" class="form-select form-select-sm">
            <option value="">همه</option>
            <?php foreach($platforms as $k => $v): ?>
              <option value="<?= e($k) ?>" <?= ($filters['platform'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 col-6">
          <label class="form-label small mb-1">دسته‌بندی</label>
          <select name="category" class="form-select form-select-sm">
            <option value="">همه</option>
            <?php foreach($categories as $cat): ?>
              <option value="<?= e($cat) ?>" <?= ($filters['category'] ?? '') === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 col-6">
          <label class="form-label small mb-1">حداقل فالوور</label>
          <select name="min_followers" class="form-select form-select-sm">
            <option value="">هر تعداد</option>
            <option value="1000" <?= ($filters['min_followers'] ?? '') == '1000' ? 'selected' : '' ?>>+۱K</option>
            <option value="5000" <?= ($filters['min_followers'] ?? '') == '5000' ? 'selected' : '' ?>>+۵K</option>
            <option value="10000" <?= ($filters['min_followers'] ?? '') == '10000' ? 'selected' : '' ?>>+۱۰K</option>
            <option value="50000" <?= ($filters['min_followers'] ?? '') == '50000' ? 'selected' : '' ?>>+۵۰K</option>
            <option value="100000" <?= ($filters['min_followers'] ?? '') == '100000' ? 'selected' : '' ?>>+۱۰۰K</option>
          </select>
        </div>
        <div class="col-md-2 col-6">
          <label class="form-label small mb-1">مرتب‌سازی</label>
          <select name="sort" class="form-select form-select-sm">
            <option value="priority" <?= ($filters['sort'] ?? 'priority') === 'priority' ? 'selected' : '' ?>>پیشنهادی</option>
            <option value="followers" <?= ($filters['sort'] ?? '') === 'followers' ? 'selected' : '' ?>>بیشترین فالوور</option>
            <option value="rating" <?= ($filters['sort'] ?? '') === 'rating' ? 'selected' : '' ?>>بهترین رتبه</option>
            <option value="orders" <?= ($filters['sort'] ?? '') === 'orders' ? 'selected' : '' ?>>بیشترین سفارش</option>
            <option value="price_low" <?= ($filters['sort'] ?? '') === 'price_low' ? 'selected' : '' ?>>ارزان‌ترین</option>
          </select>
        </div>
        <div class="col-md-1 col-12">
          <button type="submit" class="btn btn-primary btn-sm w-100">
            <i class="material-icons" style="font-size:16px;vertical-align:middle;">filter_alt</i>
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- نتایج -->
<?php if(!empty($total)): ?>
  <div class="small text-muted mt-2 mb-1"><?= number_format($total) ?> اینفلوئنسر یافت شد</div>
<?php endif; ?>

<?php if(empty($profiles)): ?>
  <div class="card mt-3">
    <div class="card-body text-center py-5">
      <i class="material-icons text-muted" style="font-size:48px;">search_off</i>
      <h6 class="mt-2 text-muted">اینفلوئنسری با این شرایط یافت نشد.</h6>
    </div>
  </div>
<?php else: ?>

<!-- جدول سبک صرافی -->
<div class="card mt-2">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="influencerTable">
        <thead class="table-dark">
          <tr>
            <th style="min-width:200px;">اینفلوئنسر</th>
            <th class="text-center" style="min-width:90px;">فالوور</th>
            <th class="text-center" style="min-width:80px;">رتبه</th>
            <th class="text-center" style="min-width:80px;">تکمیل</th>
            <th class="text-center" style="min-width:80px;">اختلاف</th>
            <th class="text-center" style="min-width:100px;">استوری</th>
            <th class="text-center" style="min-width:100px;">پست ۲۴h</th>
            <th class="text-center" style="min-width:80px;">سفارش‌ها</th>
            <th class="text-center" style="min-width:110px;">عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($profiles as $p):
            $s = $statsMap[(int)$p->id] ?? null;
            $gradeColors = ['A'=>'success','B'=>'primary','C'=>'warning','D'=>'orange','F'=>'danger'];
            $grade = $s ? $s->grade : '—';
            $gradeColor = $gradeColors[$grade] ?? 'secondary';
          ?>
          <tr class="influencer-row" style="cursor:pointer;"
              onclick="showDetail(<?= (int)$p->id ?>)"
              data-id="<?= (int)$p->id ?>">
            <!-- اینفلوئنسر -->
            <td>
              <div class="d-flex align-items-center gap-2">
                <?php if(!empty($p->profile_image)): ?>
                  <img src="<?= e($p->profile_image) ?>" class="rounded-circle"
                       style="width:38px;height:38px;object-fit:cover;flex-shrink:0;">
                <?php else: ?>
                  <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center text-white"
                       style="width:38px;height:38px;flex-shrink:0;font-size:14px;font-weight:bold;">
                    <?= mb_strtoupper(mb_substr($p->username, 0, 1)) ?>
                  </div>
                <?php endif; ?>
                <div>
                  <div class="fw-bold small">@<?= e($p->username) ?></div>
                  <?php if(!empty($p->category)): ?>
                    <div class="text-muted" style="font-size:11px;"><?= e($p->category) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <!-- فالوور -->
            <td class="text-center small fw-bold">
              <?php
                $f = (int)($p->follower_count ?? 0);
                echo $f >= 1000000 ? round($f/1000000,1).'M' : ($f >= 1000 ? round($f/1000,1).'K' : $f);
              ?>
            </td>
            <!-- رتبه -->
            <td class="text-center">
              <span class="badge bg-<?= $gradeColor ?> px-2">
                <?= e($grade) ?>
                <?php if($s): ?>
                  <span style="font-size:10px;">(<?= e($s->grade_label) ?>)</span>
                <?php endif; ?>
              </span>
            </td>
            <!-- نرخ تکمیل -->
            <td class="text-center small">
              <?php if($s && $s->total_orders > 0): ?>
                <span class="text-<?= $s->completion_rate >= 80 ? 'success' : ($s->completion_rate >= 60 ? 'warning' : 'danger') ?>
                            fw-bold"><?= $s->completion_rate ?>%</span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <!-- نرخ اختلاف -->
            <td class="text-center small">
              <?php if($s && $s->total_orders > 0): ?>
                <span class="text-<?= $s->dispute_rate <= 10 ? 'success' : ($s->dispute_rate <= 25 ? 'warning' : 'danger') ?>
                            fw-bold"><?= $s->dispute_rate ?>%</span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <!-- قیمت استوری -->
            <td class="text-center small fw-bold text-success">
              <?= $p->story_price_24h > 0 ? number_format($p->story_price_24h) : '<span class="text-muted">—</span>' ?>
            </td>
            <!-- قیمت پست -->
            <td class="text-center small fw-bold text-primary">
              <?= $p->post_price_24h > 0 ? number_format($p->post_price_24h) : '<span class="text-muted">—</span>' ?>
            </td>
            <!-- سفارش‌ها -->
            <td class="text-center small text-muted">
              <?= number_format($p->completed_orders ?? 0) ?>
            </td>
            <!-- عملیات -->
            <td class="text-center" onclick="event.stopPropagation()">
              <a href="<?= url('/influencer/advertise/create?influencer_id=' . (int)$p->id) ?>"
                 class="btn btn-primary btn-sm">
                ثبت سفارش
              </a>
            </td>
          </tr>
          <!-- ردیف جزئیات -->
          <tr id="detail-<?= (int)$p->id ?>" style="display:none;" class="bg-light">
            <td colspan="9">
              <div class="p-3">
                <div class="row g-3">
                  <div class="col-md-6">
                    <h6 class="fw-bold mb-2">آمار عملکرد</h6>
                    <?php if($s): ?>
                    <div class="d-flex flex-wrap gap-2 mb-2">
                      <?php for($i=1;$i<=5;$i++): ?>
                        <i class="material-icons text-<?= $i <= $s->stars ? 'warning' : 'secondary' ?>"
                           style="font-size:18px;">star</i>
                      <?php endfor; ?>
                      <span class="small text-muted ms-1"><?= e($s->grade_label) ?></span>
                    </div>
                    <div class="row g-1 small">
                      <div class="col-6">
                        <span class="text-muted">کل سفارش:</span>
                        <strong><?= number_format($s->total_orders) ?></strong>
                      </div>
                      <div class="col-6">
                        <span class="text-muted">تکمیل‌شده:</span>
                        <strong class="text-success"><?= number_format($s->completed_orders) ?></strong>
                      </div>
                      <div class="col-6">
                        <span class="text-muted">نرخ تکمیل:</span>
                        <strong><?= $s->completion_rate ?>%</strong>
                      </div>
                      <div class="col-6">
                        <span class="text-muted">نرخ اختلاف:</span>
                        <strong class="text-<?= $s->dispute_rate <= 10 ? 'success' : 'warning' ?>">
                          <?= $s->dispute_rate ?>%</strong>
                      </div>
                    </div>
                    <?php else: ?>
                      <p class="text-muted small">اطلاعات کافی موجود نیست.</p>
                    <?php endif; ?>
                  </div>
                  <div class="col-md-3">
                    <h6 class="fw-bold mb-2">تعرفه‌ها</h6>
                    <div class="small">
                      <?php if($p->story_price_24h > 0): ?>
                        <div class="d-flex justify-content-between border-bottom py-1">
                          <span class="text-muted">استوری ۲۴h</span>
                          <strong class="text-success"><?= number_format($p->story_price_24h) ?></strong>
                        </div>
                      <?php endif; ?>
                      <?php if($p->post_price_24h > 0): ?>
                        <div class="d-flex justify-content-between border-bottom py-1">
                          <span class="text-muted">پست ۲۴h</span>
                          <strong class="text-primary"><?= number_format($p->post_price_24h) ?></strong>
                        </div>
                      <?php endif; ?>
                      <?php if($p->post_price_48h > 0): ?>
                        <div class="d-flex justify-content-between border-bottom py-1">
                          <span class="text-muted">پست ۴۸h</span>
                          <strong class="text-primary"><?= number_format($p->post_price_48h) ?></strong>
                        </div>
                      <?php endif; ?>
                      <?php if($p->post_price_72h > 0): ?>
                        <div class="d-flex justify-content-between py-1">
                          <span class="text-muted">پست ۷۲h</span>
                          <strong class="text-primary"><?= number_format($p->post_price_72h) ?></strong>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="col-md-3">
                    <h6 class="fw-bold mb-2">درباره</h6>
                    <p class="small text-muted mb-2"><?= e($p->bio ?? '—') ?></p>
                    <a href="<?= e($p->page_url ?? '#') ?>" target="_blank"
                       class="btn btn-outline-secondary btn-sm w-100 mb-2">
                      <i class="material-icons" style="font-size:14px;vertical-align:middle;">open_in_new</i>
                      مشاهده پیج
                    </a>
                    <a href="<?= url('/influencer/advertise/create?influencer_id=' . (int)$p->id) ?>"
                       class="btn btn-primary btn-sm w-100">ثبت سفارش</a>
                  </div>
                </div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- صفحه‌بندی -->
<?php if(($pages ?? 1) > 1): ?>
<div class="d-flex justify-content-center mt-3">
  <nav><ul class="pagination pagination-sm">
    <?php if($page > 1): ?>
      <li class="page-item">
        <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $page - 1])) ?>">قبلی</a>
      </li>
    <?php endif; ?>
    <?php for($i = max(1,$page-2); $i <= min($pages,$page+2); $i++): ?>
      <li class="page-item <?= $i === $page ? 'active' : '' ?>">
        <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $i])) ?>"><?= $i ?></a>
      </li>
    <?php endfor; ?>
    <?php if($page < $pages): ?>
      <li class="page-item">
        <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $page + 1])) ?>">بعدی</a>
      </li>
    <?php endif; ?>
  </ul></nav>
</div>
<?php endif; ?>

<?php endif; ?>

<script>
function showDetail(id) {
  const row = document.getElementById('detail-' + id);
  if (!row) return;
  const isOpen = row.style.display !== 'none';
  // بستن همه
  document.querySelectorAll('[id^="detail-"]').forEach(r => r.style.display = 'none');
  document.querySelectorAll('.influencer-row').forEach(r => r.classList.remove('table-active'));
  if (!isOpen) {
    row.style.display = '';
    const mainRow = document.querySelector('[data-id="' + id + '"]');
    if (mainRow) mainRow.classList.add('table-active');
  }
}
</script>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>

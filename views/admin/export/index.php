<?php
// views/admin/export/index.php
$title = 'خروجی‌گیری داده';
include BASE_PATH . '/views/layouts/admin.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <div class="mb-4">
        <h4 class="mb-1">📥 مرکز خروجی‌گیری</h4>
        <p class="text-muted mb-0">خروجی CSV از هر بخش با فیلترهای دلخواه</p>
    </div>

    <div class="row g-4">

        <!-- کاربران -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><span class="material-icons align-middle" style="font-size:18px">people</span> خروجی کاربران</h6>
                </div>
                <div class="card-body">
                    <form action="<?= url('/admin/export/users') ?>" method="GET">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small">از تاریخ</label>
                                <input type="date" name="from" class="form-control form-control-sm">
                            </div>
                            <div class="col-6">
                                <label class="form-label small">تا تاریخ</label>
                                <input type="date" name="to" class="form-control form-control-sm">
                            </div>
                            <div class="col-6">
                                <label class="form-label small">وضعیت KYC</label>
                                <select name="kyc_status" class="form-select form-select-sm">
                                    <option value="">همه</option>
                                    <option value="verified">تأیید شده</option>
                                    <option value="pending">در انتظار</option>
                                    <option value="rejected">رد شده</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small">سطح</label>
                                <select name="tier_level" class="form-select form-select-sm">
                                    <option value="">همه</option>
                                    <option value="silver">Silver</option>
                                    <option value="gold">Gold</option>
                                    <option value="vip">VIP</option>
                                </select>
                            </div>
                        </div>
                        <button class="btn btn-primary btn-sm mt-3 w-100">
                            <span class="material-icons align-middle" style="font-size:16px">download</span>
                            دانلود CSV
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- تراکنش‌ها -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0"><span class="material-icons align-middle" style="font-size:18px">receipt_long</span> خروجی تراکنش‌ها</h6>
                </div>
                <div class="card-body">
                    <form action="<?= url('/admin/export/transactions') ?>" method="GET">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small">از تاریخ</label>
                                <input type="date" name="from" class="form-control form-control-sm">
                            </div>
                            <div class="col-6">
                                <label class="form-label small">تا تاریخ</label>
                                <input type="date" name="to" class="form-control form-control-sm">
                            </div>
                            <div class="col-6">
                                <label class="form-label small">نوع</label>
                                <select name="type" class="form-select form-select-sm">
                                    <option value="">همه</option>
                                    <option value="deposit">واریز</option>
                                    <option value="withdrawal">برداشت</option>
                                    <option value="reward">پاداش</option>
                                    <option value="commission">کمیسیون</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small">ارز</label>
                                <select name="currency" class="form-select form-select-sm">
                                    <option value="">همه</option>
                                    <option value="IRT">IRT</option>
                                    <option value="USDT">USDT</option>
                                </select>
                            </div>
                        </div>
                        <button class="btn btn-success btn-sm mt-3 w-100">
                            <span class="material-icons align-middle" style="font-size:16px">download</span>
                            دانلود CSV
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- برداشت‌ها -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-warning text-dark">
                    <h6 class="mb-0"><span class="material-icons align-middle" style="font-size:18px">payments</span> خروجی برداشت‌ها</h6>
                </div>
                <div class="card-body">
                    <form action="<?= url('/admin/export/withdrawals') ?>" method="GET">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small">از تاریخ</label>
                                <input type="date" name="from" class="form-control form-control-sm">
                            </div>
                            <div class="col-6">
                                <label class="form-label small">تا تاریخ</label>
                                <input type="date" name="to" class="form-control form-control-sm">
                            </div>
                            <div class="col-6">
                                <label class="form-label small">وضعیت</label>
                                <select name="status" class="form-select form-select-sm">
                                    <option value="">همه</option>
                                    <option value="pending">در انتظار</option>
                                    <option value="processing">در حال پردازش</option>
                                    <option value="completed">تکمیل</option>
                                    <option value="rejected">رد شده</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small">ارز</label>
                                <select name="currency" class="form-select form-select-sm">
                                    <option value="">همه</option>
                                    <option value="IRT">IRT</option>
                                    <option value="USDT">USDT</option>
                                </select>
                            </div>
                        </div>
                        <button class="btn btn-warning btn-sm mt-3 w-100 text-dark">
                            <span class="material-icons align-middle" style="font-size:16px">download</span>
                            دانلود CSV
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Audit Trail -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-danger text-white">
                    <h6 class="mb-0"><span class="material-icons align-middle" style="font-size:18px">security</span> خروجی Audit Trail</h6>
                </div>
                <div class="card-body">
                    <form action="<?= url('/admin/export/audit-trail') ?>" method="GET">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small">از تاریخ</label>
                                <input type="date" name="from" class="form-control form-control-sm">
                            </div>
                            <div class="col-6">
                                <label class="form-label small">تا تاریخ</label>
                                <input type="date" name="to" class="form-control form-control-sm">
                            </div>
                            <div class="col-12">
                                <label class="form-label small">نوع رویداد</label>
                                <input type="text" name="event" class="form-control form-control-sm"
                                       placeholder="مثلاً: user.kyc_approved">
                            </div>
                            <div class="col-12">
                                <label class="form-label small">User ID (اختیاری)</label>
                                <input type="number" name="user_id" class="form-control form-control-sm"
                                       placeholder="شناسه کاربر">
                            </div>
                        </div>
                        <button class="btn btn-danger btn-sm mt-3 w-100">
                            <span class="material-icons align-middle" style="font-size:16px">download</span>
                            دانلود CSV
                        </button>
                    </form>
                </div>
            </div>
        </div>

    </div>

    <!-- راهنما -->
    <div class="card border-0 bg-light mt-4">
        <div class="card-body">
            <h6 class="mb-2">📌 نکات مهم</h6>
            <ul class="mb-0 small text-muted">
                <li>فایل‌های CSV با BOM ذخیره می‌شوند و به درستی در Excel با فارسی نمایش داده می‌شوند.</li>
                <li>خروجی‌های بزرگ به صورت stream ارسال می‌شوند، لطفاً صبور باشید.</li>
                <li>هر خروجی‌گیری در Audit Trail ثبت می‌شود.</li>
            </ul>
        </div>
    </div>

</div>
</div>

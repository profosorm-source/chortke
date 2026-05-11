<?php
$pageTitle = $pageTitle ?? 'افزایش موجودی';
$siteCurrency = $siteCurrency ?? 'irt';
?>
<?php
$title = $title ?? 'افزایش موجودی';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-wallet-deposit.css') ?>">


    <!-- Header -->
    <div class="content-header">
        <h1>افزایش موجودی</h1>
        <a href="<?= url('/wallet') ?>" class="btn btn-outline">
            <i class="material-icons">arrow_forward</i>
            بازگشت
        </a>
    </div>

    <!-- راهنما -->
    <div class="alert alert-info">
        <i class="material-icons">info</i>
        <div>
            <strong>راهنما:</strong>
            یکی از روش‌های زیر را برای افزایش موجودی کیف پول خود انتخاب کنید.
        </div>
    </div>

    <!-- روش‌های افزایش موجودی -->
    <div class="deposit-methods">
        
        <?php if ($siteCurrency === 'irt' || $siteCurrency === 'both'): ?>
        <!-- درگاه‌های آنلاین -->
        <div class="method-card">
            <div class="method-icon online">
                <i class="material-icons">credit_card</i>
            </div>
            <div class="method-info">
                <h3>پرداخت آنلاین</h3>
                <p>واریز سریع و آنی از طریق درگاه‌های بانکی</p>
                <ul class="features">
                    <li><i class="material-icons">check_circle</i> تأیید خودکار</li>
                    <li><i class="material-icons">check_circle</i> واریز آنی</li>
                    <li><i class="material-icons">check_circle</i> امن و مطمئن</li>
                </ul>
                <div class="gateway-logos">
                    <img src="<?= asset('images/gateways/zarinpal.png') ?>" alt="زرین‌پال" title="زرین‌پال">
                    <img src="<?= asset('images/gateways/nextpay.png') ?>" alt="نکست‌پی" title="نکست‌پی">
                    <img src="<?= asset('images/gateways/idpay.png') ?>" alt="آیدی‌پی" title="آیدی‌پی">
                    <img src="<?= asset('images/gateways/dgpay.png') ?>" alt="دی‌جی‌پی" title="دی‌جی‌پی">
                </div>
            </div>
            <a href="#" class="method-btn" onclick="showOnlinePaymentModal(); return false;">
                انتخاب درگاه
                <i class="material-icons">arrow_back</i>
            </a>
        </div>

        <!-- واریز دستی -->
        <div class="method-card">
            <div class="method-icon manual">
                <i class="material-icons">account_balance</i>
            </div>
            <div class="method-info">
                <h3>واریز دستی</h3>
                <p>واریز از طریق کارت به کارت یا شبا</p>
                <ul class="features">
                    <li><i class="material-icons">check_circle</i> بدون کارمزد اضافی</li>
                    <li><i class="material-icons">schedule</i> بررسی در 2-24 ساعت</li>
                    <li><i class="material-icons">check_circle</i> واریز از کارت شخصی</li>
                </ul>
            </div>
            <a href="<?= url('/wallet/deposit/manual') ?>" class="method-btn">
                شروع واریز دستی
                <i class="material-icons">arrow_back</i>
            </a>
        </div>
        <?php endif; ?>

        <?php if ($siteCurrency === 'usdt' || $siteCurrency === 'both'): ?>
        <!-- واریز USDT -->
        <div class="method-card crypto">
            <div class="method-icon crypto">
                <i class="material-icons">currency_bitcoin</i>
            </div>
            <div class="method-info">
                <h3>واریز USDT</h3>
                <p>واریز تتر از طریق شبکه‌های BNB20 و TRC20</p>
                <ul class="features">
                    <li><i class="material-icons">check_circle</i> بررسی خودکار</li>
                    <li><i class="material-icons">check_circle</i> پشتیبانی 2 شبکه</li>
                    <li><i class="material-icons">schedule</i> تأیید در 5-30 دقیقه</li>
                </ul>
                <div class="network-badges">
                    <span class="badge badge-success">BNB20</span>
                    <span class="badge badge-info">TRC20</span>
                </div>
            </div>
            <a href="<?= url('/wallet/deposit/crypto') ?>" class="method-btn">
                واریز USDT
                <i class="material-icons">arrow_back</i>
            </a>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- مودال پرداخت آنلاین -->
<div class="modal" id="onlinePaymentModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>پرداخت آنلاین</h3>
            <button class="modal-close" onclick="closeOnlinePaymentModal()">
                <i class="material-icons">close</i>
            </button>
        </div>
        <form method="POST" action="<?= url('/payment/request') ?>" id="paymentForm">
            <?= csrf_field() ?>
            
            <div class="modal-body">
                <!-- انتخاب درگاه -->
                <div class="form-group">
                    <label>انتخاب درگاه پرداخت:</label>
                    <div class="gateway-select">
                        <label class="gateway-option">
                            <input type="radio" name="gateway" value="zarinpal" required>
                            <div class="gateway-card">
                                <img src="<?= asset('images/gateways/zarinpal.png') ?>" alt="زرین‌پال">
                                <span>زرین‌پال</span>
                            </div>
                        </label>
                        
                        <label class="gateway-option">
                            <input type="radio" name="gateway" value="nextpay" required>
                            <div class="gateway-card">
                                <img src="<?= asset('images/gateways/nextpay.png') ?>" alt="نکست‌پی">
                                <span>نکست‌پی</span>
                            </div>
                        </label>
                        
                        <label class="gateway-option">
                            <input type="radio" name="gateway" value="idpay" required>
                            <div class="gateway-card">
                                <img src="<?= asset('images/gateways/idpay.png') ?>" alt="آیدی‌پی">
                                <span>آیدی‌پی</span>
                            </div>
                        </label>
                        
                        <label class="gateway-option">
                            <input type="radio" name="gateway" value="dgpay" required>
                            <div class="gateway-card">
                                <img src="<?= asset('images/gateways/dgpay.png') ?>" alt="دی‌جی‌پی">
                                <span>دی‌جی‌پی</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- مبلغ -->
                <div class="form-group">
                    <label for="amount">مبلغ (تومان):</label>
                    <input type="number" 
                           id="amount" 
                           name="amount" 
                           class="form-control" 
                           placeholder="مثال: 100000"
                           min="10000"
                           step="1000"
                           required>
                    <small class="form-text">حداقل مبلغ: 10,000 تومان</small>
                </div>

                <!-- مبالغ پیشنهادی -->
                <div class="quick-amounts">
                    <button type="button" class="quick-amount-btn" onclick="setAmount(50000)">50,000</button>
                    <button type="button" class="quick-amount-btn" onclick="setAmount(100000)">100,000</button>
                    <button type="button" class="quick-amount-btn" onclick="setAmount(250000)">250,000</button>
                    <button type="button" class="quick-amount-btn" onclick="setAmount(500000)">500,000</button>
                    <button type="button" class="quick-amount-btn" onclick="setAmount(1000000)">1,000,000</button>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeOnlinePaymentModal()">انصراف</button>
                <button type="submit" class="btn btn-primary">
                    <i class="material-icons">payment</i>
                    انتقال به درگاه
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showOnlinePaymentModal() {
    document.getElementById('onlinePaymentModal').classList.add('show');
}

function closeOnlinePaymentModal() {
    document.getElementById('onlinePaymentModal').classList.remove('show');
}

function setAmount(amount) {
    document.getElementById('amount').value = amount;
}

// بستن مودال با کلیک خارج از آن
document.getElementById('onlinePaymentModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeOnlinePaymentModal();
    }
});
</script>
<?php
$content = ob_get_clean();
include view_path('layouts.user');
?>
<?php
$pageTitle = $pageTitle ?? 'واریز USDT';
$bnb20Address = $bnb20Address ?? '';
$trc20Address = $trc20Address ?? '';
$minDeposit = $minDeposit ?? 10;
$old = old();
$title = $title ?? 'واریز تتر';
$layout = 'user';
ob_start();
?>
<link rel="stylesheet" href="<?= asset('assets/css/views/user-crypto-deposit.css') ?>">


    <!-- Header -->
    <div class="content-header">
        <h1>واریز USDT</h1>
        <a href="<?= url('/wallet/deposit') ?>" class="btn btn-outline">
            <i class="material-icons">arrow_forward</i>
            بازگشت
        </a>
    </div>

    <!-- مراحل واریز -->
    <div class="steps-container">
        <div class="step active">
            <div class="step-number">1</div>
            <div class="step-title">انتخاب شبکه</div>
        </div>
        <div class="step-line"></div>
        <div class="step">
            <div class="step-number">2</div>
            <div class="step-title">ارسال USDT</div>
        </div>
        <div class="step-line"></div>
        <div class="step">
            <div class="step-number">3</div>
            <div class="step-title">ثبت اطلاعات</div>
        </div>
        <div class="step-line"></div>
        <div class="step">
            <div class="step-number">4</div>
            <div class="step-title">تأیید خودکار</div>
        </div>
    </div>

    <!-- انتخاب شبکه -->
    <div class="network-selection">
        <h3>
            <i class="material-icons">settings_ethernet</i>
            انتخاب شبکه انتقال
        </h3>
        
        <div class="network-grid">
            <?php if ($bnb20Address): ?>
            <div class="network-card" onclick="selectNetwork('bnb20')">
                <input type="radio" name="network_select" id="network_bnb20" value="bnb20">
                <label for="network_bnb20">
                    <div class="network-icon">
                        <i class="material-icons">link</i>
                    </div>
                    <h4>BNB Smart Chain (BEP20)</h4>
                    <div class="network-features">
                        <div class="feature">
                            <i class="material-icons">speed</i>
                            <span>سریع</span>
                        </div>
                        <div class="feature">
                            <i class="material-icons">attach_money</i>
                            <span>کارمزد کم</span>
                        </div>
                        <div class="feature">
                            <i class="material-icons">schedule</i>
                            <span>5-10 دقیقه</span>
                        </div>
                    </div>
                </label>
            </div>
            <?php endif; ?>

            <?php if ($trc20Address): ?>
            <div class="network-card" onclick="selectNetwork('trc20')">
                <input type="radio" name="network_select" id="network_trc20" value="trc20">
                <label for="network_trc20">
                    <div class="network-icon trc20">
                        <i class="material-icons">link</i>
                    </div>
                    <h4>TRON Network (TRC20)</h4>
                    <div class="network-features">
                        <div class="feature">
                            <i class="material-icons">speed</i>
                            <span>خیلی سریع</span>
                        </div>
                        <div class="feature">
                            <i class="material-icons">money_off</i>
                            <span>بدون کارمزد</span>
                        </div>
                        <div class="feature">
                            <i class="material-icons">schedule</i>
                            <span>2-5 دقیقه</span>
                        </div>
                    </div>
                </label>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- آدرس کیف پول (BNB20) -->
    <?php if ($bnb20Address): ?>
    <div class="wallet-address-card" id="bnb20_wallet" style="display: none;">
        <div class="network-badge bnb20">
            <i class="material-icons">link</i>
            BNB Smart Chain (BEP20)
        </div>
        
        <h3>آدرس کیف پول سایت</h3>
        
        <div class="address-display">
            <div class="address-text" dir="ltr"><?= e($bnb20Address) ?></div>
            <button class="copy-btn" onclick="copyToClipboard('<?= e($bnb20Address) ?>')">
                <i class="material-icons">content_copy</i>
                کپی
            </button>
        </div>

        <div class="qr-code">
            <div id="qr_bnb20"></div>
            <p>اسکن QR Code</p>
        </div>

        <div class="alert alert-danger">
            <i class="material-icons">warning</i>
            <div>
                <strong>هشدار مهم:</strong>
                <ul style="margin: 10px 0 0 20px; padding: 0;">
                    <li>حتماً شبکه BNB Smart Chain (BEP20) را انتخاب کنید</li>
                    <li>ارسال از شبکه‌های دیگر منجر به از دست رفتن دارایی می‌شود</li>
                    <li>فقط USDT ارسال کنید، سایر ارزها پشتیبانی نمی‌شوند</li>
                    <li>حداقل مبلغ واریز: <?= to_jalali($minDeposit, '', true) ?> USDT</li>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- آدرس کیف پول (TRC20) -->
    <?php if ($trc20Address): ?>
    <div class="wallet-address-card" id="trc20_wallet" style="display: none;">
        <div class="network-badge trc20">
            <i class="material-icons">link</i>
            TRON Network (TRC20)
        </div>
        
        <h3>آدرس کیف پول سایت</h3>
        
        <div class="address-display">
            <div class="address-text" dir="ltr"><?= e($trc20Address) ?></div>
            <button class="copy-btn" onclick="copyToClipboard('<?= e($trc20Address) ?>')">
                <i class="material-icons">content_copy</i>
                کپی
            </button>
        </div>

        <div class="qr-code">
            <div id="qr_trc20"></div>
            <p>اسکن QR Code</p>
        </div>

        <div class="alert alert-danger">
            <i class="material-icons">warning</i>
            <div>
                <strong>هشدار مهم:</strong>
                <ul style="margin: 10px 0 0 20px; padding: 0;">
                    <li>حتماً شبکه TRON (TRC20) را انتخاب کنید</li>
                    <li>ارسال از شبکه‌های دیگر منجر به از دست رفتن دارایی می‌شود</li>
                    <li>فقط USDT ارسال کنید، سایر ارزها پشتیبانی نمی‌شوند</li>
                    <li>حداقل مبلغ واریز: <?= to_jalali($minDeposit, '', true) ?> USDT</li>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- فرم ثبت واریز -->
    <div class="form-card" id="deposit_form" style="display: none;">
        <h3>ثبت اطلاعات واریز</h3>
        
        <div class="alert alert-info">
            <i class="material-icons">info</i>
            <div>
                پس از ارسال USDT، اطلاعات تراکنش خود را در فرم زیر وارد کنید.
                سیستم به صورت خودکار تراکنش شما را بررسی و تأیید می‌کند.
            </div>
        </div>

        <form method="POST" action="<?= url('/wallet/deposit/crypto') ?>" id="cryptoDepositForm">
            <?= csrf_field() ?>
            
            <input type="hidden" name="network" id="selected_network">

            <div class="form-row">
                <div class="form-group full-width">
                    <label for="tx_hash">هش تراکنش (Transaction Hash): <span class="required">*</span></label>
                    <input type="text" 
                           id="tx_hash" 
                           name="tx_hash" 
                           class="form-control ltr" 
                           placeholder="0x..."
                           value="<?= e($old['tx_hash'] ?? '') ?>"
                           required>
                    <small class="form-text">هش تراکنش را از کیف پول خود کپی کنید</small>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="amount">مبلغ ارسالی (USDT): <span class="required">*</span></label>
                    <input type="number" 
                           id="amount" 
                           name="amount" 
                           class="form-control" 
                           placeholder="مثال: 50"
                           min="<?= e($minDeposit) ?>"
                           step="0.01"
                           value="<?= e($old['amount'] ?? '') ?>"
                           required>
                    <small class="form-text">حداقل: <?= to_jalali($minDeposit, '', true) ?> USDT</small>
                </div>

                <div class="form-group">
                    <label for="deposit_date">تاریخ ارسال: <span class="required">*</span></label>
                    <input type="date" 
                           id="deposit_date" 
                           name="deposit_date" 
                           class="form-control" 
                           max="<?= date('Y-m-d') ?>"
                           value="<?= e($old['deposit_date'] ?? date('Y-m-d')) ?>"
                           required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="deposit_time">ساعت ارسال: <span class="required">*</span></label>
                    <input type="time" 
                           id="deposit_time" 
                           name="deposit_time" 
                           class="form-control" 
                           value="<?= e($old['deposit_time'] ?? date('H:i')) ?>"
                           required>
                </div>
            </div>

            <div class="verification-info">
                <div class="info-icon">
                    <i class="material-icons">verified</i>
                </div>
                <div class="info-content">
                    <h4>بررسی خودکار تراکنش</h4>
                    <p>
                        پس از ثبت درخواست، سیستم به صورت خودکار تراکنش شما را از طریق Blockchain بررسی می‌کند.
                        در صورت تأیید، موجودی شما ظرف 5 تا 30 دقیقه افزایش می‌یابد.
                    </p>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="material-icons">check</i>
                    ثبت درخواست واریز
                </button>
                <button type="button" class="btn btn-outline btn-lg" onclick="resetForm()">
                    انصراف
                </button>
            </div>
        </form>
    </div>

    <!-- راهنما -->
    <div class="help-card">
        <h4>
            <i class="material-icons">help</i>
            راهنمای واریز USDT
        </h4>
        <ol>
            <li>شبکه مورد نظر خود (BNB20 یا TRC20) را انتخاب کنید</li>
            <li>آدرس کیف پول سایت را کپی کنید یا از QR Code استفاده کنید</li>
            <li>از کیف پول خود (Trust Wallet, MetaMask, Binance و...) USDT ارسال کنید</li>
            <li><strong>حتماً شبکه صحیح را انتخاب کنید</strong> (خطا در انتخاب شبکه منجر به از دست رفتن دارایی می‌شود)</li>
            <li>پس از ارسال، هش تراکنش و مبلغ را در فرم وارد کنید</li>
            <li>سیستم به صورت خودکار تراکنش شما را بررسی و تأیید می‌کند</li>
            <li>پس از تأیید، موجودی به کیف پول شما افزوده می‌شود</li>
        </ol>

        <div class="alert alert-warning" style="margin-top: 20px;">
            <i class="material-icons">info</i>
            <div>
                <strong>نکته:</strong>
                اگر تراکنش شما به صورت خودکار تأیید نشد، نگران نباشید.
                تیم پشتیبانی به صورت دستی آن را بررسی و تأیید خواهد کرد.
            </div>
        </div>
    </div>
</div>

<!-- QR Code Library -->
<script src="<?= asset('assets/vendor/qrcodejs/qrcode.min.js') ?>"></script>

<script>
let selectedNetwork = null;

function selectNetwork(network) {
    selectedNetwork = network;
    document.getElementById('selected_network').value = network;
    
    // مخفی کردن همه
    document.getElementById('bnb20_wallet')?.style.setProperty('display', 'none');
    document.getElementById('trc20_wallet')?.style.setProperty('display', 'none');
    document.getElementById('deposit_form').style.display = 'none';
    
    // نمایش شبکه انتخابی
    const walletCard = document.getElementById(network + '_wallet');
    if (walletCard) {
        walletCard.style.display = 'block';
        
        // تولید QR Code
        const qrDiv = document.getElementById('qr_' + network);
        qrDiv.innerHTML = '';
        
        const address = network === 'bnb20' ? '<?= e($bnb20Address) ?>' : '<?= e($trc20Address) ?>';
        
        new QRCode(qrDiv, {
            text: address,
            width: 200,
            height: 200,
            colorDark: '#000000',
            colorLight: '#ffffff',
        });
        
        // نمایش فرم
        document.getElementById('deposit_form').style.display = 'block';
    }
    
    // اسکرول به فرم
    setTimeout(() => {
        document.getElementById('deposit_form').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 300);
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        notyf.success('آدرس کپی شد!');
    }).catch(err => {
        notyf.error('خطا در کپی کردن');
    });
}

function resetForm() {
    selectedNetwork = null;
    document.getElementById('bnb20_wallet')?.style.setProperty('display', 'none');
    document.getElementById('trc20_wallet')?.style.setProperty('display', 'none');
    document.getElementById('deposit_form').style.display = 'none';
    document.querySelectorAll('input[name="network_select"]').forEach(input => {
        input.checked = false;
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// اعتبارسنجی فرم
document.getElementById('cryptoDepositForm').addEventListener('submit', function(e) {
    if (!selectedNetwork) {
        e.preventDefault();
        notyf.error('لطفاً شبکه را انتخاب کنید');
        return false;
    }
    
    const txHash = document.getElementById('tx_hash').value;
    if (txHash.length < 64) {
        e.preventDefault();
        notyf.error('هش تراکنش نامعتبر است');
        return false;
    }
});
</script>

<?php $content = ob_get_clean(); include view_path('layouts.user');?>
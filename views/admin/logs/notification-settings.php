<?php
$title = 'تنظیمات نوتیفیکیشن';
$layout = 'admin';
ob_start();
?>

<div class="container-fluid">
    <h2 class="mb-4">⚙️ تنظیمات نوتیفیکیشن و هشدارها</h2>

    <div class="row g-4">
        <!-- کانال‌های نوتیفیکیشن -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">📢 کانال‌های ارسال</h5>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addChannelModal">
                        <i class="material-icons align-middle" style="font-size: 18px;">add</i>
                        افزودن کانال
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($channels)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="material-icons" style="font-size: 60px;">notifications_off</i>
                            <p class="mb-0">هیچ کانالی تعریف نشده</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($channels as $channel): ?>
                                <?php $config = json_decode($channel->config, true); ?>
                                <?php $alertLevels = json_decode($channel->alert_levels, true) ?? []; ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center mb-2">
                                                <span class="me-2">
                                                    <?php
                                                    echo match($channel->channel_type) {
                                                        'telegram' => '📱',
                                                        'email' => '📧',
                                                        'sms' => '💬',
                                                        'webhook' => '🔗',
                                                        default => '📢'
                                                    };
                                                    ?>
                                                </span>
                                                <h6 class="mb-0"><?= e($channel->channel_name) ?></h6>
                                                <span class="badge bg-<?= $channel->is_active ? 'success' : 'secondary' ?> ms-2">
                                                    <?= $channel->is_active ? 'فعال' : 'غیرفعال' ?>
                                                </span>
                                            </div>
                                            
                                            <div class="small text-muted mb-2">
                                                <?php if ($channel->channel_type === 'telegram'): ?>
                                                    Chat ID: <code><?= e($config['chat_id'] ?? '-') ?></code>
                                                <?php elseif ($channel->channel_type === 'email'): ?>
                                                    <?= e($config['email'] ?? '-') ?>
                                                <?php endif; ?>
                                            </div>

                                            <div class="small">
                                                <strong>سطوح هشدار:</strong>
                                                <?php foreach ($alertLevels as $level): ?>
                                                    <span class="badge bg-<?= e(
    $level === 'critical' ? 'danger' :
    ($level === 'high' ? 'warning' : 'info')
) ?>"><?= e($level) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>

                                        <div>
                                            <button class="btn btn-sm btn-outline-primary me-1" 
                                                    onclick="testChannel(<?= $channel->id ?>)">
                                                <i class="material-icons align-middle" style="font-size: 16px;">send</i>
                                                تست
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger">
                                                <i class="material-icons align-middle" style="font-size: 16px;">delete</i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- قوانین هشدار -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">⚡ قوانین هشدار خودکار</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($rules)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="material-icons" style="font-size: 60px;">rule</i>
                            <p class="mb-0">هیچ قانونی تعریف نشده</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($rules as $rule): ?>
                                <?php $condition = json_decode($rule->condition, true); ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center mb-2">
                                                <h6 class="mb-0"><?= e($rule->rule_name) ?></h6>
                                                <span class="badge bg-<?= $rule->is_active ? 'success' : 'secondary' ?> ms-2">
                                                    <?= $rule->is_active ? 'فعال' : 'غیرفعال' ?>
                                                </span>
                                            </div>
                                            
                                            <div class="small text-muted mb-1">
                                                <strong>شرط:</strong> 
                                                <?= e($condition['metric'] ?? '') ?> 
                                                <?= e($condition['operator'] ?? '') ?> 
                                                <?= number_format($rule->threshold) ?>
                                                در <?= $rule->time_window ?> دقیقه
                                            </div>

                                            <div class="small">
                                                <span class="badge bg-<?= e(
                                                    $rule->severity === 'critical' ? 'danger' : 
                                                    ($rule->severity === 'high' ? 'warning' : 'info') 
                                                ) ?>">
                                                    <?= $rule->severity ?>
                                                </span>

                                                <?php if ($rule->last_triggered_at): ?>
                                                    <span class="text-muted ms-2">
                                                        آخرین فعال‌سازی: 
                                                        <?= date('Y-m-d H:i', strtotime($rule->last_triggered_at)) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" 
                                                       <?= $rule->is_active ? 'checked' : '' ?>
                                                       onchange="toggleRule(<?= $rule->id ?>, this.checked)">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- راهنمای سریع -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm bg-light">
                <div class="card-body">
                    <h5 class="mb-3">📚 راهنمای تنظیمات</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <h6>🤖 نحوه راه‌اندازی ربات تلگرام:</h6>
                            <ol class="small">
                                <li>به @BotFather در تلگرام پیام دهید</li>
                                <li>دستور /newbot را ارسال کنید</li>
                                <li>نام و username ربات را تعیین کنید</li>
                                <li>Token دریافتی را کپی کنید</li>
                                <li>با ربات خود چت کنید و /start بزنید</li>
                                <li>برای دریافت Chat ID به @userinfobot پیام دهید</li>
                            </ol>
                        </div>
                        <div class="col-md-6">
                            <h6>⚡ سطوح هشدار:</h6>
                            <ul class="small">
                                <li><span class="badge bg-info">low</span> - اطلاعات عمومی</li>
                                <li><span class="badge bg-primary">medium</span> - توجه نیاز دارد</li>
                                <li><span class="badge bg-warning">high</span> - فوری</li>
                                <li><span class="badge bg-danger">critical</span> - بحرانی!</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal افزودن کانال -->
<div class="modal fade" id="addChannelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="/admin/logs/save-channel">
                <div class="modal-header">
                    <h5 class="modal-title">افزودن کانال جدید</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">نوع کانال</label>
                        <select class="form-select" name="channel_type" id="channelType" required onchange="updateChannelForm()">
                            <option value="">انتخاب کنید</option>
                            <option value="telegram">تلگرام</option>
                            <option value="email">ایمیل</option>
                            <option value="webhook">Webhook</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">نام کانال</label>
                        <input type="text" class="form-control" name="channel_name" required 
                               placeholder="مثلا: کانال اصلی تلگرام">
                    </div>

                    <!-- فرم تلگرام -->
                    <div id="telegramFields" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">Bot Token</label>
                            <input type="text" class="form-control" name="bot_token" 
                                   placeholder="1234567890:ABCdefGHIjklMNOpqrsTUVwxyz">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Chat ID</label>
                            <input type="text" class="form-control" name="chat_id" 
                                   placeholder="123456789">
                        </div>
                    </div>

                    <!-- فرم ایمیل -->
                    <div id="emailFields" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">آدرس ایمیل</label>
                            <input type="email" class="form-control" name="email" 
                                   placeholder="admin@example.com">
                        </div>
                    </div>

                    <!-- فرم Webhook -->
                    <div id="webhookFields" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">URL</label>
                            <input type="url" class="form-control" name="webhook_url" 
                                   placeholder="https://example.com/webhook">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">سطوح هشدار</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="alert_levels[]" value="low" id="levelLow">
                            <label class="form-check-label" for="levelLow">Low</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="alert_levels[]" value="medium" id="levelMedium">
                            <label class="form-check-label" for="levelMedium">Medium</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="alert_levels[]" value="high" id="levelHigh" checked>
                            <label class="form-check-label" for="levelHigh">High</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="alert_levels[]" value="critical" id="levelCritical" checked>
                            <label class="form-check-label" for="levelCritical">Critical</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">لغو</button>
                    <button type="submit" class="btn btn-primary">ذخیره</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function updateChannelForm() {
    const type = document.getElementById('channelType').value;
    document.getElementById('telegramFields').style.display = type === 'telegram' ? 'block' : 'none';
    document.getElementById('emailFields').style.display = type === 'email' ? 'block' : 'none';
    document.getElementById('webhookFields').style.display = type === 'webhook' ? 'block' : 'none';
}

function testChannel(channelId) {
    if (!confirm('آیا می‌خواهید یک پیام تست ارسال شود؟')) return;
    
    fetch('/admin/logs/test-channel', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'channel_id=' + channelId
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
    })
    .catch(err => {
        alert('خطا در ارسال: ' + err.message);
    });
}

function toggleRule(ruleId, isActive) {
    fetch('/admin/logs/toggle-rule', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'rule_id=' + ruleId + '&is_active=' + (isActive ? 1 : 0)
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            alert('خطا در تغییر وضعیت');
        }
    });
}
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.' . $layout);
?>

<!-- 
    Sample View: user/referral/analytics.php
    صفحه تحلیل و آمار پیشرفته
-->

<?php $pageTitle = 'تحلیل رفرال'; ?>

<div class="analytics-page">
    <div class="page-header">
        <h1>📊 تحلیل و آمار پیشرفته</h1>
        <p>بررسی دقیق عملکرد برنامه معرفی شما</p>
    </div>

    <!-- Conversion Funnel -->
    <section class="conversion-section">
        <h2>قیف تبدیل (30 روز اخیر)</h2>
        <div class="conversion-funnel">
            <div class="funnel-step">
                <div class="funnel-number"><?= $dashboard['conversion']['clicks'] ?? 0 ?></div>
                <div class="funnel-label">کلیک روی لینک</div>
                <div class="funnel-bar" style="width: 100%"></div>
            </div>
            
            <div class="funnel-arrow">↓</div>
            
            <div class="funnel-step">
                <div class="funnel-number"><?= $dashboard['conversion']['signups'] ?? 0 ?></div>
                <div class="funnel-label">ثبت‌نام</div>
                <div class="funnel-bar" style="width: <?= $dashboard['conversion']['click_to_signup_rate'] ?? 0 ?>%"></div>
                <div class="funnel-rate"><?= $dashboard['conversion']['click_to_signup_rate'] ?? 0 ?>%</div>
            </div>
            
            <div class="funnel-arrow">↓</div>
            
            <div class="funnel-step">
                <div class="funnel-number"><?= $dashboard['conversion']['active_users'] ?? 0 ?></div>
                <div class="funnel-label">کاربران فعال</div>
                <div class="funnel-bar" style="width: <?= $dashboard['conversion']['signup_to_active_rate'] ?? 0 ?>%"></div>
                <div class="funnel-rate"><?= $dashboard['conversion']['signup_to_active_rate'] ?? 0 ?>%</div>
            </div>
        </div>
        
        <div class="conversion-summary">
            <strong>نرخ تبدیل کلی: <?= $dashboard['conversion']['overall_conversion_rate'] ?? 0 ?>%</strong>
            <p>از هر 100 کلیک، <?= round($dashboard['conversion']['overall_conversion_rate'] ?? 0) ?> کاربر فعال می‌شوند</p>
        </div>
    </section>

    <!-- LTV (Lifetime Value) -->
    <section class="ltv-section">
        <h2>ارزش طول عمر (LTV)</h2>
        <div class="ltv-stats">
            <div class="ltv-card">
                <h3>میانگین LTV</h3>
                <div class="ltv-amount">
                    <span class="amount-irt"><?= number_format($dashboard['ltv']['ltv_irt'] ?? 0) ?> تومان</span>
                    <span class="amount-usdt"><?= number_format($dashboard['ltv']['ltv_usdt'] ?? 0, 2) ?> USDT</span>
                </div>
                <p class="ltv-description">هر رفرال به طور میانگین این مقدار برای شما درآمد می‌آورد</p>
            </div>
            
            <div class="ltv-breakdown">
                <h4>جزئیات:</h4>
                <ul>
                    <li>تعداد کل رفرال‌ها: <strong><?= $dashboard['ltv']['total_referrals'] ?? 0 ?></strong></li>
                    <li>کل درآمد (تومان): <strong><?= number_format($dashboard['ltv']['total_earned_irt'] ?? 0) ?></strong></li>
                    <li>کل درآمد (USDT): <strong><?= number_format($dashboard['ltv']['total_earned_usdt'] ?? 0, 2) ?></strong></li>
                </ul>
            </div>
        </div>
    </section>

    <!-- Retention Rate -->
    <section class="retention-section">
        <h2>نرخ ماندگاری</h2>
        <div class="retention-chart">
            <div class="retention-gauge">
                <svg viewBox="0 0 200 120" width="200" height="120">
                    <?php 
                    $retention = $dashboard['retention']['retention_rate'] ?? 0;
                    $angle = ($retention / 100) * 180;
                    $x = 100 + 80 * cos(deg2rad(180 - $angle));
                    $y = 100 - 80 * sin(deg2rad(180 - $angle));
                    ?>
                    <path d="M 20 100 A 80 80 0 0 1 180 100" fill="none" stroke="#e0e0e0" stroke-width="20"/>
                    <path d="M 20 100 A 80 80 0 0 1 <?= $x ?> <?= $y ?>" fill="none" stroke="#28a745" stroke-width="20"/>
                    <text x="100" y="90" text-anchor="middle" font-size="36" font-weight="bold"><?= round($retention) ?>%</text>
                    <text x="100" y="110" text-anchor="middle" font-size="12" fill="#666">نرخ ماندگاری</text>
                </svg>
            </div>
            
            <div class="retention-info">
                <p><strong><?= $dashboard['retention']['still_active'] ?? 0 ?></strong> نفر از 
                   <strong><?= $dashboard['retention']['total_referrals'] ?? 0 ?></strong> رفرال 
                   در 30 روز اخیر فعال بوده‌اند</p>
                
                <?php 
                $retention = $dashboard['retention']['retention_rate'] ?? 0;
                if ($retention >= 70):
                ?>
                    <div class="retention-badge excellent">عالی 🎉</div>
                <?php elseif ($retention >= 50): ?>
                    <div class="retention-badge good">خوب 👍</div>
                <?php elseif ($retention >= 30): ?>
                    <div class="retention-badge average">متوسط</div>
                <?php else: ?>
                    <div class="retention-badge poor">نیاز به بهبود</div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Source Breakdown -->
    <section class="sources-section">
        <h2>تفکیک منابع درآمد</h2>
        <div class="sources-chart">
            <?php 
            $total = 0;
            foreach ($dashboard['source_breakdown'] as $source) {
                $total += $source->total_irt;
            }
            ?>
            
            <div class="pie-chart">
                <!-- این بخش رو با یه کتابخونه Chart.js یا مشابه پیاده کنید -->
                <?php foreach ($dashboard['source_breakdown'] as $index => $source): ?>
                    <?php 
                    $percent = $total > 0 ? ($source->total_irt / $total) * 100 : 0;
                    $colors = ['#007bff', '#28a745', '#ffc107', '#dc3545'];
                    ?>
                    <div class="source-item" style="flex: <?= $percent ?>">
                        <div class="source-bar" style="background: <?= $colors[$index % 4] ?>; height: <?= $percent ?>%"></div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="sources-list">
                <?php foreach ($dashboard['source_breakdown'] as $source): ?>
                    <div class="source-detail">
                        <span class="source-name"><?= $source->source_type ?></span>
                        <span class="source-amount"><?= number_format($source->total_irt) ?> تومان</span>
                        <span class="source-count">(<?= $source->count ?> کمیسیون)</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Monthly Performance -->
    <section class="monthly-section">
        <h2>عملکرد ماهانه (6 ماه اخیر)</h2>
        <div class="monthly-chart">
            <table class="performance-table">
                <thead>
                    <tr>
                        <th>ماه</th>
                        <th>کمیسیون‌های پرداخت شده</th>
                        <th>در انتظار</th>
                        <th>درآمد (تومان)</th>
                        <th>درآمد (USDT)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dashboard['monthly_performance'] as $month): ?>
                        <tr>
                            <td><?= $month->month ?></td>
                            <td><?= $month->paid_commissions ?></td>
                            <td><?= $month->pending_commissions ?></td>
                            <td><?= number_format($month->earned_irt) ?></td>
                            <td><?= number_format($month->earned_usdt, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Prediction -->
    <section class="prediction-section">
        <h2>پیش‌بینی ماه آینده</h2>
        <div class="prediction-box">
            <div class="prediction-amount">
                <span class="predicted-label">درآمد پیش‌بینی شده:</span>
                <span class="predicted-value">
                    <?= number_format($dashboard['prediction']['predicted_irt'] ?? 0) ?> تومان
                </span>
            </div>
            
            <div class="prediction-trend">
                روند: 
                <?php 
                $trend = $dashboard['prediction']['trend'] ?? 'stable';
                $trendLabels = [
                    'increasing' => '📈 صعودی',
                    'stable' => '➡️ ثابت',
                    'decreasing' => '📉 نزولی'
                ];
                ?>
                <strong><?= $trendLabels[$trend] ?? $trend ?></strong>
            </div>
            
            <div class="prediction-confidence">
                <small>اطمینان: <?= $dashboard['prediction']['confidence'] ?? 'low' ?></small>
                <small>بر اساس <?= $dashboard['prediction']['based_on_months'] ?? 0 ?> ماه گذشته</small>
            </div>
        </div>
    </section>

    <!-- Comparison with Average -->
    <section class="comparison-section">
        <h2>مقایسه با میانگین سیستم</h2>
        <div class="comparison-cards">
            <div class="comparison-card">
                <h3>عملکرد شما</h3>
                <div class="comparison-stats">
                    <div>LTV: <?= number_format($comparison['user']['ltv_irt'] ?? 0) ?> تومان</div>
                    <div>نرخ تبدیل: <?= $comparison['user']['conversion_rate'] ?? 0 ?>%</div>
                </div>
            </div>
            
            <div class="comparison-vs">VS</div>
            
            <div class="comparison-card">
                <h3>میانگین کل سیستم</h3>
                <div class="comparison-stats">
                    <div>LTV: <?= number_format($comparison['system_average']['avg_earnings'] ?? 0) ?> تومان</div>
                    <div>رفرال: <?= round($comparison['system_average']['avg_referrals'] ?? 0) ?> نفر</div>
                </div>
            </div>
        </div>
        
        <div class="comparison-result">
            <?php 
            $perfEarnings = $comparison['performance_vs_average']['earnings'] ?? 0;
            ?>
            <?php if ($perfEarnings > 0): ?>
                <p class="better">🎉 عملکرد شما <?= abs(round($perfEarnings)) ?>% بهتر از میانگین است!</p>
            <?php elseif ($perfEarnings < 0): ?>
                <p class="worse">⚠️ عملکرد شما <?= abs(round($perfEarnings)) ?>% کمتر از میانگین است</p>
            <?php else: ?>
                <p class="equal">عملکرد شما برابر با میانگین سیستم است</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- Top Referrals -->
    <section class="top-referrals-section">
        <h2>برترین رفرال‌های شما</h2>
        <table class="top-referrals-table">
            <thead>
                <tr>
                    <th>رتبه</th>
                    <th>نام</th>
                    <th>تاریخ عضویت</th>
                    <th>تسک‌های انجام شده</th>
                    <th>کمیسیون کل</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dashboard['top_referrals'] as $index => $referral): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= $referral->full_name ?? 'کاربر' ?></td>
                        <td><?= to_jalali($referral->joined_at) ?></td>
                        <td><?= $referral->completed_tasks_count ?></td>
                        <td><?= number_format($referral->total_commission_irt) ?> تومان</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>

<!-- Sample CSS -->
<style>
.analytics-page {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

section {
    margin: 40px 0;
    padding: 30px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

h2 {
    margin-top: 0;
    padding-bottom: 15px;
    border-bottom: 2px solid #007bff;
}

.conversion-funnel {
    display: flex;
    align-items: center;
    justify-content: space-around;
    margin: 30px 0;
}

.funnel-step {
    flex: 1;
    text-align: center;
}

.funnel-number {
    font-size: 2em;
    font-weight: bold;
    color: #007bff;
}

.funnel-label {
    margin: 10px 0;
    color: #666;
}

.funnel-bar {
    height: 30px;
    background: linear-gradient(90deg, #007bff, #0056b3);
    border-radius: 15px;
    margin: 10px auto;
    max-width: 300px;
}

.funnel-rate {
    color: #28a745;
    font-weight: bold;
}

.funnel-arrow {
    font-size: 2em;
    color: #999;
    margin: 0 10px;
}

.ltv-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
}

.ltv-amount {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 20px 0;
    font-size: 1.5em;
    font-weight: bold;
}

.retention-chart {
    display: flex;
    align-items: center;
    gap: 40px;
}

.retention-gauge {
    flex-shrink: 0;
}

.retention-badge {
    display: inline-block;
    padding: 8px 16px;
    border-radius: 20px;
    font-weight: bold;
    margin-top: 10px;
}

.retention-badge.excellent { background: #d4edda; color: #155724; }
.retention-badge.good { background: #d1ecf1; color: #0c5460; }
.retention-badge.average { background: #fff3cd; color: #856404; }
.retention-badge.poor { background: #f8d7da; color: #721c24; }

.performance-table {
    width: 100%;
    border-collapse: collapse;
}

.performance-table th,
.performance-table td {
    padding: 12px;
    text-align: right;
    border-bottom: 1px solid #e0e0e0;
}

.performance-table th {
    background: #f8f9fa;
    font-weight: bold;
}

.prediction-box {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 8px;
    text-align: center;
}

.predicted-value {
    display: block;
    font-size: 2.5em;
    font-weight: bold;
    margin: 20px 0;
}

.comparison-cards {
    display: flex;
    gap: 20px;
    align-items: center;
    justify-content: space-around;
    margin: 30px 0;
}

.comparison-card {
    flex: 1;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 8px;
    text-align: center;
}

.comparison-vs {
    font-size: 1.5em;
    font-weight: bold;
    color: #999;
}

.comparison-result {
    text-align: center;
    margin-top: 20px;
}

.comparison-result p {
    font-size: 1.2em;
    padding: 15px;
    border-radius: 8px;
}

.comparison-result .better {
    background: #d4edda;
    color: #155724;
}

.comparison-result .worse {
    background: #fff3cd;
    color: #856404;
}

.top-referrals-table {
    width: 100%;
    border-collapse: collapse;
}

.top-referrals-table th,
.top-referrals-table td {
    padding: 12px;
    text-align: right;
    border-bottom: 1px solid #e0e0e0;
}

.top-referrals-table tbody tr:hover {
    background: #f8f9fa;
}
</style>
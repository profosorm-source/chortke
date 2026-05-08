<!-- 
    Sample View: user/referral/milestones.php
    نمایش دستاوردها و جوایز
-->

<?php $pageTitle = 'دستاوردهای من'; ?>

<div class="milestones-page">
    <div class="page-header">
        <h1>🏆 دستاوردها (Milestones)</h1>
        <p>با رسیدن به هر دستاورد، جوایز ویژه دریافت کنید!</p>
    </div>

    <!-- Achieved Milestones -->
    <section class="achieved-section">
        <h2>دستاوردهای کسب شده (<?= count($achieved) ?>)</h2>
        
        <?php if (empty($achieved)): ?>
            <div class="empty-state">
                <p>هنوز دستاوردی کسب نکرده‌اید. شروع کنید!</p>
            </div>
        <?php else: ?>
            <div class="milestones-list">
                <?php foreach ($achieved as $milestone): ?>
                    <div class="milestone-card achieved">
                        <div class="milestone-icon">
                            <?php if ($milestone->badge_icon): ?>
                                <img src="<?= $milestone->badge_icon ?>" alt="<?= $milestone->title_fa ?>">
                            <?php else: ?>
                                <span class="default-icon">🏅</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="milestone-content">
                            <h3><?= $milestone->title_fa ?></h3>
                            <?php if ($milestone->description): ?>
                                <p class="description"><?= $milestone->description ?></p>
                            <?php endif; ?>
                            
                            <div class="milestone-reward">
                                <span class="reward-label">جایزه دریافتی:</span>
                                <span class="reward-value">
                                    <?php if ($milestone->reward_type === 'cash'): ?>
                                        <?= number_format($milestone->reward_value) ?>
                                        <?= $milestone->reward_currency === 'usdt' ? 'USDT' : 'تومان' ?>
                                    <?php elseif ($milestone->reward_type === 'bonus_percent'): ?>
                                        +<?= $milestone->reward_value ?>% افزایش کمیسیون
                                    <?php else: ?>
                                        <?= $milestone->reward_type ?>
                                    <?php endif; ?>
                                </span>
                                
                                <?php if ($milestone->reward_paid): ?>
                                    <span class="reward-status paid">✓ پرداخت شده</span>
                                <?php else: ?>
                                    <span class="reward-status pending">در انتظار پرداخت</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="milestone-date">
                                <small>تاریخ دستیابی: <?= to_jalali($milestone->achieved_at) ?></small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Available Milestones -->
    <section class="available-section">
        <h2>دستاوردهای در دسترس</h2>
        
        <?php if (empty($available)): ?>
            <div class="empty-state">
                <p>🎉 شما همه دستاوردها را کسب کرده‌اید!</p>
            </div>
        <?php else: ?>
            <!-- Group by type -->
            <?php 
            $grouped = [];
            foreach ($available as $milestone) {
                $type = $milestone->milestone_type;
                if (!isset($grouped[$type])) {
                    $grouped[$type] = [];
                }
                $grouped[$type][] = $milestone;
            }
            
            $typeLabels = [
                'referral_count' => 'بر اساس تعداد رفرال',
                'total_earned' => 'بر اساس کل درآمد',
                'active_referrals' => 'بر اساس رفرال‌های فعال'
            ];
            ?>
            
            <?php foreach ($grouped as $type => $milestones): ?>
                <div class="milestone-group">
                    <h3 class="group-title"><?= $typeLabels[$type] ?? $type ?></h3>
                    
                    <div class="milestones-list">
                        <?php foreach ($milestones as $milestone): ?>
                            <div class="milestone-card available">
                                <div class="milestone-icon locked">
                                    <?php if ($milestone->badge_icon): ?>
                                        <img src="<?= $milestone->badge_icon ?>" alt="<?= $milestone->title_fa ?>">
                                    <?php else: ?>
                                        <span class="default-icon">🔒</span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="milestone-content">
                                    <h3><?= $milestone->title_fa ?></h3>
                                    <?php if ($milestone->description): ?>
                                        <p class="description"><?= $milestone->description ?></p>
                                    <?php endif; ?>
                                    
                                    <div class="milestone-requirement">
                                        <span class="req-label">هدف:</span>
                                        <span class="req-value">
                                            <?php if ($type === 'referral_count'): ?>
                                                <?= (int)$milestone->threshold_value ?> رفرال
                                            <?php elseif ($type === 'total_earned'): ?>
                                                <?= number_format($milestone->threshold_value) ?> تومان درآمد
                                            <?php elseif ($type === 'active_referrals'): ?>
                                                <?= (int)$milestone->threshold_value ?> رفرال فعال
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    
                                    <div class="milestone-progress">
                                        <div class="progress-bar">
                                            <div class="progress-fill" 
                                                 style="width: <?= min(100, $milestone->progress_percent) ?>%">
                                            </div>
                                        </div>
                                        <div class="progress-text">
                                            <span>پیشرفت: <?= round($milestone->progress_percent, 1) ?>%</span>
                                            <span>فعلی: 
                                                <?php if ($type === 'total_earned'): ?>
                                                    <?= number_format($milestone->current_value) ?> تومان
                                                <?php else: ?>
                                                    <?= (int)$milestone->current_value ?>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="milestone-reward">
                                        <span class="reward-label">🎁 جایزه:</span>
                                        <span class="reward-value highlight">
                                            <?php if ($milestone->reward_type === 'cash'): ?>
                                                <?= number_format($milestone->reward_value) ?>
                                                <?= $milestone->reward_currency === 'usdt' ? 'USDT' : 'تومان' ?>
                                            <?php elseif ($milestone->reward_type === 'bonus_percent'): ?>
                                                +<?= $milestone->reward_value ?>% افزایش کمیسیون
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</div>

<!-- Sample CSS -->
<style>
.milestones-page {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    text-align: center;
    margin-bottom: 40px;
}

.milestones-list {
    display: grid;
    gap: 20px;
}

.milestone-card {
    display: flex;
    gap: 20px;
    padding: 20px;
    border-radius: 8px;
    border: 2px solid #e0e0e0;
    background: white;
    transition: all 0.3s ease;
}

.milestone-card.achieved {
    border-color: #28a745;
    background: linear-gradient(135deg, #f8fff9 0%, #e8f5e9 100%);
}

.milestone-card.available:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.milestone-icon {
    flex-shrink: 0;
    width: 80px;
    height: 80px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #f5f5f5;
    font-size: 2.5em;
}

.milestone-icon.locked {
    opacity: 0.5;
    filter: grayscale(100%);
}

.milestone-content {
    flex: 1;
}

.milestone-content h3 {
    margin: 0 0 10px 0;
    color: #333;
}

.description {
    color: #666;
    font-size: 0.9em;
    margin-bottom: 15px;
}

.milestone-reward,
.milestone-requirement {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 10px 0;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 4px;
}

.reward-value.highlight {
    font-weight: bold;
    color: #28a745;
    font-size: 1.1em;
}

.reward-status {
    margin-left: auto;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 0.85em;
    font-weight: bold;
}

.reward-status.paid {
    background: #28a745;
    color: white;
}

.reward-status.pending {
    background: #ffc107;
    color: #333;
}

.progress-bar {
    height: 24px;
    background: #e0e0e0;
    border-radius: 12px;
    overflow: hidden;
    margin: 10px 0;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #007bff 0%, #0056b3 100%);
    transition: width 0.5s ease;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding-right: 10px;
    color: white;
    font-size: 0.85em;
    font-weight: bold;
}

.progress-text {
    display: flex;
    justify-content: space-between;
    font-size: 0.9em;
    color: #666;
}

.milestone-date {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #e0e0e0;
}

.milestone-date small {
    color: #999;
}

.group-title {
    margin: 30px 0 20px 0;
    padding-bottom: 10px;
    border-bottom: 2px solid #007bff;
    color: #333;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #999;
    font-size: 1.1em;
}

@media (max-width: 768px) {
    .milestone-card {
        flex-direction: column;
        text-align: center;
    }
    
    .milestone-icon {
        margin: 0 auto;
    }
}
</style>
<?php
$pageTitle = 'لاگ‌های تقلب';
ob_start();
?>

<div class="container-fluid">
    <h4 class="mb-4">لاگ‌های تشخیص تقلب</h4>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>کاربر</th>
                            <th>نوع تقلب</th>
                            <th>امتیاز ریسک</th>
                            <th>جزئیات</th>
                            <th>اقدام</th>
                            <th>IP</th>
                            <th>زمان</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="7" class="text-center">هیچ لاگی وجود ندارد</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td>
                                    <?php if ($log->user_id): ?>
                                        <a href="<?= url("/admin/users/show/{$log->user_id}") ?>">
                                            <?= e($log->full_name ?? 'کاربر #' . $log->user_id) ?>
                                        </a>
                                        <br><small class="text-muted"><?= e($log->email) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-warning"><?= e($log->fraud_type) ?></span>
                                </td>
                                <td>
                                    <?php 
                                    $color = 'success';
                                    if ($log->risk_score >= 70) $color = 'danger';
                                    elseif ($log->risk_score >= 40) $color = 'warning';
                                    ?>
                                    <span class="badge badge-<?= $color ?>"><?= e($log->risk_score) ?></span>
                                </td>
                                <td>
                                    <?php if ($log->details): ?>
                                        <button class="btn btn-sm btn-link" data-toggle="modal" data-target="#detailsModal<?= $log->id ?>">
                                            مشاهده
                                        </button>
                                        <!-- Modal -->
                                        <div class="modal fade" id="detailsModal<?= $log->id ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">جزئیات</h5>
                                                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <pre style="max-height: 400px; overflow-y: auto;"><?= e(json_encode(json_decode($log->details), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($log->action_taken): ?>
                                        <?php
                                        $actionColors = [
                                            'block' => 'danger',
                                            'challenge' => 'warning',
                                            'notify' => 'info'
                                        ];
                                        $color = $actionColors[$log->action_taken] ?? 'secondary';
                                        ?>
                                        <span class="badge badge-<?= $color ?>"><?= e($log->action_taken) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($log->ip_address): ?>
                                        <code><?= e($log->ip_address) ?></code>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= to_jalali($log->created_at) ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once view_path('layouts.admin');
?>

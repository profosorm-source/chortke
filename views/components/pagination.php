<?php
/**
 * Pagination Component
 * 
 * استفاده:
 * $paginationData = [
 *     'current_page' => 1,
 *     'last_page' => 10,
 *     'per_page' => 15,
 *     'total' => 150
 * ];
 * 
 * include 'components/pagination.php';
 */

$currentPage = $paginationData['current_page'] ?? 1;
$lastPage = $paginationData['last_page'] ?? 1;
$baseUrl = $_SERVER['REQUEST_URI'];
$baseUrl = strtok($baseUrl, '?');

if ($lastPage > 1):
?>

<nav aria-label="صفحه‌بندی">
    <ul class="pagination justify-content-center">
        
        <!-- Previous -->
        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= e($baseUrl) ?>?page=<?= $currentPage - 1 ?>">
                <i class="material-icons-outlined" style="font-size: 18px;">chevron_right</i>
            </a>
        </li>
        
        <?php
        // محاسبه صفحات نمایشی
        $start = max(1, $currentPage - 2);
        $end = min($lastPage, $currentPage + 2);
        
        // اولین صفحه
        if ($start > 1):
        ?>
            <li class="page-item">
                <a class="page-link" href="<?= e($baseUrl) ?>?page=1">1</a>
            </li>
            <?php if ($start > 2): ?>
                <li class="page-item disabled">
                    <span class="page-link">...</span>
                </li>
            <?php endif; ?>
        <?php endif; ?>
        
        <!-- صفحات میانی -->
        <?php for ($i = $start; $i <= $end; $i++): ?>
            <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                <a class="page-link" href="<?= e($baseUrl) ?>?page=<?= e($i) ?>"><?= e($i) ?></a>
            </li>
        <?php endfor; ?>
        
        <!-- آخرین صفحه -->
        <?php if ($end < $lastPage): ?>
            <?php if ($end < $lastPage - 1): ?>
                <li class="page-item disabled">
                    <span class="page-link">...</span>
                </li>
            <?php endif; ?>
            <li class="page-item">
                <a class="page-link" href="<?= e($baseUrl) ?>?page=<?= e($lastPage) ?>"><?= e($lastPage) ?></a>
            </li>
        <?php endif; ?>
        
        <!-- Next -->
        <li class="page-item <?= $currentPage >= $lastPage ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= e($baseUrl) ?>?page=<?= $currentPage + 1 ?>">
                <i class="material-icons-outlined" style="font-size: 18px;">chevron_left</i>
            </a>
        </li>
        
    </ul>
</nav>

<?php endif; ?>
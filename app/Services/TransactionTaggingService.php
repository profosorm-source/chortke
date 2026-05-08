<?php

namespace App\Services;

use Core\Database;
use App\Models\Transaction;

use App\Contracts\LoggerInterface;
/**
 * سرویس برچسب‌گذاری و دسته‌بندی تراکنش‌ها
 * 
 * این سرویس به کاربران اجازه می‌دهد:
 * - تراکنش‌ها را برچسب‌گذاری کنند
 * - دسته‌بندی خودکار بر اساس الگو
 * - گزارش‌گیری بر اساس دسته
 * - تحلیل هزینه‌ها
 */
class TransactionTaggingService extends \App\Services\BaseService
{
    private Database $db;
    // دسته‌بندی‌های پیش‌فرض
    private const DEFAULT_CATEGORIES = [
        // درآمد
        'income' => [
            'salary' => 'حقوق و دستمزد',
            'investment_profit' => 'سود سرمایه‌گذاری',
            'referral_commission' => 'کمیسیون معرفی',
            'task_reward' => 'پاداش تسک',
            'lottery_prize' => 'جایزه قرعه‌کشی',
            'content_revenue' => 'درآمد محتوا',
            'other_income' => 'درآمد متفرقه',
        ],
        
        // هزینه
        'expense' => [
            'withdrawal' => 'برداشت وجه',
            'fee' => 'کارمزد',
            'purchase' => 'خرید',
            'investment' => 'سرمایه‌گذاری',
            'task_creation' => 'ایجاد تسک',
            'ad_campaign' => 'کمپین تبلیغاتی',
            'other_expense' => 'هزینه متفرقه',
        ],
        
        // انتقال
        'transfer' => [
            'peer_to_peer' => 'انتقال کاربر به کاربر',
            'internal' => 'انتقال داخلی',
            'refund' => 'بازگشت وجه',
            'other_transfer' => 'انتقال متفرقه',
        ],
    ];
    
    // الگوهای خودکار برای تشخیص دسته
    private const AUTO_TAG_PATTERNS = [
        'deposit' => ['category' => 'income', 'subcategory' => 'other_income'],
        'withdrawal' => ['category' => 'expense', 'subcategory' => 'withdrawal'],
        'task_reward' => ['category' => 'income', 'subcategory' => 'task_reward'],
        'referral_commission' => ['category' => 'income', 'subcategory' => 'referral_commission'],
        'investment_deposit' => ['category' => 'expense', 'subcategory' => 'investment'],
        'investment_profit' => ['category' => 'income', 'subcategory' => 'investment_profit'],
        'lottery_participation' => ['category' => 'expense', 'subcategory' => 'other_expense'],
        'lottery_win' => ['category' => 'income', 'subcategory' => 'lottery_prize'],
        'fee' => ['category' => 'expense', 'subcategory' => 'fee'],
    ];
    
    public function __construct(Database $db, LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->db = $db;
    }
    
    /**
     * افزودن تگ به یک تراکنش
     */
    public function addTag(int $transactionId, int $userId, string $tag): array
    {
        // بررسی مالکیت تراکنش
        if (!$this->userOwnsTransaction($transactionId, $userId)) {
            return [
                'success' => false,
                'message' => 'شما مجاز به برچسب‌گذاری این تراکنش نیستید'
            ];
        }
        
        // اعتبارسنجی تگ
        $tag = $this->sanitizeTag($tag);
        if (empty($tag)) {
            return ['success' => false, 'message' => 'برچسب نامعتبر است'];
        }
        
        try {
            // بررسی تکراری نبودن
            if ($this->tagExists($transactionId, $tag)) {
                return ['success' => false, 'message' => 'این برچسب قبلاً اضافه شده است'];
            }
            
            $sql = "
                INSERT INTO transaction_tags (transaction_id, tag, created_at)
                VALUES (?, ?, NOW())
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$transactionId, $tag]);
            
            $this->logger->info('transaction.tag_added', [
                'transaction_id' => $transactionId,
                'user_id' => $userId,
                'tag' => $tag
            ]);
            
            return [
                'success' => true,
                'message' => 'برچسب با موفقیت اضافه شد',
                'tag' => $tag
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('transaction.tag_add_failed', [
                'error' => $e->getMessage()
            ]);
            
            return ['success' => false, 'message' => 'خطا در افزودن برچسب'];
        }
    }
    
    /**
     * حذف تگ از تراکنش
     */
    public function removeTag(int $transactionId, int $userId, string $tag): array
    {
        if (!$this->userOwnsTransaction($transactionId, $userId)) {
            return [
                'success' => false,
                'message' => 'شما مجاز به حذف برچسب این تراکنش نیستید'
            ];
        }
        
        try {
            $sql = "DELETE FROM transaction_tags WHERE transaction_id = ? AND tag = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$transactionId, $tag]);
            
            return [
                'success' => true,
                'message' => 'برچسب حذف شد'
            ];
            
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'خطا در حذف برچسب'];
        }
    }
    
    /**
     * دسته‌بندی یک تراکنش
     */
    public function categorize(
        int $transactionId, 
        int $userId, 
        string $category, 
        ?string $subcategory = null
    ): array {
        if (!$this->userOwnsTransaction($transactionId, $userId)) {
            return [
                'success' => false,
                'message' => 'شما مجاز به دسته‌بندی این تراکنش نیستید'
            ];
        }
        
        // اعتبارسنجی دسته
        if (!$this->isValidCategory($category, $subcategory)) {
            return ['success' => false, 'message' => 'دسته یا زیردسته نامعتبر است'];
        }
        
        try {
            $sql = "
                INSERT INTO transaction_categories 
                (transaction_id, category, subcategory, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                    category = VALUES(category),
                    subcategory = VALUES(subcategory),
                    updated_at = NOW()
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$transactionId, $category, $subcategory]);
            
            $this->logger->info('transaction.categorized', [
                'transaction_id' => $transactionId,
                'category' => $category,
                'subcategory' => $subcategory
            ]);
            
            return [
                'success' => true,
                'message' => 'دسته‌بندی انجام شد',
                'category' => $category,
                'subcategory' => $subcategory
            ];
            
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'خطا در دسته‌بندی'];
        }
    }
    
    /**
     * دسته‌بندی خودکار بر اساس نوع تراکنش
     */
    public function autoCategorizeBatch(int $userId, array $transactionIds): array
    {
        $categorized = 0;
        $failed = 0;
        
        foreach ($transactionIds as $txnId) {
            if (!$this->userOwnsTransaction($txnId, $userId)) {
                $failed++;
                continue;
            }
            
            $txn = $this->getTransaction($txnId);
            if (!$txn) {
                $failed++;
                continue;
            }
            
            // تشخیص دسته بر اساس نوع
            $autoTag = $this->detectCategory($txn);
            
            if ($autoTag) {
                $result = $this->categorize(
                    $txnId, 
                    $userId, 
                    $autoTag['category'], 
                    $autoTag['subcategory']
                );
                
                if ($result['success']) {
                    $categorized++;
                } else {
                    $failed++;
                }
            } else {
                $failed++;
            }
        }
        
        return [
            'success' => true,
            'categorized' => $categorized,
            'failed' => $failed,
            'total' => count($transactionIds)
        ];
    }
    
    /**
     * دریافت تگ‌های یک تراکنش
     */
    public function getTags(int $transactionId): array
    {
        $sql = "SELECT tag FROM transaction_tags WHERE transaction_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$transactionId]);
        
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'tag');
    }
    
    /**
     * دریافت دسته‌بندی یک تراکنش
     */
    public function getCategory(int $transactionId): ?array
    {
        $sql = "
            SELECT category, subcategory 
            FROM transaction_categories 
            WHERE transaction_id = ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$transactionId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }
    
    /**
     * جستجوی تراکنش‌ها بر اساس تگ
     */
    public function findByTag(int $userId, string $tag, int $limit = 50): array
    {
        $sql = "
            SELECT t.*
            FROM transactions t
            JOIN transaction_tags tt ON t.id = tt.transaction_id
            WHERE t.user_id = ?
            AND tt.tag = ?
            ORDER BY t.created_at DESC
            LIMIT ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $tag, $limit]);
        
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }
    
    /**
     * جستجوی تراکنش‌ها بر اساس دسته
     */
    public function findByCategory(
        int $userId, 
        string $category, 
        ?string $subcategory = null,
        int $limit = 50
    ): array {
        if ($subcategory) {
            $sql = "
                SELECT t.*
                FROM transactions t
                JOIN transaction_categories tc ON t.id = tc.transaction_id
                WHERE t.user_id = ?
                AND tc.category = ?
                AND tc.subcategory = ?
                ORDER BY t.created_at DESC
                LIMIT ?
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId, $category, $subcategory, $limit]);
        } else {
            $sql = "
                SELECT t.*
                FROM transactions t
                JOIN transaction_categories tc ON t.id = tc.transaction_id
                WHERE t.user_id = ?
                AND tc.category = ?
                ORDER BY t.created_at DESC
                LIMIT ?
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId, $category, $limit]);
        }
        
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }
    
    /**
     * گزارش هزینه‌ها بر اساس دسته (برای یک بازه زمانی)
     */
    public function getExpenseReport(int $userId, string $startDate, string $endDate): array
    {
        $sql = "
            SELECT 
                tc.category,
                tc.subcategory,
                COUNT(*) as transaction_count,
                SUM(t.amount) as total_amount,
                AVG(t.amount) as avg_amount
            FROM transactions t
            JOIN transaction_categories tc ON t.id = tc.transaction_id
            WHERE t.user_id = ?
            AND tc.category = 'expense'
            AND t.created_at BETWEEN ? AND ?
            GROUP BY tc.category, tc.subcategory
            ORDER BY total_amount DESC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $startDate, $endDate]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * گزارش درآمدها بر اساس دسته
     */
    public function getIncomeReport(int $userId, string $startDate, string $endDate): array
    {
        $sql = "
            SELECT 
                tc.category,
                tc.subcategory,
                COUNT(*) as transaction_count,
                SUM(t.amount) as total_amount,
                AVG(t.amount) as avg_amount
            FROM transactions t
            JOIN transaction_categories tc ON t.id = tc.transaction_id
            WHERE t.user_id = ?
            AND tc.category = 'income'
            AND t.created_at BETWEEN ? AND ?
            GROUP BY tc.category, tc.subcategory
            ORDER BY total_amount DESC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $startDate, $endDate]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * خلاصه مالی ماهانه
     */
    public function getMonthlySummary(int $userId, int $year, int $month): array
    {
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));
        
        $income = $this->getIncomeReport($userId, $startDate, $endDate);
        $expense = $this->getExpenseReport($userId, $startDate, $endDate);
        
        $totalIncome = array_sum(array_column($income, 'total_amount'));
        $totalExpense = array_sum(array_column($expense, 'total_amount'));
        
        return [
            'year' => $year,
            'month' => $month,
            'income' => [
                'total' => $totalIncome,
                'breakdown' => $income
            ],
            'expense' => [
                'total' => $totalExpense,
                'breakdown' => $expense
            ],
            'net' => $totalIncome - $totalExpense,
        ];
    }
    
    /**
     * پیشنهاد تگ‌ها بر اساس تاریخچه
     */
    public function suggestTags(int $userId, int $transactionId): array
    {
        $txn = $this->getTransaction($transactionId);
        if (!$txn) {
            return [];
        }
        
        // پیدا کردن تراکنش‌های مشابه
        $sql = "
            SELECT DISTINCT tt.tag, COUNT(*) as usage_count
            FROM transactions t1
            JOIN transactions t2 ON ABS(t1.amount - t2.amount) < 10000
            JOIN transaction_tags tt ON t2.id = tt.transaction_id
            WHERE t1.id = ?
            AND t1.user_id = ?
            AND t2.user_id = ?
            GROUP BY tt.tag
            ORDER BY usage_count DESC
            LIMIT 5
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$transactionId, $userId, $userId]);
        
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'tag');
    }
    
    /**
     * دریافت محبوب‌ترین تگ‌های کاربر
     */
    public function getPopularTags(int $userId, int $limit = 10): array
    {
        $sql = "
            SELECT tt.tag, COUNT(*) as count
            FROM transaction_tags tt
            JOIN transactions t ON tt.transaction_id = t.id
            WHERE t.user_id = ?
            GROUP BY tt.tag
            ORDER BY count DESC
            LIMIT ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $limit]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * ادغام تگ‌ها (برای تصحیح املایی یا یکسان‌سازی)
     */
    public function mergeTags(int $userId, string $fromTag, string $toTag): array
    {
        try {
            $this->db->beginTransaction();
            
            // بروزرسانی همه تگ‌ها
            $sql = "
                UPDATE transaction_tags tt
                JOIN transactions t ON tt.transaction_id = t.id
                SET tt.tag = ?
                WHERE t.user_id = ?
                AND tt.tag = ?
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$toTag, $userId, $fromTag]);
            $affected = $stmt->rowCount();
            
            $this->db->commit();
            
            return [
                'success' => true,
                'merged_count' => $affected,
                'from_tag' => $fromTag,
                'to_tag' => $toTag
            ];
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا در ادغام تگ‌ها'];
        }
    }
    
    // ==================== Helper Methods ====================
    
    private function userOwnsTransaction(int $transactionId, int $userId): bool
    {
        $sql = "SELECT id FROM transactions WHERE id = ? AND user_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$transactionId, $userId]);
        
        return $stmt->fetch() !== false;
    }
    
    private function sanitizeTag(string $tag): string
    {
        // حذف کاراکترهای غیرمجاز
        $tag = trim($tag);
        $tag = preg_replace('/[^a-zA-Z0-9\x{0600}-\x{06FF}\s_-]/u', '', $tag);
        
        // محدودیت طول
        return mb_substr($tag, 0, 50);
    }
    
    private function tagExists(int $transactionId, string $tag): bool
    {
        $sql = "SELECT id FROM transaction_tags WHERE transaction_id = ? AND tag = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$transactionId, $tag]);
        
        return $stmt->fetch() !== false;
    }
    
    private function isValidCategory(string $category, ?string $subcategory): bool
    {
        if (!isset(self::DEFAULT_CATEGORIES[$category])) {
            return false;
        }
        
        if ($subcategory && !isset(self::DEFAULT_CATEGORIES[$category][$subcategory])) {
            return false;
        }
        
        return true;
    }
    
    private function getTransaction(int $transactionId): ?object
    {
        $sql = "SELECT * FROM transactions WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$transactionId]);
        
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }
    
    private function detectCategory(object $transaction): ?array
    {
        $type = $transaction->type ?? '';
        
        return self::AUTO_TAG_PATTERNS[$type] ?? null;
    }
    
    /**
     * دریافت لیست دسته‌بندی‌ها
     */
    public function getCategories(): array
    {
        return self::DEFAULT_CATEGORIES;
    }
}


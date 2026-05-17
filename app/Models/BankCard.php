<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class BankCard extends Model
{
    protected static string $table = 'bank_cards';

    /**
     * ایجاد کارت بانکی جدید
     * ایجاد رکورد جدید
     * در صورت کارت تکراری (Unique) => null برمی‌گرداند.
     */
    public function create(array $data): ?object
    {
        // حذف فاصله‌ها از شماره کارت
        if (isset($data['card_number'])) {
            $data['card_number'] = \preg_replace('/\s+/', '', (string)$data['card_number']);
        }

        // timestamps
        $now = \date('Y-m-d H:i:s');
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;

        try {
            $idOrBool = parent::create($data);

            if (\is_int($idOrBool) && $idOrBool > 0) {
                return $this->find((int)$idOrBool);
            }
            return null;
        } catch (\PDOException $e) {
            // خطای کلید یکتا (کارت تکراری)
            if ((string)$e->getCode() === '23000') {
                return null;
            }
            throw $e;
        }
    }

    /**
     * دریافت کارت‌های کاربر (فقط کارت‌های حذف‌نشده)
     */
    public function getUserCards(int $userId, ?string $status = null): array
    {
        $sql = "SELECT * FROM " . static::$table . "
                WHERE user_id = :user_id AND deleted_at IS NULL";

        $params = ['user_id' => $userId];

        if ($status) {
            $sql .= " AND status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY is_default DESC, created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * شمارش کارت‌های کاربر (فقط کارت‌های حذف‌نشده)
     */
    public function countUserCards(int $userId, ?string $status = null): int
    {
        $sql = "SELECT COUNT(*) as count FROM " . static::$table . "
                WHERE user_id = :user_id AND deleted_at IS NULL";

        $params = ['user_id' => $userId];

        if ($status) {
            $sql .= " AND status = :status";
            $params['status'] = $status;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return (int)($result->count ?? 0);
    }

    /**
     * تنظیم کارت پیش‌فرض
     */
    public function setDefault(int $id, int $userId): bool
    {
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }
        try {
            // ابتدا همه کارت‌های کاربر را غیرپیش‌فرض کن (فقط حذف‌نشده‌ها)
            $stmt = $this->db->prepare(
                "UPDATE " . static::$table . "
                 SET is_default = 0, updated_at = NOW()
                 WHERE user_id = :user_id AND deleted_at IS NULL"
            );
            $stmt->execute(['user_id' => $userId]);

            // کارت انتخابی را پیش‌فرض کن (فقط حذف‌نشده)
            $stmt = $this->db->prepare(
                "UPDATE " . static::$table . "
                 SET is_default = 1, updated_at = NOW()
                 WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL"
            );

            $ok = $stmt->execute(['id' => $id, 'user_id' => $userId]);
            if ($ok) {
                if ($startedTransaction) {
                    $this->db->commit();
                }
                return true;
            }

            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * دریافت کارت پیش‌فرض کاربر
     */
    public function getDefaultCard(int $userId): ?object
    {
        $sql = "SELECT * FROM " . static::$table . "
                WHERE user_id = :user_id
                  AND is_default = 1
                  AND status = 'verified'
                  AND deleted_at IS NULL
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }

    /**
     * بروزرسانی وضعیت کارت
     */
    public function updateStatus(int $id, string $status, ?string $rejectionReason = null, ?int $reviewedBy = null): bool
    {
        $sql = "UPDATE " . static::$table . " SET status = :status, updated_at = NOW()";
        $params = ['id' => $id, 'status' => $status];

        if ($status === 'verified') {
            $sql .= ", verified_at = NOW()";
        }

        if ($rejectionReason) {
            $sql .= ", rejection_reason = :rejection_reason";
            $params['rejection_reason'] = $rejectionReason;
        }

        if ($reviewedBy) {
            $sql .= ", reviewed_by = :reviewed_by";
            $params['reviewed_by'] = $reviewedBy;
        }

        $sql .= " WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * دریافت کارت‌های در انتظار بررسی (برای ادمین)
     */
    public function getPendingCards(int $limit = 50, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT c.*, u.full_name, u.email
                FROM " . static::$table . " c
                LEFT JOIN users u ON c.user_id = u.id
                WHERE c.status = 'pending'
                  AND c.deleted_at IS NULL
                ORDER BY c.created_at ASC
                LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * شمارش کارت‌های در انتظار
     */
    public function countPendingCards(): int
    {
        $sql = "SELECT COUNT(*) as count FROM " . static::$table . "
                WHERE status = 'pending' AND deleted_at IS NULL";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return (int)($result->count ?? 0);
    }

    /**
     * حذف کارت (Soft Delete)
     * نکته: Delete واقعی ممنوع است.
     */
    public function deleteForUser(int $id, int $userId): bool
    {
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }
        try {
            // Lock the card row itself FOR UPDATE to ensure exclusive deletion process
            $card = $this->db->query("SELECT id FROM " . static::$table . " WHERE id = ? AND user_id = ? FOR UPDATE", [$id, $userId])->fetch(\PDO::FETCH_OBJ);
            if (!$card) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return false;
            }

            // بررسی اینکه کارت در manual_deposits فعال استفاده نشده باشد (BUG-18)
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM manual_deposits
                WHERE card_id = :card_id AND status NOT IN ('rejected', 'cancelled')
            ");
            $stmt->execute(['card_id' => $id]);
            $result = $stmt->fetch(\PDO::FETCH_OBJ);

            if (((int)($result->count ?? 0)) > 0) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return false;
            }

            // بررسی اینکه کارت در withdrawals فعال استفاده نشده باشد (BUG-18)
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM withdrawals
                WHERE card_id = :card_id AND status NOT IN ('rejected', 'cancelled', 'failed')
            ");
            $stmt->execute(['card_id' => $id]);
            $result2 = $stmt->fetch(\PDO::FETCH_OBJ);

            if (((int)($result2->count ?? 0)) > 0) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return false;
            }

            // Soft Delete
            $stmt = $this->db->prepare("
                UPDATE " . static::$table . "
                SET deleted_at = NOW(), updated_at = NOW(), is_default = 0
                WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
            ");

            $ok = $stmt->execute(['id' => $id, 'user_id' => $userId]);
            if ($ok) {
                if ($startedTransaction) {
                    $this->db->commit();
                }
                return true;
            }

            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * دریافت کارت بر اساس شناسه و کاربر
     */
    public function findByIdAndUser(int $id, int $userId): ?object
    {
        $sql = "SELECT * FROM " . static::$table . "
                WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }
}
<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * Rating Model - مدل اشتراکی نظرات و امتیازات عددی
 */
class Rating extends Model
{
    protected static string $table = 'ratings';

    public function getAverage(int $ratedId, string $ratedType): float
    {
        return (float)$this->db->table(static::$table)
            ->where('rated_id', '=', $ratedId)
            ->where('rated_type', '=', $ratedType)
            ->avg('rating');
    }


    /**
     * Atomic create guarded by DB named lock and compatible with uq_rating_once constraint.
     */
    public function createOnce(array $data): bool
    {
        $raterId = (int)($data['rater_id'] ?? 0);
        $refType = (string)($data['ref_type'] ?? '');
        $refId = (int)($data['ref_id'] ?? 0);
        $lockName = 'rating:' . sha1($raterId . ':' . $refType . ':' . $refId);

        try {
            $locked = (int)$this->db->fetchColumn('SELECT GET_LOCK(?, 5)', [$lockName]);
            if ($locked !== 1) {
                return false;
            }

            if ($this->hasRated($raterId, $refType, $refId)) {
                return false;
            }

            $stmt = $this->db->prepare("
                INSERT INTO ratings
                (rater_id, rated_id, rated_type, ref_type, ref_id, rating, review_text, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            return $stmt->execute([
                $raterId,
                (int)($data['rated_id'] ?? 0),
                (string)($data['rated_type'] ?? 'user'),
                $refType,
                $refId,
                (int)($data['rating'] ?? 0),
                $data['review_text'] ?? null,
            ]);
        } catch (\PDOException $e) {
            if ((string)$e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate')) {
                return false;
            }
            throw $e;
        } finally {
            try {
                $this->db->fetchColumn('SELECT RELEASE_LOCK(?)', [$lockName]);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * بررسی اینکه آیا کاربر قبلا برای این مرجع امتیاز ثبت کرده است یا خیر
     */
    public function hasRated(int $raterId, string $refType, int $refId): bool
    {
        $row = $this->db->table(static::$table)
            ->where('rater_id', '=', $raterId)
            ->where('ref_type', '=', $refType)
            ->where('ref_id', '=', $refId)
            ->selectRaw('1')
            ->first();
            
        return (bool)$row;
    }
}

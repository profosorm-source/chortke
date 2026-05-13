<?php
// app/Models/ContentAgreement.php

namespace App\Models;

use Core\Model;
use Core\Database;

class ContentAgreement extends Model {
/**
     * ثبت تعهدنامه
     */
    public function create(array $data): ?int
    {
        return $this->db->insert('content_agreements', $data);
    }

    /**
     * یافتن تعهدنامه کاربر برای یک محتوا
     */
    public function findBySubmission(int $submissionId): ?object
    {
        return $this->db->query(
            "SELECT * FROM content_agreements
             WHERE submission_id = ? AND is_deleted = 0
             ORDER BY accepted_at DESC LIMIT 1",
            [$submissionId]
        )->fetch() ?: null;
    }

    /**
     * تمام تعهدنامه‌های یک کاربر
     */
    public function getByUser(int $userId): array
    {
        return $this->db->query(
            "SELECT ca.*, cs.title as video_title
             FROM content_agreements ca
             JOIN content_submissions cs ON ca.submission_id = cs.id
             WHERE ca.user_id = ? AND ca.is_deleted = 0
             ORDER BY ca.accepted_at DESC",
            [$userId]
        )->fetchAll();
    }
}
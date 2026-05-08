<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * FileAccess Model - File ownership and access queries only
 */
class FileAccess extends Model
{
    protected static string $table = 'file_logs';

    public function checkKycOwnership(string $filename, int $userId): bool
    {
        $stmt = $this->db->query(
            "SELECT user_id FROM kyc_verifications
             WHERE verification_image = ?
             ORDER BY id DESC LIMIT 1",
            [$filename]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return $row && (int)$row->user_id === $userId;
    }

    public function checkReceiptOwnership(string $filename, int $userId): bool
    {
        $stmt = $this->db->query(
            "SELECT user_id FROM manual_deposits
             WHERE receipt_image = ?
             ORDER BY id DESC LIMIT 1",
            [$filename]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return $row && (int)$row->user_id === $userId;
    }

    public function checkTaskProofOwnership(string $filename, int $userId): bool
    {
        $stmt = $this->db->query(
            "SELECT te.executor_id, a.advertiser_id
             FROM task_executions te
             JOIN advertisements a ON a.id = te.advertisement_id
             WHERE te.proof_image = ?
             ORDER BY te.id DESC LIMIT 1",
            [$filename]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return $row && ((int)$row->executor_id === $userId || (int)$row->advertiser_id === $userId);
    }

    public function checkTaskSampleOwnership(string $filename, int $userId): bool
    {
        $stmt = $this->db->query(
            "SELECT creator_id FROM custom_tasks
             WHERE sample_image = ?
             LIMIT 1",
            [$filename]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        
        // Check if user is the creator
        if ($row && (int)$row->creator_id === $userId) {
            return true;
        }

        // Check if user has active submission
        $stmt = $this->db->query(
            "SELECT cts.id FROM custom_task_submissions cts
             JOIN custom_tasks ct ON ct.id = cts.task_id
             WHERE ct.sample_image = ? AND cts.user_id = ?
             LIMIT 1",
            [$filename, $userId]
        );

        $submission = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return (bool) $submission;
    }

    public function checkAdTaskSampleOwnership(string $filename, int $userId): bool
    {
        // Check if user is the advertiser
        $stmt = $this->db->query(
            "SELECT advertiser_id FROM advertisements
             WHERE sample_image = ?
             LIMIT 1",
            [$filename]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        if ($row && (int)$row->advertiser_id === $userId) {
            return true;
        }

        // Check if user is an executor for this advertisement
        $stmt = $this->db->query(
            "SELECT te.id FROM task_executions te
             JOIN advertisements a ON a.id = te.advertisement_id
             WHERE a.sample_image = ? AND te.executor_id = ?
             LIMIT 1",
            [$filename, $userId]
        );

        $executor = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return (bool) $executor;
    }

    public function checkDisputeEvidenceOwnership(string $filename, int $userId): bool
    {
        $stmt = $this->db->query(
            "SELECT te.executor_id, a.advertiser_id
             FROM task_executions te
             JOIN advertisements a ON a.id = te.advertisement_id
             WHERE te.dispute_evidence = ?
             ORDER BY te.id DESC LIMIT 1",
            [$filename]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return $row && ((int)$row->executor_id === $userId || (int)$row->advertiser_id === $userId);
    }

    public function checkStoryProofOwnership(string $filename, int $userId): bool
    {
        $stmt = $this->db->query(
            "SELECT customer_id, influencer_user_id
             FROM story_orders
             WHERE proof_screenshot = ?
             ORDER BY id DESC LIMIT 1",
            [$filename]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return $row && ((int)$row->customer_id === $userId || (int)$row->influencer_user_id === $userId);
    }

    public function checkStoryMediaOwnership(string $filename, int $userId): bool
    {
        $stmt = $this->db->query(
            "SELECT customer_id, influencer_user_id
             FROM story_orders
             WHERE media_path LIKE ?
             ORDER BY id DESC LIMIT 1",
            ['%' . $filename]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return $row && ((int)$row->customer_id === $userId || (int)$row->influencer_user_id === $userId);
    }

    public function checkInfluencerProfileOwnership(string $filename, int $userId): bool
    {
        $stmt = $this->db->query(
            "SELECT user_id FROM influencer_profiles
             WHERE profile_image = ?
             LIMIT 1",
            [$filename]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return $row && (int)$row->user_id === $userId;
    }

    public function checkTicketAttachmentOwnership(string $filename, int $userId): bool
    {
        $stmt = $this->db->query(
            "SELECT t.user_id
             FROM ticket_messages tm
             JOIN tickets t ON t.id = tm.ticket_id
             WHERE tm.attachments LIKE ?
             ORDER BY tm.id DESC LIMIT 1",
            ['%' . $filename . '%']
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return $row && (int)$row->user_id === $userId;
    }

    public function logFileAccess(string $folder, string $filename, int $userId, string $action, string $ip): bool
    {
        $stmt = $this->db->query(
            "INSERT INTO file_logs
             (folder, filename, viewer_id, action, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [$folder, $filename, $userId, $action, $ip]
        );

        return $stmt instanceof \PDOStatement ? $stmt->rowCount() >= 0 : (bool) $stmt;
    }

    public function logDeniedFileAccess(string $folder, string $filename, int $userId, string $ip): bool
    {
        $stmt = $this->db->query(
            "INSERT INTO file_logs
             (folder, filename, viewer_id, action, ip_address, created_at)
             VALUES (?, ?, ?, 'denied', ?, NOW())",
            [$folder, $filename, $userId, $ip]
        );

        return $stmt instanceof \PDOStatement ? $stmt->rowCount() >= 0 : (bool) $stmt;
    }
}
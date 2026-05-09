<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * Advertisement Model
 */
class Advertisement extends Model
{
    protected static string $table = 'advertisements';

    public function getByAdvertiser(int $advertiserId, int $limit = 20, int $offset = 0): array
    {
        return $this->db->table(static::$table)
            ->where('advertiser_id', '=', $advertiserId)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    public function expireOldAdvertisements(int $chunkSize = 1000): int
    {
        $totalExpired = 0;
        $maxIterations = 100; // جلوگیری از infinite loop
        $now = date('Y-m-d H:i:s');
        
        for ($i = 0; $i < $maxIterations; $i++) {
            // به‌روزرسانی تکه‌تکه (Chunked)
            $sql = "UPDATE `" . static::$table . "` 
                    SET `status` = 'completed', `updated_at` = ? 
                    WHERE `status` = 'active' 
                      AND ((end_date IS NOT NULL AND end_date < ?) 
                           OR remaining_count <= 0 
                           OR remaining_budget <= 0)
                    LIMIT ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$now, $now, $chunkSize]);
            
            $affected = $stmt->rowCount();
            $totalExpired += $affected;
            
            if ($affected < $chunkSize) {
                break;
            }
            
            usleep(50000); // 50ms delay
        }
        
        return $totalExpired;
    }
}

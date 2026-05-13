<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * RiskPolicy Model
 */
class RiskPolicy extends Model
{
    protected static string $table = 'risk_policies';

    public function get(string $domain, string $keyName): ?object
    {
        return $this->db->table(static::$table)
            ->where('domain', '=', $domain)
            ->where('key_name', '=', $keyName)
            ->first();
    }

    public function allByDomain(string $domain): array
    {
        return $this->db->table(static::$table)
            ->where('domain', '=', $domain)
            ->orderBy('key_name', 'ASC')
            ->get();
    }

    public function upsert(
        string $domain,
        string $keyName,
        string $value,
        string $valueType,
        ?string $description,
        int $updatedBy
    ): bool {
        $sql = 'INSERT INTO risk_policies (domain, key_name, value, value_type, description, updated_by) 
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    value = VALUES(value), 
                    value_type = VALUES(value_type), 
                    description = VALUES(description), 
                    updated_by = VALUES(updated_by), 
                    updated_at = CURRENT_TIMESTAMP';

        return (bool) $this->db->query($sql, [$domain, $keyName, $value, $valueType, $description, $updatedBy]);
    }
}
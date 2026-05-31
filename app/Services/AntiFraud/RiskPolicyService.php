<?php

namespace App\Services\AntiFraud;

use Core\Database;

use App\Contracts\LoggerInterface;
class RiskPolicyService
{
    /** @var array<string, mixed> */
    protected array $localCache = [];


    public function __construct(Database $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    private function cacheKey(string $domain, string $key): string
    {
        return $domain . '::' . $key;
    }

    /**
     * مقدار خام policy را برمی‌گرداند (یا default)
     */
    public function get(string $domain, string $key, $default = null)
    {
        $cacheKey = $this->cacheKey($domain, $key);

        if (array_key_exists($cacheKey, $this->localCache)) {
            return $this->localCache[$cacheKey];
        }

        $stmt = $this->db->prepare("
            SELECT value, value_type
            FROM risk_policies
            WHERE domain = ? AND key_name = ?
            LIMIT 1
        ");
        $stmt->execute([$domain, $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->localCache[$cacheKey] = $default;
            return $default;
        }

        $value = $this->castValue($row['value'], $row['value_type'] ?? 'string');
        $this->localCache[$cacheKey] = $value;

        return $value;
    }

    public function getInt(string $domain, string $key, int $default = 0): int
    {
        return (int)$this->get($domain, $key, $default);
    }

    public function getFloat(string $domain, string $key, float $default = 0.0): float
    {
        return (float)$this->get($domain, $key, $default);
    }

    public function getBool(string $domain, string $key, bool $default = false): bool
    {
        $value = $this->get($domain, $key, $default);

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower((string)$value);
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    public function getArray(string $domain, string $key, array $default = []): array
    {
        $value = $this->get($domain, $key, $default);
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }
        return $default;
    }

    /**
     * ثبت/آپدیت policy (برای پنل مدیریت)
     */
    public function set(
        string $domain,
        string $key,
        $value,
        string $valueType = 'string',
        ?int $adminId = null,
        ?string $description = null
    ): bool {
        $valueType = $this->normalizeValueType($valueType);
        $storedValue = $this->stringifyValue($value, $valueType);

        $stmt = $this->db->prepare("
            INSERT INTO risk_policies (domain, key_name, value, value_type, description, updated_by, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                value = VALUES(value),
                value_type = VALUES(value_type),
                description = VALUES(description),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ");

        $ok = $stmt->execute([
            $domain,
            $key,
            $storedValue,
            $valueType,
            $description,
            $adminId,
        ]);

        unset($this->localCache[$this->cacheKey($domain, $key)]);

        return $ok;
    }

    public function getPoliciesWithDefaults(): array
    {
        $stmt = $this->db->prepare("
            SELECT id, domain, key_name, value, value_type, description, updated_by, updated_at
            FROM risk_policies
            ORDER BY domain ASC, key_name ASC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['domain'] . '::' . $row['key_name']] = $row;
        }

        $display = [];
        foreach ($this->defaultPolicies() as $item) {
            $key = $item['domain'] . '::' . $item['key_name'];
            if (isset($indexed[$key])) {
                $display[] = $indexed[$key];
                unset($indexed[$key]);
            } else {
                $display[] = $item + [
                    'id' => null,
                    'updated_by' => null,
                    'updated_at' => null,
                ];
            }
        }

        foreach ($indexed as $remaining) {
            $display[] = $remaining;
        }

        return $display;
    }

    private function normalizeValueType(string $valueType): string
    {
        $valueType = strtolower(trim($valueType));
        return in_array($valueType, ['int', 'float', 'bool', 'string', 'json'], true) ? $valueType : 'string';
    }

    public function clearCache(): void
    {
        $this->localCache = [];
    }

    private function castValue($value, string $type)
    {
        switch (strtolower($type)) {
            case 'int':
            case 'integer':
                return (int)$value;

            case 'float':
            case 'double':
                return (float)$value;

            case 'bool':
            case 'boolean':
                $normalized = strtolower((string)$value);
                return in_array($normalized, ['1', 'true', 'yes', 'on'], true);

            case 'json':
                $decoded = json_decode((string)$value, true);
                return json_last_error() === JSON_ERROR_NONE ? $decoded : null;

            default:
                return $value;
        }
    }

    private function stringifyValue($value, string $type): string
    {
        switch (strtolower($type)) {
            case 'json':
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return json_encode($decoded, JSON_UNESCAPED_UNICODE);
                    }
                }

                return json_encode($value, JSON_UNESCAPED_UNICODE);

            case 'bool':
            case 'boolean':
                return $value ? '1' : '0';

            default:
                return (string)$value;
        }
    }
}


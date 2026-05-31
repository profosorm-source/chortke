<?php

declare(strict_types=1);

namespace Core;

/**
 * Model — پایه تمام Model‌های پروژه
 *
 * ─── جریان صحیح ────────────────────────────────────────────────
 *
 *   Container::make(UserModel)
 *       └─→ Model::__construct()
 *               └─→ Container::make(Database::class)  ← singleton
 *
 * ─── قرارداد ───────────────────────────────────────────────────
 *   همه متدها از $this->db استفاده می‌کنند.
 *   db() متد هم همان $this->db را برمی‌گرداند (نه app()->db).
 *   هیچ‌جا مستقیم Database::getInstance() صدا زده نمی‌شود.
 *
 * ─── تذکر ──────────────────────────────────────────────────────
 *   Model نباید Business Logic داشته باشد.
 *   Logic باید در Service باشد، Model فقط Data Access.
 */
abstract class Model
{
    protected static array $searchable = [];
    protected static string $table = '';

    protected Database $db;

    public function __construct(Database $db)
    {
        if (empty(static::$table)) {
            throw new \RuntimeException("Model " . static::class . " must define \$table");
        }
        $this->db = $db;
    }

    // ─────────────────────────────────────────────────────────────
    // Internal Helper — یک‌منبعه
    // ─────────────────────────────────────────────────────────────

    /**
     * برای backward compatibility — همان $this->db
     */
    protected function db(): Database
    {
        return $this->db;
    }

    /**
     * دریافت شیء دیتابیس
     */
    public function getDb(): Database
    {
        return $this->db;
    }

    public function getTable(): string
    {
        return static::$table;
    }

    // ─────────────────────────────────────────────────────────────
    // CRUD پایه
    // ─────────────────────────────────────────────────────────────

    public function create(array $data): mixed
    {
        return $this->db->table(static::$table)->insert($data);
    }

    public function find(int $id): ?object
    {
        $result = $this->db->table(static::$table)
            ->where('id', '=', $id)
            ->first();

        return $result ?: null;
    }

    public function all($filters = [], $limit = 100, $offset = 0): array
    {
        if (is_int($filters)) {
            $offset = $limit;
            $limit = $filters;
        }

        return $this->db->table(static::$table)
            ->limit((int)$limit)
            ->offset((int)$offset)
            ->get();
    }

    public function update(int $id, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        $affected = $this->db->table(static::$table)
            ->where('id', '=', $id)
            ->update($data);

        return $affected > 0;
    }

    /** Soft Delete */
    public function delete(int $id): bool
    {
        // FIX C-9: قبلاً بدون چک وجود ردیف، update انجام می‌شد.
        // اگر id وجود نمی‌داشت، 0 ردیف تأثیر می‌گرفت و هیچ خطایی
        // داده نمی‌شد — صداکننده فکر می‌کرد عملیات موفق بوده.
        if (!$this->exists($id)) {
            return false;
        }

        $affected = $this->db->table(static::$table)
            ->where('id', '=', $id)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);

        return $affected > 0;
    }

    /** Hard Delete — با احتیاط استفاده کنید */
    public function forceDelete(int $id): bool
    {
        return $this->db->table(static::$table)
            ->where('id', '=', $id)
            ->delete();
    }

    public function count(): int
    {
        return (int) $this->db->table(static::$table)->count();
    }


    // ─────────────────────────────────────────────────────────────
    // Query Builder Bridge — امکان chain کردن روی Model
    // ─────────────────────────────────────────────────────────────

    /**
     * شروع یک query با WHERE روی جدول این Model
     * مثال: $this->model->where('user_id', $id)->whereIn('status', [...])->first()
     */
    public function where($column, $operatorOrValue = '=', $value = null): QueryBuilder
    {
        return $this->db->table(static::$table)->where($column, $operatorOrValue, $value);
    }

    public function whereIn(string $column, array $values): QueryBuilder
    {
        return $this->db->table(static::$table)->whereIn($column, $values);
    }

    public function whereNull(string $column): QueryBuilder
    {
        return $this->db->table(static::$table)->whereNull($column);
    }

    public function whereNotNull(string $column): QueryBuilder
    {
        return $this->db->table(static::$table)->whereNotNull($column);
    }

    public function orderBy(string $column, string $direction = 'ASC'): QueryBuilder
    {
        return $this->db->table(static::$table)->orderBy($column, $direction);
    }

    /**
     * دسترسی مستقیم به QueryBuilder برای query های پیچیده‌تر
     */
    public function query(): QueryBuilder
    {
        return $this->db->table(static::$table);
    }

    public function exists(int $id): bool
    {
        return (bool) $this->db->table(static::$table)
            ->where('id', '=', $id)
            ->selectRaw('1')
            ->first();
    }

    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollback(): void
    {
        $this->db->rollback();
    }

    /**
     * اعمال هوشمند فیلتر کلمه کلیدی (LIKE) بر روی ستون‌های تعریف شده در $searchable
     */
    public function applySearch(QueryBuilder $query, ?string $term): QueryBuilder
    {
        $term = trim((string)$term);
        if (empty($term) || empty(static::$searchable)) {
            return $query;
        }

        $escaped = $this->escapeLikeValue($term);
        $like = "%{$escaped}%";

        return $query->where(function(QueryBuilder $q) use ($like) {
            foreach (static::$searchable as $index => $column) {
                if ($index === 0) {
                    $q->where($column, 'LIKE', $like);
                } else {
                    $q->orWhere($column, 'LIKE', $like);
                }
            }
        });
    }

    /**
     * Escape LIKE wildcards to prevent injection
     */
    protected function escapeLikeValue(string $value, int $maxLength = 100): string
    {
        $value = trim($value);
        
        if (strlen($value) > $maxLength) {
            throw new \InvalidArgumentException("Search term exceeds {$maxLength} characters");
        }
        
        return addcslashes($value, '%_');
    }
    
    /**
     * Chunked update helper
     */
    protected function chunkedUpdate(
        string $sql, 
        array $params, 
        int $chunkSize = 1000,
        int $maxIterations = 100
    ): int {
        $totalAffected = 0;
        
        for ($i = 0; $i < $maxIterations; $i++) {
            $stmt = $this->db->prepare($sql . " LIMIT ?");
            // Bind parameters plus the chunkSize
            $allParams = array_merge($params, [$chunkSize]);
            
            // Execute using the statement wrapper with bound parameters
            $stmt->execute($allParams);
            $affected = $stmt->rowCount();
            $totalAffected += $affected;
            
            if ($affected < $chunkSize) {
                break;
            }
            
            usleep(50000); // 50ms delay
        }
        
        return $totalAffected;
    }
    
    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): array
    {
        return $this->db->table(static::$table)->paginate($perPage, $pageName, $page);
    }

    public function firstOrCreate(array $attributes, array $values = []): object
    {
        $query = $this->db->table(static::$table);
        foreach ($attributes as $key => $value) {
            $query->where($key, '=', $value);
        }
        $instance = $query->first();

        if ($instance) {
            return $instance;
        }

        try {
            $id = $this->create(array_merge($attributes, $values));
            return $this->find((int)$id);
        } catch (\PDOException $e) {
            // 23000: Integrity constraint violation (Duplicate entry)
            // Mitigates Race Conditions in high concurrency inserts
            if ($e->getCode() === '23000' || $e->getCode() == 23000) {
                $retryQuery = $this->db->table(static::$table);
                foreach ($attributes as $key => $value) {
                    $retryQuery->where($key, '=', $value);
                }
                return $retryQuery->first() ?: throw $e;
            }
            throw $e;
        }
    }

    public function updateOrCreate(array $attributes, array $values = []): object
    {
        $query = $this->db->table(static::$table);
        foreach ($attributes as $key => $value) {
            $query->where($key, '=', $value);
        }
        $instance = $query->first();

        if ($instance) {
            $this->update((int)$instance->id, $values);
            return $this->find((int)$instance->id);
        }

        try {
            $id = $this->create(array_merge($attributes, $values));
            return $this->find((int)$id);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' || $e->getCode() == 23000) {
                $retryQuery = $this->db->table(static::$table);
                foreach ($attributes as $key => $value) {
                    $retryQuery->where($key, '=', $value);
                }
                $newInstance = $retryQuery->first() ?: throw $e;
                $this->update((int)$newInstance->id, $values);
                return $this->find((int)$newInstance->id);
            }
            throw $e;
        }
    }

    /**
     * Validate integer ID
     */
    protected function validateId(int $id, string $field = 'id'): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException("Invalid {$field}: must be positive integer");
        }
    }
    
    /**
     * Validate date string
     */
    protected function validateDate(string $date, string $field = 'date'): void
    {
        $d = \DateTime::createFromFormat('Y-m-d H:i:s', $date);
        if (!$d || $d->format('Y-m-d H:i:s') !== $date) {
            throw new \InvalidArgumentException("Invalid {$field} format");
        }
    }
}

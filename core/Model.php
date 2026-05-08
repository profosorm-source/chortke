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
    protected static string $table = '';

    protected Database $db;

    public function __construct(Database $db)
    {
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

        return $affected >= 0;
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
        return $this->find($id) !== null;
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
}

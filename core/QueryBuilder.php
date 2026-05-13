<?php

declare(strict_types=1);
namespace Core;

/**
 * Query Builder
 * 
 * ساخت Query به صورت شیء‌گرا
 */
class QueryBuilder
{
    private $pdo;
    private $table;
    private $select = ['*'];
    private $where = [];
    private $bindings = [];
    private $orderBy = [];
    private $groupBy = [];
    private $limit;
    private $offset;
    private $join = [];
    private $forUpdate = false;
    private $distinct = false;
    
    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Validate نام جدول برای جلوگیری از SQL Injection
     */
    private function validateTableName($table)
    {
        if (!preg_match('/^[a-zA-Z0-9_]+(?:\s+(?:as\s+)?[a-zA-Z0-9_]+)?$/i', $table)) {
            throw new \InvalidArgumentException("نام جدول غیرمجاز: {$table}");
        }
        return $table;
    }

    /**
     * Validate نام ستون برای جلوگیری از SQL Injection
     */
    private function validateColumnName($column)
    {
        if (!preg_match('/^[a-zA-Z0-9_.*\'"()]+(?:\s+(?:as\s+)?[a-zA-Z0-9_]+)?$/i', $column)) {
            throw new \InvalidArgumentException("نام ستون غیرمجاز: {$column}");
        }
        return $column;
    }

    /**
     * تنظیم جدول
     */
    public function table($table)
    {
        $this->table = $this->validateTableName($table);
        $this->reset();
        return $this;
    }

    /**
     * انتخاب ستون‌ها
     */
    public function select(...$columns)
    {
        // اگر یکی از columns آرایه بود (مثل ['col1', 'col2'])
        if (count($columns) === 1 && is_array($columns[0])) {
            $columns = $columns[0];
        }
        
        // Validate هر ستون
        foreach ($columns as $column) {
            $this->validateColumnName($column);
        }
        
        $this->select = $columns;
        return $this;
    }

    /**
     * شرط WHERE
     */
    public function where($column, $operator = '=', $value = null)
    {
        if ($column instanceof \Closure) {
            $this->where[] = [
                'type' => 'AND',
                'column' => $column,
                'operator' => 'NESTED',
                'value' => null
            ];
            return $this;
        }

        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => $operator,
            'value' => $value
        ];
        
        return $this;
    }

    /**
     * شرط OR WHERE
     */
    public function orWhere($column, $operator = '=', $value = null)
    {
        if ($column instanceof \Closure) {
            $this->where[] = [
                'type' => 'OR',
                'column' => $column,
                'operator' => 'NESTED',
                'value' => null
            ];
            return $this;
        }

        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->where[] = [
            'type' => 'OR',
            'column' => $column,
            'operator' => $operator,
            'value' => $value
        ];
        
        return $this;
    }

    /**
     * WHERE IN
     */
    public function whereIn($column, array $values)
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'IN',
            'value' => $values
        ];
        
        return $this;
    }

    /**
     * WHERE NULL
     */
    public function whereNull($column)
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'IS NULL',
            'value' => null
        ];
        
        return $this;
    }

    /**
     * WHERE NOT NULL
     */
    public function whereNotNull($column)
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'IS NOT NULL',
            'value' => null
        ];
        
        return $this;
    }

    /**
     * JOIN
     */
    public function join($table, $first, $operator, $second)
    {
        $this->join[] = [
            'type' => 'INNER',
            'table' => $this->validateTableName($table),
            'first' => $this->validateColumnName($first),
            'operator' => $this->validateOperator($operator),
            'second' => $this->validateColumnName($second)
        ];
        
        return $this;
    }

    /**
     * LEFT JOIN
     */
    public function leftJoin($table, $first, $operator, $second)
    {
        $this->join[] = [
            'type' => 'LEFT',
            'table' => $this->validateTableName($table),
            'first' => $this->validateColumnName($first),
            'operator' => $this->validateOperator($operator),
            'second' => $this->validateColumnName($second)
        ];
        
        return $this;
    }

    /**
     * Validate عملگر برای جلوگیری از SQL Injection
     */
    private function validateOperator($operator)
    {
        $allowedOps = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL'];
        $op = strtoupper($operator);
        if (!in_array($op, $allowedOps, true)) {
            throw new \InvalidArgumentException("عملگر غیرمجاز: {$operator}");
        }
        return $op;
    }

    /**
     * ORDER BY
     */
    public function orderBy($column, $direction = 'ASC')
    {
        // جلوگیری از SQL Injection: فقط کاراکترهای مجاز در نام ستون
        $this->validateColumnName($column);
        
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'ASC';
        }
        $this->orderBy[] = [$column, $direction];
        return $this;
    }

    /**
     * LIMIT
     */
    public function limit($limit)
    {
        if (!is_int($limit) || $limit <= 0) {
            throw new \InvalidArgumentException("LIMIT باید عدد مثبت باشد");
        }
        $this->limit = $limit;
        return $this;
    }

    /**
     * OFFSET
     */
    public function offset($offset)
    {
        if (!is_int($offset) || $offset < 0) {
            throw new \InvalidArgumentException("OFFSET باید عدد غیرمنفی باشد");
        }
        $this->offset = $offset;
        return $this;
    }

    /**
     * قفل کردن ردیف برای UPDATE (برای تراکنش‌های حساس مالی)
     * استفاده: ->where('id', $id)->lockForUpdate()->first()
     */
    public function lockForUpdate()
    {
        $this->forUpdate = true;
        return $this;
    }

    /**
     * GROUP BY - برای دسته‌بندی نتایج
     */
    public function groupBy($column)
    {
        if (is_array($column)) {
            foreach ($column as $col) {
                $this->validateColumnName($col);
                $this->groupBy[] = $col;
            }
        } else {
            $this->validateColumnName($column);
            $this->groupBy[] = $column;
        }
        return $this;
    }

    /**
     * WHERE NOT IN - برای استثناء مقادیر از نتایج
     */
    public function whereNotIn($column, array $values)
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'NOT IN',
            'value' => $values
        ];
        return $this;
    }

    /**
     * افزایش ستون عددی
     * مثال: ->where('id', $id)->increment('visits', 5)
     */
    public function increment($column, $value = 1)
    {
        $this->validateColumnName($column);
        
        if (empty($this->table)) {
            throw new \Exception('No table selected for update.');
        }
        if (empty($this->where)) {
            throw new \Exception('Cannot increment without WHERE clause.');
        }
        
        $sets = ["`{$column}` = `{$column}` + ?"];
        $bindings = [(int)$value];
        
        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $sets);
        $sql .= $this->buildWhereClause($bindings);
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            return (bool)$stmt->rowCount();
        } catch (\PDOException $e) {
            try {
                if (function_exists('logger')) {
                    logger()->error('database.increment.failed', [
                        'channel' => 'database',
                        'sql' => $sql ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            } catch (\Throwable $logError) {
                error_log('QueryBuilder increment failed: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * میانگین ستون
     * مثال: ->where('user_id', $id)->avg('score')
     */
    public function avg($column)
    {
        $this->validateColumnName($column);
        
        $originalSelect = $this->select;
        $originalLimit = $this->limit;
        
        $this->select = ["AVG(`{$column}`) as avg"];
        $this->limit = null;
        
        $sql = $this->buildSelectQuery();
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->bindings);
            $result = $stmt->fetch(\PDO::FETCH_OBJ);
            return (float)($result->avg ?? 0);
        } finally {
            $this->select = $originalSelect;
            $this->limit = $originalLimit;
        }
    }

    /**
     * DISTINCT - برای دریافت رکوردهای منحصربه‌فرد
     * مثال: ->selectRaw('DISTINCT country')->get()
     */
    public function distinct()
    {
        $this->distinct = true;
        return $this;
    }

    /**
     * دریافت همه رکوردها
     */
    public function get()
    {
        $sql = $this->buildSelectQuery();
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->bindings);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            // ✅ Safe logging
            try {
                if (function_exists('logger')) {
                    logger()->error('database.builder.query.failed', [
                        'channel' => 'database',
                        'sql' => $sql ?? null,
                        'bindings' => $this->bindings ?? [],
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);
                }
            } catch (\Throwable $logError) {
                error_log('QueryBuilder query failed: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * دریافت اولین رکورد
     */
    public function first()
{
    $this->limit = 1;
    $results = $this->get();
    
    if (empty($results)) {
        return null;
    }
    
    // تبدیل آرایه به Object
    return (object) $results[0];
}

    /**
     * دریافت با ID
     */
    public function find($id)
    {
        return $this->where('id', $id)->first();
    }

    /**
     * شمارش
     */
    public function count()
    {
        // FIX C-4: count() مقدار select و limit را ذخیره می‌کند،
        // سپس بعد از اتمام کار آن‌ها را بازیابی می‌کند.
        // قبلاً first() صدا زده می‌شد که limit را به 1 تبدیل می‌کرد
        // و بعد از بازیابی select، limit همچنان 1 باقی می‌ماند.
        $originalSelect = $this->select;
        $originalLimit  = $this->limit;

        $this->select = ['COUNT(*) as count'];
        $this->limit  = null;

        $sql = $this->buildSelectQuery();

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->bindings);
            $result = $stmt->fetch(\PDO::FETCH_OBJ);
        } catch (\PDOException $e) {
            $this->select = $originalSelect;
            $this->limit  = $originalLimit;
            throw $e;
        }

        $this->select = $originalSelect;
        $this->limit  = $originalLimit;

        return (int)($result->count ?? 0);
    }

    /**
     * INSERT
     */
    public function insert(array $data)
{
    if (empty($this->table)) {
        throw new \Exception('No table selected for insert.');
    }
    if (empty($data)) {
        throw new \Exception('Insert data is empty.');
    }

    $columns = \array_keys($data);
    $values  = \array_values($data);

    // Validate هر ستون
    foreach ($columns as $column) {
        $this->validateColumnName($column);
    }

    $placeholders = \array_fill(0, \count($columns), '?');

    // بک‌تیک برای ستون‌ها (ایمن‌تر)
    $colsSql = '`' . \implode('`,`', $columns) . '`';

    // بک‌تیک برای نام جدول (فرض: table از داخل سیستم set شده)
    $sql = "INSERT INTO `{$this->table}` ({$colsSql}) VALUES (" . \implode(',', $placeholders) . ")";

    try {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    } catch (\PDOException $e) {
        // ✅ Safe logging
        try {
            if (function_exists('logger')) {
                logger()->error('database.insert.failed', [
                    'channel' => 'database',
                    'sql' => $sql ?? null,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        } catch (\Throwable $logError) {
            error_log('QueryBuilder insert failed: ' . $e->getMessage());
        }
        throw $e;
    }

    // تلاش برای گرفتن ID
    $id = $this->pdo->lastInsertId();

    // اگر عددی بود برگردان (برای اینکه create بتواند find کند)
    if ($id !== '' && \ctype_digit((string)$id)) {
        return (int)$id;
    }

    // اگر جدول auto-inc ندارد
    return true;
}

    /**
     * UPDATE
     */
    public function update(array $data)
    {
        $sets = [];
        $bindings = [];

        foreach ($data as $column => $value) {
            // رفع باگ #20: sanitize نام ستون برای جلوگیری از SQL Injection
            $this->validateColumnName($column);
            $sets[] = "`{$column}` = ?";
            $bindings[] = $value;
        }
        
        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $sets);
        
        if (!empty($this->where)) {
            $sql .= $this->buildWhereClause($bindings);
        }
        
        if ($this->limit !== null) {
            $sql .= " LIMIT " . (int)$this->limit;
        }
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            // ✅ Safe logging
            try {
                if (function_exists('logger')) {
                    logger()->error('database.update.failed', [
                        'channel' => 'database',
                        'sql' => $sql ?? null,
                        'data' => $data ?? [],
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);
                }
            } catch (\Throwable $logError) {
                error_log('QueryBuilder update failed: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * DELETE - باید حداقل یک WHERE clause وجود داشته باشد
     */
    public function delete()
    {
        // جلوگیری از DELETE بدون WHERE (حذف تمام رکوردها)
        if (empty($this->where)) {
            throw new \Exception('DELETE بدون WHERE clause مجاز نیست. برای حذف تمام رکوردها از: DB::table("users")->where("1", "=", "1")->delete()');
        }

        $sql = "DELETE FROM `{$this->table}`";
        $bindings = [];
        
        if (!empty($this->where)) {
            $sql .= $this->buildWhereClause($bindings);
        }
        
        if ($this->limit !== null) {
            $sql .= " LIMIT " . (int)$this->limit;
        }
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            // ✅ Safe logging
            try {
                if (function_exists('logger')) {
                    logger()->error('database.delete.failed', [
                        'channel' => 'database',
                        'sql' => $sql ?? null,
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);
                }
            } catch (\Throwable $logError) {
                error_log('QueryBuilder delete failed: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * ساخت SELECT Query
     */
    private function buildSelectQuery()
    {
        // استفاده از backticks برای جلوگیری از SQL Injection
        $selectCols = implode(', ', array_map(function($col) {
            if (strpos($col, '*') !== false) {
                return $col;
            }
            $aliasParts = preg_split('/\s+as\s+/i', $col);
            if (count($aliasParts) === 1) {
                $aliasParts = preg_split('/\s+/', $col);
            }
            
            $mainCol = trim($aliasParts[0]);
            $alias = isset($aliasParts[1]) ? trim($aliasParts[1]) : null;

            if (strpos($mainCol, '.') !== false) {
                $parts = explode('.', $mainCol);
                $wrappedMain = '`' . trim($parts[0]) . '`.`' . trim($parts[1]) . '`';
            } else {
                $wrappedMain = '`' . $mainCol . '`';
            }

            if ($alias) {
                return $wrappedMain . ' as `' . $alias . '`';
            }
            return $wrappedMain;
        }, $this->select));

        $tableSql = $this->table;
        if (strpos($tableSql, ' ') !== false) {
            $parts = preg_split('/\s+/', $tableSql);
            if (count($parts) === 3 && strtolower($parts[1]) === 'as') {
                $tableSql = "`" . $parts[0] . "` as `" . $parts[2] . "`";
            } elseif (count($parts) === 2) {
                $tableSql = "`" . $parts[0] . "` as `" . $parts[1] . "`";
            }
        } else {
            $tableSql = "`{$tableSql}`";
        }

        $selectClause = $this->distinct ? "DISTINCT {$selectCols}" : $selectCols;
        $sql = "SELECT {$selectClause} FROM {$tableSql}";
        
        // JOIN
        if (!empty($this->join)) {
            foreach ($this->join as $join) {
                $joinTable = $join['table'];
                if (strpos($joinTable, ' ') !== false) {
                    $parts = preg_split('/\s+/', $joinTable);
                    if (count($parts) === 3 && strtolower($parts[1]) === 'as') {
                        $joinTable = "`" . $parts[0] . "` as `" . $parts[2] . "`";
                    } elseif (count($parts) === 2) {
                        $joinTable = "`" . $parts[0] . "` as `" . $parts[1] . "`";
                    }
                } else {
                    $joinTable = "`{$joinTable}`";
                }
                $sql .= " {$join['type']} JOIN {$joinTable} ON {$join['first']} {$join['operator']} {$join['second']}";
            }
        }
        
        // WHERE
        if (!empty($this->where)) {
            $sql .= $this->buildWhereClause($this->bindings);
        }
        
        // ORDER BY
        if (!empty($this->orderBy)) {
            $sql .= " ORDER BY ";
            $orders = [];
            foreach ($this->orderBy as $order) {
                // اضافه کردن backticks برای ستون
                $col = strpos($order[0], '.') !== false 
                    ? str_replace('.', '`.`', '`' . $order[0] . '`')
                    : '`' . $order[0] . '`';
                $orders[] = "{$col} {$order[1]}";
            }
            $sql .= implode(', ', $orders);
        }
        
        // LIMIT
        if ($this->limit !== null) {
            $sql .= " LIMIT " . (int)$this->limit;
        }
        
        // OFFSET
        if ($this->offset !== null) {
            $sql .= " OFFSET " . (int)$this->offset;
        }
        
        // FOR UPDATE (قفل برای تراکنش‌ها)
        if ($this->forUpdate) {
            $sql .= " FOR UPDATE";
        }
        return $sql;
    }

    /**
     * ساخت SELECT Query (بدون DISTINCT)
     */
    private function buildSelectQuerySimple()
    {
        return $this->buildSelectQuery();
    }

    /**
     * ساخت WHERE Clause
     */
    private function buildWhereClause(&$bindings)
    {
        $sql = " WHERE ";
        $conditions = [];
        
        foreach ($this->where as $index => $condition) {
            $type = $index === 0 ? '' : " {$condition['type']} ";
            
            if ($condition['operator'] === 'NESTED') {
                $subBuilder = new QueryBuilder($this->pdo);
                $subBuilder->table = $this->table;
                $condition['column']($subBuilder);
                
                if (!empty($subBuilder->where)) {
                    $subBindings = [];
                    $subSql = $subBuilder->buildWhereClause($subBindings);
                    $subSql = substr($subSql, 7); // Remove " WHERE "
                    $conditions[] = $type . "({$subSql})";
                    $bindings = array_merge($bindings, $subBindings);
                }
                continue;
            }

            // رفع باگ #20: sanitize نام ستون در WHERE clause
            $col = $condition['column'];
            $this->validateColumnName($col);
            
            // sanitize operator - استفاده از method موجود
            $op = $this->validateOperator($condition['operator']);
            
            // اضافه کردن backticks برای ستون
            if (strpos($col, '.') !== false) {
                $col = str_replace('.', '`.`', '`' . $col . '`');
            } else {
                $col = '`' . $col . '`';
            }
            
            // Handle null values - convert = NULL to IS NULL and != NULL to IS NOT NULL
            if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
                $conditions[] = $type . "{$col} {$op}";
            } elseif ($condition['value'] === null) {
                if ($op === '=') {
                    $conditions[] = $type . "{$col} IS NULL";
                } elseif ($op === '!=' || $op === '<>') {
                    $conditions[] = $type . "{$col} IS NOT NULL";
                } else {
                    // For other operators with null, just skip binding
                    $conditions[] = $type . "{$col} {$op} ?";
                    $bindings[] = $condition['value'];
                }
            } elseif ($op === 'IN') {
                $placeholders = array_fill(0, count($condition['value']), '?');
                $conditions[] = $type . "{$col} IN (" . implode(', ', $placeholders) . ")";
                $bindings = array_merge($bindings, $condition['value']);
            } elseif ($op === 'NOT IN') {
                $placeholders = array_fill(0, count($condition['value']), '?');
                $conditions[] = $type . "{$col} NOT IN (" . implode(', ', $placeholders) . ")";
                $bindings = array_merge($bindings, $condition['value']);
            } else {
                $conditions[] = $type . "{$col} {$op} ?";
                $bindings[] = $condition['value'];
            }
        }
        
        $sql .= implode('', $conditions);
        
        return $sql;
    }

    /**
     * Reset کردن Query
     */
    private function reset()
    {
        $this->select = ['*'];
        $this->where = [];
        $this->bindings = [];
        $this->orderBy = [];
        $this->groupBy = [];
        $this->limit = null;
        $this->offset = null;
        $this->join = [];
        $this->forUpdate = false;
        $this->distinct = false;
    }
}
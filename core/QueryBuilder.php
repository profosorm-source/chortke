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
    private $limit;
    private $offset;
    private $join = [];
    
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
        $column = trim((string)$column);
        if ($column === '*') {
            return $column;
        }

        // فرمت مجاز: شناسه، شناسه.شناسه یا شناسه.*، به همراه نام مستعار (AS alias) به صورت اختیاری
        // این الگو از عبور کراکترهایی مثل پرانتز، تک‌کتیشن و سایر علائم خطرناک در متد استاندارد select جلوگیری می‌کند.
        $pattern = '/^[a-zA-Z_][a-zA-Z0-9_]*(\.(\*|[a-zA-Z_][a-zA-Z0-9_]*))?(\s+as\s+[a-zA-Z_][a-zA-Z0-9_]*)?$/i';
        
        if (!preg_match($pattern, $column)) {
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
     * انتخاب به صورت Raw
     * 
     * ⚠️ Fix M5: مستندسازی سختگیرانه برای selectRaw
     * 
     * این متد خطرناک است و باید **فقط برای موارد خاصی** استفاده شود.
     * 
     * ✅ استفاده صحیح:
     * $builder->selectRaw('COUNT(*) as total')  // توابع aggregate
     * $builder->selectRaw('YEAR(created_at) as year')  // توابع تاریخی
     * $builder->selectRaw('CONCAT(first_name, " ", last_name) as full_name')  // محاسبات ستون‌ها
     * 
     * ❌ استفاده نادرست (خطرناک):
     * $builder->selectRaw($userInput)  // هرگز از input کاربر استفاده نکنید!
     * $builder->selectRaw('* FROM users; DROP TABLE users;--')  // SQL Injection
     * 
     * @param string $expression فقط از مقادیر hard-coded استفاده کنید
     * @return $this
     */
    public function selectRaw($expression)
    {
        // این مقدار بدون validation مستقیم به select اضافه می‌شود
        // buildSelectQuery وظیفه هندل کردن استثناهای آن را دارد
        $this->select[] = $expression;
        return $this;
    }

    /**
     * شرط WHERE به صورت Raw
     * 
     * ⚠️ Fix M5: مستندسازی سختگیرانه برای whereRaw
     * 
     * این متد ریسک SQL Injection ایجاد می‌کند اگر از input کاربر استفاده کنید!
     * 
     * ✅ استفاده صحیح:
     * $builder->whereRaw('YEAR(created_at) = ?', [2024])  // توابع تاریخی
     * $builder->whereRaw('amount > salary * 1.5')  // مقایسه‌های پیچیده
     * $builder->whereRaw('JSON_EXTRACT(data, "$.role") = ?', ['admin'])  // JSON queries
     * 
     * ❌ استفاده نادرست (خطرناک):
     * $builder->whereRaw("name = '" . $userName . "'")  // SQL Injection!
     * $builder->whereRaw("id = $userId OR 1=1")  // تاثیر منطق
     * $builder->whereRaw($userProvidedFilter)  // هیچگاه نپذیرید!
     * 
     * @param string $sql فقط از SQL hard-coded استفاده کنید
     * @param array $bindings placeholder مقادیر برای ایمن‌سازی
     * @return $this
     */
    public function whereRaw($sql, array $bindings = [])
    {
        $this->where[] = [
            'type' => 'AND',
            'operator' => 'RAW',
            'sql' => $sql,
            'bindings' => $bindings
        ];
        return $this;
    }

    /**
     * مرتب‌سازی به صورت Raw
     * 
     * ⚠️ Fix M5: مستندسازی سختگیرانه برای orderByRaw
     * 
     * این متد نیز ریسک SQL Injection دارد.
     * 
     * ✅ استفاده صحیح:
     * $builder->orderByRaw('RAND()')  // ترتیب تصادفی
     * $builder->orderByRaw('FIELD(status, "pending", "active", "completed")')  // ترتیب سفارشی
     * $builder->orderByRaw('ABS(amount) DESC')  // ترتیب محاسبه‌شده
     * 
     * ❌ استفاده نادرست:
     * $builder->orderByRaw($userInput)  // خطرناک!
     * $builder->orderByRaw("id; DROP TABLE users;--")  // SQL Injection
     * 
     * @param string $sql فقط از SQL hard-coded استفاده کنید
     * @return $this
     */
    public function orderByRaw($sql)
    {
        $this->orderBy[] = ['RAW', $sql];
        return $this;
    }

    /**
     * Utility helper: قرار دادن Backtick دور نام ستون
     */
    private function wrapColumn(string $column): string
    {
        $column = trim($column);
        if ($column === '*') {
            return '*';
        }
        
        if (strpos($column, '.') !== false) {
            $parts = explode('.', $column);
            $wrapped = array_map(function($p) {
                $p = trim($p);
                return ($p === '*') ? '*' : "`{$p}`";
            }, $parts);
            return implode('.', $wrapped);
        }
        
        return "`{$column}`";
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
        $allowedOps = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'IS NULL', 'IS NOT NULL'];
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

        // M8 Fix: تضمین ۱۰۰ درصدی بازیابی وضعیت شیء با استفاده از الگوی طلایی try...finally
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->bindings);
            $result = $stmt->fetch(\PDO::FETCH_OBJ);
            return (int)($result->count ?? 0);
        } finally {
            $this->select = $originalSelect;
            $this->limit  = $originalLimit;
        }
    }

    /**
     * صفحه‌بندی نتایج (Pagination)
     */
    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): array
    {
        if ($page === null) {
            $page = (int)($_GET[$pageName] ?? 1);
        }
        if ($page <= 0) {
            $page = 1;
        }

        $total = $this->count();
        
        $originalLimit  = $this->limit;
        $originalOffset = $this->offset;

        try {
            $this->limit($perPage);
            $this->offset(($page - 1) * $perPage);
            $items = $this->get();
        } finally {
            $this->limit  = $originalLimit;
            $this->offset = $originalOffset;
        }

        return [
            'data' => $items,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int)ceil($total / $perPage),
        ];
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
            $col = (string)$col;
            
            // تقسیم به بخش اصلی و نام مستعار (AS)
            $aliasParts = preg_split('/\s+as\s+/i', $col);
            if (count($aliasParts) === 1) {
                // فقط اگر پرانتز نداشته باشد (برای جلوگیری از شکستن توابع مثل DATE(x) y)
                if (!str_contains($col, '(') && !str_contains($col, ')')) {
                    $aliasParts = preg_split('/\s+/', $col);
                }
            }
            
            $mainCol = trim($aliasParts[0]);
            $alias = isset($aliasParts[1]) ? trim($aliasParts[1]) : null;

            // اگر شامل توابع (پرانتز) یا اعمال محاسباتی باشد، به صورت خام باقی می‌ماند و بک‌تیک نمی‌خورد
            if (preg_match('/[\(\)\+\-\/]/', $mainCol)) {
                $wrappedMain = $mainCol;
            } else {
                // استفاده از متد ایمن wrapColumn که برای فیلدهای استاندارد و .* آماده است
                $wrappedMain = $this->wrapColumn($mainCol);
            }

            return $alias ? "{$wrappedMain} as `{$alias}`" : $wrappedMain;
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

        $sql = "SELECT {$selectCols} FROM {$tableSql}";
        
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
                $first = $this->wrapColumn($join['first']);
                $second = $this->wrapColumn($join['second']);
                $sql .= " {$join['type']} JOIN {$joinTable} ON {$first} {$join['operator']} {$second}";
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
                if ($order[0] === 'RAW') {
                    $orders[] = $order[1];
                } else {
                    $col = $this->wrapColumn($order[0]);
                    $orders[] = "{$col} {$order[1]}";
                }
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
        
        return $sql;
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
        $this->limit = null;
        $this->offset = null;
        $this->join = [];
    }
}
<?php
namespace Core;

/**
 * Blueprint
 * 
 * تعریف ساختار جدول
 */
class Blueprint
{
    private $table;
    private $columns = [];
    private $indexes = [];

    public function __construct($table)
    {
        $this->table = $table;
    }

    /**
     * ID (Auto Increment)
     */
    public function id($name = 'id')
    {
        return $this->bigIncrements($name);
    }

    /**
     * Big Integer Auto Increment
     */
    public function bigIncrements($name)
    {
        $this->columns[] = "`{$name}` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY";
        return $this;
    }

    /**
     * String
     */
    public function string($name, $length = 255)
    {
        $this->columns[] = "`{$name}` VARCHAR({$length})";
        return $this;
    }

    /**
     * Text
     */
    public function text($name)
    {
        $this->columns[] = "`{$name}` TEXT";
        return $this;
    }

    /**
     * Integer
     */
    public function integer($name)
    {
        $this->columns[] = "`{$name}` INT";
        return $this;
    }

    /**
     * Big Integer
     */
    public function bigInteger($name)
    {
        $this->columns[] = "`{$name}` BIGINT";
        return $this;
    }

    /**
     * Decimal
     */
    public function decimal($name, $precision = 8, $scale = 2)
    {
        $this->columns[] = "`{$name}` DECIMAL({$precision}, {$scale})";
        return $this;
    }

    /**
     * Boolean
     */
    public function boolean($name)
    {
        $this->columns[] = "`{$name}` TINYINT(1) DEFAULT 0";
        return $this;
    }

    /**
     * Date
     */
    public function date($name)
    {
        $this->columns[] = "`{$name}` DATE";
        return $this;
    }

    /**
     * DateTime
     */
    public function dateTime($name)
    {
        $this->columns[] = "`{$name}` DATETIME";
        return $this;
    }

    /**
     * Timestamp
     */
    public function timestamp($name)
    {
        $this->columns[] = "`{$name}` TIMESTAMP";
        return $this;
    }

    /**
     * Timestamps (created_at, updated_at)
     */
    public function timestamps()
    {
        $this->columns[] = "created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
        $this->columns[] = "updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
        return $this;
    }

    /**
     * Soft Deletes
     */
    public function softDeletes()
    {
        $this->columns[] = "deleted_at TIMESTAMP NULL";
        return $this;
    }

    /**
     * Enum
     */
    public function enum($name, array $values)
    {
        $valuesStr = "'" . implode("','", $values) . "'";
        $this->columns[] = "`{$name}` ENUM({$valuesStr})";
        return $this;
    }

    /**
     * Foreign Key
     */
    public function foreignId($name)
    {
        $this->columns[] = "`{$name}` BIGINT UNSIGNED";
        return $this;
    }

    /**
     * Nullable
     */
    public function nullable()
    {
        $lastIndex = count($this->columns) - 1;
        $this->columns[$lastIndex] .= " NULL";
        return $this;
    }

    /**
     * Default
     */
    public function default($value)
    {
        $lastIndex = count($this->columns) - 1;
        
        if (is_string($value)) {
            $value = "'{$value}'";
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif ($value === null) {
            $value = 'NULL';
        }
        
        $this->columns[$lastIndex] .= " DEFAULT {$value}";
        return $this;
    }

    /**
     * Unique
     */
    public function unique()
    {
        $lastIndex = count($this->columns) - 1;
        $this->columns[$lastIndex] .= " UNIQUE";
        return $this;
    }

    /**
     * Index
     */
    public function index($columns)
    {
        if (!is_array($columns)) {
            $columns = [$columns];
        }
        
        $this->indexes[] = "INDEX (" . implode(', ', $columns) . ")";
        return $this;
    }

    /**
     * تبدیل به SQL
     */
    public function toSql($type = 'create')
    {
        if ($type === 'create') {
            $sql = "CREATE TABLE IF NOT EXISTS `{$this->table}` (\n";
            $sql .= "  " . implode(",\n  ", $this->columns);
            
            if (!empty($this->indexes)) {
                $sql .= ",\n  " . implode(",\n  ", $this->indexes);
            }
            
            $sql .= "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            return $sql;
        }
        
        if ($type === 'alter') {
            $sql = "ALTER TABLE `{$this->table}`\n";
            $sql .= "  ADD COLUMN " . implode(",\n  ADD COLUMN ", $this->columns);
            
            return $sql;
        }
        
        throw new \Exception("Unknown SQL type: {$type}");
    }
}
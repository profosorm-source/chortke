<?php
namespace Core;

/**
 * Schema Builder
 * 
 * ساخت و مدیریت ساختار جداول
 */
class Schema
{
    private static $db;

    /**
     * ایجاد جدول
     */
    public static function create($table, callable $callback)
    {
        self::$db = Database::getInstance();
        
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        
        $sql = $blueprint->toSql('create');
        
        try {
            self::$db->query($sql);
            logger()->info('migration.table.created', [
                'channel' => 'database',
                'table' => $table,
            ]);
        } catch (\Exception $e) {
            logger()->error('migration.table.create.failed', [
                'channel' => 'database',
                'table' => $table,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
    }

    /**
     * بررسی وجود جدول
     */
    public static function hasTable($table)
    {
        self::$db = Database::getInstance();
        
        $sql = "SHOW TABLES LIKE ?";
        $result = self::$db->select($sql, [$table]);
        
        return !empty($result);
    }

    /**
     * حذف جدول
     */
    public static function drop($table)
    {
        self::$db = Database::getInstance();
        
        $sql = "DROP TABLE IF EXISTS {$table}";
        
        try {
            self::$db->query($sql);
            logger()->info('migration.table.dropped', [
                'channel' => 'database',
                'table' => $table,
            ]);
        } catch (\Exception $e) {
            logger()->error('migration.table.drop.failed', [
                'channel' => 'database',
                'table' => $table,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
    }

    /**
     * ویرایش جدول
     */
    public static function table($table, callable $callback)
    {
        self::$db = Database::getInstance();
        
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        
        $sql = $blueprint->toSql('alter');
        
        try {
            self::$db->query($sql);
            logger()->info('migration.table.altered', [
                'channel' => 'database',
                'table' => $table,
            ]);
        } catch (\Exception $e) {
            logger()->error('migration.table.alter.failed', [
                'channel' => 'database',
                'table' => $table,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
    }
}
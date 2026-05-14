<?php

declare(strict_types=1);

namespace App\Traits;

use Core\QueryBuilder;

/**
 * Filterable Trait
 * Provides dynamic multi-column filtration architecture for standard Models.
 */
trait Filterable
{
    /**
     * Automatically applies defined structural filters to the provided QueryBuilder.
     *
     * Uses the Model's self-defined `protected static array $filterable` property.
     * Expected pattern for $filterable map:
     * [
     *    'status' => '=', 
     *    'min_price' => ['price_usdt', '>='], 
     *    'category' => 'IN'
     * ]
     */
    public function applyFilters(QueryBuilder $query, array $filters): QueryBuilder
    {
        // 1. Use static trait-ready mapping or fallback gracefully.
        // Using property_exists ensures stability if not set explicitly on model.
        if (!property_exists(static::class, 'filterable') || empty(static::$filterable)) {
            return $query;
        }

        foreach ($filters as $key => $value) {
            // Ensure value is not blank/null to prevent empty where clauses
            if ($value === null || $value === '') {
                continue;
            }

            // Check if this query key is explicitly defined for filtering in the model
            if (!isset(static::$filterable[$key])) {
                continue;
            }

            $rule = static::$filterable[$key];

            // Determine target column and comparator operator
            if (is_array($rule)) {
                // Custom mapping (e.g., ['reward', '>='])
                $targetColumn = $rule[0];
                $operator = strtoupper($rule[1]);
            } else {
                // Direct key mapping (e.g., 'status' => '=')
                $targetColumn = $key;
                $operator = strtoupper($rule);
            }

            // Security context: ensure values are clean. Standard casting by DB driver.
            $this->applyFilterRule($query, $targetColumn, $operator, $value);
        }

        return $query;
    }

    /**
     * Applies the exact WHERE clause based on operator type.
     */
    private function applyFilterRule(QueryBuilder $query, string $column, string $operator, mixed $value): void
    {
        switch ($operator) {
            case '=':
            case '!=':
            case '>':
            case '<':
            case '>=':
            case '<=':
                $query->where($column, $operator, $value);
                break;

            case 'LIKE':
                // Optional explicit prefixing helper could go here
                $escaped = addcslashes((string)$value, '%_');
                $query->where($column, 'LIKE', "%{$escaped}%");
                break;

            case 'IN':
                // Ensure array format
                $vals = is_array($value) ? $value : array_map('trim', explode(',', (string)$value));
                $query->whereIn($column, $vals);
                break;

            case 'BETWEEN':
                // Only apply if exactly 2 items exist
                if (is_array($value) && count($value) === 2) {
                    $query->where($column, '>=', $value[0])
                          ->where($column, '<=', $value[1]);
                }
                break;
        }
    }
}

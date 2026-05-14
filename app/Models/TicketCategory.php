<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * TicketCategory Model
 */
class TicketCategory extends Model
{
    protected static string $table = 'ticket_categories';

    /**
     * دریافت همه دسته‌ها
     */
    public function getAll(): array
    {
        return $this->db->table(static::$table)
            ->where('is_active', '=', 1)
            ->orderBy('display_order', 'ASC')
            ->get();
    }
}
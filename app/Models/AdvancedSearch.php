<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * AdvancedSearch Model - Secured against LIKE Injections
 */
class AdvancedSearch extends Model
{
    protected static string $table = 'searches';

    /**
     * Escape and validate search query
     */
    private function sanitizeSearchQuery(string $q, int $maxLength = 100): string
    {
        return $this->escapeLikeValue($q, $maxLength);
    }






}

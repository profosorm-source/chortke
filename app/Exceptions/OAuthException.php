<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * OAuthException - OAuth سرویس میں خرابی
 * 
 * تمام OAuth/سوشل لاگ ان کی خرابیوں کے لیے
 * - Token invalid/expired
 * - User not found
 * - Insufficient permissions
 * - Account linking failed
 */
class OAuthException extends \Core\Exceptions\UnauthorizedException
{
    protected $code = 401;
    protected $message = 'OAuth authentication error';

    public function __construct(
        string $message = '',
        int $code = 401,
        ?string $provider = null,
        ?\Throwable $previous = null
    ) {
        $fullMessage = $message ?: $this->message;
        
        if ($provider) {
            $fullMessage = "OAuth error from [{$provider}]: {$fullMessage}";
        }
        
        parent::__construct($fullMessage, $code, $previous);
    }
}

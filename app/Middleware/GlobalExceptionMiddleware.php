<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Contracts\MiddlewareInterface;
use Core\Http\Request;
use Core\Http\Response;

class GlobalExceptionMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (\Throwable $e) {
            // Log the exception securely
            if (class_exists(\Core\ExceptionHandler::class)) {
                // Ensure ExceptionHandler logs the error correctly
                // In modern framework, we just log it and get the JSON payload.
                // For Chortke, we can call a new static method that returns the payload instead of echoing it.
                $payload = \Core\ExceptionHandler::getJsonPayloadForException($e);
            } else {
                $statusCode = 500;
                $payload = ['success' => false, 'message' => 'خطای سیستمی رخ داده است.', 'code' => $statusCode];
                if ($e instanceof \DomainException) {
                    $statusCode = 400;
                    $payload = ['success' => false, 'message' => $e->getMessage(), 'code' => $statusCode];
                }
            }

            $response = new Response();
            $response->setStatus($payload['code'] ?? 500);
            $response->json($payload);
            
            return $response;
        }
    }
}

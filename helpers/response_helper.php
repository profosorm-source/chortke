<?php

/**
 * توابع کمکی پاسخ (Response) و خطا
 */

if (!function_exists('json_response')) {
    function json_response(mixed $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Vary: Origin');

        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            echo $json;
        } catch (\JsonException $e) {
            $payload = [
                'success' => false,
                'message' => 'Internal Server Error: Invalid JSON structure',
            ];

            if (config('app.debug')) {
                $payload['error'] = $e->getMessage();
            }

            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        exit;
    }
}

if (!function_exists('abort')) {
    function abort($code = 404, string $message = ''): void
    {
        $statusCode = filter_var($code, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 100, 'max_range' => 599],
        ]);
        $statusCode = $statusCode === false ? 500 : $statusCode;

        http_response_code($statusCode);
        $errorPage = __DIR__ . '/../views/errors/' . $statusCode . '.php';

        if (file_exists($errorPage)) {
            require $errorPage;
        } else {
            echo "<h1>Error {$statusCode}</h1>";
            if ($message) {
                echo '<p>' . e($message) . '</p>';
            }
        }

        exit;
    }
}

if (!function_exists('is_ajax')) {
    function is_ajax(): bool
    {
        return (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }
}

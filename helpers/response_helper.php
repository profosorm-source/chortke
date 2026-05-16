<?php

/**
 * توابع کمکی پاسخ (Response) و خطا
 */

if (!function_exists('json_response')) {
    function json_response(mixed $data, int $statusCode = 200): void
    {
        app(\Core\Response::class)->json($data, $statusCode);
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

        $response = app(\Core\Response::class);
        $response->setStatusCode($statusCode);
        
        ob_start();
        $errorPage = __DIR__ . '/../views/errors/' . $statusCode . '.php';

        if (file_exists($errorPage)) {
            $data = ['message' => $message];
            extract($data, EXTR_SKIP);
            require $errorPage;
        } else {
            echo "<h1>Error {$statusCode}</h1>";
            if ($message) {
                echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
            }
        }
        
        $content = ob_get_clean();
        $response->setContent($content);
        $response->send();
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

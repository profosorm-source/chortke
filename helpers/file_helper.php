<?php

/**
 * توابع کمکی فایل و آپلود
 */

if (!function_exists('upload_file')) {
    function upload_file(array $file, string $directory = 'general'): string
    {
        // بررسی خطای آپلود
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception('خطا در آپلود فایل');
        }
        
        // بررسی سایز فایل (حداکثر 5MB)
        $maxSize = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $maxSize) {
            throw new \Exception('حجم فایل بیش از حد مجاز است (حداکثر 5MB)');
        }
        
        // بررسی Extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
        
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new \Exception('نوع فایل مجاز نیست');
        }
        
        // بررسی MIME Type واقعی
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $allowedMimeTypes = [
            'image/jpeg',
            'image/jpg', 
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf'
        ];
        
        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            throw new \Exception('نوع MIME فایل مجاز نیست');
        }
        
        // ساخت مسیر با base_path
        $basePath = dirname(__DIR__);
        $uploadPath = $basePath . '/public/uploads/' . $directory . '/';
        
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }
        
        // تولید نام فایل یکتا و امن
        $filename = bin2hex(random_bytes(16)) . '_' . time() . '.' . $extension;
        $destination = $uploadPath . $filename;
        
        // انتقال فایل
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new \Exception('خطا در ذخیره فایل');
        }
        
        return 'uploads/' . $directory . '/' . $filename;
    }
}

if (!function_exists('delete_file')) {
    function delete_file($path)
    {
        if (empty($path)) return false;
        $fullPath = __DIR__ . '/../public/' . ltrim($path, '/');
        if (file_exists($fullPath) && is_file($fullPath)) {
            return unlink($fullPath);
        }
        return false;
    }
}

if (!function_exists('safe_filename')) {
    function safe_filename(string $filename): string
    {
        $filename = basename($filename);
        $filename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $filename);
        if (strlen($filename) > 200) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $filename = substr($filename, 0, 195) . ($ext ? '.' . $ext : '');
        }
        return $filename;
    }
}

if (!function_exists('is_allowed_extension')) {
    function is_allowed_extension(string $filename, array $allowed = []): bool
    {
        if (empty($allowed)) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, $allowed, true);
    }
}

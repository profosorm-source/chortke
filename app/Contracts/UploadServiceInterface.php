<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * UploadServiceInterface — قرارداد سرویس آپلود فایل
 * 
 * این interface سرویس آپلود تصاویر و فایل‌ها را انتزاعی می‌کند و امکان:
 * - تست‌های واحد (Mock)
 * - پیاده‌سازی‌های جایگزین (S3, GCS, Azure)
 * - حاکمیت منطق آپلود
 * 
 * را فراهم می‌آورد.
 */
interface UploadServiceInterface
{
    /**
     * آپلود تصویر امن
     * 
     * لایه‌های امنیتی:
     * - بررسی اطلاعات فایل PHP
     * - تحقق اصلی MIME type (magic bytes)
     * - نام‌گذاری تصادفی
     * - محدودیت اندازه
     * - سفیدلیست extension
     * 
     * @param array $file فایل از $_FILES
     * @param string $folder مسیر پوشه (validated)
     * @param array $allowedMimes MIME types مجاز یا extensions
     * @param int $maxBytes حداکثر اندازه بایت
     * @return array ['success' => bool, 'path' => ?string, 'error' => ?string]
     */
    public function upload(
        array $file,
        string $folder,
        array $allowedMimes = [],
        int $maxBytes = 0
    ): array;

    /**
     * بازیابی و validation فایل آپلود‌شده
     * 
     * @param string $path مسیر فایل نسبی
     * @return array ['success' => bool, 'file' => ?object, 'error' => ?string]
     */
    public function getFile(string $path): array;

    /**
     * حذف فایل
     * 
     * @param string $path مسیر فایل نسبی
     * @return bool
     */
    public function delete(string $path): bool;

    /**
     * بررسی وجود فایل
     * 
     * @param string $path مسیر فایل نسبی
     * @return bool
     */
    public function exists(string $path): bool;

    /**
     * دریافت محتوای فایل
     * 
     * @param string $path مسیر فایل نسبی
     * @return string|null
     */
    public function read(string $path): ?string;

    /**
     * نوشتن محتوا در فایل
     * 
     * @param string $path مسیر فایل نسبی
     * @param string $content محتوا
     * @return bool
     */
    public function write(string $path, string $content): bool;
}

<?php

namespace Core;

/**
 * ═══════════════════════════════════════════════════════════════
 *  Minifier - فشرده‌سازی CSS/JS
 * ═══════════════════════════════════════════════════════════════
 */
class Minifier
{
    private string $publicPath;
    private \App\Contracts\LoggerInterface $logger;

    public function __construct(?\App\Contracts\LoggerInterface $logger = null)
    {
        $this->publicPath = config('paths.public');
        $this->logger = $logger ?: logger();
    }

    /**
     * Minify CSS
     */
    public function minifyCSS(string $css): string
    {
        // M30 Fix: پیاده‌سازی راهکار فوق امن محافظت از رشته‌ها و آدرس‌های اینترنتی
        
        // ۱. حذف کامنت‌های استاندارد چندخطی
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        
        // ۲. استخراج و ایزوله کردن موقت تمام محتوای کوتیشن‌ها و تابع url() جهت مصونیت ساختاری
        $pattern = '/("([^"\\\]|\\\.)*")|(\'([^\'\\\]|\\\.)*\')|(url\([^)]+\))/i';
        $tokens = [];
        
        $css = preg_replace_callback($pattern, function ($matches) use (&$tokens) {
            $placeholder = '___CSS_SHIELD_TOKEN_' . count($tokens) . '___';
            $tokens[$placeholder] = $matches[0];
            return $placeholder;
        }, $css);

        // ۳. یکسان‌سازی و حذف کاراکترهای کنترلی خط و تب
        $css = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $css);
        
        // ۴. فشرده‌سازی تمام فاصله‌های چندگانه متوالی به یک فاصله منفرد
        $css = preg_replace('/\s+/', ' ', $css);
        
        // ۵. حذف فضای خالی زائد در اطراف جداکننده‌ها و آکولادها
        $css = preg_replace('/\s*([{}:;,])\s*/', '$1', $css);
        
        // ۶. حذف سمی‌کالن‌های غیرضروری بلافاصله پیش از بسته شدن استایل بلاک‌ها
        $css = str_replace(';}', '}', $css);
        
        // ۷. تزریق مجدد داده‌های اورجینال و امن شده به فایل نهایی
        if (!empty($tokens)) {
            $css = strtr($css, $tokens);
        }

        return trim($css);
    }

    /**
     * Minify JavaScript
     */
    public function minifyJS(string $js): string
    {
        // H28 Fix: حذف هوشمند کامنت‌ها با الگوبرداری از گرامر جاوااسکریپت
        // این ریجکس ترکیبی ابتدا تمام Literalهای رشته‌ای (کوتیشن تکی، جفتی و بک‌تیک)،
        // ریجکس‌های درون‌خطی (/.../) و کامنت‌ها را پیدا می‌کند و صرفاً کامنت‌ها را حذف می‌کند.
        // به این صورت ساختارهایی مثل "http://" که در قالب رشته هستند هرگز تخریب نمی‌شوند.
        
        $pattern = '/("([^"\\\]|\\\.)*")|(\'([^\'\\\]|\\\.)*\')|(`([^`\\\]|\\\.)*`)|(\/([^\/\\\]|\\\.)*\/)|(\/\*[\s\S]*?\*\/)|(\/\/.*)/';
        
        $js = preg_replace_callback($pattern, function ($matches) {
            $match = $matches[0];
            // اگر بخش مطابقت داده شده یک کامنت تک‌خطی یا چندخطی باشد، آن را حذف کن
            if (str_starts_with($match, '//') || str_starts_with($match, '/*')) {
                return '';
            }
            // در غیر این صورت، این بخش یک رشته معتبر جاوااسکریپت یا ریجکس است و باید بدون تغییر باقی بماند
            return $match;
        }, $js);

        // حذف فضای خالی اضافی
        $js = preg_replace('/\s+/', ' ', $js);
        
        // حذف فضا قبل و بعد اپراتورهای ریاضی و منطقی
        $js = preg_replace('/\s*([{}();,=<>+\-*\/])\s*/', '$1', $js);

        return trim($js);
    }

    /**
     * Minify فایل CSS
     */
    public function minifyCSSFile(string $inputPath, ?string $outputPath = null): bool
    {
        if (!file_exists($inputPath)) {
            $this->logger->error("CSS file not found: {$inputPath}");
            return false;
        }

        $css = file_get_contents($inputPath);
        $minified = $this->minifyCSS($css);

        $outputPath = $outputPath ?? str_replace('.css', '.min.css', $inputPath);

        $result = file_put_contents($outputPath, $minified);

        if ($result !== false) {
            $originalSize = filesize($inputPath);
            $minifiedSize = filesize($outputPath);
            $saved = $originalSize - $minifiedSize;
            $percent = round(($saved / $originalSize) * 100, 2);

            $this->logger->info('assets.css.minified', [
    'channel' => 'assets',
    'input' => basename($inputPath),
    'output' => basename($outputPath),
    'saved_bytes' => $saved,
    'saved_percent' => $percent,
]);
        }

        return $result !== false;
    }

    /**
     * Minify فایل JS
     */
    public function minifyJSFile(string $inputPath, ?string $outputPath = null): bool
    {
        if (!file_exists($inputPath)) {
            $this->logger->error("JS file not found: {$inputPath}");
            return false;
        }

        $js = file_get_contents($inputPath);
        $minified = $this->minifyJS($js);

        $outputPath = $outputPath ?? str_replace('.js', '.min.js', $inputPath);

        $result = file_put_contents($outputPath, $minified);

        if ($result !== false) {
            $originalSize = filesize($inputPath);
            $minifiedSize = filesize($outputPath);
            $saved = $originalSize - $minifiedSize;
            $percent = round(($saved / $originalSize) * 100, 2);

            $this->logger->info('assets.js.minified', [
    'channel' => 'assets',
    'input' => basename($inputPath),
    'output' => basename($outputPath),
    'saved_bytes' => $saved,
    'saved_percent' => $percent,
]);
        }

        return $result !== false;
    }

    /**
     * Minify تمام فایل‌های CSS در پوشه
     */
    public function minifyAllCSS(string $directory): int
    {
        $count = 0;
        $files = glob($directory . '/*.css');

        foreach ($files as $file) {
            // Skip فایل‌های .min.css
            if (strpos($file, '.min.css') !== false) {
                continue;
            }

            if ($this->minifyCSSFile($file)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Minify تمام فایل‌های JS در پوشه
     */
    public function minifyAllJS(string $directory): int
    {
        $count = 0;
        $files = glob($directory . '/*.js');

        foreach ($files as $file) {
            // Skip فایل‌های .min.js
            if (strpos($file, '.min.js') !== false) {
                continue;
            }

            if ($this->minifyJSFile($file)) {
                $count++;
            }
        }

        return $count;
    }
}
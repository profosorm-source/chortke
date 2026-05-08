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
        // حذف کامنت‌ها
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        
        // حذف فضای خالی اضافی
        $css = str_replace(["\r\n", "\r", "\n", "\t", '  ', '    ', '    '], '', $css);
        
        // حذف فضای خالی قبل و بعد از : ; { }
        $css = preg_replace('/\s*([{}:;,])\s*/', '$1', $css);
        
        // حذف ; آخرین property در block
        $css = str_replace(';}', '}', $css);

        return trim($css);
    }

    /**
     * Minify JavaScript
     */
    public function minifyJS(string $js): string
    {
        // حذف کامنت‌های // (single-line)
        $js = preg_replace('/\/\/.*$/m', '', $js);
        
        // حذف کامنت‌های /* */ (multi-line)
        $js = preg_replace('/\/\*[\s\S]*?\*\//', '', $js);
        
        // حذف فضای خالی اضافی
        $js = preg_replace('/\s+/', ' ', $js);
        
        // حذف فضا قبل و بعد اپراتورها
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
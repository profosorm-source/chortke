<?php

namespace App\Services;

use Core\Session;
use App\Models\CaptchaLog;
use App\Services\Settings\AppSettings;

use App\Contracts\LoggerInterface;
class CaptchaService
{
    private CaptchaLog $captchaLogModel;
    private AppSettings $appSettings;
    private Session $session;

    /** مسیر پوشه captcha نسبت به root پروژه */
    private const CAPTCHA_DIR = '/storage/captcha/';

    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        CaptchaLog $captchaLogModel,
        AppSettings $appSettings,
        Session $session
    ) {        $this->logger = $logger;

                $this->captchaLogModel = $captchaLogModel;
        $this->appSettings = $appSettings;
        $this->session      = $session;
    }

    // ══════════════════════════════════════════════════════════
    //  PUBLIC API
    // ══════════════════════════════════════════════════════════

    /**
     * تولید کپچا — نوع از پارامتر یا تنظیمات سیستم
     */
    public function generate(string $type = null): array
    {
        if (!$type) {
            $type = (string) $this->appSettings->get('captcha_type', 'math');
        }

        switch ($type) {
            case 'image':      return $this->generateImage();
            case 'recaptcha_v2': return $this->generateRecaptchaV2();
            case 'recaptcha_v3': return $this->generateRecaptchaV3();
            case 'behavioral': return $this->generateBehavioral();
            case 'math':
            default:           return $this->generateMath();
        }
    }

    /**
     * تأیید پاسخ کپچا
     */
    public function verify(string $token, string $response, ?string $recaptchaResponse = null, string $behavioralState = ''): bool
    {
        // ── reCAPTCHA (Google)
        if ($recaptchaResponse !== null && $recaptchaResponse !== '') {
            return $this->verifyRecaptcha($recaptchaResponse);
        }

        // ── بررسی token
        if ($token === '') {
            return false;
        }

        // ── behavioral stateless — قبل از session check
        // token آن با '.' جدا شده و شامل payload.signature است
        if (str_contains($token, '.')) {
            return $this->verifyBehavioral($token, $behavioralState);
        }

        $key         = "captcha_{$token}";
        $captchaData = $this->session->get($key);

        if (!$captchaData || !is_array($captchaData)) {
            return false;
        }

        $type      = (string)($captchaData['type']       ?? '');
        $createdAt = (int)  ($captchaData['created_at']  ?? 0);
        $attempts  = (int)  ($captchaData['attempts']    ?? 0);

        // ── بررسی داده خراب
        if ($createdAt <= 0 || $type === '') {
            $this->deleteImageFile($captchaData);
            $this->session->delete($key);
            return false;
        }

        // ── بررسی انقضا
        $expireMinutes = (int) $this->appSettings->get('captcha_expire_minutes', 5);
        if ((time() - $createdAt) / 60 > $expireMinutes) {
            $this->deleteImageFile($captchaData);
            $this->session->delete($key);
            return false;
        }

        // ── بررسی حداکثر تلاش
        $maxAttempts = (int) $this->appSettings->get('captcha_max_attempts', 3);
        if ($attempts >= $maxAttempts) {
            $this->deleteImageFile($captchaData);
            $this->session->delete($key);
            return false;
        }

        // ── مسیر behavioral — stateless، نیازی به session ندارد
        if ($type === 'behavioral') {
            return $this->verifyBehavioral($token, $behavioralState);
        }

        // ── مسیر math / image
        return $this->verifyChallenge($token, $captchaData, $response, $type, $attempts, $maxAttempts);
    }

    /**
     * آیا کپچا فعال است؟
     */
    public function isEnabled(): bool
    {
        return (bool) $this->appSettings->get('captcha_enabled', true);
    }

    /**
     * نوع کپچا بر اساس Risk Score
     */
    public function getCaptchaTypeByRisk(int $riskScore): string
    {
        if ($riskScore >= 80) return 'recaptcha_v2';
        if ($riskScore >= 60) return 'image';
        if ($riskScore >= 40) return 'behavioral';
        return 'math';
    }

    // ══════════════════════════════════════════════════════════
    //  GENERATE
    // ══════════════════════════════════════════════════════════

    private function generateMath(): array
    {
        $operators = ['+', '-', '*'];
        $op = $operators[array_rand($operators)];

        if ($op === '*') {
            $a = random_int(2, 9);
            $b = random_int(2, 9);
        } else {
            $a = random_int(10, 50);
            $b = random_int(1, 20);
        }

        $answer = match($op) {
            '+' => $a + $b,
            '-' => $a - $b,
            '*' => $a * $b,
        };

        $question = "{$a} {$op} {$b} = ?";
        $token    = $this->storeChallenge('math', $question, (string) $answer);

        return ['type' => 'math', 'question' => $question, 'token' => $token];
    }

    private function generateImage(): array
    {
        // پاکسازی فایل‌های قدیمی — throttle: هر 5 دقیقه یک‌بار اجرا می‌شود
        $lastCleanup = (int) $this->session->get('captcha_last_cleanup', 0);
        if (time() - $lastCleanup > 300) {
            $this->cleanupCaptchaFiles(3600);
            $this->session->set('captcha_last_cleanup', time());
        }

        $code  = $this->generateRandomCode(6);
        $token = $this->storeChallenge('image', $code, $code);

        $imagePath = $this->createCaptchaImage($code);
        $filename  = basename($imagePath);

        // ذخیره نام فایل در session برای حذف بعدی
        $data = $this->session->get("captcha_{$token}");
        if (is_array($data)) {
            $data['image_file'] = $filename;
            $this->session->set("captcha_{$token}", $data);
        }

        return ['type' => 'image', 'image' => $imagePath, 'token' => $token];
    }

    private function generateRecaptchaV2(): array
    {
        $siteKey = $this->getRecaptchaSiteKey();

        if ($siteKey === '') {
            throw new \RuntimeException('reCAPTCHA Site Key تنظیم نشده است');
        }

        return ['type' => 'recaptcha_v2', 'site_key' => $siteKey];
    }

    private function generateRecaptchaV3(): array
    {
        $siteKey = $this->getRecaptchaSiteKey();

        if ($siteKey === '') {
            throw new \RuntimeException('reCAPTCHA Site Key تنظیم نشده است');
        }

        return ['type' => 'recaptcha_v3', 'site_key' => $siteKey];
    }

    private function generateBehavioral(): array
    {
        $createdAt = time();
        $nonce     = bin2hex(random_bytes(8));
        $payload   = base64_encode(json_encode([
            'created_at' => $createdAt,
            'nonce'      => $nonce,
        ]));
        $sig   = $this->signBehavioral($payload);
        $token = $payload . '.' . $sig;

        return [
            'type'        => 'behavioral',
            'token'       => $token,
            'instruction' => 'لطفاً به صورت طبیعی با صفحه تعامل کنید',
        ];
    }

    private function signBehavioral(string $data): string
    {
        return hash_hmac('sha256', $data, secure_key());
    }

    private function parseBehavioralToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;

        [$payload, $sig] = $parts;
        if (!hash_equals($this->signBehavioral($payload), $sig)) return null;

        $data = json_decode(base64_decode($payload), true);
        if (!is_array($data) || empty($data['created_at'])) return null;

        return $data;
    }

    
    private function verifyBehavioral(string $token, string $behavioralState): bool
    {
        $minSeconds      = (int) $this->appSettings->get('behavioral_min_seconds', 4);
        $minInteractions = (int) $this->appSettings->get('behavioral_min_interactions', 5);

        // 1. بررسی امتیاز رفتاری کلاینت
        $behavioralScore = isset($_POST['behavioral_score']) ? (int)$_POST['behavioral_score'] : 0;
        if ($behavioralScore < 60) {
            $this->logger->warning('captcha.behavioral.low_score', ['score' => $behavioralScore]);
            return false;
        }

        // 2. بررسی رویدادهای خام ارسالی برای تحلیل پیشرفته
        $eventsRaw = $_POST['events'] ?? '';
        $events = is_string($eventsRaw) ? json_decode($eventsRaw, true) : null;
        
        if (!is_array($events) || count($events) < 5) {
            $this->logger->warning('captcha.behavioral.insufficient_events', ['events_count' => is_array($events) ? count($events) : 0]);
            return false;
        }

        // بررسی زمان‌های بین رویدادها (Timing Validation)
        $intervals = [];
        for ($i = 1; $i < count($events); $i++) {
            $diff = $events[$i]['time'] - $events[$i - 1]['time'];
            if ($diff >= 0) {
                $intervals[] = $diff;
            }
        }
        
        if (count($intervals) > 0) {
            $avgInterval = array_sum($intervals) / count($intervals);
            if ($avgInterval < 10) { // سرعت نامتعارف (ربات)
                $this->logger->warning('captcha.behavioral.bot_detected.avg_interval_too_fast', ['avg_interval' => $avgInterval]);
                return false;
            }
            
            // بررسی واریانس بازه‌های زمانی (ربات‌ها الگوهای فوق‌العاده منظمی ایجاد می‌کنند)
            $variance = 0.0;
            foreach ($intervals as $interval) {
                $variance += pow($interval - $avgInterval, 2);
            }
            $stdDev = sqrt($variance / count($intervals));
            if ($stdDev < 1.0) { // فواصل بیش از حد یکنواخت و منظم
                $this->logger->warning('captcha.behavioral.bot_detected.zero_variance', ['std_dev' => $stdDev]);
                return false;
            }
        }

        // بررسی آنتروپی و منحنی سرعت حرکت موس
        $mouseMoves = array_filter($events, function($e) {
            return ($e['type'] ?? '') === 'mouse_move';
        });

        if (count($mouseMoves) > 5) {
            $mouseMoves = array_values($mouseMoves);
            
            // تحلیل آنتروپی مختصات
            $distinctCoords = [];
            foreach ($mouseMoves as $move) {
                $distinctCoords[] = ($move['x'] ?? 0) . ',' . ($move['y'] ?? 0);
            }
            $distinctRatio = count(array_unique($distinctCoords)) / count($mouseMoves);
            if ($distinctRatio < 0.1) { // مختصات بدون تغییر یا بیش از حد یکنواخت
                $this->logger->warning('captcha.behavioral.bot_detected.low_entropy', ['ratio' => $distinctRatio]);
                return false;
            }

            // تحلیل منحنی سرعت و تغییر شتاب
            $velocities = [];
            for ($i = 1; $i < count($mouseMoves); $i++) {
                $dx = ($mouseMoves[$i]['x'] ?? 0) - ($mouseMoves[$i - 1]['x'] ?? 0);
                $dy = ($mouseMoves[$i]['y'] ?? 0) - ($mouseMoves[$i - 1]['y'] ?? 0);
                $dist = sqrt($dx * $dx + $dy * $dy);
                $dt = ($mouseMoves[$i]['time'] ?? 0) - ($mouseMoves[$i - 1]['time'] ?? 0);
                if ($dt > 0) {
                    $velocities[] = $dist / $dt;
                }
            }

            if (count($velocities) > 2) {
                $accelerations = [];
                for ($i = 1; $i < count($velocities); $i++) {
                    $accelerations[] = $velocities[$i] - $velocities[$i - 1];
                }
                
                $sumAbsAcc = array_sum(array_map('abs', $accelerations));
                if ($sumAbsAcc < 0.001) { // سرعت خطی کامل و بدون شتاب‌دهی طبیعی
                    $this->logger->warning('captcha.behavioral.bot_detected.no_acceleration_variance');
                    return false;
                }
            }
        }

        // parse کردن token اصلی
        $tokenData = $this->parseBehavioralToken($token);
        if (!$tokenData) {
            return false;
        }

        $createdAt = (int)$tokenData['created_at'];

        // حداقل زمان
        if ((time() - $createdAt) < $minSeconds) {
            return false;
        }

        // انقضا (5 دقیقه)
        $expireMinutes = (int) $this->appSettings->get('captcha_expire_minutes', 5);
        if ((time() - $createdAt) / 60 > $expireMinutes) {
            return false;
        }

        // parse کردن behavioral_state که از ping آمده
        if (empty($behavioralState)) {
            return false;
        }
        $stateParts = explode('.', $behavioralState);
        if (count($stateParts) !== 2) return false;
        [$statePayload, $stateSig] = $stateParts;
        if (!hash_equals($this->signBehavioral($statePayload), $stateSig)) return false;

        $state = json_decode(base64_decode($statePayload), true);
        if (!is_array($state)) return false;

        $interactions      = (int)($state['interactions']       ?? 0);
        $lastInteractionAt = (int)($state['last_interaction_at'] ?? 0);

        // حداقل تعامل
        if ($interactions < $minInteractions) {
            return false;
        }

        // آخرین تعامل در 60 ثانیه اخیر
        if ($lastInteractionAt <= 0 || (time() - $lastInteractionAt) > 60) {
            return false;
        }

        $this->logAttempt(null, 'behavioral', '', '', true);
        return true;
    }

    public function pingBehavioral(string $currentState = ''): array
    {
        $interactions = 0;
        $lastInteractionAt = 0;

        if ($currentState !== '') {
            $parts = explode('.', $currentState);
            if (count($parts) === 2) {
                [$statePayload, $stateSig] = $parts;
                if (hash_equals($this->signBehavioral($statePayload), $stateSig)) {
                    $decoded = base64_decode($statePayload, true);
                    if ($decoded !== false) {
                        $stateData = json_decode($decoded, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($stateData)) {
                            $interactions = (int)($stateData['interactions'] ?? 0);
                            $lastInteractionAt = (int)($stateData['last_interaction_at'] ?? 0);
                        }
                    }
                }
            }
        }

        $now = time();
        if ($lastInteractionAt > 0 && ($now - $lastInteractionAt) < 1) {
            return [
                'interactions' => $interactions,
                'behavioral_state' => $currentState,
            ];
        }

        $newInteractions = $interactions + 1;
        $newPayload = base64_encode(json_encode([
            'interactions' => $newInteractions,
            'last_interaction_at' => $now,
        ]));
        $newState = $newPayload . '.' . $this->signBehavioral($newPayload);

        return [
            'interactions' => $newInteractions,
            'behavioral_state' => $newState,
        ];
    }

    private function verifyChallenge(
        string $token,
        array  $captchaData,
        string $response,
        string $type,
        int    $attempts,
        int    $maxAttempts
    ): bool {
        $key = "captcha_{$token}";

        if (!array_key_exists('answer', $captchaData)) {
            $this->deleteImageFile($captchaData);
            $this->session->delete($key);
            return false;
        }

        $expected  = (string) $captchaData['answer'];
        $isCorrect = strtolower(trim($response)) === strtolower(trim($expected));

        if ($isCorrect) {
            $this->logAttempt($token, $type, (string)($captchaData['challenge'] ?? ''), $response, true);
            $this->deleteImageFile($captchaData);
            $this->session->delete($key);
            return true;
        }

        // پاسخ غلط
        $newAttempts = $attempts + 1;
        $captchaData['attempts'] = $newAttempts;

        $this->logAttempt($token, $type, (string)($captchaData['challenge'] ?? ''), $response, false);

        if ($newAttempts >= $maxAttempts) {
            $this->deleteImageFile($captchaData);
            $this->session->delete($key);
        } else {
            $this->session->set($key, $captchaData);
        }

        return false;
    }

    private function verifyRecaptcha(string $response): bool
    {
        $secretKey = trim((string) $this->appSettings->get('recaptcha_secret_key', ''));

        if ($secretKey === '') {
            return false;
        }

        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query([
                    'secret'   => $secretKey,
                    'response' => $response,
                    'remoteip' => get_client_ip(),
                ]),
                'timeout' => 5,
            ]
        ];

        $raw = @file_get_contents(
            'https://www.google.com/recaptcha/api/siteverify',
            false,
            stream_context_create($options)
        );

        if ($raw === false) {
            return false;
        }

        $result = json_decode($raw, true);

        if (!is_array($result)) {
            return false;
        }

        // reCAPTCHA v3 — بررسی Score
        if (array_key_exists('score', $result)) {
            $threshold = (float) $this->appSettings->get('recaptcha_v3_threshold', 0.5);
            $success   = !empty($result['success']) && ((float)$result['score']) >= $threshold;
            $this->logAttempt(null, 'recaptcha_v3', 'auto', $response, $success, (float)$result['score']);
            return $success;
        }

        // reCAPTCHA v2
        $success = !empty($result['success']);
        $this->logAttempt(null, 'recaptcha_v2', 'checkbox', $response, $success);
        return $success;
    }

    // ══════════════════════════════════════════════════════════
    //  STORE / IMAGE / CLEANUP
    // ══════════════════════════════════════════════════════════

    private function storeChallenge(string $type, string $challenge, string $answer): string
    {
        $token = bin2hex(random_bytes(16));

        $this->session->set("captcha_{$token}", [
            'type'       => $type,
            'challenge'  => $challenge,
            'answer'     => $answer,
            'created_at' => time(),
            'attempts'   => 0,
        ]);

        return $token;
    }

    private function createCaptchaImage(string $code): string
    {
        $width  = 200;
        $height = 60;
        $image  = \imagecreatetruecolor($width, $height);

        // رنگ‌ها
        $bg        = \imagecolorallocate($image, 245, 245, 245);
        $textColor = \imagecolorallocate($image, 30, 30, 30);
        $noise     = \imagecolorallocate($image, 180, 180, 180);

        \imagefilledrectangle($image, 0, 0, $width, $height, $bg);

        // خطوط نویز
        for ($i = 0; $i < 6; $i++) {
            \imageline($image, random_int(0, $width), random_int(0, $height),
                               random_int(0, $width), random_int(0, $height), $noise);
        }

        // نقاط نویز
        for ($i = 0; $i < 120; $i++) {
            \imagesetpixel($image, random_int(0, $width), random_int(0, $height), $noise);
        }

        // متن
        $fontPath = __DIR__ . '/../../public/assets/fonts/captcha-font.ttf';
        if (file_exists($fontPath)) {
            \imagettftext($image, 24, random_int(-12, 12), 18, 42, $textColor, $fontPath, $code);
        } else {
            \imagestring($image, 5, 55, 20, $code, $textColor);
        }

        // ذخیره فایل
        $filename = 'captcha_' . bin2hex(random_bytes(8)) . '.png';
        $dir      = rtrim(__DIR__ . '/../../storage/captcha', '/');

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        \imagepng($image, $dir . '/' . $filename);
        \imagedestroy($image);

        return self::CAPTCHA_DIR . $filename;
    }

    private function deleteImageFile(array $captchaData): void
    {
        if (($captchaData['type'] ?? '') !== 'image') {
            return;
        }

        $filename = basename((string)($captchaData['image_file'] ?? ''));

        if ($filename === '' || $filename === 'captcha') {
            return;
        }

        // فقط فایل‌هایی که با captcha_ شروع می‌شوند
        if (!str_starts_with($filename, 'captcha_')) {
            return;
        }

        $fullPath = __DIR__ . '/../../storage/captcha/' . $filename;

        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    private function cleanupCaptchaFiles(int $maxAgeSeconds = 3600): void
    {
        $dir = realpath(__DIR__ . '/../../storage/captcha');
        if (!$dir || !is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/captcha_*.png') ?: [];
        $now   = time();

        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime && ($now - $mtime) > $maxAgeSeconds) {
                @unlink($file);
            }
        }
    }

    // ══════════════════════════════════════════════════════════
    //  HELPERS
    // ══════════════════════════════════════════════════════════

    private function getRecaptchaSiteKey(): string
    {
        $key = trim((string) $this->appSettings->get('recaptcha_site_key', ''));

        if ($key === '') {
            $key = trim((string) config('captcha.recaptcha_site_key', ''));
        }

        return $key;
    }

    private function generateRandomCode(int $length = 6): string
    {
        // بدون حروف و اعداد مشابه (0/O, 1/I/l)
        $chars  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max    = strlen($chars) - 1;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, $max)];
        }

        return $result;
    }

    /**
     * ثبت لاگ — اگر جدول وجود نداشت یا خطا داد، به سکوت رد می‌شود
     */
    private function logAttempt(
        ?string $token,
        string  $type,
        string  $challenge,
        string  $response,
        bool    $success,
        ?float  $score = null
    ): void {
        try {
            $userId    = function_exists('auth') && auth() ? user_id() : null;
            $sessionId = session_id() ?: null;

            $this->captchaLogModel->recordAttempt([
                'user_id' => $userId,
                'session_id' => $sessionId,
                'type' => $type,
                'challenge' => $challenge,
                'response' => $response,
                'ip_address' => function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? null),
                'user_agent' => function_exists('get_user_agent') ? get_user_agent() : ($_SERVER['HTTP_USER_AGENT'] ?? null),
                'is_success' => $success ? 1 : 0,
                'score' => $score,
                'solved_at' => $success ? date('Y-m-d H:i:s') : null,
            ]);
        } catch (\Throwable $e) {
            // لاگ DB اختیاری است — خطای آن نباید verify را مختل کند
        }
    }
}

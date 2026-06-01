<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Services\CaptchaService;

class CaptchaController extends BaseController
{
    private CaptchaService $captchaService;

    public function __construct(CaptchaService $captchaService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->captchaService = $captchaService;
    }

    /**
     * تعویض کپچا — GET /captcha/refresh?type=math|image
     */
    public function refresh(): void
    {
        if (!is_ajax()) {
            http_response_code(400);
            exit;
        }

        $type = in_array($_GET['type'] ?? '', ['math', 'image']) ? $_GET['type'] : 'math';

        $captcha = $this->captchaService->generate($type);

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');

        if ($type === 'math') {
            echo json_encode([
                'question' => $captcha['question'],
                'token'    => $captcha['token'],
            ], JSON_UNESCAPED_UNICODE);
        } else {
            $image    = (string)($captcha['image'] ?? '');
            $filename = basename(ltrim($image, '/'));
            echo json_encode([
                'image_url' => url('file/view/captcha/' . $filename),
                'token'     => $captcha['token'],
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    /**
     * ثبت تعامل behavioral — POST /captcha/behavioral/ping
     * Stateless: state رو sign شده برمیگردونه، نیازی به session ندارد
     */
    public function behavioralPing(): void
    {
        if (!is_ajax()) {
            $this->json(false, 'Bad request', [], 400);
            return;
        }

        $token = trim((string)($_POST['captcha_token'] ?? ''));
        if ($token === '') {
            $this->json(false, 'Token missing', [], 422);
            return;
        }

        if (!str_contains($token, '.')) {
            $this->json(false, 'Invalid token format', [], 400);
            return;
        }

        $currentState = trim((string)($_POST['behavioral_state'] ?? ''));
        $result = $this->captchaService->pingBehavioral($currentState);

        $this->json(true, 'ok', [
            'interactions'     => $result['interactions'],
            'behavioral_state' => $result['behavioral_state'],
        ]);
    }
}
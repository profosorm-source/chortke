<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Contracts\LoggerInterface;
use App\Services\AntiFraud\AccountTakeoverService;
use App\Services\AntiFraud\BrowserFingerprintService;
use App\Services\AntiFraud\GeoIPService;
use App\Services\Auth\SessionService;
use App\Services\AntiFraud\RiskDecisionService;
use App\Services\ScoreService;
use Core\Request;
use Core\Response;
use Core\Session;
use Closure;

/**
 * AdvancedFraudMiddleware — سیستم پیشرفته شناسایی تقلب و ریسک
 */
class AdvancedFraudMiddleware extends BaseMiddleware
{
    private BrowserFingerprintService $fingerprintService;
    private GeoIPService $ipQualityService;
    private SessionService $sessionService;
    private AccountTakeoverService $accountTakeoverService;
    private ScoreService $scoreService;
    private RiskDecisionService $decisionService;
    private LoggerInterface $logger;
    private Session $session;

    public function __construct(
        BrowserFingerprintService $fingerprintService,
        GeoIPService $ipQualityService,
        SessionService $sessionService,
        AccountTakeoverService $accountTakeoverService,
        ScoreService $scoreService,
        RiskDecisionService $decisionService,
        LoggerInterface $logger,
        Session $session
    ) {
        $this->fingerprintService = $fingerprintService;
        $this->ipQualityService = $ipQualityService;
        $this->sessionService = $sessionService;
        $this->accountTakeoverService = $accountTakeoverService;
        $this->scoreService = $scoreService;
        $this->decisionService = $decisionService;
        $this->logger = $logger;
        $this->session = $session;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->session;
        if (!$session->has('user_id')) {
            return $next($request);
        }

        $userId = (int) $session->get('user_id');
        $ip = get_client_ip();
        $userAgent = get_user_agent();
        $sessionId = $session->getId();
        $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';

        $geoData = $this->ipQualityService->getGeolocation($ip);
        $this->sessionService->updateActivity($sessionId);

        if (!$session->get('fraud_check_done')) {
            // ✅ Pass all HTTP data explicitly
            $this->sessionService->recordSession(
                userId: $userId,
                sessionId: $sessionId,
                userAgent: $userAgent,
                ipAddress: $ip,
                acceptLanguage: $acceptLanguage,
                acceptEncoding: $acceptEncoding,
                geoData: $geoData
            );
            $session->set('fraud_check_done', true);
        }

        if ($this->ipQualityService->isIPBlacklisted($ip)) {
            $this->logger->warning('fraud.blocked_ip', ['ip' => $ip, 'user_id' => $userId]);
            $session->destroy();
            return (new Response())->redirect(url('/login?error=blocked'));
        }

        $ipCheck = $this->ipQualityService->check($ip);
        if ($ipCheck['is_suspicious']) {
            $this->ipQualityService->logIPCheck($userId, $ip, $ipCheck);
            $this->scoreService->applyDelta('user', $userId, \App\Enums\ScoreDomain::Fraud->value, (float) $ipCheck['score'] / 4, 'ip_quality', [
                'ip' => $ip,
                'reasons' => $ipCheck['reasons'],
            ]);

            if (!empty($ipCheck['details']['is_tor'])) {
                $this->ipQualityService->blacklistIP($ip, 'Tor Network', 86400 * 7);
                $session->destroy();
                return (new Response())->redirect(url('/login?error=tor_blocked'));
            }
        }

        $sessionCheck = $this->sessionService->analyzeAnomaly($userId, $sessionId);
        if ($sessionCheck['is_anomaly']) {
            $this->sessionService->logAnomaly($userId, $sessionId, $sessionCheck);
            $this->scoreService->applyDelta('user', $userId, \App\Enums\ScoreDomain::Fraud->value, (float) $sessionCheck['score'] / 2, 'session_anomaly', [
                'anomalies' => $sessionCheck['anomalies'],
                'session_id' => $sessionId,
            ]);
        }

        $currentFingerprint = $this->fingerprintService->generate([
            'user_agent' => $userAgent,
            'language' => $acceptLanguage,
            'encoding' => $acceptEncoding
        ]);

        $takeoverCheck = $this->accountTakeoverService->detect($userId, $ip, $userAgent, $currentFingerprint);
        if ($takeoverCheck['is_takeover']) {
            $this->accountTakeoverService->logDetection($userId, $ip, $userAgent, $takeoverCheck);
            $this->scoreService->applyDelta('user', $userId, \App\Enums\ScoreDomain::Fraud->value, (float) $takeoverCheck['risk_score'] / 2, 'account_takeover', [
                'signals' => $takeoverCheck['signals'],
            ]);

            if ($takeoverCheck['action'] === 'notify') {
                notify($userId, 'warning', config('messages.security.suspicious'));
            }
        }

        $decision = $this->decisionService->decide($userId, ['action' => 'general']);
        $decisionResult = (string)($decision['result'] ?? $decision['decision'] ?? 'allow');

        switch ($decisionResult) {
            case 'block':
                notify($userId, 'danger', config('messages.security.high_risk'));
                $session->destroy();
                return (new Response())->redirect(url('/login?error=high_risk'));

            case 'challenge':
                if (!$session->get('2fa_verified')) {
                    $session->setFlash('warning', config('messages.security.challenge_2fa'));
                    return (new Response())->redirect(url('/verify-2fa'));
                }
                break;

            case 'review':
                // وضعیت بررسی دستی (Review) — فلگ بررسی دستی را فعال کرده و لاگ ثبت می‌کنیم
                $session->set('under_manual_review', true);
                $this->logger->info('fraud.manual_review_triggered', [
                    'user_id' => $userId,
                    'ip' => $ip,
                ]);
                break;

            case 'allow':
            default:
                $session->remove('under_manual_review');
                break;
        }

        return $this->toResponse($next($request));
    }
}
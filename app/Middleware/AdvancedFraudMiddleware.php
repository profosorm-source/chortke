<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Contracts\LoggerInterface;
use App\Services\AntiFraud\AccountTakeoverService;
use App\Services\AntiFraud\BrowserFingerprintService;
use App\Services\AntiFraud\GeoIPService;
use App\Services\Auth\SessionService;
use App\Services\AntiFraud\RiskDecisionService;
use App\Services\Shared\ScoreService;
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

        $geoData = $this->ipQualityService->getGeolocation($ip);
        $this->sessionService->updateActivity($sessionId);

        if (!$session->get('fraud_check_done')) {
            $this->sessionService->recordSession($userId, $sessionId, $geoData);
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
            $this->scoreService->incrementFraudRawScore($userId, (float) $ipCheck['score'] / 4, 'ip_quality', [
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
            $this->scoreService->incrementFraudRawScore($userId, (float) $sessionCheck['score'] / 2, 'session_anomaly', [
                'anomalies' => $sessionCheck['anomalies'],
                'session_id' => $sessionId,
            ]);
        }

        $takeoverCheck = $this->accountTakeoverService->detect($userId, $ip, $userAgent);
        if ($takeoverCheck['is_takeover']) {
            $this->accountTakeoverService->logDetection($userId, $ip, $userAgent, $takeoverCheck);
            $this->scoreService->incrementFraudRawScore($userId, (float) $takeoverCheck['risk_score'] / 2, 'account_takeover', [
                'signals' => $takeoverCheck['signals'],
            ]);

            if ($takeoverCheck['action'] === 'notify') {
                notify($userId, 'warning', config('messages.security.suspicious'));
            }
        }

        $decision = $this->decisionService->decide($userId, ['action' => 'general']);
        $decisionResult = (string)($decision['result'] ?? $decision['decision'] ?? 'allow');

        if ($decisionResult === 'block') {
            notify($userId, 'danger', config('messages.security.high_risk'));
            $session->destroy();
            return (new Response())->redirect(url('/login?error=high_risk'));
        }

        if ($decisionResult === 'challenge' && !$session->get('2fa_verified')) {
            $session->setFlash('warning', config('messages.security.challenge_2fa'));
            return (new Response())->redirect(url('/verify-2fa'));
        }

        return $this->toResponse($next($request));
    }
}
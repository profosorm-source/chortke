<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use Core\Request;
use Core\Response;

/**
 * SecurityController — Handling security related callbacks (CSP reports, etc.)
 */
class SecurityController extends BaseController
{
    public function __construct(?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
    }

    /**
     * Handle Content Security Policy violation reports
     */
    public function cspReport(): void
    {
        // 🛡️ CRIT-11 Fix: CSP reporting must be lightweight, validated and rate-limited.
        $report = $this->request->json();

        if (!is_array($report) || empty($report)) {
            $this->logger->warning('security.csp_report.invalid_payload', [
                'payload' => $report,
                'ip' => $this->request->ip(),
                'user_agent' => $this->request->userAgent()
            ]);

            $this->response->setStatusCode(204);
            $this->response->send();
            return;
        }

        $this->logger->warning('security.csp_violation', [
            'report' => $report,
            'ip' => $this->request->ip(),
            'user_agent' => $this->request->userAgent()
        ]);

        $this->response->setStatusCode(204); // No Content
        $this->response->send();
    }
}

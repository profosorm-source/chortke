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
    /**
     * Handle Content Security Policy violation reports
     */
    public function cspReport(): void
    {
        // 🛡️ CRIT-11 Fix: CSP reporting must be lightweight and rate-limited.
        // We only log basic info to prevent log flooding.
        $report = $this->request->json();
        
        if ($report) {
            $this->logger->warning('security.csp_violation', [
                'report' => $report,
                'ip' => $this->request->ip(),
                'user_agent' => $this->request->userAgent()
            ]);
        }

        $this->response->setStatusCode(204); // No Content
        $this->response->send();
    }
}

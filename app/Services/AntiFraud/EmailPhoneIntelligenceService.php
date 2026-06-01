<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\VelocityAndScoreModel;
use App\Contracts\LoggerInterface;
/**
 * EmailPhoneIntelligenceService
 * 
 * تحلیل هوشمند ایمیل و شماره تلفن
 */
class EmailPhoneIntelligenceService
{
    private VelocityAndScoreModel $model;
    private const DISPOSABLE_DOMAINS = [
        'tempmail.com', 'guerrillamail.com', '10minutemail.com', 'mailinator.com',
        'throwaway.email', 'temp-mail.org', 'maildrop.cc', 'getnada.com',
        'mohmal.com', 'dispostable.com', 'yopmail.com', 'fakeinbox.com'
    ];
    
    private const FREE_EMAIL_PROVIDERS = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com',
        'icloud.com', 'mail.com', 'protonmail.com', 'gmx.com', 'zoho.com'
    ];

    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        VelocityAndScoreModel $model
    )
    {        $this->logger = $logger;

                $this->model = $model;
    }

    /**
     * تحلیل کامل ایمیل
     */
    public function analyzeEmail(string $email): array
    {
        $email = strtolower(trim($email));
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'is_valid' => false,
                'error' => 'فرمت ایمیل نامعتبر است'
            ];
        }
        
        [$localPart, $domain] = explode('@', $email);
        
        $cached = $this->getEmailFromCache($email);
        if ($cached) {
            return $cached;
        }
        
        $analysis = [
            'email' => $email,
            'domain' => $domain,
            'local_part' => $localPart,
            'is_valid' => true
        ];
        
        $analysis['is_disposable'] = $this->isDisposableEmail($domain);
        $analysis['is_free_provider'] = $this->isFreeEmailProvider($domain);
        
        $mxCheck = $this->checkMXRecords($domain);
        $analysis['mx_records_valid'] = $mxCheck['valid'];
        $analysis['mx_records'] = $mxCheck['records'];
        
        $analysis['risk_score'] = $this->calculateEmailRiskScore($analysis);
        $analysis['is_suspicious'] = $analysis['risk_score'] >= 60;
        
        $this->saveEmailToCache($email, $domain, $analysis);
        
        return $analysis;
    }

    private function isDisposableEmail(string $domain): bool
    {
        if (in_array($domain, self::DISPOSABLE_DOMAINS)) {
            return true;
        }
        
        $result = $this->model->getDomainIntelligence($domain);
        return $result && $result->is_disposable;
    }

    private function isFreeEmailProvider(string $domain): bool
    {
        return in_array($domain, self::FREE_EMAIL_PROVIDERS);
    }

    private function checkMXRecords(string $domain): array
    {
        $mxRecords = [];
        $valid = @getmxrr($domain, $mxRecords);
        
        return [
            'valid' => $valid && !empty($mxRecords),
            'records' => $mxRecords
        ];
    }

    private function calculateEmailRiskScore(array $analysis): int
    {
        $score = 0;
        
        if ($analysis['is_disposable'] ?? false) {
            $score += 80;
        }
        
        if ($analysis['is_free_provider'] ?? false) {
            $score += 15;
        }
        
        if (!($analysis['mx_records_valid'] ?? true)) {
            $score += 70;
        }
        
        $localPart = $analysis['local_part'] ?? '';
        if (strlen($localPart) < 3 || strlen($localPart) > 50) {
            $score += 20;
        }
        
        if (preg_match_all('/\d/', $localPart) > 5) {
            $score += 15;
        }
        
        return min(100, $score);
    }

    /**
     * تحلیل شماره تلفن
     */
    public function analyzePhone(string $phone): array
    {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        if (empty($phone)) {
            return [
                'is_valid' => false,
                'error' => 'فرمت شماره تلفن نامعتبر است'
            ];
        }
        
        $cached = $this->getPhoneFromCache($phone);
        if ($cached) {
            return $cached;
        }
        
        $analysis = [
            'phone' => $phone,
            'is_valid' => true
        ];
        
        $analysis['country_code'] = $this->extractCountryCode($phone);
        $analysis['is_voip'] = false;
        $analysis['line_type'] = 'unknown';
        
        $analysis['risk_score'] = $this->calculatePhoneRiskScore($analysis);
        $analysis['is_suspicious'] = $analysis['risk_score'] >= 60;
        
        $this->savePhoneToCache($phone, $analysis);
        
        return $analysis;
    }

    private function extractCountryCode(string $phone): ?string
    {
        if (strpos($phone, '+98') === 0) {
            return 'IR';
        } elseif (strpos($phone, '+1') === 0) {
            return 'US';
        } elseif (strpos($phone, '+44') === 0) {
            return 'GB';
        }
        
        return null;
    }

    private function calculatePhoneRiskScore(array $analysis): int
    {
        $score = 0;
        
        if ($analysis['is_voip'] ?? false) {
            $score += 60;
        }
        
        if (($analysis['line_type'] ?? '') === 'voip') {
            $score += 50;
        }
        
        $length = strlen($analysis['phone'] ?? '');
        if ($length < 8 || $length > 15) {
            $score += 30;
        }
        
        return min(100, $score);
    }

    private function getEmailFromCache(string $email): ?array
    {
        $result = $this->model->getEmailFromCache($email);
        
        if (!$result) {
            return null;
        }
        
        return [
            'email' => $result->email,
            'domain' => $result->domain,
            'is_disposable' => (bool)$result->is_disposable,
            'is_free_provider' => (bool)$result->is_free_provider,
            'mx_records_valid' => (bool)$result->mx_records_valid,
            'domain_reputation_score' => (int)$result->domain_reputation_score,
            'risk_score' => $this->calculateEmailRiskScore([
                'is_disposable' => (bool)$result->is_disposable,
                'is_free_provider' => (bool)$result->is_free_provider,
                'mx_records_valid' => (bool)$result->mx_records_valid,
                'local_part' => explode('@', $email)[0]
            ]),
            'from_cache' => true
        ];
    }

    private function saveEmailToCache(string $email, string $domain, array $analysis): void
    {
        $this->model->saveEmailToCache($email, $domain, $analysis);
    }

    private function getPhoneFromCache(string $phone): ?array
    {
        $result = $this->model->getPhoneFromCache($phone);
        
        if (!$result) {
            return null;
        }
        
        return [
            'phone' => $result->phone,
            'country_code' => $result->country_code,
            'carrier' => $result->carrier,
            'line_type' => $result->line_type,
            'is_voip' => (bool)$result->is_voip,
            'is_valid' => (bool)$result->is_valid,
            'from_cache' => true
        ];
    }

    private function savePhoneToCache(string $phone, array $analysis): void
    {
        $this->model->savePhoneToCache($phone, $analysis);
    }

    public function updateDisposableList(): int
    {
        try {
            $url = 'https://raw.githubusercontent.com/disposable/disposable-email-domains/master/domains.txt';
            $content = @file_get_contents($url);
            
            if (!$content) {
                return 0;
            }
            
            $domains = array_filter(array_map('trim', explode("\n", $content)));
            $inserted = 0;
            
            foreach ($domains as $domain) {
                if ($this->model->updateDisposableDomain($domain)) {
                    $inserted++;
                }
            }
            
            $this->logger->info('email.disposable_list.updated', [
                'count' => $inserted
            ]);
            
            return $inserted;
        } catch (\Exception $e) {
            $this->logger->error('email.disposable_list.update_failed', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    public function cleanupCache(): int
    {
        return $this->model->cleanupOldCache();
    }
}


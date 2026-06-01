<?php

namespace App\Services;

use App\Models\ExportData;

use App\Contracts\LoggerInterface;
use Core\Session;
use App\Services\Shared\PolicyService;
use Core\RateLimiter;

class ExportService
{
    private ExportData $exportData;
    private Session $session;
    private PolicyService $policyService;
    private RateLimiter $rateLimiter;
    public function __construct(
        ExportData $exportData,
        Session $session,
        PolicyService $policyService,
        RateLimiter $rateLimiter
    ) {        $this->exportData = $exportData;
        $this->session = $session;
        $this->policyService = $policyService;
        $this->rateLimiter = $rateLimiter;

            }
    /**
     * خروجی CSV (Streaming version)
     */
    public function exportCsvStream(array $headers, \PDOStatement $stmt, string $filename, bool $maskPii = false): void
    {
        $filename = \preg_replace('/[^a-zA-Z0-9_\-]/', '', $filename) . '_' . \date('Y-m-d_His') . '.csv';

        \header('Content-Type: text/csv; charset=UTF-8');
        \header('Content-Disposition: attachment; filename="' . $filename . '"');
        \header('Cache-Control: no-cache, no-store, must-revalidate');
        \header('Pragma: no-cache');
        \header('Expires: 0');

        // BOM for UTF-8 Excel compatibility
        echo "\xEF\xBB\xBF";

        $output = \fopen('php://output', 'w');

        // Header
        \fputcsv($output, $headers);

        // Rows (Streamed)
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if ($maskPii) {
                $row = $this->maskSensitiveData($row);
            }
            $row = $this->sanitizeRowForCsv($row);
            \fputcsv($output, \array_values($row));
            if (\connection_aborted()) break;
        }

        \fclose($output);
        exit;
    }

    /**
     * ماسک کردن داده‌های حساس
     */
    private function maskSensitiveData(array $row): array
    {
        if (isset($row['email'])) {
            $parts = explode('@', (string)$row['email']);
            if (count($parts) === 2) {
                $row['email'] = substr($parts[0], 0, 3) . '***@' . $parts[1];
            }
        }
        if (isset($row['mobile'])) {
            $row['mobile'] = substr((string)$row['mobile'], 0, 4) . '***' . substr((string)$row['mobile'], -2);
        }
        return $row;
    }

    private function sanitizeRowForCsv(array $row): array
    {
        foreach ($row as $key => $value) {
            if (\is_string($value)) {
                $val = \trim($value);
                if ($val !== '' && \in_array($val[0], ['=', '+', '-', '@'], true)) {
                    $row[$key] = "'" . $value;
                }
            }
        }
        return $row;
    }

    private function authorizeExport(string $permission): void
    {
        try {
            $userId = $this->session->get('user_id');
            
            if (!$userId || !$this->policyService->authorizeById($permission, $userId)) {
                throw new \Exception('Unauthorized export attempt');
            }
            
            $key = 'export:' . $userId . ':' . $permission;
            if (!$this->rateLimiter->attempt($key, 5, 3600)) { // 5 exports per hour
                throw new \Exception('Rate limit exceeded: Maximum 5 exports per hour');
            }
        } catch (\Throwable $e) {
            throw new \Exception('Authorization failed: ' . $e->getMessage());
        }
    }

    /**
     * خروجی JSON
     */
    public function exportJson(array $data, string $filename): void
    {
        $filename = \preg_replace('/[^a-zA-Z0-9_\-]/', '', $filename) . '_' . \date('Y-m-d_His') . '.json';

        \header('Content-Type: application/json; charset=UTF-8');
        \header('Content-Disposition: attachment; filename="' . $filename . '"');
        \header('Cache-Control: no-cache');

        echo \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * آماده‌سازی داده‌ها برای خروجی کاربران
     */
    public function prepareUsersExport(array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        
        $rows = $this->exportData->getUsers($dateFrom, $dateTo);
        
        $headers = ['شناسه', 'نام', 'ایمیل', 'موبایل', 'سطح', 'وضعیت', 'تاریخ ثبت‌نام', 'آخرین ورود', 'موجودی تومان', 'موجودی تتر'];

        $statusMap = [0 => 'غیرفعال', 1 => 'فعال', 2 => 'تعلیق', 3 => 'مسدود'];

        $formatted = [];
        foreach ($rows as $row) {
            $r = \is_array($row) ? (object)$row : $row;
            $formatted[] = [
                $r->id,
                $r->full_name,
                $r->email,
                $r->mobile ?? '',
                $r->tier_level ?? 'silver',
                $statusMap[(int)($r->status ?? 0)] ?? 'نامشخص',
                $r->created_at,
                $r->last_login ?? '',
                $r->balance_irt,
                $r->balance_usdt,
            ];
        }

        return ['headers' => $headers, 'rows' => $formatted];
    }

    /**
     * آماده‌سازی داده‌ها برای خروجی تراکنش‌ها
     */
    public function prepareTransactionsExport(array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $type = $filters['type'] ?? null;
        $status = $filters['status'] ?? null;
        
        $rows = $this->exportData->getTransactions($dateFrom, $dateTo, $type, $status);

        $headers = ['شناسه', 'شماره تراکنش', 'کاربر', 'نوع', 'ارز', 'مبلغ', 'قبل', 'بعد', 'وضعیت', 'تاریخ'];

        $formatted = [];
        foreach ($rows as $row) {
            $r = \is_array($row) ? (object)$row : $row;
            $formatted[] = [
                $r->id,
                $r->transaction_id,
                $r->full_name ?? '',
                $r->type,
                $r->currency,
                $r->amount,
                $r->balance_before,
                $r->balance_after,
                $r->status,
                $r->created_at,
            ];
        }

        return ['headers' => $headers, 'rows' => $formatted];
    }

    /**
     * خروجی کاربران
     */
    public function exportUsers(array $filters = []): void
    {
        $this->authorizeExport('admin.export.users');

        $dateFrom = $filters['from'] ?? null;
        $dateTo = $filters['to'] ?? null;
        $kycStatus = $filters['kyc_status'] ?? null;
        $tierLevel = $filters['tier_level'] ?? null;
        
        $stmt = $this->exportData->getUsersStatement($dateFrom, $dateTo, $kycStatus, $tierLevel);
        
        $headers = ['#', 'نام', 'ایمیل', 'موبایل', 'KYC', 'سطح', 'وضعیت', 'تاریخ', 'آخرین ورود'];
        
        $this->exportCsvStream($headers, $stmt, 'users_export', true);
    }

    /**
     * خروجی تراکنش‌ها
     */
    public function exportTransactionsStream(array $filters = []): void
    {
        $this->authorizeExport('admin.export.transactions');

        $dateFrom = $filters['from'] ?? null;
        $dateTo = $filters['to'] ?? null;
        $type = $filters['type'] ?? null;
        $status = $filters['status'] ?? null;
        
        $stmt = $this->exportData->getTransactionsStatement($dateFrom, $dateTo, $type, $status);
        
        $headers = ['#', 'شماره تراکنش', 'نام کاربر', 'نوع', 'ارز', 'مبلغ', 'قبل', 'بعد', 'وضعیت', 'تاریخ'];
        
        $this->exportCsvStream($headers, $stmt, 'transactions_export', false);
    }

    /**
     * خروجی برداشت‌ها
     */
    public function exportWithdrawalsStream(array $filters = []): void
    {
        $this->authorizeExport('admin.export.withdrawals');

        $dateFrom = $filters['from'] ?? null;
        $dateTo = $filters['to'] ?? null;
        $status = $filters['status'] ?? null;
        $currency = $filters['currency'] ?? null;
        
        $stmt = $this->exportData->getWithdrawalsStatement($dateFrom, $dateTo, $status, $currency);
        
        $headers = ['#', 'کد پیگیری', 'نام', 'ایمیل', 'مبلغ', 'کارمزد', 'مبلغ نهایی', 'ارز', 'وضعیت', 'روش', 'تاریخ'];
        
        $this->exportCsvStream($headers, $stmt, 'withdrawals_export', true);
    }

    /**
     * خروجی AuditTrail
     */
    public function exportAuditTrail(array $filters = []): void
    {
        $this->authorizeExport('admin.export.audit');

        $dateFrom = $filters['from'] ?? null;
        $dateTo = $filters['to'] ?? null;
        $event = $filters['event'] ?? null;
        $userId = isset($filters['user_id']) ? (int)$filters['user_id'] : null;
        
        $stmt = $this->exportData->getAuditTrailStatement($dateFrom, $dateTo, $event, $userId);
        
        $headers = ['#', 'رویداد', 'کاربر', 'انجام‌دهنده', 'جزئیات', 'IP', 'زمان'];
        
        $this->exportCsvStream($headers, $stmt, 'audit_trail_export', false);
    }
}
